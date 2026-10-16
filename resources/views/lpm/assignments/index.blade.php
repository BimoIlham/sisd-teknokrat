@extends('layouts.app', ['title' => 'Workspace Penugasan Supervisi - Universitas Teknokrat Indonesia'])

@section('content')
@if ($needsPreparation)
<div class="mb-6 rounded-xl border border-amber-200 bg-amber-50 p-4">
    <p class="text-sm text-amber-900">Konfigurasi workspace periode ini belum lengkap. Siapkan sebelum menyusun penugasan.</p>
    <form method="POST" action="{{ route('lpm.assignments.prepare', $period) }}" class="mt-3">
        @csrf
        <button type="submit" class="rounded-lg bg-red-900 px-4 py-2 text-sm font-semibold text-white hover:bg-red-800">Siapkan Workspace</button>
    </form>
</div>
@endif
<div class="space-y-6" x-data="{
    // Modal states
    overrideModalOpen: false,
    finalizeModalOpen: false,
    isGeneratingSchedule: false,

    // In-page proposal preview state
    showProposalPreview: false,
    proposalSearchQuery: '',
    proposalHtml: '',
    proposalLoading: false,
    proposalError: '',
    proposalRequestSequence: 0,

    // Manual override state
    selectedTarget: null,
    selectedTargetId: null,
    manualOptionsLoading: false,
    manualOptionsError: '',
    manualOptionsSequence: 0,
    selectedSupervisorId: '',
    selectedOccurrenceId: '',
    selectedDate: '',
    calendarMonth: '',
    targetOccurrences: [],
    manualAssignmentChecking: false,
    manualCheckSequence: 0,

    // Helper functions
    startScheduleGeneration(event) {
        if (this.isGeneratingSchedule) {
            event.preventDefault();
            return;
        }

        this.isGeneratingSchedule = true;
        if (window.Swal) {
            window.Swal.fire({
                title: 'Menyusun Usulan Jadwal Otomatis',
                text: 'Usulan jadwal otomatis sedang diproses. Mohon ditunggu hingga proses selesai.',
                allowOutsideClick: false,
                allowEscapeKey: false,
                showConfirmButton: false,
                showCloseButton: false,
                didOpen: () => window.Swal.showLoading()
            });
        }
    },

    resetScheduleGeneration() {
        if (!this.isGeneratingSchedule) return;
        this.isGeneratingSchedule = false;
        if (window.Swal) window.Swal.close();
    },

    async openOverrideModal(lecturerId) {
        const sequence = ++this.manualOptionsSequence;
        ++this.manualCheckSequence;
        this.selectedTargetId = lecturerId;
        this.selectedTarget = null;
        this.targetOccurrences = [];
        this.selectedSupervisorId = '';
        this.selectedOccurrenceId = '';
        this.selectedDate = '';
        this.calendarMonth = '';
        this.manualAssignmentChecking = false;
        this.manualOptionsError = '';
        this.manualOptionsLoading = true;
        this.overrideModalOpen = true;

        try {
            const response = await fetch('{{ $stage ? route('lpm.assignments.manual_options', [$stage, '__LECTURER__']) : '' }}'.replace('__LECTURER__', lecturerId), {
                headers: { 'Accept': 'application/json' }
            });
            if (!response.ok) throw new Error('Pilihan jadwal gagal dimuat. Muat ulang halaman jika penugasan sudah berubah.');
            const result = await response.json();
            if (sequence !== this.manualOptionsSequence || !this.overrideModalOpen) return;
            this.selectedTarget = result.target;
            this.targetOccurrences = result.occurrences;
            this.selectedSupervisorId = result.assignment?.supervisor_lecturer_id || '';
            const selectedOccurrence = result.occurrences.find(occurrence => occurrence.id == result.assignment?.teaching_occurrence_id) || result.occurrences[0];
            this.selectedOccurrenceId = selectedOccurrence?.id || '';
            this.selectedDate = selectedOccurrence?.date || '';
            this.calendarMonth = this.selectedDate.slice(0, 7);
        } catch (error) {
            if (sequence === this.manualOptionsSequence) this.manualOptionsError = error.message;
        } finally {
            if (sequence === this.manualOptionsSequence) this.manualOptionsLoading = false;
        }
    },

    toggleProposalPreview() {
        this.showProposalPreview = !this.showProposalPreview;
        if (this.showProposalPreview) this.loadProposalPreview();
    },

    closeOverrideModal() {
        ++this.manualOptionsSequence;
        ++this.manualCheckSequence;
        this.manualOptionsLoading = false;
        this.manualAssignmentChecking = false;
        this.overrideModalOpen = false;
    },

    async loadProposalPreview(pageUrl = null) {
        const sequence = ++this.proposalRequestSequence;
        const url = new URL(pageUrl || '{{ $latestRun ? route('lpm.assignments.proposal_preview', $latestRun) : '' }}', window.location.origin);
        url.searchParams.set('search', this.proposalSearchQuery);
        let searchFocused = false;
        let cursorPosition = null;
        this.proposalLoading = true;
        this.proposalError = '';
        try {
            const response = await fetch(url, { headers: { 'Accept': 'text/html', 'X-Requested-With': 'XMLHttpRequest' } });
            if (!response.ok || response.redirected) throw new Error('Pratinjau gagal dimuat. Coba lagi atau muat ulang halaman.');
            const html = await response.text();
            if (sequence !== this.proposalRequestSequence) return;
            searchFocused = document.activeElement?.id === 'proposal-search-input';
            cursorPosition = searchFocused ? document.activeElement.selectionStart : null;
            this.proposalHtml = html;
        } catch (error) {
            if (sequence === this.proposalRequestSequence) this.proposalError = error.message;
        } finally {
            if (sequence === this.proposalRequestSequence) {
                this.proposalLoading = false;
                if (searchFocused) this.$nextTick(() => {
                    const input = document.getElementById('proposal-search-input');
                    input?.focus();
                    input?.setSelectionRange(cursorPosition, cursorPosition);
                });
            }
        }
    },

    get availableCalendarMonths() {
        return [...new Set(this.targetOccurrences.map(occurrence => occurrence.date.slice(0, 7)))].sort();
    },

    get calendarMonthLabel() {
        if (!this.calendarMonth) return '';
        const [year, month] = this.calendarMonth.split('-').map(Number);
        return new Intl.DateTimeFormat('id-ID', { month: 'long', year: 'numeric' }).format(new Date(year, month - 1, 1));
    },

    get calendarCells() {
        if (!this.calendarMonth) return [];
        const [year, month] = this.calendarMonth.split('-').map(Number);
        const firstDayOffset = (new Date(year, month - 1, 1).getDay() + 6) % 7;
        const dayCount = new Date(year, month, 0).getDate();
        const cells = Array.from({ length: firstDayOffset }, (_, index) => ({ key: `blank-${index}`, date: '', day: '' }));

        for (let day = 1; day <= dayCount; day++) {
            const date = `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
            cells.push({ key: date, date, day });
        }

        return cells;
    },

    get selectedDateOccurrences() {
        return this.targetOccurrences.filter(occurrence => occurrence.date === this.selectedDate);
    },

    get selectedDayName() {
        return this.dayNameForDate(this.selectedDate);
    },

    dayNameForDate(date) {
        if (!date) return '';
        const [year, month, day] = date.split('-').map(Number);
        return new Intl.DateTimeFormat('id-ID', { weekday: 'long' }).format(new Date(year, month - 1, day));
    },

    get selectedDateText() {
        if (!this.selectedDate) return '';
        const [year, month, day] = this.selectedDate.split('-').map(Number);
        return new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'long', year: 'numeric' }).format(new Date(year, month - 1, day));
    },

    occurrencesOn(date) {
        return this.targetOccurrences.filter(occurrence => occurrence.date === date);
    },

    shiftCalendarMonth(direction) {
        const currentIndex = this.availableCalendarMonths.indexOf(this.calendarMonth);
        this.calendarMonth = this.availableCalendarMonths[currentIndex + direction] || this.calendarMonth;
    },

    selectCalendarDate(date) {
        const occurrences = this.occurrencesOn(date);
        if (!occurrences.length) return;
        this.selectedDate = date;
        this.selectedOccurrenceId = occurrences[0].id;
        if (this.selectedSupervisorId) this.checkManualAssignment();
    },

    selectCalendarOccurrence(occurrence) {
        this.selectedOccurrenceId = occurrence.id;
        if (this.selectedSupervisorId) this.checkManualAssignment();
    },

    async checkManualAssignment() {
        if (!this.selectedTarget || !this.selectedSupervisorId || !this.selectedOccurrenceId) return false;

        const sequence = ++this.manualCheckSequence;
        this.manualAssignmentChecking = true;

        try {
            const response = await fetch('{{ $stage ? route('lpm.assignments.manual_check', $stage) : '' }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content
                },
                body: JSON.stringify({
                    target_lecturer_id: this.selectedTarget.id,
                    supervisor_lecturer_id: this.selectedSupervisorId,
                    teaching_occurrence_id: this.selectedOccurrenceId
                })
            });
            const result = await response.json();
            if (sequence !== this.manualCheckSequence) return false;

            if (!response.ok || !result.valid) {
                const message = (result.conflicts || ['Jadwal belum dapat diperiksa. Periksa pilihan lalu coba lagi.']).join(' ');
                this.showManualConflict(message);
                return false;
            }

            return true;
        } catch (error) {
            if (sequence === this.manualCheckSequence) {
                this.showManualConflict('Jadwal belum dapat diperiksa. Coba lagi beberapa saat.');
            }
            return false;
        } finally {
            if (sequence === this.manualCheckSequence) this.manualAssignmentChecking = false;
        }
    },

    showManualConflict(message) {
        if (window.Swal) {
            window.Swal.fire({
                title: message.includes('bertabrakan') ? 'Jadwal supervisor bentrok' : 'Penugasan belum dapat disimpan',
                text: message + ' Pilih supervisor atau jadwal perkuliahan lain.',
                icon: 'warning',
                confirmButtonText: 'Perbaiki pilihan',
                confirmButtonColor: '#7B1113'
            });
        } else {
            window.alert(message);
        }
    },

    showFinalizationBlocked(unassignedCount, totalTargets, pairMismatchCount) {
        const messages = [];
        if (totalTargets === 0) messages.push('Belum ada dosen yang dijadwalkan untuk tahap ini.');
        if (unassignedCount > 0) messages.push(`Masih ada ${unassignedCount} dosen tanpa penugasan pada tahap ini.`);
        if (pairMismatchCount > 0) messages.push(`${pairMismatchCount} dosen belum memiliki pasangan supervisor yang sama dan jadwal Tahap 2 setelah Tahap 1.`);
        const message = messages.join(' ') || 'Lengkapi pasangan penugasan Tahap 1 dan Tahap 2 sebelum finalisasi.';

        if (!window.Swal) {
            window.alert(message);
            return;
        }

        window.Swal.fire({
            title: 'Finalisasi belum tersedia',
            text: message,
            icon: 'info',
            showCancelButton: unassignedCount > 0,
            confirmButtonText: unassignedCount > 0 ? 'Lihat dosen belum terjadwal' : 'Mengerti',
            cancelButtonText: 'Tutup',
            confirmButtonColor: '#7B1113'
        }).then(result => {
            if (result.isConfirmed && unassignedCount > 0) {
                window.location.href = '{{ $stage ? route('lpm.assignments.index', ['stage_id' => $stage->id, 'status' => 'unassigned']) : '' }}#daftar-penugasan';
            }
        });
    },

    async submitManualAssignment(event) {
        if (this.manualOptionsLoading || this.manualOptionsError || this.manualAssignmentChecking) return;
        if (await this.checkManualAssignment()) event.target.submit();
    },


}" @pageshow.window="resetScheduleGeneration()"
    @if(session('show_proposal_preview') && $latestRun && $proposalCount > 0)
        x-init="toggleProposalPreview(); $nextTick(() => $refs.proposalPreview.scrollIntoView({ behavior: 'smooth', block: 'start' }))"
    @endif
>

    <!-- ============================================================ -->
    <!-- 1. PAGE HEADER                                               -->
    <!-- ============================================================ -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-2 text-xs text-slate-500 font-medium mb-1">
                <span>Supervisi Akademik</span>
                <span>/</span>
                <span class="text-slate-700 font-semibold">{{ $period ? $period->name : 'Belum ada periode aktif' }}</span>
            </div>
            <h1 class="text-2xl font-bold text-slate-900 tracking-tight">Workspace Penugasan Supervisi</h1>
            <p class="mt-1 text-xs text-slate-500">Penugasan otomatis mengutamakan kelas offline yang tidak bentrok. Kelas online dipilih bila tidak ada pilihan offline yang valid.</p>
        </div>

    </div>

        @if(!$period)
        <section class="rounded-xl border border-slate-200 bg-white p-6 text-center shadow-sm">
            <h2 class="text-base font-bold text-slate-900">Belum ada periode supervisi aktif</h2>
            <p class="mt-1 text-sm text-slate-600">Aktifkan periode supervisi terlebih dahulu untuk mengelola penugasan.</p>
            <a href="{{ route('lpm.periods.index') }}" class="mt-4 inline-flex rounded-lg bg-[#7B1113] px-4 py-2 text-sm font-semibold text-white hover:bg-[#5A0E13]">Lihat periode supervisi</a>
        </section>
        @elseif(!$stage)
        <section class="rounded-xl border border-slate-200 bg-white p-6 text-center shadow-sm">
            <h2 class="text-base font-bold text-slate-900">Tahap supervisi pada periode aktif belum tersedia</h2>
            <p class="mt-1 text-sm text-slate-600">Periksa pengaturan periode supervisi {{ $period->name }}.</p>
        </section>
        @else
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <!-- ============================================================ -->
        <!-- 4. ACTION BAR: PENJADWALAN OTOMATIS                          -->
        <!-- ============================================================ -->
        <section class="flex h-full flex-col rounded-xl border border-slate-200 bg-white p-5 shadow-sm" aria-labelledby="penjadwalan-heading">
            <div class="flex flex-col gap-4">
                <div class="flex items-start gap-3">
                    <div class="w-9 h-9 rounded-lg bg-rose-50 text-[#7B1113] flex items-center justify-center shrink-0 border border-rose-100">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    </div>
                    <div>
                        <h2 id="penjadwalan-heading" class="text-base font-bold text-slate-900">1. Penjadwalan otomatis</h2>
                        <p class="text-xs text-slate-500 mt-0.5">
                            Susun jadwal observasi berdasarkan jadwal mengajar dosen.
                        </p>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2.5">
                    @if(!$stage->is_finalized)
                        <form action="{{ route('lpm.assignments.run_solver', $stage) }}" method="POST" @submit="startScheduleGeneration($event)" :aria-busy="isGeneratingSchedule">
                            @csrf
                            <button 
                                type="submit" 
                                :disabled="isGeneratingSchedule"
                                class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-[#7B1113] hover:bg-[#5A0E13] text-white text-xs font-semibold shadow-sm transition-colors cursor-pointer disabled:cursor-wait disabled:opacity-70"
                            >
                                <svg class="w-4 h-4" :class="{ 'animate-spin': isGeneratingSchedule }" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                <span x-text="isGeneratingSchedule ? 'Sedang menyusun...' : 'Buat Usulan Jadwal'">Buat Usulan Jadwal</span>
                            </button>
                        </form>

                        @if($stats['auto_count'] > 0)
                            <form id="reset-auto-form" action="{{ route('lpm.assignments.reset_auto', $stage) }}" method="POST">
                                @csrf
                                <button 
                                    type="button" 
                                    onclick="window.confirmModal({
                                        title: 'Reset Jadwal Otomatis?',
                                        text: 'Reset pasangan jadwal otomatis untuk Tahap 1 dan Tahap 2? Penugasan manual akan tetap dipertahankan.',
                                        icon: 'warning',
                                        confirmText: 'Ya, Reset',
                                        cancelText: 'Batal',
                                        confirmColor: '#DC2626',
                                        onConfirm: () => document.getElementById('reset-auto-form').submit()
                                    })"
                                    class="inline-flex items-center px-3.5 py-2.5 rounded-xl border border-slate-300 hover:bg-rose-50 hover:border-rose-300 hover:text-rose-700 text-slate-600 text-xs font-medium transition-colors cursor-pointer"
                                    title="Reset penugasan otomatis tanpa menghapus penugasan manual"
                                >
                                    Reset Otomatis
                                </button>
                            </form>
                        @endif
                    @endif
                </div>
            </div>

            <!-- Proposed Schedule Result Bar (If any proposal available) -->
            @if($latestRun && $proposalCount > 0)
                <div class="mt-auto pt-4 border-t border-slate-100 flex flex-col gap-2 text-xs">
                    <div class="flex items-center gap-2.5 text-slate-600">
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-semibold {{ $isProposalApplied ? 'bg-slate-100 text-slate-700 border border-slate-200' : 'bg-emerald-50 text-emerald-700 border border-emerald-200' }}">
                            {{ $isProposalApplied ? 'Usulan sudah diterapkan' : 'Usulan siap diterapkan' }}
                        </span>
                        <span>{{ $proposalCount }} jadwal</span>
                    </div>

                    <div class="flex flex-wrap items-center gap-3">
                        <button 
                            type="button" 
                            @click="toggleProposalPreview()" 
                            class="text-xs font-semibold text-[#7B1113] hover:underline cursor-pointer flex items-center gap-1.5"
                        >
                            <span x-text="showProposalPreview ? 'Tutup pratinjau usulan' : 'Lihat pratinjau usulan'"></span>
                        </button>

                        @if(!$stage->is_finalized && in_array($latestRun->status, ['OPTIMAL', 'FEASIBLE']) && !$isProposalApplied)

                            <form id="apply-run-form-bar" action="{{ route('lpm.assignments.apply_run', $latestRun) }}" method="POST">
                                @csrf
                                <input type="hidden" name="revision" value="{{ $state->revision }}">
                                <button 
                                    type="button" 
                                    onclick="window.confirmModal({
                                        title: 'Terapkan Usulan Jadwal?',
                                        text: 'Terapkan pasangan supervisor untuk Tahap 1 dan Tahap 2? Slot observasi pada tahap pasangannya akan dipilih otomatis.',
                                        icon: 'question',
                                        confirmText: 'Ya, Terapkan Sekarang',
                                        cancelText: 'Batal',
                                        confirmColor: '#059669',
                                        onConfirm: () => document.getElementById('apply-run-form-bar').submit()
                                    })"
                                    class="inline-flex items-center gap-1.5 text-xs font-bold text-emerald-700 hover:text-emerald-800 hover:underline cursor-pointer"
                                >
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                    Terapkan Sekarang
                                </button>
                            </form>
                        @endif
                    </div>
                </div>
            @endif
        </section>

        <section class="flex h-full flex-col rounded-xl border {{ $isPairFinalized ? 'border-emerald-200 bg-emerald-50/30' : ($stats['is_ready_for_finalization'] ? 'border-rose-200 bg-rose-50/20' : 'border-amber-200 bg-amber-50/30') }} p-5 shadow-sm" aria-labelledby="finalisasi-heading">
            <div class="flex flex-wrap items-center gap-2">
                <h2 id="finalisasi-heading" class="text-base font-bold text-slate-900">{{ $isPairFinalized ? 'Penugasan Tahap 1 dan 2 sudah difinalisasi' : '2. Finalisasi penugasan periode' }}</h2>
                @if($isPairFinalized)
                    <span class="rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-semibold text-emerald-800">Selesai</span>
                @elseif($stats['is_ready_for_finalization'])
                    <span class="rounded-full bg-rose-100 px-2.5 py-0.5 text-xs font-semibold text-[#7B1113]">Siap difinalisasi</span>
                @else
                    <span class="rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-semibold text-amber-800">Belum siap</span>
                @endif
            </div>

            @if($isPairFinalized)
                <p class="mt-2 text-sm text-slate-600">Seluruh pasangan penugasan untuk {{ $stats['total_targets'] }} dosen telah dikunci. Supervisor dan dosen yang sama berlaku untuk Tahap 1 dan Tahap 2.</p>
            @elseif($stats['total_targets'] === 0)
                <p class="mt-2 text-sm text-slate-600">Belum ada dosen yang perlu dijadwalkan pada tahap ini.</p>
            @elseif($stats['is_ready_for_finalization'])
                <p class="mt-2 text-sm text-slate-600">Jadwal Tahap 1 dan Tahap 2 untuk seluruh {{ $stats['total_targets'] }} dosen sudah valid. Pasangan penugasan periode siap dikunci satu kali.</p>
            @else
                <p class="mt-2 text-sm text-slate-600">{{ $stats['assigned_count'] }} dari {{ $stats['total_targets'] }} dosen terjadwal. Masih ada <strong class="text-amber-900">{{ $stats['unassigned_count'] }} dosen</strong> yang perlu ditugaskan.</p>
            @endif

            <div class="mt-auto flex flex-wrap items-center gap-3 pt-5">
                @if($isPairFinalized)
                    <form id="reopen-stage-form" action="{{ route('lpm.assignments.reopen_stage', $stage) }}" method="POST">
                        @csrf
                        <button type="button" onclick="window.confirmModal({ title: 'Buka ulang penugasan periode?', text: 'Buka kembali jadwal Tahap 1 dan Tahap 2 untuk menyesuaikan pasangan penugasan?', icon: 'question', confirmText: 'Ya, buka ulang', cancelText: 'Batal', confirmColor: '#D97706', onConfirm: () => document.getElementById('reopen-stage-form').submit() })" class="rounded-lg border border-emerald-300 bg-white px-4 py-2.5 text-sm font-semibold text-emerald-800 hover:bg-emerald-50">
                            Buka ulang penugasan
                        </button>
                    </form>
                @else
                    <button type="button" @if($stats['is_ready_for_finalization']) @click="finalizeModalOpen = true" @else @click="showFinalizationBlocked({{ $stats['unassigned_count'] }}, {{ $stats['total_targets'] }}, {{ $stats['pair_mismatch_count'] }})" aria-describedby="finalisasi-alasan" @endif class="rounded-lg px-4 py-2.5 text-sm font-semibold {{ $stats['is_ready_for_finalization'] ? 'bg-[#7B1113] text-white hover:bg-[#5A0E13]' : 'border border-amber-300 bg-white text-amber-900 hover:bg-amber-50' }}">
                        Finalisasi Tahap 1 &amp; 2
                    </button>
                    @if($stats['unassigned_count'] > 0)
                        <a href="{{ route('lpm.assignments.index', ['stage_id' => $stage->id, 'status' => 'unassigned']) }}#daftar-penugasan" class="text-sm font-semibold text-[#7B1113] hover:underline">
                            Lihat {{ $stats['unassigned_count'] }} dosen belum terjadwal
                        </a>
                    @endif
                @endif
            </div>
            @if(!$isPairFinalized && !$stats['is_ready_for_finalization'])
                <p id="finalisasi-alasan" class="mt-2 text-xs text-slate-500">
                    @if($stats['pair_mismatch_count'] > 0)
                        Pastikan supervisor setiap dosen sama pada kedua tahap dan jadwal Tahap 2 setelah Tahap 1.
                    @else
                        Finalisasi tersedia setelah semua dosen memiliki pasangan jadwal di Tahap 1 dan Tahap 2.
                    @endif
                </p>
            @endif
        </section>
        </div>
        @endif

    <!-- ============================================================ -->
    <!-- PRATINJAU USULAN JADWAL OTOMATIS (LANGSUNG DI HALAMAN)       -->
    <!-- ============================================================ -->
    @if($stage && $latestRun && $proposalCount > 0)
    <section x-ref="proposalPreview" x-show="showProposalPreview" x-cloak aria-label="Pratinjau usulan jadwal">
        <p x-show="proposalLoading" role="status" class="rounded-xl border border-slate-200 bg-white p-4 text-sm text-slate-500">Memuat pratinjau usulan...</p>
        <div x-show="proposalError" role="alert" class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">
            <span x-text="proposalError"></span>
            <button type="button" @click="loadProposalPreview()" class="ml-2 font-semibold underline">Coba lagi</button>
        </div>
        <div x-show="!proposalError" x-html="proposalHtml"></div>
    </section>
    @endif

    @if($stage)
    <!-- ============================================================ -->
    <!-- 4. DATA TABLE: STATUS PENUGASAN DOSEN YANG DISUPERVISI       -->
    <!-- ============================================================ -->
    <div id="daftar-penugasan" class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden scroll-mt-6">
        <!-- Table Toolbar -->
        <div class="space-y-3 border-b border-slate-200 p-3">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-2">
                    <h3 class="text-base font-bold text-slate-900">Dosen yang Disupervisi</h3>
                    <span class="text-xs font-semibold text-slate-500 bg-slate-100 px-2.5 py-0.5 rounded-full">
                        Total {{ $targets->total() }}
                    </span>
                </div>

                <div class="inline-flex rounded-lg bg-slate-100 p-0.5 text-xs font-medium">
                    <a href="{{ route('lpm.assignments.index', array_merge(request()->except(['page']), ['stage_id' => $stage->id, 'scope' => 'INCLUDED'])) }}#daftar-penugasan" class="px-3 py-1 rounded-md {{ ($filters['scope'] ?? 'INCLUDED') === 'INCLUDED' ? 'bg-white text-slate-900 font-bold shadow-sm' : 'text-slate-600 hover:text-slate-900' }}">
                        Target Aktif ({{ $totalIncludedTargets ?? 0 }})
                    </a>
                    <a href="{{ route('lpm.assignments.index', array_merge(request()->except(['page']), ['stage_id' => $stage->id, 'scope' => 'EXCLUDED'])) }}#daftar-penugasan" class="px-3 py-1 rounded-md {{ ($filters['scope'] ?? '') === 'EXCLUDED' ? 'bg-white text-slate-900 font-bold shadow-sm' : 'text-slate-600 hover:text-slate-900' }}">
                        Dikecualikan ({{ $totalExcludedTargets ?? 0 }})
                    </a>
                    <a href="{{ route('lpm.assignments.index', array_merge(request()->except(['page']), ['stage_id' => $stage->id, 'scope' => 'ALL'])) }}#daftar-penugasan" class="px-3 py-1 rounded-md {{ ($filters['scope'] ?? '') === 'ALL' ? 'bg-white text-slate-900 font-bold shadow-sm' : 'text-slate-600 hover:text-slate-900' }}">
                        Semua
                    </a>
                </div>
            </div>

            <form method="GET" action="{{ route('lpm.assignments.index') }}#daftar-penugasan" class="filter-controls" style="--filter-columns: minmax(0,1.8fr) minmax(0,1.3fr) minmax(0,1fr) auto auto">
                <input type="hidden" name="stage_id" value="{{ $stage->id }}">
                <input type="hidden" name="scope" value="{{ $filters['scope'] ?? 'INCLUDED' }}">
                <div class="filter-search">
                    <svg class="filter-search-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    <input type="search" name="search" value="{{ $filters['search'] ?? '' }}" maxlength="150" aria-label="Cari penugasan dosen" placeholder="Cari dosen, NIDN, prodi..." class="filter-control">
                </div>
                <select name="prodi" aria-label="Program Studi" class="filter-control">
                    <option value="">Semua Program Studi</option>
                    @foreach($studyPrograms as $prodi)
                        <option value="{{ $prodi }}" @selected(($filters['prodi'] ?? '') === $prodi)>{{ $prodi }}</option>
                    @endforeach
                </select>
                <select name="status" aria-label="Status penugasan" class="filter-control">
                    <option value="">Semua Status</option>
                    <option value="unassigned" @selected(($filters['status'] ?? '') === 'unassigned')>Belum Terjadwal</option>
                    <option value="assigned" @selected(($filters['status'] ?? '') === 'assigned')>Sudah Terjadwal</option>
                    <option value="auto" @selected(($filters['status'] ?? '') === 'auto')>Penugasan Otomatis</option>
                    <option value="manual" @selected(($filters['status'] ?? '') === 'manual')>Penugasan Manual</option>
                </select>
                <button type="submit" class="filter-submit">Filter</button>
                @if(!empty($filters['search']) || !empty($filters['prodi']) || !empty($filters['status']))
                    <a href="{{ route('lpm.assignments.index', ['stage_id' => $stage->id]) }}#daftar-penugasan" class="filter-reset" aria-label="Reset Filter">Reset</a>
                @endif
            </form>
        </div>

        <!-- Table -->
        <div class="overflow-x-auto" tabindex="0" aria-label="Tabel penugasan supervisi">
            <table class="w-full min-w-[1200px] table-fixed text-left text-xs">
                <caption class="sr-only">Daftar dosen sasaran, supervisor, jadwal observasi, dan status penugasan.</caption>
                <colgroup>
                    <col style="width: 4%">
                    <col style="width: 17%">
                    <col style="width: 13%">
                    <col style="width: 17%">
                    <col style="width: 21%">
                    <col style="width: 8%">
                    <col style="width: 9%">
                    <col style="width: 11%">
                </colgroup>
                <thead class="bg-slate-50 text-slate-600 font-semibold uppercase text-[11px] tracking-wider border-b border-slate-200">
                    <tr>
                        <th scope="col" class="px-4 py-3 text-center">No</th>
                        <th scope="col" class="px-4 py-3">Dosen yang Disupervisi</th>
                        <th scope="col" class="px-4 py-3">Program Studi</th>
                        <th scope="col" class="px-4 py-3">Supervisor Ditugaskan</th>
                        <th scope="col" class="px-4 py-3">Jadwal Observasi Kelas</th>
                        <th scope="col" class="px-4 py-3 text-center">Metode</th>
                        <th scope="col" class="px-4 py-3 text-center">Status</th>
                        <th scope="col" class="px-4 py-3 text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($targets as $index => $target)
                        @php
                            $assign = $assignments->get($target->lecturer_id);

                            $prodiName = $target->lecturer->studyProgram?->name ?? '-';
                            $targetNidn = $target->lecturer->nidn ?? '-';
                        @endphp
                        <tr 
                            class="h-[88px] align-middle hover:bg-slate-50/70 transition-colors"
                        >
                            <!-- No -->
                            <td class="px-4 py-3 text-center text-slate-400 font-mono text-[11px]">
                                {{ $targets->firstItem() + $index }}
                            </td>

                            <!-- Dosen yang Disupervisi -->
                            <td class="px-4 py-3">
                                <div class="line-clamp-2 font-semibold text-slate-900 text-xs sm:text-[13px]" title="{{ $target->lecturer->name }}">
                                    {{ $target->lecturer->name }}
                                </div>
                                <div class="text-[11px] text-slate-500 font-mono mt-0.5">
                                    NIDN: {{ $targetNidn }}
                                </div>
                            </td>

                            <!-- Program Studi -->
                            <td class="px-4 py-3 text-slate-600 font-medium">
                                <span class="line-clamp-2" title="{{ $prodiName }}">{{ $prodiName }}</span>
                            </td>

                            <!-- Supervisor Ditugaskan -->
                            <td class="px-4 py-3">
                                @if($assign)
                                    <div>
                                        <span class="line-clamp-2 font-semibold text-slate-900" title="{{ $assign->supervisorLecturer->name }}">
                                            {{ $assign->supervisorLecturer->name }}
                                        </span>
                                        <span class="text-[11px] text-slate-400 font-mono">
                                            NIDN: {{ $assign->supervisorLecturer->nidn ?? '-' }}
                                        </span>
                                    </div>
                                @else
                                    <span class="text-slate-400 italic text-[11px]">Belum ditentukan</span>
                                @endif
                            </td>

                            <!-- Jadwal Observasi Kelas -->
                            <td class="px-4 py-3">
                                @if($assign && $assign->teachingOccurrence)
                                    <div class="space-y-0.5">
                                        <span class="line-clamp-2 text-slate-900 font-semibold" title="{{ $assign->teachingOccurrence->teachingSchedule->course->name }}">
                                            {{ $assign->teachingOccurrence->teachingSchedule->course->name }}
                                        </span>
                                        <div class="text-[11px] text-slate-500">
                                            <span class="block whitespace-nowrap">
                                                {{ $assign->teachingOccurrence->occurrence_date->format('d/m/Y') }} 
                                                ({{ substr($assign->teachingOccurrence->start_time_local, 0, 5) }}-{{ substr($assign->teachingOccurrence->end_time_local, 0, 5) }} WIB)
                                            </span>

                                            <span class="block truncate" title="Ruang {{ $assign->teachingOccurrence->teachingSchedule->room_display }}">Ruang {{ $assign->teachingOccurrence->teachingSchedule->room_display }}</span>
                                        </div>
                                    </div>
                                @else
                                    <span class="text-slate-400 italic text-[11px]">-</span>
                                @endif
                            </td>

                            <!-- Tipe Plot -->
                            <td class="px-4 py-3 text-center">
                                @if($assign)
                                    @if($assign->assignment_type === 'MANUAL')
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-semibold bg-blue-50 text-blue-700 border border-blue-200">
                                            Manual
                                        </span>
                                    @else
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-semibold bg-slate-100 text-slate-700 border border-slate-200">
                                            Otomatis
                                        </span>
                                    @endif
                                @else

                                @endif
                            </td>

                            <!-- Status Penugasan -->
                            <td class="px-4 py-3 text-center">
                                @if($target->scope_status === 'EXCLUDED')
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-semibold bg-rose-50 text-rose-700 border border-rose-200" title="Dosen dikecualikan dari kewajiban supervisi pada periode ini">
                                        Dikecualikan
                                    </span>
                                @elseif($assign)
                                    @if($assign->status === 'FINAL')
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                            Final
                                        </span>
                                    @else
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-semibold bg-amber-50 text-amber-700 border border-amber-200">
                                            Draf
                                        </span>
                                    @endif
                                @else
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-medium bg-slate-100 text-slate-500">
                                        Belum Terjadwal
                                    </span>
                                @endif
                            </td>

                            <!-- Aksi -->
                            <td class="px-4 py-3 text-center">
                                <div class="flex items-center justify-center gap-1.5">
                                    @if(!$stage->is_finalized)
                                        @if($target->scope_status === 'EXCLUDED')
                                            <form id="include-form-{{ $target->id }}" action="{{ route('lpm.assignments.toggle_scope', [$stage, $target->lecturer]) }}" method="POST">
                                                @csrf
                                                <button 
                                                    type="button" 
                                                    data-scope-target-name="{{ $target->lecturer->name }}"
                                                    onclick="window.confirmModal({
                                                        title: 'Masukkan Kembali ke Target?',
                                                        html: window.confirmationContent('Masukkan kembali dosen ', this.dataset.scopeTargetName, ' ke target supervisi periode ini?'),
                                                        icon: 'info',
                                                        confirmText: 'Ya, Masukkan',
                                                        cancelText: 'Batal',
                                                        confirmColor: '#2563EB',
                                                        onConfirm: () => document.getElementById('include-form-{{ $target->id }}').submit()
                                                    })"
                                                    class="inline-flex items-center px-2.5 py-1 rounded-lg border border-blue-200 bg-blue-50 hover:bg-blue-100 text-blue-700 text-[11px] font-semibold transition-colors cursor-pointer"
                                                    title="Masukkan Kembali ke Target Supervisi"
                                                >
                                                    + Masukkan
                                                </button>
                                            </form>
                                        @else
                                            <button 
                                                type="button" 
                                                @click="openOverrideModal({{ $target->lecturer_id }})" 
                                                class="inline-flex items-center px-2.5 py-1 rounded-lg border border-slate-300 hover:border-[#7B1113] hover:text-[#7B1113] text-[11px] font-semibold text-slate-700 transition-colors cursor-pointer"
                                            >
                                                {{ $assign ? 'Ubah' : 'Atur Manual' }}
                                            </button>

                                            @if($assign)
                                                <form id="unassign-form-{{ $assign->id }}" action="{{ route('lpm.assignments.unassign', [$stage, $assign]) }}" method="POST">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button 
                                                        type="button" 
                                                        data-lecturer-name="{{ $target->lecturer->name }}"
                                                        onclick="window.confirmModal({
                                                            title: 'Batalkan Penugasan?',
                                                            html: window.confirmationContent('Batalkan pasangan penugasan Tahap 1 dan Tahap 2 untuk dosen ', this.dataset.lecturerName, '?'),
                                                            icon: 'warning',
                                                            confirmText: 'Ya, Batalkan',
                                                            cancelText: 'Kembali',
                                                            confirmColor: '#DC2626',
                                                            onConfirm: () => document.getElementById('unassign-form-{{ $assign->id }}').submit()
                                                        })"
                                                        class="inline-flex items-center p-1 rounded-lg border border-slate-200 hover:border-rose-300 hover:bg-rose-50 text-slate-400 hover:text-rose-600 transition-colors cursor-pointer"
                                                        title="Batalkan Penugasan"
                                                    >
                                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                                    </button>
                                                </form>
                                            @else
                                                <form id="exclude-form-{{ $target->id }}" action="{{ route('lpm.assignments.toggle_scope', [$stage, $target->lecturer]) }}" method="POST">
                                                    @csrf
                                                    <button 
                                                        type="button" 
                                                        data-scope-target-name="{{ $target->lecturer->name }}"
                                                        onclick="window.confirmModal({
                                                            title: 'Keluarkan dari Target Periode?',
                                                            html: window.confirmationContent('Keluarkan dosen ', this.dataset.scopeTargetName, ' dari target supervisi periode ini? Dosen tidak diwajibkan memiliki penugasan sehingga tahap dapat segera difinalisasi.'),
                                                            icon: 'warning',
                                                            confirmText: 'Ya, Keluarkan',
                                                            cancelText: 'Batal',
                                                            confirmColor: '#D97706',
                                                            onConfirm: () => document.getElementById('exclude-form-{{ $target->id }}').submit()
                                                        })"
                                                        class="inline-flex items-center px-2 py-1 rounded-lg border border-amber-200 bg-amber-50 hover:bg-amber-100 text-amber-800 text-[11px] font-semibold transition-colors cursor-pointer"
                                                        title="Keluarkan dari Target Supervisi Periode Ini"
                                                    >
                                                        Keluarkan
                                                    </button>
                                                </form>
                                            @endif
                                        @endif
                                    @else
                                        <span class="text-slate-400 text-[11px]">Terkunci</span>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-4 py-8 text-center text-slate-400">
                                Tidak ada data dosen yang disupervisi pada tahap ini.
                            </td>
                        </tr>
                    @endforelse

                </tbody>
            </table>
        </div>
        <div class="border-t border-slate-200 px-4 py-3">
            {{ $targets->links('vendor.pagination.tailwind', ['label' => 'dosen yang disupervisi']) }}
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- 5. MODAL 1: MANUAL OVERRIDE (Atur Penugasan)                 -->
    <!-- ============================================================ -->
    <div
        x-show="overrideModalOpen"
        x-cloak
        @keydown.escape.window="closeOverrideModal()"
        x-effect="if (overrideModalOpen) $nextTick(() => $refs.overrideDialog.focus())"
        class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-xs"
    >
        <div x-ref="overrideDialog" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="override-dialog-title" class="max-h-[90vh] w-full max-w-3xl overflow-y-auto rounded-xl border border-slate-200 bg-white p-5 shadow-xl sm:p-6">
            <div class="flex items-center justify-between pb-3 border-b border-slate-200 mb-4">
                <div>
                    <h3 id="override-dialog-title" class="text-base font-bold text-slate-900">Atur Penugasan Manual</h3>
                            <p class="text-xs text-slate-500">Supervisor yang dipilih berlaku untuk dosen ini di Tahap 1 dan Tahap 2. Slot observasi tahap pasangannya dipilih terpisah.</p>
                </div>
                <button type="button" @click="closeOverrideModal()" class="text-slate-500 hover:text-slate-700 cursor-pointer" aria-label="Tutup dialog penugasan manual">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <!-- Context Info -->
            <div class="mb-4 p-3 rounded-lg bg-slate-50 border border-slate-200 text-xs">
                <span class="text-slate-400 font-semibold uppercase text-[10px] block">Dosen yang Disupervisi</span>
                <strong x-text="selectedTarget ? selectedTarget.name : ''" class="text-slate-900 font-bold text-sm block"></strong>
                <span class="text-slate-500 text-[11px] font-mono mt-0.5 block" x-text="selectedTarget ? 'NIDN: ' + (selectedTarget.nidn || '-') : ''"></span>
            </div>

            <p x-show="manualOptionsLoading" role="status" class="py-4 text-sm text-slate-500">Memuat pilihan jadwal...</p>
            <div x-show="manualOptionsError" role="alert" class="mb-4 rounded-lg bg-rose-50 p-3 text-sm text-rose-700">
                <span x-text="manualOptionsError"></span>
                <button type="button" @click="openOverrideModal(selectedTargetId)" class="ml-2 font-semibold underline">Coba lagi</button>
            </div>
            <form x-show="!manualOptionsLoading && !manualOptionsError" action="{{ route('lpm.assignments.manual_override', $stage) }}" method="POST" @submit.prevent="submitManualAssignment($event)" class="space-y-4">
                @csrf
                <input type="hidden" name="target_lecturer_id" :value="selectedTarget ? selectedTarget.id : ''">

                <!-- 1. Supervisor Select -->
                <div>
                    <label for="supervisor_lecturer_id" class="block text-xs font-semibold text-slate-700 mb-1">
                        Pilih Supervisor <span class="text-rose-600">*</span>
                    </label>
                    <select id="supervisor_lecturer_id" name="supervisor_lecturer_id" x-model="selectedSupervisorId" @change="checkManualAssignment()" required class="w-full px-3 py-2 rounded-lg border border-slate-300 bg-white text-xs text-slate-900 focus:ring-2 focus:ring-[#7B1113] focus:border-transparent cursor-pointer">
                        <option value="">-- Pilih Dosen Supervisor --</option>
                        @foreach($supervisors as $sup)
                            <option value="{{ $sup->lecturer_id }}" :disabled="selectedTarget && selectedTarget.id == {{ $sup->lecturer_id }}">
                                {{ $sup->lecturer->name }} (Beban: {{ $supervisorWorkloads->firstWhere('supervisor.lecturer_id', $sup->lecturer_id)['assigned_count'] ?? 0 }} tugas)
                            </option>
                        @endforeach
                    </select>
                </div>

                <!-- 2. Calendar of teaching occurrences -->
                <div>
                    <p class="mb-2 text-xs font-semibold text-slate-700">Pilih Hari dan Jadwal Perkuliahan <span class="text-rose-600">*</span></p>
                    <input type="hidden" name="teaching_occurrence_id" :value="selectedOccurrenceId">
                    <template x-if="targetOccurrences.length > 0">
                        <div class="space-y-3">
                            <p class="text-xs text-slate-500">Pilih hari yang memiliki jadwal. Jam perkuliahan terlihat pada kotak tanggal.</p>
                            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                                <div class="mb-3 flex items-center justify-between gap-3">
                                    <button type="button" @click="shiftCalendarMonth(-1)" :disabled="calendarMonth === availableCalendarMonths[0]" aria-label="Bulan sebelumnya" class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-40">&larr;</button>
                                    <strong class="text-sm font-bold capitalize text-slate-900" x-text="calendarMonthLabel"></strong>
                                    <button type="button" @click="shiftCalendarMonth(1)" :disabled="calendarMonth === availableCalendarMonths[availableCalendarMonths.length - 1]" aria-label="Bulan berikutnya" class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-40">&rarr;</button>
                                </div>
                                <div class="overflow-x-auto">
                                    <div class="min-w-[490px]">
                                        <div class="mb-1 grid grid-cols-7 gap-1 text-center text-[11px] font-semibold text-slate-500">
                                            <span>Senin</span><span>Selasa</span><span>Rabu</span><span>Kamis</span><span>Jumat</span><span>Sabtu</span><span>Minggu</span>
                                        </div>
                                        <div class="grid grid-cols-7 gap-1">
                                            <template x-for="cell in calendarCells" :key="cell.key">
                                                <div>
                                                    <template x-if="cell.date && occurrencesOn(cell.date).length > 0">
                                                        <button type="button" @click="selectCalendarDate(cell.date)" :aria-pressed="selectedDate === cell.date" :aria-label="dayNameForDate(cell.date) + ' ' + cell.date" :class="selectedDate === cell.date ? 'border-[#7B1113] bg-rose-50 text-[#7B1113]' : 'border-slate-200 bg-white text-slate-700 hover:border-[#7B1113] hover:bg-rose-50/50'" class="flex min-h-14 w-full flex-col items-center justify-center rounded-lg border p-1 text-center">
                                                            <span class="text-sm font-bold" x-text="cell.day"></span>
                                                            <span class="text-[10px] font-medium" x-text="occurrencesOn(cell.date)[0].start_time"></span>
                                                            <span x-show="occurrencesOn(cell.date).length > 1" class="text-[9px]" x-text="'+' + (occurrencesOn(cell.date).length - 1) + ' jadwal'"></span>
                                                        </button>
                                                    </template>
                                                    <template x-if="!cell.date || occurrencesOn(cell.date).length === 0">
                                                        <div class="flex min-h-14 items-start justify-center rounded-lg p-1 text-xs text-slate-400" x-text="cell.day"></div>
                                                    </template>
                                                </div>
                                            </template>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="rounded-xl border border-slate-200 bg-white p-3">
                                <p class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-slate-500">Jadwal terpilih</p>
                                <div class="mb-2 flex flex-wrap items-baseline gap-2">
                                    <h4 class="text-sm font-bold capitalize text-slate-900" x-text="selectedDayName"></h4>
                                    <span class="text-xs text-slate-500" x-text="selectedDateText"></span>
                                </div>
                                <div class="grid gap-2 sm:grid-cols-2">
                                    <template x-for="occurrence in selectedDateOccurrences" :key="occurrence.id">
                                        <button type="button" @click="selectCalendarOccurrence(occurrence)" :aria-pressed="selectedOccurrenceId == occurrence.id" :class="selectedOccurrenceId == occurrence.id ? 'border-[#7B1113] bg-rose-50' : 'border-slate-200 bg-white hover:bg-slate-50'" class="rounded-lg border p-3 text-left">
                                            <span class="block text-xs font-bold text-[#7B1113]" x-text="occurrence.start_time + '–' + occurrence.end_time + ' WIB'"></span>
                                            <span class="mt-1 block text-xs font-semibold text-slate-900" x-text="occurrence.course"></span>
                                            <span class="mt-1 block text-[11px] text-slate-500" x-text="'Ruang ' + occurrence.room"></span>
                                        </button>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </template>
                    <template x-if="targetOccurrences.length === 0">
                        <div class="p-3 rounded-lg bg-rose-50 border border-rose-200 text-xs text-rose-800">
                            Dosen ini tidak memiliki jadwal mengajar aktif pada tahap ini.
                        </div>
                    </template>
                </div>

                <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-200">
                    <button type="button" @click="closeOverrideModal()" class="px-4 py-2 rounded-lg border border-slate-300 text-xs font-semibold text-slate-700 hover:bg-slate-50 cursor-pointer">
                        Batal
                    </button>
                    <button 
                        type="submit" 
                        :disabled="manualOptionsLoading || !!manualOptionsError || !selectedOccurrenceId || !selectedSupervisorId || manualAssignmentChecking" 
                        class="px-4 py-2 rounded-lg bg-[#7B1113] hover:bg-[#5A0E13] text-white text-xs font-semibold shadow-sm disabled:opacity-50 transition-colors cursor-pointer"
                    >
                        Simpan untuk Kedua Tahap
                    </button>
                </div>
            </form>
        </div>
    </div>



    <!-- ============================================================ -->
    <!-- 8. MODAL 4: KONFIRMASI FINALISASI                           -->
    <!-- ============================================================ -->
    <div x-show="finalizeModalOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-xs">
        <div @click.outside="finalizeModalOpen = false" class="bg-white rounded-xl shadow-xl border border-slate-200 w-full max-w-lg p-6">
            <div class="flex items-center justify-between pb-3 border-b border-slate-200 mb-4">
                <h3 class="text-base font-bold text-slate-900">Konfirmasi Finalisasi Tahap 1 dan Tahap 2</h3>
                <button type="button" @click="finalizeModalOpen = false" class="text-slate-400 hover:text-slate-700 cursor-pointer">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <div class="space-y-3 text-xs text-slate-600 mb-5">
                <div class="p-3.5 rounded-lg bg-slate-50 border border-slate-200 space-y-1.5">
                    <div class="flex justify-between">
                        <span>Total Dosen Disupervisi:</span>
                        <strong class="text-slate-900">{{ $stats['total_targets'] }} Orang (100% Terjadwal)</strong>
                    </div>
                    <div class="flex justify-between">
                        <span>Komposisi Penugasan:</span>
                        <strong class="text-slate-900">{{ $stats['auto_count'] }} Otomatis, {{ $stats['manual_count'] }} Manual</strong>
                    </div>
                </div>

                <p class="text-slate-500 leading-relaxed">
                    Setelah difinalisasi, pasangan supervisor–dosen dan jadwal kedua tahap dikunci sekaligus. Surat Tugas Tahap 1 dan Tahap 2 diterbitkan untuk supervisor.
                </p>
            </div>

            <form action="{{ route('lpm.assignments.finalize_stage', $stage) }}" method="POST">
                @csrf
                <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-200">
                    <button type="button" @click="finalizeModalOpen = false" class="px-4 py-2 rounded-lg border border-slate-300 text-xs font-semibold text-slate-700 hover:bg-slate-100 cursor-pointer">
                        Batal
                    </button>
                    <button type="submit" class="px-4 py-2 rounded-lg bg-emerald-700 hover:bg-emerald-800 text-white font-semibold text-xs shadow-sm cursor-pointer">
                        Kunci Tahap 1 &amp; 2
                    </button>
                </div>
            </form>
        </div>
    </div>
    @endif

</div>
@if(session('manual_assignment_conflict'))
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const message = @json(implode(' ', session('manual_assignment_conflict')));
            if (window.Swal) {
                window.Swal.fire({
                    title: message.includes('bertabrakan') ? 'Jadwal supervisor bentrok' : 'Penugasan belum dapat disimpan',
                    text: message + ' Pilih supervisor atau jadwal perkuliahan lain.',
                    icon: 'warning',
                    confirmButtonText: 'Perbaiki pilihan',
                    confirmButtonColor: '#7B1113'
                });
            } else {
                window.alert(message);
            }
        });
    </script>
@endif
@endsection

