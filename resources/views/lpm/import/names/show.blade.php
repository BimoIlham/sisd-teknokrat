@extends('layouts.app', ['title' => 'Tinjau Nama Dosen'])

@section('content')
<div class="max-w-6xl mx-auto space-y-5">
    <div class="flex items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Tinjau Nama Dosen</h1>
            <p class="text-sm text-slate-600">{{ $import->original_filename }} · {{ $rows->count() }} baris menunggu keputusan</p>
        </div>
        <a href="{{ route('lpm.import.names.index') }}" class="rounded-xl border border-slate-300 px-4 py-2 text-sm">Semua unggahan</a>
    </div>
    @if (session('success'))
        <div class="rounded-xl bg-emerald-50 p-4 text-sm text-emerald-800">{{ session('success') }}</div>
    @endif
    @if (session('error') || $errors->any())
        <div class="rounded-xl bg-rose-50 p-4 text-sm text-rose-800">{{ session('error') ?: $errors->first() }}</div>
    @endif
    <script>
        window.reviewLecturerOptions = @js($lecturerOptions);
    </script>
    @forelse ($rows as $entry)
        @php($row = $entry['row'])
        @php($match = $entry['match'])
        <section class="rounded-2xl border border-slate-200 bg-white p-5 space-y-4 shadow-sm">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="font-semibold text-slate-900">{{ $row->parsed_lecturer_name }}</h2>
                    <p class="text-sm text-slate-600">Halaman PDF {{ $row->source_page }} · baris {{ $row->row_no }} · {{ $row->parsed_course_name }}</p>
                    @if ($row->skip_reason === \App\Services\ScheduleImport\PeriodLecturerExclusions::DEFERRED_REASON)
                        <p class="mt-1 text-xs font-semibold text-amber-700">Ditunda oleh LPM · masih dapat diputuskan nanti</p>
                    @endif
                </div>
                <span class="rounded-full bg-amber-50 px-3 py-1 text-xs text-amber-800">{{ $match['reason'] }}</span>
            </div>
            <p class="text-sm text-slate-700">NIDN dalam PDF: <strong>{{ $row->normalized_payload['lecturer_external_code'] ?? 'Tidak tercantum' }}</strong>. NIDN kandidat hanya informasi, bukan bukti jika PDF tidak mencantumkannya.</p>
            @if ($match['candidates'])
                <div class="rounded-xl bg-slate-50 p-3 text-sm">
                    <p class="font-semibold text-slate-700">Kandidat:</p>
                    @foreach ($match['candidates'] as $candidate)
                        <p>{{ $candidate['name'] }} · gelar {{ $candidate['degree'] ?: 'tidak tercantum' }} · NIDN {{ $candidate['nidn'] ?: 'tidak tersedia' }}</p>
                    @endforeach
                </div>
            @endif
            <form method="POST" action="{{ route('lpm.import.names.resolve', [$import, $row]) }}" class="flex flex-wrap items-start gap-2">
                @csrf
                <div class="relative w-full sm:w-96" x-data="lecturerPicker(window.reviewLecturerOptions)" @focusout="if (!$el.contains($event.relatedTarget)) open = false">
                    <label class="sr-only" for="lecturer-{{ $row->id }}">Cari dosen yang sudah ada</label>
                    <input type="hidden" name="lecturer_id" :value="typeof selectedId === 'number' ? selectedId : ''">
                    <input type="hidden" name="reference_key" :value="typeof selectedId === 'string' && selectedId.startsWith('reference:') ? selectedId.slice(10) : ''">
                    <input id="lecturer-{{ $row->id }}" x-ref="searchInput" type="search" required autocomplete="off"
                           role="combobox" aria-autocomplete="list" aria-controls="lecturer-options-{{ $row->id }}"
                           :aria-expanded="open" :aria-activedescendant="open && filteredOptions.length ? 'lecturer-option-{{ $row->id }}-' + filteredOptions[highlightedIndex].id : null"
                           x-model="search" @input="searchChanged($event.target.value)" @focus="showOptions()" @click="showOptions()"
                           @keydown.arrow-down.prevent="moveHighlight(1)" @keydown.arrow-up.prevent="moveHighlight(-1)"
                           @keydown.enter="selectHighlighted($event)" @keydown.escape.stop.prevent="open = false"
                           x-effect="$refs.searchInput.setCustomValidity(selectedId ? '' : 'Pilih dosen dari hasil pencarian.')"
                           placeholder="Cari nama dosen atau NIDN..."
                           class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                    <div x-show="open" x-cloak class="absolute z-20 mt-1 w-full overflow-hidden rounded-xl border border-slate-200 bg-white shadow-lg">
                        <ul id="lecturer-options-{{ $row->id }}" x-ref="optionList" role="listbox" aria-label="Hasil pencarian dosen" class="max-h-56 overflow-y-auto">
                            <template x-for="(lecturer, index) in filteredOptions" :key="lecturer.id">
                                <li role="option" :id="'lecturer-option-{{ $row->id }}-' + lecturer.id" :aria-selected="selectedId === lecturer.id">
                                    <button type="button" tabindex="-1" @mousedown.prevent @click="selectOption(lecturer)"
                                            @mouseenter="highlightedIndex = index" :class="highlightedIndex === index ? 'bg-slate-100' : 'bg-white'"
                                            class="block w-full border-b border-slate-100 px-3 py-2 text-left text-sm">
                                        <span class="block font-semibold text-slate-900" x-text="lecturer.name"></span>
                                        <span class="block text-xs text-slate-500" x-text="'NIDN: ' + (lecturer.nidn || 'Belum tersedia')"></span>
                                        <span x-show="lecturer.reference" class="block text-xs text-amber-700">Referensi terverifikasi · identitas akan dipulihkan setelah konfirmasi</span>
                                    </button>
                                </li>
                            </template>
                        </ul>
                        <p x-show="filteredOptions.length === 0" role="status" class="px-3 py-3 text-sm text-slate-500">Tidak ada dosen yang cocok.</p>
                    </div>
                </div>
                <button type="submit" name="action" value="same" class="rounded-xl bg-[#7B1113] px-4 py-2 text-sm font-semibold text-white">Orang yang sama</button>
                <button type="submit" name="action" value="new" formnovalidate onclick="event.preventDefault(); window.confirmModal({ title: 'Buat dosen baru?', text: 'Buat identitas dosen baru dari nama PDF ini?', icon: 'question', confirmText: 'Ya, buat dosen', onConfirm: () => this.form.requestSubmit(this) })" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold">Dosen baru</button>
                <button type="submit" name="action" value="defer" formnovalidate class="rounded-xl border border-slate-300 px-4 py-2 text-sm">Tunda</button>
            </form>
        </section>
    @empty
        <div class="rounded-2xl border border-slate-200 bg-white p-6 text-sm text-slate-600">Semua nama dalam unggahan ini sudah ditinjau.</div>
    @endforelse
</div>
@endsection
