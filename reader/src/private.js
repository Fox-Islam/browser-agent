const PRIVATE_TYPES = new Set(['password', 'file', 'hidden']);

// Fields whose value never leaves the page: not in a returned value, not in a guard or page key,
// and not in another control's accessible name.
export function isPrivate(el) {
    if (el.localName === 'input' && PRIVATE_TYPES.has(el.type)) {
        return true;
    }
    const tokens = (el.getAttribute('autocomplete') ?? '').toLowerCase().split(/\s+/);

    return tokens.some((token) => token.startsWith('cc-'));
}
