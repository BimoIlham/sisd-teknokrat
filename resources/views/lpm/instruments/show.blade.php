@extends('layouts.app', ['title' => 'Detail Instrumen - ' . $template->name])

@section('content')
<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-2 mb-1">
                <a href="{{ route('lpm.instruments.index') }}" class="text-xs text-[#7B1113] hover:underline flex items-center gap-1">
                    &larr; Kembali ke Daftar Instrumen
                </a>
            </div>
            <h1 class="text-2xl font-bold text-slate-900">{{ $template->name }}</h1>
            <p class="text-xs text-slate-500 mt-0.5">
                Moda Pelaksanaan: <strong>{{ $template->delivery_mode === 'OFFLINE' ? 'Tatap Muka (Offline)' : 'Daring (Online / SPADA)' }}</strong>
            </p>
        </div>
    </div>

    <!-- Sections and Questions Tree -->
    <div class="space-y-4">
        @foreach($template->sections as $sec)
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="bg-slate-50 p-4 border-b border-slate-200 flex items-center justify-between">
                    <div>
                        <span class="text-[10px] font-bold uppercase tracking-wider text-[#7B1113]">Bagian {{ $sec->section_order }}</span>
                        <h3 class="text-sm font-bold text-slate-900">{{ $sec->title }}</h3>
                        @if($sec->description)
                            <p class="text-xs text-slate-500 mt-0.5">{{ $sec->description }}</p>
                        @endif
                    </div>
                    <span class="text-xs font-semibold text-slate-500">{{ $sec->questions->count() }} Butir</span>
                </div>

                <div class="divide-y divide-slate-100 p-4 space-y-3">
                    @foreach($sec->questions as $q)
                        <div class="pt-2 first:pt-0">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-semibold bg-slate-100 text-slate-700 mr-2">
                                        Butir {{ $q->sort_order ?? $loop->iteration }}
                                    </span>
                                    <span class="text-xs font-medium text-slate-800">{{ $q->question_text }}</span>
                                </div>
                                <div class="flex items-center gap-1.5 shrink-0">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-slate-100 text-slate-600">
                                        {{ match ($q->question_type) {
                                            'SCORE' => 'Skala Nilai',
                                            'YES_NO' => 'Ya / Tidak',
                                            'TEXTAREA' => 'Catatan',
                                            'FILE' => 'Unggah Berkas',
                                            'LINK' => 'Tautan',
                                            default => 'Isian',
                                        } }}
                                    </span>
                                    @if($q->is_scored)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-100 text-emerald-800">
                                            BERNILAI
                                        </span>
                                    @endif
                                </div>
                            </div>

                            @if($q->options->count() > 0)
                                <div class="mt-2 pl-6 flex flex-wrap gap-2">
                                    @foreach($q->options as $opt)
                                        <span class="inline-flex items-center px-2 py-1 rounded-md text-[11px] bg-slate-50 border border-slate-200 text-slate-600">
                                            {{ $opt->option_text }}
                                            @if($opt->numeric_score !== null)
                                                <strong class="ml-1 text-emerald-700">({{ $opt->numeric_score }})</strong>
                                            @endif
                                        </span>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
</div>
@endsection
