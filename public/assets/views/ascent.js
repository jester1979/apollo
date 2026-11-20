// Side view of the ascent of the lunar module, from the surface to the lunar orbit of P12.
// Reads only the fields of the "ascent" vehicle view: x (downrange, m), altitude (m), vx, vz (m/s),
// pitch (rad above the horizon), throttle, fuel, liftedOff, targetAltitude (m), bound, perilune and
// apolune (m, after lift-off), vg (m/s), phase, program.
// The descent stage stays where it stands on the surface; the ascent stage lifts off from it with
// the flame of the APS, tips over and flies away downrange. Both scales are square roots, so that the
// first metres of the rise and the 18 km of the whole ascent are both visible: the view is not to scale.
// Everything is drawn with Canvas paths.

const GROUND_Y = 440;
const TOP_MARGIN = 40;
const MAX_ALTITUDE = 20000; // m, a little above the target altitude
const MAX_DOWNRANGE = 300000; // m, the downrange of the whole ascent is about 250 km
const DESCENT_STAGE_X = 170; // px, where the descent stage stays
const RIGHT_LIMIT = 640; // px, the ascent stage never goes further right than this
const LANDER_HEIGHT_ABOVE_GROUND = 26; // px, the legs of the descent stage
const PATH_CAPACITY = 300;
const READOUT_WIDTH = 300; // px, the line of the target altitude starts to the right of the readout
const FEET_PER_METRE = 3.28084;
const KILOMETRE = 1000;

// The track of the ascent stage on the canvas, kept between ticks; a restart (time going back) clears it.
let path = [];
let lastTime = -1;

function altitudeFraction(altitude) {
    return Math.sqrt(Math.min(Math.max(altitude, 0), MAX_ALTITUDE) / MAX_ALTITUDE);
}

// Pixels above the resting place of the ascent stage.
function liftPixels(altitude) {
    return altitudeFraction(altitude) * (GROUND_Y - LANDER_HEIGHT_ABOVE_GROUND - TOP_MARGIN - 20);
}

function downrangeToX(downrange) {
    const fraction = Math.sqrt(Math.min(Math.abs(downrange), MAX_DOWNRANGE) / MAX_DOWNRANGE);

    return DESCENT_STAGE_X + Math.sign(downrange) * fraction * (RIGHT_LIMIT - DESCENT_STAGE_X);
}

function drawSurface(ctx, width, height) {
    ctx.fillStyle = '#3a3a3a';
    ctx.fillRect(0, GROUND_Y, width, height - GROUND_Y);
    ctx.strokeStyle = '#6a6a6a';
    ctx.lineWidth = 1;
    ctx.beginPath();
    ctx.moveTo(0, GROUND_Y);
    ctx.lineTo(width, GROUND_Y);
    ctx.stroke();
}

// The altitude of the injection: a dashed line across the view.
function drawTargetLine(ctx, width, view) {
    const y = GROUND_Y - LANDER_HEIGHT_ABOVE_GROUND - liftPixels(view.targetAltitude);
    ctx.save();
    ctx.strokeStyle = '#5cc8ff';
    ctx.lineWidth = 1;
    ctx.setLineDash([8, 6]);
    ctx.beginPath();
    ctx.moveTo(READOUT_WIDTH, y);
    ctx.lineTo(width - 120, y);
    ctx.stroke();
    ctx.restore();
    ctx.fillStyle = '#5cc8ff';
    ctx.font = '11px ui-monospace, monospace';
    ctx.fillText(`TARGET ${(view.targetAltitude / KILOMETRE).toFixed(1)} km`, READOUT_WIDTH + 8, y - 6);
}

// The part of the lander that stays behind: the descent stage with its legs, as a gold block.
function drawDescentStage(ctx, x, y) {
    ctx.save();
    ctx.translate(x, y);
    ctx.lineWidth = 2;

    ctx.strokeStyle = '#b8b8b8';
    ctx.beginPath();
    ctx.moveTo(-10, 8); ctx.lineTo(-22, 26);
    ctx.moveTo(10, 8); ctx.lineTo(22, 26);
    ctx.moveTo(-26, 26); ctx.lineTo(-18, 26);
    ctx.moveTo(26, 26); ctx.lineTo(18, 26);
    ctx.stroke();

    ctx.fillStyle = '#c9a227';
    ctx.fillRect(-14, 2, 28, 12);
    ctx.restore();
}

// The ascent stage, tilted from the vertical by the thrust direction, with the flame of the APS under it.
function drawAscentStage(ctx, x, y, tilt, throttle) {
    ctx.save();
    ctx.translate(x, y);
    ctx.rotate(tilt);

    if (throttle > 0) {
        const length = 14 + throttle * 26;
        ctx.fillStyle = '#ff8a1f';
        ctx.beginPath();
        ctx.moveTo(-4, 2);
        ctx.lineTo(0, 2 + length);
        ctx.lineTo(4, 2);
        ctx.closePath();
        ctx.fill();
        ctx.fillStyle = '#fff3b0';
        ctx.beginPath();
        ctx.moveTo(-2, 2);
        ctx.lineTo(0, 2 + length * 0.6);
        ctx.lineTo(2, 2);
        ctx.closePath();
        ctx.fill();
    }

    ctx.fillStyle = '#9c9c9c';
    ctx.beginPath();
    ctx.moveTo(-9, 2); ctx.lineTo(-12, -8); ctx.lineTo(-6, -16);
    ctx.lineTo(6, -16); ctx.lineTo(12, -8); ctx.lineTo(9, 2);
    ctx.closePath();
    ctx.fill();

    ctx.fillStyle = '#1b2b3a';
    ctx.fillRect(-4, -11, 8, 5);
    ctx.restore();
}

function drawPath(ctx) {
    if (path.length < 2) {
        return;
    }
    ctx.save();
    ctx.strokeStyle = '#e8eef5';
    ctx.lineWidth = 1.5;
    ctx.beginPath();
    path.forEach(([px, py], index) => {
        if (index === 0) {
            ctx.moveTo(px, py);
        } else {
            ctx.lineTo(px, py);
        }
    });
    ctx.stroke();
    ctx.restore();
}

function drawBar(ctx, x, label, fraction, colour) {
    const top = TOP_MARGIN;
    const height = GROUND_Y - TOP_MARGIN - 30;
    const clamped = Math.min(Math.max(fraction, 0), 1);
    ctx.strokeStyle = '#5b6b7a';
    ctx.strokeRect(x, top, 16, height);
    ctx.fillStyle = colour;
    ctx.fillRect(x, top + height * (1 - clamped), 16, height * clamped);
    ctx.fillStyle = '#9aa7b3';
    ctx.font = '11px ui-monospace, monospace';
    ctx.textAlign = 'center';
    ctx.fillText(label, x + 8, top - 6);
    ctx.textAlign = 'left';
}

function phaseText(view) {
    switch (view.phase) {
        case 'targets':
            return 'ON THE SURFACE · waiting for PRO';
        case 'countdown':
            return 'ON THE SURFACE · counting down to TIG';
        case 'burn':
            return view.liftedOff ? 'APS BURNING' : 'APS IGNITED · LIFT-OFF';
        default:
            return 'ENGINE OFF';
    }
}

function drawReadout(ctx, width, view, t) {
    ctx.fillStyle = '#e6e6e6';
    ctx.font = '14px ui-monospace, monospace';
    ctx.textAlign = 'left';
    ctx.fillText(`T      ${t.toFixed(1)} s`, 16, 26);
    ctx.fillText(`ALT    ${(view.altitude / KILOMETRE).toFixed(2)} km`, 16, 46);
    ctx.fillText(`V RAD  ${view.vz.toFixed(1)} m/s (${(view.vz * FEET_PER_METRE).toFixed(1)} ft/s)`, 16, 66);
    ctx.fillText(`V HOR  ${view.vx.toFixed(1)} m/s (${(view.vx * FEET_PER_METRE).toFixed(0)} ft/s)`, 16, 86);
    ctx.fillText(`THRTL  ${(view.throttle * 100).toFixed(0)} %`, 16, 106);
    ctx.fillText(`FUEL   ${(view.fuel * 100).toFixed(0)} %`, 16, 126);
    ctx.fillText(`PROG   P${String(view.program).padStart(2, '0')}`, 16, 146);
    ctx.fillStyle = '#9aa7b3';
    ctx.font = '12px ui-monospace, monospace';
    ctx.fillText(phaseText(view), 16, 166);
    if (view.perilune !== null && view.phase === 'done') {
        ctx.fillStyle = view.bound && view.perilune > 0 ? '#5fbf6a' : '#d95b3d';
        const apolune = view.apolune === null ? 'open' : `${(view.apolune / KILOMETRE).toFixed(0)} km`;
        ctx.fillText(`ORBIT  ${(view.perilune / KILOMETRE).toFixed(0)} x ${apolune}`, 16, 186);
    }
    ctx.fillStyle = '#6f7c88';
    ctx.fillText('not to scale', 16, GROUND_Y - 8);

    drawBar(ctx, width - 70, 'FUEL', view.fuel, view.fuel > 0.1 ? '#5fbf6a' : '#d95b3d');
    drawBar(ctx, width - 40, 'ALT', altitudeFraction(view.altitude), '#3d8bd9');
}

export function render(ctx, vehicleView, t) {
    const { width, height } = ctx.canvas;

    ctx.fillStyle = '#000';
    ctx.fillRect(0, 0, width, height);

    if (t < lastTime) {
        path = [];
    }
    lastTime = t;

    const restY = GROUND_Y - LANDER_HEIGHT_ABOVE_GROUND;
    const stageX = downrangeToX(vehicleView.x);
    const stageY = restY - liftPixels(vehicleView.altitude);
    if (vehicleView.liftedOff) {
        path.push([stageX, stageY]);
        if (path.length > PATH_CAPACITY) {
            path = path.filter((_, index) => index % 2 === 0);
        }
    }

    drawSurface(ctx, width, height);
    drawTargetLine(ctx, width, vehicleView);
    drawPath(ctx);
    drawDescentStage(ctx, DESCENT_STAGE_X, restY);
    ctx.fillStyle = '#c9a227';
    ctx.font = '11px ui-monospace, monospace';
    ctx.textAlign = 'center';
    ctx.fillText('DESCENT STAGE (stays)', DESCENT_STAGE_X, GROUND_Y + 22);
    ctx.textAlign = 'left';
    drawAscentStage(ctx, stageX, stageY, Math.PI / 2 - vehicleView.pitch, vehicleView.throttle);
    drawReadout(ctx, width, vehicleView, t);
}
