# Apollo AGC in PHP

This project ports the core of the Apollo 11 guidance software to readable PHP 8.3 and shows it in a browser. The command module computer runs Comanche055, the lunar module computer runs Luminary099. The page plays the mission as six chapters. Each chapter has the DSKY of the active computer, a Canvas view of the vehicle, and a code panel. The code panel names the original AGC routine that runs, with a link to its line in the source, and logs each key press and what the computer did with it.

It is a selective port, not an emulator. The PHP classes follow the original routines (`QUADGUID`, `STEERING`, `VERBFAN` and others), and each one names its original in an `@see` line. Nothing here runs the original assembly. The decision is in [`.docs/adr/0001-selectieve-port-in-plaats-van-emulator.md`](.docs/adr/0001-selectieve-port-in-plaats-van-emulator.md) (in Dutch).

## Source and licence

The source is [chrislgarry/Apollo-11](https://github.com/chrislgarry/Apollo-11), pinned to commit `e7576d50f7c279a707cfaa71be2020592dbf43a8`. Its `LICENSE.md` is the Public Domain Mark 1.0: the work is free of known copyright restrictions. Every file and line number cited in the code and in the code panel refers to that commit.

This port is under the MIT licence, see [`LICENSE`](LICENSE). The source is public domain, so any licence is allowed for the port.

## Install and start

You need PHP 8.3 and Composer. There are no runtime dependencies.

```
composer install
composer serve
```

`composer serve` runs `php -S 127.0.0.1:8000 -t public`; you can run that command yourself instead. Then open <http://127.0.0.1:8000/>, pick a chapter, and press "Start chapter". The simulation state lives in your PHP session, so each visitor flies a private mission.

## Using the page

- Chapter chooser: the six chapters can be started in any order. The chooser is locked while a chapter runs.
- Speed buttons: 1×, 2×, 5× and 10× set how much simulated time each tick computes (0.1 s times the speed). The clock on the Canvas and noun 65 on the DSKY (sampled AGC time) run faster at the higher speeds. The chapter can be flown at any speed.
- Next chapter: after a chapter ends in success, "Next chapter" plays an interlude of a few seconds, then starts the following chapter. The interludes cover what the port does not fly: "Trans-lunar injection and coast", "Undocking and descent orbit", "On the surface", "Rendezvous and docking" and "Coast back to Earth". After a successful entry the page shows "Mission complete".
- Apollo 11 scenario: a switch next to the start button, for the Landing only. It adds a 15% cycle steal to the Executive, so the 1201 and 1202 alarms come from the load on the ported Executive.

## Keys

The DSKY keys can be clicked or typed.

| Keyboard | DSKY key |
|---|---|
| `V` | VERB |
| `N` | NOUN |
| `Enter` | ENTR |
| `P` | PRO |
| `C` | CLR |
| `R` | RSET |
| `K` | KEY REL |
| `+` and `-` | + and - |
| `0` to `9` | digits |
| Arrow up, arrow down | ROD switch in the landing: arrow up gives less descent rate, arrow down more, 1 ft/s per press |

A command is a verb and a noun, for example `V 1 6 N 6 5 Enter` monitors the sampled time. The DSKY knows only the verbs and nouns the chapters use. Anything else lights OPR ERR, and RSET clears it. V35 is the lamp test.

## The six chapters

| Chapter | Computer and program | What you do |
|---|---|---|
| Launch | CM, P11 | Nothing. The Saturn V is flown by a fixed pitch programme and P11 only monitors, with V06N62 (VI, HDOT, H). Success is an orbit with a perigee above 150 km. |
| Lunar orbit insertion | CM, P40 | PRO on V50N25 (checklist 00203), then PRO on the flashing V99N40 from TIG-5 s. The SPS burns retrograde. Success is a bound lunar orbit with a perilune above 50 km. Without the PRO on V99 the chapter is missed. |
| Landing | LM, P63 to P66 | PRO on the flashing V99N63. P63 brakes, P64 approaches, P65 lands at 3 ft/s by itself. An arrow key in P64 or P65 switches to P66, where each press changes the descent rate by 1 ft/s. Success is contact below 3 m/s vertical and 1.2 m/s horizontal. |
| Ascent | LM, P12 | PRO on the flashing V06N76, then PRO on the flashing V99N74. The ascent stage lifts off and the descent stage stays. Success is a lunar orbit with a perilune above 15 km. |
| Trans-Earth injection | CM, P40 | The same as the lunar orbit insertion, but prograde. Success is an escape trajectory from the Moon. |
| Entry | CM, P63, P64, P67 | Nothing. The programs follow each other. The capsule holds a 60° bank in P64, then P67 steers the bank angle to the target. Success is parachutes under 7 km at less than 200 m/s. A skip out of the atmosphere or more than 12 g is a miss. |

In the Lunar orbit insertion, the Ascent and the Trans-Earth injection the chapter clock waits for the first PRO.

## Tests

```
composer test
composer stan
```

`composer test` runs PHPUnit. `composer stan` runs PHPStan at level 8.

## Limits

- Not an emulator, and not the whole software. Only the routines, verbs and nouns the six chapters need are ported.
- The pad-loaded values are chosen. The landing targets (`RBRFG`, `VBRFG`, `ABRFG` and their approach counterparts) and the ROD click scale are erasable memory that the pad loaded before flight, so they are not in the source. `src/Scenario/LandingTargets.php` holds values picked so that high gate and low gate fall near the usual heights. The injection altitude of the ascent is a pad load in Luminary099 as well, so it is also a chosen value.
- The Saturn V is flown by a fixed pitch programme in `src/Physics/SaturnV.php`. The LVDC code is not in the source, and the code panel says so.
- The entry is simplified. The bank angle comes from a numerical range predictor instead of the reference tables of `PREDICT3`. P65 (the skip phase) is left out, and the chapter starts at the entry interface, so P61 and P62 do not run.
- In P66 the computer drives the horizontal speed to zero itself, because the page has no attitude control. The crew did this by hand.
- The physics is 2D. Start states and vehicle data are rounded public values, not checked against the Apollo 11 Mission Report (`src/Scenario/StartStates.php`). The lunar module weighs 6 500 kg dry at the start of the landing.
- The Executive job costs (`src/Chapter/Landing/LandingLoad.php`) and the 15% cycle steal are chosen so that the scenario raises alarms and the plain landing does not. They are not measured AGC timings.
- Rendezvous, docking, the trans-lunar coast and the time on the surface are interludes, not simulation. Aborts and the hand controller are not included.
