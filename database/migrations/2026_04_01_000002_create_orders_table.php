<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each row represents one order parsed from a CSV row.
     * One order = one dispatched ProcessOrderJob.
     */
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();

            // Links back to the CSV upload that created this order
            $table->foreignId('import_batch_id')
                  ->constrained('import_batches')
                  ->cascadeOnDelete();

            // Identifier from the CSV (e.g. "ORD-1042") — not necessarily unique
            // across all uploads, so we don't add a unique constraint here
            $table->string('order_ref');

            // Customer details sourced from the CSV
            $table->string('customer_name');
            $table->string('customer_email');

            // Raw line items from the CSV stored as a JSON array.
            // Expected shape: [{"name":"Widget","qty":2,"unit_price":9.99}, ...]
            // Kept as JSON so we avoid a separate order_items table while
            // still being able to iterate items inside the job.
            $table->json('line_items');

            // Calculated by ProcessOrderJob — stored in pence/cents (integer)
            // to avoid floating-point rounding issues.
            // e.g. £19.98 is stored as 1998
            $table->unsignedBigInteger('subtotal_pence')->default(0);
            $table->unsignedBigInteger('tax_pence')->default(0);
            $table->unsignedBigInteger('total_pence')->default(0);

            // Job lifecycle status for this individual order
            // pending    = job dispatched, not yet picked up
            // processing = job currently running
            // completed  = job finished successfully, email sent
            // failed     = job exhausted all retries
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])
                  ->default('pending')
                  ->index(); // indexed for fast dashboard queries

            // Stores the exception message on failure — shown in dashboard
            $table->text('error_message')->nullable();

            // Set when the job completes or permanently fails
            $table->timestamp('processed_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
