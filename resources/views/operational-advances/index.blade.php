<div>
    <!-- He who is contented is rich. - Laozi -->
</div>
@extends('layouts.app')

@section('title', 'Bon operasional')
@section('breadcrumb', 'Bon operasional')

@section('content')
    @php
        $statusLabels = [
            'draft' => 'Draft', 'submitted' => 'Diajukan', 'revision_requested' => 'Perlu revisi',
            'approved' => 'Disetujui, menunggu pencairan', 'disbursed' => 'Dicairkan',
            'settlement_in_review' => 'Pemeriksaan pertanggungjawaban', 'partially_accounted' => 'Sebagian dipertanggungjawabkan',
            'return_due' => 'Menunggu pengembalian', 'reimbursement_due' => 'Menunggu penggantian',
            'settled' => 'Selesai', 'rejected' => 'Ditolak', 'cancelled' => 'Dibatalkan',
        ];
    @endphp

    <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
        <div>
            <p class="text-sm font-semibold text-emerald-700">OPERASIONAL</p>
            <h1 class="mt-2 text-3xl font-semibold tracking-tight">Bon operasional</h1>
            <p class="mt-2 text-sm text-slate-600">Pengajuan bon dan riwayat keputusan. Pencairan belum dilakukan pada tahap ini.</p>
        </div>
        @can('operational_advances.create')
            <a href="{{ route('operational-advances.create') }}" class="rounded-lg bg-emerald-800 px-4 py-2.5 text-sm font-semibold text-white">Ajukan bon</a>
        @endcan
    </div>

    <form method="GET" class="mt-6 grid gap-3 rounded-xl border border-slate-200 bg-white p-4 sm:grid-cols-3">
        <label><span class="mb-1 block text-xs font-medium text-slate-600">Cari nomor atau tujuan</span><input name="search" value="{{ $filters['search'] ?? '' }}" class="w-full rounded-lg border-slate-300 text-sm"></label>
        <label><span class="mb-1 block text-xs font-medium text-slate-600">Status</span><select name="status" class="w-full rounded-lg border-slate-300 text-sm"><option value="">Semua</option>@foreach($statuses as $status)<option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ $statusLabels[$status->value] ?? $status->value }}</option>@endforeach</select></label>
        <div class="flex items-end gap-2"><button class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium">Terapkan</button><a href="{{ route('operational-advances.index') }}" class="rounded-lg px-3 py-2 text-sm text-slate-600">Reset</a></div>
    </form>

    <div class="mt-5 overflow-hidden rounded-xl border border-slate-200 bg-white">
        <div class="overflow-x-auto">
            <table class="min-w-full text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500"><tr><th class="px-4 py-3">Nomor / Tujuan</th><th class="px-4 py-3">Pemohon</th><th class="px-4 py-3">Penerima</th><th class="px-4 py-3">Jatuh tempo</th><th class="px-4 py-3 text-right">Diminta</th><th class="px-4 py-3">Status</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($advances as $advance)
                        <tr>
                            <td class="max-w-sm px-4 py-4"><a href="{{ route('operational-advances.show', $advance) }}" class="font-semibold text-emerald-900 hover:underline">{{ $advance->number }}</a><p class="mt-1 truncate text-slate-600">{{ $advance->purpose }}</p></td>
                            <td class="px-4 py-4">{{ $advance->requester?->name }}</td>
                            <td class="px-4 py-4">{{ $advance->responsibleUser?->name }}</td>
                            <td class="whitespace-nowrap px-4 py-4 text-slate-600">{{ $advance->due_date->format('d M Y') }}</td>
                            <td class="whitespace-nowrap px-4 py-4 text-right font-medium">Rp {{ number_format((float) $advance->requested_amount, 0, ',', '.') }}</td>
                            <td class="whitespace-nowrap px-4 py-4"><span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700">{{ $statusLabels[$advance->status] ?? $advance->status }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-12 text-center text-slate-500">Belum ada bon yang sesuai dengan filter.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-5">{{ $advances->links() }}</div>
@endsection
