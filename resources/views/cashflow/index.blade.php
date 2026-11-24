@extends('layouts.app')

@section('title', 'Cashflow')
@section('breadcrumb', 'Cashflow')

@section('content')
    <div>
        <p class="text-sm font-semibold text-emerald-700">LEDGER KAS</p>
        <h1 class="mt-2 text-3xl font-semibold tracking-tight">Cashflow</h1>
        <p class="mt-2 text-sm text-slate-600">Saldo dihitung dari saldo awal yang disetujui dan seluruh pergerakan ledger.</p>
    </div>

    <section class="mt-6">
        <h2 class="mb-3 text-lg font-semibold">Saldo akun kas</h2>
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @forelse($cashAccounts as $cashAccount)
                <article class="rounded-xl border border-slate-200 bg-white p-4">
                    <p class="text-sm text-slate-600">{{ $cashAccount->name }}{{ $cashAccount->is_active ? '' : ' · Nonaktif' }}</p>
                    <p class="mt-2 text-xl font-semibold {{ str_starts_with($cashAccount->ledger_balance, '-') ? 'text-red-700' : 'text-slate-900' }}">Rp {{ number_format((float) $cashAccount->ledger_balance, 2, ',', '.') }}</p>
                </article>
            @empty
                <p class="text-sm text-slate-500">Belum ada akun kas.</p>
            @endforelse
        </div>
    </section>

    <form method="GET" class="mt-7 grid gap-4 rounded-xl border border-slate-200 bg-white p-4 sm:grid-cols-2 xl:grid-cols-4">
        <label><span class="mb-1 block text-xs font-medium text-slate-600">Cari nomor atau deskripsi</span><input name="search" value="{{ $filters['search'] ?? '' }}" class="w-full rounded-lg border-slate-300 text-sm"></label>
        <label><span class="mb-1 block text-xs font-medium text-slate-600">Dari tanggal</span><input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="w-full rounded-lg border-slate-300 text-sm"></label>
        <label><span class="mb-1 block text-xs font-medium text-slate-600">Sampai tanggal</span><input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="w-full rounded-lg border-slate-300 text-sm"></label>
        <div class="flex items-end gap-2"><button class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium">Terapkan filter</button><a href="{{ route('cashflow.index') }}" class="rounded-lg px-3 py-2 text-sm text-slate-600">Reset</a></div>

        <fieldset class="rounded-lg border border-slate-200 p-3 sm:col-span-2">
            <legend class="px-1 text-xs font-medium text-slate-600">Akun kas (pilih satu atau beberapa)</legend>
            <div class="grid max-h-36 gap-2 overflow-y-auto sm:grid-cols-2 xl:grid-cols-3">
                @foreach($cashAccounts as $cashAccount)
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="cash_account_ids[]" value="{{ $cashAccount->id }}" @checked(in_array($cashAccount->id, array_map('intval', $filters['cash_account_ids'] ?? []), true)) class="rounded border-slate-300 text-emerald-800"><span>{{ $cashAccount->name }}</span></label>
                @endforeach
            </div>
        </fieldset>

        <fieldset class="rounded-lg border border-slate-200 p-3 sm:col-span-2">
            <legend class="px-1 text-xs font-medium text-slate-600">Jenis pergerakan</legend>
            <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                @foreach($movementTypes as $kind => $label)
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="movement_kinds[]" value="{{ $kind }}" @checked(in_array($kind, $filters['movement_kinds'] ?? [], true)) class="rounded border-slate-300 text-emerald-800"><span>{{ $label }}</span></label>
                @endforeach
            </div>
        </fieldset>
    </form>

    <section class="mt-6 overflow-hidden rounded-xl border border-slate-200 bg-white">
        <div class="border-b border-slate-100 px-4 py-4 sm:px-5"><h2 class="font-semibold">Pergerakan kas</h2><p class="mt-1 text-sm text-slate-600">Saldo berjalan memperhitungkan seluruh riwayat akun, termasuk sebelum tanggal filter.</p></div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500"><tr><th class="px-4 py-3">Tanggal</th><th class="px-4 py-3">Nomor / Deskripsi</th><th class="px-4 py-3">Jenis</th><th class="px-4 py-3">Akun</th><th class="px-4 py-3 text-right">Kas masuk</th><th class="px-4 py-3 text-right">Kas keluar</th><th class="px-4 py-3 text-right">Saldo berjalan</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($movements as $movement)
                        <tr>
                            <td class="whitespace-nowrap px-4 py-4 text-slate-600">{{ \Illuminate\Support\Carbon::parse($movement->occurred_at)->format('d M Y H:i') }}</td>
                            <td class="max-w-sm px-4 py-4">@if($movement->transaction_public_id)<a href="{{ route('transactions.show', ['transaction' => $movement->transaction_public_id]) }}" class="font-medium text-emerald-900 hover:underline">{{ $movement->transaction_number }}</a>@else<p class="font-medium">{{ $movement->transaction_number }}</p>@endif<p class="mt-1 truncate text-slate-600">{{ $movement->description }}</p></td>
                            <td class="whitespace-nowrap px-4 py-4">{{ $movementTypes[$movement->kind] ?? $movement->kind }}</td>
                            <td class="whitespace-nowrap px-4 py-4">{{ $movement->cash_account_name }}</td>
                            <td class="whitespace-nowrap px-4 py-4 text-right">{{ $movement->direction === 'in' ? 'Rp '.number_format((float) $movement->amount, 2, ',', '.') : '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-4 text-right">{{ $movement->direction === 'out' ? 'Rp '.number_format((float) $movement->amount, 2, ',', '.') : '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-4 text-right font-semibold">Rp {{ number_format((float) $movement->running_balance, 2, ',', '.') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-12 text-center text-slate-500">Belum ada pergerakan kas yang sesuai filter.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
    <div class="mt-5">{{ $movements->links() }}</div>
@endsection
