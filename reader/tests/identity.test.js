import assert from 'node:assert/strict';
import { after, before, beforeEach, describe, it } from 'node:test';
import { startBrowser } from './chrome.js';

let browser;
let page;
let target;
let field;

before(async () => {
    browser = await startBrowser();
    page = await browser.newPage();
});

after(async () => {
    await browser.close();
});

beforeEach(async () => {
    await page.goto(`${browser.base}/state.html`);
    const observation = await page.read();
    target = observation.actions.find((a) => a.label === 'Target').node;
    field = observation.actions.find((a) => a.label === 'Field').node;
});

const guard = (node) => page.reader(`pageReader.guard(${node})`);
const pageKey = () => page.reader('pageReader.pageKey()');

describe('node handles', () => {
    it('survive a re-read and resolve to the same element', async () => {
        await page.run('document.body.prepend(Object.assign(document.createElement("button"), {textContent: "New first"}))');
        const observation = await page.read();

        assert.equal(observation.actions[0].label, 'New first');
        assert.equal(observation.actions.find((a) => a.label === 'Target').node, target);
        assert.equal(await page.reader(`pageReader.resolve(${target}).id`), 'target');
    });

    it('resolve to null once the element leaves the document', async () => {
        await page.run('document.getElementById("target").remove()');

        assert.equal(await page.reader(`pageReader.resolve(${target})`), null);
        assert.equal(await guard(target), null);
    });

    it('are out of reach of page scripts', async () => {
        assert.equal(await page.run('typeof pageReader'), 'undefined');
    });
});

describe('cached names', () => {
    const label = async (id) => {
        const observation = await page.read();

        return observation.actions.find((a) => a.node === (id === 'target' ? target : field))?.label;
    };

    it('follow a text change', async () => {
        await page.run('document.getElementById("target").textContent = "Renamed"');

        assert.equal(await label('target'), 'Renamed');
    });

    it('follow an attribute change', async () => {
        await page.run('document.getElementById("field").setAttribute("aria-label", "Relabelled")');

        assert.equal(await label('field'), 'Relabelled');
    });

    it('follow a change inside an open shadow root', async () => {
        await page.goto(`${browser.base}/shadow.html`);
        await page.read();
        await page.run('document.querySelector("my-card").shadowRoot.querySelector("button").textContent = "Changed in shadow"');
        const observation = await page.read();

        assert.ok(observation.actions.some((a) => a.label === 'Changed in shadow'));
    });

    it('follow typing into a control embedded in the name', async () => {
        await page.run('document.body.insertAdjacentHTML("beforeend", "<button id=give>Give <input id=amount value=25> pounds</button>")');
        await page.read();
        await page.run('const amount = document.getElementById("amount"); amount.value = "40"; amount.dispatchEvent(new Event("input", {bubbles: true}))');
        const observation = await page.read();

        assert.ok(observation.actions.some((a) => a.label === 'Give 40 pounds'));
    });

    it('follow a change made in the same task as the read', async () => {
        const observation = await page.run('document.getElementById("target").textContent = "Same task"; 1');
        const renamed = await page.reader('document.getElementById("target").textContent = "Same task again", pageReader.read({max_text: 6000, max_actions: 250, scroll_step: 560, max_options: 25, max_label: 200}).actions[0].label');

        assert.equal(observation, 1);
        assert.equal(renamed, 'Same task again');
    });
});

describe('guard', () => {
    it('matches the observation until something changes', async () => {
        const observation = await page.read();

        assert.equal(await guard(target), observation.guards[target]);
        assert.equal(await guard(field), observation.guards[field]);
    });

    const changes = {
        name: ['target', 'document.getElementById("target").textContent = "Renamed"'],
        role: ['target', 'document.getElementById("target").setAttribute("role", "link")'],
        value: ['field', 'document.getElementById("field").value = "changed"'],
        state: ['target', 'document.getElementById("target").setAttribute("aria-expanded", "true")'],
        identity: ['target', 'document.getElementById("target").replaceWith(Object.assign(document.createElement("button"), {id: "target", textContent: "Target"}))'],
    };
    for (const [change, [which, script]] of Object.entries(changes)) {
        it(`changes when the control's ${change} changes`, async () => {
            const node = which === 'target' ? target : field;
            const before = await guard(node);
            await page.run(script);

            assert.notEqual(await guard(node), before);
        });
    }

    const unusable = {
        disabled: 'document.getElementById("target").disabled = true',
        hidden: 'document.getElementById("target").style.display = "none"',
        'scrolled away': 'scrollTo(0, 2000)',
    };
    for (const [change, script] of Object.entries(unusable)) {
        it(`is null when the control is ${change}`, async () => {
            await page.run(script);

            assert.equal(await guard(target), null);
        });
    }

    const unrelated = {
        'unrelated text': 'document.getElementById("elsewhere").textContent = "Changed elsewhere"',
        'another control': 'document.getElementById("other").textContent = "Other renamed"',
        'a small scroll': 'scrollTo(0, 10)',
    };
    for (const [change, script] of Object.entries(unrelated)) {
        it(`holds when ${change} changes`, async () => {
            const before = await guard(target);
            await page.run(script);

            assert.equal(await guard(target), before);
        });
    }
});

describe('page key', () => {
    it('matches the observation until something changes', async () => {
        const observation = await page.read();

        assert.equal(await pageKey(), observation.page_key);
    });

    const changes = {
        'scroll position': 'scrollTo(0, 100)',
        'form value': 'document.getElementById("field").value = "typed"',
        url: 'history.pushState({}, "", "/state.html?step=2")',
    };
    for (const [change, script] of Object.entries(changes)) {
        it(`changes with the ${change}`, async () => {
            const before = await pageKey();
            await page.run(script);

            assert.notEqual(await pageKey(), before);
        });
    }

    it('changes with the viewport', async () => {
        const before = await pageKey();
        await page.send('Emulation.setDeviceMetricsOverride', { width: 1000, height: 700, deviceScaleFactor: 1, mobile: false });
        const resized = await pageKey();
        await page.send('Emulation.setDeviceMetricsOverride', { width: 1120, height: 780, deviceScaleFactor: 1, mobile: false });

        assert.notEqual(resized, before);
    });

    const unrelated = {
        'page text': 'document.getElementById("elsewhere").textContent = "Changed elsewhere"',
        'a control label': 'document.getElementById("target").textContent = "Renamed"',
        'a private value': 'document.getElementById("secret").value = "hunter2"',
    };
    for (const [change, script] of Object.entries(unrelated)) {
        it(`holds when ${change} changes`, async () => {
            const before = await pageKey();
            await page.run(script);

            assert.equal(await pageKey(), before);
        });
    }
});

describe('context label', () => {
    const guards = async () => {
        const observation = await page.read();

        return observation.actions.filter((a) => a.node !== undefined).map((a) => [a.label, observation.guards[a.node]]);
    };

    it('keeps a control beside a ticking clock in the same container fresh', async () => {
        await page.goto(`${browser.base}/context.html`);
        const refresh = (await page.read()).actions.find((a) => a.label === 'Refresh').node;
        const before = await guard(refresh);
        await new Promise((done) => setTimeout(done, 100));

        assert.equal(await guard(refresh), before);
    });

    it('makes a row control stale when the rows are re-rendered in another order', async () => {
        await page.goto(`${browser.base}/context.html`);
        const [first] = (await page.read()).actions.filter((a) => a.label === 'Delete');
        const before = await guard(first.node);
        await page.run('const [a, b] = document.querySelectorAll("th"); [a.textContent, b.textContent] = [b.textContent, a.textContent]');

        assert.notEqual(await guard(first.node), before);
    });

    it('follows a list item text run and a form name, and ignores text elsewhere in the container', async () => {
        await page.goto(`${browser.base}/context.html`);
        const before = await guards();
        await page.run('document.querySelector("form").insertAdjacentHTML("beforeend", "<p>Thanks for joining</p>")');
        const unchanged = await guards();
        await page.run('document.querySelector("li").firstChild.data = "Beta item "; document.querySelector("form").setAttribute("aria-label", "Offers")');
        const after = Object.fromEntries(await guards());

        assert.deepEqual(unchanged, before);
        assert.notEqual(after.Open, Object.fromEntries(before).Open);
        assert.notEqual(after.Join, Object.fromEntries(before).Join);
    });
});
