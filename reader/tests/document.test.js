import assert from 'node:assert/strict';
import { after, before, describe, it } from 'node:test';
import { startBrowser } from './chrome.js';
import { controlGroups, controlLines, cutLine, headerLines, headingLine } from '../src/render.js';

let browser;
let page;

before(async () => {
    browser = await startBrowser();
    page = await browser.newPage();
});

after(async () => {
    await browser.close();
});

async function observe(options = {}) {
    await page.goto(`${browser.base}/document.html`);

    return page.read(options);
}

const byLabel = (o, label) => o.actions.find((a) => a.label === label);

describe('links and required fields', () => {
    it('gives same-origin links a path and others an absolute url, cut at max_label', async () => {
        const observation = await observe({ max_label: 30 });

        assert.equal(byLabel(observation, 'Same origin link').href, '/names.html?tab=1#part');
        assert.equal(byLabel(observation, 'Other origin link').href, 'https://example.com/away');
        assert.equal(byLabel(observation, 'Long link').href, '/very/long/path/that/goes/on/a');
        assert.equal(byLabel(observation, 'Name').href, undefined);
    });

    it('marks native and ARIA-required fields and leaves others unmarked', async () => {
        const observation = await observe();

        assert.equal(byLabel(observation, 'Name').required, true);
        assert.equal(byLabel(observation, 'Open Name').required, true);
        assert.equal(byLabel(observation, 'Email').required, true);
        assert.equal('required' in byLabel(observation, 'Notes'), false);
    });
});

describe('outline', () => {
    it('lists on-screen headings with their levels in viewport mode', async () => {
        const observation = await observe();

        assert.equal(observation.mode, 'viewport');
        assert.deepEqual(observation.outline, [
            { level: 1, text: 'Top heading' },
            { level: 3, text: 'Level three by role' },
            { level: 2, text: 'Level two by default' },
        ]);
    });

    it('caps the outline at max_outline', async () => {
        assert.equal((await observe({ max_outline: 2 })).outline.length, 2);
    });
});

// The text rendering of a document observation, built from the same pieces the reader measures.
function rendering(o) {
    const cut = Object.values(o.truncated).some((n) => n > 0) ? cutLine(o.truncated) : '';

    return headerLines(o.url, o.title) + o.outline.map(headingLine).join('') + 'Text:\n' + (o.text ? `${o.text}\n` : '')
        + 'Controls:\n' + controlGroups(o.actions).map(controlLines).join('') + cut;
}

describe('document mode', () => {
    it('reads the whole rendered page and offers nothing to act on', async () => {
        await page.goto(`${browser.base}/document.html`);
        await page.run('scrollTo(0, 100)');
        const observation = await page.read({ mode: 'document' });
        const labels = observation.actions.map((a) => a.label);

        assert.equal(observation.mode, 'document');
        assert.match(observation.text, /First screen text[\s\S]*Second screen text/);
        assert.doesNotMatch(observation.text, /Hidden text|Clipped text/);
        assert.ok(labels.includes('Far button'));
        for (const hidden of ['Hidden button', 'Aria hidden button', 'Inert button', 'Clipped button']) {
            assert.equal(labels.includes(hidden), false, hidden);
        }
        assert.equal('page_key' in observation, false);
        assert.equal('guards' in observation, false);
        assert.deepEqual(observation.truncated, { outline: 0, text: 0, actions: 0 });
        assert.deepEqual(observation.outline.map((h) => h.text), ['Top heading', 'Level three by role', 'Level two by default', 'Below the fold']);
    });

    it('describes controls with their node but without ids, rects, empty values, false submits or Open duplicates', async () => {
        const observation = await observe({ mode: 'document' });
        const fields = new Set(observation.actions.flatMap((a) => Object.keys(a)));

        assert.ok(observation.actions.every((a) => Number.isInteger(a.node)));
        for (const field of ['id', 'rect', 'form']) {
            assert.equal(fields.has(field), false, field);
        }
        assert.equal(observation.actions.some((a) => a.value === ''), false);
        assert.equal(observation.actions.some((a) => a.submits === false), false);
        assert.equal(observation.actions.some((a) => a.label.startsWith('Open ')), false);
        const name = observation.actions.find((a) => a.label === 'Name');
        assert.deepEqual(name, { node: name.node, kind: 'fill', role: 'textbox', label: 'Name', required: true });
    });

    it('cuts to fit its rendering in max_document, keeping the outline, then controls, then text', async () => {
        const whole = await observe({ mode: 'document' });
        for (const budget of [2000, 700, 400, 250, 200]) {
            const fitted = await observe({ mode: 'document', max_document: budget });

            assert.ok(rendering(fitted).length <= budget, `rendering of ${rendering(fitted).length} over ${budget}`);
            assert.deepEqual(fitted.outline, whole.outline.slice(0, fitted.outline.length));
            if (fitted.text !== '') {
                assert.equal(fitted.truncated.actions, 0, 'text is kept only once every control fits');
                assert.equal(fitted.truncated.outline, 0);
            }
            if (fitted.actions.length > 0) {
                assert.equal(fitted.truncated.outline, 0, 'controls are kept only once the whole outline fits');
            }
            assert.equal(fitted.truncated.text, whole.text.length - fitted.text.length);
        }
    });

    it('keeps everything when the rendering fits exactly', async () => {
        const whole = await observe({ mode: 'document' });
        const exact = await observe({ mode: 'document', max_document: rendering(whole).length });
        const short = await observe({ mode: 'document', max_document: rendering(whole).length - 1 });

        assert.deepEqual(exact.truncated, { outline: 0, text: 0, actions: 0 });
        assert.ok(short.truncated.text > 0);
    });
});

describe('screen-reader-only content', () => {
    async function read(mode, focusSkip = false) {
        await page.goto(`${browser.base}/sr-only.html`);
        if (focusSkip) {
            await page.run('document.getElementById("skip").focus()');
        }

        return page.read({ mode });
    }

    for (const mode of ['viewport', 'document']) {
        it(`is left out of text and actions in ${mode} mode`, async () => {
            const observation = await read(mode);
            const labels = observation.actions.map((a) => a.label);

            assert.equal(observation.text, 'Visible heading\nSearch\nVisible paragraph');
            assert.ok(labels.includes('Search the whole site'), 'the accessible name still includes the hidden words');
            for (const hidden of ['Skip to content', 'Tiny button', 'Hidden helper']) {
                assert.equal(labels.includes(hidden), false, hidden);
            }
        });
    }

    it('reads a skip link once focus makes it visible', async () => {
        const observation = await read('viewport', true);

        assert.ok(observation.actions.some((a) => a.label === 'Skip to content'));
        assert.match(observation.text, /^Skip to content/);
    });
});

describe('hidden headings', () => {
    it('are kept in a document outline and out of its text, and left out of a viewport outline', async () => {
        await page.goto(`${browser.base}/sr-only.html`);
        await page.run('document.body.insertAdjacentHTML("afterbegin", "<h2 class=\\"sr-only\\">Navigation menu</h2>")');
        const whole = await page.read({ mode: 'document' });
        const screen = await page.read();

        assert.deepEqual(whole.outline, [{ level: 2, text: 'Navigation menu' }, { level: 1, text: 'Visible heading' }]);
        assert.doesNotMatch(whole.text, /Navigation menu/);
        assert.deepEqual(screen.outline, [{ level: 1, text: 'Visible heading' }]);
    });
});

describe('caller queries', () => {
    it('reach an observed element with el(node), get null once it has gone, and cannot see page globals', async () => {
        await page.goto(`${browser.base}/names.html`);
        await page.run('window.appState = { secret: 1 }');
        const link = (await page.read()).actions.find((a) => a.label === 'Link contents');

        assert.equal(await page.reader(`el(${link.node}).getAttribute('href')`), '/somewhere');
        assert.equal(await page.reader('typeof window.appState'), 'undefined');
        await page.run('document.querySelector("a[href=\\"/somewhere\\"]").remove()');
        assert.equal(await page.reader(`el(${link.node})`), null);
    });
});
