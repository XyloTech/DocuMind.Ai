import { blobatar } from 'blobatar/blob';
import { blobatarUri } from 'blobatar/uri';
import { _parts } from 'blobatar/internal';
import { gaze } from 'blobatar/gaze';
import * as expressions from 'blobatar/expression';

const SVG_NS = 'http://www.w3.org/2000/svg';

/**
 * The pose roster, mirrored from App\Models\Site::EXPRESSIONS so a name the
 * admin picked always resolves to exactly one export of `blobatar/expression`.
 * Anything outside this set renders the natural idle pose.
 */
const POSES = new Set([
    'idle', 'happy', 'sad', 'mad', 'surprised', 'wink', 'sleepy', 'smug',
    'unsure', 'scared', 'love', 'shy', 'sick', 'thinking',
]);

/** Excursion of the cursor gaze, in viewBox units (~1.5-4 reads well). */
const GAZE_TRAVEL = 2.2;

function resolveExpression(name) {
    return POSES.has(name) ? expressions[name] : undefined;
}

/** `blobatar()` takes a boolean backdrop; the admin UI speaks in "none". */
function resolveBackground(background) {
    return background === 'none' ? false : background;
}

function escapeXml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

/**
 * Build a blobatar element from the deterministic seed.
 *
 * Animated requests come back as inline SVG carrying the motion classes, so
 * `blobatar/motion.css` can drive the idle loops; static ones are plain markup
 * (or an `<img>` data URI when this realm has no DOMParser — a server-side
 * renderer, or a parser hiccup, must still draw a face).
 *
 * Options: `background`, `alt`, `hue`, `tone`, `expression` (a roster name),
 * `animate` (`'live'`, `'hover'`, anything else is static).
 */
export function createBlobatarImage(name, options = {}) {
    const background = resolveBackground(options.background ?? 'circle');
    const alt = options.alt || '';
    const seed = String(name ?? '').trim() || 'DocuMind';
    const hue = Number.isFinite(options.hue) ? options.hue : undefined;
    const tone = Number.isFinite(options.tone) ? options.tone : undefined;
    const expression = resolveExpression(options.expression);
    const animate = options.animate === 'live'
        ? 'always'
        : (options.animate === 'hover' ? 'hover' : undefined);

    const fallbackImage = () => {
        const image = document.createElement('img');
        image.setAttribute('src', blobatarUri(seed, {
            background,
            hue,
            tone,
            expression,
            title: alt || undefined,
        }));
        image.setAttribute('alt', alt);
        image.setAttribute('decoding', 'async');
        image.setAttribute('draggable', 'false');
        image.setAttribute('data-blobatar-image', 'true');
        image.className = 'block h-full w-full object-cover';

        return image;
    };

    if (typeof DOMParser === 'undefined' || typeof document.importNode !== 'function') {
        return fallbackImage();
    }

    let svg = null;

    try {
        const markup = animate
            ? animatedMarkup(seed, { background, hue, tone, expression, animate, alt })
            : blobatar(seed, { background, hue, tone, expression, title: alt || undefined });

        const parsed = new DOMParser().parseFromString(markup, 'image/svg+xml');

        if (!parsed.querySelector('parsererror')) {
            svg = document.importNode(parsed.documentElement, true);
        }
    } catch {
        svg = null;
    }

    if (!svg || svg.localName !== 'svg') {
        return fallbackImage();
    }

    // The gaze driver and the custom fallback both find the eyes the same way:
    // the motion root's eye group when animating, otherwise the last group of
    // exactly two paths, which is how the static renderer draws a face.
    const eyes = svg.querySelector('.mo-eyes') ?? Array.from(svg.children)
        .filter((child) => child.localName === 'g')
        .at(-1);

    if (eyes && eyes.querySelectorAll('path').length === 2) {
        eyes.setAttribute('data-blobatar-eyes', 'true');
    }

    svg.setAttribute('data-blobatar-image', 'true');
    svg.classList.add('block', 'h-full', 'w-full');

    if (alt) {
        svg.setAttribute('role', 'img');
        svg.setAttribute('aria-label', alt);
    } else {
        svg.setAttribute('aria-hidden', 'true');
    }

    return svg;
}

/**
 * Animated markup: backdrop plate + `_parts`' motion tree, with the seeded
 * timing custom properties and the gaze excursion inline so the eyes can be
 * pointed without a second trip through the stylesheet.
 */
function animatedMarkup(seed, { background, hue, tone, expression, animate, alt }) {
    const { cls, bg, inner, vars } = _parts(seed, { background, hue, tone, expression, animate });

    const style = Object.entries(vars ?? {})
        .map(([key, value]) => `${key}:${value}`)
        .concat(`--mo-track-travel:${GAZE_TRAVEL}px`)
        .join(';');

    return `<svg xmlns="${SVG_NS}" viewBox="0 0 100 100"${cls ? ` class="${cls}"` : ''} style="${style}">`
        + (alt ? `<title>${escapeXml(alt)}</title>` : '')
        + (bg ? `<path d="${bg.d}" fill="${bg.fill}"></path>` : '')
        + inner
        + '</svg>';
}

/**
 * Render `name` into `container`, replacing whatever was there — but only when
 * the seed or the options actually changed, so a live config poll does not
 * re-parse the whole transcript's avatars on every tick.
 */
export function setBlobatar(container, name, options = {}) {
    const seed = String(name ?? '').trim() || 'DocuMind';
    const key = seed + '|' + JSON.stringify(options);

    if (container.dataset.blobatarKey === key
        && container.firstElementChild?.getAttribute('data-blobatar-image') === 'true') {
        return container.firstElementChild;
    }

    const image = createBlobatarImage(seed, options);
    container.replaceChildren(image);
    container.dataset.blobatarKey = key;

    return image;
}

export function hydrateBlobatars(root = document) {
    root.querySelectorAll('[data-blobatar-name]').forEach((container) => {
        setBlobatar(container, container.dataset.blobatarName, {
            background: container.dataset.blobatarBackground || 'circle',
            animate: container.dataset.blobatarAnimate || 'hover',
            expression: container.dataset.blobatarExpression || undefined,
        });

        if (container.dataset.blobatarFollow === 'true') {
            bindBlobatarGaze(container.parentElement || container, container);
        }
    });
}

/**
 * Point an avatar's eyes at the cursor.
 *
 * Animated markup gets the package's driver: it measures the real face off its
 * own geometry, runs the pursuit and saccade, and switches itself off under a
 * coarse pointer or `prefers-reduced-motion` — watching both media queries for
 * the whole time it is bound. Static markup has no motion tree to project onto,
 * so it falls back to the lightweight translate that predates it.
 *
 * Returns a disposer; binding is safe to call on realms without a window.
 */
export function bindBlobatarGaze(scope, avatar, travel = GAZE_TRAVEL) {
    if (typeof window === 'undefined'
        || typeof window.matchMedia !== 'function'
        || !avatar) {
        return () => {};
    }

    const svg = avatar.localName === 'svg'
        ? avatar
        : (avatar.querySelector?.('svg') ?? null);

    if (svg?.classList?.contains('mo-root') && canDriveGaze(svg)) {
        svg.style.setProperty('--mo-track-travel', `${travel}px`);

        try {
            const driver = gaze(svg, { target: 'pointer' });

            return () => driver.stop();
        } catch {
            /* Fall through to the static translate below. */
        }
    }

    return bindStaticGaze(scope, avatar, travel);
}

function canDriveGaze(svg) {
    return typeof window.ResizeObserver === 'function'
        && typeof window.getComputedStyle === 'function'
        && typeof svg.getBoundingClientRect === 'function'
        && typeof svg.getBBox === 'function';
}

function bindStaticGaze(scope, avatar, travel) {
    if (typeof window.requestAnimationFrame !== 'function'
        || !scope?.addEventListener
        || !avatar?.style) {
        return () => {};
    }

    const eyeGroup = avatar.querySelector?.('[data-blobatar-eyes]');
    const pointerPreference = window.matchMedia('(hover: hover) and (pointer: fine)');
    const motionPreference = window.matchMedia('(prefers-reduced-motion: reduce)');
    const trackable = () => pointerPreference.matches && !motionPreference.matches;
    const originalTransform = avatar.style.transform || '';
    const originalTransition = avatar.style.transition;
    const originalWillChange = avatar.style.willChange;
    const originalEyeTransform = eyeGroup?.style.transform || '';
    const originalEyeTransition = eyeGroup?.style.transition;
    let active = false;
    let frame = 0;
    let pointer = null;

    const reset = () => {
        pointer = null;

        if (frame) {
            window.cancelAnimationFrame?.(frame);
            frame = 0;
        }

        avatar.style.transform = originalTransform;

        if (eyeGroup) {
            eyeGroup.style.transform = originalEyeTransform;
        }
    };

    const move = (event) => {
        pointer = { x: event.clientX, y: event.clientY };

        if (frame) return;

        frame = window.requestAnimationFrame(() => {
            frame = 0;

            if (!pointer) return;

            const bounds = scope.getBoundingClientRect();

            if (bounds.width <= 0 || bounds.height <= 0) return;

            const horizontal = Math.max(-1, Math.min(1, ((pointer.x - bounds.left) / bounds.width) * 2 - 1));
            const vertical = Math.max(-1, Math.min(1, ((pointer.y - bounds.top) / bounds.height) * 2 - 1));

            avatar.style.transform = `translate3d(${(horizontal * travel).toFixed(2)}px, ${(vertical * travel).toFixed(2)}px, 0)`;

            if (eyeGroup) {
                const eyeBounds = avatar.getBoundingClientRect();
                const eyeHorizontal = Math.max(-1, Math.min(1, ((pointer.x - eyeBounds.left) / eyeBounds.width) * 2 - 1));
                const eyeVertical = Math.max(-1, Math.min(1, ((pointer.y - eyeBounds.top) / eyeBounds.height) * 2 - 1));

                eyeGroup.style.transform = `translate(${(eyeHorizontal * 2.4).toFixed(2)}px, ${(eyeVertical * 2.4).toFixed(2)}px)`;
            }
        });
    };

    const update = () => {
        const shouldTrack = trackable();

        if (shouldTrack === active) return;

        active = shouldTrack;

        if (active) {
            avatar.style.transition = 'transform 120ms cubic-bezier(0.2, 0.75, 0.25, 1)';
            avatar.style.willChange = 'transform';
            if (eyeGroup) {
                eyeGroup.style.transition = 'transform 110ms cubic-bezier(0.2, 0.75, 0.25, 1)';
            }
            scope.addEventListener('pointermove', move, { passive: true });
            scope.addEventListener('pointerleave', reset, { passive: true });

            return;
        }

        scope.removeEventListener('pointermove', move);
        scope.removeEventListener('pointerleave', reset);

        reset();
        avatar.style.transition = originalTransition;
        avatar.style.willChange = originalWillChange;

        if (eyeGroup) {
            eyeGroup.style.transition = originalEyeTransition;
        }
    };

    const watch = (media) => {
        if (media.addEventListener) {
            media.addEventListener('change', update);
        } else {
            media.addListener?.(update);
        }
    };

    const unwatch = (media) => {
        if (media.removeEventListener) {
            media.removeEventListener('change', update);
        } else {
            media.removeListener?.(update);
        }
    };

    watch(pointerPreference);
    watch(motionPreference);
    update();

    return () => {
        unwatch(pointerPreference);
        unwatch(motionPreference);
        if (active) {
            scope.removeEventListener('pointermove', move);
            scope.removeEventListener('pointerleave', reset);
        }
        reset();
        avatar.style.transition = originalTransition;
        avatar.style.willChange = originalWillChange;

        if (eyeGroup) {
            eyeGroup.style.transition = originalEyeTransition;
        }
    };
}
