<?php

declare(strict_types=1);

namespace Apollo\Chapter\Landing;

use Apollo\Agc\Job;

/**
 * The load the landing puts on the Executive: the SERVICER every 2 s (the guidance pass plus the
 * physics measurement), a radar job every second and a display job every second for each active
 * monitor (V16). The costs are AGC seconds per job; they are chosen, not measured. The radar and
 * SERVICER jobs add up to 88% of the AGC time, which an unhindered computer carries (V16 adds 5%).
 * The Apollo 11 scenario steals 15% of the time for the rendezvous radar, 85% is left, the SERVICER
 * jobs pile up and FINDVAC runs out of VAC areas (1201), or NOVAC out of core sets (1202).
 *
 * @see Luminary099/SERVICER.agc SERVICER
 * @see Luminary099/RADAR_LEADIN_ROUTINES.agc RADSAMP
 * @see Luminary099/EXECUTIVE.agc NOVAC
 */
final class LandingLoad
{
    /** The SERVICER schedules itself every 2 s (SERVICER.agc:73), in physics steps of 0.1 s. */
    public const SERVICER_PERIOD_STEPS = 20;

    /** Radar sampling and the monitor displays run every second. */
    public const SECOND_STEPS = 10;

    /** FINDVAC with PRIO20 (SERVICER.agc: `CA PRIO20`, `TC FINDVAC`, `2CADR SERVICER`). */
    public const SERVICER_PRIORITY = 20;
    public const SERVICER_COST = 0.40;

    /** NOVAC with PRIO25 (RADAR_LEADIN_ROUTINES.agc:47-48). */
    public const RADAR_PRIORITY = 25;
    public const RADAR_COST = 0.68;

    /** A display job runs below the SERVICER; the original has no number for it. */
    public const DISPLAY_PRIORITY = 10;
    public const DISPLAY_COST = 0.05;

    /** The cycle steal of the Apollo 11 scenario: the rendezvous radar, left on, steals counter cycles. */
    public const SCENARIO_CYCLE_STEAL = 0.15;

    public static function servicer(): Job
    {
        return new Job('SERVICER', self::SERVICER_PRIORITY, self::SERVICER_COST);
    }

    public static function radar(): Job
    {
        return new Job('RADAR', self::RADAR_PRIORITY, self::RADAR_COST);
    }

    public static function display(): Job
    {
        return new Job('DISPLAY', self::DISPLAY_PRIORITY, self::DISPLAY_COST);
    }
}
