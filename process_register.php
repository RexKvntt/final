<?php
// ============================================================
//  process_register.php — Registration Handler (MySQL)
//
//  Flow:
//    1. Collect + validate form fields
//    2. Match against authorized_people (admin must have added
//       this person first before they can register)
//    3. Check for duplicate pending/active users
//    4. Generate a unique REQ-XXXXXXXX request ID
//    5. Insert user row with status = 'pending'
//       username is temporarily set to the request_id here;
//       it gets replaced with the permanent YY-XXXX ID on approval.
//    6. Create admin notification
//    7. Redirect to register_success.php
// ============================================================

require_once 'db.php';
require_once 'auth_helpers.php';

// ── Collect form input ───────────────────────────────────────
$firstname     = trim($_POST['firstname']    ?? '');
$lastname      = trim($_POST['lastname']     ?? '');
$fullname      = trim($firstname . ' ' . $lastname);
$phonenumber   = trim($_POST['phonenumber']  ?? '');
$countryCode   = trim($_POST['country_code'] ?? '+63');
$email         = trim($_POST['email']        ?? '');
$requestedRole = trim($_POST['role']         ?? 'student');

$params = http_build_query([
    'firstname'   => $firstname,
    'lastname'    => $lastname,
    'phonenumber' => $phonenumber,
    'email'       => $email,
    'role'        => $requestedRole,
]);

// ── Basic validation ─────────────────────────────────────────
if ($firstname === '' || $lastname === '' || $phonenumber === '' || $email === '' || $requestedRole === '') {
    header("Location: register.php?error=empty_fields&$params");
    exit();
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header("Location: register.php?error=invalid_email&$params");
    exit();
}

if (!in_array($requestedRole, ['student', 'faculty'], true)) {
    header("Location: register.php?error=invalid_role&$params");
    exit();
}

$digitsOnly = normalizePhone($phonenumber);
if ($countryCode !== '+63' || !preg_match('/^9\d{9}$/', $digitsOnly)) {
    header("Location: register.php?error=invalid_phone&$params");
    exit();
}

// ── Check authorized_people ──────────────────────────────────
// Admin must have pre-registered this person before they can sign up.
$stmt = $pdo->prepare("
    SELECT * FROM authorized_people
     WHERE LOWER(firstname) = LOWER(:firstname)
       AND LOWER(lastname)  = LOWER(:lastname)
       AND LOWER(email)     = LOWER(:email)
       AND phonenumber      = :phone
     LIMIT 1
");
$stmt->execute([
    ':firstname' => $firstname,
    ':lastname'  => $lastname,
    ':email'     => $email,
    ':phone'     => $digitsOnly,
]);
$authorizedPerson = $stmt->fetch();

if (!$authorizedPerson) {
    header("Location: register.php?error=not_authorized&$params");
    exit();
}

if (!empty($authorizedPerson['role']) && $authorizedPerson['role'] !== $requestedRole) {
    header("Location: register.php?error=invalid_role&$params");
    exit();
}

if ($authorizedPerson['status'] === 'activated') {
    header("Location: register.php?error=already_activated&$params");
    exit();
}

// ── Check for duplicate pending/active/disabled user ─────────
$stmt = $pdo->prepare("
    SELECT id FROM users
     WHERE LOWER(fullname) = LOWER(:fullname)
       AND email           = :email
       AND phonenumber     = :phone
       AND status IN ('pending', 'active', 'disabled')
     LIMIT 1
");
$stmt->execute([
    ':fullname' => $fullname,
    ':email'    => encryptData($email),
    ':phone'    => encryptData('0' . $digitsOnly),
]);

if ($stmt->fetch()) {
    header("Location: register.php?error=request_exists&$params");
    exit();
}

// ── Generate unique request ID (REQ-XXXXXXXX) ────────────────
do {
    $requestId = 'REQ-' . strtoupper(bin2hex(random_bytes(4)));
    $chk = $pdo->prepare("SELECT id FROM users WHERE request_id = ? LIMIT 1");
    $chk->execute([$requestId]);
} while ($chk->fetch());

// ── Insert pending user ──────────────────────────────────────
// username is temporarily the request_id.
// It will be replaced with the permanent YY-XXXX ID in process_approve.php.
$stmt = $pdo->prepare("
    INSERT INTO users (
        request_id, authorized_person_id,
        firstname, lastname, fullname,
        phonenumber, username, email,
        password, role, status,
        activation_request, registered_at
    ) VALUES (
        :request_id, :ap_id,
        :firstname, :lastname, :fullname,
        :phonenumber, :username, :email,
        NULL, :role, 'pending',
        1, NOW()
    )
");
$stmt->execute([
    ':request_id'  => $requestId,
    ':ap_id'       => $authorizedPerson['id'],
    ':firstname'   => $firstname,
    ':lastname'    => $lastname,
    ':fullname'    => $fullname,
    ':phonenumber' => encryptData('0' . $digitsOnly),
    ':username'    => $requestId,         // temporary placeholder
    ':email'       => encryptData($email),
    ':role'        => $requestedRole,
]);

// ── Notify admin ─────────────────────────────────────────────
$notifId = 'N' . strtoupper(bin2hex(random_bytes(4)));
$stmt = $pdo->prepare("
    INSERT INTO notifications (id, type, message, time, is_read)
    VALUES (:id, 'register', :message, NOW(), 0)
");
$stmt->execute([
    ':id'      => $notifId,
    ':message' => $fullname . ' submitted an account activation request.',
]);

header("Location: register_success.php");
exit();