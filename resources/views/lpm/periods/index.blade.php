@extends('layouts.app', ['title' => 'Periode Supervisi - Lembaga Penjaminan Mutu'])

@section('content')
<div class="space-y-6" x-data="{
    createModalOpen: false,
    editModalOpen: false,
    createPeriod: { name: '', lastAutomaticName: '', academic_year: '{{ $defaultAcademicYear }}', semester: 'GANJIL', stage_1_starts_on: '', stage_1_ends_on: '', stage_2_starts_on: '', stage_2_ends_on: '', status: 'ACTIVE' },
    editPeriod: {
        id: '',
        name: '',
        lastAutomaticName: '',
        academic_year: '',
        semester: 'GANJIL',
        stage_1_starts_on: '',
        stage_1_ends_on: '',
        stage_2_starts_on: '',
        stage_2_ends_on: '',
        status: 'ACTIVE'
    },
    init() {
        this.$watch('createPeriod.academic_year', () => this.updatePeriodName(this.createPeriod));
        this.$watch('createPeriod.semester', () => this.updatePeriodName(this.createPeriod));
        this.$watch('editPeriod.academic_year', () => this.updatePeriodName(this.editPeriod));
        this.$watch('editPeriod.semester', () => this.updatePeriodName(this.editPeriod));
        this.updatePeriodName(this.createPeriod);
    },
    periodNameFor(period) {
        const academicYear = (period.academic_year ?? '').trim();
        const semesterLabel = { GANJIL: 'Ganjil', GENAP: 'Genap' }[period.semester];
        if (!/^[0-9]{4}\/[0-9]{4}$/.test(academicYear) || !semesterLabel) return '';
        return 'Supervisi Semester ' + semesterLabel + ' ' + academicYear;
    },
    updatePeriodName(period) {
        const automaticName = this.periodNameFor(period);
        if (!period.name.trim() || period.name === period.lastAutomaticName) {
            period.name = automaticName;
        }
        period.lastAutomaticName = automaticName;
    },
    openCreate() {
        this.createPeriod = {
            name: '', lastAutomaticName: '', academic_year: '{{ $defaultAcademicYear }}', semester: 'GANJIL',
            stage_1_starts_on: '', stage_1_ends_on: '', stage_2_starts_on: '', stage_2_ends_on: '', status: 'ACTIVE'
        };
        this.updatePeriodName(this.createPeriod);
        this.createModalOpen = true;
    },
    openEdit(period) {
        this.editPeriod = {
            id: period.id,
            name: period.name,
            academic_year: period.academic_year ?? '',
            semester: period.semester ?? 'GANJIL',
            stage_1_starts_on: period.stage_1_starts_on ?? '',
            stage_1_ends_on: period.stage_1_ends_on ?? '',
            stage_2_starts_on: period.stage_2_starts_on ?? '',
            stage_2_ends_on: period.stage_2_ends_on ?? '',
            status: period.status
        };
        this.editPeriod.lastAutomaticName = this.periodNameFor(this.editPeriod);
        this.editModalOpen = true;
    },
    confirmToggle(formId, isActive, periodName) {
        const form = document.getElementById(formId);
        if (!form) return;
        
        window.confirmModal({
            title: isActive ? 'Tutup Periode Supervisi?' : 'Aktifkan Periode Supervisi?',
            html: isActive
                ? window.confirmationContent('Apakah Anda yakin ingin menutup periode ', periodName, '?', 'Periode yang ditutup tidak lagi digunakan untuk penugasan supervisi baru.')
                : window.confirmationContent('Jadikan ', periodName, ' sebagai periode supervisi yang aktif?', 'Perhatian: Hanya satu periode yang dapat aktif pada satu waktu. Periode aktif lainnya akan otomatis dialihkan ke status ditutup.', 'p-2.5 bg-amber-50 rounded-xl border border-amber-200 text-amber-800 text-[11px] leading-relaxed'),
            icon: isActive ? 'warning' : 'question',
            confirmText: isActive ? 'Ya, Tutup Periode' : 'Ya, Aktifkan Sekarang',
            cancelText: 'Batal',
            confirmColor: isActive ? '#E11D48' : '#7B1113',
            onConfirm: () => {
                form.submit();
            }
        });
    },
    confirmDelete(formId, periodName) {
        const form = document.getElementById(formId);
        if (!form) return;
        
        window.confirmModal({
            title: 'Hapus Periode Supervisi?',
            html: window.confirmationContent('Apakah Anda yakin ingin menghapus periode ', periodName, '?', 'Tindakan ini tidak dapat dibatalkan.', 'text-rose-600 font-semibold'),
            icon: 'warning',
            confirmText: 'Ya, Hapus Periode',
            cancelText: 'Batal',
            confirmColor: '#DC2626',
            onConfirm: () => {
                form.submit();
            }
        });
    }
}">

    <!-- ============================================================ -->
    <!-- 1. BREADCRUMB & HEADER                                       -->
    <!-- ============================================================ -->
    <div class="space-y-1">
        <div class="flex items-center gap-1.5 text-xs text-slate-500 font-medium">
            <a href="{{ route('lpm.dashboard') }}" class="hover:text-slate-800 transition-colors flex items-center gap-1">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                <span>Dashboard</span>
            </a>
            <svg class="w-3 h-3 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            <span class="text-slate-700 font-semibold">Periode Supervisi</span>
        </div>
        <h1 class="text-2xl font-bold text-slate-900 tracking-tight">Periode Supervisi</h1>
        <p class="text-xs text-slate-500">
            Kelola periode supervisi. Hanya satu periode yang dapat aktif pada satu waktu.
            Setiap periode mencakup satu semester dengan dua supervisi: Tahap 1 dan Tahap 2.
        </p>
    </div>

    <!-- ============================================================ -->
    <!-- 2. SEARCH & ACTION TOOLBAR                                   -->
    <!-- ============================================================ -->
    @if($errors->any())
        <div role="alert" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-xs text-rose-800">
            <p class="font-bold">Periode belum tersimpan. Periksa isian berikut:</p>
            <ul class="mt-1 list-disc pl-5">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif
    <div class="filter-bar flex flex-col gap-2 lg:flex-row lg:items-center lg:justify-between">
        <form method="GET" action="{{ route('lpm.periods.index') }}" class="filter-controls min-w-0 flex-1" style="--filter-columns: minmax(0,2fr) minmax(0,1fr) auto">
            <!-- Search Box -->
            <div class="filter-search">
                <svg class="filter-search-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                <input 
                    type="text" 
                    name="search" aria-label="Cari periode" 
                    value="{{ request('search') }}" 
                    placeholder="Cari nama periode..." 
                    class="filter-control"
                >
            </div>

            <!-- Status Filter -->
            <select 
                name="status" aria-label="Status periode" 
                onchange="this.form.submit()" 
                class="filter-control"
            >
                <option value="">Semua Status</option>
                <option value="ACTIVE" {{ request('status') === 'ACTIVE' ? 'selected' : '' }}>Aktif</option>
                <option value="CLOSED" {{ request('status') === 'CLOSED' ? 'selected' : '' }}>Ditutup</option>
            </select>

            <div class="filter-actions">
                <button type="submit" class="filter-button">Filter</button>
                @if(request('search') || request('status'))
                    <a href="{{ route('lpm.periods.index') }}" class="filter-reset" title="Reset Filter" aria-label="Reset Filter">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </a>
                @endif
            </div>
        </form>

        <!-- Tambah Periode Button -->
        <button 
            type="button" 
            @click="openCreate()" 
            class="filter-button self-start lg:self-auto"
        >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            <span>Tambah Periode</span>
        </button>
    </div>

    <!-- ============================================================ -->
    <!-- 3. DATA TABLE                                                -->
    <!-- ============================================================ -->
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead class="bg-slate-50 text-slate-600 font-semibold uppercase text-[11px] tracking-wider border-b border-slate-200">
                    <tr>
                        <th class="px-5 py-3.5">Periode Supervisi</th>
                        <th class="px-5 py-3.5">Tahap 1</th>
                        <th class="px-5 py-3.5">Tahap 2</th>
                        <th class="px-5 py-3.5 text-center">Penugasan</th>
                        <th class="px-5 py-3.5 text-center">Status</th>
                        <th class="px-5 py-3.5 text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($periods as $period)
                        <tr class="hover:bg-slate-50/70 transition-colors">
                            <!-- Semester Supervisi -->
                            <td class="px-5 py-4">
                                <span class="font-bold text-slate-900 text-xs sm:text-[13px] block">
                                    {{ $period->name }}
                                </span>
                                @if($period->academicTerm)
                                    <span class="text-[11px] text-slate-400 font-medium mt-0.5 block">
                                        Tahun Ajaran {{ $period->academicTerm->academic_year }} · {{ $period->academicTerm->semester_label }} · {{ $period->starts_on?->format('d M Y') }} – {{ $period->ends_on?->format('d M Y') }}
                                    </span>
                                @endif
                            </td>

                            <!-- Tahap 1 -->
                            <td class="px-5 py-4 text-slate-700 font-medium whitespace-nowrap">
                                @if($stageOne = $period->stages->firstWhere('stage_no', 1))
                                    {{ $stageOne->starts_on->format('d M Y') }} – {{ $stageOne->ends_on->format('d M Y') }}
                                @else
                                    Belum dijadwalkan
                                @endif
                            </td>

                            <!-- Tahap 2 -->
                            <td class="px-5 py-4 text-slate-700 font-medium whitespace-nowrap">
                                @if($stageTwo = $period->stages->firstWhere('stage_no', 2))
                                    {{ $stageTwo->starts_on->format('d M Y') }} – {{ $stageTwo->ends_on->format('d M Y') }}
                                @else
                                    Belum dijadwalkan
                                @endif
                            </td>


                            <!-- Penugasan -->
                            <td class="px-5 py-4 text-center font-bold text-slate-800">
                                {{ $period->assignments_count ?: ($period->targets_count ?: 0) }}
                            </td>

                            <!-- Status -->
                            <td class="px-5 py-4 text-center">
                                @if($period->is_active)
                                    <span class="inline-flex items-center px-3 py-0.5 rounded-full text-xs font-semibold bg-emerald-600 text-white shadow-2xs">
                                        Aktif
                                    </span>
                                @else
                                    <span class="inline-flex items-center px-3 py-0.5 rounded-full text-xs font-medium bg-slate-100 text-slate-600 border border-slate-200">
                                        Ditutup
                                    </span>
                                @endif
                            </td>

                            <!-- Aksi -->
                            <td class="px-5 py-4 text-center">
                                <div class="flex items-center justify-center gap-1.5">
                                    <!-- Toggle Status Button -->
                                    <form id="toggle-form-{{ $period->id }}" action="{{ route('lpm.periods.toggle_status', $period) }}" method="POST">
                                        @csrf
                                        @if($period->is_active)
                                            <button 
                                                type="button" 
                                                data-period-name="{{ $period->name }}"
                                                @click="confirmToggle('toggle-form-{{ $period->id }}', true, $el.dataset.periodName)"
                                                class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-rose-300 text-rose-700 transition-colors hover:bg-rose-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rose-500"
                                                aria-label="Tutup periode {{ $period->name }}"
                                                title="Tutup periode {{ $period->name }}"
                                            >
                                                <svg aria-hidden="true" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 10a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1v-4z"/></svg>
                                            </button>
                                        @else
                                            <button 
                                                type="button" 
                                                data-period-name="{{ $period->name }}"
                                                @click="confirmToggle('toggle-form-{{ $period->id }}', false, $el.dataset.periodName)"
                                                class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-emerald-300 text-emerald-700 transition-colors hover:bg-emerald-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500"
                                                aria-label="Aktifkan periode {{ $period->name }}"
                                                title="Aktifkan periode {{ $period->name }}"
                                            >
                                                <svg aria-hidden="true" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                            </button>
                                        @endif
                                    </form>

                                    <!-- Edit Button -->
                                    <button 
                                        type="button" 
                                        data-period="{{ json_encode([
                                            'id' => $period->id,
                                            'name' => $period->name,
                                            'academic_year' => $period->academicTerm?->academic_year,
                                            'semester' => $period->academicTerm?->semester,
                                            'stage_1_starts_on' => $period->stages->firstWhere('stage_no', 1)?->starts_on?->format('Y-m-d'),
                                            'stage_1_ends_on' => $period->stages->firstWhere('stage_no', 1)?->ends_on?->format('Y-m-d'),
                                            'stage_2_starts_on' => $period->stages->firstWhere('stage_no', 2)?->starts_on?->format('Y-m-d'),
                                            'stage_2_ends_on' => $period->stages->firstWhere('stage_no', 2)?->ends_on?->format('Y-m-d'),
                                            'status' => $period->status,
                                        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) }}"
                                        @click="openEdit(JSON.parse($el.dataset.period))" 
                                        class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-slate-300 text-slate-700 transition-colors hover:border-slate-400 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-500"
                                        aria-label="Edit periode {{ $period->name }}"
                                        title="Edit periode {{ $period->name }}"
                                    >
                                        <svg aria-hidden="true" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                                    </button>

                                    <!-- Delete Button (Only for Closed periods) -->
                                    @if(!$period->is_active)
                                        <form id="delete-form-{{ $period->id }}" action="{{ route('lpm.periods.destroy', $period) }}" method="POST">
                                            @csrf
                                            @method('DELETE')
                                            <button 
                                                type="button" 
                                                data-period-name="{{ $period->name }}"
                                                @click="confirmDelete('delete-form-{{ $period->id }}', $el.dataset.periodName)"
                                                class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-rose-200 text-rose-700 transition-colors hover:bg-rose-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rose-500"
                                                aria-label="Hapus periode {{ $period->name }}"
                                                title="Hapus periode {{ $period->name }}"
                                            >
                                                <svg aria-hidden="true" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                            </button>
                                        </form>
                                    @endif

                                    <!-- Detail Link -->
                                    <a 
                                        href="{{ route('lpm.periods.show', $period) }}" 
                                        class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-slate-200 text-[#7B1113] transition-colors hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#7B1113]"
                                        aria-label="Lihat detail periode {{ $period->name }}"
                                        title="Lihat detail periode {{ $period->name }}"
                                    >
                                        <svg aria-hidden="true" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.25 12s3.5-6.75 9.75-6.75S21.75 12 21.75 12s-3.5 6.75-9.75 6.75S2.25 12 2.25 12z" /><circle cx="12" cy="12" r="2.5" stroke-width="2" /></svg>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-5 py-8 text-center text-slate-400">
                                Belum ada data periode supervisi. Klik <strong>+ Tambah Periode</strong> untuk membuat baru.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Table Footer / Pagination -->
        <div class="px-5 py-3 border-t border-slate-200 flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-xs text-slate-500">
            <div>
                Menampilkan <strong>{{ $periods->firstItem() ?? 0 }} sampai {{ $periods->lastItem() ?? 0 }}</strong> dari <strong>{{ $periods->total() }}</strong> periode supervisi
            </div>

            <div class="flex items-center gap-3">
                <form method="GET" action="{{ route('lpm.periods.index') }}" class="flex items-center gap-1.5">
                    @if(request('search'))
                        <input type="hidden" name="search" value="{{ request('search') }}">
                    @endif
                    @if(request('status'))
                        <input type="hidden" name="status" value="{{ request('status') }}">
                    @endif
                    <span>Baris</span>
                    <select name="per_page" onchange="this.form.submit()" class="px-2 py-1 rounded-lg border border-slate-300 text-xs bg-white cursor-pointer">
                        <option value="5" {{ $perPage == 5 ? 'selected' : '' }}>5</option>
                        <option value="10" {{ $perPage == 10 ? 'selected' : '' }}>10</option>
                        <option value="25" {{ $perPage == 25 ? 'selected' : '' }}>25</option>
                        <option value="50" {{ $perPage == 50 ? 'selected' : '' }}>50</option>
                    </select>
                </form>

                @if($periods->hasPages())
                    <div>
                        {{ $periods->links('vendor.pagination.tailwind', ['showSummary' => false]) }}
                    </div>
                @endif
            </div>
        </div>
    </div>

    <!-- Institutional Footer Note -->
    <div class="text-center text-xs text-slate-400 pt-2">
        LPM &copy; 2026 — Lembaga Penjaminan Mutu Universitas Teknokrat Indonesia
    </div>

    <!-- Modal periode dipindahkan ke body agar backdrop menutup seluruh viewport. -->
    <template x-teleport="body">
        <div x-show="createModalOpen" x-cloak @keydown.escape.window="createModalOpen = false" class="fixed inset-0 z-[1000] flex items-center justify-center p-4 bg-black/50 backdrop-blur-xs">
            <div role="dialog" aria-modal="true" aria-labelledby="create-period-title" @click.outside="createModalOpen = false" class="bg-white rounded-xl shadow-xl border border-slate-200 w-full max-w-lg p-6 max-h-[90vh] overflow-y-auto">
                <div class="flex items-center justify-between pb-3 border-b border-slate-200 mb-4">
                    <div>
                        <h3 id="create-period-title" class="text-base font-bold text-slate-900">Tambah Periode Supervisi</h3>
                        <p class="text-xs text-slate-500">Tanggal setiap tahap menentukan kapan supervisor bisa mulai mengisi formulir dan kapan input ditutup.</p>
                    </div>
                    <button type="button" @click="createModalOpen = false" class="text-slate-500 hover:text-slate-700 cursor-pointer" aria-label="Tutup dialog tambah periode">&times;</button>
                </div>
                <form action="{{ route('lpm.periods.store') }}" method="POST" class="space-y-4">
                    @csrf
                    <div>
                        <label for="create-period-year" class="block text-xs font-semibold text-slate-700 mb-1">Tahun Ajaran <span class="text-rose-600">*</span></label>
                        <input id="create-period-year" type="text" name="academic_year" x-model="createPeriod.academic_year" required pattern="[0-9]{4}/[0-9]{4}" placeholder="Contoh: 2026/2027" class="w-full px-3 py-2.5 text-xs border border-slate-300 rounded-lg focus:ring-2 focus:ring-[#7B1113]">
                    </div>
                    <div>
                        <label for="create-period-semester" class="block text-xs font-semibold text-slate-700 mb-1">Semester <span class="text-rose-600">*</span></label>
                        <select id="create-period-semester" name="semester" x-model="createPeriod.semester" required class="w-full px-3 py-2.5 text-xs border border-slate-300 rounded-lg bg-white focus:ring-2 focus:ring-[#7B1113]">
                            <option value="GANJIL">Semester Ganjil</option><option value="GENAP">Semester Genap</option>
                        </select>
                    </div>
                    <div>
                        <label for="create-period-name" class="block text-xs font-semibold text-slate-700 mb-1">Nama Periode <span class="text-rose-600">*</span></label>
                        <input id="create-period-name" type="text" name="name" x-model="createPeriod.name" required maxlength="255" placeholder="Terisi otomatis dari tahun ajaran dan semester" aria-describedby="create-period-name-help" class="w-full px-3 py-2.5 text-xs border border-slate-300 rounded-lg focus:ring-2 focus:ring-[#7B1113]">
                        <p id="create-period-name-help" class="mt-1 text-[10px] text-slate-500">Nama otomatis dapat diedit sesuai kebutuhan.</p>
                    </div>
                    @foreach([1, 2] as $stageNumber)
                        <fieldset class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                            <legend class="px-1 text-xs font-bold text-[#7B1113]">Tahap {{ $stageNumber }}</legend>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <label class="block text-xs font-semibold text-slate-700">Mulai Input <span class="text-rose-600">*</span>
                                    <input type="date" name="stage_{{ $stageNumber }}_starts_on" x-model="createPeriod.stage_{{ $stageNumber }}_starts_on" required class="mt-1 w-full px-3 py-2.5 text-xs border border-slate-300 rounded-lg bg-white">
                                </label>
                                <label class="block text-xs font-semibold text-slate-700">Batas Akhir Input <span class="text-rose-600">*</span>
                                    <input type="date" name="stage_{{ $stageNumber }}_ends_on" x-model="createPeriod.stage_{{ $stageNumber }}_ends_on" :min="createPeriod.stage_{{ $stageNumber }}_starts_on" required class="mt-1 w-full px-3 py-2.5 text-xs border border-slate-300 rounded-lg bg-white">
                                </label>
                            </div>
                            <p class="mt-2 text-[10px] text-slate-500">Supervisor bisa mengisi sejak tanggal mulai sampai batas akhir secara inklusif.</p>
                            @if($stageNumber === 2)
                                <p class="mt-1 text-[10px] text-slate-500">Tahap 2 dimulai setelah Tahap 1 selesai.</p>
                            @endif
                        </fieldset>
                    @endforeach
                    <div>
                        <label for="create-period-status" class="block text-xs font-semibold text-slate-700 mb-1">Status Periode <span class="text-rose-600">*</span></label>
                        <select id="create-period-status" name="status" x-model="createPeriod.status" required class="w-full px-3 py-2.5 text-xs border border-slate-300 rounded-lg bg-white">
                            <option value="ACTIVE">Aktif (Jadikan Periode Utama)</option><option value="CLOSED">Ditutup</option>
                        </select>
                    </div>
                    <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-200">
                        <button type="button" @click="createModalOpen = false" class="px-4 py-2 rounded-lg border border-slate-300 text-xs font-semibold">Batal</button>
                        <button type="submit" class="px-4 py-2 rounded-lg bg-[#7B1113] text-white text-xs font-semibold">Simpan</button>
                    </div>
                </form>
            </div>
        </div>
    </template>

    <template x-teleport="body">
        <div x-show="editModalOpen" x-cloak @keydown.escape.window="editModalOpen = false" class="fixed inset-0 z-[1000] flex items-center justify-center p-4 bg-black/50 backdrop-blur-xs">
            <div role="dialog" aria-modal="true" aria-labelledby="edit-period-title" @click.outside="editModalOpen = false" class="bg-white rounded-xl shadow-xl border border-slate-200 w-full max-w-lg p-6 max-h-[90vh] overflow-y-auto">
                <div class="flex items-center justify-between pb-3 border-b border-slate-200 mb-4">
                    <div><h3 id="edit-period-title" class="text-base font-bold text-slate-900">Edit Periode Supervisi</h3><p class="text-xs text-slate-500">Tanggal setiap tahap menentukan kapan supervisor bisa mulai mengisi formulir dan kapan input ditutup.</p></div>
                    <button type="button" @click="editModalOpen = false" class="text-slate-500 hover:text-slate-700 cursor-pointer" aria-label="Tutup dialog edit periode">&times;</button>
                </div>
                <form :action="'{{ url('lpm/periods') }}/' + editPeriod.id" method="POST" class="space-y-4">
                    @csrf
                    @method('PUT')
                    <div><label for="edit-period-year" class="block text-xs font-semibold text-slate-700 mb-1">Tahun Ajaran <span class="text-rose-600">*</span></label>
                        <input id="edit-period-year" type="text" name="academic_year" x-model="editPeriod.academic_year" required pattern="[0-9]{4}/[0-9]{4}" placeholder="Contoh: 2026/2027" class="w-full px-3 py-2.5 text-xs border border-slate-300 rounded-lg"></div>
                    <div><label for="edit-period-semester" class="block text-xs font-semibold text-slate-700 mb-1">Semester <span class="text-rose-600">*</span></label>
                        <select id="edit-period-semester" name="semester" x-model="editPeriod.semester" required class="w-full px-3 py-2.5 text-xs border border-slate-300 rounded-lg bg-white"><option value="GANJIL">Semester Ganjil</option><option value="GENAP">Semester Genap</option></select></div>
                    <div><label for="edit-period-name" class="block text-xs font-semibold text-slate-700 mb-1">Nama Periode <span class="text-rose-600">*</span></label>
                        <input id="edit-period-name" type="text" name="name" x-model="editPeriod.name" required maxlength="255" aria-describedby="edit-period-name-help" class="w-full px-3 py-2.5 text-xs border border-slate-300 rounded-lg">
                        <p id="edit-period-name-help" class="mt-1 text-[10px] text-slate-500">Nama otomatis mengikuti tahun dan semester. Nama yang diedit sendiri tetap dipertahankan.</p>
                    </div>
                    @foreach([1, 2] as $stageNumber)
                        <fieldset class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                            <legend class="px-1 text-xs font-bold text-[#7B1113]">Tahap {{ $stageNumber }}</legend>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <label class="block text-xs font-semibold text-slate-700">Mulai Input <span class="text-rose-600">*</span>
                                    <input type="date" name="stage_{{ $stageNumber }}_starts_on" x-model="editPeriod.stage_{{ $stageNumber }}_starts_on" required class="mt-1 w-full px-3 py-2.5 text-xs border border-slate-300 rounded-lg bg-white">
                                </label>
                                <label class="block text-xs font-semibold text-slate-700">Batas Akhir Input <span class="text-rose-600">*</span>
                                    <input type="date" name="stage_{{ $stageNumber }}_ends_on" x-model="editPeriod.stage_{{ $stageNumber }}_ends_on" :min="editPeriod.stage_{{ $stageNumber }}_starts_on" required class="mt-1 w-full px-3 py-2.5 text-xs border border-slate-300 rounded-lg bg-white">
                                </label>
                            </div>
                            <p class="mt-2 text-[10px] text-slate-500">Supervisor bisa mengisi sejak tanggal mulai sampai batas akhir secara inklusif.</p>
                            @if($stageNumber === 2)
                                <p class="mt-1 text-[10px] text-slate-500">Tahap 2 dimulai setelah Tahap 1 selesai.</p>
                            @endif
                        </fieldset>
                    @endforeach
                    <div><label for="edit-period-status" class="block text-xs font-semibold text-slate-700 mb-1">Status Periode <span class="text-rose-600">*</span></label>
                        <select id="edit-period-status" name="status" x-model="editPeriod.status" required class="w-full px-3 py-2.5 text-xs border border-slate-300 rounded-lg bg-white"><option value="ACTIVE">Aktif (Jadikan Periode Utama)</option><option value="CLOSED">Ditutup</option></select></div>
                    <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-200">
                        <button type="button" @click="editModalOpen = false" class="px-4 py-2 rounded-lg border border-slate-300 text-xs font-semibold">Batal</button>
                        <button type="submit" class="px-4 py-2 rounded-lg bg-[#7B1113] text-white text-xs font-semibold">Perbarui</button>
                    </div>
                </form>
            </div>
        </div>
    </template>

</div>
@endsection
