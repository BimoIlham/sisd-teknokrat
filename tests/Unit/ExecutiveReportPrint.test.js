import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';

const template = readFileSync(new URL('../../resources/views/lpm/reports/executive.blade.php', import.meta.url), 'utf8');
const source = template.match(/<script>([\s\S]*?)<\/script>/)[1];

function fixture({ printError = false } = {}) {
    const properties = new Map();
    const listeners = new Map();
    const messages = [];
    const printStyles = { media: 'print' };
    const sheet = { clientWidth: 1070, clientHeight: 733 };
    const report = {
        hidden: true,
        closest: () => sheet,
        style: {
            setProperty: (name, value) => properties.set(name, value),
            removeProperty: name => properties.delete(name),
        },
        offsetHeight: 900,
        scrollHeight: 900,
        get offsetWidth() { return Number.parseFloat(properties.get('--report-print-width')) || 1070; },
        get scrollWidth() { return this.offsetWidth; },
        querySelectorAll: () => [{ decode: () => new Promise(() => {}) }],
    };
    let printCalls = 0;
    const window = {
        addEventListener: (name, callback) => listeners.set(name, callback),
        alert: message => messages.push(message),
        print() {
            printCalls++;
            assert.equal(printStyles.media, 'all');
            assert.equal(report.hidden, false);
            if (printError) {
                throw new Error('Printing is unavailable');
            }
        },
    };
    const document = {
        fonts: { ready: new Promise(() => {}) },
        getElementById: id => id === 'executive-report-poster' ? report : printStyles,
    };
    runInNewContext(source, { window, document });

    return { window, report, listeners, properties, messages, printStyles, printCalls: () => printCalls };
}

test('clicking print opens the dialog immediately even while fonts and images are pending', () => {
    const page = fixture();
    page.window.printExecutiveReport();
    assert.equal(page.printCalls(), 1);
    const scale = Number(page.properties.get('--report-print-scale'));
    assert.ok(scale > 0 && scale < 1);
    assert.ok(900 * scale <= 731);
});

test('closing or cancelling the dialog restores the report screen', () => {
    const page = fixture();
    page.window.printExecutiveReport();
    page.listeners.get('afterprint')();
    assert.equal(page.printStyles.media, 'print');
    assert.equal(page.report.hidden, true);
    assert.equal(page.properties.size, 0);
    page.window.printExecutiveReport();
    assert.equal(page.printCalls(), 2);
});

test('keyboard printing prepares the same report and restores it afterwards', () => {
    const page = fixture();
    page.listeners.get('beforeprint')();
    assert.equal(page.printStyles.media, 'all');
    assert.equal(page.report.hidden, false);
    assert.ok(page.properties.has('--report-print-scale'));
    page.listeners.get('afterprint')();
    assert.equal(page.printStyles.media, 'print');
    assert.equal(page.report.hidden, true);
});

test('a browser printing error restores the page and shows a readable instruction', () => {
    const page = fixture({ printError: true });
    assert.doesNotThrow(() => page.window.printExecutiveReport());
    assert.equal(page.printStyles.media, 'print');
    assert.equal(page.properties.size, 0);
    assert.equal(page.messages.length, 1);
    assert.match(page.messages[0], /Ctrl\+P/);
});

test('returning to the page restores the screen if the browser skipped afterprint', () => {
    const page = fixture();
    page.window.printExecutiveReport();
    page.listeners.get('pageshow')();
    assert.equal(page.printStyles.media, 'print');
    assert.equal(page.properties.size, 0);
});
