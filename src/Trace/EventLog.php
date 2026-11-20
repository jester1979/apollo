<?php

declare(strict_types=1);

namespace Apollo\Trace;

/**
 * The log of one AGC: what the crew keyed in, what the computer did, and which original routine did it.
 * It keeps the last 200 lines. A line gets the next sequence number when it is recorded, and takeNew()
 * hands out each line once, so a tick response carries only what the browser has not seen yet.
 */
final class EventLog
{
    public const MAX_LINES = 200;

    /** @var list<array{seq: int, t: float, kind: string, text: string, routine: array{label: string, file: string, line: int, url: string}|null}> */
    private array $lines = [];
    private int $seq = 0;
    private int $lastSent = 0;

    public function record(LogKind $kind, string $text, ?Routine $routine, float $time): void
    {
        $this->lines[] = [
            'seq' => ++$this->seq,
            't' => round($time, 1),
            'kind' => $kind->value,
            'text' => $text,
            'routine' => $routine?->toArray(),
        ];
        if (count($this->lines) > self::MAX_LINES) {
            array_shift($this->lines);
        }
    }

    /**
     * The lines recorded since the previous call, oldest first.
     *
     * @return list<array{seq: int, t: float, kind: string, text: string, routine: array{label: string, file: string, line: int, url: string}|null}>
     */
    public function takeNew(): array
    {
        $lastSent = $this->lastSent;
        $this->lastSent = $this->seq;

        return array_values(array_filter(
            $this->lines,
            static fn (array $line): bool => $line['seq'] > $lastSent,
        ));
    }

    /**
     * @return list<array{seq: int, t: float, kind: string, text: string, routine: array{label: string, file: string, line: int, url: string}|null}>
     */
    public function lines(): array
    {
        return $this->lines;
    }
}
