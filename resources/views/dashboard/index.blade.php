@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')

<div class="flex items-center justify-between mb-6">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Import Dashboard</h1>
        <p class="text-sm text-gray-500 mt-0.5">Live job status updates every 4 seconds.</p>
    </div>
    <a href="{{ route('import.create') }}"
       class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold px-4 py-2 rounded-lg transition-colors">
        + Upload CSV
    </a>
</div>

{{-- Empty state --}}
@if ($batches->isEmpty())
    <div class="text-center py-20 text-gray-400">
        <svg class="w-12 h-12 mx-auto mb-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                  d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414A1 1 0 0119 9.414V19a2 2 0 01-2 2z"/>
        </svg>
        <p class="font-medium">No imports yet.</p>
        <p class="text-sm mt-1">
            <a href="{{ route('import.create') }}" class="text-blue-600 hover:underline">Upload your first CSV</a>
            to get started.
        </p>
    </div>
@endif

{{-- One card per ImportBatch --}}
@foreach ($batches as $batch)
<div class="bg-white rounded-xl border border-gray-200 shadow-sm mb-6"
     data-batch-id="{{ $batch->id }}"
     data-poll-url="{{ route('api.batch.status', $batch) }}">

    {{-- Batch header --}}
    <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100">
        <div>
            <span class="font-semibold text-gray-800">{{ $batch->filename }}</span>
            <span class="text-xs text-gray-400 ml-2">{{ $batch->created_at->diffForHumans() }}</span>
        </div>
        <div class="flex items-center gap-3">
            {{-- Progress fraction --}}
            <span class="text-xs text-gray-500 batch-progress">
                {{ $batch->completed_rows + $batch->failed_rows }} / {{ $batch->total_rows }} done
            </span>
            {{-- Overall batch status badge --}}
            @include('dashboard._status_badge', ['status' => $batch->status, 'extra' => 'batch-status-badge'])
        </div>
    </div>

    {{-- Progress bar --}}
    <div class="h-1.5 bg-gray-100 rounded-b-none rounded-t-none overflow-hidden">
        <div class="h-full bg-blue-500 transition-all duration-500 batch-progress-bar"
             style="width: {{ $batch->progressPercent() }}%"></div>
    </div>

    {{-- Orders table --}}
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-xs text-gray-400 uppercase tracking-wider border-b border-gray-100">
                    <th class="px-5 py-3 text-left font-medium">Order Ref</th>
                    <th class="px-5 py-3 text-left font-medium">Customer</th>
                    <th class="px-5 py-3 text-left font-medium">Items</th>
                    <th class="px-5 py-3 text-left font-medium">Email</th>
                    <th class="px-5 py-3 text-right font-medium">Total</th>
                    <th class="px-5 py-3 text-center font-medium">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50 order-rows">
                @foreach ($batch->orders as $order)
                <tr data-order-id="{{ $order->id }}">
                    <td class="px-5 py-3 font-mono text-gray-700">{{ $order->order_ref }}</td>
                    <td class="px-5 py-3 text-gray-800">{{ $order->customer_name }}</td>
                    <td class="px-5 py-3 text-gray-600">
                        {{-- line_items is a PHP array thanks to the 'array' cast on the model.
                             We loop through and show "qty × name" per item, joined by commas. --}}
                        {{ collect($order->line_items)->map(fn($i) => $i['qty'] . ' × ' . $i['name'])->join(', ') }}
                    </td>
                    <td class="px-5 py-3 text-gray-500">{{ $order->customer_email }}</td>
                    <td class="px-5 py-3 text-right font-medium text-gray-800 order-total">
                        {{ $order->formattedTotal() ?: '—' }}
                    </td>
                    <td class="px-5 py-3 text-center">
                        <span class="order-status-badge" data-status="{{ $order->status }}">
                            @include('dashboard._status_badge', ['status' => $order->status])
                        </span>
                        @if ($order->isFailed() && $order->error_message)
                            <p class="text-xs text-red-500 mt-1 max-w-xs mx-auto truncate order-error"
                               title="{{ $order->error_message }}">
                                {{ $order->error_message }}
                            </p>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endforeach

{{-- Pagination --}}
@if ($batches->hasPages())
    <div class="mt-4">{{ $batches->links() }}</div>
@endif


{{-- =====================================================================
     Polling script
     Calls /api/batches/{id}/status every 4 seconds for any batch that
     isn't fully finished yet, then updates the DOM without a page reload.
     ===================================================================== --}}
<script>
document.addEventListener('DOMContentLoaded', () => {

    /**
     * Map a status string to the right Tailwind badge classes.
     * Must stay in sync with the _status_badge partial.
     */
    const badgeClasses = {
        pending:    'inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600',
        processing: 'inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-700',
        completed:  'inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700',
        failed:     'inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-700',
        partial:    'inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-700',
    };

    const badgeLabels = {
        pending: 'Pending', processing: 'Processing',
        completed: 'Completed', failed: 'Failed', partial: 'Partial',
    };

    function makeBadge(status) {
        const span = document.createElement('span');
        span.className = badgeClasses[status] ?? badgeClasses.pending;
        span.textContent = badgeLabels[status] ?? status;
        return span;
    }

    // Find all batch cards that are not yet fully finished
    function getActiveBatchCards() {
        return [...document.querySelectorAll('[data-batch-id]')].filter(card => {
            const badge = card.querySelector('.batch-status-badge');
            const status = badge?.dataset?.status ?? '';
            return !['completed', 'failed', 'partial'].includes(status);
        });
    }

    function pollBatch(card) {
        const url = card.dataset.pollUrl;

        fetch(url)
            .then(r => r.json())
            .then(data => {
                const { batch, orders } = data;

                // Update progress fraction text
                const done = batch.completed_rows + batch.failed_rows;
                const progressText = card.querySelector('.batch-progress');
                if (progressText) progressText.textContent = `${done} / ${batch.total_rows} done`;

                // Update progress bar width
                const bar = card.querySelector('.batch-progress-bar');
                if (bar) bar.style.width = `${batch.progress_percent}%`;

                // Update batch status badge
                const batchBadgeWrap = card.querySelector('.batch-status-badge');
                if (batchBadgeWrap) {
                    batchBadgeWrap.dataset.status = batch.status;
                    batchBadgeWrap.replaceChildren(makeBadge(batch.status));
                }

                // Update each order row
                orders.forEach(order => {
                    const row = card.querySelector(`[data-order-id="${order.id}"]`);
                    if (!row) return;

                    // Update total
                    const totalCell = row.querySelector('.order-total');
                    if (totalCell && order.total !== '£0.00') {
                        totalCell.textContent = order.total;
                    }

                    // Update status badge
                    const statusWrap = row.querySelector('.order-status-badge');
                    if (statusWrap && statusWrap.dataset.status !== order.status) {
                        statusWrap.dataset.status = order.status;
                        statusWrap.replaceChildren(makeBadge(order.status));
                    }

                    // Show error message on failure
                    if (order.status === 'failed' && order.error_message) {
                        let errEl = row.querySelector('.order-error');
                        if (!errEl) {
                            errEl = document.createElement('p');
                            errEl.className = 'text-xs text-red-500 mt-1 max-w-xs mx-auto truncate order-error';
                            statusWrap?.after(errEl);
                        }
                        errEl.textContent = order.error_message;
                        errEl.title = order.error_message;
                    }
                });
            })
            .catch(() => { /* silently ignore network errors — next poll will retry */ });
    }

    // Poll every 4 seconds; stop polling a card once its batch is finished
    setInterval(() => {
        getActiveBatchCards().forEach(pollBatch);
    }, 4000);

});
</script>

@endsection
