@extends('layouts.app', ['title' => 'Hasil Supervisi - ' . $report['lecturer']?->name])

@section('content')
@php
    $performedAt = $report['performedAt'];
@endphp
<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-2 mb-1">
                <a href="{{ route('supervisor.assignments.index') }}" class="text-xs text-[#7B1113] hover:underline flex items-center gap-1 font-semibold">
                    &larr; Kembali ke Daftar Supervisi
                </a>
            </div>
            <h1 class="text-2xl font-bold tracking-tight text-slate-900">Hasil Supervisi Pembelajaran</h1>
            <p class="text-xs text-slate-500 mt-0.5">
                Dosen: <strong>{{ $report['lecturer']?->name }}</strong> | Disubmit pada: {{ $performedAt ? $performedAt->translatedFormat('d M Y H:i:s').' WIB' : 'Belum tercatat' }}
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @can('downloadPdf', $supervision)
            <a href="{{ route('lpm.supervisions.pdf', $supervision) }}" target="_blank" rel="noopener" class="inline-flex items-center gap-2 rounded-xl bg-[#7B1113] px-4 py-2 text-xs font-bold text-white shadow-sm transition-colors hover:bg-[#5A0E13]">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                Cetak PDF Nilai
            </a>
            @endcan
            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800 border border-emerald-300">
                ✓ Dokumen Resmi
            </span>
        </div>
    </div>

    <div class="flex flex-col justify-between gap-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm md:flex-row md:items-center">
        <div class="flex items-center gap-4">
            <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-[#7B1113] text-white shadow-xs">
                <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path></svg>
            </div>
            <div>
                <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Skor Akhir Supervisi</span>
                <div class="mt-0.5 text-4xl font-black tracking-tight text-slate-900 sm:text-5xl">
                    {{ $report['finalScore'] }}
                    <span class="text-lg font-normal text-slate-400">/ {{ $report['maximumScore'] }}</span>
                </div>
                <p class="mt-1 text-xs text-slate-500">Kebijakan Penilaian: <strong class="text-slate-700">{{ $report['policyName'] }}</strong></p>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-8 rounded-xl border border-slate-200 bg-slate-50 px-5 py-4 text-xs">
            <div>
                <span class="text-slate-500">Total Poin Mentah</span>
                <p class="mt-0.5 text-xl font-bold text-slate-900">{{ $report['rawScore'] }}</p>
            </div>
            <div>
                <span class="text-slate-500">Butir Dinilai</span>
                <p class="mt-0.5 text-xl font-bold text-slate-900">{{ $report['scoredCount'] }}</p>
            </div>
        </div>
    </div>

    <!-- Metadata Details -->
    <div class="bg-white rounded-2xl p-6 border border-slate-200/80 shadow-xs grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 text-xs">
        <div>
            <span class="text-slate-400">Dosen Yang Dinilai:</span>
            <p class="font-bold text-slate-800">{{ $report['lecturer']?->name }}</p>
            <p class="text-[11px] text-slate-500">NIDN: {{ $report['lecturer']?->nidn ?? '-' }}</p>
        </div>
        <div>
            <span class="text-slate-400">Dosen Supervisor:</span>
            <p class="font-bold text-slate-800">{{ $report['supervisor']?->name }}</p>
            <p class="text-[11px] text-slate-500">{{ $report['stage']?->name }}</p>
        </div>
        <div>
            <span class="text-slate-400">Mata Kuliah & Kelas:</span>
            <p class="font-bold text-slate-800">{{ $report['course']?->name }}</p>
            <p class="text-[11px] text-slate-500">Kelas {{ $report['schedule']?->class_name }}</p>
        </div>
        <div>
            <span class="text-slate-400">Pelaksanaan Supervisi:</span>
            <p class="font-bold text-slate-800">
                {{ $performedAt?->translatedFormat('d F Y') ?? 'Belum tercatat' }}
            </p>
            <p class="text-[11px] text-slate-500">
                @if($performedAt)
                    {{ $performedAt->format('H:i:s') }} WIB •
                @endif
                Ruang: {{ $report['schedule']?->room }}
            </p>
        </div>
    </div>

    <!-- Answers Breakdown by Section -->
    <div class="space-y-6">
        @if($report['legacySnapshot'])
            <p class="text-xs text-slate-500">Data legacy: snapshot instrumen historis belum lengkap. Redaksi menggunakan master yang tersedia saat laporan dibuat.</p>
        @endif

        @foreach($report['structuredSections'] as $sectionIndex => $sec)
            <div class="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
                <div class="bg-slate-50 p-4 border-b border-slate-200/80 flex items-center justify-between">
                    <div>
                        <span class="text-[10px] font-bold uppercase tracking-wider text-[#7B1113]">Bagian {{ $sectionIndex + 1 }}</span>
                        <h3 class="text-sm font-bold text-slate-900">{{ $sec['title'] }}</h3>
                    </div>
                </div>

                <div class="divide-y divide-slate-100 p-5 space-y-3">
                    @foreach($sec['questions'] as $questionIndex => $q)
                        <div class="pt-2 first:pt-0">
                            <div class="flex items-start justify-between gap-3">
                                <div class="text-xs text-slate-800">
                                    <span class="font-bold text-[#7B1113] mr-1">{{ $questionIndex + 1 }}.</span>
                                    {{ $q['text'] }}
                                </div>
                                <div class="text-right shrink-0">
                                    @if($q['is_scored'] && $q['score'] !== null)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800">
                                            Skor: {{ number_format($q['score'], 0) }}
                                        </span>
                                    @endif
                                </div>
                            </div>

                            <div class="mt-1 pl-5 text-xs text-slate-600 font-medium">
                                Jawaban: 
                                <span class="font-bold text-slate-900">
                                    @if(($q['evaluation_notes'] ?? null) !== null)
                                        @include('supervisor.supervisions._evaluation-notes', ['evaluationNotes' => $q['evaluation_notes']])
                                    @else
                                        {{ $q['answer_display'] }}
                                    @endif
                                </span>
                            </div>
                            @if($q['notes'])
                                <p class="mt-2 pl-5 whitespace-pre-line break-words text-xs text-slate-600"><strong>Catatan:</strong> {{ $q['notes'] }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>

    <!-- Uploaded Evidence List -->
    @if(count($report['evidence']) > 0)
        <div class="bg-white rounded-2xl border border-slate-200/80 shadow-xs p-6">
            <h3 class="text-sm font-bold text-slate-900 mb-3">Berkas Bukti Dukung Terverifikasi</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                @foreach($report['evidence'] as $ev)
                    <div class="flex items-center justify-between p-3 rounded-xl border border-slate-200 bg-slate-50/50 text-xs">
                        <span class="font-medium text-slate-800 truncate">{{ $ev['file_name'] }}</span>
                        <a href="{{ route('supervisor.supervisions.download_evidence', $ev['id']) }}" target="_blank" class="text-[#7B1113] font-semibold hover:underline shrink-0 ml-3">
                            Unduh
                        </a>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
@endsection
