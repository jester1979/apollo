---
name: landing-fuel-budget
description: The landing chapter's fuel margin is thin; re-run a hands-off landing after any change to targets, start state or vehicle mass
metadata:
  type: project
---

The LM in `src/Scenario/StartStates.php` (15 200 kg, 6 500 kg dry) lands hands-off with about 6% fuel left. With the original 7 000 kg dry mass the same landing ended `out_of_fuel` or with 0.9% left. Reported by the builder of slice 6 of `apollo-agc-php` on 2026-11-20.

**Why:** P63 leaves about 480 m/s of delta-v, and P64 plus P65 use nearly all of it. A small change in the braking or approach targets, the start state or the masses turns a success into `out_of_fuel`.

**How to apply:** After changing `LandingTargets.php`, `StartStates.php`, the throttle logic or the guidance timing, run a hands-off landing to touchdown (the test in `tests/Guidance/LandingGuidanceTest.php`) and check the fuel left. A full landing is about 900 s of simulated time; in the browser, drive extra ticks at speed 10. See [[session-objects-hold-no-closures]] for the other constraint on this graph.
