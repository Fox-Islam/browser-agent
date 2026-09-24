import { isPrivate } from './private.js';
import { contextLabel } from './context.js';
import { collectRoots } from './dom.js';
import { handleOf } from './handles.js';

// Guards and page keys are compared for equality only, so they travel as a 53-bit hash of what
// they cover instead of the material itself.
// A transparent control's stand-in is part of its identity: a replaced overlay or label means the
// click would land on something else.
export function guardOf(el, node, info, target) {
    return hash(JSON.stringify([
        node,
        target === el ? null : handleOf(target),
        el.localName,
        el.getAttribute('type'),
        info.role,
        info.label,
        info.value,
        info.states,
        el.getAttribute('aria-pressed'),
        el.readOnly ?? null,
        el.getAttribute('aria-readonly'),
        contextLabel(el),
    ]));
}

export function pageKeyOf() {
    return hash(JSON.stringify([
        location.href,
        performance.timeOrigin,
        scrollX,
        scrollY,
        innerWidth,
        innerHeight,
        formValues(),
    ]));
}

function formValues() {
    const values = [];
    for (const root of collectRoots(document)) {
        for (const el of root.querySelectorAll('input, textarea, select, [contenteditable]')) {
            values.push(formValue(el));
        }
    }

    return values;
}

function formValue(el) {
    if (isPrivate(el)) {
        return null;
    }
    if (el.localName === 'select') {
        return [...el.selectedOptions].map((option) => option.value);
    }
    if (el.localName === 'input' || el.localName === 'textarea') {
        return [el.value, el.checked, el.indeterminate];
    }

    return el.isContentEditable ? el.textContent : null;
}

// cyrb53 by bryc, public domain.
function hash(text) {
    let h1 = 0xdeadbeef;
    let h2 = 0x41c6ce57;
    for (let i = 0; i < text.length; i++) {
        const ch = text.charCodeAt(i);
        h1 = Math.imul(h1 ^ ch, 2654435761);
        h2 = Math.imul(h2 ^ ch, 1597334677);
    }
    h1 = Math.imul(h1 ^ (h1 >>> 16), 2246822507) ^ Math.imul(h2 ^ (h2 >>> 13), 3266489909);
    h2 = Math.imul(h2 ^ (h2 >>> 16), 2246822507) ^ Math.imul(h1 ^ (h1 >>> 13), 3266489909);

    return (4294967296 * (2097151 & h2) + (h1 >>> 0)).toString(36);
}
