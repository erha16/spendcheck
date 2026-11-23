@extends('layouts.app')
@section('title', 'Edit unit kerja')
@section('breadcrumb', 'Unit kerja / Edit')
@section('content')
<h1 class="text-3xl font-semibold">Edit unit kerja</h1><form method="POST" action="{{ route('admin.departments.update', $department) }}" class="mt-6 max-w-2xl space-y-4 rounded-xl border border-slate-200 bg-white p-6">@csrf @method('PUT')@include('admin.departments.form')<button class="rounded-lg bg-emerald-800 px-4 py-2.5 text-sm font-semibold text-white">Simpan perubahan</button></form>
@endsection
