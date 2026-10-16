@extends('layouts.app', ['title' => 'Analitik Hasil Supervisi'])

@section('content')
<div class="space-y-6">
    <section class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="space-y-4">
            <div class="min-w-0">
                <p class="text-xs font-bold uppercase tracking-[0.14em] text-[#7B1113]">Analitik Supervisi</p>
                <h1 class="mt-1.5 text-xl sm:text-2xl font-bold tracking-tight text-slate-900">Perbandingan Hasil Supervisi</h1>
                <p class="mt-1.5 text-sm leading-relaxed text-slate-600">Skor tiap tahap langsung tampil setelah disubmit. Perubahan skor dihitung saat hasil Tahap 1 dan Tahap 2 dosen yang sama tersedia dan sebanding.</p>
            </div>

            <div class="min-w-0">
                @if($periods->isNotEmpty())
                    <form method="GET" action="{{ route('lpm.analytics.index') }}" class="filter-controls" style="--filter-columns: minmax(0,1.5fr) minmax(0,1fr) minmax(0,1.5fr)">
                        <label for="period_id" class="filter-field"><span class="sr-only">Semester Supervisi</span>
                            <select id="period_id" name="period_id" onchange="this.form.submit()" class="filter-control">
                                @foreach($periods as $availablePeriod)
                                    <option value="{{ $availablePeriod->id }}" @selected($period?->id === $availablePeriod->id)>
                                        {{ $availablePeriod->semester_display }}
                                    </option>
                                @endforeach
                            </select>
                        </label>
                        <label for="faculty_id" class="filter-field"><span class="sr-only">Fakultas</span>
                            <select id="faculty_id" name="faculty_id" onchange="this.form.submit()" class="filter-control">
                                <option value="">Semua Fakultas</option>
                                @foreach($faculties as $faculty)
                                    <option value="{{ $faculty->id }}" @selected($selectedFacultyId === $faculty->id) >{{ $faculty->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label for="study_program_id" class="filter-field"><span class="sr-only">Program Studi</span>
                            <select id="study_program_id" name="study_program_id" onchange="this.form.submit()" class="filter-control">
                                <option value="">Semua Program Studi</option>
                                @foreach($studyPrograms as $studyProgram)
                                    <option value="{{ $studyProgram->id }}" @selected($selectedStudyProgramId === $studyProgram->id)>
                                        {{ $studyProgram->name }}
                                    </option>
                                @endforeach
                            </select>
                        </label>
                    </form>
                @else
                    <label for="semester_unavailable" class="sr-only">Semester Supervisi</label>
                    <select id="semester_unavailable" disabled class="filter-control">
                        <option>Belum ada periode supervisi</option>
                    </select>
                @endif
            </div>
        </div>
    </section>

    @if(!$period)
        <section class="rounded-2xl border border-slate-200 bg-white px-6 py-10 sm:py-14 text-center shadow-sm">
            <div class="mx-auto max-w-xl">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Analisis belum tersedia</p>
                <h2 class="mt-2 text-lg font-semibold text-slate-800">Belum ada periode supervisi yang siap dianalisis</h2>
                <p class="mt-2 text-sm leading-relaxed text-slate-500">Buat periode supervisi untuk semester ganjil atau genap. Hasil akan ditampilkan begitu supervisor mengirim penilaian tahap pertama.</p>
            </div>
        </section>
    @elseif(empty($analytics['stage_results']))
        <section class="rounded-2xl border border-slate-200 bg-white px-6 py-10 sm:py-14 text-center shadow-sm">
            <div class="mx-auto max-w-xl">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Menunggu hasil supervisi</p>
                <h2 class="mt-2 text-lg font-semibold text-slate-800">Belum ada penilaian yang disubmit</h2>
                <p class="mt-2 text-sm leading-relaxed text-slate-500">Skor akan muncul setelah supervisor mengirim hasil Tahap 1 atau Tahap 2. Perubahan skor tersedia setelah kedua tahap dosen yang sama selesai dan dapat dibandingkan.</p>
            </div>
        </section>
    @else
        <section class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="px-5 py-4 sm:px-6 border-b border-slate-100">
                <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-2">
                    <div>
                        <h2 class="text-lg font-bold text-slate-900">Hasil Supervisi per Tahap</h2>
                        <p class="mt-1 text-sm text-slate-500">Skor yang sudah disubmit langsung ditampilkan; pasangan dan perubahan skor akan terisi setelah kedua tahap tersedia.</p>
                    </div>
                    <span class="text-xs text-slate-500">{{ $analytics['results_count'] ?? 0 }} dosen memiliki hasil</span>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[900px] text-left text-sm">
                    <thead class="bg-slate-50 text-slate-600 text-xs">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-semibold">Nama dosen</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Program studi</th>
                            <th scope="col" class="px-4 py-3 text-center font-semibold">Cara perkuliahan</th>
                            <th scope="col" class="px-4 py-3 text-center font-semibold">Skor Tahap 1</th>
                            <th scope="col" class="px-4 py-3 text-center font-semibold">Skor Tahap 2</th>
                            <th scope="col" class="px-4 py-3 text-center font-semibold">Perubahan skor</th>
                            <th scope="col" class="px-4 py-3 text-center font-semibold">Hasil</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($analytics['stage_results'] as $pair)
                            <tr class="hover:bg-slate-50/70">
                                <td class="px-4 py-3 font-semibold text-slate-900">
                                    {{ $pair['target_lecturer'] }}
                                    <span class="block text-xs font-normal text-slate-500">NIDN: {{ $pair['nidn'] ?? 'Tidak tersedia' }}</span>
                                </td>
                                <td class="px-4 py-3 text-slate-600">{{ $pair['prodi'] }}</td>
                                <td class="px-4 py-3 text-center text-slate-600">
                                    {{ match (strtoupper((string) ($pair['delivery_mode'] ?? ''))) { 'OFFLINE' => 'Offline', 'ONLINE' => 'Online', default => 'Belum tersedia' } }}
                                </td>
                                <td class="px-4 py-3 text-center font-semibold text-slate-800">{{ $pair['t1_score'] !== null ? number_format($pair['t1_score'], 2) : 'Belum ada' }}</td>
                                <td class="px-4 py-3 text-center font-semibold text-slate-800">{{ $pair['t2_score'] !== null ? number_format($pair['t2_score'], 2) : 'Belum ada' }}</td>
                                <td class="px-4 py-3 text-center font-bold {{ ($pair['delta'] ?? null) === null ? 'text-slate-400' : ($pair['delta'] > 0 ? 'text-emerald-700' : ($pair['delta'] < 0 ? 'text-rose-700' : 'text-slate-600')) }}">
                                    {{ ($pair['delta'] ?? null) !== null ? (($pair['delta'] > 0 ? '+' : '').number_format($pair['delta'], 2)) : '—' }}
                                </td>
                                <td class="px-4 py-3 text-center">
                                    @if(($pair['comparison_status'] ?? null) === 'COMPARABLE' && $pair['direction'] === 'INCREASE')
                                        <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold bg-emerald-50 text-emerald-800">Skor naik</span>
                                    @elseif(($pair['comparison_status'] ?? null) === 'COMPARABLE' && $pair['direction'] === 'DECREASE')
                                        <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold bg-rose-50 text-rose-800">Skor turun</span>
                                    @elseif(($pair['comparison_status'] ?? null) === 'COMPARABLE')
                                        <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold bg-slate-100 text-slate-700">Skor tetap</span>
                                    @else
                                        <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold bg-amber-50 text-amber-800">
                                            {{ ($pair['comparison_status'] ?? null) === 'INCOMPARABLE' ? 'Belum sebanding' : 'Menunggu tahap lain' }}
                                        </span>
                                        <span class="mt-1 block text-xs font-normal text-slate-500">{{ $pair['reason'] }}</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-6 py-10 text-center">
                                    <p class="font-semibold text-slate-700">Belum ada hasil supervisi yang disubmit</p>
                                    <p class="mt-1 text-sm text-slate-500">Data akan tampil segera setelah supervisor mengirim hasil salah satu tahap.</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section aria-label="Ringkasan hasil perbandingan" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <article class="bg-white rounded-2xl p-5 border border-slate-200 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Dosen dengan hasil</p>
                <p class="mt-2 text-3xl font-bold text-slate-900">{{ $analytics['results_count'] ?? 0 }}</p>
                <p class="mt-1 text-xs text-slate-500">Jumlah dosen dengan setidaknya satu tahap disubmit</p>
            </article>

            <article class="sm:col-span-2 bg-white rounded-2xl p-5 border border-slate-200 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Rata-rata skor</p>
                <div class="grid grid-cols-2 divide-x divide-slate-200 mt-3">
                    <div class="pr-4">
                        <p class="text-xs font-medium text-slate-500">Tahap 1</p>
                        <p class="mt-1 text-2xl font-bold text-slate-900">{{ ($analytics['mean_t1'] ?? null) !== null ? number_format($analytics['mean_t1'], 2) : 'Belum tersedia' }}</p>
                        <p class="mt-1 text-xs text-slate-500">{{ $analytics['t1_submitted_count'] ?? 0 }} dosen disubmit</p>
                    </div>
                    <div class="pl-4">
                        <p class="text-xs font-medium text-slate-500">Tahap 2</p>
                        <p class="mt-1 text-2xl font-bold text-slate-900">{{ ($analytics['mean_t2'] ?? null) !== null ? number_format($analytics['mean_t2'], 2) : 'Belum tersedia' }}</p>
                        <p class="mt-1 text-xs text-slate-500">{{ $analytics['t2_submitted_count'] ?? 0 }} dosen disubmit</p>
                    </div>
                </div>
            </article>

            @php($averageDelta = $analytics['average_delta'] ?? null)
            <article class="bg-white rounded-2xl p-5 border border-slate-200 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Rata-rata perubahan skor</p>
                <p class="mt-2 text-3xl font-bold {{ $averageDelta === null ? 'text-slate-400' : ($averageDelta > 0 ? 'text-emerald-700' : ($averageDelta < 0 ? 'text-rose-700' : 'text-slate-700')) }}">
                    @if($averageDelta !== null)
                        {{ $averageDelta > 0 ? '+' : '' }}{{ number_format($averageDelta, 2) }}
                    @else
                        Belum tersedia
                    @endif
                </p>
                <p class="mt-1 text-xs text-slate-500">Selisih dari {{ $analytics['comparable_count'] ?? 0 }} pasangan dosen dengan dua tahap sebanding</p>
            </article>
        </section>
    @endif
</div>
@endsection
