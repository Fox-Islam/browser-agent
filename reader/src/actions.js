import { isEditingHost, roleOf, TYPABLE_INPUTS } from './controls.js';
import { collapse } from './dom.js';
import { handleOf } from './handles.js';
import { accessibleName } from './names.js';
import { isPrivate } from './private.js';

const CHECKABLE_ROLES = new Set(['checkbox', 'radio', 'switch', 'menuitemcheckbox', 'menuitemradio']);

const TEXT_ROLES = new Set(['textbox', 'searchbox', 'spinbutton', 'combobox']);

// Inputs the executor fills by setting the value and dispatching input and change, because typing
// into their pickers is not reliable. Browsers ignore readonly on range and color.
const SETTABLE_INPUTS = new Set(['date', 'time', 'datetime-local', 'month', 'week', 'color', 'range']);

const READONLY_IGNORED = new Set(['color', 'range']);

// The HTML value format of each value input, named in its fill action so the model writes
// 2026-09-24 whatever the page's locale displays.
const VALUE_FORMATS = {
    date: 'YYYY-MM-DD',
    'datetime-local': 'YYYY-MM-DDTHH:MM',
    month: 'YYYY-MM',
    week: 'YYYY-Www',
    color: '#rrggbb',
    range: 'number',
};

const SECONDS_IN_A_MINUTE = 60;

// Everything the reader reports about one control, computed once so the observation and the
// guard describe the same state.
export function describe(el, maxLabel) {
    const role = roleOf(el);
    const name = cut(accessibleName(el), maxLabel);

    return {
        role,
        label: name || role,
        value: cut(valueOf(el, role), maxLabel),
        states: statesOf(el, role),
    };
}

export function actionsFor(el, info, rect, node, options) {
    const base = { node, role: info.role, submits: submitsForm(el), ...formOf(el), ...linkOf(el, options.max_label), ...requiredOf(el) };
    const shown = { value: info.value, ...info.states, rect };
    if (el.localName === 'select') {
        return selectActions(el, info, { ...base, ...info.states, rect }, options);
    }
    if (isSettable(el) || acceptsTyping(el, info.role)) {
        const fill = { ...base, kind: 'fill', label: info.label, ...fillHints(el), ...shown };

        // Document mode is not acted on, so the Open click that lets the agent act is left out.
        return options.mode === 'document'
            ? [fill]
            : [fill, { ...base, kind: 'click', label: cut(`Open ${info.label}`, options.max_label), ...shown }];
    }

    return [{ ...base, kind: 'click', label: info.label, ...shown }];
}

// The handle of the control's form owner, so a caller can tell which submit a field's edit
// belongs to. Absent for controls with no form owner.
function formOf(el) {
    const owner = el.form;

    return owner ? { form: handleOf(owner) } : {};
}

// Where a link goes: a same-origin address without its origin, any other in full.
function linkOf(el, cap) {
    if (!['a', 'area'].includes(el.localName) || !el.hasAttribute('href')) {
        return {};
    }
    let url;
    try {
        url = new URL(el.href);
    } catch {
        return {};
    }
    const href = url.origin === location.origin ? url.pathname + url.search + url.hash : url.href;

    return { href: cut(href, cap) };
}

function requiredOf(el) {
    return el.required === true || el.getAttribute('aria-required') === 'true' ? { required: true } : {};
}

// A submit button or submit/image input with a form owner, whether it sits inside the form or
// names it through its form attribute. A button without a type attribute is a submit button.
function submitsForm(el) {
    const submitter = (el.localName === 'button' && el.type === 'submit')
        || (el.localName === 'input' && (el.type === 'submit' || el.type === 'image'));

    return submitter && el.form !== null;
}

export function cut(text, cap) {
    return text.length > cap ? text.slice(0, cap) : text;
}

function selectActions(el, info, base, options) {
    const cap = options.max_label;
    const current = isPrivate(el) ? '' : cut([...el.selectedOptions].map(optionLabel).join(', '), cap);
    const choices = [...el.options].filter((option) => !option.selected && !optionDisabled(option));
    const omitted = Math.max(0, choices.length - options.max_options);

    return choices.slice(0, options.max_options).map((option) => ({
        ...base,
        kind: 'select',
        label: cut(`${info.label} → ${optionLabel(option)}`, cap),
        value: cut(option.value, cap),
        current_value: current,
        omitted_options: omitted,
    }));
}

// format for value inputs; min, max and step as the page wrote them, for value and number inputs.
function fillHints(el) {
    if (el.localName !== 'input' || !(isSettable(el) || el.type === 'number')) {
        return {};
    }
    const hints = el.type === 'number' ? {} : { format: formatOf(el) };
    for (const name of ['min', 'max', 'step']) {
        if (el.hasAttribute(name)) {
            hints[name] = el.getAttribute(name);
        }
    }

    return hints;
}

function formatOf(el) {
    if (el.type !== 'time') {
        return VALUE_FORMATS[el.type];
    }
    const step = Number(el.getAttribute('step'));

    return step > 0 && step < SECONDS_IN_A_MINUTE ? 'HH:MM:SS' : 'HH:MM';
}

export function isSettable(el) {
    if (el.localName !== 'input' || !SETTABLE_INPUTS.has(el.type)) {
        return false;
    }

    return READONLY_IGNORED.has(el.type) || !el.readOnly;
}

function acceptsTyping(el, role) {
    if (!TEXT_ROLES.has(role)) {
        return false;
    }
    if (el.localName === 'textarea' || (el.localName === 'input' && TYPABLE_INPUTS.has(el.type))) {
        return !el.readOnly;
    }

    return el.isContentEditable && el.getAttribute('aria-readonly') !== 'true';
}

function valueOf(el, role) {
    if (isPrivate(el)) {
        return '';
    }
    if (el.localName === 'input' || el.localName === 'textarea') {
        return el.value;
    }
    if (el.localName === 'select') {
        return [...el.selectedOptions].map((option) => option.value).join(', ');
    }
    if (isEditingHost(el)) {
        return collapse(el.textContent);
    }
    if (role === 'slider' || role === 'spinbutton') {
        return el.getAttribute('aria-valuetext') ?? el.getAttribute('aria-valuenow') ?? '';
    }

    return '';
}

function statesOf(el, role) {
    const states = {};
    if (CHECKABLE_ROLES.has(role)) {
        states.checked = checkedOf(el);
    }
    const selected = el.getAttribute('aria-selected');
    if (selected !== null) {
        states.selected = selected === 'true' ? 'true' : 'false';
    }
    const expanded = el.localName === 'summary' ? summaryExpanded(el) : el.getAttribute('aria-expanded');
    if (expanded !== null) {
        states.expanded = expanded === 'true' ? 'true' : 'false';
    }

    return states;
}

function checkedOf(el) {
    if (el.localName === 'input' && (el.type === 'checkbox' || el.type === 'radio')) {
        return el.indeterminate ? 'mixed' : String(el.checked);
    }
    const checked = el.getAttribute('aria-checked');

    return checked === 'true' || checked === 'mixed' ? checked : 'false';
}

function summaryExpanded(el) {
    const details = el.parentElement;

    return details?.localName === 'details' ? String(details.open) : null;
}

function optionLabel(option) {
    return collapse(option.label);
}

function optionDisabled(option) {
    return option.disabled || (option.parentElement?.localName === 'optgroup' && option.parentElement.disabled);
}
