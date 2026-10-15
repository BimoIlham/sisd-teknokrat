@extends('layouts.app', ['title' => 'Bank Pertanyaan Supervisi (' . ($mode === 'offline' ? 'Offline' : 'Online') . ')'])

@section('content')
<div x-data="questionBankApp()" class="space-y-8 pb-12">
    <!-- Header & Breadcrumbs -->
    <div class="space-y-3 pb-2">
        <nav class="flex items-center gap-1.5 text-xs text-slate-400">
            <a href="{{ route('lpm.dashboard') }}" class="hover:text-[#7B1113] transition-colors font-medium text-slate-500">Dashboard</a>
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
            <span class="font-semibold text-slate-700">Bank Pertanyaan {{ $mode === 'offline' ? 'Tatap Muka' : 'Daring' }}</span>
        </nav>

        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <h1 class="text-xl font-bold text-slate-900 tracking-tight">
                Bank Pertanyaan Supervisi ({{ $mode === 'offline' ? 'Offline' : 'Online' }})
                <span class="sr-only">{{ strtoupper($mode) }}</span>
            </h1>

            <div class="flex items-center gap-2 shrink-0">
                <button 
                    type="button" 
                    @click="openPoliciesModal()" 
                    class="inline-flex items-center px-3 py-2 rounded-lg border border-slate-200 hover:border-slate-300 hover:bg-slate-50 text-xs font-medium text-slate-600 transition-colors"
                >
                    <svg class="w-3.5 h-3.5 mr-1.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path></svg>
                    Kebijakan Skor
                </button>
                @unless($isUsed)
                <button 
                    type="button" 
                    @click="openAddSectionModal()" 
                    class="inline-flex items-center px-3 py-2 rounded-lg border border-slate-200 hover:border-slate-300 hover:bg-slate-50 text-xs font-medium text-slate-600 transition-colors"
                >
                    <svg class="w-3.5 h-3.5 mr-1.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                    Tambah Bagian
                </button>
                <button 
                    type="button" 
                    @click="openAddModal()" 
                    class="inline-flex items-center px-4 py-2 rounded-lg bg-[#7B1113] hover:bg-[#600d0e] text-white text-xs font-semibold shadow-sm transition-colors"
                >
                    <svg class="w-3.5 h-3.5 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                    Tambah Pertanyaan
                </button>
                @endunless
            </div>
        </div>
    </div>

    @if($isUsed)
        <div class="rounded-xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-900">
            Instrumen sudah digunakan dalam penilaian dan tidak dapat diubah.
        </div>
    @endif

    <!-- Alert Messages -->
    @if(session('success'))
        <div class="p-4 sm:p-5 rounded-2xl bg-emerald-50 border border-emerald-200 text-emerald-900 text-sm flex items-center justify-between shadow-2xs">
            <div class="flex items-center gap-3">
                <div class="w-8 h-8 rounded-full bg-emerald-100 text-emerald-700 flex items-center justify-center shrink-0">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                </div>
                <span>{{ session('success') }}</span>
            </div>
            <button type="button" @click="$el.parentElement.remove()" class="text-emerald-700 hover:text-emerald-900 font-bold p-1">&times;</button>
        </div>
    @endif

    @if(session('error'))
        <div class="p-4 sm:p-5 rounded-2xl bg-rose-50 border border-rose-200 text-rose-900 text-sm flex items-center justify-between shadow-2xs">
            <div class="flex items-center gap-3">
                <div class="w-8 h-8 rounded-full bg-rose-100 text-rose-700 flex items-center justify-center shrink-0">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                </div>
                <span>{{ session('error') }}</span>
            </div>
            <button type="button" @click="$el.parentElement.remove()" class="text-rose-700 hover:text-rose-900 font-bold p-1">&times;</button>
        </div>
    @endif

    @if($errors->any())
        <div class="p-5 rounded-2xl bg-rose-50 border border-rose-200 text-rose-900 text-sm shadow-2xs space-y-2">
            <div class="font-bold flex items-center gap-2">
                <svg class="w-5 h-5 text-rose-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                Terdapat kesalahan pada formulir:
            </div>
            <ul class="list-disc list-inside pl-2 text-xs text-rose-700 space-y-1">
                @foreach($errors->all() as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <!-- Summary Stat Cards -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 sm:gap-5">
        <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-2xs flex items-center gap-4">
            <div class="w-12 h-12 rounded-xl bg-blue-50 border border-blue-100 flex items-center justify-center shrink-0">
                <svg class="w-6 h-6 text-blue-700" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            </div>
            <div>
                <span class="text-xs text-slate-400 font-medium uppercase tracking-wider block mb-0.5">Total Pertanyaan</span>
                <p class="text-2xl font-bold text-slate-900">{{ $template->questions->count() }} <span class="text-xs font-normal text-slate-400">Butir</span></p>
            </div>
        </div>

        <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-2xs flex items-center gap-4">
            <div class="w-12 h-12 rounded-xl bg-emerald-50 border border-emerald-100 flex items-center justify-center shrink-0">
                <svg class="w-6 h-6 text-emerald-700" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            </div>
            <div>
                <span class="text-xs text-slate-400 font-medium uppercase tracking-wider block mb-0.5">Butir Bernilai (Skor)</span>
                <p class="text-2xl font-bold text-emerald-700">{{ $template->questions->where('is_scored', true)->count() }} <span class="text-xs font-normal text-slate-400">Skor</span></p>
            </div>
        </div>

        <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-2xs flex items-center gap-4">
            <div class="w-12 h-12 rounded-xl bg-purple-50 border border-purple-100 flex items-center justify-center shrink-0">
                <svg class="w-6 h-6 text-purple-700" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>
            </div>
            <div>
                <span class="text-xs text-slate-400 font-medium uppercase tracking-wider block mb-0.5">Total Bagian / Sesi</span>
                <p class="text-2xl font-bold text-slate-900">{{ $template->sections->count() }} <span class="text-xs font-normal text-slate-400">Bagian</span></p>
            </div>
        </div>

        <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-2xs flex items-center gap-4">
            <div class="w-12 h-12 rounded-xl bg-amber-50 border border-amber-100 flex items-center justify-center shrink-0">
                <svg class="w-6 h-6 text-amber-700" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"></path></svg>
            </div>
            <div>
                <span class="text-xs text-slate-400 font-medium uppercase tracking-wider block mb-0.5">Non-Skor (Catatan)</span>
                <p class="text-2xl font-bold text-amber-700">{{ $template->questions->where('is_scored', false)->count() }} <span class="text-xs font-normal text-slate-400">Butir</span></p>
            </div>
        </div>
    </div>

    <!-- Search & Filter Controls -->
    <div class="filter-bar filter-controls filter-controls-many" style="--filter-columns: minmax(0,1.8fr) minmax(0,1.2fr) minmax(0,1fr) minmax(0,1.2fr) auto">
        <!-- Search Input -->
        <div class="filter-search">
            <svg class="filter-search-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
            <input 
                type="text" 
                x-model="searchQuery" aria-label="Cari pertanyaan atau indikator" 
                placeholder="Cari rumusan pertanyaan atau indikator..." 
                class="filter-control"
            >
        </div>

        <!-- Filter Dropdowns in Single Row -->
        <div class="contents">
            <!-- Filter Section -->
            <select 
                x-model="selectedSection" aria-label="Bagian pertanyaan" 
                class="filter-control"
            >
                <option value="">Semua Bagian ({{ $template->sections->count() }})</option>
                @foreach($template->sections as $sec)
                    <option value="{{ $sec->id }}">Bagian {{ $sec->sort_order }}: {{ \Illuminate\Support\Str::limit($sec->title, 20) }}</option>
                @endforeach
            </select>

            <!-- Filter Question Type -->
            <select 
                x-model="selectedType" aria-label="Tipe pertanyaan" 
                class="filter-control"
            >
                <option value="">Semua Tipe Soal</option>
                <option value="SCORE">Skala Nilai (0–4)</option>
                <option value="YES_NO">Pilihan Ya/Tidak</option>
                <option value="TEXTAREA">Catatan Teks/Esai</option>
                <option value="FILE">Unggah Berkas</option>
                <option value="LINK">Tautan SPADA</option>
            </select>

            <!-- Filter Scored Status -->
            <select 
                x-model="selectedScored" aria-label="Status penilaian" 
                class="filter-control"
            >
                <option value="">Semua Status Penilaian</option>
                <option value="scored">Hanya Bernilai Skor</option>
                <option value="non_scored">Hanya Non-Skor</option>
            </select>

            <button 
                type="button" 
                x-show="searchQuery || selectedSection || selectedType || selectedScored" 
                @click="resetFilters()" aria-label="Reset Filter" title="Reset Filter" x-cloak 
                class="filter-reset"
            >
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>
    </div>

    <!-- Question Bank Tree (Grouped by Sections) -->
    <div class="flex flex-col gap-5">
        @forelse($template->sections as $sec)
            <div 
                x-show="isSectionVisible({{ $sec->id }})" 
                class="bg-white rounded-2xl border border-slate-200/90 shadow-2xs overflow-hidden transition-all"
            >
                <!-- Section Header -->
                <div class="bg-gradient-to-r from-slate-50 via-slate-50/60 to-white px-6 py-4.5 border-b border-slate-200/90 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div class="flex flex-col justify-center">
                        <div class="flex items-center gap-3">
                            <span class="inline-flex items-center justify-center px-2.5 py-1 rounded-lg text-xs font-bold bg-rose-50 text-[#7B1113] border border-rose-100 shrink-0 leading-none">
                                Bagian {{ $sec->sort_order }}
                            </span>
                            <h2 class="text-base sm:text-lg font-bold text-slate-900 leading-none">{{ $sec->title }}</h2>
                        </div>
                        @if($sec->subtitle)
                            <p class="text-xs sm:text-sm text-slate-500 leading-relaxed mt-1.5">{{ $sec->subtitle }}</p>
                        @endif
                    </div>

                    <div class="flex items-center gap-3 shrink-0 self-end sm:self-auto">
                        <span class="text-xs font-semibold text-slate-500 bg-white px-3.5 py-1.5 rounded-xl border border-slate-200 shadow-2xs">
                            {{ $sec->questions->count() }} Butir
                        </span>
                        @unless($isUsed)
                        <button
                            type="button"
                            @click="openEditSectionModal({{ json_encode([
                                'id' => $sec->id,
                                'sort_order' => $sec->sort_order,
                                'title' => $sec->title,
                                'subtitle' => $sec->subtitle,
                            ]) }})"
                            class="inline-flex items-center px-3.5 py-1.5 rounded-xl text-xs font-semibold text-slate-700 hover:bg-slate-50 border border-slate-200 transition-colors shadow-2xs"
                            aria-label="Edit Bagian {{ $sec->sort_order }}: {{ $sec->title }}"
                        >
                            <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                            Edit Bagian
                        </button>
                        <button 
                            type="button" 
                            @click="openAddModal({{ $sec->id }})" 
                            class="inline-flex items-center px-3.5 py-1.5 rounded-xl text-xs font-semibold text-[#7B1113] hover:bg-rose-50 border border-rose-200 transition-colors shadow-2xs"
                        >
                            <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                            Tambah Butir
                        </button>
                        @endunless
                    </div>
                </div>

                <!-- Section Questions List -->
                <div class="divide-y divide-slate-100">
                    @forelse($sec->questions as $q)
                        @php
                            // Clean leading "Butir X: " if present in question text for human readability
                            $cleanQuestionText = preg_replace('/^Butir\s*\d+\s*:\s*/i', '', $q->question_text);
                        @endphp
                        <div 
                            x-show="matchesQuestion({{ $sec->id }}, '{{ addslashes($q->question_text) }}', '{{ $q->question_type }}', {{ $q->is_scored ? 'true' : 'false' }})" 
                            class="p-5 hover:bg-slate-50/50 transition-colors"
                        >
                            <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-4">
                                <div class="flex items-start gap-3.5 flex-1 min-w-0">
                                    <!-- Question Number Indicator -->
                                    <span class="w-7 h-7 rounded-full bg-slate-100 text-slate-700 text-xs font-bold flex items-center justify-center shrink-0 mt-0.5 border border-slate-200 shadow-2xs">
                                        {{ $loop->iteration }}
                                    </span>

                                    <div class="space-y-2.5 flex-1 min-w-0">
                                        <!-- Question Text -->
                                        <p class="text-[15px] font-semibold text-slate-900 leading-relaxed">
                                            {{ $cleanQuestionText }}
                                        </p>

                                        <!-- Help / Guidance Text -->
                                        @if($q->help_text)
                                            <p class="text-xs sm:text-sm text-slate-500 flex items-center gap-2 italic bg-slate-50/80 px-3.5 py-1.5 rounded-xl border border-slate-100/80">
                                                <svg class="w-4 h-4 text-slate-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                                <span>{{ $q->help_text }}</span>
                                            </p>
                                        @endif

                                        <!-- Badges: Clean and Human-Friendly -->
                                        <div class="flex items-center gap-2 flex-wrap pt-1 mb-2.5">
                                            @if($q->question_type === 'SCORE')
                                                <span class="inline-flex items-center px-3 py-1 rounded-lg text-xs font-medium bg-blue-50 text-blue-700 border border-blue-100">
                                                    Skala Nilai 0–4
                                                </span>
                                            @elseif($q->question_type === 'YES_NO')
                                                <span class="inline-flex items-center px-3 py-1 rounded-lg text-xs font-medium bg-emerald-50 text-emerald-700 border border-emerald-100">
                                                    Pilihan Ya / Tidak
                                                </span>
                                            @elseif($q->question_type === 'TEXTAREA')
                                                <span class="inline-flex items-center px-3 py-1 rounded-lg text-xs font-medium bg-purple-50 text-purple-700 border border-purple-100">
                                                    Catatan Teks / Esai
                                                </span>
                                            @elseif($q->question_type === 'FILE')
                                                <span class="inline-flex items-center px-3 py-1 rounded-lg text-xs font-medium bg-amber-50 text-amber-700 border border-amber-100">
                                                    Unggah Berkas Bukti
                                                </span>
                                            @elseif($q->question_type === 'LINK')
                                                <span class="inline-flex items-center px-3 py-1 rounded-lg text-xs font-medium bg-cyan-50 text-cyan-700 border border-cyan-100">
                                                    Tautan SPADA
                                                </span>
                                            @endif

                                            @if($q->is_required)
                                                <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-medium bg-rose-50 text-rose-700 border border-rose-100">
                                                    Wajib Diisi
                                                </span>
                                            @else
                                                <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-medium bg-slate-100 text-slate-500">
                                                    Opsional
                                                </span>
                                            @endif
                                        </div>
                                    </div>
                                </div>

                                @unless($isUsed)
                                <!-- Action Buttons: Edit and Delete -->
                                <div class="flex items-center gap-2 shrink-0 self-end sm:self-start pt-1 sm:pt-0">
                                    <button 
                                        type="button" 
                                        @click="openEditModal({{ json_encode([
                                            'id' => $q->id,
                                            'form_section_id' => $q->form_section_id,
                                            'question_text' => $q->question_text,
                                            'help_text' => $q->help_text,
                                            'question_type' => $q->question_type,
                                            'is_scored' => $q->is_scored,
                                            'is_required' => $q->is_required,
                                            'sort_order' => $q->sort_order,
                                            'options' => $q->options->map(fn($opt) => [
                                                'id' => $opt->id,
                                                'value' => $opt->value,
                                                'label' => preg_replace('/^[0-9]\s*[-–:]\s*/', '', $opt->label),
                                                'numeric_score' => $opt->numeric_score !== null ? (float) $opt->numeric_score : null,
                                            ])->values()->all(),
                                        ]) }})" 
                                        class="inline-flex items-center px-3.5 py-2 rounded-xl border border-slate-200 hover:border-slate-300 hover:bg-white text-slate-700 text-xs font-semibold shadow-2xs transition-colors"
                                        title="Edit Butir Pertanyaan"
                                    >
                                        <svg class="w-4 h-4 mr-1.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                                        Edit
                                    </button>

                                    <button 
                                        type="button" 
                                        @click="openDeleteModal({{ json_encode([
                                            'id' => $q->id,
                                            'question_text' => $cleanQuestionText,
                                        ]) }})" 
                                        class="inline-flex items-center px-3.5 py-2 rounded-xl border border-rose-200 hover:bg-rose-50 text-rose-700 text-xs font-semibold shadow-2xs transition-colors"
                                        title="Hapus Butir Pertanyaan"
                                    >
                                        <svg class="w-4 h-4 mr-1.5 text-rose-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                                        Hapus
                                    </button>
                                </div>
                                @endunless
                            </div>

                            <!-- Options Badges Display -->
                            @if($q->options->count() > 0)
                                <div class="mt-3.5 pt-3 border-t border-slate-100 flex flex-wrap items-center gap-2">
                                    <span class="text-xs font-medium text-slate-400 mr-1 flex items-center gap-1.5">
                                        <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                        Pilihan respon:
                                    </span>
                                    @foreach($q->options as $opt)
                                        @php
                                            $cleanOptLabel = preg_replace('/^[0-9]\s*[-–:]\s*/', '', $opt->label);
                                        @endphp
                                        <span class="inline-flex items-center gap-2 px-3 py-1 rounded-xl text-xs bg-slate-50/90 border border-slate-200/80 text-slate-700 shadow-2xs hover:bg-slate-100/80 transition-colors">
                                            @if(is_numeric($opt->value))
                                                <span class="w-4 h-4 rounded-full bg-slate-200 text-slate-700 flex items-center justify-center text-[10px] font-bold shrink-0">{{ $opt->value }}</span>
                                            @endif
                                            <span class="font-medium">{{ $cleanOptLabel }}</span>
                                        </span>
                                    @endforeach
                                </div>
                            @endif

                            <div class="mt-3 rounded-xl border border-amber-100 bg-amber-50/60 p-3">
                                <p class="text-xs font-semibold text-amber-900">Catatan Opsional</p>
                                <p class="mt-1 text-xs text-slate-600">Supervisor dapat mengisi catatan tambahan untuk butir ini saat melakukan supervisi.</p>
                            </div>
                        </div>
                    @empty
                        <p class="text-xs text-slate-400 italic p-8 text-center">Belum ada butir pertanyaan pada bagian ini.</p>
                    @endforelse
                </div>
            </div>
        @empty
            <div class="bg-white rounded-2xl border border-slate-200 p-10 text-center">
                <svg class="w-12 h-12 text-slate-300 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 13h6m-3-3v6m5 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                <h3 class="text-base font-bold text-slate-800">Belum Ada Bagian & Pertanyaan</h3>
                <p class="text-xs sm:text-sm text-slate-500 mt-1">Mulai dengan menambahkan bagian atau butir pertanyaan baru.</p>
                @unless($isUsed)
                <button type="button" @click="openAddSectionModal()" class="mt-5 inline-flex items-center px-5 py-2.5 rounded-xl bg-[#7B1113] text-white text-xs font-semibold shadow-xs hover:bg-[#600d0e] transition-colors">
                    Tambah Bagian Pertama
                </button>
                @endunless
            </div>
        @endforelse
    </div>

    <!-- ========================================== -->
    <!-- MODAL 1: TAMBAH PERTANYAAN                -->
    <!-- ========================================== -->
    <div 
        x-show="addModalOpen" 
        x-cloak 
        class="fixed inset-0 z-50 overflow-y-auto" 
        aria-labelledby="modal-title" 
        role="dialog" 
        aria-modal="true"
    >
        <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
            <div 
                x-show="addModalOpen" 
                @click="addModalOpen = false; toggleBodyScroll()" 
                x-transition:enter="ease-out duration-300" 
                x-transition:enter-start="opacity-0" 
                x-transition:enter-end="opacity-100" 
                x-transition:leave="ease-in duration-200" 
                x-transition:leave-start="opacity-100" 
                x-transition:leave-end="opacity-0" 
                class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm transition-opacity"
            ></div>

            <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>

            <div 
                x-show="addModalOpen" 
                x-transition:enter="ease-out duration-300" 
                x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" 
                x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100" 
                x-transition:leave="ease-in duration-200" 
                x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100" 
                x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" 
                class="relative z-10 inline-block align-bottom bg-white rounded-2xl text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-2xl sm:w-full border border-slate-200"
            >
                <form action="{{ route('lpm.instruments.questions.store', $template) }}" method="POST">
                    @csrf
                    <div class="p-6 sm:p-7 border-b border-slate-100 bg-slate-50 flex items-center justify-between">
                        <div>
                            <h3 class="text-base sm:text-lg font-bold text-slate-900" id="modal-title">Tambah Butir Pertanyaan Baru</h3>
                            <p class="text-xs text-slate-500 mt-1">Instrumen Evaluasi {{ $mode === 'offline' ? 'Tatap Muka (Offline)' : 'Daring (Online)' }}</p>
                        </div>
                        <button type="button" @click="addModalOpen = false; toggleBodyScroll()" class="text-slate-400 hover:text-slate-600 font-bold text-lg p-1">&times;</button>
                    </div>

                    <div class="p-6 sm:p-7 space-y-5 max-h-[70vh] overflow-y-auto text-xs sm:text-sm">
                        <!-- Select Section -->
                        <div>
                            <label class="block font-semibold text-slate-700 mb-1.5">Pilih Bagian / Sesi <span class="text-red-500">*</span></label>
                            <select name="form_section_id" x-model="formData.form_section_id" required class="w-full text-xs sm:text-sm rounded-xl border border-slate-200 p-3 focus:ring-2 focus:ring-[#7B1113]/20 focus:border-[#7B1113]">
                                @foreach($template->sections as $sec)
                                    <option value="{{ $sec->id }}">Bagian {{ $sec->sort_order }}: {{ $sec->title }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label class="block font-semibold text-slate-700 mb-1.5">
                                Urutan Butir <span class="text-slate-400 font-normal">(Opsional)</span>
                            </label>
                            <input type="number" name="sort_order" placeholder="Otomatis di urutan terakhir jika kosong" min="1" class="w-full text-xs sm:text-sm rounded-xl border border-slate-200 p-3 focus:ring-2 focus:ring-[#7B1113]/20 focus:border-[#7B1113]">
                        </div>

                        <!-- Question Text -->
                        <div>
                            <label class="block font-semibold text-slate-700 mb-1.5">Rumusan Pertanyaan / Indikator Penilaian <span class="text-red-500">*</span></label>
                            <textarea name="question_text" rows="3" required placeholder="Contoh: Dosen menjelaskan capaian pembelajaran dan kontrak kuliah secara terstruktur..." class="w-full text-xs sm:text-sm rounded-xl border border-slate-200 p-3 focus:ring-2 focus:ring-[#7B1113]/20 focus:border-[#7B1113]"></textarea>
                        </div>

                        <!-- Help Text -->
                        <div>
                            <label class="block font-semibold text-slate-700 mb-1.5">Panduan Pengisian untuk Supervisor <span class="text-slate-400 font-normal">(Opsional)</span></label>
                            <input type="text" name="help_text" placeholder="Contoh: Beri penilaian berdasarkan pengamatan langsung saat perkuliahan berlangsung..." class="w-full text-xs sm:text-sm rounded-xl border border-slate-200 p-3 focus:ring-2 focus:ring-[#7B1113]/20 focus:border-[#7B1113]">
                        </div>

                        <!-- Question Type & Toggles -->
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 pt-3 border-t border-slate-100">
                            <div>
                                <label class="block font-semibold text-slate-700 mb-1.5">Tipe Pertanyaan <span class="text-red-500">*</span></label>
                                <select name="question_type" x-model="formData.question_type" @change="onTypeChange()" required class="w-full text-xs sm:text-sm rounded-xl border border-slate-200 p-3 font-medium focus:ring-2 focus:ring-[#7B1113]/20 focus:border-[#7B1113]">
                                    <option value="SCORE">Skala Nilai 0 - 4 (Penilaian Mutu)</option>
                                    <option value="YES_NO">Pilihan Ya / Tidak</option>
                                    <option value="TEXTAREA">Catatan Teks / Esai</option>
                                    <option value="FILE">Unggah Berkas / Dokumen / Foto</option>
                                    <option value="LINK">Tautan URL SPADA</option>
                                </select>
                            </div>

                            <div class="flex items-center sm:pt-6">
                                <label class="flex items-center gap-2.5 cursor-pointer">
                                    <input type="checkbox" name="is_scored" value="1" x-model="formData.is_scored" class="w-4 h-4 text-[#7B1113] rounded border-slate-300 focus:ring-[#7B1113]">
                                    <span class="font-semibold text-slate-800">Dihitung Skor</span>
                                </label>
                            </div>

                            <div class="flex items-center sm:pt-6">
                                <label class="flex items-center gap-2.5 cursor-pointer">
                                    <input type="checkbox" name="is_required" value="1" x-model="formData.is_required" class="w-4 h-4 text-[#7B1113] rounded border-slate-300 focus:ring-[#7B1113]">
                                    <span class="font-semibold text-slate-800">Wajib Diisi</span>
                                </label>
                            </div>
                        </div>

                        <!-- Options Configurator (Only for SCORE and YES_NO) -->
                        <div x-show="formData.question_type === 'SCORE' || formData.question_type === 'YES_NO'" class="pt-4 border-t border-slate-100 space-y-3">
                            <div class="flex items-center justify-between">
                                <label class="font-bold text-slate-800">Pilihan Respon Jawaban</label>
                                <div class="flex items-center gap-3">
                                    <button type="button" @click="resetToDefaultScale()" class="text-xs text-blue-700 hover:underline">
                                        Muat Skala Default (0-4)
                                    </button>
                                    <button type="button" @click="addOptionRow()" class="text-xs text-[#7B1113] font-bold hover:underline">
                                        + Tambah Pilihan
                                    </button>
                                </div>
                            </div>

                            <div class="space-y-2.5">
                                <template x-for="(opt, idx) in formData.options" :key="idx">
                                    <div class="flex items-center gap-3 bg-slate-50 p-3 rounded-xl border border-slate-200">
                                        <div class="w-16">
                                            <input type="text" :name="'options[' + idx + '][value]'" x-model="opt.value" placeholder="Nilai" class="w-full text-xs font-semibold rounded-lg border border-slate-300 p-2 text-center" required>
                                        </div>
                                        <div class="flex-1">
                                            <input type="text" :name="'options[' + idx + '][label]'" x-model="opt.label" placeholder="Teks Label Pilihan" class="w-full text-xs rounded-lg border border-slate-300 p-2" required>
                                        </div>
                                        <div class="w-20">
                                            <input type="number" step="0.5" min="0" max="10" :name="'options[' + idx + '][numeric_score]'" x-model="opt.numeric_score" placeholder="Skor" class="w-full text-xs font-semibold rounded-lg border border-slate-300 p-2 text-center">
                                        </div>
                                        <button type="button" @click="removeOptionRow(idx)" class="text-rose-600 hover:text-rose-800 p-1.5" title="Hapus Pilihan">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                                        </button>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>

                    <div class="p-5 bg-slate-50 border-t border-slate-200 flex items-center justify-end gap-3">
                        <button type="button" @click="addModalOpen = false; toggleBodyScroll()" class="px-5 py-2.5 rounded-xl border border-slate-300 text-xs font-semibold text-slate-700 hover:bg-slate-100 transition-colors">
                            Batal
                        </button>
                        <button type="submit" class="px-6 py-2.5 rounded-xl bg-[#7B1113] hover:bg-[#600d0e] text-white text-xs font-semibold shadow-xs transition-colors">
                            Simpan Pertanyaan
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ========================================== -->
    <!-- MODAL 2: EDIT PERTANYAAN                  -->
    <!-- ========================================== -->
    <div 
        x-show="editModalOpen" 
        x-cloak 
        class="fixed inset-0 z-50 overflow-y-auto" 
        aria-labelledby="modal-edit-title" 
        role="dialog" 
        aria-modal="true"
    >
        <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
            <div 
                x-show="editModalOpen" 
                @click="editModalOpen = false; toggleBodyScroll()" 
                class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm transition-opacity"
            ></div>

            <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>

            <div 
                x-show="editModalOpen" 
                class="relative z-10 inline-block align-bottom bg-white rounded-2xl text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-2xl sm:w-full border border-slate-200"
            >
                <form :action="'{{ url('lpm/instruments/questions') }}/' + editData.id" method="POST">
                    @csrf
                    @method('PUT')
                    <div class="p-6 sm:p-7 border-b border-slate-100 bg-slate-50 flex items-center justify-between">
                        <div>
                            <h3 class="text-base sm:text-lg font-bold text-slate-900" id="modal-edit-title">
                                Edit Butir Pertanyaan
                            </h3>
                            <p class="text-xs text-slate-500 mt-1">Perbarui rumusan pertanyaan, panduan pengisian, atau pilihan jawaban</p>
                        </div>
                        <button type="button" @click="editModalOpen = false; toggleBodyScroll()" class="text-slate-400 hover:text-slate-600 font-bold text-lg p-1">&times;</button>
                    </div>

                    <div class="p-6 sm:p-7 space-y-5 max-h-[70vh] overflow-y-auto text-xs sm:text-sm">
                        <!-- Select Section -->
                        <div>
                            <label class="block font-semibold text-slate-700 mb-1.5">Pilih Bagian / Sesi <span class="text-red-500">*</span></label>
                            <select name="form_section_id" x-model="editData.form_section_id" required class="w-full text-xs sm:text-sm rounded-xl border border-slate-200 p-3 focus:ring-2 focus:ring-[#7B1113]/20 focus:border-[#7B1113]">
                                @foreach($template->sections as $sec)
                                    <option value="{{ $sec->id }}">Bagian {{ $sec->sort_order }}: {{ $sec->title }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label class="block font-semibold text-slate-700 mb-1.5">Urutan dalam Bagian <span class="text-red-500">*</span></label>
                            <input type="number" name="sort_order" x-model="editData.sort_order" required min="1" class="w-full text-xs sm:text-sm rounded-xl border border-slate-200 p-3 focus:ring-2 focus:ring-[#7B1113]/20 focus:border-[#7B1113]">
                        </div>

                        <!-- Question Text -->
                        <div>
                            <label class="block font-semibold text-slate-700 mb-1.5">Rumusan Pertanyaan / Indikator Penilaian <span class="text-red-500">*</span></label>
                            <textarea name="question_text" x-model="editData.question_text" rows="3" required class="w-full text-xs sm:text-sm rounded-xl border border-slate-200 p-3 focus:ring-2 focus:ring-[#7B1113]/20 focus:border-[#7B1113]"></textarea>
                        </div>

                        <!-- Help Text -->
                        <div>
                            <label class="block font-semibold text-slate-700 mb-1.5">Panduan Pengisian untuk Supervisor <span class="text-slate-400 font-normal">(Opsional)</span></label>
                            <input type="text" name="help_text" x-model="editData.help_text" class="w-full text-xs sm:text-sm rounded-xl border border-slate-200 p-3 focus:ring-2 focus:ring-[#7B1113]/20 focus:border-[#7B1113]">
                        </div>

                        <!-- Question Type & Toggles -->
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 pt-3 border-t border-slate-100">
                            <div>
                                <label class="block font-semibold text-slate-700 mb-1.5">Tipe Pertanyaan <span class="text-red-500">*</span></label>
                                <select name="question_type" x-model="editData.question_type" required class="w-full text-xs sm:text-sm rounded-xl border border-slate-200 p-3 font-medium focus:ring-2 focus:ring-[#7B1113]/20 focus:border-[#7B1113]">
                                    <option value="SCORE">Skala Nilai 0 - 4 (Penilaian Mutu)</option>
                                    <option value="YES_NO">Pilihan Ya / Tidak</option>
                                    <option value="TEXTAREA">Catatan Teks / Esai</option>
                                    <option value="FILE">Unggah Berkas / Dokumen / Foto</option>
                                    <option value="LINK">Tautan URL SPADA</option>
                                </select>
                            </div>

                            <div class="flex items-center sm:pt-6">
                                <label class="flex items-center gap-2.5 cursor-pointer">
                                    <input type="checkbox" name="is_scored" value="1" x-model="editData.is_scored" class="w-4 h-4 text-[#7B1113] rounded border-slate-300 focus:ring-[#7B1113]">
                                    <span class="font-semibold text-slate-800">Dihitung Skor</span>
                                </label>
                            </div>

                            <div class="flex items-center sm:pt-6">
                                <label class="flex items-center gap-2.5 cursor-pointer">
                                    <input type="checkbox" name="is_required" value="1" x-model="editData.is_required" class="w-4 h-4 text-[#7B1113] rounded border-slate-300 focus:ring-[#7B1113]">
                                    <span class="font-semibold text-slate-800">Wajib Diisi</span>
                                </label>
                            </div>
                        </div>

                        <!-- Options Configurator for Edit -->
                        <div x-show="editData.question_type === 'SCORE' || editData.question_type === 'YES_NO'" class="pt-4 border-t border-slate-100 space-y-3">
                            <div class="flex items-center justify-between">
                                <label class="font-bold text-slate-800">Pilihan Respon Jawaban</label>
                                <button type="button" @click="addEditOptionRow()" class="text-xs text-[#7B1113] font-bold hover:underline">
                                    + Tambah Pilihan
                                </button>
                            </div>

                            <div class="space-y-2.5">
                                <template x-for="(opt, idx) in editData.options" :key="idx">
                                    <div class="flex items-center gap-3 bg-slate-50 p-3 rounded-xl border border-slate-200">
                                        <input type="hidden" :name="'options[' + idx + '][id]'" :value="opt.id">
                                        <div class="w-16">
                                            <input type="text" :name="'options[' + idx + '][value]'" x-model="opt.value" placeholder="Nilai" class="w-full text-xs font-semibold rounded-lg border border-slate-300 p-2 text-center" required>
                                        </div>
                                        <div class="flex-1">
                                            <input type="text" :name="'options[' + idx + '][label]'" x-model="opt.label" placeholder="Teks Label Pilihan" class="w-full text-xs rounded-lg border border-slate-300 p-2" required>
                                        </div>
                                        <div class="w-20">
                                            <input type="number" step="0.5" min="0" max="10" :name="'options[' + idx + '][numeric_score]'" x-model="opt.numeric_score" placeholder="Skor" class="w-full text-xs font-semibold rounded-lg border border-slate-300 p-2 text-center">
                                        </div>
                                        <button type="button" @click="removeEditOptionRow(idx)" class="text-rose-600 hover:text-rose-800 p-1.5" title="Hapus Pilihan">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                                        </button>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>

                    <div class="p-5 bg-slate-50 border-t border-slate-200 flex items-center justify-end gap-3">
                        <button type="button" @click="editModalOpen = false; toggleBodyScroll()" class="px-5 py-2.5 rounded-xl border border-slate-300 text-xs font-semibold text-slate-700 hover:bg-slate-100 transition-colors">
                            Batal
                        </button>
                        <button type="submit" class="px-6 py-2.5 rounded-xl bg-[#7B1113] hover:bg-[#600d0e] text-white text-xs font-semibold shadow-xs transition-colors">
                            Simpan Perubahan
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ========================================== -->
    <!-- MODAL 3: HAPUS PERTANYAAN                 -->
    <!-- ========================================== -->
    <div 
        x-show="deleteModalOpen" 
        x-cloak 
        class="fixed inset-0 z-50 overflow-y-auto" 
        aria-labelledby="modal-del-title" 
        role="dialog" 
        aria-modal="true"
    >
        <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
            <div 
                x-show="deleteModalOpen" 
                @click="deleteModalOpen = false; toggleBodyScroll()" 
                class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm transition-opacity"
            ></div>

            <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>

            <div 
                x-show="deleteModalOpen" 
                class="relative z-10 inline-block align-bottom bg-white rounded-2xl text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-md sm:w-full border border-slate-200"
            >
                <form :action="'{{ url('lpm/instruments/questions') }}/' + deleteData.id" method="POST">
                    @csrf
                    @method('DELETE')
                    <div class="p-7">
                        <div class="w-14 h-14 rounded-full bg-rose-100 text-rose-700 flex items-center justify-center mx-auto mb-5 shadow-2xs">
                            <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                        </div>

                        <h3 class="text-lg font-bold text-slate-900 text-center" id="modal-del-title">
                            Hapus Butir Pertanyaan?
                        </h3>
                        <p class="text-xs sm:text-sm text-slate-500 text-center mt-2.5 leading-relaxed">
                            Apakah Anda yakin ingin menghapus butir pertanyaan ini? Tindakan ini tidak dapat dibatalkan.
                        </p>

                        <div class="mt-4 p-4 bg-slate-50 rounded-xl border border-slate-200 text-xs sm:text-sm text-slate-700 text-center font-medium leading-relaxed">
                            "<span x-text="deleteData.question_text"></span>"
                        </div>
                    </div>

                    <div class="p-5 bg-slate-50 border-t border-slate-200 flex items-center justify-end gap-3">
                        <button type="button" @click="deleteModalOpen = false; toggleBodyScroll()" class="px-5 py-2.5 rounded-xl border border-slate-300 text-xs font-semibold text-slate-700 hover:bg-slate-100 transition-colors">
                            Batal
                        </button>
                        <button type="submit" class="px-6 py-2.5 rounded-xl bg-rose-600 hover:bg-rose-700 text-white text-xs font-semibold shadow-xs transition-colors">
                            Ya, Hapus Butir
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ========================================== -->
    <!-- MODAL 4: TAMBAH BAGIAN / SESI             -->
    <!-- ========================================== -->
    <div 
        x-show="addSectionModalOpen" 
        x-cloak 
        class="fixed inset-0 z-50 overflow-y-auto" 
        aria-labelledby="modal-sec-title" 
        role="dialog" 
        aria-modal="true"
    >
        <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
            <div 
                x-show="addSectionModalOpen" 
                @click="addSectionModalOpen = false; toggleBodyScroll()" 
                class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm transition-opacity"
            ></div>

            <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>

            <div 
                x-show="addSectionModalOpen" 
                class="relative z-10 inline-block align-bottom bg-white rounded-2xl text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full border border-slate-200"
            >
                <form action="{{ route('lpm.instruments.sections.store', $template) }}" method="POST">
                    @csrf
                    <div class="p-6 sm:p-7 border-b border-slate-100 bg-slate-50 flex items-center justify-between">
                        <div>
                            <h3 class="text-base sm:text-lg font-bold text-slate-900" id="modal-sec-title">Tambah Bagian / Sesi Baru</h3>
                            <p class="text-xs text-slate-500 mt-1">Instrumen Evaluasi {{ $mode === 'offline' ? 'Tatap Muka (Offline)' : 'Daring (Online)' }}</p>
                        </div>
                        <button type="button" @click="addSectionModalOpen = false; toggleBodyScroll()" class="text-slate-400 hover:text-slate-600 font-bold text-lg p-1">&times;</button>
                    </div>

                    <div class="p-6 sm:p-7 space-y-5 text-xs sm:text-sm">
                        <div>
                            <label class="block font-semibold text-slate-700 mb-1.5">Nomor Urutan</label>
                            <input type="number" name="sort_order" value="{{ $template->sections->count() + 1 }}" min="1" class="w-full text-xs sm:text-sm rounded-xl border border-slate-200 p-3 focus:ring-2 focus:ring-[#7B1113]/20 focus:border-[#7B1113]">
                        </div>

                        <div>
                            <label class="block font-semibold text-slate-700 mb-1.5">Judul Bagian / Sesi <span class="text-red-500">*</span></label>
                            <input type="text" name="title" required placeholder="Contoh: Penilaian Pembukaan Perkuliahan" class="w-full text-xs sm:text-sm rounded-xl border border-slate-200 p-3 focus:ring-2 focus:ring-[#7B1113]/20 focus:border-[#7B1113]">
                        </div>

                        <div>
                            <label class="block font-semibold text-slate-700 mb-1.5">Keterangan / Subtitle <span class="text-slate-400 font-normal">(Opsional)</span></label>
                            <textarea name="subtitle" rows="2" placeholder="Deskripsi fokus aspek penilaian pada sesi ini..." class="w-full text-xs sm:text-sm rounded-xl border border-slate-200 p-3 focus:ring-2 focus:ring-[#7B1113]/20 focus:border-[#7B1113]"></textarea>
                        </div>
                    </div>

                    <div class="p-5 bg-slate-50 border-t border-slate-200 flex items-center justify-end gap-3">
                        <button type="button" @click="addSectionModalOpen = false; toggleBodyScroll()" class="px-5 py-2.5 rounded-xl border border-slate-300 text-xs font-semibold text-slate-700 hover:bg-slate-100 transition-colors">
                            Batal
                        </button>
                        <button type="submit" class="px-6 py-2.5 rounded-xl bg-[#7B1113] hover:bg-[#600d0e] text-white text-xs font-semibold shadow-xs transition-colors">
                            Simpan Bagian
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    @unless($isUsed)
    <!-- ========================================== -->
    <!-- MODAL: EDIT BAGIAN / SESI                  -->
    <!-- ========================================== -->
    <div
        x-show="editSectionModalOpen"
        x-cloak
        class="fixed inset-0 z-50 overflow-y-auto"
        aria-labelledby="modal-edit-section-title"
        role="dialog"
        aria-modal="true"
    >
        <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
            <div
                x-show="editSectionModalOpen"
                @click="closeEditSectionModal()"
                class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm transition-opacity"
            ></div>

            <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>

            <div
                x-show="editSectionModalOpen"
                class="relative z-10 inline-block align-bottom bg-white rounded-2xl text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full border border-slate-200"
            >
                <form :action="'{{ url('lpm/instruments/sections') }}/' + editSectionData.id" method="POST">
                    @csrf
                    @method('PUT')
                    <div class="p-6 sm:p-7 border-b border-slate-100 bg-slate-50 flex items-center justify-between">
                        <h3 class="text-base sm:text-lg font-bold text-slate-900" id="modal-edit-section-title">Edit Bagian / Sesi</h3>
                        <button type="button" @click="closeEditSectionModal()" class="text-slate-400 hover:text-slate-600 font-bold text-lg p-1" aria-label="Tutup edit bagian">&times;</button>
                    </div>

                    <div class="p-6 sm:p-7 space-y-5 text-xs sm:text-sm">
                        <div>
                            <label for="edit-section-sort-order" class="block font-semibold text-slate-700 mb-1.5">Nomor Urutan <span class="text-red-500">*</span></label>
                            <input id="edit-section-sort-order" type="number" name="sort_order" x-model="editSectionData.sort_order" required min="1" class="w-full text-xs sm:text-sm rounded-xl border border-slate-200 p-3 focus:ring-2 focus:ring-[#7B1113]/20 focus:border-[#7B1113]">
                        </div>

                        <div>
                            <label for="edit-section-title" class="block font-semibold text-slate-700 mb-1.5">Judul Bagian / Sesi <span class="text-red-500">*</span></label>
                            <input id="edit-section-title" type="text" name="title" x-model="editSectionData.title" required maxlength="255" class="w-full text-xs sm:text-sm rounded-xl border border-slate-200 p-3 focus:ring-2 focus:ring-[#7B1113]/20 focus:border-[#7B1113]">
                        </div>

                        <div>
                            <label for="edit-section-subtitle" class="block font-semibold text-slate-700 mb-1.5">Keterangan / Subtitle <span class="text-slate-400 font-normal">(Opsional)</span></label>
                            <textarea id="edit-section-subtitle" name="subtitle" x-model="editSectionData.subtitle" rows="2" class="w-full text-xs sm:text-sm rounded-xl border border-slate-200 p-3 focus:ring-2 focus:ring-[#7B1113]/20 focus:border-[#7B1113]"></textarea>
                        </div>
                    </div>

                    <div class="p-5 bg-slate-50 border-t border-slate-200 flex items-center justify-end gap-3">
                        <button type="button" @click="closeEditSectionModal()" class="px-5 py-2.5 rounded-xl border border-slate-300 text-xs font-semibold text-slate-700 hover:bg-slate-100 transition-colors">Batal</button>
                        <button type="submit" class="px-6 py-2.5 rounded-xl bg-[#7B1113] hover:bg-[#600d0e] text-white text-xs font-semibold shadow-xs transition-colors">Simpan Perubahan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    @endunless

    <!-- ========================================== -->
    <!-- MODAL 5: KEBIJAKAN SKOR TERDAFTAR (D02)   -->
    <!-- ========================================== -->
    <div 
        x-show="showPoliciesModal" 
        x-cloak 
        class="fixed inset-0 z-50 overflow-y-auto" 
        role="dialog" 
        aria-modal="true"
    >
        <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
            <div 
                x-show="showPoliciesModal" 
                @click="showPoliciesModal = false; toggleBodyScroll()" 
                class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm transition-opacity"
            ></div>

            <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>

            <div 
                x-show="showPoliciesModal" 
                class="relative z-10 inline-block align-bottom bg-white rounded-2xl text-left overflow-hidden shadow-2xl transform transition-all sm:my-8 sm:align-middle sm:max-w-2xl sm:w-full border border-slate-200"
            >
                <div class="px-6 py-5 border-b border-slate-100 bg-gradient-to-r from-slate-50 via-slate-50/60 to-white flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-rose-50 text-[#7B1113] border border-rose-100 flex items-center justify-center shrink-0 shadow-2xs">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path></svg>
                        </div>
                        <div>
                            <h3 class="text-base sm:text-lg font-bold text-slate-900">Kebijakan Perhitungan Skor Supervisi</h3>
                            <p class="text-xs text-slate-500">Standar formula penilaian mutu pembelajaran dosen yang terdaftar di LPM</p>
                        </div>
                    </div>
                    <button type="button" @click="showPoliciesModal = false; toggleBodyScroll()" class="text-slate-400 hover:text-slate-600 font-bold text-xl p-1.5 rounded-lg hover:bg-slate-100 transition-colors">&times;</button>
                </div>

                <div class="p-6 space-y-3.5 max-h-[70vh] overflow-y-auto">
                    @foreach($policies as $pol)
                        @php
                            $isDefault = $pol->strategy === 'MEAN_0_4' || str_contains($pol->code, 'MEAN');
                        @endphp
                        <div class="bg-white rounded-xl p-4.5 border {{ $isDefault ? 'border-emerald-200/90 bg-emerald-50/20 shadow-xs' : 'border-slate-200/90 shadow-2xs' }} transition-all">
                            <div class="flex items-start justify-between gap-3 mb-2">
                                <div class="flex items-center gap-2.5 flex-wrap">
                                    <h4 class="text-sm font-bold text-slate-900">{{ $pol->human_title }}</h4>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[11px] font-semibold border {{ $isDefault ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-slate-100 text-slate-600 border-slate-200' }}">
                                        {{ $isDefault ? 'Kebijakan Utama (Default)' : 'Kebijakan Alternatif' }}
                                    </span>
                                </div>
                                <span class="text-xs font-bold text-slate-800 bg-white border border-slate-200 px-2.5 py-1 rounded-lg shrink-0 shadow-2xs">
                                    Skala {{ number_format($pol->min_score, 2) }} – {{ number_format($pol->max_score, 2) }}
                                </span>
                            </div>

                            <p class="text-xs text-slate-600 leading-relaxed mb-3">
                                {{ $pol->human_description }}
                            </p>

                            <div class="pt-2.5 border-t border-slate-100 flex items-center justify-between text-[11px] text-slate-500">
                                <div class="flex items-center gap-1.5">
                                    <span class="font-medium text-slate-400">Metode Formula:</span>
                                    <span class="font-semibold text-slate-700 bg-slate-50 px-2 py-0.5 rounded border border-slate-200/60">{{ $pol->human_formula }}</span>
                                </div>
                                <span class="text-emerald-700 font-semibold flex items-center gap-1">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                                    Terverifikasi LPM
                                </span>
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="px-6 py-4 bg-slate-50 border-t border-slate-200 flex items-center justify-between">
                    <span class="text-xs text-slate-500">
                        Formula diterapkan otomatis saat kalkulasi hasil supervisi perkuliahan.
                    </span>
                    <button type="button" @click="showPoliciesModal = false; toggleBodyScroll()" class="px-5 py-2 rounded-xl bg-white hover:bg-slate-100 border border-slate-200 text-xs font-semibold text-slate-700 transition-colors shadow-2xs">
                        Tutup
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function questionBankApp() {
    return {
        searchQuery: '',
        selectedSection: '',
        selectedType: '',
        selectedScored: '',
        addModalOpen: false,
        editModalOpen: false,
        deleteModalOpen: false,
        addSectionModalOpen: false,
        editSectionModalOpen: false,
        showPoliciesModal: false,

        get anyModalOpen() {
            return this.addModalOpen || this.editModalOpen || this.deleteModalOpen || this.addSectionModalOpen || this.editSectionModalOpen || this.showPoliciesModal;
        },

        toggleBodyScroll() {
            if (this.anyModalOpen) {
                document.body.style.overflow = 'hidden';
            } else {
                document.body.style.overflow = '';
            }
        },

        formData: {
            form_section_id: '{{ $template->sections->first()?->id }}',
            question_type: 'SCORE',
            is_scored: true,
            is_required: true,
            options: [
                { value: '0', label: 'Sangat Kurang / Tidak Dilakukan', numeric_score: 0.0 },
                { value: '1', label: 'Kurang Memenuhi', numeric_score: 1.0 },
                { value: '2', label: 'Cukup Memenuhi', numeric_score: 2.0 },
                { value: '3', label: 'Baik / Sesuai Standar', numeric_score: 3.0 },
                { value: '4', label: 'Sangat Baik / Melampaui Standar', numeric_score: 4.0 },
            ]
        },

        editData: {
            id: null,
            form_section_id: null,
            question_text: '',
            help_text: '',
            question_type: 'SCORE',
            is_scored: true,
            is_required: true,
            sort_order: 1,
            options: []
        },

        deleteData: {
            id: null,
            question_text: ''
        },

        editSectionData: {
            id: null,
            sort_order: 1,
            title: '',
            subtitle: ''
        },

        resetFilters() {
            this.searchQuery = '';
            this.selectedSection = '';
            this.selectedType = '';
            this.selectedScored = '';
        },

        isSectionVisible(sectionId) {
            if (this.selectedSection && this.selectedSection != sectionId) {
                return false;
            }
            return true;
        },

        matchesQuestion(sectionId, text, type, isScored) {
            if (this.selectedSection && this.selectedSection != sectionId) {
                return false;
            }
            if (this.selectedType && this.selectedType !== type) {
                return false;
            }
            if (this.selectedScored === 'scored' && !isScored) {
                return false;
            }
            if (this.selectedScored === 'non_scored' && isScored) {
                return false;
            }
            if (this.searchQuery.trim()) {
                const q = this.searchQuery.toLowerCase();
                return text.toLowerCase().includes(q);
            }
            return true;
        },

        openAddModal(preselectedSectionId = null) {
            if (preselectedSectionId) {
                this.formData.form_section_id = preselectedSectionId;
            }
            this.addModalOpen = true;
            this.toggleBodyScroll();
        },

        closeAddModal() {
            this.addModalOpen = false;
            this.toggleBodyScroll();
        },

        openAddSectionModal() {
            this.addSectionModalOpen = true;
            this.toggleBodyScroll();
        },

        closeAddSectionModal() {
            this.addSectionModalOpen = false;
            this.toggleBodyScroll();
        },

        openEditSectionModal(section) {
            this.editSectionData = {
                id: section.id,
                sort_order: section.sort_order,
                title: section.title,
                subtitle: section.subtitle || ''
            };
            this.editSectionModalOpen = true;
            this.toggleBodyScroll();
        },

        closeEditSectionModal() {
            this.editSectionModalOpen = false;
            this.toggleBodyScroll();
        },

        onTypeChange() {
            if (this.formData.question_type === 'SCORE') {
                this.formData.is_scored = true;
                this.resetToDefaultScale();
            } else if (this.formData.question_type === 'YES_NO') {
                this.formData.is_scored = false;
                this.formData.options = [
                    { value: 'YES', label: 'Ya, tersedia', numeric_score: 4.0 },
                    { value: 'NO', label: 'Tidak tersedia', numeric_score: 0.0 }
                ];
            } else {
                this.formData.is_scored = false;
                this.formData.options = [];
            }
        },

        resetToDefaultScale() {
            this.formData.options = [
                { value: '0', label: 'Sangat Kurang / Tidak Dilakukan', numeric_score: 0.0 },
                { value: '1', label: 'Kurang Memenuhi', numeric_score: 1.0 },
                { value: '2', label: 'Cukup Memenuhi', numeric_score: 2.0 },
                { value: '3', label: 'Baik / Sesuai Standar', numeric_score: 3.0 },
                { value: '4', label: 'Sangat Baik / Melampaui Standar', numeric_score: 4.0 },
            ];
        },

        addOptionRow() {
            const nextVal = this.formData.options.length.toString();
            this.formData.options.push({
                value: nextVal,
                label: 'Pilihan ' + nextVal,
                numeric_score: parseFloat(nextVal) || 0.0
            });
        },

        removeOptionRow(idx) {
            this.formData.options.splice(idx, 1);
        },

        openEditModal(q) {
            this.editData = {
                id: q.id,
                form_section_id: q.form_section_id,
                question_text: q.question_text,
                help_text: q.help_text || '',
                question_type: q.question_type,
                is_scored: Boolean(q.is_scored),
                is_required: Boolean(q.is_required),
                sort_order: q.sort_order,
                options: q.options ? JSON.parse(JSON.stringify(q.options)) : []
            };
            this.editModalOpen = true;
            this.toggleBodyScroll();
        },

        addEditOptionRow() {
            const nextVal = this.editData.options.length.toString();
            this.editData.options.push({
                id: null,
                value: nextVal,
                label: 'Pilihan ' + nextVal,
                numeric_score: parseFloat(nextVal) || 0.0
            });
        },

        removeEditOptionRow(idx) {
            this.editData.options.splice(idx, 1);
        },

        openDeleteModal(q) {
            this.deleteData = {
                id: q.id,
                question_text: q.question_text
            };
            this.deleteModalOpen = true;
            this.toggleBodyScroll();
        },

        closeEditModal() {
            this.editModalOpen = false;
            this.toggleBodyScroll();
        },

        closeDeleteModal() {
            this.deleteModalOpen = false;
            this.toggleBodyScroll();
        },

        openPoliciesModal() {
            this.showPoliciesModal = true;
            this.toggleBodyScroll();
        },

        closePoliciesModal() {
            this.showPoliciesModal = false;
            this.toggleBodyScroll();
        }
    };
}
</script>
@endsection
