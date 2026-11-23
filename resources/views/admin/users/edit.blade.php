@extends('layouts.app')
@section('title', 'Edit pengguna')
@section('breadcrumb', 'Pengguna / Edit')
@section('content')
<h1 class="text-3xl font-semibold">Edit pengguna</h1><form method="POST" action="{{ route('admin.users.update', $user) }}" class="mt-6 max-w-3xl space-y-5 rounded-xl border border-slate-200 bg-white p-6">@csrf @method('PUT')@include('admin.users.form')<button class="rounded-lg bg-emerald-800 px-4 py-2.5 text-sm font-semibold text-white">Simpan perubahan</button></form>
@endsection
