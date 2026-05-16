<?php
session_start();

require_once 'db.php';

$inputOTP = trim($_POST['otp'] ?? '');
$username = $_SESSION['temp_user'] ?? null;
$role     = $_SESSION['temp_role'] ?? null;
$channel  = $_SESSION['otp_channel'] ?? 'email';

if (!$username || !$role) {
    header("Location: login.php?error=session_expired");
    exit();
}

if (empty($inputOTP)) {
    header("Location: otp_verification.php?error=empty");
    exit();
}

if (!preg_match('/^\d{6}$/', $inputOTP)) {
    header("Location: otp_verification.php?error=invalid_format");
    exit();
}

/* ── Fetch user from DB ── */
$stmt = $pdo->prepare("
    SELECT id, otp, otp_expiry, status
      FROM users
     WHERE username = :username
     LIMIT 1
");
$stmt->execute([':username' => $username]);
$user = $stmt->fetch();

if (!$user) {
    header("Location: login.php?error=not_found");
    exit();
}

/* ══════════════════════════════════════════════
   CHANNEL: SMS — verify via Twilio Verify
══════════════════════════════════════════════ */
if ($channel === 'sms') {
    $phone = $_SESSION['otp_phone'] ?? null;
    if (!$phone) {
        header("Location: login.php?error=session_expired");
        exit();
    }

    require_once 'twilio_verify.php';
    $approved = verifyOtpViaTwilio($phone, $inputOTP);

    if ($approved) {
        unset($_SESSION['otp_phone'], $_SESSION['otp_channel']);
        $_SESSION['username'] = $username;
        $_SESSION['role']     = $role;
        $_SESSION['status']   = $user['status'];
        unset($_SESSION['temp_user'], $_SESSION['temp_role']);
        header("Location: dashboard.php");
        exit();
    } else {
        header("Location: otp_verification.php?error=wrong_otp");
        exit();
    }
}

/* ══════════════════════════════════════════════
   CHANNEL: EMAIL — verify via DB
══════════════════════════════════════════════ */
if (time() > (int)($user['otp_expiry'] ?? 0)) {
    header("Location: otp_verification.php?error=expired");
    exit();
}

if ((string)$user['otp'] === $inputOTP) {
    $pdo->prepare("UPDATE users SET otp = NULL, otp_expiry = NULL WHERE id = :id")
        ->execute([':id' => $user['id']]);

    $_SESSION['username'] = $username;
    $_SESSION['role']     = $role;
    $_SESSION['status']   = $user['status'];
    unset($_SESSION['temp_user'], $_SESSION['temp_role'], $_SESSION['otp_channel']);

    header("Location: dashboard.php");
    exit();
} else {
    header("Location: otp_verification.php?error=wrong_otp");
    exit();
}