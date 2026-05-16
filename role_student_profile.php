<?php
session_start();
require 'auth_helpers.php';
enforceTemporaryPasswordDeadline();

if (!isset($_SESSION['username']) || ($_SESSION['role'] ?? '') !== 'student') {
    header('Location: login.php');
    exit();
}

$username = $_SESSION['username'];
require_once __DIR__ . '/db.php'; // provides $pdo

$message = null;
$msgType = 'success';

// ── Load current user from DB ──
$stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
$stmt->execute([$username]);
$currentUser = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$currentUser) {
    header('Location: logout.php');
    exit();
}

// ── Save profile (phone number) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_profile'])) {
    $newPhone = trim($_POST['phonenumber'] ?? '');
    if ($newPhone !== '') {
        $normalizedPhone = normalizePhone($newPhone);
        $encryptedPhone  = encryptData(strlen($normalizedPhone) === 10 ? '0' . $normalizedPhone : $newPhone);
        $pdo->prepare("UPDATE users SET phonenumber = ? WHERE username = ?")
            ->execute([$encryptedPhone, $username]);
    }
    $message = 'Profile updated successfully.';
    // Reload user
    $stmt->execute([$username]);
    $currentUser = $stmt->fetch(PDO::FETCH_ASSOC);
}

// ── Change password ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $currentPassword  = $_POST['current_password'] ?? '';
    $newPassword      = $_POST['new_password'] ?? '';
    $confirmPassword  = $_POST['confirm_password'] ?? '';

    if (!password_verify($currentPassword, $currentUser['password'] ?? '')) {
        $message = 'Current password is incorrect.';
        $msgType = 'error';
    } elseif ($newPassword !== $confirmPassword) {
        $message = 'New passwords do not match.';
        $msgType = 'error';
    } elseif (!isSecurePassword($newPassword)) {
        $message = passwordPolicyMessage();
        $msgType = 'error';
    } else {
        $pdo->prepare(
            "UPDATE users SET
                password               = ?,
                must_change_password   = 0,
                password_changed_at    = NOW(),
                temp_password_expires_at = NULL
             WHERE username = ?"
        )->execute([password_hash($newPassword, PASSWORD_DEFAULT), $username]);
        $message = 'Password changed successfully.';
        $msgType = 'success';
        // Reload user
        $stmt->execute([$username]);
        $currentUser = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

// ── Enrolled classes from DB ──
$enrolledClasses = $pdo->prepare(
    "SELECT c.* FROM classes c
     INNER JOIN class_members cm ON cm.class_id = c.id
     WHERE cm.username = ?"
);
$enrolledClasses->execute([$username]);
$enrolledClassList = $enrolledClasses->fetchAll(PDO::FETCH_ASSOC);

$subjectsCount = 0;
foreach ($enrolledClassList as $cls) {
    $sub = $pdo->prepare("SELECT COUNT(*) FROM subjects WHERE class_id = ?");
    $sub->execute([$cls['id']]);
    $subjectsCount += (int)$sub->fetchColumn();
}

$displayName        = $currentUser['display_name'] ?? $currentUser['fullname'] ?? $username;
$fullname           = $currentUser['fullname'] ?? '';
$email              = decryptData($currentUser['email'] ?? '');
$phone              = decryptData($currentUser['phonenumber'] ?? '');
$registeredAt       = $currentUser['registered_at'] ?? '-';
$initials           = strtoupper(substr($displayName, 0, 2));
$mustChangePassword = !empty($currentUser['must_change_password']);
$passwordDeadline   = !empty($currentUser['temp_password_expires_at'])
                      ? date('M j, Y g:i A', (int)$currentUser['temp_password_expires_at'])
                      : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - Helios University</title>
    <link rel="stylesheet" href="style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script>
        (function(){
            var t = localStorage.getItem('theme') || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
            document.documentElement.setAttribute('data-theme', t);
        })();
    </script>
    <style>
        .page-content { max-width: 860px; padding: 40px 28px; }
        .profile-hero {
            background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
            border-radius: var(--radius-xl);
            padding: 40px;
            display: flex;
            align-items: center;
            gap: 28px;
            margin-bottom: 32px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 8px 32px rgba(30,64,175,0.28);
        }
        .profile-hero::after {
            content: '';
            position: absolute;
            width: 320px; height: 320px;
            border-radius: 50%;
            background: rgba(255,255,255,0.05);
            top: -120px; right: -80px;
        }
        .profile-avatar-lg {
            width: 80px; height: 80px;
            border-radius: 20px;
            background: rgba(255,255,255,0.15);
            border: 2px solid rgba(255,255,255,0.25);
            display: flex; align-items: center; justify-content: center;
            font-size: 28px; font-weight: 800;
            color: #fff;
            flex-shrink: 0;
            font-family: 'Sora', sans-serif;
            position: relative; z-index: 1;
        }
        .profile-hero-text { position: relative; z-index: 1; }
        .profile-hero-name {
            font-family: 'Sora', sans-serif;
            font-size: 28px; font-weight: 800;
            color: #fff; letter-spacing: -0.03em;
            margin-bottom: 4px;
        }
        .profile-hero-role {
            display: inline-flex; align-items: center; gap: 6px;
            background: rgba(255,255,255,0.15);
            border-radius: 999px;
            padding: 4px 12px;
            font-size: 12px; font-weight: 700;
            color: rgba(255,255,255,0.85);
        }
        .profile-section {
            background: var(--surface);
            border: 1.5px solid var(--border);
            border-radius: var(--radius-xl);
            padding: 32px;
            margin-bottom: 24px;
            box-shadow: var(--shadow-sm);
        }
        .profile-section-title {
            font-family: 'Sora', sans-serif;
            font-size: 16px; font-weight: 800;
            letter-spacing: -0.02em;
            color: var(--text-primary);
            margin-bottom: 24px;
        }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        @media (max-width: 580px) { .form-grid { grid-template-columns: 1fr; } }
        .form-field label {
            display: block;
            font-size: 11px; font-weight: 800;
            text-transform: uppercase; letter-spacing: 0.08em;
            color: var(--text-muted);
            margin-bottom: 8px;
        }
        .form-field input {
            width: 100%;
            padding: 12px 16px;
            background: var(--surface-2);
            border: 1.5px solid var(--border);
            border-radius: var(--radius-sm);
            font-family: 'Sora', sans-serif;
            font-size: 14px; font-weight: 600;
            color: var(--text-primary);
            outline: none;
            transition: border-color 0.18s;
            box-sizing: border-box;
        }
        .form-field input:focus { border-color: var(--border-focus); }
        .form-field input:disabled { color: var(--text-muted); cursor: not-allowed; }
        .field-hint { font-size: 11px; color: var(--text-muted); margin-top: 6px; }
        .save-btn {
            margin-top: 24px;
            display: inline-flex; align-items: center; gap: 8px;
            padding: 13px 28px;
            background: var(--accent);
            color: #fff;
            border: none;
            border-radius: var(--radius-sm);
            font-family: 'Sora', sans-serif;
            font-size: 14px; font-weight: 700;
            cursor: pointer;
            transition: background 0.18s;
        }
        .save-btn:hover { background: var(--accent-hover); }
        .stats-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
        .stat-card {
            background: var(--surface-2);
            border: 1.5px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 22px;
        }
        .stat-value { font-size: 32px; font-weight: 800; color: var(--text-primary); font-family: 'Sora', sans-serif; line-height: 1; }
        .stat-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: var(--text-muted); margin-top: 6px; }
    </style>
</head>
<body>
<div class="page-wrapper">
    <header class="page-header">
        <a href="dashboard.php" class="page-header-back">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
            Dashboard
        </a>
        <span class="page-header-title">My Profile</span>
        <div class="header-actions">
            <button class="theme-toggle" aria-label="Toggle theme">
                <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
                <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
            </button>
            <a href="logout.php" class="logout-btn">
                <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                Logout
            </a>
        </div>
    </header>

    <main class="page-content">
        <section class="profile-hero">
            <div class="profile-avatar-lg"><?= htmlspecialchars($initials) ?></div>
            <div class="profile-hero-text">
                <div class="profile-hero-name"><?= htmlspecialchars($displayName) ?></div>
                <div class="profile-hero-role">Student Account</div>
            </div>
        </section>

        <?php if ($message): ?>
        <div class="alert <?= $msgType === 'error' ? 'alert-error' : 'alert-success' ?>"><?= $message ?></div>
        <?php endif; ?>

        <section class="profile-section">
            <h2 class="profile-section-title">Personal Details</h2>
            <form method="POST">
                <div class="form-grid">
                    <div class="form-field">
                        <label>Full Name</label>
                        <input type="text" value="<?= htmlspecialchars($fullname) ?>" disabled>
                    </div>
                    <div class="form-field">
                        <label>Unique ID</label>
                        <input type="text" value="<?= htmlspecialchars($username) ?>" disabled>
                    </div>
                    <div class="form-field">
                        <label>Email</label>
                        <input type="text" value="<?= htmlspecialchars($email) ?>" disabled>
                    </div>
                    <div class="form-field">
                        <label>Phone Number</label>
                        <input type="text" name="phonenumber" value="<?= htmlspecialchars($phone) ?>" placeholder="+63...">
                        <div class="field-hint">Update your contact number if needed.</div>
                    </div>
                    <div class="form-field">
                        <label>Registered At</label>
                        <input type="text" value="<?= htmlspecialchars($registeredAt) ?>" disabled>
                    </div>
                </div>
                <button type="submit" name="save_profile" class="save-btn">Save Changes</button>
            </form>
        </section>

        <section class="profile-section">
            <h2 class="profile-section-title">Password Security</h2>
            <?php if ($mustChangePassword && $passwordDeadline): ?>
                <div class="alert alert-error">Temporary password must be changed by <?= htmlspecialchars($passwordDeadline) ?>, otherwise your account may be deactivated.</div>
            <?php endif; ?>
            <form method="POST">
                <div class="form-grid">
                    <div class="form-field">
                        <label>Current Password</label>
                        <input type="password" name="current_password" required>
                    </div>
                    <div class="form-field">
                        <label>New Password</label>
                        <input type="password" name="new_password" required>
                        <div class="field-hint"><?= htmlspecialchars(passwordPolicyMessage()) ?></div>
                    </div>
                    <div class="form-field">
                        <label>Confirm New Password</label>
                        <input type="password" name="confirm_password" required>
                    </div>
                </div>
                <button type="submit" name="change_password" class="save-btn">Change Password</button>
            </form>
        </section>

        <section class="profile-section">
            <h2 class="profile-section-title">Enrollment Overview</h2>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-value"><?= count($enrolledClassList) ?></div>
                    <div class="stat-label">Classes</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= $subjectsCount ?></div>
                    <div class="stat-label">Subjects</div>
                </div>
            </div>
        </section>
    </main>
</div>

<script>
document.querySelector('.theme-toggle')?.addEventListener('click', function () {
    const nextTheme = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', nextTheme);
    localStorage.setItem('theme', nextTheme);
});
</script>
</body>
</html>