<?php
/**
 * Helios University Academic Hub
 * process_class.php — Central backend for all class operations
 *
 * Actions (POST param: action):
 *   create_class      — Faculty: create a new class, generate join code
 *   delete_class      — Faculty: delete own class
 *   leave_class       — Student: leave a class
 *   kick_member       — Faculty: remove a student from class
 *   create_post       — Faculty: post announcement/assignment/material to class feed
 *   delete_post       — Faculty: delete a post
 *   submit_assignment — Student: submit work for an assignment post
 *   add_comment       — Student or Faculty: comment on a post
 *   delete_comment    — Comment author or Faculty: delete a comment
 *   score_submission  — Faculty: give a numeric score to a submission
 *   update_class      — Faculty: rename class or change subject
 */

session_start();

/* ── Auth guard ── */
if (!isset($_SESSION['username'])) {
    http_response_code(403);
    exit(json_encode(['error' => 'Not authenticated']));
}

$username = $_SESSION['username'];
$role     = $_SESSION['role'];

/* ── File paths ── */
$classesFile = __DIR__ . '/classes.json';
$usersFile   = __DIR__ . '/users.json';
$uploadBase  = __DIR__ . '/uploads/';

/* ── Bootstrap classes.json ── */
if (!file_exists($classesFile)) {
    file_put_contents($classesFile, json_encode(['classes' => []], JSON_PRETTY_PRINT));
}

/* ── Helpers ── */

/**
 * Load JSON file safely.
 */
function loadJson(string $path): array {
    if (!file_exists($path)) return [];
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

/**
 * Save JSON file atomically.
 */
function saveJson(string $path, array $data): bool {
    return file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
}

/**
 * Generate a unique 6-char alphanumeric class code.
 */
function generateCode(array $existingCodes): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no ambiguous chars
    do {
        $code = '';
        for ($i = 0; $i < 6; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
    } while (in_array($code, $existingCodes));
    return $code;
}

/**
 * Find a class by ID. Returns [&$class, $index] or null.
 */
function &findClass(array &$classes, string $classId): mixed {
    foreach ($classes as &$cls) {
        if ($cls['id'] === $classId) return $cls;
    }
    $null = null;
    return $null;
}

/**
 * Redirect back with a query param message.
 */
function redirectBack(string $url, string $param, string $value): never {
    header("Location: $url?" . $param . "=" . urlencode($value));
    exit();
}

/**
 * Handle file upload for a post attachment.
 * Returns file info array or null.
 */
function handleFileUpload(string $fieldName, string $subDir): ?array {
    if (empty($_FILES[$fieldName]['name'])) return null;

    $uploadDir = __DIR__ . '/uploads/' . $subDir . '/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    $origName = basename($_FILES[$fieldName]['name']);
    $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $allowed  = ['pdf','doc','docx','ppt','pptx','xls','xlsx','txt','zip','jpg','jpeg','png','gif','mp4','mp3'];

    if (!in_array($ext, $allowed)) return null;

    $safeName = uniqid('f_') . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $origName);
    $target   = $uploadDir . $safeName;

    if (!move_uploaded_file($_FILES[$fieldName]['tmp_name'], $target)) return null;

    return [
        'name' => $origName,
        'path' => 'uploads/' . $subDir . '/' . $safeName,
        'ext'  => $ext,
        'size' => $_FILES[$fieldName]['size'],
    ];
}

/* ══════════════════════════════════════════════════════
   ROUTER
══════════════════════════════════════════════════════ */

$action = trim($_POST['action'] ?? '');

if (empty($action)) {
    header("Location: dashboard.php");
    exit();
}

$classesData = loadJson($classesFile);
$classes     = &$classesData['classes'];

switch ($action) {

    /* ────────────────────────────────────────────────
       CREATE CLASS (Faculty only)
    ──────────────────────────────────────────────── */
    case 'create_class': {
        if ($role !== 'faculty') {
            redirectBack('dashboard.php', 'error', 'unauthorized');
        }

        $name        = trim($_POST['class_name'] ?? '');
        $subject     = trim($_POST['subject'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if (empty($name) || empty($subject)) {
            redirectBack('dashboard.php', 'error', 'class_fields_required');
        }

        // Collect existing codes to ensure uniqueness
        $existingCodes = array_column($classes, 'code');
        $code          = generateCode($existingCodes);

        $newClass = [
            'id'          => uniqid('cls_'),
            'name'        => $name,
            'subject'     => $subject,
            'description' => $description,
            'code'        => $code,
            'owner'       => $username,
            'members'     => [],   // student usernames
            'posts'       => [],   // feed posts
            'created_at'  => date('Y-m-d H:i:s'),
        ];

        $classes[] = $newClass;
        saveJson($classesFile, $classesData);

       redirectBack('role_faculty_class.php', 'class_id', $newClass['id']);
    }

    /* ────────────────────────────────────────────────
       UPDATE CLASS (Faculty owner only)
    ──────────────────────────────────────────────── */
    case 'update_class': {
        if ($role !== 'faculty') {
            redirectBack('dashboard.php', 'error', 'unauthorized');
        }

        $classId     = trim($_POST['class_id'] ?? '');
        $name        = trim($_POST['class_name'] ?? '');
        $subject     = trim($_POST['subject'] ?? '');
        $description = trim($_POST['description'] ?? '');

        $cls = &findClass($classes, $classId);

        if ($cls === null || $cls['owner'] !== $username) {
            redirectBack('dashboard.php', 'error', 'unauthorized');
        }

        if (!empty($name))    $cls['name']        = $name;
        if (!empty($subject)) $cls['subject']      = $subject;
        $cls['description'] = $description;

        saveJson($classesFile, $classesData);
        redirectBack('role_faculty_class.php', 'class_id', $classId);
    }

    /* ────────────────────────────────────────────────
       DELETE CLASS (Faculty owner only)
    ──────────────────────────────────────────────── */
    case 'delete_class': {
        if ($role !== 'faculty') {
            redirectBack('dashboard.php', 'error', 'unauthorized');
        }

        $classId = trim($_POST['class_id'] ?? '');

        $classes = array_values(array_filter($classes, function($cls) use ($classId, $username) {
            return !($cls['id'] === $classId && $cls['owner'] === $username);
        }));

        $classesData['classes'] = $classes;
        saveJson($classesFile, $classesData);

        redirectBack('dashboard.php', 'deleted', '1');
    }

    /* ────────────────────────────────────────────────
       LEAVE CLASS (Student only)
    ──────────────────────────────────────────────── */
    case 'leave_class': {
        if ($role !== 'student') {
            redirectBack('dashboard.php', 'error', 'unauthorized');
        }

        $classId = trim($_POST['class_id'] ?? '');
        $cls = &findClass($classes, $classId);

        if ($cls !== null) {
            $cls['members'] = array_values(array_filter($cls['members'], fn($m) => $m !== $username));
            saveJson($classesFile, $classesData);
        }

        redirectBack('dashboard.php', 'left', '1');
    }

    /* ────────────────────────────────────────────────
       KICK MEMBER (Faculty owner only)
    ──────────────────────────────────────────────── */
    case 'kick_member': {
        if ($role !== 'faculty') {
            redirectBack('dashboard.php', 'error', 'unauthorized');
        }

        $classId  = trim($_POST['class_id'] ?? '');
        $targetUser = trim($_POST['target_user'] ?? '');
        $cls = &findClass($classes, $classId);

        if ($cls === null || $cls['owner'] !== $username) {
            redirectBack('dashboard.php', 'error', 'unauthorized');
        }

        $cls['members'] = array_values(array_filter($cls['members'], fn($m) => $m !== $targetUser));
        saveJson($classesFile, $classesData);

        redirectBack('role_faculty_class.php', 'class_id', $classId);
    }

    /* ────────────────────────────────────────────────
       CREATE POST (Faculty owner only)
       type: announcement | assignment | material
    ──────────────────────────────────────────────── */
    case 'create_post': {
        if ($role !== 'faculty') {
            redirectBack('dashboard.php', 'error', 'unauthorized');
        }

        $classId  = trim($_POST['class_id'] ?? '');
        $type     = trim($_POST['post_type'] ?? 'announcement'); // announcement | assignment | material
        $title    = trim($_POST['title'] ?? '');
        $body     = trim($_POST['body'] ?? '');
        $deadline = trim($_POST['deadline'] ?? '');
        $points   = trim($_POST['points'] ?? '');
        $linkUrl  = trim($_POST['link_url'] ?? '');

        $cls = &findClass($classes, $classId);

        if ($cls === null || $cls['owner'] !== $username) {
            redirectBack('dashboard.php', 'error', 'unauthorized');
        }

        if (empty($title) && empty($body)) {
            redirectBack('role_faculty_class.php', 'class_id', $classId);
        }

        // Handle optional file attachment
        $fileInfo = handleFileUpload('post_file', 'class_posts');

        $post = [
            'id'         => uniqid('post_'),
            'type'       => in_array($type, ['announcement','assignment','material']) ? $type : 'announcement',
            'title'      => $title,
            'body'       => $body,
            'link_url'   => $linkUrl,
            'file'       => $fileInfo,
            'posted_by'  => $username,
            'posted_at'  => date('Y-m-d H:i:s'),
            'comments'   => [],
            // Assignment-specific
            'deadline'   => ($type === 'assignment' && $deadline) ? $deadline : null,
            'points'     => ($type === 'assignment' && is_numeric($points)) ? (int)$points : null,
            'submissions'=> [],  // [username => [file, note, submitted_at, score, score_note]]
        ];

        // Prepend post (newest first in storage too)
        array_unshift($cls['posts'], $post);
        saveJson($classesFile, $classesData);

        redirectBack('role_faculty_class.php', 'class_id', $classId);
    }

    /* ────────────────────────────────────────────────
       DELETE POST (Faculty owner only)
    ──────────────────────────────────────────────── */
    case 'delete_post': {
        if ($role !== 'faculty') {
            redirectBack('dashboard.php', 'error', 'unauthorized');
        }

        $classId = trim($_POST['class_id'] ?? '');
        $postId  = trim($_POST['post_id'] ?? '');
        $cls = &findClass($classes, $classId);

        if ($cls === null || $cls['owner'] !== $username) {
            redirectBack('dashboard.php', 'error', 'unauthorized');
        }

        $cls['posts'] = array_values(array_filter($cls['posts'], fn($p) => $p['id'] !== $postId));
        saveJson($classesFile, $classesData);

        redirectBack('role_faculty_class.php', 'class_id', $classId);
    }

    /* ────────────────────────────────────────────────
       SUBMIT ASSIGNMENT (Student only)
    ──────────────────────────────────────────────── */
    case 'submit_assignment': {
        if ($role !== 'student') {
            redirectBack('dashboard.php', 'error', 'unauthorized');
        }

        $classId = trim($_POST['class_id'] ?? '');
        $postId  = trim($_POST['post_id'] ?? '');
        $note    = trim($_POST['note'] ?? '');

        $cls = &findClass($classes, $classId);

        if ($cls === null || !in_array($username, $cls['members'] ?? [])) {
            redirectBack('dashboard.php', 'error', 'unauthorized');
        }

        foreach ($cls['posts'] as &$post) {
            if ($post['id'] === $postId && $post['type'] === 'assignment') {

                // Prevent re-submission
                if (isset($post['submissions'][$username])) {
                    redirectBack('role_student_class.php', 'class_id', $classId);
                }

                $fileInfo = handleFileUpload('submission_file', 'submissions');

                $post['submissions'][$username] = [
                    'submitted_at' => date('Y-m-d H:i:s'),
                    'note'         => $note,
                    'file'         => $fileInfo,
                    'score'        => null,
                    'score_note'   => null,
                    'scored_at'    => null,
                    'scored_by'    => null,
                ];
                break;
            }
        }
        unset($post);

        saveJson($classesFile, $classesData);
        redirectBack('role_student_class.php', 'class_id', $classId);
    }

    /* ────────────────────────────────────────────────
       ADD COMMENT (Any class member or owner)
    ──────────────────────────────────────────────── */
    case 'add_comment': {
        $classId = trim($_POST['class_id'] ?? '');
        $postId  = trim($_POST['post_id'] ?? '');
        $comment = trim($_POST['comment'] ?? '');

        if (empty($comment)) {
            redirectBack(
                $role === 'faculty' ? 'role_faculty_class.php' : 'role_student_class.php',
                'class_id', $classId
            );
        }

        $cls = &findClass($classes, $classId);

        if ($cls === null) {
            redirectBack('dashboard.php', 'error', 'not_found');
        }

        // Must be owner or enrolled member
        $isMember = ($cls['owner'] === $username) || in_array($username, $cls['members'] ?? []);
        if (!$isMember) {
            redirectBack('dashboard.php', 'error', 'unauthorized');
        }

        foreach ($cls['posts'] as &$post) {
            if ($post['id'] === $postId) {
                $post['comments'][] = [
                    'id'         => uniqid('cmt_'),
                    'author'     => $username,
                    'role'       => $role,
                    'body'       => htmlspecialchars($comment, ENT_QUOTES, 'UTF-8'),
                    'posted_at'  => date('Y-m-d H:i:s'),
                ];
                break;
            }
        }
        unset($post);

        saveJson($classesFile, $classesData);

        $redirect = $role === 'faculty' ? 'role_faculty_class.php' : 'role_student_class.php';
        redirectBack($redirect, 'class_id', $classId);
    }

    /* ────────────────────────────────────────────────
       DELETE COMMENT (Author or Faculty owner)
    ──────────────────────────────────────────────── */
    case 'delete_comment': {
        $classId   = trim($_POST['class_id'] ?? '');
        $postId    = trim($_POST['post_id'] ?? '');
        $commentId = trim($_POST['comment_id'] ?? '');

        $cls = &findClass($classes, $classId);

        if ($cls === null) {
            redirectBack('dashboard.php', 'error', 'not_found');
        }

        foreach ($cls['posts'] as &$post) {
            if ($post['id'] === $postId) {
                $post['comments'] = array_values(array_filter(
                    $post['comments'],
                    function($c) use ($commentId, $username, $cls, $role) {
                        if ($c['id'] !== $commentId) return true;
                        // Allow deletion only by comment author or class owner
                        return !($c['author'] === $username || $cls['owner'] === $username);
                    }
                ));
                break;
            }
        }
        unset($post);

        saveJson($classesFile, $classesData);

        $redirect = $role === 'faculty' ? 'role_faculty_class.php' : 'role_student_class.php';
        redirectBack($redirect, 'class_id', $classId);
    }

    /* ────────────────────────────────────────────────
       SCORE SUBMISSION (Faculty owner only)
    ──────────────────────────────────────────────── */
    case 'score_submission': {
        if ($role !== 'faculty') {
            redirectBack('dashboard.php', 'error', 'unauthorized');
        }

        $classId    = trim($_POST['class_id'] ?? '');
        $postId     = trim($_POST['post_id'] ?? '');
        $targetUser = trim($_POST['target_user'] ?? '');
        $score      = trim($_POST['score'] ?? '');
        $scoreNote  = trim($_POST['score_note'] ?? '');

        // Score must be a non-negative number
        if (!is_numeric($score) || (float)$score < 0) {
            redirectBack('role_faculty_class.php', 'class_id', $classId);
        }

        $cls = &findClass($classes, $classId);

        if ($cls === null || $cls['owner'] !== $username) {
            redirectBack('dashboard.php', 'error', 'unauthorized');
        }

        foreach ($cls['posts'] as &$post) {
            if ($post['id'] === $postId && $post['type'] === 'assignment') {
                if (isset($post['submissions'][$targetUser])) {
                    $post['submissions'][$targetUser]['score']      = (float)$score;
                    $post['submissions'][$targetUser]['score_note'] = $scoreNote;
                    $post['submissions'][$targetUser]['scored_at']  = date('Y-m-d H:i:s');
                    $post['submissions'][$targetUser]['scored_by']  = $username;
                }
                break;
            }
        }
        unset($post);

        saveJson($classesFile, $classesData);
        redirectBack('role_faculty_class.php', 'class_id', $classId);
    }

    /* ────────────────────────────────────────────────
       FALLBACK
    ──────────────────────────────────────────────── */
    default: {
        header("Location: dashboard.php");
        exit();
    }
}