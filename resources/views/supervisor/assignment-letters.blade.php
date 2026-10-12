@extends('layouts.app', ['title' => 'Surat Tugas Saya'])

@section('content')
<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-bold text-slate-900">Surat Tugas Saya</h1>
        <p class="mt-1 text-sm text-slate-500">Unduh surat tugas supervisi Tahap 1 dan Tahap 2 yang telah diterbitkan LPM untuk Anda.</p>
    </div>

    <form method="GET" action="{{ route('supervisor.assignment_letters.index') }}" class="flex flex-wrap items-end gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="min-w-0 flex-1">
            <label for="letter-period" class="mb-2 block text-sm font-semibold text-slate-700">Periode Supervisi</label>
            <select id="letter-period" name="period_id" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                <option value="">Semua periode saya</option>
                @foreach($periods as $period)
                    <option value="{{ $period->id }}" @selected($selectedPeriod?->id === $period->id)>{{ $period->name }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="rounded-xl bg-[#7B1113] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#5A0E13]">Tampilkan</button>
    </form>

    <div class="grid gap-4 md:grid-cols-2">
        @forelse($letters as $letter)
            <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="mb-3 flex items-center justify-between gap-3">
                    <h2 class="text-lg font-bold text-slate-900">Surat Tugas Tahap {{ $letter->stage->stage_no }}</h2>
                    <span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700">Terbit</span>
                </div>
                <p class="break-words text-sm font-semibold text-[#7B1113]">{{ $letter->document_number }}</p>
                <p class="mt-2 text-sm text-slate-600">{{ $letter->period->name }}</p>
                <p class="mt-1 text-xs text-slate-500">Diterbitkan {{ $letter->generated_at?->timezone('Asia/Jakarta')->locale('id')->translatedFormat('j F Y') ?? '-' }}</p>
                <a href="{{ route('supervisor.assignment_letters.download', $letter->id) }}" class="mt-5 inline-flex items-center gap-2 rounded-xl bg-[#7B1113] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#5A0E13]" aria-label="Unduh PDF surat tugas Tahap {{ $letter->stage->stage_no }}, {{ $letter->period->name }}">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v12m-4-4l4 4 4-4M5 17v4h14v-4"></path></svg>
                    Unduh PDF
                </a>
            </article>
        @empty
            <div class="rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-10 text-center md:col-span-2">
                <h2 class="font-semibold text-slate-800">Belum ada surat tugas yang dapat diunduh</h2>
                <p class="mt-2 text-sm text-slate-500">Surat tugas tersedia setelah LPM memfinalisasi penugasan Anda. Surat yang dibatalkan atau kedaluwarsa tidak ditampilkan.</p>
            </div>
        @endforelse
    </div>
    {{ $letters->links() }}
</div>
@endsection
