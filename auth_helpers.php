<?php

require_once __DIR__ . '/cryptograph_process.php';

const USERS_FILE = __DIR__ . '/users.json';
const AUTHORIZED_PEOPLE_FILE = __DIR__ . '/authorized_people.json';
const TEMP_PASSWORD_TTL_SECONDS = 259200; // 3 days

function loadJsonFile(string $file, array $fallback): array
{
    if (!file_exists($file)) {
        return $fallback;
    }

    $decoded = json_decode(file_get_contents($file), true);
    return is_array($decoded) ? $decoded : $fallback;
}

function saveJsonFile(string $file, array $data): bool
{
    return file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false;
}

function normalizeComparable(?string $value): string
{
    return strtolower(trim((string)$value));
}

function normalizePhone(?string $phone): string
{
    $digits = preg_replace('/\D/', '', (string)$phone);
    if (str_starts_with($digits, '63') && strlen($digits) === 12) {
        $digits = substr($digits, 2);
    }
    if (str_starts_with($digits, '0') && strlen($digits) === 11) {
        $digits = substr($digits, 1);
    }
    return $digits;
}

function decryptIfPossible(?string $value): string
{
    if ($value === null || $value === '') {
        return '';
    }
    $decrypted = decryptData($value);
    return $decrypted !== false && $decrypted !== '' ? $decrypted : $value;
}

function authorizedPersonMatches(array $person, string $firstName, string $lastName, string $email, string $phone): bool
{
    return normalizeComparable($person['firstname'] ?? '') === normalizeComparable($firstName)
        && normalizeComparable($person['lastname'] ?? '') === normalizeComparable($lastName)
        && normalizeComparable($person['email'] ?? '') === normalizeComparable($email)
        && normalizePhone($person['phonenumber'] ?? '') === normalizePhone($phone);
}

function generateUniqueId(array $users): string
{
    $year = date('Y');
    $max = -1;

    foreach ($users as $user) {
        $candidate = $user['unique_id'] ?? $user['username'] ?? '';
        if (preg_match('/^' . preg_quote($year, '/') . '-(\d{4})$/', $candidate, $matches)) {
            $max = max($max, (int)$matches[1]);
        }
    }

    return sprintf('%s-%04d', $year, $max + 1);
}

function generateTemporaryPin(): string
{
    return (string)random_int(100000, 999999);
}

function passwordPolicyMessage(): string
{
    return 'Password must be at least 8 characters and include uppercase, lowercase, number, and special character.';
}

function isSecurePassword(string $password): bool
{
    return strlen($password) >= 8
        && preg_match('/[A-Z]/', $password)
        && preg_match('/[a-z]/', $password)
        && preg_match('/\d/', $password)
        && preg_match('/[^A-Za-z0-9]/', $password);
}

function findUserIndexByUsername(array $users, string $username): ?int
{
    foreach ($users as $index => $user) {
        if (($user['username'] ?? '') === $username) {
            return $index;
        }
    }
    return null;
}

function disableExpiredTemporaryPasswordAccount(string $username): bool
{
    $data = loadJsonFile(USERS_FILE, ['user' => []]);
    $index = findUserIndexByUsername($data['user'] ?? [], $username);
    if ($index === null) {
        return false;
    }

    $user = $data['user'][$index];
    if (($user['role'] ?? '') === 'admin' || empty($user['must_change_password'])) {
        return false;
    }

    $expiresAt = (int)($user['temp_password_expires_at'] ?? 0);
    if ($expiresAt <= 0 || time() <= $expiresAt) {
        return false;
    }

    $data['user'][$index]['status'] = 'disabled';
    $data['user'][$index]['disabled_at'] = date('Y-m-d H:i:s');
    $data['user'][$index]['disabled_reason'] = 'temporary_password_expired';
    saveJsonFile(USERS_FILE, $data);
    return true;
}

function enforceTemporaryPasswordDeadline(): void
{
    if (empty($_SESSION['username']) || ($_SESSION['role'] ?? '') === 'admin') {
        return;
    }

    enforceSystemMaintenanceGate();

    if (disableExpiredTemporaryPasswordAccount($_SESSION['username'])) {
        session_unset();
        session_destroy();
        header('Location: disabled_account.php?reason=password_expired');
        exit();
    }
}

function loadSystemSettings(array $defaults = []): array
{
    $config = array_merge([
        'org_name' => 'Helios University',
        'maintenance' => '0',
        'm_duration' => '60',
        'm_work' => 'General updates and system improvements',
        'm_started_at' => (string)time(),
    ], $defaults);

    try {
        global $pdo;
        if (!isset($pdo) || !$pdo instanceof PDO) {
            require_once __DIR__ . '/db.php';
            $GLOBALS['pdo'] = $pdo;
        }
        $configStmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
        foreach ($configStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $config[$row['setting_key']] = $row['setting_value'];
        }
    } catch (Throwable $e) {
        error_log('loadSystemSettings failed: ' . $e->getMessage());
    }

    return $config;
}

function isSettingEnabled($value): bool
{
    return in_array($value, [true, 1, '1', 'true', 'on', 'yes'], true);
}

function renderSystemMaintenancePage(array $config): void
{
    $__org = $config['org_name'] ?? 'Helios University';
    $__work = $config['m_work'] ?? 'General updates and system improvements';
    $__dur = (int)($config['m_duration'] ?? 60);
    $__startedAt = (int)($config['m_started_at'] ?? time());
    require __DIR__ . '/maintenance_page.php';
}

function enforceSystemMaintenanceGate(): void
{
    if (($_SESSION['role'] ?? '') === 'admin') {
        return;
    }

    $config = loadSystemSettings();
    if (isSettingEnabled($config['maintenance'] ?? '0')) {
        renderSystemMaintenancePage($config);
        exit();
    }
}

function sendCredentialsEmail(string $recipientEmail, string $recipientName, string $uniqueId, string $temporaryPassword): bool
{
    require_once __DIR__ . '/vendor/autoload.php';

    // ── SMTP credentials ───────────────────────────────────────────────────
    $smtpUser = 'mamamo';      // <- replace with your Gmail
    $smtpPass = 'secret';          // <- replace with your 16-char App Password
    $orgName  = 'Helios University';

    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);

        // SMTP configuration
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $smtpUser;
        $mail->Password   = $smtpPass;
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        // Email content
        $mail->setFrom($smtpUser, $orgName);
        $mail->addAddress($recipientEmail, $recipientName);
        $mail->Subject = 'Your Helios University Account Credentials';
        $mail->isHTML(true);
        $mail->Body = '<p>Hello ' . htmlspecialchars($recipientName) . ',</p>'
            . '<p>Your account activation request has been approved.</p>'
            . '<p><strong>Unique ID / Username:</strong> ' . htmlspecialchars($uniqueId) . '<br>'
            . '<strong>Temporary Password:</strong> ' . htmlspecialchars($temporaryPassword) . '</p>'
            . '<p>This temporary password expires in <strong>3 days</strong>. '
            . 'Please sign in and change it immediately in Account Settings.</p>'
            . '<p>— ' . $orgName . '</p>';
        $mail->AltBody = "Hello {$recipientName},\n\n"
            . "Your account activation request has been approved.\n\n"
            . "Unique ID / Username: {$uniqueId}\n"
            . "Temporary Password: {$temporaryPassword}\n\n"
            . "This temporary password expires in 3 days.\n"
            . "Please sign in and change it immediately in Account Settings, otherwise your account may be deactivated for security reasons.\n\n"
            . "— {$orgName}";

        return $mail->send();

    } catch (Throwable $e) {
        error_log('sendCredentialsEmail failed: ' . $e->getMessage());
        return false;
    }
}
