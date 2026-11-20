// Side view of the lunar module above the lunar surface.
// Reads only the fields of the "landing" vehicle view: altitude, vx, vz, x, throttle, fuel, target.

const GROUND_Y = 440;
const TOP_MARGIN = 40;
const MAX_ALTITUDE = 16000; // m, a little above the PDI altitude
const PIXELS_PER_METRE_GROUND = 1 / 40; // surface scrolls 1 px per 40 m

function altitudeToY(altitude) {
    const clamped = Math.min(Math.max(altitude, 0), MAX_ALTITUDE);
    return GROUND_Y - 30 - (clamped / MAX_ALTITUDE) * (GROUND_Y - 30 - TOP_MARGIN);
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

function drawReadout(ctx, width, view, t) {
    ctx.fillStyle = '#e6e6e6';
    ctx.font = '14px ui-monospace, monospace';
    ctx.textAlign = 'left';
    ctx.fillText(`T      ${t.toFixed(1)} s`, 16, 26);
    ctx.fillText(`ALT    ${view.altitude.toFixed(0)} m`, 16, 46);
    ctx.fillText(`V RAD  ${view.vz.toFixed(1)} m/s`, 16, 66);
    ctx.fillText(`V HOR  ${view.vx.toFixed(1)} m/s`, 16, 86);
    ctx.fillText(`FUEL   ${(view.fuel * 100).toFixed(0)} %`, 16, 106);

    // altitude bar
    const barX = width - 40;
    const barTop = TOP_MARGIN;
    const barHeight = GROUND_Y - TOP_MARGIN - 30;
    const fraction = Math.min(Math.max(view.altitude / MAX_ALTITUDE, 0), 1);
    ctx.strokeStyle = '#5b6b7a';
    ctx.strokeRect(barX, barTop, 16, barHeight);
    ctx.fillStyle = '#3d8bd9';
    ctx.fillRect(barX, barTop + barHeight * (1 - fraction), 16, barHeight * fraction);
}

export function render(ctx, vehicleView, t) {
    const { width, height } = ctx.canvas;

    ctx.fillStyle = '#000';
    ctx.fillRect(0, 0, width, height);

    drawSurface(ctx, width, height, vehicleView.x);
    drawLander(ctx, width / 2, altitudeToY(vehicleView.altitude));
    drawReadout(ctx, width, vehicleView, t);
}
