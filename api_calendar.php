<?php
// api_calendar.php — Calendar Events API
// Faculty: GET / POST (add) / DELETE
// Student: GET only

session_start();
require 'auth_helpers.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json');

$role     = $_SESSION['role']     ?? '';
$username = $_SESSION['username'] ?? '';

if (!$username) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthenticated']);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];

// ── GET: fetch events for a given year-month (or whole year) ──────────────
if ($method === 'GET') {
    $year  = (int)($_GET['year']  ?? date('Y'));
    $month = isset($_GET['month']) ? (int)$_GET['month'] : null; // null = full year

    if ($month) {
        $start = sprintf('%04d-%02d-01', $year, $month);
        $end   = date('Y-m-t', strtotime($start));

        $stmt = $pdo->prepare(
            "SELECT e.id, e.title, e.description, e.event_date, e.class_id,
                    e.created_by, c.name AS class_name
             FROM calendar_events e
             LEFT JOIN classes c ON c.id = e.class_id
             WHERE e.event_date BETWEEN ? AND ?
             ORDER BY e.event_date ASC"
        );        $stmt->execute([$start, $end]);
    } else {
        // Whole-year view: return just dates + counts for dot indicators
        $stmt = $pdo->prepare(
            "SELECT event_date, COUNT(*) AS cnt
             FROM calendar_events
             WHERE YEAR(event_date) = ?
             GROUP BY event_date"
        );
        $stmt->execute([$year]);
    }

    echo json_encode(['ok' => true, 'events' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit();
}

// ── Faculty-only mutations ────────────────────────────────────────────────
if ($role !== 'faculty') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit();
}

// ── POST: create event ────────────────────────────────────────────────────
if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    $title       = trim($body['title']       ?? '');
    $description = trim($body['description'] ?? '');
    $eventDate   = trim($body['event_date']  ?? '');
    $subjectId   = !empty($body['class_id']) ? $body['class_id'] : null; // class_id field carries subject id
    $startTime   = !empty($body['start_time']) ? $body['start_time'] : null;
    $endTime     = !empty($body['end_time'])   ? $body['end_time']   : null;

    if (!$title || !$eventDate || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate)) {
        http_response_code(422);
        echo json_encode(['error' => 'Title and a valid event_date (YYYY-MM-DD) are required.']);
        exit();
    }

    // Resolve subject → class_id, and verify faculty owns that subject
    $classId = null;
    if ($subjectId !== null) {
        $check = $pdo->prepare("SELECT class_id FROM subjects WHERE id = ? AND faculty = ? LIMIT 1");
        $check->execute([$subjectId, $username]);
        $subjectRow = $check->fetch(PDO::FETCH_ASSOC);
        if (!$subjectRow) {
            http_response_code(403);
            echo json_encode(['error' => 'You are not assigned to that subject.']);
            exit();
        }
        $classId = $subjectRow['class_id'];
    }

    $insert = $pdo->prepare(
        "INSERT INTO calendar_events (title, description, event_date, start_time, end_time, class_id, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    $insert->execute([$title, $description, $eventDate, $startTime, $endTime, $classId, $username]);
    $newId = $pdo->lastInsertId();

    // Return the newly created event (join class name)
    $row = $pdo->prepare(
        "SELECT e.*, c.name AS class_name
         FROM calendar_events e
         LEFT JOIN classes c ON c.id = e.class_id
         WHERE e.id = ?"
    );
    $row->execute([$newId]);

    echo json_encode(['ok' => true, 'event' => $row->fetch(PDO::FETCH_ASSOC)]);
    exit();
}

// ── DELETE: remove event ──────────────────────────────────────────────────
if ($method === 'DELETE') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $id   = (int)($body['id'] ?? 0);

    if (!$id) {
        http_response_code(422);
        echo json_encode(['error' => 'Event id required.']);
        exit();
    }

    // Faculty may only delete their own events
    $del = $pdo->prepare("DELETE FROM calendar_events WHERE id = ? AND created_by = ?");
    $del->execute([$id, $username]);

    if ($del->rowCount()) {
        echo json_encode(['ok' => true]);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Event not found or not yours.']);
    }
    exit();
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed.']);