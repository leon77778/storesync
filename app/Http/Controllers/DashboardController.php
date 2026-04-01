<?php

namespace App\Http\Controllers;

use App\Models\ImportBatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * Show the main dashboard.
     * GET /dashboard
     *
     * Loads all import batches, most recent first.
     * Each batch eager-loads its orders so we don't fire N+1 queries
     * (one query per batch) as the view iterates over them.
     */
    public function index(): View
    {
        $batches = ImportBatch::with('orders')
            ->latest()
            ->paginate(10);

        return view('dashboard.index', compact('batches'));
    }

    /**
     * Polling endpoint — returns live order statuses for one batch as JSON.
     * GET /api/batches/{batch}/status
     *
     * The dashboard JavaScript calls this every 4 seconds and updates
     * the status badges without reloading the whole page.
     *
     * Returns a lightweight payload — only what the frontend needs.
     */
    public function batchStatus(ImportBatch $batch): JsonResponse
    {
        return response()->json([
            // Batch-level summary
            'batch' => [
                'id'              => $batch->id,
                'status'          => $batch->status,
                'total_rows'      => $batch->total_rows,
                'completed_rows'  => $batch->completed_rows,
                'failed_rows'     => $batch->failed_rows,
                'pending_rows'    => $batch->pendingRows(),
                'progress_percent'=> $batch->progressPercent(),
            ],
            // Per-order statuses — frontend uses these to update each row's badge
            'orders' => $batch->orders->map(fn (object $order) => [
                'id'            => $order->id,
                'order_ref'     => $order->order_ref,
                'status'        => $order->status,
                'total'         => $order->formattedTotal(),
                'error_message' => $order->error_message,
            ]),
        ]);
    }
}
