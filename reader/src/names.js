import { computeAccessibleName } from 'dom-accessibility-api';
import { collapse } from './dom.js';

// Accessible names are kept per element until the page changes. A name depends on the DOM, on the
// values of embedded controls and on styles; any mutation in an observed root, and any input or
// change event, drops every cached name. A style change with no DOM mutation behind it (a media
// query, :hover) keeps stale names.
let names = new WeakMap();
const observedRoots = new WeakSet();
const observer = new MutationObserver(forget);
const MUTATIONS = { subtree: true, childList: true, attributes: true, characterData: true };

export function watch(root) {
    if (observedRoots.has(root)) {
        return;
    }
    observedRoots.add(root);
    observer.observe(root, MUTATIONS);
    if (root.nodeType === Node.DOCUMENT_NODE) {
        root.addEventListener('input', forget, true);
        root.addEventListener('change', forget, true);
    }
}

// Mutation callbacks run as microtasks; records queued at the start of a read are taken
// here so the read never sees a name from before them.
export function settle() {
    if (observer.takeRecords().length > 0) {
        forget();
    }
}

export function accessibleName(el) {
    let name = names.get(el);
    if (name === undefined) {
        name = compute(el);
        names.set(el, name);
    }

    return name;
}

function forget() {
    names = new WeakMap();
}

// HTML-AAM names a text field by its placeholder when nothing else names it; the accname library
// leaves the name empty.
function compute(el) {
    const name = collapse(computeAccessibleName(el, { getComputedStyle: styleWithPseudo }));

    return name || collapse(el.getAttribute('placeholder') ?? el.getAttribute('aria-placeholder') ?? '');
}

function styleWithPseudo(el, pseudo) {
    return el.ownerDocument.defaultView.getComputedStyle(el, pseudo);
}
