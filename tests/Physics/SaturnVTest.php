<?php

declare(strict_types=1);

namespace Apollo\Tests\Physics;

use Apollo\Physics\Body;
use Apollo\Physics\Integrator;
use Apollo\Physics\SaturnStage;
use Apollo\Physics\SaturnV;
use Apollo\Scenario\StartStates;
use PHPUnit\Framework\TestCase;

final class SaturnVTest extends TestCase
{
    public function testTheStackWeighsAboutThreeMillionKilogramsAndLiftsOff(): void
    {
        $rocket = new SaturnV();
        $vehicle = $rocket->vehicle();

        self::assertEqualsWithDelta(2.94e6, $vehicle->mass, 1000.0);
        self::assertGreaterThan(1.1, $vehicle->maxThrust / ($vehicle->mass * 9.80665), 'thrust to weight at lift-off');
        self::assertSame(SaturnStage::SIC, $rocket->stage());
        self::assertSame(1.0, $rocket->propellantLeft());
        self::assertSame(1.0, $vehicle->throttle);
    }

    public function testThePitchProgrammeGoesFromVerticalToHorizontal(): void
    {
        self::assertSame(90.0, SaturnV::pitchDegrees(0.0));
        self::assertSame(90.0, SaturnV::pitchDegrees(12.0));
        self::assertEqualsWithDelta(84.0, SaturnV::pitchDegrees(21.0), 1e-9);
        self::assertSame(0.0, SaturnV::pitchDegrees(700.0));
        self::assertSame(0.0, SaturnV::pitchDegrees(5000.0));

        $rocket = new SaturnV();
        $rocket->flyProgramme(12.0);
        self::assertEqualsWithDelta(M_PI_2, $rocket->vehicle()->pitch, 1e-9);
    }

    public function testEachStageIsDroppedWhenItsPropellantIsSpentAndTheNextOneLights(): void
    {
        $rocket = new SaturnV();
        $state = StartStates::launchState();
        $integrator = new Integrator();
        $dropped = [];

        for ($step = 0; $step < 7000 && $rocket->stage() !== SaturnStage::SIVB; $step++) {
            $rocket->flyProgramme($step * Integrator::STEP);
            $state = $integrator->step($rocket->vehicle(), $state, Body::Earth, Integrator::STEP);
            $stage = $rocket->separateIfBurnedOut();
            if ($stage !== null) {
                $dropped[] = $stage;
                self::assertSame(1.0, $rocket->vehicle()->throttle);
                self::assertEqualsWithDelta(1.0, $rocket->propellantLeft(), 1e-6);
            }
        }

        self::assertSame([SaturnStage::SIC, SaturnStage::SII], $dropped);
        // 50 t payload, the S-IVB structure and its propellant are left.
        self::assertEqualsWithDelta(170000.0, $rocket->vehicle()->mass, 100.0);
        self::assertNull($rocket->separateIfBurnedOut(), 'the S-IVB is the last stage and has propellant');
    }

    public function testCutOffShutsTheEngine(): void
    {
        $rocket = new SaturnV();

        $rocket->cutOff();

        self::assertSame(0.0, $rocket->vehicle()->throttle);
    }
}
