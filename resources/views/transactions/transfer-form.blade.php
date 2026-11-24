@extends('layouts.app')

@section('title', $transaction->exists ? 'Edit transfer' : 'Buat transfer')
@section('breadcrumb', 'Transfer / '.($transaction->exists ? 'Edit' : 'Buat'))

@section('content')
    <div class="max-w-4xl">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <p class="text-sm font-semibold text-sky-700">TRANSFER ANTAR AKUN</p>
                <h1 class="mt-2 text-3xl font-semibold">{{ isset($correctionOriginal) ? 'Koreksi transfer' : ($transaction->exists ? 'Edit draft transfer' : 'Buat draft transfer') }}</h1>
                <p class="mt-2 text-sm text-slate-600">{{ isset($correctionOriginal) ? 'Koreksi akan membalik kedua movement lama dan langsung mem-posting transfer pengganti.' : 'Transfer hanya mengubah saldo akun kas, bukan total pemasukan atau pengeluaran.' }}</p>
            </div>
            @if($transaction->exists)
                <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-700">{{ $transaction->number }}</span>
            @endif
        </div>

        @if($transaction->revision_reason)
            <div class="mt-5 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"><strong>Perlu revisi:</strong> {{ $transaction->revision_reason }}</div>
        @endif

        @if(isset($correctionOriginal))<section class="rounded-xl border border-amber-200 bg-amber-50 p-5 text-sm"><h2 class="font-semibold">Data transaksi asli · {{ $correctionOriginal->number }}</h2><p class="mt-2">{{ $correctionOriginal->occurred_at->format('d M Y H:i') }} · {{ $correctionOriginal->description }} · Rp {{ number_format((float) $correctionOriginal->total_amount, 2, ',', '.') }}</p><p class="mt-2">{{ $correctionOriginal->sourceCashAccount?->name }} → {{ $correctionOriginal->destinationCashAccount?->name }}</p></section>@endif

        <form method="POST" enctype="multipart/form-data" action="{{ isset($correctionOriginal) ? route('transactions.corrections.store', $transaction) : ($transaction->exists ? route('transactions.update', $transaction) : route('transactions.store')) }}" class="mt-6 space-y-5">
            @csrf
            @if($transaction->exists && !isset($correctionOriginal))
                @method('PUT')
            @endif
            <input type="hidden" name="type" value="transfer">

            <section class="grid gap-4 rounded-xl border border-slate-200 bg-white p-5 sm:grid-cols-2 sm:p-6">
                <div>
                    <label for="occurred_at" class="mb-1 block text-sm font-medium">Tanggal kejadian</label>
                    <input id="occurred_at" type="datetime-local" name="occurred_at" required value="{{ old('occurred_at', $transaction->exists ? $transaction->occurred_at->format('Y-m-d\TH:i') : now()->format('Y-m-d\TH:i')) }}" class="w-full rounded-lg border-slate-300">
                    @error('occurred_at')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="transfer_amount" class="mb-1 block text-sm font-medium">Nominal transfer (Rp)</label>
                    <input id="transfer_amount" type="number" name="transfer_amount" min="0.01" step="0.01" required value="{{ old('transfer_amount', $transaction->exists ? $transaction->total_amount : '') }}" class="w-full rounded-lg border-slate-300">
                    @error('transfer_amount')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="source_cash_account_id" class="mb-1 block text-sm font-medium">Akun kas sumber</label>
                    <select id="source_cash_account_id" name="source_cash_account_id" required class="w-full rounded-lg border-slate-300">
                        <option value="">Pilih akun sumber</option>
                        @foreach($cashAccounts as $account)
                            <option value="{{ $account->id }}" @selected((string) old('source_cash_account_id', $transaction->source_cash_account_id) === (string) $account->id)>{{ $account->name }}{{ $account->is_active ? '' : ' (nonaktif; penyelesaian saldo)' }}</option>
                        @endforeach
                    </select>
                    @error('source_cash_account_id')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="destination_cash_account_id" class="mb-1 block text-sm font-medium">Akun kas tujuan</label>
                    <select id="destination_cash_account_id" name="destination_cash_account_id" required class="w-full rounded-lg border-slate-300">
                        <option value="">Pilih akun tujuan aktif</option>
                        @foreach($cashAccounts->where('is_active', true) as $account)
                            <option value="{{ $account->id }}" @selected((string) old('destination_cash_account_id', $transaction->destination_cash_account_id) === (string) $account->id)>{{ $account->name }}</option>
                        @endforeach
                    </select>
                    @error('destination_cash_account_id')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
                </div>
                <div class="sm:col-span-2">
                    <label for="description" class="mb-1 block text-sm font-medium">Deskripsi transfer</label>
                    <textarea id="description" name="description" rows="3" required class="w-full rounded-lg border-slate-300">{{ old('description', $transaction->description) }}</textarea>
                    @error('description')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
                </div>
                <div class="sm:col-span-2">
                    <label for="notes" class="mb-1 block text-sm font-medium">Catatan <span class="font-normal text-slate-500">(opsional)</span></label>
                    <textarea id="notes" name="notes" rows="2" class="w-full rounded-lg border-slate-300">{{ old('notes', $transaction->notes) }}</textarea>
                    @error('notes')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
                </div>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-5 sm:p-6">
                <h2 class="text-lg font-semibold">Bukti transfer</h2>
                <p class="mt-1 text-sm text-slate-600">Lampirkan bukti sebelum posting. JPG, JPEG, PNG, PDF, atau HEIC; maksimal 10 MB per file.</p>
                @if($transaction->exists && !isset($correctionOriginal) && $transaction->attachments->isNotEmpty())
                    <ul class="mt-3 space-y-2 text-sm">
                        @foreach($transaction->attachments as $attachment)
                            <li><a class="text-emerald-800 underline" href="{{ route('transactions.attachments', ['transaction' => $transaction, 'attachment' => $attachment, 'mode' => 'download']) }}">{{ $attachment->original_name }}</a> <span class="text-slate-500">({{ number_format($attachment->size_bytes / 1024, 0) }} KB)</span></li>
                        @endforeach
                    </ul>
                @endif
                @if(isset($correctionOriginal))<p class="mt-1 text-sm text-amber-900">Bukti lama hanya untuk referensi. Unggah bukti baru untuk transfer pengganti.</p>@endif
                <input type="file" name="attachments[]" multiple accept=".jpg,.jpeg,.png,.pdf,.heic" @required(isset($correctionOriginal)) class="mt-4 block w-full text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-emerald-50 file:px-3 file:py-2 file:font-medium file:text-emerald-900">
                @foreach($errors->get('attachments.*') as $message)
                    <p class="mt-2 text-sm text-red-700">{{ $message }}</p>
                @endforeach
            </section>

            @if(isset($correctionOriginal))
                <section class="rounded-xl border border-amber-200 bg-white p-5"><label class="block"><span class="mb-1 block text-sm font-semibold">Alasan koreksi wajib</span><textarea name="correction_reason" required rows="3" class="w-full rounded-lg border-amber-300">{{ old('correction_reason') }}</textarea>@error('correction_reason')<span class="mt-1 block text-sm text-red-700">{{ $message }}</span>@enderror</label></section>
            @elseif($transaction->exists && auth()->id() !== $transaction->created_by && auth()->user()->can('transactions.manage'))
                <label class="block rounded-xl border border-amber-200 bg-amber-50 p-4"><span class="mb-1 block text-sm font-semibold text-amber-950">Alasan perubahan draft milik pengguna lain</span><textarea name="change_reason" rows="2" required class="w-full rounded-lg border-amber-300">{{ old('change_reason') }}</textarea></label>
            @endif

            <div class="flex flex-wrap gap-3">
                <button class="rounded-lg bg-emerald-800 px-5 py-2.5 text-sm font-semibold text-white">{{ isset($correctionOriginal) ? 'Konfirmasi koreksi dan posting pengganti' : 'Simpan draft' }}</button>
                <a href="{{ $transaction->exists ? route('transactions.show', $transaction) : route('transactions.index', ['type' => 'transfer']) }}" class="rounded-lg border border-slate-300 px-5 py-2.5 text-sm">Batal</a>
            </div>
        </form>
    </div>
@endsection
