const emailPart = (value) => String(value ?? '')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '.')
    .replace(/^\.+|\.+$/g, '');

export function unitAccountCreator(faculties, studyPrograms, initial = {}) {
    const suggestionFor = (role, facultyId, studyProgramId) => {
        if (role === 'fakultas') {
            const faculty = faculties.find((item) => String(item.id) === String(facultyId));

            return faculty ? {
                name: `Admin ${faculty.name}`,
                email: `fakultas.${emailPart(faculty.code)}@teknokrat.ac.id`,
            } : { name: '', email: '' };
        }

        if (role === 'prodi') {
            const program = studyPrograms.find((item) => String(item.id) === String(studyProgramId));

            return program ? {
                name: `Admin Prodi ${program.name}`,
                email: `prodi.${emailPart(program.faculty_code)}.${emailPart(program.code)}@teknokrat.ac.id`,
            } : { name: '', email: '' };
        }

        return { name: '', email: '' };
    };

    const role = initial.role ?? 'fakultas';
    const facultyId = String(initial.facultyId ?? '');
    const studyProgramId = String(initial.studyProgramId ?? '');
    const previousSuggestion = suggestionFor(role, facultyId, studyProgramId);

    return {
        role,
        facultyId,
        studyProgramId,
        name: initial.name ?? '',
        email: initial.email ?? '',
        previousSuggestion,

        init() {
            this.$watch('role', () => this.updateSuggestion());
            this.$watch('facultyId', () => this.updateSuggestion());
            this.$watch('studyProgramId', () => this.updateSuggestion());
            this.updateSuggestion();
        },

        updateSuggestion() {
            const suggestion = suggestionFor(this.role, this.facultyId, this.studyProgramId);

            if (!this.name || this.name === this.previousSuggestion.name) {
                this.name = suggestion.name;
            }

            if (!this.email || this.email === this.previousSuggestion.email) {
                this.email = suggestion.email;
            }

            this.previousSuggestion = suggestion;
        },
    };
}
