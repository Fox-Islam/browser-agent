// Traversal helpers shared by the reader. Nodes from same-origin iframes belong to another realm,
// so type checks use nodeType and localName instead of instanceof.

export function styleOf(el) {
    return el.ownerDocument.defaultView.getComputedStyle(el);
}

export function isShadowRoot(node) {
    return node.nodeType === Node.DOCUMENT_FRAGMENT_NODE && node.host !== undefined;
}

// Children in flat-tree order: a host renders its open shadow root, a slot renders what is
// assigned to it, or its fallback content when nothing is.
export function composedChildren(node) {
    if (node.nodeType === Node.ELEMENT_NODE && node.shadowRoot) {
        return node.shadowRoot.childNodes;
    }
    if (node.localName === 'slot' && isShadowRoot(node.getRootNode())) {
        const assigned = node.assignedNodes();

        return assigned.length > 0 ? assigned : node.childNodes;
    }

    return node.childNodes;
}

export function parentAcrossShadow(node) {
    if (node.parentElement) {
        return node.parentElement;
    }
    const parent = node.parentNode;

    return parent && isShadowRoot(parent) ? parent.host : null;
}

// Walks up through shadow hosts and same-origin frame elements, starting at the element itself.
export function* selfAndAncestors(el) {
    for (let node = el; node;) {
        yield node;
        node = parentAcrossShadow(node) ?? frameElementOf(node);
    }
}

export function frameDocument(el) {
    try {
        const doc = el.contentDocument;

        return doc && doc.documentElement ? doc : null;
    } catch {
        return null;
    }
}

export function containsComposed(ancestor, node) {
    for (let current = node; current; current = parentAcrossShadow(current)) {
        if (current === ancestor) {
            return true;
        }
    }

    return false;
}

// Every root a query has to visit: the document, each open shadow root and each same-origin
// frame document below it.
export function collectRoots(doc) {
    const roots = [doc];
    for (let i = 0; i < roots.length; i++) {
        const walker = roots[i].ownerDocument
            ? roots[i].ownerDocument.createTreeWalker(roots[i], NodeFilter.SHOW_ELEMENT)
            : roots[i].createTreeWalker(roots[i], NodeFilter.SHOW_ELEMENT);
        for (let el = walker.nextNode(); el; el = walker.nextNode()) {
            if (el.shadowRoot) {
                roots.push(el.shadowRoot);
            }
            const inner = el.localName === 'iframe' || el.localName === 'frame' ? frameDocument(el) : null;
            if (inner) {
                roots.push(inner);
            }
        }
    }

    return roots;
}

export function collapse(text) {
    return text.replace(/\s+/g, ' ').trim();
}

function frameElementOf(node) {
    if (node.nodeType !== Node.ELEMENT_NODE || node !== node.ownerDocument.documentElement) {
        return null;
    }
    try {
        return node.ownerDocument.defaultView.frameElement;
    } catch {
        return null;
    }
}
