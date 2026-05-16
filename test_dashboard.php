<?php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once __DIR__ . '/auth_helpers.php';
require_once __DIR__ . '/db.php';

$usernameRaw = $_SESSION['username'] ?? 'not logged in';
$role = $_SESSION['role'] ?? 'no role';

echo "Username: $usernameRaw <br>";
echo "Role: $role <br>";

// Test the faculty query
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT c.*
        FROM classes c
        LEFT JOIN subjects s ON s.class_id = c.id
        WHERE c.owner = ? OR s.faculty = ?
    ");
    $stmt->execute([$usernameRaw, $usernameRaw]);
    $rows = $stmt->fetchAll();
    echo "<pre>Classes found: " . count($rows) . "\n";
    print_r($rows);
    echo "</pre>";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
}
?>