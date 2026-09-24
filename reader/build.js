import { build } from 'esbuild';
import { readFile } from 'node:fs/promises';

// The bundle is committed, so it must come from exactly the versions package.json pins: NOTICE
// names the bundled dom-accessibility-api version, and another esbuild can emit different code.
const pinned = JSON.parse(await readFile(new URL('../package.json', import.meta.url), 'utf8')).devDependencies;
for (const name of ['dom-accessibility-api', 'esbuild']) {
    const installed = JSON.parse(await readFile(new URL(`../node_modules/${name}/package.json`, import.meta.url), 'utf8')).version;
    if (installed !== pinned[name]) {
        throw new Error(`${name} ${installed} is installed but package.json pins ${pinned[name]}; run npm ci`);
    }
}

const PRIVATE = new URL('src/private.js', import.meta.url).pathname;

// accname step 2E takes the value of a control embedded in a label or referenced by
// aria-labelledby. The patch makes a private control contribute an empty string there. The
// patched text belongs to dom-accessibility-api 0.7.1; the build fails if it is missing.
const EMBEDDED_VALUE = 'if (skipToStep2E || context.isEmbeddedInLabel || context.isReferenced) {';

const privateEmbeddedControls = {
    name: 'private-embedded-controls',
    setup(builder) {
        builder.onLoad({ filter: /dom-accessibility-api\/dist\/accessible-name-and-description\.mjs$/ }, async ({ path }) => {
            const source = await readFile(path, 'utf8');
            if (!source.includes(EMBEDDED_VALUE)) {
                throw new Error(`dom-accessibility-api changed: step 2E not found in ${path}`);
            }
            const patched = source.replace(
                EMBEDDED_VALUE,
                `${EMBEDDED_VALUE}\n      if (isElement(current) && __isPrivate(current)) { consultedNodes.add(current); return ""; }`,
            );

            return { contents: `import { isPrivate as __isPrivate } from ${JSON.stringify(PRIVATE)};\n${patched}`, loader: 'js' };
        });
    },
};

await build({
    entryPoints: [new URL('src/index.js', import.meta.url).pathname],
    outfile: new URL('../resources/page-reader.js', import.meta.url).pathname,
    bundle: true,
    minify: true,
    format: 'iife',
    target: 'chrome121',
    legalComments: 'none',
    plugins: [privateEmbeddedControls],
    banner: { js: '/* phox/browser-agent page reader. Bundles dom-accessibility-api (MIT), see NOTICE. */' },
});
