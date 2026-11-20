# apollo-agc-php: Apollo 11-boordcomputer als PHP-port met DSKY en visualisatie

## Context

Dit project port de kern van de Apollo 11-boordcomputersoftware (Comanche055 voor de commandomodule, Luminary099 voor de maanlander) naar leesbare PHP 8.3. Een webpagina toont de missie als zes hoofdstukken: lancering, intrede in de maanbaan, landing, opstijgen, vertrek naar de aarde en terugkeer. Per hoofdstuk zie je één DSKY van de actieve AGC, een Canvas-weergave van het voertuig en een codepaneel. Het codepaneel toont welke originele AGC-routine draait, met een log van "invoer → wat de computer deed". De gebruiker bedient de DSKY in elk hoofdstuk en stelt in P66 de daalsnelheid bij met de ROD-schakelaar. Het Apollo 11-scenario laat in het landingshoofdstuk de alarmen 1201 en 1202 ontstaan uit echte overbelasting van de geporte Executive.

Bron: [chrislgarry/Apollo-11](https://github.com/chrislgarry/Apollo-11), public domain (`LICENSE.md`: Public Domain Mark 1.0). Alle citaten `Luminary099/…:regel` en `Comanche055/…:regel` in dit plan gelden voor commit `e7576d50f7c279a707cfaa71be2020592dbf43a8`. Basis-URL: `https://github.com/chrislgarry/Apollo-11/blob/e7576d50f7c279a707cfaa71be2020592dbf43a8/`. Het werk komt in [jester1979/apollo](https://github.com/jester1979/apollo). Termen volgen [CONTEXT.md](../CONTEXT.md): hoofdstuk, tussenstuk, Apollo 11-scenario, AGC, DSKY, port.

## Current state

Greenfield. De werkmap bevat alleen `.idea/` en `.docs/`. Er is geen git-repo, en `jester1979/apollo` is leeg (`gh repo view`: `isEmpty: true`). Daarom heeft dit plan geen `Base:`-regel.

Omgeving:
- PHP 8.3.36 CLI staat op `/Applications/MAMP/bin/php/php8.3.36/bin/php`, en ook een niet-interactieve `bash` vindt `php` via PATH.
- `composer` (2.10.2) is alleen een zsh-alias naar `/Applications/MAMP/bin/php/composer`. Gebruik in scripts en subagents dat volledige pad.

Uit de originele bron gebruikt dit plan:
- Landing:
  - P63 start bij `P63LM` (`Luminary099/THE_LUNAR_LANDING.agc:40`).
  - De hoofdvergelijking is `QUADGUID` (`Luminary099/LUNAR_LANDING_GUIDANCE_EQUATIONS.agc:546`). Het commentaar erboven (:529-544) geeft de gepubliceerde vorm: `ACG = ADG + 6(VDG + VG)/TTF + 12(RDG − RG)/TTF²`.
  - TTF wordt opgelost met de Newton-wortelzoeker `ROOTPSRS` (:1272).
  - P64 begint bij `STARTP64` (:260). P65 begint bij `P65START` (:252) en `P65VERT` (:907). P66 loopt via `STARTP66` (:143), `P66VERT` (:919) en `RODCOMP` (:948).
  - De weergaven zijn `V06N63`, `V06N64` en `V06N60` (:1457-1459).
  - De doelwaarden `RDG = RBRFG`, `VDG = VBRFG` en `ADG = ABRFG` (:1435-1437) zijn pad-loaded erasables (`ERASABLE_ASSIGNMENTS.agc:68`). Hun waarden staan niet in de bron.
  - De ROD-klikschaal `RODSCALE` (`ERASABLE_ASSIGNMENTS.agc:1391`) is ook pad-loaded.
- Branding en ontsteking: `BURNBABY` (`Luminary099/BURN_BABY_BURN--MASTER_IGNITION_ROUTINE.agc:159`) met de tabellen `P63TABLE` (:127) en `P12TABLE` (:74).
- Opstijgen:
  - `P12LM` (`Luminary099/P12.agc:35`), met `V06N76` (:111).
  - De geleiding zit in `ATMAG` en `ASCENT` (`Luminary099/ASCENT_GUIDANCE.agc:38`, `:90`), met `V16N63` (:539).
- Executive en alarmen:
  - Er zijn 7 core sets (`Luminary099/EXECUTIVE.agc:162`, `NO.CORES DEC 7`) en 5 VAC-gebieden (`VAC1USE` t/m `VAC5USE`, :134-142).
  - 1201 wordt opgegooid bij :147, 1202 bij :208.
  - De herstart loopt via `BAILOUT` (`Luminary099/ALARM_AND_ABORT.agc:133`) en `GOPROG` (`Luminary099/FRESH_START_AND_RESTART.agc:206`).
  - Radarwerk wordt gestart als NOVAC-job met `PRIO25` (`Luminary099/RADAR_LEADIN_ROUTINES.agc:47-48`).
  - De SERVICER plant zich elke 2 seconden opnieuw in (`Luminary099/SERVICER.agc:73`).
- DSKY:
  - `VERBFAN` (`Luminary099/PINBALL_GAME_BUTTONS_AND_LIGHTS.agc:970`), `PROCKEY` (:2874), `CHARALRM` (OPR ERR, :2744) en `DSPMM` (:3277).
  - `V37` (`FRESH_START_AND_RESTART.agc:739`) met `V37NONO` (:978).
  - De verbs en nouns staan in `Luminary099/ASSEMBLY_AND_OPERATION_INFORMATION.agc:166-269` (verbs) en `:284-479` (nouns). Voor de CM staat hetzelfde in het overeenkomstige bestand in `Comanche055/`.
- Lancering: `P11` (`Comanche055/P11.agc:158`), met de weergave `VHHDOT` (:409) en `V06N62` (:416).
- Branding van de commandomodule:
  - `P40CSM` (`Comanche055/P40-P47.agc:46`), met de checklist `V50N25 R1=203` (:70), `V06N40` (:336) en `V99P/TIG` (:1002).
  - De sturing loopt via `UPDATEVG` (:744), `STEERING` (:819), `S40.8` (:1646) en `S40.81` (TGO onder 4 s, :1759).
- Terugkeer:
  - P61 t/m P67 staan in `Comanche055/P61-P67.agc` (:69-477), met per programma een weergave: P63 `V06N64` (:311), P65 `V16N69` (:407), P66 `V06N66` (:459) en P67 `V16N67` (:477).
  - De geleiding zit in `Comanche055/REENTRY_CONTROL.agc`: `STARTENT` (:48), `TARGETNG` (:212), `HUNTEST` (:493), `UPCONTRL` (:873), `KEP2` (:1098) en `PREDICT3` (:1130).

Inputchecks en guards: er is geen bestaande code, dus geen bestaande check om te verbreden. Het enige nieuwe toegangspunt is `public/api.php` (slice 1). De guards ervan staan bij slice 1.

## Reachability

1. `php -S 127.0.0.1:8000 -t public` in de repo-root (bestaat nog niet: slice 1 maakt `public/`, slice 13 documenteert het in `README.md`).
2. De browser opent `http://127.0.0.1:8000/`, dat `public/index.php` laadt (slice 1).
3. De hoofdstukkiezer op de pagina (slice 8). Slices 1 t/m 7 starten het landingshoofdstuk direct met een startknop.
4. `POST /api.php` met `action: start` en de keuze voor hoofdstuk en scenario (slice 1, uitgebreid in slices 7 en 8).
5. `public/assets/app.js` stuurt elke ~100 ms een tick met toetsen, ROD-klikken en snelheid (slice 1, toetsen in slice 4, ROD in slice 6, snelheid in slice 13).
6. `Simulation::tick()` laat het hoofdstuk rekenen: natuurkunde (slice 1), Executive (slice 7), geleiding (slices 2, 6, 8-12) en DSKY (slices 3-4).
7. Het JSON-antwoord wordt getekend als voertuig-canvas (slice 1 en per hoofdstuk), DSKY (slice 3) en codepaneel met log (slice 5).
8. Na een geslaagd hoofdstuk toont "Next chapter" het tussenstuk en start het volgende hoofdstuk (slice 13).

- [ ] Iemand met alleen PHP 8.3 en een browser kan na `composer install` en `php -S 127.0.0.1:8000 -t public` elk van de zes hoofdstukken kiezen en spelen via de pagina, zonder iets anders uit te voeren.

## Plan

### 1. Projectskelet

`composer.json` (nieuw):

```json
{
    "name": "jester1979/apollo",
    "description": "Apollo 11 guidance computer (Comanche055 / Luminary099) ported to PHP 8.3, with a DSKY and mission visualisation",
    "type": "project",
    "license": "MIT",
    "require": { "php": "^8.3" },
    "require-dev": {
        "phpunit/phpunit": "^11.0",
        "phpstan/phpstan": "^2.0"
    },
    "autoload": { "psr-4": { "Apollo\\": "src/" } },
    "autoload-dev": { "psr-4": { "Apollo\\Tests\\": "tests/" } },
    "scripts": {
        "test": "phpunit",
        "stan": "phpstan analyse",
        "serve": "php -S 127.0.0.1:8000 -t public"
    }
}
```

Verder: `phpunit.xml.dist` (testsuite `tests/`), `phpstan.neon.dist` (level 8, paths `src` en `tests`) en `.gitignore` (`/vendor/`, `.phpunit.cache/`). De licentie van de port is een aanname (zie Assumptions). Er zijn geen runtime-afhankelijkheden: alles draait op kale PHP.

### 2. Bronverwijzingen: `src/Trace/Routine.php` (nieuw)

Eén enum met alle AGC-routines die de port uitvoert. Het codepaneel en de docblocks gebruiken dezelfde bron.

```php
<?php

declare(strict_types=1);

namespace Apollo\Trace;

/**
 * Original AGC routines this port executes. Line numbers are for commit
 * e7576d50f7c279a707cfaa71be2020592dbf43a8 of chrislgarry/Apollo-11.
 */
enum Routine
{
    case P63Lm;        // Luminary099/THE_LUNAR_LANDING.agc:40
    case QuadGuid;     // Luminary099/LUNAR_LANDING_GUIDANCE_EQUATIONS.agc:546
    case RootPsrs;     // …:1272
    case StartP64;     // …:260
    case P65Start;     // …:252
    case StartP66;     // …:143
    case RodComp;      // …:948
    case BurnBaby;     // Luminary099/BURN_BABY_BURN--MASTER_IGNITION_ROUTINE.agc:159
    case Servicer;     // Luminary099/SERVICER.agc:175
    case FindVac;      // Luminary099/EXECUTIVE.agc:133
    case NoVac;        // Luminary099/EXECUTIVE.agc:155
    case Bailout;      // Luminary099/ALARM_AND_ABORT.agc:133
    case GoProg;       // Luminary099/FRESH_START_AND_RESTART.agc:206
    case VerbFan;      // Luminary099/PINBALL_GAME_BUTTONS_AND_LIGHTS.agc:970
    case ProcKey;      // …:2874
    case CharAlrm;     // …:2744
    case V37;          // Luminary099/FRESH_START_AND_RESTART.agc:739
    case P12Lm;        // Luminary099/P12.agc:35
    case Ascent;       // Luminary099/ASCENT_GUIDANCE.agc:90
    case P11;          // Comanche055/P11.agc:158
    case Vhhdot;       // Comanche055/P11.agc:409
    case P40Csm;       // Comanche055/P40-P47.agc:46
    case UpdateVg;     // Comanche055/P40-P47.agc:744
    case Steering;     // Comanche055/P40-P47.agc:819
    case S408;         // Comanche055/P40-P47.agc:1646
    case StartEnt;     // Comanche055/REENTRY_CONTROL.agc:48
    case UpControl;    // Comanche055/REENTRY_CONTROL.agc:873
    case Kep2;         // Comanche055/REENTRY_CONTROL.agc:1098
    case Predict3;     // Comanche055/REENTRY_CONTROL.agc:1130

    public const COMMIT = 'e7576d50f7c279a707cfaa71be2020592dbf43a8';

    public function label(): string { /* match: 'P63LM', 'QUADGUID', … exactly as in the source */ }
    public function file(): string  { /* match: 'Luminary099/THE_LUNAR_LANDING.agc', … */ }
    public function line(): int     { /* match: 40, 546, … */ }

    public function url(): string
    {
        return sprintf('https://github.com/chrislgarry/Apollo-11/blob/%s/%s#L%d', self::COMMIT, $this->file(), $this->line());
    }

    /** @return array{label: string, file: string, line: int, url: string} */
    public function toArray(): array { /* … */ }
}
```

Elke slice voegt de cases toe die zijn routines nodig hebben. Elke geporte klasse krijgt ook een docblock-regel in de vorm `@see Luminary099/LUNAR_LANDING_GUIDANCE_EQUATIONS.agc QUADGUID`.

`src/Trace/EventLog.php` (nieuw) houdt maximaal 200 regels `{seq, t, kind: 'input'|'agc'|'alarm', text, routine: ?array}` bij. Een tick geeft alleen de regels terug met een `seq` boven de laatst verstuurde.

### 3. Natuurkunde (2D): `src/Physics/` (nieuw)

- `StateVector`: `x`, `y` (m, middelpunt van het hemellichaam in de oorsprong), `vx`, `vy` (m/s). Afgeleide waarden zijn `altitude(Body)`, `radialVelocity()` en `horizontalVelocity()` (in het lokale horizontale stelsel).
- `Body` (enum `Moon` en `Earth`): `mu()` geeft 4.9048695e12 en 3.986004418e14 m³/s², `radius()` geeft 1 737 400 en 6 378 137 m, `atmosphere()` geeft `null` of `ExponentialAtmosphere(1.225, 7200)`.
- `Vehicle`: `mass`, `dryMass`, `maxThrust` (N), `isp` (s), `throttle` (0..1), `pitch` (rad ten opzichte van de lokale horizon), en voor de dampkring `liftToDrag` en `ballisticCoefficient`.
- `Integrator::step(Vehicle, StateVector, Body, float $dt): StateVector`: RK4 met zwaartekracht, stuwkracht langs `pitch`, brandstofverbruik `ṁ = T/(isp·g0)` en weerstand plus lift in de dampkring. De stapgrootte is vast op 0,1 s.

Floats in SI. Omrekening naar voet, ft/s, zeemijl en g gebeurt alleen in de DSKY-formatter (§5).

### 4. Simulatie, hoofdstukken en endpoint

`src/Chapter/ChapterId.php` (nieuw):

```php
enum ChapterId: string
{
    case Launch = 'launch';
    case LunarOrbitInsertion = 'loi';
    case Landing = 'landing';
    case Ascent = 'ascent';
    case TransEarthInjection = 'tei';
    case Entry = 'entry';

    public function computer(): Computer { /* Launch, LOI, TEI, Entry → Computer::Cm; Landing, Ascent → Computer::Lm */ }
    public function next(): ?self { /* missievolgorde hierboven, Entry → null */ }
}
```

`src/Chapter/Chapter.php` (interface, nieuw):

```php
interface Chapter
{
    public function id(): ChapterId;
    public function agc(): Agc;
    /** Advance $dt seconds of simulated time (in 0.1 s physics steps, guidance every 2 s via the Executive). */
    public function step(float $dt, TickInput $input): void;
    /** @return array<string, mixed> data for the vehicle view, see the tick response contract */
    public function vehicleView(): array;
    public function outcome(): ?Outcome; // null while running; Outcome::Success|Crashed|OutOfFuel|Missed
}
```

`src/Agc/Agc.php` (nieuw) bundelt de toestand van één computer: `Computer $computer`, `Executive $executive`, `Dsky $dsky`, `EventLog $log`, `int $majorMode`, `?Routine $currentRoutine`, `float $missionTime`.

`src/Simulation/Simulation.php` (nieuw) heeft `start(ChapterId, bool $scenario)` en `tick(TickInput): array`. `tick` voert eerst de toetsen uit via `Dsky::press()`, dan `Chapter::step(0.1 × speed)`, en geeft het antwoord terug in de vorm van **Tick-API** hieronder. Een tick rekent altijd 0,1 s × `speed` simulatietijd, los van de echte tijd: de browser bepaalt het tempo.

`src/Http/TickRequest.php` (nieuw) is de enige plek waar invoer wordt gecontroleerd. De regels, in deze volgorde:

1. Methode `POST`, anders 405.
2. `Content-Type` begint met `application/json`, anders 415.
3. De body is maximaal 4096 bytes, anders 413.
4. De body is een JSON-object (`json_decode(..., true, 8, JSON_THROW_ON_ERROR)`), anders 400.
5. `action` is `start` of `tick`, anders 400.
6. Bij `start`: `chapter` is een waarde van `ChapterId`, anders 400. `scenario` is een bool en mag alleen `true` zijn bij `chapter = landing`, anders 400.
7. Bij `tick`: `keys` is een lijst van maximaal 32 strings, elk een waarde van enum `Key` (`VERB`, `NOUN`, `ENTR`, `PRO`, `CLR`, `RSET`, `KEY_REL`, `PLUS`, `MINUS`, `0` t/m `9`), anders 400.
8. Bij `tick`: `rod` is een int van −10 t/m 10, anders 400.
9. Bij `tick`: `speed` is 1, 2, 5 of 10, anders 400.
10. Een `tick` zonder gestarte simulatie in de sessie geeft 409.

Een fout antwoordt met `{"error": "<vaste tekst per regel>"}`, zonder paden, stacktraces of de ontvangen waarde.

`public/api.php` (nieuw):

```php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Apollo\Http\ApiController;

session_name('apollo');
session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Strict',
    'use_strict_mode' => true,
]);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$response = (new ApiController(new \Apollo\Simulation\SessionStore($_SESSION)))->handle(
    $_SERVER['REQUEST_METHOD'] ?? 'GET',
    $_SERVER['CONTENT_TYPE'] ?? '',
    (string) file_get_contents('php://input', length: 4097),
);

http_response_code($response->status);
echo json_encode($response->body, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
```

`ApiController::handle()` vangt elke `Throwable` af en geeft dan 500 met `{"error": "Internal error"}`. Alleen `error_log()` krijgt de details. De simulatie staat als PHP-object in `$_SESSION['simulation']`. Sessiedata staat op de server en komt niet van de client, dus de `unserialize` die PHP daarvoor doet, krijgt geen invoer van buiten.

`public/index.php` (nieuw) stuurt een `Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'` en dezelfde `nosniff`-header, en serveert de statische HTML-schil: hoofdstukkiezer, Canvas, DSKY, codepaneel, log en snelheidsknoppen. Er zit geen inline script of style in.

### 5. DSKY: `src/Dsky/` (nieuw)

Het is een port van de Pinball-logica (`PINBALL_GAME_BUTTONS_AND_LIGHTS.agc`), teruggebracht tot de verbs die de hoofdstukken nodig hebben.

- `Key` (enum, string-backed): de toetsen uit §4, regel 7. Het toetsenbord mapt `V`→VERB, `N`→NOUN, `Enter`→ENTR, `P`→PRO, `C`→CLR, `R`→RSET, `K`→KEY_REL, `+`/`-` en de cijfers. De mapping zit in `public/assets/dsky.js`.
- `Dsky::press(Key)` volgt `VERBFAN` en `PROCKEY`. Na VERB komen twee cijfers en na NOUN ook twee. ENTR voert het verb uit.
  - V05 toont octaal (alleen N09, de alarmcodes).
  - V06 toont decimaal, eenmalig.
  - V16 toont decimaal en ververst elke seconde (een monitor).
  - V35 is de lampentest: alle segmenten op 8 en alle lampjes aan, 5 s lang.
  - V37 verandert van programma, alleen naar een programma uit `Chapter::allowedPrograms()`. Een ander nummer geeft OPR ERR (`V37NONO`).
  - Een onbekend verb, een onbekend noun voor deze computer, of een cijfer op een plek waar het niet hoort geeft OPR ERR (`CHARALRM`).
  - PRO beantwoordt een knipperende V50 of V99 (`PROCEED`).
  - KEY REL geeft de weergave terug aan het programma.
  - RSET dooft PROG en OPR ERR.
  - CLR wist het register dat je aan het intoetsen bent.
- `Dsky::requestDisplay(int $verb, int $noun, bool $flash)` is de port van `NVSUB` en `GODSP`. Een programma gebruikt het voor zijn eigen weergave. Toetst de gebruiker zelf iets in, dan gaat KEY REL aan en verschijnt de programmaweergave pas na KEY REL, net als op de echte DSKY.
- `NounTable` (per `Computer`): alleen deze nouns, met componenten, eenheid en formaat uit `ASSEMBLY_AND_OPERATION_INFORMATION.agc`.
  - LM: 09, 60, 63, 64, 65, 68, 74, 76.
  - CM: 09, 40, 60, 62, 63, 64, 65, 66, 67, 69.
- `DisplayFormatter`: de formaten `xxxx.x`, `xxxxx.`, `xxx.xx`, `xxBxx` (minuten, spatie, seconden), `00xxx.`/`000xx.`/`0xx.xx` (tijd) en octaal. De uitvoer is het teken plus 5 cijfers, of blanco. Een waarde boven het bereik toont de maximale cijfers (`99999`) en zet geen alarm.
- Lampjes in het antwoord: `uplinkActy`, `noAtt`, `stby`, `keyRel`, `oprErr`, `temp`, `gimbalLock`, `prog`, `restart`, `tracker`, `alt`, `vel`, `compActy`. Lampjes zonder model blijven altijd uit.

### 6. Executive: `src/Agc/Executive.php` (nieuw, slice 7)

```php
/**
 * @see Luminary099/EXECUTIVE.agc NOVAC
 * @see Luminary099/EXECUTIVE.agc FINDVAC
 */
final class Executive
{
    public const CORE_SETS = 7; // EXECUTIVE.agc:162  NO.CORES DEC 7
    public const VAC_AREAS = 5; // EXECUTIVE.agc:134-142  VAC1USE..VAC5USE

    /** Schedule a job that needs only a core set. Raises Alarm 1202 when all 7 are taken. */
    public function novac(Job $job): void;

    /** Schedule a job that needs a core set and a VAC area. Raises 1201 when all 5 VAC areas are taken, 1202 when all core sets are. */
    public function findvac(Job $job): void;

    /**
     * Run jobs for $seconds of AGC time, highest priority first. Each Job has a cpuCost in seconds;
     * a job frees its core set (and VAC area) when its cost is used up. The capacity per second
     * is 1.0 minus the cycle steal.
     */
    public function run(float $seconds): void;

    /** Fraction of AGC time lost to counter interrupts (the rendezvous-radar load in the Apollo 11 scenario). */
    public function setCycleSteal(float $fraction): void;

    /** BAILOUT + GOPROG: drop all jobs, count a restart, keep the alarm code for N09. The chapter then re-registers its jobs. */
    public function bailout(int $alarmCode): void;

    /** @return array{coreSets: list<?string>, vacAreas: list<?string>, cycleSteal: float, restarts: int} */
    public function snapshot(): array;
}
```

Het landingshoofdstuk zet drie soorten jobs in:
- `SERVICER` via `findvac`, elke 2 s: de geleiding plus de natuurkunde-meting.
- Displayjobs via `novac`, elke seconde per actieve monitor (V16).
- Radarjobs via `novac` met `PRIO25`, elke seconde.

De `cpuCost` per job staat als constante in `src/Chapter/Landing/LandingLoad.php`. Zonder scenario komt de belasting rond 85% uit. Het Apollo 11-scenario zet `setCycleSteal(0.15)`, waardoor jobs zich ophopen en `novac` of `findvac` het alarm opgooit. Bij een alarm gaat PROG aan, wordt de code in N09 bewaard (op te vragen met V05N09), roept de Executive `bailout()` aan, en zet het hoofdstuk de SERVICER-, display- en radarjobs opnieuw in, zoals de restarttabellen doen. De geleiding loopt daarna door. Het codepaneel toont 7 vakjes voor de core sets en 5 voor de VAC-gebieden, met de jobnamen erin.

### 7. Geleiding per hoofdstuk: `src/Guidance/` (nieuw)

Elke klasse rekent in SI met floats, krijgt een `@see` naar het origineel en zet bij elke aanroep `Agc::$currentRoutine`.

- `Guidance/Luminary/LandingGuidance.php` (slices 2 en 6): `QUADGUID` met de gepubliceerde vergelijking `ACG = ADG + 6(VDG + VG)/TTF + 12(RDG − RG)/TTF²` per as (radiaal en horizontaal; TTF is negatief, zoals in de AGC).
  - TTF komt uit de derdegraads vergelijking `JDG·TTF³ + 6·ADG·TTF² + (18·VDG + 6·VG)·TTF + 24·(RDG − RG) = 0` op de horizontale as. Die wordt opgelost met Newton, als port van `ROOTPSRS`: maximaal 8 iteraties, daarna de vorige TTF houden.
  - De doelen per fase (`BRAKING`, `APPROACH`) staan in `src/Scenario/LandingTargets.php`. Ze zijn zo gekozen dat P63 eindigt bij high gate (~2 300 m hoogte) en P64 bij low gate (~150 m); zie Assumptions.
  - Uit ACG volgen de stuwkrachtrichting (`pitch`) en de gasklep, met de DPS-grens: 10-60% regelbaar of 100%, zoals `THROTTLE` (`Luminary099/THROTTLE_CONTROL_ROUTINES.agc:37`).
- `Guidance/Luminary/RateOfDescent.php` (slice 6): P66 (`RODCOMP`). Elke ROD-klik verandert de gewenste daalsnelheid met 1 ft/s (0,3048 m/s). De horizontale snelheid wordt automatisch naar nul gestuurd. Een klik tijdens P64 of P65 schakelt naar P66, zoals `STARTP66`. P65 (`P65VERT`) landt automatisch met 3 ft/s als er nooit geklikt wordt.
- `Guidance/Luminary/AscentGuidance.php` (slice 10): `ASCENT`. De radiale versnelling komt uit de fout in hoogte en radiale snelheid over `tgo = Vgo / aT`. Brandschluss volgt als de gewenste horizontale snelheid bereikt is. Doelbaan: zie `src/Scenario/StartStates.php`.
- `Guidance/Comanche/LaunchMonitor.php` (slice 8): P11 rekent alleen de weergavewaarden van `VHHDOT` (N62: VI, HDOT, H). De Saturn V vliegt in `src/Physics/SaturnV.php` een vast kantelprogramma met trapscheiding. Dat is geen port: de LVDC-code zit niet in de bron, en het codepaneel zegt dat er ook bij.
- `Guidance/Comanche/ExternalDeltaVBurn.php` (slices 9 en 11): P40 met extern ΔV. `UPDATEVG` trekt de gemeten ΔV af van VG, `STEERING`/`S40.8` stuurt de stuwkracht langs VG, en `S40.81` zet de motor uit als TGO onder 4 s komt (met een eindtik op de resterende tijd). Het hoofdstuk zet het doel-ΔV als vector, retrograde voor LOI en prograde voor TEI.
- `Guidance/Comanche/EntryGuidance.php` (slice 12): een vereenvoudigde port.
  - P63 (`STARTENT`) wacht op 0,05 g en schakelt dan naar P64.
  - P64 (`UPCONTRL`) houdt de lift omhoog tot de snelheid onder 5 500 m/s komt.
  - P67 (`PREDICT3`) kiest de bankhoek met een voorspeller die het resterende traject doorrekent en via bisectie op `cos(bank)` de afstand tot het doel nul maakt.
  - P66 (`KEP2`) is de ballistische fase als de lift niet nodig is.
  - P65 (skip) zit er niet in: Apollo 11 vloog geen skip-baan (niet geverifieerd) en de doelafstand vraagt het niet.

### 8. Hoofdstukken: `src/Chapter/` (nieuw)

Elk hoofdstuk heeft een vaste begintoestand uit `src/Scenario/StartStates.php` (afgeronde publieke waarden, zie Assumptions), en een eigen programmaverloop en weergaven:

| Hoofdstuk | Computer | Begin | Verloop | DSKY | Einde |
|---|---|---|---|---|---|
| `LaunchChapter` | CM | Saturn V op het platform | P11 vanaf de lancering | V06N62 | Success bij een baan (perigeum > 150 km); Missed bij terugval |
| `LoiChapter` | CM | CSM+LM op weg naar periseleen ~110 km | P40: V50N25 R1=00203 → PRO → V06N40 aftellen → V99N40 knippert vanaf TIG−5 s → PRO → branding → V16N40 | V06N40, V16N40 | Success bij een gebonden maanbaan met periseleen > 50 km |
| `LandingChapter` | LM | PDI op ~15 km hoogte, ~1 690 m/s | P63: V06N63, V99N63 knippert vanaf TIG−5 s → PRO → ontsteking; P64 V06N64; P65/P66 V06N60 | V06N63/N64/N60, V16N68, V05N09 | Success bij contact met verticaal < 3 m/s en horizontaal < 1,2 m/s; Crashed of OutOfFuel |
| `AscentChapter` | LM | Op het oppervlak, stijgtrap | P12: V06N76 → PRO → V99 → PRO → ontsteking; daarna V16N63 | V06N76, V16N63 | Success bij een maanbaan met periseleen > 15 km |
| `TeiChapter` | CM | CSM alleen in een maanbaan van 111 km | P40 zoals bij LOI, prograde | V06N40, V16N40 | Success bij een ontsnappingsbaan (energie > 0 ten opzichte van de maan) |
| `EntryChapter` | CM | Entry interface 122 km, 11 030 m/s, −6,5° | P63 → P64 → P67 (of P66) automatisch | V06N64, V16N69, V16N67, V06N66 | Success bij parachutes onder 7 km en < 200 m/s; Missed bij skip of g > 12 |

`allowedPrograms()` geeft per hoofdstuk het startprogramma en 00 (P00 stopt de geleiding: de motor gaat uit en het hoofdstuk eindigt met Missed als het voertuig daardoor niet slaagt).

### 9. Frontend: `public/` (nieuw)

- `public/assets/app.js` (ES module):
  - Start met `POST {action:'start', chapter, scenario}`.
  - Daarna een lus: `await fetch` tick, renderen, dan `setTimeout(100)`. Een nieuwe tick vertrekt pas na het vorige antwoord, zodat er geen ticks tegelijk lopen.
  - Toetsen, ROD-klikken (pijl omhoog is minder daalsnelheid, pijl omlaag meer) en de snelheid worden tussen ticks verzameld en met de volgende tick meegestuurd.
- `public/assets/dsky.js`: tekent de DSKY in HTML/CSS. Er zijn 19 toetsen. Het display heeft PROG, VERB en NOUN, R1 t/m R3 en de lampjes. Knipperen (V50, V99) loopt via een CSS-klasse. Alle tekst gaat via `textContent`.
- `public/assets/views/*.js`: één module per weergave, elk met `render(ctx, vehicleView, t)`. Alle voertuigen zijn eigen eenvoudige silhouetten, getekend met Canvas-paden, zonder afbeeldingen.
  - `launch.js`: zijaanzicht van de Saturn V die opstijgt en kantelt, met S-IC en S-II die loslaten.
  - `orbit.js`: bovenaanzicht van de maan met de baan van het voertuig (doorgetrokken voor de afgelegde baan, gestippeld voor de voorspelling) en een vlam tijdens de branding. Gebruikt door LOI en TEI.
  - `landing.js`: zijaanzicht van de LM boven het oppervlak, met een vlam die schaalt met de gasklep, balken voor hoogte en daalsnelheid, de brandstof en het landingspunt. "CONTACT LIGHT" verschijnt bij 1,7 m.
  - `ascent.js`: de stijgtrap komt los van de daaltrap, die blijft staan.
  - `entry.js`: de CM met een gloed die schaalt met de weerstand en een bankhoekwijzer, en parachutes bij het einde.
  - `interlude.js`: tussenstukken (slice 13).
- `public/assets/codepanel.js`:
  - De actieve routine (label, bestand:regel) als link naar `routine.url`.
  - Een programmaregel ("P63 – Braking phase").
  - 7 plus 5 vakjes voor de Executive (vanaf slice 7).
  - Het log, met de nieuwste regel bovenaan en maximaal 200 regels.
- `public/assets/style.css`: de layout vanaf 1024 px breed. Canvas links, DSKY rechts, codepaneel en log eronder.

### 10. Tick-API (intern contract tussen `app.js` en `api.php`)

Request: zie §4. Response (200, bij `start` en `tick`):

```json
{
  "chapter": "landing",
  "computer": "LM",
  "t": 312.4,
  "speed": 1,
  "outcome": null,
  "dsky": {
    "prog": "63", "verb": "06", "noun": "63",
    "r1": "+05612", "r2": "-00121", "r3": "+33510",
    "flash": false,
    "lights": { "compActy": true, "prog": false, "oprErr": false, "keyRel": false, "restart": false, "uplinkActy": false, "noAtt": false, "stby": false, "temp": false, "gimbalLock": false, "tracker": false, "alt": false, "vel": false }
  },
  "vehicle": { "view": "landing", "x": 0.0, "altitude": 10212.0, "vx": 1201.3, "vz": -36.9, "pitch": 1.21, "throttle": 0.57, "fuel": 0.71, "target": 41200.0 },
  "agc": {
    "routine": { "label": "QUADGUID", "file": "Luminary099/LUNAR_LANDING_GUIDANCE_EQUATIONS.agc", "line": 546, "url": "https://github.com/chrislgarry/Apollo-11/blob/e7576d50…/Luminary099/LUNAR_LANDING_GUIDANCE_EQUATIONS.agc#L546" },
    "program": "P63 – Braking phase",
    "executive": { "coreSets": ["SERVICER", "DISPLAY", null, null, null, null, null], "vacAreas": ["SERVICER", null, null, null, null], "cycleSteal": 0.0, "restarts": 0 }
  },
  "log": [ { "seq": 41, "t": 310.0, "kind": "input", "text": "V16N68 ENTR → monitor slant range, time to go, ΔH", "routine": { "label": "VERBFAN", "...": "..." } } ]
}
```

`r1` t/m `r3` zijn al opgemaakte strings (teken plus 5 cijfers, of `""` voor blanco), dus de browser rekent niets om. Het object `vehicle` verschilt per `view`: elke weergavemodule leest alleen haar eigen velden, die als `@return`-shape in `Chapter::vehicleView()` per hoofdstuk staan.

## Tests

PHPUnit is nog niet aanwezig. Slice 1 zet het op (`vendor/bin/phpunit`) en PHPStan op level 8 (`vendor/bin/phpstan analyse`).

- `tests/Http/TickRequestTest.php`: één falend geval per regel uit §4.
  1. GET → 405.
  2. `text/plain` → 415.
  3. Een body van 4097 bytes → 413.
  4. `[1,2]` of `"x"` → 400.
  5. `action: "fly"` → 400.
  6. `chapter: "mars"` → 400; `scenario: true` bij `chapter: "launch"` → 400; `scenario: "yes"` → 400.
  7. `keys: ["VERB","DROP TABLE"]` → 400; 33 keys → 400; `keys: "VERB"` → 400.
  8. `rod: 11` → 400; `rod: -11` → 400; `rod: 1.5` → 400.
  9. `speed: 3` → 400.
  10. `tick` zonder start → 409.

  Plus de geldige gevallen: `start` landing met scenario, en een `tick` met alle keys, `rod: -10` en `rod: 10`, en `speed: 10`, elk → 200.
- `tests/Http/ApiControllerTest.php`: een fout geeft alleen `{"error": …}`; een gesimuleerde `Throwable` in de simulatie geeft 500 met `"Internal error"` zonder bestandspad in de body.
- `tests/Physics/IntegratorTest.php`: een cirkelbaan om de maan op 110 km blijft na één omloop binnen 100 m van zijn hoogte; vrije val vanaf 100 m komt na `sqrt(2h/g)` binnen 0,1 s aan; brandstofverbruik bij volle stuwkracht klopt met `T/(isp·g0)` op 0,1%.
- `tests/Dsky/DisplayFormatterTest.php`: `xxxx.x` met 1234,56 ft/s → `+12346`; `xxxxx.` met −42 → `-00042`; `xxBxx` met 205 s → `03 25`; een waarde buiten bereik → `+99999`; octaal 1202 → `01202`.
- `tests/Dsky/DskyTest.php`:
  - `VERB 1 6 NOUN 6 8 ENTR` op de LM → verb 16, noun 68, ververst elke seconde.
  - `VERB 3 5 ENTR` → alle lampjes aan en `88888` in alle registers, na 5 s terug.
  - `VERB 9 7 ENTR` → OPR ERR; `NOUN 9 9 ENTR` → OPR ERR; `VERB 3 7 ENTR 4 1 ENTR` in de landing → OPR ERR (P41 niet toegestaan).
  - RSET dooft OPR ERR.
  - Een gebruikersweergave zet KEY REL aan, en KEY REL brengt de programmaweergave terug.
  - PRO op knipperende V99 → ontsteking.
- `tests/Agc/ExecutiveTest.php`: acht `novac`-jobs zonder `run` → de achtste geeft alarm 1202; zes `findvac`-jobs → de zesde geeft 1201; `bailout(01202)` → alle core sets leeg, `restarts` 1, N09 bevat 01202; jobs lopen op prioriteit.
- `tests/Guidance/LandingGuidanceTest.php`:
  - Vanaf de PDI-begintoestand met PRO bij TIG schakelt P63 naar P64 tussen 1 800 en 2 800 m hoogte.
  - P64 schakelt naar P65 onder 200 m.
  - Zonder ROD-klik landt P65 met verticaal < 3 m/s en horizontaal < 1,2 m/s.
  - Eén ROD-klik in P64 schakelt naar P66.
  - In P66 verandert elke klik de gewenste daalsnelheid met 0,3048 m/s.
  - De Newton-oplossing voor TTF convergeert binnen 8 iteraties op de begintoestand.
- `tests/Chapter/LandingScenarioTest.php`: zonder scenario geeft een volledige automatische landing 0 alarmen; met scenario minstens één 1201 of 1202, PROG gaat aan, en de landing slaagt toch.
- `tests/Chapter/LaunchChapterTest.php`, `LoiChapterTest.php`, `AscentChapterTest.php`, `TeiChapterTest.php`, `EntryChapterTest.php`: elk hoofdstuk eindigt op 1× met de PRO's op het juiste moment in `Outcome::Success`, volgens de eindvoorwaarde in de tabel van §8. Bij LOI en TEI geeft het niet beantwoorden van V99 geen branding. Bij de terugkeer blijft de maximale belasting onder 12 g.
- `tests/Trace/RoutineTest.php`: elke case heeft een niet-lege `label()`, een `file()` die met `Luminary099/` of `Comanche055/` begint en een `line()` > 0. `url()` bevat `Routine::COMMIT`.
- Bronverwijzing in de code: `grep -rLE "@see (Luminary099|Comanche055)/" src/Guidance src/Agc src/Dsky` geeft geen bestanden. Elke port verwijst dus naar zijn origineel.
- Uitvoer zonder HTML-injectie: `grep -rn "innerHTML" public/assets` geeft niets.

## Assumptions

- Code, identifiers, commentaar en de tekst in de interface en de README zijn Engels; dit plan is Nederlands. Een andere taal is een vertaling, geen andere structuur (§9, §11).
- Elke port heeft een docblock met `@see <bestand> <LABEL>`. Het formaat is met één bewerking aan te passen (§2).
- De simulatietoestand staat in `$_SESSION`. Een andere opslag raakt alleen `SessionStore` (§4).
- De geleiding rekent elke 2 s, net als de SERVICER (`Luminary099/SERVICER.agc:73`). De natuurkunde rekent in stappen van 0,1 s. Beide zijn constanten in `Simulation`.
- Begintoestanden en voertuigdata zijn afgeronde publieke waarden die niet tegen het Apollo 11 Mission Report zijn geverifieerd. Ze staan in één bestand, `src/Scenario/StartStates.php`.
- De landingsdoelen (`RBRFG`, `VBRFG`, `ABRFG` en de approach-tegenhangers) en de ROD-schaal van 1 ft/s per klik staan niet in de bron, want het zijn pad-loads (`ERASABLE_ASSIGNMENTS.agc:68`, `:1391`). Ze worden zo gekozen dat high gate en low gate rond de waarden in §7 vallen. Ze staan in `src/Scenario/LandingTargets.php`.
- De job-kosten in `LandingLoad.php` en de cycle steal van 15% zijn gekozen zodat het scenario alarmen geeft en de landing zonder scenario niet. Ze zijn niet afgeleid uit gemeten AGC-timing.
- In P66 nult de computer de horizontale snelheid zelf, omdat de gebruiker de stand niet bestuurt (§7). Op de echte vlucht deed de bemanning dat met de handregelaar.
- De terugkeergeleiding is een vereenvoudigde port: de bankhoek komt uit een numerieke voorspeller in plaats van de referentietabellen van `PREDICT3` (§7).
- De port krijgt de MIT-licentie. De bron is public domain, dus elke licentie mag. Een andere licentie is een wijziging in `composer.json` en `LICENSE`.
- Het toetsenbord bedient de DSKY: `V`, `N`, `Enter`, `P`, `C`, `R`, `K`, `+`, `-` en cijfers, plus pijl omhoog en omlaag voor ROD (§9).

## Out of scope (flag, don't address here)

Adjacent:
- Een AGC-emulator die de originele assembly draait. Bewust niet gekozen: dit project is een selectieve port. Een emulator vraagt een yaYUL-assembler en een volledige simulatie van het ruimtevaartuig.
- De knoppen ABORT en ABORT STAGE, en de handregelaar voor de stand.
- Hosting, deploy en een Docker-image. Het project draait lokaal met `php -S`.
- Mobiele layout onder 1024 px.

On-subject:
- Het verleggen van het landingspunt in P64 (`REDESIG`, `Luminary099/LUNAR_LANDING_GUIDANCE_EQUATIONS.agc:335`). Begrensd door het interview: je koos bij bediening "DSKY + overname P66", niet "Ook landingspunt". N64 toont de LPD-hoek wel.
- De rendez-vous na het opstijgen (P20, P32 t/m P35, `Luminary099/P32-P35_P72-P75.agc`). Begrensd door het interview: je koos "6 AGC-fasen", met de rendez-vous als tussenstuk.
- De overige verbs en nouns van beide computers (`ASSEMBLY_AND_OPERATION_INFORMATION.agc:166-479`). Begrensd door de standaardkeuze in de bevestiging: alleen de verbs uit §5 en de nouns die de hoofdstukken tonen.
- P61 en P62 van de terugkeer (`Comanche055/P61-P67.agc:69`, `:182`), de voorbereiding vóór entry interface. Begrensd door het interview: het hoofdstuk start bij entry interface, en P61 en P62 draaien daarvoor.
- De bewaking van de TLI-branding. Comanche055 heeft er geen programma voor (de bestandslijst kent geen P15), dus het is een tussenstuk.

## Files to add / modify

| File | Change |
|---|---|
| `composer.json`, `phpunit.xml.dist`, `phpstan.neon.dist`, `.gitignore`, `LICENSE` | **new**: projectskelet (§1) |
| `README.md` | **new**: wat het is, de bron en de licentie, starten met `composer install` en `composer serve`, toetsen, een hoofdstukoverzicht (slice 13) |
| `public/index.php` | **new**: HTML-schil met CSP (§4) |
| `public/api.php` | **new**: tick-endpoint (§4) |
| `public/assets/app.js`, `dsky.js`, `codepanel.js`, `style.css` | **new**: §9 |
| `public/assets/views/landing.js`, `launch.js`, `orbit.js`, `ascent.js`, `entry.js`, `interlude.js` | **new**: één weergave per hoofdstuktype (§9) |
| `src/Http/TickRequest.php`, `ApiController.php`, `ApiResponse.php` | **new**: invoercontrole en afhandeling (§4) |
| `src/Simulation/Simulation.php`, `SessionStore.php`, `TickInput.php` | **new**: §4 |
| `src/Physics/StateVector.php`, `Body.php`, `Vehicle.php`, `Integrator.php`, `ExponentialAtmosphere.php`, `SaturnV.php` | **new**: §3, §7 |
| `src/Trace/Routine.php`, `EventLog.php` | **new**: §2 |
| `src/Dsky/Key.php`, `Dsky.php`, `NounTable.php`, `DisplayFormatter.php`, `Lights.php` | **new**: §5 |
| `src/Agc/Agc.php`, `Computer.php`, `Executive.php`, `Job.php`, `ProgramAlarm.php` | **new**: §4, §6 |
| `src/Guidance/Luminary/LandingGuidance.php`, `RateOfDescent.php`, `AscentGuidance.php`, `Throttle.php` | **new**: §7 |
| `src/Guidance/Comanche/LaunchMonitor.php`, `ExternalDeltaVBurn.php`, `EntryGuidance.php` | **new**: §7 |
| `src/Chapter/ChapterId.php`, `Chapter.php`, `Outcome.php`, `Interlude.php`, `LaunchChapter.php`, `LoiChapter.php`, `Landing/LandingChapter.php`, `Landing/LandingLoad.php`, `AscentChapter.php`, `TeiChapter.php`, `EntryChapter.php` | **new**: §8 |
| `src/Scenario/StartStates.php`, `LandingTargets.php` | **new**: begintoestanden en doelen |
| `tests/…` | **new**: zie Tests |

## Implementation order

Voor slice 1 is `git init` nodig, plus een remote naar `git@github.com:jester1979/apollo.git` of de HTTPS-variant. Zonder repo kan de build niet committen.

- **1. Skelet: de maanlander valt zichtbaar op de pagina.** AFK. Blocked by: none.
  - [x] `composer install` en `vendor/bin/phpunit` slagen, en `vendor/bin/phpstan analyse` geeft 0 fouten.
  - [x] Na `php -S 127.0.0.1:8000 -t public` toont `http://127.0.0.1:8000/` een startknop. Daarna valt de LM zonder stuwkracht vanaf de PDI-hoogte naar het maanoppervlak op het Canvas, met een hoogte die per tick daalt.
  - [x] Het hoofdstuk eindigt met `outcome: "crashed"` en de pagina toont dat.
  - [x] Elk ongeldig request uit `TickRequestTest` krijgt de juiste statuscode en alleen `{"error": …}`.
  - [x] De responseheaders van `/` bevatten de CSP uit §4. De sessiecookie `apollo` heeft `HttpOnly` en `SameSite=Strict`.
  - Guards: invoer alleen via `TickRequest`, met de 10 regels uit §4. Het endpoint is publiek, omdat er geen gebruikersdata is; elke bezoeker heeft zijn eigen sessie. Fouten bevatten geen details. Er is geen bestands- of shelltoegang op basis van invoer.
- **2. P63-geleiding: de maanlander remt af.** AFK. Blocked by: 1.
  - [x] Na start ontsteekt de DPS automatisch (PRO komt in slice 4) en remt de LM af. P63 schakelt naar P64 tussen 1 800 en 2 800 m hoogte; voor nu stopt het hoofdstuk daar met `outcome: "missed"`.
  - [x] Het Canvas toont de vlam die meeschaalt met de gasklep, en de brandstofbalk loopt terug.
  - [x] `LandingGuidanceTest` (P63-deel en de TTF-convergentie) slaagt.
- **3. De DSKY toont wat de computer doet.** AFK. Blocked by: 2.
  - [x] Tijdens P63 toont de DSKY PROG 63 en VERB 06 NOUN 63, met R1 de snelheid (ft/s), R2 de stijgsnelheid (ft/s) en R3 de hoogte (ft), opgemaakt volgens `DisplayFormatterTest`.
  - [x] COMP ACTY licht op als de geleiding rekent.
  - [x] `DisplayFormatterTest` slaagt.
- **4. DSKY-invoer.** AFK. Blocked by: 3.
  - [x] Met de muis of het toetsenbord geeft `V16N68 ENTR` een weergave van de afstand tot het landingspunt, die elke seconde ververst, en KEY REL brengt V06N63 terug.
  - [x] V35 test alle lampjes. Een onbekend verb of noun zet OPR ERR aan, en RSET dooft het.
  - [x] Voor de ontsteking knippert V99N63 vanaf TIG−5 s. Zonder PRO volgt geen ontsteking, met PRO wel.
  - [x] `DskyTest` slaagt.
- **5. Codepaneel en log.** AFK. Blocked by: 4.
  - [ ] Het codepaneel toont tijdens P63 `QUADGUID` met `Luminary099/LUNAR_LANDING_GUIDANCE_EQUATIONS.agc:546`, als link naar die regel op GitHub (commit `e7576d5…`).
  - [ ] Elke DSKY-invoer verschijnt in het log als "invoer → reactie", met de Pinball-routine erbij, en programmawissels verschijnen als `agc`-regels.
  - [ ] `RoutineTest` slaagt en de `@see`-grep uit Tests geeft geen bestanden.
- **6. P64, P65 en P66 met ROD: landen.** AFK. Blocked by: 4.
  - [ ] Zonder ingrijpen gaat de landing door P64 (V06N64) en P65 (V06N60) en eindigt met `outcome: "success"`. "CONTACT LIGHT" verschijnt.
  - [ ] Pijl omhoog of omlaag tijdens P64 schakelt naar P66. Elke klik verandert de daalsnelheid met 1 ft/s, zichtbaar in R2 van N60 en op het Canvas.
  - [ ] Te hard neerkomen geeft `outcome: "crashed"`, een lege tank `outcome: "out_of_fuel"`.
  - [ ] `LandingGuidanceTest` slaagt volledig.
- **7. Executive en het Apollo 11-scenario.** AFK. Blocked by: 5, 6.
  - [ ] De startknop van de landing heeft een schakelaar "Apollo 11 scenario (1201/1202 alarms)".
  - [ ] Met het scenario gaat tijdens P63 of P64 PROG aan, toont V05N09 de code 01202 of 01201, en loopt de RESTART-teller in het codepaneel op. De landing gaat daarna door en kan slagen.
  - [ ] Zonder scenario komt er geen alarm.
  - [ ] Het codepaneel toont 7 core sets en 5 VAC-gebieden met de jobnamen, en die lopen vol vlak voor een alarm.
  - [ ] `ExecutiveTest` en `LandingScenarioTest` slagen.
- **8. Hoofdstukkiezer en het lanceringshoofdstuk.** AFK. Blocked by: 5.
  - [ ] De pagina toont een kiezer met de zes hoofdstukken. Kies je "Launch", dan stijgt de Saturn V op het Canvas en laten S-IC en S-II zichtbaar los.
  - [ ] De DSKY toont P11 met V06N62 (VI, HDOT, H). Het codepaneel toont `VHHDOT` en zegt dat de raket zelf door de LVDC werd gestuurd, die niet in de bron zit.
  - [ ] Het hoofdstuk eindigt in een baan met `outcome: "success"`.
  - [ ] `LaunchChapterTest` slaagt. Het landingshoofdstuk is ook via de kiezer te starten.
- **9. Intrede in de maanbaan (LOI, P40).** AFK. Blocked by: 8.
  - [ ] Na het kiezen van "Lunar orbit insertion" volgt V50N25 R1=00203. PRO geeft V06N40 met aftellen, vanaf TIG−5 s knippert V99N40, en PRO start de branding.
  - [ ] Het bovenaanzicht toont de baan die tijdens de branding van hyperbool naar ellips buigt. Na de branding toont de DSKY V16N40.
  - [ ] Zonder PRO op V99 volgt geen branding en eindigt het hoofdstuk met `missed`.
  - [ ] `LoiChapterTest` slaagt.
- **10. Opstijgen (P12).** AFK. Blocked by: 8.
  - [ ] Na "Ascent" volgt V06N76, dan PRO, dan V99, dan PRO, en daarna stijgt de stijgtrap op terwijl de daaltrap blijft staan. Daarna toont de DSKY V16N63.
  - [ ] Het hoofdstuk eindigt in een maanbaan met `success`.
  - [ ] `AscentChapterTest` slaagt.
- **11. Vertrek naar de aarde (TEI, P40).** AFK. Blocked by: 9.
  - [ ] Na "Trans-Earth injection" volgt hetzelfde P40-verloop als bij LOI, nu prograde. Het bovenaanzicht toont de baan die openbreekt naar een ontsnappingsbaan.
  - [ ] `TeiChapterTest` slaagt.
- **12. Terugkeer (P63 t/m P67, CM).** AFK. Blocked by: 8.
  - [ ] Na "Entry" laat het Canvas de CM met een gloed zien die met de weerstand meegroeit, en een bankhoekwijzer. De DSKY wisselt van P63 naar P64 naar P67, met de weergaven uit §8.
  - [ ] Het hoofdstuk eindigt met parachutes en `success`. De maximale belasting blijft onder 12 g.
  - [ ] `EntryChapterTest` slaagt.
- **13. Tussenstukken, missieverloop, tijdversnelling en README.** AFK. Blocked by: 7, 10, 11, 12.
  - [ ] Na een geslaagd hoofdstuk toont de pagina "Next chapter". Die knop speelt het tussenstuk af (een animatie van een paar seconden met een korte Engelse tekst) en start dan het volgende hoofdstuk. Na de terugkeer toont de pagina "Mission complete".
  - [ ] De tussenstukken zijn:
    - Launch → LOI: "Trans-lunar injection and coast".
    - LOI → Landing: "Undocking and descent orbit".
    - Landing → Ascent: "On the surface".
    - Ascent → TEI: "Rendezvous and docking".
    - TEI → Entry: "Coast back to Earth".
  - [ ] De snelheidsknoppen 1×, 2×, 5× en 10× versnellen de simulatietijd zichtbaar (de klok in N65 en het Canvas).
  - [ ] `README.md` beschrijft de installatie, het starten en de toetsen, en verwijst naar de bron met de licentie.

## Verification

1. Reachability: `/Applications/MAMP/bin/php/composer install`, dan `php -S 127.0.0.1:8000 -t public`. Open `http://127.0.0.1:8000/` in Chrome, kies "Landing" in de kiezer, zet het scenario uit en klik op start. Dit alles via de pagina, zonder iets anders te draaien.
2. Toets `PRO` als V99 knippert. Verwacht: ontsteking, de vlam op het Canvas en `QUADGUID` in het codepaneel.
3. Toets `V16N68` met ENTR. Verwacht: R1 tot R3 veranderen elke seconde, en het log toont de invoer. KEY REL brengt V06N63 terug.
4. Laat P64 beginnen en druk één keer op pijl omlaag. Verwacht: PROG 66 en V06N60, en een daalsnelheid die per klik 1 ft/s verandert. Land zachtjes. Verwacht: "CONTACT LIGHT" en `success`.
5. Start de landing opnieuw met het scenario aan. Verwacht: PROG gaat aan, V05N09 toont 01202 of 01201, de vakjes in het codepaneel lopen vol en de RESTART-teller loopt op. RSET dooft PROG en de landing gaat door.
6. Klik "Next chapter" na de landing. Verwacht: het tussenstuk "On the surface", daarna het opstijghoofdstuk met V06N76.
7. Kies achtereenvolgens Launch, LOI, TEI en Entry in de kiezer en vlieg elk hoofdstuk uit op 10×. Verwacht: elk eindigt met `success` als PRO op tijd wordt gegeven.
8. DevTools Network: elke `api.php`-request is een POST met `Content-Type: application/json`, en er loopt nooit meer dan één tegelijk. DevTools Console: geen fouten. Responseheaders van `/`: de CSP uit §4.
9. `curl -s -X POST -H 'Content-Type: application/json' -d '{"action":"tick","keys":["X"],"rod":0,"speed":1}' http://127.0.0.1:8000/api.php`. Verwacht: 400 of 409, met alleen `{"error":…}`.
10. `vendor/bin/phpunit` slaagt, en `vendor/bin/phpstan analyse` geeft 0 fouten.
11. `grep -rLE "@see (Luminary099|Comanche055)/" src/Guidance src/Agc src/Dsky` geeft geen bestanden, en `grep -rn "innerHTML" public/assets` geeft niets.
12. After the commit: push naar `jester1979/apollo`, clone de repo in een lege map, en herhaal stap 1 vanuit die clone. Zo blijkt dat `composer.json` en de README genoeg zijn voor een nieuwe gebruiker. Lokaal kan dat niet, omdat de werkmap al een `vendor/` heeft.
