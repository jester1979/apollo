<?php

declare(strict_types=1);

header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'");
header('X-Content-Type-Options: nosniff');
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Apollo AGC in PHP</title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
    <header class="page-header">
        <h1>Apollo AGC in PHP</h1>
        <p>Choose a chapter of the Apollo 11 mission and start it. Launch: the Saturn V climbs to orbit while the command module computer monitors (P11). Landing: the lunar module brakes with its descent engine and lands by itself; from P64 the arrow keys are the ROD switch, arrow up gives less descent rate, arrow down more, 1 ft/s per press. Ascent: the ascent stage lifts off from the descent stage and flies to lunar orbit (P12): answer the flashing displays with P (PRO).</p>
    </header>
    <main>
        <fieldset class="chooser" id="chooser">
            <legend>Chapter</legend>
            <label><input type="radio" name="chapter" value="launch" checked><span>Launch</span></label>
            <label><input type="radio" name="chapter" value="loi"><span>Lunar orbit insertion</span></label>
            <label><input type="radio" name="chapter" value="landing"><span>Landing</span></label>
            <label><input type="radio" name="chapter" value="ascent"><span>Ascent</span></label>
            <label><input type="radio" name="chapter" value="tei"><span>Trans-Earth injection</span></label>
            <label><input type="radio" name="chapter" value="entry"><span>Entry</span></label>
        </fieldset>
        <div class="controls">
            <button type="button" id="start">Start chapter</button>
            <label class="scenario-switch" id="scenario-label">
                <input type="checkbox" id="scenario">
                <span>Apollo 11 scenario (1201/1202 alarms, Landing only)</span>
            </label>
            <span id="status" role="status" aria-live="polite"></span>
        </div>
        <div class="stage">
            <canvas id="view" width="800" height="500" aria-label="Vehicle view: start a chapter to see it"></canvas>
            <section id="dsky" class="dsky" aria-label="DSKY of the active computer"></section>
        </div>
        <section id="codepanel" class="codepanel" aria-label="Code panel: the running AGC routine and the log"></section>
        <p id="outcome" class="outcome" role="status" aria-live="polite"></p>
        <noscript>This page needs JavaScript.</noscript>
    </main>
    <script type="module" src="/assets/app.js"></script>
</body>
</html>
