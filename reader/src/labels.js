import { isControl } from './controls.js';
import { collapse, composedChildren, parentAcrossShadow } from './dom.js';
import { accessibleName } from './names.js';

// Controls a user activates on their own. A field inside a name is left to accname, which takes
// its value into the name ("Give 25 pounds").
const ACTIVATABLE = 'a[href], area[href], button, summary, [role~="button"], [role~="link"], [role~="menuitem"], '
    + '[role~="menuitemcheckbox"], [role~="menuitemradio"], [role~="tab"], [role~="checkbox"], [role~="radio"], '
    + '[role~="switch"], [role~="option"]';

// Icon classes name the glyph after a set prefix (lucide-trash-2, fa-pencil). These suffixes are
// sizes, weights and animations, not glyphs.
const ICON_CLASS = /^(?:lucide|fa|bi|icon|mdi|heroicon)-(.+)$/;
const NOT_A_GLYPH = /^(?:\d*x|xs|sm|lg|xl|fw|spin|pulse|solid|regular|light|duotone|outline|thin|brands|sharp)$/;

// A control's name as the contract's Labels section defines it: the accessible name, except that a
// control holding activatable controls is named by its own content, and an unnamed one by its icon.
export function labelOf(el) {
    const name = holdsControls(el) && !namedExplicitly(el) ? ownContent(el) : accessibleName(el);

    return name || iconName(el);
}

// The label of the control an element sits inside, for telling apart controls that share a label.
export function containingLabel(el) {
    for (let node = parentAcrossShadow(el); node; node = parentAcrossShadow(node)) {
        if (isControl(node)) {
            return labelOf(node);
        }
    }

    return '';
}

function holdsControls(el) {
    return el.firstElementChild !== null && el.querySelector(ACTIVATABLE) !== null;
}

function namedExplicitly(el) {
    return el.hasAttribute('aria-labelledby') || el.hasAttribute('aria-label') || (el.labels?.length ?? 0) > 0;
}

// The visible text inside the element, leaving out the activatable controls it contains, their
// content, and anything hidden from assistive technology.
function ownContent(el) {
    const parts = [];
    const visit = (node) => {
        for (const child of composedChildren(node)) {
            if (child.nodeType === Node.TEXT_NODE) {
                parts.push(child.data);
            } else if (child.nodeType === Node.ELEMENT_NODE && shownText(child)) {
                visit(child);
            }
        }
    };
    visit(el);

    return collapse(parts.join(' '));
}

function shownText(el) {
    return !el.matches(ACTIVATABLE) && el.getAttribute('aria-hidden') !== 'true'
        && el.checkVisibility({ visibilityProperty: true }) && !['script', 'style', 'template'].includes(el.localName);
}

function iconName(el) {
    for (const icon of el.querySelectorAll('svg, i, span[class*="icon"]')) {
        const glyph = titled(icon) || referenced(icon) || classGlyph(icon);
        if (glyph) {
            return glyph;
        }
    }

    return '';
}

function titled(icon) {
    return collapse(icon.querySelector('title')?.textContent ?? icon.getAttribute('aria-label') ?? '');
}

function referenced(icon) {
    const href = icon.querySelector('use')?.getAttribute('href') ?? icon.querySelector('use')?.getAttribute('xlink:href') ?? '';
    const fragment = href.includes('#') ? href.split('#').pop() : '';

    return fragment ? glyphName(fragment) : '';
}

function classGlyph(icon) {
    const classes = (icon.getAttribute('class') ?? '').split(/\s+/);
    const glyphs = classes.map((name) => ICON_CLASS.exec(name)?.[1]).filter((glyph) => glyph && !NOT_A_GLYPH.test(glyph));

    return glyphs.length > 0 ? glyphName(glyphs[glyphs.length - 1]) : '';
}

function glyphName(raw) {
    const words = raw.replace(/^icon[-_]/, '').replace(/[-_]+/g, ' ').replace(/\s*\d+$/, '').trim();

    return words ? `${words} icon` : '';
}
