// Node handles live in the reader's isolated world, out of reach of page scripts. Elements are
// held weakly so a handle never keeps a removed element alive.
const handles = new WeakMap();
const elements = new Map();
let next = 1;

export function handleOf(el) {
    let node = handles.get(el);
    if (node === undefined) {
        node = next++;
        handles.set(el, node);
        elements.set(node, new WeakRef(el));
    }

    return node;
}

export function resolve(node) {
    const el = elements.get(node)?.deref();
    if (el === undefined) {
        elements.delete(node);
    }

    return el && el.isConnected ? el : null;
}
