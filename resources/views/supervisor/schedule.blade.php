@extends('layouts.app', ['title' => 'Jadwal Terpadu Saya'])

@section('content')
<div class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="mb-1 flex flex-wrap items-center gap-2">
                <span class="inline-flex items-center rounded-full border border-rose-200 bg-rose-50 px-2.5 py-0.5 text-[11px] font-bold text-[#7B1113]">
                    Ruang Kerja Supervisor
                </span>
                <span class="inline-flex items-center gap-1.5 text-xs text-slate-500">
                    <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                    {{ $period ? 'Periode Aktif' : 'Belum Ada Periode' }}
                </span>
            </div>
            <h1 class="text-2xl font-bold tracking-tight text-slate-900">Jadwal Terpadu Mengajar & Supervisi</h1>
            <p class="mt-1 text-xs text-slate-500 sm:text-sm">
                Dosen: <strong class="text-slate-800">{{ $lecturer?->name ?? '-' }}</strong>
                <span class="mx-1 text-slate-300">•</span>
                Semester: <strong class="text-slate-800">{{ $period?->semester_display ?? 'Belum tersedia' }}</strong>
                <span class="text-slate-500">· Tahap 1 dan Tahap 2</span>
            </p>
        </div>

        @if($lecturer && $period && auth()->user()->isLpmStaff())
            <a href="{{ route('supervisor.schedule.pdf') }}" target="_blank" class="inline-flex items-center justify-center rounded-xl bg-[#7B1113] px-4 py-2.5 text-xs font-bold text-white shadow-xs transition-colors hover:bg-[#5E0C0F]">
                <svg class="mr-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                Unduh Jadwal Resmi (PDF)
            </a>
        @endif
    </div>

    <div x-data="{ scheduleView: 'grid' }" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
        <div class="flex flex-col gap-4 border-b border-slate-100 pb-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="text-base font-bold text-slate-900">Jadwal Perkuliahan Dosen</h2>
                    <span class="rounded-full bg-[#7B1113]/10 px-2.5 py-0.5 text-xs font-bold text-[#7B1113]">
                        {{ $teachingOccurrences->count() }} Pertemuan
                    </span>
                    <span class="inline-flex items-center gap-1.5 rounded-full border border-amber-300 bg-amber-100 px-2.5 py-0.5 text-xs font-bold text-amber-900">
                        {{ $supervisionSchedules->count() }} Jadwal Supervisi
                    </span>
                </div>
                <p class="mt-1 text-xs text-slate-500">Kisi jadwal mengajar dan tugas supervisi berdasarkan periode yang sedang berjalan.</p>
            </div>

            <div class="flex shrink-0 items-center rounded-xl bg-slate-100 p-1">
                <button type="button" @click="scheduleView = 'grid'" :class="scheduleView === 'grid' ? 'bg-white text-[#7B1113] shadow-sm font-bold' : 'text-slate-600 hover:text-slate-900 font-medium'" class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs transition-all">
                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path></svg>
                    Kisi Matriks
                </button>
                <button type="button" @click="scheduleView = 'list'" :class="scheduleView === 'list' ? 'bg-white text-[#7B1113] shadow-sm font-bold' : 'text-slate-600 hover:text-slate-900 font-medium'" class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs transition-all">
                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path></svg>
                    Daftar Rincian
                </button>
            </div>
        </div>

        <div x-show="scheduleView === 'grid'" class="mt-5">
            <div class="mb-3 rounded-xl border border-slate-200 bg-slate-50 p-4 text-center">
                <h3 class="text-xs font-extrabold uppercase tracking-wider text-slate-800">Jadwal Mengajar & Supervisi Mutu Perkuliahan</h3>
                <p class="mt-0.5 text-xs font-bold uppercase tracking-wide text-[#7B1113]">Universitas Teknokrat Indonesia</p>
                <p class="mt-0.5 text-[11px] font-semibold text-slate-500">{{ $period?->name ?? 'Semester Berjalan' }}</p>
                <div class="mt-2 inline-flex flex-wrap items-center justify-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1 text-xs font-bold text-slate-800 shadow-2xs">
                    <span class="font-normal text-slate-500">Nama Dosen:</span> {{ $lecturer?->name ?? '-' }}
                    <span class="ml-1 rounded border border-amber-300 bg-amber-100 px-1.5 py-0.5 text-[10px] font-bold text-amber-900">Supervisor</span>
                </div>
            </div>

            <div class="mb-3 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-slate-50 p-3 text-xs">
                <div class="flex flex-wrap items-center gap-4">
                    <span class="font-bold text-slate-700">Petunjuk Warna:</span>
                    <span class="flex items-center gap-2 font-medium text-slate-600"><span class="h-3.5 w-3.5 rounded border border-rose-300 bg-rose-100"></span> Jadwal Mengajar</span>
                    <span class="flex items-center gap-2 font-bold text-amber-900"><span class="h-3.5 w-3.5 rounded border border-amber-400 bg-amber-200"></span> Jadwal Supervisi</span>
                </div>
                @if($supervisionAssignments->isNotEmpty())
                    <span class="rounded-full border border-amber-300 bg-amber-100 px-2.5 py-0.5 text-[11px] font-semibold text-amber-900">Terplot {{ $supervisionSchedules->count() }} jadwal supervisi</span>
                @endif
            </div>

            <div class="overflow-x-auto rounded-xl border border-slate-300 shadow-xs">
                <table class="w-full min-w-[980px] border-collapse text-xs">
                    <thead>
                        <tr class="border-b border-slate-300 bg-slate-100 text-center font-bold text-slate-800">
                            <th class="w-24 border-r border-slate-300 bg-slate-200/70 px-3 py-2.5 text-[11px] uppercase tracking-wider">Waktu</th>
                            @foreach($days as $dayName)
                                <th class="w-[15%] border-r border-slate-300 px-3 py-2.5 text-[11px] uppercase tracking-wider last:border-r-0">{{ $dayName }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-300">
                        @forelse($timeSlots as $timeSlot)
                            <tr class="transition-colors hover:bg-slate-50/30">
                                <td class="border-r border-slate-300 bg-slate-100/60 px-3 py-3 text-center align-top font-mono text-xs font-bold text-slate-700">
                                    {{ $timeSlot }}
                                </td>
                                @foreach($days as $dayNumber => $dayName)
                                    @php
                                        $cell = $scheduleGrid[$timeSlot][$dayNumber] ?? null;
                                        $cellTeachings = is_array($cell) ? ($cell['teachings'] ?? collect()) : collect();
                                        $cellSupervisions = is_array($cell) ? ($cell['supervisions'] ?? collect()) : collect();
                                    @endphp
                                    <td class="min-h-[70px] border-r border-slate-300 p-1.5 align-top last:border-r-0">
                                        @foreach($cellTeachings as $schedule)
                                            <div class="mb-1 rounded-md bg-rose-100 p-2 last:mb-0">
                                                <div class="text-xs font-black leading-tight text-slate-900">{{ $schedule->class_name ?? 'Kelas' }}</div>
                                                <div class="mt-0.5 text-[11px] font-semibold leading-snug text-slate-700">{{ trim(str_ireplace(['OFFLINE', 'ONLINE', 'HYBRID'], '', $schedule->course?->name ?? $schedule->course_name ?? '-')) }}</div>
                                                <div class="mt-1 flex items-center justify-between text-[10px] text-slate-600">
                                                    <span class="font-mono font-bold">{{ $schedule->room_display }}</span>
                                                    <span class="font-bold uppercase">{{ $schedule->delivery_mode ?? 'OFFLINE' }}</span>
                                                </div>
                                            </div>
                                        @endforeach

                                        @foreach($cellSupervisions as $supervisionSchedule)
                                            <div class="mb-1 rounded-md bg-amber-200 p-2 last:mb-0">
                                                <div class="flex items-center justify-between gap-2">
                                                    <span class="text-xs font-black leading-tight text-slate-900">{{ $supervisionSchedule->class_name }}</span>
                                                    <span class="text-[9px] font-black uppercase tracking-wide text-amber-800">Supervisi</span>
                                                </div>
                                                <div class="mt-0.5 text-[11px] font-semibold leading-snug text-slate-700">{{ trim(str_ireplace(['OFFLINE', 'ONLINE', 'HYBRID'], '', $supervisionSchedule->course_name)) }}</div>
                                                <div class="mt-1 text-[10px] text-slate-700">
                                                    <span class="font-medium">Dosen:</span>
                                                    <span class="font-bold text-slate-900">{{ $supervisionSchedule->target_lecturer_name }}</span>
                                                </div>
                                                <div class="mt-1 flex items-center justify-between text-[10px] text-slate-600">
                                                    <span class="font-mono font-bold">{{ $supervisionSchedule->room_name }}</span>
                                                    <span class="font-bold uppercase">{{ $supervisionSchedule->delivery_mode }}</span>
                                                </div>
                                            </div>
                                        @endforeach
                                    </td>
                                @endforeach
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-4 py-10 text-center text-xs text-slate-400">Belum ada slot jadwal mengajar atau supervisi.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div x-show="scheduleView === 'list'" x-cloak class="mt-5 overflow-x-auto rounded-xl border border-slate-200">
            <table class="w-full min-w-[760px] text-left text-sm">
                <thead class="border-b border-slate-200 bg-slate-50 text-[10px] font-semibold uppercase text-slate-500">
                    <tr>
                        <th class="px-4 py-2.5">Tanggal & Waktu</th>
                        <th class="px-4 py-2.5">Aktivitas</th>
                        <th class="px-4 py-2.5">Mata Kuliah / Kelas</th>
                        <th class="px-4 py-2.5">Ruang / Moda</th>
                        <th class="px-4 py-2.5">Keterangan</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($sortedEvents as $event)
                        <tr class="{{ $event['is_supervision'] ? 'border-l-4 border-amber-400 bg-amber-50/70 hover:bg-amber-100/60' : 'hover:bg-slate-50/50' }} transition-colors">
                            <td class="px-4 py-3 text-xs font-semibold text-slate-800">{{ $event['date']->format('d M Y') }}<br><span class="font-mono font-normal text-slate-500">{{ substr($event['start_time'], 0, 5) }}–{{ substr($event['end_time'], 0, 5) }} WIB</span></td>
                            <td class="px-4 py-3"><span class="inline-flex rounded border px-2 py-0.5 text-[10px] font-bold {{ $event['is_supervision'] ? 'border-amber-300 bg-amber-200 text-amber-950' : 'border-slate-200 bg-slate-100 text-slate-700' }}">{{ $event['is_supervision'] ? 'Supervisi' : 'Mengajar' }}</span></td>
                            <td class="px-4 py-3 text-xs"><strong class="text-slate-900">{{ $event['course'] }}</strong><br><span class="text-slate-500">Kelas {{ $event['class'] }}</span></td>
                            <td class="px-4 py-3 text-xs text-slate-600">{{ $event['room'] ?? 'Daring' }}<br><span class="text-[10px] text-slate-400">{{ $event['mode'] }}</span></td>
                            <td class="px-4 py-3 text-xs">@if($event['is_supervision'])<span class="font-bold text-amber-900">Menilai: {{ $event['target'] }}</span><br><span class="text-[10px] text-amber-700">{{ $event['stage'] }}</span>@else<span class="italic text-slate-400">Perkuliahan mandiri</span>@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-8 text-center text-xs text-slate-400">Belum ada jadwal mengajar atau tugas supervisi.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
