@extends('layouts.app', ['title' => 'Akun LPM'])

@section('content')
<div class="space-y-6">
    <h1 class="text-2xl font-bold">Akun Administrator LPM</h1>
    <form method="POST" action="{{ route('superadmin.users.store') }}" class="flex flex-wrap gap-3 rounded-xl bg-white p-5 shadow-sm">@csrf
        <input name="name" placeholder="Nama" required class="rounded-lg border p-2" value="{{ old('name') }}">
        <input name="email" type="email" placeholder="Email" required class="rounded-lg border p-2" value="{{ old('email') }}">
        <button class="rounded-lg bg-[#7B1113] px-4 py-2 text-white">Buat akun LPM</button>
    </form>
    <p class="text-sm text-stone-600">Kata sandi awal ditampilkan sekali setelah akun dibuat atau direset. Pengguna wajib menggantinya saat masuk.</p>
    @if($errors->any())<p class="text-sm text-red-700">{{ $errors->first() }}</p>@endif
    <div class="space-y-3">@foreach($users as $user)
        <div class="rounded-xl bg-white p-5 shadow-sm"><form method="POST" action="{{ route('superadmin.users.update', $user) }}" class="flex flex-wrap items-end gap-3">@csrf @method('PUT')
            <label class="text-sm">Nama<input class="mt-1 block rounded-lg border p-2" name="name" value="{{ $user->name }}" required></label>
            <label class="text-sm">Email<input class="mt-1 block rounded-lg border p-2" name="email" type="email" value="{{ $user->email }}" required></label>
            <label class="text-sm">Status<select name="is_active" class="mt-1 block rounded-lg border p-2"><option value="1" @selected($user->is_active)>Aktif</option><option value="0" @selected(! $user->is_active)>Nonaktif</option></select></label>
            <button class="rounded-lg bg-[#7B1113] px-4 py-2 text-white">Simpan</button>
        </form><form method="POST" action="{{ route('superadmin.users.reset_password', $user) }}" class="mt-3">@csrf<button type="button" class="text-sm text-[#7B1113] underline" onclick="window.confirmModal({ title: 'Reset kata sandi akun?', text: 'Kata sandi saat ini akan diganti. Kata sandi sementara yang baru ditampilkan setelah reset.', icon: 'warning', confirmText: 'Ya, reset', onConfirm: () => this.form.submit() })">Reset kata sandi</button></form></div>
    @endforeach</div>
    {{ $users->links() }}
</div>
@endsection
