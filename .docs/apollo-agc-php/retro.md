# Retro: /radish-build of apollo-agc-php

Session: dad784df-712a-44c9-b623-85ab8af74848, 2026-11-20. Trail read: `.docs/apollo-agc-php/implementation.md` (deviations, delivery gate), `.docs/apollo-agc-php/test-baseline.md`, `.docs/MEMORY/MEMORY.md` and the four facts this session wrote, `.docs/adr/` (one ADR), `.docs/CONTEXT.md` term names. No earlier retro exists. The project has no `CLAUDE.md`.

## Candidates

1. **Library: ship the chrome-devtools MCP with `--isolated`, and probe the browser once before dispatching slices**
   - Severity: high
   - Moment: three builders returned blocked on "The browser is already running for /Users/florislof/.cache/chrome-devtools-mcp/chrome-profile" (slices 1, 2 and 12), and each time the user had to answer "Chrome is gesloten, ga verder". The delivery reviewer reported "another actor drove the same shared Chrome. Page 9 reloaded under me". The installed config in `~/.claude.json` runs `npx chrome-devtools-mcp@latest` with no flags; the library catalog (`library.yaml`, `mcp:`) has no chrome-devtools entry.
   - Lands in: https://github.com/radishconcepts/radish-ai-library-collection/issues/8
   - Status: applied

2. **Library: give /radish-build a rule for a functional defect a later builder finds in an earlier slice's code**
   - Severity: high
   - Moment: the slice 7 report said "pressing PRO late (around t=20 s instead of 5 s) crashes the landing ... existing P63 ignition-timing behaviour from slice 2". Step 4a covers only security findings, so the orchestrator improvised a fix pass and committed it as `fix 1` without asking the user (`implementation.md`, "Fix between slices 8 and 9").
   - Lands in: https://github.com/radishconcepts/radish-ai-library-collection/issues/8
   - Status: applied

3. **Library: /radish-plan asks for late and missing input on every criterion where a person must act inside a time window**
   - Severity: medium
   - Moment: the plan's criterion "Zonder PRO volgt geen ontsteking, met PRO wel" (slice 4) had no late case, and Verification step 2 has a person press PRO by hand. Slice 4 reported "Without PRO the lander coasts and V99 keeps flashing, so the chapter never ends. The plan does not say what happens then"; slice 7 then found the late-PRO crash. The orchestrator had to add "a PRO must work late, and a chapter must end when it never comes" to every later brief by hand.
   - Lands in: https://github.com/radishconcepts/radish-ai-library-collection/issues/8
   - Status: applied

4. **Project rule: memory fact that page buttons keep focus and swallow the keyboard Enter**
   - Severity: medium
   - Moment: slice 4's learning "A focused DSKY button makes the keyboard Enter click that button as well as send ENTR ... blur the key after a mouse click" was not transcribed. Slice 13 added speed buttons without the blur, and the gate reported it as Medium A2; the delivery reviewer "lost one ENTR (V05N09) that way".
   - Lands in: `.docs/MEMORY/focused-button-swallows-enter.md` and `.docs/MEMORY/MEMORY.md`
   - Status: applied

5. **Project rule: extend `chrome-devtools-profile-lock.md` with one tab per session and screenshots without `filePath`**
   - Severity: low
   - Moment: slice 13 measured "1.68 sim s per wall second at 1× until I blanked the extra tab", because two tabs share one simulation through the session. Slice 8 reported "The chrome-devtools screenshot tool only writes inside configured workspace roots, so a path under the session scratchpad is refused."
   - Lands in: `.docs/MEMORY/chrome-devtools-profile-lock.md`
   - Status: applied

6. **Automated check: one `composer check` that runs PHPUnit, PHPStan and the two plan greps, wired into a pre-commit hook or CI**
   - Severity: medium
   - Moment: the `@see` grep and the `innerHTML` grep exist only in the plan's Tests and Verification sections. Every builder ran them by hand, the orchestrator carried them as standing checks in every brief, and the `@see` grep failed on three files from slice 1 until slice 5. The repo has no `.github/`, no `.husky/` and no pre-commit config; `composer.json` has only `test`, `stan` and `serve`.
   - Lands in: brief for /radish-plan
   - Status: briefed

7. **Library: /radish-plan orders the controls verification depends on (time acceleration) into an early slice**
   - Severity: low
   - Moment: slice 6 learned "A full hands-off landing is about 900 s of simulated time, so on the page at 1× it takes about 15 minutes of real time until the speed buttons exist in slice 13". Builders of slices 6 to 12 drove extra fetch ticks instead, and slice 8 noted these "consume log lines that the page's own loop then never shows", so the log could not be checked in the browser before slice 13.
   - Lands in: https://github.com/radishconcepts/radish-ai-library-collection/issues/8
   - Status: applied

8. **Library: /radish-build offers `git init` plus a root commit when the plan says the folder has no repo**
   - Severity: low
   - Moment: the plan said "Voor slice 1 is `git init` nodig ... Zonder repo kan de build niet committen", but Step 0.5 has no path for a missing repo and the run stopped to ask the user how to set up git.
   - Lands in: https://github.com/radishconcepts/radish-ai-library-collection/issues/8
   - Status: applied

9. **Library: /radish-build says in one line when TaskCreate and TaskList are not available, instead of requiring them**
   - Severity: low
   - Moment: `ToolSearch` for "select:TaskCreate,TaskUpdate,TaskList" returned "No matching deferred tools found", while `radish-build.md:83` says to create one task per slice.
   - Lands in: https://github.com/radishconcepts/radish-ai-library-collection/issues/8
   - Status: applied

## Brief for /radish-plan

One command, `composer check`, that fails when any of these fails:

- `vendor/bin/phpunit`
- `vendor/bin/phpstan analyse`
- `grep -rLE "@see (Luminary099|Comanche055)/" src/Guidance src/Agc src/Dsky` prints a file: a class in those folders has no reference to the original it ports or models.
- `grep -rn "innerHTML" public/assets` prints a line: frontend text must go through `textContent` or the canvas.

It comes from this build: the two greps existed only in the plan's Tests and Verification sections, every builder ran them by hand, and the `@see` grep failed on three files from slice 1 until slice 5. The repo has no guardrail to hook into yet: `composer.json` has the scripts `test`, `stan` and `serve`, and there is no `.github/workflows/`, `.husky/` or pre-commit config. The plan decides where `composer check` runs: a git pre-commit hook, a GitHub Actions job on push and pull request for `jester1979/apollo`, or both. `composer` is a zsh alias on this machine (`/Applications/MAMP/bin/php/composer`), so a hook must not depend on the alias.

## Not proposed

- A noun added to `NounTable` returning a 500 in another chapter: covered by `.docs/MEMORY/new-noun-needs-values-in-every-chapter.md`.
- The thin fuel margin of the landing: covered by `.docs/MEMORY/landing-fuel-budget.md`.
- Closures in the session graph: covered by `.docs/MEMORY/session-objects-hold-no-closures.md`.
- The orchestrator did not transcribe the learnings of slices 4, 8, 10 and 13: already in `radish-build.md` (Step 4, sub-step 2 requires it). Candidates 4 and 5 recover the two that mattered.
- Wrong line references in the plan (`GODSP`, P40 `:70`, the `FINDVAC` entry): the builders read the source as briefed and reported each one; no cost beyond a deviation line.
- Deviation groups that reached three slices without an escalation ("chosen values" 8×, "displays follow the source" 3×, "clock frozen until PRO" 3×): the plan's Assumptions assign the chosen values to the build, the other two follow the source or a brief requirement, and no slice was built on a wrong assumption.
- The stray `php -S` on port 8000 (PID 9651): every agent reused it; no cost.
- Floating-point equality on an exit threshold (slice 10): a one-off bug, fixed in the slice.
