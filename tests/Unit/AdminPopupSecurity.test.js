import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';

const read = (path) => readFileSync(new URL('../../' + path, import.meta.url), 'utf8');
const layout = read('resources/views/layouts/app.blade.php');
const helper = layout.slice(layout.indexOf('window.confirmationContent ='), layout.indexOf('</script>', layout.indexOf('window.confirmationContent =')));
const periodTemplate = read('resources/views/lpm/periods/index.blade.php');
const periodExpression = periodTemplate.match(/x-data="([\s\S]*?)">/)[1].replace(/{{[\s\S]*?}}/g, '0');
const payload = '<img src=x onerror=alert(1)>';

class Node {
    constructor(tagName = null, text = '') { this.tagName = tagName; this.children = []; this.text = text; }
    append(...nodes) { this.children.push(...nodes); }
    set textContent(value) { this.children = []; this.text = String(value); }
    get textContent() { return this.text + this.children.map((node) => node.textContent).join(''); }
    set innerHTML(value) { throw new Error('An HTML parsing sink was used'); }
}

function environment(confirmed = false) {
    const calls = [];
    let submissions = 0;
    const document = { createElement: (tag) => new Node(tag.toUpperCase()), createTextNode: (text) => new Node(null, text), getElementById: () => ({ submit: () => { submissions++; } }) };
    const Swal = { fire: (options) => { calls.push(options); return Promise.resolve({ isConfirmed: confirmed }); } };
    const window = { Swal };
    runInNewContext(helper, { window, document, Swal });
    const period = runInNewContext('(' + periodExpression + ')', { window, document });
    return { window, document, calls, period, submissions: () => submissions };
}

function assertSafeContent(options, expected = payload) {
    assert.ok(options.html instanceof Node);
    assert.ok(options.html.textContent.includes(expected));
    const walk = (node) => { assert.notEqual(node.tagName, 'IMG'); assert.notEqual(node.tagName, 'SCRIPT'); node.children.forEach(walk); };
    walk(options.html);
    assert.equal(options.title, undefined);
    assert.equal(typeof options.titleText, 'string');
}

for (const action of ['activate', 'close', 'delete']) {
    test('period ' + action + ' popup preserves malicious names as literal text', async () => {
        const env = environment();
        if (action === 'delete') env.period.confirmDelete('form', payload);
        else env.period.confirmToggle('form', action === 'close', payload);
        assertSafeContent(env.calls[0]);
        await Promise.resolve();
        assert.equal(env.submissions(), 0);
    });
}

test('assignment cancellation popup preserves names as text and still submits on confirmation', async () => {
    const env = environment(true);
    const template = read('resources/views/lpm/assignments/index.blade.php');
    const click = template.match(/data-lecturer-name="[\s\S]*?onclick="([\s\S]*?)"/)[1].replace(/{{[\s\S]*?}}/g, '0');
    const handler = runInNewContext('(function () { ' + click + ' })', { window: env.window, document: env.document });
    handler.call({ dataset: { lecturerName: payload } });
    assertSafeContent(env.calls[0]);
    await Promise.resolve();
    assert.equal(env.submissions(), 1);
});

for (const action of ['activate', 'close', 'delete']) {
    test('period ' + action + ' treats closing-script payload as text and confirms normally', async () => {
        const env = environment(true);
        const value = '</script><script>alert(1)</script>';
        if (action === 'delete') env.period.confirmDelete('form', value);
        else env.period.confirmToggle('form', action === 'close', value);
        assertSafeContent(env.calls[0], value);
        await Promise.resolve();
        assert.equal(env.submissions(), 1);
    });
}
