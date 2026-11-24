@extends('layouts.app', ['title' => $isLeadershipReport ? 'Hasil Supervisi per Dosen' : 'Monitoring Supervisi'])

@section('content')
<div class="mx-auto max-w-7xl space-y-6 pb-10">
    @php
        $resultsRoute = $isLeadershipReport ? 'pimpinan.supervisions.index' : 'lpm.supervisions.monitoring';
        $pdfRoute = $isLeadershipReport ? 'pimpinan.supervisions.pdf' : 'lpm.supervisions.pdf';
        $pageTitle = $isLeadershipReport ? 'Hasil Supervisi per Dosen' : 'Monitoring Supervisi';
    @endphp
    <header>
        <div class="flex flex-wrap items-center gap-2 text-xs font-semibold text-slate-500">
            <span>Hasil &amp; Analisis</span>
            <span aria-hidden="true">/</span>
            <span class="text-slate-800">{{ $pageTitle }}</span>
        </div>
        <h1 class="mt-3 text-2xl font-extrabold tracking-tight text-slate-900">{{ $pageTitle }}</h1>
        <p class="mt-1 text-sm text-slate-600">{{ $isLeadershipReport ? 'Lihat nilai dan PDF hasil supervisi yang sudah dikirim dari semua fakultas.' : 'Pantau dosen sasaran, supervisor, dan progres Tahap 1 serta Tahap 2 pada semester yang dipilih.' }}</p>
    </header>

    <section aria-label="Filter {{ $pageTitle }}" class="filter-bar">
        <form method="GET" action="{{ route($resultsRoute) }}" class="filter-controls filter-controls-many" style="--filter-columns: repeat(4, minmax(0,1fr))">
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
            @unless($isLeadershipReport)
            <div class="min-w-0">
                <select name="status" aria-label="Status Supervisi" class="filter-control">
                    <option value="all" @selected($selectedStatus === 'all')>Semua Status</option>
                    <option value="not_supervised" @selected($selectedStatus === 'not_supervised')>Belum Disupervisi</option>
                    <option value="draft" @selected($selectedStatus === 'draft')>Draf</option>
                    <option value="submitted" @selected($selectedStatus === 'submitted')>Sudah Disupervisi</option>
                </select>
            </div>
            <div class="min-w-0">
                <select name="supervisor_id" aria-label="Supervisor" class="filter-control">
                    <option value="">Semua Supervisor</option>
                    @foreach($supervisors as $supervisor)
                        <option value="{{ $supervisor->id }}" @selected($selectedSupervisorId === $supervisor->id)>{{ $supervisor->name }}</option>
                    @endforeach
                </select>
            </div>
            @endunless
            <div class="filter-actions">
                <button type="submit" class="filter-button">Filter</button>
                <a href="{{ route($resultsRoute, ['period_id' => $selectedPeriod?->id]) }}" class="inline-flex h-10 items-center rounded-lg border border-slate-300 px-3 text-sm font-semibold text-slate-600 hover:bg-slate-50">Reset</a>
            </div>
        </form>
        @unless($isLeadershipReport)
            <p class="mt-3 text-xs text-slate-500">Belum Disupervisi berarti formulir penilaian belum diisi. Draf berarti penilaian sudah mulai diisi tetapi belum dikirim. Filter status dan supervisor berlaku pada tahap yang sama; jika semua tahap dipilih, cukup salah satu tahap yang cocok.</p>
        @endunless
        @if($showUnfinishedTasks)
            <p class="mt-2 text-sm font-semibold text-blue-700">Menampilkan tugas yang belum dikirim, termasuk Draf. Gunakan filter di atas untuk memilih status tertentu.</p>
        @endif
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
                                <td class="px-5 py-3.5">
                                    @foreach([1, 2] as $stageNumber)
                                        <div class="text-xs {{ $loop->last ? 'mt-1.5' : '' }}">
                                            <span class="text-slate-500">Tahap {{ $stageNumber }}:</span>
                                            {{ $assignmentsByStage->get($stageNumber)?->supervisorLecturer?->name ?? '-' }}
                                        </div>
                                    @endforeach
                                </td>
                                @foreach([1, 2] as $stageNumber)
                                    @php
                                        $assignment = $assignmentsByStage->get($stageNumber);
                                        $supervision = $assignment?->supervision;
                                        $performedAt = $supervision?->submitted_at?->copy()->timezone('Asia/Jakarta')->locale('id');
                                    @endphp
                                    <td class="px-5 py-3.5">
                                        @if(!in_array($stageNumber, $stageNumbers, true))
                                            <span class="text-xs text-slate-500">Tahap belum diatur</span>
                                        @elseif(!$assignment)
                                            <span class="text-xs text-slate-400">-</span>
                                        @elseif($supervision?->status === 'SUBMITTED')
                                            <div class="font-semibold text-emerald-700">Sudah Disupervisi · {{ number_format((float) $supervision->final_score, 2) }}</div>
                                            <div class="mt-0.5 flex items-center gap-2 text-xs text-slate-500">
                                                <span>{{ $performedAt ? $performedAt->translatedFormat('j M Y H:i').' WIB' : '-' }}</span>
                                                <a href="{{ route($pdfRoute, $supervision) }}" target="_blank" rel="noopener" aria-label="Unduh PDF supervisi {{ $lecturer->name }} Tahap {{ $stageNumber }}" class="font-semibold text-[#7B1113] hover:underline">PDF</a>
                                            </div>
                                        @elseif($supervision?->answers_exists)
                                            <span class="inline-flex rounded-full bg-amber-100 px-2 py-1 text-xs font-semibold text-amber-800">Draf</span>
                                        @else
                                            <span class="inline-flex rounded-full bg-blue-50 px-2 py-1 text-xs font-semibold text-blue-700">Belum Disupervisi</span>
                                        @endif
                                        @if($assignment && $assignment->status !== 'FINAL' && $supervision?->status !== 'SUBMITTED')
                                            <p class="mt-1 text-xs text-slate-500">Menunggu finalisasi jadwal</p>
                                        @endif
                                    </td>
                                @endforeach
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
                <h3 class="mt-3 text-sm font-bold text-slate-900">Tidak ada dosen yang sesuai</h3>
                <p class="mt-1 text-sm text-slate-500">Periksa filter atau pastikan dosen sasaran sudah ditetapkan pada semester yang dipilih.</p>
            </div>
        @endif
    </section>
</div>
@endsection
