// Side view of the command module coming back through the atmosphere. The capsule flies from the entry
// interface at the left to the target flag on the ground at the right, blunt end first. A glow in front of
// the heat shield grows with the drag, a pointer shows the bank angle, and the parachutes open at the end.
// Reads only the fields of the "entry" vehicle view: x, altitude, speed, airSpeed, drag, load, maxLoad,
// pathAngle, bank, range, miss, program, chutes.
// Everything is drawn with Canvas paths. The flown path is remembered here, in the browser, up to
// MAX_TRAIL points; a new run is recognised by the clock going back.

const GROUND_Y = 440;
const TOP_MARGIN = 50;
const MAX_ALTITUDE = 125000; // m, the top of the altitude scale (a little above the 122 km interface)
const LEFT_MARGIN = 60;
const RIGHT_MARGIN = 80;
const GLOW_FULL_DRAG = 8; // g of drag at which the glow is at its largest
const CAPSULE_LENGTH = 54; // px
const MAX_TRAIL = 800;
const M_PER_NM = 1852;

const trail = [];
let lastTime = -1;

function altitudeToY(altitude) {
    const fraction = Math.min(Math.max(altitude, 0), MAX_ALTITUDE) / MAX_ALTITUDE;

    return GROUND_Y - 6 - fraction * (GROUND_Y - 6 - TOP_MARGIN);
}

function mixChannel(from, to, fraction) {
    return Math.round(from + (to - from) * fraction);
}

// The sky is blue near the ground and black in space.
function skyColour(altitude) {
    const dark = Math.min(Math.max(altitude / 70000, 0), 1) ** 0.6;

    return `rgb(${mixChannel(70, 0, dark)}, ${mixChannel(130, 0, dark)}, ${mixChannel(200, 8, dark)})`;
}

function drawSky(ctx, width, altitude) {
    ctx.fillStyle = '#000';
    ctx.fillRect(0, 0, width, GROUND_Y);
    const gradient = ctx.createLinearGradient(0, TOP_MARGIN, 0, GROUND_Y);
    gradient.addColorStop(0, '#000008');
    gradient.addColorStop(0.55, '#06091a');
    gradient.addColorStop(1, skyColour(Math.min(altitude, 30000)));
    ctx.fillStyle = gradient;
    ctx.fillRect(0, 0, width, GROUND_Y);

    // The edge of the atmosphere, the entry interface at 122 km.
    ctx.strokeStyle = 'rgba(160, 190, 230, 0.35)';
    ctx.setLineDash([6, 6]);
    ctx.beginPath();
    ctx.moveTo(0, altitudeToY(122000));
    ctx.lineTo(width, altitudeToY(122000));
    ctx.stroke();
    ctx.setLineDash([]);
    ctx.fillStyle = 'rgba(160, 190, 230, 0.6)';
    ctx.font = '11px ui-monospace, monospace';
    ctx.fillText('ENTRY INTERFACE 122 km', width - 190, altitudeToY(122000) - 5);
}

function drawGround(ctx, width, height, flagX) {
    ctx.fillStyle = '#2f4a2c';
    ctx.fillRect(0, GROUND_Y, width, height - GROUND_Y);
    ctx.strokeStyle = '#5c8a55';
    ctx.beginPath();
    ctx.moveTo(0, GROUND_Y);
    ctx.lineTo(width, GROUND_Y);
    ctx.stroke();

    // The target as a flag.
    ctx.strokeStyle = '#7fd1ff';
    ctx.fillStyle = '#7fd1ff';
    ctx.lineWidth = 2;
    ctx.beginPath();
    ctx.moveTo(flagX, GROUND_Y);
    ctx.lineTo(flagX, GROUND_Y - 24);
    ctx.stroke();
    ctx.beginPath();
    ctx.moveTo(flagX, GROUND_Y - 24);
    ctx.lineTo(flagX + 15, GROUND_Y - 19);
    ctx.lineTo(flagX, GROUND_Y - 14);
    ctx.closePath();
    ctx.fill();
    ctx.lineWidth = 1;
    ctx.font = '11px ui-monospace, monospace';
    ctx.fillText('TARGET', flagX - 20, GROUND_Y + 16);
}

// How strong the glow is, 0..1: a square root keeps the first 0.05 g visible next to the peak of 7 g.
function glowStrength(drag) {
    return Math.sqrt(Math.min(Math.max(drag / GLOW_FULL_DRAG, 0), 1));
}

function drawTrail(ctx) {
    if (trail.length < 2) {
        return;
    }
    ctx.lineWidth = 2;
    for (let index = 1; index < trail.length; index += 1) {
        const previous = trail[index - 1];
        const point = trail[index];
        const heat = point.glow;
        ctx.strokeStyle = `rgba(${mixChannel(210, 255, heat)}, ${mixChannel(220, 120, heat)}, ${mixChannel(235, 30, heat)}, ${0.35 + 0.5 * heat})`;
        ctx.beginPath();
        ctx.moveTo(previous.px, previous.py);
        ctx.lineTo(point.px, point.py);
        ctx.stroke();
    }
    ctx.lineWidth = 1;
}

// The glow and the plasma wake. The shock layer sits in front of the heat shield (to the right in the
// frame of the capsule, which points along its path), the wake trails behind it.
function drawGlow(ctx, strength) {
    if (strength <= 0.01) {
        return;
    }
    const radius = 14 + 70 * strength;
    const gradient = ctx.createRadialGradient(CAPSULE_LENGTH * 0.45, 0, 2, CAPSULE_LENGTH * 0.45, 0, radius);
    gradient.addColorStop(0, `rgba(255, 245, 200, ${0.35 + 0.65 * strength})`);
    gradient.addColorStop(0.35, `rgba(255, 150, 40, ${0.7 * strength})`);
    gradient.addColorStop(1, 'rgba(255, 60, 0, 0)');
    ctx.fillStyle = gradient;
    ctx.beginPath();
    ctx.arc(CAPSULE_LENGTH * 0.45, 0, radius, 0, Math.PI * 2);
    ctx.fill();

    const wake = 20 + 190 * strength;
    const wakeGradient = ctx.createLinearGradient(-CAPSULE_LENGTH * 0.5, 0, -CAPSULE_LENGTH * 0.5 - wake, 0);
    wakeGradient.addColorStop(0, `rgba(255, 190, 90, ${0.55 * strength})`);
    wakeGradient.addColorStop(1, 'rgba(255, 90, 20, 0)');
    ctx.fillStyle = wakeGradient;
    ctx.beginPath();
    ctx.moveTo(-CAPSULE_LENGTH * 0.5, -9 - 8 * strength);
    ctx.lineTo(-CAPSULE_LENGTH * 0.5 - wake, 0);
    ctx.lineTo(-CAPSULE_LENGTH * 0.5, 9 + 8 * strength);
    ctx.closePath();
    ctx.fill();
}

// The command module along its path: a cone with the blunt heat shield in front (to the right).
function drawCapsule(ctx) {
    const half = CAPSULE_LENGTH / 2;
    ctx.fillStyle = '#b9b2a4';
    ctx.strokeStyle = '#6e685c';
    ctx.lineWidth = 1.5;
    ctx.beginPath();
    ctx.moveTo(-half, -4);
    ctx.lineTo(half * 0.55, -half * 0.62);
    ctx.lineTo(half * 0.55, half * 0.62);
    ctx.lineTo(-half, 4);
    ctx.closePath();
    ctx.fill();
    ctx.stroke();

    // heat shield
    ctx.fillStyle = '#5a3f2a';
    ctx.beginPath();
    ctx.ellipse(half * 0.55, 0, 7, half * 0.62, 0, -Math.PI / 2, Math.PI / 2);
    ctx.closePath();
    ctx.fill();
    ctx.stroke();

    // window
    ctx.fillStyle = '#1b2b3a';
    ctx.fillRect(-half * 0.1, -half * 0.28, 8, 6);
    ctx.lineWidth = 1;
}

// The parachutes: three canopies with their lines, the capsule hanging below them.
function drawParachutes(ctx, cx, cy) {
    const canopyY = cy - 105;
    const spread = 46;
    for (const offset of [-spread, 0, spread]) {
        const x = cx + offset;
        ctx.strokeStyle = '#d8d8d8';
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(x - 24, canopyY);
        ctx.lineTo(cx, cy - 14);
        ctx.moveTo(x + 24, canopyY);
        ctx.lineTo(cx, cy - 14);
        ctx.moveTo(x, canopyY - 4);
        ctx.lineTo(cx, cy - 14);
        ctx.stroke();

        ctx.fillStyle = offset === 0 ? '#f2f2f2' : '#e0562f';
        ctx.beginPath();
        ctx.moveTo(x - 24, canopyY);
        ctx.quadraticCurveTo(x - 24, canopyY - 40, x, canopyY - 40);
        ctx.quadraticCurveTo(x + 24, canopyY - 40, x + 24, canopyY);
        ctx.quadraticCurveTo(x + 12, canopyY + 6, x, canopyY + 3);
        ctx.quadraticCurveTo(x - 12, canopyY + 6, x - 24, canopyY);
        ctx.closePath();
        ctx.fill();
        ctx.strokeStyle = '#8c3a22';
        ctx.stroke();
    }
}

// The bank angle: the pointer is up at 0 (lift up) and turns clockwise; at 180 degrees (down) the lift
// points at the ground. Only the cosine of the bank angle changes the path in this 2D model.
function drawBankPointer(ctx, cx, cy, bank) {
    const radius = 42;
    ctx.fillStyle = 'rgba(10, 14, 24, 0.8)';
    ctx.strokeStyle = '#5b6b7a';
    ctx.lineWidth = 1.5;
    ctx.beginPath();
    ctx.arc(cx, cy, radius, 0, Math.PI * 2);
    ctx.fill();
    ctx.stroke();

    ctx.strokeStyle = '#5b6b7a';
    ctx.lineWidth = 1;
    ctx.beginPath();
    for (let degrees = 0; degrees < 360; degrees += 30) {
        const angle = (degrees * Math.PI) / 180;
        ctx.moveTo(cx + Math.sin(angle) * (radius - 6), cy - Math.cos(angle) * (radius - 6));
        ctx.lineTo(cx + Math.sin(angle) * radius, cy - Math.cos(angle) * radius);
    }
    ctx.stroke();

    ctx.fillStyle = '#9aa7b3';
    ctx.font = '10px ui-monospace, monospace';
    ctx.textAlign = 'center';
    ctx.fillText('UP', cx, cy - radius + 17);
    ctx.fillText('DOWN', cx, cy + radius - 9);

    // pointer
    ctx.strokeStyle = '#ffd23d';
    ctx.fillStyle = '#ffd23d';
    ctx.lineWidth = 3;
    ctx.beginPath();
    ctx.moveTo(cx, cy);
    ctx.lineTo(cx + Math.sin(bank) * (radius - 10), cy - Math.cos(bank) * (radius - 10));
    ctx.stroke();
    ctx.beginPath();
    ctx.arc(cx, cy, 3, 0, Math.PI * 2);
    ctx.fill();

    ctx.fillStyle = '#e6e6e6';
    ctx.font = '12px ui-monospace, monospace';
    ctx.fillText(`BANK ${((bank * 180) / Math.PI).toFixed(0)}°`, cx, cy + radius + 18);
    ctx.textAlign = 'left';
    ctx.lineWidth = 1;
}

function drawReadout(ctx, view, t) {
    ctx.fillStyle = '#e6e6e6';
    ctx.font = '14px ui-monospace, monospace';
    ctx.textAlign = 'left';
    ctx.fillText(`T      ${t.toFixed(1)} s`, 16, 26);
    ctx.fillText(`ALT    ${(view.altitude / 1000).toFixed(1)} km`, 16, 46);
    ctx.fillText(`V AIR  ${view.airSpeed.toFixed(0)} m/s`, 16, 66);
    ctx.fillText(`DRAG   ${view.drag.toFixed(2)} g`, 16, 86);
    ctx.fillText(`LOAD   ${view.load.toFixed(2)} g (max ${view.maxLoad.toFixed(2)})`, 16, 106);
    ctx.fillText(`RANGE  ${(view.range / M_PER_NM).toFixed(0)} nm to go`, 16, 126);
    if (view.program >= 67) {
        const side = view.miss >= 0 ? 'long' : 'short';
        ctx.fillText(`MISS   ${(Math.abs(view.miss) / M_PER_NM).toFixed(1)} nm ${side} (predicted)`, 16, 146);
    }
    ctx.fillText(`PROG   P${String(view.program).padStart(2, '0')}`, 16, view.program >= 67 ? 166 : 146);
}

function remember(t, px, py, glow) {
    if (t < lastTime) {
        trail.length = 0;
    }
    lastTime = t;
    trail.push({ px, py, glow });
    if (trail.length > MAX_TRAIL) {
        trail.shift();
    }
}

export function render(ctx, vehicleView, t) {
    const { width, height } = ctx.canvas;
    const view = vehicleView;

    // The ground track from the entry interface to the target fills the width of the canvas.
    const targetDistance = Math.max(view.x + view.range, 1);
    const scale = (width - LEFT_MARGIN - RIGHT_MARGIN) / targetDistance;
    const px = LEFT_MARGIN + view.x * scale;
    const py = altitudeToY(view.altitude);
    const glow = glowStrength(view.drag);
    remember(t, px, py, glow);

    drawSky(ctx, width, view.altitude);
    drawGround(ctx, width, height, LEFT_MARGIN + targetDistance * scale);
    drawTrail(ctx);

    ctx.save();
    ctx.translate(px, py);
    if (view.chutes) {
        // Hanging under the canopies, the heat shield down.
        drawParachutes(ctx, 0, 0);
        ctx.rotate(Math.PI / 2);
        drawCapsule(ctx);
    } else {
        // The heat shield points along the path; the path is nearly flat on this scale.
        ctx.rotate(-view.pathAngle);
        drawGlow(ctx, glow);
        drawCapsule(ctx);
    }
    ctx.restore();

    drawReadout(ctx, view, t);
    drawBankPointer(ctx, width - 70, 78, view.bank);

    if (view.chutes) {
        ctx.fillStyle = '#ffd23d';
        ctx.font = 'bold 20px ui-monospace, monospace';
        ctx.textAlign = 'center';
        ctx.fillText('PARACHUTES', width / 2, 40);
        ctx.textAlign = 'left';
    }
}
