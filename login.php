<?php
/**
 * Helios University Login — theme-matched to register.php
 */
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

class AuthSystem {
    private $configFile;
    private $config;
    private $errors;

    public function __construct($configPath) {
        $this->configFile = $configPath;
        $this->loadConfig();
        $this->defineErrors();
    }

    private function loadConfig() {
        $defaultConfig = [
            'org_name'     => 'Helios University',
            'maintenance'  => false,
            'm_duration'   => '60',
            'm_work'       => 'General Updates & UI Enhancements',
            'm_start_time' => time()
        ];
        if (file_exists($this->configFile)) {
            $parsed = json_decode(file_get_contents($this->configFile), true);
            $this->config = is_array($parsed) ? array_merge($defaultConfig, $parsed) : $defaultConfig;
        } else {
            $this->config = $defaultConfig;
        }
    }

    private function defineErrors() {
        $this->errors = [
            'empty_fields'           => 'Enter a valid Unique ID and password.',
            'wrong_password'         => 'Wrong password, try again.',
            'not_found'              => 'Couldn\'t find your Helios University account.',
            'password_expired'       => 'Your temporary password expired. Please contact support to reactivate your account.',
            'role_mismatch'          => 'The selected role does not match this account.',
            'server'                 => 'A server error occurred. Please try again.',
            'registrations_disabled' => 'New account creation is currently closed.',
            'no_connection'          => 'No internet connection. Please check your network and try again.',
            'email'                  => 'Verification email could not be delivered. Please try again later.',
        ];
    }

    public function getConfig($key, $default = null) { return $this->config[$key] ?? $default; }
    public function getErrorMessage($errorKey) { return $this->errors[$errorKey] ?? null; }

    public function enforceMaintenanceGate() {
        if ($this->getConfig('maintenance') && ($_SESSION['role'] ?? '') !== 'admin') {
            $this->renderMaintenancePage();
            exit();
        }
    }

    private function renderMaintenancePage() {
        $orgName         = htmlspecialchars($this->getConfig('org_name'));
        $mWork           = htmlspecialchars($this->getConfig('m_work'));
        $startTime       = (int)$this->getConfig('m_start_time');
        $durationMinutes = (int)$this->getConfig('m_duration');
        $expiryTimestamp = ($startTime + ($durationMinutes * 60)) * 1000;

        echo <<<HTML
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Update — {$orgName}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script>
        (function(){
            var t=localStorage.getItem('theme')||(window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light');
            document.documentElement.setAttribute('data-theme',t);
        })();
    </script>
    <style>
        :root{--bg:#f5f7fc;--surface:#ffffff;--border:#e2e7f0;--text-primary:#111827;--text-secondary:#556070;--accent:#1a3fc4;--shadow-md:0 8px 32px rgba(10,20,60,0.08);--font:'Poppins',system-ui,sans-serif;--radius:10px;}
        [data-theme="dark"]{--bg:#0e1117;--surface:#161b26;--border:#232c3d;--text-primary:#e8edf5;--text-secondary:#8898b0;--accent:#4f78f1;--shadow-md:0 8px 32px rgba(0,0,0,0.45);}
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
        body{font-family:var(--font);background:var(--bg);color:var(--text-primary);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;}
        .card{width:100%;max-width:440px;background:var(--surface);border:1px solid var(--border);border-radius:16px;box-shadow:var(--shadow-md);padding:48px 40px;text-align:center;}
        h1{font-size:22px;font-weight:600;margin-bottom:12px;letter-spacing:-0.02em;}
        p{font-size:13.5px;color:var(--text-secondary);line-height:1.65;margin-bottom:28px;}
        .info-box{background:var(--bg);border:1px solid var(--border);border-radius:var(--radius);padding:14px 16px;text-align:left;margin-bottom:12px;}
        .info-label{font-size:11px;font-weight:600;color:var(--text-secondary);text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px;}
        .info-val{font-size:13.5px;font-weight:500;color:var(--text-primary);}
        #countdown-timer{color:var(--accent);font-variant-numeric:tabular-nums;font-weight:600;}
    </style>
</head>
<body>
<div class="card">
    <h1>We'll be right back</h1>
    <p>The {$orgName} workspace is currently undergoing scheduled maintenance to improve your experience.</p>
    <div class="info-box"><div class="info-label">Current Task</div><div class="info-val">{$mWork}</div></div>
    <div class="info-box"><div class="info-label">Estimated Time Remaining</div><div class="info-val" id="countdown-timer">Calculating...</div></div>
</div>
<script>
    const targetExpiry={$expiryTimestamp};
    function updateCountdown(){
        const now=new Date().getTime(),timeLeft=targetExpiry-now;
        if(timeLeft<=0){document.getElementById('countdown-timer').textContent='Finalizing... Please refresh shortly.';return;}
        const h=Math.floor(timeLeft/(1000*60*60)),m=Math.floor((timeLeft%(1000*60*60))/(1000*60)),s=Math.floor((timeLeft%(1000*60))/1000);
        let d='';if(h>0)d+=h+'h ';d+=(m<10?'0':'')+m+'m '+(s<10?'0':'')+s+'s';
        document.getElementById('countdown-timer').textContent=d;
    }
    setInterval(updateCountdown,1000);updateCountdown();
</script>
</body>
</html>
HTML;
    }
}

$authSystem  = new AuthSystem(__DIR__ . "/config.json");
$authSystem->enforceMaintenanceGate();

$orgName      = htmlspecialchars($authSystem->getConfig('org_name'));
$errorKey     = $_GET['error'] ?? '';
$errorMessage = $authSystem->getErrorMessage($errorKey);
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign in — <?= $orgName ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400;1,500&display=swap" rel="stylesheet">
    <script>
        (function(){
            var t=localStorage.getItem('theme')||(window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light');
            document.documentElement.setAttribute('data-theme',t);
        })();
    </script>
    <style>
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}

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
            --danger:         #dc2626;
            --danger-bg:      #fee2e2;
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
            --danger:         #f87171;
            --danger-bg:      rgba(248,113,113,0.12);
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

        /* Dot grid */
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

        /* ── NAV ── */
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

        /* ── PAGE ── */
        .page {
            position: relative; z-index: 1;
            flex: 1;
            display: flex; align-items: center; justify-content: center;
            padding: 88px 24px 60px;
        }

        /* ── CARD ── */
        .card {
            width: 100%; max-width: 420px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            box-shadow: var(--shadow-md);
            opacity: 0; animation: fadeUp 0.55s 0.05s cubic-bezier(0.16,1,0.3,1) forwards;
        }

        .card-head {
            padding: 36px 40px 28px;
            border-bottom: 1px solid var(--border);
        }
        .card-head-title {
            font-size: 22px; font-weight: 700;
            letter-spacing: -0.03em; color: var(--text-primary);
            margin-bottom: 4px;
        }
        .card-head-sub { font-size: 13px; color: var(--text-secondary); font-weight: 400; }

        .card-body { padding: 32px 40px 36px; }

        /* ── FIELDS ── */
        .field { position: relative; margin-bottom: 16px; }

        .field label {
            display: block;
            font-size: 11.5px; font-weight: 600;
            color: var(--text-muted);
            letter-spacing: 0.04em; text-transform: uppercase;
            margin-bottom: 6px;
        }

        .field input {
            width: 100%;
            padding: 11px 14px;
            font-family: var(--font); font-size: 13.5px; font-weight: 400;
            color: var(--text-primary);
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            outline: none;
            transition: border-color var(--transition), box-shadow var(--transition), background var(--transition);
            -webkit-appearance: none;
        }
        .field input:focus {
            border-color: var(--border-focus);
            box-shadow: 0 0 0 3px rgba(17,24,39,0.07);
            background: var(--surface);
        }
        .role-options {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
            margin-bottom: 16px;
        }
        .role-option input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }
        .role-option span {
            min-height: 42px;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            background: var(--bg);
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: border-color var(--transition), background var(--transition), color var(--transition), transform var(--transition);
        }
        .role-option input:checked + span {
            border-color: var(--accent);
            background: var(--accent-light);
            color: var(--accent);
            transform: translateY(-1px);
        }
        [data-theme="dark"] .field input:focus { box-shadow: 0 0 0 3px rgba(232,237,245,0.06); }
        .field input.has-toggle { padding-right: 42px; }
        .field input.field-error { border-color: var(--danger) !important; box-shadow: 0 0 0 3px rgba(220,38,38,0.10) !important; }

        /* pw toggle */
        .pw-toggle {
            position: absolute; right: 12px; bottom: 11px;
            background: none; border: none; cursor: pointer; padding: 0;
            color: var(--text-muted); transition: color var(--transition);
            display: flex; align-items: center;
        }
        .pw-toggle:hover { color: var(--text-secondary); }
        .pw-toggle svg { width: 15px; height: 15px; stroke: currentColor; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }

        /* ── ALERT ── */
        .alert {
            display: flex; align-items: center; gap: 9px;
            padding: 11px 14px; border-radius: var(--radius);
            font-size: 12.5px; font-weight: 500;
            border: 1px solid;
            margin-bottom: 20px;
            animation: fadeUp 0.25s both;
        }
        .alert.error { background: var(--danger-bg); border-color: rgba(220,38,38,0.2); color: var(--danger); }
        .alert svg { width: 14px; height: 14px; flex-shrink: 0; stroke: currentColor; fill: none; stroke-width: 2.5; stroke-linecap: round; stroke-linejoin: round; }

        /* field-level error hint */
        .field-hint {
            font-size: 11.5px; font-weight: 500;
            color: var(--danger);
            margin-top: 5px; padding-left: 2px;
            display: none; animation: fadeUp 0.2s both;
        }
        .field-hint.visible { display: block; }

        /* ── SUBMIT ── */
        .btn-submit {
            width: 100%; margin-top: 24px;
            padding: 13px;
            font-family: var(--font); font-size: 13.5px; font-weight: 600;
            color: #fff; background: var(--text-primary);
            border: none; border-radius: var(--radius);
            cursor: pointer; letter-spacing: 0.01em;
            transition: opacity var(--transition), transform var(--transition);
            box-shadow: 0 2px 12px rgba(10,20,60,0.15);
        }
        .btn-submit:hover { opacity: 0.88; transform: translateY(-1px); }
        .btn-submit:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }

        /* ── REGISTER ROW ── */
        .register-row {
            text-align: center; margin-top: 22px;
            font-size: 12.5px; color: var(--text-secondary);
        }
        .register-row a { color: var(--accent); font-weight: 600; }
        .register-row a:hover { text-decoration: underline; }

        /* ── FOOTER ── */
        .auth-footer {
            position: relative; z-index: 1;
            display: flex; justify-content: space-between; align-items: center;
            max-width: 420px; width: 100%;
            margin: 0 auto; padding: 0 24px 32px;
            font-size: 11.5px; color: var(--text-muted);
        }
        .footer-links { display: flex; gap: 20px; }
        .footer-links a { color: var(--text-muted); transition: color var(--transition); }
        .footer-links a:hover { color: var(--text-secondary); }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(16px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* Index-inspired auth refresh */
        :root {
            --bg: #ffffff;
            --surface: rgba(255,255,255,0.94);
            --border: rgba(223,231,243,0.78);
            --border-focus: #284d84;
            --text-primary: #183153;
            --text-secondary: #617089;
            --text-muted: #8fa0b8;
            --accent: #3f70b8;
            --accent-hover: #284d84;
            --accent-light: #eaf2ff;
            --shadow-md: 0 24px 60px rgba(24,49,83,0.18);
            --transition: 260ms cubic-bezier(0.16,1,0.3,1);
        }
        [data-theme="dark"] {
            --bg: #07111f;
            --surface: rgba(16,26,40,0.94);
            --border: rgba(42,57,80,0.9);
            --border-focus: #dbe9ff;
            --text-primary: #eef4ff;
            --text-secondary: #aebbd0;
            --text-muted: #71829b;
            --accent: #5f91dd;
            --accent-hover: #dbe9ff;
            --accent-light: #13243d;
            --shadow-md: 0 24px 60px rgba(0,0,0,0.42);
        }
        body {
            background:
                linear-gradient(90deg, rgba(34,72,122,0.94), rgba(63,112,184,0.72)),
                linear-gradient(135deg, #f8fbff 0 20%, #c8d8ee 20% 38%, #ffffff 38% 55%, #a7bad5 55% 100%);
            overflow-x: hidden;
            transition: background 520ms cubic-bezier(0.4,0,0.2,1), color 520ms cubic-bezier(0.4,0,0.2,1);
        }
        [data-theme="dark"] body {
            background:
                linear-gradient(90deg, rgba(3,12,25,0.96), rgba(16,37,66,0.9)),
                linear-gradient(135deg, #07111f 0 22%, #10233d 22% 44%, #0b182a 44% 66%, #18365e 66% 100%);
        }
        body::before {
            background:
                linear-gradient(90deg, transparent 0 11%, rgba(255,255,255,0.14) 11% 11.35%, transparent 11.35% 100%),
                radial-gradient(circle at 74% 34%, rgba(47,157,147,0.22) 0 8%, transparent 8.3%),
                radial-gradient(circle at 81% 31%, rgba(227,165,53,0.18) 0 5%, transparent 5.3%),
                repeating-linear-gradient(90deg, transparent 0 70px, rgba(255,255,255,0.055) 70px 71px);
            mask-image: none;
            -webkit-mask-image: none;
            opacity: 1;
        }
        [data-theme="dark"] body::before { opacity: 0.72; }
        .nav {
            background: transparent;
            border-bottom: 0;
            color: #fff;
            justify-content: flex-end;
            animation: fadeDown 700ms 80ms cubic-bezier(0.16,1,0.3,1) both;
        }
        [data-theme="dark"] .nav { background: transparent; }
        .brand-logo-img { display: block; width: 120px; height: 54px; object-fit: contain; object-position: left center; }
        .brand-logo-dark { display: none; }
        [data-theme="dark"] .brand-logo-light { display: none; }
        [data-theme="dark"] .brand-logo-dark { display: block; }
        .theme-toggle {
            background: rgba(255,255,255,0.15);
            border-color: rgba(255,255,255,0.44);
            color: #fff;
        }
        .theme-toggle:hover { background: rgba(255,255,255,0.24); border-color: rgba(255,255,255,0.72); transform: translateY(-2px) rotate(12deg); }
        .theme-toggle:active { transform: scale(0.94); }
        .theme-toggle svg { stroke: currentColor; }
        .page {
            display: grid;
            grid-template-columns: minmax(260px, 480px) minmax(340px, 420px);
            align-items: center;
            gap: clamp(34px, 7vw, 88px);
            width: min(1120px, calc(100% - 48px));
            margin: 0 auto;
            padding: 104px 0 48px;
        }
        .brand-showcase {
            min-height: 440px;
            border-radius: 8px;
            background: rgba(255,255,255,0.86);
            box-shadow: var(--shadow-md);
            opacity: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: clamp(34px, 6vw, 68px);
            animation: imageRise 900ms 220ms cubic-bezier(0.16,1,0.3,1) forwards;
        }
        [data-theme="dark"] .brand-showcase { background: rgba(16,26,40,0.88); }
        .brand-showcase .brand-logo-img {
            width: min(100%, 360px);
            height: auto;
            object-fit: contain;
            object-position: center;
        }
        .brand-showcase .brand-logo-dark { display: none; }
        [data-theme="dark"] .brand-showcase .brand-logo-light { display: none; }
        [data-theme="dark"] .brand-showcase .brand-logo-dark { display: block; }
        .card {
            border-radius: 8px;
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
            animation: fadeUp 760ms 180ms cubic-bezier(0.16,1,0.3,1) forwards;
        }
        .card-head { padding-top: 34px; }
        .card-head-title { color: var(--text-primary); font-size: 28px; letter-spacing: 0; }
        .field input {
            min-height: 46px;
            background: var(--accent-light);
            transition: border-color var(--transition), box-shadow var(--transition), background var(--transition), transform var(--transition);
        }
        .field input:focus { transform: translateY(-1px); }
        .btn-submit {
            position: relative;
            overflow: hidden;
            border-radius: 999px;
            min-height: 50px;
            background: var(--accent);
            box-shadow: 0 14px 28px rgba(24,49,83,0.18);
            transition: transform var(--transition), box-shadow var(--transition), background var(--transition);
        }
        .btn-submit::before {
            content: '';
            position: absolute;
            inset: -40% -20%;
            background: linear-gradient(120deg, transparent 20%, rgba(255,255,255,0.4) 50%, transparent 80%);
            transform: translateX(-120%);
            transition: transform 560ms cubic-bezier(0.16,1,0.3,1);
        }
        .btn-submit:hover { opacity: 1; transform: translateY(-4px) scale(1.01); box-shadow: 0 18px 36px rgba(24,49,83,0.24); }
        .btn-submit:hover::before { transform: translateX(120%); }
        .auth-footer { color: rgba(255,255,255,0.72); }
        .footer-links a { color: rgba(255,255,255,0.72); }
        .footer-links a:hover { color: #fff; }
        html.is-theme-switching body::after {
            opacity: 1;
            transform: scale(1);
        }
        body::after {
            content: '';
            position: fixed;
            inset: 0;
            z-index: 999;
            pointer-events: none;
            background: radial-gradient(circle at top right, rgba(255,255,255,0.16), transparent 34%);
            opacity: 0;
            transform: scale(1.04);
            transition: opacity 420ms cubic-bezier(0.4,0,0.2,1), transform 420ms cubic-bezier(0.4,0,0.2,1);
        }
        @keyframes fadeDown {
            from { opacity: 0; transform: translateY(-14px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes imageRise {
            from { opacity: 0; transform: translateY(32px) scale(0.96); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        @media (max-width: 480px) {
            .nav { padding: 0 20px; }
            .card-head, .card-body { padding-left: 24px; padding-right: 24px; }
        }
        @media (max-width: 860px) {
            .page {
                grid-template-columns: 1fr;
                width: min(100% - 34px, 520px);
                padding-top: 92px;
            }
            .brand-showcase { min-height: 220px; }
            .auth-footer { max-width: 520px; }
        }
    </style>
</head>
<body>

<nav class="nav">
    <button class="theme-toggle" id="themeToggle" aria-label="Toggle theme">
        <svg class="icon-moon" viewBox="0 0 24 24"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
        <svg class="icon-sun"  viewBox="0 0 24 24"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
    </button>
</nav>

<div class="page">
    <a class="brand-showcase" href="index.php" aria-label="<?= $orgName ?> home">
        <img class="brand-logo-img brand-logo-light" src="img/Light Icon.png" alt="<?= $orgName ?>">
        <img class="brand-logo-img brand-logo-dark" src="img/Dark Icon.png" alt="<?= $orgName ?>">
    </a>
    <div class="card">
        <div class="card-head">
            <div class="card-head-title">Sign in</div>
            <div class="card-head-sub">Welcome to <?= $orgName ?>.</div>
        </div>

        <div class="card-body">
            <div id="errorContainer">
            <?php if ($errorMessage && !in_array($errorKey, ['wrong_password', 'not_found', 'empty_fields'])): ?>
                <div class="alert error">
                    <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    <span><?= htmlspecialchars($errorMessage) ?></span>
                </div>
            <?php endif; ?>
            </div>

            <form action="process_login.php" method="POST" id="loginForm" novalidate>

                <div class="field">
                    <label for="username">Unique ID / Admin Username</label>
                    <input type="text" id="username" name="username"
                           class="<?= in_array($errorKey, ['not_found','empty_fields']) ? 'field-error' : '' ?>"
                           autocomplete="username" spellcheck="false"
                           value="<?= htmlspecialchars($_GET['user'] ?? '') ?>">
                    <?php if ($errorKey === 'not_found'): ?>
                        <div class="field-hint visible">Couldn't find your <?= $orgName ?> account.</div>
                    <?php elseif ($errorKey === 'empty_fields' && empty($_GET['user'])): ?>
                        <div class="field-hint visible">Please enter your Unique ID.</div>
                    <?php endif; ?>
                </div>

                <div class="field">
                    <label>Account Type</label>
                    <div class="role-options">
                        <?php $selectedRole = $_GET['role'] ?? 'student'; ?>
                        <label class="role-option">
                            <input type="radio" name="role" value="student" <?= $selectedRole === 'student' ? 'checked' : '' ?>>
                            <span>Student</span>
                        </label>
                        <label class="role-option">
                            <input type="radio" name="role" value="faculty" <?= $selectedRole === 'faculty' ? 'checked' : '' ?>>
                            <span>Faculty</span>
                        </label>
                        <label class="role-option">
                            <input type="radio" name="role" value="admin" <?= $selectedRole === 'admin' ? 'checked' : '' ?>>
                            <span>Admin</span>
                        </label>
                    </div>
                    <?php if ($errorKey === 'role_mismatch'): ?>
                        <div class="field-hint visible">Choose the role registered to this account.</div>
                    <?php endif; ?>
                </div>

                <div class="field" style="margin-bottom: 8px;">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password"
                           class="has-toggle <?= in_array($errorKey, ['wrong_password','empty_fields']) ? 'field-error' : '' ?>"
                           autocomplete="current-password">
                    <button type="button" class="pw-toggle" aria-label="Show password" onclick="togglePw(this)">
                        <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                    <?php if ($errorKey === 'wrong_password'): ?>
                        <div class="field-hint visible">Wrong password. Try again or reset it.</div>
                    <?php elseif ($errorKey === 'empty_fields' && !empty($_GET['user'])): ?>
                        <div class="field-hint visible">Please enter your password.</div>
                    <?php endif; ?>
                </div>

                <button type="submit" class="btn-submit" id="submitBtn">Sign in</button>
            </form>

            <div class="register-row">
                Don't have login credentials? <a href="register.php">Request here.</a>
            </div>
        </div>
    </div>
</div>

<div class="auth-footer">
    <span>English (US)</span>
    <div class="footer-links">
        &copy; <?= date('Y') ?> Helios University Academic Hub
    </div>
</div>

<script>
(function(){
    document.getElementById('themeToggle').addEventListener('click', function(){
        var next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        document.documentElement.classList.add('is-theme-switching');
        document.documentElement.setAttribute('data-theme', next);
        localStorage.setItem('theme', next);
        window.setTimeout(function(){
            document.documentElement.classList.remove('is-theme-switching');
        }, 460);
    });

    function showFieldError(input, msg) {
        input.classList.add('field-error');
        var hint = input.parentNode.querySelector('.field-hint');
        if (!hint) {
            hint = document.createElement('div');
            hint.className = 'field-hint';
            input.parentNode.appendChild(hint);
        }
        hint.textContent = msg;
        hint.classList.add('visible');
    }

    function clearFieldError(input) {
        input.classList.remove('field-error');
        var hint = input.parentNode.querySelector('.field-hint');
        if (hint) hint.classList.remove('visible');
    }

    var usernameInput = document.getElementById('username');
    var passwordInput = document.getElementById('password');

    usernameInput.addEventListener('input', function(){ clearFieldError(this); });
    passwordInput.addEventListener('input', function(){ clearFieldError(this); });

    document.getElementById('loginForm').addEventListener('submit', function(e){
        var ok = true;
        if (!usernameInput.value.trim()) {
            showFieldError(usernameInput, 'Please enter your Unique ID.');
            ok = false;
        }
        if (!passwordInput.value) {
            showFieldError(passwordInput, 'Please enter your password.');
            ok = false;
        }
        if (!ok) { e.preventDefault(); return; }
        var btn = document.getElementById('submitBtn');
        btn.disabled = true;
        btn.textContent = 'Signing in…';
    });

    window.togglePw = function(btn) {
        var input = document.getElementById('password');
        input.type = input.type === 'password' ? 'text' : 'password';
        btn.style.opacity = input.type === 'text' ? '0.45' : '1';
    };
})();
</script>
</body>
</html>
