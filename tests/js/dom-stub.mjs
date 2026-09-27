/**
 * Shared headless DOM for the node smoke tests.
 *
 * The minimum element/classList surface the app's browser code touches, plus a
 * selector engine small enough to read: class, tag, attribute, attribute=value
 * and #id simple selectors, comma lists, and descendant (whitespace)
 * combinators — which is everything resources/js/app.js and the widget bundle
 * actually query with. Kept behaviour-identical to the copy that used to live
 * inside widget.smoke.mjs so that test's gate output does not change.
 */

export class ClassList {
    constructor(el) {
        this.el = el;
        this.set = new Set();
    }

    add(...c) { c.forEach((x) => this.set.add(x)); }

    remove(...c) { c.forEach((x) => this.set.delete(x)); }

    toggle(c, force) {
        const on = force === undefined ? !this.set.has(c) : force;
        if (on) this.set.add(c); else this.set.delete(c);
        return on;
    }

    contains(c) { return this.set.has(c); }
}

export class El {
    constructor(tag, ns = null) {
        this.tagName = String(tag).toUpperCase();
        this.namespaceURI = ns;
        this.children = [];
        this.parentElement = null;
        this.style = {
            setProperty: (name, value) => { this.style[name] = String(value); },
            getPropertyValue: (name) => this.style[name] ?? '',
        };
        this._attrs = {};
        this._classList = new ClassList(this);
        this._text = '';
        this._html = '';
        this.listeners = {};
    }

    /* className and classList are one storage in a real browser. */
    get className() { return [...this._classList.set].join(' '); }
    set className(v) { this._classList.set = new Set(String(v).split(/\s+/).filter(Boolean)); }

    /* dataset and data-* attributes are one storage in a real browser. */
    get dataset() {
        const el = this;
        return new Proxy({}, {
            get: (_, k) => el._attrs[`data-${camelToDash(k)}`],
            set: (_, k, v) => { el._attrs[`data-${camelToDash(k)}`] = String(v); return true; },
            has: (_, k) => `data-${camelToDash(k)}` in el._attrs,
        });
    }

    get classList() { return this._classList; }

    get textContent() {
        /* Own text first, then appended children — a real element concatenates
           every text node in tree order, and the landing's bubbles set text
           before appending their source pills. innerHTML assignment stays
           opaque: surface it as stripped text only while it is the sole
           content. */
        const own = this._text !== ''
            ? this._text
            : (this.children.length === 0 && this._html !== ''
                ? this._html.replace(/<[^>]*>/g, '')
                : '');

        return own + this.children.map((c) => c.textContent).join('');
    }

    set textContent(v) { this._text = String(v); this._html = ''; this.children = []; }

    get innerHTML() { return this._html; }

    set innerHTML(v) { this._html = String(v); this.children = []; }

    get firstElementChild() { return this.children[0] ?? null; }

    get childElementCount() { return this.children.length; }

    setAttribute(k, v) { this._attrs[k] = String(v); }

    getAttribute(k) { return this._attrs[k] ?? null; }

    removeAttribute(k) { delete this._attrs[k]; }

    remove() {
        if (!this.parentElement) return;
        const i = this.parentElement.children.indexOf(this);
        if (i > -1) this.parentElement.children.splice(i, 1);
        this.parentElement = null;
    }

    append(...nodes) {
        for (const n of nodes) {
            /* Re-appending an existing child moves it to the end; it must not
               run remove() on the shared parent (which would detach the
               parent itself from *its* tree). */
            if (n.parentElement === this) {
                const i = this.children.indexOf(n);
                if (i > -1) this.children.splice(i, 1);
            } else {
                n.parentElement?.remove();
            }
            n.parentElement = this;
            this.children.push(n);
        }
    }

    replaceChildren(...nodes) {
        for (const child of this.children) {
            if (child.parentElement === this) child.parentElement = null;
        }
        this.children = [];
        this.append(...nodes);
    }

    appendChild(node) { this.append(node); return node; }

    insertAdjacentHTML() {}

    addEventListener(type, fn) { (this.listeners[type] ??= []).push(fn); }

    removeEventListener(type, fn) {
        this.listeners[type] = (this.listeners[type] ?? []).filter((f) => f !== fn);
    }

    dispatch(type, event = {}) {
        (this.listeners[type] ?? []).forEach((fn) => fn({ type, target: this, ...event }));
    }

    querySelector(sel) { return queryAll(this, sel)[0] ?? null; }

    querySelectorAll(sel) { return queryAll(this, sel); }

    closest(sel) {
        let node = this;
        while (node) {
            if (matches(node, sel)) return node;
            node = node.parentElement;
        }
        return null;
    }

    contains(node) {
        let n = node;
        while (n) {
            if (n === this) return true;
            n = n.parentElement;
        }
        return false;
    }

    focus() { this.focused = true; }

    click() { this.dispatch('click'); }

    attachShadow() { this.shadow = new El('shadow'); return this.shadow; }

    scrollTo() {}

    get scrollTop() { return 0; }

    set scrollTop(v) { this._scrollTop = v; }

    get scrollHeight() { return 0; }

    get clientHeight() { return 0; }

    getBoundingClientRect() {
        return { left: 0, top: 0, right: 100, bottom: 100, width: 100, height: 100 };
    }
}

export function queryAll(root, sel) {
    const out = [];
    const walk = (node) => {
        for (const c of node.children) {
            if (matches(c, sel)) out.push(c);
            walk(c);
        }
    };
    walk(root);
    return out;
}

export function camelToDash(key) {
    return String(key).replace(/[A-Z]/g, (ch) => '-' + ch.toLowerCase());
}

function matchesAttr(el, bracket) {
    const m = /^\[([^=\]]+)(?:=["']?([^\]"']+)["']?)?\]$/.exec(bracket);

    if (!m) return false;

    const [, name, value] = m;

    if (value === undefined) return el.getAttribute(name) !== null;
    return el.getAttribute(name) === value;
}

function matchesCompound(el, compound) {
    const tokens = compound.match(/\[[^\]]+\]|[.#][\w-]+|[a-zA-Z][\w-]*/g);

    if (!tokens || tokens.join('') !== compound) return false;

    return tokens.every((token) => {
        if (token.startsWith('[')) return matchesAttr(el, token);
        if (token.startsWith('.')) return el.classList.contains(token.slice(1));
        if (token.startsWith('#')) return el.getAttribute('id') === token.slice(1);
        return el.tagName === token.toUpperCase();
    });
}

function matchesChain(el, steps) {
    let index = steps.length - 1;

    if (!matchesCompound(el, steps[index])) return false;

    index -= 1;

    let node = el.parentElement;

    while (index >= 0) {
        if (!node) return false;
        if (matchesCompound(node, steps[index])) index -= 1;
        node = node.parentElement;
    }

    return true;
}

export function matches(el, sel) {
    return String(sel).split(',').some((option) => {
        const steps = option.trim().split(/\s+/).filter(Boolean);
        return steps.length > 0 && matchesChain(el, steps);
    });
}
