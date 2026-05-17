<?php
$__org = (string)($__org ?? 'Helios University');
$__work = (string)($__work ?? 'General updates and system improvements');
$__dur = max(0, (int)($__dur ?? 0));
$__startedAt = max(0, (int)($__startedAt ?? time()));
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Maintenance - <?= htmlspecialchars($__org) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700;800&display=swap" rel="stylesheet">
    <script>
        (function(){
            var t = localStorage.getItem('theme') || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
            document.documentElement.setAttribute('data-theme', t);
        })();
    </script>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --ink: #183153;
            --muted: #617089;
            --paper: #f4f7fb;
            --surface: #ffffff;
            --brand: #3f70b8;
            --teal: #2f9d93;
            --line: rgba(223,231,243,.82);
        }
        [data-theme="dark"] {
            --ink: #eef4ff;
            --muted: #8fa0b8;
            --paper: #07111f;
            --surface: rgba(15,24,38,.88);
            --brand: #5f91dd;
            --teal: #67c9b0;
            --line: rgba(42,57,80,.9);
        }
        html, body {
            min-height: 100vh;
            font-family: 'Poppins', system-ui, sans-serif;
            background: var(--paper);
            color: var(--ink);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }
        [data-theme="dark"] body {
            background: linear-gradient(90deg, rgba(3,12,25,.96), rgba(16,37,66,.9)), linear-gradient(135deg, #07111f 0 22%, #10233d 22% 44%, #0b182a 44% 66%, #18365e 66% 100%);
        }
        .card {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 20px;
            padding: clamp(32px,6vw,60px) clamp(28px,6vw,64px);
            text-align: center;
            max-width: 540px;
            width: 100%;
            box-shadow: 0 20px 50px rgba(24,49,83,.12);
        }
        [data-theme="dark"] .card { box-shadow: 0 24px 60px rgba(0,0,0,.42); }
        .icon-wrap {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: rgba(63,112,184,.1);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
            color: var(--brand);
        }
        .icon-wrap svg { width: 38px; height: 38px; stroke: currentColor; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
        h1 { font-size: clamp(22px,4vw,30px); font-weight: 800; color: var(--ink); margin-bottom: 10px; letter-spacing: 0; }
        .work-desc { font-size: 15px; color: var(--muted); line-height: 1.7; margin-bottom: 28px; }
        .timer-box { background: rgba(63,112,184,.08); border: 1px solid rgba(63,112,184,.18); border-radius: 14px; padding: 20px 24px; margin-bottom: 28px; }
        .timer-label { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .1em; color: var(--muted); margin-bottom: 8px; }
        .timer-display { font-size: clamp(28px,6vw,48px); font-weight: 800; color: var(--brand); font-variant-numeric: tabular-nums; letter-spacing: 0; line-height: 1; }
        [data-theme="dark"] .timer-display { color: var(--teal); }
        .timer-unit { font-size: 13px; color: var(--muted); margin-top: 6px; }
        .checking-msg { font-size: 13px; color: var(--muted); margin-bottom: 28px; display: flex; align-items: center; justify-content: center; gap: 8px; }
        .dot-pulse { display: inline-flex; gap: 4px; }
        .dot-pulse span { width: 6px; height: 6px; border-radius: 50%; background: var(--muted); animation: pulse 1.2s ease-in-out infinite; }
        .dot-pulse span:nth-child(2) { animation-delay: .2s; }
        .dot-pulse span:nth-child(3) { animation-delay: .4s; }
        @keyframes pulse { 0%,80%,100%{opacity:.2;transform:scale(.8)} 40%{opacity:1;transform:scale(1)} }
        .org-name { font-size: 13px; color: var(--muted); border-top: 1px solid var(--line); padding-top: 20px; }
        .org-name strong { color: var(--ink); }
        #timerSection, #checkingSection { display: none; }
    </style>
</head>
<body>
<div class="card">
    <div class="icon-wrap" aria-hidden="true">
        <svg viewBox="0 0 24 24"><path d="M14.7 6.3a4 4 0 0 0-5.4 5.4l-5.1 5.1a2 2 0 1 0 2.8 2.8l5.1-5.1a4 4 0 0 0 5.4-5.4l-2.8 2.8-2.1-2.1 2.8-2.8z"/></svg>
    </div>
    <h1>Under Maintenance</h1>
    <p class="work-desc"><?= htmlspecialchars($__work) ?></p>

    <div id="timerSection">
        <div class="timer-box">
            <div class="timer-label">Estimated time remaining</div>
            <div class="timer-display" id="countdown">--:--</div>
            <div class="timer-unit" id="timerUnit">minutes : seconds</div>
        </div>
    </div>

    <div id="checkingSection">
        <div class="checking-msg">
            Checking if the site is back up
            <span class="dot-pulse"><span></span><span></span><span></span></span>
        </div>
    </div>

    <div class="org-name">
        <strong><?= htmlspecialchars($__org) ?></strong> - Please check back soon.
    </div>
</div>

<script>
(function(){
    var serverDuration = <?= $__dur * 60 ?>;
    var startedAt = <?= $__startedAt * 1000 ?>;
    var timerSection = document.getElementById('timerSection');
    var checkingSection = document.getElementById('checkingSection');
    var display = document.getElementById('countdown');
    var unitEl = document.getElementById('timerUnit');

    function showChecking() {
        checkingSection.style.display = 'block';
        setTimeout(function(){ location.reload(); }, 30000);
    }

    function formatTime(secs) {
        if (secs >= 86400) {
            var d = Math.floor(secs / 86400);
            var h = Math.floor((secs % 86400) / 3600);
            var m = Math.floor((secs % 3600) / 60);
            var s = secs % 60;
            if (unitEl) unitEl.textContent = 'days : hours : minutes : seconds';
            return d + 'd ' + String(h).padStart(2,'0') + ':' + String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
        }
        if (secs >= 3600) {
            var h2 = Math.floor(secs / 3600);
            var m2 = Math.floor((secs % 3600) / 60);
            var s2 = secs % 60;
            if (unitEl) unitEl.textContent = 'hours : minutes : seconds';
            return String(h2).padStart(2,'0') + ':' + String(m2).padStart(2,'0') + ':' + String(s2).padStart(2,'0');
        }
        var m3 = Math.floor(secs / 60);
        var s3 = secs % 60;
        if (unitEl) unitEl.textContent = 'minutes : seconds';
        return String(m3).padStart(2,'0') + ':' + String(s3).padStart(2,'0');
    }

    function tick() {
        var remaining = Math.floor(((startedAt + (serverDuration * 1000)) - Date.now()) / 1000);
        if (serverDuration <= 0 || remaining <= 0) {
            timerSection.style.display = 'none';
            showChecking();
            return;
        }
        timerSection.style.display = 'block';
        display.textContent = formatTime(remaining);
        setTimeout(tick, 1000);
    }

    tick();
})();
</script>
</body>
</html>
