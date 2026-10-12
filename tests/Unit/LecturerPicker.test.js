import assert from 'node:assert/strict';
import { test } from 'node:test';
import { lecturerPicker } from '../../resources/js/lecturer-picker.js';

const lecturers = [
    { id: 1, name: 'Permata, S.Si., M.Si.', nidn: '0210108501', program: 'Informatika' },
    { id: 2, name: 'Berlintina Permatasari, S.P., M.M.', nidn: '0215028706', program: 'Manajemen' },
    { id: 3, name: 'Dosen Tanpa NIDN, M.Kom.', nidn: null, program: null },
];

test('search matches names, NIDN, and programs without discarding leading zeros', () => {
    const picker = lecturerPicker(lecturers);
    picker.searchChanged(' PERMATA informatika ');
    assert.deepEqual(picker.filteredOptions.map((option) => option.id), [1]);
    picker.searchChanged('0215028706');
    assert.deepEqual(picker.filteredOptions.map((option) => option.id), [2]);
    picker.searchChanged('TANPA nidn');
    assert.deepEqual(picker.filteredOptions.map((option) => option.id), [3]);
});

test('matching a name never selects a lecturer automatically and editing clears the selected ID', () => {
    const picker = lecturerPicker(lecturers);
    picker.searchChanged('Permata');
    assert.equal(picker.selectedId, '');
    assert.equal(picker.filteredOptions.length, 2);
    picker.selectOption(lecturers[0]);
    assert.equal(picker.selectedId, 1);
    assert.equal(picker.search, lecturers[0].name);
    assert.equal(picker.open, false);
    picker.searchChanged('Berlintina');
    assert.equal(picker.selectedId, '');
    picker.selectOption(lecturers[1]);
    assert.equal(picker.selectedId, 2);
});

test('keyboard selection follows the highlighted result and handles an empty result', () => {
    const picker = lecturerPicker(lecturers);
    picker.$nextTick = (callback) => callback();
    picker.$refs = {};
    picker.showOptions();
    picker.moveHighlight(1);
    let prevented = false;
    picker.selectHighlighted({ preventDefault() { prevented = true; } });
    assert.equal(prevented, true);
    assert.equal(picker.selectedId, 2);
    picker.searchChanged('tidak ditemukan');
    picker.moveHighlight(-1);
    picker.selectHighlighted({ preventDefault() {} });
    assert.equal(picker.selectedId, '');
    assert.equal(picker.filteredOptions.length, 0);
});

test('reopening the modal resets the previous choice and search', () => {
    const picker = lecturerPicker(lecturers);
    picker.selectOption(lecturers[0]);
    picker.reset();
    assert.equal(picker.selectedId, '');
    assert.equal(picker.search, '');
    assert.equal(picker.open, false);
    assert.equal(picker.filteredOptions.length, 3);
});
