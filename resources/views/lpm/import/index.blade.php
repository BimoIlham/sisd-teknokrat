@extends('layouts.app', ['title' => 'Upload Jadwal Perkuliahan'])

@section('content')
<div class="max-w-2xl mx-auto py-4 sm:py-8 space-y-6">

    <!-- Header Section -->
    <div class="text-center space-y-2">
        <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-rose-50 border border-rose-200 text-[#7B1113] shadow-xs mb-1">
            <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 13h6m-3-3v6m5 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
            </svg>
        </div>
        <h1 class="text-2xl sm:text-3xl font-bold text-slate-900 tracking-tight">Upload Jadwal Perkuliahan</h1>
        <p class="text-xs sm:text-sm text-slate-500 max-w-lg mx-auto leading-relaxed">
            Unggah PDF jadwal resmi. Sistem langsung membaca dan mengaktifkan jadwal yang datanya jelas; baris yang tidak terbaca dengan aman tidak akan diaktifkan.
        </p>
    </div>

    <!-- Upload Card -->
    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200 shadow-sm" x-data="{
        dragOver: false,
        fileName: '',
        fileSize: '',
        isSubmitting: false,
        handleFileSelect(event) {
            const file = event.target.files[0];
            if (file) {
                this.fileName = file.name;
                this.fileSize = (file.size / (1024 * 1024)).toFixed(2) + ' MB';
            }
        },
        handleDrop(event) {
            this.dragOver = false;
            const file = event.dataTransfer.files[0];
            if (file && file.type === 'application/pdf') {
                const input = document.getElementById('schedule_pdf');
                const dataTransfer = new DataTransfer();
                dataTransfer.items.add(file);
                input.files = dataTransfer.files;
                this.fileName = file.name;
                this.fileSize = (file.size / (1024 * 1024)).toFixed(2) + ' MB';
            } else if (file) {
                if (window.Swal) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Format Berkas Tidak Sesuai',
                        text: 'Silakan pilih berkas berekstensi .pdf resmi jadwal perkuliahan.',
                        confirmButtonColor: '#7B1113'
                    });
                }
            }
        },
        submitForm() {
            if (!this.fileName) {
                if (window.Swal) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Berkas Belum Dipilih',
                        text: 'Silakan pilih atau seret berkas PDF jadwal perkuliahan terlebih dahulu.',
                        confirmButtonColor: '#7B1113'
                    });
                }
                return;
            }
            this.isSubmitting = true;
            if (window.Swal) {
                Swal.fire({
                    title: 'Memproses Berkas PDF',
                    html: `
                        <div class='text-sm text-stone-600 space-y-2 mt-2'>
                            <p>Sistem sedang membaca PDF, mencocokkan dosen, dan mengaktifkan jadwal yang valid...</p>
                            <p class='text-xs text-stone-400 font-mono'>Mohon tunggu beberapa detik, jangan menutup halaman.</p>
                        </div>
                    `,
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    showConfirmButton: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });
            }
            this.$refs.uploadForm.submit();
        }
    }">

        <form x-ref="uploadForm" action="{{ route('lpm.import.store') }}" method="POST" enctype="multipart/form-data" class="space-y-6" @submit.prevent="submitForm">
            @csrf

            <!-- Academic Term (Otomatis Sesuai Periode Aktif) -->
            <div>
                <label class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-2">
                    Semester Supervisi
                </label>
                @if($activeTerm && $activePeriod)
                    <input type="hidden" name="academic_term_id" value="{{ $activeTerm->id }}">
                    <div class="flex items-center justify-between w-full px-4 py-3 rounded-2xl border border-slate-200 bg-slate-50 text-slate-900 text-sm font-semibold shadow-2xs">
                        <div class="flex items-center gap-2.5">
                            <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 ring-4 ring-emerald-100"></span>
                            <span>{{ $activeTerm->full_semester_label }}</span>
                        </div>
                        <span class="inline-flex items-center px-3 py-0.5 rounded-full text-xs font-bold bg-emerald-600 text-white shadow-2xs">
                            Aktif
                        </span>
                    </div>
                @else
                    <div class="p-4 rounded-2xl border border-rose-200 bg-rose-50 text-rose-800 text-xs flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                        <div>
                            <p class="font-bold">Belum Ada Periode Supervisi yang Aktif</p>
                            <p class="text-rose-700 mt-0.5">Silakan buat atau aktifkan periode supervisi terlebih dahulu sebelum mengunggah jadwal perkuliahan.</p>
                        </div>
                        <a href="{{ route('lpm.audit_periods.index') }}" class="inline-flex items-center px-3.5 py-1.5 rounded-xl bg-[#7B1113] hover:bg-[#5E0C0F] text-white text-xs font-bold transition shadow-xs shrink-0">
                            Kelola Periode Supervisi ↗
                        </a>
                    </div>
                @endif
            </div>

            <!-- Drag & Drop Zone -->
            <div>
                <label class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-2">
                    Berkas PDF Jadwal Perkuliahan <span class="text-[#7B1113]">*</span>
                </label>

                <div 
                    @dragover.prevent="dragOver = true"
                    @dragleave.prevent="dragOver = false"
                    @drop.prevent="handleDrop($event)"
                    :class="dragOver ? 'border-[#7B1113] bg-rose-50/50 scale-[1.01]' : (fileName ? 'border-emerald-400 bg-emerald-50/20' : 'border-slate-300 hover:border-slate-400 bg-slate-50/50')"
                    class="relative rounded-2xl border-2 border-dashed p-8 text-center transition-all duration-200 cursor-pointer"
                    @click="$refs.fileInput.click()"
                >
                    <input 
                        x-ref="fileInput"
                        id="schedule_pdf" 
                        type="file" 
                        name="schedule_pdf" 
                        accept="application/pdf" 
                        required 
                        class="hidden"
                        @change="handleFileSelect($event)"
                    >

                    <!-- Empty State -->
                    <template x-if="!fileName">
                        <div class="space-y-3">
                            <div class="inline-flex items-center justify-center w-12 h-12 rounded-2xl bg-white border border-slate-200 text-slate-500 shadow-2xs">
                                <svg class="w-6 h-6 text-[#7B1113]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path>
                                </svg>
                            </div>
                            <div>
                                <p class="text-sm font-bold text-slate-800">
                                    <span class="text-[#7B1113] hover:underline">Klik untuk memilih berkas</span> atau seret PDF ke sini
                                </p>
                                <p class="text-xs text-slate-400 mt-1">
                                    Hanya format PDF resmi jadwal (Maksimal 15 MB)
                                </p>
                            </div>
                        </div>
                    </template>

                    <!-- Selected File State -->
                    <template x-if="fileName">
                        <div class="flex items-center justify-between p-4 bg-white rounded-xl border border-emerald-200 shadow-2xs text-left">
                            <div class="flex items-center space-x-3.5 truncate">
                                <div class="w-10 h-10 rounded-xl bg-emerald-100 flex items-center justify-center text-emerald-700 shrink-0 font-bold text-xs uppercase">
                                    PDF
                                </div>
                                <div class="truncate">
                                    <p class="text-sm font-bold text-slate-900 truncate" x-text="fileName"></p>
                                    <p class="text-[11px] font-semibold text-emerald-600 flex items-center gap-1 mt-0.5">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                                        <span x-text="fileSize"></span> • Berkas Siap Diunggah
                                    </p>
                                </div>
                            </div>
                            <button 
                                type="button" 
                                @click.stop="$refs.fileInput.click()" 
                                class="shrink-0 px-3 py-1.5 rounded-lg border border-slate-200 text-xs font-semibold text-slate-600 hover:bg-slate-100 hover:text-slate-900 transition-colors"
                            >
                                Ganti Berkas
                            </button>
                        </div>
                    </template>
                </div>

                @error('schedule_pdf')
                    <p class="text-xs text-red-600 mt-2 font-semibold">{{ $message }}</p>
                @enderror
            </div>

            <!-- Submit Button -->
            <div class="pt-2">
                <button 
                    type="submit" 
                    :disabled="isSubmitting || {{ !$activeTerm || !$activePeriod ? 'true' : 'false' }}"
                    class="w-full inline-flex items-center justify-center px-6 py-3.5 rounded-2xl bg-[#7B1113] hover:bg-[#5A0E13] disabled:opacity-40 disabled:cursor-not-allowed text-white font-bold text-sm sm:text-base shadow-md transition-all cursor-pointer"
                >
                    <svg x-show="!isSubmitting" class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path>
                    </svg>
                    <svg x-show="isSubmitting" class="w-5 h-5 mr-2 animate-spin text-white" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <span x-text="isSubmitting ? 'Membaca & Mengaktifkan Jadwal...' : 'Unggah & Aktifkan Jadwal'"></span>
                </button>
            </div>
        </form>
    </div>

</div>
@endsection
