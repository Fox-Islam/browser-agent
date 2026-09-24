import { parentAcrossShadow, styleOf } from './dom.js';

const EVERYWHERE = { left: -Infinity, top: -Infinity, right: Infinity, bottom: Infinity };

// Where a document's viewport sits in the top viewport, and the part of the top viewport it
// shows. The top document is the one the reader was called in. With `whole`, positions are
// relative to the top document instead and nothing is cut off by the viewport, which is how
// document mode sees the page.
export function frameOf(doc, cache = new Map(), whole = false) {
    if (cache.has(doc)) {
        return cache.get(doc);
    }
    const view = doc.defaultView;
    let frame = whole
        ? { x: scrollX, y: scrollY, clip: EVERYWHERE }
        : { x: 0, y: 0, clip: { left: 0, top: 0, right: view.innerWidth, bottom: view.innerHeight } };
    const host = doc === document ? null : view.frameElement;
    if (host) {
        const parent = frameOf(host.ownerDocument, cache, whole);
        const box = host.getBoundingClientRect();
        const style = styleOf(host);
        const x = parent.x + box.left + host.clientLeft + parseFloat(style.paddingLeft);
        const y = parent.y + box.top + host.clientTop + parseFloat(style.paddingTop);
        const content = { left: x, top: y, right: x + host.clientWidth, bottom: y + host.clientHeight };
        frame = { x, y, clip: intersect(parent.clip, content) };
    }
    cache.set(doc, frame);

    return frame;
}

export function toTop(box, frame) {
    return { x: box.left + frame.x, y: box.top + frame.y, w: box.width, h: box.height };
}

// A link that wraps across lines has one box per line, and the centre of their union can fall on
// other content, so the first line's box stands for the element.
export function boxOf(el) {
    const boxes = [...el.getClientRects()].filter(hasArea);

    return boxes.length > 1 ? boxes[0] : el.getBoundingClientRect();
}

export function hasArea(box) {
    return box.width > 0 && box.height > 0;
}

export function intersect(a, b) {
    return {
        left: Math.max(a.left, b.left),
        top: Math.max(a.top, b.top),
        right: Math.min(a.right, b.right),
        bottom: Math.min(a.bottom, b.bottom),
    };
}

export function centreInside(rect, clip) {
    const cx = rect.x + rect.w / 2;
    const cy = rect.y + rect.h / 2;

    return cx >= clip.left && cx < clip.right && cy >= clip.top && cy < clip.bottom;
}

export function overlaps(rect, clip) {
    return rect.x < clip.right && rect.x + rect.w > clip.left && rect.y < clip.bottom && rect.y + rect.h > clip.top;
}

// The part of the frame an element can paint into: ancestors that clip overflow cut it down. An
// absolutely positioned element escapes the clipping of ancestors below its containing block, and
// a fixed one escapes everything below its containing block, the viewport unless an ancestor has
// a transform, filter or containment.
export function visibleArea(el, frame) {
    let clip = frame.clip;
    let escaping = escapeOf(styleOf(el).position);
    for (let node = parentAcrossShadow(el); node && !isDocumentRoot(node); node = parentAcrossShadow(node)) {
        const style = styleOf(node);
        if (escaping && holds(escaping, style)) {
            escaping = null;
        }
        if (!escaping && clipsOverflow(style)) {
            clip = intersect(clip, boxClip(node, frame));
        }
        escaping ??= escapeOf(style.position);
    }

    return clip;
}

// The same rule walked top-down: the clip for in-flow, absolutely positioned and fixed descendants
// of an element, given the clips its parent passed on. Reading computed style properties is the
// main cost of a read, so containing-block properties are read only when the answer changes a
// clip.
export function clipsBelow(el, style, frame, clips) {
    const position = style.position;
    const own = clips[escapeOf(position) ?? 'flow'];
    const inner = clipsOverflow(style) && !isDocumentRoot(el) ? intersect(own, boxClip(el, frame)) : own;
    if (inner === clips.flow && inner === clips.absolute && inner === clips.fixed) {
        return clips;
    }
    const fixed = inner !== clips.fixed && holdsFixed(style);
    const absolute = inner !== clips.absolute && (position !== 'static' || fixed || holdsFixed(style));

    return { flow: inner, absolute: absolute ? inner : clips.absolute, fixed: fixed ? inner : clips.fixed };
}

export function frameClips(frame) {
    return { flow: frame.clip, absolute: frame.clip, fixed: frame.clip };
}

function escapeOf(position) {
    return position === 'absolute' || position === 'fixed' ? position : null;
}

function holds(escaping, style) {
    return (escaping === 'absolute' && style.position !== 'static') || holdsFixed(style);
}

function holdsFixed(style) {
    return style.transform !== 'none' || style.filter !== 'none' || style.perspective !== 'none'
        || style.backdropFilter !== 'none' || style.containerType !== 'normal'
        || /paint|layout|strict|content/.test(style.contain) || /transform|filter|perspective/.test(style.willChange);
}

function clipsOverflow(style) {
    return style.overflow !== 'visible';
}

// The root element and body pass their overflow to the viewport instead of clipping.
function isDocumentRoot(el) {
    return el === el.ownerDocument.body || el === el.ownerDocument.documentElement;
}

function boxClip(el, frame) {
    const box = toTop(el.getBoundingClientRect(), frame);

    return { left: box.x, top: box.y, right: box.x + box.w, bottom: box.y + box.h };
}

export function rounded(rect) {
    return { x: Math.round(rect.x), y: Math.round(rect.y), w: Math.round(rect.w), h: Math.round(rect.h) };
}
