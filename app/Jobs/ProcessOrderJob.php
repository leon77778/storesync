<?php

namespace App\Jobs;

use App\Mail\OrderConfirmationMail;
use App\Models\ImportBatch;
use App\Models\Order;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ProcessOrderJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    /**
     * How many times Laravel should retry this job if it throws an exception.
     * After 3 failed attempts the job is moved to the failed_jobs table.
     */
    public int $tries = 3;

    /**
     * How many seconds to wait before each retry.
     * First retry: wait 10s. Second: 60s. Third: 5 minutes.
     * This is called "exponential backoff" — give the system more recovery
     * time with each attempt rather than hammering it immediately.
     */
    public array $backoff = [10, 60, 300];

    /**
     * Maximum seconds this job is allowed to run before Laravel kills it.
     * Prevents a stuck job from blocking the queue worker forever.
     */
    public int $timeout = 60;

    /**
     * We pass the Order model directly into the job.
     * Laravel's SerializesModels trait handles turning it into an ID for
     * storage in the queue, then re-fetching the fresh record when the
     * job actually runs (so we never work with stale data).
     */
    public function __construct(public Order $order) {}

    // -------------------------------------------------------------------------
    // Main job logic — runs in the background worker
    // -------------------------------------------------------------------------

    public function handle(): void
    {
        // Mark the order as actively being worked on so the dashboard
        // can show "processing" while the job is mid-flight.
        $this->order->update(['status' => 'processing']);

        // Step 1: Validate the order data
        $this->validateOrder();

        // Step 2: Calculate subtotal, tax, and total — store as pence
        $this->calculateTotals();

        // Step 3: Send confirmation email to the customer
        Mail::to($this->order->customer_email)
            ->send(new OrderConfirmationMail($this->order));

        // Step 4: Mark as done and record when it finished
        $this->order->update([
            'status'       => 'completed',
            'processed_at' => now(),
        ]);

        // Step 5: Tell the parent ImportBatch that one more row succeeded,
        // then let it recalculate its own overall status.
        $this->order->importBatch->increment('completed_rows');
        $this->order->importBatch->fresh()->recalculateStatus();
    }

    // -------------------------------------------------------------------------
    // What happens when all retry attempts are exhausted
    // -------------------------------------------------------------------------

    /**
     * Laravel calls this automatically after $tries attempts all fail.
     * We store the error message so it's visible in the dashboard,
     * then update the batch counters the same way we do on success.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("ProcessOrderJob permanently failed for order #{$this->order->id}", [
            'error' => $exception->getMessage(),
        ]);

        $this->order->update([
            'status'        => 'failed',
            'error_message' => $exception->getMessage(),
            'processed_at'  => now(),
        ]);

        $this->order->importBatch->increment('failed_rows');
        $this->order->importBatch->fresh()->recalculateStatus();
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Validates that the order has the minimum required data.
     * Throws an exception if anything is wrong — this causes the job
     * to fail and retry (or eventually land in failed_jobs).
     */
    private function validateOrder(): void
    {
        if (empty($this->order->customer_name)) {
            throw new \InvalidArgumentException("Order #{$this->order->order_ref}: missing customer name.");
        }

        if (empty($this->order->customer_email) || ! filter_var($this->order->customer_email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException("Order #{$this->order->order_ref}: invalid or missing customer email.");
        }

        if (empty($this->order->line_items) || ! is_array($this->order->line_items)) {
            throw new \InvalidArgumentException("Order #{$this->order->order_ref}: no line items found.");
        }

        foreach ($this->order->line_items as $index => $item) {
            if (! isset($item['name'], $item['qty'], $item['unit_price'])) {
                throw new \InvalidArgumentException(
                    "Order #{$this->order->order_ref}: line item {$index} is missing name, qty, or unit_price."
                );
            }

            if (! is_numeric($item['qty']) || $item['qty'] <= 0) {
                throw new \InvalidArgumentException(
                    "Order #{$this->order->order_ref}: line item {$index} has invalid qty."
                );
            }

            if (! is_numeric($item['unit_price']) || $item['unit_price'] < 0) {
                throw new \InvalidArgumentException(
                    "Order #{$this->order->order_ref}: line item {$index} has invalid unit_price."
                );
            }
        }
    }

    /**
     * Calculates subtotal, 20% VAT, and total — all stored as pence (integers).
     *
     * Why multiply by 100 and round?
     * CSV prices come in as strings like "9.99". Multiplying by 100 gives
     * 999 pence exactly. We use intval(round(...)) to ensure we never store
     * a fractional pence value.
     *
     * VAT rate is 20% — standard UK rate.
     */
    private function calculateTotals(): void
    {
        $subtotalPence = 0;

        foreach ($this->order->line_items as $item) {
            // unit_price from CSV is a decimal string e.g. "9.99"
            $unitPence      = intval(round(floatval($item['unit_price']) * 100));
            $subtotalPence += $unitPence * intval($item['qty']);
        }

        $taxPence   = intval(round($subtotalPence * 0.20)); // 20% VAT
        $totalPence = $subtotalPence + $taxPence;

        $this->order->update([
            'subtotal_pence' => $subtotalPence,
            'tax_pence'      => $taxPence,
            'total_pence'    => $totalPence,
        ]);
    }
}
