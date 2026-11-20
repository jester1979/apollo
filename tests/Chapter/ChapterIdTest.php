<?php

declare(strict_types=1);

namespace Apollo\Tests\Chapter;

use Apollo\Agc\Computer;
use Apollo\Chapter\ChapterId;
use PHPUnit\Framework\TestCase;

final class ChapterIdTest extends TestCase
{
    public function testMissionOrderEndsAfterEntry(): void
    {
        $order = [];
        for ($id = ChapterId::Launch; $id !== null; $id = $id->next()) {
            $order[] = $id->value;
        }

        self::assertSame(['launch', 'loi', 'landing', 'ascent', 'tei', 'entry'], $order);
    }

    public function testEachChapterUsesItsOwnComputer(): void
    {
        self::assertSame(Computer::Lm, ChapterId::Landing->computer());
        self::assertSame(Computer::Lm, ChapterId::Ascent->computer());
        self::assertSame(Computer::Cm, ChapterId::Launch->computer());
        self::assertSame(Computer::Cm, ChapterId::Entry->computer());
    }
}
