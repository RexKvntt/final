<?php
/**
 * Helios University Academic Hub
 * role_admin_manageUsers.php — SQL version (replaces JSON)
 */
session_start();

require 'auth_helpers.php';
require_once 'db.php';

if (!isset($_SESSION['username']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit();
}

$role     = $_SESSION['role'];
$username = $_SESSION['username'];
$initials = strtoupper(substr($username, 0, 2));

$message = null;
$msgType = 'success';

/* ══════════════════════════════════════════
   ADD AUTHORIZED PERSON
══════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_authorized_person'])) {
    $firstName       = trim($_POST['auth_firstname'] ?? '');
    $lastName        = trim($_POST['auth_lastname']  ?? '');
    $email           = trim($_POST['auth_email']     ?? '');
    $phone           = trim($_POST['auth_phone']     ?? '');
    $roleToAuthorize = trim($_POST['auth_role']      ?? 'student');

    if ($firstName === '' || $lastName === '' || $email === '' || $phone === ''
        || !filter_var($email, FILTER_VALIDATE_EMAIL)
        || !in_array($roleToAuthorize, ['student', 'faculty'], true)) {
        $message = 'Please enter valid authorized-person details.';
        $msgType = 'error';
    } else {
        // Check for duplicate
        $chk = $pdo->prepare("SELECT id FROM authorized_people WHERE email = ? OR (firstname = ? AND lastname = ? AND phonenumber = ?) LIMIT 1");
        $chk->execute([$email, $firstName, $lastName, normalizePhone($phone)]);
        if ($chk->fetch()) {
            $message = 'That person is already in the authorized activation list.';
            $msgType = 'error';
        } else {
            $authId = 'AUTH-' . strtoupper(bin2hex(random_bytes(4)));
            $ins = $pdo->prepare("
                INSERT INTO authorized_people (id, firstname, lastname, email, phonenumber, role, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())
            ");
            $ins->execute([$authId, $firstName, $lastName, $email, normalizePhone($phone), $roleToAuthorize]);
            $message = 'Authorized person added. They can now submit an activation request.';
            $msgType = 'success';
        }
    }
}

/* ══════════════════════════════════════════
   APPROVE REGISTRATION REQUEST
══════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_user'])) {
    $targetUsername = $_POST['target_username'] ?? '';

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND status = 'pending' LIMIT 1");
    $stmt->execute([$targetUsername]);
    $u = $stmt->fetch();

    if ($u) {
        // Generate new unique ID
        $countStmt = $pdo->query("SELECT COUNT(*) FROM users");
        $allUsers  = $countStmt->fetchColumn();
        $uniqueId  = generateUniqueId2($pdo);

        $tempPw       = generateTemporaryPin();
        $hashedTempPw = password_hash($tempPw, PASSWORD_DEFAULT);

        $expiry = time() + TEMP_PASSWORD_TTL_SECONDS;

        $pdo->prepare("
            UPDATE users SET
                status = 'active',
                approved_by = ?,
                approved_at = NOW(),
                username = ?,
                password = ?,
                must_change_password = 1,
                temp_password_issued_at = ?,
                temp_password_expires_at = ?
            WHERE id = ?
        ")->execute([$username, $uniqueId, $hashedTempPw, time(), $expiry, $u['id']]);

        // Mark authorized person as activated
        if (!empty($u['authorized_person_id'])) {
            $pdo->prepare("
                UPDATE authorized_people SET status = 'activated', activated_at = NOW()
                WHERE id = ?
            ")->execute([$u['authorized_person_id']]);
        }

        $approvedEmail = decryptIfPossible($u['email'] ?? '');
        $approvedName  = $u['fullname'] ?? $targetUsername;

        if (!empty($approvedEmail)) {
            sendCredentialsEmail($approvedEmail, $approvedName, $uniqueId, $tempPw);
        }

        $message = 'Account <strong>' . htmlspecialchars($targetUsername) . '</strong> approved. '
                 . 'Credentials (ID: <strong>' . htmlspecialchars($uniqueId) . '</strong>) emailed.';
        $msgType = 'success';
    }
}

/* ══════════════════════════════════════════
   REJECT REGISTRATION REQUEST
══════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reject_user'])) {
    $targetUsername = $_POST['target_username'] ?? '';
    $pdo->prepare("
        UPDATE users SET status = 'rejected', rejected_by = ?, rejected_at = NOW()
        WHERE username = ? AND status = 'pending'
    ")->execute([$username, $targetUsername]);
    $message = 'Account <strong>' . htmlspecialchars($targetUsername) . '</strong> has been rejected.';
    $msgType = 'error';
}

/* ══════════════════════════════════════════
   DISABLE / ENABLE ACCOUNT
══════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['disable_user'])) {
    $targetUsername = $_POST['target_username'] ?? '';
    $pdo->prepare("
        UPDATE users SET status = 'disabled', disabled_by = ?, disabled_at = NOW()
        WHERE username = ? AND role != 'admin'
    ")->execute([$username, $targetUsername]);
    $message = 'Account <strong>' . htmlspecialchars($targetUsername) . '</strong> has been disabled.';
    $msgType = 'error';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enable_user'])) {
    $targetUsername = $_POST['target_username'] ?? '';
    $pdo->prepare("
        UPDATE users SET status = 'active', disabled_reason = NULL, enabled_by = ?, enabled_at = NOW()
        WHERE username = ?
    ")->execute([$username, $targetUsername]);
    $message = 'Account <strong>' . htmlspecialchars($targetUsername) . '</strong> has been re-enabled.';
    $msgType = 'success';
}

/* ══════════════════════════════════════════
   LOAD DATA FROM DB
══════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user'])) {
    $targetUsername = trim($_POST['target_username'] ?? '');

    $stmt = $pdo->prepare("
        SELECT username, fullname
        FROM users
        WHERE username = ? AND status = 'disabled' AND role != 'admin'
        LIMIT 1
    ");
    $stmt->execute([$targetUsername]);
    $deleteTarget = $stmt->fetch();

    if ($deleteTarget) {
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE subjects SET faculty = NULL WHERE faculty = ?")->execute([$targetUsername]);
            $pdo->prepare("DELETE FROM subject_members WHERE username = ?")->execute([$targetUsername]);

            $deleteStmt = $pdo->prepare("
                DELETE FROM users
                WHERE username = ? AND status = 'disabled' AND role != 'admin'
            ");
            $deleteStmt->execute([$targetUsername]);
            $pdo->commit();

            $displayName = $deleteTarget['fullname'] ?: $targetUsername;
            $message = 'Disabled account <strong>' . htmlspecialchars($displayName) . '</strong> has been permanently deleted.';
            $msgType = 'success';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('User delete failed: ' . $e->getMessage());
            $message = 'Unable to delete that account right now. Please try again.';
            $msgType = 'error';
        }
    } else {
        $message = 'Only disabled non-admin accounts can be deleted.';
        $msgType = 'error';
    }
}

$allUsers         = $pdo->query("SELECT * FROM users ORDER BY registered_at DESC, id DESC")->fetchAll();
$authorizedPeople = $pdo->query("SELECT * FROM authorized_people ORDER BY created_at DESC")->fetchAll();
$allClasses       = $pdo->query("SELECT * FROM classes ORDER BY created_at DESC")->fetchAll();

$pendingUsers      = array_values(array_filter($allUsers, fn($u) => $u['status'] === 'pending'));
$disabledUsers     = array_values(array_filter($allUsers, fn($u) => $u['role'] !== 'admin' && $u['status'] === 'disabled'));
$activeAccountUsers = array_values(array_filter($allUsers, fn($u) => $u['role'] !== 'admin' && !in_array($u['status'], ['pending', 'disabled'])));

$totalUsers   = count($allUsers);
$pendingCount = count($pendingUsers);

// Notifications badge
$unreadNotifs = $pdo->query("SELECT COUNT(*) FROM notifications WHERE is_read = 0")->fetchColumn();

// Helper: generate unique username ID from DB
function generateUniqueId2(PDO $pdo): string {
    $year = date('y');
    do {
        $num = str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        $id  = $year . '-' . $num;
        $chk = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        $chk->execute([$id]);
    } while ($chk->fetch());
    return $id;
}

function formatAdminRegisteredDate(?string $value): string {
    if (empty($value)) {
        return 'Not recorded';
    }

    $timestamp = strtotime($value);
    return $timestamp ? date('M j, Y g:i A', $timestamp) : 'Not recorded';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin: Manage Users — Helios University</title>
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
        :root {
            --bg-base:        #f5f6fa;
            --bg-surface:     #ffffff;
            --bg-card:        #ffffff;
            --bg-card-hover:  #f8f9fc;
            --bg-elevated:    #f0f2f7;
            --bg-input:       #f5f6fa;
            --border-subtle:  #e8eaef;
            --border-light:   #d4d8e2;
            --border-accent:  rgba(26,122,74,0.25);
            --text-primary:   #1a1d2e;
            --text-secondary: #52576e;
            --text-muted:     #8b90a7;
            --text-inverse:   #ffffff;
            --accent-cyan:        #1a7a4a;
            --accent-cyan-dim:    rgba(26,122,74,0.10);
            --accent-cyan-glow:   rgba(26,122,74,0.20);
            --accent-violet:      #6c63ff;
            --accent-violet-dim:  rgba(108,99,255,0.10);
            --accent-emerald:     #0e9f6e;
            --accent-emerald-dim: rgba(14,159,110,0.10);
            --accent-amber:       #d97706;
            --accent-amber-dim:   rgba(217,119,6,0.10);
            --accent-rose:        #e53e3e;
            --accent-rose-dim:    rgba(229,62,62,0.10);
            --sidebar-w: 260px;
            --topbar-h:  60px;
            --ease: 200ms cubic-bezier(0.4,0,0.2,1);
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; background: var(--bg-base); color: var(--text-primary); font-family: 'Poppins', sans-serif; font-size: 14px; line-height: 1.6; -webkit-font-smoothing: antialiased; overflow-x: hidden; }
        a { text-decoration: none; color: inherit; }
        button { font-family: inherit; background: none; border: none; cursor: pointer; color: inherit; }
        ul, ol { list-style: none; }
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: var(--bg-base); }
        ::-webkit-scrollbar-thumb { background: var(--border-light); border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: var(--text-muted); }

        .app { display: flex; flex-direction: column; min-height: 100vh; }

        /* TOPBAR */
        .topbar { position: fixed; top: 0; left: 0; right: 0; height: var(--topbar-h); z-index: 1000; display: flex; align-items: center; justify-content: space-between; padding: 0 24px 0 0; background: var(--bg-surface); border-bottom: 1px solid var(--border-subtle); box-shadow: 0 1px 4px rgba(0,0,0,0.06); }
        .topbar-brand { display: flex; align-items: center; width: var(--sidebar-w); padding: 0 24px; height: 100%; border-right: 1px solid var(--border-subtle); gap: 10px; flex-shrink: 0; }
        .brand-icon { width: 32px; height: 32px; background: none; border-radius: 8px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; overflow:hidden; }
        .brand-logo-img { width:100%; height:100%; object-fit:contain; object-position:center; display:block; }
        .brand-logo-dark { display:none; }
        [data-theme="dark"] .brand-logo-light { display:none; }
        [data-theme="dark"] .brand-logo-dark { display:block; }
        .brand-wordmark { font-size: 17px; font-weight: 700; color: var(--text-primary); letter-spacing: -0.02em; }
        .brand-wordmark span { font-weight: 400; color: var(--text-muted); font-size: 11px; display: block; letter-spacing: 0.08em; text-transform: uppercase; margin-top: -3px; }
        .topbar-center { flex: 1; display: flex; align-items: center; padding: 0 24px; }
        .topbar-clock { font-size: 12px; color: var(--text-muted); font-variant-numeric: tabular-nums; letter-spacing: 0.04em; }
        .topbar-right { display: flex; align-items: center; gap: 4px; }
        .topbar-icon-btn { width: 36px; height: 36px; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: var(--text-secondary); position: relative; transition: background var(--ease), color var(--ease); }
        .topbar-icon-btn:hover { background: var(--bg-elevated); color: var(--text-primary); }
        .topbar-icon-btn svg { width: 18px; height: 18px; fill: currentColor; }
        .notif-badge { position: absolute; top: 5px; right: 5px; width: 8px; height: 8px; background: var(--accent-rose); border-radius: 50%; border: 2px solid var(--bg-surface); }
        .topbar-divider { width: 1px; height: 24px; background: var(--border-subtle); margin: 0 8px; }
        .avatar-btn { display: flex; align-items: center; gap: 10px; padding: 6px 10px; border-radius: 8px; transition: background var(--ease); }
        .avatar-btn:hover { background: var(--bg-elevated); }
        .avatar-circle { width: 32px; height: 32px; border-radius: 8px; background: linear-gradient(135deg, #1a7a4a, #0e9f6e); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 13px; color: #fff; flex-shrink: 0; }
        .avatar-meta { line-height: 1.3; text-align: left; }
        .avatar-name { font-size: 13px; font-weight: 500; color: var(--text-primary); }
        .avatar-role { font-size: 10px; text-transform: uppercase; letter-spacing: 0.08em; color: var(--accent-cyan); }
        .account-popover { position: fixed; top: 72px; right: 24px; width: min(360px, calc(100vw - 32px)); background: var(--bg-surface); border: 1px solid var(--border-light); border-radius: 28px; box-shadow: 0 14px 40px rgba(26,29,46,0.22); z-index: 1200; padding: 20px; display: none; text-align: center; }
        .account-popover.open { display: block; }
        .account-popover-close { position: absolute; top: 16px; right: 18px; width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: var(--text-secondary); font-size: 24px; line-height: 1; }
        .account-popover-close:hover { background: rgba(26,29,46,0.08); color: var(--text-primary); }
        .account-email { padding: 4px 40px 14px; font-size: 14px; font-weight: 500; color: var(--text-primary); word-break: break-word; }
        .account-avatar-large { width: 98px; height: 98px; border-radius: 50%; margin: 14px auto 12px; background: #78909c; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 44px; font-weight: 500; box-shadow: inset 0 0 0 4px rgba(255,255,255,0.14); }
        .account-greeting { font-size: 24px; font-weight: 500; color: var(--text-primary); margin-bottom: 16px; }
        .account-role-pill { display: inline-flex; align-items: center; justify-content: center; min-height: 38px; padding: 0 22px; border: 1px solid rgba(26,29,46,0.35); border-radius: 999px; color: var(--accent-cyan); font-weight: 600; background: var(--bg-elevated); margin-bottom: 18px; }
        .account-actions { background: var(--bg-elevated); border-radius: 18px; overflow: hidden; text-align: left; }
        .account-theme-toggle, .account-signout { display: flex; align-items: center; gap: 14px; width: 100%; padding: 16px 22px; color: var(--text-primary); font-size: 15px; font-weight: 600; text-decoration: none; }
        .account-theme-toggle { justify-content: space-between; border: 0; background: transparent; }
        .account-theme-toggle .theme-copy { display: inline-flex; align-items: center; gap: 12px; }
        .account-theme-toggle svg { width: 20px; height: 20px; stroke: currentColor; fill: none; stroke-width: 2; }
        .icon-sun { display: none; }
        .theme-switch { width: 48px; height: 28px; border-radius: 999px; background: rgba(88,101,124,.16); padding: 3px; display: inline-flex; align-items: center; flex-shrink: 0; transition: background var(--ease); }
        .theme-switch-knob { width: 22px; height: 22px; border-radius: 50%; background: #fff; box-shadow: 0 4px 10px rgba(15,23,42,.16); transition: transform var(--ease); }
        .account-signout:hover, .account-theme-toggle:hover { background: var(--bg-base); }
        .account-signout svg { width: 20px; height: 20px; fill: currentColor; color: var(--text-secondary); }
        .menu-toggle { width: 36px; height: 36px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px; border-radius: 8px; transition: background var(--ease); margin-right: 8px; }
        .menu-toggle:hover { background: var(--bg-elevated); }
        .menu-toggle span { display: block; width: 16px; height: 1.5px; background: var(--text-secondary); border-radius: 2px; }

        /* SIDEBAR */
        .body-wrap { display: flex; flex: 1; margin-top: var(--topbar-h); }
        .sidebar { position: fixed; top: var(--topbar-h); bottom: 0; left: 0; width: var(--sidebar-w); background: var(--bg-surface); border-right: 1px solid var(--border-subtle); overflow-y: auto; z-index: 900; padding: 16px 0 24px; transition: transform var(--ease); display: flex; flex-direction: column; }
        .sidebar.collapsed { transform: translateX(-100%); }
        .nav-section-label { font-size: 10px; font-weight: 600; letter-spacing: 0.12em; text-transform: uppercase; color: var(--text-muted); padding: 16px 20px 6px; }
        .nav-link { display: flex; align-items: center; gap: 12px; padding: 9px 20px; color: var(--text-secondary); font-size: 13.5px; font-weight: 400; transition: color var(--ease), background var(--ease); position: relative; }
        .nav-link:hover { color: var(--text-primary); background: var(--bg-elevated); }
        .nav-link.active { color: var(--accent-cyan); background: var(--accent-cyan-dim); font-weight: 500; }
        .nav-link.active::before { content: ''; position: absolute; left: 0; top: 4px; bottom: 4px; width: 3px; background: var(--accent-cyan); border-radius: 0 3px 3px 0; }
        .nav-link svg { width: 16px; height: 16px; fill: currentColor; flex-shrink: 0; opacity: 0.7; }
        .nav-link.active svg { opacity: 1; }
        .nav-badge { margin-left: auto; background: var(--accent-rose); color: #fff; font-size: 10px; font-weight: 600; padding: 1px 6px; border-radius: 20px; min-width: 18px; text-align: center; }
        .nav-badge.dim { background: var(--bg-elevated); color: var(--text-muted); }
        .nav-divider { height: 1px; background: var(--border-subtle); margin: 8px 16px; }
        .sidebar-footer { margin-top: auto; padding: 12px 12px 0; border-top: 1px solid var(--border-subtle); }
        .sidebar-footer .nav-link { border-radius: 8px; }

        /* MAIN */
        .main { flex: 1; margin-left: var(--sidebar-w); padding: 32px 36px 48px; transition: margin-left var(--ease); max-width: 1440px; }
        .main.expanded { margin-left: 0; }
        .page-header-row { display: flex; align-items: flex-end; justify-content: space-between; margin-bottom: 32px; flex-wrap: wrap; gap: 16px; }
        .page-eyebrow { font-size: 11px; text-transform: uppercase; letter-spacing: 0.12em; color: var(--accent-cyan); font-weight: 600; margin-bottom: 4px; }
        .page-title { font-size: 26px; font-weight: 700; color: var(--text-primary); letter-spacing: -0.02em; line-height: 1.2; }
        .page-subtitle { font-size: 13px; color: var(--text-muted); margin-top: 6px; }

        /* ALERT */
        .alert { display: flex; align-items: center; gap: 10px; padding: 14px 18px; border-radius: 10px; font-size: 13px; font-weight: 500; margin-bottom: 24px; }
        .alert.success { background: var(--accent-emerald-dim); color: var(--accent-emerald); border: 1px solid rgba(14,159,110,0.2); }
        .alert.error   { background: var(--accent-rose-dim);    color: var(--accent-rose);    border: 1px solid rgba(229,62,62,0.2); }

        /* PANEL */
        .panel { background: var(--bg-card); border: 1px solid var(--border-subtle); border-radius: 14px; overflow: hidden; margin-bottom: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.04); }
        .panel-header { display: flex; align-items: center; justify-content: space-between; padding: 18px 22px; border-bottom: 1px solid var(--border-subtle); }
        .panel-title { font-size: 14px; font-weight: 600; color: var(--text-primary); display: flex; align-items: center; gap: 8px; }
        .panel-title-dot { width: 6px; height: 6px; border-radius: 50%; background: var(--accent-cyan); box-shadow: 0 0 6px var(--accent-cyan); flex-shrink: 0; }

        /* USER TABLE */
        .user-table { width: 100%; border-collapse: collapse; }
        .user-table thead th { font-size: 11px; text-transform: uppercase; letter-spacing: 0.08em; color: var(--text-muted); font-weight: 600; padding: 12px 22px; text-align: left; background: var(--bg-base); border-bottom: 1px solid var(--border-subtle); }
        .user-table tbody tr { border-bottom: 1px solid var(--border-subtle); transition: background var(--ease); }
        .user-table tbody tr:last-child { border-bottom: none; }
        .user-table tbody tr:hover { background: var(--bg-card-hover); }
        .user-table tbody td { padding: 13px 22px; vertical-align: middle; font-size: 13.5px; }
        .user-cell { display: flex; align-items: center; gap: 10px; }
        .user-avatar { width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; color: #fff; flex-shrink: 0; }
        .user-avatar.s { background: linear-gradient(135deg, #1a7a4a, #0e9f6e); }
        .user-avatar.f { background: linear-gradient(135deg, #6c63ff, #a78bfa); }
        .user-avatar.a { background: linear-gradient(135deg, #d97706, #f59e0b); }
        .user-name     { font-size: 13.5px; font-weight: 500; color: var(--text-primary); }
        .user-username { font-size: 11px; color: var(--text-muted); }
        .role-chip { display: inline-flex; align-items: center; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; letter-spacing: 0.04em; text-transform: capitalize; }
        .role-chip.student { background: rgba(56,189,248,0.1); color: var(--accent-cyan); }
        .role-chip.faculty { background: rgba(52,211,153,0.1); color: var(--accent-emerald); }
        .role-chip.admin   { background: rgba(167,139,250,0.1); color: var(--accent-violet); }
        .status-dot { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 500; }
        .status-dot::before { content: ''; width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0; }
        .status-dot.active::before   { background: var(--accent-emerald); box-shadow: 0 0 4px var(--accent-emerald); }
        .status-dot.active   { color: var(--accent-emerald); }
        .status-dot.pending::before  { background: var(--accent-amber); }
        .status-dot.pending  { color: var(--accent-amber); }
        .status-dot.disabled::before { background: var(--text-muted); }
        .status-dot.disabled { color: var(--text-muted); }
        .status-dot.rejected::before { background: var(--accent-rose); }
        .status-dot.rejected { color: var(--accent-rose); }
        .btn-approve { padding: 8px 16px; font-family: 'Poppins', sans-serif; font-size: 12px; font-weight: 600; color: #fff; background: var(--accent-emerald); border: none; border-radius: 8px; cursor: pointer; transition: all 0.18s; display: inline-flex; align-items: center; gap: 6px; }
        .btn-approve:hover { background: #047857; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(5,150,105,0.25); }
        .btn-reject  { padding: 8px 14px; font-family: 'Poppins', sans-serif; font-size: 12px; font-weight: 600; color: var(--accent-rose); background: var(--accent-rose-dim); border: 1px solid transparent; border-radius: 8px; cursor: pointer; transition: all 0.18s; }
        .btn-reject:hover  { background: var(--accent-rose); color: #fff; }
        .btn-disable { padding: 6px 13px; font-family: 'Poppins', sans-serif; font-size: 12px; font-weight: 600; color: var(--accent-rose); background: var(--accent-rose-dim); border: 1px solid transparent; border-radius: 7px; cursor: pointer; transition: all 0.18s; }
        .btn-disable:hover { background: var(--accent-rose); color: #fff; }
        .btn-enable  { padding: 6px 13px; font-family: 'Poppins', sans-serif; font-size: 12px; font-weight: 600; color: var(--accent-emerald); background: var(--accent-emerald-dim); border: 1px solid transparent; border-radius: 7px; cursor: pointer; transition: all 0.18s; }
        .btn-enable:hover  { background: var(--accent-emerald); color: #fff; }
        .btn-delete { padding: 6px 13px; font-family: 'Poppins', sans-serif; font-size: 12px; font-weight: 600; color: #fff; background: var(--accent-rose); border: 1px solid transparent; border-radius: 7px; cursor: pointer; transition: all 0.18s; }
        .btn-delete:hover { background: #a50e0e; transform: translateY(-1px); }
        .account-actions-inline { display: inline-flex; align-items: center; gap: 8px; flex-wrap: wrap; }

        /* ANIMATIONS */
        @keyframes fadeUp { from{opacity:0;transform:translateY(12px)} to{opacity:1;transform:translateY(0)} }
        .animate { animation: fadeUp 0.4s ease forwards; }
        .animate-d1 { animation-delay: 0.05s; opacity: 0; }
        .animate-d2 { animation-delay: 0.10s; opacity: 0; }
        .animate-d3 { animation-delay: 0.15s; opacity: 0; }

        /* DARK MODE */
        [data-theme="dark"] {
            --bg-base:#07111f; --bg-surface:#1c222d; --bg-card:#1c222d; --bg-card-hover:#232c39;
            --bg-elevated:#13202e; --bg-input:#13202e;
            --border-subtle:rgba(125,139,164,.16); --border-light:rgba(125,139,164,.22);
            --text-primary:#eef4ff; --text-secondary:#aeb9cb; --text-muted:#7f8ca3;
        }
        [data-theme="dark"] .icon-sun { display: block; }
        [data-theme="dark"] .icon-moon { display: none; }
        [data-theme="dark"] .theme-switch { background: rgba(103,201,176,.38); }
        [data-theme="dark"] .theme-switch-knob { transform: translateX(20px); }
        [data-theme="dark"] .account-popover { background: rgba(28,34,45,.96); }

        @media (max-width: 1024px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.mobile-open { transform: translateX(0); box-shadow: 20px 0 60px rgba(0,0,0,0.15); }
            .main { margin-left: 0; padding: 24px 20px 40px; }
        }
        @media (max-width: 680px) {
            .page-header-row { flex-direction: column; align-items: flex-start; }
        }

        /* Dashboard-aligned admin shell */
        :root {
            --bg-base:#f5f6fa;
            --bg-surface:#ffffff;
            --bg-card:#ffffff;
            --bg-card-hover:#f8f9fc;
            --bg-elevated:#f0f2f7;
            --bg-input:#f5f6fa;
            --border-subtle:#e8eaef;
            --border-light:#d4d8e2;
            --text-primary:#1a1d2e;
            --text-secondary:#52576e;
            --text-muted:#8b90a7;
            --accent-cyan:#1a7a4a;
            --accent-cyan-dim:rgba(26,122,74,0.10);
            --accent-emerald:#1a7a4a;
            --accent-emerald-dim:rgba(26,122,74,0.10);
            --accent-amber:#d97706;
            --accent-amber-dim:rgba(217,119,6,0.10);
            --accent-rose:#d93025;
            --accent-rose-dim:#fce8e6;
            --accent-violet:#52576e;
            --accent-violet-dim:#f0f2f7;
            --sidebar-w:260px;
            --topbar-h:60px;
        }
        body { background: var(--bg-base); }
        .main { padding: 32px 36px 48px; max-width: 1440px; }
        .panel { border-radius: 14px; border-color: var(--border-subtle); box-shadow: none; }
        .nav-badge { background: var(--accent-rose); color: #fff; border-radius: 20px; }
        .nav-badge.dim { background: var(--bg-elevated); color: var(--text-muted); }
        .role-chip.student,
        .role-chip.faculty,
        .role-chip.admin { background: var(--accent-cyan-dim); color: var(--accent-cyan); }
        .status-dot.active,
        .status-dot.active::before { color: var(--accent-emerald); }
        .status-dot.active::before { background: var(--accent-emerald); box-shadow: none; }
        .status-dot.pending::before { background: var(--accent-amber); }
        .status-dot.pending { color: var(--accent-amber); }
        .status-dot.disabled::before { background: var(--text-muted); }
        .status-dot.disabled { color: var(--text-muted); }
        [data-theme="dark"] {
            --bg-base:#07111f;
            --bg-surface:#0d1b2e;
            --bg-card:#0d1b2e;
            --bg-card-hover:#13202e;
            --bg-elevated:#13202e;
            --bg-input:#13202e;
            --border-subtle:rgba(125,139,164,.16);
            --border-light:rgba(125,139,164,.22);
            --text-primary:#eef4ff;
            --text-secondary:#aeb9cb;
            --text-muted:#7f8ca3;
            --accent-cyan:#0e9f6e;
            --accent-cyan-dim:rgba(14,159,110,0.12);
            --accent-emerald:#0e9f6e;
            --accent-emerald-dim:rgba(14,159,110,0.12);
            --accent-amber-dim:rgba(217,119,6,0.18);
            --accent-rose-dim:rgba(229,139,137,0.18);
            --accent-violet-dim:rgba(125,139,164,0.14);
        }
        [data-theme="dark"] .account-popover-close,
        [data-theme="dark"] .account-role-pill,
        [data-theme="dark"] .account-signout,
        [data-theme="dark"] .account-theme-toggle {
            background: rgba(255,255,255,0.08);
            border-color: rgba(125,139,164,0.16);
        }
        [data-theme="dark"] .account-signout:hover,
        [data-theme="dark"] .account-theme-toggle:hover {
            background: rgba(255,255,255,0.12);
        }
    </style>
</head>
<body>

<svg style="display:none" xmlns="http://www.w3.org/2000/svg">
    <symbol id="ic-dashboard"  viewBox="0 0 24 24"><path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/></symbol>
    <symbol id="ic-users"      viewBox="0 0 24 24"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></symbol>
    <symbol id="ic-class"      viewBox="0 0 24 24"><path d="M18 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-2 14H8v-2h8v2zm0-4H8v-2h8v2zm0-4H8V6h8v2z"/></symbol>
    <symbol id="ic-bell"       viewBox="0 0 24 24"><path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6v-5c0-3.07-1.64-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.63 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z"/></symbol>
    <symbol id="ic-settings"   viewBox="0 0 24 24"><path d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.06-.94l2.03-1.58c.18-.14.23-.41.12-.61l-1.92-3.32c-.12-.22-.37-.29-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54c-.04-.24-.24-.41-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.56-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.04.3-.07.63-.07.94s.02.64.07.94l-2.03 1.58c-.18.14-.23.41-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z"/></symbol>
    <symbol id="ic-logout"     viewBox="0 0 24 24"><path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/></symbol>
    <symbol id="ic-pending"    viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67V7z"/></symbol>
</svg>

<div class="app">

    <!-- TOPBAR -->
    <header class="topbar">
        <div class="topbar-brand">
            <button class="menu-toggle" id="menuToggle" aria-label="Toggle sidebar">
                <span></span><span></span><span></span>
            </button>
            <div class="brand-icon">
                <img class="brand-logo-img brand-logo-light" src="img/Light Icon.png" alt="Helios University">
                <img class="brand-logo-img brand-logo-dark"  src="img/Dark Icon.png"  alt="Helios University">
            </div>
            <div class="brand-wordmark">Helios<span>Admin Center</span></div>
        </div>
        <div class="topbar-center">
            <span class="topbar-clock" id="liveClock"></span>
        </div>
        <div class="topbar-right">
            <a href="role_admin_systemSettings.php" class="topbar-icon-btn" title="System Settings">
                <svg><use href="#ic-settings"></use></svg>
            </a>
            <div class="topbar-divider"></div>
            <button type="button" class="avatar-btn" id="accountButton" aria-haspopup="dialog" aria-expanded="false">
                <div class="avatar-circle"><svg viewBox="0 0 24 24" style="width:18px;height:18px;fill:#fff;"><path d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.06-.94l2.03-1.58c.18-.14.23-.41.12-.61l-1.92-3.32c-.12-.22-.37-.29-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54c-.04-.24-.24-.41-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59-.24-1.13-.56-1.62-.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.49.12.61l2.03 1.58c-.05.3-.09.63-.09.94s.02.64.06.94l-2.03 1.58c-.18.14-.23.41-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .43-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.49-.12-.61l-2.01-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z"/></svg></div>
                <div class="avatar-meta">
                    <div class="avatar-name"><?= htmlspecialchars($username) ?></div>
                    <div class="avatar-role">Administrator</div>
                </div>
            </button>
        </div>
    </header>

    <div class="account-popover" id="accountPopover" role="dialog">
        <button type="button" class="account-popover-close" id="accountPopoverClose">&times;</button>
        <div class="account-email"><?= htmlspecialchars($username) ?></div>
        <div class="account-avatar-large"><?= $initials ?></div>
        <div class="account-greeting">Hi, <?= htmlspecialchars($username) ?>!</div>
        <div class="account-role-pill">Administrator Account</div>
        <div class="account-actions">
            <button type="button" class="account-theme-toggle theme-toggle">
                <span class="theme-copy">
                    <svg class="icon-moon" viewBox="0 0 24 24"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
                    <svg class="icon-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/></svg>
                    Dark mode
                </span>
                <span class="theme-switch"><span class="theme-switch-knob"></span></span>
            </button>
            <a href="logout.php" class="account-signout">
                <svg><use href="#ic-logout"></use></svg>
                Sign out
            </a>
        </div>
    </div>

    <div class="body-wrap">

        <!-- SIDEBAR -->
        <nav class="sidebar" id="sidebar">
            <div class="nav-section-label">Overview</div>
            <a href="dashboard_admin.php" class="nav-link">
                <svg><use href="#ic-dashboard"></use></svg> Dashboard
            </a>
            <div class="nav-divider"></div>
            <div class="nav-section-label">Management</div>
            <a href="role_admin_manageUsers.php" class="nav-link active">
                <svg><use href="#ic-users"></use></svg> Manage Users
                <?php if ($totalUsers > 0): ?><span class="nav-badge dim"><?= $totalUsers ?></span><?php endif; ?>
            </a>
            <a href="pending_approval.php" class="nav-link">
                <svg><use href="#ic-pending"></use></svg> Pending Approval
                <?php if ($pendingCount > 0): ?><span class="nav-badge"><?= $pendingCount ?></span><?php endif; ?>
            </a>
            <a href="role_admin_manageClasses.php" class="nav-link">
                <svg><use href="#ic-class"></use></svg> Manage Classes
                <span class="nav-badge dim"><?= count($allClasses) ?></span>
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

        <!-- MAIN CONTENT -->
        <main class="main" id="mainContent">

            <div class="page-header-row animate animate-d1">
                <div>
                    <div class="page-eyebrow">Admin · Management</div>
                    <h1 class="page-title">Manage Users</h1>
                    <p class="page-subtitle">Manage active and disabled user accounts.</p>
                </div>
            </div>

            <?php if ($message): ?>
            <div class="alert <?= $msgType ?> animate animate-d1"><?= $message ?></div>
            <?php endif; ?>

            <!-- ADD AUTHORIZED PERSON -->
            <div class="panel animate animate-d2" style="margin-bottom:24px;">
                <div class="panel-header">
                    <div class="panel-title">
                        <span class="panel-title-dot"></span>
                        Add Authorized Person
                    </div>
                    <span style="font-size:12px; color:var(--text-muted);"><?= count($authorizedPeople) ?> authorized</span>
                </div>
                <form method="POST" style="padding:20px 22px; display:grid; grid-template-columns:repeat(5,minmax(140px,1fr)); gap:12px; align-items:end;">
                    <label style="display:grid; gap:6px; font-size:11px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:.06em;">
                        First Name
                        <input name="auth_firstname" required style="min-height:40px; border:1px solid var(--border-light); border-radius:8px; padding:0 12px; background:var(--bg-input); color:var(--text-primary);">
                    </label>
                    <label style="display:grid; gap:6px; font-size:11px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:.06em;">
                        Last Name
                        <input name="auth_lastname" required style="min-height:40px; border:1px solid var(--border-light); border-radius:8px; padding:0 12px; background:var(--bg-input); color:var(--text-primary);">
                    </label>
                    <label style="display:grid; gap:6px; font-size:11px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:.06em;">
                        Email
                        <input type="email" name="auth_email" required style="min-height:40px; border:1px solid var(--border-light); border-radius:8px; padding:0 12px; background:var(--bg-input); color:var(--text-primary);">
                    </label>
                    <label style="display:grid; gap:6px; font-size:11px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:.06em;">
                        Phone
                        <input name="auth_phone" required placeholder="+639XXXXXXXXX" style="min-height:40px; border:1px solid var(--border-light); border-radius:8px; padding:0 12px; background:var(--bg-input); color:var(--text-primary);">
                    </label>
                    <label style="display:grid; gap:6px; font-size:11px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:.06em;">
                        Role
                        <select name="auth_role" style="min-height:40px; border:1px solid var(--border-light); border-radius:8px; padding:0 12px; background:var(--bg-input); color:var(--text-primary);">
                            <option value="student">Student</option>
                            <option value="faculty">Faculty</option>
                        </select>
                    </label>
                    <button type="submit" name="add_authorized_person" class="btn-approve" style="min-height:40px;">Add Person</button>
                </form>
            </div>

            <!-- PENDING APPROVAL NOTICE -->
            <?php if ($pendingCount > 0): ?>
            <div class="panel animate animate-d2" style="margin-bottom:24px;">
                <div style="display:flex; align-items:center; justify-content:space-between; gap:14px; padding:16px 22px; flex-wrap:wrap;">
                    <div style="display:flex; align-items:center; gap:10px; font-size:13px; color:var(--text-muted);">
                        <svg style="width:16px;height:16px;fill:var(--accent-amber);flex-shrink:0;"><use href="#ic-pending"></use></svg>
                        <?= $pendingCount ?> pending registration request<?= $pendingCount === 1 ? '' : 's' ?> awaiting review.
                    </div>
                    <a href="pending_approval.php" class="btn-approve" style="text-decoration:none;">Open Pending Approval</a>
                </div>
            </div>
            <?php endif; ?>

            <!-- DISABLED ACCOUNTS -->
            <?php if (!empty($disabledUsers)): ?>
            <div class="panel animate animate-d2" style="margin-bottom:24px;">
                <div class="panel-header">
                    <div class="panel-title"><span class="panel-title-dot"></span> Disabled Accounts</div>
                    <span style="font-size:12px; color:var(--text-muted);"><?= count($disabledUsers) ?> grouped</span>
                </div>
                <div style="overflow-x:auto;">
                    <table class="user-table">
                        <thead><tr><th>User</th><th>Role</th><th>Disabled</th><th>Actions</th></tr></thead>
                        <tbody>
                        <?php foreach ($disabledUsers as $u):
                            $uRole      = $u['role'] ?? 'student';
                            $avatarClass = $uRole === 'faculty' ? 'f' : 's';
                            $initLetter  = strtoupper(substr($u['fullname'] ?? $u['username'] ?? 'U', 0, 1));
                        ?>
                        <tr>
                            <td>
                                <div class="user-cell">
                                    <div class="user-avatar <?= $avatarClass ?>"><?= $initLetter ?></div>
                                    <div>
                                        <div class="user-name"><?= htmlspecialchars($u['fullname'] ?? $u['username']) ?></div>
                                        <div class="user-username">@<?= htmlspecialchars($u['username']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td><span class="role-chip <?= $uRole ?>"><?= ucfirst($uRole) ?></span></td>
                            <td style="color:var(--text-muted); font-size:12px;"><?= htmlspecialchars($u['disabled_at'] ?? '—') ?></td>
                            <td>
                                <div class="account-actions-inline">
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="target_username" value="<?= htmlspecialchars($u['username']) ?>">
                                        <button type="submit" name="enable_user" class="btn-enable"
                                                onclick="return confirm('Re-enable <?= htmlspecialchars($u['username']) ?>?')">Enable</button>
                                    </form>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="target_username" value="<?= htmlspecialchars($u['username']) ?>">
                                        <button type="submit" name="delete_user" class="btn-delete"
                                                onclick="return confirm('Permanently delete <?= htmlspecialchars($u['username']) ?>? This cannot be undone.')">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- ALL ACCOUNTS -->
            <div class="panel animate animate-d2">
                <div class="panel-header">
                    <div class="panel-title"><span class="panel-title-dot"></span> All Accounts</div>
                    <span style="font-size:12px; color:var(--text-muted);"><?= count($activeAccountUsers) ?> users</span>
                </div>
                <div style="overflow-x:auto;">
                    <table class="user-table">
                        <thead><tr><th>User</th><th>Role</th><th>Status</th><th>Registered</th><th>Action</th></tr></thead>
                        <tbody>
                        <?php foreach ($activeAccountUsers as $u):
                            $uStatus     = $u['status'] ?? 'active';
                            $uRole       = $u['role']   ?? 'student';
                            $isDisabled  = $uStatus === 'disabled';
                            $canToggle   = in_array($uStatus, ['active', 'disabled']);
                            $avatarClass = $uRole === 'faculty' ? 'f' : ($uRole === 'admin' ? 'a' : 's');
                            $initLetter  = strtoupper(substr($u['fullname'] ?? $u['username'] ?? 'U', 0, 1));
                        ?>
                        <tr>
                            <td>
                                <div class="user-cell">
                                    <div class="user-avatar <?= $avatarClass ?>"><?= $initLetter ?></div>
                                    <div>
                                        <div class="user-name"><?= htmlspecialchars($u['fullname'] ?? $u['username']) ?></div>
                                        <div class="user-username">@<?= htmlspecialchars($u['username']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td><span class="role-chip <?= $uRole ?>"><?= ucfirst($uRole) ?></span></td>
                            <td><span class="status-dot <?= $uStatus ?>"><?= ucfirst($uStatus) ?></span></td>
                            <td style="color:var(--text-muted); font-size:12px;"><?= htmlspecialchars(formatAdminRegisteredDate($u['registered_at'] ?? null)) ?></td>
                            <td>
                                <?php if ($canToggle): ?>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="target_username" value="<?= htmlspecialchars($u['username']) ?>">
                                    <?php if ($isDisabled): ?>
                                        <button type="submit" name="enable_user" class="btn-enable"
                                                onclick="return confirm('Re-enable <?= htmlspecialchars($u['username']) ?>?')">Enable</button>
                                    <?php else: ?>
                                        <button type="submit" name="disable_user" class="btn-disable"
                                                onclick="return confirm('Disable <?= htmlspecialchars($u['username']) ?>?')">Disable</button>
                                    <?php endif; ?>
                                </form>
                                <?php else: ?>
                                <span style="font-size:12px; color:var(--text-muted);">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </main>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
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
        const hh = String(now.getHours()).padStart(2,'0');
        const mm = String(now.getMinutes()).padStart(2,'0');
        const ss = String(now.getSeconds()).padStart(2,'0');
        if (clockEl) clockEl.textContent = `${hh}:${mm}:${ss}`;
    }
    updateClock();
    setInterval(updateClock, 1000);

    const sidebar     = document.getElementById('sidebar');
    const mainContent = document.getElementById('mainContent');
    const menuToggle  = document.getElementById('menuToggle');
    const accountButton  = document.getElementById('accountButton');
    const accountPopover = document.getElementById('accountPopover');
    const accountPopoverClose = document.getElementById('accountPopoverClose');

    function handleResize() {
        if (window.innerWidth > 1024) sidebar.classList.remove('mobile-open');
        else { sidebar.classList.remove('collapsed'); mainContent.classList.remove('expanded'); }
    }
    window.addEventListener('resize', handleResize);
    handleResize();

    menuToggle.addEventListener('click', () => {
        if (window.innerWidth > 1024) {
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('expanded');
        } else {
            sidebar.classList.toggle('mobile-open');
        }
    });

    document.addEventListener('click', (e) => {
        if (window.innerWidth <= 1024 && sidebar.classList.contains('mobile-open')
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
    if (accountPopoverClose) {
        accountPopoverClose.addEventListener('click', () => {
            accountPopover.classList.remove('open');
            accountButton.setAttribute('aria-expanded', 'false');
        });
    }
});
</script>
</body>
</html>
