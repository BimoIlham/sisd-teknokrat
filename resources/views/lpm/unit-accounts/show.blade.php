@extends('layouts.app', ['title' => 'Detail Akun Unit'])

@section('content')
<div class="mx-auto max-w-4xl space-y-6">
    <header class="space-y-2">
        <a href="{{ route('lpm.unit_accounts.index') }}" class="text-sm font-semibold text-[#7B1113] hover:underline">&larr; Kembali ke akun Fakultas dan Prodi</a>
        <h1 class="text-2xl font-bold text-slate-900">Detail Akun Unit</h1>
        <p class="text-sm text-slate-600">{{ $user->name }} · {{ $user->email }}</p>
    </header>

    @if ($errors->any())
        <div role="alert" class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800">{{ $errors->first() }}</div>
    @endif

    @php
        $userRole = $user->hasRole('prodi') ? 'prodi' : 'fakultas';
        $duplicateCount = $userRole === 'prodi'
            ? $programCounts->get($user->study_program_id, 0)
            : $facultyCounts->get($user->faculty_id, 0);
    @endphp
    @if ($duplicateCount > 1)
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            <strong>Perlu ditinjau:</strong> {{ $duplicateCount }} akun memakai unit ini. Pilih akun yang dipertahankan, lalu hapus akun lainnya dari tabel.
        </div>
    @endif

    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm" x-data="{ role: @js(old('role', $userRole)) }">
        <h2 class="mb-4 text-lg font-semibold text-slate-900">Pengaturan akun</h2>
        <form method="POST" action="{{ route('lpm.unit_accounts.update', $user) }}" class="grid gap-4 sm:grid-cols-2">
            @csrf
            @method('PUT')
            <label class="text-sm font-medium text-slate-700">Nama<input name="name" value="{{ old('name', $user->name) }}" required maxlength="255" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2"></label>
            <label class="text-sm font-medium text-slate-700">Email<input name="email" type="email" value="{{ old('email', $user->email) }}" required maxlength="255" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2"></label>
            <label class="text-sm font-medium text-slate-700">Peran<select name="role" x-model="role" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2"><option value="fakultas">Fakultas</option><option value="prodi">Program Studi</option></select></label>
            <label class="text-sm font-medium text-slate-700">Status<select name="is_active" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2"><option value="1" @selected(old('is_active', (int) $user->is_active) == 1)>Aktif</option><option value="0" @selected(old('is_active', (int) $user->is_active) == 0)>Nonaktif</option></select></label>
            <label x-show="role === 'fakultas'" class="text-sm font-medium text-slate-700">Fakultas<select name="faculty_id" :disabled="role !== 'fakultas'" :required="role === 'fakultas'" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2"><option value="">Pilih fakultas</option>@foreach ($faculties as $faculty)<option value="{{ $faculty->id }}" @selected(old('faculty_id', $userRole === 'fakultas' ? $user->faculty_id : null) == $faculty->id) @disabled($facultyCounts->get($faculty->id, 0) > 0 && ! ($userRole === 'fakultas' && $user->faculty_id === $faculty->id))>{{ $faculty->name }}{{ $facultyCounts->get($faculty->id, 0) > 0 && ! ($userRole === 'fakultas' && $user->faculty_id === $faculty->id) ? ' · Sudah memiliki akun' : '' }}</option>@endforeach</select></label>
            <label x-show="role === 'prodi'" x-cloak class="text-sm font-medium text-slate-700">Program studi<select name="study_program_id" :disabled="role !== 'prodi'" :required="role === 'prodi'" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2"><option value="">Pilih program studi</option>@foreach ($studyPrograms as $program)<option value="{{ $program->id }}" @selected(old('study_program_id', $userRole === 'prodi' ? $user->study_program_id : null) == $program->id) @disabled($programCounts->get($program->id, 0) > 0 && ! ($userRole === 'prodi' && $user->study_program_id === $program->id))>{{ $program->name }} · {{ $program->faculty?->name }}{{ $programCounts->get($program->id, 0) > 0 && ! ($userRole === 'prodi' && $user->study_program_id === $program->id) ? ' · Sudah memiliki akun' : '' }}</option>@endforeach</select></label>
            <div class="sm:col-span-2"><button class="rounded-xl bg-[#7B1113] px-4 py-2 text-sm font-semibold text-white">Simpan perubahan</button></div>
        </form>
    </section>

    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <h2 class="text-lg font-semibold text-slate-900">Kata sandi</h2>
        <p class="mt-1 text-sm text-slate-600">Reset mengganti kata sandi akun dan mencabut sesi login yang aktif.</p>
        <form method="POST" action="{{ route('lpm.unit_accounts.reset_password', $user) }}" class="mt-4">
            @csrf
            <button type="button" onclick="window.confirmModal({ title: 'Reset kata sandi akun?', text: 'Kata sandi saat ini akan diganti. Kata sandi sementara yang baru ditampilkan setelah reset.', icon: 'warning', confirmText: 'Ya, reset', onConfirm: () => this.form.submit() })" class="rounded-xl border border-[#7B1113] px-4 py-2 text-sm font-semibold text-[#7B1113]">Reset kata sandi</button>
        </form>
        @if (session('temporary_password'))
            <div id="temporary-password" role="status" class="mt-4 scroll-mt-6 rounded-xl border-2 border-amber-400 bg-amber-50 p-4">
                <h3 class="font-bold text-amber-950">Kata sandi sementara akun</h3>
                <p class="mt-1 text-sm text-amber-900">Salin sekarang. Informasi ini hanya tampil sekali.</p>
                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    <label class="text-sm font-semibold text-amber-950">Email akun<input type="text" readonly value="{{ session('temporary_password.email') }}" onclick="this.select()" class="mt-1 w-full rounded-xl border border-amber-300 bg-white px-3 py-2 text-slate-900"></label>
                    <label class="text-sm font-semibold text-amber-950">Kata sandi sementara<input type="text" readonly value="{{ session('temporary_password.password') }}" onclick="this.select()" class="mt-1 w-full rounded-xl border border-amber-300 bg-white px-3 py-2 font-mono font-bold text-slate-900"></label>
                </div>
                <p class="mt-3 text-xs text-amber-900">Pengguna wajib mengganti kata sandi saat masuk pertama kali.</p>
            </div>
        @endif
    </section>
</div>
@endsection
