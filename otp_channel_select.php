<?php
session_start();

// Must have a pending OTP session to be here
if (!isset($_SESSION['temp_user']) || !isset($_SESSION['temp_role'])) {
    header("Location: login.php");
    exit();
}

// Load config for org name
$configFile = __DIR__ . "/config.json";
$config = file_exists($configFile) ? json_decode(file_get_contents($configFile), true) : ['org_name' => 'Helios University'];

$errors = [
    'send_failed' => 'Failed to send the OTP. Please try again.',
    'sms_failed'  => 'SMS could not be delivered. Please try email instead.',
    'email_failed'=> 'OTP email could not be delivered. Please try SMS instead.',
    'no_connection' => 'Unable to send OTP — no internet connection. Please check your network.',
];
$error    = $errors[$_GET['error'] ?? ''] ?? null;
$isError  = $error !== null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Identity — <?= htmlspecialchars($config['org_name']) ?></title>
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
                radial-gradient(circle at 20% 20%, rgba(30,64,175,0.08) 0%, transparent 40%),
                radial-gradient(circle at 80% 80%, rgba(59,130,246,0.06) 0%, transparent 40%);
            font-family: 'Sora', sans-serif;
        }

        .auth-container {
            width: 100%; max-width: 480px;
            padding: 24px;
            animation: fadeUp 0.8s cubic-bezier(0.16, 1, 0.3, 1) both;
        }
        @keyframes fadeUp { from{opacity:0;transform:translateY(20px)} to{opacity:1;transform:translateY(0)} }

        .brand-header {
            text-align: center;
            margin-bottom: 28px;
        }
        .brand-logo-img {
            display: block;
            width: 128px;
            height: 58px;
            object-fit: contain;
            margin: 0 auto;
        }
        .brand-logo-dark { display: none; }
        [data-theme="dark"] .brand-logo-light { display: none; }
        [data-theme="dark"] .brand-logo-dark { display: block; }
        .brand-name-bold {
            font-size: 48px; font-weight: 800;
            letter-spacing: -0.05em;
            color: var(--text-primary);
            line-height: 1; display: block;
        }

        .card {
            background: var(--surface);
            border: 1.5px solid var(--border);
            border-radius: 32px;
            padding: 48px 44px;
            box-shadow: var(--shadow-xl);
            position: relative;
        }

        .theme-corner {
            position: absolute; top: 28px; right: 28px;
        }

        /* Icon */
        .icon-wrap {
            width: 64px; height: 64px; border-radius: 50%;
            background: var(--accent-light);
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 28px;
        }
        .icon-wrap svg {
            width: 28px; height: 28px;
            stroke: var(--accent); fill: none; stroke-width: 2;
            stroke-linecap: round; stroke-linejoin: round;
        }

        .card-title {
            font-size: 24px; font-weight: 800;
            color: var(--text-primary); letter-spacing: -0.03em;
            text-align: center; margin-bottom: 8px;
        }
        .card-subtitle {
            font-size: 14px; color: var(--text-secondary);
            text-align: center; line-height: 1.6; margin-bottom: 32px;
        }

        /* Error alert */
        .alert-inline {
            display: flex; align-items: center; gap: 10px;
            background: rgba(239,68,68,0.1);
            border: 1px solid rgba(239,68,68,0.2);
            color: #ef4444;
            padding: 14px 16px; border-radius: 14px;
            font-size: 13px; font-weight: 600;
            margin-bottom: 24px;
        }
        .alert-inline svg { width: 16px; height: 16px; flex-shrink: 0; stroke: currentColor; fill: none; stroke-width: 2.5; }

        /* Channel options */
        .channel-options {
            display: flex; flex-direction: column; gap: 14px;
            margin-bottom: 28px;
        }

        .channel-btn {
            width: 100%;
            display: flex; align-items: center; gap: 18px;
            padding: 20px 22px;
            background: var(--surface-2);
            border: 2px solid var(--border);
            border-radius: 20px;
            cursor: pointer;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            text-align: left;
            font-family: 'Sora', sans-serif;
            text-decoration: none;
            color: inherit;
        }
        .channel-btn:hover {
            border-color: var(--accent);
            background: var(--accent-light);
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        .channel-btn:active { transform: translateY(0); }

        .channel-icon {
            width: 48px; height: 48px; border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .channel-icon.email-icon { background: rgba(30,64,175,0.1); }
        .channel-icon.sms-icon   { background: rgba(5,150,105,0.1); }
        [data-theme="dark"] .channel-icon.email-icon { background: rgba(59,130,246,0.15); }
        [data-theme="dark"] .channel-icon.sms-icon   { background: rgba(16,185,129,0.15); }

        .channel-icon svg {
            width: 22px; height: 22px; fill: none; stroke-width: 2;
            stroke-linecap: round; stroke-linejoin: round;
        }
        .email-icon svg { stroke: var(--accent); }
        .sms-icon svg   { stroke: #059669; }
        [data-theme="dark"] .sms-icon svg { stroke: #10b981; }

        .channel-info { flex: 1; }
        .channel-title {
            font-size: 15px; font-weight: 800;
            color: var(--text-primary); letter-spacing: -0.02em;
            margin-bottom: 3px;
        }
        .channel-desc {
            font-size: 12.5px; color: var(--text-secondary);
            line-height: 1.4; font-weight: 400;
        }

        .channel-arrow {
            width: 20px; height: 20px;
            stroke: var(--text-muted); fill: none;
            stroke-width: 2.5; stroke-linecap: round; stroke-linejoin: round;
            flex-shrink: 0; transition: stroke 0.2s, transform 0.2s;
        }
        .channel-btn:hover .channel-arrow {
            stroke: var(--accent);
            transform: translateX(3px);
        }

        /* Loading state */
        .channel-btn.loading {
            pointer-events: none; opacity: 0.7;
        }

        /* Divider */
        .divider {
            display: flex; align-items: center; gap: 12px;
            margin-bottom: 20px;
        }
        .divider-line { flex: 1; height: 1px; background: var(--border); }
        .divider-text { font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.08em; }

        /* Back link */
        .back-link {
            display: flex; align-items: center; justify-content: center; gap: 6px;
            font-size: 13.5px; font-weight: 600; color: var(--text-secondary);
            text-decoration: none; transition: color 0.2s;
        }
        .back-link:hover { color: var(--accent); }
        .back-link svg { width: 14px; height: 14px; stroke: currentColor; fill: none; stroke-width: 2.5; stroke-linecap: round; stroke-linejoin: round; }

        /* Spinner for button */
        @keyframes spin { to { transform: rotate(360deg); } }
        .spin { animation: spin 0.7s linear infinite; display: inline-block; }
    </style>
</head>
<body>

<div class="auth-container">
    <div class="brand-header">
        <img class="brand-logo-img brand-logo-light" src="img/Light Icon.png" alt="<?= htmlspecialchars($config['org_name']) ?>">
        <img class="brand-logo-img brand-logo-dark" src="img/Dark Icon.png" alt="<?= htmlspecialchars($config['org_name']) ?>">
    </div>

    <div class="card">
        <div class="theme-corner">
            <button class="theme-toggle" id="themeBtn" aria-label="Toggle theme">
                <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
                <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
            </button>
        </div>

        <div class="icon-wrap">
            <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        </div>

        <h1 class="card-title">Two-Step Verification</h1>
        <p class="card-subtitle">Choose where to receive your one-time code to complete sign-in.</p>

        <?php if ($error): ?>
        <div class="alert-inline">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <span><?= htmlspecialchars($error) ?></span>
        </div>
        <?php endif; ?>

        <div class="channel-options">

            <!-- Email OTP -->
            <form method="POST" action="process_otp_dispatch.php" id="emailForm">
                <input type="hidden" name="channel" value="email">
                <button type="submit" class="channel-btn" id="emailBtn" onclick="setLoading(this)">
                    <div class="channel-icon email-icon">
                        <svg viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                    </div>
                    <div class="channel-info">
                        <div class="channel-title">Send via Email</div>
                        <div class="channel-desc">Receive the code at your registered email address</div>
                    </div>
                    <svg class="channel-arrow" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
                </button>
            </form>

            <!-- SMS OTP -->
            <form method="POST" action="process_otp_dispatch.php" id="smsForm">
                <input type="hidden" name="channel" value="sms">
                <button type="submit" class="channel-btn" id="smsBtn" onclick="setLoading(this)">
                    <div class="channel-icon sms-icon">
                        <svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                    </div>
                    <div class="channel-info">
                        <div class="channel-title">Send via SMS</div>
                        <div class="channel-desc">Receive the code as a text message on your registered number</div>
                    </div>
                    <svg class="channel-arrow" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
                </button>
            </form>

        </div>

        <div class="divider">
            <span class="divider-line"></span>
            <span class="divider-text">or</span>
            <span class="divider-line"></span>
        </div>

        <a href="login.php" class="back-link">
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

function setLoading(btn) {
    // Show a subtle loading state while the form submits
    btn.classList.add('loading');
    var titleEl = btn.querySelector('.channel-title');
    if (titleEl) titleEl.textContent = 'Sending code…';
    var arrow = btn.querySelector('.channel-arrow');
    if (arrow) {
        arrow.innerHTML = '<circle cx="12" cy="12" r="8" stroke-dasharray="40" stroke-dashoffset="40" style="animation:none"/>';
        arrow.classList.add('spin');
    }
    // Disable the other form's button too
    document.querySelectorAll('.channel-btn').forEach(function(b) {
        if (b !== btn) b.classList.add('loading');
    });
}
</script>
</body>
</html>
