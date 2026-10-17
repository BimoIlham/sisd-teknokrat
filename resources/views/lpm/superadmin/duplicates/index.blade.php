@extends('layouts.app', ['title' => 'Audit Duplikat Dosen'])

@section('content')
<div class="max-w-6xl mx-auto space-y-5">
    <div>
        <h1 class="text-2xl font-bold text-slate-900">Audit Duplikat Dosen</h1>
        <p class="text-sm text-slate-600">Pasangan berikut hanya kandidat. Periksa identitas dan dampaknya sebelum menggabungkan.</p>
    </div>
    @if (session('success'))
        <div class="rounded-xl bg-emerald-50 p-4 text-sm text-emerald-800">{{ session('success') }}</div>
    @endif
    <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white">
        <table class="min-w-full text-sm">
            <thead class="bg-slate-50 text-left text-slate-600"><tr><th class="p-4">Identitas pertama</th><th class="p-4">Identitas kedua</th><th class="p-4">Alasan kandidat</th><th class="p-4 text-center">Pratinjau</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($candidates as $pair)
                    <tr>
                        <td class="p-4">{{ $pair['first']->name }} <span class="text-slate-500">#{{ $pair['first']->id }}</span><br>NIDN {{ $pair['first']->nidn ?: 'tidak tersedia' }}</td>
                        <td class="p-4">{{ $pair['second']->name }} <span class="text-slate-500">#{{ $pair['second']->id }}</span><br>NIDN {{ $pair['second']->nidn ?: 'tidak tersedia' }}</td>
                        <td class="p-4">{{ $pair['reason'] }}</td>
                        <td class="p-4 text-center"><a class="font-semibold text-[#7B1113] underline" href="{{ route('superadmin.duplicates.preview', [$pair['first'], $pair['second']]) }}">Lihat dampak</a></td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="p-6 text-center text-slate-500">Belum ada kandidat duplikat yang ditemukan.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
