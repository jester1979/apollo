<?php

declare(strict_types=1);

namespace Apollo\Agc;

/**
 * The three alarm registers FAILREG, FAILREG+1 and FAILREG+2, which noun 09 shows. The first alarm
 * goes in the first register, the second in the next one, and from the third on the last register
 * holds the latest code with bit 15 set, to show that more than one alarm came after the second.
 * RSET (ERROR) clears all three.
 *
 * @see Luminary099/ALARM_AND_ABORT.agc ALARM
 * @see Luminary099/PINBALL_GAME_BUTTONS_AND_LIGHTS.agc ERROR
 */
final class ProgramAlarm
{
    /** Bit 15, added to the code in FAILREG+2 when it was taken already (MULTFAIL). */
    private const MULTIPLE_BIT = 0o40000;
    private const POSMAX = 0o37777;

    /** @var array{int, int, int} */
    private array $registers = [0, 0, 0];

    public function record(int $code): void
    {
        if ($this->registers[0] === 0) {
            $this->registers[0] = $code;
        } elseif ($this->registers[1] === 0) {
            $this->registers[1] = $code;
        } elseif (($this->registers[2] & self::POSMAX) === 0) {
            $this->registers[2] = $code;
        } else {
            $this->registers[2] = $code + self::MULTIPLE_BIT;
        }
    }

    public function clear(): void
    {
        $this->registers = [0, 0, 0];
    }

    /** @return array{int, int, int} */
    public function registers(): array
    {
        return $this->registers;
    }
}
