<?php

declare(strict_types=1);

namespace Apollo\Tests\Dsky;

use Apollo\Agc\Computer;
use Apollo\Dsky\Dsky;
use Apollo\Dsky\Key;
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

    /**
     * Keys in a sequence like "V16N68E": V VERB, N NOUN, E ENTR, P PRO, C CLR, R RSET, K KEY REL, + and -, digits.
     *
     * @param list<int> $programs
     */
    private function keys(Dsky $dsky, string $sequence, array $programs = [63, 0]): void
    {
        $map = [
            'V' => Key::Verb, 'N' => Key::Noun, 'E' => Key::Enter, 'P' => Key::Proceed, 'C' => Key::Clear,
            'R' => Key::Reset, 'K' => Key::KeyRelease, '+' => Key::Plus, '-' => Key::Minus,
        ];
        foreach (str_split($sequence) as $character) {
            $dsky->press($map[$character] ?? Key::from($character), $programs);
        }
    }

    private function programDisplayV06N63(Dsky $dsky): void
    {
        $dsky->showMajorMode(63);
        $dsky->requestDisplay(6, 63, false);
        $dsky->supplyData([1690.0, -3.0, 15000.0]);
    }

    public function testV16N68ShowsTheNounAndAsksTheProgramForItsValuesEverySecond(): void
    {
        $dsky = new Dsky(Computer::Lm);
        $this->programDisplayV06N63($dsky);

        $this->keys($dsky, 'V16N68E');

        $display = $dsky->toArray();
        self::assertSame('16', $display['verb']);
        self::assertSame('68', $display['noun']);
        self::assertSame('63', $display['prog'], 'PROG belongs to the program');
        self::assertSame(['', '', ''], [$display['r1'], $display['r2'], $display['r3']], 'no values yet');
        self::assertFalse($display['flash']);

        self::assertSame(68, $dsky->requestedUserNoun(false), 'asked for right after ENTR');
        $dsky->supplyUserData([481000.0, 250.0, 0.0]);
        self::assertNull($dsky->requestedUserNoun(false), 'not again before the next second');
        self::assertSame(68, $dsky->requestedUserNoun(true), 'a monitor refreshes every second');

        $display = $dsky->toArray();
        self::assertSame('+02597', $display['r1'], '481 km is 259.7 nautical miles');
        self::assertSame('04 10', $display['r2']);
        self::assertSame('+00000', $display['r3']);
    }

    public function testV06ShowsDecimalOnceAndDoesNotAskAgain(): void
    {
        $dsky = new Dsky(Computer::Lm);

        $this->keys($dsky, 'V06N63E');
        self::assertSame(63, $dsky->requestedUserNoun(false));
        $dsky->supplyUserData([1.0, 2.0, 3.0]);

        self::assertNull($dsky->requestedUserNoun(true));
        self::assertNotSame('', $dsky->toArray()['r1']);
    }

    public function testV05ShowsOctalAndOnlyForN09(): void
    {
        $dsky = new Dsky(Computer::Lm);

        $this->keys($dsky, 'V05N09E');
        self::assertFalse($dsky->isLit(Light::OprErr));
        $dsky->supplyUserData([0o1202, 0o1201, 0]);
        self::assertSame('01202', $dsky->toArray()['r1']);

        $this->keys($dsky, 'V05N63E');
        self::assertTrue($dsky->isLit(Light::OprErr));
    }

    public function testNounOnlyEntryKeepsTheVerbOfTheDisplay(): void
    {
        $dsky = new Dsky(Computer::Lm);
        $this->programDisplayV06N63($dsky);

        $this->keys($dsky, 'N60E');

        self::assertFalse($dsky->isLit(Light::OprErr));
        self::assertSame('06', $dsky->toArray()['verb']);
        self::assertSame('60', $dsky->toArray()['noun']);
        self::assertSame(60, $dsky->requestedUserNoun(false));
    }

    public function testV35LightsEveryLightAndEverySegmentFor5SecondsThenGivesTheDisplayBack(): void
    {
        $dsky = new Dsky(Computer::Lm);
        $this->programDisplayV06N63($dsky);

        $this->keys($dsky, 'V35E');

        $display = $dsky->toArray();
        self::assertSame(['88', '88', '88'], [$display['prog'], $display['verb'], $display['noun']]);
        self::assertSame(['+88888', '+88888', '+88888'], [$display['r1'], $display['r2'], $display['r3']]);
        self::assertCount(13, $display['lights']);
        self::assertNotContains(false, $display['lights']);

        $dsky->advance(4.9);
        self::assertSame('88', $dsky->toArray()['verb']);
        self::assertNotContains(false, $dsky->toArray()['lights']);

        $dsky->advance(0.2);
        $display = $dsky->toArray();
        self::assertSame(['63', '06', '63'], [$display['prog'], $display['verb'], $display['noun']]);
        self::assertNotContains(true, $display['lights'], 'every light is out again, KEY REL too');
    }

    public function testKeysAreIgnoredDuringTheLampTest(): void
    {
        $dsky = new Dsky(Computer::Lm);

        $this->keys($dsky, 'V35E');
        $this->keys($dsky, 'V97E');

        $dsky->advance(5.0);
        self::assertFalse($dsky->isLit(Light::OprErr));
    }

    public function testAnUnknownVerbGivesOprErrAndRsetPutsItOut(): void
    {
        $dsky = new Dsky(Computer::Lm);
        $this->programDisplayV06N63($dsky);

        $this->keys($dsky, 'V97E');
        self::assertTrue($dsky->isLit(Light::OprErr));
        self::assertTrue($dsky->toArray()['lights']['oprErr']);

        $this->keys($dsky, 'R');
        self::assertFalse($dsky->isLit(Light::OprErr));
    }

    public function testAnUnknownNounForThisComputerGivesOprErr(): void
    {
        $dsky = new Dsky(Computer::Lm);
        $this->programDisplayV06N63($dsky);

        $this->keys($dsky, 'N99E');
        self::assertTrue($dsky->isLit(Light::OprErr));

        $this->keys($dsky, 'R');
        $this->keys($dsky, 'V16N40E'); // N40 exists on the CM, not on the LM
        self::assertTrue($dsky->isLit(Light::OprErr));
    }

    public function testADigitWhereItDoesNotBelongGivesOprErr(): void
    {
        $dsky = new Dsky(Computer::Lm);

        $this->keys($dsky, '5');
        self::assertTrue($dsky->isLit(Light::OprErr), 'no VERB or NOUN before the digit');

        $this->keys($dsky, 'R');
        $this->keys($dsky, 'V163');
        self::assertTrue($dsky->isLit(Light::OprErr), 'a third verb digit');

        $this->keys($dsky, 'R');
        $this->keys($dsky, 'V1E');
        self::assertTrue($dsky->isLit(Light::OprErr), 'ENTR after one verb digit');

        $this->keys($dsky, 'R');
        $this->keys($dsky, '+');
        self::assertTrue($dsky->isLit(Light::OprErr), 'a sign without a load verb');
    }

    public function testRsetPutsOutProgToo(): void
    {
        $dsky = new Dsky(Computer::Lm);
        $dsky->setLight(Light::Prog, true);
        $dsky->setLight(Light::OprErr, true);
        $dsky->setLight(Light::Restart, true);

        $this->keys($dsky, 'R');

        self::assertFalse($dsky->isLit(Light::Prog));
        self::assertFalse($dsky->isLit(Light::OprErr));
        self::assertTrue($dsky->isLit(Light::Restart), 'RSET is for PROG and OPR ERR');
    }

    public function testV37AcceptsOnlyAProgramOfTheChapter(): void
    {
        $dsky = new Dsky(Computer::Lm);
        $this->programDisplayV06N63($dsky);

        $this->keys($dsky, 'V37E41E');
        self::assertTrue($dsky->isLit(Light::OprErr), 'P41 is not a program of the landing');
        self::assertNull($dsky->takeProgramChange());
        self::assertSame('06', $dsky->toArray()['verb'], 'the display is back with the program (V37NONO)');
        self::assertFalse($dsky->isLit(Light::KeyRel));

        $this->keys($dsky, 'R');
        $this->keys($dsky, 'V37E00E');
        self::assertFalse($dsky->isLit(Light::OprErr));
        self::assertSame(0, $dsky->takeProgramChange());
        self::assertNull($dsky->takeProgramChange(), 'it is taken once');

        $this->keys($dsky, 'V37E63E');
        self::assertSame(63, $dsky->takeProgramChange());
    }

    public function testV37ShowsTheDigitsInTheNounCellsAndDemandsTwoOfThem(): void
    {
        $dsky = new Dsky(Computer::Lm);

        $this->keys($dsky, 'V37E');
        self::assertSame('37', $dsky->toArray()['verb']);
        self::assertSame('', $dsky->toArray()['noun']);

        $this->keys($dsky, '6');
        self::assertSame('6', $dsky->toArray()['noun']);
        $this->keys($dsky, 'E');
        self::assertTrue($dsky->isLit(Light::OprErr), 'ALMCYCLE: two digits were demanded');
        self::assertNull($dsky->takeProgramChange());
    }

    public function testClrWipesTheRegisterBeingKeyedIn(): void
    {
        $dsky = new Dsky(Computer::Lm);

        $this->keys($dsky, 'V16');
        self::assertSame('16', $dsky->toArray()['verb']);
        $this->keys($dsky, 'C');
        self::assertSame('', $dsky->toArray()['verb']);

        $this->keys($dsky, 'N6');
        $this->keys($dsky, 'C');
        self::assertSame('', $dsky->toArray()['noun']);
        $this->keys($dsky, '8');
        self::assertFalse($dsky->isLit(Light::OprErr), 'the noun register takes digits again');
    }

    public function testKeyingInLightsKeyRelAndTheProgramDisplayReturnsOnlyAfterKeyRel(): void
    {
        $dsky = new Dsky(Computer::Lm);
        $this->programDisplayV06N63($dsky);
        self::assertFalse($dsky->isLit(Light::KeyRel));

        $this->keys($dsky, 'V16N68E');
        self::assertTrue($dsky->isLit(Light::KeyRel));

        // The program keeps displaying; the user's display stays.
        $dsky->requestDisplay(6, 63, false);
        $dsky->supplyData([1000.0, -5.0, 9000.0]);
        self::assertSame('68', $dsky->toArray()['noun']);

        $this->keys($dsky, 'K');
        $display = $dsky->toArray();
        self::assertFalse($dsky->isLit(Light::KeyRel));
        self::assertSame(['06', '63'], [$display['verb'], $display['noun']]);
        self::assertSame('+32808', $display['r1'], 'the program display shows its latest values');
        self::assertNull($dsky->requestedUserNoun(true), 'the monitor is over');
    }

    public function testProAnswersAFlashingV99AndOnlyThen(): void
    {
        $dsky = new Dsky(Computer::Lm);
        $this->programDisplayV06N63($dsky);

        $this->keys($dsky, 'P');
        self::assertFalse($dsky->takeProceed(), 'nothing was asked');

        $dsky->requestDisplay(99, 63, true);
        self::assertTrue($dsky->toArray()['flash']);
        $this->keys($dsky, 'P');
        self::assertTrue($dsky->takeProceed());
        self::assertFalse($dsky->takeProceed(), 'taken once');
        self::assertFalse($dsky->toArray()['flash']);

        $dsky->requestDisplay(50, 63, true);
        $this->keys($dsky, 'P');
        self::assertTrue($dsky->takeProceed(), 'V50 is answered by PRO too');
    }

    public function testProAnswersAFlashingV06AsWellLikeTheV06N76OfP12(): void
    {
        $dsky = new Dsky(Computer::Lm);
        $dsky->requestDisplay(6, 76, true);
        self::assertTrue($dsky->toArray()['flash']);

        $this->keys($dsky, 'P');

        self::assertTrue($dsky->takeProceed());
        self::assertFalse($dsky->toArray()['flash']);
    }

    public function testProAnswersTheFlashingVerbEvenWhileTheUserHoldsTheDisplay(): void
    {
        $dsky = new Dsky(Computer::Lm);
        $this->programDisplayV06N63($dsky);
        $this->keys($dsky, 'V16N68E');

        $dsky->requestDisplay(99, 63, true);
        self::assertSame('16', $dsky->toArray()['verb'], 'the program display waits for KEY REL');
        $this->keys($dsky, 'P');

        self::assertTrue($dsky->takeProceed());
        self::assertFalse($dsky->isLit(Light::KeyRel));
    }

    public function testTheDskySurvivesTheSessionSerialization(): void
    {
        $dsky = new Dsky(Computer::Lm);
        $this->programDisplayV06N63($dsky);
        $this->keys($dsky, 'V16N6');

        $copy = unserialize(serialize($dsky));

        self::assertEquals($dsky->toArray(), $copy->toArray());
    }
}
