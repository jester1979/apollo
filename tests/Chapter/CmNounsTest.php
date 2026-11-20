<?php

declare(strict_types=1);

namespace Apollo\Tests\Chapter;

use Apollo\Agc\Computer;
use Apollo\Chapter\ChapterId;
use Apollo\Dsky\Key;
use Apollo\Dsky\NounTable;
use Apollo\Simulation\Simulation;
use Apollo\Simulation\TickInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Every CM chapter has values for every noun of the CM table: a noun without them would be a 500 from the API. */
final class CmNounsTest extends TestCase
{
    /** @return iterable<string, array{ChapterId}> */
    public static function cmChapters(): iterable
    {
        foreach (ChapterId::cases() as $id) {
            if ($id->computer() === Computer::Cm) {
                yield $id->value => [$id];
            }
        }
    }

    #[DataProvider('cmChapters')]
    public function testEveryNounOfTheCmTableCanBeDisplayedByTheUser(ChapterId $chapter): void
    {
        $nouns = NounTable::for(Computer::Cm)->numbers();
        self::assertNotSame([], $nouns);

        foreach ($nouns as $noun) {
            $simulation = new Simulation();
            $simulation->start($chapter, false);
            $verb = $noun === 9 ? [Key::Zero, Key::Five] : [Key::One, Key::Six];

            $response = $simulation->tick(new TickInput(keys: [
                Key::Verb, ...$verb, Key::Noun, ...self::digits($noun), Key::Enter,
            ]));
            $response = $simulation->tick(new TickInput(speed: 10));

            self::assertFalse($response['dsky']['lights']['oprErr'], sprintf('noun %d in %s', $noun, $chapter->value));
            self::assertSame(sprintf('%02d', $noun), $response['dsky']['noun']);
            self::assertNotSame('', $response['dsky']['r1'], sprintf('noun %d in %s shows a value', $noun, $chapter->value));
        }
    }

    /** @return list<Key> */
    private static function digits(int $number): array
    {
        return array_map(static fn (string $digit): Key => Key::from($digit), str_split(sprintf('%02d', $number)));
    }
}
