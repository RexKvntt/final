<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

if (!isset($_SESSION['username']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: login.php');
    exit;
}

$username = htmlspecialchars($_SESSION['username']);
$initials = strtoupper(substr($username, 0, 2));

require_once 'db.php';

// Fetch all users from DB
$allUsers = $pdo->query("
    SELECT username, fullname, role, status,
           DATE(activated_at) as joined
      FROM users
     ORDER BY registered_at DESC
")->fetchAll();

// Fetch classes with member count
$allClasses = $pdo->query("
    SELECT c.id, c.name, c.subject, c.owner,
           COUNT(cm.username) as member_count
      FROM classes c
      LEFT JOIN class_members cm ON cm.class_id = c.id
     GROUP BY c.id
")->fetchAll();

// Fetch recent notifications
$allNotifs = $pdo->query("
    SELECT id, type, message, time, is_read as `read`
      FROM notifications
     ORDER BY time DESC
     LIMIT 10
")->fetchAll();

// Stats
$totalUsers    = count($allUsers);
$totalStudents = count(array_filter($allUsers, fn($u) => $u['role'] === 'student'));
$totalFaculty  = count(array_filter($allUsers, fn($u) => $u['role'] === 'faculty'));
$pendingCount  = count(array_filter($allUsers, fn($u) => $u['status'] === 'pending'));
$disabledCount = count(array_filter($allUsers, fn($u) => $u['status'] === 'disabled'));
$activeClasses = count($allClasses);
$unreadNotifs  = count(array_filter($allNotifs, fn($n) => !$n['read']));

$pendingUsers = array_filter($allUsers, fn($u) => $u['status'] === 'pending');
$recentUsers  = array_slice($allUsers, 0, 6);

$hour     = (int)date('H');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');

$stmtMe = $pdo->prepare("SELECT firstname, lastname, fullname, role FROM users WHERE username = ?");
$stmtMe->execute([$usernameRaw]);
$meRow = $stmtMe->fetch();
$displayName = $meRow['fullname'] ?? $usernameRaw;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard — Helios</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-base:            #f8f9fa;
            --bg-surface:         #ffffff;
            --border-light:       #dadce0;
            --text-primary:       #3c4043;
            --text-secondary:     #5f6368;
            --text-muted:         #80868b;
            
            --accent-green:       #1e8e3e;
            --accent-green-hover: #177030;
            --accent-green-dim:   #e6f4ea;
            
            --accent-blue:        #1a73e8;
            --accent-blue-hover:  #155dba;
            --accent-blue-dim:    #e8f0fe;
            
            --accent-red:         #d93025;
            --accent-red-hover:   #a50e0e;
            --accent-red-dim:     #fce8e6;
            
            --accent-yellow:      #f9ab00;
            --accent-yellow-dim:  #fef7e0;

            --sidebar-w:          280px;
            --topbar-h:           64px;
            --ease:               0.2s cubic-bezier(0.4, 0, 0.2, 1);
            --shadow-subtle:      0 1px 2px 0 rgba(60,64,67,0.3), 0 1px 3px 1px rgba(60,64,67,0.15);
            --shadow-hover:       0 1px 3px 0 rgba(60,64,67,0.3), 0 4px 8px 3px rgba(60,64,67,0.15);
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; background: var(--bg-base); color: var(--text-primary); font-family: 'Poppins', sans-serif; font-size: 14px; line-height: 1.5; -webkit-font-smoothing: antialiased; }
        a { text-decoration: none; color: inherit; }
        button { font-family: inherit; background: none; border: none; cursor: pointer; color: inherit; outline: none; }
        ul, ol { list-style: none; }
        
        .app { display: flex; flex-direction: column; min-height: 100vh; }
        .topbar { position: fixed; top: 0; left: 0; right: 0; height: var(--topbar-h); z-index: 1000; display: flex; align-items: center; justify-content: space-between; padding: 0 24px 0 0; background: var(--bg-surface); border-bottom: 1px solid var(--border-light); }
        .topbar-brand { display: flex; align-items: center; width: var(--sidebar-w); padding: 0 24px; height: 100%; gap: 16px; flex-shrink: 0; }
        .brand-icon { width: 36px; height: 36px; background: none; border-radius: 8px; display: flex; align-items: center; justify-content: center; overflow: hidden; }
        .brand-icon svg { width: 20px; height: 20px; fill: currentColor; }
        .brand-wordmark { font-size: 18px; font-weight: 600; color: var(--text-primary); letter-spacing: -0.01em; }
        .brand-wordmark span { font-weight: 400; color: var(--text-secondary); font-size: 12px; display: block; margin-top: -2px; }
        
        .topbar-center { flex: 1; display: flex; align-items: center; padding: 0 24px; }
        .topbar-clock { font-size: 13px; font-weight: 500; color: var(--text-secondary); }
        
        .topbar-right { display: flex; align-items: center; gap: 8px; }
        .avatar-btn { display: flex; align-items: center; gap: 12px; padding: 6px 16px 6px 6px; border-radius: 32px; border: 1px solid transparent; transition: all var(--ease); }
        .avatar-btn:hover { background: rgba(60,64,67,0.04); border-color: var(--border-light); }
        .avatar-circle { width: 32px; height: 32px; border-radius: 50%; background: var(--accent-green); display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 13px; color: #fff; }
        .avatar-name { font-size: 14px; font-weight: 500; color: var(--text-primary); }

        .menu-toggle { width: 40px; height: 40px; border-radius: 50%; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px; transition: background var(--ease); }
        .menu-toggle:hover { background: rgba(60,64,67,0.08); }
        .menu-toggle span { display: block; width: 18px; height: 2px; background: var(--text-secondary); border-radius: 2px; }

        .body-wrap { display: flex; flex: 1; margin-top: var(--topbar-h); }
        .sidebar { position: fixed; top: var(--topbar-h); bottom: 0; left: 0; width: var(--sidebar-w); background: var(--bg-surface); overflow-y: auto; z-index: 900; padding: 16px 0; transition: transform var(--ease); display: flex; flex-direction: column; border-right: 1px solid var(--border-light); }
        .sidebar.collapsed { transform: translateX(-100%); }
        .nav-section-label { font-size: 11px; font-weight: 600; letter-spacing: 0.8px; text-transform: uppercase; color: var(--text-secondary); padding: 16px 24px 8px; }
        .nav-link { display: flex; align-items: center; gap: 16px; padding: 12px 24px; color: var(--text-primary); font-size: 14px; font-weight: 500; transition: all var(--ease); border-radius: 0 24px 24px 0; margin-right: 16px; position: relative; }
        .nav-link:hover { background: rgba(60,64,67,0.04); }
        .nav-link.active { color: var(--accent-blue); background: var(--accent-blue-dim); }
        .nav-link svg { width: 20px; height: 20px; fill: currentColor; opacity: 0.7; }
        .nav-link.active svg { opacity: 1; }
        .nav-badge { margin-left: auto; background: var(--accent-red); color: white; font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 12px; }
        .nav-badge.dim { background: var(--border-light); color: var(--text-secondary); }
        .nav-divider { height: 1px; background: var(--border-light); margin: 8px 0; }

        .main { flex: 1; margin-left: var(--sidebar-w); padding: 32px 48px 64px; transition: margin-left var(--ease); max-width: 1400px; }
        .main.expanded { margin-left: 0; }

        .page-header { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 32px; }
        .page-title { font-size: 28px; font-weight: 600; color: var(--text-primary); letter-spacing: -0.02em; }
        .page-subtitle { font-size: 14px; color: var(--text-secondary); margin-top: 4px; }
        .page-date { font-size: 16px; font-weight: 600; color: var(--text-primary); text-align: right; }
        .page-date-sub { font-size: 12px; color: var(--text-secondary); font-weight: 400; }


        .kpi-strip { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-bottom: 40px; }
        .kpi-card { background: var(--bg-surface); border: 1px solid var(--border-light); border-radius: 8px; padding: 24px; display: flex; flex-direction: column; transition: box-shadow var(--ease); }
        .kpi-card:hover { box-shadow: var(--shadow-subtle); }
        .kpi-header { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; }
        .kpi-icon { width: 40px; height: 40px; border-radius: 8px; display: flex; align-items: center; justify-content: center; }
        .kpi-icon svg { width: 20px; height: 20px; fill: currentColor; }
        
        .kpi-card.blue .kpi-icon { background: var(--accent-blue-dim); color: var(--accent-blue); }
        .kpi-card.green .kpi-icon { background: var(--accent-green-dim); color: var(--accent-green); }
        .kpi-card.yellow .kpi-icon { background: var(--accent-yellow-dim); color: var(--accent-yellow); }
        .kpi-card.red .kpi-icon { background: var(--accent-red-dim); color: var(--accent-red); }

        .kpi-label { font-size: 13px; color: var(--text-secondary); font-weight: 500; }
        .kpi-value { font-size: 32px; font-weight: 600; color: var(--text-primary); line-height: 1; }

        .content-grid { display: grid; grid-template-columns: 1fr 400px; gap: 24px; }
        
        .panel { background: var(--bg-surface); border: 1px solid var(--border-light); border-radius: 8px; overflow: hidden; margin-bottom: 24px; }
        .panel-header { display: flex; align-items: center; justify-content: space-between; padding: 16px 24px; border-bottom: 1px solid var(--border-light); }
        .panel-title { font-size: 16px; font-weight: 500; color: var(--text-primary); display: flex; align-items: center; gap: 8px; }
        .panel-action { font-size: 13px; font-weight: 500; color: var(--accent-blue); transition: color var(--ease); }
        .panel-action:hover { color: var(--accent-blue-hover); }

        .table-wrap { overflow-x: auto; }
        .table { width: 100%; border-collapse: collapse; }
        .table th { font-size: 12px; font-weight: 500; color: var(--text-secondary); text-align: left; padding: 12px 24px; border-bottom: 1px solid var(--border-light); }
        .table td { padding: 16px 24px; border-bottom: 1px solid var(--border-light); }
        .table tr:last-child td { border-bottom: none; }
        .table tr:hover { background: rgba(60,64,67,0.02); }

        .user-cell { display: flex; align-items: center; gap: 12px; }
        .user-avatar { width: 36px; height: 36px; min-width: 36px; min-height: 36px; flex: 0 0 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 500; color: white; font-size: 14px; }
        .user-avatar.s { background: var(--accent-blue); }
        .user-avatar.f { background: var(--accent-green); }
        .user-avatar.a { background: var(--accent-red); }
        
        .user-name { font-size: 14px; font-weight: 500; color: var(--text-primary); }
        .user-handle { font-size: 12px; color: var(--text-secondary); }

        .status-chip { display: inline-flex; padding: 4px 10px; border-radius: 16px; font-size: 12px; font-weight: 500; text-transform: capitalize; }
        .status-chip.active { background: var(--accent-green-dim); color: var(--accent-green-hover); }
        .status-chip.pending { background: var(--accent-yellow-dim); color: #b37400; }
        .status-chip.disabled { background: var(--bg-base); color: var(--text-secondary); border: 1px solid var(--border-light); }
        
        .role-text { font-size: 13px; color: var(--text-primary); text-transform: capitalize; }

        .action-btns { display: flex; gap: 8px; }
        .btn-icon { width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: var(--text-secondary); transition: background var(--ease); }
        .btn-icon:hover { background: rgba(60,64,67,0.08); color: var(--text-primary); }
        .btn-icon svg { width: 16px; height: 16px; fill: currentColor; }

        .list-item { display: flex; align-items: center; gap: 16px; padding: 16px 24px; border-bottom: 1px solid var(--border-light); transition: background var(--ease); }
        .list-item:last-child { border-bottom: none; }
        .list-item:hover { background: rgba(60,64,67,0.02); }
        .list-icon { width: 40px; height: 40px; border-radius: 8px; background: var(--bg-base); border: 1px solid var(--border-light); display: flex; align-items: center; justify-content: center; color: var(--text-secondary); font-weight: 500; }
        .list-content { flex: 1; }
        .list-title { font-size: 14px; font-weight: 500; color: var(--text-primary); }
        .list-sub { font-size: 12px; color: var(--text-secondary); margin-top: 2px; }

        .quick-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; padding: 16px; }
        .qa-card { display: flex; flex-direction: column; gap: 8px; padding: 16px; border: 1px solid var(--border-light); border-radius: 8px; transition: all var(--ease); }
        .qa-card:hover { border-color: var(--accent-blue); background: rgba(26,115,232,0.02); }
        .qa-icon { width: 32px; height: 32px; border-radius: 50%; background: var(--accent-blue-dim); color: var(--accent-blue); display: flex; align-items: center; justify-content: center; }
        .qa-icon svg { width: 16px; height: 16px; fill: currentColor; }
        .qa-title { font-size: 13px; font-weight: 500; color: var(--text-primary); }

        @media (max-width: 1024px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.mobile-open { transform: translateX(0); }
            .main { margin-left: 0; padding: 24px; }
            .content-grid { grid-template-columns: 1fr; }
        }
        /* Match the newer admin shell used by role_admin_manageUsers.php */
        :root {
            --bg-base:#f5f6fa; --border-light:#e8eaef; --text-primary:#1a1d2e;
            --text-secondary:#52576e; --text-muted:#8b90a7; --accent-green:#1a7a4a;
            --accent-blue:#1a7a4a; --accent-blue-dim:rgba(26,122,74,0.10);
            --sidebar-w:260px; --topbar-h:60px;
        }
        .topbar { padding:0 24px 0 0; border-bottom:1px solid var(--border-light); box-shadow:0 1px 4px rgba(0,0,0,0.06); }
        .topbar-brand { gap:10px; border-right:1px solid var(--border-light); padding:0 24px; }
        .brand-icon { width:32px; height:32px; background:none; }
        .brand-icon svg { width:18px; height:18px; }
        .brand-wordmark { font-size:17px; font-weight:700; letter-spacing:-0.02em; }
        .brand-wordmark span { font-size:11px; color:var(--text-muted); letter-spacing:0.08em; text-transform:uppercase; margin-top:-3px; }
        .menu-toggle { width:36px; height:36px; border-radius:8px; gap:4px; margin-right:8px; }
        .menu-toggle:hover { background:var(--bg-elevated); }
        .menu-toggle span { display:block; width:16px; height:1.5px; background:var(--text-secondary); border-radius:2px; }
        .topbar-clock { font-size:12px; color:var(--text-muted); font-variant-numeric:tabular-nums; letter-spacing:0.04em; }
        .topbar-icon-btn { width:36px; height:36px; border-radius:8px; display:flex; align-items:center; justify-content:center; color:var(--text-secondary); position:relative; transition:background var(--ease), color var(--ease); }
        .topbar-icon-btn:hover { background:var(--bg-elevated); color:var(--text-primary); }
        .topbar-icon-btn svg { width:18px; height:18px; fill:currentColor; }
        .notif-badge { position:absolute; top:5px; right:5px; width:8px; height:8px; background:var(--accent-red); border-radius:50%; border:2px solid var(--bg-surface); }
        .topbar-divider { width:1px; height:24px; background:var(--border-light); margin:0 8px; }
        .avatar-btn { border-radius:8px; padding:6px 10px; border:0; background:transparent; cursor:pointer; }
        .avatar-circle { border-radius:8px; background:linear-gradient(135deg,#1a7a4a,#0e9f6e); }
        .avatar-meta { line-height:1.3; text-align:left; }
        .avatar-name { font-size:13px; font-weight:500; color:var(--text-primary); }
        .avatar-role { font-size:10px; text-transform:uppercase; letter-spacing:0.08em; color:#1a7a4a; }
        .account-popover { position:fixed; top:72px; right:24px; width:min(360px, calc(100vw - 32px)); background:var(--bg-surface); border:1px solid var(--border-light); border-radius:28px; box-shadow:0 14px 40px rgba(26,29,46,.22); z-index:1200; padding:20px; display:none; text-align:center; }
        .account-popover.open { display:block; }
        .account-popover-close { position:absolute; top:16px; right:18px; width:32px; height:32px; border-radius:50%; display:flex; align-items:center; justify-content:center; color:var(--text-secondary); font-size:24px; line-height:1; }
        .account-popover-close:hover { background:rgba(26,29,46,.08); color:var(--text-primary); }
        .account-email { padding:4px 40px 14px; font-size:14px; font-weight:500; color:var(--text-primary); word-break:break-word; }
        .account-avatar-large { width:98px; height:98px; border-radius:50%; margin:14px auto 12px; background:#78909c; color:#fff; display:flex; align-items:center; justify-content:center; font-size:44px; font-weight:500; box-shadow:inset 0 0 0 4px rgba(255,255,255,.14); }
        .account-greeting { font-size:24px; font-weight:500; color:var(--text-primary); margin-bottom:16px; }
        .account-role-pill { display:inline-flex; align-items:center; justify-content:center; min-height:38px; padding:0 22px; border:1px solid rgba(26,29,46,.35); border-radius:999px; color:var(--accent); font-weight:600; background:var(--bg-elevated); margin-bottom:18px; }
        .account-actions { background:var(--bg-elevated); border-radius:18px; overflow:hidden; text-align:left; }
        .account-signout { display:flex; align-items:center; gap:14px; width:100%; padding:16px 22px; color:var(--text-primary); font-size:15px; font-weight:600; text-decoration:none; }
        .account-signout:hover { background:var(--bg-elevated); }
        .account-signout svg { width:20px; height:20px; fill:currentColor; color:var(--text-secondary); }
        .sidebar { padding:16px 0 24px; border-right:1px solid var(--border-light); }
        .nav-section-label { font-size:10px; letter-spacing:0.12em; color:var(--text-muted); padding:16px 20px 6px; }
        .nav-link { gap:12px; padding:9px 20px; color:var(--text-secondary); font-size:13.5px; font-weight:400; border-radius:0; margin-right:0; }
        .nav-link:hover { color:var(--text-primary); background:var(--bg-elevated); }
        .nav-link.active { color:#1a7a4a; background:rgba(26,122,74,0.10); font-weight:500; }
        .nav-link.active::before { content:''; position:absolute; left:0; top:4px; bottom:4px; width:3px; background:#1a7a4a; border-radius:0 3px 3px 0; }
        .nav-link svg { width:16px; height:16px; opacity:0.7; }
        .nav-divider { background:var(--border-light); margin:8px 16px; }
        .sidebar-footer { margin-top:auto; padding:12px 12px 0; border-top:1px solid var(--border-light); }
        .sidebar-footer .nav-link { border-radius:8px; }
        .main { padding:32px 36px 48px; max-width:1440px; }
        .panel, .kpi-card, .qa-card { border-color:var(--border-light); border-radius:14px; }
        [data-theme="dark"] {
            --bg-base:#10151d;
            --bg-surface:#1c222d;
            --border-light:rgba(125,139,164,.16);
            --text-primary:#eef4ff;
            --text-secondary:#aeb9cb;
            --text-muted:#7f8ca3;
            --accent-blue-dim:rgba(103,201,176,.14);
            --accent-green-dim:rgba(103,201,176,.14);
        }
        [data-theme="dark"] .topbar,
        [data-theme="dark"] .sidebar,
        [data-theme="dark"] .panel,
        [data-theme="dark"] .kpi-card,
        [data-theme="dark"] .qa-card { background:var(--bg-surface); border-color:var(--border-light); }
        .account-popover {
            width:min(340px, calc(100vw - 32px));
            padding:14px;
            border-radius:28px;
            background:rgba(255,255,255,.94);
            text-align:left;
        }
        .account-popover-close { top:14px; right:14px; background:#f4f6f8; }
        .account-email { padding:10px 42px 4px 10px; color:var(--text-secondary); font-size:12px; }
        .account-avatar-large {
            width:72px;
            height:72px;
            margin:12px auto 10px;
            border-radius:22px;
            font-size:28px;
            background:linear-gradient(135deg,#1a7a4a,#67c9b0);
        }
        .account-greeting { text-align:center; font-size:19px; font-weight:800; margin-bottom:8px; }
        .account-role-pill {
            display:flex;
            width:max-content;
            margin:0 auto 14px;
            min-height:30px;
            padding:0 14px;
            border:0;
            background:#eef1f4;
            color:var(--text-secondary);
            font-size:12px;
        }
        .account-actions { display:grid; gap:10px; background:transparent; border-radius:0; }
        .account-signout,
        .account-theme-toggle {
            min-height:48px;
            border-radius:16px;
            background:#f7f8fa;
            border:1px solid rgba(88,101,124,.10);
            box-shadow:0 8px 20px rgba(37,48,67,.05);
        }
        .account-theme-toggle {
            display:flex;
            align-items:center;
            justify-content:space-between;
            width:100%;
            padding:0 18px;
            color:var(--text-primary);
            font-size:15px;
            font-weight:700;
        }
        .account-theme-toggle .theme-copy { display:inline-flex; align-items:center; gap:12px; }
        .account-theme-toggle svg { width:19px; height:19px; stroke:currentColor; fill:none; stroke-width:2; }
        .theme-switch {
            width:48px;
            height:28px;
            border-radius:999px;
            background:rgba(88,101,124,.16);
            padding:3px;
            display:inline-flex;
            align-items:center;
            flex-shrink:0;
            transition:background-color .2s ease;
        }
        .theme-switch-knob {
            width:22px;
            height:22px;
            border-radius:50%;
            background:#fff;
            box-shadow:0 4px 10px rgba(15,23,42,.16);
            transform:translateX(0);
            transition:transform .2s ease;
        }
        .icon-sun { display:none; }
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
            --bg-base:#f4f7fb;
            --bg-surface:rgba(255,255,255,.88);
            --border-light:rgba(223,231,243,.82);
            --text-primary:#183153;
            --text-secondary:#53627a;
            --text-muted:#8fa0b8;
            --accent-blue:#3f70b8;
            --accent-blue-hover:#284d84;
            --accent-blue-dim:rgba(63,112,184,.12);
            --accent-green:#2f9d93;
            --accent-green-hover:#267f77;
            --accent-green-dim:rgba(47,157,147,.14);
            --accent-red:#d45f5d;
            --accent-red-dim:rgba(212,95,93,.14);
            --accent-yellow:#e3a535;
            --accent-yellow-dim:rgba(227,165,53,.16);
        }
        [data-theme="dark"] {
            --bg-base:#07111f;
            --bg-surface:rgba(15,24,38,.88);
            --border-light:rgba(42,57,80,.9);
            --text-primary:#dbe5f3;
            --text-secondary:#8fa0b8;
            --text-muted:#62738d;
            --accent-blue:#5f91dd;
            --accent-blue-hover:#82a9e8;
            --accent-blue-dim:rgba(95,145,221,.18);
            --accent-green:#67c9b0;
            --accent-green-hover:#82d7c1;
            --accent-green-dim:rgba(47,157,147,.18);
            --accent-red:#e58b89;
            --accent-red-dim:rgba(229,139,137,.18);
            --accent-yellow:#f0c46a;
            --accent-yellow-dim:rgba(240,196,106,.16);
        }
        body {
    background: var(--bg-base);
}
[data-theme="dark"] body {
    background:
        linear-gradient(90deg, rgba(3,12,25,.96), rgba(16,37,66,.9)),
        linear-gradient(135deg, #07111f 0 22%, #10233d 22% 44%, #0b182a 44% 66%, #18365e 66% 100%);
}
        .topbar,
        .sidebar,
        .panel,
        .kpi-card,
        .qa-card { backdrop-filter:blur(18px); -webkit-backdrop-filter:blur(18px); }
        .brand-icon {
            background:rgba(26,122,74,.08);
            padding:4px;
            overflow:hidden;
        }
        .brand-logo-img {
            width:100%;
            height:100%;
            object-fit:contain;
            object-position:center;
            display:block;
        }
        .brand-logo-dark { display:none; }
        [data-theme="dark"] .brand-logo-light { display:none; }
        [data-theme="dark"] .brand-logo-dark { display:block; }
        .avatar-role,
        .nav-link.active,
        .panel-action { color:var(--accent-blue); }
        .nav-link.active { background:var(--accent-blue-dim); }
        .nav-link.active::before { background:var(--accent-blue); }
    </style>
</head>
<body>

<svg style="display:none" xmlns="http://www.w3.org/2000/svg">
    <symbol id="ic-logo" viewBox="0 0 24 24"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></symbol>
    <symbol id="ic-menu" viewBox="0 0 24 24"><path d="M3 18h18v-2H3v2zm0-5h18v-2H3v2zm0-7v2h18V6H3z"/></symbol>
    <symbol id="ic-class"      viewBox="0 0 24 24"><path d="M18 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-2 14H8v-2h8v2zm0-4H8v-2h8v2zm0-4H8V6h8v2z"/></symbol>
    <symbol id="ic-dashboard" viewBox="0 0 24 24"><path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/></symbol>
    <symbol id="ic-users" viewBox="0 0 24 24"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></symbol>
    <symbol id="ic-bell" viewBox="0 0 24 24"><path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6v-5c0-3.07-1.64-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.63 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z"/></symbol>
    <symbol id="ic-pending" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67V7z"/></symbol>
    <symbol id="ic-block" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zM4 12c0-4.42 3.58-8 8-8 1.85 0 3.55.63 4.9 1.68L5.68 16.9C4.63 15.55 4 13.85 4 12zm8 8c-1.85 0-3.55-.63-4.9-1.68l11.22-11.22C19.37 8.45 20 10.15 20 12c0 4.42-3.58 8-8 8z"/></symbol>
    <symbol id="ic-settings" viewBox="0 0 24 24"><path d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.06-.94l2.03-1.58c.18-.14.23-.41.12-.61l-1.92-3.32c-.12-.22-.37-.29-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54c-.04-.24-.24-.41-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.56-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.04.3-.07.63-.07.94s.02.64.07.94l-2.03 1.58c-.18.14-.23.41-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z"/></symbol>
    <symbol id="ic-logout" viewBox="0 0 24 24"><path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/></symbol>
    <symbol id="ic-check" viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></symbol>
    <symbol id="ic-eye" viewBox="0 0 24 24"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></symbol>
    <symbol id="ic-warning" viewBox="0 0 24 24"><path d="M1 21h22L12 2 1 21zm12-3h-2v-2h2v2zm0-4h-2v-4h2v4z"/></symbol>
    <symbol id="ic-info" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/></symbol>
    <symbol id="ic-add-user" viewBox="0 0 24 24"><path d="M15 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm-9-2V7H4v3H1v2h3v3h2v-3h3v-2H6zm9 4c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></symbol>
</svg>

<header class="topbar">
    <div class="topbar-brand">
        <button class="menu-toggle" id="menuToggle" aria-label="Toggle menu">
            <span></span><span></span><span></span>
        </button>
        <div class="brand-icon">
            <img class="brand-logo-img brand-logo-light" src="img/Light Icon.png" alt="Helios University">
            <img class="brand-logo-img brand-logo-dark" src="img/Dark Icon.png" alt="Helios University">
        </div>
        <div class="brand-wordmark">Helios<span>Admin Center</span></div>
    </div>
    
    <div class="topbar-center">
        <span class="topbar-clock" id="liveClock"></span>
    </div>

    <div class="topbar-right">
        <a href="role_admin_systemSettings.php" class="topbar-icon-btn" title="System Settings" aria-label="System settings">
            <svg><use href="#ic-settings"></use></svg>
        </a>
        <div class="topbar-divider"></div>
        <button type="button" class="avatar-btn" id="accountButton" title="Account" aria-haspopup="dialog" aria-expanded="false">
            <div class="avatar-circle"><svg viewBox="0 0 24 24" style="width:18px;height:18px;fill:#fff;"><path d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.06-.94l2.03-1.58c.18-.14.23-.41.12-.61l-1.92-3.32c-.12-.22-.37-.29-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54c-.04-.24-.24-.41-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59-.24-1.13-.56-1.62-.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.49.12.61l2.03 1.58c-.05.3-.09.63-.09.94s.02.64.06.94l-2.03 1.58c-.18.14-.23.41-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .43-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.49-.12-.61l-2.01-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z"/></svg></div>
            <div class="avatar-meta">
                <div class="avatar-name"><?= htmlspecialchars($username) ?></div>
                <div class="avatar-role">Administrator</div>
            </div>
        </button>
    </div>
</header>

<div class="account-popover" id="accountPopover" role="dialog" aria-label="Account overview">
    <button type="button" class="account-popover-close" id="accountPopoverClose" aria-label="Close account overview">&times;</button>
    <div class="account-email"><?= htmlspecialchars($username) ?></div>
    <div class="account-avatar-large"><?= $initials ?></div>
    <div class="account-greeting">Hi, <?= htmlspecialchars($username) ?>!</div>
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
            <svg><use href="#ic-logout"></use></svg>
            Sign out
        </a>
    </div>
</div>

<div class="body-wrap">
    <nav class="sidebar" id="sidebar">
        <div class="nav-section-label">Overview</div>
        <a href="dashboard_admin.php" class="nav-link active">
            <svg><use href="#ic-dashboard"></use></svg> Dashboard
        </a>
        
        <div class="nav-divider"></div>
        <div class="nav-section-label">Management</div>
        <a href="role_admin_manageUsers.php" class="nav-link">
            <svg><use href="#ic-users"></use></svg> Manage Users
            <?php if($totalUsers > 0): ?><span class="nav-badge dim"><?= $totalUsers ?></span><?php endif; ?>
        </a>
        <a href="pending_approval.php" class="nav-link">
            <svg><use href="#ic-pending"></use></svg> Pending Approval
            <?php if($pendingCount > 0): ?><span class="nav-badge"><?= $pendingCount ?></span><?php endif; ?>
        </a>
        <a href="role_admin_manageClasses.php" class="nav-link">
            <svg><use href="#ic-class"></use></svg> Manage Classes
        </a>
        
        <div class="nav-divider"></div>
        <div class="nav-section-label">System</div>
        <a href="role_admin_systemSettings.php" class="nav-link">
            <svg><use href="#ic-settings"></use></svg> System Settings
        </a>
        <div class="sidebar-footer">
            <a href="logout.php" class="nav-link">
                <svg><use href="#ic-logout"></use></svg> Sign Out
            </a>
        </div>
    </nav>

    <main class="main" id="mainContent">
        <div class="page-header">
            <div>
                <h1 class="page-title">Welcome, Administrator.</h1>
                <p class="page-subtitle">Full platform overview — users, classes, and system health.</p>
            </div>
            <div>
                <div class="page-date" id="currentDate"></div>
                <div class="page-date-sub">Current Date</div>
            </div>
        </div>


        <div class="kpi-strip">
            <div class="kpi-card blue">
                <div class="kpi-header">
                    <div class="kpi-icon"><svg><use href="#ic-users"></use></svg></div>
                    <div class="kpi-label">Total Users</div>
                </div>
                <div class="kpi-value"><?= $totalUsers ?></div>
            </div>
            <div class="kpi-card green">
                <div class="kpi-header">
                    <div class="kpi-icon"><svg><use href="#ic-class"></use></svg></div>
                    <div class="kpi-label">Active Classes</div>
                </div>
                <div class="kpi-value"><?= $activeClasses ?></div>
            </div>
            <div class="kpi-card yellow">
                <div class="kpi-header">
                    <div class="kpi-icon"><svg><use href="#ic-pending"></use></svg></div>
                    <div class="kpi-label">Pending Approval</div>
                </div>
                <div class="kpi-value"><?= $pendingCount ?></div>
            </div>
            <div class="kpi-card red">
                <div class="kpi-header">
                    <div class="kpi-icon"><svg><use href="#ic-block"></use></svg></div>
                    <div class="kpi-label">Disabled Accounts</div>
                </div>
                <div class="kpi-value"><?= $disabledCount ?></div>
            </div>
        </div>

        <div class="content-grid">
            <div>
                <div class="panel">
                    <div class="panel-header">
                        <div class="panel-title">Platform Users</div>
                        <a href="role_admin_manageUsers.php" class="panel-action">View All</a>
                    </div>
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Role</th>
                                    <th>Status</th>
                                    <th>Joined</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($recentUsers as $u): 
                                    $uRole    = $u['role']     ?? 'student';
                                    $uStatus  = $u['status']   ?? 'active';
                                    $uName    = $u['fullname'] ?? $u['username'] ?? '';
                                    $uUser    = $u['username'] ?? '';
                                    $uJoined  = $u['joined']   ?? '—';
                                    $avatarClass = $uRole === 'faculty' ? 'f' : ($uRole === 'admin' ? 'a' : 's');
                                    $initLetter  = strtoupper(substr($uName, 0, 1));
                                ?>
                                <tr>
                                    <td>
                                        <div class="user-cell">
                                            <div class="user-avatar <?= $avatarClass ?>"><?= $initLetter ?></div>
                                            <div>
                                                <div class="user-name"><?= htmlspecialchars($uName) ?></div>
                                                <div class="user-handle">@<?= htmlspecialchars($uUser) ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td><span class="role-text"><?= htmlspecialchars($uRole) ?></span></td>
                                    <td><span class="status-chip <?= $uStatus ?>"><?= htmlspecialchars($uStatus) ?></span></td>
                                    <td style="color:var(--text-muted); font-size:13px;"><?= htmlspecialchars($uJoined) ?></td>
                                    <td>
                                        <div class="action-btns">
                                            <?php if($uStatus === 'pending'): ?>
                                                <a href="pending_approval.php" class="btn-icon" title="Approve"><svg><use href="#ic-check"></use></svg></a>
                                            <?php endif; ?>
                                            <a href="role_admin_manageUsers.php?user=<?= urlencode($uUser) ?>" class="btn-icon" title="View"><svg><use href="#ic-eye"></use></svg></a>
                                            <?php if($uStatus !== 'disabled'): ?>
                                                <a href="role_admin_manageUsers.php?user=<?= urlencode($uUser) ?>" class="btn-icon" title="Disable"><svg><use href="#ic-block"></use></svg></a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="panel">
                    <div class="panel-header">
                        <div class="panel-title">Active Classes</div>
                        <a href="role_admin_manageClasses.php" class="panel-action">Manage</a>
                    </div>
                    <div>
                        <?php foreach(array_slice($allClasses, 0, 4) as $cls):
                            $cName    = $cls['name']    ?? 'Untitled';
                            $cSubj    = $cls['subject'] ?? '';
                            $cOwner   = $cls['owner']   ?? '—';
                            $cMembers = count($cls['members'] ?? []);
                            $cLetter  = strtoupper(substr($cName, 0, 1));
                        ?>
                        <div class="list-item">
                            <div class="list-icon"><?= $cLetter ?></div>
                            <div class="list-content">
                                <div class="list-title"><?= htmlspecialchars($cName) ?></div>
                                <div class="list-sub"><?= htmlspecialchars($cSubj) ?> · <?= htmlspecialchars($cOwner) ?></div>
                            </div>
                            <div style="font-size:13px; font-weight:500; color:var(--text-secondary);"><?= $cMembers ?> Enrolled</div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div>
                <div class="panel">
                    <div class="panel-header">
                        <div class="panel-title">Quick Actions</div>
                    </div>
                    <div class="quick-actions">
                        <a href="role_admin_manageUsers.php" class="qa-card">
                            <div class="qa-icon"><svg><use href="#ic-users"></use></svg></div>
                            <div class="qa-title">Manage Users</div>
                        </a>
                        <a href="pending_approval.php" class="qa-card">
                            <div class="qa-icon" style="color:var(--accent-yellow); background:var(--accent-yellow-dim);"><svg><use href="#ic-pending"></use></svg></div>
                            <div class="qa-title">Review Pending</div>
                        </a>
                        <a href="role_admin_manageClasses.php" class="qa-card">
                            <div class="qa-icon" style="color:var(--accent-green); background:var(--accent-green-dim);"><svg><use href="#ic-class"></use></svg></div>
                            <div class="qa-title">Manage Classes</div>
                        </a>
                    </div>
                </div>

            </div>
        </div>
    </main>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    function applyTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        localStorage.setItem('theme', theme);
    }
    const savedTheme = localStorage.getItem('theme') ||
        (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
    applyTheme(savedTheme);
    document.querySelectorAll('.theme-toggle').forEach((btn) => {
        btn.addEventListener('click', () => {
            const current = document.documentElement.getAttribute('data-theme');
            applyTheme(current === 'dark' ? 'light' : 'dark');
        });
    });

    const clockEl = document.getElementById('liveClock');
    const dateEl  = document.getElementById('currentDate');

    function updateClock() {
        const now = new Date();
        const hh  = String(now.getHours()).padStart(2,'0');
        const mm  = String(now.getMinutes()).padStart(2,'0');
        const ss  = String(now.getSeconds()).padStart(2,'0');
        if(clockEl) clockEl.textContent = `${hh}:${mm}:${ss}`;
        if(dateEl) {
            const opts = { weekday:'long', year:'numeric', month:'short', day:'numeric' };
            dateEl.textContent = now.toLocaleDateString('en-US', opts);
        }
    }
    updateClock();
    setInterval(updateClock, 1000);

    const sidebar = document.getElementById('sidebar');
    const mainContent = document.getElementById('mainContent');
    const menuToggle = document.getElementById('menuToggle');
    const accountButton = document.getElementById('accountButton');
    const accountPopover = document.getElementById('accountPopover');
    const accountPopoverClose = document.getElementById('accountPopoverClose');

    menuToggle.addEventListener('click', () => {
        if (window.innerWidth > 1024) {
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('expanded');
        } else {
            sidebar.classList.toggle('mobile-open');
        }
    });

    document.addEventListener('click', (e) => {
        if (window.innerWidth <= 1024 && sidebar.classList.contains('mobile-open') && !sidebar.contains(e.target) && !menuToggle.contains(e.target)) {
            sidebar.classList.remove('mobile-open');
        }

        if (accountPopover && accountPopover.classList.contains('open') &&
            !accountPopover.contains(e.target) && !accountButton.contains(e.target)) {
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
});
</script>

</body>
</html>
