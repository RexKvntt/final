<?php
session_start();
// If somehow an admin lands here, redirect them
if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
    header("Location: dashboard.php");
    exit();
}
// Destroy session — they're locked out
session_unset();
session_destroy();
$reason = $_GET['reason'] ?? '';
$message = $reason === 'password_expired'
    ? 'You failed to change your temporary password within 3 days. Please contact support to reactivate your account.'
    : 'It seems like your account has been disabled. Please contact the administrator to resolve this issue or request reactivation.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Disabled — Helios University</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='8' fill='%231e40af'/><text x='50%25' y='54%25' dominant-baseline='middle' text-anchor='middle' font-size='18' font-family='sans-serif' font-weight='bold' fill='white'>S</text></svg>">
    <link rel="stylesheet" href="style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Instrument+Serif:ital@0;1&family=Sora:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script>
        (function(){
            var t = localStorage.getItem('theme') || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
            document.documentElement.setAttribute('data-theme', t);
        })();
    </script>
    <style>
        body {
            margin: 0; padding: 0;
            display: flex; align-items: center; justify-content: center;
            min-height: 100vh;
            background-color: var(--bg);
            background-image:
                radial-gradient(ellipse 60% 50% at 50% 0%, rgba(239,68,68,0.06) 0%, transparent 60%),
                radial-gradient(ellipse 40% 40% at 80% 80%, rgba(59,130,246,0.05) 0%, transparent 55%);
            font-family: 'Sora', sans-serif;
        }
        [data-theme="dark"] body {
            background-image:
                radial-gradient(ellipse 60% 50% at 50% 0%, rgba(239,68,68,0.08) 0%, transparent 60%),
                radial-gradient(ellipse 40% 40% at 80% 80%, rgba(59,130,246,0.06) 0%, transparent 55%);
        }

        .container {
            width: 100%; max-width: 480px;
            padding: 24px;
            animation: fadeUp 0.7s cubic-bezier(0.16,1,0.3,1) both;
        }
        @keyframes fadeUp { from{opacity:0;transform:translateY(20px)} to{opacity:1;transform:translateY(0)} }

        .card {
            background: var(--surface);
            border: 1.5px solid var(--border);
            border-radius: 28px;
            padding: 52px 44px;
            box-shadow: var(--shadow-xl);
            text-align: center;
            position: relative;
        }

        .theme-corner {
            position: absolute; top: 22px; right: 22px;
        }

        /* Icon circle */
        .icon-wrap {
            width: 72px; height: 72px; border-radius: 50%;
            background: rgba(239,68,68,0.10);
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 28px;
        }
        [data-theme="dark"] .icon-wrap { background: rgba(239,68,68,0.15); }
        .icon-wrap svg {
            width: 32px; height: 32px;
            stroke: #ef4444; fill: none; stroke-width: 2;
            stroke-linecap: round; stroke-linejoin: round;
        }

        .brand {
            display: inline-flex;
            justify-content: center;
            width: 100%;
            margin-bottom: 16px;
        }
        .brand-logo-img { display: block; width: 116px; height: 52px; object-fit: contain; }
        .brand-logo-dark { display: none; }
        [data-theme="dark"] .brand-logo-light { display: none; }
        [data-theme="dark"] .brand-logo-dark { display: block; }

        h1 {
            font-family: 'Sora', sans-serif;
            font-size: 32px; font-weight: 800;
            color: var(--text-primary); line-height: 1.2;
            letter-spacing: -0.02em; margin-bottom: 14px;
        }

        .sub {
            font-size: 14.5px; color: var(--text-secondary);
            line-height: 1.7; margin-bottom: 32px;
        }

        /* Status indicator */
        .status-row {
            display: flex; align-items: center; justify-content: center; gap: 10px;
            background: rgba(239,68,68,0.08);
            border: 1.5px solid rgba(239,68,68,0.25);
            border-radius: 999px; padding: 10px 22px;
            margin-bottom: 32px;
        }
        [data-theme="dark"] .status-row {
            background: rgba(239,68,68,0.12);
            border-color: rgba(239,68,68,0.3);
        }
        .status-dot {
            width: 8px; height: 8px; border-radius: 50%; background: #ef4444;
            flex-shrink: 0;
        }
        .status-text {
            font-size: 13px; font-weight: 700; color: #dc2626;
        }
        [data-theme="dark"] .status-text { color: #f87171; }

        /* Info box */
        .info-box {
            text-align: left;
            background: var(--surface-2);
            border: 1.5px solid var(--border);
            border-radius: 16px;
            padding: 20px 22px;
            margin-bottom: 32px;
        }
        .info-box p {
            font-size: 13px; color: var(--text-secondary);
            line-height: 1.65; margin: 0;
        }
        .info-box strong { color: var(--text-primary); font-weight: 700; }

        .btn-back {
            display: inline-flex; align-items: center; gap: 7px;
            padding: 13px 28px;
            font-family: 'Sora', sans-serif; font-size: 13.5px; font-weight: 700;
            color: var(--text-secondary); background: var(--surface-2);
            border: 1.5px solid var(--border); border-radius: 12px;
            cursor: pointer; transition: all 0.2s ease; text-decoration: none;
        }
        .btn-back:hover {
            border-color: var(--accent); color: var(--accent);
            background: var(--accent-light);
        }
        .btn-back svg {
            width: 14px; height: 14px; stroke: currentColor;
            fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round;
        }
    </style>
</head>
<body>
<div class="container">
    <div class="card">

        <div class="theme-corner">
            <button class="theme-toggle" id="themeBtn" aria-label="Toggle theme">
                <svg class="icon-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
                <svg class="icon-moon" viewBox="0 0 24 24"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
            </button>
        </div>

        <div class="icon-wrap">
            <!-- Lock icon -->
            <svg viewBox="0 0 24 24">
                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
            </svg>
        </div>

        <div class="brand">
            <img class="brand-logo-img brand-logo-light" src="img/Light Icon.png" alt="Helios University">
            <img class="brand-logo-img brand-logo-dark" src="img/Dark Icon.png" alt="Helios University">
        </div>
        <h1>Your account has been disabled.</h1>

        <p class="sub">
            <?= htmlspecialchars($message) ?>
        </p>

        <div class="status-row">
            <span class="status-dot"></span>
            <span class="status-text">Account access suspended</span>
        </div>

        <div class="info-box">
            <p>
                If you believe this is a mistake, please reach out to your <strong>system administrator</strong> directly.
                Your data and records remain intact — only your access has been restricted.
            </p>
        </div>

        <a href="login.php" class="btn-back">
            <svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
            Back to Sign In
        </a>

    </div>
</div>

<script src="theme.js"></script>
<script>
document.getElementById('themeBtn').addEventListener('click', function () {
    var cur  = document.documentElement.getAttribute('data-theme');
    var next = cur === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', next);
    localStorage.setItem('theme', next);
});
</script>
</body>
</html>
