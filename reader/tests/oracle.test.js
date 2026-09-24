import assert from 'node:assert/strict';
import { after, before, it } from 'node:test';
import { startBrowser } from './chrome.js';

// Chrome's accessibility tree is the reference for names: for every control the reader returns,
// Chrome must compute the same accessible name. Chrome keeps trailing whitespace from label text,
// so its names are compared collapsed.
let browser;
let page;

before(async () => {
    browser = await startBrowser();
    page = await browser.newPage();
});

after(async () => {
    await browser.close();
});

const FIXTURES = ['names.html', 'editable.html', 'private.html', 'transparent.html', 'select.html', 'shadow.html', 'exclusions.html', 'clipping.html', 'settable.html', 'many-options.html'];

for (const fixture of FIXTURES) {
    it(`names every control in ${fixture} as Chrome's accessibility tree does`, async () => {
        await page.goto(`${browser.base}/${fixture}`);
        await page.send('Accessibility.enable');
        await page.send('DOM.getDocument', { depth: -1, pierce: true });
        const observation = await page.read();
        const names = new Map();
        for (const action of observation.actions.filter((a) => a.node !== undefined)) {
            if (!names.has(action.node)) {
                const chrome = (await page.axNode(action.node)).name?.value ?? '';
                names.set(action.node, [nameOf(action), chrome.replace(/\s+/g, ' ').trim()]);
            }
        }

        const mismatches = [...names.values()].filter(([ours, chrome]) => ours !== chrome);
        assert.deepEqual(mismatches, []);
    });
}

function nameOf(action) {
    if (action.kind === 'select') {
        return action.label.slice(0, action.label.lastIndexOf(' → '));
    }
    const label = action.kind === 'click' && action.label.startsWith('Open ') ? action.label.slice(5) : action.label;

    return label === action.role ? '' : label;
}
