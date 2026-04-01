<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportBatch extends Model
{
    /**
     * The columns we allow to be mass-assigned (e.g. when calling
     * ImportBatch::create([...]) in the controller).
     */
    protected $fillable = [
        'filename',
        'total_rows',
        'completed_rows',
        'failed_rows',
        'status',
    ];

    /**
     * Tell Laravel how to automatically cast raw database values
     * into the correct PHP types when we read them.
     */
    protected $casts = [
        'total_rows'     => 'integer',
        'completed_rows' => 'integer',
        'failed_rows'    => 'integer',
    ];

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /**
     * One ImportBatch has many Orders (one per CSV row).
     * Usage: $batch->orders
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    // -------------------------------------------------------------------------
    // Computed helpers
    // -------------------------------------------------------------------------

    /**
     * How many rows are still waiting to be processed.
     * pending = total - completed - failed
     */
    public function pendingRows(): int
    {
        return max(0, $this->total_rows - $this->completed_rows - $this->failed_rows);
    }

    /**
     * Percentage of rows that have finished (success or failure).
     * Returns 0–100 as an integer.
     */
    public function progressPercent(): int
    {
        if ($this->total_rows === 0) {
            return 0;
        }

        $done = $this->completed_rows + $this->failed_rows;

        return (int) round(($done / $this->total_rows) * 100);
    }

    /**
     * Recalculate and save the batch status based on current row counters.
     *
     * Called by ProcessOrderJob each time a job finishes so the dashboard
     * always shows an accurate overall status.
     */
    public function recalculateStatus(): void
    {
        $done = $this->completed_rows + $this->failed_rows;

        if ($done === 0) {
            $status = 'pending';
        } elseif ($done < $this->total_rows) {
            $status = 'processing';
        } elseif ($this->failed_rows === 0) {
            $status = 'completed';
        } elseif ($this->completed_rows === 0) {
            $status = 'failed';
        } else {
            $status = 'partial'; // some succeeded, some failed
        }

        $this->update(['status' => $status]);
    }
}
