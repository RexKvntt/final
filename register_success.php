<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Created — Helios University</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400;1,500&display=swap" rel="stylesheet">
    <script>
        (function(){
            var t = localStorage.getItem('theme') || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
            document.documentElement.setAttribute('data-theme', t);
        })();
    </script>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --bg:             #f5f7fc;
            --surface:        #ffffff;
            --border:         #e2e7f0;
            --border-focus:   #111827;
            --text-primary:   #111827;
            --text-secondary: #556070;
            --text-muted:     #9caab8;
            --accent:         #1a3fc4;
            --accent-hover:   #1533a8;
            --accent-light:   #eef2ff;
            --success:        #16a34a;
            --success-bg:     #dcfce7;
            --success-border: rgba(22,163,74,0.2);
            --danger:         #dc2626;
            --danger-bg:      #fee2e2;
            --shadow-sm: 0 1px 4px rgba(10,20,60,0.06);
            --shadow-md: 0 8px 32px rgba(10,20,60,0.08), 0 2px 8px rgba(10,20,60,0.04);
            --transition: 160ms cubic-bezier(0.4,0,0.2,1);
            --font: 'Poppins', system-ui, sans-serif;
            --radius: 10px;
        }

        [data-theme="dark"] {
            --bg:             #0e1117;
            --surface:        #161b26;
            --border:         #232c3d;
            --border-focus:   #e8edf5;
            --text-primary:   #e8edf5;
            --text-secondary: #8898b0;
            --text-muted:     #3d5060;
            --accent:         #4f78f1;
            --accent-hover:   #6b90f5;
            --accent-light:   #0d1632;
            --success:        #4ade80;
            --success-bg:     rgba(74,222,128,0.10);
            --success-border: rgba(74,222,128,0.20);
            --danger:         #f87171;
            --danger-bg:      rgba(248,113,113,0.12);
            --shadow-sm: 0 1px 4px rgba(0,0,0,0.4);
            --shadow-md: 0 8px 32px rgba(0,0,0,0.45);
        }

        html { -webkit-font-smoothing: antialiased; }

        body {
            font-family: var(--font);
            background: var(--bg);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            transition: background var(--transition), color var(--transition);
        }

        body::before {
            content: '';
            position: fixed; inset: 0; z-index: 0;
            background-image: radial-gradient(circle, var(--border) 1px, transparent 1px);
            background-size: 28px 28px;
            opacity: 0.55;
            pointer-events: none;
            mask-image: radial-gradient(ellipse 80% 70% at 50% 40%, black 20%, transparent 80%);
            -webkit-mask-image: radial-gradient(ellipse 80% 70% at 50% 40%, black 20%, transparent 80%);
        }
        [data-theme="dark"] body::before { opacity: 0.25; }

        a { text-decoration: none; color: inherit; }

        /* ─── NAV ─── */
        .nav {
            position: fixed; top: 0; left: 0; right: 0; z-index: 100;
            height: 60px;
            display: flex; align-items: center; justify-content: space-between;
            padding: 0 40px;
            background: rgba(245,247,252,0.82);
            border-bottom: 1px solid var(--border);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            transition: background var(--transition);
        }
        [data-theme="dark"] .nav { background: rgba(14,17,23,0.85); }

        .nav-brand {
            display: inline-flex;
            align-items: center;
            height: 46px;
        }
        .brand-logo-img { display: block; width: 104px; height: 46px; object-fit: contain; object-position: left center; }
        .brand-logo-dark { display: none; }
        [data-theme="dark"] .brand-logo-light { display: none; }
        [data-theme="dark"] .brand-logo-dark { display: block; }

        .theme-toggle {
            width: 34px; height: 34px; border-radius: 50%;
            background: var(--surface); border: 1px solid var(--border);
            display: flex; align-items: center; justify-content: center;
            cursor: pointer;
            transition: background var(--transition), border-color var(--transition), transform 0.25s;
        }
        .theme-toggle:hover { background: var(--accent-light); border-color: var(--accent); transform: rotate(18deg); }
        .theme-toggle svg { width: 14px; height: 14px; stroke: var(--text-secondary); fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
        .icon-sun  { display: none; }
        .icon-moon { display: block; }
        [data-theme="dark"] .icon-sun  { display: block; }
        [data-theme="dark"] .icon-moon { display: none; }

        /* ─── PAGE LAYOUT ─── */
        .page {
            position: relative; z-index: 1;
            flex: 1;
            display: flex; align-items: center; justify-content: center;
            padding: 88px 24px 60px;
        }

        /* ─── CARD ─── */
        .card {
            width: 100%; max-width: 460px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            box-shadow: var(--shadow-md);
            padding: 48px 44px 44px;
            text-align: center;
            opacity: 0;
            animation: fadeUp 0.55s 0.05s cubic-bezier(0.16,1,0.3,1) forwards;
        }

        /* ─── SUCCESS ICON ─── */
        .success-icon-wrap {
            width: 68px; height: 68px;
            background: var(--success-bg);
            border: 1px solid var(--success-border);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 24px;
            animation: popIn 0.5s 0.2s cubic-bezier(0.16,1,0.3,1) both;
        }
        .success-icon-wrap svg {
            width: 30px; height: 30px;
            stroke: var(--success);
            fill: none;
            stroke-width: 2.5;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        /* ─── TEXT ─── */
        .success-title {
            font-size: 21px; font-weight: 700;
            letter-spacing: -0.03em;
            color: var(--text-primary);
            margin-bottom: 10px;
        }

        .success-sub {
            font-size: 13.5px;
            color: var(--text-secondary);
            font-weight: 400;
            line-height: 1.65;
            margin-bottom: 32px;
        }

        .success-sub strong {
            color: var(--text-primary);
            font-weight: 600;
        }

        /* ─── PENDING BADGE ─── */
        .pending-badge {
            display: inline-flex; align-items: center; gap: 7px;
            background: rgba(251,191,36,0.10);
            border: 1px solid rgba(251,191,36,0.25);
            border-radius: 99px;
            padding: 6px 14px;
            font-size: 12px; font-weight: 600;
            color: #b45309;
            margin-bottom: 28px;
            letter-spacing: 0.01em;
        }
        [data-theme="dark"] .pending-badge {
            background: rgba(251,191,36,0.08);
            border-color: rgba(251,191,36,0.18);
            color: #fbbf24;
        }
        .pending-badge svg {
            width: 13px; height: 13px;
            stroke: currentColor; fill: none;
            stroke-width: 2.5; stroke-linecap: round; stroke-linejoin: round;
            flex-shrink: 0;
        }

        /* ─── DIVIDER ─── */
        .divider {
            height: 1px;
            background: var(--border);
            margin: 0 0 28px;
        }

        /* ─── BUTTON ─── */
        .btn-login {
            display: block; width: 100%;
            padding: 13px;
            font-family: var(--font); font-size: 13.5px; font-weight: 600;
            color: #fff; background: var(--accent);
            border: none; border-radius: var(--radius);
            cursor: pointer; letter-spacing: 0.01em;
            text-decoration: none;
            transition: background var(--transition), opacity var(--transition), transform var(--transition), box-shadow var(--transition);
            box-shadow: 0 2px 12px rgba(10,20,60,0.15);
        }
        .btn-login:hover { background: var(--accent-hover); transform: translateY(-1px); }
        [data-theme="dark"] .btn-login {
            background: var(--accent);
            color: #fff;
            box-shadow: 0 10px 24px rgba(79,120,241,0.20);
        }
        [data-theme="dark"] .btn-login:hover {
            background: var(--accent-hover);
        }

        /* ─── ANIMATIONS ─── */
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(16px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        @keyframes popIn {
            from { opacity: 0; transform: scale(0.7); }
            to   { opacity: 1; transform: scale(1); }
        }

        @media (max-width: 480px) {
            .nav { padding: 0 20px; }
            .card { padding: 36px 24px 32px; }
        }
    </style>
</head>
<body>

<nav class="nav">
    <a href="index.php" class="nav-brand" aria-label="Helios University home">
        <img class="brand-logo-img brand-logo-light" src="img/Light Icon.png" alt="Helios University">
        <img class="brand-logo-img brand-logo-dark" src="img/Dark Icon.png" alt="Helios University">
    </a>
    <button class="theme-toggle" id="themeToggle" aria-label="Toggle theme">
        <svg class="icon-moon" viewBox="0 0 24 24"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
        <svg class="icon-sun"  viewBox="0 0 24 24"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
    </button>
</nav>

<div class="page">
    <div class="card">

        <div class="success-icon-wrap">
            <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
        </div>

        <div class="success-title">Activation request sent!</div>

        <div class="success-sub">
            Your details matched the authorized records.<br>
            <strong>An admin needs to approve your request</strong> before credentials are emailed to you.
        </div>

        <div class="pending-badge">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            Pending admin approval
        </div>

        <div class="divider"></div>

        <a href="login.php" class="btn-login">Go to Login</a>

    </div>
</div>

<script>
    document.getElementById('themeToggle').addEventListener('click', function(){
        var next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', next);
        localStorage.setItem('theme', next);
    });
</script>
</body>
</html>
