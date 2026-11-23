<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'SpendCheck') · SpendCheck</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 antialiased">
    <div class="min-h-screen lg:flex">
        <aside class="w-full border-b border-slate-200 bg-white px-5 py-5 lg:min-h-screen lg:w-64 lg:border-b-0 lg:border-r">
            <a href="{{ route('dashboard') }}" class="text-xl font-bold tracking-tight text-emerald-800">SpendCheck</a>
            <p class="mt-1 text-xs text-slate-500">Manajemen pengeluaran operasional</p>
            <nav class="mt-8 flex gap-2 overflow-x-auto lg:flex-col">
                <a href="{{ route('dashboard') }}" class="rounded-lg px-3 py-2 text-sm hover:bg-emerald-50">Dashboard</a>
                @can('users.view')<a href="{{ route('admin.users.index') }}" class="rounded-lg px-3 py-2 text-sm hover:bg-emerald-50">Pengguna</a>@endcan
                @can('departments.view')<a href="{{ route('admin.departments.index') }}" class="rounded-lg px-3 py-2 text-sm hover:bg-emerald-50">Unit kerja</a>@endcan
                @can('audit_logs.view')<a href="{{ route('admin.audit-logs.index') }}" class="rounded-lg px-3 py-2 text-sm hover:bg-emerald-50">Audit log</a>@endcan
            </nav>
        </aside>
        <div class="min-w-0 flex-1">
            <header class="flex items-center justify-between border-b border-slate-200 bg-white px-5 py-4 sm:px-8">
                <div><p class="text-xs text-slate-500">{{ auth()->user()->department?->name ?? 'SpendCheck' }}</p><p class="font-medium">{{ auth()->user()->name }}</p></div>
                <form method="POST" action="{{ route('logout') }}">@csrf<button class="rounded-lg border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50">Keluar</button></form>
            </header>
            <main class="px-5 py-8 sm:px-8">
                <p class="mb-4 text-sm text-slate-500">Beranda / @yield('breadcrumb', 'Dashboard')</p>
                @if (session('status'))<div class="mb-5 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>@endif
                @if ($errors->any())<div class="mb-5 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800">{{ $errors->first() }}</div>@endif
                @yield('content')
            </main>
        </div>
    </div>
</body>
</html>
