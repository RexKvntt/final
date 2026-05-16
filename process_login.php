<?php
/**
 * Helios University Academic Hub
 * process_login.php — MySQL version
 *
 * All original features preserved:
 *   - Config-aware OTP enforcement (from config table)
 *   - Account status checks (pending, disabled, rejected)
 *   - Temporary password expiry → auto-disables account
 *   - OTP channel selection flow
 *   - Role mismatch detection
 *   - must_change_password flag
 */

session_start();

require 'vendor/autoload.php';
require 'auth_helpers.php';
require_once 'db.php';

/* ── 1. LOAD GLOBAL CONFIGURATION FROM DB ───────────────────
   Previously read from config.json — now from the database.
   Falls back to safe defaults if the table is empty.
────────────────────────────────────────────────────────── */
try {
    $configStmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
    $configRows = $configStmt->fetchAll();
    $config = [];
    foreach ($configRows as $row) {
        $config[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {
    // Fail-safe defaults if system_settings table isn't set up yet
    $config = [
        'org_name'    => 'Helios University',
        'enforce_otp' => true,
    ];
}

/* ── 2. INPUT VALIDATION ────────────────────────────────────
────────────────────────────────────────────────────────── */
$inputUser     = trim($_POST['username'] ?? '');
$inputPassword = $_POST['password'] ?? '';
$inputRole     = trim($_POST['role'] ?? '');

$loginParams = http_build_query(['user' => $inputUser, 'role' => $inputRole]);

if (empty($inputUser) || empty($inputPassword) || empty($inputRole)) {
    header("Location: login.php?error=empty_fields&$loginParams");
    exit();
}

if (!in_array($inputRole, ['student', 'faculty', 'admin'], true)) {
    header("Location: login.php?error=role_mismatch&$loginParams");
    exit();
}

/* ── 3. FETCH USER FROM DATABASE ────────────────────────────
   Matches on username (YY-XXXX permanent ID).
   Previously looped through users.json — now a single query.
────────────────────────────────────────────────────────── */
$stmt = $pdo->prepare("
    SELECT id, username, password, role, status,
           must_change_password, temp_password_expires_at,
           disabled_reason
      FROM users
     WHERE username = :username
     LIMIT 1
");
$stmt->execute([':username' => $inputUser]);
$user = $stmt->fetch();

// No account found
if (!$user) {
    header("Location: login.php?error=not_found&$loginParams");
    exit();
}

/* ── 4. ROLE CHECK ──────────────────────────────────────────
────────────────────────────────────────────────────────── */
if ($user['role'] !== $inputRole) {
    header("Location: login.php?error=role_mismatch&$loginParams");
    exit();
}

/* ── 5. PASSWORD VERIFICATION ───────────────────────────────
────────────────────────────────────────────────────────── */
if (empty($user['password']) || !password_verify($inputPassword, $user['password'])) {
    header("Location: login.php?error=wrong_password&$loginParams");
    exit();
}

/* ── 6. ACCOUNT STATUS CHECKS ───────────────────────────────
────────────────────────────────────────────────────────── */
$status = $user['status'];

if ($status === 'rejected') {
    header("Location: login.php?error=rejected&$loginParams");
    exit();
}

if ($status === 'disabled') {
    $reason = ($user['disabled_reason'] ?? '') === 'temporary_password_expired'
        ? '?reason=password_expired'
        : '';
    header("Location: disabled_account.php$reason");
    exit();
}

if ($status === 'pending') {
    $_SESSION['username'] = $inputUser;
    $_SESSION['role']     = $user['role'];
    $_SESSION['status']   = 'pending';
    header("Location: pending_approval.php");
    exit();
}

/* ── 7. TEMPORARY PASSWORD EXPIRY CHECK ─────────────────────
   If admin issued a temp password and it has expired,
   disable the account and boot the user out.
   Admins are exempt from this check.
────────────────────────────────────────────────────────── */
if ($user['role'] !== 'admin' && !empty($user['must_change_password'])) {
    $expiresAt = (int)($user['temp_password_expires_at'] ?? 0);
    if ($expiresAt > 0 && time() > $expiresAt) {

        // Disable the account in the DB
        $pdo->prepare("
            UPDATE users
               SET status          = 'disabled',
                   disabled_at     = NOW(),
                   disabled_reason = 'temporary_password_expired'
             WHERE id = :id
        ")->execute([':id' => $user['id']]);

        session_unset();
        session_destroy();
        header("Location: disabled_account.php?reason=password_expired");
        exit();
    }
}

/* ── 8. OTP FLOW OR DIRECT LOGIN ────────────────────────────
   Check config — if OTP is enforced, send to channel select.
   Otherwise establish session and go straight to dashboard.
────────────────────────────────────────────────────────── */
$isOtpEnforced = ($config['enforce_otp'] ?? '1') === '1';

if ($isOtpEnforced) {

    // Store identity temporarily — OTP is generated after channel pick
    $_SESSION['temp_user'] = $inputUser;
    $_SESSION['temp_role'] = $user['role'];

    header("Location: otp_channel_select.php");
    exit();

} else {

    // OTP disabled — establish full session immediately
    $_SESSION['username'] = $user['username'];
    $_SESSION['role']     = $user['role'];
    $_SESSION['status']   = $user['status'];

    header("Location: dashboard.php");
    exit();
}