import { accessibleName } from './names.js';
import { collapse, composedChildren, parentAcrossShadow } from './dom.js';

// Which item a control acts on, so a guard notices a list re-rendered under a decision ("Delete"
// now sitting beside a different row). Never the container's whole text: a clock or ticker in the
// same container would make the control permanently stale.
const CONTEXT_LENGTH = 80;

const CONTAINERS = [
    ['form', (el) => el.localName === 'form' || hasRole(el, 'form')],
    ['dialog', (el) => el.localName === 'dialog' || hasRole(el, 'dialog') || hasRole(el, 'alertdialog')],
    ['row', (el) => el.localName === 'tr' || hasRole(el, 'row')],
    ['item', (el) => el.localName === 'li' || hasRole(el, 'listitem')],
    ['article', (el) => el.localName === 'article' || hasRole(el, 'article')],
];

const HEADING = 'h1, h2, h3, h4, h5, h6, [role~="heading"]';

export function contextLabel(el) {
    const found = container(el);
    if (!found) {
        return '';
    }
    const [kind, box] = found;

    return (authorName(box) || fallback(kind, box)).slice(0, CONTEXT_LENGTH);
}

function container(el) {
    for (let node = parentAcrossShadow(el); node; node = parentAcrossShadow(node)) {
        const match = CONTAINERS.find(([, test]) => test(node));
        if (match) {
            return [match[0], node];
        }
    }

    return null;
}

// A name the page gave the container. A row or list item is otherwise named from its contents,
// which is the whole text the context label exists to avoid.
function authorName(box) {
    const named = box.hasAttribute('aria-label') || box.hasAttribute('aria-labelledby') || box.hasAttribute('title');

    return named ? accessibleName(box) : '';
}

function fallback(kind, box) {
    const cell = kind === 'row' ? rowCell(box) : null;
    const heading = cell ? null : box.querySelector(HEADING);
    if (cell || heading) {
        return collapse((cell ?? heading).textContent ?? '');
    }

    return kind === 'item' || kind === 'article' ? firstTextRun(box) : '';
}

function rowCell(row) {
    const cells = [...row.children].filter((c) => ['td', 'th'].includes(c.localName) || hasRole(c, 'cell') || hasRole(c, 'gridcell') || hasRole(c, 'rowheader'));

    return cells.find((c) => c.localName === 'th' || hasRole(c, 'rowheader')) ?? cells[0] ?? null;
}

function firstTextRun(box) {
    const stack = [...composedChildren(box)].reverse();
    while (stack.length > 0) {
        const node = stack.pop();
        if (node.nodeType === Node.TEXT_NODE && /\S/.test(node.data)) {
            return collapse(node.data);
        }
        if (node.nodeType === Node.ELEMENT_NODE && !['script', 'style', 'template'].includes(node.localName)) {
            stack.push(...[...composedChildren(node)].reverse());
        }
    }

    return '';
}

function hasRole(el, role) {
    return (el.getAttribute('role') ?? '').split(/\s+/).includes(role);
}
