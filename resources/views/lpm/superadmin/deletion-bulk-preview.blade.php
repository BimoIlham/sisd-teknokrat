@extends('layouts.app', ['title' => 'Konfirmasi Penghapusan Semua'])

@section('content')
<div class="max-w-2xl space-y-6">
    <a href="{{ route('superadmin.deletions.index', ['type' => $type]) }}" class="text-sm text-[#7B1113] underline">Kembali ke daftar</a>
    <div>
        <h1 class="text-2xl font-bold">{{ $type === 'lecturer' ? 'Hapus Semua Dosen' : 'Hapus Semua Supervisor' }}</h1>
        <p class="text-sm text-stone-600">Mencakup seluruh database, termasuk halaman lain dan periode lama. Tindakan ini tidak dapat dibatalkan.</p>
    </div>
    <div class="rounded-xl bg-white p-5 shadow-sm">
        <h2 class="mb-3 font-semibold">Pratinjau dampak</h2>
        <dl class="grid grid-cols-2 gap-3 text-sm">
            @foreach($impact['counts'] as $label => $count)
                <div class="flex justify-between border-b pb-2"><dt>{{ str_replace('_', ' ', ucfirst($label)) }}</dt><dd class="font-bold">{{ $count }}</dd></div>
            @endforeach
        </dl>
    </div>
    <p class="rounded-lg bg-amber-50 p-4 text-sm text-amber-900">{{ $type === 'lecturer' ? 'PDF impor dan periode supervisi tetap tersimpan.' : 'Identitas dosen dan jadwal mengajarnya tetap tersimpan.' }} Akun supervisor terkait dapat dihapus atau dinonaktifkan agar riwayat audit tetap utuh.</p>
    @if($impact['protected_names'] !== [])
        <div class="rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-900">
            Penghapusan dibatalkan karena akun administrator terhubung ke: {{ implode(', ', $impact['protected_names']) }}. Lepaskan hubungan akun tersebut terlebih dahulu.
        </div>
    @elseif($impact['ids'] === [])
        <p class="rounded-lg bg-stone-100 p-4 text-sm text-stone-700">Tidak ada data untuk dihapus.</p>
    @else
        <form method="POST" action="{{ route('superadmin.deletions.bulk.destroy', ['type' => $type]) }}" class="space-y-4 rounded-xl bg-white p-5 shadow-sm">
            @csrf @method('DELETE')
            <input type="hidden" name="preview_token" value="{{ $impact['token'] }}">
            <label class="block text-sm font-medium">Ketik frasa persis: <strong>{{ $impact['phrase'] }}</strong><input class="mt-1 w-full rounded-lg border p-2" type="text" name="confirmation_phrase" required autocomplete="off"></label>
            <label class="block text-sm font-medium">Kata sandi superadmin<input class="mt-1 w-full rounded-lg border p-2" type="password" name="password" required autocomplete="current-password"></label>
            @if($errors->any())<p class="text-sm text-red-700">{{ $errors->first() }}</p>@endif
            <button class="inline-flex min-h-11 items-center justify-center rounded-lg bg-rose-600 px-5 py-2.5 font-semibold text-white shadow-sm transition-colors hover:bg-rose-700" type="submit">Hapus semua secara permanen</button>
        </form>
    @endif
</div>
@endsection
