<label class="filter-field">
    <span class="sr-only">Program Studi</span>
    <select name="study_program_id" onchange="this.form.submit()" class="filter-control">
        <option value="">Semua Program Studi</option>
        @foreach($scope->programs() as $program)
            <option value="{{ $program->id }}" @selected($scope->studyProgramId() === $program->id)>{{ $program->name }}</option>
        @endforeach
    </select>
</label>
