<?php

declare(strict_types=1);

namespace Apollo\Tests\Dsky;

use Apollo\Agc\Computer;
use Apollo\Dsky\Dsky;
use Apollo\Dsky\Light;
use PHPUnit\Framework\TestCase;

final class DskyTest extends TestCase
{
    public function testANewDskyIsBlankWithAllLightsOff(): void
    {
        $display = (new Dsky(Computer::Lm))->toArray();

        self::assertSame('', $display['prog']);
        self::assertSame('', $display['verb']);
        self::assertSame('', $display['noun']);
        self::assertSame(['', '', ''], [$display['r1'], $display['r2'], $display['r3']]);
        self::assertFalse($display['flash']);
        self::assertCount(13, $display['lights']);
        self::assertNotContains(true, $display['lights']);
    }

    public function testV06N63ShowsTheFormattedRegisters(): void
    {
        $dsky = new Dsky(Computer::Lm);
        $dsky->showMajorMode(63);
        $dsky->requestDisplay(6, 63, false);
        $dsky->supplyData([1690.0, -3.0, 15000.0]);

        $display = $dsky->toArray();

        self::assertSame('63', $display['prog']);
        self::assertSame('06', $display['verb']);
        self::assertSame('63', $display['noun']);
        self::assertSame('+55446', $display['r1']);
        self::assertSame('-00098', $display['r2']);
        self::assertSame('+49213', $display['r3']);
    }

    public function testANounWithTwoComponentsLeavesR3Blank(): void
    {
        $dsky = new Dsky(Computer::Cm);
        $dsky->requestDisplay(5, 9, false);
        $dsky->supplyData([0o1202, 0o1201, 0o0]);

        self::assertSame('01202', $dsky->toArray()['r1']);
        self::assertSame('01201', $dsky->toArray()['r2']);
        self::assertSame('00000', $dsky->toArray()['r3']);
    }

    public function testChangingTheNounClearsTheOldValues(): void
    {
        $dsky = new Dsky(Computer::Lm);
        $dsky->requestDisplay(6, 63, false);
        $dsky->supplyData([1.0, 2.0, 3.0]);

        $dsky->requestDisplay(6, 60, false);

        self::assertSame(['', '', ''], [$dsky->toArray()['r1'], $dsky->toArray()['r2'], $dsky->toArray()['r3']]);
    }

    public function testRepeatingTheSameDisplayKeepsTheValues(): void
    {
        $dsky = new Dsky(Computer::Lm);
        $dsky->requestDisplay(6, 63, false);
        $dsky->supplyData([1.0, 2.0, 3.0]);

        $dsky->requestDisplay(6, 63, false);

        self::assertNotSame('', $dsky->toArray()['r1']);
    }

    public function testAFlashingDisplayIsReported(): void
    {
        $dsky = new Dsky(Computer::Lm);
        $dsky->requestDisplay(6, 63, true);

        self::assertTrue($dsky->toArray()['flash']);
    }

    public function testALightCanBeSwitchedOnAndOff(): void
    {
        $dsky = new Dsky(Computer::Lm);

        $dsky->setLight(Light::CompActy, true);
        self::assertTrue($dsky->isLit(Light::CompActy));
        self::assertTrue($dsky->toArray()['lights']['compActy']);
        self::assertFalse($dsky->toArray()['lights']['prog']);

        $dsky->setLight(Light::CompActy, false);
        self::assertFalse($dsky->isLit(Light::CompActy));
    }

    public function testAnUnknownNounForTheComputerIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Dsky(Computer::Cm))->requestDisplay(6, 63, false);
    }

    public function testAVerbThatDoesNotDisplayANounIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Dsky(Computer::Lm))->requestDisplay(37, 63, false);
    }

    public function testTheValuesMustMatchTheNoun(): void
    {
        $dsky = new Dsky(Computer::Lm);
        $dsky->requestDisplay(6, 63, false);

        $this->expectException(\InvalidArgumentException::class);

        $dsky->supplyData([1.0]);
    }
}
