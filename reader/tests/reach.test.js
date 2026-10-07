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

async function observe(fixture) {
    await page.goto(`${browser.base}/${fixture}`);

    return page.read();
}

const byLabel = (observation, label, kind = 'click') => observation.actions.find((a) => a.label === label && a.kind === kind);
const centre = (a) => ({ x: a.rect.x + a.rect.w / 2, y: a.rect.y + a.rect.h / 2 });

describe('controls revealed on hover', () => {
    it('includes a transparent button its row reveals, marked hover', async () => {
        const remove = byLabel(await observe('reach.html'), 'Remove file');

        assert.equal(remove.hover, true);
    });

    it('reports it opaque once the pointer is over it, and not before', async () => {
        const remove = byLabel(await observe('reach.html'), 'Remove file');

        assert.equal(await page.reader(`pageReader.opaque(${remove.node}, 100)`), false);
        await page.send('Input.dispatchMouseEvent', { type: 'mouseMoved', ...centre(remove) });
        assert.equal(await page.reader(`pageReader.opaque(${remove.node}, 600)`), true);
    });

    it('still excludes a transparent native checkbox with nothing standing in for it', async () => {
        const observation = await observe('transparent.html');

        assert.equal(observation.actions.some((a) => a.label === 'Invisible'), false);
    });
});

describe('controls out of view in a scrolling panel', () => {
    it('includes one below the panel fold after every on-screen control, marked offscreen', async () => {
        const observation = await observe('reach.html');
        const create = byLabel(observation, 'Create folder');
        const controls = observation.actions.filter((a) => ['click', 'fill', 'select'].includes(a.kind));

        assert.equal(create.offscreen, 'panel');
        assert.equal(controls.at(-1).label, 'Create folder');
    });

    it('reveals it inside the viewport, after which it reads as on screen', async () => {
        const create = byLabel(await observe('reach.html'), 'Create folder');
        const rect = await page.reader(`pageReader.reveal(${create.node})`);

        assert.ok(rect.y > 0 && rect.y + rect.h < 780);
        assert.equal(byLabel(await page.read(), 'Create folder').offscreen, undefined);
    });

    it('leaves out a control below the page fold', async () => {
        assert.equal(byLabel(await observe('reach.html'), 'Far below'), undefined);
    });
});

describe('labels', () => {
    it('names a card that holds buttons by its own content', async () => {
        assert.ok(byLabel(await observe('reach.html'), 'Example project'));
    });

    it('names an icon-only button after its icon', async () => {
        assert.ok(byLabel(await observe('reach.html'), 'trash icon'));
    });

    it('gives controls that share a label the control that holds them as context', async () => {
        const opens = (await observe('reach.html')).actions.filter((a) => a.label === 'Open');

        assert.deepEqual(opens.map((a) => a.context), ['Example project', 'Second project']);
    });

    it('gives a control with a label of its own no context', async () => {
        assert.equal(byLabel(await observe('reach.html'), 'trash icon').context, undefined);
    });
});

describe('busy', () => {
    it('counts on-screen loading indicators and short loading lines', async () => {
        assert.equal((await observe('loading.html')).busy, 2);
    });

    it('is zero on a page showing nothing loading', async () => {
        assert.equal((await observe('names.html')).busy, 0);
    });
});

describe('drag and drop', () => {
    it('offers a draggable element and labelled and attribute-handled drop zones', async () => {
        const observation = await observe('drag.html');

        assert.ok(byLabel(observation, 'Task seven', 'drag'));
        assert.ok(byLabel(observation, 'Done column', 'drop'));
        assert.ok(byLabel(observation, 'empty drop area', 'drop'));
        assert.ok(byLabel(observation, 'Task seven', 'drop'));
    });

    it('offers an unlabelled element marked as handling drops, named by what it holds', async () => {
        await observe('drag.html');
        await page.reader('pageReader.markDropHandlers([document.getElementById("canvas")])');

        assert.ok(byLabel(await page.read(), 'drop area containing: Start here', 'drop'));
    });

    it('guards drag sources and drop zones, and clears their own click point', async () => {
        const observation = await observe('drag.html');
        const zone = byLabel(observation, 'Done column', 'drop');
        const { x, y } = centre(zone);

        assert.notEqual(observation.guards[zone.node], undefined);
        assert.equal(await page.reader(`pageReader.blocker(${zone.node}, ${x}, ${y})`), null);
    });

    it('offers no drop zones when nothing can be dragged', async () => {
        const observation = await observe('names.html');

        assert.equal(observation.actions.some((a) => a.kind === 'drag' || a.kind === 'drop'), false);
    });
});
