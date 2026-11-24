<?php

namespace App\Services;

use App\Models\Faculty;
use App\Models\StudyProgram;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

class OrganizationalScope
{
    private readonly int $facultyId;

    private readonly ?int $studyProgramId;

    private readonly string $label;

    public function __construct(User $user, private readonly ?Faculty $leadershipFaculty = null, ?int $programId = null)
    {
        if ($leadershipFaculty) {
            abort_unless($user->isLeadership(), 403);
            abort_unless($leadershipFaculty->is_active, 404);
            if ($programId !== null) {
                abort_unless($leadershipFaculty->studyPrograms()->whereKey($programId)->exists(), 404);
            }
            $this->facultyId = $leadershipFaculty->id;
            $this->studyProgramId = $programId;
            $this->label = $leadershipFaculty->name;

            return;
        }

        if ($user->hasRole('prodi') && ! $user->hasRole('fakultas')) {
            $program = StudyProgram::query()->where('is_active', true)
                ->whereHas('faculty', fn (Builder $query): Builder => $query->where('is_active', true))
                ->find($user->study_program_id);
            abort_unless($program, 403, 'Unit program studi akun ini belum tersedia.');
            $this->facultyId = $program->faculty_id;
            $this->studyProgramId = $program->id;
            $this->label = $program->name;

            return;
        }

        if ($user->hasRole('fakultas') && ! $user->hasRole('prodi')) {
            $faculty = Faculty::query()->where('is_active', true)->find($user->faculty_id);
            abort_unless($faculty, 403, 'Unit fakultas akun ini belum tersedia.');
            $this->facultyId = $faculty->id;
            $this->studyProgramId = null;
            $this->label = $faculty->name;

            return;
        }

        abort(403, 'Akun ini tidak memiliki cakupan unit yang valid.');
    }

    public static function forRequest(Request $request): self
    {
        return new self(
            $request->user(),
            $request->route('faculty'),
            $request->routeIs('pimpinan.faculties.*') && $request->filled('study_program_id')
                ? $request->integer('study_program_id')
                : null,
        );
    }

    public function isLeadershipFaculty(): bool
    {
        return $this->leadershipFaculty !== null;
    }

    /** @return Collection<int, StudyProgram> */
    public function programs(): Collection
    {
        return StudyProgram::where('faculty_id', $this->facultyId)->orderBy('name')->get();
    }

    /** @param array<string, mixed> $parameters */
    public function route(string $name, array $parameters = []): string
    {
        if ($this->leadershipFaculty) {
            return route('pimpinan.faculties.'.$name, $parameters + [
                'faculty' => $this->leadershipFaculty,
                'study_program_id' => $this->studyProgramId,
            ]);
        }

        return route('unit.'.$name, $parameters);
    }

    public function facultyId(): int
    {
        return $this->facultyId;
    }

    public function studyProgramId(): ?int
    {
        return $this->studyProgramId;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function roleLabel(): string
    {
        return $this->studyProgramId && ! $this->isLeadershipFaculty() ? 'Program Studi' : 'Fakultas';
    }

    public function lecturers(Builder $query): Builder
    {
        if ($this->studyProgramId) {
            return $query->where('study_program_id', $this->studyProgramId);
        }

        return $query->where(function (Builder $lecturers): void {
            $lecturers->whereHas('studyProgram', fn (Builder $programs): Builder => $programs->where('faculty_id', $this->facultyId))
                ->orWhere(fn (Builder $withoutProgram): Builder => $withoutProgram->whereNull('study_program_id')->where('faculty_id', $this->facultyId));
        });
    }

    public function targets(Builder $query): Builder
    {
        return $query->whereHas('lecturer', fn (Builder $lecturers): Builder => $this->lecturers($lecturers));
    }

    public function supervisions(Builder $query): Builder
    {
        return $query->whereHas('assignment.targetLecturer', fn (Builder $lecturers): Builder => $this->lecturers($lecturers));
    }
}
