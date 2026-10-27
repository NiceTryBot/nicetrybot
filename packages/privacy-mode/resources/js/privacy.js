/**
 * Privacy mode: swaps every real domain on the page for its made-up stand-in
 * (invisible-pony.hidden-domain.com), and every word in Settings → Privacy for
 * its own, so the app can be shown on screen without giving away whose sites it
 * protects. Toggle with the button or Cmd/Ctrl + /.
 *
 * The add-on's body-end view loads the team's hostname → stand-in map as base64 JSON
 * into window.ntbPrivacyHostnames (a cached script) and renders the global word map in
 * [data-privacy-words]; its head view flags <html class="privacy-mode"> early and hides
 * the body until the first pass here has run, so real names never flash. It's served
 * as a module from the add-on (ScriptController), so it runs once the page is parsed.
 */

const STORAGE_KEY = 'nicetrybot:privacy-mode';
const SKIPPED_TAGS = new Set(['SCRIPT', 'STYLE', 'NOSCRIPT', 'TEXTAREA', 'INPUT']);
const MASKED_ATTRIBUTES = ['title', 'aria-label', 'placeholder', 'alt'];

const root = document.documentElement;

let enabled = root.classList.contains('privacy-mode');
let toStandIn = null;
let toReal = null;

/**
 * What each masked text node and attribute said before masking, so turning the mode off puts back the
 * exact text: a word's stand-in (like •••••) can't be turned back into the word the way a hostname's can.
 *
 * @type {WeakMap<Node, { original: string, masked: string }>}
 */
const textOriginals = new WeakMap();

/** @type {WeakMap<Element, Map<string, { original: string, masked: string }>>} */
const attributeOriginals = new WeakMap();

/**
 * Build a replacer for a hostname → hostname map that matches whole hostnames only,
 * longest first, so "www.shop.example" isn't half-swapped as "www.<shop's stand-in>".
 */
function replacer(map) {
    const keys = Object.keys(map).sort((a, b) => b.length - a.length);

    if (keys.length === 0) {
        return null;
    }

    const lookup = new Map(keys.map((key) => [key.toLowerCase(), map[key]]));
    const escaped = keys.map((key) => key.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));
    const pattern = new RegExp(`(?<![a-z0-9-])(?:${escaped.join('|')})(?![a-z0-9-])`, 'gi');

    return (text) => {
        pattern.lastIndex = 0;

        return pattern.test(text) ? text.replace(pattern, (match) => lookup.get(match.toLowerCase()) ?? match) : text;
    };
}

/**
 * Build a replacer for a word → stand-in map that matches anywhere in any case, longest first,
 * so "acme" goes from /ast_acmelogo.png as well as from a sentence.
 */
function wordReplacer(map) {
    const keys = Object.keys(map).sort((a, b) => b.length - a.length);

    if (keys.length === 0) {
        return null;
    }

    const lookup = new Map(keys.map((key) => [key.toLowerCase(), map[key]]));
    const pattern = new RegExp(keys.map((key) => key.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('|'), 'gi');

    return (text) => {
        pattern.lastIndex = 0;

        return pattern.test(text) ? text.replace(pattern, (match) => lookup.get(match.toLowerCase()) ?? match) : text;
    };
}

let hostnames = {};
let words = {};

function setMaps(hostnameMap, wordMap) {
    hostnames = hostnameMap;
    words = wordMap;

    const hostnamesToStandIns = replacer(hostnameMap);
    const wordsToStandIns = wordReplacer(wordMap);

    toStandIn = hostnamesToStandIns && wordsToStandIns
        ? (text) => wordsToStandIns(hostnamesToStandIns(text))
        : (hostnamesToStandIns ?? wordsToStandIns);
    toReal = replacer(Object.fromEntries(Object.entries(hostnameMap).map(([real, standIn]) => [standIn, real])));
}

function readMaps() {
    setMaps(decodeMap(window.ntbPrivacyHostnames), readMap('[data-privacy-words]'));
}

function readMap(selector) {
    return decodeMap(document.querySelector(selector)?.textContent);
}

function decodeMap(encoded) {
    try {
        return encoded ? JSON.parse(atob(encoded.trim())) : {};
    } catch {
        return {};
    }
}

function isSkipped(element) {
    return element === null || SKIPPED_TAGS.has(element.tagName) || element.closest('[data-privacy-skip]') !== null;
}

/**
 * Mask a value, remembering what it said; or unmask it, back to what it said if it's still as masked,
 * else by turning hostname stand-ins back into real names (as on a page restored from history).
 */
function rewriteValue(value, masking, remembered, remember, forget) {
    if (masking) {
        if (remembered?.masked === value || !toStandIn) {
            return value;
        }

        const rewritten = toStandIn(value);

        if (rewritten !== value) {
            remember({ original: value, masked: rewritten });
        }

        return rewritten;
    }

    if (remembered) {
        forget();

        if (remembered.masked === value) {
            return remembered.original;
        }
    }

    return toReal ? toReal(value) : value;
}

function rewriteElement(element, masking) {
    for (const name of MASKED_ATTRIBUTES) {
        const value = element.getAttribute(name);

        if (!value) {
            continue;
        }

        const remembered = attributeOriginals.get(element)?.get(name);
        const rewritten = rewriteValue(
            value,
            masking,
            remembered,
            (entry) => {
                if (!attributeOriginals.has(element)) {
                    attributeOriginals.set(element, new Map());
                }

                attributeOriginals.get(element).set(name, entry);
            },
            () => attributeOriginals.get(element)?.delete(name),
        );

        if (rewritten !== value) {
            element.setAttribute(name, rewritten);
        }
    }
}

function rewriteText(node, masking) {
    const rewritten = rewriteValue(
        node.data,
        masking,
        textOriginals.get(node),
        (entry) => textOriginals.set(node, entry),
        () => textOriginals.delete(node),
    );

    if (rewritten !== node.data) {
        node.data = rewritten;
    }
}

/**
 * Mask, or unmask, the text and labelling attributes under a node.
 */
function rewrite(node, masking) {

    if (node.nodeType === Node.TEXT_NODE) {
        if (!isSkipped(node.parentElement)) {
            rewriteText(node, masking);
        }

        return;
    }

    if (node.nodeType !== Node.ELEMENT_NODE || isSkipped(node)) {
        return;
    }

    rewriteElement(node, masking);

    const walker = document.createTreeWalker(node, NodeFilter.SHOW_ELEMENT | NodeFilter.SHOW_TEXT, {
        acceptNode: (current) => {
            if (current.nodeType === Node.ELEMENT_NODE) {
                return isSkipped(current) ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT;
            }

            return NodeFilter.FILTER_ACCEPT;
        },
    });

    while (walker.nextNode()) {
        const current = walker.currentNode;

        if (current.nodeType === Node.TEXT_NODE) {
            rewriteText(current, masking);
        } else {
            rewriteElement(current, masking);
        }
    }
}

/**
 * Keep up with Livewire morphs, Alpine bindings and wire:navigate page swaps.
 */
const observer = new MutationObserver((mutations) => {
    if (!enabled || !toStandIn) {
        return;
    }

    for (const mutation of mutations) {
        if (mutation.type === 'characterData') {
            rewrite(mutation.target, true);
        } else if (mutation.type === 'attributes') {
            if (!isSkipped(mutation.target)) {
                rewriteElement(mutation.target, true);
            }
        } else {
            mutation.addedNodes.forEach((node) => rewrite(node, true));
        }
    }
});

/**
 * Whether the page is one wire:navigate restored from its history cache with words already masked:
 * there's nothing to read them back from, so turning the mode off reloads it instead.
 */
let restoredWithMaskedWords = false;

/**
 * Bring the whole page in line with the mode. Turning back to real names also covers a
 * masked page wire:navigate restored from its history cache after the mode was turned off.
 */
function apply() {
    if (!enabled && restoredWithMaskedWords) {
        window.location.reload();

        return;
    }

    root.classList.toggle('privacy-mode', enabled);
    rewrite(root, enabled);
    root.classList.add('privacy-ready');

    // Rides along in the history snapshot, so a restored page can tell it came back masked.
    document.body?.toggleAttribute('data-privacy-masked-words', enabled && Object.keys(words).length > 0);
}

function setEnabled(value) {
    enabled = Boolean(value);

    try {
        localStorage.setItem(STORAGE_KEY, enabled ? '1' : '0');
    } catch {
        // Storage can be blocked; the mode still holds for this page.
    }

    apply();
    window.dispatchEvent(new CustomEvent('privacy-mode-changed', { detail: { enabled } }));
}

window.privacyMode = {
    get enabled() {
        return enabled;
    },
    set: setEnabled,
    toggle: () => setEnabled(!enabled),
};

document.addEventListener('keydown', (event) => {
    if ((event.metaKey || event.ctrlKey) && !event.altKey && !event.shiftKey && event.key === '/') {
        event.preventDefault();
        window.privacyMode.toggle();
    }
});

document.addEventListener('livewire:navigated', () => {
    restoredWithMaskedWords = document.body?.hasAttribute('data-privacy-masked-words') ?? false;
    readMaps();
    apply();
});

// The hostname map script arrived (or a new team's did, after a wire:navigate): unmask with the old map first.
window.addEventListener('privacy-hostnames-loaded', () => {
    if (enabled) {
        rewrite(root, false);
    }

    setMaps(decodeMap(window.ntbPrivacyHostnames), words);
    apply();
});

// A domain picked a new stand-in: put the old one back to the real name before masking with the new.
window.addEventListener('privacy-hostname-changed', (event) => {
    const { hostname, privacyHostname } = event.detail ?? {};

    if (!hostname || !privacyHostname) {
        return;
    }

    if (enabled) {
        rewrite(root, false);
    }

    setMaps({ ...hostnames, [hostname]: privacyHostname }, words);
    apply();
});

// Settings → Privacy added or removed a word: unmask with the old words before masking with the new.
window.addEventListener('privacy-words-changed', (event) => {
    const changed = event.detail?.words;

    if (!changed || typeof changed !== 'object') {
        return;
    }

    if (enabled) {
        rewrite(root, false);
    }

    setMaps(hostnames, Array.isArray(changed) ? {} : changed);
    apply();
});

readMaps();
apply();

observer.observe(root, {
    subtree: true,
    childList: true,
    characterData: true,
    attributes: true,
    attributeFilter: MASKED_ATTRIBUTES,
});
