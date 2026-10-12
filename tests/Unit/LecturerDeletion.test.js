import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';

const template = readFileSync(new URL('../../resources/views/lpm/lecturers/index.blade.php', import.meta.url), 'utf8');
const source = template.match(/async function confirmLecturerDeletion\(form\) \{[\s\S]*?(?=\n    function lecturerProfileEditor)/)[0];

function fixture(window) {
    const confirmDeletion = runInNewContext(`${source}; confirmLecturerDeletion`, { window });
    const button = { disabled: false };
    let submissions = 0;
    const form = { dataset: { lecturerName: '<img src=x onerror=alert(1)>' }, querySelector: () => button, submit: () => { submissions++; } };
    return { confirmDeletion, form, button, submissions: () => submissions };
}

test('cancelling deletion does not submit the form', async () => {
    const f = fixture({ Swal: { fire: async () => ({ isConfirmed: false }) } });
    await f.confirmDeletion(f.form);
    assert.equal(f.submissions(), 0);
    assert.equal(f.form.dataset.submitting, 'false');
});

test('confirmation uses plain text and submits only once', async () => {
    let options;
    const f = fixture({ Swal: { fire: async (value) => { options = value; return { isConfirmed: true }; } } });
    await f.confirmDeletion(f.form);
    await f.confirmDeletion(f.form);
    assert.equal(f.submissions(), 1);
    assert.equal(f.button.disabled, true);
    assert.ok(options.text.includes(f.form.dataset.lecturerName));
    assert.equal(options.html, undefined);
    assert.equal(options.titleText, 'Hapus Dosen?');
});

test('native confirmation works when SweetAlert is unavailable', async () => {
    const f = fixture({ confirm: () => true });
    await f.confirmDeletion(f.form);
    assert.equal(f.submissions(), 1);
});
