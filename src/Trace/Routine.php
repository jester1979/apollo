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
    case P12Table;     // Luminary099/BURN_BABY_BURN--MASTER_IGNITION_ROUTINE.agc:74
    case P12Ign;       // Luminary099/BURN_BABY_BURN--MASTER_IGNITION_ROUTINE.agc:488
    case P12Lm;        // Luminary099/P12.agc:35
    case NewLoad;      // Luminary099/P12.agc:111 (the V06N76 flash)
    case AtMag;        // Luminary099/ASCENT_GUIDANCE.agc:38
    case Ascent;       // Luminary099/ASCENT_GUIDANCE.agc:90
    case EngOff;       // Luminary099/ASCENT_GUIDANCE.agc:470
    case CutOff;       // Luminary099/ASCENT_GUIDANCE.agc:517
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
    case P11;          // Comanche055/P11.agc:158
    case Vhhdot;       // Comanche055/P11.agc:409
    case P40Csm;       // Comanche055/P40-P47.agc:46
    case PostBurn;     // Comanche055/P40-P47.agc:176
    case Tig5;         // Comanche055/P40-P47.agc:480
    case V99P;         // Comanche055/P40-P47.agc:984
    case V99PTig;      // Comanche055/P40-P47.agc:1002
    case UpdateVg;     // Comanche055/P40-P47.agc:744
    case Steering;     // Comanche055/P40-P47.agc:819
    case S408;         // Comanche055/P40-P47.agc:1646
    case S4081;        // Comanche055/P40-P47.agc:1759

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
            self::P12Table => 'P12TABLE',
            self::P12Ign => 'P12IGN',
            self::P12Lm => 'P12LM',
            self::NewLoad => 'NEWLOAD',
            self::AtMag => 'ATMAG',
            self::Ascent => 'ASCENT',
            self::EngOff => 'ENGOFF',
            self::CutOff => 'CUTOFF',
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
            self::P11 => 'P11',
            self::Vhhdot => 'VHHDOT',
            self::P40Csm => 'P40CSM',
            self::PostBurn => 'POSTBURN',
            self::Tig5 => 'TIG-5',
            self::V99P => 'V99P',
            self::V99PTig => 'V99P/TIG',
            self::UpdateVg => 'UPDATEVG',
            self::Steering => 'STEERING',
            self::S408 => 'S40.8',
            self::S4081 => 'S40.81',
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
            self::BurnBaby, self::V99Recyc, self::P63Zoom, self::P12Table, self::P12Ign => 'Luminary099/BURN_BABY_BURN--MASTER_IGNITION_ROUTINE.agc',
            self::P12Lm, self::NewLoad => 'Luminary099/P12.agc',
            self::AtMag, self::Ascent, self::EngOff, self::CutOff => 'Luminary099/ASCENT_GUIDANCE.agc',
            self::Num, self::Verb, self::Noun, self::Clear, self::Enter, self::VerbFan, self::CharAlrm,
            self::ProcKey, self::VbRelDsp, self::VbTstLts, self::ErrorReset => 'Luminary099/PINBALL_GAME_BUTTONS_AND_LIGHTS.agc',
            self::V37, self::V37NoNo, self::GoProg => 'Luminary099/FRESH_START_AND_RESTART.agc',
            self::FindVac, self::NoVac => 'Luminary099/EXECUTIVE.agc',
            self::Bailout => 'Luminary099/ALARM_AND_ABORT.agc',
            self::P11, self::Vhhdot => 'Comanche055/P11.agc',
            self::P40Csm, self::PostBurn, self::Tig5, self::V99P, self::V99PTig, self::UpdateVg, self::Steering,
            self::S408, self::S4081 => 'Comanche055/P40-P47.agc',
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
            self::P12Table => 74,
            self::P12Ign => 488,
            self::P12Lm => 35,
            self::NewLoad => 111,
            self::AtMag => 38,
            self::Ascent => 90,
            self::EngOff => 470,
            self::CutOff => 517,
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
            self::P11 => 158,
            self::Vhhdot => 409,
            self::P40Csm => 46,
            self::PostBurn => 176,
            self::Tig5 => 480,
            self::V99P => 984,
            self::V99PTig => 1002,
            self::UpdateVg => 744,
            self::Steering => 819,
            self::S408 => 1646,
            self::S4081 => 1759,
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
