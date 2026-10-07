import { describe } from './actions.js';
import { locateArea, modalOf } from './controls.js';
import { collapse, collectRoots } from './dom.js';
import { frameOf, rounded } from './geometry.js';
import { handleOf } from './handles.js';
import { guardOf } from './keys.js';
import { labelOf } from './labels.js';

const SOURCES = '[draggable="true"], [aria-roledescription], [data-rbd-drag-handle-draggable-id], [data-rfd-drag-handle-draggable-id]';

const ZONES = '[aria-dropeffect], [data-droppable], [data-rbd-droppable-id], [data-rfd-droppable-id], [ondrop], '
    + '[role~="list"], [role~="listbox"], [role~="grid"], [role~="region"], [role~="group"], [role~="application"], [role~="main"], '
    + 'main, section, ul, ol';

const HEADING = 'h1, h2, h3, h4, h5, h6, [role~="heading"]';

// Large enough to aim a drop at.
const MIN_ZONE_WIDTH = 80;
const MIN_ZONE_HEIGHT = 60;
const MAX_SOURCES = 40;
const MAX_ZONES = 40;
const DESCRIBED_TEXT = 50;

// Elements a page script handles drops on, found by the PHP side in the page's own world, where
// handler properties are visible, and handed to this world. Pruned as they leave the document.
const handlers = new Set();

export function markDropHandlers(elements) {
    for (const el of elements) {
        handlers.add(el);
    }

    return handlers.size;
}

// Drag and drop actions for the viewport: what can be dragged, and where it can go. Nothing when
// nothing on screen can be dragged.
export function dragActions(options, frames) {
    const modal = modalOf(document);
    const sources = onScreen(collectAll(SOURCES).filter(isSource), frames, modal).slice(0, MAX_SOURCES);
    if (sources.length === 0) {
        return { actions: [], guards: {} };
    }
    const actions = [];
    const guards = {};
    const add = (el, located, kind, label) => {
        const node = handleOf(el);
        actions.push({ node, kind, role: describe(el, options.max_label).role, label: label.slice(0, options.max_label), rect: rounded(located.rect) });
        guards[node] = guardOf(el, node, describe(el, options.max_label), el);
    };
    for (const [el, located] of sources) {
        add(el, located, 'drag', sourceLabel(el));
    }
    const named = new Set();
    for (const [el, located] of zones(sources, frames, modal)) {
        const label = zoneLabel(el, sources);
        if (label !== '' && !named.has(label) && named.size < MAX_ZONES) {
            named.add(label);
            add(el, located, 'drop', label);
        }
    }

    return { actions, guards };
}

// Large on-screen candidates first, then the sources, which are drop targets of any size.
function zones(sources, frames, modal) {
    for (const el of handlers) {
        if (!el.isConnected) {
            handlers.delete(el);
        }
    }
    const sourceElements = new Set(sources.map(([el]) => el));
    const candidates = new Set([...handlers, ...collectAll(ZONES)].filter((el) => !sourceElements.has(el)));
    const big = onScreen([...candidates], frames, modal)
        .filter(([, located]) => located.rect.w >= MIN_ZONE_WIDTH && located.rect.h >= MIN_ZONE_HEIGHT);

    return [...big, ...sources];
}

function collectAll(selector) {
    return collectRoots(document).flatMap((root) => [...root.querySelectorAll(selector)]);
}

function isSource(el) {
    return el.getAttribute('draggable') === 'true'
        || /^(draggable|sortable)$/i.test(el.getAttribute('aria-roledescription') ?? '')
        || el.hasAttribute('data-rbd-drag-handle-draggable-id')
        || el.hasAttribute('data-rfd-drag-handle-draggable-id');
}

function onScreen(elements, frames, modal) {
    const found = [];
    for (const el of elements) {
        const located = locateArea(el, frameOf(el.ownerDocument, frames), modal);
        if (located) {
            found.push([el, located]);
        }
    }

    return found;
}

// accname gives a plain element no name from its content, so its first line of text stands in.
function sourceLabel(el) {
    return labelOf(el) || headingOf(el) || collapse((el.innerText ?? '').split('\n')[0] ?? '') || 'draggable item';
}

function zoneLabel(el, sources) {
    const authored = collapse(el.getAttribute('aria-label') ?? '') || headingOf(el);
    if (authored) {
        return authored;
    }
    if (sources.some(([source]) => source === el)) {
        return sourceLabel(el);
    }

    return handlers.has(el) || el.hasAttribute('ondrop') ? described(el) : '';
}

function headingOf(el) {
    return collapse(el.querySelector(HEADING)?.innerText?.split('\n')[0] ?? '');
}

function described(el) {
    const text = (el.innerText ?? '').split('\n').map(collapse).filter(Boolean).slice(0, 2).join(' ').slice(0, DESCRIBED_TEXT);

    return text ? `drop area containing: ${text}` : 'empty drop area';
}
