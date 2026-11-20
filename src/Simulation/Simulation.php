<?php

declare(strict_types=1);

namespace Apollo\Simulation;

use Apollo\Chapter\Ascent\AscentChapter;
use Apollo\Chapter\Chapter;
use Apollo\Chapter\ChapterId;
use Apollo\Chapter\Landing\LandingChapter;
use Apollo\Chapter\Launch\LaunchChapter;
use Apollo\Chapter\Loi\LoiChapter;
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
            ChapterId::Launch => new LaunchChapter(),
            ChapterId::LunarOrbitInsertion => new LoiChapter(),
            ChapterId::Landing => new LandingChapter(null, $scenario),
            ChapterId::Ascent => new AscentChapter(),
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
        $dt = Integrator::STEP * $input->speed;

        // The keys go in first, so the chapter sees them in the same tick.
        $dsky = $this->chapter->agc()->dsky;
        $programs = $this->chapter->allowedPrograms();
        foreach ($input->keys as $key) {
            $dsky->press($key, $programs, $this->chapter->agc()->missionTime);
        }
        $this->chapter->step($dt, $input);
        $dsky->advance($dt);

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
            'dsky' => $agc->dsky->toArray(),
            'vehicle' => $chapter->vehicleView(),
            'agc' => [
                'routine' => $agc->currentRoutine?->toArray(),
                'program' => $this->programName($chapter->id(), $agc->majorMode),
                'executive' => $agc->executive->snapshot(),
            ],
            'log' => $agc->log->takeNew(),
        ];
    }

    private function programName(ChapterId $chapter, int $majorMode): string
    {
        if ($chapter === ChapterId::Launch && $majorMode === 11) {
            return 'P11 – Earth orbit insertion monitor (the Saturn V is flown by the LVDC, whose code is not in the source)';
        }

        return match ($majorMode) {
            0 => 'P00 – No guidance (free fall)',
            12 => 'P12 – Powered ascent (ATMAG, ASCENT)',
            40 => 'P40 – SPS burn with an external delta-V (UPDATEVG, STEERING, S40.8)',
            63 => 'P63 – Braking phase',
            64 => 'P64 – Approach phase',
            65 => 'P65 – Automatic landing (3 ft/s)',
            66 => 'P66 – Rate of descent (ROD)',
            default => sprintf('P%02d', $majorMode),
        };
    }
}
