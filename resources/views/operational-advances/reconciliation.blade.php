<section class="space-y-5 rounded-xl border border-slate-200 bg-white p-5">
    @php
        $cashDecimal = fn (string $amount): string => number_format((float) $amount, 2, ',', '.');
        $cashEventStatuses = ['submitted' => 'Menunggu verifikasi', 'posted' => 'Diposting', 'rejected' => 'Ditolak', 'reversed' => 'Direversal'];
        $eventLabels = ['advance_return' => 'Pengembalian sisa bon', 'advance_repay' => 'Pembayaran penggantian ke penerima'];
    @endphp
    <div>
        <h2 class="text-xl font-semibold">Rekonsiliasi kas bon</h2>
        <p class="mt-1 text-sm text-slate-600">Penerimaan sisa bon menambah kas. Pembayaran penggantian mengurangi kas. Keduanya tidak menambah beban pertanggungjawaban.</p>
    </div>

    @if($canSubmitReturn)
        <form method="POST" action="{{ route('operational-advances.returns.store', $advance) }}" class="grid gap-3 rounded-lg border border-sky-200 bg-sky-50 p-4 sm:grid-cols-2">
            @csrf
            <input type="hidden" name="advance_event_key" value="{{ old('advance_event_key', $cashEventSubmissionKey) }}">
            <h3 class="font-semibold sm:col-span-2">Ajukan pengembalian sisa dana</h3>
            <label class="block text-sm font-medium">Nominal (Rp)
                <input type="number" name="amount" min="0.01" max="{{ $settlementSummary['return_outstanding'] }}" step="0.01" value="{{ old('amount') }}" required class="mt-1 w-full rounded-lg border-slate-300 text-sm">
            </label>
            <label class="block text-sm font-medium">Tanggal dana diserahkan
                <input type="datetime-local" name="occurred_at" value="{{ old('occurred_at', now()->format('Y-m-d\\TH:i')) }}" max="{{ now()->format('Y-m-d\\TH:i') }}" required class="mt-1 w-full rounded-lg border-slate-300 text-sm">
            </label>
            <label class="block text-sm font-medium sm:col-span-2">Catatan opsional
                <textarea name="notes" rows="2" class="mt-1 w-full rounded-lg border-slate-300 text-sm">{{ old('notes') }}</textarea>
            </label>
            <p class="text-xs text-slate-600 sm:col-span-2">Keuangan Sarpras akan memverifikasi penerimaan. Saldo kas baru berubah setelah pengajuan diposting.</p>
            <button class="w-fit rounded-lg bg-sky-800 px-4 py-2 text-sm font-semibold text-white">Ajukan pengembalian</button>
        </form>
    @endif

    @if($canPayReimbursement)
        <form method="POST" action="{{ route('operational-advances.reimbursements.store', $advance) }}" class="grid gap-3 rounded-lg border border-rose-200 bg-rose-50 p-4 sm:grid-cols-2">
            @csrf
            <input type="hidden" name="advance_event_key" value="{{ old('advance_event_key', $cashEventPaymentKey) }}">
            <h3 class="font-semibold sm:col-span-2">Bayar penggantian kepada penerima</h3>
            <label class="block text-sm font-medium">Nominal (Rp)
                <input type="number" name="amount" min="0.01" max="{{ $settlementSummary['reimbursement_outstanding'] }}" step="0.01" value="{{ old('amount') }}" required class="mt-1 w-full rounded-lg border-slate-300 text-sm">
            </label>
            <label class="block text-sm font-medium">Tanggal pembayaran aktual
                <input type="datetime-local" name="occurred_at" value="{{ old('occurred_at', now()->format('Y-m-d\\TH:i')) }}" max="{{ now()->format('Y-m-d\\TH:i') }}" required class="mt-1 w-full rounded-lg border-slate-300 text-sm">
            </label>
            <label class="block text-sm font-medium sm:col-span-2">Rekening kas aktif
                <select name="cash_account_id" required class="mt-1 w-full rounded-lg border-slate-300 text-sm"><option value="">Pilih rekening kas</option>@foreach($disbursementCashAccounts as $cashAccount)<option value="{{ $cashAccount->id }}">{{ $cashAccount->name }}</option>@endforeach</select>
            </label>
            @can('transactions.negative_balance.override')
                <label class="flex items-start gap-2 text-sm text-slate-700 sm:col-span-2"><input type="checkbox" name="allow_negative" value="1" class="mt-1 rounded border-slate-300"><span>Izinkan saldo rekening menjadi negatif sesuai override Phase 3A.</span></label>
                <label class="block text-sm font-medium sm:col-span-2">Alasan override<textarea name="override_reason" rows="2" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></textarea></label>
            @endcan
            <p class="text-xs text-slate-600 sm:col-span-2">Pembayaran mengurangi payable dan kas; tidak membuat beban pengeluaran baru.</p>
            <button @disabled($disbursementCashAccounts->isEmpty()) class="w-fit rounded-lg bg-rose-800 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Posting pembayaran</button>
        </form>
    @endif

    @if($canReceiveReturn)
        @foreach($cashEventTransactions->where('type', 'advance_return')->where('status', 'submitted') as $returnRequest)
            <article class="space-y-3 rounded-lg border border-amber-200 bg-amber-50 p-4">
                <div><h3 class="font-semibold">Verifikasi pengembalian &middot; {{ $returnRequest->number }}</h3><p class="mt-1 text-sm">Rp {{ $cashDecimal($returnRequest->total_amount) }} diserahkan {{ $returnRequest->occurred_at->format('d M Y H:i') }} oleh {{ $returnRequest->creator?->name }}</p>@if($returnRequest->notes)<p class="mt-1 whitespace-pre-line text-sm text-slate-600">{{ $returnRequest->notes }}</p>@endif</div>
                <form method="POST" action="{{ route('operational-advances.returns.receive', ['operationalAdvance' => $advance, 'transaction' => $returnRequest]) }}" class="grid gap-2 sm:grid-cols-[1fr_auto]">
                    @csrf
                    <select name="cash_account_id" required class="rounded-lg border-slate-300 text-sm"><option value="">Pilih rekening penerimaan aktif</option>@foreach($disbursementCashAccounts as $cashAccount)<option value="{{ $cashAccount->id }}">{{ $cashAccount->name }}</option>@endforeach</select>
                    <button @disabled($disbursementCashAccounts->isEmpty()) class="rounded-lg bg-emerald-800 px-3 py-2 text-sm font-semibold text-white disabled:opacity-50">Verifikasi dan terima</button>
                </form>
                <form method="POST" action="{{ route('operational-advances.returns.reject', ['operationalAdvance' => $advance, 'transaction' => $returnRequest]) }}" class="grid gap-2 sm:grid-cols-[1fr_auto]">
                    @csrf
                    <input name="reason" required minlength="5" placeholder="Alasan penolakan" class="rounded-lg border-slate-300 text-sm">
                    <button class="rounded-lg border border-red-300 px-3 py-2 text-sm font-semibold text-red-900">Tolak pengajuan</button>
                </form>
            </article>
        @endforeach
    @endif

    <div class="space-y-3">
        <h3 class="font-semibold">Riwayat event kas bon</h3>
        @forelse($cashEventTransactions as $event)
            <article class="space-y-3 rounded-lg border border-slate-200 p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="font-semibold">{{ $eventLabels[$event->type] ?? $event->type }} &middot; {{ $event->number }}</p>
                        <p class="mt-1 text-sm text-slate-600">Rp {{ $cashDecimal($event->total_amount) }} &middot; {{ $event->occurred_at->format('d M Y H:i') }} &middot; {{ $cashEventStatuses[$event->status] ?? $event->status }}</p>
                        @if($event->destinationCashAccount || $event->sourceCashAccount)<p class="mt-1 text-xs text-slate-500">Rekening {{ $event->destinationCashAccount?->name ?? $event->sourceCashAccount?->name }} &middot; dibuat {{ $event->creator?->name }}@if($event->poster) &middot; diposting {{ $event->poster->name }} @endif</p>@else<p class="mt-1 text-xs text-slate-500">Diajukan oleh {{ $event->creator?->name }}</p>@endif
                        @if($event->rejection_reason)<p class="mt-1 whitespace-pre-line text-sm text-red-800">Alasan penolakan: {{ $event->rejection_reason }}</p>@endif
                    </div>
                    @if($event->status === 'posted' && auth()->user()->can('transactions.view'))<a href="{{ route('transactions.show', $event) }}" class="text-sm text-emerald-800 underline">{{ $event->number }}</a>@endif
                </div>
                @if($canCorrectCashEvents && $event->status === 'posted' && (($event->type === 'advance_return' && auth()->user()->can('operational_advances.return')) || ($event->type === 'advance_repay' && auth()->user()->can('operational_advances.reimburse'))))
                    <details class="border-t border-slate-100 pt-3">
                        <summary class="cursor-pointer text-sm font-semibold text-amber-900">Koreksi cash event dengan reversal</summary>
                        <form method="POST" action="{{ route('operational-advances.cash-events.correct', ['operationalAdvance' => $advance, 'transaction' => $event]) }}" class="mt-3 grid gap-2 sm:grid-cols-2">
                            @csrf
                            <input type="hidden" name="advance_event_key" value="{{ (string) \Illuminate\Support\Str::uuid7() }}">
                            <label class="block text-xs font-medium">Nominal pengganti<input type="number" name="amount" value="{{ $event->total_amount }}" min="0.01" step="0.01" required class="mt-1 w-full rounded border-slate-300 text-sm"></label>
                            <label class="block text-xs font-medium">Tanggal<input type="datetime-local" name="occurred_at" value="{{ now()->format('Y-m-d\\TH:i') }}" max="{{ now()->format('Y-m-d\\TH:i') }}" required class="mt-1 w-full rounded border-slate-300 text-sm"></label>
                            <label class="block text-xs font-medium sm:col-span-2">Rekening kas aktif<select name="cash_account_id" required class="mt-1 w-full rounded border-slate-300 text-sm"><option value="">Pilih rekening</option>@foreach($disbursementCashAccounts as $cashAccount)<option value="{{ $cashAccount->id }}">{{ $cashAccount->name }}</option>@endforeach</select></label>
                            <label class="block text-xs font-medium sm:col-span-2">Alasan koreksi<textarea name="correction_reason" required minlength="5" rows="2" class="mt-1 w-full rounded border-slate-300 text-sm"></textarea></label>
                            @if($event->type === 'advance_repay' && auth()->user()->can('transactions.negative_balance.override'))<label class="flex items-center gap-2 text-xs sm:col-span-2"><input type="checkbox" name="allow_negative" value="1" class="rounded border-slate-300">Override saldo negatif</label><label class="block text-xs font-medium sm:col-span-2">Alasan override<textarea name="override_reason" rows="2" class="mt-1 w-full rounded border-slate-300 text-sm"></textarea></label>@endif
                            <button class="w-fit rounded bg-amber-700 px-3 py-2 text-sm font-semibold text-white sm:col-span-2">Reversal dan posting pengganti</button>
                        </form>
                    </details>
                @endif
            </article>
        @empty
            <p class="rounded-lg bg-slate-50 p-4 text-sm text-slate-500">Belum ada pengajuan pengembalian atau pembayaran penggantian.</p>
        @endforelse
    </div>
</section>
