<?php
// ============================================================
//  db.php — PDO Database Connection
//  Include this file in every PHP file that needs the database.
//  Usage: require_once 'db.php';  then use $pdo
// ============================================================

define('DB_HOST', 'localhost');
define('DB_NAME', 'helios_db');
define('DB_USER', 'root');        // change to your MySQL username
define('DB_PASS', '');            // change to your MySQL password
define('DB_CHARSET', 'utf8mb4');

$dsn = 'mysql:host=' . DB_HOST
     . ';dbname=' . DB_NAME
     . ';charset=' . DB_CHARSET;

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (PDOException $e) {
    // In production, log this — don't expose it to users
    error_log('DB connection failed: ' . $e->getMessage());
    http_response_code(500);
    exit('Database connection error. Please try again later.');
}