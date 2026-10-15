@extends('layouts.app', ['title' => 'Bank Pertanyaan Supervisi'])

@section('content')
<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Bank Pertanyaan Supervisi & Kebijakan Skor</h1>
            <p class="text-xs text-slate-500 mt-1">
                Kelola butir pertanyaan supervisi perkuliahan tatap muka (offline) dan daring (online) secara terpisah serta kebijakan penilaian LPM.
            </p>
        </div>
    </div>

    <!-- Alert register D01-D02 status -->
    <div class="p-4 rounded-xl bg-amber-50 border border-amber-200 text-amber-900 text-xs flex items-start gap-3 shadow-2xs">
        <svg class="w-5 h-5 text-amber-600 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
        <div>
            <span class="font-bold">Bank Pertanyaan Terpisah (Offline & Online):</span>
            <p class="mt-0.5 leading-relaxed">
                Pilih moda perkuliahan di bawah ini untuk mengelola butir pertanyaan, indikator penilaian, opsi jawaban, dan skala skor.
            </p>
        </div>
    </div>

    <!-- Separated Question Bank Modes Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        @foreach($templates as $tpl)
            @php $isOffline = $tpl->delivery_mode === 'OFFLINE'; @endphp
            <div class="bg-white rounded-2xl p-6 border border-slate-200 shadow-sm flex flex-col justify-between hover:border-slate-300 transition-all">
                <div>
                    <div class="flex items-center justify-between mb-3">
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold font-mono {{ $isOffline ? 'bg-blue-100 text-blue-800' : 'bg-purple-100 text-purple-800' }}">
                            {{ $isOffline ? '🏢 MODA OFFLINE' : '🌐 MODA ONLINE' }}
                        </span>
                    </div>

                    <h3 class="text-lg font-bold text-slate-900">
                        {{ $isOffline ? 'Bank Pertanyaan Tatap Muka (Offline)' : 'Bank Pertanyaan Daring (Online / SPADA)' }}
                    </h3>
                    <p class="text-xs text-slate-500 mt-1 leading-relaxed">{{ $tpl->description ?: ($isOffline ? 'Instrumen evaluasi dan indikator supervisi pelaksanaan perkuliahan tatap muka di ruang kelas.' : 'Instrumen evaluasi dan indikator supervisi pelaksanaan perkuliahan daring melalui SPADA / LMS.') }}</p>

                    <div class="grid grid-cols-2 gap-4 mt-6 pt-4 border-t border-slate-100 text-xs">
                        <div class="bg-slate-50 p-3 rounded-xl">
                            <span class="text-slate-400 text-[11px] uppercase font-semibold">Total Bagian:</span>
                            <p class="text-lg font-bold text-slate-800">{{ $tpl->sections_count }} Sesi</p>
                        </div>
                        <div class="bg-slate-50 p-3 rounded-xl">
                            <span class="text-slate-400 text-[11px] uppercase font-semibold">Total Pertanyaan:</span>
                            <p class="text-lg font-bold text-slate-800">{{ $tpl->questions_count }} Butir</p>
                        </div>
                    </div>
                </div>

                <div class="mt-6 pt-4 border-t border-slate-100 flex items-center justify-between">
                    <span class="text-[11px] text-slate-400 font-mono">Status: {{ $tpl->status }}</span>
                    <a 
                        href="{{ route('lpm.instruments.mode', strtolower($tpl->delivery_mode)) }}" 
                        class="inline-flex items-center px-4 py-2 rounded-xl bg-[#7B1113] hover:bg-[#600d0e] text-white text-xs font-semibold shadow-xs transition-colors"
                    >
                        Kelola Bank Soal {{ $tpl->delivery_mode }} &rarr;
                    </a>
                </div>
            </div>
        @endforeach
    </div>

    <!-- Scoring Policies Table -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden p-6">
        <h3 class="text-base font-bold text-slate-900 mb-4">Kebijakan Perhitungan Skor Terdaftar</h3>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead class="bg-slate-50 text-slate-500 uppercase font-semibold">
                    <tr>
                        <th class="px-3 py-2.5">Nama Kebijakan</th>
                        <th class="px-3 py-2.5">Metode Kalkulasi</th>
                        <th class="px-3 py-2.5 text-center">Rentang Skor</th>
                        <th class="px-3 py-2.5">Keterangan</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($policies as $pol)
                        <tr>
                            <td class="px-3 py-3 font-bold text-slate-900">{{ $pol->name }}</td>
                            <td class="px-3 py-3 font-semibold text-[#7B1113]">{{ $pol->human_formula }}</td>
                            <td class="px-3 py-3 text-center font-bold text-slate-800">{{ $pol->min_score }} s/d {{ $pol->max_score }}</td>
                            <td class="px-3 py-3 text-slate-600">{{ $pol->human_description }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
