import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';

const template = readFileSync(new URL('../../resources/views/lpm/supervisors/index.blade.php', import.meta.url), 'utf8');
const source = template.match(/async function confirmSupervisorPasswordReset\(form\) \{[\s\S]*?(?=\nfunction supervisorDirectoryApp)/)[0];

function fixture(window) {
    const confirmReset = runInNewContext(`${source}; confirmSupervisorPasswordReset`, { window });
    const button = { disabled: false };
    let submissions = 0;
    const form = {
        dataset: { supervisorName: '<img src=x onerror=alert(1)>', supervisorEmail: 'supervisor@example.test', defaultPassword: 'Teknokrat123@' },
        querySelector: () => button,
        submit: () => { submissions++; },
    };
    return { confirmReset, form, button, submissions: () => submissions };
}

test('cancelling a password reset does not submit', async () => {
    const f = fixture({ Swal: { fire: async () => ({ isConfirmed: false }) } });
    await f.confirmReset(f.form);
    assert.equal(f.submissions(), 0);
    assert.equal(f.form.dataset.submitting, 'false');
});

test('confirmation shows the chosen account and default as plain text and submits once', async () => {
    let options;
    const f = fixture({ Swal: { fire: async (value) => { options = value; return { isConfirmed: true }; } } });
    await f.confirmReset(f.form);
    await f.confirmReset(f.form);
    assert.equal(f.submissions(), 1);
    assert.equal(f.button.disabled, true);
    for (const value of Object.values(f.form.dataset).slice(0, 3)) {
        assert.ok(options.text.includes(value));
    }
    assert.equal(options.html, undefined);
    assert.equal(options.titleText, 'Reset Password Supervisor?');
});

test('native confirmation works without SweetAlert', async () => {
    const f = fixture({ confirm: () => true });
    await f.confirmReset(f.form);
    assert.equal(f.submissions(), 1);
});
