<?php

declare(strict_types=1);

namespace Apollo\Trace;

/**
 * Original AGC routines this port executes. Line numbers are for commit
 * e7576d50f7c279a707cfaa71be2020592dbf43a8 of chrislgarry/Apollo-11.
 *
 * Each slice adds the cases its routines need.
 */
enum Routine
{
    case P63Lm;        // Luminary099/THE_LUNAR_LANDING.agc:40
    case QuadGuid;     // Luminary099/LUNAR_LANDING_GUIDANCE_EQUATIONS.agc:546
    case RootPsrs;     // Luminary099/LUNAR_LANDING_GUIDANCE_EQUATIONS.agc:1272
    case StartP64;     // Luminary099/LUNAR_LANDING_GUIDANCE_EQUATIONS.agc:260
    case P65Start;     // Luminary099/LUNAR_LANDING_GUIDANCE_EQUATIONS.agc:252
    case P65Vert;      // Luminary099/LUNAR_LANDING_GUIDANCE_EQUATIONS.agc:907
    case StartP66;     // Luminary099/LUNAR_LANDING_GUIDANCE_EQUATIONS.agc:143
    case P66Vert;      // Luminary099/LUNAR_LANDING_GUIDANCE_EQUATIONS.agc:919
    case RodComp;      // Luminary099/LUNAR_LANDING_GUIDANCE_EQUATIONS.agc:948
    case Throttle;     // Luminary099/THROTTLE_CONTROL_ROUTINES.agc:37
    case BurnBaby;     // Luminary099/BURN_BABY_BURN--MASTER_IGNITION_ROUTINE.agc:159
    case V99Recyc;     // Luminary099/BURN_BABY_BURN--MASTER_IGNITION_ROUTINE.agc:770
    case P63Zoom;      // Luminary099/BURN_BABY_BURN--MASTER_IGNITION_ROUTINE.agc:564
    case Num;          // Luminary099/PINBALL_GAME_BUTTONS_AND_LIGHTS.agc:536
    case Verb;         // Luminary099/PINBALL_GAME_BUTTONS_AND_LIGHTS.agc:658
    case Noun;         // Luminary099/PINBALL_GAME_BUTTONS_AND_LIGHTS.agc:671
    case Clear;        // Luminary099/PINBALL_GAME_BUTTONS_AND_LIGHTS.agc:752
    case Enter;        // Luminary099/PINBALL_GAME_BUTTONS_AND_LIGHTS.agc:870
    case VerbFan;      // Luminary099/PINBALL_GAME_BUTTONS_AND_LIGHTS.agc:970
    case CharAlrm;     // Luminary099/PINBALL_GAME_BUTTONS_AND_LIGHTS.agc:2744
    case ProcKey;      // Luminary099/PINBALL_GAME_BUTTONS_AND_LIGHTS.agc:2874
    case VbRelDsp;     // Luminary099/PINBALL_GAME_BUTTONS_AND_LIGHTS.agc:2918
    case VbTstLts;     // Luminary099/PINBALL_GAME_BUTTONS_AND_LIGHTS.agc:3623
    case ErrorReset;   // Luminary099/PINBALL_GAME_BUTTONS_AND_LIGHTS.agc:3732
    case V37;          // Luminary099/FRESH_START_AND_RESTART.agc:739
    case V37NoNo;      // Luminary099/FRESH_START_AND_RESTART.agc:978
    case FindVac;      // Luminary099/EXECUTIVE.agc:133 (FINDVAC2)
    case NoVac;        // Luminary099/EXECUTIVE.agc:155 (NOVAC2)
    case Bailout;      // Luminary099/ALARM_AND_ABORT.agc:133
    case GoProg;       // Luminary099/FRESH_START_AND_RESTART.agc:206

    public const COMMIT = 'e7576d50f7c279a707cfaa71be2020592dbf43a8';

    /** The label exactly as it appears in the original source. */
    public function label(): string
    {
        return match ($this) {
            self::P63Lm => 'P63LM',
            self::QuadGuid => 'QUADGUID',
            self::RootPsrs => 'ROOTPSRS',
            self::StartP64 => 'STARTP64',
            self::P65Start => 'P65START',
            self::P65Vert => 'P65VERT',
            self::StartP66 => 'STARTP66',
            self::P66Vert => 'P66VERT',
            self::RodComp => 'RODCOMP',
            self::Throttle => 'THROTTLE',
            self::BurnBaby => 'BURNBABY',
            self::V99Recyc => 'V99RECYC',
            self::P63Zoom => 'P63ZOOM',
            self::Num => 'NUM',
            self::Verb => 'VERB',
            self::Noun => 'NOUN',
            self::Clear => 'CLEAR',
            self::Enter => 'ENTER',
            self::VerbFan => 'VERBFAN',
            self::CharAlrm => 'CHARALRM',
            self::ProcKey => 'PROCKEY',
            self::VbRelDsp => 'VBRELDSP',
            self::VbTstLts => 'VBTSTLTS',
            self::ErrorReset => 'ERROR',
            self::V37 => 'V37',
            self::V37NoNo => 'V37NONO',
            self::FindVac => 'FINDVAC2',
            self::NoVac => 'NOVAC2',
            self::Bailout => 'BAILOUT',
            self::GoProg => 'GOPROG',
        };
    }

    /** Path of the original source file, relative to the repository root. */
    public function file(): string
    {
        return match ($this) {
            self::P63Lm => 'Luminary099/THE_LUNAR_LANDING.agc',
            self::QuadGuid, self::RootPsrs, self::StartP64, self::P65Start, self::P65Vert, self::StartP66,
            self::P66Vert, self::RodComp => 'Luminary099/LUNAR_LANDING_GUIDANCE_EQUATIONS.agc',
            self::Throttle => 'Luminary099/THROTTLE_CONTROL_ROUTINES.agc',
            self::BurnBaby, self::V99Recyc, self::P63Zoom => 'Luminary099/BURN_BABY_BURN--MASTER_IGNITION_ROUTINE.agc',
            self::Num, self::Verb, self::Noun, self::Clear, self::Enter, self::VerbFan, self::CharAlrm,
            self::ProcKey, self::VbRelDsp, self::VbTstLts, self::ErrorReset => 'Luminary099/PINBALL_GAME_BUTTONS_AND_LIGHTS.agc',
            self::V37, self::V37NoNo, self::GoProg => 'Luminary099/FRESH_START_AND_RESTART.agc',
            self::FindVac, self::NoVac => 'Luminary099/EXECUTIVE.agc',
            self::Bailout => 'Luminary099/ALARM_AND_ABORT.agc',
        };
    }

    /** Line of the label in the original file. */
    public function line(): int
    {
        return match ($this) {
            self::P63Lm => 40,
            self::QuadGuid => 546,
            self::RootPsrs => 1272,
            self::StartP64 => 260,
            self::P65Start => 252,
            self::P65Vert => 907,
            self::StartP66 => 143,
            self::P66Vert => 919,
            self::RodComp => 948,
            self::Throttle => 37,
            self::BurnBaby => 159,
            self::V99Recyc => 770,
            self::P63Zoom => 564,
            self::Num => 536,
            self::Verb => 658,
            self::Noun => 671,
            self::Clear => 752,
            self::Enter => 870,
            self::VerbFan => 970,
            self::CharAlrm => 2744,
            self::ProcKey => 2874,
            self::VbRelDsp => 2918,
            self::VbTstLts => 3623,
            self::ErrorReset => 3732,
            self::V37 => 739,
            self::V37NoNo => 978,
            self::FindVac => 133,
            self::NoVac => 155,
            self::Bailout => 133,
            self::GoProg => 206,
        };
    }

    public function url(): string
    {
        return sprintf(
            'https://github.com/chrislgarry/Apollo-11/blob/%s/%s#L%d',
            self::COMMIT,
            $this->file(),
            $this->line(),
        );
    }

    /** @return array{label: string, file: string, line: int, url: string} */
    public function toArray(): array
    {
        return [
            'label' => $this->label(),
            'file' => $this->file(),
            'line' => $this->line(),
            'url' => $this->url(),
        ];
    }
}
