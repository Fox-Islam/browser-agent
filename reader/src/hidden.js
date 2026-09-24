import { parentAcrossShadow, styleOf } from './dom.js';

// The pattern sites use to keep something for screen readers while hiding it from sight: a box of
// at most 1×1 that clips its overflow, or one clipped to nothing by `clip` or `clip-path`. A skip
// link that becomes visible on focus stops matching once it is focused.
export function isScreenReaderOnly(el, style = styleOf(el)) {
    return clippedToNothing(style) || tinyClippingBox(el, style);
}

export function insideScreenReaderOnly(el) {
    for (let node = el; node; node = parentAcrossShadow(node)) {
        if (isScreenReaderOnly(node)) {
            return true;
        }
    }

    return false;
}

function clippedToNothing(style) {
    const positioned = style.position === 'absolute' || style.position === 'fixed';
    const rect = /^rect\(\s*(-?[\d.]+)px,?\s*(-?[\d.]+)px,?\s*(-?[\d.]+)px,?\s*(-?[\d.]+)px\s*\)$/.exec(style.clip);
    const clipEmpty = positioned && rect !== null && (Number(rect[2]) - Number(rect[4]) <= 0 || Number(rect[3]) - Number(rect[1]) <= 0);
    const inset = /^inset\(\s*([\d.]+)%/.exec(style.clipPath);

    return clipEmpty || (inset !== null && Number(inset[1]) >= 50);
}

function tinyClippingBox(el, style) {
    if (style.overflowX === 'visible' && style.overflowY === 'visible') {
        return false;
    }
    const box = el.getBoundingClientRect();

    return box.width <= 1 && box.height <= 1 && (box.width > 0 || box.height > 0);
}
