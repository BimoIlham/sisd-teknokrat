@extends('layouts.app', ['title' => 'Mulai Penilaian Supervisi'])

@section('content')
<div class="mx-auto max-w-2xl rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
    <h1 class="text-xl font-bold text-slate-900">Mulai Penilaian Supervisi</h1>
    <p class="mt-2 text-sm text-slate-600">Siapkan formulir penilaian untuk {{ $assignment->targetLecturer->name }}.</p>
    @if ($errors->any())
        <div class="mt-4 text-sm text-red-700" role="alert">{{ $errors->first() }}</div>
    @endif
    <form method="POST" action="{{ route('supervisor.supervisions.start', $assignment) }}" class="mt-6">
        @csrf
        <button type="submit" class="rounded-lg bg-red-900 px-4 py-2 text-sm font-semibold text-white hover:bg-red-800">Mulai Penilaian</button>
    </form>
</div>
@endsection
