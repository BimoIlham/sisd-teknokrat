@extends('layouts.app', ['title' => 'Akun Fakultas dan Prodi'])

@section('content')
<div class="space-y-6">
    <header>
        <h1 class="text-2xl font-bold text-slate-900">Akun Fakultas dan Prodi</h1>
        <p class="mt-1 text-sm text-slate-600">Akun hanya dapat melihat dosen dan hasil supervisi pada unit yang ditetapkan.</p>
    </header>
    @if ($errors->any())
        <div role="alert" class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800">{{ $errors->first() }}</div>
    @endif
    @php
        $facultyOptions = $faculties->map(fn ($faculty) => ['id' => $faculty->id, 'code' => $faculty->code, 'name' => $faculty->name])->values();
        $studyProgramOptions = $studyPrograms->map(fn ($program) => ['id' => $program->id, 'code' => $program->code, 'name' => $program->name, 'faculty_code' => $program->faculty?->code])->values();
        $initialAccount = ['role' => old('role', 'fakultas'), 'facultyId' => old('faculty_id', ''), 'studyProgramId' => old('study_program_id', ''), 'name' => old('name', ''), 'email' => old('email', '')];
    @endphp
    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm" x-data="unitAccountCreator(@js($facultyOptions), @js($studyProgramOptions), @js($initialAccount))">
        <h2 class="mb-4 text-lg font-semibold text-slate-900">Buat akun unit</h2>
        <form method="POST" action="{{ route('lpm.unit_accounts.store') }}" class="grid gap-3 sm:grid-cols-2">@csrf
            <label class="text-sm font-medium text-slate-700">Peran<select name="role" x-model="role" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2"><option value="fakultas">Fakultas</option><option value="prodi">Program Studi</option></select></label>
            <label x-show="role === 'fakultas'" class="text-sm font-medium text-slate-700">Fakultas<select name="faculty_id" x-model="facultyId" :disabled="role !== 'fakultas'" :required="role === 'fakultas'" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2"><option value="">Pilih fakultas</option>@foreach ($faculties as $faculty)<option value="{{ $faculty->id }}" @disabled($facultyCounts->get($faculty->id, 0) > 0)>{{ $faculty->name }}{{ $facultyCounts->get($faculty->id, 0) > 0 ? ' · Sudah memiliki akun' : '' }}</option>@endforeach</select></label>
            <label x-show="role === 'prodi'" x-cloak class="text-sm font-medium text-slate-700">Program studi<select name="study_program_id" x-model="studyProgramId" :disabled="role !== 'prodi'" :required="role === 'prodi'" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2"><option value="">Pilih program studi</option>@foreach ($studyPrograms as $program)<option value="{{ $program->id }}" @disabled($programCounts->get($program->id, 0) > 0)>{{ $program->name }} · {{ $program->faculty?->name }}{{ $programCounts->get($program->id, 0) > 0 ? ' · Sudah memiliki akun' : '' }}</option>@endforeach</select></label>
            <label class="text-sm font-medium text-slate-700">Nama pengguna<input name="name" x-model="name" required maxlength="255" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2"></label>
            <label class="text-sm font-medium text-slate-700">Email<input name="email" type="email" x-model="email" required maxlength="255" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2"></label>
            <p class="text-xs text-slate-500 sm:col-span-2">Nama dan email diisi sebagai saran setelah unit dipilih. Periksa alamat email sebelum menyimpan; keduanya dapat diubah.</p>
            <div class="sm:col-span-2"><button class="rounded-xl bg-[#7B1113] px-4 py-2 text-sm font-semibold text-white">Buat akun</button></div>
            @if (session('temporary_password'))
                <div id="temporary-password" role="status" class="scroll-mt-6 rounded-xl border-2 border-amber-400 bg-amber-50 p-4 sm:col-span-2">
                    <h3 class="text-base font-bold text-amber-950">Kata sandi sementara akun</h3>
                    <p class="mt-1 text-sm text-amber-900">Salin sekarang. Informasi ini hanya tampil sekali.</p>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <label class="text-sm font-semibold text-amber-950">Email akun<input type="text" readonly value="{{ session('temporary_password.email') }}" onclick="this.select()" class="mt-1 w-full rounded-xl border border-amber-300 bg-white px-3 py-2 text-slate-900"></label>
                        <label class="text-sm font-semibold text-amber-950">Kata sandi sementara<input type="text" readonly value="{{ session('temporary_password.password') }}" onclick="this.select()" class="mt-1 w-full rounded-xl border border-amber-300 bg-white px-3 py-2 font-mono font-bold text-slate-900"></label>
                    </div>
                    <p class="mt-3 text-xs text-amber-900">Klik kolom untuk memilih seluruh isinya. Pengguna wajib mengganti kata sandi saat masuk pertama kali. Jika terlewat, gunakan Reset kata sandi pada akun tersebut.</p>
                </div>
            @endif
        </form>
        <p class="mt-3 text-xs text-slate-500">Kata sandi sementara ditampilkan sekali setelah akun dibuat. Pengguna wajib menggantinya saat masuk pertama kali.</p>
    </section>
    <section class="space-y-3">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="text-lg font-semibold text-slate-900">Akun terdaftar</h2>
            </div>
            <form method="GET" action="{{ route('lpm.unit_accounts.index') }}" class="flex items-center gap-2">
                <label for="unit-account-role-filter" class="text-sm font-medium text-slate-700">Filter peran</label>
                <select id="unit-account-role-filter" name="role" onchange="this.form.submit()" class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm">
                    <option value="all" @selected($roleFilter === 'all')>Semua</option>
                    <option value="fakultas" @selected($roleFilter === 'fakultas')>Fakultas</option>
                    <option value="prodi" @selected($roleFilter === 'prodi')>Prodi</option>
                </select>
            </form>
        </div>
        <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="min-w-[780px] w-full text-left text-sm">
                    <thead class="bg-slate-50 text-xs font-semibold uppercase text-slate-500">
                        <tr>
                            <th class="px-4 py-3 text-center">No.</th>
                            <th class="px-4 py-3">Nama</th>
                            <th class="px-4 py-3">Email</th>
                            <th class="px-4 py-3">Peran</th>
                            <th class="px-4 py-3">Unit</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3 text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($users as $user)
                            @php
                                $userRole = $user->hasRole('prodi') ? 'prodi' : 'fakultas';
                                $duplicateCount = $userRole === 'prodi'
                                    ? $programCounts->get($user->study_program_id, 0)
                                    : $facultyCounts->get($user->faculty_id, 0);
                            @endphp
                            <tr class="hover:bg-slate-50/60">
                                <td class="px-4 py-3 text-center text-slate-500">{{ $users->firstItem() + $loop->index }}</td>
                                <td class="px-4 py-3 font-semibold text-slate-900">{{ $user->name }}</td>
                                <td class="px-4 py-3 text-slate-600">{{ $user->email }}</td>
                                <td class="px-4 py-3">{{ $userRole === 'prodi' ? 'Prodi' : 'Fakultas' }}</td>
                                <td class="px-4 py-3">
                                    <span>{{ $userRole === 'prodi' ? ($user->studyProgram?->name ?? 'Belum ditetapkan') : ($user->faculty?->name ?? 'Belum ditetapkan') }}</span>
                                    @if ($duplicateCount > 1)
                                        <span class="mt-1 block w-fit rounded-lg bg-amber-50 px-2 py-1 text-xs font-semibold text-amber-800">Perlu ditinjau · {{ $duplicateCount }} akun</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3"><span class="rounded-lg px-2 py-1 text-xs font-semibold {{ $user->is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">{{ $user->is_active ? 'Aktif' : 'Nonaktif' }}</span></td>
                                <td class="px-4 py-3 text-center">
                                    <div class="flex items-center justify-center gap-1">
                                        <a href="{{ route('lpm.unit_accounts.show', $user) }}" aria-label="Detail akun {{ $user->name }}" title="Detail akun {{ $user->name }}" class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-slate-200 text-[#7B1113] hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#7B1113]">
                                            <svg aria-hidden="true" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.25 12s3.5-6.75 9.75-6.75S21.75 12 21.75 12s-3.5 6.75-9.75 6.75S2.25 12 2.25 12z" /><circle cx="12" cy="12" r="2.5" stroke-width="2" /></svg>
                                        </a>
                                        <form method="POST" action="{{ route('lpm.unit_accounts.destroy', $user) }}">
                                            @csrf
                                            @method('DELETE')
                                            <button type="button" onclick="window.confirmModal({ title: 'Hapus akun unit?', text: 'Akun ' + @js($user->email) + ' akan dihapus permanen. Fakultas, prodi, dan data dosen tetap tersimpan.', icon: 'warning', confirmText: 'Ya, hapus akun', confirmColor: '#B91C1C', onConfirm: () => this.form.submit() })" aria-label="Hapus akun {{ $user->name }}" title="Hapus akun {{ $user->name }}" class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-rose-200 text-rose-700 hover:bg-rose-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rose-500">
                                                <svg aria-hidden="true" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-4 py-8 text-center text-slate-500">Belum ada akun pada filter ini.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="border-t border-slate-200 px-4 py-3">{{ $users->links() }}</div>
        </div>
    </section>
</div>
@endsection
