import assert from 'node:assert/strict';
import { after, before, describe, it } from 'node:test';
import { startBrowser } from './chrome.js';

let browser;
let page;

before(async () => {
    browser = await startBrowser();
    page = await browser.newPage();
});

after(async () => {
    await browser.close();
});

async function action(fixture, label) {
    await page.goto(`${browser.base}/${fixture}`);
    const observation = await page.read();

    return observation.actions.find((a) => a.label === label);
}

const centre = (a) => [a.rect.x + a.rect.w / 2, a.rect.y + a.rect.h / 2];
const blocker = (a, [x, y] = centre(a)) => page.reader(`pageReader.blocker(${a.node}, ${x}, ${y})`);

describe('blocker', () => {
    it('clears a control whose click point shows the control', async () => {
        assert.equal(await blocker(await action('names.html', 'Aria label')), null);
    });

    it('clears a transparent input whose click point shows its label', async () => {
        assert.equal(await blocker(await action('transparent.html', 'Accept terms')), null);
    });

    it('clears a transparent input whose click point shows its stand-in overlay', async () => {
        assert.equal(await blocker(await action('transparent.html', 'Dark mode')), null);
    });

    it('clears controls in open shadow roots and same-origin frames', async () => {
        assert.equal(await blocker(await action('shadow.html', 'Shadow button')), null);
        assert.equal(await blocker(await action('frame.html', 'Inner button')), null);
    });

    it('reports a control covered by another element', async () => {
        const target = await action('names.html', 'Aria label');
        await page.run('document.body.insertAdjacentHTML("beforeend", "<div style=\\"position:fixed;inset:0;background:white\\">Cookie banner</div>")');

        assert.equal(await blocker(target), '<div> "Cookie banner"');
    });

    it('reports a control that became unusable or left the document', async () => {
        const target = await action('names.html', 'Aria label');
        await page.run('document.querySelector("[aria-label=\\"Aria label\\"]").disabled = true');
        assert.equal(await blocker(target), 'the control is no longer usable');
        await page.run('document.querySelector("[aria-label=\\"Aria label\\"]").remove()');
        assert.equal(await blocker(target), 'the control left the document');
    });
});

describe('stand-ins', () => {
    it('change the control guard when replaced', async () => {
        const toggle = await action('transparent.html', 'Dark mode');
        const before = await page.reader(`pageReader.guard(${toggle.node})`);
        await page.run('const knob = document.getElementById("knob"); knob.replaceWith(knob.cloneNode())');

        assert.notEqual(await page.reader(`pageReader.guard(${toggle.node})`), before);
    });
});

describe('quiet', () => {
    it('reports how long the document has gone unchanged, and waits in the page for stillness', async () => {
        await page.goto(`${browser.base}/names.html`);
        await page.run('document.body.append("changed")');
        const fresh = await page.reader('pageReader.quietMs()');
        const held = await page.reader('pageReader.still(100, 2000)');

        assert.ok(fresh < 100);
        assert.equal(held.still, true);
        assert.ok(held.quiet >= 100);
    });

    it('gives up at the timeout on a page that keeps changing', async () => {
        await page.goto(`${browser.base}/names.html`);
        await page.run('setInterval(() => document.body.dataset.tick = Date.now(), 10)');
        const held = await page.reader('pageReader.still(200, 300)');

        assert.equal(held.still, false);
        assert.ok(held.waited >= 300);
    });
});

describe('setValue', () => {
    const events = 'window.seen = []; for (const t of ["input", "change"]) document.addEventListener(t, (e) => seen.push(t + ":" + e.target.getAttribute("aria-label")))';

    it('sets a value input and fires input then change to page listeners', async () => {
        const date = await action('settable.html', 'Date');
        await page.run(events);

        assert.equal(await page.reader(`pageReader.setValue(${date.node}, "2026-10-01")`), '2026-10-01');
        assert.equal(await page.run('document.querySelector("[aria-label=Date]").value'), '2026-10-01');
        assert.deepEqual(await page.run('seen'), ['input:Date', 'change:Date']);
    });

    it('returns the value the input cleared or clamped', async () => {
        const date = await action('settable.html', 'Date');
        const volume = (await page.read()).actions.find((a) => a.label === 'Volume');

        assert.equal(await page.reader(`pageReader.setValue(${date.node}, "24/09/2026")`), '');
        assert.equal(await page.reader(`pageReader.setValue(${volume.node}, "80")`), '50');
    });

    it('chooses an enabled select option by value', async () => {
        const size = await action('select.html', 'Size → Small');

        assert.equal(await page.reader(`pageReader.setValue(${size.node}, "s")`), 's');
        assert.equal(await page.run('document.getElementById("size").value'), 's');
        assert.equal(await page.reader(`pageReader.setValue(${size.node}, "l")`), 's');
    });

    it('refuses controls that are not selects or value inputs', async () => {
        const name = await action('names.html', 'Email address');

        await assert.rejects(page.reader(`pageReader.setValue(${name.node}, "x")`), /setValue takes a select or a value input/);
    });
});
