<!DOCTYPE html>
<html lang="en" class="h-full bg-gray-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'StoreSync') — StoreSync</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full font-sans antialiased text-gray-800">

    {{-- Top navigation bar --}}
    <nav class="bg-white border-b border-gray-200 shadow-sm">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 flex items-center justify-between h-14">
            <a href="{{ route('dashboard.index') }}" class="text-lg font-bold tracking-tight text-blue-700">
                StoreSync
            </a>
            <div class="flex items-center gap-6 text-sm font-medium">
                <a href="{{ route('dashboard.index') }}"
                   class="text-gray-600 hover:text-blue-700 transition-colors
                          {{ request()->routeIs('dashboard.*') ? 'text-blue-700 font-semibold' : '' }}">
                    Dashboard
                </a>
                <a href="{{ route('import.create') }}"
                   class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-1.5 rounded-lg transition-colors
                          {{ request()->routeIs('import.*') ? 'bg-blue-700' : '' }}">
                    Upload CSV
                </a>
            </div>
        </div>
    </nav>

    {{-- Flash messages (success / error) --}}
    @if (session('success'))
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 mt-4">
            <div class="bg-green-50 border border-green-200 text-green-800 rounded-lg px-4 py-3 text-sm">
                {{ session('success') }}
            </div>
        </div>
    @endif

    @if ($errors->any())
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 mt-4">
            <div class="bg-red-50 border border-red-200 text-red-800 rounded-lg px-4 py-3 text-sm space-y-1">
                @foreach ($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Main page content --}}
    <main class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        @yield('content')
    </main>

</body>
</html>
