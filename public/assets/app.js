import { createCodePanel } from './codepanel.js';
import { createDsky } from './dsky.js';
import { render as renderAscent } from './views/ascent.js';
import { render as renderEntry } from './views/entry.js';
import { DURATION_MS as INTERLUDE_MS, render as renderInterlude } from './views/interlude.js';
import { render as renderLanding } from './views/landing.js';
import { render as renderLaunch } from './views/launch.js';
import { render as renderOrbit } from './views/orbit.js';

const API_URL = '/api.php';
const TICK_INTERVAL_MS = 100;

const views = {
    ascent: renderAscent, entry: renderEntry, landing: renderLanding, launch: renderLaunch, orbit: renderOrbit,
};

// What the canvas shows for each view, for assistive technology.
const VIEW_LABELS = {
    ascent: 'Lunar module ascent stage lifting off from the descent stage, which stays on the lunar surface, side view',
    entry: 'Command module entering the atmosphere, side view, with a glow that grows with the drag, a bank angle pointer and parachutes at the end',
    landing: 'Lunar module braking above the lunar surface',
    launch: 'Saturn V climbing to orbit, side view',
    orbit: 'Command and service module near the Moon, top view, with the path flown and the path predicted',
};

// The chapter that ends the mission: a success there shows "Mission complete".
const LAST_CHAPTER = 'entry';

// The Apollo 11 scenario applies to the landing only; the API refuses it for the other chapters.
const SCENARIO_CHAPTER = 'landing';

const startButton = document.getElementById('start');
const chooser = document.getElementById('chooser');
const scenarioSwitch = document.getElementById('scenario');
const scenarioLabel = document.getElementById('scenario-label');
const statusLine = document.getElementById('status');
const outcomeLine = document.getElementById('outcome');
const speedGroup = document.getElementById('speed');
const nextButton = document.getElementById('next');
const missionComplete = document.getElementById('mission-complete');
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
// The simulation speed the next tick asks for: 1, 2, 5 or 10 (the server validates it again).
let speed = 1;
// What the server offered after the last successful chapter: {chapter, interlude}, or null.
let nextOffer = null;
let interludePlaying = false;
const INTERLUDE_FRAME_MS = 33;

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

speedGroup.addEventListener('click', (event) => {
    const button = event.target instanceof Element ? event.target.closest('.speed-button') : null;
    const chosen = button ? Number(button.dataset.speed) : 0;
    if (![1, 2, 5, 10].includes(chosen)) {
        return;
    }
    speed = chosen;
    for (const other of speedGroup.querySelectorAll('.speed-button')) {
        other.setAttribute('aria-pressed', String(other === button));
    }
});

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

// Each tab has its own simulation on the server: the id is made up once per page load.
const TAB_ID = Array.from(crypto.getRandomValues(new Uint8Array(8)), (byte) => byte.toString(16).padStart(2, '0')).join('');

async function post(payload) {
    const response = await fetch(API_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ ...payload, tab: TAB_ID }),
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
    statusLine.textContent = `t = ${state.t.toFixed(1)} s · speed ${state.speed}× · altitude ${formatAltitude(state.vehicle.altitude)}`;
    if (state.outcome !== null) {
        outcomeLine.dataset.outcome = state.outcome;
        outcomeLine.textContent = `Outcome: ${state.outcome}`;
        offerNext(state);
    }
}

// After a success the server names the next chapter ("next"); after the entry there is none and the mission is complete.
function offerNext(state) {
    nextOffer = state.next ?? null;
    nextButton.hidden = nextOffer === null;
    missionComplete.hidden = !(state.outcome === 'success' && nextOffer === null && state.chapter === LAST_CHAPTER);
}

function hideNext() {
    nextOffer = null;
    nextButton.hidden = true;
    missionComplete.hidden = true;
}

function delay(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

async function run(chapter = selectedChapter()) {
    const myRun = ++runId;
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
    hideNext();
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
                speed,
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
        startButton.disabled = interludePlaying;
        chooser.disabled = interludePlaying;
        updateScenarioSwitch();
    }
}

// Plays the interlude on the canvas for a few seconds; a reduced-motion preference holds one still frame.
async function playInterlude(offer) {
    const still = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const started = performance.now();
    canvas.setAttribute('aria-label', `Interlude: ${offer.interlude}`);
    statusLine.textContent = offer.interlude;
    for (let elapsed = 0; elapsed < INTERLUDE_MS; elapsed = performance.now() - started) {
        renderInterlude(ctx, offer, still ? 0.7 : elapsed / INTERLUDE_MS);
        await delay(INTERLUDE_FRAME_MS);
    }
    renderInterlude(ctx, offer, still ? 0.7 : 1);
}

// "Next chapter": the interlude, then the next chapter through the normal start request, never with the scenario.
async function goToNextChapter() {
    const offer = nextOffer;
    if (offer === null || running || interludePlaying) {
        return;
    }
    interludePlaying = true;
    nextButton.disabled = true;
    startButton.disabled = true;
    chooser.disabled = true;
    try {
        await playInterlude(offer);
    } finally {
        interludePlaying = false;
        nextButton.disabled = false;
        startButton.disabled = false;
        chooser.disabled = false;
    }
    const radio = chooser.querySelector(`input[name="chapter"][value="${CSS.escape(offer.chapter)}"]`);
    if (radio) {
        radio.checked = true;
    }
    scenarioSwitch.checked = false;
    await run(offer.chapter);
}

startButton.addEventListener('click', () => run());
nextButton.addEventListener('click', goToNextChapter);
updateScenarioSwitch();
