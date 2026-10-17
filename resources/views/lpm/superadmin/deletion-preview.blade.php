@extends('layouts.app', ['title' => 'Konfirmasi Penghapusan Permanen'])

@section('content')
<div class="max-w-2xl space-y-6">
    <a href="{{ route('superadmin.deletions.index', ['type' => $type]) }}" class="text-sm text-[#7B1113] underline">Kembali ke daftar</a>
    <div><h1 class="text-2xl font-bold">Hapus permanen: {{ $impact['name'] }}</h1><p class="text-sm text-stone-600">Tindakan ini menghapus data terkait dari database dan file yang dimiliki item.</p></div>
    @if($type === 'lecturer')<p class="rounded-lg bg-blue-50 p-4 text-sm text-blue-900">Penghapusan dosen ini hanya berlaku untuk periode #{{ $periodId }}. Identitas dosen tetap tersedia untuk periode berikutnya. Hapus Semua Dosen mencakup seluruh database.</p>@endif
    <div class="rounded-xl bg-white p-5 shadow-sm"><h2 class="mb-3 font-semibold">Pratinjau dampak</h2><dl class="grid grid-cols-2 gap-3 text-sm">@foreach($impact['counts'] as $label => $count)<div class="flex justify-between border-b pb-2"><dt>{{ str_replace('_', ' ', ucfirst($label)) }}</dt><dd class="font-bold">{{ $count }}</dd></div>@endforeach</dl></div>
    @if(in_array($type, ['lecturer', 'supervisor']))<p class="rounded-lg bg-amber-50 p-4 text-sm text-amber-900">Akun terkait dihapus jika tidak lagi dipakai sebagai identitas pembuat data lain. Jika masih dipakai, akun dinonaktifkan dan dilepas dari dosen agar riwayat audit tetap utuh.</p>@endif
    <form method="POST" action="{{ route('superadmin.deletions.destroy', ['type' => $type, 'id' => $id]) }}" class="space-y-4 rounded-xl bg-white p-5 shadow-sm">
        @csrf @method('DELETE')
        <input type="hidden" name="preview_token" value="{{ $impact['token'] }}">
        @if($type === 'lecturer')<input type="hidden" name="period_id" value="{{ $periodId }}">@endif
        <label class="block text-sm font-medium">Ketik nama item persis: <strong>{{ $impact['name'] }}</strong><input class="mt-1 w-full rounded-lg border p-2" type="text" name="confirmation_name" required autocomplete="off"></label>
        <label class="block text-sm font-medium">Kata sandi superadmin<input class="mt-1 w-full rounded-lg border p-2" type="password" name="password" required autocomplete="current-password"></label>
        @if($errors->any())<p class="text-sm text-red-700">{{ $errors->first() }}</p>@endif
        <button class="inline-flex min-h-11 items-center justify-center rounded-lg bg-rose-600 px-5 py-2.5 font-semibold text-white shadow-sm transition-colors hover:bg-rose-700" type="submit">Hapus permanen</button>
    </form>
</div>
@endsection
