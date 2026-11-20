<?php

declare(strict_types=1);

namespace Apollo\Dsky;

use Apollo\Agc\Computer;

/**
 * The display side of one DSKY: PROG, VERB and NOUN, the registers R1 to R3 and the lights. A
 * program asks for a display with requestDisplay() and hands over the values the noun shows; the
 * tick response carries the result as formatted strings, so the browser converts nothing.
 *
 * Key input is not handled yet.
 *
 * @see Luminary099/PINBALL_GAME_BUTTONS_AND_LIGHTS.agc NVSUB
 * @see Luminary099/DISPLAY_INTERFACE_ROUTINES.agc GODSP
 */
final class Dsky
{
    /** The verbs that display a noun: V05 octal, V06 decimal, V16 a monitor. */
    private const DISPLAY_VERBS = [5, 6, 16];

    private readonly NounTable $nouns;

    private ?int $prog = null;
    private ?int $verb = null;
    private ?int $noun = null;
    private bool $flash = false;

    /** @var list<float> the values of the shown noun, in SI units (octal nouns: the integer) */
    private array $values = [];

    /** @var array<string, bool> keyed by Light::value */
    private array $lights = [];

    public function __construct(private readonly Computer $computer)
    {
        $this->nouns = NounTable::for($computer);
        foreach (Light::cases() as $light) {
            $this->lights[$light->value] = false;
        }
    }

    /** Port of NVSUB/GODSP: puts a verb and a noun on the display. A program calls it for its own display. */
    public function requestDisplay(int $verb, int $noun, bool $flash): void
    {
        if (!in_array($verb, self::DISPLAY_VERBS, true)) {
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
     * The values the displayed noun shows, one per component, in SI units (m, m/s, s). The
     * original program fetches them from its erasable registers each time it displays.
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
     * @return array{
     *     prog: string, verb: string, noun: string, r1: string, r2: string, r3: string,
     *     flash: bool, lights: array<string, bool>
     * }
     */
    public function toArray(): array
    {
        $noun = $this->noun === null ? null : $this->nouns->find($this->noun);
        $registers = ['', '', ''];
        if ($noun !== null && $this->values !== []) {
            foreach ($noun->components as $index => $component) {
                $registers[$index] = DisplayFormatter::component($this->values[$index], $component);
            }
        }

        return [
            'prog' => self::twoDigits($this->prog),
            'verb' => self::twoDigits($this->verb),
            'noun' => self::twoDigits($this->noun),
            'r1' => $registers[0],
            'r2' => $registers[1],
            'r3' => $registers[2],
            'flash' => $this->flash,
            'lights' => $this->lights,
        ];
    }

    private static function twoDigits(?int $value): string
    {
        return $value === null ? '' : sprintf('%02d', $value);
    }
}
