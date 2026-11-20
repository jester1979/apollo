import { createCodePanel } from './codepanel.js';
import { createDsky } from './dsky.js';
import { render as renderLanding } from './views/landing.js';

const API_URL = '/api.php';
const TICK_INTERVAL_MS = 100;

const views = { landing: renderLanding };

const startButton = document.getElementById('start');
const scenarioSwitch = document.getElementById('scenario');
const statusLine = document.getElementById('status');
const outcomeLine = document.getElementById('outcome');
const canvas = document.getElementById('view');
const ctx = canvas.getContext('2d');

// The most DSKY keys one tick may carry (TickRequest accepts 32).
const MAX_KEYS_PER_TICK = 32;

// The most ROD clicks one tick may carry (TickRequest accepts -10..10).
const MAX_ROD_PER_TICK = 10;

// Keys pressed since the last tick; the next tick takes them along.
const pendingKeys = [];
// ROD clicks since the last tick: arrow up is +1 (less descent rate), arrow down -1 (more).
let pendingRod = 0;
let running = false;

const dsky = createDsky(document.getElementById('dsky'), (name) => {
    if (running) {
        pendingKeys.push(name);
    }
});
dsky.render({
    prog: '', verb: '', noun: '', r1: '', r2: '', r3: '', flash: false, lights: {},
});

const codePanel = createCodePanel(document.getElementById('codepanel'));

document.addEventListener('keydown', (event) => {
    if (!running || event.repeat || event.ctrlKey || event.metaKey || event.altKey) {
        return;
    }
    if (event.key !== 'ArrowUp' && event.key !== 'ArrowDown') {
        return;
    }
    if (event.target?.closest('input, textarea, select')) {
        return;
    }
    event.preventDefault();
    const click = event.key === 'ArrowUp' ? 1 : -1;
    pendingRod = Math.min(Math.max(pendingRod + click, -MAX_ROD_PER_TICK), MAX_ROD_PER_TICK);
});

let runId = 0;

async function post(payload) {
    const response = await fetch(API_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify(payload),
    });

    let data;
    try {
        data = await response.json();
    } catch {
        throw new Error('Unexpected response from the server');
    }
    if (!response.ok) {
        throw new Error(typeof data.error === 'string' ? data.error : 'Request failed');
    }

    return data;
}

function show(state) {
    const view = views[state.vehicle.view];
    if (view) {
        view(ctx, state.vehicle, state.t);
    }
    dsky.render(state.dsky);
    codePanel.update(state.agc, state.log);
    statusLine.textContent = `t = ${state.t.toFixed(1)} s · altitude ${state.vehicle.altitude.toFixed(0)} m`;
    if (state.outcome !== null) {
        outcomeLine.dataset.outcome = state.outcome;
        outcomeLine.textContent = `Outcome: ${state.outcome}`;
    }
}

function delay(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

async function run() {
    const myRun = ++runId;
    startButton.disabled = true;
    scenarioSwitch.disabled = true;
    pendingKeys.length = 0;
    pendingRod = 0;
    running = true;
    dsky.setEnabled(true);
    outcomeLine.textContent = '';
    delete outcomeLine.dataset.outcome;
    codePanel.reset();
    statusLine.textContent = 'Starting…';

    try {
        let state = await post({ action: 'start', chapter: 'landing', scenario: scenarioSwitch.checked });
        show(state);

        // One tick in flight at a time: await the answer, render, then wait.
        while (state.outcome === null && myRun === runId) {
            await delay(TICK_INTERVAL_MS);
            const rod = pendingRod;
            pendingRod = 0;
            state = await post({
                action: 'tick',
                keys: pendingKeys.splice(0, MAX_KEYS_PER_TICK),
                rod,
                speed: 1,
            });
            show(state);
        }
    } catch (error) {
        statusLine.textContent = `Error: ${error.message}`;
    } finally {
        if (myRun === runId) {
            running = false;
            dsky.setEnabled(false);
        }
        startButton.disabled = false;
        scenarioSwitch.disabled = false;
        startButton.textContent = 'Start again';
    }
}

startButton.addEventListener('click', run);
