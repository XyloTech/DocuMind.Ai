import { bindBlobatarGaze, createBlobatarImage, hydrateBlobatars, setBlobatar } from './blobatar.js';

const CSRF = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

const JSON_HEADERS = {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
    'X-CSRF-TOKEN': CSRF,
};

const POLL_INTERVAL_MS = 1500;
const POLL_RETRY_MS = 4000;
const POLL_MAX_ATTEMPTS = 40;
const POLL_MAX_DELAY_MS = 30000;

/* Class set flashed on a copy button after a successful copy. */
const COPIED_CLASSES = [
    'border-emerald-300', 'bg-emerald-50', 'text-emerald-600',
    'dark:border-emerald-500/40', 'dark:bg-emerald-500/10', 'dark:text-emerald-400',
];

/**
 * `navigator.clipboard` is undefined on insecure origins (a plain-HTTP staging
 * site) and can be denied by permissions policy inside an iframe, so keep a
 * legacy path for copying the install snippet.
 */
function copyFallback(text) {
    try {
        const area = document.createElement('textarea');
        area.value = text;
        area.setAttribute('readonly', '');
        area.style.position = 'fixed';
        area.style.top = '-1000px';
        area.style.opacity = '0';
        document.body.appendChild(area);
        area.select();
        const ok = document.execCommand('copy');
        area.remove();
        return ok;
    } catch {
        return false;
    }
}

/* Status copy per server-sent `status` phase. The server emits each phase at
   the moment that work really starts, so nothing here is a guess. */
const PHASE_LABELS = {
    reading: 'Reading your question…',
    searching: 'Searching approved support knowledge…',
    preparing: 'Preparing an answer…',
};

const PHASE_FALLBACK = PHASE_LABELS.reading;
const FIRST_BYTE_TIMEOUT_MS = 15000;
const STREAM_FRAME_MS = 45;

/*
|--------------------------------------------------------------------------
| Markdown Parser with Syntax Styling & Code Copying
|--------------------------------------------------------------------------
*/
function escapeHtml(str) {
    return String(str ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function wrapParagraph(body) {
    return `<p>${body.replace(/\n/g, '<br>')}</p>`;
}

function parseMarkdown(md) {
    if (!md) return '';

    const codeBlocks = [];

    // 1. Extract fenced code blocks first
    let text = md.replace(/```([a-zA-Z0-9_-]*)\s*([\s\S]*?)```/g, (match, lang, code) => {
        const index = codeBlocks.length;
        const displayLang = (lang || 'code').toLowerCase();
        const trimmedCode = code.trim();
        const safeCode = escapeHtml(trimmedCode);
        const encodedRaw = encodeURIComponent(trimmedCode);

        codeBlocks.push(`
            <div class="grok-code-block" data-code-block>
                <div class="grok-code-header">
                    <span class="grok-code-lang">${displayLang}</span>
                    <button type="button" class="grok-code-copy" data-copy-code data-raw-code="${encodedRaw}">
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect width="14" height="14" x="8" y="8" rx="2" ry="2"/>
                            <path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>
                        </svg>
                        <span>Copy</span>
                    </button>
                </div>
                <pre><code>${safeCode}</code></pre>
            </div>
        `);
        return `\n@@CODE_BLOCK_${index}@@\n`;
    });

    // 2. Escape remaining text
    text = escapeHtml(text);

    // 3. Headers
    text = text.replace(/^### (.*$)/gim, '<h3>$1</h3>');
    text = text.replace(/^## (.*$)/gim, '<h2>$1</h2>');
    text = text.replace(/^# (.*$)/gim, '<h1>$1</h1>');

    // 4. Bold & Italic
    text = text.replace(/\*\*\*(.*?)\*\*\*/g, '<strong><em>$1</em></strong>');
    text = text.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
    text = text.replace(/__(.*?)__/g, '<strong>$1</strong>');
    text = text.replace(/\*(.*?)\*/g, '<em>$1</em>');
    text = text.replace(/_(.*?)_/g, '<em>$1</em>');
    text = text.replace(/~~(.*?)~~/g, '<del>$1</del>');

    // 5. Inline Code
    text = text.replace(/`([^`]+)`/g, '<code>$1</code>');

    // 6. Links
    text = text.replace(/\[([^\]]+)\]\((https?:\/\/[^\s\)]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>');

    // 7. Blockquotes
    text = text.replace(/^\&gt;\s?(.*$)/gim, '<blockquote>$1</blockquote>');

    // 8. Tables
    text = text.replace(/((?:\|[^\n]+\|\r?\n?)+)/g, (match) => {
        const lines = match.trim().split(/\r?\n/).map(l => l.trim()).filter(Boolean);
        if (lines.length < 2) return match;
        const isHeaderSep = /^\|[\s\-:]+(\|[\s\-:]+)+\|?$/.test(lines[1]);
        if (!isHeaderSep && lines.length < 3) return match;

        let tableHtml = '<div class="overflow-x-auto"><table class="grok-table">';
        let startRow = 0;
        if (isHeaderSep) {
            const headerCells = lines[0].split('|').slice(1, -1);
            tableHtml += '<thead><tr>';
            headerCells.forEach(cell => {
                tableHtml += `<th>${cell.trim()}</th>`;
            });
            tableHtml += '</tr></thead><tbody>';
            startRow = 2;
        } else {
            tableHtml += '<tbody>';
        }

        for (let i = startRow; i < lines.length; i++) {
            const cells = lines[i].split('|').slice(1, -1);
            tableHtml += '<tr>';
            cells.forEach(cell => {
                tableHtml += `<td>${cell.trim()}</td>`;
            });
            tableHtml += '</tr>';
        }
        tableHtml += '</tbody></table></div>';
        return tableHtml;
    });

    // 9. Lists
    text = text.replace(/^[\*\-] (.*$)/gim, '<ul><li>$1</li></ul>');
    text = text.replace(/<\/ul>\s?<ul>/g, '');

    text = text.replace(/^\d+\. (.*$)/gim, '<ol><li>$1</li></ol>');
    text = text.replace(/<\/ol>\s?<ol>/g, '');

    // 10. Paragraphs
    const paragraphs = text.split(/\n\n+/);
    text = paragraphs.map(p => {
        p = p.trim();
        if (!p) return '';
        // A block-level element may open the paragraph or be embedded in it —
        // wrapping either in <p> produces a mangled DOM.
        if (/^<(h[1-3]|ul|ol|blockquote|div)\b/.test(p) || p.startsWith('@@CODE_BLOCK_')) {
            return p;
        }
        // Split trailing code blocks out so they are never nested in a <p>.
        const fence = p.search(/@@CODE_BLOCK_\d+@@/);

        if (fence > 0) {
            return `${wrapParagraph(p.slice(0, fence))}\n\n${p.slice(fence).trim()}`;
        }

        return wrapParagraph(p);
    }).join('\n');

    // 11. Restore code blocks
    codeBlocks.forEach((block, index) => {
        // Replacer function: a string replacement would expand `$&`, `$1` and
        // backtick patterns found inside the user's own code.
        text = text.replace(`@@CODE_BLOCK_${index}@@`, () => block);
    });

    return text;
}

/*
|--------------------------------------------------------------------------
| Text-to-Speech (Read Aloud)
|--------------------------------------------------------------------------
*/
let currentTTSUtterance = null;
let currentTTSButton = null;

function ttsLabel(button, label) {
    const span = button.querySelector('span');

    if (span) {
        span.textContent = label;
    }
}

function ttsReset() {
    const button = currentTTSButton;

    currentTTSButton = null;
    currentTTSUtterance = null;

    if (!button) return;

    button.classList.remove('text-indigo-600', 'font-bold', 'dark:text-indigo-400');
    ttsLabel(button, 'Read');
}

function toggleTTS(button, text) {
    if (!('speechSynthesis' in window)) {
        toast('Speech synthesis is not supported in this browser.');
        return;
    }

    // Pressing the same button always means "stop", regardless of engine state.
    if (currentTTSButton === button) {
        window.speechSynthesis.cancel();
        ttsReset();
        return;
    }

    const wasSpeaking = window.speechSynthesis.speaking || window.speechSynthesis.pending;

    if (wasSpeaking) {
        window.speechSynthesis.cancel();
        ttsReset();
    }

    const cleanText = String(text ?? '')
        .replace(/```[\s\S]*?```/g, ' Code block omitted. ')
        .replace(/`([^`]+)`/g, '$1')
        .replace(/\[([^\]]+)\]\([^\)]+\)/g, '$1')
        .replace(/[#*_~>|]/g, '')
        .replace(/\s+/g, ' ')
        .trim();

    if (!cleanText) return;

    const utterance = new SpeechSynthesisUtterance(cleanText);
    utterance.rate = 1.0;
    utterance.pitch = 1.0;

    const activate = () => {
        currentTTSButton = button;
        currentTTSUtterance = utterance;
        button.classList.add('text-indigo-600', 'font-bold', 'dark:text-indigo-400');
        ttsLabel(button, 'Stop');
    };

    const reset = () => {
        if (currentTTSUtterance === utterance) {
            ttsReset();
        }
    };

    utterance.onend = reset;
    utterance.onerror = reset;

    // Chrome silently drops an utterance queued in the same task as a cancel().
    const start = () => {
        try {
            window.speechSynthesis.speak(utterance);
        } catch {
            toast('Read aloud is unavailable right now.');
            return;
        }

        activate();
    };

    if (wasSpeaking) {
        setTimeout(start, 120);
    } else {
        start();
    }
}

/*
|--------------------------------------------------------------------------
| Document Dropzone & Polling
|--------------------------------------------------------------------------
*/
function initDropzone() {
    const form = document.querySelector('[data-dropzone]');

    if (!form) {
        return;
    }

    const input = form.querySelector('[data-file-input]');
    const browse = form.querySelector('[data-browse]');
    const errorBox = form.querySelector('[data-dropzone-error]');
    const progress = form.querySelector('[data-upload-progress]');
    const bar = form.querySelector('[data-upload-bar]');
    const label = form.querySelector('[data-upload-label]');
    const trigger = form.querySelector('[data-upload-trigger]');
    const maxBytes = Number(form.dataset.maxKb ?? 25600) * 1024;

    let dragDepth = 0;
    let uploading = false;

    const showError = (message) => {
        errorBox.textContent = message;
        errorBox.classList.remove('hidden');
    };

    const clearError = () => {
        errorBox.textContent = '';
        errorBox.classList.add('hidden');
    };

    const setDragging = (on) => {
        form.dataset.drag = on ? 'true' : 'false';
    };

    const resetDragging = () => {
        dragDepth = 0;
        setDragging(false);
    };

    const openFileDialog = () => input.click();

    form.addEventListener('click', (event) => {
        if (event.target.closest('button, a, input, label')) {
            return;
        }

        openFileDialog();
    });

    browse?.addEventListener('click', openFileDialog);

    form.addEventListener('submit', (event) => {
        event.preventDefault();

        const file = input.files?.[0];

        if (file) {
            upload(file);
            return;
        }

        openFileDialog();
    });

    input.addEventListener('change', () => {
        const file = input.files?.[0];

        if (file) {
            upload(file);
        }
    });

    form.addEventListener('dragenter', (event) => {
        event.preventDefault();
        dragDepth += 1;
        setDragging(true);
    });

    form.addEventListener('dragover', (event) => {
        event.preventDefault();
    });

    form.addEventListener('dragleave', () => {
        dragDepth = Math.max(0, dragDepth - 1);

        if (dragDepth === 0) {
            setDragging(false);
        }
    });

    // A drag that leaves the window never fires `dragleave`, which would strand
    // the dropzone in its highlighted state.
    form.addEventListener('mouseleave', resetDragging);
    window.addEventListener('dragend', resetDragging);
    window.addEventListener('drop', resetDragging);

    form.addEventListener('drop', (event) => {
        event.preventDefault();
        resetDragging();

        const file = event.dataTransfer?.files?.[0];

        if (file) {
            upload(file);
        }
    });

    function validate(file) {
        const looksLikePdf = file.type === 'application/pdf' || /\.pdf$/i.test(file.name);

        if (!looksLikePdf) {
            return 'Only PDF files can be uploaded.';
        }

        if (file.size > maxBytes) {
            return `That PDF is too large. The limit is ${Math.floor(maxBytes / 1024 / 1024)} MB.`;
        }

        return null;
    }

    function upload(file) {
        if (uploading) {
            return;
        }

        clearError();

        const problem = validate(file);

        if (problem) {
            showError(problem);
            return;
        }

        const body = new FormData();
        body.append('document', file, file.name);

        const xhr = new XMLHttpRequest();

        uploading = true;
        trigger.disabled = true;
        progress.hidden = false;
        bar.style.width = '0%';
        label.textContent = 'Uploading… 0%';

        const finish = () => {
            uploading = false;
            trigger.disabled = false;
            progress.hidden = true;
            bar.style.width = '0%';
            input.value = '';
        };

        xhr.upload.addEventListener('progress', (event) => {
            if (!event.lengthComputable) {
                return;
            }

            const percent = Math.min(99, Math.round((event.loaded / event.total) * 100));
            bar.style.width = `${percent}%`;
            label.textContent = `Uploading… ${percent}%`;
        });

        xhr.addEventListener('load', () => {
            finish();

            // A proxy interstitial or a PHP notice can make a 2xx body invalid
            // JSON; never let that Surface as an unhandled SyntaxError.
            let payload = null;

            try {
                payload = JSON.parse(xhr.responseText);
            } catch {
                payload = null;
            }

            if (xhr.status === 201 || xhr.status === 202) {
                if (payload?.html && payload?.document?.id) {
                    insertCard(payload.html, payload.document.id);
                    return;
                }

                showError('The upload could not be read. Please try again.');
                return;
            }

            if (xhr.status === 422) {
                const messages = Object.values(payload?.errors ?? {}).flat();
                showError(messages[0] ?? 'That upload could not be accepted.');
                return;
            }

            if (xhr.status === 419) {
                showError('Your session expired. Refresh the page and try again.');
                return;
            }

            if (xhr.status === 429) {
                showError('Too many uploads. Please wait a moment and try again.');
                return;
            }

            showError('The upload failed. Please try again.');
        });

        xhr.addEventListener('error', () => {
            finish();
            showError('The upload could not reach the server. Check your connection and try again.');
        });

        xhr.open('POST', form.dataset.storeUrl, true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-CSRF-TOKEN', CSRF);
        xhr.send(body);
    }
}

function insertCard(html, id) {
    const list = document.querySelector('[data-document-list]');
    const empty = document.querySelector('[data-empty-state]');

    if (!list) {
        return;
    }

    if (empty) {
        empty.hidden = true;
    }

    list.insertAdjacentHTML('afterbegin', html);
    updateCount(list);

    startPolling(id);
}

function updateCount(list = document.querySelector('[data-document-list]')) {
    const counter = document.querySelector('[data-document-count]');

    if (!counter || !list) {
        return;
    }

    counter.textContent = String(list.querySelectorAll('[data-document-card]').length);
}

function statusUrl(id) {
    const template = document.querySelector('[data-status-url]');

    if (template) {
        return template.dataset.statusUrl.replace('__id__', encodeURIComponent(id));
    }

    return `/documents/${encodeURIComponent(id)}/status`;
}

function startPolling(id) {
    let attempts = 0;

    const tick = async () => {
        if (attempts >= POLL_MAX_ATTEMPTS) {
            markPollingStalled(id);
            return;
        }

        attempts += 1;

        try {
            const response = await fetch(statusUrl(id), {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });

            if (response.status === 404 || response.status === 403) {
                document.querySelector(`[data-document-id="${id}"]`)?.remove();
                updateCount();
                return;
            }

            if (!response.ok) {
                window.setTimeout(tick, retryDelay());
                return;
            }

            const payload = await response.json().catch(() => null);

            if (!payload) {
                window.setTimeout(tick, retryDelay());
                return;
            }

            const card = document.querySelector(`[data-document-id="${id}"]`);

            if (card) {
                card.outerHTML = payload.html;
            }

            if (payload.terminal) {
                updateCount();
                return;
            }

            // A healthy poll must not count toward the give-up budget, or a
            // document that legitimately takes minutes would be abandoned.
            attempts = 0;

            window.setTimeout(tick, POLL_INTERVAL_MS);
        } catch {
            window.setTimeout(tick, retryDelay());
        }
    };

    // Back off on failures so a broken endpoint cannot be hammered forever.
    const retryDelay = () => Math.min(POLL_RETRY_MS * attempts, POLL_MAX_DELAY_MS);

    window.setTimeout(tick, POLL_INTERVAL_MS);
}

/**
 * The status endpoint stopped answering while the document is still not
 * terminal. Leaving the bar frozen with no explanation reads as "broken", so
 * surface the real state and how to recover.
 */
function markPollingStalled(id) {
    const card = document.querySelector(`[data-document-id="${id}"]`);

    if (!card || card.querySelector('[data-poll-stalled]')) {
        return;
    }

    const note = document.createElement('p');
    note.dataset.pollStalled = 'true';
    note.className = 'mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300';
    note.textContent = 'Still processing — large PDFs can take a few minutes. Reload the page to pick the progress back up.';

    const anchor = card.querySelector('[role="progressbar"]')?.parentElement;

    if (anchor) {
        anchor.after(note);
    } else {
        card.append(note);
    }
}

function initPendingDocuments() {
    document.querySelectorAll('[data-document-card]').forEach((card) => {
        if (!card.querySelector('[role="progressbar"]')) {
            return;
        }

        const id = card.dataset.documentId;

        if (id) {
            startPolling(id);
        }
    });
}

function initDeletes() {
    document.addEventListener('submit', (event) => {
        const form = event.target.closest('[data-delete-form]');

        if (!form) {
            return;
        }

        event.preventDefault();

        confirmDialog({
            title: 'Delete this document?',
            message: 'This cannot be undone.',
            confirmText: 'Delete',
            danger: true,
        }).then((ok) => {
            if (!ok) {
                return;
            }

            const card = form.closest('[data-document-card]');

            fetch(form.action, {
                method: 'DELETE',
                headers: JSON_HEADERS,
                credentials: 'same-origin',
            })
                .then((response) => {
                    // 404 means it is already gone, so removing the card is correct.
                    // 403 is an authorization failure — nothing was deleted.
                    if (response.ok || response.status === 204 || response.status === 404) {
                        card?.remove();
                        updateCount();

                        const empty = document.querySelector('[data-empty-state]');
                        const remaining = document.querySelectorAll('[data-document-card]').length;

                        if (empty) {
                            empty.hidden = remaining > 0;
                        }

                        return;
                    }

                    showToast({
                        type: 'error',
                        title: 'Could not delete that document',
                        message: 'Please refresh and try again.',
                    });
                })
                .catch(() => {
                    showToast({
                        type: 'error',
                        title: 'Connection lost',
                        message: 'The request could not reach the server. Please try again.',
                    });
                });
        });
    });
}

function initTheme() {
    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-theme-toggle]');

        if (!button) {
            return;
        }

        const dark = !document.documentElement.classList.contains('dark');

        document.documentElement.classList.toggle('dark', dark);

        try {
            localStorage.setItem('documind_theme', dark ? 'dark' : 'light');
        } catch {
            /* preference simply will not persist */
        }
    });
}

const TOAST_ICONS = {
    info: '<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm.75-11.25a.75.75 0 00-1.5 0v.5a.75.75 0 001.5 0v-.5zM10 9a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 9z" clip-rule="evenodd"/></svg>',
    success:
        '<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.53-9.47a.75.75 0 00-1.06-1.06L9 10.94 7.53 9.47a.75.75 0 00-1.06 1.06L7.94 12.5l-1.47 1.47a.75.75 0 101.06 1.06L9 13.56l1.47 1.47a.75.75 0 001.06-1.06L10.06 12.5l1.47-1.47z" clip-rule="evenodd"/></svg>',
    warning:
        '<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z"/></svg>',
    error: '<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm2.28-7.72a.75.75 0 00-1.06-1.06L10 9.94 8.78 8.72a.75.75 0 00-1.06 1.06L8.94 11l-1.22 1.22a.75.75 0 101.06 1.06L10 12.06l1.22 1.22a.75.75 0 001.06-1.06L11.06 11l1.22-1.22z" clip-rule="evenodd"/></svg>',
};

/**
 * Styled, stacked toasts replace the old full-width flash banner. `title`
 * doubles as the message when no body is given; severity picks colour and
 * whether the node announces assertively.
 */
function showToast({ type = 'info', title = '', message = '', link = null, linkLabel = 'Open', duration = null } = {}) {
    let host = document.querySelector('[data-toast-host]');

    if (!host) {
        host = document.createElement('div');
        host.dataset.toastHost = '';
        host.className = 'dm-toast-host';
        document.body.append(host);
    }

    while (host.children.length >= 4) {
        host.firstElementChild.remove();
    }

    const node = document.createElement('div');
    node.className = `dm-toast dm-toast--${type}`;
    node.setAttribute('role', type === 'error' ? 'alert' : 'status');
    node.innerHTML = `
        <span class="dm-toast-icon">${TOAST_ICONS[type] || TOAST_ICONS.info}</span>
        <div class="dm-toast-content">
            <p class="dm-toast-title">${escapeHtml(title || message)}</p>
            ${title && message ? `<p class="dm-toast-body">${escapeHtml(message)}</p>` : ''}
            ${link ? `<a class="dm-toast-link" href="${escapeHtml(link)}">${escapeHtml(linkLabel)}</a>` : ''}
        </div>
        <button type="button" class="dm-toast-close" aria-label="Dismiss notification">
            <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z"/></svg>
        </button>`;

    let dismissed = false;
    const dismiss = () => {
        if (dismissed) return;
        dismissed = true;
        node.classList.add('dm-toast--leaving');
        setTimeout(() => node.remove(), 200);
    };

    node.querySelector('.dm-toast-close').addEventListener('click', dismiss);
    host.append(node);

    let remaining = duration ?? (type === 'error' ? 7000 : type === 'warning' ? 6000 : 4200);
    let started = Date.now();
    let timer = setTimeout(dismiss, remaining);
    const pause = () => {
        clearTimeout(timer);
        remaining = Math.max(1200, remaining - (Date.now() - started));
    };
    const resume = () => {
        started = Date.now();
        timer = setTimeout(dismiss, remaining);
    };

    node.addEventListener('mouseenter', pause);
    node.addEventListener('mouseleave', resume);
    node.addEventListener('focusin', pause);
    node.addEventListener('focusout', resume);

    return node;
}

function toast(message, type = 'info') {
    showToast({ type, title: message });
}

/**
 * Accessible replacement for window.confirm: a focus-trapped alertdialog
 * resolving to true (confirm) / false (cancel, Escape, backdrop). Always
 * await it — never call it synchronously.
 */
function confirmDialog({
    title = 'Are you sure?',
    message = '',
    confirmText = 'Confirm',
    cancelText = 'Cancel',
    danger = false,
} = {}) {
    return new Promise((resolve) => {
        const backdrop = document.createElement('div');
        backdrop.className = 'dm-confirm-backdrop';
        backdrop.innerHTML = `
            <div class="dm-confirm-card" role="alertdialog" aria-modal="true" aria-labelledby="dm-confirm-title"${message ? ' aria-describedby="dm-confirm-body"' : ''}>
                <p class="dm-confirm-title" id="dm-confirm-title">${escapeHtml(title)}</p>
                ${message ? `<p class="dm-confirm-body" id="dm-confirm-body">${escapeHtml(message)}</p>` : ''}
                <div class="dm-confirm-actions">
                    <button type="button" data-confirm-cancel class="btn btn-secondary btn-sm">${escapeHtml(cancelText)}</button>
                    <button type="button" data-confirm-ok class="btn ${danger ? 'btn-danger' : 'btn-primary'} btn-sm">${escapeHtml(confirmText)}</button>
                </div>
            </div>`;

        const previousFocus = document.activeElement;
        const okBtn = backdrop.querySelector('[data-confirm-ok]');
        const cancelBtn = backdrop.querySelector('[data-confirm-cancel]');
        const focusable = [cancelBtn, okBtn];

        const close = (value) => {
            document.removeEventListener('keydown', onKey, true);
            backdrop.remove();
            if (previousFocus && typeof previousFocus.focus === 'function') {
                previousFocus.focus();
            }
            resolve(value);
        };

        const onKey = (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                close(false);
            } else if (event.key === 'Tab') {
                const first = focusable[0];
                const last = focusable[focusable.length - 1];

                if (event.shiftKey && document.activeElement === first) {
                    event.preventDefault();
                    last.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault();
                    first.focus();
                }
            }
        };

        okBtn.addEventListener('click', () => close(true));
        cancelBtn.addEventListener('click', () => close(false));
        backdrop.addEventListener('click', (event) => {
            if (event.target === backdrop) {
                close(false);
            }
        });

        document.addEventListener('keydown', onKey, true);
        document.body.append(backdrop);
        cancelBtn.focus();
    });
}

/**
 * Native-form confirm flow: block the synchronous submit, ask, then re-submit
 * with a one-shot flag so the guard lets the second pass through.
 */
function guardFormSubmit(event, options) {
    const form = event.target;

    if (!(form instanceof HTMLFormElement)) {
        return;
    }

    if (form.dataset.confirmed === '1') {
        delete form.dataset.confirmed;
        return;
    }

    event.preventDefault();

    confirmDialog(options).then((ok) => {
        if (ok) {
            form.dataset.confirmed = '1';
            form.requestSubmit();
        }
    });
}

function initChatDelete() {
    document.addEventListener('submit', (event) => {
        const form = event.target.closest('[data-delete-chat]');

        if (!form) {
            return;
        }

        guardFormSubmit(event, {
            title: 'Delete this conversation?',
            message: 'Your document will be preserved.',
            confirmText: 'Delete',
            danger: true,
        });
    });
}

/*
|--------------------------------------------------------------------------
| Grok Chat Sidebar: Search Filter & Inline Rename
|--------------------------------------------------------------------------
*/
function initChatSearch() {
    const input = document.querySelector('[data-search-chats]');
    if (!input) return;

    const empty = document.querySelector('[data-chat-search-empty]');

    input.addEventListener('input', () => {
        const query = input.value.trim().toLowerCase();
        const items = document.querySelectorAll('[data-chat-item]');

        let visible = 0;

        items.forEach(item => {
            const title = item.dataset.chatTitle || '';
            const match = !query || title.includes(query);
            item.style.display = match ? '' : 'none';
            if (match) visible += 1;
        });

        // Only a real search can miss — the sidebar already shows its own
        // "No conversations yet" placeholder when the list is server-side empty.
        if (empty) {
            empty.classList.toggle('hidden', !(query && visible === 0));
        }
    });
}

/**
 * Inline rename dialog. Replaces `window.prompt`, which cannot show the
 * server's validation reason, blocks the main thread, and looks broken next to
 * an otherwise polished UI.
 *
 * @return {Promise<string|null>} the new title, or null when cancelled
 */
function promptRename(currentTitle) {
    return new Promise((resolve) => {
        const overlay = document.createElement('div');
        overlay.className = 'dm-overlay fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4 backdrop-blur-sm dark:bg-black/60';

        const field = document.createElement('input');
        field.type = 'text';
        field.maxLength = 120;
        field.value = currentTitle;
        field.setAttribute('aria-label', 'Conversation name');
        field.className =
            'w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:outline-none dark:border-white/10 dark:bg-white/5 dark:text-white';

        const error = document.createElement('p');
        error.className = 'mt-2 hidden text-xs text-rose-600 dark:text-rose-400';

        const submit = () => {
            const value = field.value.trim();

            if (value === '') {
                error.textContent = 'Give the conversation a name.';
                error.classList.remove('hidden');
                field.focus();
                return;
            }

            close(value);
        };

        const cancel = () => close(null);

        const onKey = (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                close(null);
            } else if (event.key === 'Enter') {
                event.preventDefault();
                submit();
            }
        };

        const panel = document.createElement('div');
        panel.className =
            'dm-rename rounded-2xl border border-slate-200 bg-white dark:bg-[#0d0f15] p-5 shadow-2xl dark:border-white/10';
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-modal', 'true');
        panel.setAttribute('aria-label', 'Rename conversation');

        const heading = document.createElement('h2');
        heading.className = 'text-sm font-bold text-slate-900 dark:text-white';
        heading.textContent = 'Rename conversation';

        const hint = document.createElement('p');
        hint.className = 'mt-1 text-xs text-slate-500';
        hint.textContent = 'Up to 120 characters. Press Enter to save, Escape to cancel.';

        const footer = document.createElement('div');
        footer.className = 'mt-4 flex items-center justify-end gap-2';

        const cancelButton = document.createElement('button');
        cancelButton.type = 'button';
        cancelButton.className =
            'rounded-full px-3.5 py-1.5 text-xs font-semibold text-slate-500 hover:bg-slate-100 hover:text-slate-800 dark:text-slate-500 dark:hover:bg-white/10 dark:hover:text-white';
        cancelButton.textContent = 'Cancel';
        cancelButton.addEventListener('click', cancel);

        const saveButton = document.createElement('button');
        saveButton.type = 'button';
        saveButton.className =
            'rounded-full bg-slate-900 px-4 py-1.5 text-xs font-semibold text-white transition hover:bg-slate-800 dark:bg-white dark:text-black dark:hover:bg-slate-200';
        saveButton.textContent = 'Save';
        saveButton.addEventListener('click', submit);

        footer.append(cancelButton, saveButton);
        panel.append(heading, hint, field, error, footer);
        overlay.append(panel);
        document.body.append(overlay);

        function close(value) {
            document.removeEventListener('keydown', onKey, true);
            overlay.remove();
            resolve(value);
        }

        overlay.addEventListener('click', (event) => {
            if (event.target === overlay) {
                close(null);
            }
        });

        document.addEventListener('keydown', onKey, true);

        field.focus();
        field.select();
    });
}

/**
 * Slide-out chat settings panel in the header.
 */
function initChatSettings() {
    const trigger = document.querySelector('[data-chat-settings]');
    const panel = document.querySelector('[data-settings-panel]');

    if (!trigger || !panel) return;

    const close = document.querySelector('[data-settings-close]');

    const setOpen = (open) => {
        panel.hidden = !open;
        panel.classList.toggle('opacity-0', !open);
        panel.classList.toggle('scale-95', !open);
        panel.classList.toggle('pointer-events-none', !open);
        trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
    };

    trigger.setAttribute('aria-expanded', 'false');
    setOpen(false);

    trigger.addEventListener('click', () => {
        setOpen(panel.hidden);
    });

    close?.addEventListener('click', () => setOpen(false));

    document.addEventListener('click', (event) => {
        if (panel.hidden) return;

        if (!panel.contains(event.target) && !trigger.contains(event.target)) {
            setOpen(false);
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !panel.hidden) {
            setOpen(false);
            trigger.focus();
        }
    });
}

function initChatRename() {
    document.addEventListener('click', async (event) => {
        const btn = event.target.closest('[data-rename-chat-trigger]');
        if (!btn) return;

        const currentTitle = (btn.dataset.currentTitle || 'Chat').trim();
        const updateUrl = btn.dataset.updateUrl;
        if (!updateUrl) return;

        const nextTitle = (await promptRename(currentTitle))?.trim();

        if (!nextTitle || nextTitle === currentTitle) {
            return;
        }

        try {
            const response = await fetch(updateUrl, {
                method: 'PATCH',
                headers: {
                    ...JSON_HEADERS,
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ title: nextTitle }),
                credentials: 'same-origin',
            });

            if (!response.ok) {
                const data = await response.json().catch(() => ({}));
                const reason =
                    Object.values(data.errors ?? {}).flat()[0] ??
                    (typeof data.message === 'string' ? data.message : null);

                toast(reason ?? 'Could not rename chat.');
                return;
            }

            const data = await response.json();
            const title = typeof data.title === 'string' && data.title !== '' ? data.title : nextTitle;

            // Update in sidebar
            const item = btn.closest('[data-chat-item]');
            if (item) {
                const titleNode = item.querySelector('[data-chat-item-title]');
                if (titleNode) titleNode.textContent = title;
                item.querySelector('a')?.setAttribute('title', title);
                item.dataset.chatTitle = title.toLowerCase();
            }

            // Update in header
            const headerTitle = document.querySelector('[data-header-title]');
            if (headerTitle) {
                headerTitle.textContent = title;
            }

            // Update triggers
            document.querySelectorAll(`[data-rename-chat-trigger][data-update-url="${updateUrl}"]`).forEach(el => {
                el.dataset.currentTitle = title;
            });

            const brand = document.body.dataset.appName?.trim();
            const currentDocumentTitle = document.title;
            const separator = ' · ';

            if (brand && currentDocumentTitle.endsWith(separator + brand)) {
                document.title = `${title}${separator}${brand}`;
            }

            toast('Chat renamed.');
        } catch {
            toast('Failed to rename chat. Check connection.');
        }
    });
}

/*
|--------------------------------------------------------------------------
| Grok Chat Workspace Engine: Streaming, Markdown, Actions, Scroll
|--------------------------------------------------------------------------
*/
function initChat() {
    const composer = document.querySelector('[data-composer]');

    if (!composer) {
        return;
    }

    const transcript = document.querySelector('[data-transcript]');
    const input = composer.querySelector('[data-composer-input]');
    const send = composer.querySelector('[data-composer-send]');
    const stop = composer.querySelector('[data-composer-stop]');
    const errorBox = document.querySelector('[data-composer-error]');
    const placeholder = document.querySelector('[data-placeholder]');
    const scrollBtn = document.querySelector('[data-scroll-to-bottom]');
    const charCount = document.querySelector('[data-char-count]');
    const attach = composer.querySelector('[data-composer-attach]');
    const maxChars = Number(input.getAttribute('maxlength')) || 4000;

    let sending = false;
    let abortController = null;

    const autosize = () => {
        input.style.height = 'auto';
        input.style.height = `${Math.min(input.scrollHeight, 160)}px`;
    };

    const syncComposerState = () => {
        const length = input.value.trim().length;

        composer.dataset.ready = length > 0 ? 'true' : 'false';
        send.disabled = length === 0;

        if (!charCount) return;

        const ratio = input.value.length / maxChars;
        const nearLimit = ratio >= 0.9;

        charCount.classList.toggle('hidden', !nearLimit);
        charCount.textContent = `${input.value.length.toLocaleString()} / ${maxChars.toLocaleString()}`;
        charCount.classList.toggle('text-rose-600', nearLimit);
        charCount.classList.toggle('dark:text-rose-400', nearLimit);
        charCount.classList.toggle('text-slate-500', !nearLimit);
    };

    const showError = (message) => {
        if (!errorBox) {
            toast(message);
            return;
        }

        errorBox.textContent = message;
        errorBox.classList.remove('hidden');
    };

    const scrollToBottom = (behavior = 'smooth') => {
        transcript.scrollTo({ top: transcript.scrollHeight, behavior });
    };

    // Scroll to bottom button visibility
    const checkScrollPosition = () => {
        if (!scrollBtn) return;
        const dist = transcript.scrollHeight - transcript.scrollTop - transcript.clientHeight;
        if (dist > 150) {
            scrollBtn.classList.add('visible');
        } else {
            scrollBtn.classList.remove('visible');
        }
    };

    transcript.addEventListener('scroll', checkScrollPosition, { passive: true });
    scrollBtn?.addEventListener('click', () => scrollToBottom('smooth'));

    // A long conversation must open at the newest message, with the jump button
    // reflecting the real scroll offset.
    requestAnimationFrame(() => {
        scrollToBottom('auto');
        checkScrollPosition();
    });

    input.addEventListener('input', () => {
        autosize();
        syncComposerState();
    });

    input.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            composer.requestSubmit();
        }

        if (event.key === 'Escape' && sending) {
            abortController?.abort();
        }
    });

    syncComposerState();

    attach?.addEventListener('click', () => {
        input.focus();
        toast('This chat is already grounded in the attached document.');
    });

    // Starter suggestion chips
    document.addEventListener('click', (event) => {
        const chip = event.target.closest('[data-suggestion]');

        if (!chip || sending || composer.dataset.enabled === 'false') {
            return;
        }

        input.value = chip.dataset.suggestion ?? '';
        autosize();
        syncComposerState();
        input.focus();
        composer.requestSubmit();
    });

    // Copy user message prompt
    document.addEventListener('click', async (event) => {
        const btn = event.target.closest('[data-copy-user-message]');
        if (!btn) return;
        const text = btn.closest('[data-message]')?.querySelector('[data-message-content]')?.textContent?.trim() || '';
        try {
            await navigator.clipboard.writeText(text);
            toast('Prompt copied.');
        } catch {
            toast('Could not copy prompt.');
        }
    });

    // Copy entire answer
    document.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-copy-answer]');
        if (!button) return;

        const content = button.closest('[data-message]')?.querySelector('[data-message-content]');
        const textToCopy = content?.dataset?.rawText || content?.innerText?.trim() || '';

        try {
            await navigator.clipboard.writeText(textToCopy);
            const span = button.querySelector('span');
            const orig = span ? span.textContent : 'Copy';
            if (span) span.textContent = 'Copied!';
            button.classList.add('text-emerald-500');
            toast('Answer copied to clipboard.');
            setTimeout(() => {
                if (span) span.textContent = orig;
                button.classList.remove('text-emerald-500');
            }, 2000);
        } catch {
            toast('Copying is not available in this browser.');
        }
    });

    // Copy code snippet inside code block
    document.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-copy-code]');
        if (!button) return;

        const encodedRaw = button.dataset.rawCode;
        const codeText = encodedRaw
            ? decodeURIComponent(encodedRaw)
            : button.closest('.grok-code-block')?.querySelector('pre code')?.innerText || '';

        try {
            await navigator.clipboard.writeText(codeText);
            const span = button.querySelector('span');
            const orig = span ? span.textContent : 'Copy';
            if (span) span.textContent = 'Copied!';
            button.classList.add('text-emerald-400');
            setTimeout(() => {
                if (span) span.textContent = orig;
                button.classList.remove('text-emerald-400');
            }, 2000);
        } catch {
            toast('Could not copy code.');
        }
    });

    // Read Aloud / TTS button
    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-tts-button]');
        if (!button) return;

        const content = button.closest('[data-message]')?.querySelector('[data-message-content]');
        const text = content?.dataset?.rawText || content?.innerText || '';
        toggleTTS(button, text);
    });

    // Thumbs up / down feedback — posted so the rating survives a reload and
    // actually reaches the analytics tables.
    document.addEventListener('click', async (event) => {
        const thumb = event.target.closest('[data-feedback-thumb]');
        if (!thumb) return;

        const isUp = thumb.dataset.feedbackThumb === 'up';
        const next = isUp;
        const bar = thumb.parentElement;
        const upBtn = bar?.querySelector('[data-feedback-thumb="up"]');
        const downBtn = bar?.querySelector('[data-feedback-thumb="down"]');
        const messageId = thumb.closest('[data-message]')?.dataset.messageId;
        const action = composer?.action;

        const paint = (state) => {
            upBtn?.classList.toggle('text-emerald-600', state === true);
            upBtn?.classList.toggle('dark:text-emerald-400', state === true);
            upBtn?.classList.toggle('font-bold', state === true);
            downBtn?.classList.toggle('text-rose-600', state === false);
            downBtn?.classList.toggle('dark:text-rose-400', state === false);
            downBtn?.classList.toggle('font-bold', state === false);
            upBtn?.setAttribute('aria-pressed', String(state === true));
            downBtn?.setAttribute('aria-pressed', String(state === false));
        };

        const previous = thumb.dataset.state === 'true' ? true : thumb.dataset.state === 'false' ? false : null;

        // Clicking the active thumb again clears the vote.
        const value = previous === next ? null : next;

        paint(value);
        [upBtn, downBtn].forEach((node) => {
            if (!node) return;
            node.dataset.state = 'null';
        });
        if (value !== null) thumb.dataset.state = String(value);

        if (!messageId || !action) {
            toast('Feedback saved.');
            return;
        }

        try {
            const response = await fetch(`${action}/${encodeURIComponent(messageId)}/feedback`, {
                method: 'POST',
                headers: JSON_HEADERS,
                body: new URLSearchParams(value === null ? {} : { helpful: String(value) }),
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            toast(value === null ? 'Feedback cleared.' : value ? 'Feedback saved: Helpful response' : 'Feedback saved: Needs improvement');
        } catch {
            paint(previous);
            if (previous !== null) thumb.dataset.state = String(previous);
            toast('Feedback could not be saved. Check your connection.');
        }
    });

    stop?.addEventListener('click', () => abortController?.abort());

    composer.addEventListener('submit', async (event) => {
        event.preventDefault();

        // Consent is off: the composer is disabled and the notice above it
        // explains why, so never fire a request that will be refused.
        if (composer.dataset.enabled === 'false') {
            return;
        }

        const text = input.value.trim();

        if (!text || sending) {
            return;
        }

        sending = true;
        abortController = new AbortController();
        send.disabled = true;
        send.classList.add('hidden');
        stop?.classList.remove('hidden');
        errorBox?.classList.add('hidden');
        input.value = '';
        autosize();
        syncComposerState();
        placeholder?.remove();

        appendMessage('user', text);

        // Assistant bubble starts with typing dots
        const assistantBubble = appendMessage('assistant', '');
        assistantBubble.showTyping();

        scrollToBottom('auto');

        const startTime = Date.now();

        // The server pushes a `status` byte the moment the stream opens, so
        // silence means the request is dead rather than merely slow.
        let timedOut = false;
        let wentOffline = false;

        const watchdog = setTimeout(() => {
            timedOut = true;
            abortController?.abort();
        }, FIRST_BYTE_TIMEOUT_MS);

        const handleOffline = () => {
            wentOffline = true;
            abortController?.abort();
        };

        window.addEventListener('offline', handleOffline);

        try {
            const response = await fetch(composer.action, {
                method: 'POST',
                headers: { ...JSON_HEADERS, Accept: 'text/event-stream' },
                body: new URLSearchParams({ message: text }),
                credentials: 'same-origin',
                signal: abortController.signal,
            });

            // Any failure status is a failure. Falling through to the SSE reader
            // on an HTML error page produced a silent, empty answer.
            if (!response.ok) {
                const data = await response.json().catch(() => ({}));
                const message =
                    typeof data.message === 'string'
                        ? data.message
                        : Object.values(data.errors ?? {}).flat()[0] ??
                          `The message could not be sent (${response.status}).`;

                if (data.credits !== undefined) {
                    updateCredits(data.credits);
                }

                throw new Error(message);
            }

            const type = response.headers.get('content-type') ?? '';

            if (type.includes('application/json')) {
                const data = await response.json();
                assistantBubble.hideTyping();
                assistantBubble.setMarkdown(data.message?.content ?? '');
                renderSources(assistantBubble, data.message?.sources ?? []);
                updateCredits(data.credits);

                if (data.handoff) {
                    showHandoffNotice(data.handoff);
                }
            } else {
                await readEvents(response, assistantBubble, startTime, () => clearTimeout(watchdog));
            }

            assistantBubble.addActions();
        } catch (error) {
            // Safari and iOS reject an aborted fetch with a plain Error.
            const stopped = error?.name === 'AbortError';

            // Must happen before anything writes to the bubble, otherwise a
            // pending render frame would overwrite the message below.
            assistantBubble.cancelPending();
            assistantBubble.hideTyping();

            if (timedOut || wentOffline) {
                const reason = timedOut
                    ? 'The reply did not arrive in time.'
                    : 'You went offline before the reply arrived.';

                assistantBubble.content.className =
                    'grok-prose rounded-2xl border border-amber-300/60 bg-amber-50/80 px-4 py-3 text-[15px] leading-relaxed text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300';
                assistantBubble.content.innerHTML =
                    `${assistantBubble.rawText ? parseMarkdown(assistantBubble.rawText) + '\n\n' : ''}` +
                    `*${reason} The answer may still be saved — reload the chat to check.*`;

                showError(timedOut ? 'The assistant did not respond in time.' : 'You are offline.');
            } else if (stopped) {
                const current = assistantBubble.rawText;

                assistantBubble.setMarkdown(
                    current ? `${current}\n\n*Generation stopped.*` : '*Stopped.*',
                );
                showError('Generation stopped.');
            } else {
                const partial = assistantBubble.rawText;

                assistantBubble.content.className =
                    'grok-prose rounded-2xl border border-amber-300/60 bg-amber-50/80 px-4 py-3 text-[15px] leading-relaxed text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300';
                assistantBubble.content.innerHTML = partial
                    ? `${parseMarkdown(partial)}\n\n*Generation interrupted — your credit was refunded.*`
                    : '<p>Something went wrong. Your credit was refunded.</p>';

                showError(error instanceof Error ? error.message : 'Something went wrong.');
            }
        } finally {
            clearTimeout(watchdog);
            window.removeEventListener('offline', handleOffline);
            sending = false;
            abortController = null;
            send.classList.remove('hidden');
            send.disabled = input.value.trim().length === 0;
            stop?.classList.add('hidden');
            scrollToBottom();
            input.focus();
        }
    });
}

/*
|--------------------------------------------------------------------------
| Thinking Indicator
|--------------------------------------------------------------------------
| Builds the animated "dots + shimmering status label" block that replaces
| the answer bubble while the model streams its first tokens.
*/
function createThinkingIndicator() {
    const root = document.createElement('span');
    root.className = 'dm-thinking';
    root.setAttribute('role', 'status');
    root.setAttribute('aria-live', 'polite');

    const dots = document.createElement('span');
    dots.className = 'dm-thinking-dots';
    dots.setAttribute('aria-hidden', 'true');
    dots.innerHTML = '<span></span><span></span><span></span>';

    const label = document.createElement('span');
    label.className = 'dm-thinking-label dm-thinking-enter';
    label.textContent = PHASE_FALLBACK;

    root.append(dots, label);
    root.setAttribute('aria-label', PHASE_FALLBACK);

    return { root, label };
}

function appendMessage(role, text) {
    const transcript = document.querySelector('[data-transcript]');
    const host = transcript.firstElementChild;
    const isUser = role === 'user';

    const article = document.createElement('article');
    article.dataset.message = '';
    article.dataset.role = role;
    article.className = isUser ? 'group flex justify-end' : 'group';

    const content = document.createElement('div');
    let avatar = null;
    let thinking = null;
    let thinkingHost = null;
    let row = null;
    let stopAvatarGaze = null;
    let renderTimer = null;
    let pendingText = null;
    let caret = null;
    let rawText = text;

    if (isUser) {
        const wrap = document.createElement('div');
        wrap.className = 'max-w-[85%] sm:max-w-[80%]';

        content.className =
            'whitespace-pre-wrap break-words rounded-3xl rounded-tr-md bg-blue-100 px-4 py-2.5 text-[15px] leading-relaxed text-slate-800 shadow-xs dark:bg-blue-500/15 dark:text-slate-700 dark:ring-1 dark:ring-blue-400/20';
        content.textContent = text;
        wrap.append(content);

        const actions = document.createElement('div');
        actions.className = 'dm-action-bar mt-1 flex items-center justify-end gap-1 group-hover:opacity-100 group-focus-within:opacity-100';
        actions.innerHTML = `
            <button type="button" data-copy-user-message class="dm-icon-button rounded-lg p-1.5 text-slate-400 dark:text-slate-500 hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-white/10 dark:hover:text-slate-200" title="Copy prompt" aria-label="Copy prompt">
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect width="14" height="14" x="8" y="8" rx="2" ry="2"/>
                    <path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>
                </svg>
            </button>
        `;
        wrap.append(actions);
        article.append(wrap);
    } else {
        const wrap = document.createElement('div');
        wrap.className = 'w-full max-w-3xl';

        row = document.createElement('div');
        row.className = 'flex items-start gap-3';

        avatar = document.createElement('span');
        avatar.setAttribute('aria-hidden', 'true');
        avatar.className =
            'dm-avatar mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center overflow-hidden rounded-full ring-1 ring-white/20';
        avatar.append(createBlobatarImage(document.body.dataset.appName || 'DocuMind', { animate: 'hover' }));

        const column = document.createElement('div');
        column.className = 'min-w-0 flex-1';

        thinkingHost = document.createElement('div');
        thinkingHost.className = 'hidden';

        content.className = 'grok-prose text-[15px] leading-relaxed text-slate-700';
        content.dataset.rawText = text;

        column.append(thinkingHost, content);
        row.append(avatar, column);
        stopAvatarGaze = bindBlobatarGaze(row, avatar);
        wrap.append(row);
        article.append(wrap);
    }

    /**
     * Swap the face while the assistant works: the thinking pose for as long
     * as the answer streams, the resting one once it lands. The gaze driver is
     * rebound because it follows the node this just replaced.
     */
    const setAvatarPose = (expression) => {
        if (!avatar || !row) {
            return;
        }

        setBlobatar(avatar, document.body.dataset.appName || 'DocuMind', {
            animate: 'hover',
            expression: expression ?? undefined,
        });

        stopAvatarGaze?.();
        stopAvatarGaze = bindBlobatarGaze(row, avatar);
    };

    host.append(article);

    const flushPending = () => {
        renderTimer = null;

        if (pendingText === null) {
            return;
        }

        const next = pendingText;
        pendingText = null;

        content.dataset.rawText = next;
        content.innerHTML = parseMarkdown(next);
        bubble.showCaret();
    };

    const bubble = {
        article,
        content,
        /** The scrolling viewport, not the non-scrolling message column. */
        stream: transcript,
        get rawText() {
            return rawText;
        },

        setText(value) {
            bubble.cancelPending();
            rawText = value;
            content.dataset.rawText = value;
            content.textContent = value;
            bubble.showCaret();
        },

        /**
         * Progressive render: formats the accumulated answer on a throttled
         * frame so streamed tokens arrive as real Markdown, not raw text.
         */
        streamText(value) {
            rawText = value;
            pendingText = value;

            if (renderTimer === null) {
                renderTimer = setTimeout(flushPending, STREAM_FRAME_MS);
            }
        },

        cancelPending() {
            if (renderTimer !== null) {
                clearTimeout(renderTimer);
                renderTimer = null;
            }

            pendingText = null;
        },

        setMarkdown(value) {
            bubble.cancelPending();
            rawText = value;
            content.dataset.rawText = value;
            content.innerHTML = parseMarkdown(value);
            content.dataset.rendered = 'true';
        },

        /**
         * The stream ended without a `done` event, so the answer is partial.
         */
        markIncomplete() {
            const note = document.createElement('p');
            note.className =
                'mt-3 rounded-xl border border-amber-300/60 bg-amber-50/80 px-3.5 py-2.5 text-xs leading-relaxed text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300';
            note.textContent =
                rawText.trim() === ''
                    ? 'The response was interrupted before it started. Your credit was refunded.'
                    : 'This response was cut off before it finished. Ask again to continue.';

            content.append(note);
        },

        showTyping() {
            if (isUser || thinking) {
                return;
            }

            thinking = createThinkingIndicator();
            thinkingHost.replaceChildren(thinking.root);
            thinkingHost.classList.remove('hidden');
            article.dataset.streaming = 'true';
            setAvatarPose('thinking');
        },

        /**
         * Adopt a `status` phase the server announced. The label only moves
         * when real work starts, so it never invents progress.
         */
        setPhase(phase) {
            const text = PHASE_LABELS[phase];

            if (!thinking || !text || thinking.label.textContent === text) {
                return;
            }

            thinking.label.textContent = text;
            thinking.root.setAttribute('aria-label', text);
            thinking.label.classList.remove('dm-thinking-enter');
            void thinking.label.offsetWidth;
            thinking.label.classList.add('dm-thinking-enter');
        },

        hideTyping() {
            const wasThinking = thinking !== null;

            article.removeAttribute('data-streaming');

            if (wasThinking) {
                setAvatarPose(null);
            }

            if (!thinking) {
                return;
            }

            const exiting = thinking.root;
            thinking = null;
            exiting.classList.add('dm-thinking-exit');
            setTimeout(() => {
                if (exiting.isConnected) {
                    exiting.remove();
                }

                if (thinkingHost && !thinkingHost.childElementCount) {
                    thinkingHost.classList.add('hidden');
                }
            }, 240);
        },

        showCaret() {
            if (caret || isUser) return;
            caret = document.createElement('span');
            caret.className = 'dm-caret';
            caret.setAttribute('aria-hidden', 'true');
            content.append(caret);
        },

        hideCaret() {
            caret?.remove();
            caret = null;
        },

        addThought(durationSec, sourcesCount) {
            if (isUser) return;
            const col = article.querySelector('.min-w-0.flex-1');
            if (!col || col.querySelector('.grok-thought')) return;

            const thought = document.createElement('details');
            thought.className = 'grok-thought group/thought';
            thought.innerHTML = `
                <summary class="dm-thinking-pill">
                    <svg class="grok-thought-icon shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                    </svg>
                    <span>
                        Thought for ${durationSec}s
                        ${sourcesCount ? `&middot; ${sourcesCount} ${sourcesCount === 1 ? 'source' : 'sources'} verified` : ''}
                    </span>
                </summary>
                <div class="grok-thought-body">
                    <p class="text-xs text-slate-500">
                        Retrieval complete: indexed chunk candidates analysed, similarity and keyword scores fused, then each claim cross-referenced against the document text.
                    </p>
                </div>
            `;
            col.insertBefore(thought, content);
        },

        addActions() {
            if (isUser || !rawText.trim()) {
                return;
            }

            const wrap = article.querySelector('.w-full.max-w-3xl');
            if (!wrap || wrap.querySelector('[data-message-actions]')) return;

            const actions = document.createElement('div');
            actions.dataset.messageActions = '';
            actions.className =
                'dm-action-bar mt-2.5 flex flex-wrap items-center gap-0.5 pl-10 group-hover:opacity-100 group-focus-within:opacity-100';

            const previous = article.previousElementSibling;
            const asked = previous?.dataset.role === 'user' ? previous.textContent?.trim() : null;

            if (asked) {
                const retry = document.createElement('button');
                retry.type = 'button';
                retry.className =
                    'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium text-slate-500 hover:bg-slate-100 hover:text-slate-800 dark:text-slate-500 dark:hover:bg-white/10 dark:hover:text-white';
                retry.innerHTML = `
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/>
                        <path d="M3 3v5h5"/>
                    </svg>
                    <span>Ask again</span>
                `;
                retry.addEventListener('click', () => {
                    previous.remove();
                    article.remove();
                    const input = document.querySelector('[data-composer-input]');
                    if (input) {
                        input.value = asked;
                        input.style.height = 'auto';
                        input.style.height = `${Math.min(input.scrollHeight, 160)}px`;
                        document.querySelector('[data-composer]')?.requestSubmit();
                    }
                });
                actions.append(retry);
            }

            // Copy
            const copy = document.createElement('button');
            copy.type = 'button';
            copy.setAttribute('data-copy-answer', '');
            copy.className =
                'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium text-slate-500 hover:bg-slate-100 hover:text-slate-800 dark:text-slate-500 dark:hover:bg-white/10 dark:hover:text-white';
            copy.innerHTML = `
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect width="14" height="14" x="8" y="8" rx="2" ry="2"/>
                    <path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>
                </svg>
                <span>Copy</span>
            `;
            actions.append(copy);

            // Read
            const tts = document.createElement('button');
            tts.type = 'button';
            tts.setAttribute('data-tts-button', '');
            tts.className =
                'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium text-slate-500 hover:bg-slate-100 hover:text-slate-800 dark:text-slate-500 dark:hover:bg-white/10 dark:hover:text-white';
            tts.innerHTML = `
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/>
                    <path d="M15.54 8.46a5 5 0 0 1 0 7.07"/>
                    <path d="M19.07 4.93a10 10 0 0 1 0 14.14"/>
                </svg>
                <span>Read</span>
            `;
            actions.append(tts);

            // Thumbs up
            const thumbUp = document.createElement('button');
            thumbUp.type = 'button';
            thumbUp.setAttribute('data-feedback-thumb', 'up');
            thumbUp.className =
                'dm-icon-button rounded-full p-1.5 text-slate-400 dark:text-slate-500 hover:bg-slate-100 hover:text-emerald-600 dark:hover:bg-white/10 dark:hover:text-emerald-400';
            thumbUp.innerHTML = `
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M7 10v12"/>
                    <path d="M15 5.88 14 10h5.83a2 2 0 0 1 1.92 2.56l-2.33 8A2 2 0 0 1 17.5 22H4a2 2 0 0 1-2-2v-8a2 2 0 0 1 2-2h3"/>
                </svg>
            `;
            actions.append(thumbUp);

            // Thumbs down
            const thumbDown = document.createElement('button');
            thumbDown.type = 'button';
            thumbDown.setAttribute('data-feedback-thumb', 'down');
            thumbDown.className =
                'dm-icon-button rounded-full p-1.5 text-slate-400 dark:text-slate-500 hover:bg-slate-100 hover:text-rose-600 dark:hover:bg-white/10 dark:hover:text-rose-400';
            thumbDown.innerHTML = `
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M17 14V2"/>
                    <path d="M9 18.12 10 14H4.17a2 2 0 0 1-1.92-2.56l2.33-8A2 2 0 0 1 6.5 2H20a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2h-3"/>
                </svg>
            `;
            actions.append(thumbDown);

            wrap.append(actions);
        },
    };

    return bubble;
}

function renderSources(bubble, sources) {
    if (!Array.isArray(sources) || sources.length === 0) {
        return;
    }

    const row = document.createElement('div');
    row.className = 'mt-3 flex flex-wrap items-center gap-1.5 pl-10';
    row.dataset.sources = '';

    const label = document.createElement('span');
    label.className = 'mr-1 text-[11px] font-semibold uppercase tracking-wider text-slate-400 dark:text-slate-500';
    label.textContent = 'Sources';
    row.append(label);

    sources.slice(0, 6).forEach((source, index) => {
        const chip = document.createElement('details');
        chip.className = 'group/source max-w-full';

        const summary = document.createElement('summary');
        summary.className =
            'inline-flex cursor-pointer list-none items-center gap-1.5 rounded-full border border-slate-200 bg-slate-50/80 px-2.5 py-1 text-[11px] font-medium text-slate-600 transition hover:border-indigo-300 hover:bg-indigo-50/60 hover:text-indigo-600 dark:border-white/10 dark:bg-white/5 dark:text-slate-600 dark:hover:border-indigo-400 dark:hover:bg-indigo-500/10 dark:hover:text-indigo-300';

        const pageFrom = escapeHtml(source.page_from);
        const pageTo = escapeHtml(source.page_to);
        const pages =
            source.page_from === source.page_to ? `p${pageFrom}` : `p${pageFrom}–${pageTo}`;

        // A round()'d BM25 score can legitimately be 0, so test for the key's
        // presence — the same rule the server-rendered Blade partial uses.
        const isKeyword = Object.prototype.hasOwnProperty.call(source, 'keyword_score');
        const match = isKeyword
            ? 'Keyword match'
            : `${Math.round((Number(source.score) || 0) * 100)}% match`;

        summary.innerHTML = `
            <span class="flex h-3.5 w-3.5 items-center justify-center rounded-full bg-indigo-100 text-[9px] font-bold text-indigo-700 dark:bg-indigo-500/20 dark:text-indigo-300">
                ${index + 1}
            </span>
            <span class="truncate">${match} &middot; ${pages}</span>
        `;

        const snippet = document.createElement('div');
        snippet.className =
            'mt-2 w-80 max-w-[85vw] rounded-2xl border border-slate-200 bg-white dark:bg-[#0d0f15] p-4 text-xs leading-relaxed text-slate-600 shadow-xl dark:border-white/10 dark:text-slate-600';

        snippet.innerHTML = `
            <div class="mb-2 flex items-center justify-between border-b border-slate-100 pb-2 dark:border-white/10">
                <span class="text-[10px] font-bold uppercase tracking-wider text-indigo-600 dark:text-indigo-400">
                    Citation [${index + 1}] &middot; Page ${pageFrom}
                </span>
                <span class="text-[10px] text-slate-400 dark:text-slate-500">chunk #${escapeHtml(source.chunk_index)}</span>
            </div>
            <blockquote class="italic text-slate-700 dark:text-slate-700">
                "${escapeHtml(source.snippet ?? '')}"
            </blockquote>
        `;

        chip.append(summary, snippet);
        row.append(chip);
    });

    const wrap = bubble.article.querySelector('.w-full.max-w-3xl');
    if (wrap) {
        wrap.append(row);
    }

    bubble.stream.scrollTo({ top: bubble.stream.scrollHeight, behavior: 'smooth' });
}

function updateCredits(credits) {
    if (!Number.isFinite(Number(credits))) {
        return;
    }

    const count = Number(credits);

    document.querySelectorAll('[data-credits-count]').forEach((node) => {
        node.textContent = `${count} ${count === 1 ? 'credit' : 'credits'}`;
    });

    document.querySelectorAll('[data-sidebar-credits]').forEach((node) => {
        node.textContent = `${count} left`;
    });
}

/**
 * Banner shown after the assistant hands the chat over to a person: points
 * at the one-to-one support conversation the server just opened. One per
 * transcript, so a second handoff in the same chat does not stack banners.
 *
 * @param  {{url?: string, agent?: string|null}}  payload
 */
function showHandoffNotice(payload) {
    if (!payload?.url) {
        return;
    }

    const transcript = document.querySelector('[data-transcript]');

    if (!transcript || transcript.querySelector('[data-handoff-notice]')) {
        return;
    }

    const notice = document.createElement('div');
    notice.dataset.handoffNotice = '';
    notice.className =
        'mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 rounded-2xl border border-indigo-200 bg-indigo-50 px-4 py-2.5 text-[13px] leading-relaxed text-indigo-900 dark:border-indigo-500/30 dark:bg-indigo-500/10 dark:text-indigo-100';

    const text = document.createElement('span');
    text.className = 'flex-1 min-w-0';
    text.textContent = payload.agent
        ? `${payload.agent} from the support team is joining this chat.`
        : 'A support specialist has been notified and will reply here shortly.';

    const link = document.createElement('a');
    link.href = payload.url;
    link.className = 'shrink-0 font-semibold underline underline-offset-2';
    link.textContent = 'Open support chat';

    notice.append(text, link);
    transcript.firstElementChild?.append(notice);
}

/**
 * Read the SSE reply into the bubble.
 *
 * @param  (() => void)|null  onFirstByte  fired once the stream is confirmed alive
 */
async function readEvents(response, bubble, startTime, onFirstByte = null) {
    const reader = response.body?.getReader();

    if (!reader) {
        throw new Error('This browser cannot read the response stream.');
    }

    const decoder = new TextDecoder();
    const viewport = bubble.stream;
    let buffer = '';
    let accumulatedText = '';
    let follow = true;
    let sourcesCount = 0;
    let completed = false;

    const onScroll = () => {
        follow =
            viewport.scrollHeight - viewport.scrollTop - viewport.clientHeight < 140;
    };

    viewport.addEventListener('scroll', onScroll, { passive: true });

    const followStream = () => {
        if (follow) {
            viewport.scrollTop = viewport.scrollHeight;
        }
    };

    const handle = (frame) => {
        let event = 'message';
        let data = '';

        for (const line of frame.split(/\r?\n/)) {
            if (line.startsWith('event:')) {
                event = line.slice(6).trim();
            } else if (line.startsWith('data:')) {
                // The spec joins repeated `data:` lines with a newline and
                // strips exactly one leading space.
                const chunk = line.slice(5).replace(/^ /, '');

                data += data === '' ? chunk : `\n${chunk}`;
            }
        }

        if (data === '') {
            return;
        }

        let payload;

        try {
            payload = JSON.parse(data);
        } catch {
            return;
        }

        if (event === 'status' && typeof payload.phase === 'string') {
            bubble.setPhase(payload.phase);
            return;
        }

        if (event === 'delta' && typeof payload.text === 'string') {
            if (accumulatedText === '') {
                bubble.hideTyping();
            }

            accumulatedText += payload.text;
            bubble.streamText(accumulatedText);
            followStream();

            return;
        }

        if (event === 'sources') {
            const list = Array.isArray(payload.sources) ? payload.sources : [];
            sourcesCount = list.length;
            renderSources(bubble, list);
            return;
        }

        if (event === 'done') {
            completed = true;

            if (payload.credits !== undefined) {
                updateCredits(payload.credits);
            }

            return;
        }

        if (event === 'handoff') {
            showHandoffNotice(payload);
            return;
        }

        if (event === 'error') {
            throw new Error(
                typeof payload.message === 'string' ? payload.message : 'Something went wrong.',
            );
        }
    };

    const drain = () => {
        let match;

        while ((match = buffer.match(/\r?\n\r?\n/)) !== null) {
            handle(buffer.slice(0, match.index));
            buffer = buffer.slice(match.index + match[0].length);
        }
    };

    try {
        for (;;) {
            const { value, done } = await reader.read();

            if (done) {
                break;
            }

            if (onFirstByte !== null && value && value.length > 0) {
                const notify = onFirstByte;
                onFirstByte = null;
                notify();
            }

            buffer += decoder.decode(value, { stream: true });

            drain();
        }

        buffer += decoder.decode();
        drain();

        if (buffer.trim() !== '') {
            handle(buffer);
        }

        bubble.setMarkdown(accumulatedText);

        if (!completed) {
            // The connection died before `done`; a truncated answer must not be
            // presented as a finished, fully-grounded one.
            bubble.markIncomplete();

            return;
        }

        bubble.hideCaret();
        bubble.hideTyping();

        const durationSec = Math.max(0.4, Math.round(((Date.now() - startTime) / 1000) * 10) / 10);
        bubble.addThought(durationSec, sourcesCount);
    } finally {
        viewport.removeEventListener('scroll', onScroll);
        bubble.hideTyping();
        bubble.hideCaret();
    }
}

function initSidebar() {
    const sidebar = document.querySelector('[data-chat-sidebar]');

    if (!sidebar) {
        return;
    }

    const setOpen = (open) => {
        sidebar.dataset.open = open ? 'true' : 'false';
    };

    setOpen(window.innerWidth >= 1024);

    document.addEventListener('click', (event) => {
        if (event.target.closest('[data-sidebar-toggle]')) {
            setOpen(sidebar.dataset.open !== 'true');
            return;
        }

        // Dismiss drawer on small screens
        if (
            window.innerWidth < 1024
            && sidebar.dataset.open === 'true'
            && !event.target.closest('[data-chat-sidebar]')
        ) {
            setOpen(false);
        }
    });

    // Act only when the viewport crosses the breakpoint, in either direction —
    // reacting to every resize event forced the sidebar open while shrinking.
    let wasDesktop = window.innerWidth >= 1024;

    window.addEventListener('resize', () => {
        const isDesktop = window.innerWidth >= 1024;

        if (isDesktop === wasDesktop) return;

        wasDesktop = isDesktop;
        setOpen(isDesktop);
    });
}

function initSummarize() {
    document.addEventListener('submit', async (event) => {
        const form = event.target.closest('[data-summarize]');

        if (!form) {
            return;
        }

        event.preventDefault();

        const button = form.querySelector('[data-summarize-button]');
        const original = button?.innerHTML;

        if (button) {
            button.disabled = true;
            button.textContent = 'Summarising…';
        }

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                headers: JSON_HEADERS,
                credentials: 'same-origin',
            });

            const data = await response.json().catch(() => ({}));

            if (!response.ok) {
                const message =
                    typeof data.message === 'string'
                        ? data.message
                        : Object.values(data.errors ?? {}).flat()[0] ?? 'The summary could not be generated.';

                throw new Error(message);
            }

            showSummary(data.summary ?? '', data.credits);
            updateCredits(data.credits);
        } catch (error) {
            showToast({
                type: 'error',
                title: 'Summary failed',
                message: error instanceof Error ? error.message : 'The summary could not be generated.',
            });
        } finally {
            if (button) {
                button.disabled = false;
                button.innerHTML = original ?? 'Summarise';
            }
        }
    });
}

function showSummary(summary, credits) {
    const overlay = document.createElement('div');
    overlay.className = 'fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm';

    const panel = document.createElement('div');
    panel.className = 'w-full max-w-2xl rounded-2xl border border-slate-200 bg-white dark:bg-[#0d0f15] p-6 shadow-2xl dark:border-white/10';
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-modal', 'true');

    const heading = document.createElement('h2');
    heading.className = 'text-base font-bold text-slate-900 dark:text-white';
    heading.textContent = 'Executive Summary';

    const body = document.createElement('div');
    body.className = 'grok-prose mt-3 max-h-[55vh] overflow-y-auto text-sm leading-relaxed text-slate-700 dark:text-slate-600';
    body.innerHTML = parseMarkdown(summary);

    const footer = document.createElement('div');
    footer.className = 'mt-6 flex items-center justify-between gap-3 border-t border-slate-100 pt-3 dark:border-white/10';

    const note = document.createElement('span');
    note.className = 'text-xs text-slate-400 dark:text-slate-500';
    note.textContent = `${credits} credits remaining`;

    const close = document.createElement('button');
    close.type = 'button';
    close.className =
        'rounded-xl bg-slate-900 px-4 py-2 text-xs font-semibold text-white transition hover:bg-slate-800 dark:bg-white dark:text-black dark:hover:bg-slate-200';
    close.textContent = 'Done';

    const onKey = (event) => {
        if (event.key === 'Escape') {
            dismiss();
        }
    };

    // Must remove the listener on every exit path, not just Escape — otherwise
    // each dismissed modal leaks a document-level handler.
    const dismiss = () => {
        document.removeEventListener('keydown', onKey);
        overlay.remove();
    };

    close.addEventListener('click', dismiss);
    overlay.addEventListener('click', (event) => {
        if (event.target === overlay) {
            dismiss();
        }
    });
    document.addEventListener('keydown', onKey);

    footer.append(note, close);
    panel.append(heading, body, footer);
    overlay.append(panel);
    document.body.append(overlay);
    close.focus();
}

function renderExistingAssistantMessages() {
    document.querySelectorAll('[data-message][data-role="assistant"]').forEach(article => {
        const content = article.querySelector('[data-message-content]');

        if (!content || content.dataset.rendered === 'true') {
            return;
        }

        const raw = content.textContent || '';

        // Empty, pending or failed answers have nothing to format. Mark them
        // so a later pass cannot try again.
        if (raw.trim() === '' || raw.trim() === '…') {
            content.dataset.rendered = 'true';
            return;
        }

        content.dataset.rawText = raw;
        content.innerHTML = parseMarkdown(raw);
        content.dataset.rendered = 'true';
    });
}

function preventBrowserNavigationOnDrop() {
    ['dragover', 'drop'].forEach((type) => {
        window.addEventListener(type, (event) => event.preventDefault());
    });
}

function initWidgetCustomization() {
    const form = document.querySelector('[data-widget-customization]');
    const preview = document.querySelector('[data-widget-preview]');

    if (!form || !preview) return;

    const field = (name) => form.elements.namedItem(name);
    const panel = preview.querySelector('[data-preview-panel]');
    const avatar = preview.querySelector('[data-preview-avatar]');
    const liveAvatar = document.querySelector('[data-avatar-live]');
    const launcherBlob = preview.querySelector('[data-preview-launcher-blob]');
    let stopAvatarGaze = bindBlobatarGaze(preview, avatar);
    let stopLauncherGaze = launcherBlob ? bindBlobatarGaze(preview, launcherBlob) : () => {};
    let trackedAvatar = avatar.querySelector('[data-blobatar-image]');
    let trackedLauncher = launcherBlob?.querySelector('[data-blobatar-image]') ?? null;
    const title = preview.querySelector('[data-preview-title]');
    const previewHeader = title.parentElement;
    const greeting = preview.querySelector('[data-preview-greeting]');
    const launcherRow = preview.querySelector('[data-preview-launcher-row]');
    const previewStatus = document.querySelector('[data-preview-status]');

    /** The "Auto" checkbox means: derive this from the seed — leave it out. */
    const autoChecked = (name) => Boolean(form.querySelector(`[data-auto-toggle="${name}"]`)?.checked);

    /** Exactly what the widget will receive for the avatar controls. */
    const currentAvatarOptions = () => ({
        background: field('blobatar_background').value || 'squircle',
        hue: autoChecked('blobatar_hue') ? undefined : Number(field('blobatar_hue').value),
        tone: autoChecked('blobatar_tone') ? undefined : Number(field('blobatar_tone').value),
        expression: field('blobatar_expression').value || 'idle',
        animate: field('blobatar_animation').value || 'live',
    });

    const currentSeed = () => field('blobatar_seed').value.trim()
        || field('bot_name').value.trim()
        || 'Assistant';

    /** Keep a slider's disabled state and readout in step with its Auto box. */
    const syncRange = (range) => {
        if (!range) return;

        const auto = autoChecked(range.name);
        const output = range.parentElement?.querySelector('[data-range-output]');
        range.disabled = auto;

        if (output) {
            output.textContent = auto
                ? 'Auto'
                : (range.max === '360' ? `${range.value}°` : range.value);
        }
    };

    const update = () => {
        const accent = field('accent_color').value || '#4f46e5';
        const theme = field('theme').value;
        const light = theme === 'light'
            || (theme === 'system' && window.matchMedia?.('(prefers-color-scheme: light)').matches);
        const position = field('position').value;
        const logoUrl = field('logo_url').value.trim();
        const rgb = /^#([\da-f]{2})([\da-f]{2})([\da-f]{2})$/i.exec(accent);
        const brightness = rgb
            ? rgb.slice(1).map((part) => parseInt(part, 16)).reduce((sum, value) => sum + value, 0) / 3
            : 0;
        const accentText = brightness > 150 ? '#10131a' : '#ffffff';

        title.textContent = field('bot_name').value.trim() || 'Assistant';
        greeting.textContent = field('greeting').value.trim() || 'Hi! I can help with the product. What do you need help with?';
        launcherRow.style.justifyContent = position === 'bottom-left' ? 'flex-start' : 'flex-end';
        panel.style.alignSelf = position === 'bottom-left' ? 'flex-start' : 'flex-end';
        launcherBlob.parentElement.style.backgroundColor = accent;
        launcherBlob.parentElement.style.color = accentText;
        avatar.style.backgroundColor = accent;
        avatar.style.color = accentText;
        preview.style.backgroundColor = light ? '#eef2f7' : '#090a0f';
        preview.style.borderColor = light ? '#d8e0eb' : '#252a35';
        panel.style.backgroundColor = light ? '#ffffff' : '#0d0f15';
        panel.style.borderColor = light ? '#dce3ed' : 'rgba(255, 255, 255, 0.12)';
        previewHeader.style.borderBottomColor = light ? '#e2e8f0' : 'rgba(255, 255, 255, 0.1)';
        title.style.color = light ? '#172033' : '#ffffff';
        greeting.style.backgroundColor = light ? '#f1f5f9' : 'rgba(255, 255, 255, 0.05)';
        greeting.style.color = light ? '#334155' : '#cbd5e1';

        const seed = currentSeed();
        const blobOptions = currentAvatarOptions();

        if (logoUrl && /^https?:\/\//i.test(logoUrl)) {
            if (avatar.querySelector('img')?.getAttribute('src') !== logoUrl) {
                const image = document.createElement('img');
                image.src = logoUrl;
                image.alt = '';
                image.className = 'h-full w-full rounded-md object-cover';
                image.addEventListener('error', () => {
                    if (avatar.contains(image)) {
                        setBlobatar(avatar, seed, blobOptions);
                        refreshAvatarGaze();
                    }
                }, { once: true });
                avatar.replaceChildren(image);
            }
        } else {
            setBlobatar(avatar, seed, blobOptions);
        }

        // The launcher always wears the blobatar (the logo is a header/hint
        // treatment in the real widget), and the live sample in the settings
        // card mirrors the same options — what you see here is what ships.
        if (launcherBlob) setBlobatar(launcherBlob, seed, blobOptions);
        if (liveAvatar) setBlobatar(liveAvatar, seed, blobOptions);

        refreshAvatarGaze();

        preview.dataset.previewTheme = theme;
    };

    function refreshAvatarGaze() {
        const currentAvatar = avatar.querySelector('[data-blobatar-image]');

        if (currentAvatar !== trackedAvatar) {
            stopAvatarGaze();
            trackedAvatar = currentAvatar;
            stopAvatarGaze = bindBlobatarGaze(preview, avatar);
        }

        const currentLauncher = launcherBlob?.querySelector('[data-blobatar-image]') ?? null;

        if (currentLauncher !== trackedLauncher) {
            stopLauncherGaze();
            trackedLauncher = currentLauncher;
            if (launcherBlob) stopLauncherGaze = bindBlobatarGaze(preview, launcherBlob);
        }
    }

    const customizationFields = [
        'bot_name', 'greeting', 'accent_color', 'logo_url', 'position', 'theme', 'launcher_icon',
        'collect_email', 'visitor_retention_days',
        'blobatar_seed', 'blobatar_size', 'blobatar_background', 'blobatar_expression', 'blobatar_animation',
    ];
    const handleCustomization = () => {
        update();
        if (previewStatus) previewStatus.textContent = 'Unsaved changes';
    };

    customizationFields.forEach((name) => {
        field(name).addEventListener('input', handleCustomization);
        field(name).addEventListener('change', handleCustomization);
    });

    form.querySelectorAll('[data-range]').forEach((range) => {
        range.addEventListener('input', () => {
            syncRange(range);
            handleCustomization();
        });
    });

    form.querySelectorAll('[data-auto-toggle]').forEach((box) => {
        box.addEventListener('change', () => {
            syncRange(field(box.dataset.autoToggle));
            handleCustomization();
        });
        syncRange(field(box.dataset.autoToggle));
    });

    form.querySelector('[data-widget-reset]')?.addEventListener('click', () => {
        const defaults = {
            bot_name: 'Assistant',
            greeting: 'Hi! I can help with the product. What do you need help with?',
            accent_color: '#4f46e5',
            logo_url: '',
            position: 'bottom-left',
            theme: 'dark',
            launcher_icon: 'brand',
            blobatar_seed: '',
            blobatar_size: '40',
            blobatar_background: 'squircle',
            blobatar_expression: 'idle',
            blobatar_animation: 'live',
            blobatar_hue: '210',
            blobatar_tone: '0.5',
        };

        Object.entries(defaults).forEach(([name, value]) => {
            field(name).value = value;
        });

        form.querySelectorAll('[data-auto-toggle]').forEach((box) => {
            box.checked = true;
            syncRange(field(box.dataset.autoToggle));
        });

        update();
        if (previewStatus) previewStatus.textContent = 'Defaults restored, not yet saved';
    });

    window.matchMedia?.('(prefers-color-scheme: light)').addEventListener?.('change', () => {
        if (field('theme').value === 'system') update();
    });

    update();
}

/**
 * Framework tabs on the widget install panel. Only one panel is visible at a
 * time, and arrow keys move between tabs as the tablist role requires.
 */
/*
|--------------------------------------------------------------------------
| Install Embed Code: copy + verify
|--------------------------------------------------------------------------
| Registered from boot() rather than initChat() — the widget screen has no
| composer, so scoping these to the chat engine silently disabled them.
*/
function initInstallActions() {
    const section = document.querySelector('[data-install]');

    if (!section) return;

    document.addEventListener('click', async (event) => {
        const btn = event.target.closest('[data-copy-target]');
        if (!btn) return;

        // Prefer the specific tab so each framework copies its own snippet.
        const key = btn.dataset.copyFor;
        const source = key
            ? document.querySelector(`[data-snippet-source="${key}"]`)
            : btn.parentElement?.querySelector('[data-copy-source]');

        if (!source) return;

        const code = (source.textContent ?? '').trim();
        const span = btn.querySelector('span');
        const original = span ? span.textContent : 'Copy';

        const flash = (label, classes) => {
            if (span) span.textContent = label;
            btn.classList.add(...classes);
            window.setTimeout(() => {
                if (span) span.textContent = original;
                btn.classList.remove(...classes);
            }, 2000);
        };

        try {
            await navigator.clipboard.writeText(code);
            flash('Copied!', COPIED_CLASSES);
            toast('Embed code copied to clipboard.');
        } catch {
            // Clipboard API is blocked on insecure origins and in some
            // embeds — fall back to a selection so the code is still grabbable.
            const range = document.createRange();
            range.selectNodeContents(source);
            const selection = window.getSelection();
            selection?.removeAllRanges();
            selection?.addRange(range);

            const copied = copyFallback(code);

            if (copied) {
                flash('Copied!', COPIED_CLASSES);
                toast('Embed code copied to clipboard.');
            } else {
                toast('Select the code and press Ctrl/Cmd + C to copy it.');
            }
        }
    });

    // Verify that the published bundle is reachable from this installation.
    document.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-verify-install]');
        if (!button) return;

        const status = document.querySelector('[data-install-status]');
        const label = status?.querySelector('span:last-child');
        const original = label?.textContent ?? 'Widget bundle ready';

        if (label) label.textContent = 'Checking…';
        button.disabled = true;

        try {
            const url = section.dataset.widgetScriptUrl || '/widget.js';
            const response = await fetch(url, { headers: { Accept: 'application/javascript' } });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const body = await response.text();

            if (body.length < 1024 || !body.includes('DocuMindWidget')) {
                throw new Error('the response is not the widget bundle');
            }

            status?.classList.remove('border-amber-200', 'bg-amber-50', 'text-amber-800', 'dark:border-amber-500/30', 'dark:bg-amber-500/10', 'dark:text-amber-300');
            status?.classList.add('border-emerald-200', 'bg-emerald-50', 'text-emerald-700', 'dark:border-emerald-500/30', 'dark:bg-emerald-500/10', 'dark:text-emerald-400');

            if (label) label.textContent = 'Bundle served correctly';
            toast(`widget.js is live (${Math.round(body.length / 1024)} KB). Open the live preview to test a message.`);
        } catch (error) {
            status?.classList.add('border-rose-200', 'bg-rose-50', 'text-rose-700', 'dark:border-rose-500/30', 'dark:bg-rose-500/10', 'dark:text-rose-300');

            if (label) label.textContent = 'Bundle not served';
            toast(`The widget bundle is not being served (${error.message}). Run "npm run build".`);
        } finally {
            button.disabled = false;
            window.setTimeout(() => {
                if (label) label.textContent = original;
            }, 4000);
        }
    });
}

function initInstallSnippets() {
    const tabs = document.querySelector('[data-snippet-tabs]');

    if (!tabs) return;

    const buttons = Array.from(tabs.querySelectorAll('[data-snippet-tab]'));
    const panels = Array.from(document.querySelectorAll('[data-snippet-panel]'));
    const scrollControls = Array.from(document.querySelectorAll('[data-snippet-scroll]'));

    const ACTIVE = ['bg-slate-900', 'text-white', 'shadow-xs', 'dark:bg-white', 'dark:text-black'];
    const INACTIVE = [
        'border', 'border-slate-200', 'bg-white', 'text-slate-600',
        'hover:border-indigo-300', 'hover:text-indigo-600',
        'dark:border-white/10', 'dark:bg-white/5', 'dark:text-slate-400',
        'dark:hover:border-indigo-400', 'dark:hover:text-indigo-300',
    ];

    const fades = Array.from(document.querySelectorAll('[data-snippet-fade]'));

    const updateScrollControls = () => {
        const maxScroll = Math.max(0, tabs.scrollWidth - tabs.clientWidth);
        const previous = scrollControls.find((control) => control.dataset.snippetScroll === 'previous');
        const next = scrollControls.find((control) => control.dataset.snippetScroll === 'next');
        const atStart = tabs.scrollLeft <= 1;
        const atEnd = maxScroll <= 1 || tabs.scrollLeft >= maxScroll - 1;

        if (previous) previous.disabled = atStart;
        if (next) next.disabled = atEnd;

        fades.forEach((fade) => {
            const looksRightward = fade.dataset.snippetFade === 'next';
            const edgeReached = looksRightward ? atEnd : atStart;

            fade.dataset.visible = edgeReached ? 'false' : 'true';
        });
    };

    scrollControls.forEach((control) => {
        control.addEventListener('click', () => {
            const direction = control.dataset.snippetScroll === 'next' ? 1 : -1;
            tabs.scrollBy({ left: direction * Math.max(160, tabs.clientWidth * 0.75), behavior: 'smooth' });
        });
    });

    tabs.addEventListener('scroll', updateScrollControls, { passive: true });
    window.addEventListener('resize', updateScrollControls);

    tabs.addEventListener('wheel', (event) => {
        if (Math.abs(event.deltaY) <= Math.abs(event.deltaX) || event.shiftKey) {
            return;
        }

        event.preventDefault();
        tabs.scrollLeft += event.deltaY;
    }, { passive: false });

    let touchStartX = 0;
    tabs.addEventListener('touchstart', (event) => {
        touchStartX = event.touches[0]?.clientX ?? 0;
    }, { passive: true });

    tabs.addEventListener('touchmove', (event) => {
        const touchCurrentX = event.touches[0]?.clientX ?? touchStartX;
        const deltaX = touchCurrentX - touchStartX;

        if (Math.abs(deltaX) > 8) {
            tabs.scrollLeft -= deltaX;
            touchStartX = touchCurrentX;
        }
    }, { passive: true });

    // The picked integration must survive closing the panel, switching
    // dashboard tabs, and coming back to this page later.
    const STORAGE_KEY = 'documind:install-snippet';

    const readSaved = () => {
        try {
            return window.localStorage.getItem(STORAGE_KEY);
        } catch {
            return null;
        }
    };

    const save = (key) => {
        try {
            window.localStorage.setItem(STORAGE_KEY, key);
        } catch {
            // Private mode or blocked storage — keep the choice for this visit only.
        }
    };

    // Scrolls the row itself, never the page: a restored tab that sits outside
    // the visible strip must come into view without yanking the viewport.
    const reveal = (button) => {
        const left = button.offsetLeft;
        const right = left + button.offsetWidth;
        const viewRight = tabs.scrollLeft + tabs.clientWidth;

        if (left < tabs.scrollLeft) {
            tabs.scrollTo({ left: Math.max(0, left - 8), behavior: 'smooth' });
        } else if (right > viewRight) {
            tabs.scrollTo({ left: right - tabs.clientWidth + 8, behavior: 'smooth' });
        }
    };

    const select = (key) => {
        buttons.forEach((button) => {
            const active = button.dataset.snippetTab === key;

            button.classList.remove(...(active ? INACTIVE : ACTIVE));
            button.classList.add(...(active ? ACTIVE : INACTIVE));
            button.setAttribute('aria-selected', active ? 'true' : 'false');
            button.tabIndex = active ? 0 : -1;

            if (active) reveal(button);
        });

        panels.forEach((panel) => {
            const active = panel.dataset.snippetPanel === key;

            panel.classList.toggle('hidden', !active);
            panel.setAttribute('aria-hidden', active ? 'false' : 'true');
        });

        save(key);
    };

    tabs.addEventListener('click', (event) => {
        const button = event.target.closest('[data-snippet-tab]');

        if (button) {
            select(button.dataset.snippetTab);
        }
    });

    tabs.addEventListener('keydown', (event) => {
        const index = buttons.findIndex((button) => button.dataset.snippetTab === event.target.dataset?.snippetTab);

        if (index === -1) return;

        let next = null;

        if (event.key === 'ArrowRight') next = (index + 1) % buttons.length;
        if (event.key === 'ArrowLeft') next = (index - 1 + buttons.length) % buttons.length;
        if (event.key === 'Home') next = 0;
        if (event.key === 'End') next = buttons.length - 1;

        if (event.key === 'PageDown') next = Math.min(buttons.length - 1, index + 3);
        if (event.key === 'PageUp') next = Math.max(0, index - 3);

        if (next === null) return;

        event.preventDefault();
        select(buttons[next].dataset.snippetTab);
        buttons[next].focus();
    });

    const saved = readSaved();
    const restored = saved !== null && buttons.some((button) => button.dataset.snippetTab === saved);

    const initial = restored ? saved : buttons[0]?.dataset.snippetTab;

    if (initial) {
        select(initial);
    }

    updateScrollControls();

    // Inter swaps in after first paint and can widen the pills enough to
    // change which side of the strip still has options to scroll to.
    if (document.fonts?.ready) {
        document.fonts.ready.then(updateScrollControls);
    }
}

/*
|--------------------------------------------------------------------------
| Mobile Navigation Drawer
|--------------------------------------------------------------------------
*/
function initMobileNav() {
    const nav = document.querySelector('[data-mobile-nav]');
    if (!nav) return;

    const toggle = document.querySelector('[data-mobile-nav-toggle]');
    const close = document.querySelector('[data-mobile-nav-close]');
    const backdrop = document.querySelector('[data-mobile-nav-backdrop]');

    const setOpen = (open) => {
        if (open) {
            nav.classList.add('open');
            document.body.style.overflow = 'hidden';
        } else {
            nav.classList.remove('open');
            document.body.style.overflow = '';
        }
    };

    toggle?.addEventListener('click', () => setOpen(true));
    close?.addEventListener('click', () => setOpen(false));
    backdrop?.addEventListener('click', () => setOpen(false));
}

/*
|--------------------------------------------------------------------------
| User Dropdown (replaces native <details> with click-outside-close)
|--------------------------------------------------------------------------
*/
function initUserDropdown() {
    const container = document.querySelector('[data-user-dropdown]');
    if (!container) return;

    const toggle = container.querySelector('[data-user-dropdown-toggle]');
    const panel = container.querySelector('[data-user-dropdown-panel]');
    const chevron = container.querySelector('[data-user-dropdown-chevron]');
    let isOpen = false;

    const setOpen = (open) => {
        isOpen = open;
        panel.style.display = open ? 'block' : 'none';
        if (chevron) {
            chevron.style.transform = open ? 'rotate(180deg)' : '';
        }
    };

    toggle?.addEventListener('click', (e) => {
        e.stopPropagation();
        setOpen(!isOpen);
    });

    document.addEventListener('click', (e) => {
        if (isOpen && !container.contains(e.target)) {
            setOpen(false);
        }
    });

    document.addEventListener('keydown', (e) => {
        if (isOpen && e.key === 'Escape') {
            setOpen(false);
        }
    });
}

/*
|--------------------------------------------------------------------------
| Widget Site Deletion Confirmation
|--------------------------------------------------------------------------
*/
function initWidgetSiteDelete() {
    document.addEventListener('submit', (event) => {
        const form = event.target.closest('[data-delete-site]');
        if (!form) return;

        guardFormSubmit(event, {
            title: 'Delete this widget site?',
            message: 'All visitor data and analytics will be permanently lost.',
            confirmText: 'Delete site',
            danger: true,
        });
    });
}

/**
 * Rotating the key invalidates every copy of the snippet already installed on
 * a customer's site, so it must not be a single accidental click.
 */
function initSiteRotateKey() {
    document.addEventListener('submit', (event) => {
        const form = event.target.closest('[data-rotate-key]');
        if (!form) return;

        guardFormSubmit(event, {
            title: 'Generate a new site key?',
            message: 'Every page already using the current embed code will stop working until you paste the new one.',
            confirmText: 'Rotate key',
            danger: true,
        });
    });
}

function initAdminConfirmations() {
    document.addEventListener('submit', (event) => {
        const form = event.target.closest('[data-admin-confirm]');

        if (!form) return;

        guardFormSubmit(event, {
            title: form.dataset.adminConfirm || 'Confirm this administrative action?',
            confirmText: 'Confirm',
            danger: true,
        });
    });
}

function initFirebaseAuth() {
    if (!document.querySelector('[data-google-login], [data-firebase-logout]')) return;

    import('./firebase-auth.js').then(({ bindFirebaseAuth }) => {
        bindFirebaseAuth();
    }).catch(() => {
        document.querySelectorAll('[data-google-login-status]').forEach((status) => {
            status.textContent = 'Google sign-in could not load. Refresh the page and try again.';
            status.classList.add('text-rose-600', 'dark:text-rose-400');
        });
    });
}

function initVisitorInbox() {
    const section = document.querySelector('[data-lead-inbox]');
    const list = section?.querySelector('[data-lead-list]');

    if (!section || !list) return;

    const refreshButton = section.querySelector('[data-lead-refresh]');
    const updated = section.querySelector('[data-lead-updated]');
    let refreshing = false;

    list.addEventListener('submit', (event) => {
        if (!event.target.closest('[data-lead-delete]')) return;

        guardFormSubmit(event, {
            title: 'Delete this visitor email and transcript?',
            message: 'This cannot be undone.',
            confirmText: 'Delete',
            danger: true,
        });
    });

    const refresh = async (manual = false) => {
        if (refreshing || (!manual && (
            document.visibilityState !== 'visible'
            || list.contains(document.activeElement)
            || list.querySelector('details[open]')
        ))) {
            return;
        }

        refreshing = true;

        if (refreshButton) refreshButton.disabled = true;

        try {
            const response = await fetch(section.dataset.refreshUrl, {
                headers: { Accept: 'application/json' },
                cache: 'no-store',
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            const data = await response.json();
            list.innerHTML = data.html;

            Object.entries(data.totals || {}).forEach(([key, value]) => {
                const target = section.querySelector(`[data-lead-total="${key}"]`);
                if (target) target.textContent = Number(value).toLocaleString();
            });

            if (updated) updated.textContent = `Updated ${data.updated_at || 'just now'}`;
        } catch {
            if (updated) updated.textContent = 'Refresh failed';
        } finally {
            refreshing = false;
            if (refreshButton) refreshButton.disabled = false;
        }
    };

    refreshButton?.addEventListener('click', () => refresh(true));
    window.setInterval(() => refresh(), 30000);
}

/**
 * Live activity dashboard. Polls the analytics endpoint (keeping the current
 * range/search filters) and patches the numbers, charts and transcript feed in
 * place so the page never has to reload while visitors are using the widget.
 */
function initActivityDashboard() {
    const section = document.querySelector('[data-activity][data-refresh-url]');

    if (!section) return;

    const updated = section.querySelector('[data-activity-updated]');
    const feed = section.querySelector('[data-activity-feed]');
    const failureList = section.querySelector('[data-failure-list]');
    const failureEmpty = section.querySelector('[data-failure-empty]');
    const offlineBanner = section.querySelector('[data-activity-banner="offline"]');
    const errorBanner = section.querySelector('[data-activity-banner="error"]');

    let refreshing = false;

    const pick = (data, path) => path
        .split('.')
        .reduce((value, key) => (value === null || value === undefined ? value : value[key]), data);

    const formatMetric = (value, format) => {
        const number = Number(value);

        if (!Number.isFinite(number)) return null;

        return format === 'int'
            ? Math.round(number).toLocaleString()
            : String(Math.round(number));
    };

    const applyMetric = (element, value) => {
        const formatted = formatMetric(value, element.dataset.format);

        if (formatted === null) return;

        // Keep whatever the server put around the number ("12 failed", "45%").
        const parts = /^([^\d]*)(-?[\d,]+)(.*)$/.exec(element.textContent.trim());

        element.textContent = parts ? `${parts[1]}${formatted}${parts[3]}` : formatted;
    };

    const applyOutcomes = (outcomes) => {
        let offset = 0;

        outcomes.forEach((outcome) => {
            const value = section.querySelector(`[data-outcome-value="${outcome.key}"]`);
            if (value) value.textContent = Math.round(Number(outcome.value) || 0).toLocaleString();

            const segment = section.querySelector(`[data-outcome-segment="${outcome.key}"]`);
            if (!segment) return;

            const percent = Math.max(0, Math.min(100, Number(outcome.percent) || 0));

            segment.style.strokeDasharray = `${percent} ${100 - percent}`;
            segment.style.strokeDashoffset = String(-offset);

            const title = segment.querySelector('title');
            if (title) title.textContent = `${outcome.label}: ${outcome.value} (${outcome.percent}%)`;

            offset += percent;
        });
    };

    const applyFailures = (failures, totalFailed) => {
        if (!failureList || !failureEmpty) return;

        const empty = failures.length === 0;

        failureEmpty.toggleAttribute('hidden', !empty);
        failureList.toggleAttribute('hidden', empty);

        if (empty) return;

        failureList.innerHTML = failures.map((failure) => {
            const width = totalFailed > 0
                ? Math.max(6, Math.round((Number(failure.value) || 0) / totalFailed * 100))
                : 0;

            return `
                <li title="${escapeHtml(failure.hint ?? '')}">
                    <div class="flex items-baseline justify-between gap-2 text-xs">
                        <span class="font-semibold text-slate-800 dark:text-slate-100">${escapeHtml(failure.label ?? '')}</span>
                        <span class="tabular-nums text-slate-500 dark:text-slate-400">${escapeHtml(String(failure.value ?? 0))}</span>
                    </div>
                    <div class="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-white/10">
                        <span class="block h-full rounded-full bg-rose-500" style="width: ${width}%"></span>
                    </div>
                    <p class="mt-1 text-[11px] leading-snug text-slate-500 dark:text-slate-400">${escapeHtml(failure.hint ?? '')}</p>
                </li>`;
        }).join('');
    };

    const applyDaily = (days) => {
        const entries = Object.entries(days || {});

        if (entries.length === 0) return;

        const chartMax = Math.max(
            1,
            ...entries.map(([, values]) => Number(values.messages) || 0),
        );

        entries.forEach(([day, values]) => {
            const column = section.querySelector(`[data-day="${day}"]`);

            if (!column) return;

            const total = Number(values.messages) || 0;

            column.title = `${day} — ${total} messages `
                + `(${Number(values.successful) || 0} answered, `
                + `${Number(values.fallback) || 0} fallback, `
                + `${Number(values.failed) || 0} failed)`;

            ['failed', 'fallback', 'successful'].forEach((key) => {
                const bar = column.querySelector(`[data-bar="${key}"]`);

                if (!bar) return;

                const amount = Number(values[key]) || 0;

                bar.style.height = `${total > 0 ? Math.max(2, Math.round(amount / chartMax * 100)) : 0}%`;
            });
        });
    };

    const apply = (data) => {
        section.querySelectorAll('[data-metric]').forEach((element) => {
            const value = pick(data, element.dataset.metric);

            if (value !== null && value !== undefined) applyMetric(element, value);
        });

        section.querySelectorAll('[data-metric-bar]').forEach((element) => {
            const value = Number(pick(data, element.dataset.metricBar));

            if (Number.isFinite(value)) {
                element.style.width = `${Math.max(0, Math.min(100, value))}%`;
            }
        });

        applyOutcomes(data.outcomes || []);
        applyFailures(data.failures || [], Number(data.totals?.failed) || 0);
        applyDaily(data.daily?.days || {});

        if (feed && typeof data.feed_html === 'string') feed.innerHTML = data.feed_html;
        if (updated && data.updated_at) updated.textContent = data.updated_at;
    };

    const refresh = async () => {
        if (refreshing) return;

        if (navigator.onLine === false) {
            offlineBanner?.removeAttribute('hidden');
            return;
        }

        if (document.visibilityState !== 'visible'
            || (feed && (feed.contains(document.activeElement) || feed.querySelector('details[open]')))) {
            return;
        }

        refreshing = true;

        try {
            const response = await fetch(`${section.dataset.refreshUrl}${window.location.search}`, {
                headers: { Accept: 'application/json' },
                cache: 'no-store',
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            apply(await response.json());

            offlineBanner?.setAttribute('hidden', '');
            errorBanner?.setAttribute('hidden', '');
        } catch {
            if (navigator.onLine === false) offlineBanner?.removeAttribute('hidden');
            else errorBanner?.removeAttribute('hidden');
        } finally {
            refreshing = false;
        }
    };

    window.addEventListener('offline', () => offlineBanner?.removeAttribute('hidden'));
    window.addEventListener('online', () => {
        offlineBanner?.setAttribute('hidden', '');
        refresh();
    });
    window.setInterval(refresh, 20000);
}

/**
 * Landing page (resources/views/landing.blade.php). Registered right after
 * hydrateBlobatars so every avatar the markup declares is already drawn when
 * we bind gaze and stamp expressions onto the story/feature cards.
 */
function initLanding() {
    const landing = document.querySelector('[data-landing]');

    if (!landing) return;

    initLandingMenu(landing);
    initLandingShowcase(landing);
    initLandingReveals(landing);
    initLandingSources(landing);
    initLandingStory(landing);
    initLandingChatPreview(landing);
    initLandingWidgetDemo(landing);
    initLandingPlatform(landing);
    initLandingSnippets(landing);
}

function initLandingMenu(landing) {
    const toggle = landing.querySelector('[data-landing-menu-toggle]');
    const menu = landing.querySelector('[data-landing-menu]');

    if (!toggle || !menu) return;

    const setOpen = (open) => {
        menu.hidden = !open;
        toggle.setAttribute('aria-expanded', String(open));
        toggle.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
    };

    toggle.addEventListener('click', () => setOpen(menu.hidden));

    menu.addEventListener('click', (event) => {
        if (event.target.closest('a')) setOpen(false);
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !menu.hidden) setOpen(false);
    });
}

/**
 * The hero showcase: a muted, looping product tour that plays only while it
 * is on screen — and only when the visitor hasn't asked for reduced motion or
 * reduced data, in which case the poster stays until they press play. Play,
 * pause, mute and replay live in the frame's chrome bar; around the frame a
 * carousel of floating support moments (visitor question, typing, grounded
 * answer, email capture, human handoff) advances on a timer, each one
 * individually closeable, all dismissible and replayable as a group.
 */
function initLandingShowcase(landing) {
    const showcase = landing.querySelector('[data-showcase]');

    if (!showcase) return;

    const frame = showcase.querySelector('[data-showcase-frame]');
    const video = showcase.querySelector('[data-showcase-video]');
    const toggle = showcase.querySelector('[data-video-toggle]');
    const muteButton = showcase.querySelector('[data-video-mute]');
    const restartButton = showcase.querySelector('[data-video-replay]');
    const layer = showcase.querySelector('[data-popups]');
    const momentsReplay = showcase.querySelector('[data-popups-replay]');
    const momentsDismiss = showcase.querySelector('[data-popups-dismiss]');
    const popups = Array.from(showcase.querySelectorAll('[data-popup]'));

    const reduced = prefersReducedMotion();
    const saveData = Boolean(navigator.connection && navigator.connection.saveData);

    /* ── Floating moments ───────────────────────────────────── */
    let popupIndex = 0;
    let popupTimer = 0;
    let momentsHidden = false;
    const closed = new Set();

    const nextOpen = (from) => {
        for (let step = 1; step <= popups.length; step += 1) {
            const candidate = (from + step) % popups.length;

            if (!closed.has(candidate)) return candidate;
        }

        return -1;
    };

    const paintMoments = () => {
        popups.forEach((popup, index) => {
            const isClosed = closed.has(index);
            const show = !momentsHidden
                && !isClosed
                && (reduced || index === popupIndex);

            popup.classList.toggle('is-closed', isClosed);
            popup.classList.toggle('is-active', show);
        });

        if (layer) layer.dataset.popupsState = momentsHidden ? 'off' : 'on';
    };

    const scheduleNext = () => {
        window.clearTimeout(popupTimer);

        if (reduced || momentsHidden || popups.length === 0) return;

        popupTimer = window.setTimeout(() => {
            const next = nextOpen(popupIndex);

            if (next < 0) return;

            popupIndex = next;
            paintMoments();
            scheduleNext();
        }, 3400);
    };

    function replayMoments() {
        closed.clear();
        momentsHidden = false;
        popupIndex = 0;
        paintMoments();
        scheduleNext();
    }

    layer?.addEventListener('click', (event) => {
        const close = event.target.closest('[data-popup-close]');

        if (!close) return;

        const popup = close.closest('[data-popup]');
        const index = popups.indexOf(popup);

        if (index < 0) return;

        closed.add(index);

        const next = nextOpen(index);

        if (next >= 0) popupIndex = next;

        paintMoments();
        scheduleNext();
    });

    momentsReplay?.addEventListener('click', () => replayMoments());

    momentsDismiss?.addEventListener('click', () => {
        momentsHidden = true;
        window.clearTimeout(popupTimer);
        paintMoments();
    });

    paintMoments();

    if (!reduced) scheduleNext();

    /* ── The tour itself ────────────────────────────────────── */
    const setVideoState = (playing) => {
        if (frame) frame.dataset.videoState = playing ? 'playing' : 'paused';
        if (toggle) toggle.setAttribute('aria-label', playing ? 'Pause the demo video' : 'Play the demo video');
    };

    const playVideo = () => {
        if (!video) return;

        const attempt = video.play();

        if (attempt && typeof attempt.catch === 'function') {
            attempt.catch(() => setVideoState(false));
        }

        setVideoState(true);
    };

    const pauseVideo = () => {
        if (!video) return;

        video.pause();
        setVideoState(false);
    };

    const syncMuteLabel = () => {
        if (!video || !muteButton) return;

        muteButton.setAttribute('aria-label', video.muted ? 'Unmute the demo video' : 'Mute the demo video');
        if (frame) frame.dataset.videoMuted = String(video.muted);
    };

    if (video) {
        video.muted = true;
        syncMuteLabel();

        if (reduced || saveData) {
            /* Poster only: autoplaying would override an explicit preference,
               so the visitor starts the tour themselves. */
            setVideoState(false);
        } else if (typeof IntersectionObserver === 'undefined') {
            playVideo();
        } else {
            const observer = new IntersectionObserver((entries) => {
                entries.forEach((entry) => {
                    if (entry.isIntersecting) playVideo();
                    else pauseVideo();
                });
            }, { threshold: 0.3 });

            observer.observe(video);
        }

        toggle?.addEventListener('click', () => {
            if (frame?.dataset.videoState === 'playing') pauseVideo();
            else playVideo();
        });

        muteButton?.addEventListener('click', () => {
            video.muted = !video.muted;
            syncMuteLabel();
        });

        restartButton?.addEventListener('click', () => {
            video.currentTime = 0;
            playVideo();
            replayMoments();
        });
    }
}

/**
 * The knowledge-section chips: toggling a source updates aria-pressed and the
 * "N connected" count — a small taste of enabling a source in the dashboard.
 */
function initLandingSources(landing) {
    const chips = Array.from(landing.querySelectorAll('[data-source-chip]'));

    if (chips.length === 0) return;

    const counter = landing.querySelector('[data-source-count]');

    const sync = () => {
        if (!counter) return;

        const count = chips.filter((chip) => chip.getAttribute('aria-pressed') === 'true').length;

        counter.textContent = `${count} connected`;
    };

    chips.forEach((chip) => {
        chip.addEventListener('click', () => {
            chip.setAttribute('aria-pressed', String(chip.getAttribute('aria-pressed') !== 'true'));
            sync();
        });
    });

    sync();
}

/**
 * The #platform tablist: click or arrow-key between Widget customization,
 * Analytics, Workspaces, Admin controls and Integrations. The first tab is
 * genuinely live — accent swatches repaint the preview's accent variable and
 * the face buttons re-render the preview Blobatar, exactly like the
 * dashboard's widget designer. Admin switches flip for real too.
 */
function initLandingPlatform(landing) {
    const section = landing.querySelector('[data-platform]');

    if (!section) return;

    const tabs = Array.from(section.querySelectorAll('[role="tab"]'));
    const panels = Array.from(section.querySelectorAll('[data-platform-panel]'));

    if (tabs.length === 0) return;

    const activate = (index, moveFocus = false) => {
        tabs.forEach((tab, tabIndex) => {
            const active = tabIndex === index;

            tab.setAttribute('aria-selected', String(active));
            tab.tabIndex = active ? 0 : -1;

            if (active && moveFocus) tab.focus();
        });

        panels.forEach((panel, panelIndex) => {
            panel.hidden = panelIndex !== index;
        });
    };

    tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => activate(index));

        tab.addEventListener('keydown', (event) => {
            const targets = {
                ArrowRight: (index + 1) % tabs.length,
                ArrowLeft: (index - 1 + tabs.length) % tabs.length,
                Home: 0,
                End: tabs.length - 1,
            };

            if (!(event.key in targets)) return;

            event.preventDefault();
            activate(targets[event.key], true);
        });
    });

    activate(0);

    /* Live widget customizer inside the first tab. */
    const preview = section.querySelector('[data-custom-preview]');
    const avatar = section.querySelector('[data-custom-avatar]');
    const accents = Array.from(section.querySelectorAll('[data-accent]'));
    const faces = Array.from(section.querySelectorAll('[data-face]'));

    accents.forEach((button) => {
        button.addEventListener('click', () => {
            accents.forEach((other) => other.setAttribute('aria-pressed', String(other === button)));

            const hex = button.getAttribute('data-accent-hex') || '#6366f1';

            if (preview) preview.style.setProperty('--l-widget-accent', hex);
        });
    });

    faces.forEach((button) => {
        button.addEventListener('click', () => {
            faces.forEach((other) => other.setAttribute('aria-pressed', String(other === button)));

            if (avatar) {
                setBlobatar(avatar, avatar.dataset.blobatarName || 'DocuMind', {
                    background: avatar.dataset.blobatarBackground || 'circle',
                    animate: 'live',
                    expression: button.getAttribute('data-face'),
                });
            }
        });
    });

    section.querySelectorAll('[data-admin-switch]').forEach((sw) => {
        sw.addEventListener('click', () => {
            sw.setAttribute('aria-checked', String(sw.getAttribute('aria-checked') !== 'true'));
        });
    });
}

function prefersReducedMotion() {
    return typeof window.matchMedia === 'function'
        && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

function initLandingReveals(landing) {
    const targets = landing.querySelectorAll('.reveal');

    if (prefersReducedMotion() || typeof IntersectionObserver === 'undefined') {
        targets.forEach((target) => target.classList.add('is-visible'));
        return;
    }

    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (!entry.isIntersecting) return;

            entry.target.classList.add('is-visible');
            observer.unobserve(entry.target);
        });
    }, { rootMargin: '0px 0px -8% 0px', threshold: 0.06 });

    targets.forEach((target) => observer.observe(target));
}

/**
 * The five-step story: clicking a step swaps the sticky panel, and scrolling
 * keeps the active step in sync with whatever crosses the middle band of the
 * viewport. Panels are absolutely stacked in one grid cell (see .l-story-panels),
 * so activation is just flipping [data-visible] and aria-hidden.
 */
function initLandingStory(landing) {
    const story = landing.querySelector('[data-story]');

    if (!story) return;

    const steps = Array.from(story.querySelectorAll('[data-story-step]'));
    const panels = Array.from(story.querySelectorAll('[data-story-panel]'));

    const activate = (index) => {
        steps.forEach((step, stepIndex) => {
            const active = stepIndex === index;

            step.dataset.active = String(active);

            if (active) step.setAttribute('aria-current', 'step');
            else step.removeAttribute('aria-current');
        });

        panels.forEach((panel, panelIndex) => {
            const visible = panelIndex === index;

            panel.dataset.visible = String(visible);
            panel.setAttribute('aria-hidden', String(!visible));
        });
    };

    steps.forEach((step, index) => step.addEventListener('click', () => activate(index)));

    if (typeof IntersectionObserver !== 'undefined') {
        const observer = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting) return;

                const index = steps.indexOf(entry.target);

                if (index >= 0) activate(index);
            });
        }, { rootMargin: '-30% 0px -50% 0px', threshold: 0 });

        steps.forEach((step) => observer.observe(step));
    }

    activate(0);
}

/** Build a chat bubble; `className` carries l-bubble-bot / l-bubble-user. */
function appendLandingBubble(log, className, text) {
    const row = document.createElement('div');

    row.className = `l-bubble ${className}`;
    row.textContent = text;
    log.appendChild(row);
    log.scrollTop = log.scrollHeight;

    return row;
}

/** Typing row: a thinking Blobatar beside the classic three bouncing dots. */
function appendLandingTyping(log) {
    const row = document.createElement('div');
    const avatar = document.createElement('span');
    const dots = document.createElement('span');

    row.className = 'l-bubble l-bubble-bot flex items-center gap-2';
    row.setAttribute('aria-label', 'Assistant is typing');

    avatar.style.width = '24px';
    avatar.style.height = '24px';
    avatar.style.display = 'inline-grid';
    avatar.style.placeItems = 'center';
    setBlobatar(avatar, 'DocuMind', { background: 'circle', animate: 'live', expression: 'thinking' });

    dots.className = 'l-typing';

    for (let index = 0; index < 3; index += 1) dots.appendChild(document.createElement('i'));

    row.append(avatar, dots);
    log.appendChild(row);
    log.scrollTop = log.scrollHeight;

    return row;
}

function appendLandingSources(log, sources, parent) {
    const meta = document.createElement('div');

    meta.className = 'l-bubble-meta';

    sources.forEach((source) => {
        const pill = document.createElement('span');

        pill.className = 'l-src';
        pill.textContent = source;
        meta.appendChild(pill);
    });

    parent.appendChild(meta);
    log.scrollTop = log.scrollHeight;
}

/** The "Live preview" chat: four canned, grounded Q&As behind the chips. */
function initLandingChatPreview(landing) {
    const preview = landing.querySelector('[data-chat-preview]');

    if (!preview) return;

    const log = preview.querySelector('[data-chat-log]');
    const chips = Array.from(preview.querySelectorAll('[data-qa]'));

    if (!log || chips.length === 0) return;

    const answers = {
        product: {
            question: 'What is DocuMind?',
            answer: 'DocuMind is an AI customer-support platform. You upload your approved documents, configure an assistant in your brand voice, and embed it on your site — it answers only from your knowledge.',
            sources: ['Overview.pdf · p.1', 'Positioning.md'],
        },
        policy: {
            question: 'How do you handle customer data?',
            answer: 'Every workspace is isolated, traffic is encrypted, and you control retention — from 30 days to keep-forever. Visitors consent before any email is captured.',
            sources: ['Privacy policy · §2', 'Data handling.pdf · p.4'],
        },
        faq: {
            question: 'How do I install the widget?',
            answer: "Copy one script tag before your page's </body>. It works with any framework — React, Next.js, Vue, Laravel or plain HTML.",
            sources: ['Install guide.md', 'FAQ.pdf · p.2'],
        },
        support: {
            question: 'Can I talk to a human?',
            answer: "Absolutely — I'll escalate with the full transcript. A teammate gets the context and replies right in the same conversation.",
            sources: ['Handbook.pdf · p.9'],
        },
    };

    const setBusy = (busy) => chips.forEach((chip) => { chip.disabled = busy; });

    const ask = (key) => {
        const data = answers[key];

        if (!data || log.dataset.busy === 'true') return;

        log.dataset.busy = 'true';
        setBusy(true);
        appendLandingBubble(log, 'l-bubble-user', data.question);

        const typing = appendLandingTyping(log);
        const wait = prefersReducedMotion() ? 150 : 950;

        window.setTimeout(() => {
            typing.remove();

            const bot = appendLandingBubble(log, 'l-bubble-bot', data.answer);

            appendLandingSources(log, data.sources, bot);
            log.dataset.busy = 'false';
            setBusy(false);
        }, wait);
    };

    chips.forEach((chip) => chip.addEventListener('click', () => ask(chip.dataset.qa)));
}

/**
 * The in-page widget demo (#demo): launcher toggles [data-open], suggestion
 * chips and the free-text input share one resolver, and the handoff card
 * swaps itself for a confirmation. All handlers are delegated so "Replay demo"
 * (a plain innerHTML restore of the log) needs no rebinding.
 */
function initLandingWidgetDemo(landing) {
    const demo = landing.querySelector('[data-widget-demo]');

    if (!demo) return;

    const launcher = demo.querySelector('[data-demo-launcher]');
    const log = demo.querySelector('[data-demo-log]');
    const form = demo.querySelector('[data-demo-form]');
    const input = form ? form.querySelector('input[name="question"]') : null;
    const replay = landing.querySelector('[data-demo-replay]');

    if (!launcher || !log || !form || !input) return;

    const initialLog = log.innerHTML;

    // Bumped by Replay so a reply still in flight from before the reset can
    // never append itself onto the freshly restored conversation.
    let epoch = 0;

    const replies = [
        {
            match: ['pro', 'plan', 'pricing', 'included'],
            answer: 'Pro includes 3 sites, 2,000 messages a month, unlimited documents, visitor email capture and analytics export.',
            sources: ['Pricing page', 'Pro plan · p.2'],
        },
        {
            match: ['refund', 'money back', 'cancel'],
            answer: 'Full refunds within 14 days of purchase — no questions asked. Billing lives under Settings → Plan.',
            sources: ['Terms of service · §4'],
        },
        {
            match: ['install', 'embed', 'script', 'snippet'],
            answer: "Paste one script tag before your </body> — the widget mounts itself on load. No build step required.",
            sources: ['Install guide.md'],
        },
        {
            match: ['secure', 'privacy', 'data', 'gdpr'],
            answer: 'Documents and chats are isolated per workspace, encrypted in transit and at rest, with retention windows you control.',
            sources: ['Privacy policy · §3'],
        },
        { match: ['human', 'person', 'agent', 'teammate'], handoff: true },
    ];

    const setOpen = (open) => {
        demo.dataset.open = String(open);
        launcher.setAttribute('aria-expanded', String(open));
        launcher.setAttribute('aria-label', open
            ? 'Close the support chat demo'
            : 'Open the support chat demo');
    };

    launcher.addEventListener('click', () => {
        const open = demo.dataset.open !== 'true';

        setOpen(open);

        if (open) window.setTimeout(() => input.focus({ preventScroll: true }), 80);
    });

    const appendHandoff = () => {
        const row = appendLandingBubble(
            log,
            'l-bubble-bot',
            "I can bring a teammate in — they'll receive this full transcript.",
        );
        const meta = document.createElement('div');
        const button = document.createElement('button');

        meta.className = 'l-bubble-meta';
        button.type = 'button';
        button.className = 'l-chip l-chip-sm';
        button.dataset.demoHandoff = '';
        button.textContent = 'Escalate to a teammate';

        meta.appendChild(button);
        row.appendChild(meta);
        log.scrollTop = log.scrollHeight;
    };

    const appendHandoffDone = () => {
        const row = appendLandingBubble(
            log,
            'l-bubble-bot',
            "Done — the team has been alerted with this transcript. Expect a reply here and by email shortly.",
        );

        appendLandingSources(log, ['Escalated · a human will reply'], row);
    };

    const send = (text) => {
        const question = String(text || '').trim();

        if (!question || log.dataset.busy === 'true') return;

        log.dataset.busy = 'true';
        appendLandingBubble(log, 'l-bubble-user', question);

        const typing = appendLandingTyping(log);
        const needle = question.toLowerCase();
        const reply = replies.find((entry) => entry.match.some((word) => needle.includes(word)));
        const wait = prefersReducedMotion() ? 150 : 900;
        const sentAt = epoch;

        window.setTimeout(() => {
            if (sentAt !== epoch) return;

            typing.remove();

            if (!reply) {
                const bot = appendLandingBubble(
                    log,
                    'l-bubble-bot',
                    "That's not in my approved sources yet — I've logged it as an unanswered question for the team.",
                );

                appendLandingSources(log, ['Knowledge gap · flagged'], bot);
            } else if (reply.handoff) {
                appendHandoff();
            } else {
                const bot = appendLandingBubble(log, 'l-bubble-bot', reply.answer);

                appendLandingSources(log, reply.sources, bot);
            }

            log.dataset.busy = 'false';
        }, wait);
    };

    form.addEventListener('submit', (event) => {
        event.preventDefault();

        const text = input.value;

        input.value = '';
        send(text);
    });

    log.addEventListener('click', (event) => {
        const chip = event.target.closest('[data-demo-ask]');

        if (chip) {
            send(chip.textContent);
            return;
        }

        const handoff = event.target.closest('[data-demo-handoff]');

        if (handoff) {
            handoff.closest('.l-bubble')?.remove();
            appendHandoffDone();
        }
    });

    replay?.addEventListener('click', () => {
        epoch += 1;
        log.innerHTML = initialLog;
        log.dataset.busy = 'false';
        input.value = '';
        setOpen(false);
        launcher.focus({ preventScroll: true });
    });

    // The launcher blob watches the cursor over the whole faux site; the
    // header blob only when the pointer is inside the widget panel.
    const launcherAvatar = launcher.querySelector('[data-blobatar-name]');

    if (launcherAvatar) bindBlobatarGaze(launcher, launcherAvatar);

    const headerAvatar = demo.querySelector('.l-widget-panel [data-blobatar-name]');

    if (headerAvatar) bindBlobatarGaze(demo, headerAvatar);
}

/** Copy button on the story's install snippet. */
function initLandingSnippets(landing) {
    landing.querySelectorAll('[data-copy-snippet]').forEach((button) => {
        button.addEventListener('click', async () => {
            const block = button.closest('[data-story-panel]')?.querySelector('.l-code');

            if (!block) return;

            try {
                await navigator.clipboard.writeText(block.textContent.trim());

                const label = button.textContent;

                button.textContent = 'Copied!';
                window.setTimeout(() => { button.textContent = label; }, 1600);
            } catch {
                /* clipboard unavailable — the snippet stays selectable */
            }
        });
    });
}

/*
|---------------------------------------------------------------------------
| Notification Centre
|--------------------------------------------------------------------------
| Bell dropdown (client-rendered feed), a 30 s poller with a `since` cursor
| kept in memory (each page load silently adopts the current server time so
| old rows never replay as toasts), grouped toasts for new items, the unread
| badge, browser Notification when the user opted in, and the preferences
| page's permission button.
*/
function relativeNotifTime(iso) {
    if (!iso) return '';
    const diff = Date.now() - new Date(iso).getTime();
    if (Number.isNaN(diff)) return '';
    const mins = Math.round(diff / 60000);
    if (mins < 1) return 'Just now';
    if (mins < 60) return `${mins}m ago`;
    const hours = Math.round(mins / 60);
    if (hours < 24) return `${hours}h ago`;
    const days = Math.round(hours / 24);
    if (days < 7) return `${days}d ago`;
    return new Date(iso).toLocaleDateString();
}

function notificationGroups(items) {
    const groups = [];

    for (const n of items) {
        const last = groups.at(-1);

        if (last && last.title === n.title && last.severity === n.severity) {
            last.count += 1;
        } else {
            groups.push({
                id: n.id,
                title: n.title,
                body: n.body,
                severity: n.severity,
                link: n.link,
                linkLabel: n.link_label,
                count: 1,
            });
        }
    }

    return groups;
}

function initNotificationCenter() {
    const root = document.querySelector('[data-notifications]');
    const markAllBtn = document.querySelector('[data-mark-all]');
    const base = root?.dataset.notificationsBase || markAllBtn?.dataset.notificationsBase || null;

    if (!base) return;

    const browserEnabled = root?.dataset.notificationsBrowser === '1';
    const toggle = root.querySelector('[data-notifications-toggle]');
    const panel = root.querySelector('[data-notifications-panel]');
    const list = root.querySelector('[data-notifications-list]');
    const badge = root.querySelector('[data-notifications-badge]');
    const readAll = root.querySelector('[data-notifications-read-all]');

    const stateEls = {};
    for (const el of (list?.querySelectorAll('[data-notifications-state]') ?? [])) {
        stateEls[el.dataset.notificationsState] = el;
    }

    const POLL_MS = 30000;
    let cursor = null;
    let failures = 0;
    let skipTicks = 0;
    let inFlight = false;

    const setBadge = (count) => {
        if (!badge) return;
        const n = Number(count) || 0;
        badge.hidden = n === 0;
        badge.textContent = n > 9 ? '9+' : String(n);
        toggle?.setAttribute('aria-label', n > 0 ? `Notifications, ${n} unread` : 'Notifications, none unread');
    };

    setBadge(Number(root?.dataset.notificationsInitial || 0));

    const hideStates = () => {
        for (const el of Object.values(stateEls)) el.hidden = true;
    };

    const showState = (name) => {
        list?.querySelectorAll('.dm-notif-item').forEach((node) => node.remove());
        for (const [key, el] of Object.entries(stateEls)) el.hidden = key !== name;
    };

    const buildItem = (n) => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = `dm-notif-item${n.read ? '' : ' dm-notif-item--unread'}`;
        btn.dataset.notifId = n.id;
        if (n.link) btn.dataset.notifLink = n.link;
        btn.innerHTML = `
            <span class="dm-notif-icon dm-notif-icon--${escapeHtml(n.category)}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><use href="#notif-cat-${escapeHtml(n.category)}"></use></svg>
            </span>
            <span class="dm-notif-item-copy">
                <span class="dm-notif-item-title">${escapeHtml(n.title)}</span>
                ${n.body ? `<span class="dm-notif-item-body">${escapeHtml(n.body)}</span>` : ''}
                <span class="dm-notif-item-time"><time datetime="${escapeHtml(n.created_at ?? '')}">${escapeHtml(relativeNotifTime(n.created_at))}</time></span>
            </span>`;
        return btn;
    };

    const renderFeed = async () => {
        showState('loading');

        try {
            const res = await fetch(`${base}/feed`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (!res.ok) throw new Error(`feed ${res.status}`);

            const data = await res.json();
            setBadge(data.unread);

            const items = Array.isArray(data.notifications) ? data.notifications : [];
            if (!items.length) {
                showState('empty');
                return;
            }

            hideStates();
            const frag = document.createDocumentFragment();
            for (const n of items) frag.append(buildItem(n));
            list.prepend(frag);
        } catch {
            showState('error');
        }
    };

    const markRead = async (id) => {
        try {
            const res = await fetch(`${base}/${encodeURIComponent(id)}/read`, {
                method: 'POST',
                headers: JSON_HEADERS,
                credentials: 'same-origin',
            });
            if (!res.ok) return null;
            const data = await res.json();
            setBadge(data.unread);
            return data;
        } catch {
            return null;
        }
    };

    const setOpen = (open) => {
        if (!panel || !toggle) return;
        panel.hidden = !open;
        toggle.setAttribute('aria-expanded', String(open));
        if (open) renderFeed();
    };

    toggle?.addEventListener('click', () => setOpen(panel?.hidden !== false));

    document.addEventListener('click', (event) => {
        if (panel && !panel.hidden && !root.contains(event.target)) setOpen(false);
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && panel && !panel.hidden) {
            setOpen(false);
            toggle?.focus();
        }
    });

    list?.addEventListener('click', (event) => {
        if (event.target.closest('[data-notifications-retry]')) {
            renderFeed();
            return;
        }

        const item = event.target.closest('[data-notif-id]');
        if (!item) return;

        item.classList.remove('dm-notif-item--unread');
        const link = item.dataset.notifLink || null;
        markRead(item.dataset.notifId).finally(() => {
            if (link) window.location.assign(link);
        });
    });

    readAll?.addEventListener('click', async () => {
        readAll.disabled = true;
        try {
            const res = await fetch(`${base}/read-all`, {
                method: 'POST',
                headers: JSON_HEADERS,
                credentials: 'same-origin',
            });
            if (!res.ok) throw new Error(`read-all ${res.status}`);
            const data = await res.json();
            setBadge(data.unread);
            await renderFeed();
        } catch {
            showToast({ type: 'error', title: "Couldn't mark all as read", message: 'Check your connection and try again.' });
        } finally {
            readAll.disabled = false;
        }
    });

    const toastNew = (items) => {
        const groups = notificationGroups(items).slice(-4);

        for (const g of groups) {
            const title = g.count > 1 ? `${g.title} (+${g.count - 1} more)` : g.title;
            showToast({
                type: g.severity || 'info',
                title,
                message: g.body || '',
                link: g.link,
                linkLabel: g.linkLabel || 'Open',
            });
        }

        if (
            browserEnabled === '1' &&
            'Notification' in window &&
            Notification.permission === 'granted' &&
            document.visibilityState === 'hidden'
        ) {
            for (const g of groups.slice(-3)) {
                try {
                    new Notification(g.title, { body: g.body || '', tag: `${g.severity}-${g.id}` });
                } catch {
                    /* notification construction can throw on some platforms */
                }
            }
        }
    };

    const poll = async ({ initial = false } = {}) => {
        if (inFlight) return;

        if (!navigator.onLine) {
            if (panel && !panel.hidden && !list.querySelector('.dm-notif-item')) showState('offline');
            return;
        }

        inFlight = true;

        try {
            const url = `${base}/poll${cursor ? `?since=${encodeURIComponent(cursor)}` : ''}`;
            const res = await fetch(url, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (!res.ok) throw new Error(`poll ${res.status}`);

            const data = await res.json();
            failures = 0;
            cursor = data.server_time;
            setBadge(data.unread);

            const items = Array.isArray(data.notifications) ? data.notifications : [];
            if (!initial && items.length) {
                toastNew(items.slice().reverse());
                if (panel && !panel.hidden) renderFeed();
            }
        } catch {
            failures = Math.min(failures + 1, 5);
            skipTicks = Math.min(2 ** failures, 16);
        } finally {
            inFlight = false;
        }
    };

    poll({ initial: true });

    setInterval(() => {
        if (document.visibilityState !== 'visible') return;
        if (skipTicks > 0) {
            skipTicks -= 1;
            return;
        }
        poll();
    }, POLL_MS);

    document.addEventListener('online', () => poll());
    window.addEventListener('focus', () => {
        if (skipTicks > 0) {
            skipTicks = 0;
            poll();
        }
    });

    /* Centre page: mark-all button + click a row to mark read and open it. */
    markAllBtn?.addEventListener('click', async () => {
        markAllBtn.disabled = true;
        try {
            const res = await fetch(`${base}/read-all`, {
                method: 'POST',
                headers: JSON_HEADERS,
                credentials: 'same-origin',
            });
            if (!res.ok) throw new Error(`read-all ${res.status}`);
            document.querySelectorAll('[data-notification-id]').forEach((row) => {
                row.classList.remove('dm-notif-row--unread');
                row.querySelector('.dm-notif-dot')?.remove();
            });
            setBadge(0);
            showToast({ type: 'success', title: 'All notifications marked read.' });
        } catch {
            showToast({ type: 'error', title: "Couldn't mark all as read", message: 'Check your connection and try again.' });
            markAllBtn.disabled = false;
        }
    });

    document.addEventListener('click', (event) => {
        const row = event.target.closest('[data-notification-id]');
        if (!row || (root && root.contains(row))) return;

        const link = row.dataset.notificationLink || null;
        const wasUnread = row.classList.contains('dm-notif-row--unread');

        if (!link && !wasUnread) return;

        event.preventDefault();
        if (wasUnread) {
            row.classList.remove('dm-notif-row--unread');
            row.querySelector('.dm-notif-dot')?.remove();
        }
        markRead(row.dataset.notificationId).finally(() => {
            if (link) window.location.assign(link);
        });
    });

    /* Flash status lines queued by the server during this request. */
    document.querySelectorAll('[data-page-toasts]').forEach((el) => {
        try {
            const queued = JSON.parse(el.dataset.pageToasts);
            if (Array.isArray(queued)) {
                for (const t of queued) {
                    showToast({ type: t.type || 'success', title: t.message || '' });
                }
            }
        } catch {
            /* malformed carrier — nothing to show */
        }
        el.remove();
    });
}

function initBrowserPermission() {
    const btn = document.querySelector('[data-browser-permission]');
    const status = document.querySelector('[data-browser-permission-status]');

    if (!btn || !status) return;

    const render = () => {
        if (!('Notification' in window)) {
            status.textContent = 'Not supported in this browser.';
            btn.hidden = true;
            return;
        }

        const permission = Notification.permission;
        status.textContent =
            permission === 'granted'
                ? 'Enabled for this browser.'
                : permission === 'denied'
                  ? 'Blocked — allow notifications for this site in your browser settings.'
                  : 'Not enabled yet.';
        btn.disabled = permission !== 'default';
        btn.textContent = permission === 'granted' ? 'Enabled' : 'Enable browser notifications';
    };

    render();

    btn.addEventListener('click', async () => {
        if (!('Notification' in window)) return;
        const permission = await Notification.requestPermission();
        render();
        if (permission === 'granted') {
            showToast({ type: 'success', title: 'Browser notifications enabled.' });
        }
    });
}

/**
 * One init failure must never take down the rest of the page: a missing node
 * on a single screen used to strand every later handler, which is how the
 * Install Embed Code buttons ended up doing nothing.
 */
function boot() {
    const steps = [
        preventBrowserNavigationOnDrop,
        initTheme,
        hydrateBlobatars,
        initLanding,
        initMobileNav,
        initUserDropdown,
        initDropzone,
        initDeletes,
        initChatDelete,
        initWidgetSiteDelete,
        initWidgetCustomization,
        initSiteRotateKey,
        initAdminConfirmations,
        initFirebaseAuth,
        initVisitorInbox,
        initActivityDashboard,
        initInstallActions,
        initInstallSnippets,
        initChat,
        initSidebar,
        initChatSearch,
        initChatRename,
        initChatSettings,
        initSummarize,
        renderExistingAssistantMessages,
        updateCount,
        initPendingDocuments,
        initNotificationCenter,
        initBrowserPermission,
    ];

    for (const step of steps) {
        try {
            step();
        } catch (error) {
            console.error(`[documind] ${step.name} failed`, error);
        }
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
} else {
    boot();
}