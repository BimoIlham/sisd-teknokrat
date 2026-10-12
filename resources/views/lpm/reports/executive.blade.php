@extends('layouts.app', ['title' => 'Laporan Hasil Supervisi Pembelajaran'])

@section('content')
@php
    $categoryPresentation = [
        'spada' => ['color' => '#7c3fa0', 'action' => 'Optimalisasi SPADA', 'recommendation' => 'Dampingi kelengkapan perangkat dan materi pembelajaran, sediakan template, dan tinjau pembaruan SPADA secara berkala.'],
        'video' => ['color' => '#a855f7', 'action' => 'Pengembangan video pembelajaran', 'recommendation' => 'Dampingi pembuatan video yang sesuai dengan mata kuliah dan penautannya pada materi pembelajaran.'],
        'classroom' => ['color' => '#3b82c4', 'action' => 'Pengelolaan kelas aktif', 'recommendation' => 'Perkuat strategi pengelolaan kelas, pemerataan partisipasi, dan interaksi mahasiswa melalui pendampingan praktik mengajar.'],
        'time' => ['color' => '#e88c00', 'action' => 'Disiplin waktu', 'recommendation' => 'Tinjau ketepatan jadwal dan pemanfaatan durasi perkuliahan serta sepakati langkah perbaikan bersama dosen.'],
        'english' => ['color' => '#319b98', 'action' => 'Penggunaan bahasa Inggris', 'recommendation' => 'Dampingi penyusunan bahan ajar dan instruksi berbahasa Inggris, kemudian lakukan review materi.'],
        'structure' => ['color' => '#db2777', 'action' => 'Pembukaan dan penutupan pembelajaran', 'recommendation' => 'Perkuat penyampaian tujuan, rangkuman, refleksi, dan informasi lanjutan pada setiap pertemuan.'],
        'references' => ['color' => '#4d8b41', 'action' => 'Penguatan referensi dan penerapan', 'recommendation' => 'Tinjau acuan materi dan tambahkan referensi relevan, studi kasus, serta contoh penerapan.'],
        'online' => ['color' => '#5365b3', 'action' => 'Penguatan aktivitas daring', 'recommendation' => 'Dampingi pemanfaatan forum diskusi, interaksi daring, dan evaluasi pembelajaran sesuai kebutuhan mata kuliah.'],
    ];
    $clusters = $commonFindings->values()->map(fn (array $category): array => [
        'title' => $category['title'],
        'count' => $category['lecturer_count'],
        'color' => $categoryPresentation[$category['key']]['color'],
        'action' => $categoryPresentation[$category['key']]['action'],
        'recommendation' => $categoryPresentation[$category['key']]['recommendation'],
    ]);
    $totalCategoryOccurrences = $clusters->sum('count');
    $chartCircumference = 2 * pi() * 38;
    $chartOffset = 0;
@endphp

<div class="report-print-sheet contents">
<div id="executive-report" x-data="{ rankingLimit: 5, rankingStageTop: {{ $hasStageTwoData ? 2 : 1 }}, rankingStageBottom: {{ $hasStageTwoData ? 2 : 1 }} }" class="executive-report flex flex-col gap-8 max-w-7xl mx-auto pb-12">

    <div class="report-first-page contents">

    <!-- 1. TOP HEADER & FILTER TOOLBAR (Clean Borderless Executive Header) -->
    <section class="report-intro rounded-2xl bg-white p-4 shadow-sm sm:p-5">
        <div class="space-y-4">
            <!-- Left: Title & Institutional Identity -->
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="inline-flex items-center rounded-full bg-rose-50 px-2.5 py-1 text-xs font-bold text-[#7B1113]">
                        Lembaga Penjaminan Mutu (LPM)
                    </span>
                    <span class="text-xs font-semibold tracking-wide text-slate-500">
                        UNIVERSITAS TEKNOKRAT INDONESIA
                    </span>
                </div>

                <h1 class="mt-3 text-xl font-extrabold leading-tight tracking-tight text-slate-900 sm:text-2xl">
                    Laporan Hasil Supervisi Pembelajaran
                </h1>

                <p class="mt-1 text-sm font-medium text-slate-600">{{ $reportLabel }}</p>
                <p class="mt-2 hidden text-xs text-slate-500 print:block">Meningkatkan Kualitas Pembelajaran, Mewujudkan UTI Disiplin, Bermutu, Kreatif, Inovatif</p>
            </div>

            <div class="flex min-w-0 flex-col gap-2 print:hidden lg:flex-row lg:items-end">
                <form method="GET" action="{{ route('lpm.reports.supervision') }}" class="filter-controls w-full flex-1" style="--filter-columns: minmax(0,1.6fr) minmax(0,1fr)">
                    <label class="filter-field">
                        <span class="sr-only">Semester Supervisi</span>
                        <select name="period_id" @disabled($periods->isEmpty()) onchange="this.form.submit()" class="filter-control">
                            @forelse($periods as $availablePeriod)
                                <option value="{{ $availablePeriod->id }}" @selected($selectedPeriod?->id === $availablePeriod->id)>{{ $availablePeriod->academicTerm?->semester_label ?? 'Semester Supervisi' }} {{ $availablePeriod->academicTerm?->academic_year ?? '-' }}</option>
                            @empty
                                <option>Belum ada semester supervisi</option>
                            @endforelse
                        </select>
                    </label>
                    <label class="filter-field">
                        <span class="sr-only">Tahap Supervisi</span>
                        <select name="stage" @disabled(!$selectedPeriod) onchange="this.form.submit()" class="filter-control">
                            <option value="all" @selected($selectedStage === 'all')>Tahap 1 dan Tahap 2</option>
                            <option value="1" @selected($selectedStage === '1')>Tahap 1</option>
                            <option value="2" @selected($selectedStage === '2')>Tahap 2</option>
                        </select>
                    </label>
                </form>

                <button
                    type="button"
                    onclick="window.printExecutiveReport()"
                    title="Ekspor laporan sebagai PDF atau cetak"
                    class="filter-button self-start lg:self-auto"
                >
                    <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path></svg>
                    Ekspor PDF / Cetak
                </button>
            </div>
        </div>
    </section>

    @if(!$selectedPeriod || !$hasSubmissions)
        <section class="rounded-2xl border border-slate-200 bg-white px-6 py-10 text-center shadow-sm">
            <h2 class="text-lg font-bold text-slate-900">Belum ada hasil supervisi</h2>
            <p class="mt-2 text-sm text-slate-600">Belum ada hasil yang dikirim untuk {{ $reportLabel }}. Pilih periode lain atau tunggu supervisor mengirim hasilnya.</p>
        </section>
    </div>
    @else
    <!-- 2. EXECUTIVE KPI SUMMARY METRICS (4 Balanced Modern Stat Cards) -->
    <section class="report-kpis grid grid-cols-1 sm:grid-cols-2 {{ $reportScope === 'semester' ? 'lg:grid-cols-4' : 'lg:grid-cols-3' }} gap-3">
        <!-- Metric 1: Total Dosen -->
        <div class="flex min-h-36 flex-col rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
            <div class="flex items-center justify-between">
                <span class="text-sm font-semibold text-slate-700">Dosen sasaran</span>
                @if($reportScope === 'semester')
                    <span class="w-8 h-8 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center shrink-0">
                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path d="M9 6a3 3 0 11-6 0 3 3 0 016 0zM17 6a3 3 0 11-6 0 3 3 0 016 0zM12.93 17c.046-.327.07-.66.07-1a6.97 6.97 0 00-1.5-4.33A5 5 0 0119 16v1h-6.07zM6 11a5 5 0 015 5v1H1v-1a5 5 0 015-5z"/></svg>
                    </span>
                @endif
            </div>
            <div class="flex flex-1 flex-col justify-center">
                <div class="text-3xl font-extrabold text-slate-900 leading-none sm:text-4xl">
                    {{ $summary['total_supervised'] }}
                </div>
                <p class="mt-2 text-sm leading-snug text-slate-500">Dosen dalam periode laporan ini</p>
            </div>
        </div>

        @if($reportScope !== 'semester')
            <div class="flex min-h-36 flex-col rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
                <p class="text-sm font-semibold text-slate-700">Hasil terkirim</p>
                <div class="flex flex-1 flex-col justify-center">
                    <p class="text-3xl font-extrabold leading-none text-slate-900 sm:text-4xl">{{ $summary['submitted_count'] }}</p>
                    <p class="mt-2 text-sm leading-snug text-slate-500">Hasil supervisi yang sudah dikirim</p>
                </div>
            </div>
            <div class="flex min-h-36 flex-col rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
                <p class="text-sm font-semibold text-slate-700">Rata-rata skor</p>
                <div class="flex flex-1 flex-col justify-center">
                    <p class="text-3xl font-extrabold leading-none text-slate-900 sm:text-4xl">{{ number_format($summary['average_score'], 2) }}</p>
                    <p class="mt-2 text-sm leading-snug text-slate-500">Dari skor maksimal 4,00</p>
                </div>
            </div>
        @else
        <!-- Metric 2: Rata-rata T1 -->
        <div class="flex min-h-36 flex-col rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
            <div class="flex items-center justify-between">
                <span class="text-sm font-semibold text-slate-700">Rata-rata Tahap 1</span>
                <span class="w-8 h-8 rounded-xl bg-slate-100 text-slate-600 flex items-center justify-center shrink-0 font-bold text-xs">
                    T1
                </span>
            </div>
            <div class="flex flex-1 flex-col justify-center">
                <div class="{{ $hasStageOneData ? 'text-3xl font-extrabold text-slate-900' : 'text-base font-semibold text-slate-500' }} leading-tight">
                    {{ $hasStageOneData ? number_format($summary['avg_t1'], 2) : 'Belum tersedia' }}
                </div>
                <p class="mt-2 text-sm leading-snug text-slate-500">Dari skor maksimal 4,00</p>
            </div>
        </div>

        <!-- Metric 3: Rata-rata T2 -->
        <div class="flex min-h-36 flex-col rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
            <div class="flex items-center justify-between">
                <span class="text-sm font-semibold text-slate-700">Rata-rata Tahap 2</span>
                <span class="w-8 h-8 rounded-xl bg-blue-50 text-blue-700 flex items-center justify-center shrink-0 font-bold text-xs">
                    T2
                </span>
            </div>
            <div class="flex flex-1 flex-col justify-center">
                <div class="{{ $hasStageTwoData ? 'text-3xl font-extrabold text-slate-900' : 'text-base font-semibold text-slate-500' }} leading-tight">
                    {{ $hasStageTwoData ? number_format($summary['avg_t2'], 2) : 'Belum tersedia' }}
                </div>
                <p class="mt-2 text-sm leading-snug text-slate-500">{{ $hasStageTwoData ? 'Dari skor maksimal 4,00' : 'Belum ada hasil yang dikirim' }}</p>
            </div>
        </div>

        <!-- Metric 4: Rata-rata Peningkatan -->
        <div class="flex min-h-36 flex-col rounded-2xl border border-emerald-100 bg-gradient-to-br from-white to-emerald-50/40 p-4 shadow-sm sm:p-5">
            <div class="flex items-center justify-between">
                <span class="text-sm font-semibold text-emerald-800">Peningkatan</span>
                <span class="w-8 h-8 rounded-xl bg-emerald-100 text-emerald-700 flex items-center justify-center shrink-0">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
                </span>
            </div>
            <div class="flex flex-1 flex-col justify-center">
                <div class="{{ $hasComparisonData ? 'text-3xl font-extrabold text-emerald-700' : 'text-base font-semibold text-slate-500' }} leading-tight">
                    @if($hasComparisonData)
                        {{ $summary['delta_avg'] >= 0 ? '+' : '' }}{{ number_format($summary['delta_avg'], 2) }}
                    @else
                        Belum tersedia
                    @endif
                </div>
                <span class="mt-2 block text-sm leading-snug text-emerald-700">
                    {{ $hasComparisonData ? 'Selisih rata-rata skor' : 'Menunggu hasil Tahap 2' }}
                </span>
            </div>
        </div>
        @endif
    </section>

    <!-- 3. THREE RANKING TABLES (BORDERLESS CLEAN CARDS WITH INDEPENDENT STAGE FILTERS) -->
    <section class="report-rankings grid grid-cols-1 {{ $reportScope === 'semester' ? 'lg:grid-cols-3' : 'lg:grid-cols-2' }} gap-6 items-stretch">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between {{ $reportScope === 'semester' ? 'lg:col-span-3' : 'lg:col-span-2' }}">
            <div>
                <h2 class="text-lg font-bold text-slate-900">Peringkat dosen</h2>
                <p class="mt-1 text-sm text-slate-600">Dosen dengan skor tertinggi dan terendah pada periode yang dipilih.</p>
            </div>
            <div class="inline-flex h-11 w-fit items-center rounded-xl border border-slate-200 bg-white p-1 shadow-sm print:hidden" role="group" aria-label="Jumlah dosen dalam peringkat">
                <button type="button" @click="rankingLimit = 5" :class="rankingLimit === 5 ? 'bg-[#7B1113] text-white' : 'text-slate-600 hover:bg-slate-50'" class="flex h-9 items-center rounded-lg px-4 text-sm font-semibold transition-colors">5 dosen</button>
                <button type="button" @click="rankingLimit = 10" :class="rankingLimit === 10 ? 'bg-[#7B1113] text-white' : 'text-slate-600 hover:bg-slate-50'" class="flex h-9 items-center rounded-lg px-4 text-sm font-semibold transition-colors">10 dosen</button>
            </div>
        </div>

        <!-- CARD 1: DOSEN TERBAIK -->
        <div class="bg-white rounded-2xl shadow-sm flex flex-col overflow-hidden">
            <!-- Card Header -->
            <div class="px-5 py-3.5 bg-slate-50/70 flex items-center justify-between gap-2">
                <div class="flex items-center gap-2.5 min-w-0">
                    <span class="w-7 h-7 rounded-lg bg-blue-100 text-blue-700 flex items-center justify-center shrink-0">
                        <svg class="w-4 h-4 text-blue-700" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                    </span>
                    @if($reportScope === 'semester')
                        <h2 class="truncate text-sm font-bold text-slate-900" x-text="rankingLimit + ' Dosen Terbaik Tahap ' + rankingStageTop">10 Dosen Terbaik Tahap {{ $hasStageTwoData ? 2 : 1 }}</h2>
                    @else
                        <h2 class="truncate text-sm font-bold text-slate-900" x-text="rankingLimit + ' Dosen Terbaik Tahap {{ $selectedStage }}'">10 Dosen Terbaik Tahap {{ $selectedStage }}</h2>
                    @endif
                </div>

                <!-- Stage Filter Toggle (Independent) -->
                @if($hasStageOneData && $hasStageTwoData && $reportScope === 'semester')
                <div class="inline-flex p-0.5 rounded-lg bg-slate-200/70 shrink-0">
                    <button 
                        type="button" 
                        @click="rankingStageTop = 1" 
                        :class="rankingStageTop === 1 ? 'bg-white text-blue-700 font-bold shadow-xs' : 'text-slate-600 hover:text-slate-900 font-semibold'"
                        class="px-2.5 py-1 text-xs rounded-md transition-all cursor-pointer"
                    >
                        Tahap 1
                    </button>
                    @if($hasStageTwoData)
                        <button 
                            type="button" 
                            @click="rankingStageTop = 2" 
                            :class="rankingStageTop === 2 ? 'bg-white text-blue-700 font-bold shadow-xs' : 'text-slate-600 hover:text-slate-900 font-semibold'"
                            class="px-2.5 py-1 text-xs rounded-md transition-all cursor-pointer"
                        >
                            Tahap 2
                        </button>
                    @endif
                </div>
                @endif
            </div>

            <!-- Table -->
            <div class="flex-1 overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="bg-slate-50/40 text-[11px] font-bold text-slate-400 uppercase tracking-wider">
                            <th class="py-2.5 px-3 text-center w-12">#</th>
                            <th class="py-2.5 px-3">Nama Dosen</th>
                            @if($reportScope === 'semester')
                                <th class="py-2.5 px-3 text-center w-24" x-text="'Skor T' + rankingStageTop">Skor T2</th>
                            @else
                                <th class="py-2.5 px-3 text-center w-24">Skor</th>
                            @endif
                        </tr>
                    </thead>

                    <!-- Tahap 2 Data (Default) -->
                    <tbody class="text-xs sm:text-sm" x-show="rankingStageTop === 2" @if(!$hasStageTwoData) style="display: none;" @endif>
                        @foreach($top10T2 as $item)
                            <tr 
                                class="hover:bg-slate-50/60 transition-colors"
                                x-show="rankingLimit >= {{ $loop->iteration }}"
                                @if($loop->iteration > 5) style="display: none;" @endif
                            >
                                <td class="py-3 px-3 text-center">
                                    <div class="w-6 h-6 min-w-[24px] max-w-[24px] aspect-square rounded-full bg-slate-100 text-slate-600 font-bold text-xs flex items-center justify-center shrink-0 mx-auto">
                                        {{ $item['rank'] }}
                                    </div>
                                </td>
                                <td class="py-3 px-3 font-semibold text-slate-800 leading-snug">
                                    {{ $item['name'] }}
                                </td>
                                <td class="py-3 px-3 text-center font-mono font-bold text-sm text-blue-700">
                                    <span class="inline-block px-2.5 py-0.5 rounded-md bg-blue-50 text-blue-700">
                                        {{ number_format((float) $item['score'], 2) }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>

                    <!-- Tahap 1 Data -->
                    <tbody class="text-xs sm:text-sm" x-show="rankingStageTop === 1" style="display: none;">
                        @foreach($top10T1 as $item)
                            <tr 
                                class="hover:bg-slate-50/60 transition-colors"
                                x-show="rankingLimit >= {{ $loop->iteration }}"
                                @if($loop->iteration > 5) style="display: none;" @endif
                            >
                                <td class="py-3 px-3 text-center">
                                    <div class="w-6 h-6 min-w-[24px] max-w-[24px] aspect-square rounded-full bg-slate-100 text-slate-600 font-bold text-xs flex items-center justify-center shrink-0 mx-auto">
                                        {{ $item['rank'] }}
                                    </div>
                                </td>
                                <td class="py-3 px-3 font-semibold text-slate-800 leading-snug">
                                    {{ $item['name'] }}
                                </td>
                                <td class="py-3 px-3 text-center font-mono font-bold text-sm text-blue-700">
                                    <span class="inline-block px-2.5 py-0.5 rounded-md bg-blue-50 text-blue-700">
                                        {{ number_format((float) $item['score'], 2) }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <!-- Card Footer -->
            <div class="px-5 py-3 bg-slate-50/60 flex items-center justify-between text-xs text-slate-500 mt-auto">
                <span>Skala skor: 0,00 sampai 4,00</span>
                <span class="text-slate-400 text-[11px]" x-text="'Menampilkan ' + rankingLimit + ' dosen'"></span>
            </div>
        </div>

        <!-- CARD 2: DOSEN TERENDAH -->
        <div class="bg-white rounded-2xl shadow-sm flex flex-col overflow-hidden">
            <!-- Card Header -->
            <div class="px-5 py-3.5 bg-slate-50/70 flex items-center justify-between gap-2">
                <div class="flex items-center gap-2.5 min-w-0">
                    <span class="w-7 h-7 rounded-lg bg-rose-100 text-rose-700 flex items-center justify-center shrink-0">
                        <svg class="w-4 h-4 text-rose-700" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                    </span>
                    @if($reportScope === 'semester')
                        <h2 class="truncate text-sm font-bold text-slate-900" x-text="rankingLimit + ' Dosen Terendah Tahap ' + rankingStageBottom">10 Dosen Terendah Tahap {{ $hasStageTwoData ? 2 : 1 }}</h2>
                    @else
                        <h2 class="truncate text-sm font-bold text-slate-900" x-text="rankingLimit + ' Dosen Terendah Tahap {{ $selectedStage }}'">10 Dosen Terendah Tahap {{ $selectedStage }}</h2>
                    @endif
                </div>

                <!-- Stage Filter Toggle (Independent) -->
                @if($hasStageOneData && $hasStageTwoData && $reportScope === 'semester')
                <div class="inline-flex p-0.5 rounded-lg bg-slate-200/70 shrink-0">
                    <button 
                        type="button" 
                        @click="rankingStageBottom = 1" 
                        :class="rankingStageBottom === 1 ? 'bg-white text-rose-700 font-bold shadow-xs' : 'text-slate-600 hover:text-slate-900 font-semibold'"
                        class="px-2.5 py-1 text-xs rounded-md transition-all cursor-pointer"
                    >
                        Tahap 1
                    </button>
                    @if($hasStageTwoData)
                        <button 
                            type="button" 
                            @click="rankingStageBottom = 2" 
                            :class="rankingStageBottom === 2 ? 'bg-white text-rose-700 font-bold shadow-xs' : 'text-slate-600 hover:text-slate-900 font-semibold'"
                            class="px-2.5 py-1 text-xs rounded-md transition-all cursor-pointer"
                        >
                            Tahap 2
                        </button>
                    @endif
                </div>
                @endif
            </div>

            <!-- Table -->
            <div class="flex-1 overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="bg-slate-50/40 text-[11px] font-bold text-slate-400 uppercase tracking-wider">
                            <th class="py-2.5 px-3 text-center w-12">#</th>
                            <th class="py-2.5 px-3">Nama Dosen</th>
                            @if($reportScope === 'semester')
                                <th class="py-2.5 px-3 text-center w-24" x-text="'Skor T' + rankingStageBottom">Skor T2</th>
                            @else
                                <th class="py-2.5 px-3 text-center w-24">Skor</th>
                            @endif
                        </tr>
                    </thead>

                    <!-- Tahap 2 Data (Default) -->
                    <tbody class="text-xs sm:text-sm" x-show="rankingStageBottom === 2" @if(!$hasStageTwoData) style="display: none;" @endif>
                        @foreach($bottom10T2 as $item)
                            <tr 
                                class="hover:bg-slate-50/60 transition-colors"
                                x-show="rankingLimit >= {{ $loop->iteration }}"
                                @if($loop->iteration > 5) style="display: none;" @endif
                            >
                                <td class="py-3 px-3 text-center">
                                    <div class="w-6 h-6 min-w-[24px] max-w-[24px] aspect-square rounded-full bg-slate-100 text-slate-600 font-bold text-xs flex items-center justify-center shrink-0 mx-auto">
                                        {{ $item['rank'] }}
                                    </div>
                                </td>
                                <td class="py-3 px-3 font-semibold text-slate-800 leading-snug">
                                    {{ $item['name'] }}
                                </td>
                                <td class="py-3 px-3 text-center font-mono font-bold text-sm text-rose-700">
                                    <span class="inline-block px-2.5 py-0.5 rounded-md bg-rose-50 text-rose-700">
                                        {{ number_format((float) $item['score'], 2) }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>

                    <!-- Tahap 1 Data -->
                    <tbody class="text-xs sm:text-sm" x-show="rankingStageBottom === 1" style="display: none;">
                        @foreach($bottom10T1 as $item)
                            <tr 
                                class="hover:bg-slate-50/60 transition-colors"
                                x-show="rankingLimit >= {{ $loop->iteration }}"
                                @if($loop->iteration > 5) style="display: none;" @endif
                            >
                                <td class="py-3 px-3 text-center">
                                    <div class="w-6 h-6 min-w-[24px] max-w-[24px] aspect-square rounded-full bg-slate-100 text-slate-600 font-bold text-xs flex items-center justify-center shrink-0 mx-auto">
                                        {{ $item['rank'] }}
                                    </div>
                                </td>
                                <td class="py-3 px-3 font-semibold text-slate-800 leading-snug">
                                    {{ $item['name'] }}
                                </td>
                                <td class="py-3 px-3 text-center font-mono font-bold text-sm text-rose-700">
                                    <span class="inline-block px-2.5 py-0.5 rounded-md bg-rose-50 text-rose-700">
                                        {{ number_format((float) $item['score'], 2) }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <!-- Card Footer -->
            <div class="px-5 py-3 bg-slate-50/60 flex items-center justify-between text-xs text-slate-500 mt-auto">
                <span class="text-rose-700 font-medium">Prioritas Pembinaan</span>
                <span class="text-slate-400 text-[11px]" x-text="'Menampilkan ' + rankingLimit + ' dosen'"></span>
            </div>
        </div>

        @if($reportScope === 'semester')
        <!-- CARD 3: PENINGKATAN TERBESAR -->
        <div class="bg-white rounded-2xl shadow-sm flex flex-col overflow-hidden">
            <!-- Card Header -->
            <div class="px-5 py-3.5 bg-slate-50/70 flex items-center justify-between gap-2">
                <div class="flex items-center gap-2.5 min-w-0">
                    <span class="w-7 h-7 rounded-lg bg-emerald-100 text-emerald-700 flex items-center justify-center shrink-0">
                        <svg class="w-4 h-4 text-emerald-700" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
                    </span>
                    <h2 class="text-sm font-bold text-slate-900 truncate" x-text="rankingLimit + ' Peningkatan Terbesar (T1 → T2)'">
                        10 Peningkatan Terbesar (T1 → T2)
                    </h2>
                </div>
                <span class="inline-flex items-center px-2 py-0.5 rounded-md bg-emerald-50 text-emerald-700 font-bold text-xs shrink-0">
                    T1 → T2
                </span>
            </div>

            <!-- Table -->
            @if($hasComparisonData)
            <div class="flex-1 overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="bg-slate-50/40 text-[11px] font-bold text-slate-400 uppercase tracking-wider">
                            <th class="py-2.5 px-2 text-center w-10">#</th>
                            <th class="py-2.5 px-2">Nama Dosen</th>
                            <th class="py-2.5 px-1.5 text-center w-12">T1</th>
                            <th class="py-2.5 px-1.5 text-center w-12">T2</th>
                            <th class="py-2.5 px-2 text-center w-14 text-emerald-700">+IPK</th>
                            <th class="py-2.5 px-2 text-center w-12">%</th>
                        </tr>
                    </thead>
                    <tbody class="text-xs sm:text-sm">
                        @foreach($topImprovement as $item)
                            <tr 
                                class="hover:bg-slate-50/60 transition-colors"
                                x-show="rankingLimit >= {{ $loop->iteration }}"
                                @if($loop->iteration > 5) style="display: none;" @endif
                            >
                                <td class="py-3 px-2 text-center">
                                    <div class="w-6 h-6 min-w-[24px] max-w-[24px] aspect-square rounded-full bg-slate-100 text-slate-600 font-bold text-xs flex items-center justify-center shrink-0 mx-auto">
                                        {{ $item['rank'] }}
                                    </div>
                                </td>
                                <td class="py-3 px-2 font-semibold text-slate-800 leading-snug">
                                    {{ $item['name'] }}
                                </td>
                                <td class="py-3 px-1.5 text-center font-mono text-xs text-slate-500">
                                    {{ number_format((float) $item['t1'], 2) }}
                                </td>
                                <td class="py-3 px-1.5 text-center font-mono font-bold text-xs text-slate-800">
                                    {{ number_format((float) $item['t2'], 2) }}
                                </td>
                                <td class="py-3 px-2 text-center font-mono font-bold text-xs text-emerald-700">
                                    <span class="inline-block px-1.5 py-0.5 rounded-md bg-emerald-50 text-emerald-700">
                                        +{{ number_format((float) $item['delta'], 2) }}
                                    </span>
                                </td>
                                <td class="py-3 px-2 text-center font-mono text-xs text-slate-600 font-semibold">
                                    {{ $item['pct'] }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @else
                <div class="flex min-h-64 flex-1 flex-col items-center justify-center px-6 text-center">
                    <span class="mb-3 inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-amber-50 font-black text-amber-700">T2</span>
                    <h3 class="text-sm font-bold text-slate-900">Tahap 2 belum terlaksana</h3>
                    <p class="mt-1 max-w-xs text-xs leading-relaxed text-slate-500">
                        Perbandingan dan peringkat peningkatan akan tersedia setelah hasil Tahap 2 dikirim.
                    </p>
                </div>
            @endif

            <!-- Card Footer -->
            <div class="px-5 py-3 bg-slate-50/60 flex items-center justify-between text-xs text-slate-500 mt-auto">
                <span class="text-emerald-700 font-medium">Evaluasi Kemajuan T1 → T2</span>
                <span class="text-slate-400 text-[11px]">
                    {{ $hasComparisonData ? 'Berdasarkan hasil terkirim' : 'Menunggu Tahap 2' }}
                </span>
            </div>
        </div>
        @endif

    </section>

    </div>

    @php
        $displayFindings = $commonFindings->values()->map(function (array $category, int $index): array {
            return [
                'key' => $category['key'],
                'number' => $index + 1,
                'title' => $category['title'],
                'count' => $category['lecturer_count'],
                'points' => $category['points'],
                'lecturer_list' => $category['lecturer_names'],
            ];
        })->all();
    @endphp

    <!-- 4. TEMUAN UMUM DALAM SUPERVISI (EXPANDED TO FULL WIDTH WITH REFINED TYPOGRAPHY) -->
    <section class="report-findings bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <!-- Section Header -->
        <div class="px-6 py-5 bg-slate-50/70 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <span class="w-9 h-9 rounded-xl bg-purple-100 text-purple-800 flex items-center justify-center shrink-0">
                    <svg class="w-5 h-5 text-purple-700" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                </span>
                <div>
                    <h2 class="text-lg sm:text-xl font-semibold text-slate-900 leading-tight">
                        {{ count($displayFindings) > 0 ? count($displayFindings).' ' : '' }}Temuan Umum dalam Supervisi
                    </h2>
                    <p class="text-sm font-normal text-slate-500 mt-0.5">Perbaikan dan saran berdasarkan catatan hasil observasi supervisor</p>
                </div>
            </div>
            <span class="inline-flex items-center px-3.5 py-1.5 rounded-full text-xs font-medium bg-purple-50 text-purple-800 self-start sm:self-auto">
                Evaluasi Mutu Perkuliahan
            </span>
        </div>

        <!-- All common findings use the same card layout -->
        <div class="p-5 sm:p-6 bg-slate-100/70 space-y-5">
            
            @if($displayFindings === [])
                <p class="rounded-xl bg-white p-5 text-sm text-slate-600">Belum ada temuan perbaikan atau saran dalam kategori yang sama pada minimal dua dosen berbeda untuk pilihan ini.</p>
            @else
            <div class="report-findings-grid grid grid-cols-1 md:grid-cols-2 auto-rows-fr gap-5 items-stretch">
                @foreach($displayFindings as $f)
                    <div x-data="{ showLecturers: false }" class="report-finding bg-white rounded-2xl border border-slate-300 p-5 sm:p-6 shadow-sm flex flex-col justify-between space-y-4">
                        <!-- Card Header -->
                        <div class="flex items-center justify-between gap-3 pb-3 border-b border-slate-100">
                            <div class="flex items-center gap-3">
                                <span class="w-7 h-7 rounded-full bg-slate-100 text-slate-800 font-semibold text-sm flex items-center justify-center shrink-0">
                                    {{ $f['number'] }}
                                </span>
                                <h3 class="font-semibold text-base text-slate-900 leading-snug">
                                    {{ $f['title'] }}
                                </h3>
                            </div>
                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-slate-100 text-slate-700 shrink-0">
                                {{ $f['count'] }} Dosen
                            </span>
                        </div>

                        <!-- Card Points List -->
                        <div class="space-y-2.5 flex-1">
                            <h4 class="text-xs font-medium text-slate-500">
                                Catatan evaluasi:
                            </h4>
                            <ul class="space-y-2">
                                @foreach($f['points'] as $pt)
                                    <li class="flex items-start gap-2.5 text-sm font-normal text-slate-700 leading-relaxed">
                                        <span class="w-1.5 h-1.5 rounded-full bg-slate-400 mt-2 shrink-0"></span>
                                        <span>{{ $pt }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>

                        <!-- Card Lecturers Dropdown (Collapsible) -->
                        <div class="pt-3 border-t border-slate-100">
                            <button 
                                type="button" 
                                @click="showLecturers = !showLecturers"
                                :aria-expanded="showLecturers" aria-expanded="false" aria-controls="finding-lecturers-{{ $f['key'] }}"
                                class="w-full flex items-center justify-between py-2 px-3 rounded-xl bg-slate-50 hover:bg-slate-100 text-xs font-medium text-slate-800 transition-colors cursor-pointer group focus:outline-none focus:ring-2 focus:ring-purple-600 focus:ring-offset-2 print:hidden"
                            >
                                <span class="flex items-center gap-2">
                                    <span class="w-5 h-5 rounded-md bg-purple-100 text-purple-700 flex items-center justify-center shrink-0">
                                        <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path d="M9 6a3 3 0 11-6 0 3 3 0 016 0zM17 6a3 3 0 11-6 0 3 3 0 016 0zM12.93 17c.046-.327.07-.66.07-1a6.97 6.97 0 00-1.5-4.33A5 5 0 0119 16v1h-6.07zM6 11a5 5 0 015 5v1H1v-1a5 5 0 015-5z"/></svg>
                                    </span>
                                    <span>Daftar Dosen</span>
                                    <span class="text-[11px] font-medium text-slate-600 bg-white px-2 py-0.5 rounded-full border border-slate-200">
                                        {{ count($f['lecturer_list'] ?? []) }} Dosen
                                    </span>
                                </span>
                                <span class="flex items-center gap-1 text-xs text-purple-700 font-medium group-hover:text-purple-900">
                                    <span x-text="showLecturers ? 'Tutup' : 'Lihat'">Lihat</span>
                                    <svg class="w-3.5 h-3.5 transform transition-transform duration-200" :class="showLecturers ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                                </span>
                            </button>

                            <div x-show="showLecturers" style="display: none;" id="finding-lecturers-{{ $f['key'] }}" class="report-finding-lecturers mt-2.5 p-3 rounded-xl bg-slate-50 border border-slate-100">
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-1.5 max-h-56 overflow-y-auto pr-1">
                                    @foreach($f['lecturer_list'] as $idx => $lecturer)
                                        <div class="flex items-center gap-2.5 py-2 px-3 rounded-lg bg-white border border-slate-200/70 text-sm font-medium text-slate-700">
                                            <span class="w-6 h-6 rounded-full bg-slate-100 text-slate-500 font-medium text-xs flex items-center justify-center shrink-0">
                                                {{ $idx + 1 }}
                                            </span>
                                            <span class="truncate">{{ $lecturer }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            @endif
        </div>
    </section>

    @include('lpm.reports._strategic-summary')


    @endif

</div>

@include('lpm.reports._print-poster')

</div>

@endsection

@push('scripts')
<script>
    (() => {
        const report = document.getElementById('executive-report-poster');
        const sheet = report.closest('.report-print-sheet');
        const printStyles = document.getElementById('executive-report-print-styles');

        const fitReportToPage = () => {
            printStyles.media = 'all';
            report.style.setProperty('--report-print-scale', '1');
            report.style.removeProperty('--report-print-width');

            const availableWidth = sheet.clientWidth;
            const availableHeight = sheet.clientHeight - 2;

            if (availableWidth <= 0 || availableHeight <= 0) {
                return;
            }

            let lowerScale = 0;
            let upperScale = 1;

            for (let iteration = 0; iteration < 14; iteration++) {
                const scale = (lowerScale + upperScale) / 2;
                report.style.setProperty('--report-print-width', `${availableWidth / scale}px`);

                const contentHeight = Math.max(report.offsetHeight, report.scrollHeight);
                const contentWidth = Math.max(report.offsetWidth, report.scrollWidth);

                if (contentHeight * scale <= availableHeight && contentWidth * scale <= availableWidth + 1) {
                    lowerScale = scale;
                } else {
                    upperScale = scale;
                }
            }

            const scale = lowerScale || upperScale;
            report.style.setProperty('--report-print-width', `${availableWidth / scale}px`);
            report.style.setProperty('--report-print-scale', scale.toString());
        };

        const restoreReportLayout = () => {
            printStyles.media = 'print';
            report.style.removeProperty('--report-print-width');
            report.style.removeProperty('--report-print-scale');
        };

        window.printExecutiveReport = async () => {
            await document.fonts.ready;
            await Promise.all(Array.from(report.querySelectorAll('img'), (image) => image.decode().catch(() => {}))); 
            fitReportToPage();

            try {
                window.print();
            } catch (error) {
                restoreReportLayout();
                throw error;
            }
        };

        window.addEventListener('beforeprint', fitReportToPage);
        window.addEventListener('afterprint', restoreReportLayout);
        window.addEventListener('pagehide', restoreReportLayout);
        window.addEventListener('pageshow', restoreReportLayout);
    })();
</script>
@endpush
