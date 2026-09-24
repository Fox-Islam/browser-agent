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

async function observe(fixture, options = {}) {
    await page.goto(`${browser.base}/${fixture}`);

    return page.read(options);
}

function labels(observation, kind = null) {
    return observation.actions.filter((a) => kind === null || a.kind === kind).map((a) => a.label);
}

function byLabel(observation, label) {
    return observation.actions.find((a) => a.label === label);
}

describe('observation', () => {
    it('reports the document, viewport and scroll position', async () => {
        const observation = await observe('long.html');

        assert.equal(observation.url, `${browser.base}/long.html`);
        assert.equal(observation.title, 'Long');
        assert.deepEqual(observation.viewport, { w: 1120, h: 780 });
        const height = await page.run('document.documentElement.scrollHeight');

        assert.deepEqual(observation.scroll, { y: 0, height, view: 780 });
        assert.ok(height > 2100);
    });

    it('returns null while the document has no body', async () => {
        await page.goto(`${browser.base}/names.html`);
        await page.run('document.body.remove()');

        assert.equal(await page.reader('pageReader.read({max_text: 100, max_actions: 10, scroll_step: 100})'), null);
    });

    it('reads visible on-screen text one run per line, without off-screen or hidden text', async () => {
        const observation = await observe('exclusions.html');

        assert.match(observation.text, /^Enabled\nNatively disabled\n/);
        assert.doesNotMatch(observation.text, /Display none|Visibility hidden|Content visibility|Below the fold|Left of the viewport|Zero size|Clipped by overflow/);
    });

    it('keeps inline elements on one line and separates inline blocks', async () => {
        const names = await observe('names.html');
        const many = await observe('many.html');

        assert.match(names.text, /^Plain bold text$/m);
        assert.match(many.text, /^Button 1 Button 2 Button 3 /m);
    });

    it('cuts text at max_text', async () => {
        const observation = await observe('many.html', { max_text: 20 });

        assert.equal(observation.text, 'A paragraph of words');
    });
});

describe('accessible names', () => {
    it('takes names from every accname source', async () => {
        const observation = await observe('names.html');

        assert.deepEqual(labels(observation, 'click').filter((l) => !l.startsWith('Open ')), [
            'Labelled by span', 'Aria label', 'Link contents', 'Title only', 'Alt text', 'button',
        ]);
        assert.deepEqual(labels(observation, 'fill'), ['Email address', 'Wrapped label', 'Placeholder only']);
    });

    it('uses the role as the label when the name is empty', async () => {
        const observation = await observe('names.html');

        assert.equal(observation.actions.at(-2).label, 'button');
        assert.equal(observation.actions.at(-2).role, 'button');
    });

    it('uses the first line box of a link that wraps', async () => {
        const observation = await observe('names.html');
        const link = byLabel(observation, 'Link contents');
        const hit = await page.run(`document.elementFromPoint(${link.rect.x + link.rect.w / 2}, ${link.rect.y + link.rect.h / 2}).closest('a')?.textContent`);

        assert.equal(hit, 'Link contents');
    });
});

describe('exclusions', () => {
    it('clips a control by the overflow of ancestors at or above its containing block only', async () => {
        const observation = await observe('clipping.html');

        assert.deepEqual(labels(observation, 'click'), ['Escaping menu item', 'Escaping fixed']);
        assert.match(observation.text, /^Escaping menu item\nEscaping fixed$/);
    });

    it('keeps only enabled, rendered, on-screen controls', async () => {
        const observation = await observe('exclusions.html');

        assert.deepEqual(labels(observation), [
            'Enabled', 'Plain cell', 'Cell button', 'Div button', 'Scroll down', 'Wait for the page to update',
        ]);
    });

    it('leaves a gridcell that contains a button to the button', async () => {
        const observation = await observe('exclusions.html');

        assert.deepEqual(observation.actions.filter((a) => a.role === 'gridcell').map((a) => a.label), ['Plain cell']);
    });
});

describe('transparent native inputs', () => {
    it('includes a transparent input behind a visible label, with the label rect', async () => {
        const observation = await observe('transparent.html');
        const wrapped = await page.run('JSON.stringify(document.getElementById("wrap").getBoundingClientRect())');
        const forLabel = await page.run('JSON.stringify(document.getElementById("for").getBoundingClientRect())');

        assert.deepEqual(byLabel(observation, 'Accept terms').rect, rectOf(wrapped));
        assert.deepEqual(byLabel(observation, 'Monthly plan').rect, rectOf(forLabel));
    });

    it('includes a transparent input under a visible overlay, with the overlay rect', async () => {
        const observation = await observe('transparent.html');
        const knob = await page.run('JSON.stringify(document.getElementById("knob").getBoundingClientRect())');

        assert.deepEqual(byLabel(observation, 'Dark mode').rect, rectOf(knob));
    });

    it('excludes a transparent control with nothing visible standing in for it', async () => {
        const observation = await observe('transparent.html');

        assert.equal(byLabel(observation, 'Invisible'), undefined);
    });
});

describe('visually hidden styled inputs', () => {
    it('includes a hidden checkbox, radio or wrapped input with a visible label, with the label rect', async () => {
        const observation = await observe('hidden-inputs.html');
        const rectOfLabel = async (id) => rectOf(await page.run(`JSON.stringify(document.getElementById("${id}").getBoundingClientRect())`));

        assert.deepEqual(byLabel(observation, 'Accept terms').rect, await rectOfLabel('terms'));
        assert.deepEqual(byLabel(observation, 'Yearly plan').rect, await rectOfLabel('plan'));
        assert.deepEqual(byLabel(observation, 'Newsletter').rect, await rectOfLabel('newslabel'));
        assert.equal(byLabel(observation, 'Accept terms').checked, 'false');
    });

    it('excludes hidden controls with nothing visible standing in for them', async () => {
        const observation = await observe('hidden-inputs.html');

        assert.equal(byLabel(observation, 'Unlabelled'), undefined);
        assert.equal(byLabel(observation, 'Hidden button'), undefined);
    });
});

describe('private fields', () => {
    it('never returns the value of password, file, hidden or cc- fields', async () => {
        const observation = await observe('private.html');
        const serialised = JSON.stringify(observation);

        assert.equal(byLabel(observation, 'Username').value, 'fox');
        for (const label of ['Password', 'Upload', 'Card number', 'Billing card']) {
            assert.equal(byLabel(observation, label).value, '', label);
        }
        assert.equal(byLabel(observation, 'Expiry month → 01').current_value, '');
        for (const secret of ['hunter2', 'secret-token', '4111111111111111', '12/30']) {
            assert.equal(serialised.includes(secret), false, secret);
        }
    });
});

describe('private values in names', () => {
    it('drops a private value from the accessible name of the control around or referencing it', async () => {
        const observation = await observe('private-names.html');
        const serialised = JSON.stringify([observation.text, observation.actions]);

        assert.equal(byLabel(observation, 'Pay with now').role, 'button');
        assert.equal(byLabel(observation, 'Card').role, 'button');
        assert.equal(byLabel(observation, 'PIN required').kind, 'fill');
        assert.equal(byLabel(observation, 'Remember on this device').role, 'checkbox');
        assert.equal(byLabel(observation, 'Unlock').role, 'button');
        for (const secret of ['hunter2', 'hunter3', 'hunter4', '4111111111111111', '987', '07']) {
            assert.equal(serialised.includes(secret), false, secret);
        }
    });

    it('keeps a non-private embedded value in the name', async () => {
        await page.goto(`${browser.base}/private-names.html`);
        await page.run('document.body.insertAdjacentHTML("beforeend", "<button>Give <input value=25> pounds</button>")');
        const observation = await page.read();

        assert.equal(byLabel(observation, 'Give 25 pounds').role, 'button');
    });
});

describe('native select', () => {
    it('yields one select action per enabled, unselected option', async () => {
        const observation = await observe('select.html');

        assert.deepEqual(
            observation.actions.filter((a) => a.kind === 'select').map((a) => [a.label, a.value, a.current_value]),
            [
                ['Size → Small', 's', 'Medium'],
                ['Size → Extra small', 'xs', 'Medium'],
                ['Toppings → Cheese', 'c', 'Anchovies, Basil'],
            ],
        );
    });
});

describe('submits', () => {
    it('marks the controls whose click submits a form', async () => {
        const observation = await observe('submits.html');
        const submits = Object.fromEntries(observation.actions.filter((a) => a.node !== undefined).map((a) => [a.label, a.submits]));

        assert.deepEqual(submits, {
            Typeless: true,
            'Submit type': true,
            'Submit input': true,
            'Image input': true,
            'Plain button': false,
            Reset: false,
            Field: false,
            'Open Field': false,
            'Outside with form': true,
            'Outside without form': false,
            'A link': false,
        });
    });
});

describe('form owners', () => {
    it('gives controls with a form owner that form node, including a button naming it through form', async () => {
        const observation = await observe('submits.html');
        const form = Object.fromEntries(observation.actions.filter((a) => a.node !== undefined).map((a) => [a.label, a.form]));

        assert.equal(typeof form.Typeless, 'number');
        assert.equal(form['Outside with form'], form.Typeless);
        assert.equal(form.Field, form.Typeless);
        assert.equal(form['Outside without form'], undefined);
        assert.equal(form['A link'], undefined);
    });
});

describe('long selects', () => {
    it('offers at most max_options options and reports how many it left out', async () => {
        const observation = await observe('many-options.html');
        const options = observation.actions.filter((a) => a.kind === 'select');

        assert.equal(options.length, 25);
        assert.deepEqual([options[0].label, options.at(-1).label], ['Country → Country 1', 'Country → Country 25']);
        assert.ok(options.every((a) => a.omitted_options === 14));
        assert.equal(byLabel(observation, 'After the select').kind, 'click');
    });

    it('reports zero omitted options when every option fits', async () => {
        const observation = await observe('select.html');

        assert.deepEqual(observation.actions.filter((a) => a.kind === 'select').map((a) => a.omitted_options), [0, 0, 0]);
    });
});

describe('kinds', () => {
    it('gives editable text controls a fill and an Open click', async () => {
        const observation = await observe('editable.html');

        assert.deepEqual(
            observation.actions.filter((a) => a.kind !== 'wait').map((a) => `${a.kind} ${a.role} ${a.label}`),
            [
                'fill textbox Message body',
                'click textbox Open Message body',
                'click combobox Picker',
                'fill combobox City',
                'click combobox Open City',
                'fill combobox Country',
                'click combobox Open Country',
                'fill textbox Notes',
                'click textbox Open Notes',
                'click textbox Read-only notes',
                'fill searchbox Search site',
                'click searchbox Open Search site',
                'fill spinbutton Quantity',
                'click spinbutton Open Quantity',
                'click slider Volume',
                'click checkbox Mixed',
                'click switch Wifi',
                'click tab Overview',
                'click button More',
            ],
        );
    });

    it('gives value inputs a fill in their own value format and an Open click', async () => {
        const observation = await observe('settable.html');
        const fills = observation.actions.filter((a) => a.kind === 'fill');

        assert.deepEqual(
            fills.map((a) => [a.label, a.value, a.format, a.min, a.max, a.step]),
            [
                ['Date', '2026-09-24', 'YYYY-MM-DD', '2026-01-01', '2026-12-31', undefined],
                ['Time', '09:30', 'HH:MM', undefined, undefined, undefined],
                ['Precise time', '', 'HH:MM:SS', undefined, undefined, '1'],
                ['Appointment', '', 'YYYY-MM-DDTHH:MM', undefined, undefined, undefined],
                ['Month', '', 'YYYY-MM', undefined, undefined, undefined],
                ['Week', '', 'YYYY-Www', undefined, undefined, undefined],
                ['Colour', '#ff0000', '#rrggbb', undefined, undefined, undefined],
                ['Volume', '30', 'number', '0', '50', '5'],
                ['Guests', '', undefined, '1', '8', undefined],
                ['Name', '', undefined, undefined, undefined, undefined],
            ],
        );
        assert.ok(observation.actions.filter((a) => a.kind === 'click').every((a) => !('format' in a) && !('min' in a)));
        assert.equal(byLabel(observation, 'Fixed date').kind, 'click');
    });

    it('reports values and exposed states only', async () => {
        const observation = await observe('editable.html');
        const pick = (label) => {
            const { value, checked, selected, expanded } = byLabel(observation, label);

            return JSON.parse(JSON.stringify({ value, checked, selected, expanded }));
        };

        assert.deepEqual(pick('Message body'), { value: 'Hello world' });
        assert.deepEqual(pick('Picker'), { value: '', expanded: 'false' });
        assert.deepEqual(pick('Quantity'), { value: '3' });
        assert.deepEqual(pick('Volume'), { value: '40' });
        assert.deepEqual(pick('Mixed'), { value: 'on', checked: 'mixed' });
        assert.deepEqual(pick('Wifi'), { value: '', checked: 'true' });
        assert.deepEqual(pick('Overview'), { value: '', selected: 'true' });
        assert.deepEqual(pick('More'), { value: '', expanded: 'false' });
    });

    it('numbers actions in order and gives each control one node', async () => {
        const observation = await observe('editable.html');
        const controls = observation.actions.filter((a) => a.node !== undefined);

        assert.deepEqual(controls.map((a) => a.id), controls.map((_, i) => `e${i + 1}`));
        assert.equal(byLabel(observation, 'Notes').node, byLabel(observation, 'Open Notes').node);
    });
});

describe('shadow DOM and frames', () => {
    it('reads open shadow roots in flat-tree order', async () => {
        const observation = await observe('shadow.html');

        assert.equal(observation.text, 'Before host\nSlotted title\nShadow button\nSlotted button\nAfter host');
        assert.deepEqual(labels(observation, 'click'), ['Shadow button', 'Slotted button']);
    });

    it('reads same-origin iframes with rects in the top viewport', async () => {
        const observation = await observe('frame.html');

        assert.match(observation.text, /Inner text/);
        assert.deepEqual(byLabel(observation, 'Inner button').rect, { x: 115, y: 225, w: 100, h: 30 });
    });
});

describe('page entries and limits', () => {
    it('offers scrolling only towards the page ends it has not reached', async () => {
        const top = await observe('long.html');
        await page.run('scrollTo(0, 700)');
        const middle = await page.read();
        await page.run('scrollTo(0, document.documentElement.scrollHeight)');
        const bottom = await page.read();
        const ids = (o) => o.actions.filter((a) => a.node === undefined).map((a) => a.id);

        assert.deepEqual(ids(top), ['scroll_down', 'wait']);
        assert.deepEqual(ids(middle), ['scroll_down', 'scroll_up', 'wait']);
        assert.deepEqual(ids(bottom), ['scroll_up', 'wait']);
        assert.equal(byLabel(middle, 'Scroll down').delta, 560);
        assert.equal(byLabel(middle, 'Scroll up').delta, -560);
    });

    it('caps controls at max_actions and counts the rest', async () => {
        const observation = await observe('many.html', { max_actions: 5 });

        assert.deepEqual(labels(observation), ['Button 1', 'Button 2', 'Button 3', 'Button 4', 'Button 5', 'Wait for the page to update']);
        assert.equal(observation.omitted_actions, 25);
        assert.deepEqual(Object.keys(observation.guards), ['1', '2', '3', '4', '5']);
    });

    it('counts controls, not actions, against max_actions', async () => {
        const observation = await observe('select.html', { max_actions: 1 });

        assert.deepEqual(labels(observation), ['Size → Small', 'Size → Extra small', 'Wait for the page to update']);
        assert.equal(observation.omitted_actions, 1);
    });

    it('caps each label and value at max_label', async () => {
        const observation = await observe('editable.html', { max_label: 8 });

        assert.equal(byLabel(observation, 'Message ').value, 'Hello wo');
        assert.equal(byLabel(observation, 'Open Mes').kind, 'click');
    });

    it('is not operable when nothing can be clicked, filled or selected', async () => {
        await page.goto(`${browser.base}/names.html`);
        await page.run('document.body.innerHTML = "<p>Nothing to do</p>"');
        const observation = await page.read();

        assert.deepEqual(observation.actions.map((a) => a.kind), ['wait']);
    });
});

function rectOf(json) {
    const box = JSON.parse(json);

    return { x: Math.round(box.x), y: Math.round(box.y), w: Math.round(box.width), h: Math.round(box.height) };
}
