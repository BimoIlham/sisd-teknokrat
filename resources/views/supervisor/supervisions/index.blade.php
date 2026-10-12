@extends('layouts.app', ['title' => 'Tugas Supervisi Saya'])

@section('content')
<div class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="mb-1 flex flex-wrap items-center gap-2">
                <span class="inline-flex items-center rounded-full border border-rose-200 bg-rose-50 px-2.5 py-0.5 text-[11px] font-bold text-[#7B1113]">Ruang Kerja Supervisor</span>
                <span class="text-xs text-slate-500">{{ $assignments->total() }} penugasan</span>
            </div>
            <h1 class="text-2xl font-bold tracking-tight text-slate-900">Daftar Tugas Supervisi</h1>
            <p class="mt-1 text-xs text-slate-500 sm:text-sm">Isi formulir pada rentang tanggal Tahap 1 atau Tahap 2 yang sedang dibuka.</p>
        </div>
        <a href="{{ route('supervisor.schedule') }}" class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs font-bold text-slate-700 shadow-2xs hover:bg-slate-50">Lihat Jadwal Saya</a>
    </div>

    <form method="GET" action="{{ route('supervisor.assignments.index') }}" class="filter-bar filter-controls" style="--filter-columns: minmax(0,1.7fr) minmax(0,1fr)">
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

    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xs">
        <div class="border-b border-slate-100 px-5 py-4 sm:px-6">
            <h2 class="text-base font-bold text-slate-900">Penugasan Aktif</h2>
            <p class="mt-0.5 text-xs text-slate-500">Formulir tersedia setelah jadwal difinalisasi dan hanya selama tanggal input tahap berlangsung.</p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[880px] text-left text-xs">
                <thead class="border-b border-slate-200 bg-slate-50 text-[10px] font-semibold uppercase tracking-wider text-slate-500">
                    <tr>
                        <th class="px-3 py-2.5">Dosen yang Disupervisi</th>
                        <th class="px-3 py-2.5">Tahap</th>
                        <th class="px-3 py-2.5">Mata Kuliah / Kelas</th>
                        <th class="px-3 py-2.5">Waktu & Ruang</th>
                        <th class="px-3 py-2.5 text-center">Status</th>
                        <th class="px-3 py-2.5 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($assignments as $assign)
                        @php($inputStatus = $assign->supervisionStage->supervisionInputStatus($inputDate))
                        <tr class="transition-colors hover:bg-slate-50/60">
                            <td class="px-3 py-3 font-semibold text-slate-900">
                                {{ $assign->targetLecturer->name }}<br>
                                <span class="text-[10px] text-slate-400">NIDN: {{ $assign->targetLecturer->nidn ?? '-' }}</span>
                            </td>
                            <td class="px-3 py-3 text-slate-500 font-medium">
                                {{ $assign->supervisionStage->name }}
                            </td>
                            <td class="px-3 py-3">
                                <strong class="text-slate-900">{{ $assign->teachingOccurrence?->teachingSchedule?->course?->name }}</strong><br>
                                <span class="text-slate-500">Kelas {{ $assign->teachingOccurrence?->teachingSchedule?->class_name }}</span>
                            </td>
                            <td class="px-3 py-3 text-slate-600">
                                @if($assign->teachingOccurrence)
                                    {{ $assign->teachingOccurrence->occurrence_date->format('d/m/Y') }}<br>
                                    <span class="text-slate-400">{{ substr($assign->teachingOccurrence->start_time_local, 0, 5) }}–{{ substr($assign->teachingOccurrence->end_time_local, 0, 5) }} • {{ $assign->teachingOccurrence->teachingSchedule->room_display }}</span>
                                @else
                                    -
                                @endif
                            </td>
                            <td class="px-3 py-3 text-center">
                                @if($assign->supervision && $assign->supervision->status === 'SUBMITTED')
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800">
                                        SELESAI (Skor: {{ number_format($assign->supervision->final_score, 2) }})
                                    </span>
                                @elseif($assign->supervision?->answers_exists)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 text-amber-800">
                                        Draf
                                    </span>
                                @else
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-100 text-slate-600">
                                        Belum Diisi
                                    </span>
                                @endif
                            </td>
                            <td class="px-3 py-3 text-right">
                                @if($assign->supervision && $assign->supervision->status === 'SUBMITTED')
                                    <a href="{{ route('supervisor.supervisions.show', $assign->supervision) }}" class="inline-flex items-center px-2.5 py-1.5 rounded-lg border border-slate-200 hover:bg-slate-100 text-xs font-semibold text-slate-700">
                                        Lihat Hasil
                                    </a>
                                @elseif($assign->status === 'FINAL' && $inputStatus === 'open')
                                    <a href="{{ route('supervisor.supervisions.form', $assign) }}" class="inline-flex items-center px-3 py-1.5 rounded-lg bg-[#7B1113] hover:bg-[#5E0C0F] text-white text-xs font-semibold shadow-xs">
                                        {{ $assign->supervision?->answers_exists ? 'Lanjutkan Penilaian' : 'Buka Formulir' }} &rarr;
                                    </a>
                                @elseif($assign->status === 'FINAL')
                                    <span class="inline-flex max-w-64 items-center rounded-lg bg-slate-100 px-3 py-1.5 text-left text-xs font-semibold text-slate-600">
                                        {{ $assign->supervisionStage->supervisionInputUnavailableMessage($inputDate) }}
                                    </span>
                                @else
                                    <span class="inline-flex items-center rounded-lg bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-500">Menunggu Finalisasi</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-3 py-6 text-center text-slate-400">
                                @if($hasActiveStageWindow)
                                    Belum ada penugasan supervisi pada tahap ini.
                                @else
                                    Tidak ada jadwal supervisi yang berlaku pada tanggal ini.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($assignments->hasPages())
            <div class="border-t border-slate-200 px-5 py-4">
                {{ $assignments->links('vendor.pagination.tailwind', ['label' => 'penugasan supervisi']) }}
            </div>
        @endif
    </div>
</div>
@endsection
