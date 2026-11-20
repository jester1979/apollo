<?php

declare(strict_types=1);

namespace Apollo\Physics;

use Apollo\Scenario\StartStates;

/**
 * The Saturn V as a flying body: three stages that burn one after the other, and the fixed pitch
 * programme they fly. The stack sits in one Vehicle at a time; when a stage has burned all its
 * propellant it is dropped and the next stage's Vehicle takes over with the mass that is left.
 *
 * This is not a port. The rocket was flown by the Launch Vehicle Digital Computer (LVDC) in the
 * instrument unit, and its code is not in the Apollo-11 repository. The pitch programme is a table
 * of chosen values that reaches the parking orbit with the stage data of StartStates; the real LVDC
 * steered with a closed-loop guidance. The command module computer only monitored the flight (P11).
 */
final class SaturnV
{
    /**
     * The pitch programme: time since lift-off in seconds and the angle of the stack above the local
     * horizon in degrees. The stack rises vertically for 12 s to clear the tower, then rolls over
     * and pitches down to nearly horizontal flight as the speed builds up. Between two entries the
     * angle is interpolated linearly.
     */
    private const PITCH_PROGRAMME = [
        [0.0, 90.0],
        [12.0, 90.0],
        [30.0, 78.0],
        [60.0, 62.0],
        [90.0, 50.0],
        [120.0, 41.0],
        [160.0, 34.0],
        [250.0, 26.0],
        [350.0, 19.0],
        [450.0, 12.0],
        [550.0, 6.0],
        [700.0, 0.0],
    ];

    /** A stage is spent when less than this much propellant is left, in kg. */
    private const BURNED_OUT = 1.0;

    private SaturnStage $stage = SaturnStage::SIC;
    private Vehicle $vehicle;
    private float $stagePropellant;

    public function __construct()
    {
        $stack = StartStates::SIC_PROPELLANT + StartStates::SIC_STRUCTURE
            + StartStates::SII_PROPELLANT + StartStates::SII_STRUCTURE
            + StartStates::SIVB_PROPELLANT + StartStates::SIVB_STRUCTURE
            + StartStates::SATURN_PAYLOAD;
        $this->stagePropellant = StartStates::SIC_PROPELLANT;
        $this->vehicle = $this->stageVehicle($stack, $this->stagePropellant, StartStates::SIC_THRUST, StartStates::SIC_ISP);
    }

    /** The vehicle that flies now: the stack from the burning stage upwards. */
    public function vehicle(): Vehicle
    {
        return $this->vehicle;
    }

    public function stage(): SaturnStage
    {
        return $this->stage;
    }

    /** Fraction of the propellant of the burning stage that is left, 1 at its ignition and 0 when it is spent. */
    public function propellantLeft(): float
    {
        return $this->vehicle->fuelMass() / $this->stagePropellant;
    }

    /** Sets the pitch of the stack for the time since lift-off, from the fixed programme. */
    public function flyProgramme(float $time): void
    {
        $this->vehicle->pitch = deg2rad(self::pitchDegrees($time));
    }

    /**
     * Drops the burning stage when it has burned out and lights the next one at once (the real stack
     * waited about a second for the ullage rockets). The S-IVB is the last stage: it stays.
     *
     * @return SaturnStage|null the stage that was dropped, or null when none was
     */
    public function separateIfBurnedOut(): ?SaturnStage
    {
        if ($this->vehicle->fuelMass() >= self::BURNED_OUT) {
            return null;
        }

        $dropped = $this->stage;
        $remaining = $this->vehicle->mass;
        switch ($dropped) {
            case SaturnStage::SIC:
                $this->stage = SaturnStage::SII;
                $this->stagePropellant = StartStates::SII_PROPELLANT;
                $this->vehicle = $this->stageVehicle(
                    $remaining - StartStates::SIC_STRUCTURE,
                    $this->stagePropellant,
                    StartStates::SII_THRUST,
                    StartStates::SII_ISP,
                    $this->vehicle->pitch,
                );

                return $dropped;
            case SaturnStage::SII:
                $this->stage = SaturnStage::SIVB;
                $this->stagePropellant = StartStates::SIVB_PROPELLANT;
                $this->vehicle = $this->stageVehicle(
                    $remaining - StartStates::SII_STRUCTURE,
                    $this->stagePropellant,
                    StartStates::SIVB_THRUST,
                    StartStates::SIVB_ISP,
                    $this->vehicle->pitch,
                );

                return $dropped;
            default:
                return null;
        }
    }

    /** Shuts the engine of the stage that burns (the S-IVB cut-off at orbit). */
    public function cutOff(): void
    {
        $this->vehicle->throttle = 0.0;
    }

    /** True when the burning stage has no propellant left. */
    public function isSpent(): bool
    {
        return $this->vehicle->fuelMass() < self::BURNED_OUT;
    }

    /** Pitch above the local horizon in degrees at the given time since lift-off. */
    public static function pitchDegrees(float $time): float
    {
        $last = self::PITCH_PROGRAMME[count(self::PITCH_PROGRAMME) - 1];
        if ($time >= $last[0]) {
            return $last[1];
        }

        for ($i = 1, $count = count(self::PITCH_PROGRAMME); $i < $count; $i++) {
            [$t1, $p1] = self::PITCH_PROGRAMME[$i];
            if ($time < $t1) {
                [$t0, $p0] = self::PITCH_PROGRAMME[$i - 1];

                return $p0 + ($p1 - $p0) * ($time - $t0) / max($t1 - $t0, 1e-9);
            }
        }

        return $last[1];
    }

    private function stageVehicle(float $stackMass, float $propellant, float $thrust, float $isp, float $pitch = M_PI_2): Vehicle
    {
        return new Vehicle(
            mass: $stackMass,
            dryMass: $stackMass - $propellant,
            maxThrust: $thrust,
            isp: $isp,
            throttle: 1.0,
            pitch: $pitch,
            dragArea: StartStates::SATURN_DRAG_AREA,
        );
    }
}
