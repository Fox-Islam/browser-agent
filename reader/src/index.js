import { describe } from './actions.js';
import { locateNow } from './controls.js';
import { blocker, setValue } from './execute.js';
import { quietMs, still } from './quiet.js';
import { resolve } from './handles.js';
import { settle } from './names.js';
import { guardOf, pageKeyOf } from './keys.js';
import { read } from './read.js';

// The executor's staleness check calls guard() without options, so labels are cut to the length
// the last read used.
let maxLabel = Infinity;

function guard(node) {
    settle();
    const el = resolve(node);

    const located = el ? locateNow(el) : null;

    return located ? guardOf(el, node, describe(el, maxLabel), located.target) : null;
}

// Caller queries run in this world and reach observed elements by node number.
globalThis.el ??= (node) => resolve(node);

globalThis.pageReader ??= Object.freeze({
    read(options) {
        maxLabel = options.max_label;

        return read(options);
    },
    resolve,
    guard,
    pageKey: pageKeyOf,
    blocker,
    setValue,
    quietMs,
    still,
});
