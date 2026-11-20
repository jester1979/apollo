// Side view of the Saturn V rising and pitching over, with the S-IC and the S-II dropping away.
// Reads only the fields of the "launch" vehicle view: altitude, x, speed, vz, pitch, throttle, stage,
// propellant, sicSeparated, siiSeparated, perigee, program.
// The silhouettes are drawn with Canvas paths; the rocket is drawn from its nose down, in a frame that
// turns with its pitch, and the camera follows it.

const GROUND_Y = 450;
const TOP_MARGIN = 70;
const MAX_ALTITUDE = 200000; // m, the top of the altitude scale
const ALTITUDE_KNEE = 100; // m, the log scale starts to bend here, so the first metres are visible
const PIXELS_PER_METRE = 1.3; // the stack is about 110 m long
const CENTRE_X = 400;

// Parts of the stack in metres, from the nose to the tail, with the stage each belongs to
// (1 = S-IC, 2 = S-II, 3 = S-IVB and what it carries).
const TOWER_LENGTH = 10;
const CAPSULE_LENGTH = 11;
const ADAPTER_LENGTH = 8;
const SIVB_LENGTH = 19;
const SII_INTERSTAGE = 3;
const SII_LENGTH = 25;
const SIC_INTERSTAGE = 5;
const SIC_LENGTH = 38;
const UPPER_LENGTH = TOWER_LENGTH + CAPSULE_LENGTH + ADAPTER_LENGTH + SIVB_LENGTH;
const SII_BLOCK = SII_INTERSTAGE + SII_LENGTH;
const SIC_BLOCK = SIC_INTERSTAGE + SIC_LENGTH;
const FULL_LENGTH = (UPPER_LENGTH + SII_BLOCK + SIC_BLOCK) * PIXELS_PER_METRE;

const SEPARATION_VISIBLE = 40; // s, as long as the server reports a dropped stage

// Log scale: every decade of altitude gets the same height, so the climb shows all the way to orbit.
function altitudeFraction(altitude) {
    const clamped = Math.min(Math.max(altitude, 0), MAX_ALTITUDE);

    return Math.log10(1 + clamped / ALTITUDE_KNEE) / Math.log10(1 + MAX_ALTITUDE / ALTITUDE_KNEE);
}

// The tail of the full stack: it sits on the pad at the start and rises with the altitude.
function tailY(altitude) {
    const low = GROUND_Y - 8;
    const high = TOP_MARGIN + FULL_LENGTH;

    return low - altitudeFraction(altitude) * (low - high);
}

function mixChannel(from, to, fraction) {
    return Math.round(from + (to - from) * fraction);
}

// The sky is blue at the ground and black in space; the brightness goes down with the altitude.
function skyColour(altitude) {
    const fraction = Math.min(Math.max(altitude / 80000, 0), 1);
    const dark = fraction ** 0.6;

    return `rgb(${mixChannel(70, 0, dark)}, ${mixChannel(130, 0, dark)}, ${mixChannel(200, 8, dark)})`;
}

// A fixed pattern of stars (no randomness, so the sky does not flicker), visible above about 30 km.
const STARS = Array.from({ length: 70 }, (_, index) => ({
    x: (index * 137.5) % 800,
    y: (index * 71.3) % 430,
    size: index % 5 === 0 ? 2 : 1,
}));

function drawSky(ctx, width, height, altitude) {
    ctx.fillStyle = skyColour(altitude);
    ctx.fillRect(0, 0, width, height);

    const visibility = Math.min(Math.max((altitude - 30000) / 70000, 0), 1);
    if (visibility > 0) {
        ctx.fillStyle = `rgba(255, 255, 255, ${visibility.toFixed(2)})`;
        for (const star of STARS) {
            ctx.fillRect(star.x, star.y, star.size, star.size);
        }
    }
}

function drawGround(ctx, width, height) {
    ctx.fillStyle = '#3f5a38';
    ctx.fillRect(0, GROUND_Y, width, height - GROUND_Y);
    ctx.strokeStyle = '#2a3d26';
    ctx.beginPath();
    ctx.moveTo(0, GROUND_Y);
    ctx.lineTo(width, GROUND_Y);
    ctx.stroke();

    // The launch pad with its service tower, to the left of the rocket.
    ctx.fillStyle = '#5a5a5a';
    ctx.fillRect(CENTRE_X - 60, GROUND_Y - 6, 120, 6);
    ctx.fillStyle = '#7a7a7a';
    ctx.fillRect(CENTRE_X - 48, GROUND_Y - 100, 8, 94);
    ctx.fillRect(CENTRE_X - 48, GROUND_Y - 100, 22, 4);
    ctx.fillRect(CENTRE_X - 48, GROUND_Y - 60, 22, 3);
}

// Altitude marks on the left side of the canvas.
function drawAltitudeScale(ctx, altitude) {
    const marks = [0, 100, 1000, 10000, 100000];
    ctx.font = '11px ui-monospace, monospace';
    ctx.textAlign = 'left';
    for (const mark of marks) {
        const y = GROUND_Y - 8 - altitudeFraction(mark) * (GROUND_Y - 8 - (TOP_MARGIN + FULL_LENGTH)) - FULL_LENGTH / 2;
        const label = mark >= 1000 ? `${mark / 1000} km` : `${mark} m`;
        ctx.strokeStyle = altitude >= mark ? '#8fb4d9' : '#4b6277';
        ctx.fillStyle = altitude >= mark ? '#cfe3f5' : '#7f95a8';
        ctx.beginPath();
        ctx.moveTo(8, y);
        ctx.lineTo(20, y);
        ctx.stroke();
        ctx.fillText(label, 26, y + 4);
    }
}

function rect(ctx, x, y, width, height, fill) {
    ctx.fillStyle = fill;
    ctx.fillRect(x, y, width, height);
}

// The launch escape tower and the command module with the service module, from the nose down.
function drawPayload(ctx, top) {
    const scale = PIXELS_PER_METRE;
    const x = 0;

    // escape tower
    ctx.strokeStyle = '#d8d8d8';
    ctx.lineWidth = 1.5;
    ctx.beginPath();
    ctx.moveTo(x, top);
    ctx.lineTo(x, top + TOWER_LENGTH * scale);
    ctx.stroke();
    rect(ctx, x - 2, top + 3 * scale, 4, 4 * scale, '#e8e8e8');

    // command module, a cone
    const capsuleTop = top + TOWER_LENGTH * scale;
    ctx.fillStyle = '#c8c8c8';
    ctx.beginPath();
    ctx.moveTo(x, capsuleTop);
    ctx.lineTo(x + 5, capsuleTop + 5 * scale);
    ctx.lineTo(x + 5, capsuleTop + CAPSULE_LENGTH * scale);
    ctx.lineTo(x - 5, capsuleTop + CAPSULE_LENGTH * scale);
    ctx.lineTo(x - 5, capsuleTop + 5 * scale);
    ctx.closePath();
    ctx.fill();

    // spacecraft lunar module adapter, widening to the rocket
    const adapterTop = capsuleTop + CAPSULE_LENGTH * scale;
    ctx.fillStyle = '#e4e4e4';
    ctx.beginPath();
    ctx.moveTo(x - 5, adapterTop);
    ctx.lineTo(x + 5, adapterTop);
    ctx.lineTo(x + 6.5, adapterTop + ADAPTER_LENGTH * scale);
    ctx.lineTo(x - 6.5, adapterTop + ADAPTER_LENGTH * scale);
    ctx.closePath();
    ctx.fill();
}

// The S-IVB: a white tank with a black band.
function drawSivb(ctx, top) {
    const length = SIVB_LENGTH * PIXELS_PER_METRE;
    rect(ctx, -6.5, top, 13, length, '#f0f0f0');
    rect(ctx, -6.5, top + length * 0.35, 13, length * 0.18, '#202020');
    ctx.fillStyle = '#d4d4d4';
    ctx.beginPath(); // engine bell
    ctx.moveTo(-2, top + length);
    ctx.lineTo(2, top + length);
    ctx.lineTo(3, top + length + 3);
    ctx.lineTo(-3, top + length + 3);
    ctx.closePath();
    ctx.fill();
}

// The S-II with the interstage above it: white, with a black interstage band.
function drawSii(ctx, top) {
    const scale = PIXELS_PER_METRE;
    rect(ctx, -6.5, top, 13, SII_INTERSTAGE * scale, '#1c1c1c');
    rect(ctx, -6.5, top + SII_INTERSTAGE * scale, 13, SII_LENGTH * scale, '#f4f4f4');
    rect(ctx, -6.5, top + (SII_INTERSTAGE + SII_LENGTH * 0.5) * scale, 13, 2, '#9a9a9a');
}

// The S-IC with the interstage above it: black and white stripes and the four fins.
function drawSic(ctx, top) {
    const scale = PIXELS_PER_METRE;
    const length = SIC_LENGTH * scale;
    const body = top + SIC_INTERSTAGE * scale;
    rect(ctx, -6.5, top, 13, SIC_INTERSTAGE * scale, '#e8e8e8');
    rect(ctx, -6.5, body, 13, length, '#f4f4f4');
    rect(ctx, -6.5, body + length * 0.18, 13, length * 0.14, '#1c1c1c');
    rect(ctx, -6.5, body + length * 0.5, 13, length * 0.14, '#1c1c1c');
    ctx.fillStyle = '#2b2b2b';
    ctx.beginPath(); // the two fins seen from the side
    ctx.moveTo(-6.5, body + length * 0.7);
    ctx.lineTo(-14, body + length + 4);
    ctx.lineTo(-6.5, body + length);
    ctx.closePath();
    ctx.moveTo(6.5, body + length * 0.7);
    ctx.lineTo(14, body + length + 4);
    ctx.lineTo(6.5, body + length);
    ctx.closePath();
    ctx.fill();
    rect(ctx, -5, body + length, 10, 3, '#3a3a3a'); // engine skirt
}

// The exhaust flame at the tail of the burning stage; the size follows the stage.
function drawFlame(ctx, y, view) {
    if (view.throttle <= 0) {
        return;
    }
    const sizes = { 1: [44, 9], 2: [26, 6], 3: [20, 3.5] };
    const [length, half] = sizes[view.stage] ?? sizes[3];
    const flicker = 0.9 + 0.1 * Math.sin(view.altitude * 0.37 + view.speed * 0.11);

    ctx.fillStyle = '#ff8a1f';
    ctx.beginPath();
    ctx.moveTo(-half, y);
    ctx.lineTo(0, y + length * flicker);
    ctx.lineTo(half, y);
    ctx.closePath();
    ctx.fill();
    ctx.fillStyle = '#fff3b0';
    ctx.beginPath();
    ctx.moveTo(-half / 2, y);
    ctx.lineTo(0, y + length * 0.6 * flicker);
    ctx.lineTo(half / 2, y);
    ctx.closePath();
    ctx.fill();
}

// A spent stage falls away behind the stack: it drops back along the axis, drifts sideways and tumbles
// a little, then fades. `top` is where the stage sat on the stack; `draw` paints it with its top at y = 0.
function drawDroppedStage(ctx, top, seconds, draw, side) {
    if (seconds === null || seconds === undefined) {
        return;
    }
    const gap = 4 + 10 * seconds + 1.5 * seconds * seconds;
    const fade = Math.min(Math.max((SEPARATION_VISIBLE - seconds) / 12, 0), 1);

    ctx.save();
    ctx.globalAlpha = fade;
    ctx.translate(side * (1.5 * seconds), top + gap);
    ctx.rotate(side * 0.035 * seconds * seconds / (1 + seconds * 0.3));
    draw(ctx, 0);
    // a short burst of light at the moment of separation
    if (seconds < 1.2) {
        ctx.globalAlpha = 1 - seconds / 1.2;
        ctx.fillStyle = '#fff6c8';
        ctx.beginPath();
        ctx.arc(0, -gap + 2, 12, 0, Math.PI * 2);
        ctx.fill();
    }
    ctx.restore();
}

// The whole rocket in its own frame: the origin is the nose, y goes down the axis towards the tail.
function drawRocket(ctx, view) {
    const scale = PIXELS_PER_METRE;
    const upper = UPPER_LENGTH * scale;
    const siiBlock = SII_BLOCK * scale;
    const stage = view.stage;

    drawPayload(ctx, 0);
    drawSivb(ctx, (TOWER_LENGTH + CAPSULE_LENGTH + ADAPTER_LENGTH) * scale);

    let tail = upper; // y of the tail of the stack that burns now, in the frame of the nose
    if (stage <= 2) {
        drawSii(ctx, upper);
        tail = upper + siiBlock;
    }
    if (stage <= 1) {
        drawSic(ctx, upper + siiBlock);
        tail = upper + siiBlock + SIC_BLOCK * scale;
    }
    // The tail of the S-IVB is a little longer than its tank, for the engine bell.
    drawFlame(ctx, tail + (stage === 3 ? 3 : 0), view);

    // Dropped stages fall away from the place they had on the stack: the S-II below the S-IVB, the S-IC below the S-II.
    drawDroppedStage(ctx, upper, view.siiSeparated, drawSii, 1);
    drawDroppedStage(ctx, upper + siiBlock, view.sicSeparated, drawSic, -1);
}

function drawReadout(ctx, width, view, t) {
    ctx.fillStyle = '#f2f2f2';
    ctx.font = '14px ui-monospace, monospace';
    ctx.textAlign = 'left';
    const rows = [
        `T      ${t.toFixed(1)} s`,
        `ALT    ${(view.altitude / 1000).toFixed(1)} km`,
        `VI     ${view.speed.toFixed(0)} m/s`,
        `HDOT   ${view.vz.toFixed(0)} m/s`,
        `STAGE  ${['', 'S-IC', 'S-II', 'S-IVB'][view.stage] ?? ''}`,
        `PROP   ${(view.propellant * 100).toFixed(0)} %`,
        `PERIG  ${(view.perigee / 1000).toFixed(0)} km`,
    ];
    rows.forEach((row, index) => ctx.fillText(row, width - 200, 26 + index * 20));
}

export function render(ctx, vehicleView, t) {
    const { width, height } = ctx.canvas;
    const altitude = vehicleView.altitude;

    drawSky(ctx, width, height, altitude);
    drawGround(ctx, width, height);
    drawAltitudeScale(ctx, altitude);

    // The nose is anchored: when a stage drops the rest stays where it is, and the stage falls away.
    const nose = tailY(altitude) - FULL_LENGTH;
    // The angle from the vertical: 0 at a pitch of 90 degrees above the horizon, clockwise towards the east.
    const tilt = Math.PI / 2 - vehicleView.pitch;
    ctx.save();
    ctx.translate(CENTRE_X, nose);
    ctx.rotate(tilt);
    drawRocket(ctx, vehicleView);
    ctx.restore();

    drawReadout(ctx, width, vehicleView, t);
}
