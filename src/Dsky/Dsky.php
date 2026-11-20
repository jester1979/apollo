<?php

declare(strict_types=1);

namespace Apollo\Dsky;

use Apollo\Agc\Computer;
use Apollo\Trace\EventLog;
use Apollo\Trace\LogKind;
use Apollo\Trace\Routine;

/**
 * One DSKY: the display (PROG, VERB and NOUN, the registers R1 to R3, the lights) and the keys.
 *
 * A program asks for a display with requestDisplay() and hands over the values the noun shows. The
 * user keys in through press(), which follows VERBFAN and PROCKEY: after VERB two digits, after NOUN
 * two digits, ENTR executes. What the user keys in is a display of its own: the first key-in lights
 * KEY REL and from then on the program display is only remembered, not shown, until KEY REL (or PRO,
 * or the end of a lamp test) gives the display back. The values of a noun the user asks for come from
 * the program side: the chapter asks requestedUserNoun() and answers with supplyUserData(). Nothing
 * here holds a closure, so the object serializes into the session with the rest of the simulation.
 * Every key press is also written to the event log, as "key → what the computer did" with the Pinball
 * routine that does it.
 *
 * The tick response carries the display as formatted strings, so the browser converts nothing.
 *
 * @see Luminary099/PINBALL_GAME_BUTTONS_AND_LIGHTS.agc VERBFAN
 * @see Luminary099/PINBALL_GAME_BUTTONS_AND_LIGHTS.agc PROCKEY
 * @see Luminary099/PINBALL_GAME_BUTTONS_AND_LIGHTS.agc CHARALRM
 * @see Luminary099/PINBALL_GAME_BUTTONS_AND_LIGHTS.agc NVSUB
 * @see Luminary099/DISPLAY_INTERFACE_ROUTINES.agc GODSP
 */
final class Dsky
{
    /** The verbs that display a noun: V05 octal, V06 decimal, V16 a monitor. */
    private const DISPLAY_VERBS = [5, 6, 16];

    /** The verbs a program flashes to ask the crew for an answer: V50 please perform, V99 please enable engine. */
    private const PROCEED_VERBS = [50, 99];

    private const VERB_OCTAL = 5;
    private const VERB_MONITOR = 16;
    private const VERB_LAMP_TEST = 35;
    private const VERB_CHANGE_PROGRAM = 37;

    /** V05 shows octal and takes only the alarm codes. */
    private const OCTAL_NOUN = 9;

    /** VBTSTLTS: the lamp test lasts 5 s (SHOLTS). */
    public const LAMP_TEST_SECONDS = 5.0;

    private readonly NounTable $nouns;

    private ?int $prog = null;

    // The display the program asked for.
    private ?int $verb = null;
    private ?int $noun = null;
    private bool $flash = false;

    /** @var list<float> the values of the program's noun, in SI units (octal nouns: the integer) */
    private array $values = [];

    // The display of the user, shown instead of the program's while $userControl is set.
    private bool $userControl = false;
    private Entry $entry = Entry::None;
    private bool $verbTyped = false;
    private bool $nounTyped = false;
    private string $userVerbText = '';
    private string $userNounText = '';
    private ?int $userVerb = null;
    private ?int $userNoun = null;

    /** @var list<float> */
    private array $userValues = [];
    private ?int $userValuesNoun = null;

    /** The noun the chapter has to supply values for (null: nothing is being asked for). */
    private ?int $userDataNoun = null;
    private bool $userDataPending = false;
    private bool $userMonitor = false;

    /** Seconds of lamp test left. */
    private float $lampTest = 0.0;

    private bool $proceed = false;
    private bool $reset = false;
    private ?int $programChange = null;

    /** @var array<string, bool> keyed by Light::value */
    private array $lights = [];

    /** The mission time of the key press being handled, for the log lines. */
    private float $now = 0.0;

    public function __construct(private readonly Computer $computer, private readonly ?EventLog $log = null)
    {
        $this->nouns = NounTable::for($computer);
        foreach (Light::cases() as $light) {
            $this->lights[$light->value] = false;
        }
    }

    /** Port of NVSUB/GODSP: puts a verb and a noun on the display. A program calls it for its own display. */
    public function requestDisplay(int $verb, int $noun, bool $flash): void
    {
        if (!in_array($verb, self::DISPLAY_VERBS, true) && !in_array($verb, self::PROCEED_VERBS, true)) {
            throw new \InvalidArgumentException(sprintf('Verb %d does not display a noun', $verb));
        }
        if ($this->nouns->find($noun) === null) {
            throw new \InvalidArgumentException(sprintf('Noun %d is unknown to the %s', $noun, $this->computer->value));
        }

        if ($noun !== $this->noun) {
            $this->values = [];
        }
        $this->verb = $verb;
        $this->noun = $noun;
        $this->flash = $flash;
    }

    /**
     * The values the noun of the program's display shows, one per component, in SI units (m, m/s, s).
     * The original program fetches them from its erasable registers each time it displays.
     *
     * @param list<float|int> $values
     */
    public function supplyData(array $values): void
    {
        $noun = $this->noun === null ? null : $this->nouns->find($this->noun);
        if ($noun === null) {
            throw new \LogicException('No noun is displayed');
        }
        if (count($values) !== count($noun->components)) {
            throw new \InvalidArgumentException(sprintf('Noun %d takes %d values', $noun->number, count($noun->components)));
        }

        $this->values = array_map(floatval(...), $values);
    }

    /**
     * Port of DSPMM: shows the major mode on PROG.
     *
     * @see Luminary099/PINBALL_GAME_BUTTONS_AND_LIGHTS.agc DSPMM
     */
    public function showMajorMode(int $majorMode): void
    {
        $this->prog = $majorMode;
    }

    public function setLight(Light $light, bool $on): void
    {
        $this->lights[$light->value] = $on;
    }

    public function isLit(Light $light): bool
    {
        return $this->lights[$light->value];
    }

    /**
     * One key press. The allowed programs are the ones V37 may select in the running chapter; $time is
     * the mission time the log line gets.
     *
     * @param list<int> $allowedPrograms
     */
    public function press(Key $key, array $allowedPrograms = [], float $time = 0.0): void
    {
        if ($this->lampTest > 0.0) {
            return; // the display stays busy until the lamp test is over (TSTLTS3)
        }

        $this->now = $time;

        match ($key) {
            Key::Verb => $this->pressVerb(),
            Key::Noun => $this->pressNoun(),
            Key::Enter => $this->pressEnter($allowedPrograms),
            Key::Proceed => $this->pressProceed(),
            Key::Clear => $this->pressClear(),
            Key::Reset => $this->pressReset(),
            Key::KeyRelease => $this->pressKeyRelease(),
            Key::Plus, Key::Minus => $this->alarm(
                sprintf('%s → no load verb is waiting for a sign, OPR ERR', $key === Key::Plus ? '+' : '-'),
            ), // signs only belong to the load verbs, which this port lacks
            default => $this->pressDigit((int) $key->value),
        };
    }

    /** Moves the DSKY clock on; the lamp test runs out and gives the display back. */
    public function advance(float $seconds): void
    {
        if ($this->lampTest <= 0.0) {
            return;
        }

        $this->lampTest = max(0.0, $this->lampTest - $seconds);
        if ($this->lampTest === 0.0) {
            $this->releaseUserDisplay();
        }
    }

    /** True when PRO was pressed since the last call while the program flashed V50 or V99. */
    public function takeProceed(): bool
    {
        $proceed = $this->proceed;
        $this->proceed = false;

        return $proceed;
    }

    /** True when RSET was pressed since the last call: the program side then clears its alarm registers too. */
    public function takeReset(): bool
    {
        $reset = $this->reset;
        $this->reset = false;

        return $reset;
    }

    /** True while the user's display is a monitor (V16) that the chapter has to refresh every second. */
    public function isMonitoring(): bool
    {
        return $this->userMonitor && $this->userDataNoun !== null;
    }

    /** The program V37 selected since the last call, or null. Only a program the chapter allows gets here. */
    public function takeProgramChange(): ?int
    {
        $program = $this->programChange;
        $this->programChange = null;

        return $program;
    }

    /**
     * The noun of the user's display when the chapter has to supply its values now: right after ENTR,
     * and for a monitor (V16) at every moment the chapter passes $monitorDue.
     */
    public function requestedUserNoun(bool $monitorDue): ?int
    {
        if ($this->userDataNoun === null) {
            return null;
        }

        return $this->userDataPending || ($this->userMonitor && $monitorDue) ? $this->userDataNoun : null;
    }

    /**
     * The values for the noun requestedUserNoun() returned, in SI units like supplyData().
     *
     * @param list<float|int> $values
     */
    public function supplyUserData(array $values): void
    {
        $noun = $this->userDataNoun === null ? null : $this->nouns->find($this->userDataNoun);
        if ($noun === null) {
            throw new \LogicException('The user display asks for no data');
        }
        if (count($values) !== count($noun->components)) {
            throw new \InvalidArgumentException(sprintf('Noun %d takes %d values', $noun->number, count($noun->components)));
        }

        $this->userValues = array_map(floatval(...), $values);
        $this->userValuesNoun = $noun->number;
        $this->userDataPending = false;
    }

    /**
     * @return array{
     *     prog: string, verb: string, noun: string, r1: string, r2: string, r3: string,
     *     flash: bool, lights: array<string, bool>
     * }
     */
    public function toArray(): array
    {
        if ($this->lampTest > 0.0) {
            return [
                'prog' => '88',
                'verb' => '88',
                'noun' => '88',
                'r1' => '+88888',
                'r2' => '+88888',
                'r3' => '+88888',
                'flash' => true,
                'lights' => array_map(static fn (bool $on): bool => true, $this->lights),
            ];
        }

        if ($this->userControl) {
            [$r1, $r2, $r3] = $this->registers($this->userValuesNoun, $this->userValues);

            return [
                'prog' => self::twoDigits($this->prog),
                'verb' => $this->userVerbText,
                'noun' => $this->userNounText,
                'r1' => $r1,
                'r2' => $r2,
                'r3' => $r3,
                'flash' => false,
                'lights' => $this->lights,
            ];
        }

        [$r1, $r2, $r3] = $this->registers($this->noun, $this->values);

        return [
            'prog' => self::twoDigits($this->prog),
            'verb' => self::twoDigits($this->verb),
            'noun' => self::twoDigits($this->noun),
            'r1' => $r1,
            'r2' => $r2,
            'r3' => $r3,
            'flash' => $this->flash,
            'lights' => $this->lights,
        ];
    }

    /** VERBFAN: VERB starts a new verb; two digits follow. */
    private function pressVerb(): void
    {
        $this->takeDisplay();
        if ($this->entry === Entry::Program) {
            $this->userNounText = self::twoDigits($this->userNoun);
        }
        $this->entry = Entry::Verb;
        $this->verbTyped = true;
        $this->userVerbText = '';
        $this->note('VERB → verb register blanked, two digits expected', Routine::Verb);
    }

    /** NOUN starts a new noun; two digits follow. */
    private function pressNoun(): void
    {
        $this->takeDisplay();
        $this->entry = Entry::Noun;
        $this->nounTyped = true;
        $this->userNounText = '';
        $this->note('NOUN → noun register blanked, two digits expected', Routine::Noun);
    }

    private function pressDigit(int $digit): void
    {
        $text = match ($this->entry) {
            Entry::Verb => $this->userVerbText,
            Entry::Noun, Entry::Program => $this->userNounText,
            Entry::None => null,
        };
        if ($text === null || strlen($text) >= 2) {
            // a digit where none belongs
            $this->alarm(sprintf('%d → no register is waiting for a digit, OPR ERR', $digit));

            return;
        }

        $register = match ($this->entry) {
            Entry::Verb => 'verb',
            Entry::Noun => 'noun',
            default => 'program',
        };
        if ($this->entry === Entry::Verb) {
            $this->userVerbText .= $digit;
        } else {
            $this->userNounText .= $digit;
        }
        $this->note(sprintf('%d → digit put in the %s register', $digit, $register), Routine::Num);
    }

    /**
     * @param list<int> $allowedPrograms
     */
    private function pressEnter(array $allowedPrograms): void
    {
        match ($this->entry) {
            Entry::None => $this->note('ENTR → nothing keyed in, nothing to execute', Routine::Enter),
            Entry::Program => $this->enterProgram($allowedPrograms),
            Entry::Verb, Entry::Noun => $this->executeVerbNoun(),
        };
    }

    /**
     * ENTR after VERB and NOUN. A verb or noun that was not keyed in again stays what the display
     * shows, so that "NOUN 6 3 ENTR" changes only the noun.
     */
    private function executeVerbNoun(): void
    {
        $verb = $this->verbTyped ? self::typedNumber($this->userVerbText) : $this->userVerb;
        $noun = $this->nounTyped ? self::typedNumber($this->userNounText) : $this->userNoun;
        $this->verbTyped = false;
        $this->nounTyped = false;
        $this->entry = Entry::None;
        $command = self::command($verb, $noun);

        if ($verb === self::VERB_LAMP_TEST) {
            $this->startLampTest();
            $this->note(self::command($verb, null) . ' ENTR → lamp test: every segment and every light on for 5 s', Routine::VbTstLts);

            return;
        }
        if ($verb === self::VERB_CHANGE_PROGRAM) {
            // MMCHANG asks for the program in the noun cells.
            $this->note(self::command($verb, null) . ' ENTR → change program: the program number is asked for', Routine::V37);
            $this->userVerbText = '37';
            $this->userNounText = '';
            $this->stopMonitor();
            $this->entry = Entry::Program;

            return;
        }
        if ($verb === null || !in_array($verb, self::DISPLAY_VERBS, true)) {
            $this->alarm($command . ' ENTR → unknown verb, OPR ERR'); // unknown verb

            return;
        }
        if ($noun === null || $this->nouns->find($noun) === null) {
            // unknown noun for this computer, or not two digits
            $this->alarm($command . ' ENTR → unknown noun, OPR ERR');

            return;
        }
        if ($verb === self::VERB_OCTAL && $noun !== self::OCTAL_NOUN) {
            $this->alarm($command . ' ENTR → V05 shows octal, which only N09 has, OPR ERR');

            return;
        }

        $this->userVerb = $verb;
        $this->userNoun = $noun;
        $this->userVerbText = self::twoDigits($verb);
        $this->userNounText = self::twoDigits($noun);
        $this->userValues = [];
        $this->userValuesNoun = $noun;
        $this->userDataNoun = $noun;
        $this->userDataPending = true;
        $this->userMonitor = $verb === self::VERB_MONITOR;
        $this->note(
            sprintf('%s ENTR → %s', $command, match ($verb) {
                self::VERB_OCTAL => sprintf('display noun %02d in octal', $noun),
                self::VERB_MONITOR => sprintf('monitor noun %02d, refreshed every second', $noun),
                default => sprintf('display noun %02d in decimal', $noun),
            }),
            Routine::VerbFan,
        );
    }

    /**
     * V37 ENTR and the two program digits, then ENTR. Only a program of the chapter is accepted; any
     * other number is V37NONO: OPR ERR, and the display goes back to the program.
     *
     * @param list<int> $allowedPrograms
     * @see Luminary099/FRESH_START_AND_RESTART.agc V37
     * @see Luminary099/FRESH_START_AND_RESTART.agc V37NONO
     */
    private function enterProgram(array $allowedPrograms): void
    {
        $program = self::typedNumber($this->userNounText);
        if ($program === null) {
            // ALMCYCLE: two digits were demanded; alarm and ask again.
            $this->alarm('ENTR → two program digits were demanded, OPR ERR');
            $this->userNounText = '';
            $this->entry = Entry::Program;

            return;
        }

        if (in_array($program, $allowedPrograms, true)) {
            $this->programChange = $program;
            $this->note(sprintf('V37 N%02d ENTR → program %02d requested', $program, $program), Routine::V37);
        } else {
            $this->alarm(
                sprintf('V37 N%02d ENTR → program %02d does not exist here, OPR ERR', $program, $program),
                Routine::V37NoNo,
            );
        }
        $this->releaseUserDisplay();
    }

    /** PROCKEY: answers a flashing V50 or V99 and gives the display back. PRO without a question does nothing. */
    private function pressProceed(): void
    {
        if (!$this->flash || !in_array($this->verb, self::PROCEED_VERBS, true)) {
            $this->note('PRO → no display is asking for an answer, nothing happens', Routine::ProcKey);

            return;
        }

        $this->note(sprintf('PRO → answer to the flashing V%02d: proceed', $this->verb), Routine::ProcKey);
        $this->proceed = true;
        $this->flash = false;
        $this->releaseUserDisplay();
    }

    /** CLR wipes the register being keyed in. */
    private function pressClear(): void
    {
        $this->note(
            $this->entry === Entry::None ? 'CLR → nothing keyed in' : 'CLR → the register being keyed in is wiped',
            Routine::Clear,
        );
        match ($this->entry) {
            Entry::Verb => $this->userVerbText = '',
            Entry::Noun, Entry::Program => $this->userNounText = '',
            Entry::None => null,
        };
    }

    /** RSET turns off PROG and OPR ERR. */
    private function pressReset(): void
    {
        $this->lights[Light::Prog->value] = false;
        $this->lights[Light::OprErr->value] = false;
        $this->reset = true;
        $this->note('RSET → OPR ERR and PROG lights off', Routine::ErrorReset);
    }

    /** KEY REL: the display of the program comes back. */
    private function pressKeyRelease(): void
    {
        $this->releaseUserDisplay();
        $this->note('KEY REL → keyed-in display released, the program display comes back', Routine::VbRelDsp);
    }

    /** CHARALRM: switches OPR ERR on and drops what was being keyed in. */
    private function alarm(string $text, Routine $routine = Routine::CharAlrm): void
    {
        $this->lights[Light::OprErr->value] = true;
        $this->verbTyped = false;
        $this->nounTyped = false;
        $this->entry = Entry::None;
        $this->note($text, $routine);
    }

    private function note(string $text, Routine $routine): void
    {
        $this->log?->record(LogKind::Input, $text, $routine, $this->now);
    }

    /** "V16N68", or "V16" without a noun, or "V--" when no verb is known. */
    private static function command(?int $verb, ?int $noun): string
    {
        return ($verb === null ? 'V--' : sprintf('V%02d', $verb)) . ($noun === null ? '' : sprintf('N%02d', $noun));
    }

    /** V35 (VBTSTLTS): every segment shows 8 and every light is on for 5 s. */
    private function startLampTest(): void
    {
        $this->takeDisplay();
        $this->stopMonitor();
        $this->lampTest = self::LAMP_TEST_SECONDS;
    }

    /** The first key-in: the user's display starts from what the program shows, and KEY REL lights. */
    private function takeDisplay(): void
    {
        $this->stopMonitor();
        if ($this->userControl) {
            return;
        }

        $this->userControl = true;
        $this->lights[Light::KeyRel->value] = true;
        $this->userVerbText = self::twoDigits($this->verb);
        $this->userNounText = self::twoDigits($this->noun);
        $this->userVerb = $this->verb;
        $this->userNoun = $this->noun;
        $this->userValues = $this->values;
        $this->userValuesNoun = $this->noun;
    }

    /** KEY REL (RELDSP): the program display comes back and KEY REL goes out. */
    private function releaseUserDisplay(): void
    {
        $this->userControl = false;
        $this->entry = Entry::None;
        $this->verbTyped = false;
        $this->nounTyped = false;
        $this->userValues = [];
        $this->stopMonitor();
        $this->lights[Light::KeyRel->value] = false;
    }

    private function stopMonitor(): void
    {
        $this->userDataNoun = null;
        $this->userDataPending = false;
        $this->userMonitor = false;
    }

    /**
     * @param list<float> $values
     * @return array{string, string, string}
     */
    private function registers(?int $noun, array $values): array
    {
        $registers = ['', '', ''];
        $definition = $noun === null ? null : $this->nouns->find($noun);
        if ($definition !== null && $values !== []) {
            foreach ($definition->components as $index => $component) {
                $registers[$index] = DisplayFormatter::component($values[$index], $component);
            }
        }

        return [$registers[0], $registers[1], $registers[2]];
    }

    private static function typedNumber(string $text): ?int
    {
        return strlen($text) === 2 ? (int) $text : null;
    }

    private static function twoDigits(?int $value): string
    {
        return $value === null ? '' : sprintf('%02d', $value);
    }
}
