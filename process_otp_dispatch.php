<?php
/**
 * Helios University Academic Hub
 * OTP Dispatch: Routes the OTP to Email or SMS based on user selection
 * Updated: reads/writes from MySQL instead of users.json
 */
session_start();

require 'vendor/autoload.php';
require 'cryptograph_process.php';
require_once 'db.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

/* ── 1. SESSION GUARD ── */
$username = $_SESSION['temp_user'] ?? null;
$role     = $_SESSION['temp_role'] ?? null;

if (!$username || !$role) {
    header("Location: login.php?error=session_expired");
    exit();
}

/* ── 2. LOAD CONFIG FROM DB ── */
try {
    $configStmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
    $configRows = $configStmt->fetchAll();
    $config = [];
    foreach ($configRows as $row) {
        $config[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {
    $config = ['org_name' => 'Helios University'];
}

/* ── 3. GET CHOSEN CHANNEL ── */
$channel = trim($_POST['channel'] ?? '');
if (!in_array($channel, ['email', 'sms'])) {
    header("Location: otp_channel_select.php?error=send_failed");
    exit();
}

/* ── 4. FIND THE USER FROM DB ── */
$stmt = $pdo->prepare("
    SELECT id, username, email, phonenumber
      FROM users
     WHERE username = :username
     LIMIT 1
");
$stmt->execute([':username' => $username]);
$targetUser = $stmt->fetch();

if (!$targetUser) {
    header("Location: login.php?error=not_found");
    exit();
}

/* ── 5. GENERATE OTP & SAVE TO DB ── */
$otp       = (string)random_int(100000, 999999);
$otpExpiry = time() + 300; // 5 minutes

$updateStmt = $pdo->prepare("
    UPDATE users
       SET otp = :otp,
           otp_expiry = :otp_expiry
     WHERE id = :id
");
$updateStmt->execute([
    ':otp'        => $otp,
    ':otp_expiry' => $otpExpiry,
    ':id'         => $targetUser['id'],
]);

$orgName = htmlspecialchars($config['org_name'] ?? 'Helios University');
$time    = date('g:i A');
$date    = date('F j, Y');

/* ── Helper: roll back OTP on failure ── */
function rollbackOtp($pdo, $userId) {
    $pdo->prepare("UPDATE users SET otp = NULL, otp_expiry = NULL WHERE id = :id")
        ->execute([':id' => $userId]);
}

/* ══════════════════════════════════════════════
   CHANNEL: EMAIL
══════════════════════════════════════════════ */
if ($channel === 'email') {

    $smtpReachable = @fsockopen('smtp.gmail.com', 587, $errno, $errstr, 5);
    if (!$smtpReachable) {
        rollbackOtp($pdo, $targetUser['id']);
        header("Location: otp_channel_select.php?error=no_connection");
        exit();
    }
    fclose($smtpReachable);

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'email@gmail.com'; // replace with your Gmail
        $mail->Password   = 'secret_app_password';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom('helios.univv@gmail.com', $config['org_name']);
        $decryptedEmail = decryptData($targetUser['email']);
        $mail->addAddress($decryptedEmail);

        $mail->Subject = "Sign-in Verification Code — $otp";
        $mail->isHTML(true);
        $mail->Body = '
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                @import url("https://fonts.googleapis.com/css2?family=Sora:wght@400;700;800&display=swap");
            </style>
        </head>
        <body style="font-family:\'Sora\', sans-serif; background-color:#f8fafc; padding:60px 20px; margin:0;">
            <div style="max-width:520px; background-color:#ffffff; border-radius:28px; padding:48px; margin:auto; box-shadow:0 20px 40px rgba(15,23,42,0.06); border:1px solid #e2e8f0;">
                <div style="font-size:24px; font-weight:800; color:#1e40af; letter-spacing:-0.04em; margin-bottom:32px;">' . $orgName . '</div>
                <h1 style="font-size:28px; font-weight:800; color:#0f172a; margin:0 0 12px 0; letter-spacing:-0.02em;">Verify your identity</h1>
                <p style="font-size:16px; color:#64748b; line-height:1.6; margin:0;">A sign-in attempt requires verification. Use the code below to continue.</p>
                <div style="background-color:#f1f5f9; border-radius:20px; padding:40px 20px; text-align:center; margin:32px 0; border:1px solid #e2e8f0;">
                    <div style="font-size:11px; font-weight:800; color:#94a3b8; text-transform:uppercase; letter-spacing:0.15em; margin-bottom:12px;">Security Code</div>
                    <div style="font-size:48px; font-weight:800; color:#1e40af; letter-spacing:12px;">' . $otp . '</div>
                </div>
                <div style="border-top:1px solid #f1f5f9; padding-top:24px;">
                    <p style="font-size:13px; color:#94a3b8; margin:0;">
                        Requested on <strong>' . $date . '</strong> at <strong>' . $time . '</strong><br>
                        This code expires in <strong>5 minutes.</strong>
                    </p>
                </div>
            </div>
            <div style="text-align:center; margin-top:32px;">
                <p style="font-size:12px; color:#cbd5e1;">&copy; ' . date('Y') . ' ' . $orgName . ' Academic Hub</p>
            </div>
        </body>
        </html>';
        $mail->AltBody = "Your $orgName verification code is: $otp. It expires in 5 minutes.";

        $mail->send();
        $_SESSION['otp_channel'] = 'email';
        header("Location: otp_verification.php");
        exit();

    } catch (MailException $e) {
        rollbackOtp($pdo, $targetUser['id']);
        header("Location: otp_channel_select.php?error=email_failed");
        exit();
    }
}

/* ══════════════════════════════════════════════
   CHANNEL: SMS (Twilio Verify)
══════════════════════════════════════════════ */
if ($channel === 'sms') {

    $rawPhone = decryptData($targetUser['phonenumber'] ?? '');

    if (preg_match('/^09\d{9}$/', $rawPhone)) {
        $phoneE164 = '+63' . substr($rawPhone, 1);
    } elseif (preg_match('/^639\d{9}$/', $rawPhone)) {
        $phoneE164 = '+' . $rawPhone;
    } elseif (preg_match('/^\+63\d{10}$/', $rawPhone)) {
        $phoneE164 = $rawPhone;
    } else {
        rollbackOtp($pdo, $targetUser['id']);
        header("Location: otp_channel_select.php?error=sms_failed");
        exit();
    }

    // Store phone in session so otp_verification_process.php can verify it
    $_SESSION['otp_phone'] = $phoneE164;

    require_once 'twilio_verify.php';
    $sent = sendOtpViaTwilio($phoneE164);

    if (!$sent) {
        rollbackOtp($pdo, $targetUser['id']);
        header("Location: otp_channel_select.php?error=sms_failed");
        exit();
    }

    // Mark channel as sms so verification knows to use Twilio
    $_SESSION['otp_channel'] = 'sms';

    header("Location: otp_verification.php");
    exit();
}

// Fallback
header("Location: otp_channel_select.php");
exit();