@extends('layouts.app', ['title' => 'Formulir Supervisi - ' . $assignment->targetLecturer->name])

@section('content')
<div class="space-y-6 pb-36 sm:pb-28" x-data="{
    answers: Object.assign({}, {{ Illuminate\Support\Js::from($existingAnswersPayload) }}),
    notes: Object.assign({}, {{ Illuminate\Support\Js::from($existingNotesPayload) }}),
    scoredQuestions: {{ Illuminate\Support\Js::from($scoredQuestionsPayload) }},
    scoreStrategy: {{ Illuminate\Support\Js::from($supervision->scoringPolicy->strategy) }},
    get liveScore() {
        return this.$supervisionScore(this.answers, this.scoredQuestions, this.scoreStrategy);
    },
    rowVersion: {{ $supervision->row_version }},
    isSaving: false,
    savingPromise: null,
    saveError: '',
    uploadingQuestionId: null,
    deletingEvidenceId: null,
    hasPendingSave: false,
    isReviewing: false,
    saveStatus: 'Tersimpan',
    lastSavedTime: '{{ $supervision->updated_at ? $supervision->updated_at->copy()->timezone('Asia/Jakarta')->format('H:i') : '' }}',
    evidenceList: {{ Illuminate\Support\Js::from($evidencePayload) }},

    triggerAutoSave() {
        if (this.isReviewing) return;
        if (this.isSaving || this.uploadingQuestionId !== null || this.deletingEvidenceId !== null) {
            this.hasPendingSave = true;
            return;
        }

        this.isSaving = true;
        this.saveStatus = 'Menyimpan...';

        this.savingPromise = fetch('{{ route('supervisor.supervisions.draft', $supervision) }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Accept': 'application/json',
            },
            body: JSON.stringify({
                answers: this.answers,
                notes: this.notes,
                row_version: this.rowVersion,
            })
        })
        .then(async (response) => {
            const data = await response.json();
            if (response.status === 409) {
                this.saveStatus = 'Konflik Versi! Harap refresh halaman.';
                if (window.Swal) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Konflik Versi Formulir',
                        text: 'Formulir ini telah diubah di sesi lain. Silakan muat ulang halaman untuk mencegah penimpaan data.',
                        confirmButtonColor: '#7B1113'
                    });
                }
                return;
            }
            if (!response.ok) {
                throw new Error(data.message || 'Gagal menyimpan draf.');
            }

            this.rowVersion = data.row_version;
            this.saveError = '';
            this.lastSavedTime = new Date(data.updated_at).toLocaleTimeString('id-ID', {
                hour: '2-digit',
                minute: '2-digit',
                hour12: false,
                timeZone: 'Asia/Jakarta'
            });
            this.saveStatus = 'Tersimpan';
        })
        .catch((err) => {
            this.saveStatus = 'Gagal menyimpan draf';
            this.saveError = err.message;
            console.error(err);
        })
        .finally(() => {
            this.isSaving = false;
            if (this.hasPendingSave) {
                this.hasPendingSave = false;
                this.triggerAutoSave();
            }
        });
    },

    async uploadEvidenceFile(event, questionId) {
        const file = event.target.files[0];
        if (!file || this.isReviewing || this.uploadingQuestionId !== null || this.deletingEvidenceId !== null) return;

        this.uploadingQuestionId = questionId;
        if (this.isSaving) await this.savingPromise;
        const formData = new FormData();
        formData.append('file', file);
        formData.append('question_id', questionId);
        formData.append('_token', '{{ csrf_token() }}');

        fetch('{{ route('supervisor.supervisions.upload_evidence', $supervision) }}', {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
            },
            body: formData
        })
        .then(async (res) => {
            const data = await res.json();
            if (!res.ok) throw new Error(data.message || 'Gagal mengunggah bukti.');
            this.rowVersion = data.row_version;
            this.evidenceList.push({
                id: data.evidence.id,
                question_id: data.evidence.question_id,
                file_name: data.evidence.file_name,
                file_size: data.evidence.file_size,
                download_url: data.evidence.download_url,
            });
            event.target.value = '';
        })
        .catch(err => {
            if (window.Swal) {
                Swal.fire({
                    icon: 'error',
                    title: 'Gagal Mengunggah Bukti',
                    text: err.message,
                    confirmButtonColor: '#7B1113'
                });
            }
        })
        .finally(() => {
            this.uploadingQuestionId = null;
            if (this.hasPendingSave) {
                this.hasPendingSave = false;
                this.triggerAutoSave();
            }
        });
    },

    deleteEvidenceFile(id) {
        window.confirmModal({
            title: 'Hapus Berkas Bukti?',
            text: 'Berkas bukti ini akan dihapus dari data supervisi.',
            icon: 'warning',
            confirmText: 'Ya, Hapus',
            cancelText: 'Batal',
            confirmColor: '#DC2626',
            onConfirm: async () => {
                if (this.isReviewing || this.uploadingQuestionId !== null || this.deletingEvidenceId !== null) return;
                this.deletingEvidenceId = id;
                if (this.isSaving) await this.savingPromise;
                fetch('/supervisor/supervisions/evidence/' + id, {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'Accept': 'application/json',
                    }
                })
                .then(async (res) => {
                    const data = await res.json();
                    if (res.ok) {
                        this.rowVersion = data.row_version;
                        this.evidenceList = this.evidenceList.filter(e => e.id !== id);
                        return;
                    }
                    throw new Error(data.message || 'Gagal menghapus bukti.');
                })
                .catch(err => {
                    if (window.Swal) {
                        Swal.fire({ icon: 'error', title: 'Gagal Menghapus Bukti', text: err.message, confirmButtonColor: '#7B1113' });
                    }
                })
                .finally(() => {
                    this.deletingEvidenceId = null;
                    if (this.hasPendingSave) {
                        this.hasPendingSave = false;
                        this.triggerAutoSave();
                    }
                });
            }
        });
    },
}">

    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <a href="{{ route('supervisor.assignments.index') }}" class="inline-flex items-center gap-1 text-xs font-semibold text-[#7B1113] hover:underline">&larr; Kembali ke daftar tugas</a>
            <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900">Formulir Supervisi Pembelajaran</h1>
            <p class="mt-1 text-xs text-slate-500">Isi penilaian sesuai hasil observasi. Perubahan tersimpan otomatis sebagai draf.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2 self-start">
            <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold"
                  role="status"
                  aria-live="polite"
                  aria-atomic="true"
                  :class="{
                      'bg-emerald-50 text-emerald-800': saveStatus === 'Tersimpan',
                      'bg-amber-50 text-amber-800': isSaving,
                      'bg-rose-50 text-rose-800': saveStatus.includes('Konflik') || saveStatus.includes('Gagal')
                  }">
                <span class="h-1.5 w-1.5 rounded-full" :class="isSaving ? 'bg-amber-500 animate-pulse' : (saveStatus.includes('Konflik') || saveStatus.includes('Gagal') ? 'bg-rose-500' : 'bg-emerald-500')"></span>
                <span x-text="saveStatus"></span>
                <span x-show="lastSavedTime && saveStatus === 'Tersimpan'" class="font-normal opacity-75" x-text="lastSavedTime"></span>
            </span>
            <span class="inline-flex rounded-full border border-amber-300 bg-amber-100 px-3 py-1 text-xs font-bold text-amber-900">{{ $assignment->supervisionStage->name }}</span>
        </div>
    </div>

    <div class="rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900" role="status">
        Formulir dapat diisi mulai {{ $assignment->supervisionStage->starts_on?->format('d/m/Y') }} sampai {{ $assignment->supervisionStage->ends_on?->format('d/m/Y') }}. Setelah tanggal akhir, input dan pengiriman supervisi ditutup.
    </div>
    <div x-cloak x-show="saveError" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800" role="alert" x-text="saveError"></div>

    <!-- Target Lecturer & Occurrence Metadata Card -->
    <div class="bg-white rounded-2xl p-6 border border-slate-200 shadow-sm">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 border-b border-slate-100 pb-4">
            <div>
                <span class="text-[10px] font-bold uppercase tracking-wider text-rose-800">
                    Formulir Evaluasi Pembelajaran ({{ $supervision->formTemplate->delivery_mode }})
                </span>
                <h1 class="text-xl font-bold text-slate-900 mt-0.5">
                    {{ $assignment->targetLecturer->name }}
                </h1>
                <p class="text-xs text-slate-500">
                    NIDN: {{ $assignment->targetLecturer->nidn ?? '-' }} • Program Studi: {{ $assignment->targetLecturer->studyProgram?->name ?? '-' }}
                </p>
            </div>
            <div class="text-right">
                <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-bold bg-slate-100 text-slate-800">
                    Tahap: {{ $assignment->supervisionStage->name }}
                </span>
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 pt-4 text-xs">
            <div>
                <span class="text-slate-400">Mata Kuliah:</span>
                <p class="font-bold text-slate-800">
                    {{ $assignment->teachingOccurrence?->teachingSchedule?->course?->name }} ({{ $assignment->teachingOccurrence?->teachingSchedule?->course?->code }})
                </p>
            </div>
            <div>
                <span class="text-slate-400">Kelas & Ruang:</span>
                <p class="font-bold text-slate-800">
                    Kelas {{ $assignment->teachingOccurrence?->teachingSchedule?->class_name }} • Ruang {{ $assignment->teachingOccurrence?->teachingSchedule?->room_display }}
                </p>
            </div>
            <div>
                <span class="text-slate-400">Tanggal & Waktu:</span>
                <p class="font-bold text-slate-800">
                    {{ $assignment->teachingOccurrence?->occurrence_date->format('d M Y') }} ({{ substr($assignment->teachingOccurrence?->start_time_local, 0, 5) }}-{{ substr($assignment->teachingOccurrence?->end_time_local, 0, 5) }})
                </p>
            </div>
            <div>
                <span class="text-slate-400">Kebijakan Penilaian:</span>
                <p class="font-bold text-slate-800">
                    {{ $supervision->scoringPolicy->name }}
                </p>
            </div>
        </div>
    </div>

    <!-- Dynamic Form Sections -->
    <form id="submit-supervision-form" action="{{ route('supervisor.supervisions.review_draft', $supervision) }}" method="POST" @submit="isReviewing = true">
        @csrf
        <input type="hidden" name="row_version" :value="rowVersion">

        @if($errors->any())
            <div class="mb-6 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900" role="alert">
                <p class="font-bold">Lengkapi formulir sebelum meninjau hasil.</p>
                <ul class="mt-2 list-disc space-y-1 pl-5 text-xs">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="space-y-6">
            @foreach($supervision->formTemplate->sections as $sectionIndex => $section)
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                    <div class="bg-gradient-to-r from-slate-50 to-slate-100 p-4 border-b border-slate-200 flex items-center justify-between">
                        <div>
                            <span class="text-[10px] font-bold uppercase tracking-wider text-rose-800">
                                Bagian {{ $section->section_order }}
                            </span>
                            <h2 class="text-sm font-bold text-slate-900">{{ $section->title }}</h2>
                            @if($section->description)
                                <p class="text-xs text-slate-500 mt-0.5">{{ $section->description }}</p>
                            @endif
                        </div>
                        <span class="text-xs font-semibold text-slate-500">{{ $section->questions->count() }} Butir</span>
                    </div>

                    <div class="divide-y divide-slate-100 p-5 space-y-5">
                        @foreach($section->questions as $questionIndex => $question)
                            <fieldset class="pt-3 first:pt-0">
                                <legend class="sr-only">
                                    Pertanyaan {{ $questionIndex + 1 }}: {{ $question->question_text }}
                                    {{ $question->is_required ? '(wajib)' : '' }}
                                </legend>
                                <div class="flex items-start justify-between gap-3 mb-2">
                                    <div id="question-label-{{ $question->id }}" class="text-xs font-medium text-slate-900 leading-relaxed">
                                        <span class="font-bold text-rose-800 mr-1.5">
                                            {{ $questionIndex + 1 }}.
                                        </span>
                                        {{ $question->question_text }}
                                        @if($question->is_scored || $question->is_required)
                                            <span class="text-red-600 font-bold ml-1" aria-hidden="true">*</span>
                                            <span class="sr-only">(wajib)</span>
                                        @endif
                                    </div>
                                    <div class="shrink-0">
                                        @if($question->is_scored)
                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold bg-emerald-100 text-emerald-800">
                                                Skor 0-4
                                            </span>
                                        @endif
                                    </div>
                                </div>

                                <!-- Input widget by question_type -->
                                @if($question->question_type === 'SCORE')
                                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-2 mt-2">
                                        @foreach($question->options as $opt)
                                            <label class="flex items-center p-2.5 rounded-xl border border-slate-200 hover:border-rose-400 cursor-pointer transition-colors text-xs"
                                                   :class="{ 'border-rose-600 bg-rose-50/50 text-rose-900 font-semibold': answers[{{ $question->id }}] == {{ $opt->id }} }">
                                                <input type="radio" 
                                                       name="answers[{{ $question->id }}]" 
                                                       value="{{ $opt->id }}" 
                                                       x-model="answers[{{ $question->id }}]"
                                                       @change="triggerAutoSave()"
                                                       class="w-3.5 h-3.5 text-rose-800 focus:ring-rose-700">
                                                <span class="ml-2">
                                                    {{ $opt->option_text }}
                                                    @if($opt->numeric_score !== null)
                                                        <strong class="text-emerald-700">({{ $opt->numeric_score }})</strong>
                                                    @endif
                                                </span>
                                            </label>
                                        @endforeach
                                    </div>
                                @elseif($question->question_type === 'YES_NO')
                                    <div class="flex items-center gap-4 mt-2">
                                        @foreach($question->options as $opt)
                                            <label class="flex items-center p-2.5 px-4 rounded-xl border border-slate-200 hover:border-rose-400 cursor-pointer transition-colors text-xs"
                                                   :class="{ 'border-rose-600 bg-rose-50/50 text-rose-900 font-semibold': answers[{{ $question->id }}] == {{ $opt->id }} }">
                                                <input type="radio" 
                                                       name="answers[{{ $question->id }}]" 
                                                       value="{{ $opt->id }}" 
                                                       x-model="answers[{{ $question->id }}]"
                                                       @change="triggerAutoSave()"
                                                       class="w-3.5 h-3.5 text-rose-800 focus:ring-rose-700">
                                                <span class="ml-2 font-medium">{{ $opt->option_text }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                @elseif($question->question_type === 'LINK')
                                    <div class="mt-2">
                                        <input type="url" 
                                               id="answer-{{ $question->id }}"
                                               name="answers[{{ $question->id }}]" 
                                               aria-labelledby="question-label-{{ $question->id }}"
                                               x-model="answers[{{ $question->id }}]"
                                               @input.debounce.750ms="triggerAutoSave()"
                                               placeholder="https://..."
                                               class="w-full px-3 py-2 rounded-xl border border-slate-300 bg-white text-xs text-slate-900 focus:ring-2 focus:ring-rose-700">
                                    </div>
                                @elseif($question->usesStructuredEvaluation())
                                    <p class="mt-2 text-xs text-slate-500">{{ $question->is_required ? 'Isi minimal satu kolom sesuai hasil pengamatan.' : 'Isi kolom yang sesuai dengan hasil pengamatan.' }} Tidak harus ada kekurangan pada setiap observasi.</p>
                                    <div class="mt-3 space-y-3">
                                        @foreach(\App\Services\Supervision\EvaluationNotes::LABELS as $noteKey => $noteLabel)
                                            <div>
                                                <label for="evaluation-{{ $question->id }}-{{ $noteKey }}" class="mb-1 block text-xs font-semibold text-slate-700">{{ $noteLabel }}</label>
                                                <textarea id="evaluation-{{ $question->id }}-{{ $noteKey }}"
                                                          name="answers[{{ $question->id }}][{{ $noteKey }}]"
                                                          x-model="answers[{{ $question->id }}].{{ $noteKey }}"
                                                          @input.debounce.750ms="triggerAutoSave()"
                                                          rows="3" maxlength="5000"
                                                          class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs text-slate-900 focus:ring-2 focus:ring-rose-700"></textarea>
                                            </div>
                                        @endforeach
                                    </div>
                                @elseif($question->question_type === 'TEXTAREA')
                                    <div class="mt-2">
                                        <textarea id="answer-{{ $question->id }}"
                                                  name="answers[{{ $question->id }}]" 
                                                  aria-labelledby="question-label-{{ $question->id }}"
                                                  x-model="answers[{{ $question->id }}]"
                                                  @input.debounce.750ms="triggerAutoSave()"
                                                  rows="3" 
                                                  placeholder="Tuliskan catatan evaluasi kualitatif dan rekomendasi konstruktif..."
                                                  class="w-full px-3 py-2 rounded-xl border border-slate-300 bg-white text-xs text-slate-900 focus:ring-2 focus:ring-rose-700"></textarea>
                                    </div>
                                @elseif($question->question_type === 'FILE')
                                    <div class="mt-3 rounded-xl border border-dashed border-slate-300 bg-slate-50 p-4">
                                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                            <p class="text-xs text-slate-600">Unggah PDF, PNG, atau JPG dengan ukuran maksimal 5 MB.</p>
                                            <label class="inline-flex cursor-pointer items-center justify-center rounded-xl bg-[#7B1113] px-3.5 py-2 text-xs font-semibold text-white shadow-xs transition-colors hover:bg-[#5E0C0F]">
                                                <span x-text="uploadingQuestionId === {{ $question->id }} ? 'Mengunggah...' : 'Pilih Berkas'"></span>
                                                <input type="file"
                                                       @change="uploadEvidenceFile($event, {{ $question->id }})"
                                                       :disabled="isReviewing || uploadingQuestionId !== null || deletingEvidenceId !== null"
                                                       accept=".pdf,.png,.jpg,.jpeg"
                                                       class="hidden">
                                            </label>
                                        </div>
                                        <div class="mt-3 space-y-2">
                                            <template x-for="ev in evidenceList.filter(item => item.question_id == {{ $question->id }})" :key="ev.id">
                                                <div class="flex items-center justify-between rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs">
                                                    <span x-text="ev.file_name" class="truncate font-medium text-slate-800"></span>
                                                    <div class="ml-3 flex shrink-0 items-center gap-3">
                                                        <a :href="ev.download_url" target="_blank" class="font-semibold text-[#7B1113] hover:underline">Unduh</a>
                                                        <button type="button" @click="deleteEvidenceFile(ev.id)" :disabled="isReviewing || uploadingQuestionId !== null || deletingEvidenceId !== null" class="font-semibold text-rose-600 hover:text-rose-800 disabled:opacity-50">Hapus</button>
                                                    </div>
                                                </div>
                                            </template>
                                        </div>
                                    </div>
                                @endif
                                @if($question->question_type !== 'TEXTAREA')
                                    <div class="mt-3 rounded-xl border-l-4 border-rose-200 bg-slate-50 p-3">
                                        <label for="note-{{ $question->id }}" class="mb-1 block text-xs font-semibold text-slate-700">Catatan untuk nilai butir ini (opsional)</label>
                                        <textarea id="note-{{ $question->id }}"
                                                  name="notes[{{ $question->id }}]"
                                                  x-model="notes[{{ $question->id }}]"
                                                  @input.debounce.750ms="triggerAutoSave()"
                                                  rows="2"
                                                  maxlength="5000"
                                                  placeholder="Tuliskan alasan atau temuan yang mendukung nilai butir ini, jika diperlukan."
                                                  class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs text-slate-900 focus:ring-2 focus:ring-rose-700"></textarea>
                                    </div>
                                @endif
                            </fieldset>
                        @endforeach
                    </div>
                </div>
            @endforeach

            <!-- Review Button Card -->
            <div class="flex flex-col items-center justify-between gap-4 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:flex-row">
                <div>
                    <h3 class="text-base font-bold text-slate-900">Tinjau hasil supervisi</h3>
                    <p class="mt-0.5 text-xs text-slate-500">
                        Periksa jawaban dan pratinjau skor setiap butir sebelum hasil dikirim.
                    </p>
                </div>
                <button 
                    type="submit"
                    :disabled="isSaving || isReviewing || uploadingQuestionId !== null || deletingEvidenceId !== null"
                    class="inline-flex cursor-pointer items-center rounded-xl bg-[#7B1113] px-6 py-3 text-sm font-bold text-white shadow-xs transition-colors hover:bg-[#5E0C0F] disabled:cursor-wait disabled:opacity-50"
                >
                    Tinjau jawaban dan skor
                </button>
            </div>
        </div>
    </form>
    <aside id="live-supervision-score"
           aria-label="Ringkasan nilai supervisi langsung"
           class="fixed inset-x-0 bottom-0 z-30 border-t border-slate-200 bg-white shadow-[0_-4px_20px_rgba(15,23,42,0.08)] print:hidden"
           :class="sidebarCollapsed ? 'lg:left-20' : 'lg:left-64 xl:left-72'">
        <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-x-5 gap-y-2 px-4 py-3 sm:px-6 lg:px-8" style="padding-bottom: max(0.75rem, env(safe-area-inset-bottom));">
            <div class="min-w-0">
                <p class="text-xs font-semibold text-slate-500">Nilai sementara</p>
                <div class="mt-0.5 flex items-baseline gap-1.5" role="status" aria-live="polite" aria-atomic="true">
                    <span class="text-2xl font-bold tabular-nums text-[#7B1113]" x-text="liveScore.finalScore === null ? '—' : liveScore.finalScore.toFixed(2)">—</span>
                    <span class="text-xs text-slate-500">/ 4.00</span>
                </div>
            </div>
            <div class="text-right">
                <p class="text-xs font-semibold text-slate-700"><span class="tabular-nums" x-text="liveScore.answeredCount + ' / ' + liveScore.totalCount">0 / {{ $scoredQuestionsPayload->count() }}</span> butir berskor dinilai</p>
                <p class="mt-1 text-[11px] text-slate-500">Dihitung dari butir yang sudah dinilai.</p>
            </div>
        </div>
    </aside>
</div>
@endsection
