@extends('layouts.app')
@section('title', 'Tambah unit kerja')
@section('breadcrumb', 'Unit kerja / Tambah')
@section('content')
<h1 class="text-3xl font-semibold">Tambah unit kerja</h1><form method="POST" action="{{ route('admin.departments.store') }}" class="mt-6 max-w-2xl space-y-4 rounded-xl border border-slate-200 bg-white p-6">
    @csrf
    @include('admin.departments.form')
    <button class="rounded-lg bg-emerald-800 px-4 py-2.5 text-sm font-semibold text-white">Simpan unit</button>
</form>
@endsection
