@extends('layouts.app', ['title' => 'Daftar Fakultas'])

@section('content')
<div class="mx-auto max-w-7xl space-y-6">
    <header>
        <h1 class="text-2xl font-bold text-slate-900">Daftar Fakultas</h1>
        <p class="mt-1 text-sm text-slate-600">Pilih fakultas untuk melihat hasil supervisi dan analisis kohort.</p>
    </header>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @forelse($faculties as $faculty)
            <a href="{{ route('pimpinan.faculties.dashboard', $faculty) }}" class="block rounded-2xl border border-slate-200 bg-white p-6 shadow-sm hover:bg-slate-50">
                <p class="text-xs font-bold text-[#7B1113]">{{ $faculty->code }}</p>
                <h2 class="mt-2 text-lg font-bold text-slate-900">{{ $faculty->name }}</h2>
                <p class="mt-3 text-sm font-semibold text-[#7B1113]">Buka dashboard fakultas &rarr;</p>
            </a>
        @empty
            <p class="text-sm text-slate-600">Belum ada fakultas aktif.</p>
        @endforelse
    </div>
</div>
@endsection
