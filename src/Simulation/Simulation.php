<?php

declare(strict_types=1);

namespace Apollo\Simulation;

use Apollo\Chapter\Chapter;
use Apollo\Chapter\ChapterId;
use Apollo\Chapter\Landing\LandingChapter;
use Apollo\Physics\Integrator;

/**
 * One visitor's running chapter. Not final: the API tests substitute a failing simulation.
 */
class Simulation
{
    private ?Chapter $chapter = null;
    private bool $scenario = false;
    private int $speed = 1;

    /**
     * @return array<string, mixed> the tick response
     * @throws ChapterNotAvailable when the chapter is not built yet
     */
    public function start(ChapterId $id, bool $scenario): array
    {
        $this->chapter = match ($id) {
            ChapterId::Landing => new LandingChapter(),
            default => throw new ChapterNotAvailable(),
        };
        $this->scenario = $scenario;
        $this->speed = 1;

        return $this->response($this->chapter);
    }

    public function isScenario(): bool
    {
        return $this->scenario;
    }

    /** @return array<string, mixed> the tick response */
    public function tick(TickInput $input): array
    {
        if ($this->chapter === null) {
            throw new \LogicException('No chapter started');
        }

        $this->speed = $input->speed;
        $this->chapter->step(Integrator::STEP * $input->speed, $input);

        return $this->response($this->chapter);
    }

    /** @return array<string, mixed> */
    private function response(Chapter $chapter): array
    {
        $agc = $chapter->agc();

        return [
            'chapter' => $chapter->id()->value,
            'computer' => $agc->computer->value,
            't' => round($agc->missionTime, 1),
            'speed' => $this->speed,
            'outcome' => $chapter->outcome()?->value,
            // Placeholder until the DSKY exists (slice 3): blank registers, all lights off.
            'dsky' => [
                'prog' => '', 'verb' => '', 'noun' => '',
                'r1' => '', 'r2' => '', 'r3' => '',
                'flash' => false,
                'lights' => [
                    'compActy' => false, 'prog' => false, 'oprErr' => false, 'keyRel' => false,
                    'restart' => false, 'uplinkActy' => false, 'noAtt' => false, 'stby' => false,
                    'temp' => false, 'gimbalLock' => false, 'tracker' => false, 'alt' => false,
                    'vel' => false,
                ],
            ],
            'vehicle' => $chapter->vehicleView(),
            // Placeholder until the Executive (slice 7) exists.
            'agc' => [
                'routine' => $agc->currentRoutine?->toArray(),
                'program' => $this->programName($agc->majorMode),
                'executive' => [
                    'coreSets' => array_fill(0, 7, null),
                    'vacAreas' => array_fill(0, 5, null),
                    'cycleSteal' => 0.0,
                    'restarts' => 0,
                ],
            ],
            'log' => [],
        ];
    }

    private function programName(int $majorMode): string
    {
        return match ($majorMode) {
            0 => 'P00 – No guidance (free fall)',
            63 => 'P63 – Braking phase',
            64 => 'P64 – Approach phase',
            default => sprintf('P%02d', $majorMode),
        };
    }
}
