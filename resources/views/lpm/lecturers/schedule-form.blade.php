@extends('layouts.app', ['title' => ($schedule ? 'Edit Jadwal' : 'Tambah Jadwal') . ' — ' . $lecturer->name])

@section('content')
<div class="mx-auto max-w-3xl space-y-6">
    <div>
        <a href="{{ route('lpm.lecturers.show', ['lecturer' => $lecturer, 'period_id' => $period->id]) }}" class="text-sm font-semibold text-[#7B1113] hover:underline">&larr; Kembali ke detail dosen</a>
        <h1 class="mt-3 text-2xl font-bold text-slate-900">{{ $schedule ? 'Edit Jadwal Perkuliahan' : 'Tambah Jadwal Perkuliahan' }}</h1>
        <p class="mt-1 text-sm text-slate-500">{{ $lecturer->name }} · {{ $period->academicTerm->name }}</p>
    </div>

    <form method="POST" action="{{ $schedule
        ? route('lpm.lecturers.schedules.update', ['lecturer' => $lecturer, 'period' => $period, 'schedule' => $schedule])
        : route('lpm.lecturers.schedules.store', ['lecturer' => $lecturer, 'period' => $period]) }}" class="space-y-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
        @csrf
        @if($schedule)
            @method('PUT')
        @endif

        @if($errors->any())
            <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800" role="alert">
                <p class="font-bold">Jadwal belum dapat disimpan.</p>
                <ul class="mt-2 list-inside list-disc space-y-1">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if($schedule?->source_import_id)
            <div class="rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800">
                Jadwal ini berasal dari impor otomatis. Perubahan akan disimpan dan disinkronkan dengan baris sumber impornya.
            </div>
        @endif

        <div class="grid gap-5 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <label for="course_name" class="mb-1.5 block text-sm font-semibold text-slate-700">Mata Kuliah</label>
                <input id="course_name" name="course_name" type="text" list="course-suggestions" maxlength="255" required autocomplete="off" value="{{ old('course_name', $schedule?->course?->name) }}" placeholder="Ketik nama mata kuliah" class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm focus:border-[#7B1113] focus:outline-none focus:ring-2 focus:ring-[#7B1113]/20">
                <datalist id="course-suggestions">
                    @foreach($courses as $course)
                        <option value="{{ $course->name }}" label="{{ $course->code ?? '' }}"></option>
                    @endforeach
                </datalist>
                <p class="mt-1.5 text-xs text-slate-500">Pilih saran yang tersedia atau ketik mata kuliah baru. Nama baru akan langsung ditambahkan ke data sistem.</p>
            </div>

            <div>
                <label for="class_name" class="mb-1.5 block text-sm font-semibold text-slate-700">Kelas</label>
                <input id="class_name" name="class_name" type="text" maxlength="100" required value="{{ old('class_name', $schedule?->class_name) }}" placeholder="Contoh: IF 23 A" class="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm focus:border-[#7B1113] focus:outline-none focus:ring-2 focus:ring-[#7B1113]/20">
            </div>

            <div>
                <label for="room_name" class="mb-1.5 block text-sm font-semibold text-slate-700">Ruang</label>
                <input id="room_name" name="room_name" type="text" maxlength="100" value="{{ old('room_name', $schedule?->room_name) }}" placeholder="Wajib untuk Offline" class="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm focus:border-[#7B1113] focus:outline-none focus:ring-2 focus:ring-[#7B1113]/20">
            </div>

            <div>
                <label for="day_of_week" class="mb-1.5 block text-sm font-semibold text-slate-700">Hari</label>
                <select id="day_of_week" name="day_of_week" required class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm focus:border-[#7B1113] focus:outline-none focus:ring-2 focus:ring-[#7B1113]/20">
                    @foreach([1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'] as $day => $dayName)
                        <option value="{{ $day }}" @selected((string) old('day_of_week', $schedule?->day_of_week ?? 1) === (string) $day)>{{ $dayName }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="delivery_mode" class="mb-1.5 block text-sm font-semibold text-slate-700">Metode Perkuliahan</label>
                <select id="delivery_mode" name="delivery_mode" required class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm focus:border-[#7B1113] focus:outline-none focus:ring-2 focus:ring-[#7B1113]/20">
                    @foreach(['OFFLINE' => 'Offline', 'ONLINE' => 'Online'] as $mode => $label)
                        <option value="{{ $mode }}" @selected(old('delivery_mode', $schedule?->delivery_mode ?? 'OFFLINE') === $mode)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="starts_local" class="mb-1.5 block text-sm font-semibold text-slate-700">Jam Mulai</label>
                <input id="starts_local" name="starts_local" type="time" required value="{{ old('starts_local', $schedule ? substr((string) $schedule->starts_local, 0, 5) : '07:00') }}" class="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm focus:border-[#7B1113] focus:outline-none focus:ring-2 focus:ring-[#7B1113]/20">
            </div>

            <div>
                <label for="ends_local" class="mb-1.5 block text-sm font-semibold text-slate-700">Jam Selesai</label>
                <input id="ends_local" name="ends_local" type="time" required value="{{ old('ends_local', $schedule ? substr((string) $schedule->ends_local, 0, 5) : '08:40') }}" class="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm focus:border-[#7B1113] focus:outline-none focus:ring-2 focus:ring-[#7B1113]/20">
            </div>

            <div class="sm:col-span-2 rounded-xl border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-800">
                Jadwal ini mengikuti status periode supervisi <strong>{{ $period->name }}</strong>. Jadwal tersedia selama periode aktif; tanggal Tahap 1 dan 2 menentukan rentang waktu supervisor bisa mengisi dan mengirim formulir.
            </div>
        </div>

        <div class="flex flex-col-reverse gap-2 border-t border-slate-200 pt-5 sm:flex-row sm:justify-end">
            <a href="{{ route('lpm.lecturers.show', ['lecturer' => $lecturer, 'period_id' => $period->id]) }}" class="inline-flex items-center justify-center rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">Batal</a>
            <button type="submit" class="inline-flex items-center justify-center rounded-xl bg-[#7B1113] px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-[#5A0E13]">{{ $schedule ? 'Simpan Perubahan' : 'Tambah Jadwal' }}</button>
        </div>
    </form>
</div>
@endsection
