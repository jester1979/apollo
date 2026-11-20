// Top view of the Moon with the trajectory of the vehicle, for the burns of P40 (lunar orbit insertion and
// trans-Earth injection). Reads only the fields of the "orbit" vehicle view: x, y (m, centre of the Moon in
// the origin), bodyRadius, extent (the radius at the edge of the view), trail and prediction (lists of
// [x, y] in km), thrustAngle, throttle, fuel, bound, eccentricity, perilune, apolune, vg, altitude, speed, phase.
// The flown path is a solid line, the predicted path a dashed one: it is an open hyperbola until the
// burn has made the orbit bound, then a closed ellipse. Everything is drawn with Canvas paths.

const TRAIL_COLOUR = '#e8eef5';
const PREDICTION_COLOUR = '#5cc8ff';
const VEHICLE_LENGTH = 16; // px
const FLAME_LENGTH = 46; // px at full throttle
const DASH = [7, 6];
const KM = 1000;

// Pixels per metre: the radius `extent` ends at the nearest edge of the canvas, with a margin.
function scaleFor(width, height, extent) {
    return (Math.min(width, height) / 2 - 12) / extent;
}

function toScreen(width, height, scale, xMetres, yMetres) {
    return [width / 2 + xMetres * scale, height / 2 - yMetres * scale];
}

// A fixed pattern of stars, so the sky does not flicker.
const STARS = Array.from({ length: 90 }, (_, index) => ({
    x: (index * 211.7) % 800,
    y: (index * 97.3) % 500,
    size: index % 7 === 0 ? 2 : 1,
}));

function drawSky(ctx, width, height) {
    ctx.fillStyle = '#05070d';
    ctx.fillRect(0, 0, width, height);
    ctx.fillStyle = 'rgba(255, 255, 255, 0.7)';
    for (const star of STARS) {
        ctx.fillRect((star.x * width) / 800, (star.y * height) / 500, star.size, star.size);
    }
}

// The Moon seen from above: a grey disc with a few craters.
function drawMoon(ctx, cx, cy, radius) {
    const gradient = ctx.createRadialGradient(cx - radius * 0.3, cy - radius * 0.3, radius * 0.1, cx, cy, radius);
    gradient.addColorStop(0, '#c9c9c4');
    gradient.addColorStop(1, '#6f6f6b');
    ctx.fillStyle = gradient;
    ctx.beginPath();
    ctx.arc(cx, cy, radius, 0, Math.PI * 2);
    ctx.fill();

    ctx.fillStyle = 'rgba(70, 70, 68, 0.45)';
    const craters = [[-0.35, -0.2, 0.17], [0.3, -0.4, 0.11], [0.15, 0.35, 0.2], [-0.5, 0.3, 0.09], [0.55, 0.05, 0.08]];
    for (const [dx, dy, size] of craters) {
        ctx.beginPath();
        ctx.arc(cx + dx * radius, cy + dy * radius, size * radius, 0, Math.PI * 2);
        ctx.fill();
    }
}

function strokePath(ctx, width, height, scale, points, colour, dash) {
    if (!Array.isArray(points) || points.length < 2) {
        return;
    }
    ctx.save();
    ctx.strokeStyle = colour;
    ctx.lineWidth = dash.length > 0 ? 1.5 : 2;
    ctx.setLineDash(dash);
    ctx.beginPath();
    points.forEach(([xKm, yKm], index) => {
        const [px, py] = toScreen(width, height, scale, xKm * KM, yKm * KM);
        if (index === 0) {
            ctx.moveTo(px, py);
        } else {
            ctx.lineTo(px, py);
        }
    });
    ctx.stroke();
    ctx.restore();
}

// The exhaust flame points away from the thrust; its length follows the throttle.
function drawFlame(ctx, throttle, flicker) {
    if (throttle <= 0) {
        return;
    }
    const length = FLAME_LENGTH * throttle * flicker;
    ctx.fillStyle = '#ff8a1f';
    ctx.beginPath();
    ctx.moveTo(-VEHICLE_LENGTH / 2, -5);
    ctx.lineTo(-VEHICLE_LENGTH / 2 - length, 0);
    ctx.lineTo(-VEHICLE_LENGTH / 2, 5);
    ctx.closePath();
    ctx.fill();
    ctx.fillStyle = '#fff3b0';
    ctx.beginPath();
    ctx.moveTo(-VEHICLE_LENGTH / 2, -2.5);
    ctx.lineTo(-VEHICLE_LENGTH / 2 - length * 0.6, 0);
    ctx.lineTo(-VEHICLE_LENGTH / 2, 2.5);
    ctx.closePath();
    ctx.fill();
}

// The command and service module: a cone on a cylinder with the engine bell at the tail. The vehicle is
// drawn along +x of its own frame; the thrust pushes it forward, so the nose points along the thrust.
function drawVehicle(ctx, px, py, angle, throttle, t) {
    ctx.save();
    ctx.translate(px, py);
    ctx.rotate(-angle);
    drawFlame(ctx, throttle, 0.88 + 0.12 * Math.sin(t * 40));

    ctx.fillStyle = '#d8d8d8';
    ctx.fillRect(-VEHICLE_LENGTH / 2, -4, VEHICLE_LENGTH * 0.65, 8); // service module
    ctx.fillStyle = '#eeeeee';
    ctx.beginPath(); // command module
    ctx.moveTo(VEHICLE_LENGTH / 2 + 3, 0);
    ctx.lineTo(VEHICLE_LENGTH / 2 - VEHICLE_LENGTH * 0.35, -4);
    ctx.lineTo(VEHICLE_LENGTH / 2 - VEHICLE_LENGTH * 0.35, 4);
    ctx.closePath();
    ctx.fill();
    ctx.fillStyle = '#9a9a9a';
    ctx.beginPath(); // engine bell
    ctx.moveTo(-VEHICLE_LENGTH / 2, -2);
    ctx.lineTo(-VEHICLE_LENGTH / 2 - 4, -3.5);
    ctx.lineTo(-VEHICLE_LENGTH / 2 - 4, 3.5);
    ctx.lineTo(-VEHICLE_LENGTH / 2, 2);
    ctx.closePath();
    ctx.fill();
    ctx.restore();
}

function formatKm(metres) {
    return metres === null || metres === undefined ? '-' : `${(metres / KM).toFixed(0)} km`;
}

function drawReadout(ctx, width, view, t) {
    ctx.fillStyle = '#f2f2f2';
    ctx.font = '14px ui-monospace, monospace';
    ctx.textAlign = 'left';
    const rows = [
        `T      ${t.toFixed(1)} s`,
        `ALT    ${formatKm(view.altitude)}`,
        `SPEED  ${view.speed.toFixed(0)} m/s`,
        `VG     ${view.vg.toFixed(0)} m/s`,
        `ORBIT  ${view.bound ? 'bound' : 'open'}`,
        `PERI   ${formatKm(view.perilune)}`,
        `APO    ${formatKm(view.apolune)}`,
    ];
    rows.forEach((row, index) => ctx.fillText(row, 14, 26 + index * 20));

    ctx.textAlign = 'right';
    ctx.fillStyle = TRAIL_COLOUR;
    ctx.fillText('— flown', width - 14, 26);
    ctx.fillStyle = PREDICTION_COLOUR;
    ctx.fillText('- - predicted', width - 14, 46);
    ctx.textAlign = 'left';
}

export function render(ctx, vehicleView, t) {
    const { width, height } = ctx.canvas;
    const scale = scaleFor(width, height, vehicleView.extent);
    const [cx, cy] = toScreen(width, height, scale, 0, 0);

    drawSky(ctx, width, height);

    // Everything beyond the canvas is clipped; the open orbit runs out of the picture.
    ctx.save();
    ctx.beginPath();
    ctx.rect(0, 0, width, height);
    ctx.clip();
    drawMoon(ctx, cx, cy, vehicleView.bodyRadius * scale);
    strokePath(ctx, width, height, scale, vehicleView.prediction, PREDICTION_COLOUR, DASH);
    strokePath(ctx, width, height, scale, vehicleView.trail, TRAIL_COLOUR, []);
    ctx.restore();

    const [px, py] = toScreen(width, height, scale, vehicleView.x, vehicleView.y);
    drawVehicle(ctx, px, py, vehicleView.thrustAngle, vehicleView.throttle, t);

    drawReadout(ctx, width, vehicleView, t);
}
