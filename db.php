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

function heliosColumnExists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
          FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function heliosIndexExists(PDO $pdo, string $table, string $index): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
          FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND INDEX_NAME = ?
    ");
    $stmt->execute([$table, $index]);
    return (int)$stmt->fetchColumn() > 0;
}

function heliosConstraintExists(PDO $pdo, string $table, string $constraint): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
          FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND CONSTRAINT_NAME = ?
    ");
    $stmt->execute([$table, $constraint]);
    return (int)$stmt->fetchColumn() > 0;
}

function heliosEnsureAcademicSchema(PDO $pdo): void {
    if (!heliosColumnExists($pdo, 'posts', 'subject')) {
        $pdo->exec("ALTER TABLE posts ADD COLUMN subject varchar(20) DEFAULT NULL AFTER posted_by");
    }
    if (!heliosIndexExists($pdo, 'posts', 'idx_posts_subject')) {
        $pdo->exec("ALTER TABLE posts ADD KEY idx_posts_subject (subject)");
    }
    if (heliosColumnExists($pdo, 'posts', 'faculty')) {
        if (heliosConstraintExists($pdo, 'posts', 'posts_ibfk_4')) {
            $pdo->exec("ALTER TABLE posts DROP FOREIGN KEY posts_ibfk_4");
        }
        if (heliosIndexExists($pdo, 'posts', 'idx_posts_faculty')) {
            $pdo->exec("ALTER TABLE posts DROP INDEX idx_posts_faculty");
        }
        $pdo->exec("ALTER TABLE posts DROP COLUMN faculty");
    }

    $pdo->exec(
        "UPDATE posts p
         JOIN (
            SELECT class_id, faculty, MIN(id) AS subject_id, COUNT(*) AS subject_count
              FROM subjects
             WHERE faculty IS NOT NULL AND faculty <> ''
             GROUP BY class_id, faculty
            HAVING subject_count = 1
         ) s ON s.class_id = p.class_id AND s.faculty = p.posted_by
         SET p.subject = s.subject_id
         WHERE p.subject IS NULL"
    );

    if (heliosColumnExists($pdo, 'classes', 'owner')) {
        if (heliosConstraintExists($pdo, 'classes', 'classes_ibfk_1')) {
            $pdo->exec("ALTER TABLE classes DROP FOREIGN KEY classes_ibfk_1");
        }
        if (heliosIndexExists($pdo, 'classes', 'owner')) {
            $pdo->exec("ALTER TABLE classes DROP INDEX owner");
        }
        $pdo->exec("ALTER TABLE classes DROP COLUMN owner");
    }
    if (heliosColumnExists($pdo, 'classes', 'code')) {
        if (heliosIndexExists($pdo, 'classes', 'code')) {
            $pdo->exec("ALTER TABLE classes DROP INDEX code");
        }
        $pdo->exec("ALTER TABLE classes DROP COLUMN code");
    }
}

heliosEnsureAcademicSchema($pdo);
$pdo->exec("DELETE FROM calendar_events WHERE event_date < CURDATE()");
