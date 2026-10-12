import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { setImmediate } from 'node:timers/promises';
import { runInNewContext } from 'node:vm';

const template = readFileSync(new URL('../../resources/views/supervisor/supervisions/form.blade.php', import.meta.url), 'utf8');
const expression = template.match(/x-data="([\s\S]*?)">\s*\n/)[1].replace(/{{[\s\S]*?}}/g, '0');

function createForm() {
    const requests = [];
    let confirmation;
    const form = runInNewContext(`(${expression})`, {
        console,
        Date,
        FormData: class {
            append() {}
        },
        window: { confirmModal: (options) => { confirmation = options; } },
        fetch: (url, options) => new Promise((resolve) => {
            requests.push({
                url, options,
                complete: (data) => resolve({ ok: true, status: 200, json: async () => data }),
            });
        }),
    });
    Object.assign(form, { rowVersion: 5, answers: {}, notes: {}, evidenceList: [] });

    return { form, requests, confirm: () => confirmation.onConfirm() };
}

test('upload waits for autosave and queued autosave uses the new evidence version', async () => {
    const { form, requests } = createForm();
    form.triggerAutoSave();
    assert.equal(JSON.parse(requests[0].options.body).row_version, 5);
    const event = { target: { files: [{}], value: 'bukti.jpg' } };
    const upload = form.uploadEvidenceFile(event, 10);
    form.triggerAutoSave();
    assert.equal(requests.length, 1);
    assert.equal(form.hasPendingSave, true);

    requests[0].complete({ row_version: 6, updated_at: '2026-10-10T01:00:00Z' });
    await upload;
    assert.equal(requests.length, 2);
    assert.equal(form.uploadingQuestionId, 10);
    requests[1].complete({
        row_version: 7,
        evidence: { id: 20, question_id: 10, file_name: 'bukti.jpg', file_size: 100, download_url: '/evidence/20' },
    });
    await setImmediate();
    assert.equal(requests.length, 3);
    assert.equal(JSON.parse(requests[2].options.body).row_version, 7);
    assert.equal(form.evidenceList[0].id, 20);
    assert.equal(event.target.value, '');
    assert.equal(form.uploadingQuestionId, null);
    requests[2].complete({ row_version: 8, updated_at: '2026-10-10T01:00:01Z' });
    await form.savingPromise;
    assert.equal(form.rowVersion, 8);
    assert.equal(form.isSaving, false);
});

test('delete waits for autosave and queued autosave uses the deletion version', async () => {
    const { form, requests, confirm } = createForm();
    form.evidenceList = [{ id: 20 }];
    form.triggerAutoSave();
    form.deleteEvidenceFile(20);
    const deletion = confirm();
    form.triggerAutoSave();
    assert.equal(requests.length, 1);
    requests[0].complete({ row_version: 6, updated_at: '2026-10-10T01:00:00Z' });
    await deletion;
    assert.equal(requests.length, 2);
    assert.equal(requests[1].options.method, 'DELETE');
    assert.equal(form.deletingEvidenceId, 20);
    requests[1].complete({ row_version: 7 });
    await setImmediate();
    assert.equal(form.evidenceList.length, 0);
    assert.equal(form.deletingEvidenceId, null);
    assert.equal(JSON.parse(requests[2].options.body).row_version, 7);
    requests[2].complete({ row_version: 8, updated_at: '2026-10-10T01:00:01Z' });
    await form.savingPromise;
    assert.equal(form.rowVersion, 8);
});

test('another upload cannot start while evidence is changing', async () => {
    const { form, requests } = createForm();
    form.deletingEvidenceId = 20;
    await form.uploadEvidenceFile({ target: { files: [{}] } }, 10);
    assert.equal(requests.length, 0);
    assert.equal(form.uploadingQuestionId, null);
});
