import { collapse } from './dom.js';
import { hasArea, overlaps, toTop } from './geometry.js';

const HEADING_TAG = /^h([1-6])$/;

// A heading's level: h1-h6 by tag, role="heading" by aria-level, level 2 when that is unset.
export function headingLevel(el) {
    const tag = HEADING_TAG.exec(el.localName);
    if ((el.getAttribute('role') ?? '').split(/\s+/).includes('heading')) {
        const level = parseInt(el.getAttribute('aria-level') ?? '', 10);

        return Number.isInteger(level) && level > 0 ? level : 2;
    }

    return tag ? Number(tag[1]) : null;
}

export function headingShows(el, frame, clip) {
    const box = el.getBoundingClientRect();

    return el.checkVisibility({ opacityProperty: true, visibilityProperty: true })
        && hasArea(box)
        && overlaps(toTop(box, frame), clip);
}

export function headingEntry(el, level, cap) {
    return { level, text: collapse(el.textContent ?? '').slice(0, cap) };
}
