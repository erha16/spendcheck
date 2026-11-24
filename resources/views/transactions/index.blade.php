@extends('layouts.app')

@section('title', 'Transaksi')
@section('breadcrumb', 'Transaksi')

@section('content')
    <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
        <div>
            <p class="text-sm font-semibold text-emerald-700">KEUANGAN</p>
            <h1 class="mt-2 text-3xl font-semibold tracking-tight">Transaksi</h1>
            <p class="mt-2 text-sm text-slate-600">Draft, pemeriksaan, dan riwayat transaksi pemasukan serta pengeluaran.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            @can('transactions.review')
                <a href="{{ route('transactions.review-queue') }}" class="rounded-lg border border-emerald-800 px-4 py-2.5 text-sm font-semibold text-emerald-900">Antrean pemeriksaan</a>
            @endcan
            @can('transactions.income.create')
                <a href="{{ route('transactions.create', ['type' => 'income']) }}" class="rounded-lg bg-emerald-800 px-4 py-2.5 text-sm font-semibold text-white">Buat pemasukan</a>
            @endcan
            @can('transactions.expense.create')
                <a href="{{ route('transactions.create', ['type' => 'expense']) }}" class="rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white">Buat pengeluaran</a>
            @endcan
            @can('transactions.transfer.create')
                <a href="{{ route('transactions.index', ['type' => 'transfer']) }}" class="rounded-lg border border-sky-700 px-4 py-2.5 text-sm font-semibold text-sky-900">Daftar transfer</a>
                <a href="{{ route('transactions.create', ['type' => 'transfer']) }}" class="rounded-lg border border-sky-700 px-4 py-2.5 text-sm font-semibold text-sky-900">Buat transfer</a>
            @endcan
        </div>
    </div>

    <form method="GET" class="mt-6 grid gap-3 rounded-xl border border-slate-200 bg-white p-4 sm:grid-cols-2 xl:grid-cols-6">
        <label class="xl:col-span-2"><span class="mb-1 block text-xs font-medium text-slate-600">Cari nomor atau deskripsi</span><input name="search" value="{{ $filters['search'] ?? '' }}" class="w-full rounded-lg border-slate-300 text-sm"></label>
        <label><span class="mb-1 block text-xs font-medium text-slate-600">Jenis</span><select name="type" class="w-full rounded-lg border-slate-300 text-sm"><option value="">Semua</option><option value="income" @selected(($filters['type'] ?? '') === 'income')>Pemasukan</option><option value="expense" @selected(($filters['type'] ?? '') === 'expense')>Pengeluaran</option><option value="transfer" @selected(($filters['type'] ?? '') === 'transfer')>Transfer</option></select></label>
        <label><span class="mb-1 block text-xs font-medium text-slate-600">Status</span><select name="status" class="w-full rounded-lg border-slate-300 text-sm"><option value="">Semua</option>@foreach(['draft' => 'Draft', 'submitted' => 'Diajukan', 'revision_requested' => 'Perlu revisi', 'rejected' => 'Ditolak', 'posted' => 'Posted', 'reversed' => 'Dikoreksi', 'cancelled' => 'Dibatalkan'] as $value => $label)<option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></label>
        <label><span class="mb-1 block text-xs font-medium text-slate-600">Dari tanggal</span><input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="w-full rounded-lg border-slate-300 text-sm"></label>
        <label><span class="mb-1 block text-xs font-medium text-slate-600">Sampai tanggal</span><input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="w-full rounded-lg border-slate-300 text-sm"></label>
        @if($responsibleUsers->isNotEmpty())
            <label><span class="mb-1 block text-xs font-medium text-slate-600">Penanggung jawab</span><select name="responsible_user_id" class="w-full rounded-lg border-slate-300 text-sm"><option value="">Semua</option>@foreach($responsibleUsers as $responsibleUser)<option value="{{ $responsibleUser->id }}" @selected((string) ($filters['responsible_user_id'] ?? '') === (string) $responsibleUser->id)>{{ $responsibleUser->name }}</option>@endforeach</select></label>
        @endif
        <div class="flex flex-wrap items-end gap-2 xl:col-span-6"><button class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium">Terapkan filter</button><a href="{{ route('transactions.index') }}" class="rounded-lg px-3 py-2 text-sm text-slate-600">Reset</a></div>
    </form>

    <div class="mt-5 overflow-hidden rounded-xl border border-slate-200 bg-white">
        <div class="overflow-x-auto">
            <table class="min-w-full text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500"><tr><th class="px-4 py-3">Nomor / Deskripsi</th><th class="px-4 py-3">Tanggal</th><th class="px-4 py-3">Jenis</th><th class="px-4 py-3">Penanggung jawab</th><th class="px-4 py-3 text-right">Total</th><th class="px-4 py-3">Status</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($transactions as $transaction)
                        <tr>
                            <td class="max-w-sm px-4 py-4"><a href="{{ route('transactions.show', $transaction) }}" class="font-semibold text-emerald-900 hover:underline">{{ $transaction->number }}</a><p class="mt-1 truncate text-slate-600">{{ $transaction->description }}</p></td>
                            <td class="whitespace-nowrap px-4 py-4 text-slate-600">{{ $transaction->occurred_at->format('d M Y') }}</td>
                            <td class="px-4 py-4">{{ ['income' => 'Pemasukan', 'expense' => 'Pengeluaran', 'transfer' => 'Transfer'][$transaction->type] ?? $transaction->type }}</td>
                            <td class="px-4 py-4">{{ $transaction->responsibleUser?->name ?? '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-4 text-right font-medium">Rp {{ number_format((float) $transaction->total_amount, 2, ',', '.') }}</td>
                            <td class="whitespace-nowrap px-4 py-4"><span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700">{{ ['draft' => 'Draft', 'submitted' => 'Diajukan', 'revision_requested' => 'Perlu revisi', 'rejected' => 'Ditolak', 'posted' => 'Posted', 'reversed' => 'Dikoreksi', 'cancelled' => 'Dibatalkan'][$transaction->status] ?? $transaction->status }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-12 text-center text-slate-500">Belum ada transaksi yang sesuai dengan filter.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-5">{{ $transactions->links() }}</div>
@endsection
