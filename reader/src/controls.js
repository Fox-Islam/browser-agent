import { containsComposed, selfAndAncestors, styleOf } from './dom.js';
import { insideScreenReaderOnly } from './hidden.js';
import { boxOf, centreInside, frameOf, hasArea, toTop, visibleArea } from './geometry.js';

// WAI-ARIA 1.2 roles. The first recognised token of a role attribute wins.
const ARIA_ROLES = new Set([
    'alert', 'alertdialog', 'application', 'article', 'banner', 'blockquote', 'button', 'caption',
    'cell', 'checkbox', 'code', 'columnheader', 'combobox', 'complementary', 'contentinfo',
    'definition', 'deletion', 'dialog', 'document', 'emphasis', 'feed', 'figure', 'form', 'generic',
    'grid', 'gridcell', 'group', 'heading', 'img', 'insertion', 'link', 'list', 'listbox',
    'listitem', 'log', 'main', 'marquee', 'math', 'menu', 'menubar', 'menuitem',
    'menuitemcheckbox', 'menuitemradio', 'meter', 'navigation', 'none', 'note', 'option',
    'paragraph', 'presentation', 'progressbar', 'radio', 'radiogroup', 'region', 'row',
    'rowgroup', 'rowheader', 'scrollbar', 'search', 'searchbox', 'separator', 'slider',
    'spinbutton', 'status', 'strong', 'subscript', 'superscript', 'switch', 'tab', 'table',
    'tablist', 'tabpanel', 'term', 'textbox', 'time', 'timer', 'toolbar', 'tooltip', 'tree',
    'treegrid', 'treeitem',
]);

const INTERACTIVE_ROLES = new Set([
    'button', 'link', 'checkbox', 'radio', 'switch', 'tab', 'menuitem', 'menuitemcheckbox',
    'menuitemradio', 'option', 'combobox', 'textbox', 'searchbox', 'spinbutton', 'slider',
    'gridcell',
]);

// HTML-AAM mappings for the input types a control can have.
const INPUT_ROLES = {
    button: 'button', image: 'button', reset: 'button', submit: 'button', file: 'button',
    color: 'button', checkbox: 'checkbox', radio: 'radio', range: 'slider', number: 'spinbutton',
    search: 'searchbox',
};

const LISTABLE_INPUTS = new Set(['text', 'search', 'email', 'tel', 'url']);

export const TYPABLE_INPUTS = new Set(['text', 'search', 'email', 'tel', 'url', 'password', 'number']);

export function isControl(el) {
    const tag = el.localName;
    if ((tag === 'a' || tag === 'area') && el.hasAttribute('href')) {
        return true;
    }
    if (tag === 'button' || tag === 'select' || tag === 'textarea' || tag === 'summary') {
        return true;
    }
    if (tag === 'input') {
        return el.type !== 'hidden';
    }

    return isEditingHost(el) || INTERACTIVE_ROLES.has(explicitRole(el));
}

export function isEditingHost(el) {
    return el.isContentEditable && !el.parentElement?.isContentEditable;
}

export function roleOf(el) {
    const role = explicitRole(el);
    const presentational = role === 'none' || role === 'presentation';

    return role && !presentational ? role : implicitRole(el);
}

// Where a user would click to operate the control: the element they would click, which is the
// control or its stand-in, and its top-viewport rect. Null when the control is not usable.
export function locate(el, frame, modal) {
    const box = el.getBoundingClientRect();
    const available = el.isConnected && !isDisabled(el) && !isHidden(el, modal)
        && el.checkVisibility({ visibilityProperty: true, contentVisibilityAuto: true })
        && hasArea(box) && !coversButton(el);
    const hidden = available && ((box.width <= 1 && box.height <= 1) || insideScreenReaderOnly(el));
    const target = available && (!hidden || isStyledInput(el)) ? clickTarget(el, hidden) : null;
    if (!target) {
        return null;
    }
    const rect = toTop(boxOf(target), frame);

    return centreInside(rect, visibleArea(target, frame)) ? { rect, target } : null;
}

export function locateNow(el) {
    const doc = el.ownerDocument;

    return el.isConnected && isControl(el) ? locate(el, frameOf(doc), modalOf(doc)) : null;
}

export function modalOf(doc) {
    return doc.querySelector(':modal');
}

function explicitRole(el) {
    const tokens = (el.getAttribute('role') ?? '').trim().toLowerCase().split(/\s+/);

    return tokens.find((token) => ARIA_ROLES.has(token)) ?? null;
}

function implicitRole(el) {
    switch (el.localName) {
        case 'a':
        case 'area':
            return el.hasAttribute('href') ? 'link' : 'generic';
        case 'button':
        case 'summary':
            return 'button';
        case 'textarea':
            return 'textbox';
        case 'select':
            return el.multiple || el.size > 1 ? 'listbox' : 'combobox';
        case 'input':
            return inputRole(el);
        default:
            return isEditingHost(el) ? 'textbox' : 'generic';
    }
}

function inputRole(el) {
    if (LISTABLE_INPUTS.has(el.type) && el.hasAttribute('list')) {
        return 'combobox';
    }

    return INPUT_ROLES[el.type] ?? 'textbox';
}

function isDisabled(el) {
    if (el.matches(':disabled')) {
        return true;
    }
    for (const node of selfAndAncestors(el)) {
        if (node.getAttribute('aria-disabled') === 'true') {
            return true;
        }
    }

    return false;
}

function isHidden(el, modal) {
    if (modal && !containsComposed(modal, el)) {
        return true;
    }
    for (const node of selfAndAncestors(el)) {
        if (node.inert || node.getAttribute('aria-hidden') === 'true') {
            return true;
        }
    }

    return false;
}

function coversButton(el) {
    return explicitRole(el) === 'gridcell' && el.querySelector('button, [role~="button"]') !== null;
}

// Checkboxes, radios and file pickers that site builders hide, visually or at opacity 0, and draw
// through their label or an overlay.
export function isStyledInput(el) {
    return el.localName === 'input' && ['checkbox', 'radio', 'file'].includes(el.type);
}

// A transparent or visually hidden control is operated through whatever the user sees in its
// place: its label, or the element painted on top of it. With nothing standing in for it the
// control is invisible.
function clickTarget(el, hidden = false) {
    if (!hidden && el.checkVisibility({ opacityProperty: true })) {
        return el;
    }
    for (const label of el.labels ?? []) {
        if (shows(label)) {
            return label;
        }
    }
    const box = el.getBoundingClientRect();
    const hit = el.getRootNode().elementFromPoint(box.left + box.width / 2, box.top + box.height / 2);
    const onTop = hit && hit !== el && !containsComposed(hit, el) && !containsComposed(el, hit);

    return onTop && shows(hit) ? hit : null;
}

function shows(el) {
    return el.checkVisibility({ opacityProperty: true, visibilityProperty: true })
        && hasArea(el.getBoundingClientRect())
        && styleOf(el).pointerEvents !== 'none';
}
