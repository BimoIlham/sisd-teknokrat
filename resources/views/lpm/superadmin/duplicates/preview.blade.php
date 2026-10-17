@extends('layouts.app', ['title' => 'Pratinjau Gabung Dosen'])

@section('content')
<div class="max-w-4xl mx-auto space-y-5">
    <a href="{{ route('superadmin.duplicates.index') }}" class="text-sm text-[#7B1113] underline">Kembali ke audit</a>
    <div>
        <h1 class="text-2xl font-bold text-slate-900">Pratinjau Penggabungan Identitas</h1>
        <p class="text-sm text-slate-600">Jadwal, keikutsertaan periode, dan riwayat akan diarahkan ke identitas yang dipertahankan.</p>
    </div>
    <div class="grid gap-4 sm:grid-cols-2">
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-5"><p class="text-xs font-semibold uppercase text-emerald-700">Dipertahankan</p><p class="mt-2 font-bold">{{ $keep->name }} #{{ $keep->id }}</p><p class="text-sm">NIDN {{ $keep->nidn ?: 'tidak tersedia' }}</p></div>
        <div class="rounded-2xl border border-rose-200 bg-rose-50 p-5"><p class="text-xs font-semibold uppercase text-rose-700">Digabung ke identitas utama</p><p class="mt-2 font-bold">{{ $remove->name }} #{{ $remove->id }}</p><p class="text-sm">NIDN {{ $remove->nidn ?: 'tidak tersedia' }}</p></div>
    </div>
    <div class="rounded-2xl border border-slate-200 bg-white p-5">
        <h2 class="font-semibold">Data terkait</h2>
        <div class="mt-3 grid gap-2 sm:grid-cols-2">
            @foreach ($impact['counts'] as $table => $count)
                <p class="text-sm"><span class="text-slate-600">{{ str_replace('_', ' ', $table) }}:</span> <strong>{{ $count }}</strong></p>
            @endforeach
        </div>
    </div>
    @if ($impact['periods'])
        <div class="rounded-2xl border border-slate-200 bg-white p-5">
            <h2 class="font-semibold">Dampak per periode</h2>
            <div class="mt-3 space-y-2 text-sm">
                @foreach ($impact['periods'] as $period)
                    <p><strong>{{ $period['name'] }}</strong> · {{ $period['targets'] }} keikutsertaan dosen, {{ $period['supervisors'] }} keanggotaan supervisor, {{ $period['schedules'] }} jadwal</p>
                @endforeach
            </div>
        </div>
    @endif
    @if ($impact['conflicts'])
        <div class="rounded-2xl border border-rose-200 bg-rose-50 p-5 text-sm text-rose-900">
            <strong>Penggabungan dibatalkan karena konflik:</strong>
            <ul class="mt-2 list-disc pl-5">@foreach ($impact['conflicts'] as $conflict)<li>{{ $conflict }}</li>@endforeach</ul>
        </div>
    @else
        <form method="POST" action="{{ route('superadmin.duplicates.merge', [$keep, $remove]) }}" class="rounded-2xl border border-slate-200 bg-white p-5 space-y-4">
            @csrf
            <input type="hidden" name="fingerprint" value="{{ $impact['fingerprint'] }}">
            <label class="block text-sm font-medium">Ketik nama identitas yang digabung: {{ $remove->name }}
                <input name="confirm_name" required autocomplete="off" class="mt-1 w-full rounded-xl border border-slate-300 p-3">
            </label>
            <label class="block text-sm font-medium">Kata sandi superadmin
                <input type="password" name="password" required class="mt-1 w-full rounded-xl border border-slate-300 p-3">
            </label>
            @if ($errors->any())<p class="text-sm text-rose-700">{{ $errors->first() }}</p>@endif
            <button type="submit" class="rounded-xl bg-[#7B1113] px-5 py-3 text-sm font-semibold text-white">Gabungkan identitas</button>
        </form>
    @endif
</div>
@endsection
