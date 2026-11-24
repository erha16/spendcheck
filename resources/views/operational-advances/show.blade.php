<div>
    <!-- Simplicity is the essence of happiness. - Cedric Bledsoe -->
</div>
@extends('layouts.app')

@section('title', $advance->number)
@section('breadcrumb', 'Bon operasional / '.$advance->number)

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
    <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
        <div><p class="text-sm font-semibold text-emerald-700">BON OPERASIONAL</p><h1 class="mt-2 text-3xl font-semibold tracking-tight">{{ $advance->number }}</h1><p class="mt-2 text-sm text-slate-600">{{ $advance->purpose }}</p></div>
        <span class="w-fit rounded-full bg-slate-100 px-3 py-1.5 text-sm font-medium text-slate-700">{{ $statusLabels[$advance->status] ?? $advance->status }}</span>
    </div>

    <div class="mt-6 grid gap-5 lg:grid-cols-[minmax(0,1fr)_20rem]">
        <div class="space-y-5">
            <section class="grid gap-4 rounded-xl border border-slate-200 bg-white p-5 sm:grid-cols-2">
                <div><p class="text-xs text-slate-500">Pemohon</p><p class="mt-1 font-medium">{{ $advance->requester?->name }}</p></div>
                <div><p class="text-xs text-slate-500">Penerima / penanggung jawab</p><p class="mt-1 font-medium">{{ $advance->responsibleUser?->name }}</p></div>
                <div><p class="text-xs text-slate-500">Nominal diminta</p><p class="mt-1 font-semibold">Rp {{ number_format((float) $advance->requested_amount, 0, ',', '.') }}</p></div>
                <div><p class="text-xs text-slate-500">Nominal disetujui</p><p class="mt-1 font-semibold">{{ $advance->approved_amount ? 'Rp '.number_format((float) $advance->approved_amount, 0, ',', '.') : 'Belum disetujui' }}</p></div>
                <div><p class="text-xs text-slate-500">Batas waktu</p><p class="mt-1">{{ $advance->due_date->format('d M Y') }}</p></div>
                <div><p class="text-xs text-slate-500">Akun kas rencana</p><p class="mt-1">{{ $advance->plannedCashAccount?->name }}</p></div>
                <div><p class="text-xs text-slate-500">Kegiatan</p><p class="mt-1">{{ $advance->activity?->name ?? '—' }}</p></div>
                <div><p class="text-xs text-slate-500">Dibuat oleh</p><p class="mt-1">{{ $advance->creator?->name }}</p></div>
                @if($advance->mandate_reason)<div class="sm:col-span-2"><p class="text-xs text-slate-500">Alasan mandat</p><p class="mt-1 whitespace-pre-line">{{ $advance->mandate_reason }}</p></div>@endif
                @if($advance->revision_reason)<div class="sm:col-span-2 rounded-lg bg-amber-50 p-3"><p class="text-xs font-medium text-amber-900">Perlu revisi</p><p class="mt-1 whitespace-pre-line text-sm text-amber-900">{{ $advance->revision_reason }}</p></div>@endif
                @if($advance->rejection_reason)<div class="sm:col-span-2 rounded-lg bg-red-50 p-3"><p class="text-xs font-medium text-red-900">Alasan penolakan</p><p class="mt-1 whitespace-pre-line text-sm text-red-900">{{ $advance->rejection_reason }}</p></div>@endif
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-5"><h2 class="font-semibold">Lampiran pengajuan</h2>
                @if($advance->attachments->isEmpty())<p class="mt-3 text-sm text-slate-500">Belum ada lampiran.</p>@else
                    <ul class="mt-3 divide-y divide-slate-100">@foreach($advance->attachments as $attachment)<li class="flex flex-wrap items-center justify-between gap-3 py-3"><div><p class="font-medium">{{ $attachment->original_name }}</p><p class="mt-1 text-xs text-slate-500">{{ $attachment->uploader?->name }} · {{ number_format($attachment->size_bytes / 1024, 0) }} KB</p></div><div class="flex gap-3 text-sm"><a href="{{ route('operational-advances.attachments', ['operationalAdvance' => $advance, 'attachment' => $attachment, 'mode' => 'preview']) }}" target="_blank" rel="noopener" class="text-emerald-800 underline">Lihat</a><a href="{{ route('operational-advances.attachments', ['operationalAdvance' => $advance, 'attachment' => $attachment, 'mode' => 'download']) }}" class="text-emerald-800 underline">Unduh</a></div></li>@endforeach</ul>
                @endif
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-5"><h2 class="font-semibold">Riwayat tindakan</h2><ol class="mt-4 space-y-4">@forelse($auditLogs as $entry)<li class="border-l-2 border-emerald-200 pl-4"><p class="text-sm font-medium">{{ $entry->description }}</p><p class="mt-1 text-xs text-slate-500">{{ $entry->user?->name ?? 'Sistem' }} · {{ $entry->created_at->format('d M Y H:i') }}</p>@if(data_get($entry->new_values, 'reason'))<p class="mt-2 whitespace-pre-line text-sm text-slate-600">{{ data_get($entry->new_values, 'reason') }}</p>@endif</li>@empty<li class="text-sm text-slate-500">Belum ada riwayat tindakan.</li>@endforelse</ol></section>
        </div>

        <aside class="space-y-4">
            @if($canEdit)<a href="{{ route('operational-advances.edit', $advance) }}" class="block rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-center text-sm font-semibold">Ubah draft</a>@endif
            @if(in_array($advance->status, ['draft', 'revision_requested'], true) && (auth()->user()->can('operational_advances.submit') && ($advance->isParticipant(auth()->user()) || $advance->created_by === auth()->id())))
                <form method="POST" action="{{ route('operational-advances.submit', $advance) }}" class="rounded-xl border border-slate-200 bg-white p-4">@csrf<p class="text-sm text-slate-600">Ajukan bon untuk diperiksa Keuangan Sarpras.</p><button class="mt-3 w-full rounded-lg bg-emerald-800 px-3 py-2.5 text-sm font-semibold text-white">Ajukan bon</button></form>
            @endif
            @if($canReview)
                <section class="space-y-4 rounded-xl border border-slate-200 bg-white p-4"><h2 class="font-semibold">Pemeriksaan Keuangan</h2>
                    @can('operational_advances.approve')
                        <form method="POST" action="{{ route('operational-advances.approve', $advance) }}" class="space-y-2">@csrf<label class="block text-sm font-medium">Nominal disetujui (Rp)<input name="approved_amount" value="{{ $advance->requested_amount }}" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label><button class="w-full rounded-lg bg-emerald-800 px-3 py-2 text-sm font-semibold text-white">Setujui pengajuan</button><p class="text-xs text-slate-500">Persetujuan belum mencairkan dana.</p></form>
                    @endcan
                    <form method="POST" action="{{ route('operational-advances.review', $advance) }}" class="space-y-2 border-t border-slate-100 pt-4">@csrf<label class="block text-sm font-medium">Alasan</label><textarea name="reason" required rows="3" class="w-full rounded-lg border-slate-300 text-sm"></textarea><div class="flex gap-2"><button name="decision" value="revision_requested" class="flex-1 rounded-lg border border-amber-300 px-3 py-2 text-sm font-semibold text-amber-900">Minta revisi</button><button name="decision" value="rejected" class="flex-1 rounded-lg border border-red-300 px-3 py-2 text-sm font-semibold text-red-900">Tolak</button></div></form>
                </section>
            @endif
            @if($canCancel)<form method="POST" action="{{ route('operational-advances.cancel', $advance) }}" class="space-y-2 rounded-xl border border-slate-200 bg-white p-4">@csrf<label class="block text-sm font-medium">Alasan pembatalan<textarea name="reason" required rows="2" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></textarea></label><button class="w-full rounded-lg border border-red-300 px-3 py-2 text-sm font-semibold text-red-900">Batalkan sebelum pencairan</button></form>@endif
            @if($advance->status === 'approved')<p class="rounded-lg bg-sky-50 p-4 text-sm text-sky-900">Bon disetujui dan menunggu pencairan. Pencatatan kas belum tersedia pada Phase 3B.1.</p>@endif
        </aside>
    </div>
@endsection
