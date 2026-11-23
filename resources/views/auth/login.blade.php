<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Masuk · SpendCheck</title>@vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-100 text-slate-900 antialiased">
    <main class="grid min-h-screen lg:grid-cols-2">
        <section class="hidden bg-emerald-950 p-12 text-white lg:flex lg:flex-col lg:justify-between">
            <a href="{{ route('home') }}" class="text-2xl font-bold tracking-tight">SpendCheck</a>
            <div class="max-w-lg"><p class="text-sm font-semibold uppercase tracking-[0.2em] text-emerald-300">Kontrol operasional</p><h1 class="mt-5 text-5xl font-semibold leading-tight">Kelola pengeluaran dengan lebih tertib.</h1><p class="mt-5 text-lg text-emerald-100/80">Satu ruang kerja untuk tim, unit, dan proses persetujuan Anda.</p></div>
            <p class="text-sm text-emerald-100/60">Akses aman untuk pengguna terdaftar.</p>
        </section>
        <section class="flex items-center justify-center px-5 py-12">
            <div class="w-full max-w-md rounded-2xl bg-white p-7 shadow-xl shadow-slate-900/5 sm:p-10">
                <div class="mb-8 lg:hidden"><p class="text-xl font-bold text-emerald-800">SpendCheck</p></div>
                <p class="text-sm font-semibold text-emerald-700">SELAMAT DATANG</p><h2 class="mt-2 text-3xl font-semibold tracking-tight">Masuk ke akun</h2><p class="mt-2 text-sm text-slate-500">Gunakan email dan kata sandi yang terdaftar.</p>
                @if ($errors->any())<div role="alert" class="mt-6 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800">{{ $errors->first() }}</div>@endif
                <form method="POST" action="{{ route('login') }}" class="mt-7 space-y-5">
                    @csrf
                    <div><label for="email" class="mb-2 block text-sm font-medium">Email</label><input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="username" autofocus class="w-full rounded-lg border border-slate-300 px-3 py-3 outline-none transition focus:border-emerald-700 focus:ring-2 focus:ring-emerald-700/15">@error('email')<p class="mt-2 text-sm text-red-700">{{ $message }}</p>@enderror</div>
                    <div><label for="password" class="mb-2 block text-sm font-medium">Kata sandi</label><input id="password" name="password" type="password" required autocomplete="current-password" class="w-full rounded-lg border border-slate-300 px-3 py-3 outline-none transition focus:border-emerald-700 focus:ring-2 focus:ring-emerald-700/15">@error('password')<p class="mt-2 text-sm text-red-700">{{ $message }}</p>@enderror</div>
                    <button class="w-full rounded-lg bg-emerald-800 px-4 py-3 font-semibold text-white transition hover:bg-emerald-900 focus:outline-none focus:ring-2 focus:ring-emerald-700 focus:ring-offset-2">Masuk</button>
                </form>
                <p class="mt-7 text-center text-xs text-slate-500">Jika memerlukan akses, hubungi administrator sistem.</p>
            </div>
        </section>
    </main>
</body>
</html>
