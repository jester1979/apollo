// The interlude between two chapters: a Canvas animation of a few seconds with a short title above it.
// It covers what the port does not fly: the coast to the Moon, the undocking, the time on the surface, the
// rendezvous and the coast home. One scene per chapter that follows; every picture is drawn with Canvas
// paths, without images. The server sends the title and the id of the next chapter, the scene follows
// from that id, so the page holds no copy of the mission order.

export const DURATION_MS = 4500;

const TITLE_COLOUR = '#f2f2f2';
const SPACECRAFT_FILL = '#d8d8d0';
const SPACECRAFT_SHADE = '#9a9a92';
const MOON_COLOUR = '#a8a8a0';
const MOON_CRATER = '#8c8c85';

// A fixed pattern of stars: no randomness, so the sky does not flicker. `depth` decides how fast a star drifts.
const STARS = Array.from({ length: 90 }, (_, index) => ({
    x: (index * 211.7) % 800,
    y: (index * 97.3 + (index % 7) * 31) % 500,
    size: 0.6 + (index % 4) * 0.4,
    depth: 0.2 + (index % 5) * 0.2,
}));

function smooth(value) {
    const clamped = Math.min(Math.max(value, 0), 1);

    return clamped * clamped * (3 - 2 * clamped);
}

// The part of `progress` between `from` and `to`, as 0..1 with easing.
function between(progress, from, to) {
    return smooth((progress - from) / (to - from));
}

function drawStars(ctx, width, height, progress, drift) {
    ctx.fillStyle = '#ffffff';
    for (const star of STARS) {
        const x = (((star.x - progress * drift * star.depth) % width) + width) % width;
        ctx.globalAlpha = 0.35 + star.depth * 0.5;
        ctx.fillRect(x, star.y % height, star.size, star.size);
    }
    ctx.globalAlpha = 1;
}

function drawDisc(ctx, x, y, radius, colour) {
    ctx.fillStyle = colour;
    ctx.beginPath();
    ctx.arc(x, y, radius, 0, Math.PI * 2);
    ctx.fill();
}

function drawEarth(ctx, x, y, radius) {
    drawDisc(ctx, x, y, radius * 1.06, 'rgba(120, 180, 255, 0.25)');
    drawDisc(ctx, x, y, radius, '#2f6db5');
    ctx.save();
    ctx.beginPath();
    ctx.arc(x, y, radius, 0, Math.PI * 2);
    ctx.clip();
    ctx.fillStyle = '#4f9a58'; // two continents
    ctx.beginPath();
    ctx.ellipse(x - radius * 0.3, y - radius * 0.2, radius * 0.38, radius * 0.25, 0.4, 0, Math.PI * 2);
    ctx.ellipse(x + radius * 0.35, y + radius * 0.3, radius * 0.25, radius * 0.4, -0.3, 0, Math.PI * 2);
    ctx.fill();
    ctx.fillStyle = 'rgba(255, 255, 255, 0.55)'; // cloud band
    ctx.beginPath();
    ctx.ellipse(x, y - radius * 0.05, radius * 0.9, radius * 0.07, -0.2, 0, Math.PI * 2);
    ctx.fill();
    ctx.fillStyle = 'rgba(0, 0, 0, 0.35)'; // night side
    ctx.beginPath();
    ctx.arc(x + radius * 0.55, y, radius, 0, Math.PI * 2);
    ctx.fill();
    ctx.restore();
}

function drawMoon(ctx, x, y, radius) {
    drawDisc(ctx, x, y, radius, MOON_COLOUR);
    ctx.save();
    ctx.beginPath();
    ctx.arc(x, y, radius, 0, Math.PI * 2);
    ctx.clip();
    ctx.fillStyle = MOON_CRATER;
    for (const [dx, dy, size] of [[-0.35, -0.3, 0.16], [0.3, -0.45, 0.1], [0.1, 0.2, 0.2], [-0.5, 0.35, 0.12], [0.55, 0.3, 0.09]]) {
        ctx.beginPath();
        ctx.arc(x + dx * radius, y + dy * radius, size * radius, 0, Math.PI * 2);
        ctx.fill();
    }
    ctx.restore();
}

// The command and service module, nose to the right at angle 0; `size` is its length in pixels.
function drawCsm(ctx, x, y, size, angle) {
    ctx.save();
    ctx.translate(x, y);
    ctx.rotate(angle);
    const unit = size / 10;
    ctx.fillStyle = SPACECRAFT_FILL; // service module
    ctx.fillRect(-5 * unit, -1.5 * unit, 7 * unit, 3 * unit);
    ctx.fillStyle = SPACECRAFT_SHADE;
    ctx.fillRect(-5 * unit, -1.5 * unit, 7 * unit, 0.7 * unit);
    ctx.fillStyle = SPACECRAFT_FILL; // command module cone
    ctx.beginPath();
    ctx.moveTo(2 * unit, -1.5 * unit);
    ctx.lineTo(5 * unit, -0.35 * unit);
    ctx.lineTo(5 * unit, 0.35 * unit);
    ctx.lineTo(2 * unit, 1.5 * unit);
    ctx.closePath();
    ctx.fill();
    ctx.fillStyle = '#5c5c56'; // engine bell
    ctx.beginPath();
    ctx.moveTo(-5 * unit, -0.5 * unit);
    ctx.lineTo(-6.6 * unit, -1.1 * unit);
    ctx.lineTo(-6.6 * unit, 1.1 * unit);
    ctx.lineTo(-5 * unit, 0.5 * unit);
    ctx.closePath();
    ctx.fill();
    ctx.restore();
}

// The lunar module with its landing legs: `size` is its width in pixels, `legs` draws the descent stage.
function drawLm(ctx, x, y, size, legs) {
    ctx.save();
    ctx.translate(x, y);
    const unit = size / 10;
    if (legs) {
        ctx.strokeStyle = SPACECRAFT_SHADE;
        ctx.lineWidth = Math.max(1, unit * 0.35);
        ctx.beginPath();
        for (const side of [-1, 1]) {
            ctx.moveTo(side * 2 * unit, 1.5 * unit);
            ctx.lineTo(side * 4.8 * unit, 4.4 * unit);
            ctx.moveTo(side * 4.8 * unit, 4.4 * unit);
            ctx.lineTo(side * 5.8 * unit, 4.4 * unit);
        }
        ctx.stroke();
        ctx.fillStyle = '#c9a23a'; // gold foil of the descent stage
        ctx.fillRect(-3 * unit, 0.4 * unit, 6 * unit, 2.2 * unit);
    }
    ctx.fillStyle = SPACECRAFT_FILL; // ascent stage
    ctx.beginPath();
    ctx.moveTo(-2.4 * unit, 0.4 * unit);
    ctx.lineTo(-3 * unit, -1.4 * unit);
    ctx.lineTo(-1.6 * unit, -3 * unit);
    ctx.lineTo(1.6 * unit, -3 * unit);
    ctx.lineTo(3 * unit, -1.4 * unit);
    ctx.lineTo(2.4 * unit, 0.4 * unit);
    ctx.closePath();
    ctx.fill();
    ctx.fillStyle = '#2b3540'; // window
    ctx.beginPath();
    ctx.moveTo(-1.4 * unit, -1.6 * unit);
    ctx.lineTo(-0.5 * unit, -2.2 * unit);
    ctx.lineTo(-0.3 * unit, -1.1 * unit);
    ctx.closePath();
    ctx.fill();
    ctx.restore();
}

// Launch to LOI: the stack leaves the Earth and coasts towards the Moon.
function drawCoastToMoon(ctx, width, height, progress) {
    drawEarth(ctx, 110, height - 120, 90);
    drawMoon(ctx, width - 110, 190, 50);
    const t = between(progress, 0.05, 0.95);
    const x = 190 + t * (width - 400);
    const y = height - 190 - Math.sin(t * Math.PI) * 90 - t * 60;
    ctx.strokeStyle = 'rgba(255, 255, 255, 0.35)';
    ctx.setLineDash([4, 8]);
    ctx.beginPath();
    for (let step = 0; step <= 1.0001; step += 0.02) {
        const px = 190 + step * (width - 400);
        const py = height - 190 - Math.sin(step * Math.PI) * 90 - step * 60;
        if (step === 0) {
            ctx.moveTo(px, py);
        } else {
            ctx.lineTo(px, py);
        }
    }
    ctx.stroke();
    ctx.setLineDash([]);
    drawCsm(ctx, x, y, 44, -0.35 + t * 0.6);
}

// LOI to Landing: the lunar module undocks from the command module and drops into a descent orbit.
function drawUndocking(ctx, width, height, progress) {
    drawMoon(ctx, width / 2, height + 330, 520);
    const csmX = 250 + progress * 40;
    const csmY = 250;
    const apart = between(progress, 0.3, 0.9);
    drawCsm(ctx, csmX, csmY, 100, 0);
    drawLm(ctx, csmX + 82 + apart * 150, csmY - 4 + apart * 70, 50, true);
    if (apart > 0) {
        ctx.strokeStyle = 'rgba(255, 255, 255, 0.35)';
        ctx.setLineDash([3, 7]);
        ctx.beginPath();
        ctx.moveTo(csmX + 82, csmY - 4);
        ctx.lineTo(csmX + 82 + apart * 150, csmY - 4 + apart * 70);
        ctx.stroke();
        ctx.setLineDash([]);
    }
}

// Landing to Ascent: the lunar module on the surface, with the flag and the Earth in the sky.
function drawSurface(ctx, width, height, progress) {
    const horizon = height - 150;
    drawEarth(ctx, width - 170, 150, 34);
    ctx.fillStyle = '#6f6f69';
    ctx.beginPath();
    ctx.moveTo(0, horizon + 10);
    ctx.quadraticCurveTo(width * 0.3, horizon - 14, width * 0.55, horizon + 4);
    ctx.quadraticCurveTo(width * 0.8, horizon + 14, width, horizon - 6);
    ctx.lineTo(width, height);
    ctx.lineTo(0, height);
    ctx.closePath();
    ctx.fill();
    ctx.fillStyle = MOON_CRATER;
    for (const [x, y, w, h] of [[120, horizon + 60, 90, 14], [560, horizon + 80, 120, 16], [330, horizon + 108, 70, 10]]) {
        ctx.beginPath();
        ctx.ellipse(x, y, w, h, 0, 0, Math.PI * 2);
        ctx.fill();
    }
    const settle = between(progress, 0, 0.25);
    drawLm(ctx, 330, horizon - 12 + (1 - settle) * -40, 120, true);
    // The flag: a pole with a cross rod that holds the cloth out, because there is no air to lift it.
    const flagX = 520;
    ctx.strokeStyle = '#e6e6e6';
    ctx.lineWidth = 2;
    ctx.beginPath();
    ctx.moveTo(flagX, horizon + 40);
    ctx.lineTo(flagX, horizon - 62);
    ctx.moveTo(flagX, horizon - 60);
    ctx.lineTo(flagX + 62, horizon - 60);
    ctx.stroke();
    const flagReveal = between(progress, 0.3, 0.6);
    ctx.fillStyle = '#d9d9d9';
    ctx.fillRect(flagX, horizon - 58, 60 * flagReveal, 38);
    ctx.fillStyle = '#c0392b';
    for (let stripe = 0; stripe < 4; stripe += 1) {
        ctx.fillRect(flagX, horizon - 58 + stripe * 10, 60 * flagReveal, 4);
    }
    ctx.fillStyle = '#243b8a';
    ctx.fillRect(flagX, horizon - 58, 24 * flagReveal, 18);
    // Footprints appear one by one.
    ctx.fillStyle = 'rgba(40, 40, 38, 0.55)';
    const prints = Math.floor(between(progress, 0.4, 0.95) * 8);
    for (let index = 0; index < prints; index += 1) {
        ctx.beginPath();
        ctx.ellipse(380 + index * 17, horizon + 52 + (index % 2) * 7, 5, 2.5, 0, 0, Math.PI * 2);
        ctx.fill();
    }
}

// Ascent to TEI: the ascent stage closes in on the command module and docks.
function drawRendezvous(ctx, width, height, progress) {
    drawMoon(ctx, 150, height + 40, 230);
    const csmX = 330;
    const csmY = 230;
    const gap = 1 - between(progress, 0.1, 0.8);
    drawCsm(ctx, csmX, csmY, 110, 0);
    const lmX = csmX + 88 + gap * 260;
    const lmY = csmY - 4 - gap * 70;
    drawLm(ctx, lmX, lmY, 56, false);
    if (gap > 0.01) {
        ctx.strokeStyle = 'rgba(255, 255, 255, 0.3)';
        ctx.setLineDash([3, 7]);
        ctx.beginPath();
        ctx.moveTo(lmX, lmY);
        ctx.lineTo(csmX + 88, csmY - 4);
        ctx.stroke();
        ctx.setLineDash([]);
    } else {
        ctx.fillStyle = '#6be38c';
        ctx.font = '16px ui-monospace, monospace';
        ctx.textAlign = 'center';
        ctx.fillText('DOCKED', csmX + 110, csmY + 76);
    }
}

// TEI to Entry: the command module coasts towards the growing Earth.
function drawCoastHome(ctx, width, height, progress) {
    const grow = between(progress, 0, 1);
    drawEarth(ctx, width - 200 + grow * 60, height / 2 + 40, 70 + grow * 190);
    const x = 120 + grow * (width - 520);
    drawCsm(ctx, x, 150 + grow * 130, 46, 0.3 + grow * 0.35);
}

const SCENES = {
    loi: drawCoastToMoon,
    landing: drawUndocking,
    ascent: drawSurface,
    tei: drawRendezvous,
    entry: drawCoastHome,
};

function drawTitle(ctx, width, title, progress) {
    const alpha = between(progress, 0, 0.18) * (1 - between(progress, 0.92, 1) * 0.5);
    ctx.globalAlpha = alpha;
    ctx.fillStyle = TITLE_COLOUR;
    ctx.font = '600 30px system-ui, sans-serif';
    ctx.textAlign = 'center';
    ctx.fillText(title, width / 2, 62);
    ctx.strokeStyle = 'rgba(255, 255, 255, 0.4)';
    ctx.lineWidth = 1;
    ctx.beginPath();
    ctx.moveTo(width / 2 - 80, 78);
    ctx.lineTo(width / 2 + 80, 78);
    ctx.stroke();
    ctx.globalAlpha = 1;
    ctx.textAlign = 'left';
}

// Draws one frame. `next` is the offer of the server ({chapter, interlude}); `progress` runs from 0 to 1.
export function render(ctx, next, progress) {
    const { width, height } = ctx.canvas;
    ctx.fillStyle = '#000';
    ctx.fillRect(0, 0, width, height);
    drawStars(ctx, width, height, progress, 120);
    const scene = SCENES[next.chapter];
    if (scene) {
        scene(ctx, width, height, progress);
    }
    drawTitle(ctx, width, next.interlude, progress);
    // A thin bar along the bottom shows how much of the interlude is left.
    ctx.fillStyle = 'rgba(255, 255, 255, 0.25)';
    ctx.fillRect(0, height - 3, width * progress, 3);
}
