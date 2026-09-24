import { spawn } from 'node:child_process';
import { existsSync, globSync, mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { createServer } from 'node:http';
import { homedir, tmpdir } from 'node:os';
import { extname, join } from 'node:path';

const BUNDLE = readFileSync(new URL('../../resources/page-reader.js', import.meta.url), 'utf8');
const FIXTURES = new URL('fixtures/', import.meta.url).pathname;
const CANDIDATES = [
    process.env.CHROME_PATH,
    ...globSync(join(homedir(), '.cache/puppeteer/chrome-headless-shell/*/*/chrome-headless-shell')),
    '/usr/bin/chromium',
    '/usr/bin/chromium-browser',
];

export const DEFAULTS = { max_text: 6000, max_actions: 250, scroll_step: 560, max_options: 25, max_label: 200, mode: 'viewport', max_document: 50000, max_outline: 200 };

// A headless Chrome and a fixture server on 127.0.0.1, shared by every test in a file.
export async function startBrowser() {
    const binary = CANDIDATES.find((path) => path && existsSync(path));
    if (!binary) {
        throw new Error('No headless Chrome found. Set CHROME_PATH to a Linux Chrome or chrome-headless-shell binary.');
    }
    const server = await serveFixtures();
    const profile = mkdtempSync(join(tmpdir(), 'page-reader-'));
    const chrome = spawn(binary, [
        '--headless', '--remote-debugging-port=0', `--user-data-dir=${profile}`, '--no-first-run',
        '--no-sandbox', '--hide-scrollbars', '--mute-audio',
    ]);
    const cdp = await connect(await devtoolsUrl(chrome));

    return {
        base: `http://127.0.0.1:${server.address().port}`,
        newPage: () => openPage(cdp),
        async close() {
            cdp.close();
            chrome.kill();
            server.close();
            await new Promise((done) => chrome.once('exit', done));
            rmSync(profile, { recursive: true, force: true });
        },
    };
}

async function openPage(cdp) {
    const { targetId } = await cdp.send('Target.createTarget', { url: 'about:blank' });
    const { sessionId } = await cdp.send('Target.attachToTarget', { targetId, flatten: true });
    const send = (method, params = {}) => cdp.send(method, params, sessionId);
    await send('Page.enable');
    await send('Emulation.setDeviceMetricsOverride', { width: 1120, height: 780, deviceScaleFactor: 1, mobile: false });
    let context = null;

    const evaluate = async (params) => {
        const { result, exceptionDetails } = await send('Runtime.evaluate', params);
        if (exceptionDetails) {
            throw new Error(exceptionDetails.exception?.description ?? exceptionDetails.text);
        }

        return result;
    };

    const page = {
        send,
        async goto(url) {
            const loaded = cdp.once('Page.loadEventFired', sessionId);
            await send('Page.navigate', { url });
            await loaded;
            const { frameTree } = await send('Page.getFrameTree');
            ({ executionContextId: context } = await send('Page.createIsolatedWorld', {
                frameId: frameTree.frame.id,
                worldName: 'phox-page-reader',
            }));
            await evaluate({ expression: BUNDLE, contextId: context });
        },
        async read(options = {}) {
            return page.reader(`pageReader.read(${JSON.stringify({ ...DEFAULTS, ...options })})`);
        },
        async reader(expression) {
            return (await evaluate({ expression, contextId: context, returnByValue: true, awaitPromise: true })).value;
        },
        // Runs in the page's own world, as page scripts would.
        async run(expression) {
            return (await evaluate({ expression, returnByValue: true, awaitPromise: true })).value;
        },
        // Chrome's accessibility tree entry for the element behind a node handle.
        async axNode(node) {
            const { objectId } = await evaluate({ expression: `pageReader.resolve(${node})`, contextId: context });
            const { node: dom } = await send('DOM.describeNode', { objectId });
            const { nodes } = await send('Accessibility.getPartialAXTree', { backendNodeId: dom.backendNodeId, fetchRelatives: false });

            return nodes[0];
        },
        async close() {
            await cdp.send('Target.closeTarget', { targetId });
        },
    };

    return page;
}

function serveFixtures() {
    const types = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript' };
    const server = createServer((request, response) => {
        const path = join(FIXTURES, new URL(request.url, 'http://x').pathname);
        if (!path.startsWith(FIXTURES) || !existsSync(path)) {
            response.writeHead(404).end();

            return;
        }
        response.writeHead(200, { 'content-type': types[extname(path)] ?? 'application/octet-stream' });
        response.end(readFileSync(path));
    });

    return new Promise((done) => server.listen(0, '127.0.0.1', () => done(server)));
}

function devtoolsUrl(chrome) {
    return new Promise((done, fail) => {
        let output = '';
        chrome.stderr.on('data', (chunk) => {
            output += chunk;
            const match = output.match(/DevTools listening on (ws:\/\/\S+)/);
            if (match) {
                done(match[1]);
            }
        });
        chrome.once('exit', (code) => fail(new Error(`Chrome exited with ${code}: ${output}`)));
    });
}

async function connect(url) {
    const socket = new WebSocket(url);
    await new Promise((done, fail) => {
        socket.onopen = done;
        socket.onerror = fail;
    });
    let id = 0;
    const pending = new Map();
    const waiters = [];
    socket.onmessage = ({ data }) => {
        const message = JSON.parse(data);
        if (message.id !== undefined) {
            const { resolve, reject } = pending.get(message.id);
            pending.delete(message.id);
            message.error ? reject(new Error(message.error.message)) : resolve(message.result);

            return;
        }
        for (const waiter of waiters.filter((w) => w.method === message.method && w.sessionId === message.sessionId)) {
            waiters.splice(waiters.indexOf(waiter), 1);
            waiter.resolve(message.params);
        }
    };

    return {
        send(method, params = {}, sessionId = undefined) {
            const message = { id: ++id, method, params, sessionId };
            socket.send(JSON.stringify(message));

            return new Promise((resolve, reject) => pending.set(message.id, { resolve, reject }));
        },
        once(method, sessionId) {
            return new Promise((resolve) => waiters.push({ method, sessionId, resolve }));
        },
        close() {
            socket.close();
        },
    };
}
