<?php
/**
 * Helios Academic Hub
 * System Configuration & Global Command Center
 */
session_start();
require 'cryptograph_process.php';

// Strict Admin Access Control
if (!isset($_SESSION['username']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

require_once 'db.php';

$message = null;
$msgType = 'success';

// Load config from DB
$configStmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
$config = [];
foreach ($configStmt->fetchAll() as $row) {
    $config[$row['setting_key']] = $row['setting_value'];
}

/* ── Handle Save Process ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    $settings = [
        'org_name'    => trim($_POST['org_name'] ?? ''),
        'sys_email'   => trim($_POST['sys_email'] ?? ''),
        'allow_reg'   => isset($_POST['allow_reg'])   ? '1' : '0',
        'maintenance' => isset($_POST['maintenance'])  ? '1' : '0',
        'm_duration'  => trim($_POST['m_duration'] ?? '60'),
        'm_work'      => trim($_POST['m_work'] ?? ''),
        'enforce_otp' => isset($_POST['enforce_otp']) ? '1' : '0',
        'last_updated'=> date('Y-m-d H:i:s'),
    ];

    $stmt = $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = ?");
    foreach ($settings as $key => $value) {
        $stmt->execute([$value, $key]);
    }

    // Update local $config so the page reflects new values immediately
    $config = array_merge($config, $settings);
    $message = "Settings saved successfully.";
}

/* ── Handle Danger Zone Actions ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_assignments'])) {
    $asnFile = __DIR__ . "/assignments.json";
    if (file_exists($asnFile)) {
        file_put_contents($asnFile, json_encode(['assignments' => []], JSON_PRETTY_PRINT));
        $message = "Assignment records have been purged.";
        $msgType = "success";
    }
}

$username = htmlspecialchars($_SESSION['username']);
$initials = strtoupper(substr($username, 0, 2));

// Load counts for sidebar badges
$allUsersStmt = $pdo->query("SELECT status FROM users WHERE role IN ('faculty','student','admin')");
$allUserRows  = $allUsersStmt->fetchAll();
$totalUsers   = count($allUserRows);
$pendingCount = count(array_filter($allUserRows, fn($u) => $u['status'] === 'pending'));
$totalClassesStmt = $pdo->query("SELECT COUNT(*) FROM classes");
$totalClasses = (int)$totalClassesStmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings — Helios</title>
    <script>
        (function () {
            var t = localStorage.getItem('theme') || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
            document.documentElement.setAttribute('data-theme', t);
        })();
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --bg:            #f5f6fa;
            --surface:       #ffffff;
            --border:        #e8eaef;
            --border-light:  #f0f2f7;
            --text-primary:  #1a1d2e;
            --text-secondary:#52576e;
            --text-muted:    #8b90a7;
            --accent:        #1a7a4a;
            --accent-dim:    rgba(26,122,74,0.10);
            --accent-glow:   rgba(26,122,74,0.18);
            --danger:        #ef4444;
            --danger-dim:    rgba(239,68,68,0.06);
            --danger-border: #fca5a5;
            --success:       #1a7a4a;
            --amber:         #f59e0b;
            --shadow-sm:     0 1px 4px rgba(0,0,0,0.06);
            --shadow-md:     0 4px 16px rgba(0,0,0,0.08);
            --radius:        14px;
            --sidebar-w:     230px;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: var(--bg);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
        }

        /* ── SIDEBAR ── */
        .sidebar {
            width: var(--sidebar-w);
            background: var(--surface);
            border-right: 1.5px solid var(--border);
            display: flex;
            flex-direction: column;
            padding: 0;
            position: fixed;
            top: 0; left: 0; bottom: 0;
            z-index: 100;
        }

        .sidebar-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 22px 20px 18px;
            border-bottom: 1.5px solid var(--border);
            text-decoration: none;
        }
        .brand-icon {
            width: 34px; height: 34px;
            background: var(--accent);
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
        }
        .brand-icon svg { width: 18px; height: 18px; fill: #fff; }
        .brand-text { display: flex; flex-direction: column; }
        .brand-name { font-size: 14px; font-weight: 700; color: var(--text-primary); line-height: 1.2; }
        .brand-sub  { font-size: 10px; font-weight: 500; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.08em; }

        .sidebar-nav { flex: 1; padding: 16px 12px; display: flex; flex-direction: column; gap: 2px; overflow-y: auto; }

        .nav-label {
            font-size: 9.5px; font-weight: 700; letter-spacing: 0.1em;
            text-transform: uppercase; color: var(--text-muted);
            padding: 14px 8px 6px;
        }

        .nav-link {
            display: flex; align-items: center; gap: 10px;
            padding: 9px 12px; border-radius: 10px;
            font-size: 13px; font-weight: 500;
            color: var(--text-secondary);
            text-decoration: none;
            transition: all 0.18s ease;
            position: relative;
        }
        .nav-link:hover { background: var(--bg); color: var(--text-primary); }
        .nav-link.active {
            background: var(--accent-dim);
            color: var(--accent);
            font-weight: 600;
        }
        .nav-link svg { width: 16px; height: 16px; stroke: currentColor; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; flex-shrink: 0; }

        .nav-badge {
            margin-left: auto;
            background: var(--accent);
            color: #fff;
            font-size: 10px; font-weight: 700;
            padding: 1px 7px; border-radius: 20px;
            min-width: 20px; text-align: center;
        }
        .nav-badge.amber { background: var(--amber); }

        .sidebar-footer {
            padding: 12px;
            border-top: 1.5px solid var(--border);
        }
        .sidebar-user {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 12px; border-radius: 10px;
            background: var(--bg);
        }
        .user-avatar {
            width: 32px; height: 32px; border-radius: 9px;
            background: var(--accent);
            display: flex; align-items: center; justify-content: center;
            font-size: 11px; font-weight: 700; color: #fff; flex-shrink: 0;
        }
        .user-info { flex: 1; min-width: 0; }
        .user-name  { font-size: 12px; font-weight: 600; color: var(--text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .user-role  { font-size: 10px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.06em; }

        .sign-out-btn {
            display: flex; align-items: center; gap: 10px;
            padding: 9px 12px; border-radius: 10px;
            font-size: 13px; font-weight: 500;
            color: var(--text-muted);
            text-decoration: none;
            margin-top: 4px;
            transition: all 0.18s;
        }
        .sign-out-btn:hover { background: var(--bg); color: var(--danger); }
        .sign-out-btn svg { width: 16px; height: 16px; stroke: currentColor; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }

        /* ── MAIN ── */
        .main {
            margin-left: var(--sidebar-w);
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        /* ── TOPBAR ── */
        .topbar {
            position: sticky; top: 0; z-index: 50;
            background: rgba(245,246,250,0.92);
            backdrop-filter: blur(10px);
            border-bottom: 1.5px solid var(--border);
            padding: 0 32px;
            height: 60px;
            display: flex; align-items: center; justify-content: space-between;
        }
        .topbar-title { font-size: 14px; font-weight: 600; color: var(--text-secondary); }
        .topbar-right { display: flex; align-items: center; gap: 8px; }
        .topbar-avatar {
            width: 34px; height: 34px; border-radius: 10px;
            background: var(--accent);
            display: flex; align-items: center; justify-content: center;
            font-size: 11px; font-weight: 700; color: #fff;
        }

        /* ── CONTENT ── */
        .content {
            padding: 36px 40px 60px;
            max-width: 820px;
            width: 100%;
        }

        .page-heading { margin-bottom: 32px; }
        .page-heading h1 {
            font-size: 28px; font-weight: 700;
            color: var(--text-primary); letter-spacing: -0.02em;
            margin-bottom: 4px;
        }
        .page-heading p { font-size: 14px; color: var(--text-muted); }

        /* ── ALERT ── */
        .alert {
            padding: 14px 18px; border-radius: var(--radius);
            font-size: 13.5px; font-weight: 500;
            margin-bottom: 24px;
            display: flex; align-items: center; gap: 10px;
        }
        .alert-success { background: #1d743c; border: 1.5px solid #86efac; color: #f0fdf4; }
        .alert-error   { background: #cc3a3a; border: 1.5px solid #fca5a5; color: #fef2f2; }

        /* ── CARD ── */
        .card {
            background: var(--surface);
            border: 1.5px solid var(--border);
            border-radius: var(--radius);
            margin-bottom: 20px;
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }

        .card-header {
            padding: 20px 24px 16px;
            border-bottom: 1.5px solid var(--border-light);
        }
        .card-header h2 { font-size: 15px; font-weight: 700; color: var(--text-primary); margin-bottom: 2px; }
        .card-header p  { font-size: 12.5px; color: var(--text-muted); }

        .card-body { padding: 0 24px; }

        /* ── SETTING ROW ── */
        .setting-row {
            display: flex; align-items: center; justify-content: space-between;
            padding: 18px 0; gap: 20px;
            border-bottom: 1px solid var(--border-light);
        }
        .setting-row:last-child { border-bottom: none; }
        .setting-info { flex: 1; }
        .setting-label { display: block; font-size: 13.5px; font-weight: 600; color: var(--text-primary); margin-bottom: 3px; }
        .setting-desc  { font-size: 12px; color: var(--text-muted); line-height: 1.5; }

        /* ── TEXT INPUT ── */
        .config-input {
            padding: 10px 14px;
            border: 1.5px solid var(--border);
            border-radius: 10px;
            background: var(--bg);
            font-family: 'Poppins', sans-serif;
            font-size: 13px;
            color: var(--text-primary);
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
            width: 220px;
        }
        .config-input:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px var(--accent-dim);
            background: var(--surface);
        }

        /* ── TOGGLE SWITCH ── */
        .switch { position: relative; display: inline-block; width: 44px; height: 24px; flex-shrink: 0; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider {
            position: absolute; cursor: pointer; inset: 0;
            background: var(--border); border-radius: 30px; transition: 0.25s;
        }
        .slider::before {
            content: ''; position: absolute;
            width: 18px; height: 18px; border-radius: 50%;
            left: 3px; bottom: 3px;
            background: #fff;
            transition: 0.25s;
            box-shadow: var(--shadow-sm);
        }
        input:checked + .slider { background: var(--accent); }
        input:checked + .slider::before { transform: translateX(20px); }

        /* ── DANGER CARD ── */
        .card.danger {
            border-color: var(--danger-border);
            background: var(--danger-dim);
        }
        .card.danger .card-header { border-bottom-color: var(--danger-border); }
        .card.danger .card-header h2 { color: var(--danger); }
        .card.danger .setting-row { border-bottom-color: rgba(239,68,68,0.12); }

        /* ── BUTTONS ── */
        .btn-save {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 12px 32px;
            background: var(--accent);
            color: #fff;
            border: none; border-radius: 10px;
            font-family: 'Poppins', sans-serif;
            font-size: 13.5px; font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            box-shadow: 0 4px 12px var(--accent-glow);
        }
        .btn-save:hover { opacity: 0.9; transform: translateY(-1px); }
        .btn-save:active { transform: translateY(0); }

        .btn-danger {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 10px 20px;
            background: transparent;
            color: var(--danger);
            border: 1.5px solid var(--danger-border);
            border-radius: 10px;
            font-family: 'Poppins', sans-serif;
            font-size: 13px; font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-danger:hover { background: var(--danger); color: #fff; }

        .form-footer {
            display: flex; justify-content: flex-end;
            padding-top: 8px; margin-bottom: 20px;
        }

        /* ── LAST UPDATED CHIP ── */
        .last-updated {
            display: inline-flex; align-items: center; gap: 6px;
            font-size: 11.5px; color: var(--text-muted);
            padding: 6px 12px;
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 20px;
            margin-bottom: 28px;
        }
        .last-updated svg { width: 12px; height: 12px; fill: currentColor; }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(12px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .card { animation: fadeUp 0.35s cubic-bezier(0.16,1,0.3,1) both; }
        .card:nth-child(2) { animation-delay: 0.05s; }
        .card:nth-child(3) { animation-delay: 0.10s; }
        .card:nth-child(4) { animation-delay: 0.15s; }
        /* Match the newer admin shell used by role_admin_manageUsers.php */
        :root { --sidebar-w:260px; --topbar-h:60px; --bg-base:#f5f6fa; --bg-surface:#fff; --border-subtle:#e8eaef; --bg-elevated:#f0f2f7; }
        body { display:flex; background:var(--bg-base); font-family:'Poppins', sans-serif; font-size:14px; line-height:1.6; }
        button, input, textarea, select { font-family:'Poppins', sans-serif; }
        .admin-shell-topbar { position:fixed; top:0; left:0; right:0; height:var(--topbar-h); z-index:1000; display:flex; align-items:center; justify-content:space-between; padding:0 24px 0 0; background:var(--bg-surface); border-bottom:1px solid var(--border-subtle); box-shadow:0 1px 4px rgba(0,0,0,0.06); }
        .admin-shell-topbar .topbar-brand { display:flex; align-items:center; width:var(--sidebar-w); height:100%; padding:0 24px; gap:10px; border-right:1px solid var(--border-subtle); flex-shrink:0; }
        .admin-shell-topbar .brand-icon { width:32px; height:32px; border-radius:8px; background:none; display:flex; align-items:center; justify-content:center; overflow:hidden; }
        .admin-shell-topbar .brand-icon svg { width:18px; height:18px; fill:#fff; stroke:none; }
        .brand-wordmark { font-size:17px; font-weight:700; color:var(--text-primary); letter-spacing:-0.02em; line-height:1.15; }
        .brand-wordmark span { display:block; font-size:11px; font-weight:400; color:var(--text-muted); letter-spacing:0.08em; text-transform:uppercase; margin-top:-1px; }
        .menu-toggle { width:36px; height:36px; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:4px; border-radius:8px; transition:background .2s ease; margin-right:8px; border:0; background:transparent; cursor:pointer; }
        .menu-toggle:hover { background:var(--bg-elevated); }
        .menu-toggle span { display:block; width:16px; height:1.5px; background:var(--text-secondary); border-radius:2px; }
        .topbar-center { flex:1; display:flex; align-items:center; padding:0 24px; }
        .topbar-clock { font-size:12px; color:var(--text-muted); font-variant-numeric:tabular-nums; letter-spacing:0.04em; }
        .topbar-right { display:flex; align-items:center; gap:4px; }
        .topbar-icon-btn { width:36px; height:36px; border-radius:8px; display:flex; align-items:center; justify-content:center; color:var(--text-secondary); position:relative; transition:background .2s ease, color .2s ease; }
        .topbar-icon-btn:hover { background:var(--bg-elevated); color:var(--text-primary); }
        .topbar-icon-btn svg { width:18px; height:18px; fill:currentColor; }
        .topbar-divider { width:1px; height:24px; background:var(--border-subtle); margin:0 8px; }
        .topbar-avatar { border-radius:8px; background:linear-gradient(135deg,#1a7a4a,#0e9f6e); }
        .avatar-btn { display:flex; align-items:center; gap:10px; padding:6px 10px; border-radius:8px; border:0; background:transparent; cursor:pointer; color:var(--text-primary); }
        .avatar-btn:hover { background:var(--bg-elevated); }
        .avatar-circle { width:32px; height:32px; border-radius:8px; background:linear-gradient(135deg,#1a7a4a,#0e9f6e); display:flex; align-items:center; justify-content:center; font-weight:700; font-size:13px; color:#fff; flex-shrink:0; }
        .avatar-meta { line-height:1.3; text-align:left; }
        .avatar-name { font-size:13px; font-weight:500; color:var(--text-primary); }
        .avatar-role { font-size:10px; text-transform:uppercase; letter-spacing:0.08em; color:#1a7a4a; }
        .account-popover { position:fixed; top:72px; right:24px; width:min(360px, calc(100vw - 32px)); background:var(--bg-surface); border:1px solid var(--border-subtle); border-radius:28px; box-shadow:0 14px 40px rgba(26,29,46,.22); z-index:1200; padding:20px; display:none; text-align:center; }
        .account-popover.open { display:block; }
        .account-popover-close { position:absolute; top:16px; right:18px; width:32px; height:32px; border-radius:50%; display:flex; align-items:center; justify-content:center; color:var(--text-secondary); font-size:24px; line-height:1; }
        .account-popover-close:hover { background:rgba(26,29,46,.08); color:var(--text-primary); }
        .account-email { padding:4px 40px 14px; font-size:14px; font-weight:500; color:var(--text-primary); word-break:break-word; }
        .account-avatar-large { width:98px; height:98px; border-radius:50%; margin:14px auto 12px; background:#78909c; color:#fff; display:flex; align-items:center; justify-content:center; font-size:44px; font-weight:500; box-shadow:inset 0 0 0 4px rgba(255,255,255,.14); }
        .account-greeting { font-size:24px; font-weight:500; color:var(--text-primary); margin-bottom:16px; }
        .account-role-pill { display:inline-flex; align-items:center; justify-content:center; min-height:38px; padding:0 22px; border:1px solid rgba(26,29,46,.35); border-radius:999px; color:var(--accent); font-weight:600; background:var(--bg-elevated); margin-bottom:18px; }
        .account-actions { background:var(--bg-elevated); border-radius:18px; overflow:hidden; text-align:left; }
        .account-theme-toggle,
        .account-signout { display:flex; align-items:center; gap:14px; width:100%; padding:16px 22px; color:var(--text-primary); font-size:15px; font-weight:600; text-decoration:none; }
        .account-theme-toggle { justify-content:space-between; border:0; background:transparent; }
        .account-theme-toggle .theme-copy { display:inline-flex; align-items:center; gap:12px; }
        .account-theme-toggle svg { width:20px; height:20px; stroke:currentColor; fill:none; stroke-width:2; }
        .icon-sun { display:none; }
        .theme-switch { width:48px; height:28px; border-radius:999px; background:rgba(88,101,124,.16); padding:3px; display:inline-flex; align-items:center; flex-shrink:0; transition:background-color .2s ease; }
        .theme-switch-knob { width:22px; height:22px; border-radius:50%; background:#fff; box-shadow:0 4px 10px rgba(15,23,42,.16); transition:transform .2s ease; }
        .account-signout:hover,
        .account-theme-toggle:hover { background:var(--bg-elevated); }
        .account-signout svg { width:20px; height:20px; fill:currentColor; color:var(--text-secondary); }
        .sidebar { top:var(--topbar-h); width:var(--sidebar-w); border-right:1px solid var(--border-subtle); padding:16px 0 24px; transition:transform .2s cubic-bezier(.4,0,.2,1); z-index:900; }
        .sidebar.collapsed { transform:translateX(-100%); }
        .sidebar-brand { display:none; }
        .sidebar-nav { padding:0; gap:0; }
        .nav-label { font-size:10px; letter-spacing:0.12em; color:var(--text-muted); padding:16px 20px 6px; }
        .nav-link { border-radius:0; gap:12px; padding:9px 20px; color:var(--text-secondary); font-size:13.5px; font-weight:400; }
        .nav-link:hover { color:var(--text-primary); background:var(--bg-elevated); }
        .nav-link.active { color:#1a7a4a; background:rgba(26,122,74,0.10); font-weight:500; }
        .nav-link.active::before { content:''; position:absolute; left:0; top:4px; bottom:4px; width:3px; background:#1a7a4a; border-radius:0 3px 3px 0; }
        .nav-link svg { width:16px; height:16px; fill:currentColor; stroke:none; opacity:.7; }
        .sidebar-footer { margin-top:auto; padding:12px 12px 0; border-top:1px solid var(--border-subtle); }
        .sign-out-btn svg { width:16px; height:16px; fill:currentColor; stroke:none; }
        .main { margin-left:var(--sidebar-w); margin-top:var(--topbar-h); transition:margin-left .2s cubic-bezier(.4,0,.2,1); }
        .main.expanded { margin-left:0; }
        .main > .topbar { display:none; }
        .content { max-width:1440px; padding:32px 36px 48px; }
        .page-heading h1 { font-family:'Poppins', sans-serif; font-size:26px; font-weight:700; letter-spacing:-0.02em; line-height:1.2; }
        .page-heading p { font-family:'Poppins', sans-serif; font-size:13px; color:var(--text-muted); margin-top:6px; }
        .card-header h2 { font-family:'Poppins', sans-serif; font-size:14px; font-weight:600; }
        .card-header p, .setting-desc, .last-updated { font-family:'Poppins', sans-serif; }
        .card { border-radius:14px; border-color:var(--border-subtle); }
        @media (max-width:1024px) {
            .sidebar { transform:translateX(-100%); }
            .sidebar.mobile-open { transform:translateX(0); box-shadow:20px 0 60px rgba(0,0,0,.15); }
            .main { margin-left:0; }
        }
        [data-theme="dark"] {
            --bg:#10151d;
            --surface:#1c222d;
            --border:rgba(125,139,164,.16);
            --border-light:rgba(125,139,164,.12);
            --text-primary:#eef4ff;
            --text-secondary:#aeb9cb;
            --text-muted:#7f8ca3;
            --accent-dim:rgba(103,201,176,.14);
            --accent-glow:rgba(103,201,176,.2);
            --danger-dim:rgba(239,68,68,.10);
        }
        [data-theme="dark"] .icon-sun { display:block; }
        [data-theme="dark"] .icon-moon { display:none; }
        [data-theme="dark"] .theme-switch { background:rgba(103,201,176,.38); }
        [data-theme="dark"] .theme-switch-knob { transform:translateX(20px); }
        [data-theme="dark"] .account-popover { background:rgba(28,34,45,.96); }
        [data-theme="dark"] .account-popover-close,
        [data-theme="dark"] .account-role-pill,
        [data-theme="dark"] .account-signout,
        [data-theme="dark"] .account-theme-toggle { background:rgba(255,255,255,.08); border-color:rgba(125,139,164,.16); }
        :root {
            --bg:#f4f7fb;
            --surface:rgba(255,255,255,.88);
            --border:rgba(223,231,243,.82);
            --border-light:rgba(223,231,243,.62);
            --text-primary:#183153;
            --text-secondary:#53627a;
            --text-muted:#8fa0b8;
            --accent:#3f70b8;
            --accent-dim:rgba(63,112,184,.12);
            --accent-glow:rgba(63,112,184,.18);
            --success:#2f9d93;
            --danger:#d45f5d;
            --danger-dim:rgba(212,95,93,.14);
            --amber:#e3a535;
            --bg-base:#f4f7fb;
            --bg-surface:rgba(255,255,255,.88);
            --bg-elevated:#eef3fb;
            --border-subtle:rgba(223,231,243,.82);
        }
        [data-theme="dark"] {
            --bg:#07111f;
            --surface:rgba(15,24,38,.88);
            --border:rgba(42,57,80,.9);
            --border-light:rgba(42,57,80,.7);
            --text-primary:#dbe5f3;
            --text-secondary:#8fa0b8;
            --text-muted:#62738d;
            --accent:#5f91dd;
            --accent-dim:rgba(95,145,221,.18);
            --accent-glow:rgba(95,145,221,.2);
            --success:#67c9b0;
            --danger:#e58b89;
            --danger-dim:rgba(229,139,137,.18);
            --amber:#f0c46a;
            --bg-base:#07111f;
            --bg-surface:rgba(15,24,38,.88);
            --bg-elevated:#13243a;
            --border-subtle:rgba(42,57,80,.9);
        }
       body {
            background: var(--bg);
        }
        [data-theme="dark"] body {
            background:
                linear-gradient(90deg, rgba(3,12,25,.96), rgba(16,37,66,.9)),
                linear-gradient(135deg, #07111f 0 22%, #10233d 22% 44%, #0b182a 44% 66%, #18365e 66% 100%);
        }
        .admin-shell-topbar,
        .sidebar,
        .card { backdrop-filter:blur(18px); -webkit-backdrop-filter:blur(18px); }
        .brand-icon,
        .admin-shell-topbar .brand-icon {
            background: none;
            padding: 0;
            overflow: hidden;
        }
        .brand-logo-img { width:100%; height:100%; object-fit:contain; object-position:center; display:block; }
        .brand-logo-dark { display:none; }
        [data-theme="dark"] .brand-logo-light { display:none; }
        [data-theme="dark"] .brand-logo-dark { display:block; }
        .avatar-role,
        .nav-link.active { color:var(--accent); }
        .nav-link.active { background:var(--accent-dim); }
        .nav-link.active::before { background:var(--accent); }

        /* Dashboard-aligned admin shell */
        :root {
            --bg:#f5f6fa;
            --surface:#ffffff;
            --border:#e8eaef;
            --border-light:#f0f2f7;
            --text-primary:#1a1d2e;
            --text-secondary:#52576e;
            --text-muted:#8b90a7;
            --accent:#1a7a4a;
            --accent-dim:rgba(26,122,74,0.10);
            --accent-glow:rgba(26,122,74,0.18);
            --success:#1a7a4a;
            --danger:#d93025;
            --danger-dim:#fce8e6;
            --danger-border:#f4b8b3;
            --amber:#d97706;
            --bg-base:#f5f6fa;
            --bg-surface:#ffffff;
            --bg-elevated:#f0f2f7;
            --border-subtle:#e8eaef;
            --sidebar-w:260px;
            --topbar-h:60px;
        }
        body { background: var(--bg); }
        [data-theme="dark"] body { background: var(--bg); }
        .content { max-width: 1440px; padding: 32px 36px 48px; }
        .card { border-color: var(--border); border-radius: 14px; box-shadow: none; backdrop-filter: none; -webkit-backdrop-filter: none; }
        .admin-shell-topbar,
        .sidebar { backdrop-filter: none; -webkit-backdrop-filter: none; }
        .nav-badge { background: var(--danger); color: #fff; border-radius: 20px; }
        .nav-badge.dim { background: var(--bg-elevated); color: var(--text-muted); }
        [data-theme="dark"] {
            --bg:#07111f;
            --surface:#0d1b2e;
            --border:rgba(125,139,164,.16);
            --border-light:rgba(125,139,164,.12);
            --text-primary:#eef4ff;
            --text-secondary:#aeb9cb;
            --text-muted:#7f8ca3;
            --accent:#0e9f6e;
            --accent-dim:rgba(14,159,110,0.12);
            --accent-glow:rgba(14,159,110,0.20);
            --success:#0e9f6e;
            --danger:#e58b89;
            --danger-dim:rgba(229,139,137,.18);
            --amber:#f0c46a;
            --bg-base:#07111f;
            --bg-surface:#0d1b2e;
            --bg-elevated:#13202e;
            --border-subtle:rgba(125,139,164,.16);
        }
    </style>

    <!-- SVG ICONS SPRITE -->
    <svg style="display:none" xmlns="http://www.w3.org/2000/svg">
        <symbol id="ic-logo" viewBox="0 0 24 24"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></symbol>
        <symbol id="ic-dashboard" viewBox="0 0 24 24"><path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/></symbol>
        <symbol id="ic-users" viewBox="0 0 24 24"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></symbol>
        <symbol id="ic-class" viewBox="0 0 24 24"><path d="M18 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-2 14H8v-2h8v2zm0-4H8v-2h8v2zm0-4H8V6h8v2z"/></symbol>
        <symbol id="ic-pending" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67V7z"/></symbol>
        <symbol id="ic-settings" viewBox="0 0 24 24"><path d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.06-.94l2.03-1.58c.18-.14.23-.41.12-.61l-1.92-3.32c-.12-.22-.37-.29-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54c-.04-.24-.24-.41-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.56-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.04.3-.07.63-.07.94s.02.64.07.94l-2.03 1.58c-.18.14-.23.41-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z"/></symbol>
        <symbol id="ic-bell" viewBox="0 0 24 24"><path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6v-5c0-3.07-1.64-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.63 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z"/></symbol>
        <symbol id="ic-logout" viewBox="0 0 24 24"><path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/></symbol>
        <symbol id="ic-check" viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></symbol>
        <symbol id="ic-alert" viewBox="0 0 24 24"><path d="M1 21h22L12 2 1 21zm12-3h-2v-2h2v2zm0-4h-2v-4h2v4z"/></symbol>
        <symbol id="ic-trash" viewBox="0 0 24 24"><path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></symbol>
    </svg>
</head>
<body>

<header class="admin-shell-topbar">
    <div class="topbar-brand">
        <button class="menu-toggle" id="menuToggle" aria-label="Toggle sidebar">
            <span></span><span></span><span></span>
        </button>
        <div class="brand-icon">
            <img class="brand-logo-img brand-logo-light" src="img/Light Icon.png" alt="Helios University">
            <img class="brand-logo-img brand-logo-dark" src="img/Dark Icon.png" alt="Helios University">
        </div>
        <div class="brand-wordmark">Helios<span>Admin Center</span></div>
    </div>
    <div class="topbar-center"><span class="topbar-clock" id="liveClock"></span></div>
    <div class="topbar-right">
        <a href="role_admin_systemSettings.php" class="topbar-icon-btn" title="System Settings" aria-label="System settings">
            <svg><use href="#ic-settings"/></svg>
        </a>
        <div class="topbar-divider"></div>
        <button type="button" class="avatar-btn" id="accountButton" title="Account" aria-haspopup="dialog" aria-expanded="false">
            <div class="avatar-circle"><svg viewBox="0 0 24 24" style="width:18px;height:18px;fill:#fff;"><path d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.06-.94l2.03-1.58c.18-.14.23-.41.12-.61l-1.92-3.32c-.12-.22-.37-.29-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54c-.04-.24-.24-.41-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59-.24-1.13-.56-1.62-.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.49.12.61l2.03 1.58c-.05.3-.09.63-.09.94s.02.64.06.94l-2.03 1.58c-.18.14-.23.41-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .43-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.49-.12-.61l-2.01-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z"/></svg></div>
            <div class="avatar-meta">
                <div class="avatar-name"><?= $username ?></div>
                <div class="avatar-role">Administrator</div>
            </div>
        </button>
    </div>
</header>

<div class="account-popover" id="accountPopover" role="dialog" aria-label="Account overview">
    <button type="button" class="account-popover-close" id="accountPopoverClose" aria-label="Close account overview">&times;</button>
    <div class="account-email"><?= $username ?></div>
    <div class="account-avatar-large"><?= $initials ?></div>
    <div class="account-greeting">Hi, <?= $username ?>!</div>
    <div class="account-role-pill">Administrator Account</div>
    <div class="account-actions">
        <button type="button" class="account-theme-toggle theme-toggle" aria-label="Toggle dark mode">
            <span class="theme-copy">
                <svg class="icon-moon" viewBox="0 0 24 24"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
                <svg class="icon-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
                Dark mode
            </span>
            <span class="theme-switch" aria-hidden="true"><span class="theme-switch-knob"></span></span>
        </button>
        <a href="logout.php" class="account-signout">
            <svg><use href="#ic-logout"/></svg>
            Sign out
        </a>
    </div>
</div>

<!-- ── SIDEBAR ── -->
<aside class="sidebar" id="sidebar">
    <a href="dashboard_admin.php" class="sidebar-brand">
        <div class="brand-icon">
            <img class="brand-logo-img brand-logo-light" src="img/Light Icon.png" alt="Helios University">
            <img class="brand-logo-img brand-logo-dark" src="img/Dark Icon.png" alt="Helios University">
        </div>
        <div class="brand-text">
            <span class="brand-name">Helios</span>
            <span class="brand-sub">Admin Center</span>
        </div>
    </a>

    <nav class="sidebar-nav">
        <div class="nav-label">Overview</div>
        <a href="dashboard_admin.php" class="nav-link">
            <svg><use href="#ic-dashboard"/></svg> Dashboard
        </a>
        <div class="nav-label">Management</div>
        <a href="role_admin_manageUsers.php" class="nav-link">
            <svg><use href="#ic-users"/></svg> Manage Users
            <?php if ($totalUsers > 0): ?><span class="nav-badge dim"><?= $totalUsers ?></span><?php endif; ?>
        </a>
        <a href="pending_approval.php" class="nav-link">
            <svg><use href="#ic-pending"/></svg> Pending Approval
            <?php if ($pendingCount > 0): ?><span class="nav-badge"><?= $pendingCount ?></span><?php endif; ?>
        </a>
        <a href="role_admin_manageClasses.php" class="nav-link">
            <svg><use href="#ic-class"/></svg> Classes
            <?php if ($totalClasses > 0): ?><span class="nav-badge dim"><?= $totalClasses ?></span><?php endif; ?>
        </a>

        <div class="nav-label">System</div>
        <a href="role_admin_systemSettings.php" class="nav-link active">
            <svg><use href="#ic-settings"/></svg> System Settings
        </a>
    </nav>

    <div class="sidebar-footer">
        <a href="logout.php" class="sign-out-btn">
            <svg><use href="#ic-logout"/></svg> Sign Out
        </a>
    </div>
</aside>

<!-- ── MAIN ── -->
<div class="main" id="mainContent">
    <div class="topbar">
        <span class="topbar-title">System Settings</span>
        <div class="topbar-right">
            <div class="topbar-avatar" style="display:flex;align-items:center;justify-content:center;"><svg viewBox="0 0 24 24" style="width:18px;height:18px;fill:#fff;"><path d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.06-.94l2.03-1.58c.18-.14.23-.41.12-.61l-1.92-3.32c-.12-.22-.37-.29-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54c-.04-.24-.24-.41-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59-.24-1.13-.56-1.62-.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.49.12.61l2.03 1.58c-.05.3-.09.63-.09.94s.02.64.06.94l-2.03 1.58c-.18.14-.23.41-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .43-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.49-.12-.61l-2.01-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z"/></svg></div>
        </div>
    </div>

    <div class="content">
        <div class="page-heading">
            <h1>System Settings</h1>
            <p>Control global behavior and security protocols.</p>
        </div>

        <?php if (!empty($config['last_updated'])): ?>
        <div class="last-updated">
            <svg><use href="#ic-check"/></svg>
            Last saved: <?= htmlspecialchars($config['last_updated']) ?>
        </div>
        <?php endif; ?>

        <?php if ($message): ?>
        <div class="alert alert-<?= $msgType ?>">
            <svg style="width:16px;height:16px;fill:currentColor;flex-shrink:0"><use href="#ic-<?= $msgType === 'success' ? 'check' : 'alert' ?>"/></svg>
            <?= $message ?>
        </div>
        <?php endif; ?>

        <form method="POST">

            <!-- Identity & Branding -->
            <div class="card">
                <div class="card-header">
                    <h2>Identity &amp; Branding</h2>
                    <p>Customize system identification strings.</p>
                </div>
                <div class="card-body">
                    <div class="setting-row">
                        <div class="setting-info">
                            <span class="setting-label">Organization Name</span>
                            <span class="setting-desc">Primary title shown on headers and emails.</span>
                        </div>
                        <input type="text" class="config-input" name="org_name" value="<?= htmlspecialchars($config['org_name']) ?>">
                    </div>
                    <div class="setting-row">
                        <div class="setting-info">
                            <span class="setting-label">Support Email Address</span>
                            <span class="setting-desc">System notification return address.</span>
                        </div>
                        <input type="email" class="config-input" name="sys_email" value="<?= htmlspecialchars($config['sys_email']) ?>">
                    </div>
                </div>
            </div>

            <!-- Maintenance Mode -->
            <div class="card">
                <div class="card-header">
                    <h2>Maintenance Mode</h2>
                    <p>Lock the platform and show a countdown to users.</p>
                </div>
                <div class="card-body">
                    <div class="setting-row">
                        <div class="setting-info">
                            <span class="setting-label">Enable Maintenance</span>
                            <span class="setting-desc">Lock the platform for everyone except administrators.</span>
                        </div>
                        <label class="switch">
                            <input type="checkbox" name="maintenance" <?= ($config['maintenance'] ?? false) ? 'checked' : '' ?>>
                            <span class="slider"></span>
                        </label>
                    </div>
                    <div class="setting-row">
                        <div class="setting-info">
                            <span class="setting-label">Duration (Minutes)</span>
                            <span class="setting-desc">Countdown timer shown to users during maintenance.</span>
                        </div>
                        <input type="number" class="config-input" name="m_duration" value="<?= htmlspecialchars($config['m_duration'] ?? '60') ?>">
                    </div>
                    <div class="setting-row">
                        <div class="setting-info">
                            <span class="setting-label">Maintenance Work</span>
                            <span class="setting-desc">Brief description of what's being done.</span>
                        </div>
                        <input type="text" class="config-input" name="m_work" value="<?= htmlspecialchars($config['m_work'] ?? '') ?>" placeholder="e.g. Server optimization">
                    </div>
                </div>
            </div>

            <!-- Security & Registration -->
            <div class="card">
                <div class="card-header">
                    <h2>Security &amp; Registration</h2>
                    <p>Toggles for account protection and creation.</p>
                </div>
                <div class="card-body">
                    <div class="setting-row">
                        <div class="setting-info">
                            <span class="setting-label">Allow New Registrations</span>
                            <span class="setting-desc">Control whether new accounts can be created publicly.</span>
                        </div>
                        <label class="switch">
                            <input type="checkbox" name="allow_reg" <?= $config['allow_reg'] ? 'checked' : '' ?>>
                            <span class="slider"></span>
                        </label>
                    </div>
                    <div class="setting-row">
                        <div class="setting-info">
                            <span class="setting-label">Strict 2FA Enforcement</span>
                            <span class="setting-desc">Require OTP verification for every sign-in attempt.</span>
                        </div>
                        <label class="switch">
                            <input type="checkbox" name="enforce_otp" <?= $config['enforce_otp'] ? 'checked' : '' ?>>
                            <span class="slider"></span>
                        </label>
                    </div>
                </div>
            </div>

            <div class="form-footer">
                <button type="submit" name="save_settings" class="btn-save">
                    <svg style="width:15px;height:15px;fill:currentColor"><use href="#ic-check"/></svg>
                    Save Changes
                </button>
            </div>

        </form>

        <!-- Danger Zone -->
        <div class="card danger">
            <div class="card-header">
                <h2>Danger Zone</h2>
                <p>Irreversible operations — proceed with caution.</p>
            </div>
            <div class="card-body">
                <div class="setting-row">
                    <div class="setting-info">
                        <span class="setting-label" style="color:var(--danger);">Purge Assignment Records</span>
                        <span class="setting-desc">Permanently delete all tasks and submission history.</span>
                    </div>
                    <form method="POST" style="margin:0">
                        <button type="submit" name="reset_assignments" class="btn-danger"
                                onclick="return confirm('This will permanently delete all assignment data. Are you sure?')">
                            <svg style="width:14px;height:14px;fill:currentColor"><use href="#ic-trash"/></svg>
                            Purge Records
                        </button>
                    </form>
                </div>
            </div>
        </div>

    </div><!-- /content -->
</div><!-- /main -->

<script>
document.querySelectorAll('.theme-toggle').forEach((toggle) => {
    toggle.addEventListener('click', () => {
        const nextTheme = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', nextTheme);
        localStorage.setItem('theme', nextTheme);
    });
});

const clockEl = document.getElementById('liveClock');
function updateClock() {
    const now = new Date();
    const hh = String(now.getHours()).padStart(2, '0');
    const mm = String(now.getMinutes()).padStart(2, '0');
    const ss = String(now.getSeconds()).padStart(2, '0');
    if (clockEl) clockEl.textContent = `${hh}:${mm}:${ss}`;
}
updateClock();
setInterval(updateClock, 1000);

const sidebar = document.getElementById('sidebar');
const menuToggle = document.getElementById('menuToggle');
const mainContent = document.getElementById('mainContent');
const accountButton = document.getElementById('accountButton');
const accountPopover = document.getElementById('accountPopover');
const accountPopoverClose = document.getElementById('accountPopoverClose');
if (menuToggle && sidebar && mainContent) {
    menuToggle.addEventListener('click', () => {
        if (window.innerWidth > 1024) {
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('expanded');
        } else {
            sidebar.classList.toggle('mobile-open');
        }
    });
}
document.addEventListener('click', (e) => {
    if (window.innerWidth <= 1024 && sidebar && sidebar.classList.contains('mobile-open')
        && !sidebar.contains(e.target) && !menuToggle.contains(e.target)) {
        sidebar.classList.remove('mobile-open');
    }

    if (accountPopover && accountPopover.classList.contains('open')
        && !accountPopover.contains(e.target) && !accountButton.contains(e.target)) {
        accountPopover.classList.remove('open');
        accountButton.setAttribute('aria-expanded', 'false');
    }
});
if (accountButton && accountPopover) {
    accountButton.addEventListener('click', (e) => {
        e.stopPropagation();
        const isOpen = accountPopover.classList.toggle('open');
        accountButton.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });
}
if (accountPopoverClose && accountPopover && accountButton) {
    accountPopoverClose.addEventListener('click', () => {
        accountPopover.classList.remove('open');
        accountButton.setAttribute('aria-expanded', 'false');
        accountButton.focus();
    });
}
</script>
</body>
</html>
