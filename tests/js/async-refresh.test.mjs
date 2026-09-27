import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

// Exercise the real refresh method without starting Alpine or a browser session.
function pageWithResponse(onRead = () => {}) {
    const source = readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8');
    const object = source.slice(source.indexOf('const asyncPage ='), source.indexOf('window.JudgeAsync ='));
    const context = vm.createContext({
        AbortController, setTimeout, clearTimeout,
        window: { location: { href: 'https://judge.test/review' } },
        fetch: async () => ({
            ok: true, url: 'https://judge.test/review',
            text: async () => { onRead(); return '<main>fresh</main>'; },
        }),
    });
    vm.runInContext(`${object}\nglobalThis.subject = asyncPage;`, context);
    let replacements = 0;
    context.subject.replaceFromHtml = async () => { replacements++; return true; };
    context.subject.showStatus = () => {};
    return { page: context.subject, replacements: () => replacements };
}

test('background refresh preserves an edit begun during the request', async () => {
    let editing = false;
    const { page, replacements } = pageWithResponse(() => { editing = true; });
    assert.equal(await page.refresh(undefined, { canReplace: () => !editing }), false);
    assert.equal(replacements(), 0);
    assert.equal(page.busy, false);
});

test('idle background refresh still replaces the page', async () => {
    const { page, replacements } = pageWithResponse();
    assert.equal(await page.refresh(undefined, { canReplace: () => true }), true);
    assert.equal(replacements(), 1);
    assert.equal(page.busy, false);
});

test('existing callers without a guard keep their refresh behaviour', async () => {
    const { page, replacements } = pageWithResponse();
    assert.equal(await page.refresh(), true);
    assert.equal(replacements(), 1);
});
