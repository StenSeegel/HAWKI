import { JSDOM } from 'jsdom';
import createDOMPurify from 'dompurify';
import { readFileSync } from 'fs';

const dom = new JSDOM('', { url: 'http://localhost' });
const purify = createDOMPurify(dom.window);

// Monkey patch the createDOMPurify function (which is what Mermaid gets when it imports 'dompurify' in Node)
createDOMPurify.sanitize = purify.sanitize;
createDOMPurify.addHook = purify.addHook;
createDOMPurify.clearConfig = purify.clearConfig;
createDOMPurify.isValidAttribute = purify.isValidAttribute;
createDOMPurify.removeAttribute = purify.removeAttribute;

global.window = dom.window;
global.document = dom.window.document;
global.DOMParser = dom.window.DOMParser;
global.DOMPurify = purify;

const { default: mermaid } = await import('mermaid');

const code = readFileSync(0, 'utf-8');

if (!code || code.trim() === '') {
    console.log(JSON.stringify({ valid: false, error: "No input provided." }));
    process.exit(0);
}

try {
    mermaid.initialize({ startOnLoad: false });
    await mermaid.parse(code);
    console.log(JSON.stringify({ valid: true, error: null }));
} catch (e) {
    let errorMessage = e.message || String(e);
    if (e.str) {
        errorMessage = e.str;
    }
    console.log(JSON.stringify({ valid: false, error: errorMessage }));
}
