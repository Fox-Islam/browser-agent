// When the document last changed, kept in the reader's world, so how long a page has been quiet is
// one question instead of a wait watched from outside. A scroll counts as a change.
let lastChange = performance.now();

const touched = () => {
    lastChange = performance.now();
};

new MutationObserver(touched).observe(document, { subtree: true, childList: true, attributes: true, characterData: true });
addEventListener('scroll', touched, { passive: true, capture: true });

export function quietMs() {
    return performance.now() - lastChange;
}

// Resolves once the document has been quiet for stillMs, or when timeoutMs has passed. One call
// covers the whole wait.
export function still(stillMs, timeoutMs) {
    const began = performance.now();

    return new Promise((resolve) => {
        const look = () => {
            const quiet = quietMs();
            const waited = performance.now() - began;
            if (quiet >= stillMs || waited >= timeoutMs) {
                resolve({ still: quiet >= stillMs, quiet: Math.round(quiet), waited: Math.round(waited) });

                return;
            }
            setTimeout(look, Math.max(10, Math.min(50, stillMs - quiet)));
        };
        look();
    });
}
