<div>
    <!-- Nothing in life is to be feared, it is only to be understood. Now is the time to understand more, so that we may fear less. - Maria Skłodowska-Curie -->
</div>
@extends('layouts.app')

@section('title', $isEditing ? 'Ubah draft bon' : 'Ajukan bon')
@section('breadcrumb', 'Bon operasional / '.($isEditing ? 'Ubah draft' : 'Pengajuan'))

@section('content')
    <div class="max-w-3xl">
        <h1 class="text-3xl font-semibold tracking-tight">{{ $isEditing ? 'Ubah draft bon' : 'Ajukan bon operasional' }}</h1>
        <p class="mt-2 text-sm text-slate-600">Bon yang disetujui belum dicairkan. Pencairan dicatat pada subfase berikutnya.</p>

        <form method="POST" action="{{ $isEditing ? route('operational-advances.update', $advance) : route('operational-advances.store') }}" enctype="multipart/form-data" class="mt-6 space-y-5 rounded-xl border border-slate-200 bg-white p-5 sm:p-7">
            @csrf
            @if($isEditing) @method('PUT') @endif

            @if($users->isNotEmpty())
                <div class="grid gap-4 sm:grid-cols-2">
                    <label><span class="mb-1 block text-sm font-medium">Pemohon</span><select name="requester_user_id" class="w-full rounded-lg border-slate-300 text-sm"><option value="{{ auth()->id() }}">{{ auth()->user()->name }} (saya)</option>@foreach($users as $user)<option value="{{ $user->id }}" @selected((string) old('requester_user_id', $advance->requester_user_id) === (string) $user->id)>{{ $user->name }}</option>@endforeach</select></label>
                    <label><span class="mb-1 block text-sm font-medium">Penerima / penanggung jawab</span><select name="responsible_user_id" class="w-full rounded-lg border-slate-300 text-sm"><option value="{{ auth()->id() }}">{{ auth()->user()->name }} (saya)</option>@foreach($users as $user)<option value="{{ $user->id }}" @selected((string) old('responsible_user_id', $advance->responsible_user_id) === (string) $user->id)>{{ $user->name }}</option>@endforeach</select></label>
                </div>
                <label class="block"><span class="mb-1 block text-sm font-medium">Alasan mandat jika mengajukan untuk orang lain</span><textarea name="mandate_reason" rows="2" class="w-full rounded-lg border-slate-300 text-sm">{{ old('mandate_reason', $advance->mandate_reason) }}</textarea></label>
            @endif

            <label class="block"><span class="mb-1 block text-sm font-medium">Tujuan bon</span><textarea name="purpose" required rows="3" class="w-full rounded-lg border-slate-300 text-sm">{{ old('purpose', $advance->purpose) }}</textarea></label>
            <div class="grid gap-4 sm:grid-cols-2">
                <label><span class="mb-1 block text-sm font-medium">Nominal diminta (Rp)</span><input name="requested_amount" inputmode="decimal" value="{{ old('requested_amount', $advance->requested_amount) }}" required class="w-full rounded-lg border-slate-300 text-sm"></label>
                <label><span class="mb-1 block text-sm font-medium">Batas waktu pertanggungjawaban</span><input type="date" name="due_date" value="{{ old('due_date', $advance->due_date?->format('Y-m-d')) }}" required class="w-full rounded-lg border-slate-300 text-sm"></label>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <label><span class="mb-1 block text-sm font-medium">Akun kas rencana</span><select name="planned_cash_account_id" required class="w-full rounded-lg border-slate-300 text-sm"><option value="">Pilih akun kas</option>@foreach($cashAccounts as $cashAccount)<option value="{{ $cashAccount->id }}" @selected((string) old('planned_cash_account_id', $advance->planned_cash_account_id) === (string) $cashAccount->id)>{{ $cashAccount->name }}</option>@endforeach</select></label>
                <label><span class="mb-1 block text-sm font-medium">Kegiatan (opsional)</span><select name="activity_id" class="w-full rounded-lg border-slate-300 text-sm"><option value="">Tidak ada kegiatan khusus</option>@foreach($activities as $activity)<option value="{{ $activity->id }}" @selected((string) old('activity_id', $advance->activity_id) === (string) $activity->id)>{{ $activity->name }}</option>@endforeach</select></label>
            </div>
            <label class="block"><span class="mb-1 block text-sm font-medium">Lampiran pengajuan (opsional, maks. 10 MB per file)</span><input type="file" name="attachments[]" multiple accept=".jpg,.jpeg,.png,.pdf,.heic" class="w-full rounded-lg border border-slate-300 p-2 text-sm"></label>

            @if($advance->exists && $advance->attachments->isNotEmpty())
                <div><p class="text-sm font-medium">Lampiran tersimpan</p><ul class="mt-2 list-inside list-disc text-sm text-slate-600">@foreach($advance->attachments as $attachment)<li>{{ $attachment->original_name }}</li>@endforeach</ul></div>
            @endif

            <div class="flex flex-wrap gap-3 border-t border-slate-100 pt-4"><button class="rounded-lg bg-emerald-800 px-4 py-2.5 text-sm font-semibold text-white">Simpan draft</button><a href="{{ $advance->exists ? route('operational-advances.show', $advance) : route('operational-advances.index') }}" class="rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-medium">Kembali</a></div>
        </form>
    </div>
@endsection
