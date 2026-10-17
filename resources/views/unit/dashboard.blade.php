@extends('layouts.app', ['title' => 'Dashboard '.$scope->roleLabel()])

@section('content')
<div class="mx-auto max-w-7xl space-y-6">
    <header class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <p class="text-xs font-bold uppercase tracking-wider text-[#7B1113]">{{ $scope->roleLabel() }}</p>
        <h1 class="mt-1 text-2xl font-bold text-slate-900">Dashboard {{ $scope->label() }}</h1>
        <p class="mt-2 text-sm text-slate-600">Ringkasan dosen dan hasil supervisi pada {{ $period?->semester_display ?? 'periode yang tersedia' }}.</p>
        <form method="GET" action="{{ route('unit.dashboard') }}" class="mt-4">
            <label for="dashboard-period" class="mb-1 block text-sm font-semibold text-slate-700">Semester Supervisi</label>
            <select id="dashboard-period" name="period_id" onchange="this.form.submit()" @disabled($periods->isEmpty()) class="w-full max-w-sm rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm">
                @forelse($periods as $availablePeriod)
                    <option value="{{ $availablePeriod->id }}" @selected($period?->id === $availablePeriod->id)>{{ $availablePeriod->semester_display }}</option>
                @empty
                    <option>Belum ada periode supervisi</option>
                @endforelse
            </select>
        </form>
    </header>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-sm text-slate-500">Dosen aktif</p><p class="mt-2 text-3xl font-bold text-slate-900">{{ $lecturerCount }}</p></article>
        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-sm text-slate-500">Target supervisi</p><p class="mt-2 text-3xl font-bold text-slate-900">{{ $targetCount }}</p></article>
        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-sm text-slate-500">Hasil dikirim</p><p class="mt-2 text-3xl font-bold text-slate-900">{{ $submittedCount }}</p><p class="mt-1 text-xs text-slate-500">{{ $progress['percentage'] }}% dari {{ $progress['total_required'] }} hasil yang diperlukan</p></article>
        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-sm text-slate-500">Rata-rata skor</p><p class="mt-2 text-3xl font-bold text-slate-900">{{ $averageScore === null ? '—' : number_format($averageScore, 2) }}</p></article>
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="text-lg font-bold text-slate-900">Analisis Kohort T1/T2</h2>
            <p class="mt-2 text-sm text-slate-600">{{ $analytics['comparable_count'] ?? 0 }} pasangan tahap dapat dibandingkan.</p>
            <p class="mt-3 text-sm text-slate-700">Rata-rata T1: <strong>{{ isset($analytics['mean_t1']) ? number_format($analytics['mean_t1'], 2) : 'Belum tersedia' }}</strong> · T2: <strong>{{ isset($analytics['mean_t2']) ? number_format($analytics['mean_t2'], 2) : 'Belum tersedia' }}</strong></p>
            <a href="{{ route('unit.analytics.index', ['period_id' => $period?->id]) }}" class="mt-4 inline-block text-sm font-semibold text-[#7B1113] underline">Lihat analisis</a>
        </section>
        <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="text-lg font-bold text-slate-900">Akses unit</h2>
            <div class="mt-3 flex flex-wrap gap-3 text-sm font-semibold text-[#7B1113]">
                <a href="{{ route('unit.lecturers.index', ['period_id' => $period?->id]) }}" class="rounded-xl border border-slate-200 px-4 py-2">Direktori Dosen</a>
                <a href="{{ route('unit.reports.supervision', ['period_id' => $period?->id]) }}" class="rounded-xl border border-slate-200 px-4 py-2">Laporan Hasil Supervisi</a>
            </div>
        </section>
    </div>

    <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <h2 class="text-lg font-bold text-slate-900">Hasil supervisi terbaru</h2>
        <div class="mt-4 divide-y divide-slate-100">
            @forelse ($recentSubmissions as $submission)
                <div class="flex flex-wrap items-center justify-between gap-2 py-3 text-sm">
                    <span class="font-semibold text-slate-800">{{ $submission->assignment?->targetLecturer?->name ?? 'Dosen tidak tersedia' }}</span>
                    <span class="text-slate-600">Tahap {{ $submission->assignment?->supervisionStage?->stage_no ?? '—' }} · {{ number_format((float) $submission->final_score, 2) }}</span>
                </div>
            @empty
                <p class="py-3 text-sm text-slate-500">Belum ada hasil supervisi yang dikirim untuk unit ini.</p>
            @endforelse
        </div>
    </section>
</div>
@endsection
