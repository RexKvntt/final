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
        $mail->Username   = 'itsasecret';
        $mail->Password   = 'mamamosecret';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom('helios.univv@gmail.com', $config['org_name']);
        $decryptedEmail = decryptData($targetUser['email']);
        $mail->addAddress($decryptedEmail);

        $mail->Subject = "Sign-in Verification Code - $otp";
        $mail->isHTML(true);
    $mail->Body = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>OreXis OTP Code</title>
</head>
<body style="margin:0;padding:0;background-color:#07111f;font-family:Arial,sans-serif;color:#f5f8ff;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:linear-gradient(145deg,#07111f 0%,#12233b 100%);padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:620px;background:linear-gradient(160deg,rgba(38,48,72,0.96),rgba(18,34,57,0.98));border:1px solid #2d4668;border-radius:28px;overflow:hidden;">
                    <tr>
                        <td style="padding:32px 36px;background:linear-gradient(135deg,#0d1c33 0%,#163356 100%);border-bottom:1px solid #2d4668;text-align:center;">
                            <div style="font-size:12px;letter-spacing:0.32em;text-transform:uppercase;color:#8ecfff;margin-bottom:10px;">Security Verification</div>
                            <div style="font-size:32px;font-weight:700;line-height:1.1;color:#ffffff;">Hel<span style="color:#53d2ff;">ios</span></div>
                            <div style="margin-top:10px;font-size:15px;line-height:1.7;color:#c7d5ef;">Use the one-time password below to finish signing in to your account.</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px 36px;text-align:center;">
                            <div style="font-size:14px;line-height:1.7;color:#c7d5ef;margin-bottom:20px;text-align:center;">Your verification code is valid for 5 minutes.</div>
                            <div style="display:inline-block;padding:0;color:#ffffff;font-size:32px;font-weight:700;letter-spacing:0.28em;text-align:center;">
                                {$otp}
                            </div>
                            <div style="margin-top:24px;font-size:13px;line-height:1.7;color:#9fb2cf;">
                                If you did not try to sign in, you can safely ignore this email.
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;
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