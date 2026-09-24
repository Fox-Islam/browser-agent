import { controlGroups, controlLines, CONTROLS_HEADER, cutLine, headerLines, headingLine, TEXT_HEADER, textBlock } from './render.js';

// Cuts a document-mode reading so its text rendering fits max_document characters. The outline is
// kept first, then the controls in document order, then the text: headings and controls carry the
// page's structure and what a visitor can do in the fewest characters, and a model answering from
// a cut page can say what exists beyond the cut. Each part is kept as a prefix, and a part
// gets nothing once an earlier one has been cut.
export function fitDocument(page, outline, actions, text, budget) {
    const groups = controlGroups(actions);
    const fixed = headerLines(page.url, page.title).length + TEXT_HEADER.length + CONTROLS_HEADER.length;
    const headings = outline.map((h) => headingLine(h).length);
    const controls = groups.map((g) => controlLines(g).length);
    const whole = fixed + sum(headings) + sum(controls) + textBlock(text).length;
    if (whole <= budget) {
        return { outline, actions, text, truncated: { outline: 0, text: 0, actions: 0 } };
    }
    // The cut line's numbers are at most the totals, so this is the most it can take.
    let left = budget - fixed - cutLine({ outline: outline.length, text: text.length, actions: groups.length }).length;
    const keptHeadings = prefix(headings, left);
    left = keptHeadings < headings.length ? 0 : left - sum(headings);
    const keptGroups = prefix(controls, left);
    left = keptGroups < controls.length ? 0 : left - sum(controls);
    const keptText = text.slice(0, Math.max(0, left - 1));

    return {
        outline: outline.slice(0, keptHeadings),
        actions: groups.slice(0, keptGroups).flat(),
        text: keptText,
        truncated: { outline: outline.length - keptHeadings, text: text.length - keptText.length, actions: groups.length - keptGroups },
    };
}

function prefix(costs, budget) {
    let used = 0;
    let count = 0;
    while (count < costs.length && used + costs[count] <= budget) {
        used += costs[count];
        count++;
    }

    return count;
}

function sum(values) {
    return values.reduce((total, value) => total + value, 0);
}
