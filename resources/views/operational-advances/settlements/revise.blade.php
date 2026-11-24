@extends('layouts.app')

@section('title', 'Revisi item pertanggungjawaban')
@section('breadcrumb', 'Bon operasional / '.$advance->number.' / Revisi item')

@section('content')
    <div class="mx-auto max-w-3xl">
        <a href="{{ route('operational-advances.show', $advance) }}" class="text-sm font-medium text-emerald-800 underline">Kembali ke bon</a>
        <h1 class="mt-3 text-3xl font-semibold tracking-tight">Ajukan ulang item</h1>
        <p class="mt-2 text-sm text-slate-600">Versi lama akan dipertahankan dan ditandai superseded. Versi baru menunggu pemeriksaan.</p>
        <div class="mt-5 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950">
            <p class="font-semibold">Catatan pemeriksa</p>
            <p class="mt-1 whitespace-pre-line">{{ $item->review_reason }}</p>
        </div>

        <form method="POST" action="{{ route('operational-advances.settlement-items.revisions.store', ['operationalAdvance' => $advance, 'item' => $item]) }}" enctype="multipart/form-data" class="mt-5 space-y-4 rounded-xl border border-slate-200 bg-white p-5">
            @csrf
            <label class="block text-sm font-medium">Tanggal pengeluaran
                <input type="datetime-local" name="occurred_at" value="{{ old('occurred_at', $item->occurred_at?->format('Y-m-d\\TH:i')) }}" max="{{ now()->format('Y-m-d\\TH:i') }}" required class="mt-1 w-full rounded-lg border-slate-300 text-sm">
            </label>
            <label class="block text-sm font-medium">Deskripsi
                <textarea name="description" required rows="3" class="mt-1 w-full rounded-lg border-slate-300 text-sm">{{ old('description', $item->description) }}</textarea>
            </label>
            <div class="grid gap-4 sm:grid-cols-2">
                <label class="block text-sm font-medium">Nominal (Rp)<input name="amount" inputmode="decimal" value="{{ old('amount', $item->amount) }}" required class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="block text-sm font-medium">Kategori
                    <select name="category_id" required class="mt-1 w-full rounded-lg border-slate-300 text-sm"><option value="">Pilih kategori</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected((string) old('category_id', $item->category_id) === (string) $category->id)>{{ $category->name }}</option>@endforeach</select>
                </label>
                <label class="block text-sm font-medium">Subkategori
                    <select name="subcategory_id" class="mt-1 w-full rounded-lg border-slate-300 text-sm"><option value="">Pilih jika tersedia</option>@foreach($categories as $category)@foreach($category->subcategories as $subcategory)<option value="{{ $subcategory->id }}" @selected((string) old('subcategory_id', $item->subcategory_id) === (string) $subcategory->id)>{{ $category->name }} · {{ $subcategory->name }}</option>@endforeach @endforeach</select>
                </label>
                <label class="block text-sm font-medium">Kegiatan (opsional)
                    <select name="activity_id" class="mt-1 w-full rounded-lg border-slate-300 text-sm"><option value="">Tanpa kegiatan khusus</option>@foreach($activities as $activity)<option value="{{ $activity->id }}" @selected((string) old('activity_id', $item->activity_id) === (string) $activity->id)>{{ $activity->name }}</option>@endforeach</select>
                </label>
            </div>
            <label class="block text-sm font-medium">Bukti baru (JPG, PNG, PDF, HEIC; maks. 10 MB per file)
                <input type="file" name="attachments[]" multiple required accept=".jpg,.jpeg,.png,.pdf,.heic" class="mt-1 block w-full text-sm text-slate-700 file:mr-3 file:rounded-lg file:border-0 file:bg-emerald-50 file:px-3 file:py-2 file:font-medium file:text-emerald-900">
            </label>
            <button class="rounded-lg bg-emerald-800 px-5 py-2.5 text-sm font-semibold text-white">Ajukan versi baru</button>
        </form>
    </div>
@endsection
