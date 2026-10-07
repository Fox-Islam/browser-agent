import { collectRoots } from './dom.js';
import { centreInside, frameOf, hasArea, toTop } from './geometry.js';

// Loading indicators a page shows while what it was opened for is on its way. A page shell is
// operable and still long before its lists arrive, and a decision on it answers about content
// that was coming.
const INDICATORS = '[aria-busy="true"], [role~="progressbar"], [class*="skeleton"], [class*="shimmer"], '
    + '[class*="animate-pulse"], [class*="animate-spin"], [class*="spinner"]';

const LOADING_LINE = /^(loading|fetching|please wait)\b/i;
const MAX_LOADING_LINE = 40;

export function busyCount(lines, frames) {
    let count = lines.filter((line) => line.length <= MAX_LOADING_LINE && LOADING_LINE.test(line)).length;
    for (const root of collectRoots(document)) {
        for (const el of root.querySelectorAll(INDICATORS)) {
            const doc = el.ownerDocument;
            const frame = frameOf(doc, frames);
            const box = el.getBoundingClientRect();
            if (hasArea(box) && el.checkVisibility({ opacityProperty: true, visibilityProperty: true }) && centreInside(toTop(box, frame), frame.clip)) {
                count++;
            }
        }
    }

    return count;
}
