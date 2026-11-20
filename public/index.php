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
        <p>Chapter: Landing. The lunar module starts powered descent at PDI altitude and brakes with its descent engine.</p>
    </header>
    <main>
        <div class="controls">
            <button type="button" id="start">Start landing</button>
            <span id="status" role="status" aria-live="polite"></span>
        </div>
        <div class="stage">
            <canvas id="view" width="800" height="500" aria-label="Lunar module braking above the lunar surface"></canvas>
            <section id="dsky" class="dsky" aria-label="DSKY of the lunar module"></section>
        </div>
        <section id="codepanel" class="codepanel" aria-label="Code panel: the running AGC routine and the log"></section>
        <p id="outcome" class="outcome" role="status" aria-live="polite"></p>
        <noscript>This page needs JavaScript.</noscript>
    </main>
    <script type="module" src="/assets/app.js"></script>
</body>
</html>
