import { actionsFor, cut, describe } from './actions.js';
import { isControl, isStyledInput, locate, modalOf } from './controls.js';
import { composedChildren, frameDocument, styleOf } from './dom.js';
import { clipsBelow, frameClips, frameOf, hasArea, overlaps, rounded, toTop } from './geometry.js';
import { handleOf } from './handles.js';
import { settle, watch } from './names.js';
import { fitDocument } from './budget.js';
import { isScreenReaderOnly } from './hidden.js';
import { headingEntry, headingLevel, headingShows } from './outline.js';
import { guardOf, pageKeyOf } from './keys.js';

// Elements whose children are never rendered as page text.
const OPAQUE = new Set(['head', 'script', 'style', 'noscript', 'template', 'select', 'textarea', 'datalist']);

export function read(options) {
    if (!document.body) {
        return null;
    }
    settle();
    const whole = options.mode === 'document';
    const scan = new Scan(whole ? Infinity : options.max_text, options.max_label, whole);
    watch(document);
    scan.walk(document.documentElement, document, frameClips(frameOf(document, scan.frames, whole)));
    const text = scan.finishText();
    const outline = scan.outline.slice(0, options.max_outline);
    const { actions, omitted, guards } = buildActions(scan.controls, options, whole);
    const common = {
        url: location.href,
        title: document.title,
        viewport: { w: innerWidth, h: innerHeight },
        scroll: scrollState(),
        mode: whole ? 'document' : 'viewport',
    };
    if (whole) {
        const fitted = fitDocument(common, outline, actions.map(describing), text, options.max_document);

        return { ...common, outline: fitted.outline, text: fitted.text, actions: fitted.actions, omitted_actions: omitted, truncated: fitted.truncated };
    }

    return {
        ...common,
        outline,
        text,
        actions: [...numbered(actions), ...pageActions(options.scroll_step)],
        omitted_actions: omitted,
        page_key: pageKeyOf(),
        guards,
    };
}

// A document-mode action describes what the page offers and nothing needed to act: no id, rect or
// form, and no empty value or false submits. The node stays so a caller query can reach it.
const DESCRIBING = ['node', 'kind', 'role', 'label', 'value', 'current_value', 'omitted_options', 'href', 'required', 'format', 'min', 'max', 'step', 'checked', 'selected', 'expanded', 'submits'];

function describing(action) {
    const kept = {};
    for (const field of DESCRIBING) {
        const value = action[field];
        if (value !== undefined && value !== '' && value !== false) {
            kept[field] = value;
        }
    }

    return kept;
}

function numbered(actions) {
    return actions.map((action, i) => ({ id: `e${i + 1}`, ...action }));
}

// Document mode keeps no guards.
function buildActions(controls, options, whole) {
    const frames = new Map();
    const modals = new Map();
    const actions = [];
    const guards = {};
    let kept = 0;
    let omitted = 0;
    for (const el of controls) {
        const doc = el.ownerDocument;
        if (!modals.has(doc)) {
            modals.set(doc, modalOf(doc));
        }
        const located = locate(el, frameOf(doc, frames, whole), modals.get(doc));
        if (!located) {
            continue;
        }
        if (kept === options.max_actions) {
            omitted++;
            continue;
        }
        kept++;
        const node = handleOf(el);
        const info = describe(el, options.max_label);
        actions.push(...actionsFor(el, info, rounded(located.rect), node, options));
        if (!whole) {
            guards[node] = guardOf(el, node, info, located.target);
        }
    }

    return { actions, omitted, guards };
}

function scrollState() {
    const root = document.scrollingElement ?? document.documentElement;

    return { y: Math.round(scrollY), height: root.scrollHeight, view: innerHeight };
}

function pageActions(step) {
    const { y, height, view } = scrollState();
    const actions = [];
    if (y + view < height - 1) {
        actions.push({ id: 'scroll_down', kind: 'scroll', label: 'Scroll down', delta: step });
    }
    if (y > 0) {
        actions.push({ id: 'scroll_up', kind: 'scroll', label: 'Scroll up', delta: -step });
    }
    actions.push({ id: 'wait', kind: 'wait', label: 'Wait for the page to update' });

    return actions;
}

// One pass over the flat tree in document order, gathering visible text lines, headings and
// candidate controls. Controls are checked for usability afterwards, in the same synchronous call.
class Scan {
    constructor(maxText, maxLabel, whole) {
        this.maxText = maxText;
        this.maxLabel = maxLabel;
        this.whole = whole;
        this.outline = [];
        this.lines = [];
        this.line = '';
        this.length = 0;
        this.controls = [];
        this.frames = new Map();
        this.ranges = new Map();
        this.visibleParents = new Map();
    }

    walk(node, doc, clips) {
        if (node.shadowRoot) {
            watch(node.shadowRoot);
        }
        for (const child of composedChildren(node)) {
            if (child.nodeType === Node.TEXT_NODE) {
                this.addText(child, doc, clips.flow);
            } else if (child.nodeType === Node.ELEMENT_NODE) {
                this.visit(child, doc, clips);
            }
        }
    }

    visit(el, doc, clips) {
        if (OPAQUE.has(el.localName) && isControl(el)) {
            this.controls.push(el);
        }
        if (OPAQUE.has(el.localName)) {
            return;
        }
        const style = styleOf(el);
        const display = style.display;
        if (display === 'none') {
            return;
        }
        if (isScreenReaderOnly(el, style)) {
            // Its text is not on screen, but a styled input inside may be drawn through its label.
            this.controls.push(...[el, ...el.querySelectorAll('input')].filter(isStyledInput));
            this.addHiddenHeadings(el);

            return;
        }
        const block = !display.startsWith('inline') && display !== 'contents';
        // inline-block, inline-flex and the like sit on a line as separate runs.
        const atomic = display.startsWith('inline-') ? ' ' : '';
        this.breakLineIf(block || el.localName === 'br');
        if (isControl(el)) {
            this.controls.push(el);
        }
        this.addHeading(el, doc, clips.flow);
        this.line += atomic;
        this.descend(el, doc, clipsBelow(el, style, frameOf(doc, this.frames, this.whole), clips));
        this.line += atomic;
        this.breakLineIf(block);
    }

    descend(el, doc, clips) {
        const inner = el.localName === 'iframe' || el.localName === 'frame' ? frameDocument(el) : null;
        if (inner) {
            watch(inner);
            this.walk(inner.documentElement, inner, frameClips(frameOf(inner, this.frames, this.whole)));
        } else if (styleOf(el).contentVisibility !== 'hidden') {
            this.walk(el, doc, clips);
        }
    }

    addText(node, doc, clip) {
        if (this.length >= this.maxText) {
            return;
        }
        if (!/\S/.test(node.data)) {
            this.line += ' ';

            return;
        }
        if (this.textShows(node, doc, clip)) {
            this.line += node.data;
        }
    }

    textShows(node, doc, clip) {
        const parent = node.parentElement ?? node.parentNode?.host;
        if (!parent || !this.parentShows(parent)) {
            return false;
        }
        if (!this.ranges.has(doc)) {
            this.ranges.set(doc, doc.createRange());
        }
        const range = this.ranges.get(doc);
        range.selectNodeContents(node);
        const box = range.getBoundingClientRect();

        return hasArea(box) && overlaps(toTop(box, frameOf(doc, this.frames, this.whole)), clip);
    }

    // Sites label sections with headings hidden for screen readers ("Navigation menu"); they carry
    // the page's structure, so document mode keeps them in the outline, and only there.
    addHiddenHeadings(el) {
        if (!this.whole) {
            return;
        }
        for (const heading of [el, ...el.querySelectorAll('h1, h2, h3, h4, h5, h6, [role~="heading"]')]) {
            const level = headingLevel(heading);
            if (level !== null && heading.checkVisibility({ visibilityProperty: true })) {
                this.outline.push(headingEntry(heading, level, this.maxLabel));
            }
        }
    }

    addHeading(el, doc, clip) {
        const level = headingLevel(el);
        if (level !== null && headingShows(el, frameOf(doc, this.frames, this.whole), clip)) {
            this.outline.push(headingEntry(el, level, this.maxLabel));
        }
    }

    parentShows(el) {
        if (!this.visibleParents.has(el)) {
            this.visibleParents.set(el, el.checkVisibility({ opacityProperty: true, visibilityProperty: true }));
        }

        return this.visibleParents.get(el);
    }

    breakLineIf(condition) {
        if (!condition) {
            return;
        }
        const line = this.line.replace(/\s+/g, ' ').trim();
        this.line = '';
        if (line !== '' && this.length < this.maxText) {
            this.lines.push(line);
            this.length += line.length + 1;
        }
    }

    finishText() {
        this.breakLineIf(true);

        return cut(this.lines.join('\n'), this.maxText);
    }
}
