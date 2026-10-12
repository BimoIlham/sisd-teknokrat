@extends('layouts.app', ['title' => 'Daftar Tim Supervisor'])

@section('content')
<div x-data="supervisorDirectoryApp()" x-init="if (addModalOpen) { toggleBodyScroll(); loadCandidates(); }" class="space-y-6 pb-12">
    <!-- Header & Breadcrumbs -->
    <div class="space-y-3">
        <nav class="flex items-center gap-1.5 text-xs text-slate-400">
            <a href="{{ route('lpm.dashboard') }}" class="hover:text-[#7B1113] transition-colors font-medium text-slate-500">Dashboard</a>
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
            <span class="font-semibold text-slate-700">Daftar Tim Supervisor</span>
        </nav>

        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
                <h1 class="text-xl sm:text-2xl font-bold text-slate-900 tracking-tight">
                    Daftar Tim Supervisor
                </h1>
                <p class="text-xs text-slate-500 mt-1">
                    Daftar dosen yang ditugaskan sebagai supervisor evaluasi perkuliahan di lingkungan Universitas Teknokrat Indonesia.
                </p>
            </div>

            <div class="flex items-center gap-2.5 shrink-0">
                <button 
                    type="button" 
                    @click="openAddModal()" 
                    @disabled(!$selectedPeriod)
                    class="inline-flex items-center px-4 py-2.5 rounded-xl bg-[#7B1113] hover:bg-[#600d0e] text-white text-xs font-semibold shadow-sm transition-all hover:shadow"
                >
                    <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"></path></svg>
                    Tetapkan Supervisor Baru
                </button>
            </div>
        </div>
    </div>

    <!-- Alert Messages -->
    @if(session('success'))
        <div class="p-4 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-900 text-xs sm:text-sm flex items-center justify-between shadow-2xs">
            <div class="flex items-center gap-2.5">
                <div class="w-7 h-7 rounded-full bg-emerald-100 text-emerald-700 flex items-center justify-center shrink-0">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                </div>
                <span>{{ session('success') }}</span>
            </div>
            <button type="button" @click="$el.parentElement.remove()" class="text-emerald-700 hover:text-emerald-900 font-bold p-1">&times;</button>
        </div>
    @endif

    @if(session('error'))
        <div class="p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-900 text-xs sm:text-sm flex items-center justify-between shadow-2xs">
            <div class="flex items-center gap-2.5">
                <div class="w-7 h-7 rounded-full bg-rose-100 text-rose-700 flex items-center justify-center shrink-0">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                </div>
                <span>{{ session('error') }}</span>
            </div>
            <button type="button" @click="$el.parentElement.remove()" class="text-rose-700 hover:text-rose-900 font-bold p-1">&times;</button>
        </div>
    @endif

    @if($rotatedCredential)
        <div class="p-4 rounded-xl bg-amber-50 border border-amber-200 text-amber-950 text-xs sm:text-sm shadow-2xs">
            <p class="font-semibold">Kata sandi sementara: {{ $rotatedCredential['email'] }}</p>
            <p class="mt-1">Ditampilkan sekali. Sampaikan hanya kepada pemilik akun melalui saluran yang sesuai. Pemilik wajib menggantinya setelah login. Jika hilang, lakukan rotasi baru.</p>
            <code class="inline-block mt-3 px-3 py-2 rounded-lg bg-white border border-amber-200 font-mono select-all break-all">{{ $rotatedCredential['password'] }}</code>
        </div>
    @endif

    <!-- Summary Metrics -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
        <div class="bg-white rounded-2xl p-4 sm:p-5 border border-slate-200/90 shadow-2xs flex items-center gap-3.5">
            <div class="w-11 h-11 rounded-xl bg-[#7B1113]/10 text-[#7B1113] flex items-center justify-center shrink-0">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
            </div>
            <div>
                <p class="text-xs text-slate-500 font-medium">Supervisor Periode Ini</p>
                <p class="text-xl sm:text-2xl font-bold text-slate-900 mt-0.5">{{ $totalSupervisors }}</p>
                <p class="text-[11px] text-slate-400 mt-1">Supervisor yang masih ditetapkan pada periode ini</p>
            </div>
        </div>

        <div class="bg-white rounded-2xl p-4 sm:p-5 border border-slate-200/90 shadow-2xs flex items-center gap-3.5">
            <div class="w-11 h-11 rounded-xl bg-blue-50 text-blue-700 flex items-center justify-center shrink-0">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            </div>
            <div>
                <p class="text-xs text-slate-500 font-medium">Tugas Belum Selesai</p>
                <p class="text-xl sm:text-2xl font-bold text-blue-700 mt-0.5">{{ $pendingAssignmentsCount }}</p>
                <p class="text-[11px] text-slate-400 mt-1">Tugas yang hasil supervisinya belum dikirim</p>
            </div>
        </div>

        <div class="bg-white rounded-2xl p-4 sm:p-5 border border-slate-200/90 shadow-2xs flex items-center gap-3.5">
            <div class="w-11 h-11 rounded-xl bg-amber-50 text-amber-700 flex items-center justify-center shrink-0">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path></svg>
            </div>
            <div>
                <p class="text-xs text-slate-500 font-medium">Total Tugas Terjadwal</p>
                <p class="text-xl sm:text-2xl font-bold text-slate-900 mt-0.5">{{ $totalAssignments }}</p>
                <p class="text-[11px] text-slate-400 mt-1">Tugas seluruh tahap, tanpa pembatalan</p>
            </div>
        </div>

        <div class="bg-white rounded-2xl p-4 sm:p-5 border border-slate-200/90 shadow-2xs flex items-center gap-3.5">
            <div class="w-11 h-11 rounded-xl bg-emerald-50 text-emerald-700 flex items-center justify-center shrink-0">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z"></path></svg>
            </div>
            <div>
                <p class="text-xs text-slate-500 font-medium">Supervisi Selesai</p>
                <p class="text-xl sm:text-2xl font-bold text-emerald-700 mt-0.5">{{ $totalCompletedAssignments }}</p>
                <p class="text-[11px] text-slate-400 mt-1">Hasil supervisi yang sudah dikirim</p>
            </div>
        </div>
    </div>

    <!-- Filter & Search Bar -->
    <div class="filter-bar">
        <form method="GET" action="{{ route('lpm.supervisors.index') }}" class="filter-controls" style="--filter-columns: minmax(0,2fr) minmax(0,1fr) minmax(0,1.7fr) auto">
            <!-- Search Input -->
            <div class="filter-search">
                <svg class="filter-search-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                <input
                    type="text"
                    name="search" aria-label="Cari supervisor"
                    value="{{ request('search') }}"
                    placeholder="Cari nama, NIDN/kode, atau email..."
                    class="filter-control"
                >
            </div>

            <!-- Filter Fakultas -->
            <div class="filter-field">
                <select name="faculty_id" aria-label="Fakultas" onchange="this.form.submit()" class="filter-control">
                    <option value="">Semua Fakultas</option>
                    @foreach($faculties as $fac)
                        <option value="{{ $fac->id }}" {{ request('faculty_id') == $fac->id ? 'selected' : '' }}>
                            {{ $fac->code }}
                        </option>
                    @endforeach
                </select>
            </div>

            <!-- Filter Periode Supervisi -->
            <div class="filter-field">
                <select name="period_id" aria-label="Semester Supervisi" onchange="this.form.submit()" class="filter-control">
                    @foreach($periods as $p)
                        <option value="{{ $p->id }}" {{ (int) $periodId === $p->id ? 'selected' : '' }}>
                            {{ $p->academicTerm?->semester_label ?? 'Semester Supervisi' }} {{ $p->academicTerm?->academic_year ?? '-' }}
                        </option>
                    @endforeach
                </select>
            </div>

            <!-- Submit & Reset -->
            <div class="filter-actions">
                <button type="submit" class="filter-button">
                    Filter
                </button>
                @if(request('search') || request('faculty_id') || request('status'))
                    <a href="{{ route('lpm.supervisors.index', ['period_id' => $selectedPeriod?->id]) }}" title="Reset Filter" aria-label="Reset Filter" class="filter-reset">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                    </a>
                @endif
            </div>
        </form>
    </div>

    <!-- Supervisors Table / Card List -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-2xs overflow-hidden">
        @if($supervisors->count() > 0)
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-slate-50/80 border-b border-slate-200 text-[11px] font-semibold text-slate-500 uppercase tracking-wider">
                            <th class="w-16 py-3 px-4 text-center">No.</th>
                            <th class="py-3 px-4 sm:px-5">Informasi Supervisor</th>
                            <th class="py-3 px-4">Fakultas / Program Studi</th>
                            <th class="py-3 px-4">Akun Login</th>
                            <th class="py-3 px-4 text-center">Beban Mengajar</th>
                            <th class="py-3 px-4 text-center">Tugas Supervisi</th>
                            <th class="py-3 px-4 text-center">Periode Ditugaskan</th>
                            <th class="py-3 px-4 text-right whitespace-nowrap">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 text-xs">
                        @foreach($supervisors as $supervisor)
                            @php
                                $user = $supervisor->user;
                                $isSupervisorUser = $user && $user->hasRole('supervisor');
                            @endphp
                            <tr class="hover:bg-slate-50/60 transition-colors">
                                <td class="w-16 py-3.5 px-4 text-center text-sm tabular-nums text-slate-600">
                                    {{ $supervisors->firstItem() + $loop->index }}
                                </td>
                                <!-- Profile & Name -->
                                <td class="py-3.5 px-4 sm:px-5">
                                    <div>
                                        <p class="font-bold text-slate-900 text-xs sm:text-sm leading-snug">
                                            {{ $supervisor->name }}
                                        </p>
                                        <div class="flex items-center gap-2 mt-0.5">
                                            <span class="text-[11px] font-mono text-slate-500">
                                                NIDN: {{ $supervisor->nidn ?? 'Belum tersedia' }}
                                            </span>
                                            @if($isSupervisorUser)
                                                <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                                    Akun Aktif
                                                </span>
                                            @else
                                                <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold bg-amber-50 text-amber-700 border border-amber-200">
                                                    Belum Ada Akses
                                                </span>
                                            @endif
                                        </div>
                                    </div>
                                </td>

                                <!-- Faculty & Prodi -->
                                <td class="py-3.5 px-4 text-slate-600">
                                    <div class="font-medium text-slate-800">
                                        {{ $supervisor->studyProgram?->name ?? '-' }}
                                    </div>
                                    <div class="text-[11px] text-slate-400">
                                        {{ $supervisor->faculty?->name ?? '-' }}
                                    </div>
                                </td>

                                <!-- Login Account -->
                                <td class="py-3.5 px-4">
                                    @if($user)
                                        <div class="font-mono text-slate-700 text-xs">
                                            {{ $user->email }}
                                        </div>
                                        <div class="text-[10px] text-slate-400 mt-0.5">
                                            {{ $user->last_login_at ? 'Login: ' . $user->last_login_at->diffForHumans() : 'Belum pernah login' }}
                                        </div>
                                    @else
                                        <span class="text-slate-400 italic text-[11px]">Belum dibuatkan akun</span>
                                    @endif
                                </td>

                                <!-- Teaching Schedules -->
                                <td class="py-3.5 px-4 text-center">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-slate-100 text-slate-700">
                                        {{ $supervisor->teaching_schedules_count }} Jadwal
                                    </span>
                                </td>

                                <!-- Supervision Assignments -->
                                <td class="py-3.5 px-4 text-center">
                                    <div class="inline-flex flex-col items-center">
                                        <span class="font-bold text-slate-800 text-xs">
                                            {{ $supervisor->total_assignments_count }} Tugas
                                        </span>
                                        @if($supervisor->total_assignments_count > 0)
                                            <span class="text-[10px] text-emerald-600 font-medium">
                                                {{ $supervisor->completed_assignments_count }} Selesai
                                            </span>
                                        @else
                                            <span class="text-[10px] text-slate-400">Belum ada</span>
                                        @endif
                                    </div>
                                </td>

                                <!-- Assigned Periods -->
                                <td class="py-3.5 px-4 text-center">
                                    @if($supervisor->periodSupervisors->count() > 0)
                                        <div class="flex flex-wrap gap-1 justify-center max-w-[160px] mx-auto">
                                            @foreach($supervisor->periodSupervisors->take(2) as $ps)
                                                <span class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-semibold {{ $ps->is_active ? 'bg-blue-50 text-blue-700 border border-blue-200' : 'bg-slate-100 text-slate-500' }}" title="{{ $ps->period?->name }}">
                                                    {{ $ps->period?->academicTerm?->semester_label ?? 'Semester' }}
                                                </span>
                                            @endforeach
                                        </div>
                                    @else
                                        <span class="text-[11px] text-slate-400 italic">Belum terikat periode</span>
                                    @endif
                                </td>

                                <!-- Actions -->
                                <td class="py-3.5 px-4 text-right align-middle whitespace-nowrap">
                                    <div class="flex items-center justify-end gap-2">
                                        <div class="flex flex-col items-end gap-2">
                                        @foreach($assignmentLetters->get($supervisor->id, collect()) as $letter)
                                            <a href="{{ route('lpm.supervisors.assignment_letter', ['supervisor' => $supervisor, 'stage' => $letter->supervision_stage_id]) }}"
                                               class="inline-flex h-8 shrink-0 items-center gap-1.5 px-2.5 rounded-lg border border-[#7B1113]/20 bg-rose-50 hover:bg-rose-100 text-[11px] font-semibold text-[#7B1113] transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#7B1113]/30"
                                               title="Unduh surat tugas {{ $letter->document_number }}">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v12m0 0l-4-4m4 4l4-4M5 16v4a1 1 0 001 1h12a1 1 0 001-1v-4"></path></svg>
                                                Surat Tugas T{{ $letter->stage->stage_no }}
                                            </a>
                                        @endforeach
                                        </div>
                                        <div class="inline-flex items-center gap-2">
                                        <a 
                                            href="{{ route('lpm.lecturers.show', ['lecturer' => $supervisor, 'period_id' => $selectedPeriod?->id]) }}" 
                                            class="inline-flex h-8 shrink-0 items-center justify-center px-2.5 rounded-lg border border-slate-200 hover:bg-slate-50 text-[11px] font-semibold text-slate-600 transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-300"
                                            title="Lihat Detail Profil & Riwayat"
                                        >
                                            Detail
                                        </a>

                                        <button 
                                            type="button" 
                                            @click="openDeleteModal({{ json_encode(['id' => $supervisor->id, 'name' => $supervisor->name]) }})" 
                                            class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-rose-200 bg-white text-rose-600 hover:border-rose-300 hover:bg-rose-50 transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rose-200"
                                            title="Hapus dari periode ini"
                                            aria-label="Hapus {{ $supervisor->name }} dari periode ini"
                                        >
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                                        </button>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            @if($supervisors->hasPages())
                <div class="px-5 py-3 border-t border-slate-200 bg-slate-50/50">
                    {{ $supervisors->links('vendor.pagination.tailwind', ['label' => 'supervisor']) }}
                </div>
            @endif
        @else
            <!-- Empty State -->
            <div class="py-12 px-4 text-center">
                <div class="w-12 h-12 rounded-full bg-slate-100 text-slate-400 flex items-center justify-center mx-auto mb-3">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
                </div>
                <h3 class="text-sm font-bold text-slate-800">Tidak ada supervisor yang ditemukan</h3>
                <p class="text-xs text-slate-500 mt-1 max-w-sm mx-auto">
                    Tidak ditemukan data supervisor yang sesuai dengan kriteria pencarian atau filter yang dipilih.
                </p>
                <div class="mt-4">
                    <button 
                        type="button" 
                        @click="openAddModal()" 
                        class="inline-flex items-center px-4 py-2 rounded-xl bg-[#7B1113] hover:bg-[#600d0e] text-white text-xs font-semibold shadow-sm transition-colors"
                    >
                        <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                        Tetapkan Supervisor Sekarang
                    </button>
                </div>
            </div>
        @endif
    </div>

    <!-- ========================================== -->
    <!-- MODAL: TETAPKAN SUPERVISOR BARU           -->
    <!-- ========================================== -->
    <div 
        x-show="addModalOpen" 
        x-cloak 
        class="fixed inset-0 z-50 overflow-y-auto" 
        role="dialog" 
        aria-modal="true"
    >
        <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
            <div 
                x-show="addModalOpen" 
                @click="closeAddModal()" 
                class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm transition-opacity"
            ></div>

            <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>

            <div 
                x-show="addModalOpen" 
                class="relative z-10 inline-block align-bottom bg-white rounded-2xl text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full border border-slate-200"
            >
                <form action="{{ route('lpm.supervisors.store') }}" method="POST">
                    @csrf
                    <div class="p-6 border-b border-slate-100 bg-slate-50 flex items-center justify-between">
                        <div>
                            <h3 class="text-base font-bold text-slate-900">Tetapkan Supervisor Baru</h3>
                            <p class="text-xs text-slate-500 mt-0.5">Pilih dosen yang akan diberi peran dan akses sebagai supervisor perkuliahan</p>
                        </div>
                        <button type="button" @click="closeAddModal()" class="text-slate-400 hover:text-slate-600 font-bold text-lg p-1">&times;</button>
                    </div>

                    <div class="p-6 space-y-4 text-xs sm:text-sm">
                        @if($errors->any())
                            <div role="alert" class="rounded-lg border border-rose-200 bg-rose-50 p-3 text-rose-800">
                                <ul class="list-disc pl-4 space-y-1">
                                    @foreach($errors->all() as $message)
                                        <li>{{ $message }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                        <p x-show="candidatesLoading" role="status" class="text-slate-500">Memuat daftar dosen...</p>
                        <div x-show="candidatesError" role="alert" class="rounded-lg bg-rose-50 p-3 text-rose-700">
                            <span x-text="candidatesError"></span>
                            <button type="button" @click="loadCandidates()" class="ml-2 font-semibold underline">Coba lagi</button>
                        </div>
                        <!-- Select Lecturer -->
                        <div x-data="lecturerPicker()"
                             x-init="$watch('selectedId', (id) => { const lecturer = options.find((candidate) => candidate.id === id); supervisorEmail = lecturer?.email ?? ''; selectedSupervisorHasAccount = !!lecturer?.has_account; })"
                             @reset-supervisor-lecturer.window="reset(); options = []"
                             @load-supervisor-candidates.window="options = $event.detail; highlightedIndex = 0"
                             @focusout="if (!$el.contains($event.relatedTarget)) open = false">
                            <label for="supervisor-lecturer-search" class="block font-semibold text-slate-700 mb-1.5">
                                Pilih Dosen <span class="text-rose-500">*</span>
                            </label>
                            <input type="hidden" name="lecturer_id" :value="selectedId">
                            <div class="relative">
                                <svg class="absolute left-3 top-3 w-4 h-4 text-slate-400 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                                <input id="supervisor-lecturer-search" x-ref="searchInput" type="text" required autocomplete="off"
                                       role="combobox" aria-autocomplete="list" aria-controls="supervisor-lecturer-options"
                                       :aria-expanded="open"
                                       :aria-activedescendant="open && filteredOptions.length ? 'supervisor-lecturer-option-' + filteredOptions[highlightedIndex].id : null"
                                       x-model="search" @input="searchChanged($event.target.value)" @focus="showOptions()" @click="showOptions()"
                                       @keydown.arrow-down.prevent="moveHighlight(1)" @keydown.arrow-up.prevent="moveHighlight(-1)"
                                       @keydown.enter="selectHighlighted($event)" @keydown.escape.stop.prevent="open = false"
                                       x-effect="$refs.searchInput.setCustomValidity(selectedId ? '' : 'Pilih dosen dari hasil pencarian.')"
                                       :disabled="candidatesLoading || !!candidatesError" placeholder="Cari nama dosen atau NIDN..."
                                       class="w-full pl-9 pr-9 py-2.5 rounded-xl border border-slate-300 bg-white text-xs sm:text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-[#7B1113]/20 focus:border-[#7B1113]">
                                <button type="button" x-show="search" x-cloak @click="reset(); $refs.searchInput.focus()"
                                        aria-label="Hapus pilihan dosen" class="absolute right-2 top-2 px-1 text-lg text-slate-400 hover:text-slate-700">&times;</button>
                            </div>
                            <div x-show="open" x-cloak class="mt-1 rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden">
                                <ul id="supervisor-lecturer-options" x-ref="optionList" role="listbox" aria-label="Hasil pencarian dosen" class="max-h-48 overflow-y-auto">
                                    <template x-for="(lecturer, index) in filteredOptions" :key="lecturer.id">
                                        <li role="option" :id="'supervisor-lecturer-option-' + lecturer.id" :aria-selected="selectedId === lecturer.id">
                                            <button type="button" tabindex="-1" @mousedown.prevent @click="selectOption(lecturer)"
                                                    @mouseenter="highlightedIndex = index" :class="highlightedIndex === index ? 'bg-slate-100' : 'bg-white'"
                                                    class="block w-full px-3 py-2.5 text-left border-b border-slate-100">
                                                <span class="block font-semibold text-slate-900" x-text="lecturer.name"></span>
                                                <span class="block text-[11px] text-slate-500 mt-0.5" x-text="'NIDN: ' + (lecturer.nidn || 'Belum tersedia') + (lecturer.program ? ' · ' + lecturer.program : '')"></span>
                                            </button>
                                        </li>
                                    </template>
                                </ul>
                                <p x-show="filteredOptions.length === 0" role="status" class="px-3 py-3 text-xs text-slate-500">Tidak ada dosen yang cocok.</p>
                            </div>
                            <p class="text-[11px] text-slate-400 mt-1">
                                Menampilkan dosen aktif yang belum ditugaskan sebagai supervisor pada periode terpilih.
                            </p>
                        </div>

                        <!-- Supervision Period Assignment -->
                        <div>
                            <label class="block font-semibold text-slate-700 mb-1.5">
                                Periode Supervisi
                            </label>
                            <input type="hidden" name="supervision_period_id" value="{{ $selectedPeriod?->id }}">
                            <div class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-xs font-semibold text-slate-700">
                                {{ $selectedPeriod?->semester_display ?? 'Belum ada periode supervisi' }}
                            </div>
                            <p class="text-[11px] text-slate-400 mt-1">
                                Penugasan supervisor berlaku khusus untuk periode yang dipilih.
                            </p>
                        </div>

                        <!-- Custom Email (Optional) -->
                        <div>
                            <label for="supervisor-login-email" class="block font-semibold text-slate-700 mb-1.5">
                                Email Akun Login (Opsional)
                            </label>
                            <input 
                                type="email" 
                                name="email" 
                                id="supervisor-login-email"
                                x-model="supervisorEmail"
                                placeholder="Contoh: budi.santoso@teknokrat.ac.id" 
                                class="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-xs sm:text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-[#7B1113]/20 focus:border-[#7B1113] placeholder:text-slate-400"
                            >
                            <p class="text-[11px] text-slate-400 mt-1" x-text="selectedSupervisorHasAccount ? 'Dosen ini sudah memiliki akun. Gunakan email akun yang terhubung; email akun lama tidak diubah melalui formulir ini.' : 'Email otomatis terisi saat dosen dipilih dan dapat diedit. Nama dosen digunakan tanpa gelar atau NIDN; email yang sudah dipakai diberi angka pembeda.'"></p>
                        </div>

                        <!-- Initial Password Notice -->
                        <div class="p-3 rounded-xl bg-slate-50 border border-slate-200 text-xs text-slate-600 space-y-1">
                            <p class="font-semibold text-slate-800">Catatan Kredensial Akun:</p>
                            <p class="text-[11px] text-slate-500">
                                Kata sandi bawaan untuk akun baru:
                                <code class="font-mono font-semibold text-slate-800 select-all">{{ $defaultInitialPassword }}</code>.
                                Pemilik akun wajib menggantinya dengan sandi berbeda saat login pertama sebelum dapat mengakses halaman aplikasi. Akun yang sudah ada tetap menggunakan sandinya saat ini.
                            </p>
                        </div>
                    </div>

                    <div class="p-5 bg-slate-50 border-t border-slate-200 flex items-center justify-end gap-3">
                        <button type="button" @click="closeAddModal()" class="px-4 py-2 rounded-xl border border-slate-300 text-xs font-semibold text-slate-700 hover:bg-slate-100 transition-colors">
                            Batal
                        </button>
                        <button type="submit" :disabled="candidatesLoading || !!candidatesError" class="px-5 py-2 rounded-xl bg-[#7B1113] hover:bg-[#600d0e] text-white text-xs font-semibold shadow-xs transition-colors disabled:opacity-50">
                            Tetapkan Supervisor
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ========================================== -->
    <!-- MODAL: HAPUS / NONAKTIFKAN SUPERVISOR     -->
    <!-- ========================================== -->
    <div 
        x-show="deleteModalOpen" 
        x-cloak 
        class="fixed inset-0 z-50 overflow-y-auto" 
        role="dialog" 
        aria-modal="true"
    >
        <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
            <div 
                x-show="deleteModalOpen" 
                @click="closeDeleteModal()" 
                class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm transition-opacity"
            ></div>

            <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>

            <div 
                x-show="deleteModalOpen" 
                class="relative z-10 inline-block align-bottom bg-white rounded-2xl text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-md sm:w-full border border-slate-200"
            >
                <form :action="'{{ url('lpm/supervisors') }}/' + deleteData.id + '?period_id={{ $selectedPeriod?->id }}'" method="POST">
                    @csrf
                    @method('DELETE')
                    <div class="p-6">
                        <div class="w-12 h-12 rounded-full bg-rose-100 text-rose-600 flex items-center justify-center mx-auto mb-4">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                        </div>
                        <h3 class="text-base font-bold text-slate-900 text-center">Hapus Supervisor dari Periode Ini?</h3>
                        <p class="text-xs text-slate-500 text-center mt-2">
                            <strong class="text-slate-800" x-text="deleteData.name"></strong> tidak lagi ditugaskan pada periode ini. Riwayat periode lain tetap tersimpan.
                        </p>
                    </div>

                    <div class="p-5 bg-slate-50 border-t border-slate-200 flex items-center justify-end gap-3">
                        <button type="button" @click="closeDeleteModal()" class="px-4 py-2 rounded-xl border border-slate-300 text-xs font-semibold text-slate-700 hover:bg-slate-100 transition-colors">
                            Batal
                        </button>
                        <button type="submit" class="px-5 py-2 rounded-xl bg-rose-600 hover:bg-rose-700 text-white text-xs font-semibold shadow-xs transition-colors">
                            Ya, Hapus dari Periode
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function supervisorDirectoryApp() {
    return {
        addModalOpen: {{ $errors->any() ? 'true' : 'false' }},
        candidatesLoading: false,
        candidatesError: '',
        candidatesSequence: 0,
        supervisorEmail: @js(old('email', '')),
        selectedSupervisorHasAccount: false,
        deleteModalOpen: false,
        deleteData: {
            id: null,
            name: ''
        },

        get anyModalOpen() {
            return this.addModalOpen || this.deleteModalOpen;
        },

        toggleBodyScroll() {
            if (this.anyModalOpen) {
                document.body.style.overflow = 'hidden';
            } else {
                document.body.style.overflow = '';
            }
        },

        openAddModal() {
            this.supervisorEmail = '';
            this.selectedSupervisorHasAccount = false;
            this.$dispatch('reset-supervisor-lecturer');
            this.addModalOpen = true;
            this.toggleBodyScroll();
            this.loadCandidates();
        },

        async loadCandidates() {
            const sequence = ++this.candidatesSequence;
            this.candidatesLoading = true;
            this.candidatesError = '';
            try {
                const response = await fetch('{{ $selectedPeriod ? route('lpm.supervisors.candidates', $selectedPeriod) : '' }}', {
                    headers: { 'Accept': 'application/json' }
                });
                if (!response.ok) throw new Error('Daftar dosen gagal dimuat. Coba lagi atau muat ulang halaman.');
                const result = await response.json();
                if (sequence === this.candidatesSequence && this.addModalOpen) {
                    this.$dispatch('load-supervisor-candidates', result.data);
                }
            } catch (error) {
                if (sequence === this.candidatesSequence) this.candidatesError = error.message;
            } finally {
                if (sequence === this.candidatesSequence) this.candidatesLoading = false;
            }
        },

        closeAddModal() {
            ++this.candidatesSequence;
            this.candidatesLoading = false;
            this.addModalOpen = false;
            this.toggleBodyScroll();
        },

        openDeleteModal(supervisor) {
            this.deleteData = {
                id: supervisor.id,
                name: supervisor.name
            };
            this.deleteModalOpen = true;
            this.toggleBodyScroll();
        },

        closeDeleteModal() {
            this.deleteModalOpen = false;
            this.toggleBodyScroll();
        }
    };
}
</script>
@endsection
