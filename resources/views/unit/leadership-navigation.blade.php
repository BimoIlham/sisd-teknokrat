@php
    $periodLink = ['period_id' => request()->query('period_id')];
    $globalLinks = [
        'pimpinan.dashboard' => ['Dashboard', 'M3 10.5 12 3l9 7.5M5 9v12h14V9M9 21v-7h6v7'],
        'pimpinan.analytics.index' => ['Analisis Kohort Seluruh Fakultas', 'M4 19h16M7 15v-4m5 4V5m5 10V8'],
        'pimpinan.reports.supervision' => ['Laporan Hasil Supervisi Seluruh Fakultas', 'M6 3h9l3 3v15H6zM9 11h6m-6 4h6'],
    ];
    $facultyLinks = [
        'dashboard' => ['Dashboard', 'dashboard'],
        'lecturers.index' => ['Direktori Dosen', 'lecturers.*'],
        'analytics.index' => ['Analisis Kohort T1/T2', 'analytics.*'],
        'reports.supervision' => ['Laporan Hasil Supervisi', 'reports.*'],
    ];
@endphp

<div class="space-y-1">
    <div x-show="!sidebarCollapsed" class="px-3 text-[11px] font-extrabold uppercase tracking-wider text-rose-100/80">Pimpinan / Wakil Rektor</div>
    @foreach($globalLinks as $routeName => [$label, $iconPath])
        <a href="{{ route($routeName, $periodLink) }}" aria-label="{{ $label }}" title="{{ $label }}"
            @if(request()->routeIs($routeName)) aria-current="page" @endif
            class="flex items-center rounded-xl px-3.5 py-2.5 text-sm {{ request()->routeIs($routeName) ? 'bg-white font-bold text-[#7B1113]' : 'text-rose-100 hover:bg-white/10 hover:text-white' }}">
            <svg class="w-4.5 h-4.5 mr-3 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $iconPath }}"/></svg>
            <span x-show="!sidebarCollapsed">{{ $label }}</span>
        </a>
    @endforeach
</div>

@if($leadershipFaculties->isNotEmpty())
    <div x-show="!sidebarCollapsed" class="space-y-1">
        <div class="px-3 text-[11px] font-extrabold uppercase tracking-wider text-rose-100/80">Fakultas</div>
        @foreach($leadershipFaculties as $faculty)
            @php
                $activeFaculty = ($scope ?? null)?->isLeadershipFaculty() && $scope->facultyId() === $faculty->id;
                $facultyParams = ['faculty' => $faculty] + $periodLink;
            @endphp
            <div x-data="{ open: @js($activeFaculty) }">
                <button type="button" @click="open = !open" :aria-expanded="open.toString()"
                    aria-controls="leadership-faculty-{{ $faculty->id }}" title="{{ $faculty->name }}"
                    class="flex w-full items-center justify-between rounded-xl px-3.5 py-2.5 text-left text-sm font-semibold text-rose-100 hover:bg-white/10 hover:text-white">
                    <span>{{ $faculty->code }}</span>
                    <svg :class="{ 'rotate-180': open }" class="w-3.5 h-3.5 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m6 9 6 6 6-6"/></svg>
                </button>
                <div id="leadership-faculty-{{ $faculty->id }}" x-show="open" @unless($activeFaculty) x-cloak @endunless class="ml-3 space-y-1">
                    @include('unit.leadership-section-links', [
                        'sectionPrefix' => 'pimpinan.faculties.',
                        'sectionParams' => $facultyParams,
                        'sectionActive' => $activeFaculty && $scope->studyProgramId() === null,
                    ])
                    @if($faculty->studyPrograms->isNotEmpty())
                        <div class="px-3 pt-4 text-[11px] font-extrabold uppercase tracking-wider text-rose-100/80">Program Studi</div>
                    @endif
                    @foreach($faculty->studyPrograms as $program)
                        @php
                            $activeProgram = $activeFaculty && $scope->studyProgramId() === $program->id;
                        @endphp
                        <div x-data="{ programOpen: @js($activeProgram) }">
                            <button type="button" @click="programOpen = !programOpen" :aria-expanded="programOpen.toString()"
                                aria-controls="leadership-program-{{ $program->id }}"
                                class="flex w-full items-center justify-between gap-2 rounded-xl px-3.5 py-2 text-left text-sm font-semibold text-rose-100 hover:bg-white/10 hover:text-white">
                                <span>{{ $program->name }}</span>
                                <svg :class="{ 'rotate-180': programOpen }" class="w-3.5 h-3.5 shrink-0 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m6 9 6 6 6-6"/></svg>
                            </button>
                            <div id="leadership-program-{{ $program->id }}" x-show="programOpen" @unless($activeProgram) x-cloak @endunless class="ml-3 space-y-1">
                                @include('unit.leadership-section-links', [
                                    'sectionPrefix' => 'pimpinan.faculties.programs.',
                                    'sectionParams' => $facultyParams + ['program' => $program],
                                    'sectionActive' => $activeProgram,
                                ])
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
@endif
