<?php
// ============================================================
//  process_change_password.php — Password Change Handler
//
//  What this file does:
//    - Lets a logged-in user change their password
//    - Verifies their current password before allowing a change
//    - Enforces a minimum password strength
//    - Blocks reusing the same password
//
//  What this file NEVER does:
//    - Touch the username column (ID is permanent)
//    - Touch any other profile field
//    - Allow unauthenticated access
// ============================================================

session_start();
require_once 'db.php';

// ── Auth guard ───────────────────────────────────────────────
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

$username    = $_SESSION['username'];   // the YY-XXXX ID, read-only here
$currentPass = trim($_POST['current_password'] ?? '');
$newPass     = trim($_POST['new_password']     ?? '');
$confirmPass = trim($_POST['confirm_password'] ?? '');

// Redirect destination varies by role
$redirectBase = match ($_SESSION['role']) {
    'admin'   => 'role_admin_systemSettings.php',
    'faculty' => 'role_faculty_profile.php',
    default   => 'role_student_profile.php',
};

// ── Validation ───────────────────────────────────────────────
if ($currentPass === '' || $newPass === '' || $confirmPass === '') {
    header("Location: {$redirectBase}?error=empty_fields");
    exit();
}

if ($newPass !== $confirmPass) {
    header("Location: {$redirectBase}?error=password_mismatch");
    exit();
}

// Min 8 chars, must contain at least 1 letter and 1 number
if (
    strlen($newPass) < 8 ||
    !preg_match('/[A-Za-z]/', $newPass) ||
    !preg_match('/[0-9]/', $newPass)
) {
    header("Location: {$redirectBase}?error=weak_password");
    exit();
}

// ── Fetch current hashed password ───────────────────────────
// Only the password column is selected — username is never fetched
// for modification here.
$stmt = $pdo->prepare("
    SELECT password FROM users
     WHERE username = :username
       AND status   = 'active'
     LIMIT 1
");
$stmt->execute([':username' => $username]);
$row = $stmt->fetch();

if (!$row) {
    header("Location: {$redirectBase}?error=not_found");
    exit();
}

// ── Verify current password ──────────────────────────────────
if (!password_verify($currentPass, $row['password'])) {
    header("Location: {$redirectBase}?error=wrong_password");
    exit();
}

// Prevent reusing the exact same password
if (password_verify($newPass, $row['password'])) {
    header("Location: {$redirectBase}?error=same_password");
    exit();
}

// ── Update password — username intentionally excluded ────────
// Only SET password. The username (YY-XXXX ID) column is not
// mentioned anywhere in this UPDATE statement.
$newHash = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]);

$stmt = $pdo->prepare("
    UPDATE users
       SET password = :password
     WHERE username = :username
       AND status   = 'active'
");
$stmt->execute([
    ':password' => $newHash,
    ':username' => $username,
]);

if ($stmt->rowCount() === 0) {
    header("Location: {$redirectBase}?error=server");
    exit();
}

header("Location: {$redirectBase}?success=password_changed");
exit();