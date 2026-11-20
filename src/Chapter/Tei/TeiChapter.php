<?php

declare(strict_types=1);

namespace Apollo\Chapter\Tei;

use Apollo\Chapter\Burn\BurnSpec;
use Apollo\Chapter\Burn\P40BurnChapter;
use Apollo\Chapter\ChapterId;
use Apollo\Physics\Conic;
use Apollo\Physics\StateVector;
use Apollo\Scenario\StartStates;

/**
 * The trans-Earth injection chapter: the same P40 flow as the lunar orbit insertion (P40BurnChapter), now
 * prograde, with the CSM alone in a circular orbit of 111 km. The burn gains TEI_DELTA_V along the velocity
 * at ignition, so the orbit it predicts starts as a closed ellipse that grows with the burn, until it breaks
 * open into an escape trajectory.
 *
 * The chapter ends in success when the vehicle is on an escape trajectory with respect to the Moon (its
 * energy is above zero), and missed for an orbit that is still bound.
 *
 * @see Comanche055/P40-P47.agc P40CSM
 * @see Comanche055/P40-P47.agc POSTBURN
 */
final class TeiChapter extends P40BurnChapter
{
    /** The radius at the edge of the view, in metres; the top view draws the orbit with it. */
    private const VIEW_EXTENT = 2600000.0;

    /** The most distant point of the predicted path of an open orbit, in metres. */
    private const PREDICTION_RADIUS = 3600000.0;

    /**
     * @param StateVector|null $state start state; the circular orbit of the chapter when omitted
     */
    public function __construct(?StateVector $state = null)
    {
        parent::__construct(
            ChapterId::TransEarthInjection,
            $state ?? StartStates::teiState(),
            StartStates::teiVehicle(),
            new BurnSpec(
                deltaV: StartStates::TEI_DELTA_V,
                prograde: true,
                countdown: StartStates::TEI_COUNTDOWN,
                viewExtent: self::VIEW_EXTENT,
                predictionRadius: self::PREDICTION_RADIUS,
                burnName: 'injection',
                startNote: 'P40 starts: the CSM alone circles the Moon at 111 km. V50N25 R1=00203 asks the crew to switch to CMC-AUTO; the clock waits for PRO',
            ),
        );
    }

    /** Success for an escape trajectory (energy above zero relative to the Moon), missed for a bound orbit. */
    protected function judge(Conic $conic): array
    {
        if ($conic->isBound()) {
            return [
                false,
                sprintf(
                    'Orbit: still bound to the Moon (apolune %.0f km), the vehicle does not escape, the injection is missed',
                    ($conic->apoapsisAltitude() ?? 0.0) / 1000.0,
                ),
            ];
        }

        $energy = $conic->specificEnergy();

        return [
            true,
            sprintf(
                'Orbit: an escape trajectory, energy %+.2f MJ/kg, %.0f m/s of excess speed: the vehicle leaves the Moon',
                $energy / 1.0e6,
                sqrt(2.0 * $energy),
            ),
        ];
    }
}
