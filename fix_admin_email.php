<?php
/**
 * One-time fix: sets the admin's encrypted email and phone in the DB.
 * DELETE THIS FILE after running it!
 */
require_once 'db.php';
require_once 'cryptograph_process.php';

// ── SET THESE VALUES ──────────────────────────────────────────
$adminUsername = '26-0001';             // your admin username
$adminEmail    = 'helios.univv@gmail.com'; // the real email to receive OTPs
$adminPhone    = '09476139634';         // your real phone number (09 format)
// ─────────────────────────────────────────────────────────────

$stmt = $pdo->prepare("
    UPDATE users
       SET email       = :email,
           phonenumber = :phone
     WHERE username    = :username
");
$stmt->execute([
    ':email'    => encryptData($adminEmail),
    ':phone'    => encryptData($adminPhone),
    ':username' => $adminUsername,
]);

echo "Done! Email and phone updated for user: $adminUsername<br>";
echo "Encrypted email saved: " . encryptData($adminEmail) . "<br>";
echo "<strong style='color:red'>DELETE this file now!</strong>";