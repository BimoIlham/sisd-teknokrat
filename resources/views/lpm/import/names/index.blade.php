@extends('layouts.app', ['title' => 'Tinjau Nama Dosen'])

@section('content')
<div class="max-w-5xl mx-auto space-y-5">
    <div class="flex items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Tinjau Nama Dosen</h1>
            <p class="text-sm text-slate-600">Unggahan dengan nama dosen yang belum dapat dipastikan.</p>
        </div>
        <a href="{{ route('lpm.import.index') }}" class="rounded-xl border border-slate-300 px-4 py-2 text-sm">Upload jadwal</a>
    </div>
    <div class="rounded-2xl border border-slate-200 bg-white divide-y divide-slate-100">
        @forelse ($imports as $import)
            <a href="{{ route('lpm.import.names.show', $import) }}" class="flex items-center justify-between gap-3 p-4 hover:bg-slate-50">
                <span class="font-medium text-slate-900">{{ $import->original_filename }}</span>
                <span class="text-xs text-slate-500">{{ $import->created_at?->format('d M Y H:i') }}</span>
            </a>
        @empty
            <p class="p-6 text-sm text-slate-500">Tidak ada nama yang menunggu tinjauan.</p>
        @endforelse
    </div>
</div>
@endsection
