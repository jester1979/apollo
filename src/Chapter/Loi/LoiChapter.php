<?php

declare(strict_types=1);

namespace Apollo\Chapter\Loi;

use Apollo\Chapter\Burn\BurnSpec;
use Apollo\Chapter\Burn\P40BurnChapter;
use Apollo\Chapter\ChapterId;
use Apollo\Physics\Conic;
use Apollo\Physics\StateVector;
use Apollo\Scenario\StartStates;

/**
 * The lunar orbit insertion chapter: P40 with an external delta-V, flown with the CSM and the LM docked.
 * The vehicle coasts towards the Moon on a hyperbola; the burn gains LOI_DELTA_V retrograde, so the orbit it
 * predicts bends from an open hyperbola into a closed ellipse. The P40 flow itself (V50N25, V06N40, V99N40,
 * PRO, V16N40, the bounded waits, P00) is in P40BurnChapter.
 *
 * The chapter ends in success for a bound orbit with a perilune above MIN_PERILUNE, and missed for any other
 * orbit.
 *
 * @see Comanche055/P40-P47.agc P40CSM
 * @see Comanche055/P40-P47.agc POSTBURN
 */
final class LoiChapter extends P40BurnChapter
{
    /** A perilune above this altitude counts as an orbit, in metres. */
    public const MIN_PERILUNE = 50000.0;

    /** The radius at the edge of the view, in metres; the top view draws the orbit with it. */
    private const VIEW_EXTENT = 2600000.0;

    /** The most distant point of the predicted path of an open orbit, in metres. */
    private const PREDICTION_RADIUS = 3600000.0;

    /**
     * @param StateVector|null $state start state; the approach to the Moon of the chapter when omitted
     */
    public function __construct(?StateVector $state = null)
    {
        parent::__construct(
            ChapterId::LunarOrbitInsertion,
            $state ?? StartStates::loiState(),
            StartStates::loiVehicle(),
            new BurnSpec(
                deltaV: StartStates::LOI_DELTA_V,
                prograde: false,
                countdown: StartStates::LOI_COUNTDOWN,
                viewExtent: self::VIEW_EXTENT,
                predictionRadius: self::PREDICTION_RADIUS,
                burnName: 'insertion',
                startNote: 'P40 starts: the CSM with the LM coasts towards perilune. V50N25 R1=00203 asks the crew to switch to CMC-AUTO; the clock waits for PRO',
            ),
        );
    }

    /** Success for a bound orbit with a perilune above MIN_PERILUNE, missed for any other orbit. */
    protected function judge(Conic $conic): array
    {
        $apolune = $conic->apoapsisAltitude();
        $success = $conic->isBound() && $conic->periapsisAltitude() > self::MIN_PERILUNE;

        return [
            $success,
            $conic->isBound()
                ? sprintf(
                    'Orbit: perilune %.0f km, apolune %.0f km: %s',
                    $conic->periapsisAltitude() / 1000.0,
                    ($apolune ?? 0.0) / 1000.0,
                    $success ? 'a bound lunar orbit' : 'the perilune is too low, the insertion is missed',
                )
                : 'Orbit: still open, the vehicle is not captured, the insertion is missed',
        ];
    }
}
