@extends('layouts.app', ['title' => 'Tinjau Hasil Supervisi - ' . $supervision->assignment->targetLecturer->name])

@section('content')
<div class="space-y-6" x-data="{ confirmed: false }">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="text-xs font-semibold text-[#7B1113]">Langkah terakhir sebelum pengiriman</p>
            <h1 class="mt-1 text-2xl font-bold text-slate-900">Tinjau hasil supervisi</h1>
            <p class="mt-1 text-sm text-slate-600">Periksa jawaban dan skor untuk {{ $supervision->assignment->targetLecturer->name }} sebelum hasil dikunci.</p>
        </div>
        <a href="{{ route('supervisor.supervisions.form', $supervision->assignment) }}" class="inline-flex items-center justify-center rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">Kembali untuk memperbaiki</a>
    </div>

    <section class="grid gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:grid-cols-3 sm:p-6" aria-labelledby="skor-preview-heading">
        <div class="sm:col-span-2">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Pratinjau nilai</p>
            <h2 id="skor-preview-heading" class="mt-1 text-4xl font-bold text-slate-900">{{ number_format($scorePreview['final_score'], 2, ',', '.') }} <span class="text-lg font-medium text-slate-400">/ 4,00</span></h2>
            <p class="mt-2 text-sm text-slate-600">Dihitung dari jawaban yang tersimpan memakai kebijakan {{ $supervision->scoringPolicy->name }}. Nilai resmi ditetapkan saat hasil dikirim.</p>
        </div>
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-1">
            <div class="rounded-xl bg-slate-50 p-3">
                <p class="text-xs text-slate-500">Total poin</p>
                <p class="mt-1 text-lg font-bold text-slate-900">{{ number_format($scorePreview['raw_score'], 2, ',', '.') }}</p>
            </div>
            <div class="rounded-xl bg-slate-50 p-3">
                <p class="text-xs text-slate-500">Butir dinilai</p>
                <p class="mt-1 text-lg font-bold text-slate-900">{{ $scorePreview['n_items'] }}</p>
            </div>
        </div>
    </section>

    <div class="space-y-4">
        @foreach($supervision->formTemplate->sections as $section)
            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm" aria-labelledby="section-{{ $section->id }}">
                <div class="border-b border-slate-200 bg-slate-50 px-5 py-3">
                    <p class="text-xs font-semibold text-[#7B1113]">Bagian {{ $section->section_order }}</p>
                    <h2 id="section-{{ $section->id }}" class="text-sm font-bold text-slate-900">{{ $section->title }}</h2>
                </div>
                <div class="divide-y divide-slate-100 px-5">
                    @foreach($section->questions as $question)
                        @php
                            $answer = $answersByQuestion->get($question->id);
                            $evidence = $evidenceByQuestion->get($question->id, collect());
                            $answerText = $answer?->option?->option_text
                                ?? $answer?->text_value
                                ?? ($answer?->boolean_value !== null ? ($answer->boolean_value ? 'Ya' : 'Tidak') : null);
                        @endphp
                        <div class="grid gap-2 py-4 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] sm:items-start sm:gap-5">
                            <div>
                                <p class="text-[11px] font-semibold text-slate-500">Pertanyaan {{ $loop->iteration }}</p>
                                <p class="mt-1 text-xs font-semibold text-slate-900">{{ $question->question_text }}</p>
                            </div>
                            <div class="text-xs text-slate-700">
                                @if($answer?->evaluation_notes !== null)
                                    @include('supervisor.supervisions._evaluation-notes', ['evaluationNotes' => $answer->evaluation_notes])
                                @elseif($question->question_type === 'FILE')
                                    @forelse($evidence as $file)
                                        <a href="{{ route('supervisor.supervisions.download_evidence', $file) }}" target="_blank" rel="noopener" class="block font-semibold text-[#7B1113] hover:underline">{{ $file->original_filename }}</a>
                                    @empty
                                        <span>{{ $answerText ?: 'Belum ada berkas' }}</span>
                                    @endforelse
                                @else
                                    <span class="break-words">{{ $answerText ?: 'Belum diisi' }}</span>
                                @endif
                                @if($answer?->notes)
                                    <p class="mt-2 whitespace-pre-line break-words text-slate-600"><strong>Catatan:</strong> {{ $answer->notes }}</p>
                                @endif
                            </div>
                            <div class="sm:text-right">
                                @if($question->is_scored && $answer?->numeric_score !== null)
                                    <span class="inline-flex rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-bold text-emerald-800">Skor {{ number_format((float) $answer->numeric_score, 2, ',', '.') }}</span>
                                @elseif($question->is_scored)
                                    <span class="text-xs text-slate-500">Belum dinilai</span>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>

    <section class="rounded-2xl border border-amber-200 bg-amber-50/50 p-5 shadow-sm sm:p-6" aria-labelledby="confirm-review-heading">
        <h2 id="confirm-review-heading" class="text-base font-bold text-slate-900">Konfirmasi hasil penilaian</h2>
        <p class="mt-1 text-sm text-slate-600">Setelah dikirim, jawaban dan nilai supervisi tidak dapat diubah.</p>

        @if($errors->any())
            <div class="mt-4 rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800" role="alert">
                @foreach($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif

        <form action="{{ route('supervisor.supervisions.submit', $supervision) }}" method="POST" class="mt-5 space-y-4">
            @csrf
            <input type="hidden" name="review_version" value="{{ $scorePreview['row_version'] }}">
            <label for="review_confirmed" class="flex cursor-pointer items-start gap-3 rounded-xl border border-amber-200 bg-white p-4">
                <input id="review_confirmed" type="checkbox" name="review_confirmed" value="1" x-model="confirmed" required class="mt-0.5 h-4 w-4 rounded border-slate-300 text-[#7B1113] focus:ring-[#7B1113]">
                <span class="text-sm font-medium text-slate-800">Saya telah memeriksa seluruh jawaban dan skor, dan menyatakan hasil supervisi ini sudah benar.</span>
            </label>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-end">
                <a href="{{ route('supervisor.supervisions.form', $supervision->assignment) }}" class="rounded-xl border border-slate-300 px-5 py-2.5 text-center text-sm font-semibold text-slate-700 hover:bg-white">Perbaiki jawaban</a>
                <button type="submit" :disabled="!confirmed" class="rounded-xl bg-[#7B1113] px-5 py-2.5 text-sm font-bold text-white hover:bg-[#5E0C0F] disabled:cursor-not-allowed disabled:opacity-50">Kirim & Finalkan Supervisi</button>
            </div>
        </form>
    </section>
</div>
@endsection
