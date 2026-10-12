@extends('layouts.app', ['title' => 'Dashboard LPM - Sistem Supervisi Mutu Perkuliahan'])

@section('content')
<div class="space-y-6">

    <!-- ============================================================ -->
    <!-- 1. PAGE HEADER (AUTHENTIC SISD LPM UTI DOMAIN)               -->
    <!-- ============================================================ -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-2 mb-1">
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-rose-50 text-[#7B1113] border border-rose-200">
                    Lembaga Penjaminan Mutu (LPM)
                </span>
                <span class="inline-flex items-center gap-1.5 text-xs text-stone-500">
                    <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                    Sistem Operasional Aktif
                </span>
            </div>
            <h1 class="text-2xl sm:text-[28px] font-bold text-stone-900 tracking-tight">
                Dashboard Supervisi Mutu Perkuliahan
            </h1>
            <p class="text-xs sm:text-sm text-stone-500 mt-1">
                Monitoring pelaksanaan penugasan, jadwal observasi kelas, dan evaluasi capaian mutu pembelajaran periode: 
                <strong class="text-stone-800 font-bold">
                    {{ $activePeriod ? $activePeriod->semester_display.' · Tahap 1 dan Tahap 2' : 'Belum Ada Periode Aktif' }}
                </strong>
            </p>
        </div>

        <!-- Quick Action Buttons -->
        <div class="flex flex-wrap items-center gap-2.5 shrink-0">
            <a 
                href="{{ route('lpm.import.index') }}" 
                class="inline-flex items-center px-3.5 py-2 rounded-xl border border-stone-200 bg-white hover:bg-stone-50 text-stone-700 text-xs font-bold transition shadow-2xs"
            >
                <svg class="w-4 h-4 mr-2 text-[#7B1113]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path></svg>
                Import PDF Jadwal
            </a>
            <a 
                href="{{ route('lpm.assignments.index') }}" 
                class="inline-flex items-center px-4 py-2 rounded-xl bg-[#7B1113] hover:bg-[#5E0C0F] text-white text-xs font-bold transition shadow-xs"
            >
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path></svg>
                Workspace Solver
            </a>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- 2. 4 TOP STAT CARDS (REAL SISD METRICS)                      -->
    <!-- ============================================================ -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-5">
        
        <!-- CARD 1: TOTAL PENUGASAN (Institutional Maroon) -->
        <div class="bg-white rounded-2xl p-5 border border-stone-200/80 shadow-xs relative overflow-hidden flex flex-col justify-between">
            <div>
                <div class="flex items-start justify-between">
                    <div>
                        <span class="text-[11px] font-bold uppercase tracking-wider text-stone-400">
                            TOTAL PENUGASAN
                        </span>
                        <div class="text-3xl font-extrabold text-stone-900 mt-1.5">
                            {{ $totalAssignments }}
                        </div>
                    </div>
                    <!-- Institutional Maroon Icon Box -->
                    <div class="w-10 h-10 rounded-xl bg-[#7B1113] text-white flex items-center justify-center shadow-xs shrink-0">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path></svg>
                    </div>
                </div>
                <div class="mt-2 text-[11px] text-stone-500">
                    <span class="font-bold text-stone-700">{{ $totalLecturers }}</span> Dosen Aktif • <span class="font-bold text-stone-700">{{ $totalSupervisors }}</span> Supervisor
                </div>
            </div>

            <!-- Mini Sparkline in Red -->
            <div class="mt-4 -mb-1 w-full h-8 overflow-hidden">
                <svg viewBox="0 0 100 25" preserveAspectRatio="none" class="w-full h-full">
                    <defs>
                        <linearGradient id="gradRed" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stop-color="#E52329" stop-opacity="0.25" />
                            <stop offset="100%" stop-color="#E52329" stop-opacity="0.0" />
                        </linearGradient>
                    </defs>
                    <path d="M0,20 Q20,18 40,22 T70,14 T100,16 L100,25 L0,25 Z" fill="url(#gradRed)" />
                    <path d="M0,20 Q20,18 40,22 T70,14 T100,16" fill="none" stroke="#E52329" stroke-width="2" stroke-linecap="round" />
                </svg>
            </div>
        </div>

        <!-- CARD 2: SUPERVISI SELESAI (Emerald Green) -->
        <div class="bg-white rounded-2xl p-5 border border-stone-200/80 shadow-xs relative overflow-hidden flex flex-col justify-between">
            <div>
                <div class="flex items-start justify-between">
                    <div>
                        <span class="text-[11px] font-bold uppercase tracking-wider text-stone-400">
                            SUPERVISI SELESAI
                        </span>
                        <div class="text-3xl font-extrabold text-stone-900 mt-1.5">
                            {{ $completedCount }}
                        </div>
                    </div>
                    <!-- Green Icon Box -->
                    <div class="w-10 h-10 rounded-xl bg-[#10B981] text-white flex items-center justify-center shadow-xs shrink-0">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    </div>
                </div>
                <div class="mt-2 text-[11px] text-emerald-700 font-medium">
                    ✓ Dokumen Beku (Snapshot Resmi)
                </div>
            </div>

            <!-- Mini Sparkline in Green -->
            <div class="mt-4 -mb-1 w-full h-8 overflow-hidden">
                <svg viewBox="0 0 100 25" preserveAspectRatio="none" class="w-full h-full">
                    <defs>
                        <linearGradient id="gradGreen" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stop-color="#10B981" stop-opacity="0.25" />
                            <stop offset="100%" stop-color="#10B981" stop-opacity="0.0" />
                        </linearGradient>
                    </defs>
                    <path d="M0,22 Q30,20 50,15 T80,16 T100,10 L100,25 L0,25 Z" fill="url(#gradGreen)" />
                    <path d="M0,22 Q30,20 50,15 T80,16 T100,10" fill="none" stroke="#10B981" stroke-width="2" stroke-linecap="round" />
                </svg>
            </div>
        </div>

        <!-- CARD 3: SEDANG DINILAI (Amber Warm) -->
        <div class="bg-white rounded-2xl p-5 border border-stone-200/80 shadow-xs relative overflow-hidden flex flex-col justify-between">
            <div>
                <div class="flex items-start justify-between">
                    <div>
                        <span class="text-[11px] font-bold uppercase tracking-wider text-stone-400">
                            SEDANG DINILAI
                        </span>
                        <div class="text-3xl font-extrabold text-stone-900 mt-1.5">
                            {{ $inProgressCount }}
                        </div>
                    </div>
                    <!-- Amber Icon Box -->
                    <div class="w-10 h-10 rounded-xl bg-[#F59E0B] text-white flex items-center justify-center shadow-xs shrink-0">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg>
                    </div>
                </div>
                <div class="mt-2 text-[11px] text-amber-700 font-medium">
                    ✎ Draf Observasi Berjalan
                </div>
            </div>

            <!-- Mini Sparkline in Amber -->
            <div class="mt-4 -mb-1 w-full h-8 overflow-hidden">
                <svg viewBox="0 0 100 25" preserveAspectRatio="none" class="w-full h-full">
                    <defs>
                        <linearGradient id="gradOrange" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stop-color="#F59E0B" stop-opacity="0.25" />
                            <stop offset="100%" stop-color="#F59E0B" stop-opacity="0.0" />
                        </linearGradient>
                    </defs>
                    <path d="M0,21 Q25,23 50,17 T75,19 T100,18 L100,25 L0,25 Z" fill="url(#gradOrange)" />
                    <path d="M0,21 Q25,23 50,17 T75,19 T100,18" fill="none" stroke="#F59E0B" stroke-width="2" stroke-linecap="round" />
                </svg>
            </div>
        </div>

        <!-- CARD 4: BELUM DINILAI (Deep Maroon) -->
        <div class="bg-white rounded-2xl p-5 border border-stone-200/80 shadow-xs relative overflow-hidden flex flex-col justify-between">
            <div>
                <div class="flex items-start justify-between">
                    <div>
                        <span class="text-[11px] font-bold uppercase tracking-wider text-stone-400">
                            BELUM DINILAI
                        </span>
                        <div class="text-3xl font-extrabold text-stone-900 mt-1.5">
                            {{ $pendingCount }}
                        </div>
                    </div>
                    <!-- Institutional Maroon Icon Box -->
                    <div class="w-10 h-10 rounded-xl bg-[#7B1113] text-white flex items-center justify-center shadow-xs shrink-0">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    </div>
                </div>
                <div class="mt-2 text-[11px] text-stone-500 font-medium">
                    ⏳ Menunggu Jadwal Tatap Muka
                </div>
            </div>

            <!-- Mini Sparkline in Maroon -->
            <div class="mt-4 -mb-1 w-full h-8 overflow-hidden">
                <svg viewBox="0 0 100 25" preserveAspectRatio="none" class="w-full h-full">
                    <defs>
                        <linearGradient id="gradMaroon" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stop-color="#7B1113" stop-opacity="0.25" />
                            <stop offset="100%" stop-color="#7B1113" stop-opacity="0.0" />
                        </linearGradient>
                    </defs>
                    <path d="M0,20 Q30,22 60,19 T90,21 T100,19 L100,25 L0,25 Z" fill="url(#gradMaroon)" />
                    <path d="M0,20 Q30,22 60,19 T90,21 T100,19" fill="none" stroke="#C22227" stroke-width="2" stroke-linecap="round" />
                </svg>
            </div>
        </div>

    </div>

    <!-- ============================================================ -->
    <!-- 2.5. KOMPARASI KOHORT TAHAP 1 VS TAHAP 2 (DESAIN.MD SEC 12)  -->
    <!-- ============================================================ -->
    <div class="bg-white rounded-2xl border border-stone-200/80 p-5 sm:p-6 shadow-xs">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-stone-100">
            <div>
                <div class="flex items-center gap-2 mb-1">
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-rose-50 text-[#7B1113] border border-rose-200">
                        Evaluasi Mutu Siklus Perkuliahan
                    </span>
                    <span class="text-xs text-stone-400 font-medium">Analisis Antartahap</span>
                </div>
                <h2 class="text-base sm:text-lg font-bold text-stone-900 tracking-tight">
                    Komparasi Capaian Kohort (Tahap 1 vs Tahap 2)
                </h2>
                <p class="text-xs text-stone-500 mt-0.5">
                    Perbandingan mutu pembelajaran antara observasi awal semester (Tahap 1) dan lanjutan semester (Tahap 2).
                </p>
            </div>
            <a 
                href="{{ route('lpm.analytics.index') }}" 
                class="inline-flex items-center px-3.5 py-1.5 rounded-xl border border-stone-200 hover:border-[#7B1113] text-[#7B1113] bg-white hover:bg-rose-50 text-xs font-bold transition shadow-2xs shrink-0"
            >
                <span>Lihat Analisis Detail</span>
                <span class="ml-1 text-sm">↗</span>
            </a>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 pt-4">
            <!-- Tahap 1 -->
            <div class="p-4 rounded-xl bg-stone-50/80 border border-stone-200/70 flex flex-col justify-between">
                <div>
                    <span class="text-[11px] font-bold text-stone-400 uppercase tracking-wider">Tahap 1: Perkuliahan Awal</span>
                    <div class="flex items-baseline gap-2 mt-1">
                        @if($t1Mean !== null)
                            <span class="text-2xl font-black text-stone-900">{{ number_format($t1Mean, 2) }}</span>
                            <span class="text-xs text-stone-500 font-semibold">/ 4.00</span>
                        @else
                            <span class="text-2xl font-black text-stone-400">—</span>
                            <span class="text-xs text-stone-400 font-medium">Belum Ada Data</span>
                        @endif
                    </div>
                </div>
                <div class="text-[11px] text-stone-500 mt-2 flex items-center justify-between">
                    <span>Sampel Observasi:</span>
                    <span class="font-bold text-stone-700">{{ $t1Count }} Dosen Selesai</span>
                </div>
            </div>

            <!-- Tahap 2 -->
            <div class="p-4 rounded-xl bg-stone-50/80 border border-stone-200/70 flex flex-col justify-between">
                <div>
                    <span class="text-[11px] font-bold text-stone-400 uppercase tracking-wider">Tahap 2: Perkuliahan Lanjutan</span>
                    <div class="flex items-baseline gap-2 mt-1">
                        @if($t2Mean !== null)
                            <span class="text-2xl font-black text-stone-900">{{ number_format($t2Mean, 2) }}</span>
                            <span class="text-xs text-stone-500 font-semibold">/ 4.00</span>
                        @else
                            <span class="text-2xl font-black text-stone-400">—</span>
                            <span class="text-xs text-stone-400 font-medium">Belum Ada Data</span>
                        @endif
                    </div>
                </div>
                <div class="text-[11px] text-stone-500 mt-2 flex items-center justify-between">
                    <span>Sampel Observasi:</span>
                    <span class="font-bold text-stone-700">{{ $t2Count }} Dosen Selesai</span>
                </div>
            </div>

            <!-- Perubahan Berpasangan (Delta) -->
            <div class="p-4 rounded-xl bg-stone-50/80 border border-stone-200/70 flex flex-col justify-between">
                <div>
                    <span class="text-[11px] font-bold text-stone-400 uppercase tracking-wider">Rerata Perubahan Berpasangan (Delta)</span>
                    <div class="flex items-baseline gap-2 mt-1">
                        @if(!empty($cohortAnalytics['average_delta']))
                            <span class="text-2xl font-black text-emerald-600">
                                {{ $cohortAnalytics['average_delta'] >= 0 ? '+' : '' }}{{ number_format($cohortAnalytics['average_delta'], 2) }}
                            </span>
                            <span class="text-xs text-emerald-700 font-semibold">▲ Meningkat</span>
                        @else
                            <span class="text-2xl font-black text-stone-400">—</span>
                            <span class="text-xs text-stone-400 font-medium">Belum Ada Data</span>
                        @endif
                    </div>
                </div>
                <div class="text-[11px] text-stone-500 mt-2 flex items-center justify-between">
                    <span>Pasangan Sebanding:</span>
                    <span class="font-bold text-stone-700">{{ $cohortAnalytics['comparable_count'] ?? 0 }} Dosen Pasangan</span>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- 3. TWO-COLUMN MIDDLE SECTION (SUPERVISI AKTIF + DISTRIBUSI)  -->
    <!-- ============================================================ -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-5">
        
        <!-- LEFT COLUMN: PENUGASAN SUPERVISI TERBARU (~65% width / lg:col-span-8) -->
        <div class="lg:col-span-8 bg-white rounded-2xl border border-stone-200/80 p-5 sm:p-6 shadow-xs flex flex-col justify-between">
            <div>
                <!-- Card Header -->
                <div class="flex items-center justify-between pb-4 border-b border-stone-100">
                    <div>
                        <h2 class="text-base sm:text-lg font-bold text-stone-900 tracking-tight">
                            Penugasan Supervisi Terbaru
                        </h2>
                        <p class="text-xs text-stone-500 mt-0.5">
                            Daftar jadwal observasi dosen dan penugasan supervisor pada periode aktif.
                        </p>
                    </div>
                    <a 
                        href="{{ route('lpm.assignments.index') }}" 
                        class="text-xs sm:text-sm font-semibold text-[#7B1113] hover:text-[#5A0E13] flex items-center gap-1 transition-colors shrink-0"
                    >
                        <span>Workspace Penugasan</span>
                        <span class="text-base">↗</span>
                    </a>
                </div>

                <!-- Table Container -->
                <div class="overflow-x-auto mt-4">
                    <table class="w-full min-w-[640px] text-left border-collapse">
                        <thead>
                            <tr class="text-[11px] font-bold text-stone-400 uppercase tracking-wider border-b border-stone-100">
                                <th class="pb-3 pr-4">Fakultas / Kelas</th>
                                <th class="pb-3 px-4">Dosen & Mata Kuliah</th>
                                <th class="pb-3 px-4">Dosen Supervisor</th>
                                <th class="pb-3 px-4 text-center">Tahap</th>
                                <th class="pb-3 px-4">Status</th>
                                <th class="pb-3 pl-4 text-right">Update</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100 text-xs sm:text-sm">
                            @forelse($recentAssignments as $asg)
                            @php
                                $status = $asg->supervision?->status ?? 'PENDING';
                                $isSubmitted = $status === 'SUBMITTED';
                                $isDraft = $status === 'DRAFT';
                                
                                $facultyCode = strtoupper($asg->targetLecturer?->faculty?->code ?? $asg->targetLecturer?->studyProgram?->faculty?->code ?? '-');
                                $prodiName = $asg->targetLecturer?->studyProgram?->name ?? '-';
                                $className = $asg->occurrence?->schedule?->class_name ?? '-';
                                $courseName = $asg->occurrence?->schedule?->course?->name ?? 'Mata Kuliah';
                                $stageName = $asg->supervisionStage?->name ?? 'Tahap 1';
                            @endphp
                            <tr class="hover:bg-stone-50/80 transition-colors">
                                <!-- FAKULTAS & KELAS -->
                                <td class="py-3.5 pr-4 whitespace-nowrap">
                                    <div class="font-bold text-stone-900">
                                        {{ $facultyCode }}
                                    </div>
                                    <div class="text-[11px] text-stone-500 mt-0.5">
                                        Kelas {{ $className }}
                                    </div>
                                </td>

                                <!-- DOSEN YANG DISUPERVISI & MATA KULIAH -->
                                <td class="py-3.5 px-4 min-w-[200px]">
                                    <div class="font-semibold text-stone-900">
                                        {{ $asg->targetLecturer?->name ?? 'Dosen' }}
                                    </div>
                                    <div class="text-[11px] text-stone-500 mt-0.5 truncate" title="{{ $courseName }}">
                                        {{ $courseName }}
                                    </div>
                                </td>

                                <!-- DOSEN SUPERVISOR -->
                                <td class="py-3.5 px-4 whitespace-nowrap">
                                    <div class="font-medium text-stone-800">
                                        {{ $asg->supervisorLecturer?->name ?? 'Belum Ditugaskan' }}
                                    </div>
                                    <div class="text-[10px] text-stone-400">
                                        Dosen Supervisor
                                    </div>
                                </td>

                                <!-- TAHAP -->
                                <td class="py-3.5 px-4 text-center whitespace-nowrap">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-stone-100 text-stone-700">
                                        {{ $stageName }}
                                    </span>
                                </td>

                                <!-- STATUS PILL -->
                                <td class="py-3.5 px-4 whitespace-nowrap">
                                    @if($isSubmitted)
                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-[#10B981] text-white shadow-2xs">
                                            Selesai ({{ number_format($asg->supervision?->final_score, 2) }})
                                        </span>
                                    @elseif($isDraft)
                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-[#F59E0B] text-white shadow-2xs">
                                            Sedang Dinilai
                                        </span>
                                    @else
                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-rose-50 text-[#7B1113] border border-rose-200/80 shadow-2xs">
                                            Belum Mulai
                                        </span>
                                    @endif
                                </td>

                                <!-- UPDATE TIME -->
                                <td class="py-3.5 pl-4 text-right text-xs text-stone-500 whitespace-nowrap">
                                    {{ $asg->updated_at ? $asg->updated_at->diffForHumans() : 'Baru saja' }}
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="6" class="py-8 text-center text-xs text-stone-400">
                                    Belum ada penugasan supervisi pada periode ini.
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Footer note -->
            <div class="pt-4 mt-2 border-t border-stone-100 flex flex-col sm:flex-row sm:items-center justify-between gap-2 text-xs text-stone-400">
                <span>Menampilkan penugasan aktif dari total {{ $totalAssignments }} penugasan terjadwal</span>
                <span class="inline-flex items-center gap-1.5 font-medium text-stone-600">
                    <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                    Sistem Penjadwalan Terverifikasi
                </span>
            </div>
        </div>

        <!-- RIGHT COLUMN: DISTRIBUSI CAPAIAN SKOR MUTU (~35% width / lg:col-span-4) -->
        <div class="lg:col-span-4 bg-white rounded-2xl border border-stone-200/80 p-5 sm:p-6 shadow-xs flex flex-col justify-between">
            <div>
                <!-- Card Header -->
                <div class="pb-3 border-b border-stone-100">
                    <h2 class="text-base sm:text-lg font-bold text-stone-900 tracking-tight">
                        Distribusi Capaian Skor Mutu
                    </h2>
                    <p class="text-xs text-stone-500 mt-0.5">
                        Standar Penilaian LPM UTI (Skala 4.00)
                    </p>
                </div>

                <!-- Circular Gauge / Donut Matching Mockup -->
                <div class="py-6 flex flex-col items-center justify-center">
                    <div class="relative w-36 h-36 flex items-center justify-center">
                        <!-- SVG Ring -->
                        <svg class="w-full h-full transform -rotate-90" viewBox="0 0 100 100">
                            <!-- Background Track: Light Gray / Stone-100 -->
                            <circle 
                                cx="50" cy="50" r="40" 
                                stroke="#F4F4F5" 
                                stroke-width="9" 
                                fill="transparent" 
                            />
                            <!-- Progress Ring in Emerald Green -->
                            <circle 
                                cx="50" cy="50" r="40" 
                                stroke="#10B981" 
                                stroke-width="9" 
                                stroke-linecap="round"
                                stroke-dasharray="251.2" 
                                stroke-dashoffset="{{ 251.2 - (251.2 * ($overallPercentage / 100)) }}" 
                                fill="transparent"
                                class="transition-all duration-1000 ease-out"
                            />
                        </svg>

                        <!-- Center Text -->
                        <div class="absolute inset-0 flex flex-col items-center justify-center text-center">
                            <span class="text-2xl font-black text-stone-900 leading-none">
                                {{ $totalSubmitted > 0 ? number_format($avgScore, 2) : '0.00' }}
                            </span>
                            <span class="text-[10px] font-bold text-stone-500 mt-1 uppercase tracking-tight">
                                Skor Rata-rata
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Category Breakdown List with Color Dots Matching PRD Scoring Criteria -->
                <div class="space-y-3 pt-2 text-xs">
                    
                    <!-- 1. Sangat Baik (Skor >= 3.50) -->
                    <div>
                        <div class="flex items-center justify-between">
                            <div class="flex items-center space-x-2">
                                <span class="w-2.5 h-2.5 rounded-full bg-[#10B981] shrink-0"></span>
                                <span class="text-stone-700 font-medium">Sangat Baik (≥ 3.50)</span>
                            </div>
                            <div class="font-bold text-stone-900">
                                <span>{{ $tierCounts['sangat_lengkap'] }}</span>
                                <span class="text-stone-400 ml-1 font-normal">{{ $tierPercentages['sangat_lengkap'] }}%</span>
                            </div>
                        </div>
                        @if($tierPercentages['sangat_lengkap'] > 0)
                        <div class="w-full bg-stone-100 h-1.5 rounded-full mt-1.5 overflow-hidden">
                            <div class="bg-[#10B981] h-full rounded-full" style="width: {{ $tierPercentages['sangat_lengkap'] }}%"></div>
                        </div>
                        @endif
                    </div>

                    <!-- 2. Baik (Skor 3.00 - 3.49) -->
                    <div class="flex items-center justify-between">
                        <div class="flex items-center space-x-2">
                            <span class="w-2.5 h-2.5 rounded-full bg-[#34D399] shrink-0"></span>
                            <span class="text-stone-700 font-medium">Baik (3.00 – 3.49)</span>
                        </div>
                        <div class="font-bold text-stone-900">
                            <span>{{ $tierCounts['lengkap'] }}</span>
                            <span class="text-stone-400 ml-1 font-normal">{{ $tierPercentages['lengkap'] }}%</span>
                        </div>
                    </div>

                    <!-- 3. Cukup / Temuan Minor (Skor 2.50 - 2.99) -->
                    <div class="flex items-center justify-between">
                        <div class="flex items-center space-x-2">
                            <span class="w-2.5 h-2.5 rounded-full bg-[#F59E0B] shrink-0"></span>
                            <span class="text-stone-700 font-medium">Cukup (2.50 – 2.99)</span>
                        </div>
                        <div class="font-bold text-stone-900">
                            <span>{{ $tierCounts['cukup'] }}</span>
                            <span class="text-stone-400 ml-1 font-normal">{{ $tierPercentages['cukup'] }}%</span>
                        </div>
                    </div>

                    <!-- 4. Kurang / Temuan Mayor (Skor 2.00 - 2.49) -->
                    <div class="flex items-center justify-between">
                        <div class="flex items-center space-x-2">
                            <span class="w-2.5 h-2.5 rounded-full bg-[#EA580C] shrink-0"></span>
                            <span class="text-stone-700 font-medium">Kurang (2.00 – 2.49)</span>
                        </div>
                        <div class="font-bold text-stone-900">
                            <span>{{ $tierCounts['kurang'] }}</span>
                            <span class="text-stone-400 ml-1 font-normal">{{ $tierPercentages['kurang'] }}%</span>
                        </div>
                    </div>

                    <!-- 5. Perlu Pembinaan Khusus (Skor < 2.00) -->
                    <div class="flex items-center justify-between">
                        <div class="flex items-center space-x-2">
                            <span class="w-2.5 h-2.5 rounded-full bg-[#EF4444] shrink-0"></span>
                            <span class="text-stone-700 font-medium">Perlu Pembinaan (&lt; 2.00)</span>
                        </div>
                        <div class="font-bold text-stone-900">
                            <span>{{ $tierCounts['kritis'] }}</span>
                            <span class="text-stone-400 ml-1 font-normal">{{ $tierPercentages['kritis'] }}%</span>
                        </div>
                    </div>

                </div>
            </div>

            <!-- Footer Subtext -->
            <div class="pt-4 mt-4 border-t border-stone-100 text-center text-xs text-stone-400">
                Total {{ $totalSubmitted }} evaluasi resmi tersubmit (Snapshot Immutable).
            </div>
        </div>

    </div>

    <!-- ============================================================ -->
    <!-- 4. BOTTOM SECTION: PROGRESS KEPATUHAN SUPERVISI PER FAKULTAS -->
    <!-- ============================================================ -->
    <div class="bg-white rounded-2xl border border-stone-200/80 p-5 sm:p-6 shadow-xs">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-4">
            <div>
                <h2 class="text-base sm:text-lg font-bold text-stone-900 tracking-tight">
                    Progress Kepatuhan Supervisi per Fakultas
                </h2>
                <p class="text-xs text-stone-500 mt-0.5">
                    Capaian supervisi mutu perkuliahan berdasarkan target dosen dan tahap observasi wajib.
                </p>
            </div>
            <div class="flex items-center gap-2">
                <span class="text-xl sm:text-2xl font-black text-[#10B981]">
                    {{ $progressData['percentage'] }}%
                </span>
                <span class="text-xs text-stone-400">Tercapai</span>
            </div>
        </div>

        <!-- Overall Progress Bar -->
        <div class="w-full bg-stone-100 h-3 rounded-full overflow-hidden mb-6">
            <div 
                class="bg-gradient-to-r from-emerald-500 to-[#10B981] h-full rounded-full transition-all duration-700" 
                style="width: {{ $progressData['percentage'] }}%"
            ></div>
        </div>

        <!-- Faculty Breakdown Grid -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 pt-2">
            @foreach($faculties as $fac)
            <div class="p-4 rounded-xl bg-stone-50/90 border border-stone-200/70">
                <div class="flex items-center justify-between mb-2">
                    <span class="font-bold text-xs sm:text-sm text-stone-800">
                        {{ $fac['name'] }} ({{ $fac['code'] }})
                    </span>
                    <span class="text-xs font-bold text-[#10B981]">
                        {{ $fac['percentage'] }}%
                    </span>
                </div>
                <div class="w-full bg-stone-200 h-2 rounded-full overflow-hidden">
                    <div class="bg-[#10B981] h-full rounded-full" style="width: {{ $fac['percentage'] }}%"></div>
                </div>
                <div class="flex justify-between text-[11px] text-stone-500 mt-1.5">
                    <span>{{ $fac['completed'] }} supervisi selesai</span>
                    <span>{{ $fac['total'] }} dosen · 2 supervisi per semester</span>
                </div>
            </div>
            @endforeach
        </div>
    </div>

</div>
@endsection
