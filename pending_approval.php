<?php
session_start();

if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

/* ═══════════════════════════════════════════════
   ADMIN VIEW
════════════════════════════════════════════════ */
if (($_SESSION['role'] ?? '') === 'admin') {

    require_once __DIR__ . '/db.php';          // provides $pdo
    require_once __DIR__ . '/auth_helpers.php'; // provides generateTemporaryPin(), sendCredentialsEmail(), decryptIfPossible()

   if (!defined('TEMP_PASSWORD_TTL_SECONDS')) {
    define('TEMP_PASSWORD_TTL_SECONDS', 259200);
} // 3 days

    $adminUsername = $_SESSION['username'];
    $message  = null;
    $msgType  = 'success';

    /* ── Helper: generate next unique ID from SQL ── */
    function generateUniqueIdFromDb(PDO $pdo): string {
        $year = date('Y');
        $stmt = $pdo->prepare(
            "SELECT request_id FROM users
             WHERE request_id REGEXP ?
             ORDER BY request_id DESC LIMIT 1"
        );
        $stmt->execute([$year . '-[0-9]{4}']);
        $last = $stmt->fetchColumn();
        $next = 0;
        if ($last && preg_match('/' . preg_quote($year, '/') . '-(\d{4})$/', $last, $m)) {
            $next = (int)$m[1] + 1;
        }
        return sprintf('%s-%04d', $year, $next);
    }

    /* ══════════════════════════════════════════
       ACTION: APPROVE
    ══════════════════════════════════════════ */
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_user'])) {
        $targetUsername = trim($_POST['target_username'] ?? '');

        // Fetch the pending user from SQL
        $stmt = $pdo->prepare(
            "SELECT * FROM users
             WHERE username = ? AND status = 'pending' AND activation_request = 1
             LIMIT 1"
        );
        $stmt->execute([$targetUsername]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            $uniqueId      = generateUniqueIdFromDb($pdo);
            $tempPw        = generateTemporaryPin();
            $hashedPw      = password_hash($tempPw, PASSWORD_DEFAULT);
            $expiresAt     = time() + TEMP_PASSWORD_TTL_SECONDS;
            $approvedName  = trim(($user['fullname'] ?? '') ?: $targetUsername);
            $approvedEmail = decryptIfPossible($user['email'] ?? '');

            // Update user: assign unique ID as new username, activate, set temp password
            $upd = $pdo->prepare(
                "UPDATE users SET
                    username               = ?,
                    request_id             = ?,
                    status                 = 'active',
                    activation_request     = 0,
                    password               = ?,
                    must_change_password   = 1,
                    temp_password_expires_at = ?,
                    activated_at           = NOW()
                 WHERE username = ? AND status = 'pending'"
            );
            $upd->execute([$uniqueId, $uniqueId, $hashedPw, $expiresAt, $targetUsername]);

            // Update authorized_people table if linked
            if (!empty($user['authorized_person_id'])) {
                $pdo->prepare(
                    "UPDATE authorized_people
                     SET status = 'activated', activated_at = NOW()
                     WHERE id = ?"
                )->execute([$user['authorized_person_id']]);
            }

            // Send credentials email
            $emailSent = false;
            if (!empty($approvedEmail)) {
                $emailSent = sendCredentialsEmail($approvedEmail, $approvedName, $uniqueId, $tempPw);
            }

            $message = 'Account for <strong>' . htmlspecialchars($approvedName) . '</strong> approved. '
                     . 'Unique ID: <strong>' . htmlspecialchars($uniqueId) . '</strong>. '
                     . 'Temporary password: <strong>' . htmlspecialchars($tempPw) . '</strong>. '
                     . ($emailSent ? 'Credentials emailed.' : '<em>Email could not be sent — share credentials manually.</em>');
            $msgType = 'success';
        } else {
            $message = 'User not found or already processed.';
            $msgType = 'error';
        }
    }

    /* ══════════════════════════════════════════
       ACTION: REJECT
    ══════════════════════════════════════════ */
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reject_user'])) {
        $targetUsername = trim($_POST['target_username'] ?? '');
        $upd = $pdo->prepare(
            "UPDATE users SET status = 'disabled', activation_request = 0
             WHERE username = ? AND status = 'pending'"
        );
        $upd->execute([$targetUsername]);
        $message = 'Account request for <strong>' . htmlspecialchars($targetUsername) . '</strong> has been rejected.';
        $msgType = 'error';
    }

    /* ══════════════════════════════════════════
       LOAD DATA FOR VIEW
    ══════════════════════════════════════════ */
    $pendingUsers = $pdo->query(
        "SELECT username, fullname, email, role, status, activation_request, registered_at, request_id
         FROM users
         WHERE status = 'pending' AND activation_request = 1
         ORDER BY registered_at ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    $pendingCount = count($pendingUsers);

    $totalUsers = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();

    $classCount = (int)$pdo->query("SELECT COUNT(*) FROM classes")->fetchColumn();

    $unreadNotifs = (int)$pdo->query(
        "SELECT COUNT(*) FROM notifications WHERE is_read = 0"
    )->fetchColumn();

    $initials = strtoupper(substr($adminUsername, 0, 2));
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pending Approval - Helios Admin Center</title>
    <script>
        (function () {
            var t = localStorage.getItem('theme') || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
            document.documentElement.setAttribute('data-theme', t);
        })();
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *{box-sizing:border-box;margin:0;padding:0}
        :root{
            --bg-base:#f4f7fb;
            --bg-surface:rgba(255,255,255,.88);
            --bg-elevated:#eef3fb;
            --border:rgba(223,231,243,.82);
            --text-primary:#183153;
            --text-secondary:#53627a;
            --text-muted:#8fa0b8;
            --accent:#1a7a4a;
            --accent-dim:rgba(26,122,74,.10);
            --danger:#d45f5d;
            --danger-dim:rgba(212,95,93,.14);
            --amber:#e3a535;
            --amber-dim:rgba(227,165,53,.16);
            --sidebar-w:260px;
            --topbar-h:60px;
            --ease:200ms cubic-bezier(.4,0,.2,1);
        }
        [data-theme="dark"]{
            --bg-base:#07111f;
            --bg-surface:rgba(15,24,38,.88);
            --bg-elevated:#13243a;
            --border:rgba(42,57,80,.9);
            --text-primary:#dbe5f3;
            --text-secondary:#8fa0b8;
            --text-muted:#62738d;
            --accent:#0e9f6e;
            --accent-dim:rgba(14,159,110,.15);
            --danger:#e58b89;
            --danger-dim:rgba(229,139,137,.18);
            --amber:#f0c46a;
            --amber-dim:rgba(240,196,106,.16);
        }
        html,body{min-height:100%;background:var(--bg-base);color:var(--text-primary);font-family:'Poppins',sans-serif;font-size:14px;line-height:1.6}
        a{text-decoration:none;color:inherit}
        button{font-family:inherit;border:0;background:none;color:inherit;cursor:pointer}

        /* ── Topbar ── */
        .topbar{position:fixed;top:0;left:0;right:0;height:var(--topbar-h);z-index:1000;display:flex;align-items:center;justify-content:space-between;padding:0 24px 0 0;background:var(--bg-surface);border-bottom:1px solid var(--border);box-shadow:0 1px 4px rgba(0,0,0,.06);backdrop-filter:blur(18px)}
        .topbar-brand{display:flex;align-items:center;width:var(--sidebar-w);height:100%;padding:0 24px;gap:10px;border-right:1px solid var(--border);flex-shrink:0}
        .menu-toggle{width:36px;height:36px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;border-radius:8px;margin-right:8px}
        .menu-toggle:hover,.topbar-icon-btn:hover,.avatar-btn:hover{background:var(--bg-elevated)}
        .menu-toggle span{width:16px;height:1.5px;background:var(--text-secondary);border-radius:2px}
        .brand-icon{width:32px;height:32px;border-radius:8px;background:rgba(26,122,74,.08);display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0}
        .brand-logo-img{width:100%;height:100%;object-fit:contain;display:block}
        .brand-logo-dark{display:none}
        [data-theme="dark"] .brand-logo-light{display:none}
        [data-theme="dark"] .brand-logo-dark{display:block}
        .brand-wordmark{font-size:17px;font-weight:700;letter-spacing:0;color:var(--text-primary);line-height:1.15}
        .brand-wordmark span{display:block;font-size:11px;font-weight:400;color:var(--text-muted);letter-spacing:.08em;text-transform:uppercase}
        .topbar-center{flex:1;display:flex;align-items:center;padding:0 24px}
        .topbar-clock{font-size:12px;color:var(--text-muted);font-variant-numeric:tabular-nums;letter-spacing:.04em}
        .topbar-right{display:flex;align-items:center;gap:4px}
        .topbar-icon-btn{width:36px;height:36px;border-radius:8px;display:flex;align-items:center;justify-content:center;color:var(--text-secondary);position:relative}
        .topbar-icon-btn svg{width:18px;height:18px;fill:currentColor}
        .notif-badge{position:absolute;top:5px;right:5px;width:8px;height:8px;background:var(--danger);border-radius:50%;border:2px solid var(--bg-surface)}
        .topbar-divider{width:1px;height:24px;background:var(--border);margin:0 8px}
        .avatar-btn{display:flex;align-items:center;gap:10px;padding:6px 10px;border-radius:8px}
        .avatar-circle{width:32px;height:32px;border-radius:8px;background:linear-gradient(135deg,#1a7a4a,#0e9f6e);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;color:#fff}
        .avatar-meta{line-height:1.3;text-align:left}
        .avatar-name{font-size:13px;font-weight:500;color:var(--text-primary)}
        .avatar-role{font-size:10px;text-transform:uppercase;letter-spacing:.08em;color:var(--accent)}

        /* ── Sidebar ── */
        .sidebar{position:fixed;top:var(--topbar-h);bottom:0;left:0;width:var(--sidebar-w);background:var(--bg-surface);border-right:1px solid var(--border);overflow-y:auto;z-index:900;padding:16px 0 24px;transition:transform var(--ease);display:flex;flex-direction:column;backdrop-filter:blur(18px)}
        .sidebar.collapsed{transform:translateX(-100%)}
        .nav-section-label{font-size:10px;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:var(--text-muted);padding:16px 20px 6px}
        .nav-link{display:flex;align-items:center;gap:12px;padding:9px 20px;color:var(--text-secondary);font-size:13.5px;font-weight:400;position:relative}
        .nav-link:hover{color:var(--text-primary);background:var(--bg-elevated)}
        .nav-link.active{color:var(--accent);background:var(--accent-dim);font-weight:500}
        .nav-link.active::before{content:'';position:absolute;left:0;top:4px;bottom:4px;width:3px;background:var(--accent);border-radius:0 3px 3px 0}
        .nav-link svg{width:16px;height:16px;fill:currentColor;opacity:.7}
        .nav-badge{margin-left:auto;background:var(--danger);color:#fff;font-size:10px;font-weight:600;padding:1px 6px;border-radius:20px;min-width:18px;text-align:center}
        .nav-badge.dim{background:var(--bg-elevated);color:var(--text-muted)}
        .nav-divider{height:1px;background:var(--border);margin:8px 16px}
        .sidebar-footer{margin-top:auto;padding:12px 12px 0;border-top:1px solid var(--border)}
        .sidebar-footer .nav-link{border-radius:8px}

        /* ── Main ── */
        .main{margin-top:var(--topbar-h);margin-left:var(--sidebar-w);padding:32px 36px 48px;transition:margin-left var(--ease);max-width:1440px}
        .main.expanded{margin-left:0}
        .page-header{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;margin-bottom:28px;flex-wrap:wrap}
        .eyebrow{font-size:11px;text-transform:uppercase;letter-spacing:.12em;color:var(--accent);font-weight:700;margin-bottom:4px}
        h1{font-size:26px;font-weight:700;line-height:1.2}
        .sub{color:var(--text-muted);font-size:13px;margin-top:6px}

        /* ── Panel ── */
        .panel{background:var(--bg-surface);border:1px solid var(--border);border-radius:14px;box-shadow:0 1px 3px rgba(0,0,0,.04);overflow:hidden;backdrop-filter:blur(18px)}
        .panel-head{padding:18px 22px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:12px}
        .panel-title{font-weight:700;font-size:14px;display:flex;align-items:center;gap:8px}
        .panel-title::before{content:'';width:6px;height:6px;border-radius:50%;background:var(--accent);box-shadow:0 0 6px var(--accent)}
        .count{background:var(--amber);color:#fff;border-radius:999px;padding:2px 10px;font-size:11px;font-weight:800}

        /* ── Request rows ── */
        .request{display:grid;grid-template-columns:1fr auto;gap:18px;align-items:center;padding:18px 22px;border-bottom:1px solid var(--border)}
        .request:last-child{border-bottom:0}
        .user{display:flex;align-items:center;gap:12px;min-width:0}
        .user-avatar{width:42px;height:42px;border-radius:10px;background:var(--amber-dim);color:var(--amber);display:flex;align-items:center;justify-content:center;font-weight:800;flex-shrink:0;font-size:18px}
        .name{font-size:14px;font-weight:700;color:var(--text-primary)}
        .meta{font-size:12px;color:var(--text-muted);margin-top:2px}
        .role-badge{display:inline-flex;margin-top:7px;padding:3px 10px;border-radius:999px;background:var(--accent-dim);color:var(--accent);font-size:11px;font-weight:800;text-transform:capitalize}
        .actions{display:flex;gap:8px}
        .approve,.reject{min-height:38px;padding:0 16px;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;border:none;font-family:inherit}
        .approve{background:#0e9f6e;color:#fff}
        .approve:hover{background:#047857}
        .reject{background:var(--danger-dim);color:var(--danger)}
        .reject:hover{background:var(--danger);color:#fff}
        .empty{padding:48px;text-align:center;color:var(--text-muted);font-size:13px}

        /* ── Alert ── */
        .alert{margin-bottom:18px;padding:14px 18px;border-radius:10px;font-size:13px;font-weight:500;line-height:1.6}
        .alert.success{background:rgba(14,159,110,.10);color:#047857;border:1px solid rgba(14,159,110,.22)}
        .alert.error{background:var(--danger-dim);color:var(--danger);border:1px solid rgba(212,95,93,.2)}

        /* ── Account popover ── */
        .account-popover{position:fixed;top:72px;right:24px;width:min(340px,calc(100vw - 32px));background:rgba(255,255,255,.94);border:1px solid var(--border);border-radius:28px;box-shadow:0 14px 40px rgba(26,29,46,.22);z-index:1200;padding:14px;display:none;text-align:left}
        .account-popover.open{display:block}
        .account-popover-close{position:absolute;top:14px;right:14px;width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;color:var(--text-secondary);font-size:24px;line-height:1;background:#f4f6f8}
        .account-email{padding:10px 42px 4px 10px;color:var(--text-secondary);font-size:12px;word-break:break-word}
        .account-avatar-large{width:72px;height:72px;margin:12px auto 10px;border-radius:22px;background:linear-gradient(135deg,#1a7a4a,#67c9b0);color:#fff;display:flex;align-items:center;justify-content:center;font-size:28px;font-weight:700}
        .account-greeting{text-align:center;font-size:19px;font-weight:800;margin-bottom:8px}
        .account-role-pill{display:flex;width:max-content;margin:0 auto 14px;min-height:30px;padding:0 14px;border-radius:999px;background:#eef1f4;color:var(--text-secondary);font-size:12px;font-weight:700;align-items:center}
        .account-actions{display:grid;gap:10px}
        .account-theme-toggle,.account-signout{min-height:48px;border-radius:16px;background:#f7f8fa;border:1px solid rgba(88,101,124,.10);display:flex;align-items:center;gap:14px;width:100%;padding:0 18px;color:var(--text-primary);font-size:15px;font-weight:700}
        .account-theme-toggle{justify-content:space-between}
        .theme-copy{display:inline-flex;align-items:center;gap:12px}
        .account-theme-toggle svg{width:19px;height:19px;stroke:currentColor;fill:none;stroke-width:2}
        .account-signout svg{width:20px;height:20px;fill:currentColor;color:var(--text-secondary)}
        .icon-sun{display:none}
        [data-theme="dark"] .icon-sun{display:block}
        [data-theme="dark"] .icon-moon{display:none}
        .theme-switch{width:48px;height:28px;border-radius:999px;background:rgba(88,101,124,.16);padding:3px;display:inline-flex;align-items:center;flex-shrink:0;transition:background-color .2s ease}
        .theme-switch-knob{width:22px;height:22px;border-radius:50%;background:#fff;box-shadow:0 4px 10px rgba(15,23,42,.16);transition:transform .2s ease}
        [data-theme="dark"] .theme-switch{background:rgba(14,159,110,.38)}
        [data-theme="dark"] .theme-switch-knob{transform:translateX(20px)}
        [data-theme="dark"] .account-popover{background:rgba(15,24,38,.96)}
        [data-theme="dark"] .account-popover-close,[data-theme="dark"] .account-role-pill,[data-theme="dark"] .account-theme-toggle,[data-theme="dark"] .account-signout{background:rgba(255,255,255,.08);border-color:rgba(125,139,164,.16)}

        @media(max-width:1024px){.sidebar{transform:translateX(-100%)}.sidebar.mobile-open{transform:translateX(0);box-shadow:20px 0 60px rgba(0,0,0,.15)}.main{margin-left:0;padding:24px 20px 40px}}
        @media(max-width:720px){.request{display:block}.actions{margin-top:14px;flex-wrap:wrap}.approve,.reject{flex:1}.topbar-center,.avatar-meta{display:none}.topbar-brand{width:auto;min-width:0}.brand-wordmark{display:none}}

        /* Dashboard-aligned admin shell */
        :root{
            --bg-base:#f5f6fa;
            --bg-surface:#ffffff;
            --bg-elevated:#f0f2f7;
            --border:#e8eaef;
            --text-primary:#1a1d2e;
            --text-secondary:#52576e;
            --text-muted:#8b90a7;
            --accent:#1a7a4a;
            --accent-dim:rgba(26,122,74,.10);
            --danger:#d93025;
            --danger-dim:#fce8e6;
            --amber:#d97706;
            --amber-dim:rgba(217,119,6,.10);
            --sidebar-w:260px;
            --topbar-h:60px;
        }
        html,body{background:var(--bg-base)}
        .main{padding:32px 36px 48px;max-width:1440px}
        .topbar,.sidebar,.panel{backdrop-filter:none}
        .panel{border-radius:14px;box-shadow:none}
        .nav-badge{background:var(--danger);color:#fff;border-radius:20px}
        .nav-badge.dim{background:var(--bg-elevated);color:var(--text-muted)}
        .count{background:var(--amber)}
        [data-theme="dark"]{
            --bg-base:#07111f;
            --bg-surface:#0d1b2e;
            --bg-elevated:#13202e;
            --border:rgba(125,139,164,.16);
            --text-primary:#eef4ff;
            --text-secondary:#aeb9cb;
            --text-muted:#7f8ca3;
            --accent:#0e9f6e;
            --accent-dim:rgba(14,159,110,.12);
            --danger:#e58b89;
            --danger-dim:rgba(229,139,137,.18);
            --amber:#f0c46a;
            --amber-dim:rgba(240,196,106,.16);
        }
    </style>
</head>
<body>
<svg style="display:none" xmlns="http://www.w3.org/2000/svg">
    <symbol id="ic-dashboard" viewBox="0 0 24 24"><path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/></symbol>
    <symbol id="ic-users"    viewBox="0 0 24 24"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></symbol>
    <symbol id="ic-class"    viewBox="0 0 24 24"><path d="M18 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-2 14H8v-2h8v2zm0-4H8v-2h8v2zm0-4H8V6h8v2z"/></symbol>
    <symbol id="ic-pending"    viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67V7z"/></symbol>
    <symbol id="ic-settings" viewBox="0 0 24 24"><path d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.06-.94l2.03-1.58c.18-.14.23-.41.12-.61l-1.92-3.32c-.12-.22-.37-.29-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54c-.04-.24-.24-.41-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.56-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.04.3-.07.63-.07.94s.02.64.07.94l-2.03 1.58c-.18.14-.23.41-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z"/></symbol>
    <symbol id="ic-bell"     viewBox="0 0 24 24"><path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6v-5c0-3.07-1.64-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.63 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z"/></symbol>
    <symbol id="ic-logout"   viewBox="0 0 24 24"><path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/></symbol>
</svg>

<header class="topbar">
    <div class="topbar-brand">
        <button class="menu-toggle" id="menuToggle" aria-label="Toggle sidebar"><span></span><span></span><span></span></button>
        <div class="brand-icon">
            <img class="brand-logo-img brand-logo-light" src="img/Light Icon.png" alt="Helios University">
            <img class="brand-logo-img brand-logo-dark"  src="img/Dark Icon.png"  alt="Helios University">
        </div>
        <div class="brand-wordmark">Helios<span>Admin Center</span></div>
    </div>
    <div class="topbar-center"><span class="topbar-clock" id="liveClock"></span></div>
    <div class="topbar-right">
        <a href="role_admin_systemSettings.php" class="topbar-icon-btn" title="System Settings">
            <svg><use href="#ic-settings"></use></svg>
        </a>
        <div class="topbar-divider"></div>
        <button type="button" class="avatar-btn" id="accountButton" aria-haspopup="dialog" aria-expanded="false">
            <div class="avatar-circle"><?= htmlspecialchars($initials) ?></div>
            <div class="avatar-meta">
                <div class="avatar-name"><?= htmlspecialchars($adminUsername) ?></div>
                <div class="avatar-role">Administrator</div>
            </div>
        </button>
    </div>
</header>

<div class="account-popover" id="accountPopover" role="dialog">
    <button type="button" class="account-popover-close" id="accountPopoverClose">&times;</button>
    <div class="account-email"><?= htmlspecialchars($adminUsername) ?></div>
    <div class="account-avatar-large"><?= htmlspecialchars($initials) ?></div>
    <div class="account-greeting">Hi, <?= htmlspecialchars($adminUsername) ?>!</div>
    <div class="account-role-pill">Administrator Account</div>
    <div class="account-actions">
        <button type="button" class="account-theme-toggle theme-toggle" aria-label="Toggle dark mode">
            <span class="theme-copy">
                <svg class="icon-moon" viewBox="0 0 24 24"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
                <svg class="icon-sun"  viewBox="0 0 24 24"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
                Dark mode
            </span>
            <span class="theme-switch" aria-hidden="true"><span class="theme-switch-knob"></span></span>
        </button>
        <a href="logout.php" class="account-signout"><svg><use href="#ic-logout"></use></svg> Sign out</a>
    </div>
</div>

<nav class="sidebar" id="sidebar">
    <div class="nav-section-label">Overview</div>
    <a href="dashboard_admin.php" class="nav-link"><svg><use href="#ic-dashboard"></use></svg> Dashboard</a>
    <div class="nav-divider"></div>
    <div class="nav-section-label">Management</div>
    <a href="role_admin_manageUsers.php" class="nav-link">
        <svg><use href="#ic-users"></use></svg> Manage Users
        <?php if ($totalUsers > 0): ?><span class="nav-badge dim"><?= $totalUsers ?></span><?php endif; ?>
    </a>
    <a href="pending_approval.php" class="nav-link active">
        <svg><use href="#ic-pending"></use></svg> Pending Approval
        <?php if ($pendingCount > 0): ?><span class="nav-badge"><?= $pendingCount ?></span><?php endif; ?>
    </a>
    <a href="role_admin_manageClasses.php" class="nav-link">
        <svg><use href="#ic-class"></use></svg> Manage Classes
        <span class="nav-badge dim"><?= $classCount ?></span>
    </a>
    <div class="nav-divider"></div>
    <div class="nav-section-label">System</div>
    <a href="role_admin_systemSettings.php" class="nav-link"><svg><use href="#ic-settings"></use></svg> System Settings</a>
    <div class="sidebar-footer">
        <a href="logout.php" class="nav-link"><svg><use href="#ic-logout"></use></svg> Sign Out</a>
    </div>
</nav>

<main class="main" id="mainContent">
    <div class="page-header">
        <div>
            <div class="eyebrow">Admin — Management</div>
            <h1>Pending Approval</h1>
            <p class="sub">Review student and faculty account requests before login credentials are issued.</p>
        </div>
    </div>

    <?php if ($message): ?>
    <div class="alert <?= htmlspecialchars($msgType) ?>"><?= $message ?></div>
    <?php endif; ?>

    <section class="panel">
        <div class="panel-head">
            <div class="panel-title">Approval Requests</div>
            <div class="count"><?= $pendingCount ?></div>
        </div>

        <?php if (!empty($pendingUsers)): ?>
            <?php foreach ($pendingUsers as $pu):
                $ini      = strtoupper(substr($pu['fullname'] ?? $pu['username'] ?? 'U', 0, 1));
                $reqId    = $pu['request_id'] ?? $pu['username'];
                $regDate  = !empty($pu['registered_at'])
                            ? date('M j, Y g:i A', strtotime($pu['registered_at']))
                            : '';
            ?>
            <article class="request">
                <div class="user">
                    <div class="user-avatar"><?= htmlspecialchars($ini) ?></div>
                    <div>
                        <div class="name"><?= htmlspecialchars($pu['fullname'] ?? $pu['username']) ?></div>
                        <div class="meta">
                            Request ID: <?= htmlspecialchars($reqId) ?>
                            <?= $regDate ? ' &mdash; Submitted ' . htmlspecialchars($regDate) : '' ?>
                        </div>
                        <div class="role-badge"><?= htmlspecialchars($pu['role'] ?? 'student') ?></div>
                    </div>
                </div>
                <div class="actions">
                    <form method="POST">
                        <input type="hidden" name="target_username" value="<?= htmlspecialchars($pu['username']) ?>">
                        <button class="approve" type="submit" name="approve_user"
                                onclick="return confirm('Approve account for <?= htmlspecialchars(addslashes($pu['fullname'] ?? $pu['username'])) ?>?\n\nCredentials will be emailed and displayed here.')">
                            ✓ Approve
                        </button>
                    </form>
                    <form method="POST">
                        <input type="hidden" name="target_username" value="<?= htmlspecialchars($pu['username']) ?>">
                        <button class="reject" type="submit" name="reject_user"
                                onclick="return confirm('Reject account for <?= htmlspecialchars(addslashes($pu['fullname'] ?? $pu['username'])) ?>?')">
                            ✕ Reject
                        </button>
                    </form>
                </div>
            </article>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty">
                <svg viewBox="0 0 24 24" style="width:40px;height:40px;fill:var(--border);display:block;margin:0 auto 12px"><use href="#ic-pending"></use></svg>
                No pending registration requests at this time.
            </div>
        <?php endif; ?>
    </section>
</main>

<script>
document.querySelectorAll('.theme-toggle').forEach(t => {
    t.addEventListener('click', () => {
        const next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', next);
        localStorage.setItem('theme', next);
    });
});
const clockEl = document.getElementById('liveClock');
function updateClock() {
    const now = new Date();
    if (clockEl) clockEl.textContent =
        String(now.getHours()).padStart(2,'0') + ':' +
        String(now.getMinutes()).padStart(2,'0') + ':' +
        String(now.getSeconds()).padStart(2,'0');
}
updateClock(); setInterval(updateClock, 1000);

const sidebar     = document.getElementById('sidebar');
const mainContent = document.getElementById('mainContent');
const menuToggle  = document.getElementById('menuToggle');
const accountButton  = document.getElementById('accountButton');
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
document.addEventListener('click', e => {
    if (window.innerWidth <= 1024 && sidebar && sidebar.classList.contains('mobile-open')
        && !sidebar.contains(e.target) && !menuToggle.contains(e.target)) {
        sidebar.classList.remove('mobile-open');
    }
    if (accountPopover && accountPopover.classList.contains('open')
        && !accountPopover.contains(e.target) && !accountButton.contains(e.target)) {
        accountPopover.classList.remove('open');
        accountButton.setAttribute('aria-expanded','false');
    }
});
if (accountButton && accountPopover) {
    accountButton.addEventListener('click', e => {
        e.stopPropagation();
        const isOpen = accountPopover.classList.toggle('open');
        accountButton.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });
}
if (accountPopoverClose) {
    accountPopoverClose.addEventListener('click', () => {
        accountPopover.classList.remove('open');
        accountButton.setAttribute('aria-expanded','false');
        accountButton.focus();
    });
}
</script>
</body>
</html>
<?php
    exit();
} // end admin view

/* ═══════════════════════════════════════════════
   PENDING USER "AWAITING APPROVAL" VIEW
   (shown to logged-in users whose status=pending)
════════════════════════════════════════════════ */
if (($_SESSION['status'] ?? '') !== 'pending') {
    header("Location: dashboard.php");
    exit();
}

$username = htmlspecialchars($_SESSION['username']);
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Awaiting Approval — Helios University</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script>
        (function(){
            var t=localStorage.getItem('theme')||(window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light');
            document.documentElement.setAttribute('data-theme',t);
        })();
    </script>
    <style>
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
        :root{--bg:#f5f7fc;--surface:#fff;--border:#e2e7f0;--text-primary:#111827;--text-secondary:#556070;--text-muted:#9caab8;--accent:#1a7a4a;--accent-hover:#155f3a;--accent-light:#eef9f4;--shadow-md:0 8px 32px rgba(10,20,60,0.08),0 2px 8px rgba(10,20,60,0.04);--transition:160ms cubic-bezier(0.4,0,0.2,1);--font:'Poppins',system-ui,sans-serif;--radius:10px}
        [data-theme="dark"]{--bg:#0e1117;--surface:#161b26;--border:#232c3d;--text-primary:#e8edf5;--text-secondary:#8898b0;--text-muted:#3d5060;--accent:#0e9f6e;--accent-hover:#0c8a5f;--accent-light:#0d1f17;--shadow-md:0 8px 32px rgba(0,0,0,0.45)}
        html{-webkit-font-smoothing:antialiased}
        body{font-family:var(--font);background:var(--bg);color:var(--text-primary);min-height:100vh;display:flex;flex-direction:column;transition:background var(--transition),color var(--transition)}
        body::before{content:'';position:fixed;inset:0;z-index:0;background-image:radial-gradient(circle,var(--border) 1px,transparent 1px);background-size:28px 28px;opacity:.55;pointer-events:none;mask-image:radial-gradient(ellipse 80% 70% at 50% 40%,black 20%,transparent 80%);-webkit-mask-image:radial-gradient(ellipse 80% 70% at 50% 40%,black 20%,transparent 80%)}
        [data-theme="dark"] body::before{opacity:.25}
        a{text-decoration:none;color:inherit}
        .nav{position:fixed;top:0;left:0;right:0;z-index:100;height:60px;display:flex;align-items:center;justify-content:space-between;padding:0 40px;background:rgba(245,247,252,.82);border-bottom:1px solid var(--border);backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px)}
        [data-theme="dark"] .nav{background:rgba(14,17,23,.85)}
        .nav-brand{display:inline-flex;align-items:center;height:46px}
        .brand-logo-img{display:block;width:104px;height:46px;object-fit:contain;object-position:left center}
        .brand-logo-dark{display:none}
        [data-theme="dark"] .brand-logo-light{display:none}
        [data-theme="dark"] .brand-logo-dark{display:block}
        .theme-toggle{width:34px;height:34px;border-radius:50%;background:var(--surface);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;cursor:pointer;transition:background var(--transition),border-color var(--transition),transform .25s}
        .theme-toggle:hover{background:var(--accent-light);border-color:var(--accent);transform:rotate(18deg)}
        .theme-toggle svg{width:14px;height:14px;stroke:var(--text-secondary);fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
        .icon-sun{display:none}.icon-moon{display:block}
        [data-theme="dark"] .icon-sun{display:block}[data-theme="dark"] .icon-moon{display:none}
        .page{position:relative;z-index:1;flex:1;display:flex;align-items:center;justify-content:center;padding:88px 24px 60px}
        .card{width:100%;max-width:480px;background:var(--surface);border:1px solid var(--border);border-radius:16px;box-shadow:var(--shadow-md);opacity:0;animation:fadeUp .55s .05s cubic-bezier(.16,1,.3,1) forwards}
        .card-head{padding:40px 44px 32px;border-bottom:1px solid var(--border);display:flex;flex-direction:column;align-items:center;text-align:center}
        .icon-wrap{width:60px;height:60px;border-radius:50%;background:rgba(251,191,36,.10);border:1px solid rgba(251,191,36,.22);display:flex;align-items:center;justify-content:center;margin-bottom:20px}
        [data-theme="dark"] .icon-wrap{background:rgba(251,191,36,.08);border-color:rgba(251,191,36,.16)}
        .icon-wrap svg{width:26px;height:26px;stroke:#d97706;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
        [data-theme="dark"] .icon-wrap svg{stroke:#fbbf24}
        .card-title{font-size:20px;font-weight:600;letter-spacing:-.025em;color:var(--text-primary);margin-bottom:6px}
        .card-sub{font-size:13px;font-weight:400;color:var(--text-secondary);line-height:1.65}
        .card-sub strong{font-weight:500;color:var(--text-primary)}
        .card-body{padding:28px 44px 36px}
        .pending-badge{display:flex;align-items:center;justify-content:center;gap:8px;background:rgba(251,191,36,.08);border:1px solid rgba(251,191,36,.22);border-radius:99px;padding:9px 18px;margin-bottom:28px}
        [data-theme="dark"] .pending-badge{background:rgba(251,191,36,.06);border-color:rgba(251,191,36,.15)}
        .pending-dot{width:7px;height:7px;border-radius:50%;background:#d97706;flex-shrink:0;animation:pulse 1.8s ease infinite}
        [data-theme="dark"] .pending-dot{background:#fbbf24}
        @keyframes pulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.45;transform:scale(.7)}}
        .pending-text{font-size:12px;font-weight:500;color:#92400e;letter-spacing:.01em}
        [data-theme="dark"] .pending-text{color:#fcd34d}
        .steps{background:var(--bg);border:1px solid var(--border);border-radius:var(--radius);padding:18px 20px;display:flex;flex-direction:column;gap:14px;margin-bottom:28px}
        .step{display:flex;align-items:flex-start;gap:12px}
        .step-num{width:20px;height:20px;border-radius:50%;flex-shrink:0;background:var(--border);font-size:10.5px;font-weight:600;color:var(--text-muted);display:flex;align-items:center;justify-content:center;margin-top:1px}
        .step-num.done{background:rgba(22,163,74,.12);color:#16a34a;font-size:11px}
        [data-theme="dark"] .step-num.done{background:rgba(74,222,128,.12);color:#4ade80}
        .step-label{font-size:13px;font-weight:400;color:var(--text-secondary);line-height:1.55}
        .step-label b{font-weight:500;color:var(--text-primary)}
        .btn-signout{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;padding:11px 16px;font-family:var(--font);font-size:13px;font-weight:500;color:var(--text-secondary);background:var(--bg);border:1px solid var(--border);border-radius:var(--radius);cursor:pointer;text-decoration:none;transition:border-color var(--transition),color var(--transition),background var(--transition)}
        .btn-signout:hover{border-color:var(--accent);color:var(--accent);background:var(--accent-light)}
        .btn-signout svg{width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
        @keyframes fadeUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
        @media(max-width:520px){.nav{padding:0 20px}.card-head{padding:32px 24px 24px}.card-body{padding:24px 24px 28px}}
    </style>
</head>
<body>
<nav class="nav">
    <a href="index.php" class="nav-brand" aria-label="Helios University home">
        <img class="brand-logo-img brand-logo-light" src="img/Light Icon.png" alt="Helios University">
        <img class="brand-logo-img brand-logo-dark"  src="img/Dark Icon.png"  alt="Helios University">
    </a>
    <button class="theme-toggle" id="themeToggle" aria-label="Toggle theme">
        <svg class="icon-moon" viewBox="0 0 24 24"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
        <svg class="icon-sun"  viewBox="0 0 24 24"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
    </button>
</nav>
<div class="page">
    <div class="card">
        <div class="card-head">
            <div class="icon-wrap">
                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </div>
            <div class="card-title">Awaiting approval</div>
            <div class="card-sub">
                Hi, <strong><?= $username ?></strong>. Your account is registered and
                pending admin review before you can access the dashboard.
            </div>
        </div>
        <div class="card-body">
            <div class="pending-badge">
                <span class="pending-dot"></span>
                <span class="pending-text">Account pending review</span>
            </div>
            <div class="steps">
                <div class="step">
                    <div class="step-num done">✓</div>
                    <div class="step-label"><b>Account created</b> — your registration was received.</div>
                </div>
                <div class="step">
                    <div class="step-num">2</div>
                    <div class="step-label"><b>Admin review</b> — an administrator is reviewing your request.</div>
                </div>
                <div class="step">
                    <div class="step-num">3</div>
                    <div class="step-label"><b>Access granted</b> — once approved, sign in to reach your dashboard.</div>
                </div>
            </div>
            <a href="logout.php" class="btn-signout">
                <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                Sign out
            </a>
        </div>
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
