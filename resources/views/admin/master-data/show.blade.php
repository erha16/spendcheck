@extends('layouts.app')
@section('title', $definition['label'].' · Detail')
@section('breadcrumb', 'Master data / '.$definition['label'].' / Detail')
@section('content')
<div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
    <div><h1 class="text-3xl font-semibold">Detail {{ strtolower($definition['label']) }}</h1><p class="mt-2 text-sm text-slate-600">Informasi master data dan status penggunaannya.</p></div>
    <div class="flex gap-3"><a href="{{ route('admin.master.index', ['resource' => $resource]) }}" class="rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium">Kembali ke daftar</a>@can(str_replace('-', '_', $resource).'.manage')<a href="{{ route('admin.master.edit', ['resource' => $resource, 'record' => $item->id]) }}" class="rounded-lg bg-emerald-800 px-4 py-2.5 text-sm font-semibold text-white">Edit</a>@endcan</div>
</div>
<dl class="mt-6 grid gap-4 rounded-xl border border-slate-200 bg-white p-5 sm:grid-cols-2 sm:p-6">
    @foreach($definition['fields'] as $name => $field)
        @php($value = $name === 'category_id' ? $item->category->name : $item->getAttribute($name))
        @if ($name === 'account_number' && filled($value))@php($value = '•••• '.substr($value, -4))@endif
        <div class="min-w-0"><dt class="text-sm font-medium text-slate-500">{{ $field['label'] }}</dt><dd class="mt-1 break-words text-slate-900">{{ $value instanceof \Illuminate\Support\Carbon ? $value->format('d M Y') : ($value === null || $value === '' ? '—' : $value) }}</dd></div>
    @endforeach
    <div><dt class="text-sm font-medium text-slate-500">Status</dt><dd class="mt-1"><span class="rounded-full px-2.5 py-1 text-xs font-medium {{ $item->is_active ? 'bg-emerald-50 text-emerald-800' : 'bg-slate-100 text-slate-600' }}">{{ $item->is_active ? 'Aktif' : 'Nonaktif' }}</span></dd></div>
    <div><dt class="text-sm font-medium text-slate-500">Dibuat</dt><dd class="mt-1 text-slate-900">{{ $item->created_at->format('d M Y H:i') }}</dd></div>
    <div><dt class="text-sm font-medium text-slate-500">Terakhir diperbarui</dt><dd class="mt-1 text-slate-900">{{ $item->updated_at->format('d M Y H:i') }}</dd></div>
</dl>
@endsection
