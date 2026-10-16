@extends('layouts.app', ['title' => 'Dashboard Supervisor'])

@section('content')
<div class="space-y-6">
    <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
        <div>
            <div class="mb-1 flex flex-wrap items-center gap-2">
                <span class="inline-flex items-center rounded-full border border-rose-200 bg-rose-50 px-2.5 py-0.5 text-[11px] font-bold text-[#7B1113]">
                    Dosen Supervisor Pembelajaran
                </span>
                <span class="inline-flex items-center gap-1.5 text-xs text-stone-500">
                    <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                    Sistem Operasional Aktif
                </span>
            </div>
            <h1 class="text-2xl font-bold tracking-tight text-stone-900 sm:text-[28px]">Dashboard Supervisor</h1>
            <p class="mt-1 text-xs text-stone-500 sm:text-sm">
                Selamat datang, <strong class="text-stone-800">{{ $lecturer?->name ?? $user->name }}</strong>.
                Semester: <strong class="text-stone-800">{{ $activePeriod?->semester_display ?? 'Belum Ada Periode Aktif' }}</strong>
                <span class="text-stone-500">· Tahap 1 dan Tahap 2</span>
                @if($quota)<span class="mx-1 text-stone-300">•</span> Kuota: <strong class="text-stone-800">{{ $quota }} sesi</strong>@endif
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2.5">
            <a href="{{ route('supervisor.schedule') }}" class="inline-flex items-center rounded-xl border border-stone-200 bg-white px-3.5 py-2 text-xs font-bold text-stone-700 shadow-2xs transition hover:bg-stone-50">
                <svg class="mr-2 h-4 w-4 text-[#7B1113]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                Jadwal Saya
            </a>
            <a href="{{ route('supervisor.assignments.index') }}" class="inline-flex items-center rounded-xl bg-[#7B1113] px-4 py-2 text-xs font-bold text-white shadow-xs transition hover:bg-[#5E0C0F]">
                <svg class="mr-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path></svg>
                Tugas Supervisi
            </a>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3 sm:gap-5">
        <div class="relative overflow-hidden rounded-2xl border border-stone-200/80 bg-white p-5 shadow-xs">
            <div class="flex items-start justify-between">
                <div><span class="text-[11px] font-bold uppercase tracking-wider text-stone-400">Total Penugasan</span><div class="mt-1.5 text-3xl font-extrabold text-stone-900">{{ $myAssignments->count() }}</div></div>
                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-[#7B1113] text-white shadow-xs"><svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path></svg></div>
            </div>
            <p class="mt-2 text-[11px] text-stone-500">Sesi pada tahap yang tanggalnya sedang berlaku</p>
        </div>

        <div class="relative overflow-hidden rounded-2xl border border-stone-200/80 bg-white p-5 shadow-xs">
            <div class="flex items-start justify-between">
                <div><span class="text-[11px] font-bold uppercase tracking-wider text-stone-400">Sedang Dinilai</span><div class="mt-1.5 text-3xl font-extrabold text-stone-900">{{ $pendingCount }}</div></div>
                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-[#F59E0B] text-white shadow-xs"><svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg></div>
            </div>
            <p class="mt-2 text-[11px] font-medium text-amber-700">Draf atau belum mulai diisi</p>
        </div>

        <div class="relative overflow-hidden rounded-2xl border border-stone-200/80 bg-white p-5 shadow-xs">
            <div class="flex items-start justify-between">
                <div><span class="text-[11px] font-bold uppercase tracking-wider text-stone-400">Supervisi Selesai</span><div class="mt-1.5 text-3xl font-extrabold text-stone-900">{{ $submittedCount }}</div></div>
                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-[#10B981] text-white shadow-xs"><svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg></div>
            </div>
            <p class="mt-2 text-[11px] font-medium text-emerald-700">Dokumen telah difinalkan</p>
        </div>
    </div>

    <div class="overflow-hidden rounded-2xl border border-stone-200/80 bg-white shadow-xs">
        <div class="flex items-center justify-between border-b border-stone-100 px-5 py-4 sm:px-6">
            <div><h2 class="text-base font-bold text-stone-900">Penugasan Supervisi Terbaru</h2><p class="mt-0.5 text-xs text-stone-500">Daftar kelas dan dosen yang perlu Anda observasi.</p></div>
            <a href="{{ route('supervisor.assignments.index') }}" class="text-xs font-bold text-[#7B1113] hover:underline">Lihat semua →</a>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[850px] text-left text-xs">
                <thead class="border-b border-stone-200 bg-stone-50 text-[10px] font-semibold uppercase tracking-wider text-stone-500">
                    <tr><th class="px-5 py-3">Dosen yang Disupervisi</th><th class="px-4 py-3">Tahap</th><th class="px-4 py-3">Mata Kuliah / Kelas</th><th class="px-4 py-3">Waktu & Ruang</th><th class="px-4 py-3 text-center">Status</th><th class="px-5 py-3 text-center">Aksi</th></tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @forelse($myAssignments->take(8) as $assignment)
                        <tr class="transition-colors hover:bg-stone-50/60">
                            <td class="px-5 py-3 font-semibold text-stone-900">{{ $assignment->targetLecturer->name }}</td>
                            <td class="px-4 py-3 text-stone-600">{{ $assignment->supervisionStage->name }}</td>
                            <td class="px-4 py-3"><strong class="text-stone-900">{{ $assignment->teachingOccurrence?->teachingSchedule?->course?->name ?? '-' }}</strong><br><span class="text-stone-500">Kelas {{ $assignment->teachingOccurrence?->teachingSchedule?->class_name ?? '-' }}</span></td>
                            <td class="px-4 py-3 text-stone-600">@if($assignment->teachingOccurrence){{ $assignment->teachingOccurrence->occurrence_date->format('d/m/Y') }}<br><span class="text-stone-400">{{ substr($assignment->teachingOccurrence->start_time_local, 0, 5) }}–{{ substr($assignment->teachingOccurrence->end_time_local, 0, 5) }} • {{ $assignment->teachingOccurrence->teachingSchedule?->room_display ?? '-' }}</span>@else - @endif</td>
                            <td class="px-4 py-3 text-center">
                                @if($assignment->supervision?->status === 'SUBMITTED')
                                    <span class="inline-flex rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-bold text-emerald-800">Selesai</span>
                                @elseif($assignment->supervision?->answers_exists)
                                    <span class="inline-flex rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-bold text-amber-800">Draf</span>
                                @elseif($assignment->status === 'FINAL')
                                    <span class="inline-flex rounded-full bg-stone-100 px-2 py-0.5 text-[10px] font-bold text-stone-600">Belum Diisi</span>
                                @else
                                    <span class="inline-flex rounded-full bg-stone-100 px-2 py-0.5 text-[10px] font-bold text-stone-600">Belum Final</span>
                                @endif
                            </td>
                            <td class="px-5 py-3 text-center">
                                @if($assignment->supervision?->status === 'SUBMITTED')
                                    <a href="{{ route('supervisor.supervisions.show', $assignment->supervision) }}" aria-label="Lihat hasil supervisi {{ $assignment->targetLecturer->name }}" title="Lihat hasil supervisi {{ $assignment->targetLecturer->name }}" class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-stone-200 text-stone-700 transition-colors hover:bg-stone-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-stone-500">
                                        <svg aria-hidden="true" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.25 12s3.5-6.75 9.75-6.75S21.75 12 21.75 12s-3.5 6.75-9.75 6.75S2.25 12 2.25 12z" /><circle cx="12" cy="12" r="2.5" stroke-width="2" /></svg>
                                    </a>
                                @elseif($assignment->status === 'FINAL')
                                    <a href="{{ route('supervisor.supervisions.form', $assignment) }}" aria-label="{{ $assignment->supervision?->answers_exists ? 'Lanjutkan penilaian' : 'Buka formulir' }} {{ $assignment->targetLecturer->name }}" title="{{ $assignment->supervision?->answers_exists ? 'Lanjutkan penilaian' : 'Buka formulir' }} {{ $assignment->targetLecturer->name }}" class="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-[#7B1113] text-white transition-colors hover:bg-[#5E0C0F] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#7B1113]">
                                        <svg aria-hidden="true" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 3h8l4 4v13a1 1 0 01-1 1H5a1 1 0 01-1-1V4a1 1 0 011-1h3zm8 0v4h4M8 12h8m-8 4h5" /></svg>
                                    </a>
                                @else
                                    <span class="text-[11px] font-semibold text-stone-400">Menunggu finalisasi</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-5 py-10 text-center text-stone-400">Tidak ada tugas supervisi yang berlaku pada tanggal ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
