// Draws the DSKY in HTML and CSS: PROG, VERB and NOUN, the registers R1 to R3, the lights and the
// 19 keys. Every text goes in through textContent. A key press, by mouse or keyboard, is handed to
// `onKey` as the name of the Key enum of the API; the page sends the queued keys with the next tick.

const LIGHTS = [
    ['compActy', 'COMP ACTY'],
    ['uplinkActy', 'UPLINK ACTY'],
    ['noAtt', 'NO ATT'],
    ['stby', 'STBY'],
    ['keyRel', 'KEY REL'],
    ['oprErr', 'OPR ERR'],
    ['temp', 'TEMP'],
    ['gimbalLock', 'GIMBAL LOCK'],
    ['prog', 'PROG'],
    ['restart', 'RESTART'],
    ['tracker', 'TRACKER'],
    ['alt', 'ALT'],
    ['vel', 'VEL'],
];

// The key names of the API (the Key enum on the server) per key label.
const KEY_NAMES = {
    'VERB': 'VERB',
    'NOUN': 'NOUN',
    'ENTR': 'ENTR',
    'PRO': 'PRO',
    'CLR': 'CLR',
    'RSET': 'RSET',
    'KEY REL': 'KEY_REL',
    '+': 'PLUS',
    '-': 'MINUS',
};

// The keyboard: V, N, Enter, P, C, R, K, + and -, and the digits.
const KEYBOARD = {
    'v': 'VERB',
    'n': 'NOUN',
    'Enter': 'ENTR',
    'p': 'PRO',
    'c': 'CLR',
    'r': 'RSET',
    'k': 'KEY_REL',
    '+': 'PLUS',
    '-': 'MINUS',
};

function keyboardName(key) {
    if (/^[0-9]$/.test(key)) {
        return key;
    }
    for (const candidate of [key, key.toLowerCase()]) {
        if (Object.hasOwn(KEYBOARD, candidate)) {
            return KEYBOARD[candidate];
        }
    }

    return undefined;
}

// Shows a keyboard press on the key of the panel for a moment.
function flashKey(buttons, name) {
    const button = buttons.find((candidate) => candidate.dataset.key === name);
    if (button) {
        button.classList.add('pressed');
        setTimeout(() => button.classList.remove('pressed'), 150);
    }
}

function keyName(label) {
    return Object.hasOwn(KEY_NAMES, label) ? KEY_NAMES[label] : label;
}

// The 19 keys: VERB and NOUN on the left, the sign and digit block in the middle, five control keys
// on the right.
const KEY_GROUPS = [
    ['dsky-keys-left', ['VERB', 'NOUN']],
    ['dsky-keys-digits', ['+', '7', '8', '9', '-', '4', '5', '6', '0', '1', '2', '3']],
    ['dsky-keys-right', ['CLR', 'PRO', 'KEY REL', 'ENTR', 'RSET']],
];

function element(tag, className, text) {
    const node = document.createElement(tag);
    if (className) {
        node.className = className;
    }
    if (text !== undefined) {
        node.textContent = text;
    }

    return node;
}

function labelled(title, valueClass) {
    const wrapper = element('div', 'dsky-field');
    const value = element('div', `dsky-digits ${valueClass}`);
    wrapper.append(element('span', 'dsky-field-label', title), value);

    return { wrapper, value };
}

/**
 * Builds the DSKY inside `container` and returns `{ render(dsky), setEnabled(enabled) }`, where
 * `dsky` is the `dsky` object of the tick response. `onKey(name)` gets the API name of every key
 * pressed on the keys or the keyboard while the DSKY is enabled.
 */
export function createDsky(container, onKey = () => {}) {
    let enabled = false;
    const buttons = [];
    const lights = new Map();
    const lightPanel = element('div', 'dsky-lights');
    for (const [name, label] of LIGHTS) {
        const light = element('div', 'dsky-light', label);
        light.dataset.light = name;
        lights.set(name, light);
        lightPanel.append(light);
    }

    const prog = labelled('PROG', 'dsky-prog');
    const verb = labelled('VERB', 'dsky-verb');
    const noun = labelled('NOUN', 'dsky-noun');
    const r1 = labelled('R1', 'dsky-register');
    const r2 = labelled('R2', 'dsky-register');
    const r3 = labelled('R3', 'dsky-register');

    const codes = element('div', 'dsky-codes');
    codes.append(prog.wrapper, verb.wrapper, noun.wrapper);

    const display = element('div', 'dsky-display');
    display.append(lightPanel, codes, r1.wrapper, r2.wrapper, r3.wrapper);

    const keypad = element('div', 'dsky-keys');
    for (const [groupClass, keys] of KEY_GROUPS) {
        const group = element('div', groupClass);
        for (const key of keys) {
            const button = element('button', 'dsky-key', key);
            button.type = 'button';
            button.disabled = true;
            button.dataset.key = keyName(key);
            button.addEventListener('click', () => {
                if (enabled) {
                    onKey(keyName(key));
                }
            });
            buttons.push(button);
            group.append(button);
        }
        keypad.append(group);
    }

    const panel = element('div', 'dsky-panel');
    panel.append(display, keypad);
    container.replaceChildren(panel);

    // After a mouse click the focus must not stay on a button, on the DSKY or anywhere else on the page
    // (speed, start, next chapter): Enter on the keyboard is ENTR, not another press of the button that
    // was clicked. Keyboard activation has detail 0 and keeps its focus.
    document.addEventListener('click', (event) => {
        const clicked = event.target instanceof Element ? event.target.closest('button') : null;
        if (event.detail > 0 && clicked instanceof HTMLElement) {
            clicked.blur();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (!enabled || event.repeat || event.ctrlKey || event.metaKey || event.altKey) {
            return;
        }
        // Typing in a field, or Enter on a focused button (which clicks it itself), is not a DSKY key.
        const target = event.target instanceof Element ? event.target : null;
        if (target?.closest('input, textarea, select') || (event.key === 'Enter' && target?.closest('button'))) {
            return;
        }

        const name = keyboardName(event.key);
        if (name !== undefined) {
            onKey(name);
            flashKey(buttons, name);
        }
    });

    return {
        setEnabled(value) {
            enabled = value;
            for (const button of buttons) {
                button.disabled = !value;
            }
        },
        render(dsky) {
            prog.value.textContent = dsky.prog;
            verb.value.textContent = dsky.verb;
            noun.value.textContent = dsky.noun;
            verb.value.classList.toggle('flash', dsky.flash);
            noun.value.classList.toggle('flash', dsky.flash);
            r1.value.textContent = dsky.r1;
            r2.value.textContent = dsky.r2;
            r3.value.textContent = dsky.r3;
            for (const [name, light] of lights) {
                light.classList.toggle('on', dsky.lights[name] === true);
            }
        },
    };
}
