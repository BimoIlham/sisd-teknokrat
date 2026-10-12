@extends('layouts.app', ['title' => 'Dosen yang Sudah Disupervisi'])

@section('content')
<div class="mx-auto max-w-7xl space-y-6 pb-10">
    <header>
        <div class="flex flex-wrap items-center gap-2 text-xs font-semibold text-slate-500">
            <span>Hasil &amp; Analisis</span>
            <span aria-hidden="true">/</span>
            <span class="text-slate-800">Dosen yang Sudah Disupervisi</span>
        </div>
        <h1 class="mt-3 text-2xl font-extrabold tracking-tight text-slate-900">Dosen yang Sudah Disupervisi</h1>
        <p class="mt-1 text-sm text-slate-600">Daftar dosen yang hasil supervisinya telah dikirim, beserta status Tahap 1 dan Tahap 2.</p>
    </header>

    <section aria-label="Filter hasil supervisi" class="filter-bar">
        <form method="GET" action="{{ route('lpm.supervisions.completed') }}" class="filter-controls filter-controls-many" style="--filter-columns: minmax(0,1.5fr) minmax(0,1fr) minmax(0,1.2fr) minmax(0,1.4fr) minmax(0,0.9fr) auto">
            <div class="filter-search">
                <svg class="filter-search-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                <input
                    id="search"
                    type="text"
                    name="search"
                    value="{{ $search }}"
                    aria-label="Cari dosen"
                    placeholder="Cari dosen, NIDN/kode..."
                    class="filter-control"
                >
            </div>
            <div class="min-w-0">
                <select name="faculty_id" aria-label="Fakultas" class="filter-control">
                    <option value="">Semua Fakultas</option>
                    @foreach($faculties as $faculty)
                        <option value="{{ $faculty->id }}" title="{{ $faculty->name }}" @selected($selectedFacultyId === $faculty->id)>{{ $faculty->code }}</option>
                    @endforeach
                </select>
            </div>
            <div class="min-w-0">
                <select name="study_program_id" aria-label="Program Studi" class="filter-control">
                    <option value="">Semua Program Studi</option>
                    @foreach($studyPrograms as $studyProgram)
                        <option value="{{ $studyProgram->id }}" @selected($selectedProgramId === $studyProgram->id)>{{ $studyProgram->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="min-w-0">
                <select name="period_id" aria-label="Semester Supervisi" @disabled($periods->isEmpty()) class="filter-control">
                    @forelse($periods as $period)
                        <option value="{{ $period->id }}" @selected($selectedPeriod?->id === $period->id)>{{ $period->academicTerm?->semester_label ?? 'Semester Supervisi' }} {{ $period->academicTerm?->academic_year ?? '-' }}</option>
                    @empty
                        <option value="">Belum ada semester supervisi</option>
                    @endforelse
                </select>
            </div>
            <div class="min-w-0">
                <select name="stage" aria-label="Tahap Supervisi" class="filter-control">
                    <option value="all" @selected($selectedStage === 'all')>Semua Tahap</option>
                    <option value="1" @selected($selectedStage === '1')>Tahap 1</option>
                    <option value="2" @selected($selectedStage === '2')>Tahap 2</option>
                </select>
            </div>
            <div class="filter-actions">
                <button type="submit" class="filter-button">Filter</button>
            </div>
        </form>
    </section>

    <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-5 py-4">
            <div>
                <h2 class="font-bold text-slate-900">Daftar Dosen</h2>
                <p class="mt-0.5 text-xs text-slate-500">Setiap dosen ditampilkan satu kali. Hasil tiap tahap tercantum terpisah.</p>
            </div>
            <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">{{ $supervisedLecturers->total() }} dosen</span>
        </div>

        @if($supervisedLecturers->isNotEmpty())
            <div class="overflow-x-auto">
                <table class="w-full min-w-[950px] text-left">
                    <thead class="bg-slate-50 text-[11px] font-bold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-5 py-3 text-center">No.</th>
                            <th class="px-5 py-3">Dosen</th>
                            <th class="px-5 py-3">Program Studi / Fakultas</th>
                            <th class="px-5 py-3">Supervisor</th>
                            <th class="px-5 py-3">Tahap 1</th>
                            <th class="px-5 py-3">Tahap 2</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 text-sm text-slate-700">
                        @foreach($supervisedLecturers as $lecturer)
                            @php
                                $assignmentsByStage = $lecturer->assignmentsAsTarget->keyBy(fn ($assignment) => $assignment->supervisionStage?->stage_no);
                                $stageOneAssignment = $assignmentsByStage->get(1);
                                $stageTwoAssignment = $assignmentsByStage->get(2);
                                $stageOnePerformedAt = $stageOneAssignment?->supervision?->submitted_at?->copy()->timezone('Asia/Jakarta')->locale('id');
                                $stageTwoPerformedAt = $stageTwoAssignment?->supervision?->submitted_at?->copy()->timezone('Asia/Jakarta')->locale('id');
                                $supervisor = $stageOneAssignment?->supervisorLecturer ?? $stageTwoAssignment?->supervisorLecturer;
                            @endphp
                            <tr class="transition-colors hover:bg-slate-50/70">
                                <td class="px-5 py-3.5 text-center text-slate-400">{{ $supervisedLecturers->firstItem() + $loop->index }}</td>
                                <td class="px-5 py-3.5">
                                    <div class="font-semibold text-slate-900">{{ $lecturer->name }}</div>
                                    <div class="mt-0.5 text-xs text-slate-500">NIDN: {{ $lecturer->nidn ?? '-' }}</div>
                                </td>
                                <td class="px-5 py-3.5">
                                    <div class="font-medium text-slate-800">{{ $lecturer->studyProgram?->name ?? '-' }}</div>
                                    <div class="mt-0.5 text-xs text-slate-500">{{ $lecturer->faculty?->name ?? '-' }}</div>
                                </td>
                                <td class="px-5 py-3.5">{{ $supervisor?->name ?? '-' }}</td>
                                <td class="px-5 py-3.5">
                                        @if($stageOneAssignment?->supervision?->status === 'SUBMITTED')
                                            <div class="font-semibold text-emerald-700">Selesai · {{ number_format((float) $stageOneAssignment->supervision->final_score, 2) }}</div>
                                            <div class="mt-0.5 flex items-center gap-2 text-xs text-slate-500">
                                                <span>{{ $stageOnePerformedAt ? $stageOnePerformedAt->translatedFormat('j M Y H:i').' WIB' : '-' }}</span>
                                                <a href="{{ route('lpm.supervisions.pdf', $stageOneAssignment->supervision) }}" target="_blank" rel="noopener" class="font-semibold text-[#7B1113] hover:underline">PDF</a>
                                            </div>
                                        @else
                                            <span class="text-xs text-slate-400">Belum disubmit</span>
                                        @endif
                                </td>
                                <td class="px-5 py-3.5">
                                        @if($stageTwoAssignment?->supervision?->status === 'SUBMITTED')
                                            <div class="font-semibold text-emerald-700">Selesai · {{ number_format((float) $stageTwoAssignment->supervision->final_score, 2) }}</div>
                                            <div class="mt-0.5 flex items-center gap-2 text-xs text-slate-500">
                                                <span>{{ $stageTwoPerformedAt ? $stageTwoPerformedAt->translatedFormat('j M Y H:i').' WIB' : '-' }}</span>
                                                <a href="{{ route('lpm.supervisions.pdf', $stageTwoAssignment->supervision) }}" target="_blank" rel="noopener" class="font-semibold text-[#7B1113] hover:underline">PDF</a>
                                            </div>
                                        @else
                                            <span class="text-xs text-slate-400">Belum disubmit</span>
                                        @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="border-t border-slate-200 px-5 py-3">
                {{ $supervisedLecturers->links('vendor.pagination.tailwind') }}
            </div>
        @else
            <div class="px-5 py-14 text-center">
                <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-400" aria-hidden="true">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622C17.176 19.29 21 14.591 21 9c0-1.042-.133-2.053-.382-3.016z"></path></svg>
                </div>
                <h3 class="mt-3 text-sm font-bold text-slate-900">Belum ada hasil supervisi</h3>
                <p class="mt-1 text-sm text-slate-500">Hasil akan muncul di sini setelah supervisor mengirim formulir pada periode yang dipilih.</p>
            </div>
        @endif
    </section>
</div>
@endsection
