import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';

const template = readFileSync(new URL('../../resources/views/lpm/periods/index.blade.php', import.meta.url), 'utf8');
const expression = template.match(/x-data="([\s\S]*?)">/)[1].replace(/{{[\s\S]*?}}/g, '2026/2027');

function createPeriodForm() {
    const watchers = new Map();
    const form = runInNewContext(`(${expression})`);
    form.$watch = (key, callback) => watchers.set(key, callback);
    form.init();
    const change = (key, value) => {
        const [model, field] = key.split('.');
        form[model][field] = value;
        watchers.get(key)?.();
    };
    return { form, change };
}

test('new period gets a name from the default academic year and semester', () => {
    const { form } = createPeriodForm();
    form.openCreate();
    assert.equal(form.createPeriod.name, 'Supervisi Semester Ganjil 2026/2027');
});

test('automatic name follows changes to year and semester', () => {
    const { form, change } = createPeriodForm();
    change('createPeriod.semester', 'GENAP');
    change('createPeriod.academic_year', '2027/2028');
    assert.equal(form.createPeriod.name, 'Supervisi Semester Genap 2027/2028');
});

test('a manually edited name survives changes to the selection', () => {
    const { form, change } = createPeriodForm();
    form.createPeriod.name = 'Supervisi Khusus';
    change('createPeriod.semester', 'GENAP');
    change('createPeriod.academic_year', '2027/2028');
    assert.equal(form.createPeriod.name, 'Supervisi Khusus');
});

test('incomplete year clears an automatic name until the year is complete', () => {
    const { form, change } = createPeriodForm();
    change('createPeriod.academic_year', '2027/');
    assert.equal(form.createPeriod.name, '');
    change('createPeriod.academic_year', '2027/2028');
    assert.equal(form.createPeriod.name, 'Supervisi Semester Ganjil 2027/2028');
});

test('reopening the create dialog restores the default automatic name', () => {
    const { form } = createPeriodForm();
    form.createPeriod.name = 'Nama Percobaan';
    form.openCreate();
    assert.equal(form.createPeriod.name, 'Supervisi Semester Ganjil 2026/2027');
});

test('editing a period with an automatic name updates it when the semester changes', () => {
    const { form, change } = createPeriodForm();
    form.openEdit({ id: 1, name: 'Supervisi Semester Ganjil 2026/2027', academic_year: '2026/2027', semester: 'GANJIL', status: 'ACTIVE' });
    change('editPeriod.semester', 'GENAP');
    assert.equal(form.editPeriod.name, 'Supervisi Semester Genap 2026/2027');
});

test('editing a period preserves its existing custom name', () => {
    const { form, change } = createPeriodForm();
    form.openEdit({ id: 1, name: 'Supervisi Khusus', academic_year: '2026/2027', semester: 'GANJIL', status: 'ACTIVE' });
    change('editPeriod.academic_year', '2027/2028');
    assert.equal(form.editPeriod.name, 'Supervisi Khusus');
});

test('both dialogs put academic year before semester and name', () => {
    for (const mode of ['create', 'edit']) {
        const year = template.indexOf(`id="${mode}-period-year"`);
        const semester = template.indexOf(`id="${mode}-period-semester"`);
        const name = template.indexOf(`id="${mode}-period-name"`);
        assert.ok(year >= 0 && year < semester && semester < name);
    }
});
