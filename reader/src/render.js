// A copy of Phox\BrowserAgent\Reader\TextRendering, which documents the rules. The reader renders
// only to measure: document mode cuts the observation so its rendering fits max_document. Both must
// produce the same text; tests pin the two together.

export function headerLines(url, title) {
    return `URL: ${url}\nTitle: ${title}\nOutline:\n`;
}

export const TEXT_HEADER = 'Text:\n';

export const CONTROLS_HEADER = 'Controls:\n';

export function headingLine(heading) {
    return `${'  '.repeat(Math.max(0, heading.level - 1))}${heading.text}\n`;
}

export function textBlock(text) {
    return text === '' ? '' : `${text}\n`;
}

export function cutLine(truncated) {
    return `Cut to fit: ${truncated.outline} headings, ${truncated.text} characters of text, ${truncated.actions} controls\n`;
}

export function controlGroups(actions) {
    const groups = [];
    for (const action of actions) {
        const last = groups.at(-1)?.[0];
        if (action.kind !== 'click' && action.kind !== 'fill' && action.kind !== 'select') {
            continue;
        }
        if (action.kind === 'select' && last?.kind === 'select' && selectKey(last) === selectKey(action)) {
            groups.at(-1).push(action);
        } else if (!(action.kind === 'click' && last?.kind === 'fill' && action.node === last.node && action.label === `Open ${last.label}`)) {
            groups.push([action]);
        }
    }

    return groups;
}

export function controlLines(group) {
    const [first] = group;
    const select = first.kind === 'select';
    const parts = [`- [${first.node}] ${first.role}: ${select ? optionOf(first.label)[0] : first.label}`];
    if (first.kind !== 'click') {
        parts.push(` [${first.kind}]`);
    }
    const echoesLabel = first.role === 'button' && first.value === first.label;
    if (!select && (first.value ?? '') !== '' && !echoesLabel) {
        parts.push(` value=${first.value}`);
    }
    if (select && (first.current_value ?? '') !== '') {
        parts.push(` selected=${first.current_value}`);
    }
    parts.push(...flags(first));
    const line = `${parts.join('')}\n`;
    if (!select) {
        return line;
    }
    const more = first.omitted_options ? ` (+${first.omitted_options} more)` : '';

    return `${line}  options: ${group.map((a) => optionOf(a.label)[1]).join(', ')}${more}\n`;
}

function flags(action) {
    const parts = [];
    if (action.required) {
        parts.push(' (required)');
    }
    const checked = { true: ' (checked)', false: ' (unchecked)', mixed: ' (mixed)' }[action.checked];
    const expanded = { true: ' (expanded)', false: ' (collapsed)' }[action.expanded];
    parts.push(...[checked, expanded].filter(Boolean));
    if (action.submits) {
        parts.push(' (submits)');
    }
    if (action.format) {
        parts.push(` format=${action.format}`);
    }
    if (action.href) {
        parts.push(` -> ${action.href}`);
    }

    return parts;
}

function selectKey(action) {
    return `${action.node ?? ''}\u0000${optionOf(action.label)[0]}\u0000${action.current_value ?? ''}`;
}

function optionOf(label) {
    const at = label.lastIndexOf(' → ');

    return at === -1 ? [label, ''] : [label.slice(0, at), label.slice(at + 3)];
}
