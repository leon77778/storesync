<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Order extends Model
{
    /**
     * The columns we allow to be mass-assigned.
     */
    protected $fillable = [
        'import_batch_id',
        'order_ref',
        'customer_name',
        'customer_email',
        'line_items',
        'subtotal_pence',
        'tax_pence',
        'total_pence',
        'status',
        'error_message',
        'processed_at',
    ];

    /**
     * Automatic type casting when reading from the database.
     *
     * 'array' cast means Laravel will automatically JSON-decode line_items
     * into a PHP array when we read it, and JSON-encode it back when we save.
     *
     * 'datetime' cast means processed_at comes back as a Carbon date object
     * so we can easily format it (e.g. $order->processed_at->diffForHumans()).
     */
    protected $casts = [
        'line_items'      => 'array',
        'subtotal_pence'  => 'integer',
        'tax_pence'       => 'integer',
        'total_pence'     => 'integer',
        'processed_at'    => 'datetime',
    ];

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /**
     * Each Order belongs to one ImportBatch (its parent CSV upload).
     * Usage: $order->importBatch
     */
    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class);
    }

    // -------------------------------------------------------------------------
    // Pence → display currency helpers
    // -------------------------------------------------------------------------

    /**
     * Convert an integer pence value to a formatted currency string.
     *
     * Why pence (integers)?
     * Floating-point numbers can't represent money exactly. For example,
     * 0.1 + 0.2 in PHP = 0.30000000000000004. Storing as pence (integers)
     * avoids all rounding errors — we only convert to decimals for display.
     *
     * Examples:
     *   1998  → "£19.98"
     *   500   → "£5.00"
     *   0     → "£0.00"
     */
    public static function penceToCurrency(int $pence, string $symbol = '£'): string
    {
        return $symbol . number_format($pence / 100, 2);
    }

    /**
     * Formatted subtotal for display (e.g. "£19.98").
     */
    public function formattedSubtotal(): string
    {
        return self::penceToCurrency($this->subtotal_pence);
    }

    /**
     * Formatted tax for display (e.g. "£4.00").
     */
    public function formattedTax(): string
    {
        return self::penceToCurrency($this->tax_pence);
    }

    /**
     * Formatted total for display (e.g. "£23.98").
     */
    public function formattedTotal(): string
    {
        return self::penceToCurrency($this->total_pence);
    }

    // -------------------------------------------------------------------------
    // Status helpers
    // -------------------------------------------------------------------------

    /**
     * Quick boolean checks used in Blade views.
     * e.g. @if($order->isCompleted()) ... @endif
     */
    public function isPending(): bool    { return $this->status === 'pending'; }
    public function isProcessing(): bool { return $this->status === 'processing'; }
    public function isCompleted(): bool  { return $this->status === 'completed'; }
    public function isFailed(): bool     { return $this->status === 'failed'; }
}
