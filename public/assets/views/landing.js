// Side view of the lunar module above the lunar surface.
// Reads only the fields of the "landing" vehicle view: altitude, vx, vz, x, throttle, fuel, target,
// program, desiredRate.

const GROUND_Y = 440;
const TOP_MARGIN = 40;
const MAX_ALTITUDE = 16000; // m, a little above the PDI altitude
const PIXELS_PER_METRE_GROUND = 1 / 40; // surface scrolls 1 px per 40 m
const CONTACT_LIGHT_ALTITUDE = 1.7; // m, the probes of the legs touch the surface
const MAX_DESCENT_RATE = 20; // m/s at the bottom of the descent rate bar
const MAX_ASCENT_RATE = 5; // m/s at the top of the bar
const FEET_PER_METRE = 3.28084;

// A square-root scale keeps the last few hundred metres visible next to the 16 km of the descent.
function altitudeFraction(altitude) {
    return Math.sqrt(Math.min(Math.max(altitude, 0), MAX_ALTITUDE) / MAX_ALTITUDE);
}

function altitudeToY(altitude) {
    return GROUND_Y - 30 - altitudeFraction(altitude) * (GROUND_Y - 30 - TOP_MARGIN);
}

function drawSurface(ctx, width, height, downrange) {
    ctx.fillStyle = '#3a3a3a';
    ctx.fillRect(0, GROUND_Y, width, height - GROUND_Y);
    ctx.strokeStyle = '#6a6a6a';
    ctx.lineWidth = 1;
    ctx.beginPath();
    ctx.moveTo(0, GROUND_Y);
    ctx.lineTo(width, GROUND_Y);
    ctx.stroke();

    // Dashes that scroll with the ground track, so horizontal speed is visible.
    const spacing = 80;
    const offset = (downrange * PIXELS_PER_METRE_GROUND) % spacing;
    ctx.strokeStyle = '#555';
    ctx.beginPath();
    for (let x = -offset; x < width; x += spacing) {
        ctx.moveTo(x, GROUND_Y + 24);
        ctx.lineTo(x + 30, GROUND_Y + 24);
    }
    ctx.stroke();
}

// Exhaust flame of the descent engine; its length follows the throttle (0..1).
function drawFlame(ctx, cx, cy, throttle) {
    if (throttle <= 0) {
        return;
    }
    const length = 10 + throttle * 70;
    const half = 5 + throttle * 6;

    ctx.save();
    ctx.translate(cx, cy);

    ctx.fillStyle = '#ff8a1f';
    ctx.beginPath();
    ctx.moveTo(-half, 14);
    ctx.lineTo(0, 14 + length);
    ctx.lineTo(half, 14);
    ctx.closePath();
    ctx.fill();

    ctx.fillStyle = '#fff3b0';
    ctx.beginPath();
    ctx.moveTo(-half / 2, 14);
    ctx.lineTo(0, 14 + length * 0.6);
    ctx.lineTo(half / 2, 14);
    ctx.closePath();
    ctx.fill();

    ctx.restore();
}

function drawLander(ctx, cx, cy) {
    ctx.save();
    ctx.translate(cx, cy);
    ctx.lineWidth = 2;

    // legs
    ctx.strokeStyle = '#b8b8b8';
    ctx.beginPath();
    ctx.moveTo(-10, 8); ctx.lineTo(-22, 26);
    ctx.moveTo(10, 8); ctx.lineTo(22, 26);
    ctx.moveTo(-26, 26); ctx.lineTo(-18, 26);
    ctx.moveTo(26, 26); ctx.lineTo(18, 26);
    ctx.stroke();

    // descent stage
    ctx.fillStyle = '#c9a227';
    ctx.fillRect(-14, 2, 28, 12);

    // ascent stage
    ctx.fillStyle = '#9c9c9c';
    ctx.beginPath();
    ctx.moveTo(-9, 2); ctx.lineTo(-12, -8); ctx.lineTo(-6, -16);
    ctx.lineTo(6, -16); ctx.lineTo(12, -8); ctx.lineTo(9, 2);
    ctx.closePath();
    ctx.fill();

    // window
    ctx.fillStyle = '#1b2b3a';
    ctx.fillRect(-4, -11, 8, 5);

    ctx.restore();
}

function drawBar(ctx, x, label, fraction, color) {
    const top = TOP_MARGIN;
    const height = GROUND_Y - TOP_MARGIN - 30;
    const clamped = Math.min(Math.max(fraction, 0), 1);
    ctx.strokeStyle = '#5b6b7a';
    ctx.strokeRect(x, top, 16, height);
    ctx.fillStyle = color;
    ctx.fillRect(x, top + height * (1 - clamped), 16, height * clamped);
    ctx.fillStyle = '#9aa7b3';
    ctx.font = '11px ui-monospace, monospace';
    ctx.textAlign = 'center';
    ctx.fillText(label, x + 8, top - 6);
    ctx.textAlign = 'left';
}

// The bar of the altitude rate runs from MAX_DESCENT_RATE down to MAX_ASCENT_RATE up; the tick marks
// the rate the crew asked for with the ROD switch (P66).
function drawRateBar(ctx, x, view) {
    const top = TOP_MARGIN;
    const height = GROUND_Y - TOP_MARGIN - 30;
    const span = MAX_DESCENT_RATE + MAX_ASCENT_RATE;
    const toFraction = (rate) => Math.min(Math.max((rate + MAX_DESCENT_RATE) / span, 0), 1);

    drawBar(ctx, x, 'DESC', toFraction(view.vz), '#d9a53d');
    // zero line
    const zeroY = top + height * (1 - toFraction(0));
    ctx.strokeStyle = '#9aa7b3';
    ctx.beginPath();
    ctx.moveTo(x - 3, zeroY);
    ctx.lineTo(x + 19, zeroY);
    ctx.stroke();

    if (view.desiredRate !== null) {
        const y = top + height * (1 - toFraction(view.desiredRate));
        ctx.strokeStyle = '#ffffff';
        ctx.lineWidth = 2;
        ctx.beginPath();
        ctx.moveTo(x - 6, y);
        ctx.lineTo(x + 22, y);
        ctx.stroke();
        ctx.lineWidth = 1;
    }
}

// The landing site as a flag on the surface, at the same scale as the scrolling dashes. Far away it
// sits at the edge of the canvas as an arrow.
function drawLandingPoint(ctx, width, view) {
    const centre = width / 2;
    const offset = view.target * PIXELS_PER_METRE_GROUND;
    const limit = width / 2 - 110;
    const x = centre + Math.min(Math.max(offset, -limit), limit);
    const away = Math.abs(offset) > limit;

    ctx.strokeStyle = '#7fd1ff';
    ctx.fillStyle = '#7fd1ff';
    ctx.lineWidth = 2;
    ctx.beginPath();
    ctx.moveTo(x, GROUND_Y);
    ctx.lineTo(x, GROUND_Y - 22);
    ctx.stroke();
    ctx.beginPath();
    if (away) {
        const direction = Math.sign(offset);
        ctx.moveTo(x, GROUND_Y - 22);
        ctx.lineTo(x - direction * 12, GROUND_Y - 28);
        ctx.lineTo(x - direction * 12, GROUND_Y - 16);
    } else {
        ctx.moveTo(x, GROUND_Y - 22);
        ctx.lineTo(x + 14, GROUND_Y - 17);
        ctx.lineTo(x, GROUND_Y - 12);
    }
    ctx.closePath();
    ctx.fill();
    ctx.lineWidth = 1;
}

function formatDistance(metres) {
    const absolute = Math.abs(metres);
    return absolute >= 1000 ? `${(absolute / 1000).toFixed(1)} km` : `${absolute.toFixed(0)} m`;
}

function drawReadout(ctx, width, view, t) {
    ctx.fillStyle = '#e6e6e6';
    ctx.font = '14px ui-monospace, monospace';
    ctx.textAlign = 'left';
    ctx.fillText(`T      ${t.toFixed(1)} s`, 16, 26);
    ctx.fillText(`ALT    ${view.altitude.toFixed(view.altitude < 100 ? 1 : 0)} m`, 16, 46);
    ctx.fillText(`V RAD  ${view.vz.toFixed(1)} m/s (${(view.vz * FEET_PER_METRE).toFixed(1)} ft/s)`, 16, 66);
    ctx.fillText(`V HOR  ${view.vx.toFixed(1)} m/s`, 16, 86);
    ctx.fillText(`THRTL  ${(view.throttle * 100).toFixed(0)} %`, 16, 106);
    ctx.fillText(`FUEL   ${(view.fuel * 100).toFixed(0)} %`, 16, 126);
    const side = view.target >= 0 ? 'short' : 'past';
    ctx.fillText(`SITE   ${formatDistance(view.target)} ${side}`, 16, 146);
    if (view.program >= 63) {
        ctx.fillText(`PROG   P${view.program}`, 16, 166);
    }
    if (view.desiredRate !== null) {
        ctx.fillStyle = '#ffffff';
        ctx.fillText(`ROD    ${(view.desiredRate * FEET_PER_METRE).toFixed(1)} ft/s wanted`, 16, 186);
    }

    drawRateBar(ctx, width - 100, view);
    drawBar(ctx, width - 70, 'FUEL', view.fuel, view.fuel > 0.15 ? '#5fbf6a' : '#d95b3d');
    drawBar(ctx, width - 40, 'ALT', altitudeFraction(view.altitude), '#3d8bd9');
}

// The CONTACT LIGHT of the real LM comes on when a probe touches the surface.
function drawContactLight(ctx, width, view) {
    if (view.altitude > CONTACT_LIGHT_ALTITUDE) {
        return;
    }
    ctx.fillStyle = '#ffd23d';
    ctx.fillRect(width / 2 - 100, 60, 200, 34);
    ctx.fillStyle = '#1b1b1b';
    ctx.font = 'bold 20px ui-monospace, monospace';
    ctx.textAlign = 'center';
    ctx.fillText('CONTACT LIGHT', width / 2, 84);
    ctx.textAlign = 'left';
}

export function render(ctx, vehicleView, t) {
    const { width, height } = ctx.canvas;

    ctx.fillStyle = '#000';
    ctx.fillRect(0, 0, width, height);

    drawSurface(ctx, width, height, vehicleView.x);
    drawLandingPoint(ctx, width, vehicleView);
    const landerY = altitudeToY(vehicleView.altitude);
    drawFlame(ctx, width / 2, landerY, vehicleView.throttle);
    drawLander(ctx, width / 2, landerY);
    drawReadout(ctx, width, vehicleView, t);
    drawContactLight(ctx, width, vehicleView);
}
