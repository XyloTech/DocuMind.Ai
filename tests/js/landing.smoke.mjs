/**
 * Headless smoke test for the landing page's interactive layer.
 *
 * resources/views/landing.blade.php drives everything through initLanding()
 * in resources/js/app.js. This builds the same DOM skeleton the blade emits,
 * boots the real module against the shared DOM stub, then walks every
 * interactive surface: mobile menu, theme toggle, scroll story, chat
 * preview, widget demo (open / gaze / send / handoff / replay) and the
 * copy-snippet button.
 *
 * The stub has no IntersectionObserver, so reveals resolve through the
 * immediate fallback, and no DOMParser, so blobatars hydrate to their
 * data-URI <img> fallback — both are the code's documented degraded paths.
 *
 * Run with: node tests/js/landing.smoke.mjs
 */
import assert from 'node:assert/strict';
import { El, queryAll } from './dom-stub.mjs';

/* ── Skeleton mirroring landing.blade.php ──────────────────────────── */

const el = (tag, attrs = {}, ...children) => {
    const node = new El(tag);

    for (const [key, value] of Object.entries(attrs)) {
        if (key === 'class') {
            node.className = value;
        } else if (key === 'text') {
            node.textContent = value;
        } else {
            node.setAttribute(key, value);
        }
    }

    node.append(...children);

    return node;
};

const body = new El('body');
const documentElement = el('html', { class: 'dark' });
documentElement.classList.remove('dark');

const themeToggle = el('button', { 'data-theme-toggle': '', 'aria-label': 'Switch to light theme' });
const menuToggle = el('button', { 'data-landing-menu-toggle': '', 'aria-expanded': 'false' });
const menuLink = el('a', { href: '#features', text: 'Features' });
const menu = el('div', { 'data-landing-menu': '' }, menuLink);
menu.hidden = true;

const header = el('header', {}, themeToggle, menuToggle, menu);
const reveals = [el('div', { class: 'reveal' }), el('div', { class: 'reveal' })];

const steps = Array.from({ length: 5 }, (_, i) => el('button', {
    'data-story-step': '',
    class: 'l-step',
}, el('span', { class: 'l-step-index', text: String(i + 1) })));
steps[0].setAttribute('aria-current', 'step');

const copyBtn = el('button', { 'data-copy-snippet': '', text: 'Copy' });
const code = el('pre', { class: 'l-code', text: '<script src="http://localhost/widget.js" data-site-key="pk_live_x"></script>' });

const panels = Array.from({ length: 5 }, (_, i) => {
    const panel = el('div', {
        'data-story-panel': '',
        class: 'l-panel',
        'data-visible': i === 0 ? 'true' : 'false',
        'aria-hidden': i === 0 ? 'false' : 'true',
    });

    if (i === 3) panel.append(copyBtn, code);

    return panel;
});

const story = el('div', { 'data-story': '' },
    el('ol', {}, ...steps.map((step) => el('li', {}, step))),
    el('div', {}, el('div', { class: 'l-story-panels' }, ...panels)));

const chatLog = el('div', { 'data-chat-log': '' },
    el('div', { class: 'l-bubble l-bubble-bot', text: 'Ask me anything.' }));
const qaChips = ['product', 'policy', 'faq', 'support'].map((qa) =>
    el('button', { 'data-qa': qa, text: qa }));
const chatPreview = el('div', { 'data-chat-preview': '' }, chatLog, el('div', {}, ...qaChips));

const launcherAvatar = el('span', {
    'data-blobatar-name': 'DocuMind',
    'data-blobatar-background': 'circle',
    'data-blobatar-animate': 'live',
});
const launcher = el('button', {
    'data-demo-launcher': '',
    'aria-expanded': 'false',
    'aria-label': 'Open the support chat demo',
}, launcherAvatar);
const headerAvatar = el('span', {
    'data-blobatar-name': 'DocuMind',
    'data-blobatar-background': 'circle',
});
const demoChips = [
    ['pro', "What's included in Pro?"],
    ['refund', "What's your refund window?"],
    ['human', 'Talk to a human'],
].map(([key, label]) => el('button', { 'data-demo-ask': key, class: 'l-chip', text: label }));

const demoLog = el('div', { 'data-demo-log': '' },
    el('div', { class: 'l-bubble l-bubble-bot', text: 'Welcome!' }),
    el('div', {}, ...demoChips));

const input = el('input', { name: 'question', type: 'text' });
const form = el('form', { 'data-demo-form': '' }, input, el('button', { type: 'submit', text: 'Ask' }));
const widgetPanel = el('div', { class: 'l-widget-panel' },
    el('div', {}, headerAvatar), demoLog, form);
const demo = el('div', { 'data-widget-demo': '', 'data-open': 'false' },
    el('div', { class: 'site-filler' }), launcher, widgetPanel);
const replayBtn = el('button', { 'data-demo-replay': '', text: 'Replay demo' });

/* Hero showcase: video, custom controls, and the floating moments. */
const video = el('video', { 'data-showcase-video': '' });
video.muted = true;
video.paused = true;
video.currentTime = 0;
video.play = () => {
    video.paused = false;
    return Promise.resolve();
};
video.pause = () => {
    video.paused = true;
};

const vToggle = el('button', { 'data-video-toggle': '', 'aria-label': 'Pause the demo video' });
const vMute = el('button', { 'data-video-mute': '', 'aria-label': 'Unmute the demo video' });
const vReplay = el('button', { 'data-video-replay': '', 'aria-label': 'Replay the demo video' });

const popups = Array.from({ length: 3 }, () => {
    const close = el('button', { 'data-popup-close': '', 'aria-label': 'Dismiss this moment' });
    return el('div', { 'data-popup': '', class: 'l-pop' }, close);
});
const popupsLayer = el('div', { 'data-popups': '' }, ...popups);
const momentsReplay = el('button', { 'data-popups-replay': '', text: 'Replay moments' });
const momentsDismiss = el('button', { 'data-popups-dismiss': '', text: 'Close moments' });

const showcaseFrame = el('div', {
    'data-showcase-frame': '',
    'data-video-state': 'paused',
    'data-video-muted': 'true',
}, vToggle, vMute, vReplay, video, popupsLayer);
const showcase = el('div', { 'data-showcase': '' }, showcaseFrame,
    el('div', {}, momentsReplay, momentsDismiss));

/* Knowledge section: source chips feed the connected-count badge. */
const sourceChips = ['Product info', 'FAQs', 'Policies'].map((label, i) => el('button', {
    'data-source-chip': '',
    'aria-pressed': i < 2 ? 'true' : 'false',
    text: label,
}));
const sourceCount = el('span', { 'data-source-count': '', text: '2 connected' });
const knowledge = el('section', { id: 'knowledge' }, sourceCount, ...sourceChips);

/* Platform section: tablist + panels + widget customizer controls. */
const tabIds = ['customize', 'analytics', 'workspaces', 'admin', 'integrations'];
const tabs = tabIds.map((id, i) => el('button', {
    role: 'tab',
    id: `tab-${id}`,
    'aria-selected': i === 0 ? 'true' : 'false',
    tabindex: i === 0 ? '0' : '-1',
}));
const platformPanels = tabIds.map((id, i) => el('div', {
    role: 'tabpanel',
    id: `panel-${id}`,
    'data-platform-panel': '',
    ...(i === 0 ? {} : { hidden: '' }),
}));

const accentHexes = ['#6366f1', '#06b6d4', '#10b981'];
const accents = accentHexes.map((hex, i) => el('button', {
    'data-accent': '',
    'data-accent-hex': hex,
    'aria-pressed': i === 0 ? 'true' : 'false',
}));
const faces = ['happy', 'wink', 'smug'].map((face, i) => el('button', {
    'data-face': face,
    'aria-pressed': i === 0 ? 'true' : 'false',
}));
const adminSwitches = [true, false].map((on) => el('button', {
    role: 'switch',
    'data-admin-switch': '',
    'aria-checked': on ? 'true' : 'false',
    text: 'toggle',
}));
const customAvatar = el('span', {
    'data-blobatar-name': 'DocuMind',
    'data-blobatar-background': 'circle',
    'data-custom-avatar': '',
});
const preview = el('div', { 'data-custom-preview': '' }, customAvatar);
preview.style.setProperty('--l-widget-accent', '#6366f1');

const platform = el('section', { id: 'platform', 'data-platform': '' },
    el('div', { role: 'tablist', 'aria-label': 'Platform areas' }, ...tabs),
    ...platformPanels);

platformPanels[0].append(...accents, ...faces, preview, ...adminSwitches);

const landing = el('div', { 'data-landing': '' },
    header, ...reveals, showcase, knowledge, platform, story, chatPreview,
    el('div', {}, replayBtn, demo));

body.append(landing);

/* ── Environment globals (set before boot) ─────────────────────────── */

const errors = [];
const clipboardWrites = [];
const store = new Map();

const document = {
    readyState: 'complete',
    visibilityState: 'visible',
    listeners: {},
    body,
    documentElement,
    createElement: (tag) => new El(tag),
    createElementNS: (_ns, tag) => new El(tag, _ns),
    querySelector: (selector) => queryAll(body, selector)[0] ?? null,
    querySelectorAll: (selector) => queryAll(body, selector),
    addEventListener(type, handler) {
        (this.listeners[type] ??= []).push(handler);
    },
    removeEventListener(type, handler) {
        this.listeners[type] = (this.listeners[type] ?? []).filter((l) => l !== handler);
    },
    dispatch(type, event = {}) {
        (this.listeners[type] ??= []).forEach((handler) => handler({ type, target: this, ...event }));
    },
};

const window = {
    location: { origin: 'http://localhost:8000', href: 'http://localhost:8000/' },
    addEventListener: () => {},
    removeEventListener: () => {},
    setTimeout: (fn, ms) => setTimeout(fn, ms),
    clearTimeout: (id) => clearTimeout(id),
    setInterval: () => 0,
    clearInterval: () => {},
    requestAnimationFrame: (fn) => setTimeout(() => fn(Date.now()), 0),
    cancelAnimationFrame: (id) => clearTimeout(id),
    matchMedia: (query) => ({
        /* Motion allowed, fine pointer available — the non-degraded paths. */
        matches: !query.includes('prefers-reduced-motion'),
        addEventListener: () => {},
        removeEventListener: () => {},
    }),
    localStorage: {
        getItem: (key) => (store.has(key) ? store.get(key) : null),
        setItem: (key, value) => store.set(key, String(value)),
        removeItem: (key) => store.delete(key),
    },
};

const navigator = {
    clipboard: {
        writeText: async (text) => {
            clipboardWrites.push(text);
        },
    },
};

const realError = console.error;
console.error = (...args) => {
    errors.push(args.map(String).join(' '));
};

Object.defineProperty(globalThis, 'document', { value: document, configurable: true, writable: true });
Object.defineProperty(globalThis, 'window', { value: window, configurable: true, writable: true });
Object.defineProperty(globalThis, 'localStorage', { value: window.localStorage, configurable: true, writable: true });
Object.defineProperty(globalThis, 'navigator', { value: navigator, configurable: true, writable: true });

/* ── Runner helpers ────────────────────────────────────────────────── */

const problems = [];
let okCount = 0;

const check = async (name, fn) => {
    try {
        await fn();
        okCount += 1;
        console.log(`  ok    ${name}`);
    } catch (error) {
        problems.push(name);
        console.log(`  FAIL  ${name}`);
        console.log(`        ${error.message}`);
    }
};

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const last = (node) => node.children[node.children.length - 1];

/* ── Boot the real module ──────────────────────────────────────────── */

try {
    await import('../../resources/js/app.js');
} catch (error) {
    console.error = realError;
    console.error(`FAIL — booting resources/js/app.js threw: ${error.message}`);
    process.exit(1);
}

/* ── Checks ────────────────────────────────────────────────────────── */

await check('initLanding boots without errors', () => {
    const landingErrors = errors.filter((line) => line.includes('initLanding') || line.includes('[documind]'));
    assert.deepEqual(landingErrors, []);
});

await check('reveals resolve immediately without IntersectionObserver', () => {
    for (const reveal of queryAll(landing, '.reveal')) {
        assert.ok(reveal.classList.contains('is-visible'), 'reveal never became visible');
    }
});

await check('mobile menu opens, closes on link, and on Escape', () => {
    menuToggle.click();
    assert.equal(menu.hidden, false);
    assert.equal(menuToggle.getAttribute('aria-expanded'), 'true');

    menu.dispatch('click', { target: menuLink });
    assert.equal(menu.hidden, true);
    assert.equal(menuToggle.getAttribute('aria-expanded'), 'false');

    menuToggle.click();
    document.dispatch('keydown', { key: 'Escape' });
    assert.equal(menu.hidden, true);
    assert.equal(menuToggle.getAttribute('aria-expanded'), 'false');
});

await check('theme toggle flips dark mode and persists the choice', () => {
    assert.equal(documentElement.classList.contains('dark'), false);
    document.dispatch('click', { target: themeToggle });
    assert.equal(documentElement.classList.contains('dark'), true);
    assert.equal(store.get('documind_theme'), 'dark');

    document.dispatch('click', { target: themeToggle });
    assert.equal(documentElement.classList.contains('dark'), false);
    assert.equal(store.get('documind_theme'), 'light');
});

await check('hero video autoplays muted when motion is allowed', () => {
    /* No IntersectionObserver in the stub, so the fallback plays at boot. */
    assert.equal(video.paused, false, 'video should be playing');
    assert.equal(video.muted, true, 'video should start muted');
    assert.equal(showcaseFrame.dataset.videoState, 'playing');
    assert.equal(showcaseFrame.dataset.videoMuted, 'true');
});

await check('video controls pause, resume, mute and replay', () => {
    vToggle.click();
    assert.equal(video.paused, true);
    assert.equal(showcaseFrame.dataset.videoState, 'paused');

    vToggle.click();
    assert.equal(video.paused, false);
    assert.equal(showcaseFrame.dataset.videoState, 'playing');

    vMute.click();
    assert.equal(video.muted, false);
    assert.equal(showcaseFrame.dataset.videoMuted, 'false');
    assert.equal(vMute.getAttribute('aria-label'), 'Mute the demo video');

    vMute.click();
    assert.equal(video.muted, true);
    assert.equal(showcaseFrame.dataset.videoMuted, 'true');
    assert.equal(vMute.getAttribute('aria-label'), 'Unmute the demo video');

    video.currentTime = 42;
    vReplay.click();
    assert.equal(video.currentTime, 0, 'replay did not rewind');
    assert.equal(video.paused, false, 'replay did not restart playback');
});

await check('floating moments cycle, close and replay', () => {
    assert.ok(popups[0].classList.contains('is-active'), 'first moment not active');
    assert.equal(popups[1].classList.contains('is-active'), false, 'two moments active at once');

    popupsLayer.dispatch('click', { target: popups[0].children[0] });
    assert.equal(popups[0].classList.contains('is-closed'), true, 'closed moment not dismissed');
    assert.ok(popups[1].classList.contains('is-active'), 'did not advance after close');

    momentsDismiss.click();
    assert.equal(popupsLayer.dataset.popupsState, 'off');
    assert.equal(popups[1].classList.contains('is-active'), false, 'dismiss left a moment up');

    momentsReplay.click();
    assert.equal(popupsLayer.dataset.popupsState, 'on');
    assert.ok(popups[0].classList.contains('is-active'), 'replay did not restart the cycle');
});

await check('knowledge chips toggle and recount connected sources', () => {
    assert.equal(sourceCount.textContent, '2 connected');

    sourceChips[2].click();
    assert.equal(sourceChips[2].getAttribute('aria-pressed'), 'true');
    assert.equal(sourceCount.textContent, '3 connected');

    sourceChips[0].click();
    assert.equal(sourceChips[0].getAttribute('aria-pressed'), 'false');
    assert.equal(sourceCount.textContent, '2 connected');
});

await check('platform tabs switch by click and arrow keys', () => {
    tabs[1].click();
    assert.equal(tabs[1].getAttribute('aria-selected'), 'true');
    assert.equal(tabs[0].getAttribute('aria-selected'), 'false');
    assert.equal(platformPanels[1].hidden, false, 'clicked panel stayed hidden');
    assert.equal(platformPanels[0].hidden, true, 'previous panel still shown');
    assert.equal(tabs[1].tabIndex, 0);
    assert.equal(tabs[0].tabIndex, -1);

    tabs[1].dispatch('keydown', { key: 'ArrowRight', preventDefault() {} });
    assert.equal(tabs[2].getAttribute('aria-selected'), 'true', 'ArrowRight did not advance');

    tabs[2].dispatch('keydown', { key: 'End', preventDefault() {} });
    assert.equal(tabs[4].getAttribute('aria-selected'), 'true', 'End did not jump to last tab');

    tabs[4].dispatch('keydown', { key: 'Home', preventDefault() {} });
    assert.equal(tabs[0].getAttribute('aria-selected'), 'true', 'Home did not jump to first tab');
    assert.ok(tabs[0].focused, 'keyboard activation did not focus the tab');
});

await check('accent swatches repaint the widget preview', () => {
    accents[1].click();
    assert.equal(preview.style.getPropertyValue('--l-widget-accent'), '#06b6d4');
    assert.equal(accents[1].getAttribute('aria-pressed'), 'true');
    assert.equal(accents[0].getAttribute('aria-pressed'), 'false');
});

await check('face buttons re-render the preview Blobatar', () => {
    faces[1].click();
    assert.equal(faces[1].getAttribute('aria-pressed'), 'true');
    assert.equal(faces[0].getAttribute('aria-pressed'), 'false');

    const image = customAvatar.children[0];
    assert.ok(image, 'preview avatar not rendered');
    assert.match(customAvatar.dataset.blobatarKey, /wink/, 'preview avatar did not re-render');
});

await check('admin switches flip aria-checked', () => {
    assert.equal(adminSwitches[0].getAttribute('aria-checked'), 'true');
    adminSwitches[0].click();
    assert.equal(adminSwitches[0].getAttribute('aria-checked'), 'false');

    adminSwitches[1].click();
    assert.equal(adminSwitches[1].getAttribute('aria-checked'), 'true');
});

await check('scroll story swaps panels and marks the active step', () => {
    assert.equal(steps.length, 5, 'setup flow should have five steps');
    assert.equal(panels.length, 5);

    steps[2].click();
    assert.equal(panels[2].dataset.visible, 'true');
    assert.equal(panels[0].dataset.visible, 'false');
    assert.equal(panels[0].getAttribute('aria-hidden'), 'true');
    assert.equal(panels[2].getAttribute('aria-hidden'), 'false');
    assert.equal(steps[2].getAttribute('aria-current'), 'step');
    assert.equal(steps[0].getAttribute('aria-current'), null);
});

await check('chat preview answers with sources and re-enables chips', async () => {
    qaChips[0].click();
    assert.equal(qaChips[0].disabled, true, 'chip not disabled while busy');
    assert.equal(qaChips[1].disabled, true);

    const userBubble = chatLog.children[1];
    assert.match(userBubble.textContent, /What is DocuMind\?/);
    const typing = chatLog.children[2];
    assert.equal(typing.getAttribute('aria-label'), 'Assistant is typing');

    const busyCount = chatLog.children.length;
    qaChips[1].click();
    assert.equal(chatLog.children.length, busyCount, 'second click queued a duplicate ask');

    await sleep(1100);
    assert.equal(chatLog.children.length, 3, 'typing row was not replaced by exactly one reply');
    const bot = chatLog.children[2];
    assert.match(bot.textContent, /approved documents/);
    assert.equal(bot.querySelectorAll('.l-src').length, 2, 'grounding pills missing');
    assert.equal(qaChips[0].disabled, false, 'chips stayed disabled after the reply');
});

await check('widget demo opens, closes, and updates aria state', () => {
    launcher.click();
    assert.equal(demo.dataset.open, 'true');
    assert.equal(launcher.getAttribute('aria-expanded'), 'true');
    assert.match(launcher.getAttribute('aria-label'), /Close/);

    launcher.click();
    assert.equal(demo.dataset.open, 'false');
    launcher.click();
    assert.equal(demo.dataset.open, 'true');
});

await check('launcher blobatar follows the cursor gaze', async () => {
    launcher.dispatch('pointermove', { clientX: 1000, clientY: 1000 });
    await sleep(30);
    assert.equal(
        launcherAvatar.style.transform,
        'translate3d(2.20px, 2.20px, 0)',
        `unexpected transform: ${launcherAvatar.style.transform}`,
    );
});

await check('hydrated launcher avatar uses the data-URI image fallback', () => {
    const image = launcherAvatar.children[0];
    assert.ok(image, 'launcher avatar never hydrated');
    assert.equal(image.getAttribute('data-blobatar-image'), 'true');
    assert.match(image.getAttribute('src'), /^data:image\/svg\+xml/);
});

await check('widget demo answers an in-scope question with sources', async () => {
    input.value = "What's included in Pro?";
    form.dispatch('submit', { preventDefault() {} });

    assert.equal(demoLog.children.length, 4, 'user bubble + typing row not appended');
    assert.match(demoLog.children[2].textContent, /What's included in Pro\?/);
    assert.equal(demoLog.children[3].getAttribute('aria-label'), 'Assistant is typing');
    assert.equal(demoLog.dataset.busy, 'true');

    await sleep(1050);
    assert.equal(demoLog.children.length, 4, 'reply bubble not appended');
    const bot = demoLog.children[3];
    assert.match(bot.textContent, /2,000 messages/);
    assert.equal(bot.querySelectorAll('.l-src').length, 2, 'grounding pills missing');
    assert.equal(demoLog.dataset.busy, 'false');
});

await check('widget demo falls back to the knowledge-gap reply', async () => {
    input.value = 'Do you ship to Mars?';
    form.dispatch('submit', { preventDefault() {} });
    await sleep(1050);
    assert.match(last(demoLog).textContent, /unanswered question/);
});

await check('Talk to a human escalates and confirms handoff', async () => {
    demoLog.dispatch('click', { target: demoChips[2] });
    await sleep(1050);

    const handoffButton = demoLog.querySelector('[data-demo-handoff]');
    assert.ok(handoffButton, 'handoff card missing');
    demoLog.dispatch('click', { target: handoffButton });

    assert.match(last(demoLog).textContent, /human will reply/);
});

await check('replay resets the demo and cancels an in-flight reply', async () => {
    input.value = 'Another question';
    form.dispatch('submit', { preventDefault() {} });

    replayBtn.click();
    assert.equal(demo.dataset.open, 'false');
    assert.equal(input.value, '');

    const countAfterReplay = demoLog.children.length;
    await sleep(1050);
    assert.equal(
        demoLog.children.length,
        countAfterReplay,
        'ghost reply landed after replay — epoch guard failed',
    );
});

await check('copy button copies the snippet and restores its label', async () => {
    copyBtn.click();
    await sleep(20);

    assert.equal(clipboardWrites.length, 1, 'clipboard never written');
    assert.match(clipboardWrites[0], /widget\.js/);
    assert.equal(copyBtn.textContent, 'Copied!');

    await sleep(1650);
    assert.equal(copyBtn.textContent, 'Copy');
});

/* ── Summary ───────────────────────────────────────────────────────── */

console.error = realError;

if (problems.length > 0) {
    console.log('');
    console.log(`FAIL — ${problems.length} problem(s), ${okCount} passing`);
    process.exit(1);
}

console.log('');
console.log(`PASS — ${okCount} checks`);

/* The moments carousel runs an endless timer — exit rather than let it
   hold the process open after the checks are done. */
process.exit(0);
