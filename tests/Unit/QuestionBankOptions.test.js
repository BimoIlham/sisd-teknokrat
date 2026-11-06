import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';

const template = readFileSync(new URL('../../resources/views/lpm/instruments/mode.blade.php', import.meta.url), 'utf8');
const source = template.match(/function questionBankApp\(\) \{[\s\S]*?(?=\n<\/script>)/)[0];

function fixture() {
    return runInNewContext(`${source}; questionBankApp()`, { document: { body: { style: {} } } });
}

test('editing a scale question replaces its options when the type changes to yes/no', () => {
    const app = fixture();
    app.openEditModal({ id: 4, question_type: 'SCORE', is_scored: true, options: app.formData.options });
    app.editData.question_type = 'YES_NO';
    app.onTypeChange(app.editData);
    assert.equal(app.editData.options.map(option => option.value).join(','), 'YES,NO');
    assert.equal(app.editData.options.map(option => option.numeric_score).join(','), '4,0');
    assert.equal(app.editData.is_scored, false);
    assert.equal(app.formData.options.length, 5);
    assert.ok(template.includes('@change="onTypeChange(editData)"'));
});

test('opening an invalid yes/no question repairs editable options and preserves the score setting', () => {
    const app = fixture();
    app.openEditModal({ id: 4, question_type: 'YES_NO', is_scored: true, options: app.formData.options });
    assert.equal(app.editData.options.map(option => option.value).join(','), 'YES,NO');
    assert.equal(app.editData.is_scored, true);
});

test('opening a valid yes/no question preserves its labels, IDs, and scores', () => {
    const app = fixture();
    const options = [
        { id: 41, value: 'YES', label: 'Ya, materi tersedia', numeric_score: 4 },
        { id: 42, value: 'NO', label: 'Tidak ada materi', numeric_score: 0 },
    ];
    app.openEditModal({ id: 4, question_type: 'YES_NO', options });
    assert.equal(JSON.stringify(app.editData.options), JSON.stringify(options));
});

test('changing edit types back to scale or to text creates the appropriate options', () => {
    const app = fixture();
    app.editData.question_type = 'SCORE';
    app.onTypeChange(app.editData);
    assert.equal(app.editData.options.map(option => option.value).join(','), '0,1,2,3,4');
    assert.equal(app.editData.is_scored, true);
    app.editData.question_type = 'TEXTAREA';
    app.onTypeChange(app.editData);
    assert.equal(app.editData.options.length, 0);
    assert.equal(app.editData.is_scored, false);
});
