export function lecturerPicker(options = []) {
    return {
        options,
        search: '',
        selectedId: '',
        open: false,
        highlightedIndex: 0,

        get filteredOptions() {
            const normalize = (value) => String(value ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
            const terms = normalize(this.search).trim().split(/\s+/).filter(Boolean);

            return this.options.filter((option) => {
                const text = normalize(`${option.name} ${option.nidn ?? ''} ${option.program ?? ''}`);
                return terms.every((term) => text.includes(term));
            });
        },

        showOptions() {
            this.open = true;
            this.highlightedIndex = 0;
        },

        searchChanged(value) {
            this.search = value;
            this.selectedId = '';
            this.showOptions();
        },

        selectOption(option) {
            const lecturer = this.options.find((candidate) => candidate.id === option.id);
            if (!lecturer) {
                return;
            }

            this.selectedId = lecturer.id;
            this.search = lecturer.name;
            this.open = false;
        },

        moveHighlight(direction) {
            if (!this.open) {
                this.showOptions();
            } else {
                const count = this.filteredOptions.length;
                this.highlightedIndex = count ? (this.highlightedIndex + direction + count) % count : 0;
            }

            this.$nextTick(() => {
                this.$refs.optionList?.querySelectorAll('[role="option"]')[this.highlightedIndex]?.scrollIntoView({ block: 'nearest' });
            });
        },

        selectHighlighted(event) {
            if (!this.open) {
                return;
            }

            event.preventDefault();
            const option = this.filteredOptions[this.highlightedIndex];
            if (option) {
                this.selectOption(option);
            }
        },

        reset() {
            this.search = '';
            this.selectedId = '';
            this.open = false;
            this.highlightedIndex = 0;
        },
    };
}
