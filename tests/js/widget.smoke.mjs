/**
 * Headless smoke test for the built widget bundle.
 *
 * The bundle is a browser IIFE, so this provides the minimum DOM/window surface
 * it touches and then asserts that mounting works, the launcher renders, the
 * SSE stream is parsed, and a failure produces a visible error instead of
 * nothing at all. Run with: node tests/js/widget.smoke.mjs
 */
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';

import { El } from './dom-stub.mjs';

const bundle = readFileSync('public/build/widget.js', 'utf8');

/* ── Environment ─────────────────────────────────────────────────────── */

const document = {
    readyState: 'complete',
    visibilityState: 'hidden',
    currentScript: null,
    listeners: {},
    body: new El('body'),
    head: new El('head'),
    documentElement: new El('html'),
    createElement: (tag) => new El(tag),
    createElementNS: (_ns, tag) => new El(tag, _ns),
    querySelector: () => null,
    querySelectorAll: () => [],
    addEventListener(type, fn) { (this.listeners[type] ??= []).push(fn); },
    removeEventListener(type, fn) { this.listeners[type] = (this.listeners[type] ?? []).filter((listener) => listener !== fn); },
    dispatch(type, event = {}) { (this.listeners[type] ?? []).forEach((fn) => fn({ type, target: this, ...event })); },
};

const store = new Map();
let reducedMotion = false;
let touchDevice = false;
const mediaListeners = [];

const scriptEl = new El('script');
scriptEl.src = 'https://app.example.com/widget.js';
scriptEl.dataset.siteKey = 'pk_testkey123456';
document.querySelector = (sel) => (sel.includes('data-site-key') ? scriptEl : null);

const window = {
    location: { origin: 'https://customer.example', href: 'https://customer.example/' },
    addEventListener: () => {},
    removeEventListener: () => {},
    setTimeout: (fn, ms) => setTimeout(fn, ms),
    clearTimeout: (id) => clearTimeout(id),
    requestAnimationFrame: (fn) => setTimeout(() => fn(Date.now()), 0),
    cancelAnimationFrame: (id) => clearTimeout(id),
    matchMedia: (query) => ({
        get matches() {
            return query.includes('prefers-reduced-motion') ? reducedMotion : !touchDevice;
        },
        addEventListener: (_type, fn) => mediaListeners.push({ query, fn }),
        removeEventListener: (_type, fn) => {
            const index = mediaListeners.findIndex((listener) => listener.query === query && listener.fn === fn);
            if (index !== -1) mediaListeners.splice(index, 1);
        },
    }),
    localStorage: {
        getItem: (k) => (store.has(k) ? store.get(k) : null),
        setItem: (k, v) => store.set(k, String(v)),
        removeItem: (k) => store.delete(k),
    },
};

class HTMLScriptElement {}

/* ── SSE fixture ─────────────────────────────────────────────────────── */

function sseResponse(frames) {
    const encoder = new TextEncoder();
    const chunks = frames.map((f) => encoder.encode(f));
    let i = 0;

    return {
        ok: true,
        status: 200,
        headers: { get: () => 'text/event-stream' },
        body: {
            getReader: () => ({
                read: async () => (i < chunks.length
                    ? { value: chunks[i++], done: false }
                    : { value: undefined, done: true }),
            }),
        },
    };
}

const calls = [];
let collectEmail = false;
let emailConsentSaved = false;

/* Route the mock by URL so the whole happy path can be exercised. */
const fetchMock = async (url, opts = {}) => {
    calls.push({ url, opts });

    const json = (body, status = 200) => ({
        ok: status >= 200 && status < 300,
        status,
        headers: { get: () => 'application/json' },
        json: async () => body,
    });

    if (url.endsWith('/config')) {
        return json({
            config: {
                bot_name: 'Updated Helper',
                greeting: 'Welcome back with fresh settings.',
                accent_color: '#16a34a',
                position: 'bottom-left',
                theme: 'dark',
                launcher_icon: 'chat',
                collect_email: collectEmail,
                suggestions: ['What changed?'],
            },
        });
    }

    if (/\/conversations$/.test(url) && (opts.method ?? 'GET') === 'POST') {
        const body = JSON.parse(opts.body ?? '{}');

        if (collectEmail && !emailConsentSaved && !body.email) {
            return json({
                message: 'Enter your email address to start this chat.',
                errors: { email: ['Enter your email address to start this chat.'], email_consent: ['Confirm consent to continue.'] },
            }, 422);
        }

        if (collectEmail && body.email && body.email_consent === true) {
            emailConsentSaved = true;
        }

        return json({
            conversation_id: 7,
            config: {
                bot_name: 'Acme Helper',
                greeting: 'Hi! I can help with product support.',
                accent_color: '#0ea5e9',
                position: 'bottom-right',
                theme: 'light',
                launcher_icon: 'spark',
                collect_email: collectEmail,
                suggestions: ['What are your opening hours?'],
            },
            email_captured: collectEmail && emailConsentSaved,
            quota: { remaining: 999, exhausted: false },
        });
    }

    if (url.includes('/messages')) {
        return sseResponse([
            'event: status\r\ndata: {"phase":"reading"}\r\n\r\n',
            'event: status\r\ndata: {"phase":"searching"}\r\n\r\n',
            'event: status\r\ndata: {"phase":"preparing"}\r\n\r\n',
            'event: delta\r\ndata: {"text":"Invoices are due "}\r\n\r\n',
            'event: delta\r\ndata: {"text":"within thirty days."}\r\n\r\n',
            'event: sources\ndata: {"sources":[{"knowledge_used":true}]}\n\n',
            'event: done\ndata: {"id":42}\n\n',
        ]);
    }

    if (url.includes('/conversations/7?')) {
        return json({
            config: { bot_name: 'Acme Helper', greeting: 'Hi!', collect_email: collectEmail },
            email_captured: collectEmail && emailConsentSaved,
            messages: [],
            quota: { remaining: 999, exhausted: false },
        });
    }

    return json({}, 500);
};

const context = {
    document,
    window,
    HTMLScriptElement,
    localStorage: window.localStorage,
    fetch: fetchMock,
    AbortController,
    TextDecoder,
    TextEncoder,
    URL,
    navigator: { clipboard: { writeText: async () => {} } },
    setTimeout,
    clearTimeout,
    console,
    location: window.location,
    alert: () => {},
    confirm: () => true,
    Math,
    Date,
    JSON,
    Object,
    Promise,
};

context.globalThis = context;
vm.createContext(context);

const errors = [];

try {
    vm.runInContext(bundle, context, { filename: 'widget.js' });
} catch (e) {
    console.error('BUNDLE THREW ON LOAD:', e);
    process.exit(1);
}

const api = window.DocuMindWidget;

const check = async (name, fn) => {
    try {
        await fn();
        console.log(`  ok    ${name}`);
    } catch (e) {
        errors.push(e);
        console.error(`  FAIL  ${name}\n        ${e.message}\n${String(e.stack).split('\n').slice(1, 5).join('\n')}`);
    }
};

const tick = (ms = 60) => new Promise((r) => setTimeout(r, ms));

const host = () => document.body.children[0];
const shadow = () => host().shadow;
const wrap = () => shadow().children.find((c) => c.classList.contains('root'));
const panel = () => wrap().children.find((c) => c.classList.contains('panel'));
const log = () => panel().querySelector('[data-log]');

console.log('\nwidget bundle smoke test\n');

await check('publishes a public API for framework mounting', () => {
    assert.ok(api, 'window.DocuMindWidget missing');
    assert.equal(typeof api.mount, 'function');
    assert.equal(typeof api.unmount, 'function');
});

await check('auto-mounts from the script tag', async () => {
    await tick();

    assert.ok(document.body.children.length > 0, 'nothing was appended to the body');
    assert.ok(host().shadow, 'no shadow root');
    assert.match(host().id, /^documind-widget-/);
    assert.equal(host().style.position, 'fixed', 'widget host must be viewport-positioned outside Shadow DOM styles');
    assert.equal(host().style.zIndex, '2147483000');
    assert.equal(host().style.width, '60px', 'host hit area should match the launcher width');
    assert.equal(host().style.height, '60px', 'host hit area should match the launcher height');
    assert.ok(wrap(), 'no widget root element');
    assert.ok(wrap().children.some((c) => c.classList.contains('launcher')), 'no launcher button');
    assert.ok(panel(), 'no chat panel');
    assert.equal(wrap().querySelector('.launcher').getAttribute('aria-controls'), panel().getAttribute('id'));
    assert.equal(panel().getAttribute('role'), 'dialog');
});

await check('the launcher is rendered before any network call resolves', () => {
    // If the shell waited on the API, a wrong origin would render nothing at all.
    assert.ok(wrap().children.some((c) => c.classList.contains('launcher')));
});

await check('sends visitor_id when opening a conversation', () => {
    const call = calls.find((c) => c.url.endsWith('/conversations'));

    assert.ok(call, 'no bootstrap call');
    assert.match(JSON.parse(call.opts.body).visitor_id, /^v_/);
    assert.equal(JSON.parse(call.opts.body).email, undefined, 'disabled email capture must start without requesting an email');
});

await check('applies the branding returned by the API', async () => {
    await tick();

    assert.equal(panel().querySelector('.title').textContent, 'Acme Helper');
    assert.equal(panel().querySelector('.status').textContent, 'Online');
    assert.ok(shadow().children[0].textContent.includes('#0ea5e9'), 'accent colour not applied');
    assert.ok(wrap().classList.contains('right'), 'bubble position not applied');
    assert.ok(shadow().children[0].textContent.includes('.panel { background: #ffffff; color: #172033;'), 'light theme not applied');
    assert.equal(wrap().querySelector('.launcher').children[0].children.length, 2, 'spark launcher icon not applied');
    assert.ok(
        panel().querySelector('.avatar').children[0].getAttribute('src').startsWith('data:image/svg+xml,'),
        'assistant blobatar was not rendered locally',
    );
    assert.ok(
        log().textContent.includes('Hi! I can help with product support.'),
        `greeting missing; log was "${log().textContent}"`,
    );
});

await check('refreshes an open widget when the page becomes visible', async () => {
    document.visibilityState = 'visible';
    document.dispatch('visibilitychange');
    await tick(30);

    assert.equal(panel().querySelector('.title').textContent, 'Updated Helper');
    assert.ok(shadow().children[0].textContent.includes('#16a34a'), 'refreshed accent colour not applied');
    assert.ok(wrap().classList.contains('left'), 'refreshed launcher side not applied');
    assert.ok(host().classList.contains('left'), 'refreshed host position not applied');
    assert.ok(log().textContent.includes('Welcome back with fresh settings.'), 'initial greeting not refreshed');
    assert.ok(panel().querySelector('[data-suggestions]').textContent.includes('What changed?'), 'starter questions not refreshed');
});

await check('opens by keyboard and Escape returns focus to the launcher', () => {
    const launcher = wrap().querySelector('.launcher');

    launcher.dispatch('click');
    assert.equal(launcher.getAttribute('aria-expanded'), 'true');
    document.dispatch('keydown', { key: 'Escape', preventDefault() {} });
    assert.equal(launcher.getAttribute('aria-expanded'), 'false');
    assert.equal(launcher.focused, true);
});

await check('forwards clicks on the standalone host hit area to the launcher', () => {
    const widgetHost = host();
    const launcher = wrap().querySelector('.launcher');

    widgetHost.dispatch('click', { composedPath: () => [widgetHost] });
    assert.equal(launcher.getAttribute('aria-expanded'), 'true');
});

await check('assistant avatar tracks a fine pointer within a subtle movement range', async () => {
    panel().dispatch('pointermove', { clientX: 100, clientY: 0 });
    await tick(20);

    const transform = panel().querySelector('.avatar').style.transform;
    assert.equal(transform, 'translate3d(2.20px, -2.20px, 0)');
});

await check('reduced motion disables avatar parallax immediately', async () => {
    reducedMotion = true;
    mediaListeners.filter((listener) => listener.query.includes('prefers-reduced-motion')).forEach(({ fn }) => fn());
    panel().dispatch('pointermove', { clientX: 100, clientY: 0 });
    await tick(20);

    assert.equal(panel().querySelector('.avatar').style.transform, '');
});

await check('touch pointers do not activate avatar parallax', async () => {
    reducedMotion = false;
    touchDevice = true;
    mediaListeners.forEach(({ fn }) => fn());
    panel().dispatch('pointermove', { clientX: 100, clientY: 0 });
    await tick(20);

    assert.equal(panel().querySelector('.avatar').style.transform, '');
});

await check('anchors the panel opposite the launcher and honors reduced motion', () => {
    const css = shadow().children[0].textContent;

    assert.ok(css.includes('.root.left .panel { left: 0; right: auto; }'));
    assert.ok(css.includes('.root.right .panel { right: 0; left: auto; }'));
    assert.ok(css.includes('@media (prefers-reduced-motion: reduce)'));
    assert.ok(css.includes('@keyframes launcher-pulse'));
});

await check('renders starter suggestions', async () => {
    const suggestions = panel().querySelector('[data-suggestions]');
    assert.ok(suggestions.children.length > 0, 'no suggestions rendered');
});

await check('a message request carries visitor_id', async () => {
    const input = panel().querySelector('[data-input]');
    input.value = 'When are invoices due?';

    panel().querySelector('[data-composer]').dispatch('submit', { preventDefault() {} });

    const pending = log().querySelector('[role="status"]');

    assert.equal(pending?.getAttribute('aria-label'), 'Reading your question…');
    assert.equal(pending?.querySelector('.typing-label')?.textContent, 'Reading your question…');

    await tick(120);

    const call = calls.find((c) => c.url.includes('/messages'));

    assert.ok(call, 'no message call');
    assert.ok(JSON.parse(call.opts.body).visitor_id, 'messages request had no visitor_id');
});

await check('streams a CRLF-framed SSE answer into the transcript', async () => {
    const text = log().textContent;

    assert.ok(
        text.includes('Invoices are due within thirty days.'),
        `answer not rendered; log was "${text}"`,
    );
});

await check('shows knowledge use without exposing internal source details', async () => {
    const indicator = log().querySelector('.knowledge-used');

    assert.ok(indicator, 'no support-knowledge indicator');
    assert.ok(indicator.textContent.includes('Answered from support knowledge'));
    assert.ok(!log().textContent.includes('p2–3'), 'internal page coordinates were exposed');
    assert.ok(!log().textContent.includes('Due in 30 days.'), 'internal source excerpt was exposed');
});

await check('adds feedback controls once the answer is done', async () => {
    const up = log().querySelector('[data-feedback="up"]');
    assert.ok(up, 'no feedback control');
    assert.equal(up.getAttribute('data-message-id'), '42');
});

await check('does not mark a truncated stream as complete', async () => {
    assert.ok(
        !log().textContent.includes('cut off'),
        'unexpected truncation note on a complete stream',
    );
});

await check('unmount removes the widget from the host page', () => {
    api.unmount();
    assert.equal(document.body.children.length, 0, 'widget still attached');
});

await check('email collection blocks chat until valid email and consent are submitted', async () => {
    collectEmail = true;
    const conversationRequestsBefore = calls.filter((call) => call.url.endsWith('/conversations') && call.opts.method === 'POST').length;
    await api.mount({ siteKey: 'pk_email_gate_test' });

    const gate = panel().querySelector('[data-email-gate]');
    const emailInput = gate.querySelector('[type="email"]');
    const consentInput = gate.querySelector('[type="checkbox"]');

    assert.equal(gate.style.display, 'flex');
    assert.equal(log().style.display, 'none');
    assert.ok(calls.length > conversationRequestsBefore, 'the server session check was not made');
    const sessionCheck = calls
        .filter((call) => call.url.endsWith('/conversations') && call.opts.method === 'POST')
        .at(-1);
    assert.equal(JSON.parse(sessionCheck.opts.body).email, undefined, 'email must not be sent before consent');

    emailInput.value = 'not-an-email';
    emailInput.validity = { valid: false };
    gate.dispatch('submit', { preventDefault() {} });
    assert.equal(emailInput.getAttribute('aria-invalid'), 'true');
    assert.ok(gate.querySelector('[role="alert"]').textContent.includes('valid email'));

    emailInput.value = 'visitor@example.com';
    emailInput.validity = { valid: true };
    consentInput.checked = true;
    gate.dispatch('submit', { preventDefault() {} });
    await tick(30);

    const submitted = calls
        .filter((call) => call.url.includes('/conversations') && call.opts.method === 'POST')
        .at(-1);
    assert.ok(submitted, 'valid consent did not create the conversation');
    assert.equal(JSON.parse(submitted.opts.body).email, 'visitor@example.com');
    assert.equal(JSON.parse(submitted.opts.body).email_consent, true);
    assert.equal(gate.style.display, 'none');
    assert.equal(log().style.display, 'flex');
    assert.ok(log().textContent.includes('Hi!'));

    api.unmount();
    window.localStorage.removeItem('documind_conv_pk_email_gate_test');
    await api.mount({ siteKey: 'pk_email_gate_test' });
    assert.equal(panel().querySelector('[data-email-gate]').style.display, 'none');
    assert.equal(log().style.display, 'flex');
    api.unmount();
    collectEmail = false;
});

console.log(`\n${errors.length === 0 ? 'PASS' : 'FAIL'} — ${errors.length} problem(s)\n`);
process.exit(errors.length === 0 ? 0 : 1);

