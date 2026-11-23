@extends('layouts.app')
@section('title', ($record->exists ? 'Edit ' : 'Tambah ').$definition['label'])
@section('breadcrumb', 'Master data / '.$definition['label'].' / '.($record->exists ? 'Edit' : 'Tambah'))
@section('content')
<h1 class="text-3xl font-semibold">{{ $record->exists ? 'Edit ' : 'Tambah ' }}{{ strtolower($definition['label']) }}</h1>
<form method="POST" action="{{ $record->exists ? route('admin.master.update', ['resource' => $resource, 'record' => $record->id]) : route('admin.master.store', ['resource' => $resource]) }}" class="mt-6 max-w-2xl space-y-4 rounded-xl border border-slate-200 bg-white p-5 sm:p-6">
    @csrf
    @if ($record->exists)@method('PUT')@endif
    @foreach($definition['fields'] as $name => $field)
        <div>
            <label for="{{ $name }}" class="mb-1 block text-sm font-medium">{{ $field['label'] }}</label>
            @if ($field['type'] === 'textarea')
                <textarea id="{{ $name }}" name="{{ $name }}" rows="3" class="w-full rounded-lg border-slate-300">{{ old($name, $record->getAttribute($name)) }}</textarea>
            @elseif ($field['type'] === 'select')
                <select id="{{ $name }}" name="{{ $name }}" required class="w-full rounded-lg border-slate-300"><option value="">Pilih {{ strtolower($field['label']) }}</option>@foreach($field['options'] as $value => $label)<option value="{{ $value }}" @selected(old($name, $record->getAttribute($name)) === $value)>{{ $label }}</option>@endforeach</select>
            @elseif ($field['type'] === 'category')
                <select id="{{ $name }}" name="{{ $name }}" required class="w-full rounded-lg border-slate-300"><option value="">Pilih kategori</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected((string) old($name, $record->getAttribute($name)) === (string) $category->id)>{{ $category->name }}</option>@endforeach</select>
            @else
                @php($value = $record->getAttribute($name))
                <input id="{{ $name }}" type="{{ $field['type'] }}" name="{{ $name }}" value="{{ old($name, $field['type'] === 'date' ? $value?->format('Y-m-d') : $value) }}" @required(in_array('required', $field['rules'], true)) class="w-full rounded-lg border-slate-300">
            @endif
        </div>
    @endforeach
    <div class="flex flex-wrap gap-3 pt-2"><button class="rounded-lg bg-emerald-800 px-4 py-2.5 text-sm font-semibold text-white">{{ $record->exists ? 'Simpan perubahan' : 'Simpan' }}</button><a href="{{ route('admin.master.index', ['resource' => $resource]) }}" class="rounded-lg border border-slate-300 px-4 py-2.5 text-sm">Batal</a></div>
</form>
@endsection
