import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { setImmediate } from 'node:timers/promises';
import { runInNewContext } from 'node:vm';

const template = readFileSync(new URL('../../resources/views/supervisor/supervisions/form.blade.php', import.meta.url), 'utf8');
const expression = template.match(/x-data="([\s\S]*?)">\s*\n/)[1].replace(/{{[\s\S]*?}}/g, '0');

function createForm() {
    const requests = [];
    const alerts = [];
    const popup = { fire: (options) => { alerts.push(options); } };
    let confirmation;
    const form = runInNewContext(`(${expression})`, {
        console: { error() {} },
        Date,
        Swal: popup,
        FormData: class {
            append() {}
        },
        window: { Swal: popup, confirmModal: (options) => { confirmation = options; } },
        fetch: (url, options) => new Promise((resolve) => {
            requests.push({
                url, options,
                complete: (data, { status = 200, html = false, redirected = false } = {}) => resolve({
                    ok: status >= 200 && status < 300,
                    status,
                    redirected,
                    json: async () => {
                        if (html) throw new SyntaxError('Unexpected token < in private server HTML');
                        return data;
                    },
                }),
            });
        }),
    });
    Object.assign(form, {
        rowVersion: 5, answers: {}, notes: {}, evidenceList: [],
        deleteEvidenceUrlTemplate: 'https://example.test/public/supervisor/supervisions/evidence/__EVIDENCE_ID__',
    });

    return { form, requests, alerts, confirm: () => confirmation.onConfirm() };
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
    assert.equal(requests[1].url, 'https://example.test/public/supervisor/supervisions/evidence/20');
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

for (const status of [200, 401, 403, 404, 413, 419, 429, 500, 502, 503, 504]) {
    test(`HTML upload response ${status} shows a useful error and preserves the form`, async () => {
        const { form, requests, alerts } = createForm();
        form.answers = { 1: 10 };
        form.notes = { 1: 'Catatan belum tersimpan' };
        const event = { target: { files: [{}], value: 'bukti.png' } };
        await form.uploadEvidenceFile(event, 10);
        requests[0].complete(null, { status, html: true });
        await setImmediate();
        assert.equal(alerts.length, 1);
        assert.equal(alerts[0].title, 'Gagal Mengunggah Bukti');
        assert.ok(!alerts[0].text.includes('Unexpected token'));
        assert.ok(!alerts[0].text.includes('private server HTML'));
        if (status !== 401) assert.ok(alerts[0].text.includes(`HTTP ${status}`));
        assert.equal(form.rowVersion, 5);
        assert.equal(form.evidenceList.length, 0);
        assert.equal(form.uploadingQuestionId, null);
        assert.equal(form.answers[1], 10);
        assert.equal(form.notes[1], 'Catatan belum tersimpan');
        assert.equal(event.target.value, '');
    });
}

test('upload redirects show a session message instead of parsing the login page', async () => {
    const { form, requests, alerts } = createForm();
    await form.uploadEvidenceFile({ target: { files: [{}], value: 'bukti.png' } }, 10);
    requests[0].complete(null, { html: true, redirected: true });
    await setImmediate();
    assert.ok(alerts[0].text.includes('dialihkan'));
    assert.ok(alerts[0].text.includes('sesi login'));
    assert.equal(form.evidenceList.length, 0);
    assert.equal(form.uploadingQuestionId, null);
});

test('JSON upload validation errors show the file-specific message', async () => {
    const { form, requests, alerts } = createForm();
    await form.uploadEvidenceFile({ target: { files: [{}], value: 'bukti.png' } }, 10);
    requests[0].complete({ message: 'Validation failed', errors: { file: ['Ukuran berkas maksimal 5 MB.'] } }, { status: 422 });
    await setImmediate();
    assert.equal(alerts[0].text, 'Ukuran berkas maksimal 5 MB.');
    assert.equal(form.rowVersion, 5);
});

test('HTML delete errors keep the attachment and release the deletion state', async () => {
    const { form, requests, alerts, confirm } = createForm();
    form.evidenceList = [{ id: 20 }];
    form.deleteEvidenceFile(20);
    await confirm();
    assert.equal(requests[0].url, 'https://example.test/public/supervisor/supervisions/evidence/20');
    requests[0].complete(null, { status: 403, html: true });
    await setImmediate();
    assert.equal(alerts[0].title, 'Gagal Menghapus Bukti');
    assert.ok(alerts[0].text.includes('HTTP 403'));
    assert.equal(form.evidenceList[0].id, 20);
    assert.equal(form.rowVersion, 5);
    assert.equal(form.deletingEvidenceId, null);
});

test('a failed upload still flushes queued notes using the unchanged row version', async () => {
    const { form, requests } = createForm();
    await form.uploadEvidenceFile({ target: { files: [{}], value: 'bukti.png' } }, 10);
    form.triggerAutoSave();
    requests[0].complete(null, { status: 413, html: true });
    await setImmediate();
    assert.equal(requests.length, 2);
    assert.equal(JSON.parse(requests[1].options.body).row_version, 5);
    requests[1].complete({ row_version: 6, updated_at: '2026-11-07T01:00:00Z' });
    await form.savingPromise;
    assert.equal(form.rowVersion, 6);
});

test('autosave HTML failures leave unsaved answers available and explain the expired session', async () => {
    const { form, requests } = createForm();
    form.answers = { 1: 10 };
    form.triggerAutoSave();
    requests[0].complete(null, { status: 419, html: true });
    await form.savingPromise;
    assert.ok(form.saveError.includes('HTTP 419'));
    assert.equal(form.answers[1], 10);
    assert.equal(form.rowVersion, 5);
    assert.equal(form.isSaving, false);
});
