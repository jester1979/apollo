<?php

declare(strict_types=1);

namespace Apollo\Tests\Trace;

use Apollo\Trace\Routine;
use PHPUnit\Framework\TestCase;

final class RoutineTest extends TestCase
{
    public function testEveryRoutinePointsAtALineOfTheOriginalSource(): void
    {
        foreach (Routine::cases() as $routine) {
            self::assertNotSame('', $routine->label());
            self::assertMatchesRegularExpression('#^(Luminary099|Comanche055)/#', $routine->file());
            self::assertGreaterThan(0, $routine->line());
            self::assertStringContainsString(Routine::COMMIT, $routine->url());
        }
    }

    public function testQuadGuidPointsAtItsLabel(): void
    {
        self::assertSame(
            [
                'label' => 'QUADGUID',
                'file' => 'Luminary099/LUNAR_LANDING_GUIDANCE_EQUATIONS.agc',
                'line' => 546,
                'url' => 'https://github.com/chrislgarry/Apollo-11/blob/e7576d50f7c279a707cfaa71be2020592dbf43a8/Luminary099/LUNAR_LANDING_GUIDANCE_EQUATIONS.agc#L546',
            ],
            Routine::QuadGuid->toArray(),
        );
    }

    public function testTheLabelsOfThePinballRoutinesPointAtTheirLines(): void
    {
        $lines = [
            'VERBFAN' => 970,
            'PROCKEY' => 2874,
            'CHARALRM' => 2744,
            'V37' => 739,
            'V37NONO' => 978,
        ];
        foreach (Routine::cases() as $routine) {
            if (isset($lines[$routine->label()])) {
                self::assertSame($lines[$routine->label()], $routine->line(), $routine->label());
                unset($lines[$routine->label()]);
            }
        }

        self::assertSame([], $lines, 'every logged routine is a case');
    }

    public function testTheLabelsOfTheLandingRoutinesPointAtTheirLines(): void
    {
        $lines = [
            'STARTP64' => 260,
            'P65START' => 252,
            'P65VERT' => 907,
            'STARTP66' => 143,
            'P66VERT' => 919,
            'RODCOMP' => 948,
            'THROTTLE' => 37,
        ];
        foreach (Routine::cases() as $routine) {
            if (isset($lines[$routine->label()])) {
                self::assertSame($lines[$routine->label()], $routine->line(), $routine->label());
                unset($lines[$routine->label()]);
            }
        }

        self::assertSame([], $lines, 'every landing routine is a case');
    }

    public function testTheLabelsOfTheExecutiveRoutinesPointAtTheirLines(): void
    {
        $lines = [
            'FINDVAC2' => ['Luminary099/EXECUTIVE.agc', 133],
            'NOVAC2' => ['Luminary099/EXECUTIVE.agc', 155],
            'BAILOUT' => ['Luminary099/ALARM_AND_ABORT.agc', 133],
            'GOPROG' => ['Luminary099/FRESH_START_AND_RESTART.agc', 206],
        ];
        foreach (Routine::cases() as $routine) {
            if (isset($lines[$routine->label()])) {
                self::assertSame($lines[$routine->label()], [$routine->file(), $routine->line()], $routine->label());
                unset($lines[$routine->label()]);
            }
        }

        self::assertSame([], $lines, 'every Executive routine is a case');
    }

    public function testTheLabelsOfTheLaunchRoutinesPointAtTheirLines(): void
    {
        $lines = [
            'P11' => ['Comanche055/P11.agc', 158],
            'VHHDOT' => ['Comanche055/P11.agc', 409],
        ];
        foreach (Routine::cases() as $routine) {
            if (isset($lines[$routine->label()])) {
                self::assertSame($lines[$routine->label()], [$routine->file(), $routine->line()], $routine->label());
                unset($lines[$routine->label()]);
            }
        }

        self::assertSame([], $lines, 'every launch routine is a case');
    }

    public function testTheLabelsOfTheP40RoutinesPointAtTheirLines(): void
    {
        $lines = [
            'P40CSM' => 46,
            'POSTBURN' => 176,
            'TIG-5' => 480,
            'V99P' => 984,
            'V99P/TIG' => 1002,
            'UPDATEVG' => 744,
            'STEERING' => 819,
            'S40.8' => 1646,
            'S40.81' => 1759,
        ];
        foreach (Routine::cases() as $routine) {
            if (isset($lines[$routine->label()])) {
                self::assertSame(['Comanche055/P40-P47.agc', $lines[$routine->label()]], [$routine->file(), $routine->line()], $routine->label());
                unset($lines[$routine->label()]);
            }
        }

        self::assertSame([], $lines, 'every P40 routine is a case');
    }

    public function testTheLabelsOfTheAscentRoutinesPointAtTheirLines(): void
    {
        $lines = [
            'P12LM' => ['Luminary099/P12.agc', 35],
            'NEWLOAD' => ['Luminary099/P12.agc', 111],
            'P12TABLE' => ['Luminary099/BURN_BABY_BURN--MASTER_IGNITION_ROUTINE.agc', 74],
            'P12IGN' => ['Luminary099/BURN_BABY_BURN--MASTER_IGNITION_ROUTINE.agc', 488],
            'ATMAG' => ['Luminary099/ASCENT_GUIDANCE.agc', 38],
            'ASCENT' => ['Luminary099/ASCENT_GUIDANCE.agc', 90],
            'ENGOFF' => ['Luminary099/ASCENT_GUIDANCE.agc', 470],
            'CUTOFF' => ['Luminary099/ASCENT_GUIDANCE.agc', 517],
        ];
        foreach (Routine::cases() as $routine) {
            if (isset($lines[$routine->label()])) {
                self::assertSame($lines[$routine->label()], [$routine->file(), $routine->line()], $routine->label());
                unset($lines[$routine->label()]);
            }
        }

        self::assertSame([], $lines, 'every ascent routine is a case');
    }

    public function testNoTwoRoutinesShareALabel(): void
    {
        $labels = array_map(static fn (Routine $routine): string => $routine->label(), Routine::cases());

        self::assertSame($labels, array_values(array_unique($labels)));
    }
}
