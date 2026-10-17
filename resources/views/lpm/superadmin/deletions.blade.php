@extends('layouts.app', ['title' => 'Penghapusan Permanen'])

@section('content')
<div class="space-y-6">
    <div><h1 class="text-2xl font-bold">Penghapusan Permanen</h1><p class="text-sm text-stone-600">Pilih item untuk melihat dampak dan mengonfirmasi penghapusan.</p></div>
    <div class="flex flex-wrap gap-2">
        @foreach(['lecturer' => 'Dosen', 'supervisor' => 'Supervisor', 'schedule' => 'Jadwal', 'import' => 'Unggahan PDF', 'period' => 'Periode'] as $key => $label)
            <a href="{{ route('superadmin.deletions.index', ['type' => $key]) }}" class="rounded-lg px-4 py-2 {{ $type === $key ? 'bg-[#7B1113] text-white' : 'bg-white text-stone-800' }}">{{ $label }}</a>
        @endforeach
    </div>
    @if($type === 'lecturer')
        <form method="GET" action="{{ route('superadmin.deletions.index') }}" class="rounded-xl bg-white p-4 shadow-sm">
            <input type="hidden" name="type" value="lecturer">
            <label class="block text-sm font-medium">Periode dosen
                <select name="period_id" class="mt-2 w-full rounded-lg border p-2" onchange="this.form.submit()">
                    @foreach($periods as $period)
                        <option value="{{ $period->id }}" @selected($selectedPeriod?->id === $period->id)>{{ $period->name }} ({{ $period->academicTerm?->academic_year }})</option>
                    @endforeach
                </select>
            </label>
        </form>
    @endif
    @if(in_array($type, ['lecturer', 'supervisor'], true) && ($type === 'lecturer' ? $bulkCount : $items->total()) > 0)
        <div class="flex flex-col gap-4 rounded-xl border border-rose-200 bg-rose-50 p-5 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-start gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-rose-100 text-rose-700" aria-hidden="true">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 6h18m-2 0-1 14H6L5 6m3 0V4h8v2m-6 4v6m4-6v6"/></svg>
                </span>
                <div>
                    <h2 class="font-semibold text-stone-900">Penghapusan massal {{ $type === 'lecturer' ? 'dosen' : 'supervisor' }}</h2>
                    <p class="mt-1 text-sm text-stone-700">Mencakup {{ $type === 'lecturer' ? $bulkCount : $items->total() }} {{ $type === 'lecturer' ? 'dosen' : 'supervisor' }} di seluruh database, termasuk halaman lain dan periode lama.</p>
                    <p class="mt-1 text-xs text-rose-900">Anda dapat meninjau dampaknya sebelum mengonfirmasi.</p>
                </div>
            </div>
            <a href="{{ route('superadmin.deletions.bulk.preview', ['type' => $type]) }}" class="inline-flex min-h-11 shrink-0 items-center justify-center rounded-lg bg-rose-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-rose-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-rose-700">Hapus Semua {{ $type === 'lecturer' ? 'Dosen' : 'Supervisor' }}</a>
        </div>
    @endif
    <div class="overflow-x-auto rounded-xl bg-white shadow-sm">
        <table class="w-full text-left text-sm"><thead><tr class="border-b bg-stone-50"><th class="p-4">Item</th><th class="p-4 text-center">Aksi</th></tr></thead><tbody>
            @forelse($items as $item)
                <tr class="border-b"><td class="p-4">{{ match($type) { 'lecturer', 'supervisor', 'period' => $item->name, 'schedule' => $item->lecturer?->name.' / '.$item->course?->name.' / '.$item->class_name, 'import' => $item->original_filename } }} <span class="text-stone-500">#{{ $item->id }}</span></td><td class="p-4 text-center"><a class="font-semibold text-[#7B1113] underline" href="{{ route('superadmin.deletions.preview', ['type' => $type, 'id' => $item->id, 'period_id' => $type === 'lecturer' ? $selectedPeriod?->id : null]) }}">Lihat dampak</a></td></tr>
            @empty
                <tr><td colspan="2" class="p-6 text-center text-stone-500">Belum ada item.</td></tr>
            @endforelse
        </tbody></table>
    </div>
    {{ $items->appends(['type' => $type, 'period_id' => $selectedPeriod?->id])->links() }}
</div>
@endsection
