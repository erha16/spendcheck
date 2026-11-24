@extends('layouts.app')

@section('title', $transaction->number)
@section('breadcrumb', 'Transaksi / '.$transaction->number)

@section('content')
    @php
        $canEdit = in_array($transaction->status, ['draft', 'revision_requested'], true)
            && ($transaction->created_by === auth()->id() || auth()->user()->can('transactions.manage'))
            && ($transaction->status !== 'revision_requested' || $transaction->created_by === auth()->id());
        $canSubmit = $transaction->created_by === auth()->id() && in_array($transaction->status, ['draft', 'revision_requested'], true);
        $statusLabels = ['draft' => 'Draft', 'submitted' => 'Diajukan', 'revision_requested' => 'Perlu revisi', 'rejected' => 'Ditolak', 'posted' => 'Posted', 'reversed' => 'Dikoreksi', 'cancelled' => 'Dibatalkan'];
    @endphp
    <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
        <div><p class="text-sm font-semibold text-emerald-700">{{ ['income' => 'PEMASUKAN', 'expense' => 'PENGELUARAN', 'transfer' => 'TRANSFER ANTAR AKUN'][$transaction->type] ?? $transaction->type }} · {{ $statusLabels[$transaction->status] ?? $transaction->status }}</p><h1 class="mt-2 break-all text-2xl font-semibold sm:text-3xl">{{ $transaction->number }}</h1><p class="mt-2 text-sm text-slate-600">Dibuat {{ $transaction->created_at->format('d M Y H:i') }} oleh {{ $transaction->creator?->name }}</p></div>
        <div class="flex flex-wrap gap-2">
            @if($canEdit)<a href="{{ route('transactions.edit', $transaction) }}" class="rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-semibold">Edit draft</a>@endif
            @if($transaction->status === 'posted' && auth()->user()->can('transactions.correct') && $transaction->replacements->isEmpty())<a href="{{ route('transactions.corrections.create', $transaction) }}" class="rounded-lg border border-amber-300 px-4 py-2.5 text-sm font-semibold text-amber-950">Koreksi transaksi</a>@endif
            @if($canSubmit && auth()->user()->can('transactions.'.$transaction->type.'.create'))<form method="POST" action="{{ route('transactions.submit', $transaction) }}">@csrf<button class="rounded-lg bg-emerald-800 px-4 py-2.5 text-sm font-semibold text-white">Ajukan pemeriksaan</button></form>@endif
            @if($transaction->status === 'draft' && ($transaction->created_by === auth()->id() || auth()->user()->can('transactions.cancel')))
                <form method="POST" action="{{ route('transactions.cancel', $transaction) }}" class="flex gap-2">@csrf<input name="reason" required placeholder="Alasan pembatalan" class="w-44 rounded-lg border-slate-300 text-sm"><button class="rounded-lg border border-red-300 px-3 py-2 text-sm text-red-800">Batalkan</button></form>
            @endif
        </div>
    </div>

    @if($transaction->revision_reason)<div class="mt-5 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950"><strong>Alasan revisi:</strong> {{ $transaction->revision_reason }}</div>@endif
    @if($transaction->rejection_reason)<div class="mt-5 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-900"><strong>Alasan penolakan:</strong> {{ $transaction->rejection_reason }}</div>@endif
    @if($transaction->cancellation_reason)<div class="mt-5 rounded-xl border border-slate-200 bg-slate-100 p-4 text-sm text-slate-800"><strong>Alasan pembatalan:</strong> {{ $transaction->cancellation_reason }}</div>@endif
    @if($transaction->status === 'reversed')<div class="mt-5 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950"><strong>Transaksi ini telah dikoreksi.</strong> @if($transaction->replacements->isNotEmpty()) Pengganti: @foreach($transaction->replacements as $replacement)@if(!auth()->user()->hasRole('petugas_sarpras') || $replacement->created_by === auth()->id())<a class="underline" href="{{ route('transactions.show', $replacement) }}">{{ $replacement->number }}</a>@else{{ $replacement->number }}@endif @endforeach @endif</div>@endif
    @if($transaction->reversalOf)<div class="mt-5 rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-950">Transaksi pengganti dari @if(!auth()->user()->hasRole('petugas_sarpras') || $transaction->reversalOf->created_by === auth()->id())<a class="underline" href="{{ route('transactions.show', $transaction->reversalOf) }}">{{ $transaction->reversalOf->number }}</a>@else{{ $transaction->reversalOf->number }}@endif.</div>@endif

    @if($transaction->revisions->isNotEmpty() || $transaction->replacementRevision)<section class="mt-5 rounded-xl border border-slate-200 bg-white p-5"><h2 class="font-semibold">Riwayat koreksi</h2><ol class="mt-3 space-y-4">@foreach($transaction->revisions as $revision)<li class="border-l-2 border-amber-300 pl-4 text-sm"><p class="font-medium">Revisi {{ $revision->revision_number }} · {{ $revision->corrected_at->format('d M Y H:i') }} oleh {{ $revision->corrector?->name }}</p><p class="mt-1">Alasan: {{ $revision->reason }}</p><p class="mt-1">{{ $transaction->number }} → @if(!auth()->user()->hasRole('petugas_sarpras') || $revision->replacement?->created_by === auth()->id())<a class="underline" href="{{ route('transactions.show', $revision->replacement) }}">{{ $revision->replacement?->number }}</a>@else{{ $revision->replacement?->number }}@endif</p></li>@endforeach @if($transaction->replacementRevision)<li class="border-l-2 border-sky-300 pl-4 text-sm"><p class="font-medium">Pengganti dari transaksi terdahulu</p><p class="mt-1">Alasan: {{ $transaction->replacementRevision->reason }} · {{ $transaction->replacementRevision->corrected_at->format('d M Y H:i') }} oleh {{ $transaction->replacementRevision->corrector?->name }}</p></li>@endif</ol></section>@endif

    <div class="mt-6 grid gap-5 xl:grid-cols-[minmax(0,1fr)_20rem]">
        <div class="space-y-5">
            <section class="rounded-xl border border-slate-200 bg-white p-5 sm:p-6">
                <h2 class="text-lg font-semibold">Ringkasan</h2>
                <dl class="mt-4 grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
                    <div><dt class="text-slate-500">Tanggal kejadian</dt><dd class="mt-1 font-medium">{{ $transaction->occurred_at->format('d M Y H:i') }}</dd></div>
                    @if($transaction->type !== 'transfer')<div><dt class="text-slate-500">Penanggung jawab</dt><dd class="mt-1 font-medium">{{ $transaction->responsibleUser?->name ?? '—' }}</dd></div>@endif
                    @if($transaction->type === 'transfer')
                        <div><dt class="text-slate-500">Akun kas sumber</dt><dd class="mt-1 font-medium">{{ $transaction->sourceCashAccount?->name }}</dd></div>
                        <div><dt class="text-slate-500">Akun kas tujuan</dt><dd class="mt-1 font-medium">{{ $transaction->destinationCashAccount?->name }}</dd></div>
                    @else
                        <div><dt class="text-slate-500">Akun kas</dt><dd class="mt-1 font-medium">{{ $transaction->type === 'expense' ? $transaction->sourceCashAccount?->name : $transaction->destinationCashAccount?->name }}</dd></div>
                        <div><dt class="text-slate-500">Metode pembayaran</dt><dd class="mt-1 font-medium">{{ $transaction->paymentMethod?->name ?? '—' }}</dd></div>
                    @endif
                    @if($transaction->type === 'expense')<div><dt class="text-slate-500">Sumber dana</dt><dd class="mt-1 font-medium">{{ $transaction->fundSource?->name ?? '—' }}</dd></div><div><dt class="text-slate-500">Vendor</dt><dd class="mt-1 font-medium">{{ $transaction->vendor?->name ?? '—' }}</dd></div>@endif
                    <div class="sm:col-span-2"><dt class="text-slate-500">Deskripsi</dt><dd class="mt-1 whitespace-pre-line font-medium">{{ $transaction->description }}</dd></div>
                    @if($transaction->type === 'transfer' && $transaction->notes)<div class="sm:col-span-2"><dt class="text-slate-500">Catatan</dt><dd class="mt-1 whitespace-pre-line font-medium">{{ $transaction->notes }}</dd></div>@endif
                </dl>
            </section>

            @if($transaction->items->isNotEmpty())
                <section class="overflow-hidden rounded-xl border border-slate-200 bg-white">
                    <div class="border-b border-slate-100 px-5 py-4"><h2 class="font-semibold">Rincian item</h2></div>
                    <div class="overflow-x-auto"><table class="min-w-full text-left text-sm"><thead class="bg-slate-50 text-xs uppercase text-slate-500"><tr><th class="px-4 py-3">Deskripsi</th>@if($transaction->type === 'expense')<th class="px-4 py-3">Kategori</th>@endif<th class="px-4 py-3 text-right">Kuantitas</th><th class="px-4 py-3 text-right">Harga</th><th class="px-4 py-3 text-right">Subtotal</th></tr></thead><tbody class="divide-y divide-slate-100">@foreach($transaction->items as $item)<tr><td class="px-4 py-3">{{ $item->description }}@if($item->activity)<span class="mt-1 block text-xs text-slate-500">{{ $item->activity->name }}</span>@endif</td>@if($transaction->type === 'expense')<td class="px-4 py-3">{{ $item->category?->name }}@if($item->subcategory)<span class="mt-1 block text-xs text-slate-500">{{ $item->subcategory->name }}</span>@endif</td>@endif<td class="whitespace-nowrap px-4 py-3 text-right">{{ $item->quantity }} {{ $item->unit }}</td><td class="whitespace-nowrap px-4 py-3 text-right">Rp {{ number_format((float) $item->unit_price, 2, ',', '.') }}</td><td class="whitespace-nowrap px-4 py-3 text-right font-medium">Rp {{ number_format((float) $item->subtotal, 2, ',', '.') }}</td></tr>@endforeach</tbody><tfoot class="bg-slate-50"><tr><th colspan="{{ $transaction->type === 'expense' ? 4 : 3 }}" class="px-4 py-3 text-right">Total</th><td class="px-4 py-3 text-right font-semibold">Rp {{ number_format((float) $transaction->total_amount, 2, ',', '.') }}</td></tr></tfoot></table></div>
                </section>
            @else
                <section class="rounded-xl border border-slate-200 bg-white p-5"><h2 class="font-semibold">{{ $transaction->type === 'transfer' ? 'Nominal transfer' : 'Nilai pemasukan' }}</h2><p class="mt-2 text-xl font-semibold">Rp {{ number_format((float) $transaction->total_amount, 2, ',', '.') }}</p></section>
            @endif

            @if($transaction->type === 'transfer' && $transaction->movements->isNotEmpty())
                <section class="overflow-hidden rounded-xl border border-slate-200 bg-white">
                    <div class="border-b border-slate-100 px-5 py-4"><h2 class="font-semibold">Pergerakan transfer</h2></div>
                    <div class="overflow-x-auto"><table class="min-w-full text-left text-sm"><thead class="bg-slate-50 text-xs uppercase text-slate-500"><tr><th class="px-4 py-3">Jenis</th><th class="px-4 py-3">Akun kas</th><th class="px-4 py-3 text-right">Nominal</th></tr></thead><tbody class="divide-y divide-slate-100">@foreach($transaction->movements as $movement)<tr><td class="px-4 py-3">{{ $movement->kind === 'transfer_out' ? 'Transfer keluar' : 'Transfer masuk' }}</td><td class="px-4 py-3">{{ $movement->cashAccount?->name }}</td><td class="px-4 py-3 text-right">Rp {{ number_format((float) $movement->amount, 2, ',', '.') }}</td></tr>@endforeach</tbody></table></div>
                </section>
            @endif

            <section class="rounded-xl border border-slate-200 bg-white p-5 sm:p-6">
                <h2 class="text-lg font-semibold">Bukti transaksi <span class="font-normal text-slate-500">({{ $transaction->attachments->count() }})</span></h2>
                @if($transaction->attachments->isEmpty())<p class="mt-3 text-sm text-slate-500">Belum ada bukti yang dilampirkan.</p>@else
                    <ul class="mt-4 divide-y divide-slate-100">@foreach($transaction->attachments as $attachment)<li class="flex flex-wrap items-center justify-between gap-3 py-3"><div><p class="text-sm font-medium">{{ $attachment->original_name }}</p><p class="mt-1 text-xs text-slate-500">Diunggah {{ $attachment->created_at->format('d M Y H:i') }} oleh {{ $attachment->uploader?->name }} · {{ number_format($attachment->size_bytes / 1024, 0) }} KB</p></div><div class="flex gap-3 text-sm"><a href="{{ route('transactions.attachments', ['transaction' => $transaction, 'attachment' => $attachment, 'mode' => 'preview']) }}" target="_blank" rel="noopener" class="text-emerald-800 underline">Lihat</a><a href="{{ route('transactions.attachments', ['transaction' => $transaction, 'attachment' => $attachment, 'mode' => 'download']) }}" class="text-emerald-800 underline">Unduh</a></div></li>@endforeach</ul>
                @endif
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-5 sm:p-6"><h2 class="text-lg font-semibold">Riwayat tindakan</h2><ol class="mt-4 space-y-4">@forelse($auditLogs as $entry)<li class="border-l-2 border-emerald-200 pl-4"><p class="text-sm font-medium">{{ $entry->description }}</p><p class="mt-1 text-xs text-slate-500">{{ $entry->user?->name ?? 'Sistem' }} · {{ $entry->created_at->format('d M Y H:i') }}</p>@if(data_get($entry->new_values, 'reason'))<p class="mt-2 whitespace-pre-line text-sm text-slate-600">{{ data_get($entry->new_values, 'reason') }}</p>@endif</li>@empty<li class="text-sm text-slate-500">Belum ada riwayat tindakan.</li>@endforelse</ol></section>
        </div>

        <aside class="space-y-5">
            @if($transaction->status === 'posted')
                <section class="rounded-xl border border-emerald-200 bg-emerald-50 p-5"><h2 class="font-semibold text-emerald-950">Sudah diposting</h2><p class="mt-2 text-sm text-emerald-900">{{ $transaction->posted_at?->format('d M Y H:i') }} oleh {{ $transaction->poster?->name }}</p>@if($transaction->negative_balance_override)<p class="mt-3 text-sm text-amber-900">Saldo negatif diizinkan sebesar Rp {{ number_format((float) $transaction->negative_balance_shortfall, 2, ',', '.') }}. Alasan: {{ $transaction->negative_balance_override_reason }}</p>@endif</section>
            @endif

            @if($transaction->status === 'submitted' && auth()->user()->can('transactions.review'))
                <section class="space-y-4 rounded-xl border border-slate-200 bg-white p-5"><h2 class="font-semibold">Pemeriksaan Keuangan</h2>
                    <form method="POST" action="{{ route('transactions.review', $transaction) }}" class="space-y-2">@csrf<input type="hidden" name="decision" value="revision"><label class="block text-sm font-medium">Minta revisi</label><textarea name="reason" required rows="3" placeholder="Jelaskan perbaikan yang diperlukan" class="w-full rounded-lg border-slate-300 text-sm"></textarea><button class="w-full rounded-lg border border-amber-300 px-3 py-2 text-sm font-semibold text-amber-900">Kirim permintaan revisi</button></form>
                    <form method="POST" action="{{ route('transactions.review', $transaction) }}" class="space-y-2">@csrf<input type="hidden" name="decision" value="reject"><label class="block text-sm font-medium">Tolak permanen</label><textarea name="reason" required rows="3" placeholder="Alasan penolakan" class="w-full rounded-lg border-slate-300 text-sm"></textarea><button class="w-full rounded-lg border border-red-300 px-3 py-2 text-sm font-semibold text-red-900">Tolak transaksi</button></form>
                    <form method="POST" action="{{ route('transactions.post', $transaction) }}" class="space-y-3 border-t border-slate-100 pt-4">@csrf<h3 class="font-medium">Posting</h3>@if($transaction->type === 'expense')<label class="flex items-start gap-2 text-sm"><input type="checkbox" name="allow_negative" value="1" class="mt-1 rounded border-slate-300"><span>Izinkan saldo menjadi negatif jika saldo kas tidak mencukupi</span></label><label class="block text-sm">Alasan override<textarea name="override_reason" rows="2" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></textarea></label>@elseif($transaction->type === 'transfer')<p class="text-sm text-slate-600">Saldo akun sumber harus mencukupi. Transfer tidak dapat membuat saldo negatif.</p>@endif<button class="w-full rounded-lg bg-emerald-800 px-3 py-2.5 text-sm font-semibold text-white">Posting ke ledger</button></form>
                </section>
            @endif
        </aside>
    </div>
@endsection
