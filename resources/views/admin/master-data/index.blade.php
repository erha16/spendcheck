@extends('layouts.app')
@section('title', $definition['label'])
@section('breadcrumb', 'Master data / '.$definition['label'])
@section('content')
<div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
    <div><h1 class="text-3xl font-semibold">{{ $definition['label'] }}</h1><p class="mt-2 text-sm text-slate-600">Kelola data {{ strtolower($definition['label']) }} dan status penggunaannya.</p></div>
    @can(str_replace('-', '_', $resource).'.manage')<a href="{{ route('admin.master.create', ['resource' => $resource]) }}" class="rounded-lg bg-emerald-800 px-4 py-2.5 text-center text-sm font-semibold text-white">Tambah {{ strtolower($definition['label']) }}</a>@endcan
</div>
<form method="GET" class="mt-6 flex flex-col gap-3 sm:flex-row">
    <label class="sr-only" for="master-search">Cari {{ strtolower($definition['label']) }}</label>
    <input id="master-search" name="search" value="{{ $search }}" placeholder="Cari {{ strtolower($definition['label']) }}..." class="w-full rounded-lg border-slate-300 sm:max-w-md">
    <button class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium hover:bg-slate-50">Cari</button>
    @if ($search !== '')<a href="{{ route('admin.master.index', ['resource' => $resource]) }}" class="rounded-lg px-3 py-2 text-sm text-slate-600">Hapus pencarian</a>@endif
</form>
<div class="mt-5 overflow-x-auto rounded-xl border border-slate-200 bg-white">
    <table class="min-w-full text-left text-sm">
        <thead class="bg-slate-50 text-xs uppercase text-slate-500"><tr>@foreach($definition['columns'] as $label)<th class="px-4 py-3">{{ $label }}</th>@endforeach<th class="px-4 py-3">Status</th><th class="px-4 py-3"></th></tr></thead>
        <tbody class="divide-y divide-slate-100">
            @forelse($records as $record)
                <tr>
                    @foreach($definition['columns'] as $column => $label)
                        @php($value = data_get($record, $column))
                        <td class="max-w-xs px-4 py-4 {{ $loop->first ? 'font-medium' : '' }}">{{ $value instanceof \Illuminate\Support\Carbon ? $value->format('d M Y') : \Illuminate\Support\Str::limit((string) $value, 90) }}</td>
                    @endforeach
                    <td class="px-4 py-4"><span class="rounded-full px-2.5 py-1 text-xs font-medium {{ $record->is_active ? 'bg-emerald-50 text-emerald-800' : 'bg-slate-100 text-slate-600' }}">{{ $record->is_active ? 'Aktif' : 'Nonaktif' }}</span></td>
                    <td class="whitespace-nowrap px-4 py-4 text-right">
                        @can(str_replace('-', '_', $resource).'.manage')
                            <a href="{{ route('admin.master.edit', ['resource' => $resource, 'record' => $record->id]) }}" class="text-emerald-800">Edit</a>
                            <form class="ml-3 inline" method="POST" action="{{ route('admin.master.status.update', ['resource' => $resource, 'record' => $record->id]) }}">@csrf @method('PATCH')<input type="hidden" name="is_active" value="{{ $record->is_active ? 0 : 1 }}"><button class="text-slate-600">{{ $record->is_active ? 'Nonaktifkan' : 'Aktifkan' }}</button></form>
                            <form class="ml-3 inline" method="POST" action="{{ route('admin.master.destroy', ['resource' => $resource, 'record' => $record->id]) }}" onsubmit="return confirm('Hapus data ini?')">@csrf @method('DELETE')<button class="text-red-700">Hapus</button></form>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="{{ count($definition['columns']) + 2 }}" class="px-4 py-10 text-center text-slate-500">Belum ada {{ strtolower($definition['label']) }}.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="mt-5">{{ $records->links() }}</div>
@endsection
