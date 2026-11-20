<?php

declare(strict_types=1);

namespace Apollo\Tests\Chapter;

use Apollo\Agc\Computer;
use Apollo\Chapter\ChapterId;
use Apollo\Chapter\Interlude;
use Apollo\Chapter\Outcome;
use Apollo\Dsky\Key;
use Apollo\Simulation\Simulation;
use Apollo\Simulation\TickInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InterludeTest extends TestCase
{
    /** @return iterable<string, array{ChapterId, ChapterId, string}> */
    public static function transitions(): iterable
    {
        yield 'launch to loi' => [ChapterId::Launch, ChapterId::LunarOrbitInsertion, 'Trans-lunar injection and coast'];
        yield 'loi to landing' => [ChapterId::LunarOrbitInsertion, ChapterId::Landing, 'Undocking and descent orbit'];
        yield 'landing to ascent' => [ChapterId::Landing, ChapterId::Ascent, 'On the surface'];
        yield 'ascent to tei' => [ChapterId::Ascent, ChapterId::TransEarthInjection, 'Rendezvous and docking'];
        yield 'tei to entry' => [ChapterId::TransEarthInjection, ChapterId::Entry, 'Coast back to Earth'];
    }

    #[DataProvider('transitions')]
    public function testEveryTransitionHasItsTitleAndNextChapter(ChapterId $from, ChapterId $to, string $title): void
    {
        self::assertSame($title, Interlude::titleAfter($from));
        self::assertSame(['chapter' => $to->value, 'interlude' => $title], Interlude::nextAfter($from, Outcome::Success));
        self::assertSame($to, $from->next());
    }

    public function testNothingFollowsTheEntry(): void
    {
        self::assertNull(Interlude::titleAfter(ChapterId::Entry));
        self::assertNull(Interlude::nextAfter(ChapterId::Entry, Outcome::Success));
    }

    public function testOnlyASuccessOffersTheNextChapter(): void
    {
        foreach (ChapterId::cases() as $chapter) {
            self::assertNull(Interlude::nextAfter($chapter, null), $chapter->value . ' still running');
            foreach ([Outcome::Crashed, Outcome::OutOfFuel, Outcome::Missed] as $outcome) {
                self::assertNull(Interlude::nextAfter($chapter, $outcome), $chapter->value . ' ' . $outcome->value);
            }
        }
    }

    public function testTheTickResponseOffersTheNextChapterOnlyAfterTheLaunchSucceeded(): void
    {
        $simulation = new Simulation();
        $response = $simulation->start(ChapterId::Launch, false);
        self::assertNull($response['next']);

        for ($tick = 0; $tick < 3000 && $response['outcome'] === null; $tick++) {
            self::assertNull($response['next']);
            $response = $simulation->tick(new TickInput(speed: 10));
        }

        self::assertSame('success', $response['outcome']);
        self::assertSame(['chapter' => 'loi', 'interlude' => 'Trans-lunar injection and coast'], $response['next']);

        // The page then starts the next chapter through the normal start request.
        $next = $simulation->start(ChapterId::from($response['next']['chapter']), false);
        self::assertSame('loi', $next['chapter']);
        self::assertNull($next['outcome']);
        self::assertNull($next['next']);
    }

    /** @return iterable<string, array{ChapterId}> */
    public static function chapters(): iterable
    {
        foreach (ChapterId::cases() as $id) {
            yield $id->value => [$id];
        }
    }

    #[DataProvider('chapters')]
    public function testNoun65ShowsTheMissionClockAndRunsFasterAtHigherSpeed(ChapterId $chapter): void
    {
        $clockAfter = static function (int $speed) use ($chapter): float {
            $simulation = new Simulation();
            $simulation->start($chapter, false);
            // The burn and ascent chapters hold their clock until PRO answers the first checklist display.
            $answer = in_array($chapter, [ChapterId::LunarOrbitInsertion, ChapterId::TransEarthInjection, ChapterId::Ascent], true)
                ? [Key::Proceed]
                : [];
            $keys = [...$answer, Key::Verb, Key::One, Key::Six, Key::Noun, Key::Six, Key::Five, Key::Enter];
            $simulation->tick(new TickInput(keys: $keys, speed: $speed));
            for ($tick = 0; $tick < 30; $tick++) {
                $response = $simulation->tick(new TickInput(speed: $speed));
            }

            self::assertSame('65', $response['dsky']['noun']);
            self::assertFalse($response['dsky']['lights']['oprErr']);
            // R1 hours (00xxx.), R2 minutes (000xx.), R3 seconds (0xx.xx).
            $seconds = (int) $response['dsky']['r1'] * 3600 + (int) $response['dsky']['r2'] * 60 + (int) $response['dsky']['r3'] / 100;
            self::assertEqualsWithDelta($response['t'], (float) $seconds, 1.5 * $speed / 10 + 1.0, 'N65 is the mission clock');

            return $response['t'];
        };

        // 31 ticks of 0.1 s x speed.
        self::assertEqualsWithDelta(3.1, $clockAfter(1), 0.05);
        self::assertEqualsWithDelta(31.0, $clockAfter(10), 0.05);
    }

    public function testBothComputersKnowNoun65(): void
    {
        foreach ([Computer::Lm, Computer::Cm] as $computer) {
            self::assertNotNull(\Apollo\Dsky\NounTable::for($computer)->find(65), $computer->value);
        }
    }
}
