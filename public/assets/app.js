import { createCodePanel } from './codepanel.js';
import { createDsky } from './dsky.js';
import { render as renderAscent } from './views/ascent.js';
import { render as renderLanding } from './views/landing.js';
import { render as renderLaunch } from './views/launch.js';
import { render as renderOrbit } from './views/orbit.js';

const API_URL = '/api.php';
const TICK_INTERVAL_MS = 100;

const views = {
    ascent: renderAscent, landing: renderLanding, launch: renderLaunch, orbit: renderOrbit,
};

// What the canvas shows for each view, for assistive technology.
const VIEW_LABELS = {
    ascent: 'Lunar module ascent stage lifting off from the descent stage, which stays on the lunar surface, side view',
    landing: 'Lunar module braking above the lunar surface',
    launch: 'Saturn V climbing to orbit, side view',
    orbit: 'Command and service module near the Moon, top view, with the path flown and the path predicted',
};

// The Apollo 11 scenario applies to the landing only; the API refuses it for the other chapters.
const SCENARIO_CHAPTER = 'landing';

const startButton = document.getElementById('start');
const chooser = document.getElementById('chooser');
const scenarioSwitch = document.getElementById('scenario');
const scenarioLabel = document.getElementById('scenario-label');
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

function selectedChapter() {
    const checked = chooser.querySelector('input[name="chapter"]:checked');

    return checked ? checked.value : 'launch';
}

// The scenario switch is for the landing: elsewhere it is off and disabled, and while a run is going too.
function updateScenarioSwitch() {
    const available = !running && selectedChapter() === SCENARIO_CHAPTER;
    if (selectedChapter() !== SCENARIO_CHAPTER) {
        scenarioSwitch.checked = false;
    }
    scenarioSwitch.disabled = !available;
    scenarioLabel.classList.toggle('is-disabled', !available);
}

chooser.addEventListener('change', updateScenarioSwitch);

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
        const failure = new Error(typeof data.error === 'string' ? data.error : 'Request failed');
        failure.status = response.status;
        throw failure;
    }

    return data;
}

function formatAltitude(metres) {
    return metres >= 10000 ? `${(metres / 1000).toFixed(1)} km` : `${metres.toFixed(0)} m`;
}

function show(state) {
    const view = views[state.vehicle.view];
    if (view) {
        view(ctx, state.vehicle, state.t);
    }
    canvas.setAttribute('aria-label', VIEW_LABELS[state.vehicle.view] ?? 'Vehicle view');
    dsky.render(state.dsky);
    codePanel.update(state.agc, state.log);
    statusLine.textContent = `t = ${state.t.toFixed(1)} s · altitude ${formatAltitude(state.vehicle.altitude)}`;
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
    const chapter = selectedChapter();
    const scenario = chapter === SCENARIO_CHAPTER && scenarioSwitch.checked;
    startButton.disabled = true;
    chooser.disabled = true;
    pendingKeys.length = 0;
    pendingRod = 0;
    running = true;
    updateScenarioSwitch();
    dsky.setEnabled(true);
    outcomeLine.textContent = '';
    delete outcomeLine.dataset.outcome;
    codePanel.reset();
    statusLine.textContent = 'Starting…';

    try {
        let state = await post({ action: 'start', chapter, scenario });
        startButton.textContent = 'Start again';
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
        // 501: the API knows the chapter but it is not built yet.
        statusLine.textContent = error.status === 501
            ? 'This chapter is not available yet.'
            : `Error: ${error.message}`;
    } finally {
        if (myRun === runId) {
            running = false;
            dsky.setEnabled(false);
        }
        startButton.disabled = false;
        chooser.disabled = false;
        updateScenarioSwitch();
    }
}

startButton.addEventListener('click', run);
updateScenarioSwitch();
