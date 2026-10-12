@extends('layouts.app', ['title' => $lecturer->name . ' — Detail Dosen'])

@section('content')
@php
    $submittedT1Supervision = $t1Assignments->first(fn ($assignment) => $assignment->supervision?->status === 'SUBMITTED')?->supervision;
    $submittedT2Supervision = $t2Assignments->first(fn ($assignment) => $assignment->supervision?->status === 'SUBMITTED')?->supervision;
@endphp
<div class="space-y-6">
    @if($periods->isNotEmpty())
        <form method="GET" action="{{ route('lpm.lecturers.show', $lecturer) }}" class="filter-bar flex flex-col gap-2 lg:flex-row lg:items-center lg:gap-3">
            <label for="lecturer-period" class="filter-field shrink-0">Semester Supervisi</label>
            <select id="lecturer-period" name="period_id" onchange="this.form.submit()" class="filter-control lg:max-w-sm">
                @foreach($periods as $availablePeriod)
                    <option value="{{ $availablePeriod->id }}" @selected($selectedPeriod?->id === $availablePeriod->id)>{{ $availablePeriod->academicTerm?->semester_label ?? 'Semester Supervisi' }} {{ $availablePeriod->academicTerm?->academic_year ?? '-' }}</option>
                @endforeach
            </select>
            <p class="text-xs text-slate-500">Hasil Tahap 1 dan Tahap 2 ditampilkan untuk semester pada periode yang dipilih.</p>
        </form>
    @endif

    <!-- Back Link + Header -->
    <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-4">
        <div class="flex items-start gap-4">
            <a href="{{ route('lpm.lecturers.index', ['period_id' => $selectedPeriod?->id]) }}" class="mt-1 p-2 rounded-xl border border-slate-200 hover:bg-slate-100 text-slate-500 hover:text-[#7B1113] transition-colors shrink-0" title="Kembali ke Direktori">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
            </a>
            <div>
                <h1 class="text-2xl font-bold text-slate-900">{{ $lecturer->name }}</h1>
                <div class="flex flex-wrap items-center gap-2 mt-1.5">
                    @if($lecturer->nidn)
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-mono font-bold bg-slate-100 text-slate-600 border border-slate-200">NIDN {{ $lecturer->nidn }}</span>
                    @endif
                    @if($lecturer->faculty)
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-blue-50 text-blue-800 border border-blue-200">
                            {{ $lecturer->faculty->code }}
                        </span>
                    @endif
                    @if($lecturer->studyProgram)
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-medium bg-slate-100 text-slate-700 border border-slate-200">
                            {{ $lecturer->studyProgram->name }}
                        </span>
                    @endif
                    @if($lecturer->is_active)
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-100 text-emerald-800">Aktif</span>
                    @else
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-slate-100 text-slate-500">Non-Aktif</span>
                    @endif
                    @if(!empty($isSupervisor) && $isSupervisor)
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 text-amber-900 border border-amber-300">Supervisor</span>
                    @endif
                </div>
            </div>
        </div>

        <!-- Score Summary Card -->
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 sm:min-w-[230px] text-center">
            <p class="text-[10px] font-bold uppercase text-slate-400 tracking-wider">Rata-rata Skor</p>
            @if($avgScore !== null)
                <p class="text-3xl font-black mt-1 {{ $avgScore >= 3.5 ? 'text-emerald-600' : ($avgScore >= 2.5 ? 'text-amber-600' : 'text-rose-600') }}">
                    {{ number_format($avgScore, 2) }}
                </p>
                <p class="text-[10px] text-slate-400 mt-0.5">dari seluruh supervisi</p>
            @else
                <p class="text-3xl font-black mt-1 text-slate-300">—</p>
                <p class="text-[10px] text-slate-400 mt-0.5">Belum ada skor</p>
            @endif

            @if($submittedT1Supervision || $submittedT2Supervision)
                <div class="mt-3 flex flex-wrap justify-center gap-2 border-t border-slate-100 pt-3">
                    @if($submittedT1Supervision)
                        <a href="{{ route('lpm.supervisions.pdf', $submittedT1Supervision) }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 rounded-lg bg-[#7B1113] px-3 py-1.5 text-[11px] font-bold text-white transition-colors hover:bg-[#5A0E13]">
                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                            Cetak PDF Nilai T1
                        </a>
                    @endif
                    @if($submittedT2Supervision)
                        <a href="{{ route('lpm.supervisions.pdf', $submittedT2Supervision) }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 rounded-lg bg-[#7B1113] px-3 py-1.5 text-[11px] font-bold text-white transition-colors hover:bg-[#5A0E13]">
                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                            Cetak PDF Nilai T2
                        </a>
                    @endif
                </div>
            @endif
        </div>
    </div>

    <!-- Tabbed Content Area -->
    <div x-data="{ activeTab: 'schedules' }">

        <!-- Tab Navigation -->
        <div class="border-b border-slate-200 mb-6">
            <nav class="flex space-x-1 -mb-px overflow-x-auto" aria-label="Tabs">
                <button @click="activeTab = 'schedules'" :class="activeTab === 'schedules' ? 'border-[#7B1113] text-[#7B1113] bg-rose-50/50' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300'" class="whitespace-nowrap py-3 px-4 border-b-2 font-bold text-xs transition-colors rounded-t-lg cursor-pointer">
                    Jadwal Mengajar
                </button>
                <button @click="activeTab = 't1'" :class="activeTab === 't1' ? 'border-[#7B1113] text-[#7B1113] bg-rose-50/50' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300'" class="whitespace-nowrap py-3 px-4 border-b-2 font-bold text-xs transition-colors rounded-t-lg cursor-pointer">
                    Tahap 1
                </button>
                <button @click="activeTab = 't2'" :class="activeTab === 't2' ? 'border-[#7B1113] text-[#7B1113] bg-rose-50/50' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300'" class="whitespace-nowrap py-3 px-4 border-b-2 font-bold text-xs transition-colors rounded-t-lg cursor-pointer">
                    Tahap 2
                </button>
                <button @click="activeTab = 'compare'" :class="activeTab === 'compare' ? 'border-[#7B1113] text-[#7B1113] bg-rose-50/50' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300'" class="whitespace-nowrap py-3 px-4 border-b-2 font-bold text-xs transition-colors rounded-t-lg cursor-pointer">
                    Perbandingan Tahap 1 vs 2
                </button>
                <button @click="activeTab = 'findings'" :class="activeTab === 'findings' ? 'border-[#7B1113] text-[#7B1113] bg-rose-50/50' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300'" class="whitespace-nowrap py-3 px-4 border-b-2 font-bold text-xs transition-colors rounded-t-lg cursor-pointer">
                    Temuan Umum
                </button>
            </nav>
        </div>

        <!-- ═══════════════════════════════════════ -->
        <!-- TAB 1: Jadwal Mengajar (Matrix Grid & List) -->
        <!-- ═══════════════════════════════════════ -->
        <div x-show="activeTab === 'schedules'" x-cloak x-data="{ scheduleView: 'grid' }">
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden p-6 space-y-5">
                
                <!-- Schedule Controls & Header -->
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-slate-100">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="font-bold text-slate-900 text-base">Jadwal Perkuliahan Dosen</h2>
                            <span class="text-xs font-bold bg-[#7B1113]/10 text-[#7B1113] px-2.5 py-0.5 rounded-full">
                                {{ $schedules->count() }} Jadwal Mengajar
                            </span>
                            @if(!empty($supervisionSchedules) && $supervisionSchedules->isNotEmpty())
                                <span class="text-xs font-bold bg-amber-100 text-amber-900 border border-amber-300 px-2.5 py-0.5 rounded-full flex items-center gap-1.5 shadow-2xs">
                                    <svg class="w-3.5 h-3.5 text-amber-700" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"></path></svg>
                                    {{ $supervisionSchedules->count() }} Jadwal Supervisi
                                </span>
                            @endif
                            @if(!$selectedPeriod)
                                <span class="text-[10px] font-bold bg-slate-100 text-slate-600 px-2 py-0.5 rounded">
                                    Status: Belum Ada Periode Aktif
                                </span>
                            @elseif(!$schedulePeriodIsActive)
                                <span class="text-[10px] font-bold bg-slate-100 text-slate-600 px-2 py-0.5 rounded">
                                    Status: Periode Nonaktif
                                </span>
                            @elseif(!empty($isStagingSchedule) && $isStagingSchedule)
                                <span class="text-[10px] font-bold bg-amber-100 text-amber-800 px-2 py-0.5 rounded">
                                    Status: Pementasan (Staging)
                                </span>
                            @else
                                <span class="text-[10px] font-bold bg-emerald-100 text-emerald-800 px-2 py-0.5 rounded">
                                    Status: Periode Aktif
                                </span>
                            @endif
                        </div>
                        <p class="text-xs text-slate-500 mt-1">
                            @if($schedulePeriodIsActive)
                                Jadwal perkuliahan tersedia selama periode supervisi aktif. Tanggal Tahap 1 dan 2 menentukan rentang input formulir supervisi.
                            @elseif($selectedPeriod)
                                Periode supervisi tidak aktif. Jadwal perkuliahan periode ini tidak ditampilkan atau digunakan untuk penugasan baru.
                            @else
                                Belum ada periode supervisi aktif, sehingga jadwal perkuliahan tidak tersedia.
                            @endif
                        </p>
                    </div>

                    <!-- Schedule Actions & View Switcher -->
                    <div class="flex flex-wrap items-center justify-end gap-2">
                        @if($schedulePeriodIsActive && $selectedPeriod)
                            <a href="{{ route('lpm.lecturers.schedules.create', ['lecturer' => $lecturer, 'period' => $selectedPeriod]) }}" class="inline-flex items-center gap-1.5 rounded-xl bg-[#7B1113] px-3 py-2 text-xs font-bold text-white shadow-sm transition-colors hover:bg-[#5A0E13]">
                                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                                Tambah Jadwal
                            </a>
                        @endif
                        <div class="flex items-center bg-slate-100 p-1 rounded-xl shrink-0">
                        <button type="button" @click="scheduleView = 'grid'" :class="scheduleView === 'grid' ? 'bg-white text-[#7B1113] shadow-sm font-bold' : 'text-slate-600 hover:text-slate-900 font-medium'" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs transition-all cursor-pointer">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path></svg>
                            Kisi Matriks (Resmi)
                        </button>
                        <button type="button" @click="scheduleView = 'list'" :class="scheduleView === 'list' ? 'bg-white text-[#7B1113] shadow-sm font-bold' : 'text-slate-600 hover:text-slate-900 font-medium'" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs transition-all cursor-pointer">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path></svg>
                            Daftar Rincian
                        </button>
                        </div>
                    </div>
                </div>

                <!-- ═══════════════════════════════════════════════════════════ -->
                <!-- 1. OFFICIAL TIMETABLE MATRIX GRID (FET Weekly Grid)       -->
                <!-- ═══════════════════════════════════════════════════════════ -->
                <div x-show="scheduleView === 'grid'">
                    <!-- Document Header Card -->
                    <div class="bg-slate-50 border border-slate-200 rounded-xl p-4 mb-4 text-center">
                        <h3 class="text-xs uppercase font-extrabold tracking-wider text-slate-800">
                            {{ !empty($supervisionSchedules) && $supervisionSchedules->isNotEmpty() ? 'JADWAL MENGAJAR & SUPERVISI MUTU PERKULIAHAN' : 'JADWAL MENGAJAR DOSEN' }}
                        </h3>
                        <p class="text-xs font-bold text-[#7B1113] uppercase tracking-wide mt-0.5">
                            UNIVERSITAS TEKNOKRAT INDONESIA
                        </p>
                        <p class="text-[11px] text-slate-500 font-semibold mt-0.5">
                            {{ strtoupper($selectedPeriod?->academicTerm?->name ?? 'Jadwal Perkuliahan') }}
                        </p>
                        <div class="mt-2 inline-flex items-center gap-1.5 px-3 py-1 rounded-lg bg-white border border-slate-200 text-xs text-slate-800 font-bold shadow-2xs">
                            <span class="text-slate-500 font-normal">Nama Dosen :</span> {{ $lecturer->name }}
                            @if(!empty($isSupervisor) && $isSupervisor)
                                <span class="ml-1 px-1.5 py-0.2 rounded text-[10px] font-bold bg-amber-100 text-amber-900 border border-amber-300">Supervisor</span>
                            @endif
                        </div>
                    </div>

                    <!-- Petunjuk Warna Slot (Legend) -->
                    <div class="flex flex-wrap items-center justify-between gap-3 p-3 rounded-xl bg-slate-50 border border-slate-200 text-xs mb-3">
                        <div class="flex flex-wrap items-center gap-4">
                            <span class="font-bold text-slate-700 flex items-center gap-1.5">
                                <svg class="w-4 h-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                Petunjuk Warna:
                            </span>
                            <div class="flex items-center gap-2">
                                <span class="w-3.5 h-3.5 rounded bg-rose-100 border border-rose-300"></span>
                                <span class="text-slate-600 font-medium">Jadwal Mengajar Mandiri</span>
                            </div>
                            @if(!empty($isSupervisor) && $isSupervisor)
                                <div class="flex items-center gap-2">
                                    <span class="w-3.5 h-3.5 rounded bg-amber-200 border border-amber-400"></span>
                                    <span class="text-amber-900 font-bold">Jadwal Tugas Supervisi Mutu Perkuliahan (Dosen Lain)</span>
                                </div>
                            @endif
                        </div>
                        @if(!empty($supervisionSchedules) && $supervisionSchedules->isNotEmpty())
                            <span class="text-[11px] text-amber-900 font-semibold bg-amber-100 px-2.5 py-0.5 rounded-full border border-amber-300">
                                Terplot {{ $supervisionSchedules->count() }} Tugas Supervisi Aktif
                            </span>
                        @endif
                    </div>

                    <!-- Matrix Grid Table -->
                    <div class="overflow-x-auto border border-slate-300 rounded-xl shadow-xs">
                        <table class="w-full border-collapse text-xs">
                            <thead>
                                <tr class="bg-slate-100 text-slate-800 font-bold text-center border-b border-slate-300">
                                    <th class="py-2.5 px-3 border-r border-slate-300 w-24 text-[11px] uppercase tracking-wider bg-slate-200/70">
                                        Waktu
                                    </th>
                                    @foreach($days as $dNum => $dName)
                                        <th class="py-2.5 px-3 border-r border-slate-300 last:border-r-0 text-[11px] uppercase tracking-wider w-[15%]">
                                            {{ $dName }}
                                        </th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-300">
                                @forelse($timeSlots as $timeSlot)
                                    <tr class="hover:bg-slate-50/30 transition-colors">
                                        <!-- Time Column -->
                                        <td class="py-3 px-3 font-mono font-bold text-center text-slate-700 bg-slate-100/60 border-r border-slate-300 align-top text-xs">
                                            {{ $timeSlot }}
                                        </td>
                                        
                                        <!-- Day Columns (SENIN - SABTU) -->
                                        @foreach($days as $dNum => $dName)
                                            @php
                                                $cell = $scheduleGrid[$timeSlot][$dNum] ?? null;
                                                $cellTeachings = is_array($cell) ? ($cell['teachings'] ?? collect()) : ($cell ? collect([$cell]) : collect());
                                                $cellSupervisions = is_array($cell) ? ($cell['supervisions'] ?? collect()) : collect();
                                                $hasSupervision = $cellSupervisions->isNotEmpty();
                                                $hasTeaching = $cellTeachings->isNotEmpty();
                                            @endphp
                                            <td class="p-1.5 border-r border-slate-300 last:border-r-0 align-top min-h-[70px]">
                                                {{-- 1. Teaching Card (Pink Cell) --}}
                                                @foreach($cellTeachings as $sch)
                                                    <div class="bg-rose-100 rounded-md p-2 mb-1 last:mb-0">
                                                        <div class="font-black text-slate-900 text-xs leading-tight">
                                                            {{ $sch->class_name ?? 'Kelas' }}
                                                        </div>
                                                        <div class="font-semibold text-slate-700 text-[11px] leading-snug mt-0.5">
                                                            {{ trim(str_ireplace(['OFFLINE', 'ONLINE', 'HYBRID'], '', $sch->course?->name ?? $sch->course_name ?? '-')) }}
                                                        </div>
                                                        <div class="text-[10px] text-slate-600 mt-1 flex items-center justify-between">
                                                            <span class="font-mono font-bold">{{ $sch->room_display ?? $sch->room_name ?? '-' }}</span>
                                                            <span class="font-bold uppercase">{{ $sch->delivery_mode ?? 'OFFLINE' }}</span>
                                                        </div>
                                                    </div>
                                                @endforeach

                                                {{-- 2. Supervision Card (Yellow Cell) --}}
                                                @foreach($cellSupervisions as $sup)
                                                    <div class="bg-yellow-200 rounded-md p-2 mb-1 last:mb-0">
                                                        <div>
                                                            <span class="font-black text-slate-900 text-xs leading-tight">{{ $sup->class_name }}</span>
                                                        </div>
                                                        <div class="font-semibold text-slate-700 text-[11px] leading-snug mt-0.5">
                                                            {{ trim(str_ireplace(['OFFLINE', 'ONLINE', 'HYBRID'], '', $sup->course_name)) }}
                                                        </div>
                                                        <div class="text-[10px] text-slate-700 mt-1">
                                                            <span class="font-medium">Dosen yang Disupervisi:</span>
                                                            <div class="font-bold text-slate-900">{{ $sup->target_lecturer_name }}</div>
                                                        </div>
                                                        <div class="text-[10px] text-slate-600 mt-1 flex items-center justify-between">
                                                            <span class="font-mono font-bold">{{ $sup->room_name }}</span>
                                                            <span class="font-bold uppercase">{{ $sup->delivery_mode }}</span>
                                                        </div>
                                                        @if(!empty($sup->stage_dates))
                                                            <div class="text-[9px] text-slate-500 font-mono text-right mt-0.5">
                                                                @foreach($sup->stage_dates as $stageDate)
                                                                    <div>
                                                                        {{ $stageDate['name'] }}
                                                                        @if($stageDate['date'])
                                                                            ({{ \Carbon\Carbon::parse($stageDate['date'])->format('d/m') }})
                                                                        @endif
                                                                    </div>
                                                                @endforeach
                                                            </div>
                                                        @endif
                                                    </div>
                                                @endforeach
                                            </td>
                                        @endforeach
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="px-4 py-10 text-center text-xs text-slate-400">Belum ada slot jadwal mengajar atau supervisi.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- ═══════════════════════════════════════════════════════════ -->
                <!-- 2. DETAILED LIST VIEW                                      -->
                <!-- ═══════════════════════════════════════════════════════════ -->
                <div x-show="scheduleView === 'list'">
                    <div class="overflow-x-auto border border-slate-200 rounded-xl">
                        <table class="w-full text-left text-sm">
                            <thead class="bg-slate-50 text-slate-500 uppercase text-[10px] font-semibold border-b border-slate-200">
                                <tr>
                                    <th class="px-4 py-2.5">Hari</th>
                                    <th class="px-4 py-2.5">Jam Kuliah</th>
                                    <th class="px-4 py-2.5">Jenis Aktivitas</th>
                                    <th class="px-4 py-2.5">Mata Kuliah</th>
                                    <th class="px-4 py-2.5">Kelas</th>
                                    <th class="px-4 py-2.5">Ruang</th>
                                    <th class="px-4 py-2.5">Metode</th>
                                    <th class="px-4 py-2.5">Dosen / Keterangan</th>
                                    <th class="px-4 py-2.5">Sumber</th>
                                    <th class="px-4 py-2.5 text-right">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @php
                                    $dayNames = [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];
                                    $eventsList = $allScheduleEvents ?? $schedules;
                                @endphp
                                @forelse($eventsList as $ev)
                                    @php
                                        $isSup = !empty($ev->is_supervision);
                                    @endphp
                                    <tr class="{{ $isSup ? 'bg-amber-50/70 hover:bg-amber-100/60 border-l-4 border-amber-400 transition-colors' : 'hover:bg-slate-50/50 transition-colors' }}">
                                        <td class="px-4 py-3 font-semibold text-slate-800 text-xs">
                                            {{ $dayNames[$ev->day_of_week] ?? $ev->day_of_week }}
                                        </td>
                                        <td class="px-4 py-3 text-xs text-slate-600 font-mono">
                                            {{ substr((string)$ev->starts_local, 0, 5) }} – {{ substr((string)$ev->ends_local, 0, 5) }}
                                        </td>
                                        <td class="px-4 py-3 text-xs">
                                            @if($isSup)
                                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-black uppercase tracking-wider bg-amber-200 text-amber-950 border border-amber-300">
                                                    Supervisi
                                                </span>
                                            @else
                                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-medium bg-slate-100 text-slate-700 border border-slate-200">
                                                    Mengajar
                                                </span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-xs font-semibold text-slate-900">
                                            {{ $ev->course?->name ?? $ev->course_name ?? '-' }}
                                        </td>
                                        <td class="px-4 py-3 text-xs font-bold {{ $isSup ? 'text-amber-950' : 'text-[#7B1113]' }}">
                                            {{ $ev->class_name ?? $ev->class_group ?? '-' }}
                                        </td>
                                        <td class="px-4 py-3 text-xs text-slate-600 font-mono">
                                            {{ $ev->room_display ?? $ev->room_name ?? $ev->room ?? '-' }}
                                        </td>
                                        <td class="px-4 py-3 text-xs">
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold {{ ($ev->delivery_mode ?? 'OFFLINE') === 'ONLINE' ? 'bg-blue-100 text-blue-800' : ($isSup ? 'bg-amber-200 text-amber-950 border border-amber-300' : 'bg-slate-100 text-slate-700') }}">
                                                {{ $ev->delivery_mode ?? 'OFFLINE' }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-xs">
                                            @if($isSup)
                                                <span class="text-amber-900 font-semibold text-[11px]">Disupervisi:</span>
                                                <span class="font-bold text-slate-900">{{ $ev->target_lecturer_name }}</span>
                                                @if(!empty($ev->stage_name))
                                                    <span class="text-[10px] text-amber-800 block">({{ $ev->stage_name }})</span>
                                                @endif
                                            @else
                                                <span class="text-slate-400 italic">Dosen Pengampu Mandiri</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-xs">
                                            @if(!$isSup && ($ev->schedule ?? null))
                                                <span class="inline-flex rounded-full px-2 py-1 text-[10px] font-semibold {{ $ev->schedule_source === 'Impor otomatis' ? 'bg-blue-50 text-blue-700' : 'bg-slate-100 text-slate-600' }}">{{ $ev->schedule_source }}</span>
                                            @elseif(!$isSup && !empty($isStagingSchedule))
                                                <span class="inline-flex rounded-full bg-amber-50 px-2 py-1 text-[10px] font-semibold text-amber-700">Menunggu impor</span>
                                            @else
                                                <span class="text-slate-400">—</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-right">
                                            @if(!$isSup && ($ev->schedule ?? null) && $selectedPeriod)
                                                <div class="inline-flex items-center gap-1">
                                                    <a href="{{ route('lpm.lecturers.schedules.edit', ['lecturer' => $lecturer, 'period' => $selectedPeriod, 'schedule' => $ev->schedule]) }}" class="rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">Edit</a>
                                                    <form method="POST" action="{{ route('lpm.lecturers.schedules.destroy', ['lecturer' => $lecturer, 'period' => $selectedPeriod, 'schedule' => $ev->schedule]) }}" onsubmit="return confirm('Hapus jadwal perkuliahan ini? Riwayat supervisi yang sudah berjalan akan tetap disimpan.');">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit" class="rounded-lg border border-rose-200 px-2.5 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-50">Hapus</button>
                                                    </form>
                                                </div>
                                            @else
                                                <span class="text-slate-400">—</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="10" class="px-4 py-8 text-center text-slate-400 text-xs">
                                            {{ $schedulePeriodIsActive ? 'Belum ada jadwal mengajar atau tugas supervisi.' : 'Periode supervisi tidak aktif; jadwal perkuliahan tidak tersedia.' }}
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>

        <!-- ═══════════════════════════════════════ -->
        <!-- TAB 2: Penilaian Tahap 1                -->
        <!-- ═══════════════════════════════════════ -->
        <div x-show="activeTab === 't1'" x-cloak>
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="px-5 py-4 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                    <div>
                        <h2 class="font-bold text-slate-900 text-sm">Hasil Supervisi — Tahap 1</h2>
                        <p class="text-[11px] text-slate-500">Evaluasi pengajaran dan instrumen supervisi mutu tahap 1.</p>
                    </div>
                    <span class="text-[10px] font-bold bg-blue-100 text-blue-700 px-2.5 py-1 rounded-lg">{{ $t1Assignments->count() }} Supervisi</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-slate-50 text-slate-500 uppercase text-[10px] font-semibold">
                            <tr>
                                <th class="px-4 py-2.5">Supervisor</th>
                                <th class="px-4 py-2.5">Mata Kuliah</th>
                                <th class="px-4 py-2.5">Tanggal</th>
                                <th class="px-4 py-2.5">Mode</th>
                                <th class="px-4 py-2.5 text-center">Skor (IPK)</th>
                                <th class="px-4 py-2.5 text-center">Status</th>
                                <th class="px-4 py-2.5 text-right">Laporan</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse($t1Assignments as $a)
                                <tr class="hover:bg-slate-50/50">
                                    <td class="px-4 py-3">
                                        <p class="text-xs font-semibold text-slate-900">{{ $a->supervisorLecturer?->name ?? '-' }}</p>
                                    </td>
                                    <td class="px-4 py-3 text-xs text-slate-600">{{ $a->teachingOccurrence?->teachingSchedule?->course?->name ?? '-' }}</td>
                                    <td class="px-4 py-3 text-xs text-slate-600">{{ $a->supervision?->supervision_date?->format('d/m/Y') ?? '-' }}</td>
                                    <td class="px-4 py-3">
                                        @if($a->supervision?->delivery_mode)
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold {{ $a->supervision->delivery_mode === 'LURING' ? 'bg-teal-100 text-teal-800' : 'bg-violet-100 text-violet-800' }}">{{ $a->supervision->delivery_mode }}</span>
                                        @else
                                            <span class="text-slate-400 text-xs">-</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        @if($a->supervision?->final_score !== null)
                                            <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-black {{ (float) $a->supervision->final_score >= 3.5 ? 'bg-emerald-100 text-emerald-800' : ((float) $a->supervision->final_score >= 2.5 ? 'bg-amber-100 text-amber-800' : 'bg-rose-100 text-rose-800') }}">
                                                {{ number_format((float) $a->supervision->final_score, 2) }}
                                            </span>
                                        @else
                                            <span class="text-slate-400 text-xs">—</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        @php $status = $a->supervision?->status ?? $a->status; @endphp
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold
                                            {{ $status === 'SUBMITTED' ? 'bg-emerald-100 text-emerald-800' : ($status === 'DRAFT' ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-500') }}
                                        ">{{ $status ?? 'PENDING' }}</span>
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        @if($a->supervision?->status === 'SUBMITTED')
                                            <a href="{{ route('lpm.supervisions.pdf', $a->supervision->id) }}" target="_blank" class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-slate-100 hover:bg-rose-50 text-slate-700 hover:text-[#7B1113] font-semibold text-xs border border-slate-200 transition-colors" title="Unduh Lembar Hasil PDF">
                                                <svg class="w-3.5 h-3.5 text-[#7B1113]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                                                PDF
                                            </a>
                                        @else
                                            <span class="text-slate-400 text-xs">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="px-4 py-8 text-center text-slate-400 text-xs">Belum ada data supervisi Tahap 1.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ═══════════════════════════════════════ -->
        <!-- TAB 3: Penilaian Tahap 2                -->
        <!-- ═══════════════════════════════════════ -->
        <div x-show="activeTab === 't2'" x-cloak>
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="px-5 py-4 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                    <div>
                        <h2 class="font-bold text-slate-900 text-sm">Hasil Supervisi — Tahap 2</h2>
                        <p class="text-[11px] text-slate-500">Evaluasi pengajaran dan instrumen supervisi mutu tahap 2.</p>
                    </div>
                    <span class="text-[10px] font-bold bg-purple-100 text-purple-700 px-2.5 py-1 rounded-lg">{{ $t2Assignments->count() }} Supervisi</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-slate-50 text-slate-500 uppercase text-[10px] font-semibold">
                            <tr>
                                <th class="px-4 py-2.5">Supervisor</th>
                                <th class="px-4 py-2.5">Mata Kuliah</th>
                                <th class="px-4 py-2.5">Tanggal</th>
                                <th class="px-4 py-2.5">Mode</th>
                                <th class="px-4 py-2.5 text-center">Skor (IPK)</th>
                                <th class="px-4 py-2.5 text-center">Status</th>
                                <th class="px-4 py-2.5 text-right">Laporan</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse($t2Assignments as $a)
                                <tr class="hover:bg-slate-50/50">
                                    <td class="px-4 py-3">
                                        <p class="text-xs font-semibold text-slate-900">{{ $a->supervisorLecturer?->name ?? '-' }}</p>
                                    </td>
                                    <td class="px-4 py-3 text-xs text-slate-600">{{ $a->teachingOccurrence?->teachingSchedule?->course?->name ?? '-' }}</td>
                                    <td class="px-4 py-3 text-xs text-slate-600">{{ $a->supervision?->supervision_date?->format('d/m/Y') ?? '-' }}</td>
                                    <td class="px-4 py-3">
                                        @if($a->supervision?->delivery_mode)
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold {{ $a->supervision->delivery_mode === 'LURING' ? 'bg-teal-100 text-teal-800' : 'bg-violet-100 text-violet-800' }}">{{ $a->supervision->delivery_mode }}</span>
                                        @else
                                            <span class="text-slate-400 text-xs">-</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        @if($a->supervision?->final_score !== null)
                                            <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-black {{ (float) $a->supervision->final_score >= 3.5 ? 'bg-emerald-100 text-emerald-800' : ((float) $a->supervision->final_score >= 2.5 ? 'bg-amber-100 text-amber-800' : 'bg-rose-100 text-rose-800') }}">
                                                {{ number_format((float) $a->supervision->final_score, 2) }}
                                            </span>
                                        @else
                                            <span class="text-slate-400 text-xs">—</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        @php $status = $a->supervision?->status ?? $a->status; @endphp
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold
                                            {{ $status === 'SUBMITTED' ? 'bg-emerald-100 text-emerald-800' : ($status === 'DRAFT' ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-500') }}
                                        ">{{ $status ?? 'PENDING' }}</span>
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        @if($a->supervision?->status === 'SUBMITTED')
                                            <a href="{{ route('lpm.supervisions.pdf', $a->supervision->id) }}" target="_blank" class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-slate-100 hover:bg-rose-50 text-slate-700 hover:text-[#7B1113] font-semibold text-xs border border-slate-200 transition-colors" title="Unduh Lembar Hasil PDF">
                                                <svg class="w-3.5 h-3.5 text-[#7B1113]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                                                PDF
                                            </a>
                                        @else
                                            <span class="text-slate-400 text-xs">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="px-4 py-8 text-center text-slate-400 text-xs">Belum ada data supervisi Tahap 2.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ═══════════════════════════════════════ -->
        <!-- TAB 4: Perbandingan T1 vs T2            -->
        <!-- ═══════════════════════════════════════ -->
        <div x-show="activeTab === 'compare'" x-cloak>
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="px-5 py-4 border-b border-slate-100">
                    <h2 class="font-bold text-slate-900 text-sm">Perbandingan Skor Tahap 1 vs Tahap 2</h2>
                    <p class="text-[10px] text-slate-500 mt-0.5">Perbandingan per periode supervisi. <span class="text-emerald-600 font-bold">▲ Naik</span> &nbsp; <span class="text-rose-600 font-bold">▼ Turun</span> &nbsp; <span class="text-slate-500 font-bold">● Stabil</span></p>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-slate-50 text-slate-500 uppercase text-[10px] font-semibold">
                            <tr>
                                <th class="px-4 py-2.5">Periode</th>
                                <th class="px-4 py-2.5">Semester</th>
                                <th class="px-4 py-2.5 text-center">Skor T1</th>
                                <th class="px-4 py-2.5 text-center">Skor T2</th>
                                <th class="px-4 py-2.5 text-center">Delta</th>
                                <th class="px-4 py-2.5 text-center">Tren</th>
                                <th class="px-4 py-2.5">Supervisor T1</th>
                                <th class="px-4 py-2.5">Supervisor T2</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse($comparisons as $c)
                                <tr class="hover:bg-slate-50/50">
                                    <td class="px-4 py-3 text-xs font-semibold text-slate-900">{{ $c['period_name'] }}</td>
                                    <td class="px-4 py-3 text-xs text-slate-600">{{ $c['term_name'] }}</td>
                                    <td class="px-4 py-3 text-center">
                                        @if($c['t1_score'] !== null)
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-bold bg-blue-100 text-blue-800">{{ $c['t1_score'] }}</span>
                                        @else
                                            <span class="text-slate-400 text-xs">—</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        @if($c['t2_score'] !== null)
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-bold bg-purple-100 text-purple-800">{{ $c['t2_score'] }}</span>
                                        @else
                                            <span class="text-slate-400 text-xs">—</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        @if($c['delta'] !== null)
                                            <span class="text-xs font-black {{ $c['delta'] > 0 ? 'text-emerald-600' : ($c['delta'] < 0 ? 'text-rose-600' : 'text-slate-500') }}">
                                                {{ $c['delta'] > 0 ? '+' : '' }}{{ $c['delta'] }}
                                            </span>
                                        @else
                                            <span class="text-slate-400 text-xs">—</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        @if($c['direction'] === 'INCREASE')
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800">▲ Naik</span>
                                        @elseif($c['direction'] === 'DECREASE')
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-100 text-rose-800">▼ Turun</span>
                                        @elseif($c['direction'] === 'STABLE')
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-100 text-slate-600">● Stabil</span>
                                        @else
                                            <span class="text-slate-400 text-xs">—</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-xs text-slate-600">{{ $c['t1_supervisor'] ?? '-' }}</td>
                                    <td class="px-4 py-3 text-xs text-slate-600">{{ $c['t2_supervisor'] ?? '-' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="px-4 py-8 text-center text-slate-400 text-xs">Belum ada data perbandingan T1 vs T2.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ═══════════════════════════════════════ -->
        <!-- TAB 5: Temuan Umum                      -->
        <!-- ═══════════════════════════════════════ -->
        <div x-show="activeTab === 'findings'" x-cloak>
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
                    <h2 class="font-bold text-slate-900 text-sm">Temuan Umum Supervisi</h2>
                    <span class="text-[10px] font-bold bg-amber-100 text-amber-700 px-2 py-0.5 rounded">{{ is_countable($findings) ? count($findings) : 0 }} Catatan</span>
                </div>
                <div class="divide-y divide-slate-100">
                    @forelse($findings as $f)
                        <div class="p-5 hover:bg-slate-50/50 transition-colors">
                            <div class="flex items-start gap-3">
                                <div class="w-8 h-8 rounded-lg bg-amber-100 text-amber-700 flex items-center justify-center shrink-0 mt-0.5">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"></path></svg>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2 mb-1">
                                        <span class="text-[10px] font-bold text-[#7B1113] bg-rose-50 px-2 py-0.5 rounded">{{ $f['stage'] }}</span>
                                        <span class="text-[10px] text-slate-400">{{ $f['date'] }}</span>
                                    </div>
                                    <p class="text-xs font-bold text-slate-700 mb-1">{{ $f['question'] }}</p>
                                    <p class="text-xs text-slate-600 leading-relaxed">{{ $f['answer'] }}</p>
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="p-8 text-center text-slate-400 text-xs">
                            Belum ada temuan atau catatan dari supervisi.
                        </div>
                    @endforelse
                </div>
            </div>
        </div>

    </div>
</div>
@endsection
