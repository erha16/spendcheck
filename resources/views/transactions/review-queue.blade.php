@extends('layouts.app')

@section('title', 'Antrean pemeriksaan')
@section('breadcrumb', 'Transaksi / Pemeriksaan')

@section('content')
    <div>
        <p class="text-sm font-semibold text-emerald-700">KEUANGAN SARPRAS</p>
        <h1 class="mt-2 text-3xl font-semibold tracking-tight">Antrean pemeriksaan</h1>
        <p class="mt-2 text-sm text-slate-600">Transaksi yang diajukan dan belum diputuskan.</p>
    </div>
    <div class="mt-6 overflow-hidden rounded-xl border border-slate-200 bg-white">
        <div class="overflow-x-auto"><table class="min-w-full text-left text-sm"><thead class="bg-slate-50 text-xs uppercase text-slate-500"><tr><th class="px-4 py-3">Nomor / Deskripsi</th><th class="px-4 py-3">Jenis</th><th class="px-4 py-3">Pembuat</th><th class="px-4 py-3">Diajukan</th><th class="px-4 py-3 text-right">Total</th><th class="px-4 py-3"></th></tr></thead><tbody class="divide-y divide-slate-100">
            @forelse($transactions as $transaction)<tr><td class="max-w-sm px-4 py-4"><a href="{{ route('transactions.show', $transaction) }}" class="font-semibold text-emerald-900 hover:underline">{{ $transaction->number }}</a><p class="mt-1 truncate text-slate-600">{{ $transaction->description }}</p></td><td class="px-4 py-4">{{ ['income' => 'Pemasukan', 'expense' => 'Pengeluaran', 'transfer' => 'Transfer'][$transaction->type] ?? $transaction->type }}</td><td class="px-4 py-4">{{ $transaction->creator?->name }}</td><td class="whitespace-nowrap px-4 py-4">{{ $transaction->submitted_at?->format('d M Y H:i') }}</td><td class="whitespace-nowrap px-4 py-4 text-right">Rp {{ number_format((float) $transaction->total_amount, 2, ',', '.') }}</td><td class="px-4 py-4 text-right"><a href="{{ route('transactions.show', $transaction) }}" class="font-medium text-emerald-800">Periksa</a></td></tr>
            @empty<tr><td colspan="6" class="px-4 py-12 text-center text-slate-500">Tidak ada transaksi dalam antrean.</td></tr>@endforelse
        </tbody></table></div>
    </div>
    <div class="mt-5">{{ $transactions->links() }}</div>
@endsection
