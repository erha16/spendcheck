@extends('layouts.app')

@section('title', 'Ajukan pertanggungjawaban')
@section('breadcrumb', 'Bon operasional / '.$advance->number.' / Pertanggungjawaban')

@section('content')
    <div class="mx-auto max-w-5xl">
        <a href="{{ route('operational-advances.show', $advance) }}" class="text-sm font-medium text-emerald-800 underline">Kembali ke bon</a>
        <h1 class="mt-3 text-3xl font-semibold tracking-tight">Ajukan pertanggungjawaban</h1>
        <p class="mt-2 text-sm text-slate-600">{{ $advance->number }} · Pengeluaran diterima menjadi beban final tanpa membuat arus kas baru.</p>

        <form method="POST" action="{{ route('operational-advances.settlements.store', $advance) }}" enctype="multipart/form-data" class="mt-6 space-y-5">
            @csrf
            <input type="hidden" name="submission_key" value="{{ $submissionKey }}">
            <div id="settlement-items" class="space-y-4">
                <fieldset class="settlement-item rounded-xl border border-slate-200 bg-white p-5" data-index="0">
                    <legend class="px-2 text-lg font-semibold">Item pengeluaran</legend>
                    <div class="grid gap-4 md:grid-cols-2">
                        <label class="block text-sm font-medium">Tanggal pengeluaran
                            <input type="datetime-local" name="items[0][occurred_at]" value="{{ old('items.0.occurred_at', now()->subDay()->format('Y-m-d\\TH:i')) }}" max="{{ now()->format('Y-m-d\\TH:i') }}" required class="mt-1 w-full rounded-lg border-slate-300 text-sm">
                        </label>
                        <label class="block text-sm font-medium">Nominal (Rp)
                            <input name="items[0][amount]" inputmode="decimal" value="{{ old('items.0.amount') }}" required class="mt-1 w-full rounded-lg border-slate-300 text-sm">
                        </label>
                        <label class="block text-sm font-medium md:col-span-2">Deskripsi
                            <textarea name="items[0][description]" required rows="2" class="mt-1 w-full rounded-lg border-slate-300 text-sm">{{ old('items.0.description') }}</textarea>
                        </label>
                        <label class="block text-sm font-medium">Kategori
                            <select name="items[0][category_id]" data-category required class="mt-1 w-full rounded-lg border-slate-300 text-sm">
                                <option value="">Pilih kategori</option>
                                @foreach($categories as $category)
                                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block text-sm font-medium">Subkategori
                            <select name="items[0][subcategory_id]" data-subcategory class="mt-1 w-full rounded-lg border-slate-300 text-sm">
                                <option value="">Pilih subkategori jika tersedia</option>
                                @foreach($categories as $category)
                                    @foreach($category->subcategories as $subcategory)
                                        <option value="{{ $subcategory->id }}" data-category-id="{{ $category->id }}">{{ $subcategory->name }}</option>
                                    @endforeach
                                @endforeach
                            </select>
                        </label>
                        <label class="block text-sm font-medium md:col-span-2">Kegiatan (opsional)
                            <select name="items[0][activity_id]" class="mt-1 w-full rounded-lg border-slate-300 text-sm">
                                <option value="">Tanpa kegiatan khusus</option>
                                @foreach($activities as $activity)
                                    <option value="{{ $activity->id }}">{{ $activity->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block text-sm font-medium md:col-span-2">Bukti (JPG, PNG, PDF, HEIC; maks. 10 MB per file)
                            <input type="file" name="items[0][attachments][]" multiple required accept=".jpg,.jpeg,.png,.pdf,.heic" class="mt-1 block w-full text-sm text-slate-700 file:mr-3 file:rounded-lg file:border-0 file:bg-emerald-50 file:px-3 file:py-2 file:font-medium file:text-emerald-900">
                        </label>
                    </div>
                </fieldset>
            </div>

            <div class="flex flex-wrap justify-between gap-3">
                <button type="button" id="add-settlement-item" class="rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold">Tambah item</button>
                <button class="rounded-lg bg-emerald-800 px-5 py-2.5 text-sm font-semibold text-white">Ajukan batch</button>
            </div>
            <p class="text-xs text-slate-500">Setiap item dan bukti disimpan sebagai histori. Hanya item yang diterima Keuangan Sarpras dihitung sebagai beban final.</p>
        </form>
    </div>

    <template id="settlement-item-template">
        <fieldset class="settlement-item rounded-xl border border-slate-200 bg-white p-5" data-index="__INDEX__">
            <legend class="flex w-full items-center justify-between px-2 text-lg font-semibold"><span>Item pengeluaran</span><button type="button" class="remove-settlement-item text-sm font-medium text-red-800 underline">Hapus item</button></legend>
            <div class="grid gap-4 md:grid-cols-2">
                <label class="block text-sm font-medium">Tanggal pengeluaran<input type="datetime-local" name="items[__INDEX__][occurred_at]" max="{{ now()->format('Y-m-d\\TH:i') }}" required class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="block text-sm font-medium">Nominal (Rp)<input name="items[__INDEX__][amount]" inputmode="decimal" required class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="block text-sm font-medium md:col-span-2">Deskripsi<textarea name="items[__INDEX__][description]" required rows="2" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></textarea></label>
                <label class="block text-sm font-medium">Kategori
                    <select name="items[__INDEX__][category_id]" data-category required class="mt-1 w-full rounded-lg border-slate-300 text-sm"><option value="">Pilih kategori</option>@foreach($categories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach</select>
                </label>
                <label class="block text-sm font-medium">Subkategori
                    <select name="items[__INDEX__][subcategory_id]" data-subcategory class="mt-1 w-full rounded-lg border-slate-300 text-sm"><option value="">Pilih subkategori jika tersedia</option>@foreach($categories as $category)@foreach($category->subcategories as $subcategory)<option value="{{ $subcategory->id }}" data-category-id="{{ $category->id }}">{{ $subcategory->name }}</option>@endforeach @endforeach</select>
                </label>
                <label class="block text-sm font-medium md:col-span-2">Kegiatan (opsional)
                    <select name="items[__INDEX__][activity_id]" class="mt-1 w-full rounded-lg border-slate-300 text-sm"><option value="">Tanpa kegiatan khusus</option>@foreach($activities as $activity)<option value="{{ $activity->id }}">{{ $activity->name }}</option>@endforeach</select>
                </label>
                <label class="block text-sm font-medium md:col-span-2">Bukti (JPG, PNG, PDF, HEIC; maks. 10 MB per file)<input type="file" name="items[__INDEX__][attachments][]" multiple required accept=".jpg,.jpeg,.png,.pdf,.heic" class="mt-1 block w-full text-sm text-slate-700 file:mr-3 file:rounded-lg file:border-0 file:bg-emerald-50 file:px-3 file:py-2 file:font-medium file:text-emerald-900"></label>
            </div>
        </fieldset>
    </template>

    <script>
        (() => {
            const list = document.getElementById('settlement-items');
            const template = document.getElementById('settlement-item-template');
            let nextIndex = list.querySelectorAll('.settlement-item').length;

            document.getElementById('add-settlement-item').addEventListener('click', () => {
                const html = template.innerHTML.replaceAll('__INDEX__', String(nextIndex++));
                list.insertAdjacentHTML('beforeend', html);
            });

            list.addEventListener('click', (event) => {
                if (event.target.closest('.remove-settlement-item')) {
                    event.target.closest('.settlement-item').remove();
                }
            });

            list.addEventListener('change', (event) => {
                if (!event.target.matches('[data-category]')) {
                    return;
                }

                const item = event.target.closest('.settlement-item');
                const subcategory = item.querySelector('[data-subcategory]');
                subcategory.value = '';
                Array.from(subcategory.options).forEach((option) => {
                    option.hidden = option.value !== '' && option.dataset.categoryId !== event.target.value;
                });
            });
        })();
    </script>
@endsection
