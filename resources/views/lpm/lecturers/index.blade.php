@extends('layouts.app', ['title' => 'Direktori Dosen'])

@section('content')
<div class="space-y-6" x-data='lecturerProfileEditor(@json($faculties->map(fn ($faculty) => ["id" => $faculty->id, "name" => $faculty->name, "code" => $faculty->code])->values()), @json($selectedPeriod?->id))'>
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Direktori Dosen</h1>
            <p class="text-xs text-slate-500 mt-1">Daftar dosen pada {{ $selectedPeriod?->semester_display ?? 'periode terpilih' }}, beserta fakultas asal dan program studi pada profil dosen.</p>
        </div>
        <button type="button" @click="openCreate()" @disabled($faculties->isEmpty() || !$selectedPeriod) class="inline-flex items-center justify-center gap-2 rounded-xl bg-[#7B1113] px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-[#5A0E13] disabled:cursor-not-allowed disabled:opacity-50">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
            Tambah Dosen
        </button>
    </div>

    <!-- Search & Filter Bar -->
    <div class="filter-bar space-y-2">
        <form method="GET" action="{{ route('lpm.lecturers.index') }}" class="filter-controls" style="--filter-columns: minmax(0,2fr) minmax(0,1fr) minmax(0,2fr) auto">
            <!-- Search Text -->
            <div class="filter-search">
                <svg class="filter-search-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                <input type="text" name="search" aria-label="Cari dosen" value="{{ request('search') }}" placeholder="Cari nama dosen atau NIDN..." class="filter-control">
            </div>

            <!-- Filter Fakultas -->
            <div class="filter-field">
                <select name="faculty" aria-label="Fakultas" onchange="this.form.submit()" class="filter-control">
                    <option value="">Semua Fakultas</option>
                    @foreach($faculties as $fac)
                        <option value="{{ $fac->id }}" {{ ($selectedFaculty ?? '') == $fac->id ? 'selected' : '' }}>
                            {{ $fac->code }}
                        </option>
                    @endforeach
                </select>
            </div>

            <!-- Filter semester supervisi -->
            <div class="filter-field">
                <div class="relative">
                    <select name="period_id" aria-label="Semester Supervisi" @disabled($periods->isEmpty()) onchange="this.form.submit()" class="filter-control">
                        @forelse($periods as $availablePeriod)
                            <option value="{{ $availablePeriod->id }}" @selected($selectedPeriod?->id === $availablePeriod->id)>
                                {{ $availablePeriod->academicTerm?->semester_label ?? 'Semester Supervisi' }} {{ $availablePeriod->academicTerm?->academic_year ?? '-' }}
                            </option>
                        @empty
                            <option>Belum ada semester supervisi</option>
                        @endforelse
                    </select>
                </div>
            </div>

            <!-- Submit Button & Reset -->
            <div class="filter-actions">
                <button type="submit" title="Terapkan Filter" class="filter-button">Filter</button>
                @if(request('search') || request('faculty') || (request('completion') && request('completion') !== 'all'))
                    <a href="{{ route('lpm.lecturers.index', ['period_id' => $selectedPeriod?->id]) }}" title="Reset Filter" aria-label="Reset Filter" class="filter-reset">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                    </a>
                @endif
            </div>
        </form>

        <!-- Current Filter Summary Badge -->
        <div class="flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 pt-2 text-xs text-slate-500">
            <div class="flex flex-wrap items-center gap-2">
                <span class="inline-flex items-center px-2.5 py-1 rounded-lg bg-rose-50 text-[#7B1113] font-bold border border-rose-100">
                    {{ $selectedPeriod?->semester_display ?? 'Belum ada semester supervisi' }}
                </span>
                @if(!empty($selectedFaculty))
                    @php $selFac = $faculties->firstWhere('id', $selectedFaculty); @endphp
                    @if($selFac)
                        <span class="inline-flex items-center px-2.5 py-1 rounded-lg bg-blue-50 text-blue-800 border border-blue-200 font-bold">
                            Fakultas: {{ $selFac->code }}
                        </span>
                    @endif
                @endif
                @if($completionFilter !== 'all')
                    <span class="inline-flex items-center px-2.5 py-1 rounded-lg {{ $completionFilter === 'complete' ? 'bg-emerald-50 text-emerald-800 border border-emerald-200 font-bold' : 'bg-amber-50 text-amber-800 border border-amber-200 font-bold' }}">
                        {{ $completionFilter === 'complete' ? 'Data Lengkap' : 'Perlu Dilengkapi' }}
                    </span>
                @endif
            </div>
            <div class="text-slate-400">
                Total: <span class="font-bold text-slate-700">{{ $lecturers->total() }}</span> Dosen Terdaftar
            </div>
        </div>
    </div>

    <div class="flex flex-wrap items-center gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm shadow-sm" aria-live="polite">
        <span class="font-semibold text-slate-700">Kelengkapan data dosen:</span>
        <a href="{{ request()->fullUrlWithQuery(['completion' => $completionFilter === 'complete' ? 'all' : 'complete', 'page' => null]) }}" aria-pressed="{{ $completionFilter === 'complete' ? 'true' : 'false' }}" title="{{ $completionFilter === 'complete' ? 'Tampilkan semua dosen' : 'Tampilkan dosen dengan data lengkap' }}" class="rounded-lg px-3 py-1.5 font-semibold transition-colors {{ $completionFilter === 'complete' ? 'bg-emerald-600 text-white ring-2 ring-emerald-200' : 'bg-emerald-50 text-emerald-700 hover:bg-emerald-100' }}">{{ $completenessCounts['complete'] }} lengkap</a>
        <a href="{{ request()->fullUrlWithQuery(['completion' => $completionFilter === 'incomplete' ? 'all' : 'incomplete', 'page' => null]) }}" aria-pressed="{{ $completionFilter === 'incomplete' ? 'true' : 'false' }}" title="{{ $completionFilter === 'incomplete' ? 'Tampilkan semua dosen' : 'Tampilkan dosen yang perlu dilengkapi' }}" class="rounded-lg px-3 py-1.5 font-semibold transition-colors {{ $completionFilter === 'incomplete' ? 'bg-amber-600 text-white ring-2 ring-amber-200' : 'bg-amber-50 text-amber-800 hover:bg-amber-100' }}">{{ $completenessCounts['incomplete'] }} perlu dilengkapi</a>
        <span class="text-xs text-slate-500">Diperiksa dari nama, NIDN, fakultas, dan program studi.</span>
    </div>

    <!-- Lecturers Table -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <div>
            <table class="w-full table-fixed text-left text-sm">
                <colgroup>
                    <col class="w-[5%]">
                    <col class="w-[22%]">
                    <col class="w-[13%]">
                    <col class="w-[12%]">
                    <col class="w-[17%]">
                    <col class="w-[16%]">
                    <col class="w-[15%]">
                </colgroup>
                <thead class="bg-slate-50 text-slate-500 uppercase text-xs font-semibold">
                    <tr>
                        <th class="px-2 py-3 text-center">No.</th>
                        <th class="px-2 py-3">Nama Dosen</th>
                        <th class="px-2 py-3">NIDN</th>
                        <th class="px-2 py-3">Fakultas Asal</th>
                        <th class="px-2 py-3">Program Studi</th>
                        <th class="px-2 py-3">Kelengkapan Data</th>
                        <th class="px-2 py-3 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($lecturers as $lecturer)
                        <tr class="hover:bg-slate-50/50 transition-colors" id="lecturer-row-{{ $lecturer->id }}">
                            <td class="px-2 py-3.5 text-center font-medium text-slate-500">{{ $lecturers->firstItem() + $loop->index }}</td>
                            <td class="px-2 py-3.5">
                                <p x-ref="lecturerName{{ $lecturer->id }}" class="break-words font-bold text-slate-900">{{ $lecturer->name }}</p>
                            </td>
                            <td class="whitespace-nowrap px-2 py-3.5 font-mono text-xs text-slate-600" x-ref="lecturerNidn{{ $lecturer->id }}">{{ $lecturer->nidn ?? 'Belum tersedia' }}</td>
                            <td class="px-2 py-3.5">
                                <span id="faculty-badge-{{ $lecturer->id }}" x-ref="lecturerFaculty{{ $lecturer->id }}" class="inline-flex whitespace-nowrap items-center rounded-lg border px-2.5 py-1 text-[11px] font-bold {{ $lecturer->faculty ? 'border-blue-200 bg-blue-50 text-blue-800' : 'border-slate-200 bg-slate-100 text-slate-400' }}">
                                    {{ $lecturer->faculty?->code ?? 'Belum diset' }}
                                </span>
                            </td>
                            <td class="px-2 py-3.5">
                                <span x-ref="lecturerProgram{{ $lecturer->id }}" class="break-words text-xs font-semibold {{ $lecturer->studyProgram ? 'text-slate-800' : 'text-slate-400 italic' }}">
                                    {{ $lecturer->studyProgram?->name ?? 'Belum diset' }}
                                </span>
                            </td>
                            <td class="px-2 py-3.5">
                                @if($lecturer->profile_complete)
                                    <span class="inline-flex max-w-full rounded-lg bg-emerald-50 px-2 py-1 text-[11px] font-bold text-emerald-700">Lengkap</span>
                                @else
                                    <div class="space-y-1.5">
                                        <span class="inline-flex max-w-full rounded-lg bg-amber-50 px-2 py-1 text-[11px] font-bold text-amber-800">Perlu dilengkapi</span>
                                        <p class="text-[11px] leading-4 text-slate-500">Perlu diperiksa: {{ $lecturer->missing_profile_fields->join(', ') }}</p>
                                    </div>
                                @endif
                            </td>
                            <td class="px-2 py-3.5 text-right">
                                <div class="flex flex-wrap items-center justify-end gap-1">
                                    <button type="button" data-lecturer-id="{{ $lecturer->id }}" data-lecturer-name="{{ $lecturer->name }}" data-lecturer-nidn="{{ $lecturer->nidn }}" data-faculty-id="{{ $lecturer->faculty_id }}" data-study-program-id="{{ $lecturer->study_program_id }}" @click="openEditorFromButton($event.currentTarget)" class="inline-flex items-center rounded-lg border border-slate-200 px-2 py-1.5 text-xs font-semibold text-slate-700 transition-colors hover:bg-slate-100">Edit</button>
                                    <a href="{{ route('lpm.lecturers.show', ['lecturer' => $lecturer, 'period_id' => $selectedPeriod?->id]) }}" class="inline-flex items-center rounded-lg border border-slate-200 px-2 py-1.5 text-xs font-semibold text-[#7B1113] transition-colors hover:bg-slate-100">
                                        Detail &rarr;
                                    </a>
                                    <form method="POST" action="{{ route('lpm.lecturers.destroy', $lecturer) }}" data-lecturer-name="{{ $lecturer->name }}" @submit.prevent="confirmLecturerDeletion($event.currentTarget)">
                                        @csrf
                                        @method('DELETE')
                                        <input type="hidden" name="period_id" value="{{ $selectedPeriod?->id }}">
                                        <input type="hidden" name="row_version" value="{{ $lecturer->row_version }}">
                                        <button type="submit" aria-label="Hapus dosen {{ $lecturer->name }}" class="inline-flex items-center rounded-lg border border-rose-200 px-2 py-1.5 text-xs font-semibold text-rose-700 transition-colors hover:bg-rose-50 disabled:opacity-50">Hapus</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-8 text-center text-slate-400">Tidak ada data dosen ditemukan untuk filter ini.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="border-t border-slate-200 px-4 py-3">
            {{ $lecturers->links('vendor.pagination.tailwind', ['label' => 'dosen']) }}
        </div>
    </div>

    <div x-cloak x-show="editing" x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-slate-950/60 px-4 py-6 backdrop-blur-sm" @click.self="closeEditor()" @keydown.escape.window="closeEditor()">
        <section x-show="editing" x-transition class="my-auto w-full max-w-xl rounded-2xl bg-white p-6 shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="lecturer-editor-title">
            <div class="flex items-start justify-between gap-4 border-b border-slate-200 pb-4">
                <div>
                    <h2 id="lecturer-editor-title" class="text-lg font-bold text-slate-900" x-text="isCreating ? 'Tambah Dosen Manual' : 'Edit Data Dosen'"></h2>
                    <p class="mt-1 text-sm text-slate-500" x-text="isCreating ? 'Masukkan identitas dosen dan fakultasnya.' : 'Perbarui nama, NIDN, fakultas, dan program studi.'"></p>
                </div>
                <button type="button" @click="closeEditor()" class="rounded-lg p-2 text-slate-500 hover:bg-slate-100" aria-label="Tutup">&#10005;</button>
            </div>
            <div class="mt-5 space-y-4">
                <div>
                    <label for="lecturer-name" class="mb-1.5 block text-sm font-semibold text-slate-700">Nama Dosen</label>
                    <input id="lecturer-name" type="text" x-model="form.name" maxlength="255" required class="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm focus:border-[#7B1113] focus:outline-none focus:ring-2 focus:ring-[#7B1113]/20">
                </div>
                <div>
                    <label for="lecturer-nidn" class="mb-1.5 block text-sm font-semibold text-slate-700">NIDN</label>
                    <input id="lecturer-nidn" type="text" x-model="form.nidn" inputmode="numeric" maxlength="10" pattern="[0-9]{10}" placeholder="10 digit atau kosongkan" class="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 font-mono text-sm focus:border-[#7B1113] focus:outline-none focus:ring-2 focus:ring-[#7B1113]/20">
                    <p class="mt-1 text-xs text-slate-500">NIDN harus berisi tepat 10 angka. Kosongkan jika belum tersedia.</p>
                </div>
                <div>
                    <label for="lecturer-faculty" class="mb-1.5 block text-sm font-semibold text-slate-700">Fakultas</label>
                    <select id="lecturer-faculty" x-model="form.faculty_id" @change="changeFaculty()" required class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm focus:border-[#7B1113] focus:outline-none focus:ring-2 focus:ring-[#7B1113]/20">
                        <option value="">Pilih Fakultas</option>
                        <template x-for="faculty in faculties" :key="faculty.id">
                            <option :value="String(faculty.id)" x-text="faculty.name"></option>
                        </template>
                    </select>
                </div>
                <div>
                    <label for="lecturer-program" class="mb-1.5 block text-sm font-semibold text-slate-700">Program Studi</label>
                    <select id="lecturer-program" x-model="form.study_program_id" :disabled="!form.faculty_id || loadingPrograms" class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm focus:border-[#7B1113] focus:outline-none focus:ring-2 focus:ring-[#7B1113]/20 disabled:cursor-not-allowed disabled:bg-slate-100">
                        <option value="" x-text="form.faculty_id ? 'Pilih Program Studi (opsional)' : 'Pilih fakultas terlebih dahulu'"></option>
                        <template x-for="program in programs" :key="program.id">
                            <option :value="String(program.id)" x-text="`${program.name} (${program.code})`"></option>
                        </template>
                    </select>
                    <p class="mt-1 text-xs text-slate-500">Pilihan program studi mengikuti fakultas yang dipilih.</p>
                </div>
            </div>
            <p x-show="errorMessage" x-text="errorMessage" class="mt-4 rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert"></p>
            <div class="mt-6 flex justify-end gap-2 border-t border-slate-200 pt-4">
                <button type="button" @click="closeEditor()" class="rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">Batal</button>
                <button type="button" @click="saveEditor()" :disabled="saving || loadingPrograms" class="rounded-xl bg-[#7B1113] px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-[#5A0E13] disabled:cursor-wait disabled:opacity-60">
                    <span x-show="!saving" x-text="isCreating ? 'Tambah Dosen' : 'Simpan Perubahan'"></span>
                    <span x-show="saving">Menyimpan...</span>
                </button>
            </div>
        </section>
    </div>
</div>

<script>
    async function confirmLecturerDeletion(form) {
        if (form.dataset.submitting === 'true') {
            return;
        }
        const message = 'Hapus ' + form.dataset.lecturerName + ' dari direktori beserta jadwal mengajarnya di semua periode?';
        form.dataset.submitting = 'true';
        try {
            const confirmed = window.Swal
                ? (await window.Swal.fire({
                    titleText: 'Hapus Dosen?',
                    text: message,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Ya, Hapus',
                    cancelButtonText: 'Batal',
                    confirmButtonColor: '#7B1113',
                    focusCancel: true,
                })).isConfirmed
                : window.confirm(message);
            if (confirmed) {
                form.querySelector('button[type="submit"]').disabled = true;
                form.submit();
            } else {
                form.dataset.submitting = 'false';
            }
        } catch (error) {
            form.dataset.submitting = 'false';
        }
    }

    function lecturerProfileEditor(faculties, selectedPeriodId) {
        return {
            editing: false,
            isCreating: false,
            saving: false,
            loadingPrograms: false,
            faculties: faculties,
            programs: [],
            errorMessage: '',
            form: { id: null, name: '', nidn: '', faculty_id: '', study_program_id: '' },

            openCreate() {
                this.isCreating = true;
                this.form = { id: null, name: '', nidn: '', faculty_id: '', study_program_id: '' };
                this.programs = [];
                this.errorMessage = '';
                this.editing = true;
            },

            openEditorFromButton(button) {
                this.openEditor({
                    id: button.dataset.lecturerId,
                    name: button.dataset.lecturerName,
                    nidn: button.dataset.lecturerNidn,
                    faculty_id: button.dataset.facultyId,
                    study_program_id: button.dataset.studyProgramId,
                });
            },

            async openEditor(lecturer) {
                this.isCreating = false;
                this.form = {
                    id: lecturer.id,
                    name: lecturer.name || '',
                    nidn: lecturer.nidn || '',
                    faculty_id: lecturer.faculty_id ? String(lecturer.faculty_id) : '',
                    study_program_id: lecturer.study_program_id ? String(lecturer.study_program_id) : '',
                };
                this.programs = [];
                this.errorMessage = '';
                this.editing = true;
                if (this.form.faculty_id) {
                    await this.loadPrograms();
                }
            },

            closeEditor() {
                this.editing = false;
                this.errorMessage = '';
            },

            async changeFaculty() {
                this.form.study_program_id = '';
                this.programs = [];
                if (this.form.faculty_id) {
                    await this.loadPrograms();
                }
            },

            async loadPrograms() {
                this.loadingPrograms = true;
                try {
                    const response = await fetch("{{ url('lpm/api/faculties') }}/" + this.form.faculty_id + "/study-programs", {
                        headers: { 'Accept': 'application/json' },
                    });
                    if (!response.ok) {
                        throw new Error('Daftar program studi gagal dimuat.');
                    }
                    this.programs = await response.json();
                } catch (error) {
                    this.errorMessage = error.message || 'Daftar program studi gagal dimuat.';
                } finally {
                    this.loadingPrograms = false;
                }
            },

            async saveEditor() {
                this.saving = true;
                this.errorMessage = '';
                try {
                    const endpoint = this.isCreating
                        ? "{{ route('lpm.lecturers.store') }}"
                        : "{{ url('lpm/lecturers') }}/" + this.form.id;
                    const requestBody = {
                        name: this.form.name,
                        nidn: this.form.nidn || null,
                        faculty_id: this.form.faculty_id,
                        study_program_id: this.form.study_program_id || null,
                    };
                    if (this.isCreating && selectedPeriodId) {
                        requestBody.period_id = selectedPeriodId;
                    }
                    const response = await fetch(endpoint, {
                        method: this.isCreating ? 'POST' : 'PATCH',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            'Accept': 'application/json',
                        },
                        body: JSON.stringify(requestBody),
                    });
                    const data = await response.json();
                    if (!response.ok) {
                        const validationErrors = Object.values(data.errors || {}).flat();
                        throw new Error(validationErrors[0] || data.message || 'Data dosen gagal disimpan.');
                    }
                    if (this.isCreating && data.redirect_url) {
                        window.location.href = data.redirect_url;
                    } else {
                        window.location.reload();
                    }
                } catch (error) {
                    this.errorMessage = error.message || 'Data dosen gagal disimpan.';
                } finally {
                    this.saving = false;
                }
            },
        };
    }
</script>

@endsection
