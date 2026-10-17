@extends('layouts.app', ['title' => 'Analisis Kohort '.$scope->label()])

@section('content')
<div class="mx-auto max-w-7xl space-y-6">
    <header class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <p class="text-xs font-bold uppercase tracking-wider text-[#7B1113]">{{ $scope->roleLabel() }} {{ $scope->label() }}</p>
        <h1 class="mt-1 text-2xl font-bold text-slate-900">Analisis Kohort T1/T2</h1>
        <p class="mt-1 text-sm text-slate-600">Hasil dosen pada unit ini saja.</p>
        <form method="GET" action="{{ route('unit.analytics.index') }}" class="mt-4">
            <label for="period_id" class="sr-only">Periode supervisi</label>
            <select id="period_id" name="period_id" onchange="this.form.submit()" class="w-full max-w-sm rounded-xl border border-slate-300 px-3 py-2 text-sm">
                @forelse ($periods as $availablePeriod)
                    <option value="{{ $availablePeriod->id }}" @selected($period?->id === $availablePeriod->id)>{{ $availablePeriod->semester_display }}</option>
                @empty
                    <option>Belum ada periode supervisi</option>
                @endforelse
            </select>
        </form>
    </header>
    @if ($period)
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-2xl border border-slate-200 bg-white p-5"><p class="text-sm text-slate-500">Dosen dengan hasil</p><p class="mt-2 text-2xl font-bold">{{ $analytics['results_count'] }}</p></div>
            <div class="rounded-2xl border border-slate-200 bg-white p-5"><p class="text-sm text-slate-500">Pasangan sebanding</p><p class="mt-2 text-2xl font-bold">{{ $analytics['comparable_count'] }}</p></div>
            <div class="rounded-2xl border border-slate-200 bg-white p-5"><p class="text-sm text-slate-500">Rata-rata T1 / T2</p><p class="mt-2 text-xl font-bold">{{ $analytics['mean_t1'] === null ? '—' : number_format($analytics['mean_t1'], 2) }} / {{ $analytics['mean_t2'] === null ? '—' : number_format($analytics['mean_t2'], 2) }}</p></div>
            <div class="rounded-2xl border border-slate-200 bg-white p-5"><p class="text-sm text-slate-500">Progres unit</p><p class="mt-2 text-2xl font-bold">{{ $progress['percentage'] }}%</p></div>
        </div>
        <section class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            <table class="w-full min-w-[800px] text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-600"><tr><th class="px-4 py-3">Dosen</th><th class="px-4 py-3">Program studi</th><th class="px-4 py-3 text-center">T1</th><th class="px-4 py-3 text-center">T2</th><th class="px-4 py-3 text-center">Perubahan</th><th class="px-4 py-3">Status</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($analytics['stage_results'] as $result)
                        <tr><td class="px-4 py-3 font-semibold">{{ $result['target_lecturer'] }}</td><td class="px-4 py-3">{{ $result['prodi'] }}</td><td class="px-4 py-3 text-center">{{ $result['t1_score'] === null ? '—' : number_format($result['t1_score'], 2) }}</td><td class="px-4 py-3 text-center">{{ $result['t2_score'] === null ? '—' : number_format($result['t2_score'], 2) }}</td><td class="px-4 py-3 text-center">{{ $result['delta'] === null ? '—' : number_format($result['delta'], 2) }}</td><td class="px-4 py-3">{{ match ($result['comparison_status']) { 'COMPARABLE' => 'Sebanding', 'INCOMPARABLE' => 'Belum sebanding', default => 'Menunggu tahap lain' } }}</td></tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-8 text-center text-slate-500">Belum ada hasil supervisi untuk unit ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>
    @else
        <p class="rounded-2xl border border-slate-200 bg-white p-6 text-sm text-slate-600">Belum ada periode supervisi.</p>
    @endif
</div>
@endsection
