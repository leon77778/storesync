@extends('layouts.app')

@section('title', 'Upload Orders')

@section('content')
<div class="max-w-xl mx-auto">

    <h1 class="text-2xl font-bold text-gray-900 mb-1">Upload Orders CSV</h1>
    <p class="text-sm text-gray-500 mb-8">
        Each row in the CSV becomes a background job that validates, calculates, and emails the customer.
    </p>

    {{-- Upload form --}}
    <form action="{{ route('import.store') }}"
          method="POST"
          enctype="multipart/form-data"
          class="bg-white rounded-xl border border-gray-200 shadow-sm p-6 space-y-6">

        @csrf

        {{-- File drop zone --}}
        <div>
            <label for="csv_file" class="block text-sm font-medium text-gray-700 mb-2">
                CSV File
            </label>
            <div class="relative flex flex-col items-center justify-center border-2 border-dashed border-gray-300
                        rounded-lg p-8 text-center hover:border-blue-400 transition-colors cursor-pointer
                        @error('csv_file') border-red-400 @enderror"
                 onclick="document.getElementById('csv_file').click()">

                <svg class="w-10 h-10 text-gray-400 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                          d="M9 13h6m-3-3v6m5 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414A1 1 0 0119 9.414V19a2 2 0 01-2 2z"/>
                </svg>

                <p class="text-sm text-gray-600" id="file-label">
                    <span class="text-blue-600 font-medium">Click to choose a file</span>
                    &nbsp;or drag and drop
                </p>
                <p class="text-xs text-gray-400 mt-1">CSV up to 2MB</p>

                <input id="csv_file"
                       name="csv_file"
                       type="file"
                       accept=".csv,text/plain"
                       class="sr-only"
                       onchange="document.getElementById('file-label').innerHTML =
                           '<span class=\'text-blue-600 font-medium\'>' + this.files[0].name + '</span>'">
            </div>

            @error('csv_file')
                <p class="text-red-600 text-xs mt-1">{{ $message }}</p>
            @enderror
        </div>

        {{-- Expected format hint --}}
        <div class="bg-gray-50 rounded-lg p-4 text-xs text-gray-600 space-y-1">
            <p class="font-semibold text-gray-700 mb-2">Expected CSV format:</p>
            <p class="font-mono bg-white border border-gray-200 rounded px-2 py-1 text-gray-800 overflow-x-auto whitespace-nowrap">
                order_ref, customer_name, customer_email, product_name, quantity, unit_price
            </p>
            <p class="font-mono bg-white border border-gray-200 rounded px-2 py-1 text-gray-800 mt-2 overflow-x-auto whitespace-nowrap">
                ORD-001, Jane Smith, jane@example.com, Blue Widget, 2, 9.99
            </p>
        </div>

        <button type="submit"
                class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2.5 rounded-lg
                       transition-colors text-sm">
            Import Orders
        </button>

    </form>

</div>
@endsection
