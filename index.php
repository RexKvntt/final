<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Helios University</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
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
            --line: #dfe7f3;
            --paper: #ffffff;
            --paper-soft: #f5f8fc;
            --brand: #3f70b8;
            --brand-deep: #284d84;
            --brand-soft: #eaf2ff;
            --teal: #2f9d93;
            --gold: #e3a535;
            --shadow: 0 18px 50px rgba(24, 49, 83, 0.15);
            --font: 'Poppins', system-ui, -apple-system, BlinkMacSystemFont, sans-serif;
            --ease-out: cubic-bezier(0.16, 1, 0.3, 1);
            --ease-smooth: cubic-bezier(0.4, 0, 0.2, 1);
        }

        [data-theme="dark"] {
            --ink: #eef4ff;
            --muted: #aebbd0;
            --line: #2a3950;
            --paper: #07111f;
            --paper-soft: #101d2e;
            --brand: #254b7f;
            --brand-deep: #0d1d33;
            --brand-soft: #13243d;
            --shadow: 0 18px 50px rgba(0, 0, 0, 0.35);
        }

        html { min-height: 100%; -webkit-font-smoothing: antialiased; scroll-behavior: smooth; }
        body {
            min-height: 100vh;
            font-family: var(--font);
            color: var(--ink);
            background: var(--paper);
            transition: background-color 520ms var(--ease-smooth), color 520ms var(--ease-smooth);
        }

        a { color: inherit; text-decoration: none; }
        button, a { -webkit-tap-highlight-color: transparent; }

        .page {
            min-height: 100vh;
            width: 100%;
            overflow: hidden;
            background: var(--paper);
            transition: background-color 520ms var(--ease-smooth);
        }

        .hero {
            min-height: 100vh;
            display: grid;
            grid-template-rows: auto 1fr;
            position: relative;
            color: #ffffff;
            background:
                linear-gradient(90deg, rgba(34, 72, 122, 0.92), rgba(63, 112, 184, 0.74)),
                linear-gradient(135deg, #f8fbff 0 20%, #c8d8ee 20% 38%, #ffffff 38% 55%, #a7bad5 55% 100%);
            transition: background 620ms var(--ease-smooth);
        }

        [data-theme="dark"] .hero {
            background:
                linear-gradient(90deg, rgba(3, 12, 25, 0.94), rgba(16, 37, 66, 0.9)),
                linear-gradient(135deg, #07111f 0 22%, #10233d 22% 44%, #0b182a 44% 66%, #18365e 66% 100%);
        }

        .hero::before {
            content: '';
            position: absolute;
            inset: 0;
            background:
                linear-gradient(90deg, transparent 0 11%, rgba(255,255,255,0.18) 11% 11.35%, transparent 11.35% 100%),
                radial-gradient(circle at 74% 34%, rgba(47, 157, 147, 0.28) 0 8%, transparent 8.3%),
                radial-gradient(circle at 81% 31%, rgba(227, 165, 53, 0.24) 0 5%, transparent 5.3%),
                repeating-linear-gradient(90deg, transparent 0 70px, rgba(255,255,255,0.08) 70px 71px);
            opacity: 0.8;
            pointer-events: none;
            transition: background 620ms var(--ease-smooth), opacity 620ms var(--ease-smooth);
        }

        [data-theme="dark"] .hero::before {
            background:
                linear-gradient(90deg, transparent 0 11%, rgba(255,255,255,0.07) 11% 11.35%, transparent 11.35% 100%),
                radial-gradient(circle at 74% 34%, rgba(47, 157, 147, 0.16) 0 8%, transparent 8.3%),
                radial-gradient(circle at 81% 31%, rgba(227, 165, 53, 0.12) 0 5%, transparent 5.3%),
                repeating-linear-gradient(90deg, transparent 0 70px, rgba(255,255,255,0.035) 70px 71px);
        }

        .nav {
            width: min(1180px, calc(100% - 48px));
            margin: 0 auto;
            padding: 30px 0 18px;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            position: relative;
            z-index: 2;
            opacity: 0;
            animation: fadeDown 720ms 80ms var(--ease-out) forwards;
        }

        .brand {
            display: inline-flex;
            align-items: center;
            height: 54px;
        }

        .brand-logo-img {
            display: block;
            width: 120px;
            height: 54px;
            object-fit: contain;
            object-position: left center;
        }
        .brand-logo-dark { display: none; }
        [data-theme="dark"] .brand-logo-light { display: none; }
        [data-theme="dark"] .brand-logo-dark { display: block; }

        .nav-links {
            display: flex;
            align-items: center;
            gap: clamp(16px, 3vw, 32px);
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .nav-links a {
            opacity: 0.86;
            border-bottom: 2px solid transparent;
            padding-bottom: 5px;
            transition: opacity 180ms ease, border-color 180ms ease, transform 180ms ease;
        }

        .nav-links a:hover,
        .nav-links a:focus-visible {
            opacity: 1;
            border-color: #ffffff;
            outline: none;
            transform: translateY(-2px);
        }

        .theme-toggle {
            width: 38px;
            height: 38px;
            border-radius: 999px;
            border: 1px solid rgba(255,255,255,0.44);
            background: rgba(255,255,255,0.15);
            color: #ffffff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: transform 260ms var(--ease-out), background 260ms ease, border-color 520ms var(--ease-smooth);
        }

        .theme-toggle:hover { transform: translateY(-2px) rotate(12deg); background: rgba(255,255,255,0.24); }
        .theme-toggle:active { transform: translateY(0) scale(0.94); }
        .theme-toggle svg { width: 17px; height: 17px; stroke: currentColor; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; animation: iconPop 260ms var(--ease-out); }
        .icon-sun { display: none; }
        [data-theme="dark"] .icon-sun { display: block; }
        [data-theme="dark"] .icon-moon { display: none; }

        .hero-main {
            width: min(1180px, calc(100% - 48px));
            margin: 0 auto;
            position: relative;
            z-index: 1;
            display: grid;
            grid-template-columns: 1.04fr 0.96fr;
            align-items: center;
            gap: clamp(36px, 7vw, 88px);
            padding: 38px 0 54px;
        }

        .hero-main > div:first-child {
            opacity: 0;
            animation: fadeUp 820ms 180ms var(--ease-out) forwards;
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            opacity: 0.86;
            margin-bottom: 18px;
        }

        .eyebrow::before {
            content: '';
            width: 34px;
            height: 2px;
            background: #ffffff;
            border-radius: 999px;
        }

        .hero-title {
            max-width: 720px;
            font-size: clamp(30px, 4.4vw, 54px);
            font-weight: 800;
            line-height: 1.16;
            letter-spacing: 0;
            text-wrap: balance;
        }

        .hero-sub {
            max-width: 560px;
            margin-top: 22px;
            color: rgba(255,255,255,0.86);
            font-size: clamp(15px, 1.7vw, 18px);
            line-height: 1.75;
        }

        .hero-cta {
            display: flex;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
            margin-top: 34px;
            opacity: 0;
            animation: fadeUp 760ms 440ms var(--ease-out) forwards;
        }

        .btn {
            min-height: 52px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 14px 28px;
            border-radius: 999px;
            font-size: 14px;
            font-weight: 700;
            position: relative;
            overflow: hidden;
            isolation: isolate;
            transition: transform 240ms var(--ease-out), box-shadow 240ms ease, background 240ms ease, color 520ms var(--ease-smooth), border-color 520ms var(--ease-smooth);
        }

        .btn::before {
            content: '';
            position: absolute;
            inset: -40% -20%;
            z-index: -1;
            background: linear-gradient(120deg, transparent 20%, rgba(255,255,255,0.48) 50%, transparent 80%);
            transform: translateX(-120%);
            transition: transform 560ms var(--ease-out);
        }

        .btn svg { width: 17px; height: 17px; stroke: currentColor; fill: none; stroke-width: 2.5; stroke-linecap: round; stroke-linejoin: round; }
        .btn-primary { background: #ffffff; color: var(--brand-deep); box-shadow: 0 12px 30px rgba(16, 42, 81, 0.22); }
        .btn-secondary { background: rgba(255,255,255,0.12); color: #ffffff; border: 1px solid rgba(255,255,255,0.48); }
        .btn:hover { transform: translateY(-4px) scale(1.02); }
        .btn:hover::before { transform: translateX(120%); }
        .btn:active { transform: translateY(-1px) scale(0.98); }
        .btn-primary:hover { box-shadow: 0 16px 36px rgba(16, 42, 81, 0.28); }
        .btn-secondary:hover { background: rgba(255,255,255,0.2); }
        [data-theme="dark"] .btn-primary {
            background: var(--teal);
            color: #06111f;
            box-shadow: 0 16px 36px rgba(47,157,147,0.26);
        }
        [data-theme="dark"] .btn-primary:hover {
            background: #67c9b0;
            box-shadow: 0 18px 42px rgba(47,157,147,0.32);
        }
        [data-theme="dark"] .btn-secondary {
            background: rgba(255,255,255,0.08);
            border-color: rgba(219,233,255,0.28);
        }
        [data-theme="dark"] .btn-secondary:hover {
            background: rgba(255,255,255,0.14);
        }

        .workspace-art {
            min-height: 420px;
            border-radius: 8px;
            position: relative;
            overflow: hidden;
            box-shadow: var(--shadow);
            background: rgba(255,255,255,0.86);
            opacity: 0;
            transform: translateY(26px) scale(0.98);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: clamp(34px, 6vw, 72px);
            animation: imageRise 900ms 320ms var(--ease-out) forwards;
            transition: box-shadow 300ms ease, transform 300ms var(--ease-out), background-color 520ms var(--ease-smooth);
        }

        .workspace-art:hover { transform: translateY(-6px) scale(1.01); box-shadow: 0 24px 60px rgba(16, 42, 81, 0.28); }

        .workspace-art::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(90deg, rgba(35, 72, 121, 0.22), rgba(63, 112, 184, 0.08));
            pointer-events: none;
        }

        [data-theme="dark"] .workspace-art::after {
            background: linear-gradient(90deg, rgba(2, 9, 18, 0.42), rgba(13, 29, 51, 0.2));
        }

        [data-theme="dark"] .workspace-art {
            background: rgba(16, 29, 46, 0.88);
        }

        .workspace-art img {
            width: min(100%, 380px);
            height: auto;
            display: block;
            object-fit: contain;
            position: relative;
            z-index: 1;
        }

        .workspace-art .brand-logo-dark { display: none; }
        [data-theme="dark"] .workspace-art .brand-logo-light { display: none; }
        [data-theme="dark"] .workspace-art .brand-logo-dark { display: block; }

        .intro {
            padding: clamp(46px, 7vw, 76px) 24px;
            background: var(--paper);
            text-align: center;
            transition: background-color 520ms var(--ease-smooth);
        }

        .section-title {
            color: var(--brand-deep);
            font-size: clamp(24px, 4vw, 34px);
            font-weight: 800;
            line-height: 1.2;
            margin-bottom: 14px;
        }

        [data-theme="dark"] .section-title { color: #dbe9ff; }

        .section-title::after {
            content: '';
            display: block;
            width: 48px;
            height: 3px;
            margin: 14px auto 0;
            background: var(--brand);
            border-radius: 999px;
            transform-origin: center;
            animation: lineGrow 700ms var(--ease-out) both;
        }

        .intro p {
            width: min(760px, 100%);
            margin: 22px auto 0;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.8;
        }

        .pillars {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            background: var(--brand);
            color: #ffffff;
            transition: background-color 520ms var(--ease-smooth);
        }

        .pillar {
            min-height: 230px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 34px 28px;
            transition: transform 260ms var(--ease-out), background-color 520ms var(--ease-smooth);
        }

        .pillar:nth-child(2) { background: #202328; }
        [data-theme="dark"] .pillar:nth-child(2) { background: #050a12; }
        .pillar svg { width: 42px; height: 42px; margin-bottom: 20px; stroke: currentColor; fill: none; stroke-width: 1.9; stroke-linecap: round; stroke-linejoin: round; }
        .pillar h2 { font-size: 22px; font-weight: 700; margin-bottom: 12px; }
        .pillar p { max-width: 310px; color: rgba(255,255,255,0.78); font-size: 13px; line-height: 1.7; }
        .pillar:hover { transform: translateY(-5px); }
        .pillar:hover svg { animation: iconFloat 620ms var(--ease-out); }

        .services {
            padding: clamp(48px, 7vw, 82px) 24px;
            background: var(--paper);
            transition: background-color 520ms var(--ease-smooth);
        }

        .services > p {
            width: min(720px, 100%);
            margin: 18px auto 32px;
            color: var(--muted);
            text-align: center;
            font-size: 14px;
            line-height: 1.75;
        }

        .service-grid {
            width: min(980px, 100%);
            margin: 0 auto;
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 18px 24px;
        }

        .service {
            min-height: 70px;
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 15px 18px;
            background: var(--paper-soft);
            border: 1px solid var(--line);
            border-radius: 8px;
            color: var(--ink);
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            transition: transform 240ms var(--ease-out), box-shadow 240ms ease, border-color 240ms ease, background-color 520ms var(--ease-smooth), color 520ms var(--ease-smooth);
        }

        .service:hover {
            transform: translateY(-5px);
            border-color: rgba(63,112,184,0.7);
            box-shadow: 0 14px 28px rgba(24, 49, 83, 0.12);
        }

        .service svg {
            flex: 0 0 auto;
            width: 38px;
            height: 38px;
            padding: 8px;
            border-radius: 999px;
            border: 2px solid rgba(63,112,184,0.58);
            stroke: var(--brand);
            fill: none;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
            transition: transform 240ms var(--ease-out), border-color 240ms ease, stroke 520ms var(--ease-smooth);
        }

        .service:hover svg { transform: rotate(-5deg) scale(1.06); border-color: var(--brand); }

        .stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1px;
            color: #ffffff;
            background-color: var(--brand);
            transition: background-color 520ms var(--ease-smooth);
        }

        .stat {
            min-height: 178px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 26px 18px;
            text-align: center;
            transition: transform 240ms var(--ease-out), background-color 240ms ease;
        }

        .stat:hover { transform: translateY(-4px); background-color: rgba(255,255,255,0.08); }

        .stat svg { width: 42px; height: 42px; margin-bottom: 12px; stroke: currentColor; fill: none; stroke-width: 1.8; }
        .stat strong { font-size: clamp(32px, 5vw, 48px); line-height: 1; }
        .stat span { margin-top: 12px; font-size: 12px; font-weight: 700; text-transform: uppercase; color: rgba(255,255,255,0.76); }

        .footer {
            padding: 24px;
            text-align: center;
            color: var(--muted);
            background: var(--paper);
            border-top: 1px solid var(--line);
            font-size: 12px;
            transition: background-color 520ms var(--ease-smooth), border-color 520ms var(--ease-smooth), color 520ms var(--ease-smooth);
        }

        .reveal {
            opacity: 0;
            transform: translateY(26px);
            transition: opacity 760ms var(--ease-out), transform 760ms var(--ease-out);
        }

        .reveal.is-visible {
            opacity: 1;
            transform: translateY(0);
        }

        .pillars .reveal:nth-child(2),
        .service-grid .reveal:nth-child(2),
        .stats .reveal:nth-child(2) { transition-delay: 90ms; }

        .pillars .reveal:nth-child(3),
        .service-grid .reveal:nth-child(3),
        .stats .reveal:nth-child(3) { transition-delay: 180ms; }

        .service-grid .reveal:nth-child(4),
        .stats .reveal:nth-child(4) { transition-delay: 270ms; }

        .service-grid .reveal:nth-child(5) { transition-delay: 360ms; }
        .service-grid .reveal:nth-child(6) { transition-delay: 450ms; }

        html.is-theme-switching .page::after {
            opacity: 1;
            transform: scale(1);
        }

        .page::after {
            content: '';
            position: fixed;
            inset: 0;
            z-index: 999;
            pointer-events: none;
            background: radial-gradient(circle at top right, rgba(255,255,255,0.16), transparent 34%);
            opacity: 0;
            transform: scale(1.04);
            transition: opacity 420ms var(--ease-smooth), transform 420ms var(--ease-smooth);
        }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(24px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes fadeDown {
            from { opacity: 0; transform: translateY(-14px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes imageRise {
            from { opacity: 0; transform: translateY(32px) scale(0.96); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        @keyframes lineGrow {
            from { transform: scaleX(0); opacity: 0; }
            to { transform: scaleX(1); opacity: 1; }
        }

        @keyframes iconFloat {
            0% { transform: translateY(0) scale(1); }
            45% { transform: translateY(-6px) scale(1.08); }
            100% { transform: translateY(0) scale(1); }
        }

        @keyframes iconPop {
            from { transform: scale(0.72) rotate(-18deg); opacity: 0; }
            to { transform: scale(1) rotate(0); opacity: 1; }
        }

        @media (prefers-reduced-motion: reduce) {
            *,
            *::before,
            *::after {
                animation-duration: 1ms !important;
                animation-iteration-count: 1 !important;
                scroll-behavior: auto !important;
                transition-duration: 1ms !important;
            }

            .reveal,
            .workspace-art,
            .hero-main > div:first-child,
            .hero-cta,
            .nav {
                opacity: 1;
                transform: none;
            }
        }

        @media (max-width: 900px) {
            .nav { width: min(100% - 34px, 1180px); }
            .nav-links a { display: none; }
            .hero-main {
                width: min(100% - 34px, 1180px);
                grid-template-columns: 1fr;
                padding: 30px 0 48px;
            }
            .workspace-art { min-height: 280px; }
            .pillars, .service-grid, .stats { grid-template-columns: 1fr; }
            .pillar, .stat { min-height: 170px; }
        }

        @media (max-width: 520px) {
            .hero { min-height: auto; }
            .nav { padding-top: 22px; }
            .hero-main { gap: 28px; }
            .hero-title { font-size: clamp(28px, 9vw, 42px); }
            .hero-cta { align-items: stretch; }
            .btn { width: 100%; }
            .workspace-art { min-height: 220px; }
            .service { align-items: flex-start; }
        }
    </style>
</head>
<body>
<main class="page">
    <section class="hero" id="home">
        <nav class="nav" aria-label="Main navigation">
            <div class="nav-links">
                <a href="#home">Home</a>
                <a href="#about">About</a>
                <a href="#features">Features</a>
                <button class="theme-toggle" id="themeToggle" aria-label="Toggle theme">
                    <svg class="icon-moon" viewBox="0 0 24 24"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
                    <svg class="icon-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
                </button>
            </div>
        </nav>

        <div class="hero-main">
            <div>
                <p class="eyebrow">Academic task monitoring</p>
                <h1 class="hero-title">Manage classes, assignments, and progress in one place.</h1>
                <p class="hero-sub">
                    The Helios University Task Manager gives students and faculty an efficient way to track coursework and class activity with secure account access.
                </p>
                <div class="hero-cta">
                    <a href="register.php" class="btn btn-primary">
                        Request Account Access
                        <svg viewBox="0 0 24 24"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                    </a>
                    <a href="login.php" class="btn btn-secondary">
                        <svg viewBox="0 0 24 24"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
                        Sign In
                    </a>
                </div>
            </div>
            <a class="workspace-art" href="#home" aria-label="Helios University home">
                <img class="brand-logo-light" src="img/Light Icon.png" alt="Helios University">
                <img class="brand-logo-dark" src="img/Dark Icon.png" alt="Helios University">
            </a>
        </div>
    </section>

    <section class="intro reveal" id="about">
        <h2 class="section-title">Welcome To Helios University</h2>
        <p>
            The Helios Administration humbly presents a seamless way to manage and track courseworks whether you are a student or a faculty member. Our platform is designed to provide a comprehensive overview of all your academic activities, from assignments and notifications to class progress.
        </p>
    </section>

    <section class="pillars" aria-label="Platform principles">
        <article class="pillar reveal">
            <svg viewBox="0 0 24 24"><path d="M3 12a9 9 0 1 0 9-9"/><path d="M3 3v6h6"/><path d="M3 9a9 9 0 0 1 9-6"/></svg>
            <h2>Track Work</h2>
            <p>Keep assignments, submissions, notifications, and class activity easy to follow.</p>
        </article>
        <article class="pillar reveal">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="4"/><path d="M12 3v3M12 18v3M3 12h3M18 12h3"/></svg>
            <h2>Role-Based Access</h2>
            <p>Role-based views help every user land on the tools and information that matter.</p>
        </article>
        <article class="pillar reveal">
            <svg viewBox="0 0 24 24"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg>
            <h2>See Progress</h2>
            <p>Faculty and students can monitor class movement without digging through clutter.</p>
        </article>
    </section>

    <section class="services reveal" id="features">
        <h2 class="section-title" style="text-align: center;">System Features</h2>
        <p>Core workflows are grouped into quick scanning blocks, inspired by the reference layout but tailored to this academic system.</p>
        <div class="service-grid">
            <div class="service reveal"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="14" rx="2"/><path d="M8 22h8M12 18v4"/></svg>Class Dashboard</div>
            <div class="service reveal"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h8M8 17h5"/></svg>Assignments</div>
            <div class="service reveal"><svg viewBox="0 0 24 24"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 7h18s-3 0-3-7"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>Reminders</div>
            <div class="service reveal"><svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>User Roles</div>
            <div class="service reveal"><svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M9 12l2 2 4-4"/></svg>OTP Security</div>
            <div class="service reveal"><svg viewBox="0 0 24 24"><path d="M3 3v18h18"/><path d="M7 14l3-3 3 2 5-6"/></svg>Progress Reports</div>
        </div>
    </section>

    <section class="stats" aria-label="Platform highlights">
        <div class="stat reveal"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg><strong>24/7</strong><span>Class Access</span></div>
        <div class="stat reveal"><svg viewBox="0 0 24 24"><path d="M20 6L9 17l-5-5"/></svg><strong>100%</strong><span>Role Guided</span></div>
        <div class="stat reveal"><svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/></svg><strong>3</strong><span>User Types</span></div>
        <div class="stat reveal"><svg viewBox="0 0 24 24"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 1 0-7.78 7.78L12 21.23l8.84-8.84a5.5 5.5 0 0 0 0-7.78z"/></svg><strong>1</strong><span>Unified System</span></div>
    </section>

    <footer class="footer">
        &copy; <?= date('Y') ?> Helios University Academic Platform
    </footer>
</main>

<script>
    var toggle = document.getElementById('themeToggle');
    var revealItems = document.querySelectorAll('.reveal');

    if ('IntersectionObserver' in window) {
        var revealObserver = new IntersectionObserver(function(entries) {
            entries.forEach(function(entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-visible');
                    revealObserver.unobserve(entry.target);
                }
            });
        }, { threshold: 0.16, rootMargin: '0px 0px -50px 0px' });

        revealItems.forEach(function(item) {
            revealObserver.observe(item);
        });
    } else {
        revealItems.forEach(function(item) {
            item.classList.add('is-visible');
        });
    }

    if (toggle) {
        toggle.addEventListener('click', function(){
            var current = document.documentElement.getAttribute('data-theme');
            var next = current === 'dark' ? 'light' : 'dark';
            document.documentElement.classList.add('is-theme-switching');
            document.documentElement.setAttribute('data-theme', next);
            localStorage.setItem('theme', next);
            window.setTimeout(function() {
                document.documentElement.classList.remove('is-theme-switching');
            }, 460);
        });
    }
</script>
</body>
</html>
