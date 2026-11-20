<?php

declare(strict_types=1);

namespace Apollo\Tests\Chapter;

use Apollo\Chapter\Landing\LandingChapter;
use Apollo\Chapter\Outcome;
use Apollo\Dsky\Key;
use Apollo\Simulation\TickInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The crew answers the flashing V99N63 when it can, not at the second: the landing must work for a PRO
 * that comes late, because the landing site belongs to the ignition point, not to the start of the chapter.
 */
final class LandingLateProTest extends TestCase
{
    /** @return iterable<string, array{float, bool}> */
    public static function proTimes(): iterable
    {
        foreach ([false, true] as $scenario) {
            foreach ([5.0, 10.0, 20.0, 40.0, 60.0] as $seconds) {
                yield sprintf('PRO at %d s, scenario %s', $seconds, $scenario ? 'on' : 'off') => [$seconds, $scenario];
            }
        }
    }

    #[DataProvider('proTimes')]
    public function testAHandsOffLandingSucceedsWhenProComesLate(float $seconds, bool $scenario): void
    {
        $chapter = new LandingChapter(null, $scenario);
        $chapter->step($seconds, new TickInput());
        $chapter->agc()->dsky->press(Key::Proceed, $chapter->allowedPrograms());

        $chapter->step(1500.0, new TickInput());

        self::assertSame(Outcome::Success, $chapter->outcome());
        self::assertGreaterThan(0.0, $chapter->vehicleView()['fuel'], 'fuel left at contact');
    }

    public function testWithoutProTheChapterEndsMissedAfterABoundedWait(): void
    {
        $chapter = new LandingChapter();

        $chapter->step(LandingChapter::IGNITION_WAIT_SECONDS - 1.0, new TickInput());
        self::assertNull($chapter->outcome(), 'still waiting for PRO');

        $chapter->step(2.0, new TickInput());

        self::assertSame(Outcome::Missed, $chapter->outcome());
        self::assertSame(0.0, $chapter->vehicleView()['throttle']);
        self::assertSame(1.0, $chapter->vehicleView()['fuel']);
        $texts = array_column($chapter->agc()->log->takeNew(), 'text');
        self::assertContains('No PRO: the ignition was not confirmed, the landing is missed', $texts);
    }

    public function testAProThatComesAfterTheWaitDoesNothing(): void
    {
        $chapter = new LandingChapter();
        $chapter->step(LandingChapter::IGNITION_WAIT_SECONDS + 1.0, new TickInput());

        $chapter->agc()->dsky->press(Key::Proceed, $chapter->allowedPrograms());
        $chapter->step(1.0, new TickInput());

        self::assertSame(Outcome::Missed, $chapter->outcome());
        self::assertSame(0.0, $chapter->vehicleView()['throttle']);
    }

    /** @return iterable<string, array{float, bool}> */
    public static function ignitionTimes(): iterable
    {
        yield 'PRO at TIG-5 s' => [5.0, false];
        yield 'PRO at TIG' => [10.0, false];
        yield 'PRO 1 s after TIG' => [11.0, true];
    }

    #[DataProvider('ignitionTimes')]
    public function testOnlyAProAfterTigIsLoggedAsALateIgnition(float $seconds, bool $late): void
    {
        $chapter = new LandingChapter();
        $chapter->step($seconds, new TickInput());
        $chapter->agc()->dsky->press(Key::Proceed, $chapter->allowedPrograms());

        $chapter->step(1.0, new TickInput());

        $lateLines = array_filter(
            array_column($chapter->agc()->log->takeNew(), 'text'),
            static fn (string $text): bool => str_starts_with($text, 'Late ignition'),
        );
        self::assertCount($late ? 1 : 0, $lateLines);
    }

    public function testAV37AfterTheLandingDoesNotChangeItsOutcome(): void
    {
        $chapter = new LandingChapter();
        $chapter->step(5.0, new TickInput());
        $chapter->agc()->dsky->press(Key::Proceed, $chapter->allowedPrograms());
        $chapter->step(1500.0, new TickInput());
        self::assertSame(Outcome::Success, $chapter->outcome());

        foreach ([Key::Verb, Key::Three, Key::Seven, Key::Enter, Key::Zero, Key::Zero, Key::Enter] as $key) {
            $chapter->agc()->dsky->press($key, $chapter->allowedPrograms());
        }
        $chapter->step(1.0, new TickInput());

        self::assertSame(Outcome::Success, $chapter->outcome());
    }
}
