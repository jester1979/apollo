// Draws the DSKY in HTML and CSS: PROG, VERB and NOUN, the registers R1 to R3, the lights and the
// 19 keys. Every text goes in through textContent. The keys are drawn only; pressing them does
// nothing yet.

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
 * Builds the DSKY inside `container` and returns `{ render(dsky) }`, where `dsky` is the `dsky`
 * object of the tick response.
 */
export function createDsky(container) {
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
            button.dataset.key = key;
            group.append(button);
        }
        keypad.append(group);
    }

    const panel = element('div', 'dsky-panel');
    panel.append(display, keypad);
    container.replaceChildren(panel);

    return {
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
