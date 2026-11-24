<section id="pertanggungjawaban" class="space-y-5 rounded-xl border border-slate-200 bg-white p-5">
    @php
        $settlementStatusLabels = ['draft' => 'Draft', 'submitted' => 'Diajukan', 'in_review' => 'Dalam pemeriksaan', 'revision_requested' => 'Perlu revisi', 'completed' => 'Selesai diperiksa'];
        $itemStatusLabels = ['pending' => 'Menunggu pemeriksaan', 'accepted' => 'Diterima sebagai beban final', 'revision_requested' => 'Perlu revisi', 'rejected' => 'Ditolak', 'superseded' => 'Versi lama'];
        $decimalDisplay = fn (string $value): string => number_format((float) $value, 2, ',', '.');
    @endphp
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div><h2 class="text-xl font-semibold">Pertanggungjawaban bon</h2><p class="mt-1 text-sm text-slate-600">Realisasi hanya menjumlahkan item diterima dan adjustment disetujui. Return dan repayment memengaruhi kas serta kewajiban, tanpa menambah beban.</p></div>
        @if($canSubmitSettlement)
            <a href="{{ route('operational-advances.settlements.create', $advance) }}" class="rounded-lg bg-emerald-800 px-4 py-2.5 text-sm font-semibold text-white">Ajukan batch</a>
        @endif
    </div>

    <dl class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-lg bg-slate-50 p-3"><dt class="text-xs text-slate-500">Pencairan</dt><dd class="mt-1 font-semibold">Rp {{ $decimalDisplay($settlementSummary['disbursed']) }}</dd></div>
        <div class="rounded-lg bg-emerald-50 p-3"><dt class="text-xs text-emerald-800">Beban final diterima</dt><dd class="mt-1 font-semibold">Rp {{ $decimalDisplay($settlementSummary['accepted_expense']) }}</dd></div>
        <div class="rounded-lg bg-sky-50 p-3"><dt class="text-xs text-sky-900">Pengembalian tercatat</dt><dd class="mt-1 font-semibold">Rp {{ $decimalDisplay($settlementSummary['returned']) }}</dd></div>
        <div class="rounded-lg bg-amber-50 p-3"><dt class="text-xs text-amber-900">Dana wajib dikembalikan</dt><dd class="mt-1 font-semibold">Rp {{ $decimalDisplay($settlementSummary['return_outstanding']) }}</dd></div>
        <div class="rounded-lg bg-violet-50 p-3"><dt class="text-xs text-violet-900">Payable dibayar</dt><dd class="mt-1 font-semibold">Rp {{ $decimalDisplay($settlementSummary['reimbursed']) }}</dd></div>
        <div class="rounded-lg bg-rose-50 p-3"><dt class="text-xs text-rose-900">Payable organisasi</dt><dd class="mt-1 font-semibold">Rp {{ $decimalDisplay($settlementSummary['reimbursement_outstanding']) }}</dd></div>
    </dl>
    <p class="text-xs text-slate-500">Saldo bon mengikutsertakan arus kas posted dan beban accepted. Pengajuan pengembalian yang belum diterima Keuangan belum mengubah saldo kas.</p>

    @forelse($advance->settlements->sortBy('batch_number') as $settlement)
        <article id="pertanggungjawaban-{{ $settlement->batch_number }}" class="space-y-4 rounded-xl border border-slate-200 p-4">
            <header class="flex flex-wrap items-start justify-between gap-3">
                <div><h3 class="font-semibold">Batch {{ $settlement->batch_number }}</h3><p class="mt-1 text-xs text-slate-500">Diajukan {{ $settlement->submitter?->name }} · {{ $settlement->submitted_at?->format('d M Y H:i') }}</p></div>
                <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-700">{{ $settlementStatusLabels[$settlement->status] ?? $settlement->status }}</span>
            </header>

            @foreach($settlement->items->sortBy('id') as $item)
                <section class="space-y-3 rounded-lg border border-slate-200 p-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <div class="flex flex-wrap items-center gap-2"><h4 class="font-semibold">{{ $item->description }}</h4><span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs">{{ $itemStatusLabels[$item->review_status] ?? $item->review_status }}</span></div>
                            <p class="mt-1 text-sm text-slate-600">{{ $item->occurred_at->format('d M Y H:i') }} · {{ $item->category?->name }}{{ $item->subcategory ? ' / '.$item->subcategory->name : '' }}{{ $item->activity ? ' · '.$item->activity->name : '' }}</p>
                            <p class="mt-1 text-sm font-semibold">Rp {{ $decimalDisplay($item->amount) }}</p>
                            <p class="mt-1 text-xs text-slate-500">Versi diajukan {{ $item->submitter?->name }}@if($item->reviewer) · diperiksa {{ $item->reviewer->name }} @endif</p>
                            @if($item->replacesItem)<p class="mt-1 text-xs text-slate-500">Menggantikan versi item #{{ $item->replaces_item_id }}</p>@endif
                            @if($item->replacement->isNotEmpty())<p class="mt-1 text-xs text-slate-500">Memiliki versi penerus; versi ini tidak dihitung sebagai beban.</p>@endif
                            @if($item->review_reason)<p class="mt-2 whitespace-pre-line rounded bg-amber-50 p-2 text-sm text-amber-950">Catatan pemeriksa: {{ $item->review_reason }}</p>@endif
                        </div>
                    </div>

                    <ul class="space-y-2 border-t border-slate-100 pt-3">
                        @foreach($item->attachments as $attachment)
                            <li class="flex flex-wrap items-center justify-between gap-2 text-sm">
                                <span>{{ $attachment->original_name }} · {{ number_format($attachment->size_bytes / 1024, 0) }} KB</span>
                                <span class="flex gap-3"><a class="text-emerald-800 underline" href="{{ route('operational-advances.settlement-attachments', ['operationalAdvance' => $advance, 'advanceSettlement' => $settlement, 'item' => $item, 'attachment' => $attachment, 'mode' => 'preview']) }}" target="_blank" rel="noopener">Lihat</a><a class="text-emerald-800 underline" href="{{ route('operational-advances.settlement-attachments', ['operationalAdvance' => $advance, 'advanceSettlement' => $settlement, 'item' => $item, 'attachment' => $attachment, 'mode' => 'download']) }}">Unduh</a></span>
                            </li>
                        @endforeach
                    </ul>

                    @if($canReviewSettlements && $item->review_status === 'pending' && (int) $item->submitted_by !== (int) auth()->id())
                        <form method="POST" action="{{ route('operational-advances.settlement-items.review', ['operationalAdvance' => $advance, 'advanceSettlement' => $settlement, 'item' => $item]) }}" class="grid gap-2 border-t border-slate-100 pt-3 sm:grid-cols-[1fr_2fr_auto]">
                            @csrf
                            <select name="decision" required class="rounded-lg border-slate-300 text-sm"><option value="accepted">Terima</option><option value="revision_requested">Minta revisi</option><option value="rejected">Tolak</option></select>
                            <input name="reason" placeholder="Alasan wajib untuk revisi atau penolakan" class="rounded-lg border-slate-300 text-sm">
                            <button class="rounded-lg bg-emerald-800 px-3 py-2 text-sm font-semibold text-white">Simpan keputusan</button>
                        </form>
                    @endif

                    @if($canSubmitSettlementRevision && $item->review_status === 'revision_requested' && $item->replacement->isEmpty())
                        <a href="{{ route('operational-advances.settlement-items.revisions.create', ['operationalAdvance' => $advance, 'item' => $item]) }}" class="inline-block rounded-lg border border-amber-300 px-3 py-2 text-sm font-semibold text-amber-950">Perbaiki dan ajukan ulang</a>
                    @endif

                    @if($canRequestSettlementAdjustment && $item->review_status === 'accepted')
                        @php $pendingAdjustment = $item->adjustments->firstWhere('status', 'pending'); @endphp
                        @if($pendingAdjustment)
                            <p class="rounded bg-amber-50 p-2 text-sm text-amber-950">Koreksi item ini sedang menunggu pemeriksaan.</p>
                        @else
                            <details class="border-t border-slate-100 pt-3">
                                <summary class="cursor-pointer text-sm font-semibold text-amber-900">Koreksi beban diterima</summary>
                                <div class="mt-3 grid gap-4 lg:grid-cols-2">
                                    <form method="POST" action="{{ route('operational-advances.settlement-items.adjustments.store', ['operationalAdvance' => $advance, 'item' => $item]) }}" class="space-y-2 rounded-lg bg-slate-50 p-3">
                                        @csrf
                                        <input type="hidden" name="adjustment_group_id" value="{{ (string) \Illuminate\Support\Str::uuid7() }}">
                                        <input type="hidden" name="mode" value="replace">
                                        <h5 class="font-medium">Ganti nilai/klasifikasi</h5>
                                        <label class="block text-xs font-medium">Tanggal<input type="datetime-local" name="occurred_at" value="{{ now()->format('Y-m-d\\TH:i') }}" max="{{ now()->format('Y-m-d\\TH:i') }}" required class="mt-1 w-full rounded border-slate-300 text-sm"></label>
                                        <label class="block text-xs font-medium">Alasan<textarea name="reason" required minlength="5" rows="2" class="mt-1 w-full rounded border-slate-300 text-sm"></textarea></label>
                                        <label class="block text-xs font-medium">Deskripsi pengganti<input name="description" value="{{ $item->description }}" required class="mt-1 w-full rounded border-slate-300 text-sm"></label>
                                        <label class="block text-xs font-medium">Nominal pengganti<input name="amount" inputmode="decimal" required class="mt-1 w-full rounded border-slate-300 text-sm"></label>
                                        <label class="block text-xs font-medium">Kategori<select name="category_id" data-adjustment-category required class="mt-1 w-full rounded border-slate-300 text-sm"><option value="">Pilih kategori</option>@foreach($settlementCategories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach</select></label>
                                        <label class="block text-xs font-medium">Subkategori<select name="subcategory_id" data-adjustment-subcategory class="mt-1 w-full rounded border-slate-300 text-sm"><option value="">Pilih jika tersedia</option>@foreach($settlementCategories as $category)@foreach($category->subcategories as $subcategory)<option value="{{ $subcategory->id }}" data-category-id="{{ $category->id }}">{{ $subcategory->name }}</option>@endforeach @endforeach</select></label>
                                        <label class="block text-xs font-medium">Kegiatan<select name="activity_id" class="mt-1 w-full rounded border-slate-300 text-sm"><option value="">Tanpa kegiatan khusus</option>@foreach($settlementActivities as $activity)<option value="{{ $activity->id }}">{{ $activity->name }}</option>@endforeach</select></label>
                                        <p class="text-xs text-slate-500">Koreksi akan membalik nilai item efektif lalu menerapkan nominal pengganti setelah disetujui.</p>
                                        <button class="rounded bg-amber-700 px-3 py-2 text-sm font-semibold text-white">Ajukan penggantian</button>
                                    </form>
                                    <form method="POST" action="{{ route('operational-advances.settlement-items.adjustments.store', ['operationalAdvance' => $advance, 'item' => $item]) }}" class="space-y-2 rounded-lg bg-slate-50 p-3">
                                        @csrf
                                        <input type="hidden" name="adjustment_group_id" value="{{ (string) \Illuminate\Support\Str::uuid7() }}">
                                        <input type="hidden" name="mode" value="reverse">
                                        <h5 class="font-medium">Batalkan beban item</h5>
                                        <label class="block text-xs font-medium">Tanggal<input type="datetime-local" name="occurred_at" value="{{ now()->format('Y-m-d\\TH:i') }}" max="{{ now()->format('Y-m-d\\TH:i') }}" required class="mt-1 w-full rounded border-slate-300 text-sm"></label>
                                        <label class="block text-xs font-medium">Alasan pembatalan<textarea name="reason" required minlength="5" rows="2" class="mt-1 w-full rounded border-slate-300 text-sm"></textarea></label>
                                        <button class="rounded border border-red-300 px-3 py-2 text-sm font-semibold text-red-900">Ajukan reversal</button>
                                    </form>
                                </div>
                            </details>
                        @endif
                    @endif

                    @foreach($item->adjustments->groupBy('adjustment_group_id') as $groupId => $adjustmentGroup)
                        <div class="rounded-lg border border-amber-200 bg-amber-50 p-3">
                            <p class="text-sm font-semibold">Koreksi {{ $adjustmentGroup->first()->status === 'pending' ? 'menunggu pemeriksaan' : ($adjustmentGroup->first()->status === 'approved' ? 'disetujui' : 'ditolak') }}</p>
                            <p class="mt-1 text-xs text-slate-700">{{ $adjustmentGroup->first()->reason }} · {{ $adjustmentGroup->first()->creator?->name }}</p>
                            <ul class="mt-2 space-y-1 text-sm">@foreach($adjustmentGroup as $adjustment)<li>{{ $adjustment->kind === 'reversal' ? 'Reversal' : 'Pengganti' }}: Rp {{ $decimalDisplay($adjustment->amount_delta) }}@if($adjustment->category) · {{ $adjustment->category->name }}@endif</li>@endforeach</ul>
                            @if($adjustmentGroup->first()->review_reason)<p class="mt-2 text-sm">Catatan pemeriksa: {{ $adjustmentGroup->first()->review_reason }}</p>@endif
                            @if($canReviewSettlements && $adjustmentGroup->first()->status === 'pending' && (int) $adjustmentGroup->first()->created_by !== (int) auth()->id())
                                <form method="POST" action="{{ route('operational-advances.settlement-items.adjustments.review', ['operationalAdvance' => $advance, 'item' => $item, 'adjustmentGroupId' => $groupId]) }}" class="mt-3 flex flex-wrap gap-2">
                                    @csrf
                                    <input name="review_reason" placeholder="Alasan jika menolak" class="min-w-48 flex-1 rounded border-slate-300 text-sm">
                                    <button name="decision" value="approved" class="rounded bg-emerald-800 px-3 py-2 text-sm font-semibold text-white">Setujui koreksi</button>
                                    <button name="decision" value="rejected" class="rounded border border-red-300 px-3 py-2 text-sm font-semibold text-red-900">Tolak koreksi</button>
                                </form>
                            @endif
                        </div>
                    @endforeach
                </section>
            @endforeach

            @if($canReviewSettlements && $settlement->items->contains(fn ($item) => in_array($item->review_status, ['pending', 'revision_requested'], true)))
                <form method="POST" action="{{ route('operational-advances.settlements.reject', ['operationalAdvance' => $advance, 'advanceSettlement' => $settlement]) }}" class="flex flex-wrap gap-2 border-t border-slate-100 pt-3">
                    @csrf
                    <input name="reason" required minlength="5" placeholder="Alasan penolakan seluruh item terbuka" class="min-w-56 flex-1 rounded-lg border-slate-300 text-sm">
                    <button class="rounded-lg border border-red-300 px-3 py-2 text-sm font-semibold text-red-900">Tolak item terbuka dalam batch</button>
                </form>
            @endif
        </article>
    @empty
        <p class="rounded-lg bg-slate-50 p-4 text-sm text-slate-600">Belum ada batch pertanggungjawaban.</p>
    @endforelse
</section>

<script>
    document.querySelectorAll('[data-adjustment-category]').forEach((categorySelect) => {
        categorySelect.addEventListener('change', () => {
            const subcategorySelect = categorySelect.closest('form').querySelector('[data-adjustment-subcategory]');
            subcategorySelect.value = '';
            Array.from(subcategorySelect.options).forEach((option) => {
                option.hidden = option.value !== '' && option.dataset.categoryId !== categorySelect.value;
            });
        });
    });
</script>
