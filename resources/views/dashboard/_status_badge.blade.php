{{--
    Reusable status badge.
    Usage: @include('dashboard._status_badge', ['status' => $order->status])
    Optional $extra = additional CSS classes (e.g. for JS targeting)
--}}
@php
    $colours = [
        'pending'    => 'bg-gray-100 text-gray-600',
        'processing' => 'bg-blue-100 text-blue-700',
        'completed'  => 'bg-green-100 text-green-700',
        'failed'     => 'bg-red-100 text-red-700',
        'partial'    => 'bg-yellow-100 text-yellow-700',
    ];
    $labels = [
        'pending'    => 'Pending',
        'processing' => 'Processing',
        'completed'  => 'Completed',
        'failed'     => 'Failed',
        'partial'    => 'Partial',
    ];
    $colour = $colours[$status] ?? 'bg-gray-100 text-gray-600';
    $label  = $labels[$status]  ?? ucfirst($status);
    $extra  = $extra ?? '';
@endphp

<span data-status="{{ $status }}"
      class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium {{ $colour }} {{ $extra }}">
    {{ $label }}
</span>
