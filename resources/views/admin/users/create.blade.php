@extends('layouts.app')
@section('title', 'Tambah pengguna')
@section('breadcrumb', 'Pengguna / Tambah')
@section('content')
<h1 class="text-3xl font-semibold">Tambah pengguna</h1><form method="POST" action="{{ route('admin.users.store') }}" class="mt-6 max-w-3xl space-y-5 rounded-xl border border-slate-200 bg-white p-6">@csrf
@include('admin.users.form', ['user' => new App\Models\User])
<div><label class="mb-1 block text-sm font-medium">Kata sandi awal (min. 12 karakter)</label><input name="password" type="password" required autocomplete="new-password" class="w-full rounded-lg border-slate-300"></div><div><label class="mb-1 block text-sm font-medium">Konfirmasi kata sandi</label><input name="password_confirmation" type="password" required autocomplete="new-password" class="w-full rounded-lg border-slate-300"></div>
@include('admin.users.role-fields')
<div class="flex gap-3"><button class="rounded-lg bg-emerald-800 px-4 py-2.5 text-sm font-semibold text-white">Simpan pengguna</button><a href="{{ route('admin.users.index') }}" class="rounded-lg border px-4 py-2.5 text-sm">Batal</a></div></form>
@endsection
