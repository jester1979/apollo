<?php

declare(strict_types=1);

namespace Apollo\Tests\Guidance;

use Apollo\Guidance\Comanche\EntryGuidance;
use Apollo\Guidance\Comanche\EntryProgram;
use Apollo\Physics\Body;
use Apollo\Physics\StateVector;
use Apollo\Scenario\StartStates;
use Apollo\Trace\Routine;
use PHPUnit\Framework\TestCase;

final class EntryGuidanceTest extends TestCase
{
    private const TARGET_ANGLE = StartStates::ENTRY_TARGET_RANGE / 6378137.0;

    /** A state at the given altitude flying clockwise at the given air-relative-ish speed, level. */
    private function state(float $altitude, float $speed, float $climb = 0.0): StateVector
    {
        return new StateVector(0.0, Body::Earth->radius() + $altitude, $speed, $climb);
    }

    public function testP63WaitsForPoint05GAndThenSwitchesToP64(): void
    {
        $guidance = new EntryGuidance(self::TARGET_ANGLE);
        $vehicle = StartStates::entryVehicle();
        $state = $this->state(100000.0, 11000.0);

        $guidance->guide($state, $vehicle, 0.04 * EntryGuidance::G0);
        self::assertSame(EntryProgram::P63, $guidance->program());
        self::assertSame(Routine::StartEnt, $guidance->routine());

        $guidance->guide($state, $vehicle, 0.051 * EntryGuidance::G0);
        self::assertSame(EntryProgram::P64, $guidance->program());
    }

    public function testP64HoldsTheLiftUpUntilTheSpeedIsUnder5500MetresPerSecond(): void
    {
        $guidance = new EntryGuidance(self::TARGET_ANGLE);
        $vehicle = StartStates::entryVehicle();
        $guidance->guide($this->state(90000.0, 10000.0), $vehicle, 0.06 * EntryGuidance::G0);

        $guidance->guide($this->state(60000.0, 6000.0), $vehicle, 3.0 * EntryGuidance::G0);

        self::assertSame(EntryProgram::P64, $guidance->program());
        self::assertSame(Routine::UpControl, $guidance->routine());
        self::assertEqualsWithDelta(M_PI / 3.0, $guidance->bank(), 1e-12);
        self::assertGreaterThan(0.0, cos($guidance->bank()), 'the lift points up');

        $guidance->guide($this->state(60000.0, 5400.0), $vehicle, 1.0 * EntryGuidance::G0);

        self::assertSame(EntryProgram::P67, $guidance->program());
        self::assertSame(Routine::Predict3, $guidance->routine());
    }

    public function testP64GivesWayToTheBallisticP66WhenTheDragHasBuiltUpAndFallsUnderQ7(): void
    {
        $guidance = new EntryGuidance(self::TARGET_ANGLE);
        $vehicle = StartStates::entryVehicle();
        $guidance->guide($this->state(90000.0, 10000.0), $vehicle, 0.06 * EntryGuidance::G0);

        // Low drag before it has ever built up is not a climb out: the entry has only just begun.
        $guidance->guide($this->state(85000.0, 10000.0), $vehicle, 0.1 * EntryGuidance::G0);
        self::assertSame(EntryProgram::P64, $guidance->program());

        $guidance->guide($this->state(70000.0, 9500.0), $vehicle, 0.5 * EntryGuidance::G0);
        $guidance->guide($this->state(80000.0, 9000.0, 300.0), $vehicle, 0.1 * EntryGuidance::G0);

        self::assertSame(EntryProgram::P66, $guidance->program());

        // KEP2 keeps the bank angle and waits for the drag to be over Q7 + 0.5 ft/s^2.
        $bank = $guidance->bank();
        $guidance->guide($this->state(78000.0, 8900.0), $vehicle, EntryGuidance::Q7_RETURN - 0.01);
        self::assertSame(EntryProgram::P66, $guidance->program());
        self::assertSame(Routine::Kep2, $guidance->routine());
        self::assertSame($bank, $guidance->bank());

        $guidance->guide($this->state(76000.0, 8800.0), $vehicle, EntryGuidance::Q7_RETURN + 0.01);
        self::assertSame(EntryProgram::P67, $guidance->program());
    }

    public function testP67PicksABankAngleThatBringsThePredictedRangeToTheTarget(): void
    {
        $guidance = new EntryGuidance(self::TARGET_ANGLE);
        $vehicle = StartStates::entryVehicle();
        $guidance->guide($this->state(90000.0, 10000.0), $vehicle, 0.06 * EntryGuidance::G0);
        // 64 km up, 5850 m/s inertial (about 5440 m/s through the air), level, 500 nautical miles from the target.
        $angle = self::TARGET_ANGLE - 500.0 * 1852.0 / Body::Earth->radius();
        $radius = Body::Earth->radius() + 64000.0;
        $state = new StateVector(
            $radius * sin($angle),
            $radius * cos($angle),
            5850.0 * cos($angle),
            -5850.0 * sin($angle),
        );

        $guidance->guide($state, $vehicle, 1.0 * EntryGuidance::G0);

        self::assertSame(EntryProgram::P67, $guidance->program());
        self::assertGreaterThan(0.0, $guidance->bank());
        self::assertLessThan(M_PI, $guidance->bank());
        self::assertLessThan(2000.0, abs($guidance->predictedMiss()), 'the bisection ends within 2 km of the target');
    }

    public function testP67PutsTheLiftFullyUpOrDownWhenTheTargetIsOutOfReach(): void
    {
        $vehicle = StartStates::entryVehicle();
        $state = $this->state(60000.0, 5400.0);

        // Target right below: too long even with the lift down.
        $near = new EntryGuidance(0.0);
        $near->guide($this->state(90000.0, 10000.0), $vehicle, 0.06 * EntryGuidance::G0);
        $near->guide($state, $vehicle, 1.0 * EntryGuidance::G0);
        self::assertEqualsWithDelta(M_PI, $near->bank(), 1e-12);
        self::assertGreaterThan(0.0, $near->predictedMiss());

        // Target a quarter of the way round the Earth: short even with the lift up.
        $far = new EntryGuidance(M_PI / 2.0);
        $far->guide($this->state(90000.0, 10000.0), $vehicle, 0.06 * EntryGuidance::G0);
        $far->guide($state, $vehicle, 1.0 * EntryGuidance::G0);
        self::assertEqualsWithDelta(0.0, $far->bank(), 1e-12);
        self::assertLessThan(0.0, $far->predictedMiss());
    }

    public function testPredict3StopsSteeringUnder1000FeetPerSecondAndKeepsTheLastBankAngle(): void
    {
        $guidance = new EntryGuidance(self::TARGET_ANGLE);
        $vehicle = StartStates::entryVehicle();
        $guidance->guide($this->state(90000.0, 10000.0), $vehicle, 0.06 * EntryGuidance::G0);
        $guidance->guide($this->state(60000.0, 5400.0), $vehicle, 1.0 * EntryGuidance::G0);
        $bank = $guidance->bank();
        self::assertTrue($guidance->isSteering());

        $guidance->guide($this->state(15000.0, 300.0), $vehicle, 1.0 * EntryGuidance::G0);

        self::assertFalse($guidance->isSteering());
        self::assertSame($bank, $guidance->bank());
    }

    public function testAStoppedGuidanceChangesNothing(): void
    {
        $guidance = new EntryGuidance(self::TARGET_ANGLE);
        $guidance->stop();

        $guidance->guide($this->state(90000.0, 10000.0), StartStates::entryVehicle(), 1.0 * EntryGuidance::G0);

        self::assertSame(EntryProgram::P63, $guidance->program());
        self::assertFalse($guidance->isSteering());
    }
}
