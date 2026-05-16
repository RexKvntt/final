<?php


session_start();

require_once 'db.php';
require_once 'auth_helpers.php';

// PHPMailer — install via: composer require phpmailer/phpmailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require_once __DIR__ . '/vendor/autoload.php';

// ── Auth guard: admin only ───────────────────────────────────
if (!isset($_SESSION['username']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    header("Location: dashboard.php?error=unauthorized");
    exit();
}

$requestId = trim($_POST['request_id'] ?? '');

if (empty($requestId)) {
    header("Location: role_admin_manageUsers.php?error=missing_id");
    exit();
}

// ── Fetch pending user ───────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT u.*
      FROM users u
     WHERE u.request_id = :request_id
       AND u.status = 'pending'
     LIMIT 1
");
$stmt->execute([':request_id' => $requestId]);
$user = $stmt->fetch();

if (!$user) {
    header("Location: role_admin_manageUsers.php?error=not_found");
    exit();
}

// Decrypt stored email for sending
$userEmail = decryptIfPossible($user['email']);


$yearPrefix = date('y');   // '26', '27', etc. — auto-advances each year

do {
    $randomDigits = random_int(1000, 9999);
    $newUsername  = $yearPrefix . '-' . $randomDigits;   // e.g. '26-4821'
    $chk = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
    $chk->execute([$newUsername]);
} while ($chk->fetch());

// ── Generate temporary password (12 chars, no ambiguous chars) ──
$chars    = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
$tempPass = '';
for ($i = 0; $i < 12; $i++) {
    $tempPass .= $chars[random_int(0, strlen($chars) - 1)];
}
$hashedPass = password_hash($tempPass, PASSWORD_BCRYPT, ['cost' => 12]);

// ── Activate user — single atomic transaction ────────────────
try {
    $pdo->beginTransaction();

    // Set the permanent YY-XXXX username, hashed password, active status.
    // The DB trigger (trg_block_username_change) will lock username
    // after this update completes, so it can never be changed again.
    $stmt = $pdo->prepare("
        UPDATE users
           SET username           = :username,
               password           = :password,
               status             = 'active',
               activation_request = 0,
               activated_at       = NOW()
         WHERE request_id = :request_id
           AND status     = 'pending'
    ");
    $stmt->execute([
        ':username'   => $newUsername,
        ':password'   => $hashedPass,
        ':request_id' => $requestId,
    ]);

    if ($stmt->rowCount() === 0) {
        $pdo->rollBack();
        header("Location: role_admin_manageUsers.php?error=already_approved");
        exit();
    }

    // Mark the authorized_people row as activated
    $stmt = $pdo->prepare("
        UPDATE authorized_people
           SET status = 'activated'
         WHERE id = :ap_id
    ");
    $stmt->execute([':ap_id' => $user['authorized_person_id']]);

    // Log approval notification for admin panel
    $notifId = 'N' . strtoupper(bin2hex(random_bytes(4)));
    $stmt = $pdo->prepare("
        INSERT INTO notifications (id, type, message, time, is_read)
        VALUES (:id, 'approval', :message, NOW(), 0)
    ");
    $stmt->execute([
        ':id'      => $notifId,
        ':message' => $user['fullname'] . ' approved — ID ' . $newUsername . ' issued.',
    ]);

    $pdo->commit();

} catch (Exception $e) {
    $pdo->rollBack();
    error_log('[Helios] Approval transaction failed: ' . $e->getMessage());
    header("Location: role_admin_manageUsers.php?error=server");
    exit();
}

// ── Send credentials email via PHPMailer ─────────────────────
$mail = new PHPMailer(true);

try {
    // ── SMTP config — fill these in ──────────────────────────
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';           // or your SMTP host
    $mail->SMTPAuth   = true;
    $mail->Username   = 'secret';     // sender email
    $mail->Password   = 'secret';        // Gmail App Password
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;

    $mail->setFrom('your_email@gmail.com', 'Helios University Academic Hub');
    $mail->addAddress($userEmail, $user['fullname']);
    $mail->isHTML(true);
    $mail->Subject = 'Your Helios Account is Approved — ID: ' . $newUsername;

    // ── HTML email body ──────────────────────────────────────
    $mail->Body = "
    <div style='font-family:Arial,sans-serif;max-width:520px;margin:auto;padding:32px;border:1px solid #e0e0e0;border-radius:8px;'>

        <h2 style='color:#3C3489;margin-top:0;'>Welcome to Helios Academic Hub</h2>

        <p>Hi <strong>{$user['fullname']}</strong>,</p>
        <p>Your account has been approved by the administrator. Here are your login credentials:</p>

        <div style='background:#f0effe;border-left:4px solid #3C3489;border-radius:4px;padding:16px 20px;margin:20px 0;'>
            <p style='margin:0 0 4px;font-size:12px;color:#666;text-transform:uppercase;letter-spacing:1px;'>Your Permanent ID</p>
            <p style='margin:0;font-size:28px;font-weight:bold;color:#3C3489;letter-spacing:4px;'>{$newUsername}</p>
            <p style='margin:8px 0 0;font-size:12px;color:#888;'>This is your username for logging in. It cannot be changed.</p>
        </div>

        <div style='background:#f5f5f5;border-radius:4px;padding:16px 20px;margin:20px 0;'>
            <p style='margin:0 0 4px;font-size:12px;color:#666;text-transform:uppercase;letter-spacing:1px;'>Temporary Password</p>
            <p style='margin:0;font-size:20px;font-family:monospace;color:#333;letter-spacing:2px;'>{$tempPass}</p>
        </div>

        <p style='color:#c0392b;font-size:14px;'>
            <strong>⚠ Important:</strong> Please log in and change your password immediately. Otherwise, your account may be deactivated for security reasons.
            Your ID (<strong>{$newUsername}</strong>) is permanent and cannot be changed.
        </p>

        <a href='https://yourdomain.com/login.php'
           style='display:inline-block;margin-top:8px;padding:12px 28px;background:#3C3489;color:#fff;border-radius:6px;text-decoration:none;font-weight:bold;font-size:15px;'>
            Log In Now →
        </a>

        <hr style='margin:28px 0;border:none;border-top:1px solid #eee;'>
        <p style='color:#aaa;font-size:11px;margin:0;'>
            Helios University Academic Hub &nbsp;|&nbsp;
            If you did not request this account, please ignore this email.
        </p>
    </div>
    ";

    // ── Plain text fallback ──────────────────────────────────
    $mail->AltBody =
        "Welcome to Helios Academic Hub\n\n"
      . "Hi {$user['fullname']},\n\n"
      . "Your account has been approved.\n\n"
      . "Your Permanent ID : {$newUsername}\n"
      . "Temporary Password: {$tempPass}\n\n"
      . "IMPORTANT: Log in and change your password immediately.\n"
      . "Your ID is permanent and cannot be changed.\n\n"
      . "Login: https://yourdomain.com/login.php\n\n"
      . "Helios University Academic Hub";

    $mail->send();

} catch (Exception $e) {
    // Account IS activated. Email failed — log it so admin can resend manually.
    error_log('[Helios] Credential email failed for ' . $newUsername . ': ' . $mail->ErrorInfo);
    header("Location: role_admin_manageUsers.php?approved=1&email_failed=1&user=" . urlencode($user['fullname']));
    exit();
}

header("Location: role_admin_manageUsers.php?approved=1&user=" . urlencode($user['fullname']));
exit();