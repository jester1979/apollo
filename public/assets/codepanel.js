// The code panel: which original AGC routine runs (as a link to its line on GitHub), the program, and
// the log of what the crew keyed in and what the computer did. Every text goes in through textContent.
// A link gets its href only when the server's URL points into the pinned Apollo-11 repository.

const MAX_LOG_LINES = 200;
const SOURCE_URL_PREFIX = 'https://github.com/chrislgarry/Apollo-11/blob/';
const KINDS = ['input', 'agc', 'alarm'];

function isSourceUrl(url) {
    return typeof url === 'string' && url.startsWith(SOURCE_URL_PREFIX);
}

// Fills `link` with the routine; without a trustworthy URL it stays a link-less anchor and gets no href.
function fillRoutineLink(link, routine, withLocation) {
    link.textContent = withLocation
        ? `${routine.label} · ${routine.file}:${routine.line}`
        : routine.label;
    if (isSourceUrl(routine.url)) {
        link.href = routine.url;
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
    } else {
        link.removeAttribute('href');
    }
}

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

function formatTime(seconds) {
    return `${Number(seconds).toFixed(1)} s`;
}

// A row of boxes for the core sets or the VAC areas of the Executive; a box shows the name of its job.
function createBoxes(title, count) {
    const group = element('div', 'codepanel-exec-group');
    const heading = element('h3', 'codepanel-exec-title', title);
    const list = element('ol', 'codepanel-boxes');
    const boxes = [];
    for (let index = 0; index < count; index += 1) {
        const box = element('li', 'codepanel-box', '');
        box.dataset.state = 'free';
        boxes.push(box);
    }
    list.append(...boxes);
    group.append(heading, list);

    return {
        group,
        // Fills the boxes from a list of job names (null: free); any other value counts as free.
        set(names) {
            boxes.forEach((box, index) => {
                const name = Array.isArray(names) ? names[index] : null;
                const taken = typeof name === 'string' && name !== '';
                box.textContent = taken ? name : '';
                box.dataset.state = taken ? 'taken' : 'free';
                box.setAttribute('aria-label', taken ? `${title} ${index + 1}: ${name}` : `${title} ${index + 1}: free`);
            });
        },
    };
}

export function createCodePanel(root) {
    const program = element('p', 'codepanel-program');
    const routineLine = element('p', 'codepanel-routine');
    const routineLabel = element('span', 'codepanel-label', 'Routine: ');
    const routineLink = element('a', 'codepanel-link');
    routineLine.append(routineLabel, routineLink);

    const executive = element('div', 'codepanel-exec');
    const executiveTitle = element('h2', 'codepanel-title', 'Executive');
    const coreSets = createBoxes('Core sets', 7);
    const vacAreas = createBoxes('VAC areas', 5);
    const restarts = element('p', 'codepanel-restarts');
    const restartLabel = element('span', 'codepanel-label', 'RESTART ');
    const restartCount = element('strong', 'codepanel-restart-count', '0');
    const cycleSteal = element('span', 'codepanel-cycle-steal', '');
    restarts.append(restartLabel, restartCount, cycleSteal);
    executive.append(executiveTitle, coreSets.group, vacAreas.group, restarts);
    coreSets.set([]);
    vacAreas.set([]);

    const logTitle = element('h2', 'codepanel-title', 'Log');
    const log = element('ol', 'codepanel-log');
    log.tabIndex = 0;
    log.setAttribute('aria-label', 'Log of DSKY input and AGC events, newest first');

    root.replaceChildren(program, routineLine, executive, logTitle, log);

    let lastSeq = 0;

    function addLine(line) {
        const item = element('li', 'codepanel-line');
        item.dataset.kind = KINDS.includes(line.kind) ? line.kind : 'agc';
        item.append(
            element('span', 'codepanel-time', formatTime(line.t)),
            element('span', 'codepanel-kind', item.dataset.kind),
            element('span', 'codepanel-text', line.text),
        );
        if (line.routine) {
            const link = element('a', 'codepanel-routine-ref');
            fillRoutineLink(link, line.routine, false);
            item.append(link);
        }
        log.prepend(item);
        while (log.children.length > MAX_LOG_LINES) {
            log.lastElementChild.remove();
        }
    }

    return {
        // Takes the "agc" and "log" parts of a tick response; the log holds only lines not sent before.
        update(agc, lines) {
            program.textContent = agc.program;
            if (agc.routine) {
                fillRoutineLink(routineLink, agc.routine, true);
            } else {
                routineLink.textContent = 'none';
                routineLink.removeAttribute('href');
            }
            const state = agc.executive;
            if (state) {
                coreSets.set(state.coreSets);
                vacAreas.set(state.vacAreas);
                restartCount.textContent = String(Number(state.restarts) || 0);
                const steal = Math.round((Number(state.cycleSteal) || 0) * 100);
                cycleSteal.textContent = steal > 0 ? ` · cycle steal ${steal}%` : '';
            }
            for (const line of lines) {
                if (line.seq > lastSeq) {
                    lastSeq = line.seq;
                    addLine(line);
                }
            }
        },
        reset() {
            lastSeq = 0;
            program.textContent = '';
            routineLink.textContent = '';
            routineLink.removeAttribute('href');
            coreSets.set([]);
            vacAreas.set([]);
            restartCount.textContent = '0';
            cycleSteal.textContent = '';
            log.replaceChildren();
        },
    };
}
