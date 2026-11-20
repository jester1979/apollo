import { render as renderLanding } from './views/landing.js';

const API_URL = '/api.php';
const TICK_INTERVAL_MS = 100;

const views = { landing: renderLanding };

const startButton = document.getElementById('start');
const statusLine = document.getElementById('status');
const outcomeLine = document.getElementById('outcome');
const canvas = document.getElementById('view');
const ctx = canvas.getContext('2d');

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
    outcomeLine.textContent = '';
    delete outcomeLine.dataset.outcome;
    statusLine.textContent = 'Starting…';

    try {
        let state = await post({ action: 'start', chapter: 'landing', scenario: false });
        show(state);

        // One tick in flight at a time: await the answer, render, then wait.
        while (state.outcome === null && myRun === runId) {
            await delay(TICK_INTERVAL_MS);
            state = await post({ action: 'tick', keys: [], rod: 0, speed: 1 });
            show(state);
        }
    } catch (error) {
        statusLine.textContent = `Error: ${error.message}`;
    } finally {
        startButton.disabled = false;
        startButton.textContent = 'Start again';
    }
}

startButton.addEventListener('click', run);
