@extends('layouts.app')

@section('title', $transaction->exists ? 'Edit transaksi' : 'Buat transaksi')
@section('breadcrumb', 'Transaksi / '.($transaction->exists ? 'Edit' : 'Buat'))

@section('content')
    @if (! $type)
        <div class="max-w-3xl">
            <h1 class="text-3xl font-semibold">Buat transaksi</h1>
            <p class="mt-2 text-sm text-slate-600">Pilih jenis transaksi yang akan disimpan sebagai draft.</p>
            <div class="mt-6 grid gap-4 sm:grid-cols-2">
                @can('transactions.income.create')<a href="{{ route('transactions.create', ['type' => 'income']) }}" class="rounded-xl border border-slate-200 bg-white p-6 hover:border-emerald-700"><span class="text-sm font-semibold text-emerald-700">PEMASUKAN</span><h2 class="mt-2 text-xl font-semibold">Catat pemasukan</h2><p class="mt-2 text-sm text-slate-600">Simpan penerimaan dana sebagai draft.</p></a>@endcan
                @can('transactions.expense.create')<a href="{{ route('transactions.create', ['type' => 'expense']) }}" class="rounded-xl border border-slate-200 bg-white p-6 hover:border-emerald-700"><span class="text-sm font-semibold text-emerald-700">PENGELUARAN</span><h2 class="mt-2 text-xl font-semibold">Catat pengeluaran</h2><p class="mt-2 text-sm text-slate-600">Rincikan item dan lampirkan bukti transaksi.</p></a>@endcan
                @can('transactions.transfer.create')<a href="{{ route('transactions.create', ['type' => 'transfer']) }}" class="rounded-xl border border-slate-200 bg-white p-6 hover:border-emerald-700"><span class="text-sm font-semibold text-sky-700">TRANSFER</span><h2 class="mt-2 text-xl font-semibold">Pindah antar akun kas</h2><p class="mt-2 text-sm text-slate-600">Catat pemindahan dana sebagai dua movement yang seimbang.</p></a>@endcan
            </div>
        </div>
    @else
        @php
            $savedItems = old('items', $transaction->exists ? $transaction->items->map(fn ($item) => $item->only(['description', 'category_id', 'subcategory_id', 'activity_id', 'quantity', 'unit', 'unit_price']))->all() : []);
            $incomeMode = old('income_mode', $type === 'income' && $transaction->exists && $transaction->items->isNotEmpty() ? 'items' : 'direct');
            $directIncomeAmount = old('income_amount', $transaction->exists && $type === 'income' && $transaction->items->isEmpty() ? $transaction->total_amount : '');
        @endphp
        <div class="max-w-5xl">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div><p class="text-sm font-semibold text-emerald-700">{{ $type === 'income' ? 'PEMASUKAN' : 'PENGELUARAN' }}</p><h1 class="mt-2 text-3xl font-semibold">{{ isset($correctionOriginal) ? 'Koreksi transaksi' : ($transaction->exists ? 'Edit draft' : 'Buat draft '.$type) }}</h1><p class="mt-2 text-sm text-slate-600">{{ isset($correctionOriginal) ? 'Perubahan akan membalik transaksi asli dan langsung mem-posting pengganti.' : 'Nomor transaksi akan mengikuti tanggal kejadian dan dibuat otomatis.' }}</p></div>
                @if($transaction->exists)<span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-700">{{ $transaction->number }}</span>@endif
            </div>

            @if($transaction->revision_reason)<div class="mt-5 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"><strong>Perlu revisi:</strong> {{ $transaction->revision_reason }}</div>@endif

            @if(isset($correctionOriginal))<section class="mt-5 rounded-xl border border-amber-200 bg-amber-50 p-5 text-sm"><h2 class="font-semibold text-amber-950">Data transaksi asli · {{ $correctionOriginal->number }}</h2><p class="mt-2">{{ $correctionOriginal->occurred_at->format('d M Y H:i') }} · {{ $correctionOriginal->description }} · Rp {{ number_format((float) $correctionOriginal->total_amount, 2, ',', '.') }}</p><p class="mt-2 text-amber-900">Isi data pengganti di bawah. Koreksi akan langsung diposting setelah dikonfirmasi.</p></section>@endif

            <form method="POST" enctype="multipart/form-data" action="{{ isset($correctionOriginal) ? route('transactions.corrections.store', $transaction) : ($transaction->exists ? route('transactions.update', $transaction) : route('transactions.store')) }}" class="mt-6 space-y-6" data-transaction-form data-type="{{ $type }}">
                @csrf
                @if($transaction->exists && !isset($correctionOriginal))@method('PUT')@endif
                <input type="hidden" name="type" value="{{ $type }}">
                <input type="hidden" name="income_mode" value="{{ $incomeMode }}" data-income-mode>

                <section class="grid gap-4 rounded-xl border border-slate-200 bg-white p-5 sm:grid-cols-2 sm:p-6">
                    <div><label for="occurred_at" class="mb-1 block text-sm font-medium">Tanggal kejadian</label><input id="occurred_at" type="datetime-local" name="occurred_at" required value="{{ old('occurred_at', $transaction->exists ? $transaction->occurred_at->format('Y-m-d\TH:i') : now()->format('Y-m-d\TH:i')) }}" class="w-full rounded-lg border-slate-300">@error('occurred_at')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror</div>
                    <div><label for="responsible_user_id" class="mb-1 block text-sm font-medium">Penanggung jawab</label><select id="responsible_user_id" name="responsible_user_id" required class="w-full rounded-lg border-slate-300"><option value="">Pilih pengguna aktif</option>@foreach($users as $user)<option value="{{ $user->id }}" @selected((string) old('responsible_user_id', $transaction->responsible_user_id) === (string) $user->id)>{{ $user->name }}</option>@endforeach</select>@error('responsible_user_id')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror</div>
                    <div class="sm:col-span-2"><label for="description" class="mb-1 block text-sm font-medium">Deskripsi</label><textarea id="description" name="description" rows="3" required class="w-full rounded-lg border-slate-300">{{ old('description', $transaction->description) }}</textarea>@error('description')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror</div>
                    <div><label for="{{ $type === 'expense' ? 'source_cash_account_id' : 'destination_cash_account_id' }}" class="mb-1 block text-sm font-medium">Akun kas {{ $type === 'expense' ? 'sumber' : 'tujuan' }}</label><select id="{{ $type === 'expense' ? 'source_cash_account_id' : 'destination_cash_account_id' }}" name="{{ $type === 'expense' ? 'source_cash_account_id' : 'destination_cash_account_id' }}" required class="w-full rounded-lg border-slate-300"><option value="">Pilih akun kas</option>@foreach($cashAccounts as $account)<option value="{{ $account->id }}" @selected((string) old($type === 'expense' ? 'source_cash_account_id' : 'destination_cash_account_id', $type === 'expense' ? $transaction->source_cash_account_id : $transaction->destination_cash_account_id) === (string) $account->id)>{{ $account->name }}{{ $account->is_active ? '' : ' (nonaktif)' }}</option>@endforeach</select>@error($type === 'expense' ? 'source_cash_account_id' : 'destination_cash_account_id')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror</div>
                    <div><label for="payment_method_id" class="mb-1 block text-sm font-medium">Metode pembayaran</label><select id="payment_method_id" name="payment_method_id" required class="w-full rounded-lg border-slate-300"><option value="">Pilih metode</option>@foreach($paymentMethods as $method)<option value="{{ $method->id }}" @selected((string) old('payment_method_id', $transaction->payment_method_id) === (string) $method->id)>{{ $method->name }}</option>@endforeach</select>@error('payment_method_id')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror</div>
                    @if($type === 'expense')
                        <div><label for="fund_source_id" class="mb-1 block text-sm font-medium">Sumber dana</label><select id="fund_source_id" name="fund_source_id" required class="w-full rounded-lg border-slate-300"><option value="">Pilih sumber dana</option>@foreach($fundSources as $source)<option value="{{ $source->id }}" @selected((string) old('fund_source_id', $transaction->fund_source_id) === (string) $source->id)>{{ $source->name }}</option>@endforeach</select>@error('fund_source_id')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror</div>
                        <div><label for="vendor_id" class="mb-1 block text-sm font-medium">Vendor <span class="font-normal text-slate-500">(opsional)</span></label><select id="vendor_id" name="vendor_id" class="w-full rounded-lg border-slate-300"><option value="">Tidak ada vendor</option>@foreach($vendors as $vendor)<option value="{{ $vendor->id }}" @selected((string) old('vendor_id', $transaction->vendor_id) === (string) $vendor->id)>{{ $vendor->name }}</option>@endforeach</select></div>
                    @endif
                </section>

                @if($type === 'income')
                    <section class="rounded-xl border border-slate-200 bg-white p-5 sm:p-6">
                        <div class="flex flex-wrap items-start justify-between gap-3"><div><h2 class="text-lg font-semibold">Nilai pemasukan</h2><p class="mt-1 text-sm text-slate-600">Masukkan satu nilai langsung atau rincikan beberapa item.</p></div><div class="flex gap-2"><button type="button" data-mode="direct" class="rounded-lg border px-3 py-2 text-sm">Satu nilai</button><button type="button" data-mode="items" class="rounded-lg border px-3 py-2 text-sm">Rincian item</button></div></div>
                        <div data-direct-income class="mt-4 {{ $incomeMode === 'items' ? 'hidden' : '' }}"><label for="income_amount" class="mb-1 block text-sm font-medium">Nilai pemasukan (Rp)</label><input id="income_amount" type="number" min="0.01" step="0.01" name="income_amount" value="{{ $directIncomeAmount }}" class="w-full rounded-lg border-slate-300 sm:max-w-sm">@error('income_amount')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror</div>
                        <div data-items-section class="mt-4 {{ $incomeMode === 'direct' ? 'hidden' : '' }}"><div data-items-container>
                            @foreach($savedItems ?: [[]] as $index => $item)
                                <div class="mb-3 grid gap-3 rounded-lg border border-slate-200 p-3 sm:grid-cols-[1fr_12rem_auto]" data-item-row>
                                    <label><span class="mb-1 block text-xs font-medium">Deskripsi item</span><input name="items[{{ $index }}][description]" value="{{ $item['description'] ?? '' }}" class="w-full rounded-lg border-slate-300 text-sm"></label>
                                    <label><span class="mb-1 block text-xs font-medium">Nilai (Rp)</span><input type="number" min="0.01" step="0.01" name="items[{{ $index }}][unit_price]" value="{{ $item['unit_price'] ?? '' }}" class="item-unit-price w-full rounded-lg border-slate-300 text-sm"></label>
                                    <button type="button" data-remove-item class="self-end rounded-lg px-3 py-2 text-sm text-red-700">Hapus</button>
                                    <input type="hidden" name="items[{{ $index }}][quantity]" value="1">
                                </div>
                            @endforeach
                        </div><button type="button" data-add-item class="rounded-lg border border-slate-300 px-3 py-2 text-sm">Tambah item</button></div>
                        <p class="mt-4 text-right text-sm font-semibold">Total saat ini: <span data-total>Rp 0,00</span></p>
                    </section>
                @else
                    <section class="rounded-xl border border-slate-200 bg-white p-5 sm:p-6">
                        <div class="flex flex-wrap items-end justify-between gap-3"><div><h2 class="text-lg font-semibold">Rincian pengeluaran</h2><p class="mt-1 text-sm text-slate-600">Subtotal dan total dihitung ulang di server saat disimpan.</p></div><button type="button" data-add-item class="rounded-lg border border-slate-300 px-3 py-2 text-sm">Tambah item</button></div>
                        <div class="mt-4 space-y-3" data-items-container>
                            @foreach($savedItems ?: [[]] as $index => $item)
                                <div class="grid gap-3 rounded-lg border border-slate-200 p-3 sm:grid-cols-2 xl:grid-cols-4" data-item-row>
                                    <label class="sm:col-span-2"><span class="mb-1 block text-xs font-medium">Deskripsi item</span><input name="items[{{ $index }}][description]" value="{{ $item['description'] ?? '' }}" required class="w-full rounded-lg border-slate-300 text-sm"></label>
                                    <label><span class="mb-1 block text-xs font-medium">Kategori</span><select name="items[{{ $index }}][category_id]" required data-category class="w-full rounded-lg border-slate-300 text-sm"><option value="">Pilih kategori</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected((string) ($item['category_id'] ?? '') === (string) $category->id)>{{ $category->name }}</option>@endforeach</select></label>
                                    <label><span class="mb-1 block text-xs font-medium">Subkategori</span><select name="items[{{ $index }}][subcategory_id]" data-subcategory class="w-full rounded-lg border-slate-300 text-sm"><option value="">Pilih jika tersedia</option>@foreach($categories as $category)@foreach($category->subcategories as $subcategory)<option data-parent="{{ $category->id }}" value="{{ $subcategory->id }}" @selected((string) ($item['subcategory_id'] ?? '') === (string) $subcategory->id)>{{ $subcategory->name }}</option>@endforeach @endforeach</select></label>
                                    <label><span class="mb-1 block text-xs font-medium">Kegiatan <span class="font-normal">(opsional)</span></span><select name="items[{{ $index }}][activity_id]" class="w-full rounded-lg border-slate-300 text-sm"><option value="">Tidak ada kegiatan</option>@foreach($activities as $activity)<option value="{{ $activity->id }}" @selected((string) ($item['activity_id'] ?? '') === (string) $activity->id)>{{ $activity->name }}</option>@endforeach</select></label>
                                    <label><span class="mb-1 block text-xs font-medium">Kuantitas</span><input type="number" min="0.0001" step="0.0001" name="items[{{ $index }}][quantity]" value="{{ $item['quantity'] ?? '1.0000' }}" required class="item-quantity w-full rounded-lg border-slate-300 text-sm"></label>
                                    <label><span class="mb-1 block text-xs font-medium">Satuan</span><input name="items[{{ $index }}][unit]" value="{{ $item['unit'] ?? '' }}" required class="w-full rounded-lg border-slate-300 text-sm"></label>
                                    <label><span class="mb-1 block text-xs font-medium">Harga satuan (Rp)</span><input type="number" min="0.01" step="0.01" name="items[{{ $index }}][unit_price]" value="{{ $item['unit_price'] ?? '' }}" required class="item-unit-price w-full rounded-lg border-slate-300 text-sm"></label>
                                    <div class="flex items-end justify-between gap-3"><span class="pb-2 text-xs text-slate-500">Subtotal: <strong data-subtotal>Rp 0,00</strong></span><button type="button" data-remove-item class="rounded-lg px-2 py-2 text-sm text-red-700">Hapus</button></div>
                                </div>
                            @endforeach
                        </div>
                        <p class="mt-4 text-right text-sm font-semibold">Total saat ini: <span data-total>Rp 0,00</span></p>
                    </section>
                @endif

                <section class="rounded-xl border border-slate-200 bg-white p-5 sm:p-6">
                    <h2 class="text-lg font-semibold">Bukti transaksi</h2>
                    <p class="mt-1 text-sm text-slate-600">JPG, PNG, PDF, atau HEIC. Maksimal 10 MB per file. Bukti pengeluaran wajib sebelum posting.</p>
                    @if($transaction->exists && !isset($correctionOriginal) && $transaction->attachments->isNotEmpty())<ul class="mt-3 space-y-2 text-sm">@foreach($transaction->attachments as $attachment)<li><a class="text-emerald-800 underline" href="{{ route('transactions.attachments', ['transaction' => $transaction, 'attachment' => $attachment, 'mode' => 'download']) }}">{{ $attachment->original_name }}</a> <span class="text-slate-500">({{ number_format($attachment->size_bytes / 1024, 0) }} KB)</span></li>@endforeach</ul>@endif
                    @if(isset($correctionOriginal))<p class="mt-1 text-sm text-amber-900">Bukti lama hanya untuk referensi. Unggah bukti baru untuk transaksi pengganti.</p>@endif
                    <input type="file" name="attachments[]" multiple accept=".jpg,.jpeg,.png,.pdf,.heic" @required(isset($correctionOriginal) && $type === 'expense') class="mt-4 block w-full text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-emerald-50 file:px-3 file:py-2 file:font-medium file:text-emerald-900">
                    @foreach($errors->get('attachments.*') as $message)<p class="mt-2 text-sm text-red-700">{{ $message }}</p>@endforeach
                </section>

                @if(isset($correctionOriginal))
                    <section class="grid gap-4 rounded-xl border border-amber-200 bg-white p-5 sm:grid-cols-2"><label class="sm:col-span-2"><span class="mb-1 block text-sm font-semibold">Alasan koreksi wajib</span><textarea name="correction_reason" required rows="3" class="w-full rounded-lg border-amber-300">{{ old('correction_reason') }}</textarea>@error('correction_reason')<span class="mt-1 block text-sm text-red-700">{{ $message }}</span>@enderror</label>@if($type === 'expense')<label class="flex items-start gap-2 text-sm"><input type="checkbox" name="allow_negative" value="1" @checked(old('allow_negative')) class="mt-1 rounded border-slate-300"><span>Izinkan saldo negatif sesuai kewenangan Keuangan Sarpras</span></label><label><span class="mb-1 block text-sm">Alasan override saldo negatif</span><textarea name="override_reason" rows="2" class="w-full rounded-lg border-slate-300">{{ old('override_reason') }}</textarea>@error('override_reason')<span class="mt-1 block text-sm text-red-700">{{ $message }}</span>@enderror</label>@endif</section>
                @elseif($transaction->exists && auth()->id() !== $transaction->created_by && auth()->user()->can('transactions.manage'))
                    <label class="block rounded-xl border border-amber-200 bg-amber-50 p-4"><span class="mb-1 block text-sm font-semibold text-amber-950">Alasan perubahan draft milik pengguna lain</span><textarea name="change_reason" rows="2" required class="w-full rounded-lg border-amber-300">{{ old('change_reason') }}</textarea></label>
                @endif

                <div class="flex flex-wrap gap-3"><button class="rounded-lg bg-emerald-800 px-5 py-2.5 text-sm font-semibold text-white">{{ isset($correctionOriginal) ? 'Konfirmasi koreksi dan posting pengganti' : 'Simpan draft' }}</button><a href="{{ $transaction->exists ? route('transactions.show', $transaction) : route('transactions.index') }}" class="rounded-lg border border-slate-300 px-5 py-2.5 text-sm">Batal</a></div>
            </form>
        </div>
    @endif
@endsection
