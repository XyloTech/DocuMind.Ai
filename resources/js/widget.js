import { bindBlobatarGaze, setBlobatar } from './blobatar.js';
import motionCss from 'blobatar/motion.css?inline';
import gazeCss from 'blobatar/gaze.css?inline';

/**
 * DocuMind embeddable chat widget — Grok-style AI interface.
 *
 * One <script> tag, no build step on the customer's site.
 * Rendered inside a Shadow DOM root so styles never leak in or out.
 */
const STORAGE_VISITOR_KEY = 'documind_visitor';
const STORAGE_CONV_PREFIX = 'documind_conv_';
const STREAM_FRAME_MS = 45;
const CONFIG_REFRESH_MS = 30000;
/* Copy per `status` phase (App\Enums\AnswerPhase); the server sends each one at
   the moment that work starts, so the label reports progress, never guesses it. */
const PHASE_LABELS = {
    reading: 'Reading your question…',
    searching: 'Searching approved support knowledge…',
    preparing: 'Preparing an answer…',
};
/* The server pushes `status` immediately, so silence means a dead request. */
const FIRST_BYTE_TIMEOUT_MS = 15000;

let config = null;
let conversationId = null;
let streaming = false;
let controller = null;
let root = null;
let els = {};
let apiHost = '';
let siteKey = null;
let started = false;
let configRefreshTimer = null;
let configRefreshing = false;
let visibilityHandler = null;
let escapeHandler = null;
/**
 * Per-slot gaze disposers. Keyed by the slot element so a re-render stops the
 * old driver before its SVG detaches, and cleared wholesale on unmount.
 */
const avatarGazeCleanups = new Map();
let launcherTimers = [];
/**
 * Whether this visitor has already handed over (and consented to) their email.
 * This must be a real module binding: an undeclared assignment throws inside the
 * strict-mode bundle, which used to make showEmailGate() blow up and leave the
 * widget calling POST /conversations without an email — the validation error the
 * visitor then saw instead of the form.
 */
let emailCaptured = false;

// Guard the DOM reads so the bundle can also be imported by a server-side
// renderer (Next/Nuxt prerender) without throwing before any code runs.
const bootScript = typeof document === 'undefined'
    ? null
    : document.currentScript instanceof HTMLScriptElement
        ? document.currentScript
        : document.querySelector('script[data-site-key]');

function resolveOrigin(script) {
    if (!script || !script.src) {
        return '';
    }

    try {
        return new URL(script.src, window.location.href).origin;
    } catch {
        return '';
    }
}

function apiPath() {
    return `${apiHost}/api/widget`;
}

if (bootScript) {
    siteKey = bootScript.dataset.siteKey || null;
    apiHost = resolveOrigin(bootScript);
}

/**
 * The rim's gradient angle only sweeps smoothly when --dm-orbit is a *registered*
 * custom property. Unregistered it animates discretely, and a discrete jump from
 * 0deg to 360deg is a visual no-op for a conic gradient — which is exactly how
 * the rim ends up looking permanently static.
 *
 * Registration is document-scoped and an @property rule inside the shadow
 * stylesheet is ignored, so the widget's own stylesheet cannot declare it. This
 * injects it into the host document instead, where it applies to elements in the
 * shadow tree too.
 */
function registerOrbitProperty() {
    try {
        if (typeof document === 'undefined' || typeof document.createElement !== 'function') {
            return;
        }

        const host = document.head || document.documentElement;
        if (!host || typeof host.appendChild !== 'function') {
            return;
        }

        if (typeof document.getElementById === 'function' && document.getElementById('dm-orbit-property')) {
            return;
        }

        const style = document.createElement('style');
        style.id = 'dm-orbit-property';
        style.textContent = '@property --dm-orbit { syntax: "<angle>"; inherits: false; initial-value: 0deg; }';
        host.appendChild(style);
    } catch {
        /* Registration is an enhancement: without it the rim still renders, it
           just holds a static angle instead of sweeping. */
    }
}

registerOrbitProperty();

/**
 * The same registration problem as the rim, one family over: blobatar's motion
 * stylesheet animates its timing custom properties (--mo-bob, --mo-blink, …),
 * and an unregistered property interpolates discretely — every idle loop would
 * step instead of glide. `@property` is document-scoped, so the rules are
 * lifted out of the motion stylesheet and injected into the host document,
 * where they apply to the shadow tree's elements too. Idempotent per page.
 */
function registerBlobatarMotion() {
    try {
        if (typeof document === 'undefined' || typeof document.createElement !== 'function') {
            return;
        }

        const host = document.head || document.documentElement;
        if (!host || typeof host.appendChild !== 'function') {
            return;
        }

        if (typeof document.getElementById === 'function' && document.getElementById('dm-blobatar-motion')) {
            return;
        }

        const rules = `${motionCss}\n${gazeCss}`.match(/@property[^{}]*\{[^{}]*\}/g);
        if (!rules || rules.length === 0) {
            return;
        }

        const style = document.createElement('style');
        style.id = 'dm-blobatar-motion';
        style.textContent = rules.join('\n');
        host.appendChild(style);
    } catch {
        /* Registration is an enhancement: without it the blobs still render,
           they just snap between timing values instead of tweening them. */
    }
}

registerBlobatarMotion();

function visitorId() {
    let id = null;

    try {
        id = localStorage.getItem(STORAGE_VISITOR_KEY);
    } catch {
        id = null;
    }

    if (!id) {
        id = 'v_' + Math.random().toString(36).slice(2) + Date.now().toString(36);
        try {
            localStorage.setItem(STORAGE_VISITOR_KEY, id);
        } catch {
            /* private mode fallback */
        }
    }

    return id;
}

function getStoredConversation() {
    try {
        return localStorage.getItem(`${STORAGE_CONV_PREFIX}${siteKey}`);
    } catch {
        return null;
    }
}

function storeConversation(id) {
    try {
        if (id) {
            localStorage.setItem(`${STORAGE_CONV_PREFIX}${siteKey}`, id);
        } else {
            localStorage.removeItem(`${STORAGE_CONV_PREFIX}${siteKey}`);
        }
    } catch {
        /* storage restricted */
    }
}

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function parseMarkdown(md) {
    if (!md) return '';

    const codeBlocks = [];

    // Extract code blocks
    let text = md.replace(/```([a-zA-Z0-9_-]*)\s*([\s\S]*?)```/g, (match, lang, code) => {
        const index = codeBlocks.length;
        const displayLang = (lang || 'code').toLowerCase();
        const safeCode = escapeHtml(code.trim());
        const encoded = encodeURIComponent(code.trim());
        codeBlocks.push(`
            <div class="code-block">
                <div class="code-header">
                    <span>${displayLang}</span>
                    <button type="button" class="code-copy" data-code-raw="${encoded}">Copy</button>
                </div>
                <pre><code>${safeCode}</code></pre>
            </div>
        `);
        return `@@CODE_${index}@@`;
    });

    text = escapeHtml(text);

    // Headers
    text = text.replace(/^### (.*$)/gim, '<div class="h3">$1</div>');
    text = text.replace(/^## (.*$)/gim, '<div class="h2">$1</div>');
    text = text.replace(/^# (.*$)/gim, '<div class="h1">$1</div>');

    // Bold, italic, strikethrough
    text = text.replace(/\*\*\*(.*?)\*\*\*/g, '<strong><em>$1</em></strong>');
    text = text.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
    text = text.replace(/__(.*?)__/g, '<strong>$1</strong>');
    text = text.replace(/\*(.*?)\*/g, '<em>$1</em>');
    text = text.replace(/_(.*?)_/g, '<em>$1</em>');
    text = text.replace(/~~(.*?)~~/g, '<del>$1</del>');

    // Inline code
    text = text.replace(/`([^`]+)`/g, '<code class="inline-code">$1</code>');

    // Links
    text = text.replace(/\[([^\]]+)\]\((https?:\/\/[^\s\)]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>');

    // Lists
    text = text.replace(/^[\*\-] (.*$)/gim, '<ul><li>$1</li></ul>');
    text = text.replace(/<\/ul>\s?<ul>/g, '');

    text = text.replace(/^\d+\. (.*$)/gim, '<ol><li>$1</li></ol>');
    text = text.replace(/<\/ol>\s?<ol>/g, '');

    // Paragraphs
    const paragraphs = text.split(/\n\n+/);
    text = paragraphs.map(p => {
        p = p.trim();
        if (!p) return '';
        if (p.startsWith('<div') || p.startsWith('<ul>') || p.startsWith('<ol>') || p.startsWith('@@CODE_')) {
            return p;
        }
        return `<p>${p.replace(/\n/g, '<br>')}</p>`;
    }).join('');

    // Restore code blocks. The replacer must be a function: with a string
    // replacement, `$&` and `$1` inside the user's own code would be expanded.
    codeBlocks.forEach((block, index) => {
        text = text.replace(`@@CODE_${index}@@`, () => block);
    });

    return text;
}

function styleSheet(accent, theme = 'dark') {
    const accentRgb = /^#([\da-f]{2})([\da-f]{2})([\da-f]{2})$/i.exec(accent);
    const luminance = accentRgb
        ? accentRgb.slice(1).map((channel) => {
            const value = parseInt(channel, 16) / 255;
            return value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
        }).reduce((total, value, index) => total + value * [0.2126, 0.7152, 0.0722][index], 0)
        : 0;
    const accentText = luminance > 0.42 ? '#10131a' : '#ffffff';
    const lightThemeRules = `
.panel { background: #ffffff; color: #172033; box-shadow: 0 22px 60px rgba(15, 23, 42, 0.2), 0 0 0 1px #dce3ed; }
    .email-gate { background: #f6f8fb; }
    .email-gate h2 { color: #172033; }
    .email-gate p { color: #475569; }
    .email-gate label { color: #334155; }
    .email-gate input[type="email"] { border-color: #94a3b8; background: #ffffff; color: #172033; }
    .email-error { color: #9f1239 !important; }
.header, .composer-dock { background: #ffffff; border-color: #e2e8f0; }
.launcher-hint { background: #ffffff; color: #172033; border-color: #dce3ed; box-shadow: 0 12px 34px rgba(15, 23, 42, 0.18); }
.unread-badge { border-color: #ffffff; }
.header .title, .msg.bot strong { color: #172033; }
.header .status, .header .btn-icon, .action-btn { color: #586579; }
.header .btn-icon:hover, .action-btn:hover { background: #eef2f7; color: #172033; }
.log, .suggestions { background: #f6f8fb; scrollbar-color: #cbd5e1 transparent; }
.msg { color: #263247; }
.msg.user { background: #e8edf5; border-color: #d5deea; }
.msg.bot { background: #ffffff; border-color: #e2e8f0; }
.msg-avatar { background: #ffffff; border-color: #e2e8f0; }
.msg.error { background: #fff1f2; border-color: #fecdd3; color: #9f1239; }
.msg.bot .inline-code { background: #eef2f7; color: #be123c; }
.msg.bot a { color: #1d4ed8; }
.code-block { background: #f8fafc; border-color: #dbe3ed; }
.code-header { background: #eef2f7; color: #536176; }
.code-copy { color: #536176; }
.code-copy:hover { color: #172033; }
.code-block pre { color: #253149; }
.action-btn { border-color: #dbe3ed; }
.action-btn.active-up { color: #047857; }
.action-btn.active-down { color: #be123c; }
.error-hint { color: #634615; background: #fff8e8; border-color: #f1d69d; }
.typing, .suggestion { background: #ffffff; color: #334155; }
.typing i { background: ${accent}; }
.retry-btn { background: #ffffff; border-color: #dbe3ed; color: #334155; }
.retry-btn:hover { background: #eef2f7; color: #172033; }
.suggestion { border-color: #dbe3ed; }
.suggestion:hover { color: #172033; background: color-mix(in srgb, ${accent} 9%, #ffffff); }
.composer textarea { background: #ffffff; color: #172033; border-color: #d3dce8; }
.composer textarea::placeholder { color: #718096; }
.quota-banner { color: #784b08; background: #fff8e8; border-color: #f1d69d; }
`;
    const themeRules = theme === 'light'
        ? lightThemeRules
        : theme === 'system'
            ? `@media (prefers-color-scheme: light) { ${lightThemeRules} }`
            : '';

    return `
:host { all: initial; }
* { box-sizing: border-box; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; margin: 0; padding: 0; }
button:focus-visible, textarea:focus-visible { outline: 3px solid ${accent}; outline-offset: 3px; }
button:disabled { cursor: not-allowed; opacity: 0.58; }
.header .btn-icon, .action-btn, .suggestion, .code-copy { min-width: 40px; min-height: 40px; }

.root { position: fixed; bottom: 24px; z-index: 2147483000; font-size: 14px; }
.root.left { left: 24px; }
.root.right { right: 24px; }

/* Entrance choreography: one calm pass when the widget first paints, chosen by
   the owner in Widget settings. The class is dropped 1.4s after mount so a later
   config change never replays it. */
.root.enter .launcher { animation: launcher-in-fade 0.55s cubic-bezier(0.16, 1, 0.3, 1) both; }
.root.enter.anim-pulse .launcher { animation-name: launcher-in-pulse; }
.root.enter.anim-bounce .launcher { animation-name: launcher-in-bounce; }
.root.enter.anim-fade .launcher { animation-name: launcher-in-fade; }
@keyframes launcher-in-pulse {
    0% { opacity: 0; transform: scale(0.55); }
    60% { opacity: 1; transform: scale(1.08); }
    100% { opacity: 1; transform: scale(1); }
}
@keyframes launcher-in-bounce {
    0% { opacity: 0; transform: translateY(30px); }
    55% { opacity: 1; transform: translateY(-7px); }
    78% { transform: translateY(3px); }
    100% { opacity: 1; transform: translateY(0); }
}
@keyframes launcher-in-fade {
    0% { opacity: 0; transform: translateY(10px); }
    100% { opacity: 1; transform: translateY(0); }
}

/* Grok-style floating launcher button */
.launcher {
    width: 60px; height: 60px; border-radius: 9999px; border: 0; cursor: pointer;
    background: ${accent}; color: ${accentText}; display: grid; place-items: center;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.4), 0 0 0 1px rgba(255, 255, 255, 0.12);
    transition: transform 0.2s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.2s ease;
    position: relative; outline: none;
}
.launcher::before {
    content: ''; position: absolute; inset: -5px; border: 1px solid ${accent};
    border-radius: inherit; pointer-events: none; opacity: 0;
}
/* Attention is a short first-load greeting, not a permanent distraction: the
   ring pulses three times and then the launcher settles. */
.launcher.attention::before { animation: launcher-pulse 2.6s ease-out 3; }
.launcher:hover { transform: scale(1.05); box-shadow: 0 14px 40px rgba(0, 0, 0, 0.5), 0 0 0 2px ${accent}; }
.launcher:active { transform: scale(0.97); }
.launcher:focus-visible { outline: 3px solid ${accent}; outline-offset: 4px; }
.launcher svg { width: 26px; height: 26px; transition: transform 0.2s ease; }
.launcher .close { display: none; }
/* The blobatar is the launcher's face. The glyph icon stays in the DOM as the
   fallback for a failed render (.no-blob); both hide while the panel is open,
   so grid centring only ever sees one visible child. */
.launcher .launcher-blob {
    width: 46px; height: 46px; border-radius: 9999px; overflow: hidden;
    display: grid; place-items: center;
}
.launcher .launcher-blob svg, .launcher .launcher-blob img {
    width: 100%; height: 100%; display: block; border-radius: inherit;
}
.launcher .chat { display: none; }
.launcher.no-blob .chat { display: block; }
.launcher.no-blob .launcher-blob { display: none; }
@keyframes launcher-pulse {
    from { opacity: 0.55; transform: scale(0.88); }
    to { opacity: 0; transform: scale(1.35); }
}
/* The open class lives on the host (toggle() sets it there, and the focus-steal
   check reads it from there), but this stylesheet runs inside the shadow root,
   and a descendant combinator never crosses the shadow boundary: a selector of
   the form ".root.open .panel" asks for an ancestor *inside* the shadow tree
   that carries the class, so it matched nothing and the panel stayed
   display:none forever. :host() is the only selector that reaches the host. */
:host(.open) .launcher .chat { display: none; }
:host(.open) .launcher .close { display: block; }
:host(.open) .launcher-hint { display: none; }

/* "Need help?" label beside the bubble. Decorative and pointer-transparent, so
   it can never swallow a click meant for the host page. */
.launcher-hint {
    position: absolute; bottom: 10px; display: flex; align-items: center; gap: 8px;
    padding: 7px 14px 7px 7px; border-radius: 9999px; max-width: calc(100vw - 120px);
    background: #12151f; color: #f1f5f9; border: 1px solid rgba(255, 255, 255, 0.14);
    box-shadow: 0 12px 34px rgba(0, 0, 0, 0.45);
    font-size: 13px; font-weight: 650; line-height: 1.2; white-space: nowrap;
    opacity: 0; transform: translateY(8px) scale(0.94); pointer-events: none;
    transition: opacity 0.35s ease, transform 0.4s cubic-bezier(0.16, 1, 0.3, 1);
}
.root.right .launcher-hint { right: 72px; }
.root.left .launcher-hint { left: 72px; }
.launcher-hint.show { opacity: 1; transform: translateY(0) scale(1); }
.launcher-hint-avatar {
    width: 26px; height: 26px; flex: 0 0 auto; border-radius: 9999px; overflow: hidden;
    display: grid; place-items: center; background: ${accent}; color: ${accentText};
    box-shadow: 0 0 0 2px rgba(255, 255, 255, 0.12);
}
.launcher-hint-avatar img, .launcher-hint-avatar svg { width: 100%; height: 100%; display: block; object-fit: cover; }

.unread-badge {
    position: absolute; top: -2px; right: -2px; width: 14px; height: 14px;
    background: ${accent}; border: 2px solid #090a0f; border-radius: 9999px;
}

/* Grok-style Chat Panel */
.panel {
    position: absolute; bottom: 76px; width: 400px; max-width: calc(100vw - 32px);
    height: 600px; max-height: calc(100vh - 120px); display: none; flex-direction: column;
    background: #0d0f15; color: #f1f5f9; border-radius: 20px; overflow: hidden;
    box-shadow: 0 25px 70px rgba(0, 0, 0, 0.6), 0 0 0 1px rgba(255, 255, 255, 0.1);
    animation: panel-rise 0.25s cubic-bezier(0.16, 1, 0.3, 1);
}
.email-gate {
    display: none; flex: 1; flex-direction: column; justify-content: center; gap: 14px;
    overflow-y: auto; padding: 24px; background: #090a0f;
}
.email-gate h2 { color: #f8fafc; font-size: 18px; line-height: 1.3; font-weight: 700; }
.email-gate p { color: #cbd5e1; font-size: 13px; line-height: 1.6; }
.email-gate label { color: #e2e8f0; font-size: 12px; line-height: 1.5; }
.email-gate input[type="email"] {
    display: block; width: 100%; min-height: 44px; margin-top: 6px; padding: 10px 12px;
    border: 1px solid #566273; border-radius: 10px; background: #12151f; color: #f8fafc;
    font: inherit; font-size: 14px;
}
.email-gate input[type="email"]:focus-visible, .email-gate input[type="checkbox"]:focus-visible { outline: 3px solid ${accent}; outline-offset: 3px; }
.email-consent { display: flex; align-items: flex-start; gap: 9px; }
.email-consent input { width: 18px; height: 18px; flex: 0 0 auto; margin-top: 1px; accent-color: ${accent}; }
.email-error { min-height: 1.25em; color: #fda4af !important; }
.email-submit { min-height: 44px; border: 0; border-radius: 10px; padding: 10px 14px; background: ${accent}; color: ${accentText}; font: inherit; font-size: 14px; font-weight: 700; cursor: pointer; }
.email-submit:disabled { cursor: wait; opacity: 0.65; }
.root.left .panel { left: 0; right: auto; }
.root.right .panel { right: 0; left: auto; }
:host(.open) .panel { display: flex; }

@keyframes panel-rise {
    from { opacity: 0; transform: translateY(12px) scale(0.98); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}

@media (max-width: 480px) {
    .panel { position: fixed; inset: 0; width: 100%; max-width: 100%; height: 100dvh; max-height: 100dvh; border-radius: 0; }
    .msg-wrap, .msg-wrap.bot { max-width: 94%; }
    .header { padding: max(14px, env(safe-area-inset-top)) 16px 14px; }
    .composer-dock { padding-bottom: max(12px, env(safe-area-inset-bottom)); }

    /* The launcher is the only way to open the panel, so it must stay on
       screen. It is dropped while the panel is full-bleed so it cannot sit
       on top of the chat header. */
    .root { bottom: max(24px, env(safe-area-inset-bottom)); }
    :host(.open) .launcher { display: none; }
    .launcher-hint { display: none; }
}

/* Respect the host page's safe areas on notched devices. */
@supports (padding: env(safe-area-inset-bottom)) {
    .root { bottom: max(24px, env(safe-area-inset-bottom)); }
    .composer-dock { padding-bottom: max(12px, env(safe-area-inset-bottom)); }
}

/* Host pages that scroll-lock or zoom can still host the bubble safely. */
@media (max-height: 520px) and (min-width: 481px) {
    .panel { max-height: calc(100dvh - 100px); }
}

/* Header */
.header {
    display: flex; align-items: center; gap: 12px; padding: 14px 18px;
    background: #12151f; border-bottom: 1px solid rgba(255, 255, 255, 0.08);
}
.header .avatar {
    width: 34px; height: 34px; border-radius: 10px; background: ${accent};
    color: ${accentText}; display: grid; place-items: center; font-size: 13px; font-weight: 700;
    box-shadow: 0 2px 8px rgba(0,0,0,0.3);
}
.header .avatar img, .header .avatar svg { width: 100%; height: 100%; display: block; border-radius: 10px; object-fit: cover; }
.header .info { flex: 1; min-width: 0; }
.header .title {
    font-size: 14px; font-weight: 650; color: #fff; letter-spacing: -0.01em;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.header .status { font-size: 11px; color: #94a3b8; display: flex; align-items: center; gap: 5px; margin-top: 2px; }
.header .status-dot { width: 6px; height: 6px; border-radius: 50%; background: #10b981; }
.header .status-dot.offline { background: #fb7185; }
.header .status-dot.waiting { background: #f59e0b; }

.header .btn-icon {
    background: transparent; border: 0; color: #94a3b8; cursor: pointer;
    padding: 6px; border-radius: 8px; transition: all 0.15s ease;
}
.header .btn-icon:hover { background: rgba(255, 255, 255, 0.08); color: #fff; }

/* Log / Message Stream */
.log {
    flex: 1; overflow-y: auto; padding: 16px; display: flex; flex-direction: column;
    gap: 16px; background: #090a0f; scrollbar-width: thin; scrollbar-color: #222634 transparent;
}
.log::-webkit-scrollbar { width: 6px; }
.log::-webkit-scrollbar-thumb { background-color: #222634; border-radius: 9999px; }

/* Messages */
.msg-wrap { display: flex; flex-direction: column; max-width: 88%; animation: msg-fade 0.2s ease-out; }
.msg-wrap.user { align-self: flex-end; }
/* Bot rows: the companion face sits in a gutter beside a column stack, so
   feedback buttons, sources and notes align with the bubble — never float
   beside the avatar. */
.msg-wrap.bot { align-self: flex-start; max-width: 92%; flex-direction: row; align-items: flex-start; gap: 8px; }
.msg-stack { display: flex; flex-direction: column; min-width: 0; }
.msg-avatar {
    flex: 0 0 auto; width: 24px; height: 24px; border-radius: 9999px; overflow: hidden;
    display: grid; place-items: center; background: #12151f;
    border: 1px solid rgba(255, 255, 255, 0.1);
}
.msg-avatar img, .msg-avatar svg { width: 100%; height: 100%; display: block; }
.msg-wrap.typing-exit { animation: dm-typing-out 0.18s ease forwards; }

@keyframes msg-fade {
    from { opacity: 0; transform: translateY(6px); }
    to { opacity: 1; transform: translateY(0); }
}

.msg {
    padding: 11px 15px; border-radius: 16px; font-size: 14px; line-height: 1.6;
    word-break: break-word; color: #f1f5f9;
}
.msg.user { background: #1f2433; border: 1px solid rgba(255, 255, 255, 0.1); border-bottom-right-radius: 4px; }
.msg.bot { background: #12151f; border: 1px solid rgba(255, 255, 255, 0.08); border-bottom-left-radius: 4px; }
.msg.error { background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3); color: #fca5a5; }

/* Markdown inside Bot Messages */
.msg.bot p + p { margin-top: 8px; }
.msg.bot ul, .msg.bot ol { padding-left: 20px; margin: 6px 0; }
.msg.bot li { margin: 3px 0; }
.msg.bot .inline-code {
    background: rgba(255, 255, 255, 0.1); padding: 2px 5px; border-radius: 4px;
    font-size: 12px; font-family: monospace; color: #f43f5e;
}
.msg.bot a { color: #818cf8; text-decoration: underline; }
.msg.bot strong { color: #fff; font-weight: 650; }

.code-block {
    margin: 8px 0; border-radius: 10px; background: #07080b; border: 1px solid rgba(255, 255, 255, 0.1);
    overflow: hidden; font-family: monospace; font-size: 12px;
}
.code-header {
    display: flex; justify-content: space-between; align-items: center;
    padding: 5px 10px; background: rgba(255, 255, 255, 0.05); color: #94a3b8; font-size: 11px;
}
.code-copy { background: transparent; border: 0; color: #94a3b8; cursor: pointer; font-size: 11px; }
.code-copy:hover { color: #fff; }
.code-block pre { padding: 10px; overflow-x: auto; margin: 0; color: #e2e8f0; }

/* Sources */
.sources { margin-top: 8px; }
.knowledge-used { font-size: 10px; line-height: 1.4; color: #94a3b8; }

/* Bot message actions: Feedback (Thumbs) */
.bot-actions { display: flex; align-items: center; gap: 6px; margin-top: 6px; }
.bot-actions:empty { display: none; }
.action-btn {
    background: transparent; border: 1px solid rgba(255, 255, 255, 0.08); color: #94a3b8;
    border-radius: 9999px; padding: 4px; cursor: pointer;
    display: inline-flex; align-items: center; justify-content: center; transition: all 0.15s ease;
}
.action-btn svg { width: 14px; height: 14px; display: block; }
.action-btn:hover { background: rgba(255, 255, 255, 0.08); color: #fff; }
.action-btn.active-up { color: #10b981; border-color: rgba(16, 185, 129, 0.4); }
.action-btn.active-down { color: #f43f5e; border-color: rgba(244, 63, 94, 0.4); }

/* Inline notice under a bubble (truncated stream, connection problem) */
.error-hint {
    margin-top: 6px; font-size: 11px; line-height: 1.5; color: #94a3b8;
    background: rgba(245, 158, 11, 0.08); border: 1px solid rgba(245, 158, 11, 0.22);
    border-radius: 10px; padding: 8px 10px;
}

/* Typing indicator */
.typing { display: inline-flex; align-items: center; gap: 4px; padding: 12px 16px; background: #12151f; border-radius: 16px; width: fit-content; }
.typing.typing-exit { animation: dm-typing-out 0.18s ease forwards; }
@keyframes dm-typing-out { to { opacity: 0; transform: translateY(3px); } }
.typing i { width: 6px; height: 6px; border-radius: 50%; background: ${accent}; animation: bounce 1.1s infinite, dot-glow 1.1s infinite; }
.typing i:nth-child(2) { animation-delay: 0.15s; }
.typing i:nth-child(3) { animation-delay: 0.3s; }
/* The real phase the server announced; it replaces itself in place, never fakes motion. */
.typing-label { margin-left: 6px; font-size: 11px; line-height: 1.4; color: #94a3b8; animation: dm-status-in 0.2s ease; }
@keyframes dm-status-in { from { opacity: 0; transform: translateY(2px); } to { opacity: 1; transform: none; } }
/* The companion face joins in while the answer is being worked out. */
.msg-wrap.is-thinking .msg-avatar { animation: dm-avatar-think 1.8s ease-in-out infinite; }
@keyframes dm-avatar-think {
    0%, 100% { box-shadow: 0 0 0 0 ${accent}22; }
    50% { box-shadow: 0 0 12px 2px ${accent}66; }
}
@keyframes bounce { 0%, 60%, 100% { transform: translateY(0); opacity: .4; } 30% { transform: translateY(-4px); opacity: 1; } }
/* A soft neon halo so the indicator reads as "the model is working" at a glance. */
@keyframes dot-glow { 0%, 100% { box-shadow: 0 0 2px ${accent}55; } 30% { box-shadow: 0 0 10px ${accent}cc; } }
/* Without motion the three dots must still read as "working", so they hold a
   static brightness ramp instead of vanishing behind an animation kill switch. */
@media (prefers-reduced-motion: reduce) {
    .typing i { animation: none !important; transform: none; box-shadow: none; }
    .typing i:nth-child(1) { opacity: .85; }
    .typing i:nth-child(2) { opacity: .55; }
    .typing i:nth-child(3) { opacity: .3; }
    .root.enter .launcher { animation: none !important; }
    .launcher.attention::before { animation: none !important; }
    .launcher-hint { transition: none; }
    .panel { animation: none; }
}

/* Retry after a failed reply */
.retry-btn {
    margin-top: 8px; min-height: 34px; padding: 6px 14px; border-radius: 9999px;
    border: 1px solid rgba(255, 255, 255, 0.16); background: rgba(255, 255, 255, 0.06);
    color: #e2e8f0; font: inherit; font-size: 12px; font-weight: 650; cursor: pointer;
    transition: background 0.15s ease, color 0.15s ease;
}
.retry-btn:hover { background: rgba(255, 255, 255, 0.14); color: #fff; }

/* Suggestions */
.suggestions { display: flex; flex-wrap: wrap; gap: 6px; padding: 8px 16px 10px; background: #090a0f; }
.suggestion {
    font-size: 12px; color: #cbd5e1; background: #12151f; border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 9999px; padding: 6px 12px; cursor: pointer; text-align: left; transition: all 0.15s ease;
}
.suggestion:hover { border-color: ${accent}; color: #fff; background: rgba(99, 102, 241, 0.1); }

/* Composer Dock */
.composer-dock { padding: 12px; background: #12151f; border-top: 1px solid rgba(255, 255, 255, 0.08); }
.composer { position: relative; display: flex; align-items: flex-end; gap: 8px; isolation: isolate; }

/* --dm-orbit is animated below but deliberately not declared here. An @property
   rule inside this shadow stylesheet is ignored, so the registration is injected
   into the host document by registerOrbitProperty() instead, where it applies to
   shadow-tree elements as well. */

/* Travelling gradient rim, same idea as the app composer: one conic ramp of
   blue -> violet -> pink carrying a brightness wave that sweeps the whole
   perimeter. A narrow comet on a wide field is an ellipse under rotation, so it
   races along the long edges and stalls at the short ones; a full-circle wave
   keeps the rim lit and the motion legible everywhere. Masked to a 1.5px ring,
   and the ring must not be rotated: the mask travels with any transform, so on
   a wide field a rotating ring swings off the border box and is painted over by
   this element's own background, leaving only the static halo. The angle sweeps
   instead. */
.composer::before {
    content: ''; position: absolute; inset: -2px; z-index: -1; border-radius: 16px; padding: 2px;
    background: conic-gradient(
        from var(--dm-orbit, 0deg),
        rgb(96 165 250 / 0.18) 0deg,
        rgb(96 165 250 / 0.45) 40deg,
        rgb(129 140 248 / 0.75) 90deg,
        rgb(167 139 250 / 0.95) 135deg,
        rgb(216 130 180 / 1) 165deg,
        rgb(244 114 182 / 0.72) 200deg,
        rgb(236 72 153 / 0.4) 250deg,
        rgb(167 139 250 / 0.2) 300deg,
        rgb(96 165 250 / 0.18) 360deg
    );
    -webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
    -webkit-mask-composite: xor;
    mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
    mask-composite: exclude;
    opacity: 0; transform: scale(0.985); transition: opacity 0.4s ease, transform 0.4s ease; pointer-events: none;
}

/* Breathing ambient bloom behind the field. */
.composer::after {
    content: ''; position: absolute; inset: -12px; z-index: -2; border-radius: 26px;
    background: radial-gradient(58% 125% at 50% 100%, rgb(96 165 250 / 0.28), rgb(167 139 250 / 0.17) 45%, rgb(244 114 182 / 0.1) 68%, transparent 80%);
    filter: blur(8px); opacity: 0; transition: opacity 0.45s ease; pointer-events: none;
}
.composer:focus-within::before { opacity: 1; transform: scale(1); animation: dm-orbit 4s linear infinite; }
.composer:focus-within::after { opacity: 1; will-change: transform, opacity; animation: dm-bloom 3.4s ease-in-out infinite; }
.composer:focus-within { box-shadow: inset 0 0 0 1px rgba(167 139 250, 0.16), 0 0 0 1px rgba(167 139 250, 0.3), 0 0 16px -2px rgba(192 132 252, 0.4), 0 0 40px -8px rgba(236 72 153, 0.28); }

@keyframes dm-orbit { from { --dm-orbit: 0deg; } to { --dm-orbit: 360deg; } }
@keyframes dm-bloom {
    0%, 100% { opacity: 0.5; transform: scale(1); }
    50% { opacity: 0.85; transform: scale(1.05); }
}

.composer textarea {
    flex: 1; resize: none; max-height: 120px; min-height: 42px; padding: 10px 14px;
    font-size: 14px; line-height: 1.4; color: #fff; background: #090a0f;
    border: 1px solid rgba(255, 255, 255, 0.12); border-radius: 12px; outline: none;
    transition: border-color 0.25s ease;
}
.composer textarea:focus { border-color: rgba(167, 139, 250, 0.55); }
.composer textarea::placeholder { color: #a2adbc; }

.btn-send {
    width: 42px; height: 42px; border: 0; border-radius: 12px; background: #fff; color: #000;
    cursor: pointer; display: grid; place-items: center; transition: all 0.15s ease;
}
.btn-send:hover { background: #e2e8f0; }
.btn-send:disabled { opacity: 0.72; cursor: not-allowed; }

.btn-stop {
    width: 42px; height: 42px; border: 1px solid rgba(244, 63, 94, 0.3); border-radius: 12px;
    background: rgba(244, 63, 94, 0.1); color: #f43f5e; cursor: pointer; display: none; place-items: center;
}
.btn-stop:hover { background: rgba(244, 63, 94, 0.2); }

.quota-banner { padding: 8px 14px; font-size: 11px; color: #f59e0b; background: rgba(245, 158, 11, 0.1); border-top: 1px solid rgba(245, 158, 11, 0.2); }

${themeRules}

${motionCss}
${gazeCss}

@media (prefers-reduced-motion: reduce) { * { animation: none !important; transition: none !important; } }
`;
}

const SVG_NS = 'http://www.w3.org/2000/svg';

function brandIcon() {
    return icon(ICONS.brand, { size: 18, width: 2.2 });
}

function stopGlyph() {
    const svg = document.createElementNS(SVG_NS, 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('width', '14');
    svg.setAttribute('height', '14');
    svg.setAttribute('fill', 'currentColor');
    svg.setAttribute('aria-hidden', 'true');

    const rect = document.createElementNS(SVG_NS, 'rect');
    rect.setAttribute('x', '4');
    rect.setAttribute('y', '4');
    rect.setAttribute('width', '16');
    rect.setAttribute('height', '16');
    rect.setAttribute('rx', '2');
    svg.appendChild(rect);

    return svg;
}

/**
 * Every face the widget draws starts here: the owner's seed (or the
 * assistant's name when they left it on auto), size, palette, pose and motion
 * from Widget settings. Overrides are for one-off poses — the typing
 * indicator's "thinking" face, for example. Undefined overrides are dropped so
 * a caller never blanks out a configured value by accident.
 */
function avatarOptions(overrides = {}) {
    const blob = config?.blobatar || {};
    const clean = Object.fromEntries(
        Object.entries(overrides).filter(([, value]) => value !== undefined),
    );

    return {
        background: blob.background || 'squircle',
        hue: Number.isFinite(blob.hue) ? blob.hue : undefined,
        tone: Number.isFinite(blob.tone) ? blob.tone : undefined,
        expression: blob.expression || 'idle',
        animate: blob.animation || 'live',
        seed: blob.seed || config?.bot_name || 'Assistant',
        alt: '',
        ...clean,
    };
}

/** The owner's "avatar size" — header face, and the base for the smaller ones. */
function avatarSize() {
    const size = Number(config?.blobatar?.size);

    return Number.isFinite(size) ? Math.min(64, Math.max(24, Math.round(size))) : 40;
}

/** Message-row faces ride at roughly half the header size, clamped for layout. */
function messageAvatarSize() {
    return Math.max(20, Math.min(32, Math.round(avatarSize() * 0.55)));
}

function stopSlotGaze(slot) {
    avatarGazeCleanups.get(slot)?.();
    avatarGazeCleanups.delete(slot);
}

/**
 * Render `options` into `slot`, replacing the previous face only when the
 * seed or options actually changed — a 30s config poll must not re-parse the
 * whole transcript. With a `scope`, the eyes also track the cursor there;
 * `scope` is null for transient or decorative slots that should stay still.
 *
 * Returns false only if rendering failed outright, so the caller can fall back
 * to the glyph launcher.
 */
function mountAvatar(slot, scope, options) {
    if (!slot) return false;

    const key = `${options.seed}|${JSON.stringify(options)}`;
    if (slot.dataset.blobatarKey === key && slot.firstElementChild) return true;

    stopSlotGaze(slot);

    try {
        setBlobatar(slot, options.seed, options);
        if (scope) avatarGazeCleanups.set(slot, bindBlobatarGaze(scope, slot));

        return true;
    } catch {
        slot.replaceChildren();
        delete slot.dataset.blobatarKey;

        return false;
    }
}

/**
 * Header and hint faces honour the owner's uploaded logo (their explicit
 * branding), falling back to a blobatar if the URL 404s mid-session.
 */
function mountBrandedAvatar(slot, scope, options) {
    if (!slot) return false;

    if (!config?.logo_url) {
        return mountAvatar(slot, scope, options);
    }

    const key = `logo|${config.logo_url}`;
    if (slot.dataset.blobatarKey === key && slot.firstElementChild) return true;

    stopSlotGaze(slot);

    const img = document.createElement('img');
    img.setAttribute('src', config.logo_url);
    img.setAttribute('alt', options.alt || '');
    img.addEventListener('error', () => {
        if (slot.firstElementChild === img) mountAvatar(slot, scope, options);
    }, { once: true });

    slot.replaceChildren(img);
    slot.dataset.blobatarKey = key;
    if (scope) avatarGazeCleanups.set(slot, bindBlobatarGaze(scope, slot));

    return true;
}

function mountHeaderAvatar() {
    const avatar = els.avatar;
    if (!avatar) return;

    const size = avatarSize();
    avatar.style.width = `${size}px`;
    avatar.style.height = `${size}px`;
    mountBrandedAvatar(avatar, els.panel, avatarOptions({
        alt: `${config?.bot_name || 'Assistant'} avatar`,
    }));
}

/**
 * The standing face on the page. Sized from the owner's avatar size (clamped
 * to sit inside the 60px button), and it watches the cursor while the panel is
 * shut. If the blob cannot render at all, the glyph icon takes over.
 */
function mountLauncherAvatar() {
    if (!els.launcherBlob) return;

    const size = Math.min(52, Math.max(36, avatarSize()));
    els.launcherBlob.style.width = `${size}px`;
    els.launcherBlob.style.height = `${size}px`;
    els.launcher?.classList.toggle('no-blob', !mountAvatar(els.launcherBlob, els.launcher, avatarOptions()));
}

/**
 * Build an SVG icon from raw path/rect data.
 *
 * The widget used to assemble its whole shell from one innerHTML string. That
 * required a permissive CSP (`unsafe-inline`) on the host page, made the markup
 * impossible to unit test, and meant any future interpolation was one missing
 * escape away from an injection. Everything is now real DOM.
 */
function icon(paths, { size = 16, width = 2, className = '' } = {}) {
    const svg = document.createElementNS(SVG_NS, 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('width', String(size));
    svg.setAttribute('height', String(size));
    svg.setAttribute('fill', 'none');
    svg.setAttribute('stroke', 'currentColor');
    svg.setAttribute('stroke-width', String(width));
    svg.setAttribute('aria-hidden', 'true');

    if (className) {
        svg.setAttribute('class', className);
    }

    for (const d of paths) {
        const path = document.createElementNS(SVG_NS, 'path');
        path.setAttribute('d', d);
        svg.appendChild(path);
    }

    return svg;
}

const ICONS = {
    brand: ['M4 4h7v7H4z', 'M13 13h7v7h-7z', 'm4 20 16-16'],
    chat: ['M21 11.5a8.4 8.4 0 0 1-8.4 8.4 8.5 8.5 0 0 1-4-.98L3 20l1.08-4.64a8.4 8.4 0 1 1 16.92-3.86Z', 'M8 11h.01', 'M12 11h.01', 'M16 11h.01'],
    // A support headset: the universally read "a person will help you" mark.
    support: ['M4 14v-2a8 8 0 0 1 16 0v2', 'M4 13h3v6H6a2 2 0 0 1-2-2z', 'M17 13h3v6a2 2 0 0 1-2 2h-1z', 'M17 19v1a2 2 0 0 1-2 2h-3'],
    spark: ['m12 3 1.9 5.8L20 11l-6.1 2.2L12 19l-1.9-5.8L4 11l6.1-2.2L12 3Z', 'm19 14 1 2.5 2.5 1-2.5 1-1 2.5-1-2.5-2.5-1 2.5-1 1-2.5Z'],
    close: ['M6 18 18 6M6 6l12 12'],
    restart: ['M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8', 'M3 3v5h5'],
    chevron: ['m6 9 6 6 6-6'],
    send: ['M5 12h14M12 5l7 7-7 7'],
};

function launcherGlyph() {
    const iconKey = config?.launcher_icon || 'chat';
    const paths = ICONS[iconKey] || ICONS.chat;

    return icon(paths, { size: 26, width: 2.2, className: 'chat' });
}

/** The words beside the bubble — owner-editable, with a safe default. */
function launcherLabel() {
    const label = typeof config?.launcher_label === 'string' ? config.launcher_label.trim() : '';

    return (label || 'Need help?').slice(0, 40);
}

function el(tag, props = {}, children = []) {
    const node = document.createElement(tag);

    for (const [key, value] of Object.entries(props)) {
        if (value === null || value === undefined) continue;

        if (key === 'class') {
            node.className = value;
        } else if (key === 'text') {
            node.textContent = value;
        } else if (key === 'style') {
            node.style.display = value;
        } else {
            node.setAttribute(key, value);
        }
    }

    node.append(...children);

    return node;
}

/**
 * Render the launcher and panel immediately, with no network call involved.
 *
 * This used to run only *after* `POST /conversations` succeeded, so any
 * failure — wrong origin, paused site, offline visitor, blocked CORS — left
 * nothing on the page at all and the pasted snippet looked broken. Now the
 * shell always paints and connection problems are reported inside it.
 */
function build() {
    if (root) {
        return;
    }

    const accent = config?.accent_color || '#6366f1';
    const position = config?.position === 'bottom-left' ? 'left' : 'right';

    root = document.createElement('div');
    const widgetId = `documind-widget-${String(siteKey || 'default').replace(/[^a-zA-Z0-9_-]/g, '-')}`;
    root.id = widgetId;
    root.className = `dm-widget-root ${position}`;
    root.style.all = 'initial';
    root.style.position = 'fixed';
    root.style.bottom = 'max(24px, env(safe-area-inset-bottom))';
    root.style.zIndex = '2147483000';
    root.style.fontSize = '14px';
    root.style.width = '60px';
    root.style.height = '60px';
    root.style[position] = '24px';

    const shadow = root.attachShadow({ mode: 'open' });
    const style = document.createElement('style');
    style.textContent = styleSheet(accent, config?.theme || 'dark');

    const wrap = document.createElement('div');
    wrap.className = 'root ' + position;

    const label = launcherLabel();
    const animation = ['pulse', 'bounce', 'fade'].includes(config?.launcher_animation)
        ? config.launcher_animation
        : 'pulse';

    wrap.classList.add('enter', `anim-${animation}`);

    // The glyph stays in the DOM (and first, where the launcher-icon contract
    // expects it) as the no-JS-avatar fallback: CSS hides it unless the blob
    // fails to render or the panel is open.
    const launcherBlob = el('span', { class: 'launcher-blob' });

    const launcher = el('button', {
        class: 'launcher',
        type: 'button',
        'aria-label': `${label} — open chat support`,
        title: `${label} — chat with us`,
        'aria-controls': `${widgetId}-dialog`,
        'aria-expanded': 'false',
    }, [launcherGlyph(), icon(ICONS.close, { size: 26, width: 2.5, className: 'close' }), launcherBlob]);

    const hintText = el('span', { class: 'launcher-hint-text', text: label });
    const hintAvatar = el('span', { class: 'launcher-hint-avatar' });
    const hint = el('div', { class: 'launcher-hint', 'aria-hidden': 'true' }, [
        hintAvatar,
        hintText,
    ]);

    const avatar = el('div', { class: 'avatar' });

    const header = el('div', { class: 'header' }, [
        avatar,
        el('div', { class: 'info' }, [
            el('div', { class: 'title', text: config?.bot_name || 'Assistant' }),
            el('div', { class: 'status', role: 'status', 'aria-live': 'polite' }, [
                el('span', { class: 'status-dot' }),
                el('span', { text: 'Connecting…' }),
            ]),
        ]),
        el('button', {
            class: 'btn-icon',
            type: 'button',
            'aria-label': 'Restart conversation',
            title: 'New conversation',
            'data-restart': '',
        }, [icon(ICONS.restart)]),
        el('button', {
            class: 'btn-icon',
            type: 'button',
            'aria-label': 'Minimize chat',
            title: 'Minimize',
            'data-minimize': '',
        }, [icon(ICONS.chevron)]),
    ]);

    const emailInput = el('input', {
        type: 'email',
        name: 'email',
        required: '',
        autocomplete: 'email',
        maxlength: '190',
        placeholder: 'name@example.com',
        'aria-describedby': `${widgetId}-email-error`,
    });
    const consentInput = el('input', { type: 'checkbox', name: 'email_consent', required: '' });
    const emailError = el('p', {
        class: 'email-error',
        id: `${widgetId}-email-error`,
        role: 'alert',
        'aria-live': 'assertive',
    });
    const emailSubmit = el('button', { class: 'email-submit', type: 'submit', text: 'Continue to chat' });
    const emailGate = el('form', { class: 'email-gate', 'data-email-gate': '', novalidate: '' }, [
        el('div', {}, [
            el('h2', { text: 'Before we start' }),
            el('p', { text: 'Share your email so the support team can follow up on this conversation. It is stored with this chat and shown only to authorized team members.' }),
        ]),
        el('label', {}, [el('span', { text: 'Email address' }), emailInput]),
        el('label', { class: 'email-consent' }, [
            consentInput,
            el('span', { text: 'I agree to share my email with this support team for this conversation.' }),
        ]),
        emailError,
        emailSubmit,
    ]);
    emailGate.style.display = 'none';

    const log = el('div', { class: 'log', 'data-log': '', role: 'log', 'aria-live': 'polite', 'aria-relevant': 'additions', style: 'none' });
    const suggestions = el('div', { class: 'suggestions', 'data-suggestions': '', style: 'none' });
    const quota = el('div', { class: 'quota-banner', 'data-quota': '', style: 'none' });

    const input = el('textarea', {
        'data-input': '',
        rows: '1',
        maxlength: '1000',
        placeholder: 'Ask about a product, feature, or issue…',
        'aria-label': 'Message',
    });

    const stop = el('button', {
        class: 'btn-stop',
        type: 'button',
        'data-stop': '',
        'aria-label': 'Stop',
    }, [stopGlyph()]);

    const send = el('button', {
        class: 'btn-send',
        type: 'submit',
        'data-send': '',
        'aria-label': 'Send',
    }, [icon(ICONS.send)]);

    const form = el('form', { class: 'composer', 'data-composer': '' }, [input, stop, send]);
    const dock = el('div', { class: 'composer-dock', style: 'none' }, [form]);

    const panel = el('div', {
        id: `${widgetId}-dialog`,
        class: 'panel',
        role: 'dialog',
        'aria-label': config?.bot_name || 'AI Assistant',
    }, [header, emailGate, log, suggestions, quota, dock]);

    wrap.append(panel, hint, launcher);
    shadow.append(style, wrap);

    els = {
        root,
        style,
        panel,
        wrap,
        launcher,
        hint,
        hintText,
        log: panel.querySelector('[data-log]'),
        input: panel.querySelector('[data-input]'),
        send: panel.querySelector('[data-send]'),
        stop: panel.querySelector('[data-stop]'),
        form: panel.querySelector('[data-composer]'),
        suggestions: panel.querySelector('[data-suggestions]'),
        quota: panel.querySelector('[data-quota]'),
        minimize: panel.querySelector('[data-minimize]'),
        restart: panel.querySelector('[data-restart]'),
        status: panel.querySelector('.status'),
        emailGate,
        emailInput,
        consentInput,
        emailError,
        emailSubmit,
        avatar,
        hintAvatar,
        launcherBlob,
    };

    mountLauncherAvatar();
    mountBrandedAvatar(els.hintAvatar, null, avatarOptions());
    mountHeaderAvatar();

    launcher.addEventListener('click', (event) => {
        event.stopPropagation?.();
        toggle();
    });
    root.addEventListener('click', (event) => {
        // A click that starts inside the shadow tree is retargeted to the host,
        // so `event.target === root` is also true for every panel button. The
        // minimize button closed the panel and this listener then reopened it
        // in the same event, which is why it looked dead. composedPath()[0] is
        // the element the click actually landed on.
        if (event.composedPath()[0] !== root) return;

        if (!root.classList.contains('open')) {
            toggle();
        }
    });
    els.minimize.addEventListener('click', () => toggle());
    els.restart.addEventListener('click', () => resetConversation());

    els.form.addEventListener('submit', (event) => {
        event.preventDefault();
        sendMessage();
    });

    emailGate.addEventListener('submit', (event) => {
        event.preventDefault();
        submitEmailGate();
    });

    els.stop.addEventListener('click', () => {
        if (controller) controller.abort();
    });

    els.input.addEventListener('input', autosize);
    els.input.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            sendMessage();
        }
    });

    // Copy Code handler in shadow root
    shadow.addEventListener('click', async (event) => {
        const btn = event.target.closest('[data-code-raw]');
        if (!btn) return;
        const raw = decodeURIComponent(btn.dataset.codeRaw || '');
        try {
            await navigator.clipboard.writeText(raw);
            btn.textContent = 'Copied!';
            setTimeout(() => { btn.textContent = 'Copy'; }, 2000);
        } catch {
            /* ignore */
        }
    });

    // Feedback handler in shadow root
    shadow.addEventListener('click', async (event) => {
        const btn = event.target.closest('[data-feedback]');
        if (!btn || !conversationId) return;

        const helpful = btn.dataset.feedback === 'up';
        const msgId = btn.dataset.messageId;
        const parent = btn.parentElement;

        try {
            await fetch(`${apiPath()}/${siteKey}/conversations/${conversationId}/feedback`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({
                    message_id: Number(msgId),
                    helpful,
                    visitor_id: visitorId(),
                }),
            });

            parent.querySelectorAll('[data-feedback]').forEach(b => {
                b.classList.remove('active-up', 'active-down');
            });
            btn.classList.add(helpful ? 'active-up' : 'active-down');
        } catch {
            /* feedback is best-effort */
        }
    });

    document.body.append(root);

    // The entrance choreography runs once; dropping the class afterwards keeps
    // later re-renders (icon/label changes) from replaying it.
    launcherTimers.push(window.setTimeout(() => wrap.classList.remove('enter'), 1400));
}

/**
 * Apply everything the owner controls about the launcher — label, icon and
 * entrance animation — without rebuilding the widget.
 */
function applyLauncherConfig() {
    if (!root || !els?.launcher) return;

    const label = launcherLabel();
    const open = root.classList.contains('open');

    els.hintText.textContent = label;
    els.launcher.setAttribute('aria-label', open ? 'Close chat' : `${label} — open chat support`);
    els.launcher.setAttribute('title', `${label} — chat with us`);
    els.launcher.replaceChildren(launcherGlyph(), icon(ICONS.close, { size: 26, width: 2.5, className: 'close' }), els.launcherBlob);

    els.wrap.classList.remove('anim-pulse', 'anim-bounce', 'anim-fade');
    const animation = ['pulse', 'bounce', 'fade'].includes(config?.launcher_animation)
        ? config.launcher_animation
        : 'pulse';
    els.wrap.classList.add(`anim-${animation}`);
}

/**
 * First-load greeting: the bubble pulses and the label slides in, then the
 * ring settles — but the label itself stays beside the bubble so a first-time
 * visitor always knows the icon opens a chat. Reduced-motion visitors get the
 * label without the choreography (the shadow stylesheet disables animations).
 */
function startLauncherAttention() {
    if (!els.launcher || !els.hint) return;

    els.launcher.classList.add('attention');

    launcherTimers.push(window.setTimeout(() => els.launcher?.classList.remove('attention'), 8200));
    launcherTimers.push(window.setTimeout(() => {
        if (!root?.classList.contains('open')) els.hint?.classList.add('show');
    }, 700));
}

function stopLauncherAttention() {
    launcherTimers.forEach((timer) => window.clearTimeout(timer));
    launcherTimers = [];
    els.launcher?.classList.remove('attention');
    // The label stays visible while the panel is open-closed; :host(.open)
    // hides it in CSS, so it reappears on its own when the visitor minimises.
}

/**
 * Tell the owner's dashboard that the widget loaded, opened, or sent a
 * message. Deliberately fire-and-forget: engagement never blocks, delays or
 * breaks the chat, and no identifier beyond the local random visitor id is
 * sent.
 */
function reportEvent(type) {
    if (!siteKey || !type) return;

    const payload = { type, visitor_id: visitorId() };

    if (conversationId) {
        payload.conversation_id = conversationId;
    }

    try {
        fetch(`${apiPath()}/${siteKey}/events`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify(payload),
            keepalive: true,
        }).catch(() => {});
    } catch {
        /* engagement is best effort */
    }
}

function toggle() {
    const open = root.classList.toggle('open');
    const label = launcherLabel();

    if (open) {
        stopLauncherAttention();
        reportEvent('launcher_open');
    } else {
        els.hint?.classList.add('show');
        reportEvent('launcher_close');
    }

    els.launcher.setAttribute('aria-label', open ? 'Close chat' : `${label} — open chat support`);
    els.launcher.setAttribute('title', open ? 'Close chat' : `${label} — chat with us`);
    els.launcher.setAttribute('aria-expanded', open ? 'true' : 'false');

    if (open) {
        escapeHandler = (event) => {
            if (event.key === 'Escape' && root?.classList.contains('open')) {
                event.preventDefault();
                toggle();
            }
        };
        document.addEventListener('keydown', escapeHandler);
        if (els.emailGate.style.display !== 'none') {
            els.emailInput.focus();
        } else if (els.form.parentElement.style.display !== 'none') {
            els.input.focus();
        }
        scroll();
    } else {
        if (escapeHandler) {
            document.removeEventListener('keydown', escapeHandler);
            escapeHandler = null;
        }
        els.launcher.focus();
    }
}

/**
 * True while the site asks for an email and this visitor has not consented yet.
 * Every path that would open a conversation consults this first, so the gate
 * is shown proactively instead of being discovered through a 422.
 */
function emailGateNeeded() {
    return Boolean(config?.collect_email) && !emailCaptured;
}

/** The backend reports a missing email/consent as a 422 validation bag. */
function isEmailConsentFailure(body) {
    return Boolean(body?.errors?.email || body?.errors?.email_consent);
}

function showEmailGate() {
    emailCaptured = false;
    conversationId = null;
    storeConversation(null);
    els.emailGate.style.display = 'flex';
    els.restart.style.display = 'none';
    els.log.style.display = 'none';
    els.suggestions.style.display = 'none';
    els.quota.style.display = 'none';
    els.form.parentElement.style.display = 'none';
    setConnectionStatus('Email required', 'waiting');
    els.emailError.textContent = '';
    els.emailInput.removeAttribute('aria-invalid');
    reportEvent('email_gate_shown');
}

function showConversation() {
    els.emailGate.style.display = 'none';
    els.restart.style.display = '';
    els.log.style.display = 'flex';
    els.suggestions.style.display = 'flex';
    els.quota.style.display = 'none';
    els.form.parentElement.style.display = 'block';
}

async function submitEmailGate() {
    const email = els.emailInput.value.trim();
    els.emailError.textContent = '';
    els.emailInput.removeAttribute('aria-invalid');

    if (!els.emailInput.validity.valid) {
        els.emailInput.setAttribute('aria-invalid', 'true');
        els.emailError.textContent = 'Enter a valid email address, such as name@example.com.';
        els.emailInput.focus();

        return;
    }

    if (!els.consentInput.checked) {
        els.emailError.textContent = 'Confirm consent to continue.';
        els.consentInput.focus();

        return;
    }

    const originalLabel = els.emailSubmit.textContent;
    els.emailSubmit.disabled = true;
    els.emailSubmit.textContent = 'Starting chat…';
    setConnectionStatus('Saving consent…', 'waiting');

    try {
        const response = await fetch(`${apiPath()}/${siteKey}/conversations`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({
                visitor_id: visitorId(),
                email,
                email_consent: true,
            }),
        });
        const data = await response.json().catch(() => ({}));

        if (!response.ok) {
            const validationMessage = data.errors?.email?.[0] || data.errors?.email_consent?.[0];
            throw new Error(validationMessage || data.message || 'We could not save your details. Please try again.');
        }

        emailCaptured = data.email_captured === true;
        conversationId = data.conversation_id;
        applyConfig(data.config);
        storeConversation(conversationId);
        await restoreConversation(conversationId);
    } catch (error) {
        els.emailError.textContent = error.message || 'We could not start the chat. Check your connection and try again.';
        setConnectionStatus('Could not start chat', 'offline');
    } finally {
        els.emailSubmit.disabled = false;
        els.emailSubmit.textContent = originalLabel;
    }
}

function setConnectionStatus(label, state = 'online') {
    const status = els.status;
    const text = status?.children[1];
    const dot = status?.querySelector('.status-dot');

    if (text) text.textContent = label;
    if (dot) dot.className = `status-dot ${state}`;
}

function autosize() {
    els.input.style.height = 'auto';
    els.input.style.height = `${Math.min(els.input.scrollHeight, 120)}px`;
}

function scroll() {
    els.log.scrollTop = els.log.scrollHeight;
}

/**
 * The small companion face beside a bot row. Tagged `data-msg-avatar` with its
 * pose override so a config refresh can re-render it (a no-op when nothing
 * changed). Decorative: the message text carries the meaning.
 */
function messageAvatar(expression) {
    const slot = document.createElement('span');
    slot.className = 'msg-avatar';
    slot.dataset.msgAvatar = expression || '';
    slot.style.width = `${messageAvatarSize()}px`;
    slot.style.height = `${messageAvatarSize()}px`;
    mountAvatar(slot, null, avatarOptions(expression ? { expression } : undefined));

    return slot;
}

function addMessage(role, text, messageId = null, feedback = null) {
    const row = document.createElement('div');
    row.className = `msg-wrap ${role === 'user' ? 'user' : 'bot'}`;

    const node = document.createElement('div');
    node.className = `msg ${role === 'user' ? 'user' : 'bot'}`;

    // User rows are a single bubble; bot rows are avatar + a column stack that
    // keeps feedback, sources and notes aligned with the bubble — not with the
    // avatar gutter. `wrap` stays the append target for all of them.
    if (role === 'user') {
        node.textContent = text;
        row.append(node);
        els.log.append(row);
        scroll();

        return { row, wrap: row, node };
    }

    node.innerHTML = parseMarkdown(text);

    const stack = document.createElement('div');
    stack.className = 'msg-stack';
    stack.append(node);

    if (text.trim()) {
        if (messageId) {
            stack.append(feedbackActions(messageId, feedback));
        } else {
            const actions = document.createElement('div');
            actions.className = 'bot-actions';
            stack.append(actions);
        }
    }

    row.append(messageAvatar(), stack);
    els.log.append(row);
    scroll();

    return { row, wrap: stack, node };
}

function addGreetingMessage(text) {
    const greeting = addMessage('bot', text);
    greeting.row.dataset.initialGreeting = 'true';

    return greeting;
}

function addTyping() {
    const dots = ['i', 'i', 'i'].map(() => document.createElement('i'));
    const label = document.createElement('span');
    label.className = 'typing-label';
    label.textContent = PHASE_LABELS.reading;

    const node = el('div', { class: 'typing', 'aria-label': PHASE_LABELS.reading, 'aria-live': 'polite', role: 'status' }, [...dots, label]);
    const stack = el('div', { class: 'msg-stack' }, [node]);
    const row = el('div', { class: 'msg-wrap bot is-thinking' }, [messageAvatar('thinking'), stack]);

    els.log.append(row);
    scroll();

    return row;
}

/**
 * Adopt a `status` phase the server announced for this pending answer.
 */
function setTypingPhase(row, phase) {
    const text = PHASE_LABELS[phase];

    if (!text) return;

    const bubble = row?.querySelector?.('.typing');
    const label = bubble?.querySelector?.('.typing-label');

    if (!label || label.textContent === text) return;

    label.textContent = text;
    bubble.setAttribute('aria-label', text);
}

function dismissTyping(typing) {
    if (!typing?.isConnected || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        typing?.remove();

        return;
    }

    // The fade runs on the bubble and the row (avatar included) together; the
    // row is removed once it lands.
    typing.classList.add('typing-exit');
    typing.querySelector?.('.typing')?.classList.add('typing-exit');
    window.setTimeout(() => typing.remove(), 180);
}

// Citation popovers register a document-level dismiss handler while they are
// open. Tracked centrally so rebuilding the transcript (new message, error,
// reset) cannot leave handlers pointing at detached rows.
const sourceDismissers = new Set();

function clearSourceDismissers() {
    sourceDismissers.forEach((dismiss) => document.removeEventListener('click', dismiss, true));
    sourceDismissers.clear();
}

function renderSources(sources, container) {
    if (!Array.isArray(sources) || sources.length === 0) return;

    const row = document.createElement('div');
    row.className = 'sources knowledge-used';
    row.textContent = 'Answered from support knowledge';

    container.append(row);
    scroll();
}

function renderSuggestions(list) {
    els.suggestions.innerHTML = '';

    if (!Array.isArray(list) || list.length === 0) return;

    list.slice(0, 3).forEach((question) => {
        const button = document.createElement('button');
        button.className = 'suggestion';
        button.type = 'button';
        button.textContent = question;
        button.addEventListener('click', () => {
            els.input.value = question;
            autosize();
            reportEvent('suggestion_click');
            sendMessage();
        });
        els.suggestions.append(button);
    });
}

function showQuota(text) {
    els.quota.textContent = text;
    els.quota.style.display = 'block';
}

/**
 * Replace the transcript with a readable failure. Without this the visitor
 * stares at an empty bubble with no idea whether the widget is broken or
 * they are just waiting for a reply.
 */
function showConnectionError(message, detail) {
    if (!els.log) return;

    showConversation();
    setConnectionStatus('Offline', 'offline');

    clearSourceDismissers();
    els.log.innerHTML = '';

    const row = document.createElement('div');
    row.className = 'msg-wrap bot';

    const stack = document.createElement('div');
    stack.className = 'msg-stack';

    const node = document.createElement('div');
    node.className = 'msg bot error';
    node.textContent = message;

    const hint = document.createElement('div');
    hint.className = 'error-hint';
    hint.textContent = detail;

    stack.append(node, hint);
    row.append(messageAvatar(), stack);
    els.log.append(row);

    if (els.input) els.input.disabled = true;
    if (els.send) els.send.disabled = true;
}

function applyQuota(quota) {
    if (!quota || !quota.exhausted) return;

    showQuota('This assistant has reached its monthly message limit.');
    if (els.input) els.input.disabled = true;
    if (els.send) els.send.disabled = true;
}

function applyConfig(next) {
    if (!next) return;

    config = next;

    // Re-render the branding once the real config arrives. The accent colour
    // and bubble position both live in the stylesheet, so it is swapped too.
    if (!root || !els.panel) return;

    const title = els.panel.querySelector('.title');

    if (title) {
        title.textContent = next.bot_name || 'Assistant';
    }

    els.panel.setAttribute('aria-label', next.bot_name || 'AI Assistant');
    els.style.textContent = styleSheet(next.accent_color || '#6366f1', next.theme || 'dark');
    els.wrap.className = `root ${next.position === 'bottom-left' ? 'left' : 'right'}`;
    root.classList.toggle('left', next.position === 'bottom-left');
    root.classList.toggle('right', next.position !== 'bottom-left');
    root.style.left = next.position === 'bottom-left' ? '24px' : 'auto';
    root.style.right = next.position === 'bottom-left' ? 'auto' : '24px';
    applyLauncherConfig();

    mountLauncherAvatar();
    mountBrandedAvatar(els.hintAvatar, null, avatarOptions());
    mountHeaderAvatar();

    // Faces already in the transcript follow along (the mount is a no-op when
    // the seed and options did not change). `data-msg-avatar` carries the
    // slot's pose override — "" for plain messages, "thinking" while typing.
    els.log?.querySelectorAll('[data-msg-avatar]').forEach((slot) => {
        const expression = slot.dataset.msgAvatar || undefined;
        mountAvatar(slot, null, avatarOptions(expression ? { expression } : undefined));
    });

    const initialGreeting = els.log.children.length === 1
        ? els.log.firstElementChild
        : null;

    if (initialGreeting?.dataset.initialGreeting === 'true') {
        const message = initialGreeting.querySelector('.msg');

        if (message?.classList.contains('bot')) {
            message.innerHTML = parseMarkdown(next.greeting || 'Hi! I can help with the product. What do you need help with?');
            renderSuggestions(next.suggestions);
        }
    }
}

async function refreshConfig() {
    if (!root || !siteKey || configRefreshing) return;

    configRefreshing = true;

    try {
        const response = await fetch(`${apiPath()}/${siteKey}/config`, {
            headers: { Accept: 'application/json' },
            cache: 'no-store',
        });

        if (!response.ok || !root) return;

        const data = await response.json();

        if (data.config && JSON.stringify(data.config) !== JSON.stringify(config)) {
            const wasCollectingEmail = Boolean(config?.collect_email);
            applyConfig(data.config);

            if (data.config.collect_email && ! emailCaptured) {
                showEmailGate();
            } else if (wasCollectingEmail && ! data.config.collect_email && els.emailGate.style.display !== 'none') {
                showConversation();

                if (conversationId) {
                    await restoreConversation(conversationId);
                } else {
                    await startNewConversation();
                }
            }
        }
    } catch {
        // Configuration refresh is best-effort; an active conversation stays usable.
    } finally {
        configRefreshing = false;
    }
}

function scheduleConfigRefresh() {
    if (configRefreshTimer !== null) {
        window.clearTimeout(configRefreshTimer);
        configRefreshTimer = null;
    }

    if (!root || !started || !config) return;

    configRefreshTimer = window.setTimeout(async () => {
        configRefreshTimer = null;

        if (document.visibilityState === 'visible') {
            await refreshConfig();
        }

        scheduleConfigRefresh();
    }, CONFIG_REFRESH_MS);
}

async function resetConversation() {
    if (streaming) return;

    storeConversation(null);
    conversationId = null;
    clearSourceDismissers();
    els.log.innerHTML = '';

    if (els.input) els.input.disabled = false;
    if (els.send) els.send.disabled = false;

    if (emailGateNeeded()) {
        showEmailGate();

        return;
    }

    await startNewConversation();
}

async function startNewConversation() {
    // Opening a conversation without an email is a guaranteed 422 when the
    // site collects emails, so the form is shown up front instead.
    if (emailGateNeeded()) {
        showEmailGate();

        return;
    }

    try {
        const response = await fetch(`${apiPath()}/${siteKey}/conversations`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({ visitor_id: visitorId() }),
        });

        if (response.status === 404) {
            showConnectionError(
                'This assistant is not available.',
                'The site key is invalid, or the widget has been paused by its owner.',
            );
            return;
        }

        if (!response.ok) {
            const body = await response.json().catch(() => ({}));

            if (isEmailConsentFailure(body)) {
                showEmailGate();

                return;
            }

            showConnectionError(
                body.message || 'The assistant could not be started.',
                'Please try again in a moment.',
            );
            return;
        }

        const data = await response.json();
        conversationId = data.conversation_id;
        applyConfig(data.config);
        emailCaptured = data.email_captured === true;
        showConversation();
        setConnectionStatus('Online');

        storeConversation(conversationId);

        addGreetingMessage(config.greeting || 'Hi! I can help with the product. What do you need help with?');
        renderSuggestions(config.suggestions);
        applyQuota(data.quota);
    } catch {
        showConnectionError(
            'Could not reach the assistant.',
            'Check your internet connection and try again.',
        );
    }
}

async function restoreConversation(convId) {
    try {
        const response = await fetch(
            `${apiPath()}/${siteKey}/conversations/${convId}?visitor_id=${encodeURIComponent(visitorId())}`,
            { headers: { Accept: 'application/json' } },
        );

        if (!response.ok) {
            storeConversation(null);

            if (config?.collect_email) {
                conversationId = null;
                showEmailGate();
            } else {
                await startNewConversation();
            }

            return;
        }

        const data = await response.json();
        conversationId = convId;
        applyConfig(data.config);
        emailCaptured = data.email_captured === true;

        if (config.collect_email && ! emailCaptured) {
            showEmailGate();

            return;
        }

        showConversation();
        setConnectionStatus('Online');

        if (Array.isArray(data.messages) && data.messages.length > 0) {
            data.messages.forEach(msg => {
                const bubble = addMessage(msg.role, msg.content, msg.id, msg.feedback);
                if (msg.role !== 'user' && Array.isArray(msg.sources)) {
                    renderSources(msg.sources, bubble.wrap);
                }
            });
        } else {
            addGreetingMessage(config.greeting || 'Hi! I can help with the product. What do you need help with?');
        }

        renderSuggestions(config.suggestions);
        applyQuota(data.quota);
    } catch {
        if (emailGateNeeded()) {
            showEmailGate();
        } else {
            await startNewConversation();
        }
    }
}

async function resumeConsentedSession() {
    try {
        const response = await fetch(`${apiPath()}/${siteKey}/conversations`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({ visitor_id: visitorId() }),
        });
        const data = await response.json().catch(() => ({}));

        if (response.ok) {
            conversationId = data.conversation_id;
            emailCaptured = data.email_captured === true;
            applyConfig(data.config);
            storeConversation(conversationId);
            await restoreConversation(conversationId);

            return;
        }

        if (response.status === 422 && (isEmailConsentFailure(data) || emailGateNeeded())) {
            showEmailGate();

            return;
        }

        showConnectionError('This assistant is not available.', data.message || 'Please try again in a moment.');
    } catch {
        showConnectionError('Could not reach the assistant.', 'Check your internet connection and try again.');
    }
}

async function start() {
    if (started) {
        return;
    }

    started = true;

    // Paint the shell before any network call so a failed request still shows
    // the launcher and an explanation rather than nothing at all.
    build();

    try {
        const response = await fetch(`${apiPath()}/${siteKey}/config`, {
            headers: { Accept: 'application/json' },
            cache: 'no-store',
        });

        if (!response.ok) {
            showConnectionError('This assistant is not available.', 'Check the site key and try again.');
        } else {
            const data = await response.json();
            applyConfig(data.config);

            // Only greet once the widget is known to be live: a paused site or
            // a bad key must not advertise help it cannot provide.
            startLauncherAttention();
            reportEvent('widget_loaded');

            const existing = getStoredConversation();

            if (existing) {
                await restoreConversation(existing);
            } else if (config.collect_email) {
                await resumeConsentedSession();
            } else {
                await startNewConversation();
            }
        }
    } catch {
        showConnectionError('Could not reach the assistant.', 'Check your internet connection and try again.');
    }

    visibilityHandler = () => {
        if (document.visibilityState === 'visible') {
            refreshConfig();
        }
    };
    document.addEventListener('visibilitychange', visibilityHandler);
    scheduleConfigRefresh();
}

const THUMB_UP = ['M7 10v12', 'M15 5.88 14 10h5.83a2 2 0 0 1 1.92 2.56l-2.33 8A2 2 0 0 1 17.5 22H4a2 2 0 0 1-2-2v-8a2 2 0 0 1 2-2h3'];
const THUMB_DOWN = ['M17 14V2', 'M9 18.12 10 14H4.17a2 2 0 0 1-1.92-2.56l2.33-8A2 2 0 0 1 6.5 2H20a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2h-3'];

function feedbackActions(messageId, state = null) {
    const actions = document.createElement('div');
    actions.className = 'bot-actions';

    const up = el('button', {
        class: `action-btn ${state === true ? 'active-up' : ''}`,
        type: 'button',
        'data-feedback': 'up',
        'data-message-id': String(messageId),
        title: 'Helpful',
        'aria-label': 'Helpful',
    }, [icon(THUMB_UP, { size: 14 })]);

    const down = el('button', {
        class: `action-btn ${state === false ? 'active-down' : ''}`,
        type: 'button',
        'data-feedback': 'down',
        'data-message-id': String(messageId),
        title: 'Not helpful',
        'aria-label': 'Not helpful',
    }, [icon(THUMB_DOWN, { size: 14 })]);

    actions.append(up, down);

    return actions;
}

async function sendMessage() {
    if (streaming || !conversationId) {
        return;
    }

    const text = els.input.value.trim();
    if (!text) return;

    streaming = true;
    controller = new AbortController();
    els.send.style.display = 'none';
    els.stop.style.display = 'grid';
    els.input.value = '';
    autosize();
    els.suggestions.innerHTML = '';

    const userWrap = addMessage('user', text);
    reportEvent('message_sent');

    const typing = addTyping();
    let assistantBubble = null;
    let accumulatedText = '';
    let sourcesList = null;
    let renderTimer = null;
    let completed = false;

    // Re-parsing the whole answer on every delta is O(n^2) and makes long
    // answers crawl; one render per frame is smooth and cheap enough.
    const scheduleRender = () => {
        if (renderTimer !== null) return;

        renderTimer = setTimeout(() => {
            renderTimer = null;

            if (!assistantBubble) return;

            assistantBubble.node.innerHTML = parseMarkdown(accumulatedText);
            scroll();
        }, STREAM_FRAME_MS);
    };

    // The server pushes `status` the moment the stream opens, so silence means
    // the request is dead rather than merely slow.
    let timedOut = false;
    let wentOffline = false;

    let watchdog = setTimeout(() => {
        timedOut = true;
        controller?.abort();
    }, FIRST_BYTE_TIMEOUT_MS);

    const handleOffline = () => {
        wentOffline = true;
        controller?.abort();
    };

    window.addEventListener('offline', handleOffline);

    try {
        const response = await fetch(
            `${apiPath()}/${siteKey}/conversations/${conversationId}/messages`,
            {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'text/event-stream' },
                body: JSON.stringify({ message: text, visitor_id: visitorId() }),
                signal: controller.signal,
            },
        );

        if (!response.ok || !response.body) {
            const body = await response.json().catch(() => ({}));

            // Consent was revoked or the session lost it: bring the gate back
            // rather than showing the visitor a dead-end error.
            if (response.status === 403 && emailGateNeeded()) {
                dismissTyping(typing);
                showEmailGate();

                return;
            }

            if (body.exhausted) {
                showQuota(body.message || 'This assistant has reached its monthly message limit.');
                if (els.input) els.input.disabled = true;
                if (els.send) els.send.disabled = true;
                return;
            }

            throw new Error(body.message || 'The assistant could not reply.');
        }

        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';

        const handle = (frame) => {
            let event = 'message';
            let data = '';

            frame.split(/\r?\n/).forEach((line) => {
                if (line.startsWith('event:')) event = line.slice(6).trim();
                else if (line.startsWith('data:')) {
                    const chunk = line.slice(5).replace(/^ /, '');
                    data += data === '' ? chunk : `\n${chunk}`;
                }
            });

            if (!data) return;

            let payload;

            try {
                payload = JSON.parse(data);
            } catch {
                return;
            }

            if (event === 'status' && typeof payload.phase === 'string') {
                setTypingPhase(typing, payload.phase);
                return;
            }

            if (event === 'delta' && typeof payload.text === 'string') {
                if (accumulatedText === '') {
                    // Keep the typing indicator up until real content arrives,
                    // otherwise the bubble sits blank for the whole wait.
                    dismissTyping(typing);
                    assistantBubble = addMessage('bot', '');
                }

                accumulatedText += payload.text;
                scheduleRender();

                return;
            }

            if (event === 'sources') {
                sourcesList = Array.isArray(payload.sources) ? payload.sources : [];
                return;
            }

            if (event === 'done') {
                completed = true;

                if (assistantBubble && payload.id) {
                    assistantBubble.wrap.append(feedbackActions(payload.id));
                }

                return;
            }

            if (event === 'error') {
                throw new Error(payload.message || 'Something went wrong.');
            }
        };

        for (;;) {
            const { value, done } = await reader.read();

            if (done) break;

            if (value && value.length > 0) {
                // The stream is alive: stop watching for the first byte.
                clearTimeout(watchdog);
                watchdog = null;
            }

            buffer += decoder.decode(value, { stream: true });

            // A proxy can rewrite line endings, so match both forms.
            let match;

            while ((match = buffer.match(/\r?\n\r?\n/)) !== null) {
                handle(buffer.slice(0, match.index));
                buffer = buffer.slice(match.index + match[0].length);
            }
        }

        // The answer is final (even an empty one), so the dots must not stay.
        dismissTyping(typing);

        if (renderTimer !== null) {
            clearTimeout(renderTimer);
            renderTimer = null;
        }

        if (accumulatedText && assistantBubble) {
            assistantBubble.node.innerHTML = parseMarkdown(accumulatedText);
        } else if (!assistantBubble) {
            assistantBubble = addMessage('bot', '');
        }

        if (sourcesList && assistantBubble) {
            renderSources(sourcesList, assistantBubble.wrap);
        }

        if (!completed) {
            showInlineNote(
                assistantBubble,
                'This reply was cut off before it finished. Please ask again.',
            );
        }
    } catch (error) {
        if (renderTimer !== null) {
            clearTimeout(renderTimer);
            renderTimer = null;
        }

        dismissTyping(typing);

        if (timedOut || wentOffline) {
            // Aborted on purpose: the server keeps generating with the socket
            // gone, so say what happened instead of showing a dead panel.
            if (!assistantBubble) {
                assistantBubble = addMessage('bot', '');
            }

            assistantBubble.node.classList.add('error');
            assistantBubble.node.textContent = timedOut
                ? 'The assistant did not respond in time.'
                : 'You went offline before the reply arrived.';

            showInlineNote(assistantBubble, 'The answer may still be saved — reopen the chat to check.');
        } else if (error && error.name === 'AbortError') {
            if (assistantBubble) {
                assistantBubble.node.innerHTML += '<br><em>(Stopped)</em>';
            }
        } else {
            if (!assistantBubble) {
                assistantBubble = addMessage('bot', '');
            }

            assistantBubble.node.classList.add('error');
            assistantBubble.node.textContent =
                error && error.message ? error.message : 'Something went wrong. Please try again.';

            // Nothing streamed back, so the visitor's question can simply be
            // sent again rather than leaving them to retype it.
            if (accumulatedText === '') {
                addRetryAction(userWrap, assistantBubble.wrap, text);
            }
        }
    } finally {
        clearTimeout(watchdog);
        window.removeEventListener('offline', handleOffline);
        streaming = false;
        controller = null;
        els.send.style.display = 'grid';
        els.stop.style.display = 'none';

        // Only steal focus while the panel is actually open, otherwise an
        // embedded widget yanks the cursor out of the host page's own form.
        if (root && root.classList.contains('open') && els.input && !els.input.disabled) {
            els.input.focus();
        }

        scroll();
    }
}

function showInlineNote(bubble, text) {
    if (!bubble) return;

    const note = document.createElement('div');
    note.className = 'error-hint';
    note.textContent = text;
    bubble.wrap.append(note);
}

/**
 * A "Try again" affordance under a failed reply. It removes the failed pair of
 * bubbles and replays the question, so a blip costs the visitor nothing.
 */
function addRetryAction(userWrap, errorWrap, text) {
    if (!errorWrap || typeof text !== 'string' || text === '') return;

    const button = el('button', { class: 'retry-btn', type: 'button', text: 'Try again' });

    button.addEventListener('click', () => {
        userWrap?.remove();
        // The failed pair lives in a row (avatar + stack); remove the row, not
        // just the stack the button hangs in, or the avatar is left behind.
        (errorWrap.closest?.('.msg-wrap') || errorWrap).remove();
        els.input.value = text;
        autosize();
        sendMessage();
    });

    errorWrap.append(button);
}

/**
 * Public API for frameworks that mount the bundle themselves.
 *
 * The auto-boot path needs nothing from the customer; this exists for React,
 * Next.js, Vue, Angular and Svelte apps that would rather control mounting
 * (StrictMode double-effects, SSR guards, lazy hydration) than let a script
 * tag decide for them.
 */
function mount(options = {}) {
    const key = options.siteKey || siteKey;

    if (!key) {
        return Promise.reject(new Error('DocuMindWidget: a siteKey is required.'));
    }

    siteKey = key;

    if (options.apiHost) {
        apiHost = String(options.apiHost).replace(/\/+$/, '');
    } else if (!apiHost) {
        apiHost = window.location.origin;
    }

    if (options.config) {
        applyConfig(options.config);
    }

    return start();
}

function unmount() {
    clearSourceDismissers();
    avatarGazeCleanups.forEach((cleanup) => cleanup());
    avatarGazeCleanups.clear();
    stopLauncherAttention();
    if (configRefreshTimer !== null) {
        window.clearTimeout(configRefreshTimer);
        configRefreshTimer = null;
    }
    if (visibilityHandler) {
        document.removeEventListener('visibilitychange', visibilityHandler);
        visibilityHandler = null;
    }
    if (escapeHandler) {
        document.removeEventListener('keydown', escapeHandler);
        escapeHandler = null;
    }
    root?.remove();
    root = null;
    els = {};
    conversationId = null;
    started = false;
    streaming = false;
    controller?.abort();
    controller = null;
    configRefreshing = false;
}

const widgetApi = { mount, unmount, version: '1.0.0' };

// Expose the API without triggering the auto-boot, so a manual mount and the
// script-tag path can never both run. A second copy of the bundle (a snippet
// pasted twice) must not clobber the API the host page already holds.
if (typeof window !== 'undefined') {
    if (window.DocuMindWidget) {
        window.DocuMindWidget.__duplicateLoad = true;
    } else {
        window.DocuMindWidget = widgetApi;
    }
}

if (siteKey && typeof document !== 'undefined') {
    if (window.__docuMindWidgetLoaded) {
        console.warn('[DocuMind] widget script is already on this page — ignoring the duplicate tag.');
    } else {
        window.__docuMindWidgetLoaded = true;

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', start, { once: true });
        } else {
            start();
        }
    }
}
