import { isSettable } from './actions.js';
import { locateNow } from './controls.js';
import { collapse, containsComposed, frameDocument } from './dom.js';
import { frameOf } from './geometry.js';
import { resolve } from './handles.js';

// Null when the element at a top-viewport point is the control, its label or its stand-in, or
// inside one of them; otherwise a short description of what is in the way.
export function blocker(node, x, y) {
    const el = resolve(node);
    const located = el ? locateNow(el) : null;
    if (!located) {
        return el ? 'the control is no longer usable' : 'the control left the document';
    }
    const hit = elementAt(x, y);
    const targets = [el, located.target, ...(el.labels ?? [])];

    return hit && targets.some((target) => containsComposed(target, hit)) ? null : describeElement(hit);
}

// Sets a select's or value input's value as a user's choice would, fires input and change, and
// returns the value the element holds afterwards. The browser clears a malformed value and clamps
// an out-of-range one, so the caller compares what it asked for with what it got. Null when the
// element has left the document.
export function setValue(node, value) {
    const el = resolve(node);
    if (!el) {
        return null;
    }
    if (el.localName !== 'select' && !(el.localName === 'input' && isSettable(el))) {
        throw new TypeError('setValue takes a select or a value input');
    }
    if (el.localName === 'select') {
        chooseOption(el, value);
    } else {
        el.value = value;
    }
    el.dispatchEvent(new Event('input', { bubbles: true, composed: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));

    return el.localName === 'select' ? heldOption(el, value) : el.value;
}

function chooseOption(select, value) {
    const option = [...select.options].find((o) => o.value === value && !o.disabled);
    if (option) {
        option.selected = true;
    }
}

// A multiple select holds several values; the one asked for is held when it is selected.
function heldOption(select, value) {
    return [...select.selectedOptions].some((o) => o.value === value) ? value : select.value;
}

const DESCRIPTION_TEXT = 40;

function describeElement(el) {
    if (!el) {
        return 'nothing is at the click point';
    }
    const id = el.id ? `#${el.id}` : '';
    const classes = typeof el.className === 'string' && el.className.trim() !== ''
        ? `.${el.className.trim().split(/\s+/).slice(0, 2).join('.')}` : '';
    const text = collapse(el.textContent ?? '').slice(0, DESCRIPTION_TEXT);

    return `<${el.localName}${id}${classes}>${text ? ` "${text}"` : ''}`;
}

// Hit-testing descends into open shadow roots and same-origin frames, which the document's own
// elementFromPoint reports as their host.
function elementAt(x, y) {
    let hit = document.elementFromPoint(x, y);
    for (let depth = 0; hit && depth < 64; depth++) {
        const inner = innerHit(hit, x, y);
        if (!inner || inner === hit) {
            break;
        }
        hit = inner;
    }

    return hit;
}

function innerHit(el, x, y) {
    if (el.shadowRoot) {
        return el.shadowRoot.elementFromPoint(x - frameOf(el.ownerDocument).x, y - frameOf(el.ownerDocument).y);
    }
    const doc = el.localName === 'iframe' || el.localName === 'frame' ? frameDocument(el) : null;
    if (!doc) {
        return null;
    }
    const frame = frameOf(doc);

    return doc.elementFromPoint(x - frame.x, y - frame.y);
}
