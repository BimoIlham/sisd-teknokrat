@extends('layouts.app', ['title' => 'Detail Periode - ' . $period->name])

@section('content')
<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-2 mb-1">
                <a href="{{ route('lpm.periods.index') }}" class="text-xs text-[#7B1113] hover:underline flex items-center gap-1">
                    &larr; Kembali ke Daftar Periode
                </a>
            </div>
            <h1 class="text-2xl font-bold text-slate-900">{{ $period->name }}</h1>
            <p class="text-xs text-slate-500 mt-0.5">
                {{ $period->semester_display }} · Tahap 1 dan Tahap 2 | Rentang: {{ $period->start_date->format('d M Y') }} s/d {{ $period->end_date->format('d M Y') }}
            </p>
        </div>
    </div>

    <!-- Stages Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        @foreach($period->stages as $stg)
            <div class="bg-white rounded-xl p-5 border border-slate-200 shadow-sm">
                <div class="flex items-center justify-between mb-3">
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold {{ $stg->is_finalized ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' }}">
                        {{ $stg->is_finalized ? 'FINAL TERKUNCI' : 'DRAF PERENCANAAN' }}
                    </span>
                    <span class="text-xs font-semibold text-slate-400">Tahap ke-{{ $stg->stage_order }}</span>
                </div>
                <h3 class="text-base font-bold text-slate-900">{{ $stg->name }}</h3>
                <p class="text-xs text-slate-500 mt-1">
                    Rentang Waktu: <strong>{{ $stg->start_date->format('d M Y') }}</strong> s/d <strong>{{ $stg->end_date->format('d M Y') }}</strong>
                </p>
                <div class="mt-4 pt-3 border-t border-slate-100 text-xs text-slate-600">
                    Instrumen Terikat: 
                    <span class="font-semibold text-slate-800">
                        {{ $stg->instrument?->formTemplate?->name ?? 'Belum terikat' }}
                    </span>
                    <br>
                    Kebijakan Skor: 
                    <span class="font-semibold text-slate-800">
                        {{ $stg->instrument?->scoringPolicy?->name ?? 'Default' }}
                    </span>
                </div>
            </div>
        @endforeach
    </div>

    <!-- Supervisors Table -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden p-6">
        <h3 class="text-base font-bold text-slate-900 mb-4">Daftar Dosen Supervisor Periode Ini</h3>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead class="bg-slate-50 text-slate-500 uppercase font-semibold">
                    <tr>
                        <th class="px-3 py-2.5">Nama Supervisor</th>
                        <th class="px-3 py-2.5">NIDN</th>
                        <th class="px-3 py-2.5">Program Studi</th>
                        <th class="px-3 py-2.5 text-center">Batas Kuota</th>
                        <th class="px-3 py-2.5 text-center">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($supervisors as $sup)
                        <tr>
                            <td class="px-3 py-3 font-semibold text-slate-900">{{ $sup->lecturer->name }}</td>
                            <td class="px-3 py-3 text-slate-500">{{ $sup->lecturer->nidn ?? '-' }}</td>
                            <td class="px-3 py-3 text-slate-600">{{ $sup->lecturer->studyProgram?->name ?? '-' }}</td>
                            <td class="px-3 py-3 text-center font-bold text-slate-700">{{ $sup->target_quota ?? 'Fleksibel' }}</td>
                            <td class="px-3 py-3 text-center">
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold {{ $sup->is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600' }}">
                                    {{ $sup->is_active ? 'AKTIF' : 'NON-AKTIF' }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-3 py-6 text-center text-slate-400">Belum ada supervisor yang ditugaskan pada periode ini.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
