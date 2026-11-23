@extends('layouts.app')

@section('title', 'Dashboard')
@section('content')
    <div class="mb-8"><p class="text-sm font-semibold text-emerald-700">RINGKASAN</p><h1 class="mt-2 text-3xl font-semibold tracking-tight">Dashboard</h1><p class="mt-2 text-slate-600">Selamat datang, {{ auth()->user()->name }}.</p></div>
    <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"><h2 class="font-semibold">Workspace siap digunakan</h2><p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">Ringkasan keuangan akan ditampilkan setelah sumber data transaksi tersedia. Tidak ada angka operasional contoh yang ditampilkan.</p></div>
@endsection
