<?php
declare(strict_types=1);
session_start();
ini_set('display_errors', '1');
error_reporting(E_ALL);
require_once __DIR__ . '/auth_helpers.php';
enforceTemporaryPasswordDeadline();
require_once __DIR__ . '/db.php';

if (file_exists(__DIR__ . '/cryptograph_process.php')) {
    require_once __DIR__ . '/cryptograph_process.php';
}

interface DatabaseDriverInterface {
    public static function load(string $type): array;
    public static function save(string $type, array $data): bool;
}

interface SanitizationInterface {
    public static function string(?string $input): string;
    public static function int(?string $input): int;
    public static function float(?string $input): float;
}

class SystemCore implements DatabaseDriverInterface {
    public const DIR_UPLOADS = __DIR__ . '/uploads/class_posts/';

    public static function authenticateSession(): array {
        if (!isset($_SESSION['username']) || !isset($_SESSION['role'])) {
            header("Location: login.php?error=unauthorized_access");
            exit();
        }
        $role = $_SESSION['role'];
        if (!in_array($role, ['faculty', 'student', 'admin'], true)) {
            header("Location: login.php?error=invalid_role_configuration");
            exit();
        }
        return [
            'username' => htmlspecialchars($_SESSION['username'], ENT_QUOTES, 'UTF-8'),
            'role'     => htmlspecialchars($role, ENT_QUOTES, 'UTF-8')
        ];
    }

    public static function load(string $type): array {
        global $pdo;

        if ($type === 'classes') {
            $stmt = $pdo->query("SELECT * FROM classes");
            $classes = $stmt->fetchAll();

            foreach ($classes as &$cls) {
                // Subjects
                $s = $pdo->prepare("SELECT * FROM subjects WHERE class_id = ?");
                $s->execute([$cls['id']]);
                $subjects = $s->fetchAll();
                foreach ($subjects as &$subj) {
                    $sm = $pdo->prepare("SELECT username FROM subject_members WHERE subject_id = ?");
                    $sm->execute([$subj['id']]);
                    $subj['students'] = array_column($sm->fetchAll(), 'username');
                }
                unset($subj);
                $cls['subjects'] = $subjects;

                // Members
                $m = $pdo->prepare("SELECT username FROM class_members WHERE class_id = ?");
                $m->execute([$cls['id']]);
                $cls['members'] = array_column($m->fetchAll(), 'username');

                // Posts — uses posted_at for ordering
                $p = $pdo->prepare("SELECT * FROM posts WHERE class_id = ? ORDER BY posted_at DESC");
                $p->execute([$cls['id']]);
                $posts = $p->fetchAll();

                foreach ($posts as &$post) {
                    // Backfill display metadata for posts created before subject tracking.
                    $postFaculty = $post['posted_by'] ?? '';
                    $post['subject_data'] = null;
                    foreach ($subjects as $subject) {
                        if (!empty($post['subject']) && ($subject['id'] ?? '') === $post['subject']) {
                            $post['subject_data'] = $subject;
                            break;
                        }
                    }
                    if (!$post['subject_data'] && !empty($postFaculty)) {
                        $facultySubjects = array_values(array_filter(
                            $subjects,
                            fn($subject) => ($subject['faculty'] ?? '') === $postFaculty
                        ));
                        if (count($facultySubjects) === 1) {
                            $post['subject_data'] = $facultySubjects[0];
                            $post['subject'] = $facultySubjects[0]['id'] ?? null;
                        }
                    }

                    // Post file: columns id, post_id, orig_name, stored_path, ext, size.
                    $pf = $pdo->prepare("SELECT * FROM post_files WHERE post_id = ?");
                    $pf->execute([$post['id']]);
                    $pfile = $pf->fetch();
                    $post['file'] = $pfile ? [
                        'name' => $pfile['orig_name'],
                        'path' => $pfile['stored_path'],
                        'ext'  => $pfile['ext'],
                        'size' => $pfile['size'],
                    ] : null;

                    // Comments — columns: id, post_id, author, role, body, posted_at
                    $cm = $pdo->prepare("SELECT * FROM comments WHERE post_id = ? ORDER BY posted_at ASC");
                    $cm->execute([$post['id']]);
                    $rawComments = $cm->fetchAll();
                    $post['comments'] = array_map(fn($c) => [
                        'id'        => $c['id'],
                        'author'    => $c['author'],
                        'role'      => $c['role'],
                        'body'      => $c['body'],
                        'posted_at' => $c['posted_at'],
                    ], $rawComments);

                    // Submissions — columns: id, post_id, student_username, note, submitted_at, score, score_note, scored_at, scored_by
                    $sf = $pdo->prepare("SELECT * FROM submissions WHERE post_id = ?");
                    $sf->execute([$post['id']]);
                    $subs = $sf->fetchAll();
                    $post['submissions'] = [];
                    foreach ($subs as $sub) {
                        // Submission files — columns: id, submission_id, orig_name, stored_path, ext, size
                        $sff = $pdo->prepare("SELECT * FROM submission_files WHERE submission_id = ?");
                        $sff->execute([$sub['id']]);
                        $subFile = $sff->fetch();
                        $post['submissions'][$sub['student_username']] = [
                            'submitted_at' => $sub['submitted_at'],
                            'note'         => $sub['note'],
                            'score'        => $sub['score'],
                            'score_note'   => $sub['score_note'],
                            'scored_at'    => $sub['scored_at'],
                            'scored_by'    => $sub['scored_by'],
                            'file'         => $subFile ? [
                                'name' => $subFile['orig_name'],
                                'path' => $subFile['stored_path'],
                                'ext'  => $subFile['ext'],
                                'size' => $subFile['size'],
                            ] : null,
                        ];
                    }
                }
                unset($post);
                $cls['posts'] = $posts;
            }
            unset($cls);
            return ['classes' => $classes];

        } else {
            // Users
            $stmt = $pdo->query("SELECT * FROM users");
            $users = $stmt->fetchAll();
            return ['user' => $users];
        }
    }

    public static function save(string $type, array $data): bool {
        // Persistence is handled directly via PDO in each execute method
        return true;
    }

    public static function ensureDirectories(): void {
        if (!is_dir(self::DIR_UPLOADS)) {
            mkdir(self::DIR_UPLOADS, 0755, true);
        }
    }
}

class Sanitizer implements SanitizationInterface {
    public static function string(?string $input): string {
        if ($input === null) return '';
        return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }
    
    public static function int(?string $input): int {
        return (int)preg_replace('/[^0-9-]/', '', (string)$input);
    }
    
    public static function float(?string $input): float {
        return (float)preg_replace('/[^0-9.-]/', '', (string)$input);
    }
    
    public static function date(?string $input): ?string {
        if (empty($input)) return null;
        $timestamp = strtotime($input);
        return $timestamp !== false ? date('Y-m-d\TH:i', $timestamp) : null;
    }
}

class FileHandler {
    private array $allowedExtensions = [
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 
        'txt', 'csv', 'jpg', 'jpeg', 'png', 'gif', 'zip', 'rar', 'mp4', 'mp3', 'wav', 'html', 'css', 'js', 'php'
    ];
    
    private int $maxFileSize = 104857600; 

    public function processUpload(array $fileData, string $destinationDir): ?array {
        if (!isset($fileData['name']) || $fileData['error'] !== UPLOAD_ERR_OK) {
            return null;
        }

        if ($fileData['size'] > $this->maxFileSize) {
            return null;
        }

        $origName = basename($fileData['name']);
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        
        if (!in_array($ext, $this->allowedExtensions, true)) {
            return null;
        }

        $safeName = uniqid('up_') . '_' . preg_replace('/[^a-zA-Z0-9.-]/', '_', $origName);
        $targetPath = rtrim($destinationDir, '/') . '/' . $safeName;
        
        if (move_uploaded_file($fileData['tmp_name'], $targetPath)) {
            return [
                'name' => $origName,
                'path' => 'uploads/class_posts/' . $safeName,
                'ext'  => $ext,
                'size' => $fileData['size']
            ];
        }

        return null;
    }

    public function removeFile(string $filePath): bool {
        $absolutePath = __DIR__ . '/' . ltrim($filePath, '/');
        if (file_exists($absolutePath) && is_file($absolutePath)) {
            return @unlink($absolutePath);
        }
        return false;
    }
}

class ClassroomManager {
    private array $classesData;
    private array $usersData;
    private string $currentUsername;
    private string $currentUserRole;
    private ?array $currentClass = null;
    private string $classId;
    private FileHandler $fileHandler;

    public function __construct(string $username, string $role, string $classId) {
        $this->currentUsername = $username;
        $this->currentUserRole = $role;
        $this->classId = $classId;
        $this->fileHandler = new FileHandler();
        
        SystemCore::ensureDirectories();
$this->classesData = SystemCore::load('classes');
$this->usersData   = SystemCore::load('users');

$this->resolveClassContext();
    }

    private function resolveClassContext(): void {
        if (!isset($this->classesData['classes']) || !is_array($this->classesData['classes'])) {
            header("Location: dashboard.php?error=database_corruption");
            exit();
        }

        foreach ($this->classesData['classes'] as &$cls) {
            if (isset($cls['id']) && $cls['id'] === $this->classId) {
                if ($this->currentUserRole === 'faculty') {
                    if ($this->isFacultyAssignedToClass($cls)) {
                        $this->currentClass = &$cls;
                        return;
                    }
                } elseif ($this->currentUserRole === 'student') {
                    if (in_array($this->currentUsername, $cls['members'] ?? [], true) || $this->isStudentEnrolledInSubject($cls)) {
                        $this->currentClass = &$cls;
                        return;
                    }
                } elseif ($this->currentUserRole === 'admin') {
                    $this->currentClass = &$cls;
                    return;
                }
            }
        }
        
        header("Location: dashboard.php?error=class_not_found_or_access_denied");
        exit();
    }

    private function isFacultyAssignedToClass(array $class): bool {
        foreach ($class['subjects'] ?? [] as $subject) {
            if (($subject['faculty'] ?? '') === $this->currentUsername) {
                return true;
            }
        }
        return false;
    }

    private function isStudentEnrolledInSubject(array $class): bool {
        foreach ($class['subjects'] ?? [] as $subject) {
            if (in_array($this->currentUsername, $subject['students'] ?? [], true)) {
                return true;
            }
        }
        return false;
    }

    private function resolveFacultySubject(?string $subjectId = null): ?array {
        $assignedSubjects = array_values(array_filter(
            $this->currentClass['subjects'] ?? [],
            fn($subject) => ($subject['faculty'] ?? '') === $this->currentUsername
        ));

        if ($subjectId !== null && $subjectId !== '') {
            foreach ($assignedSubjects as $subject) {
                if (($subject['id'] ?? '') === $subjectId) {
                    return $subject;
                }
            }
            return null;
        }

        return $assignedSubjects[0] ?? null;
    }

    private function executeCreatePost(array $data, array $files): void {
        global $pdo;
        $type     = Sanitizer::string($data['post_type'] ?? 'announcement');
        $title    = Sanitizer::string($data['title'] ?? '');
        $body     = trim($data['body'] ?? '');
        $deadline = Sanitizer::date($data['deadline'] ?? null);
        $points   = Sanitizer::int($data['points'] ?? '100');
        $subject  = $this->resolveFacultySubject(Sanitizer::string($data['subject_id'] ?? ''));

        $fileInfo = null;
        if (isset($files['post_file'])) {
            $fileInfo = $this->fileHandler->processUpload($files['post_file'], SystemCore::DIR_UPLOADS);
        }

        if ($this->currentUserRole === 'faculty' && !$subject) {
            $this->redirect('stream', 'error_subject_required');
        }

        if (empty($title) && empty($body) && $fileInfo === null) {
            $this->redirect('stream', 'error_empty_post');
        }

        $type     = in_array($type, ['announcement', 'assignment', 'material']) ? $type : 'announcement';
        $postId   = uniqid('post_');
        $deadline = ($type === 'assignment' && !empty($deadline)) ? $deadline : null;
        $points   = ($type === 'assignment' && $points > 0) ? $points : null;

        $stmt = $pdo->prepare(
            "INSERT INTO posts (id, class_id, type, title, body, posted_by, subject, deadline, points)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $postId,
            $this->classId,
            $type,
            $title,
            $body,
            $this->currentUsername,
            $subject['id'] ?? null,
            $deadline,
            $points
        ]);

        if ($fileInfo) {
            $pf = $pdo->prepare(
                "INSERT INTO post_files (post_id, orig_name, stored_path, ext, size)
                 VALUES (?, ?, ?, ?, ?)"
            );
            $pf->execute([$postId, $fileInfo['name'], $fileInfo['path'], $fileInfo['ext'], $fileInfo['size']]);
        }

        $this->redirect('stream', 'post_created');
    }

    private function executeSubmitAssignment(array $data, array $files): void {
        global $pdo;
        $postId = Sanitizer::string($data['post_id'] ?? '');
        $note   = trim($data['note'] ?? '');

        if (!$this->canStudentAccessPost($postId)) {
            $this->redirect('grades', 'error_access_denied');
        }

        $fileInfo = null;
        if (isset($files['submission_file'])) {
            $fileInfo = $this->fileHandler->processUpload($files['submission_file'], SystemCore::DIR_UPLOADS);
        }

        // Insert submission
        $stmt = $pdo->prepare(
            "INSERT INTO submissions (post_id, student_username, note)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE note = VALUES(note), submitted_at = NOW()"
        );
        $stmt->execute([$postId, $this->currentUsername, Sanitizer::string($note)]);
        $subId = $pdo->lastInsertId();

        if ($fileInfo && $subId) {
            $pf = $pdo->prepare(
                "INSERT INTO submission_files (submission_id, orig_name, stored_path, ext, size)
                 VALUES (?, ?, ?, ?, ?)"
            );
            $pf->execute([$subId, $fileInfo['name'], $fileInfo['path'], $fileInfo['ext'], $fileInfo['size']]);
        }

        $this->redirect('grades', 'work_submitted');
    }

    private function executeUnsubmitAssignment(string $postId): void {
        global $pdo;
        if (!$this->canStudentAccessPost($postId)) {
            $this->redirect('grades', 'error_access_denied');
        }
        // Get submission id first to delete files
        $stmt = $pdo->prepare("SELECT id FROM submissions WHERE post_id = ? AND student_username = ? AND score IS NULL");
        $stmt->execute([$postId, $this->currentUsername]);
        $sub = $stmt->fetch();
        if ($sub) {
            $pdo->prepare("DELETE FROM submission_files WHERE submission_id = ?")->execute([$sub['id']]);
            $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$sub['id']]);
        }
        $this->redirect('grades', 'work_unsubmitted');
    }

    private function canStudentAccessPost(string $postId): bool {
        global $pdo;
        if ($this->currentUserRole !== 'student' || $postId === '') {
            return true;
        }

        $stmt = $pdo->prepare("SELECT subject FROM posts WHERE id = ? AND class_id = ?");
        $stmt->execute([$postId, $this->classId]);
        $subjectId = $stmt->fetchColumn();
        if ($subjectId === false) {
            return false;
        }
        if (empty($subjectId)) {
            return in_array($this->currentUsername, $this->currentClass['members'] ?? [], true);
        }

        foreach ($this->currentClass['subjects'] ?? [] as $subject) {
            if (($subject['id'] ?? '') === $subjectId) {
                return in_array($this->currentUsername, $subject['students'] ?? [], true);
            }
        }

        return false;
    }

    private function executeDeletePost(string $postId): void {
        global $pdo;
        if (empty($postId)) return;
        if ($this->currentUserRole === 'faculty') {
            $ownerCheck = $pdo->prepare("SELECT id FROM posts WHERE id = ? AND class_id = ? AND posted_by = ?");
            $ownerCheck->execute([$postId, $this->classId, $this->currentUsername]);
            if (!$ownerCheck->fetchColumn()) {
                $this->redirect('stream', 'error_delete_denied');
            }
        }
        $pdo->prepare("DELETE FROM submission_files WHERE submission_id IN (SELECT id FROM submissions WHERE post_id = ?)")->execute([$postId]);
        $pdo->prepare("DELETE FROM submissions WHERE post_id = ?")->execute([$postId]);
        $pdo->prepare("DELETE FROM comments WHERE post_id = ?")->execute([$postId]);
        $pdo->prepare("DELETE FROM post_files WHERE post_id = ?")->execute([$postId]);
        $pdo->prepare("DELETE FROM posts WHERE id = ? AND class_id = ?")->execute([$postId, $this->classId]);
        $this->redirect('stream', 'post_deleted');
    }

    private function executeUpdatePost(array $data, array $files): void {
        global $pdo;
        $postId = Sanitizer::string($data['post_id'] ?? '');
        if (empty($postId)) return;

        $ownerCheck = $pdo->prepare("SELECT * FROM posts WHERE id = ? AND class_id = ? AND posted_by = ?");
        $ownerCheck->execute([$postId, $this->classId, $this->currentUsername]);
        $existing = $ownerCheck->fetch(PDO::FETCH_ASSOC);
        if (!$existing) {
            $this->redirect('stream', 'error_update_denied');
        }

        $type = $existing['type'] ?? 'assignment';
        $title = Sanitizer::string($data['title'] ?? '');
        $body = trim($data['body'] ?? '');
        $deadline = Sanitizer::date($data['deadline'] ?? null);
        $points = Sanitizer::int($data['points'] ?? '100');
        $subject = $this->resolveFacultySubject(Sanitizer::string($data['subject_id'] ?? ''));

        if (!$subject || (empty($title) && empty($body))) {
            $this->redirect('stream', 'error_invalid_post');
        }

        $deadline = ($type === 'assignment' && !empty($deadline)) ? $deadline : null;
        $points = ($type === 'assignment' && $points > 0) ? $points : null;

        $stmt = $pdo->prepare(
            "UPDATE posts
                SET title = ?, body = ?, subject = ?, deadline = ?, points = ?
              WHERE id = ? AND class_id = ? AND posted_by = ?"
        );
        $stmt->execute([$title, $body, $subject['id'] ?? null, $deadline, $points, $postId, $this->classId, $this->currentUsername]);

        if (!empty($data['remove_file'])) {
            $pf = $pdo->prepare("SELECT stored_path FROM post_files WHERE post_id = ?");
            $pf->execute([$postId]);
            $storedPath = $pf->fetchColumn();
            if ($storedPath) $this->fileHandler->removeFile((string)$storedPath);
            $pdo->prepare("DELETE FROM post_files WHERE post_id = ?")->execute([$postId]);
        }

        if (isset($files['post_file']) && !empty($files['post_file']['name'])) {
            $fileInfo = $this->fileHandler->processUpload($files['post_file'], SystemCore::DIR_UPLOADS);
            if ($fileInfo) {
                $pf = $pdo->prepare("SELECT stored_path FROM post_files WHERE post_id = ?");
                $pf->execute([$postId]);
                $storedPath = $pf->fetchColumn();
                if ($storedPath) $this->fileHandler->removeFile((string)$storedPath);
                $pdo->prepare("DELETE FROM post_files WHERE post_id = ?")->execute([$postId]);
                $pdo->prepare(
                    "INSERT INTO post_files (post_id, orig_name, stored_path, ext, size)
                     VALUES (?, ?, ?, ?, ?)"
                )->execute([$postId, $fileInfo['name'], $fileInfo['path'], $fileInfo['ext'], $fileInfo['size']]);
            }
        }

        $_POST['_redirect_post'] = $postId;
        $this->redirect('stream', 'post_updated');
    }

    private function executeAddComment(string $postId, string $text): void {
        global $pdo;
        if (empty($postId) || empty(trim($text))) return;
        $stmt = $pdo->prepare(
            "INSERT INTO comments (id, post_id, author, role, body)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([uniqid('cmt_'), $postId, $this->currentUsername, $this->currentUserRole, trim($text)]);
        $this->redirect('stream', 'comment_added');
    }

    private function executeDeleteComment(string $postId, string $commentId): void {
        global $pdo;
        if (empty($postId) || empty($commentId)) return;
        if ($this->currentUserRole === 'faculty') {
            $pdo->prepare("DELETE FROM comments WHERE id = ? AND post_id = ?")->execute([$commentId, $postId]);
        } else {
            $pdo->prepare("DELETE FROM comments WHERE id = ? AND post_id = ? AND author = ?")->execute([$commentId, $postId, $this->currentUsername]);
        }
        $this->redirect('stream', 'comment_deleted');
    }

    private function executeScoreSubmission(string $postId, string $studentUser, string $score, string $note): void {
        global $pdo;
        if (empty($postId) || empty($studentUser)) return;

        if ($this->currentUserRole === 'faculty') {
            $ownerCheck = $pdo->prepare("SELECT id FROM posts WHERE id = ? AND class_id = ? AND posted_by = ?");
            $ownerCheck->execute([$postId, $this->classId, $this->currentUsername]);
            if (!$ownerCheck->fetchColumn()) {
                $this->redirect('grades', 'error_score_denied');
            }
        }

        // Get points ceiling
        $ps = $pdo->prepare("SELECT points FROM posts WHERE id = ?");
        $ps->execute([$postId]);
        $pointCeiling = (float)($ps->fetchColumn() ?? 100);

        $finalScore = null;
        if ($score !== '' && is_numeric($score)) {
            $parsed = (float)$score;
            if ($parsed >= 0) $finalScore = min($parsed, $pointCeiling);
        }

        $stmt = $pdo->prepare(
            "UPDATE submissions SET score = ?, score_note = ?, scored_at = NOW(), scored_by = ?
             WHERE post_id = ? AND student_username = ?"
        );
        $stmt->execute([$finalScore, Sanitizer::string($note), $this->currentUsername, $postId, $studentUser]);
        $this->redirect('grades', 'score_updated');
    }

    private function executeUpdateSettings(array $data): void {
        global $pdo;
        $name    = Sanitizer::string($data['class_name'] ?? '');
        $subject = Sanitizer::string($data['subject'] ?? '');
        $desc    = trim($data['description'] ?? '');

        if (!empty($name)) {
            $pdo->prepare("UPDATE classes SET name = ? WHERE id = ?")->execute([$name, $this->classId]);
        }
        if (!empty($subject)) {
            $pdo->prepare("UPDATE classes SET subject = ? WHERE id = ?")->execute([$subject, $this->classId]);
        }
        $pdo->prepare("UPDATE classes SET description = ? WHERE id = ?")->execute([$desc, $this->classId]);
        $this->redirect('stream', 'settings_updated');
    }

    private function executeUpdateBanner(array $data): void {
        global $pdo;
        $color = strtolower(trim($data['banner_color'] ?? ''));
        if (preg_match('/^#[0-9a-f]{6}$/', $color)) {
            $pdo->prepare("UPDATE classes SET description = ? WHERE id = ?")->execute([$color, $this->classId]);
        }
        $this->redirect('stream', 'banner_updated');
    }

    private function executeRemoveStudent(string $studentUser): void {
        global $pdo;
        if (empty($studentUser)) return;
        $pdo->prepare("DELETE FROM class_members WHERE class_id = ? AND username = ?")->execute([$this->classId, $studentUser]);
        $this->redirect('people', 'student_removed');
    }

    private function executeDeleteClass(): void {
        global $pdo;
        $subStmt = $pdo->prepare("SELECT id FROM subjects WHERE class_id = ?");
        $subStmt->execute([$this->classId]);
        $subjectIds = $subStmt->fetchAll(PDO::FETCH_COLUMN);
        if ($subjectIds) {
            $in = implode(',', array_fill(0, count($subjectIds), '?'));
            $pdo->prepare("DELETE FROM subject_members WHERE subject_id IN ($in)")->execute($subjectIds);
        }
        $postStmt = $pdo->prepare("SELECT id FROM posts WHERE class_id = ?");
        $postStmt->execute([$this->classId]);
        $postIds = $postStmt->fetchAll(PDO::FETCH_COLUMN);
        if ($postIds) {
            $in = implode(',', array_fill(0, count($postIds), '?'));
            $pdo->prepare("DELETE FROM submission_files WHERE submission_id IN (SELECT id FROM submissions WHERE post_id IN ($in))")->execute($postIds);
            $pdo->prepare("DELETE FROM submissions WHERE post_id IN ($in)")->execute($postIds);
            $pdo->prepare("DELETE FROM comments WHERE post_id IN ($in)")->execute($postIds);
            $pdo->prepare("DELETE FROM post_files WHERE post_id IN ($in)")->execute($postIds);
            $pdo->prepare("DELETE FROM posts WHERE class_id = ?")->execute([$this->classId]);
        }
        $pdo->prepare("DELETE FROM subjects WHERE class_id = ?")->execute([$this->classId]);
        $pdo->prepare("DELETE FROM class_members WHERE class_id = ?")->execute([$this->classId]);
        $pdo->prepare("DELETE FROM classes WHERE id = ?")->execute([$this->classId]);
        header("Location: dashboard.php?msg=class_deleted");
        exit();
    }

    private function saveState(): void {
        // No-op: all writes now go directly to MySQL via PDO
    }

    private function redirect(string $tab, string $msg): void {
        $postId = $_POST['_redirect_post'] ?? '';
        $url = "?id=" . urlencode($this->classId) . "&tab=" . urlencode($tab) . "&msg=" . urlencode($msg);
        if (!empty($postId)) {
            $url .= "&post=" . urlencode($postId);
        }
        header("Location: " . $url);
        exit();
    }

    // ── Public API ────────────────────────────────────────────────────────────

    public function handlePostRequest(array $data, array $files): void {
        $action = $data['action'] ?? '';
        switch ($action) {
            case 'create_post':
                $this->executeCreatePost($data, $files);
                break;
            case 'submit_assignment':
                $this->executeSubmitAssignment($data, $files);
                break;
            case 'unsubmit_assignment':
                $this->executeUnsubmitAssignment(Sanitizer::string($data['post_id'] ?? ''));
                break;
            case 'delete_post':
                $this->executeDeletePost(Sanitizer::string($data['post_id'] ?? ''));
                break;
            case 'update_post':
                $this->executeUpdatePost($data, $files);
                break;
            case 'add_comment':
                $this->executeAddComment(
                    Sanitizer::string($data['post_id'] ?? ''),
                    $data['comment_text'] ?? ''
                );
                break;
            case 'delete_comment':
                $this->executeDeleteComment(
                    Sanitizer::string($data['post_id'] ?? ''),
                    Sanitizer::string($data['comment_id'] ?? '')
                );
                break;
            case 'score_submission':
                $this->executeScoreSubmission(
                    Sanitizer::string($data['post_id'] ?? ''),
                    Sanitizer::string($data['target_user'] ?? ''),
                    $data['score'] ?? '',
                    $data['score_note'] ?? ''
                );
                break;
            case 'update_settings':
                $this->executeUpdateSettings($data);
                break;
            case 'update_banner':
                $this->executeUpdateBanner($data);
                break;
            case 'remove_student':
                $this->executeRemoveStudent(Sanitizer::string($data['target_user'] ?? ''));
                break;
            case 'delete_class':
                $this->executeDeleteClass();
                break;
        }
    }

    public function getClassData(): array {
        return $this->currentClass ?? [];
    }

    public function buildUserDictionary(): array {
        $dict = [];
        foreach ($this->usersData['user'] ?? [] as $user) {
            if (isset($user['username'])) {
                $dict[$user['username']] = $user;
            }
        }
        return $dict;
    }

    public function getCurrentProfile(): array {
        foreach ($this->usersData['user'] ?? [] as $user) {
            if (($user['username'] ?? '') === $this->currentUsername) {
                return $user;
            }
        }
        return [];
    }
}
     
class ViewRenderer {
    
    public static function getFileIcon(string $ext): string {
        $ext = strtolower(trim($ext));
        $icons = [
            'pdf'  => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M16 13H8"/><path d="M16 17H8"/><path d="M10 9H8"/>',
            'doc'  => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M16 13H8"/><path d="M16 17H8"/><path d="M10 9H8"/>',
            'docx' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M16 13H8"/><path d="M16 17H8"/><path d="M10 9H8"/>',
            'txt'  => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/>',
            'xls'  => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h8"/><path d="M8 17h8"/><path d="M12 13v4"/>',
            'xlsx' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h8"/><path d="M8 17h8"/><path d="M12 13v4"/>',
            'ppt'  => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><rect x="8" y="11" width="8" height="6" rx="1"/>',
            'pptx' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><rect x="8" y="11" width="8" height="6" rx="1"/>',
            'jpg'  => '<rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>',
            'png'  => '<rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>',
            'gif'  => '<rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>',
            'zip'  => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
            'rar'  => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
            'mp4'  => '<rect x="2" y="2" width="20" height="20" rx="2.18" ry="2.18"/><line x1="7" y1="2" x2="7" y2="22"/><line x1="17" y1="2" x2="17" y2="22"/><line x1="2" y1="12" x2="22" y2="12"/>',
            'mp3'  => '<path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/>'
        ];
        
        if (array_key_exists($ext, $icons)) {
            return $icons[$ext];
        }
        
        return '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/>';
    }

    public static function formatRelativeTime(string $datetime): string {
        $targetTime = strtotime($datetime);
        if ($targetTime === false) return $datetime;
        
        $diff = time() - $targetTime;
        if ($diff < 60) return 'Just now';
        if ($diff < 3600) {
            $m = floor($diff / 60);
            return $m . ($m == 1 ? ' min ago' : ' mins ago');
        }
        if ($diff < 86400) {
            $h = floor($diff / 3600);
            return $h . ($h == 1 ? ' hr ago' : ' hrs ago');
        }
        if ($diff < 604800) {
            $d = floor($diff / 86400);
            return $d . ($d == 1 ? ' day ago' : ' days ago');
        }
        return date('M j, Y', $targetTime);
    }

    public static function getPostTheme(string $type): array {
        switch (strtolower(trim($type))) {
            case 'assignment': 
                return ['label' => 'Assignment', 'color' => 'var(--gc-blue)', 'bg' => 'var(--gc-blue-light)', 'icon' => 'icon-assignment'];
            case 'material':   
                return ['label' => 'Material', 'color' => 'var(--gc-green)', 'bg' => 'var(--gc-green-light)', 'icon' => 'icon-material'];
            case 'announcement':
            default:           
                return ['label' => 'Announcement', 'color' => 'var(--gc-text-secondary)', 'bg' => 'var(--gc-bg-hover)', 'icon' => 'icon-announcement'];
        }
    }
}

/* ═════════════════════════════════════════════════════════════════════════════
   [INITIALIZATION] CONTROLLER BOOTSTRAP
══════════════════════════════════════════════════════════════════════════════ */

$authData = SystemCore::authenticateSession();
$username = $authData['username'];
$role     = $authData['role'];

$classId = trim($_GET['id'] ?? $_GET['class_id'] ?? '');

$manager = new ClassroomManager($username, $role, $classId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $manager->handlePostRequest($_POST, $_FILES);
}

$currentClass    = $manager->getClassData();
$usersDictionary = $manager->buildUserDictionary();
$myProfile       = $manager->getCurrentProfile();

$myDisplayName = $myProfile['display_name'] ?? $myProfile['fullname'] ?? $username;
$myInitials    = strtoupper(substr($myDisplayName, 0, 1));
$myAvatarUrl   = (!empty($myProfile['avatar']) && file_exists(__DIR__ . '/' . $myProfile['avatar'])) ? htmlspecialchars($myProfile['avatar'], ENT_QUOTES, 'UTF-8') : null;

$accountSettingsHref = $role === 'faculty' ? 'role_faculty_profile.php' : 'role_student_profile.php';

$allowedTabs = ($role === 'faculty') ? ['stream', 'people', 'grades'] : ['stream', 'grades'];
$activeTab   = in_array($_GET['tab'] ?? '', $allowedTabs, true) ? $_GET['tab'] : 'stream';

$enrolledMembers = $currentClass['members'] ?? [];
$classSubjects   = $currentClass['subjects'] ?? [];
$visibleClassSubjects = match($role) {
    'student' => array_values(array_filter(
        $classSubjects,
        fn($subject) => in_array($username, $subject['students'] ?? [], true)
    )),
    'faculty' => array_values(array_filter(
        $classSubjects,
        fn($subject) => ($subject['faculty'] ?? '') === $username
    )),
    default => $classSubjects,
};
if ($role === 'faculty') {
    $facultySubjectMembers = [];
    foreach ($visibleClassSubjects as $subject) {
        foreach ($subject['students'] ?? [] as $studentUsername) {
            $facultySubjectMembers[$studentUsername] = $studentUsername;
        }
    }
    $enrolledMembers = array_values($facultySubjectMembers);
}
$visibleSubjectIds = array_map(fn($subject) => $subject['id'] ?? '', $visibleClassSubjects);
$selectedSubjectId = Sanitizer::string($_GET['subject'] ?? '');
if ($selectedSubjectId !== '' && !in_array($selectedSubjectId, $visibleSubjectIds, true)) {
    $selectedSubjectId = '';
}
$subjectById = [];
$assignedTeachers = [];
foreach ($classSubjects as $subject) {
    $subjectId = $subject['id'] ?? '';
    if ($subjectId !== '') {
        $subjectById[$subjectId] = $subject;
    }
    $subjectFaculty = $subject['faculty'] ?? '';
    if ($subjectFaculty !== '') {
        $assignedTeachers[$subjectFaculty]['subjects'][] = $subject;
    }
}
foreach ($assignedTeachers as $teacherUsername => &$teacherInfo) {
    $profile = $usersDictionary[$teacherUsername] ?? [];
    $teacherInfo['name'] = $profile['display_name'] ?? $profile['fullname'] ?? $teacherUsername;
    $teacherInfo['initials'] = strtoupper(substr($teacherInfo['name'], 0, 1));
}
unset($teacherInfo);
$primaryTeacher = reset($assignedTeachers);
$facultyName = is_array($primaryTeacher) ? ($primaryTeacher['name'] ?? 'Faculty') : 'Faculty';
$facultyInitials = strtoupper(substr($facultyName, 0, 1));
$facultyAvatarUrl = null;
$getSubjectName = static function(array $post) use ($subjectById): string {
    $subject = $post['subject_data'] ?? null;
    if (!$subject && !empty($post['subject'])) {
        $subject = $subjectById[$post['subject']] ?? null;
    }
    return $subject['name'] ?? 'Unassigned subject';
};
$getPostFacultyName = static function(array $post) use ($usersDictionary, $facultyName): string {
    $facultyUser = $post['posted_by'] ?? '';
    $profile = $usersDictionary[$facultyUser] ?? [];
    return ($profile['display_name'] ?? $profile['fullname'] ?? $facultyUser) ?: $facultyName;
};
$allClassPosts = $currentClass['posts'] ?? [];
$classPosts = array_values(array_filter($allClassPosts, function($post) use ($role, $username, $visibleSubjectIds, $selectedSubjectId) {
    $postSubject = $post['subject'] ?? '';
    $postFaculty = $post['posted_by'] ?? '';

    if ($role === 'faculty' && $postFaculty !== $username) {
        return false;
    }
    if ($role === 'student' && $postSubject !== '' && !in_array($postSubject, $visibleSubjectIds, true)) {
        return false;
    }
    if ($selectedSubjectId !== '' && $postSubject !== $selectedSubjectId) {
        return false;
    }

    return true;
}));
$assignmentPosts = array_values(array_filter($classPosts, fn($p) => ($p['type'] ?? '') === 'assignment'));
$selectedSubject = $selectedSubjectId !== '' ? ($subjectById[$selectedSubjectId] ?? null) : null;
$nowTs           = time();
$dueTasksCount   = 0;
$missingTasksCount = 0;
foreach ($assignmentPosts as $task) {
    $deadline = !empty($task['deadline']) ? strtotime($task['deadline']) : null;
    if ($deadline && $deadline >= $nowTs) {
        $dueTasksCount++;
    }
    if ($deadline && $deadline < $nowTs) {
        if ($role === 'student') {
            if (empty($task['submissions'][$username])) $missingTasksCount++;
        } else {
            $missingTasksCount += max(0, count($enrolledMembers) - count($task['submissions'] ?? []));
        }
    }
}

// Post detail view support
$activePostId = trim($_GET['post'] ?? '');
$activePost   = null;
if ($activePostId) {
    foreach ($classPosts as $p) {
        if (($p['id'] ?? '') === $activePostId) { $activePost = $p; break; }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <script>
    (function(){
        var t = localStorage.getItem('theme') ||
            (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        document.documentElement.setAttribute('data-theme', t);
    })();
</script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= htmlspecialchars($currentClass['name']) ?> - Helios University Dashboard</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400;1,500&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --gc-bg-main: #ffffff;
            --gc-bg-alt: #f8f9fa;
            --gc-bg-hover: #f1f3f4;
            --gc-bg-active: #e8eaed;
            
            --gc-border: #dadce0;
            --gc-border-dark: #bdc1c6;
            --gc-border-focus: #1a73e8;
            
            --gc-text-primary: #202124;
            --gc-text-secondary: #4f555d;
            --gc-text-tertiary: #646a73;
            --font-main: 'Poppins', system-ui, -apple-system, sans-serif;
            
            --gc-blue: #1a73e8;
            --gc-blue-hover: #174ea6;
            --gc-blue-light: #e8f0fe;
            --gc-green: #1e8e3e;
            --gc-green-light: #e6f4ea;
            --gc-red: #d93025;
            --gc-red-light: #fce8e6;
            --gc-warning: #f29900;
            --gc-warning-light: #fef7e0;
            --gc-purple: #9334e6;
            
            <?php 
            $bannerColor = $currentClass['banner_color'] ?? ($role === 'faculty' ? '#1967d2' : '#0d652d');
            ?>
            --class-theme-main: <?= htmlspecialchars($bannerColor) ?>;
            --class-theme-grad: <?= htmlspecialchars($bannerColor) ?>;
            --class-theme-text: #ffffff;
            
            --gc-shadow-nav: 0 1px 2px 0 rgba(60,64,67,0.3), 0 2px 6px 2px rgba(60,64,67,0.15);
            --gc-shadow-card: 0 1px 2px 0 rgba(60,64,67,0.3), 0 1px 3px 1px rgba(60,64,67,0.15);
            --gc-shadow-hover: 0 1px 3px 0 rgba(60,64,67,0.3), 0 4px 8px 3px rgba(60,64,67,0.15);
            --gc-shadow-modal: 0 24px 38px 3px rgba(0,0,0,0.14), 0 9px 46px 8px rgba(0,0,0,0.12), 0 11px 15px -7px rgba(0,0,0,0.2);
            --gc-shadow-dropdown: 0 4px 5px 0 rgba(0,0,0,0.14), 0 1px 10px 0 rgba(0,0,0,0.12), 0 2px 4px -1px rgba(0,0,0,0.2);
            
            --gc-radius-sm: 4px;
            --gc-radius-md: 8px;
            --gc-radius-lg: 12px;
            --gc-radius-xl: 16px;
            --gc-radius-pill: 9999px;
            
            --transition-fast: 0.15s cubic-bezier(0.4, 0.0, 0.2, 1);
            --transition-standard: 0.25s cubic-bezier(0.4, 0.0, 0.2, 1);
        }

        [data-theme="dark"] {
            --gc-bg-main: #202124;
            --gc-bg-alt: #292a2d;
            --gc-bg-hover: #3c4043;
            --gc-bg-active: #4a4d51;
            
            --gc-border: #5f6368;
            --gc-border-dark: #80868b;
            --gc-border-focus: #8ab4f8;
            
            --gc-text-primary: #e8eaed;
            --gc-text-secondary: #9aa0a6;
            --gc-text-tertiary: #80868b;
            
            --gc-blue: #8ab4f8;
            --gc-blue-hover: #aecbfa;
            --gc-blue-light: rgba(138, 180, 248, 0.15);
            
            --gc-green: #81c995;
            --gc-green-light: rgba(129, 201, 149, 0.15);
            
            --gc-red: #f28b82;
            --gc-red-light: rgba(242, 139, 130, 0.15);
            
            --gc-warning: #fdd663;
            --gc-warning-light: rgba(253, 214, 99, 0.15);

            --class-theme-text: #e8eaed;

            --gc-shadow-card: 0 1px 2px 0 rgba(0,0,0,0.6), 0 1px 3px 1px rgba(0,0,0,0.3);
            --gc-shadow-hover: 0 1px 3px 0 rgba(0,0,0,0.6), 0 4px 8px 3px rgba(0,0,0,0.3);
            --gc-shadow-modal: 0 24px 38px 3px rgba(0,0,0,0.6), 0 9px 46px 8px rgba(0,0,0,0.4), 0 11px 15px -7px rgba(0,0,0,0.5);
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html { font-size: 100%; -webkit-text-size-adjust: 100%; scroll-behavior: smooth; }
        
        body { 
            font-family: var(--font-main); 
            background: #f5f6fa;
            color: var(--gc-text-primary); 
            -webkit-font-smoothing: antialiased; 
            line-height: 1.5; 
            min-height: 100vh; 
            display: flex; 
            flex-direction: column;
            transition: background var(--transition-standard), color var(--transition-standard);
        }
        [data-theme="dark"] body {
            background: #07111f;
        }
        body::before {
            display: none;
        }
        
        a { text-decoration: none; color: var(--gc-blue); transition: color var(--transition-fast); }
        a:hover { color: var(--gc-blue-hover); }
        button { font-family: inherit; cursor: pointer; border: none; background: transparent; outline: none; }
        input, textarea, select { font-family: inherit; }
        ul, ol { list-style: none; }
        img, svg { display: block; max-width: 100%; }

        .icon-sprite-container { display: none; }
        .visually-hidden { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); border: 0; }
        .text-truncate { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        .app-bar {
            position: fixed; top: 0; left: 0; right: 0; height: 60px;
            background: rgba(255,255,255,.96); border-bottom: 1px solid rgba(24,49,83,.12);
            display: flex; align-items: center; justify-content: space-between;
            padding: 0 24px; z-index: 1000;
            transition: box-shadow var(--transition-standard), background var(--transition-standard);
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
        }
        .app-bar.scrolled { box-shadow: var(--gc-shadow-card); }
        [data-theme="dark"] .app-bar { background: rgba(9,16,27,.96); border-bottom-color: rgba(42,57,80,.95); }
        
        .app-bar-left { display: flex; align-items: center; gap: 16px; }
        
        .btn-icon-circular { 
            width: 40px; height: 40px; border-radius: 8px; 
            color: var(--gc-text-secondary); background: rgba(255,255,255,.42); border: 1px solid rgba(24,49,83,.08);
            display: flex; align-items: center; justify-content: center;
            transition: background-color var(--transition-fast), color var(--transition-fast); 
        }
        .btn-icon-circular:hover, .btn-icon-circular:focus-visible { 
            background-color: var(--gc-bg-hover); color: var(--gc-text-primary);
        }
        .btn-icon-circular svg { width: 24px; height: 24px; fill: currentColor; }
        [data-theme="dark"] .btn-icon-circular {
            background: rgba(60,64,67,.74);
            border-color: rgba(128,134,139,.32);
            color: var(--gc-text-primary);
        }
        
        .brand-container { display: flex; align-items: center; gap: 12px; }
        .brand-logo-img {
            width: 36px;
            height: 36px;
            object-fit: contain;
            object-position: center;
            display: block;
            border-radius: 8px;
            overflow: hidden;
            background: none;
            padding: 0;
        }
        .brand-logo-dark { display: none; }
        [data-theme="dark"] .brand-logo-light { display: none; }
        [data-theme="dark"] .brand-logo-dark { display: block; }
        .brand-title { font-size: 17px; font-weight: 700; color: var(--gc-text-primary); letter-spacing: -0.02em; line-height: 1.15; }
        .brand-title span { display:block; font-size:11px; font-weight:400; color: var(--gc-text-tertiary); letter-spacing:.08em; text-transform:uppercase; margin-top:-1px; }
        
        .app-bar-center { display: flex; gap: 8px; height: 100%; }
        
        .tab-link {
            position: relative; padding: 0 24px; display: flex; align-items: center; height: 100%;
            font-size: 14px; font-weight: 500; color: var(--gc-text-secondary); 
            transition: color var(--transition-fast), background-color var(--transition-fast);
        }
        .tab-link::after {
            content: ''; position: absolute; bottom: 0; left: 0; right: 0;
            height: 3px; background-color: var(--gc-blue); border-radius: 3px 3px 0 0;
            transform: scaleX(0); transition: transform var(--transition-standard);
        }
        .tab-link:hover { background-color: var(--gc-bg-alt); color: var(--gc-text-primary); }
        .tab-link.active { color: var(--gc-blue); }
        .tab-link.active::after { transform: scaleX(1); }

        .app-bar-right { display: flex; align-items: center; gap: 10px; }
        .topbar-clock { font-size:12px; color:var(--gc-text-tertiary); font-variant-numeric:tabular-nums; letter-spacing:.04em; white-space:nowrap; }
        .theme-toggle {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            border: 1px solid transparent;
            color: var(--gc-text-secondary);
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background-color var(--transition-fast), color var(--transition-fast), transform var(--transition-fast);
        }
        .theme-toggle:hover { background-color: var(--gc-bg-hover); color: var(--gc-text-primary); transform: rotate(8deg); }
        .theme-toggle svg { width: 18px; height: 18px; stroke: currentColor; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
        .icon-sun { display: none; }
        [data-theme="dark"] .icon-sun { display: block; }
        [data-theme="dark"] .icon-moon { display: none; }
        
        .user-avatar-trigger {
            display:flex; align-items:center; gap:10px; padding:6px 10px;
            border-radius:8px; background:transparent; border:1px solid transparent;
            color:var(--gc-text-primary); cursor:pointer; transition:background-color var(--transition-fast), border-color var(--transition-fast);
        }
        .user-avatar-trigger:hover, .user-avatar-trigger:focus-visible { 
            background-color: var(--gc-bg-hover); border-color: var(--gc-border);
        }
        .user-avatar-circle {
            width: 32px; height: 32px; border-radius: 8px;
            background: linear-gradient(135deg,#1a7a4a,#0e9f6e); color:#fff;
            display:flex; align-items:center; justify-content:center; font-size:13px; font-weight:700; overflow:hidden; flex-shrink:0;
        }
        .user-avatar-circle img { width: 100%; height: 100%; object-fit: cover; }
        .user-avatar-meta { line-height:1.3; text-align:left; display:flex; flex-direction:column; align-items:flex-start; }
        .user-avatar-name { display:block; font-size:13px; font-weight:500; color:var(--gc-text-primary); }
        .user-avatar-role { display:block; font-size:10px; text-transform:uppercase; letter-spacing:.08em; color:#1a7a4a; margin-top:1px; }
        .account-popover { position:fixed; top:72px; right:24px; width:min(360px, calc(100vw - 32px)); background:#eef3fb; border:1px solid var(--gc-border); border-radius:28px; box-shadow:0 14px 40px rgba(26,29,46,.22); z-index:1200; padding:20px; display:none; text-align:center; }
        .account-popover.open { display:block; }
        [data-theme="dark"] .account-popover { background:#292a2d; border-color:#5f6368; box-shadow:0 18px 46px rgba(0,0,0,.58); }
        .account-popover-close { position:absolute; top:16px; right:18px; width:32px; height:32px; border-radius:50%; display:flex; align-items:center; justify-content:center; color:var(--gc-text-secondary); font-size:24px; line-height:1; }
        .account-popover-close:hover { background:rgba(26,29,46,.08); color:var(--gc-text-primary); }
        [data-theme="dark"] .account-popover-close:hover { background:rgba(255,255,255,.08); }
        .account-email { padding:4px 40px 14px; font-size:14px; font-weight:500; color:var(--gc-text-primary); word-break:break-word; }
        .account-avatar-large { width:98px; height:98px; border-radius:50%; margin:14px auto 12px; background:#78909c; color:#fff; display:flex; align-items:center; justify-content:center; font-size:44px; font-weight:500; box-shadow:inset 0 0 0 4px rgba(255,255,255,.14); overflow:hidden; }
        .account-avatar-large img { width:100%; height:100%; object-fit:cover; }
        .account-greeting { font-size:24px; font-weight:500; color:var(--gc-text-primary); margin-bottom:16px; }
        .account-role-pill { display:inline-flex; align-items:center; justify-content:center; min-height:38px; padding:0 22px; border:1px solid rgba(26,29,46,.35); border-radius:999px; color:#0b57d0; font-weight:600; background:rgba(255,255,255,.35); margin-bottom:18px; }
        .account-actions { background:#fff; border-radius:18px; overflow:hidden; text-align:left; }
        [data-theme="dark"] .account-role-pill { color:var(--gc-green); background:var(--gc-green-light); border-color:rgba(129,201,149,.35); }
        [data-theme="dark"] .account-actions { background:#202124; }
        .account-action-link { display:flex; align-items:center; gap:14px; width:100%; padding:16px 22px; color:var(--gc-text-primary); font-size:15px; font-weight:600; text-decoration:none; }
        .account-action-link:hover { background:#f6f8fc; }
        [data-theme="dark"] .account-action-link:hover { background:#3c4043; }
        .account-action-link svg { width:20px; height:20px; fill:currentColor; color:var(--gc-text-secondary); flex-shrink:0; }

        /* Sidebar + content shell */
        .app-shell { display: flex; margin-top: 60px; min-height: calc(100vh - 60px); }

        .class-sidebar {
            width: 256px; flex-shrink: 0;
            background: rgba(255,255,255,.96); border-right: 1px solid rgba(24,49,83,.10);
            position: fixed; top: 60px; bottom: 0; left: 0;
            overflow-y: auto; overflow-x: hidden;
            transition: transform var(--transition-standard), width var(--transition-standard);
            z-index: 800; padding: 8px 0;
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
        }
        [data-theme="dark"] .class-sidebar { background: rgba(9,16,27,.96); border-right-color: rgba(42,57,80,.95); }
        .class-sidebar.collapsed { transform: translateX(-100%); }

        .sidebar-nav-item {
            display: flex; align-items: center; gap: 12px;
            padding: 9px 20px; font-size: 13.5px; font-weight: 400;
            color: var(--gc-text-secondary); text-decoration: none; cursor: pointer;
            border-radius: 0; margin-right: 0;
            transition: background-color var(--transition-fast), color var(--transition-fast);
            border: none; background: transparent; font-family: var(--font-main); width: 100%;
            text-align: left; position: relative;
        }
        .sidebar-nav-item svg { width: 16px; height: 16px; fill: currentColor; flex-shrink: 0; color: var(--gc-text-secondary); opacity: .7; }
        .sidebar-nav-item:hover { background-color: rgba(24,49,83,.06); color: var(--gc-text-primary); }
        .sidebar-nav-item.active { background-color: rgba(26,122,74,.10); color: #1a7a4a; font-weight: 500; }
        .sidebar-nav-item.active::before {
            content: "";
            position: absolute;
            left: 0; top: 4px; bottom: 4px;
            width: 3px;
            background: #1a7a4a;
            border-radius: 0 3px 3px 0;
        }
        .sidebar-nav-item.active svg { color: #1a7a4a; opacity: 1; }

        .sidebar-class-item {
            display: flex; align-items: center; gap: 12px;
            padding: 8px 20px; font-size: 13px;
            color: var(--gc-text-secondary); text-decoration: none;
            border-radius: 0; margin-right: 0;
            transition: background-color var(--transition-fast);
        }
        .sidebar-class-item:hover { background-color: rgba(24,49,83,.06); color: var(--gc-text-primary); }
        .sidebar-class-item.active { background-color: rgba(26,122,74,.10); color: var(--gc-text-primary); }
        .sidebar-class-avatar {
            width: 28px; height: 28px; border-radius: 50%;
            background-color: var(--gc-bg-active); color: var(--gc-text-secondary);
            display: flex; align-items: center; justify-content: center;
            font-size: 12px; font-weight: 600; flex-shrink: 0;
        }
        .sidebar-class-info { display: flex; flex-direction: column; overflow: hidden; }
        .sidebar-class-name { font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-size: 13px; }
        .sidebar-class-sub { font-size: 11px; color: var(--gc-text-secondary); }

        .sidebar-divider { height: 1px; background: var(--gc-border); margin: 8px 0; }
        .sidebar-section-label { font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: .12em; color: var(--gc-text-tertiary); padding: 16px 20px 6px; }

        .workspace-container { 
            margin-left: 256px; padding: 32px 40px; flex-grow: 1; min-width: 0;
            display: flex; flex-direction: column;
            transition: margin-left var(--transition-standard);
            max-width: 1320px;
        }
        .workspace-container.sidebar-collapsed { margin-left: 0; }

        .hero-banner {
            min-height: 182px; border-radius: var(--gc-radius-lg);
            background: var(--class-theme-main);
            background: linear-gradient(135deg, var(--class-theme-main), var(--class-theme-grad));
            position: relative; overflow: hidden; margin-bottom: 24px;
            display: flex; flex-direction: column; justify-content: flex-end; padding: 28px 32px;
            box-shadow: var(--gc-shadow-card);
        }
        [data-theme="dark"] .hero-banner {
            background: var(--class-theme-main);
            background: linear-gradient(135deg, var(--class-theme-main), var(--class-theme-grad));
        }
        .hero-banner::after {
            content: ''; position: absolute; right: -40px; top: -40px;
            width: 240px; height: 240px; border-radius: 50%;
            background: radial-gradient(circle, rgba(255,255,255,0.12) 0%, transparent 70%);
            pointer-events: none;
        }
        .hero-content { position: relative; z-index: 10; }
        .hero-title { font-size: 28px; font-weight: 600; color: #fff; margin-bottom: 2px; letter-spacing: -0.01em; line-height: 1.2; }
        .hero-subtitle { font-size: 15px; font-weight: 400; color: rgba(255,255,255,0.85); }
        
        .hero-actions-container {
            position: absolute; top: 16px; right: 16px; z-index: 10;
            display: flex; gap: 8px; align-items: center;
        }
        .hero-badge-pill {
            background-color: rgba(0,0,0,0.25); color: #fff;
            padding: 6px 14px; border-radius: var(--gc-radius-pill); font-size: 13px; font-weight: 500;
            display: flex; align-items: center; gap: 8px; backdrop-filter: blur(4px);
        }
        .hero-edit-btn {
            position: absolute; bottom: 14px; right: 14px; z-index: 10;
            width: 36px; height: 36px; border-radius: 50%;
            background: rgba(0,0,0,0.3); color: #fff; border: none; cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            transition: background var(--transition-fast);
        }
        .hero-edit-btn:hover { background: rgba(0,0,0,0.5); }
        .hero-edit-btn svg { width: 18px; height: 18px; }

        .btn-contained { 
            background-color: var(--gc-blue); color: white; padding: 8px 24px; 
            border-radius: var(--gc-radius-sm); font-weight: 500; font-size: 14px; 
            transition: background-color var(--transition-fast), box-shadow var(--transition-fast);
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
        }
        .btn-contained:hover, .btn-contained:focus-visible { background-color: var(--gc-blue-hover); box-shadow: var(--gc-shadow-card); }
        .btn-contained:disabled { background-color: var(--gc-bg-hover); color: var(--gc-text-tertiary); box-shadow: none; cursor: not-allowed; }
        
        .btn-text-action { 
            background: transparent; color: var(--gc-text-secondary); padding: 8px 16px; 
            border-radius: var(--gc-radius-sm); font-size: 14px; font-weight: 500; 
            transition: background-color var(--transition-fast), color var(--transition-fast);
        }
        .btn-text-action:hover { background-color: var(--gc-bg-hover); color: var(--gc-text-primary); }
        
        .btn-icon-standard { 
            padding: 8px; border-radius: 50%; color: var(--gc-text-secondary); 
            transition: background-color var(--transition-fast), color var(--transition-fast); 
            display: flex; align-items: center; justify-content: center;
        }
        .btn-icon-standard:hover { background-color: var(--gc-bg-hover); color: var(--gc-text-primary); }
        .btn-icon-standard svg { width: 24px; height: 24px; fill: currentColor; }

        .layout-stream { display: grid; grid-template-columns: 260px minmax(0, 1fr); gap: 24px; align-items: start; }
        @media(max-width: 768px) { 
            .layout-stream { grid-template-columns: 1fr; } 
            .layout-sidebar { display: none; }
        }

        .widget-panel { 
            background-color: var(--gc-bg-main); border: 1px solid var(--gc-border); 
            border-radius: var(--gc-radius-md); padding: 16px 20px; margin-bottom: 24px; 
            box-shadow: var(--gc-shadow-card); 
        }
        .widget-header { 
            font-size: 14px; font-weight: 500; color: var(--gc-text-primary); 
            margin-bottom: 12px; display: flex; align-items: center; justify-content: space-between;
        }
        
        .code-display-block { margin-bottom: 8px; }
        .code-string { font-size: 24px; font-weight: 500; color: var(--gc-blue); letter-spacing: 2px; line-height: 1.2;}
        .code-actions { display: flex; gap: 8px; margin-top: 8px; }
        
        .upcoming-empty-state { font-size: 13px; color: var(--gc-text-secondary); margin-bottom: 8px; line-height: 1.5; }
        .upcoming-task-list { display: flex; flex-direction: column; gap: 12px; margin-bottom: 12px; }
        .upcoming-task-item { display: flex; flex-direction: column; gap: 2px; }
        .upcoming-task-title { font-size: 13px; font-weight: 500; color: var(--gc-text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .upcoming-task-due { font-size: 12px; color: var(--gc-text-secondary); }

        .composer-trigger {
            background-color: var(--gc-bg-main); border: 1px solid var(--gc-border);
            border-radius: var(--gc-radius-md); padding: 16px; margin-bottom: 24px;
            box-shadow: var(--gc-shadow-card); display: flex; align-items: center; gap: 16px;
            cursor: pointer; transition: box-shadow var(--transition-standard), border-color var(--transition-standard);
        }
        .composer-trigger:hover { box-shadow: var(--gc-shadow-hover); border-color: var(--gc-border-dark); }
        .composer-placeholder-text { font-size: 14px; color: var(--gc-text-secondary); flex-grow: 1;}
        .composer-quick-actions { display: flex; gap: 8px; }
        .composer-quick-btn { 
            width: 40px; height: 40px; border-radius: 50%; color: var(--gc-text-secondary);
            display: flex; align-items: center; justify-content: center; transition: background-color 0.2s;
        }
        .composer-quick-btn:hover { background-color: var(--gc-bg-hover); color: var(--gc-blue); }
        .composer-quick-btn svg { width: 20px; height: 20px; fill: currentColor; }

        .feed-post-card { 
            background-color: var(--gc-bg-main); border: 1px solid var(--gc-border); 
            border-radius: var(--gc-radius-md); margin-bottom: 16px; 
            box-shadow: var(--gc-shadow-card); transition: box-shadow var(--transition-standard); 
            position: relative; display: flex; flex-direction: column;
        }
        .feed-post-card:hover { box-shadow: var(--gc-shadow-hover); }
        .post-indicator { position: absolute; top: 0; bottom: 0; left: 0; width: 4px; border-radius: var(--gc-radius-md) 0 0 var(--gc-radius-md); }
        
        .post-header { padding: 16px 24px; display: flex; align-items: flex-start; justify-content: space-between; }
        .post-author-block { display: flex; align-items: center; gap: 16px; }
        .post-type-icon { 
            width: 40px; height: 40px; border-radius: 50%; 
            display: flex; align-items: center; justify-content: center; color: white; flex-shrink: 0;
        }
        .post-metadata { display: flex; flex-direction: column; justify-content: center; }
        .post-metadata-title { font-size: 15px; font-weight: 500; color: var(--gc-text-primary); line-height: 1.4; }
        .post-metadata-time { font-size: 12px; color: var(--gc-text-secondary); margin-top: 2px; }
        
        .post-dropdown { position: relative; }
        .post-dropdown-menu {
            position: absolute; top: 100%; right: 0; background: var(--gc-bg-main);
            border: 1px solid var(--gc-border); border-radius: var(--gc-radius-sm);
            box-shadow: var(--gc-shadow-dropdown); min-width: 150px; z-index: 100;
            display: none; flex-direction: column; padding: 8px 0;
        }
        .post-dropdown-menu.show { display: flex; }
        .dropdown-item {
            padding: 10px 16px; font-size: 14px; color: var(--gc-text-primary);
            text-align: left; cursor: pointer; transition: background-color var(--transition-fast);
            display: flex; align-items: center; gap: 12px; width: 100%;
        }
        .dropdown-item:hover { background-color: var(--gc-bg-alt); }
        .dropdown-item.danger { color: var(--gc-red); }
        .dropdown-item svg { width: 18px; height: 18px; fill: currentColor; }

        .post-content-body { padding: 0 24px 16px; border-bottom: 1px solid var(--gc-border); }
        .post-content-body:last-child { border-bottom: none; }
        .post-text-content { font-size: 14px; color: var(--gc-text-primary); line-height: 1.5; white-space: pre-wrap; word-wrap: break-word; }
        .post-text-content:not(:empty) { margin-bottom: 16px; }
        
        .attachment-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; }
        .attachment-item { 
            display: flex; align-items: center; gap: 16px; 
            border: 1px solid var(--gc-border); border-radius: var(--gc-radius-md); 
            padding: 12px; transition: background-color var(--transition-fast); 
            text-decoration: none; color: inherit; background-color: var(--gc-bg-main);
        }
        .attachment-item:hover { background-color: var(--gc-bg-alt); }
        .attachment-icon-box { 
            width: 40px; height: 40px; border-radius: var(--gc-radius-sm); 
            background-color: var(--gc-bg-hover); color: var(--gc-text-secondary); 
            display: flex; align-items: center; justify-content: center; flex-shrink: 0;
        }
        .attachment-icon-box svg { width: 24px; height: 24px; fill: currentColor; }
        .attachment-info { display: flex; flex-direction: column; overflow: hidden; }
        .attachment-filename { font-size: 14px; font-weight: 500; margin: 0 0 2px; color: var(--gc-text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .attachment-filetype { font-size: 12px; color: var(--gc-text-secondary); text-transform: uppercase; font-weight: 500; }

        .assignment-stats-bar { 
            display: flex; padding: 16px 24px; background-color: var(--gc-bg-alt);
            border-radius: 0 0 var(--gc-radius-md) var(--gc-radius-md); gap: 32px;
            align-items: center; flex-wrap: wrap;
        }
        .stat-block { display: flex; flex-direction: column; cursor: pointer; transition: opacity var(--transition-fast); }
        .stat-block:hover { opacity: 0.7; }
        .stat-num { font-size: 28px; font-weight: 400; color: var(--gc-text-primary); line-height: 1; margin-bottom: 4px; }
        .stat-lbl { font-size: 12px; color: var(--gc-text-secondary); font-weight: 500; text-transform: uppercase; letter-spacing: 0.05em; }

        .assignment-action-bar-student { 
            padding: 16px 24px; border-top: 1px solid var(--gc-border); 
            background-color: #fafafa; border-radius: 0 0 var(--gc-radius-md) var(--gc-radius-md); 
            display: flex; justify-content: space-between; align-items: center; 
            flex-wrap: wrap; gap: 16px; margin-top: auto;
        }

        .status-indicator { 
            font-size: 13px; font-weight: 600; padding: 6px 14px; 
            border-radius: var(--gc-radius-pill); display: flex; align-items: center; gap: 6px;
            letter-spacing: 0.01em;
        }
        .status-indicator svg { width: 16px; height: 16px; stroke: currentColor; fill: none; stroke-width: 2.5; stroke-linecap: round; stroke-linejoin: round;}
        .status-missing { color: var(--gc-red); background-color: var(--gc-red-light); }
        .status-done { color: var(--gc-green); background-color: var(--gc-green-light); }
        .status-graded { color: var(--gc-blue); background-color: var(--gc-blue-light); }

        .comments-container { border-top: 1px solid var(--gc-border); padding: 16px 24px; background-color: var(--gc-bg-main); border-radius: 0 0 var(--gc-radius-md) var(--gc-radius-md); }
        .comments-toggle { font-size: 13px; color: var(--gc-text-secondary); font-weight: 500; display: flex; align-items: center; gap: 8px; cursor: pointer; padding: 8px 0; user-select: none; width: fit-content; }
        .comments-toggle svg { width: 16px; height: 16px; fill: currentColor; transition: transform var(--transition-standard); }
        .comments-toggle.expanded svg { transform: rotate(180deg); }
        .comments-toggle:hover { color: var(--gc-text-primary); }

        .comments-list { display: none; margin-top: 16px; flex-direction: column; gap: 16px; }
        .comments-list.expanded { display: flex; }
        .comment-item { display: flex; gap: 12px; align-items: flex-start; }
        .comment-avatar { width: 32px; height: 32px; border-radius: 50%; background-color: var(--gc-bg-hover); display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 500; color: var(--gc-text-secondary); flex-shrink: 0; }
        .comment-body-wrap { flex-grow: 1; }
        .comment-meta { display: flex; align-items: center; gap: 8px; margin-bottom: 2px; flex-wrap: wrap;}
        .comment-author { font-size: 13px; font-weight: 600; color: var(--gc-text-primary); }
        .comment-time { font-size: 11px; color: var(--gc-text-secondary); }
        .comment-text { font-size: 13px; color: var(--gc-text-primary); line-height: 1.4; word-wrap: break-word;}
        .comment-action-btn { background: none; border: none; padding: 4px; color: var(--gc-text-tertiary); cursor: pointer; border-radius: 50%; display: flex; align-items: center; justify-content: center; transition: background-color 0.2s, color 0.2s; }
        .comment-action-btn:hover { background-color: var(--gc-bg-hover); color: var(--gc-red); }
        .comment-action-btn svg { width: 16px; height: 16px; fill: currentColor; }
        
        .comment-input-area { display: flex; gap: 12px; align-items: flex-start; margin-top: 16px; }
        .comment-input-wrapper { flex-grow: 1; position: relative; }
        .comment-textarea { width: 100%; min-height: 40px; padding: 10px 48px 10px 16px; border: 1px solid var(--gc-border); border-radius: 20px; font-size: 13px; font-family: var(--font-main); color: var(--gc-text-primary); background-color: var(--gc-bg-main); outline: none; resize: none; transition: border-color var(--transition-fast); overflow: hidden; }
        .comment-textarea:focus { border-color: var(--gc-border-focus); }
        .btn-send-comment { position: absolute; right: 4px; top: 4px; bottom: 4px; width: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: var(--gc-text-secondary); transition: background-color var(--transition-fast), color var(--transition-fast); cursor: pointer; }
        .btn-send-comment:hover:not(:disabled) { background-color: var(--gc-bg-hover); color: var(--gc-blue); }
        .btn-send-comment:disabled { opacity: 0.5; cursor: default; }
        .btn-send-comment svg { width: 18px; height: 18px; fill: currentColor; }

        /* ── PEOPLE TAB (ROSTER VIEW) ── */
        .layout-people { max-width: 900px; margin: 0 auto; padding-top: 24px; padding-bottom: 48px; }
        .roster-section { margin-bottom: 40px; }
        .roster-header { 
            display: flex; align-items: baseline; gap: 8px;
            border-bottom: 1px solid var(--gc-border); padding-bottom: 12px; 
            margin-bottom: 0; margin-top: 8px; 
        }
        .roster-title { font-size: 22px; font-weight: 400; color: var(--gc-blue); line-height: 1; margin: 0; }
        .roster-count { font-size: 13px; color: var(--gc-blue); font-weight: 400; }
        
        .person-item { 
            display: flex; align-items: center; justify-content: space-between; 
            padding: 12px 0; border-bottom: 1px solid var(--gc-border); 
            transition: background-color var(--transition-fast); 
        }
        .person-item:last-child { border-bottom: none; }
        .person-info-group { display: flex; align-items: center; gap: 16px; }
        .person-avatar { 
            width: 36px; height: 36px; border-radius: 50%; 
            background-color: var(--gc-bg-hover); color: var(--gc-text-secondary); 
            display: flex; align-items: center; justify-content: center; 
            font-size: 15px; font-weight: 500; flex-shrink: 0;
        }
        .person-name { font-size: 14px; font-weight: 400; color: var(--gc-text-primary); }

        /* ── CLASS SETTINGS DIALOG INPUTS ── */
        .clean-input-group { display: flex; flex-direction: column; gap: 0; margin-bottom: 20px; }
        .clean-label { 
            font-size: 12px; font-weight: 500; color: var(--gc-blue); 
            padding: 8px 12px 0; background: var(--gc-bg-alt);
            border-radius: 4px 4px 0 0; border-bottom: none;
        }
        .clean-input { 
            width: 100%; padding: 6px 12px 10px; font-family: var(--font-main);
            font-size: 15px; color: var(--gc-text-primary);
            background: var(--gc-bg-alt); border: none; border-bottom: 1px solid var(--gc-border-dark);
            border-radius: 0 0 0 0; outline: none;
            transition: background 0.2s, border-color 0.2s;
        }
        .clean-input:focus { 
            background: var(--gc-blue-light); border-bottom: 2px solid var(--gc-blue); outline: none;
        }
        .textarea-control { min-height: 80px; resize: vertical; }
        .color-danger { color: var(--gc-red) !important; }
        .color-danger:hover { background-color: var(--gc-red-light) !important; }
        
        .btn-icon-danger { color: var(--gc-text-tertiary); padding: 8px; border-radius: 50%; transition: background-color 0.2s, color 0.2s; display: flex; align-items: center; justify-content: center; }
        .btn-icon-danger:hover { background-color: var(--gc-red-light); color: var(--gc-red); }
        .btn-icon-danger svg { width: 20px; height: 20px; fill: currentColor; }

        /* ── GRADES TAB (DATA GRID) - FACULTY ── */
        .layout-grades { max-width: 1400px; margin: 0 auto; padding-top: 24px; overflow-x: auto;}
        .grades-table-wrapper { border: 1px solid var(--gc-border); border-radius: var(--gc-radius-md); background-color: var(--gc-bg-main); overflow: hidden; box-shadow: var(--gc-shadow-card); }
        .grades-table { width: 100%; border-collapse: collapse; text-align: left; }
        .grades-table th { background-color: var(--gc-bg-alt); padding: 16px; border-bottom: 1px solid var(--gc-border); border-right: 1px solid var(--gc-border); font-weight: 500; font-size: 14px; color: var(--gc-text-secondary); vertical-align: bottom; }
        .grades-table th:last-child, .grades-table td:last-child { border-right: none; }
        .grades-table td { padding: 16px; border-bottom: 1px solid var(--gc-border); border-right: 1px solid var(--gc-border); font-size: 14px; color: var(--gc-text-primary); vertical-align: middle; }
        .grades-table tr:last-child td { border-bottom: none; }
        .grades-table tbody tr:hover { background-color: var(--gc-bg-hover); }
        
        .th-student { min-width: 200px; position: sticky; left: 0; background-color: var(--gc-bg-main); z-index: 10; border-right: 2px solid var(--gc-border) !important;}
        .grades-table th.th-student { background-color: var(--gc-bg-alt); z-index: 11; }
        
        .th-assignment { min-width: 160px; max-width: 200px; }
        .th-assignment-title { font-weight: 500; color: var(--gc-text-primary); margin-bottom: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .th-assignment-meta { font-size: 12px; color: var(--gc-text-secondary); }
        
        .grade-cell-content { display: flex; flex-direction: column; gap: 4px; align-items: center; }
        .grade-input-wrapper { display: flex; align-items: baseline; justify-content: center; gap: 2px; }
        .grade-input-field { 
            width: 50px; padding: 4px 0; font-family: var(--font-main); font-size: 15px; 
            color: var(--gc-text-primary); background: transparent; border: none; 
            border-bottom: 2px solid transparent; text-align: right; outline: none; 
            transition: border-bottom-color var(--transition-fast), background-color var(--transition-fast);
            border-radius: 4px 4px 0 0;
        }
        .grade-input-field:hover { background-color: var(--gc-bg-hover); border-bottom-color: var(--gc-border-dark); }
        .grade-input-field:focus { background-color: var(--gc-blue-light); border-bottom-color: var(--gc-blue); }
        .grade-denominator { font-size: 13px; color: var(--gc-text-secondary); }
        
        .status-badge { font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 12px; text-transform: uppercase; letter-spacing: 0.05em; }
        .status-badge.missing { background-color: var(--gc-red-light); color: var(--gc-red); }
        .status-badge.turned-in { background-color: var(--gc-green-light); color: var(--gc-green); }
        
        /* ── MY WORK TAB (STUDENT) ── */
        .layout-work { max-width: 1100px; margin: 0 auto; padding-top: 24px;}
        .work-profile-block { display: flex; align-items: center; gap: 20px; margin-bottom: 32px; padding: 24px; background-color: var(--gc-bg-main); border: 1px solid var(--gc-border); border-radius: var(--gc-radius-md); box-shadow: var(--gc-shadow-card); }
        .work-profile-avatar { width: 64px; height: 64px; border-radius: 50%; background-color: var(--class-theme-grad); color: white; display: flex; align-items: center; justify-content: center; font-size: 24px; font-weight: 500; }
        .work-profile-name { font-size: 24px; font-weight: 500; color: var(--gc-text-primary); }
        
        .work-summary-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 32px; }
        .work-stat-card { background: var(--gc-bg-main); border: 1px solid var(--gc-border); border-radius: 8px; padding: 20px; display: flex; flex-direction: column; align-items: center; box-shadow: var(--gc-shadow-card);}
        .work-stat-num { font-size: 32px; font-weight: 400; color: var(--gc-text-primary); line-height: 1; margin-bottom: 4px;}
        .work-stat-lbl { font-size: 13px; font-weight: 500; color: var(--gc-text-secondary); text-transform: uppercase;}

        .work-items-container { border: 1px solid var(--gc-border); border-radius: var(--gc-radius-md); background: white; overflow: hidden; box-shadow: var(--gc-shadow-card); margin-bottom: 24px;}
        .work-group-header { padding: 12px 16px; background-color: var(--gc-bg-alt); border-bottom: 1px solid var(--gc-border); color: var(--gc-text-primary); font-weight: 500; font-size: 14px;}
        .work-row { display: grid; grid-template-columns: 1fr auto auto; align-items: center; gap: 16px; padding: 16px 24px; border-bottom: 1px solid var(--gc-border); transition: background var(--transition-fast); }
        .work-row:last-child { border-bottom: none; }
        .work-row:hover { background-color: var(--gc-bg-alt); }
        .work-row-main { display: flex; flex-direction: column; gap: 4px; overflow: hidden; }
        .work-row-title { font-size: 15px; font-weight: 500; color: var(--gc-text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin:0;}
        .work-row-due { font-size: 12px; color: var(--gc-text-secondary); }
        .work-row-status-block { text-align: right; display:flex; flex-direction:column; align-items:flex-end; gap:4px;}
        .work-row-score { font-size: 15px; font-weight: 500; color: var(--gc-text-primary); }
        .work-row-score span { color: var(--gc-text-secondary); font-size: 13px; font-weight: 400;}

        /* ── GC DETAIL VIEW ── */
        .layout-stream,
        .layout-people,
        .layout-grades,
        .layout-work {
            scroll-margin-top: 88px;
        }
        .layout-people,
        .layout-grades,
        .layout-work {
            max-width: none;
            width: 100%;
            margin: 24px 0 0;
            padding: 24px;
            background: var(--gc-bg-main);
            border: 1px solid var(--gc-border);
            border-radius: var(--gc-radius-lg);
            box-shadow: var(--gc-shadow-card);
        }
        .layout-people::before,
        .layout-grades::before,
        .layout-work::before {
            display: block;
            margin-bottom: 18px;
            font-size: 18px;
            font-weight: 700;
            color: var(--gc-text-primary);
        }
        .layout-people::before { content: "People"; }
        .layout-grades::before { content: "Gradebook"; }
        .layout-work::before { content: "Classwork"; }
        .layout-people .roster-section:last-child { margin-bottom: 0; }
        .layout-grades { overflow-x: auto; }
        .work-items-container { background: var(--gc-bg-main); }
        [data-theme="dark"] .layout-people,
        [data-theme="dark"] .layout-grades,
        [data-theme="dark"] .layout-work { background: #292a2d; }

        .gc-detail-wrap { max-width: 1320px; margin: 0 auto; padding: 24px 0; }
        .gc-detail-back { display:inline-flex; align-items:center; gap:8px; color:var(--gc-text-secondary); font-size:14px; font-weight:500; margin-bottom:24px; transition:color var(--transition-fast); text-decoration:none; }
        .gc-detail-back:hover { color:var(--gc-blue); }
        .gc-detail-layout { display:grid; grid-template-columns: 1fr 380px; gap:32px; align-items:start; }
        @media(max-width:768px) { .gc-detail-layout { grid-template-columns:1fr; } }

        .gc-detail-main { background:var(--gc-bg-main); border:1px solid var(--gc-border); border-radius:var(--gc-radius-md); box-shadow:var(--gc-shadow-card); }
        .gc-detail-header { display:flex; align-items:flex-start; gap:20px; padding:32px 32px 0; }
        .gc-detail-icon { width:52px; height:52px; border-radius:50%; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
        .gc-detail-title-group { flex:1; min-width:0; }
        .gc-detail-title { font-size:30px; font-weight:400; color:var(--gc-text-primary); line-height:1.3; margin-bottom:6px; }
        .gc-detail-meta { font-size:14px; color:var(--gc-text-secondary); margin-bottom:8px; }
        .gc-detail-points-row { display:flex; align-items:center; gap:16px; font-size:14px; color:var(--gc-text-secondary); font-weight:500; }
        .gc-detail-due { color:var(--gc-text-secondary); }
        .gc-detail-divider { border:none; border-top:1px solid var(--gc-border); margin:24px 32px; }
        .gc-detail-body { padding:0 32px 28px; font-size:15px; color:var(--gc-text-primary); line-height:1.75; white-space:pre-wrap; }
        .gc-detail-attachment { padding:0 32px 24px; }
        .gc-detail-stats { display:flex; align-items:center; gap:40px; padding:20px 32px; background:var(--gc-bg-alt); border-top:1px solid var(--gc-border); border-radius:0 0 var(--gc-radius-md) var(--gc-radius-md); flex-wrap:wrap; }
        .gc-detail-comments { padding:0 32px 32px; }
        .gc-comments-label { display:flex; align-items:center; gap:8px; font-size:14px; font-weight:500; color:var(--gc-text-primary); margin-bottom:16px; }

        /* Your work panel */
        .gc-detail-sidebar { position:sticky; top:88px; }
        .gc-your-work-panel { background:var(--gc-bg-main); border:1px solid var(--gc-border); border-radius:var(--gc-radius-md); padding:24px; box-shadow:var(--gc-shadow-card); }
        .gc-yw-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:16px; }
        .gc-yw-title { font-size:18px; font-weight:500; color:var(--gc-text-primary); }
        .gc-yw-status { font-size:14px; font-weight:600; }
        .gc-yw-status.missing { color:var(--gc-red); }
        .gc-yw-status.turned-in { color:var(--gc-green); }
        .gc-yw-status.graded { color:var(--gc-blue); }
        .gc-yw-status.assigned { color:var(--gc-text-secondary); }
        .gc-yw-grade { font-size:26px; font-weight:500; color:var(--gc-text-primary); margin-bottom:16px; }
        .gc-yw-file { margin-bottom:16px; }
        .gc-yw-add-btn { width:100%; padding:14px; border:1px solid var(--gc-border); border-radius:var(--gc-radius-pill); font-size:15px; font-weight:500; color:var(--gc-blue); background:var(--gc-bg-main); display:flex; align-items:center; justify-content:center; gap:6px; cursor:pointer; transition:background var(--transition-fast); margin-bottom:10px; }
        .gc-yw-add-btn:hover { background:var(--gc-blue-light); }
        .gc-yw-done-btn { width:100%; padding:14px; border-radius:var(--gc-radius-pill); font-size:15px; font-weight:500; color:var(--gc-text-secondary); background:var(--gc-bg-hover); border:none; cursor:pointer; transition:background var(--transition-fast); margin-bottom:10px; }
        .gc-yw-done-btn:hover { background:var(--gc-bg-active); }
        .gc-yw-late-note { font-size:13px; color:var(--gc-text-secondary); text-align:center; margin-top:6px; }
        .gc-yw-private-label { display:flex; align-items:center; gap:6px; font-size:14px; font-weight:500; color:var(--gc-text-secondary); margin-bottom:10px; }
        .gc-yw-private-link { font-size:14px; color:var(--gc-blue); cursor:pointer; }
        .gc-yw-feedback { background:var(--gc-blue-light); border-left:3px solid var(--gc-blue); border-radius:0 var(--gc-radius-sm) var(--gc-radius-sm) 0; padding:12px 14px; margin-bottom:14px; }
        .gc-yw-feedback-label { display:block; font-size:11px; font-weight:700; color:var(--gc-blue); text-transform:uppercase; letter-spacing:.05em; margin-bottom:4px; }
        .gc-yw-feedback-text { font-size:14px; color:var(--gc-text-primary); line-height:1.5; margin:0; }

        /* Stream list card hover */
        .stream-card-clickable:hover { box-shadow:var(--gc-shadow-hover); }

        /* ── MODALS (MATERIAL DESIGN) ── */
        .modal-backdrop { 
            position: fixed; inset: 0; background-color: rgba(0,0,0,0.6); z-index: 3000; 
            display: none; align-items: center; justify-content: center; 
            backdrop-filter: blur(2px); opacity: 0; transition: opacity var(--transition-standard);
        }
        .modal-backdrop.active { display: flex; opacity: 1; }
        
        .dialog-surface { 
            background-color: var(--gc-bg-main); border-radius: var(--gc-radius-md); 
            width: 100%; max-width: 600px; box-shadow: var(--gc-shadow-modal); 
            display: flex; flex-direction: column; max-height: 90vh; 
            transform: scale(0.9) translateY(20px); transition: transform var(--transition-standard);
        }
        .modal-backdrop.active .dialog-surface { transform: scale(1) translateY(0); }
        
        .dialog-header { padding: 24px; border-bottom: 1px solid var(--gc-border); display: flex; justify-content: space-between; align-items: center; flex-shrink:0;}
        .dialog-title { font-size: 20px; font-weight: 500; margin: 0; color: var(--gc-text-primary);}
        .btn-dialog-close { padding: 8px; border-radius: 50%; cursor: pointer; color: var(--gc-text-secondary); transition: background-color 0.2s; display: flex; align-items: center; justify-content: center;}
        .btn-dialog-close:hover { background-color: var(--gc-bg-hover); color: var(--gc-text-primary);}
        .btn-dialog-close svg { width: 24px; height: 24px; fill: currentColor; }
        
        .dialog-content { padding: 24px; flex: 1; overflow-y: auto; display: flex; flex-direction: column; gap: 24px;}
        
        /* Floating Label Inputs inside Dialogs */
        .md-input-container { 
            position: relative;
            background: var(--gc-bg-alt); border-radius: 4px 4px 0 0; 
            border-bottom: 1px solid var(--gc-text-secondary); 
            transition: background 0.2s, border-bottom-color 0.2s; 
        }
        .md-input-container:focus-within { 
            background: var(--gc-blue-light); border-bottom: 2px solid var(--gc-blue); 
        }
        
        .md-input-field { 
            width: 100%; padding: 22px 16px 6px 16px; font-size: 15px; 
            border: none; background: transparent; outline: none; 
            color: var(--gc-text-primary); font-family: var(--font-main); 
        }
        .md-textarea { min-height: 120px; resize: vertical; padding-top: 24px; }
        
        .md-input-label { 
            position: absolute; left: 16px; top: 18px; font-size: 15px; 
            color: var(--gc-text-secondary); pointer-events: none; margin: 0;
            transition: 0.2s ease all; transform-origin: left top; 
        }
        
        .md-input-field:focus ~ .md-input-label, 
        .md-input-field:not(:placeholder-shown) ~ .md-input-label { 
            transform: translateY(-12px) scale(0.75); color: var(--gc-blue); 
        }

        /* Fixed Non-overlapping input grid */
        .static-grid-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .static-input-wrap { display: flex; flex-direction: column; background: var(--gc-bg-alt); border-radius: 4px 4px 0 0; border-bottom: 1px solid var(--gc-text-secondary); padding: 8px 12px 0; transition: background 0.2s, border-color 0.2s;}
        .static-input-wrap:focus-within { background: var(--gc-blue-light); border-bottom: 2px solid var(--gc-blue); }
        .static-input-label { font-size: 12px; color: var(--gc-blue); font-weight: 500; }
        .static-input-field { border: none; background: transparent; padding: 4px 0 8px; font-family: var(--font-main); font-size: 15px; color: var(--gc-text-primary); outline: none; width: 100%;}

        .upload-dropzone {
            border: 1px dashed var(--gc-border-dark); border-radius: 4px; padding: 32px 24px;
            text-align: center; cursor: pointer; transition: background-color var(--transition-fast), border-color var(--transition-fast);
            display: flex; flex-direction: column; align-items: center; gap: 12px;
            background-color: var(--gc-bg-main); 
        }
        .upload-dropzone:hover { background-color: var(--gc-bg-alt); border-color: var(--gc-blue); }
        .upload-dropzone svg { width: 32px; height: 32px; fill: var(--gc-blue); }
        .upload-dropzone-text { font-size: 14px; font-weight: 500; color: var(--gc-blue); }
        .upload-file-display { font-size: 13px; color: var(--gc-text-secondary); margin-top: 8px; word-break: break-all;}

        .dialog-actions { 
            padding: 16px 24px; border-top: 1px solid var(--gc-border); flex-shrink:0;
            display: flex; justify-content: flex-end; gap: 8px; background-color: var(--gc-bg-main);
            border-radius: 0 0 var(--gc-radius-md) var(--gc-radius-md);
        }

        .type-selector-tabs { display: flex; gap: 8px; border-bottom: 1px solid var(--gc-border); padding-bottom: 16px;}
        .type-tab { 
            padding: 8px 16px; border-radius: 16px; border: 1px solid var(--gc-border); 
            color: var(--gc-text-secondary); font-size: 14px; font-weight: 500; 
            transition: all var(--transition-fast); display: flex; align-items: center; gap: 8px;
        }
        .type-tab svg { width: 18px; height: 18px; fill: currentColor; }
        .type-tab:hover { background-color: var(--gc-bg-alt); color: var(--gc-text-primary); }
        .type-tab.active { background-color: var(--gc-blue-light); color: var(--gc-blue); border-color: var(--gc-blue-light); }

        .dialog-surface.large { max-width: 800px; }
        .submission-list-item { 
            display: flex; flex-direction: column; gap: 12px; padding: 16px; 
            border: 1px solid var(--gc-border); border-radius: var(--gc-radius-md); margin-bottom: 16px; 
            background-color: var(--gc-bg-alt);
        }
        .submission-student-info { display: flex; justify-content: space-between; align-items: center; }
        .submission-student-name { font-size: 15px; font-weight: 500; color: var(--gc-text-primary); display: flex; align-items: center; gap: 12px;}
        .submission-meta { font-size: 12px; color: var(--gc-text-secondary); }
        .submission-content-area { display: flex; flex-direction: column; gap: 12px; padding-left: 44px; }
        .submission-note { font-size: 14px; color: var(--gc-text-primary); font-style: italic; border-left: 3px solid var(--gc-border); padding-left: 12px; }
        .submission-grading-form { display: flex; align-items: center; gap: 12px; margin-top: 8px; flex-wrap: wrap;}

        .empty-illustration {
            text-align: center; padding: 64px 24px;
            background-color: var(--gc-bg-main); border: 1px solid var(--gc-border);
            border-radius: var(--gc-radius-md);
        }
        .empty-illustration svg { width: 72px; height: 72px; fill: var(--gc-border-dark); margin-bottom: 20px; }
        .empty-illustration h3 { font-size: 20px; font-weight: 500; color: var(--gc-text-primary); margin-bottom: 8px; }
        .empty-illustration p { font-size: 14px; color: var(--gc-text-secondary); }

        /* ── CLASSWORK TAB ROWS (Google Classroom style) ── */
        .layout-work { padding-top: 16px; }
        .cw-group { margin-bottom: 8px; border: 1px solid var(--gc-border); border-radius: var(--gc-radius-md); overflow: hidden; background: var(--gc-bg-main); box-shadow: var(--gc-shadow-card); }
        .cw-group-header { padding: 12px 24px; background: var(--gc-bg-alt); border-bottom: 1px solid var(--gc-border); font-size: 14px; font-weight: 500; color: var(--gc-text-primary); }
        .cw-row {
            display: flex; align-items: center; gap: 16px; padding: 14px 24px;
            border-bottom: 1px solid var(--gc-border); text-decoration: none; color: inherit;
            transition: background-color var(--transition-fast);
        }
        .cw-row:last-child { border-bottom: none; }
        .cw-row:hover { background-color: var(--gc-bg-hover); }
        .cw-row-icon { width: 32px; height: 32px; border-radius: 50%; background: var(--gc-green-light); color: var(--gc-green); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .cw-row-icon svg { width: 18px; height: 18px; fill: currentColor; }
        .cw-row-main { flex: 1; min-width: 0; }
        .cw-row-title { font-size: 14px; font-weight: 500; color: var(--gc-text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .cw-row-meta { font-size: 12px; color: var(--gc-text-secondary); margin-top: 2px; }
        .cw-row-status { flex-shrink: 0; }
        .cw-badge { font-size: 12px; font-weight: 500; padding: 3px 10px; border-radius: 12px; }
        .cw-badge-assigned { background: var(--gc-bg-hover); color: var(--gc-text-secondary); }
        .cw-badge-submitted { background: var(--gc-green-light); color: var(--gc-green); }
        .cw-badge-returned { background: var(--gc-blue-light); color: var(--gc-blue); }
        .cw-badge-missing { background: var(--gc-red-light); color: var(--gc-red); }

        /* Helios-matched class workspace */
        body {
            background: #f5f6fa;
        }
        [data-theme="dark"] body {
            background:
                linear-gradient(90deg, rgba(3,12,25,.96), rgba(16,37,66,.9)),
                linear-gradient(135deg, #07111f 0 22%, #10233d 22% 44%, #0b182a 44% 66%, #18365e 66% 100%);
        }
        body::before { display: none; }
        .app-bar {
            left: 0;
            right: 0;
            top: 0;
            height: 60px;
            border-radius: 0;
            background: rgba(255,255,255,.96);
            border-bottom: 1px solid rgba(24,49,83,.12);
            box-shadow: 0 1px 4px rgba(0,0,0,.06);
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
        }
        [data-theme="dark"] .app-bar {
            background: rgba(9,16,27,.96);
            border-bottom-color: rgba(42,57,80,.95);
        }
        .class-sidebar {
            display: block;
            top: 60px;
            background: rgba(255,255,255,.96);
            border-right: 1px solid rgba(24,49,83,.10);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
        }
        [data-theme="dark"] .class-sidebar {
            background: rgba(9,16,27,.96);
            border-right-color: rgba(42,57,80,.95);
        }
        .app-shell {
            margin-top: 60px;
            justify-content: flex-start;
        }
        .workspace-container {
            margin-left: 256px;
            margin-right: auto;
            width: min(1400px, calc(100vw - 312px));
            max-width: 1400px;
            padding: 32px 48px 56px;
        }
        .workspace-container.sidebar-collapsed {
            margin-left: auto;
            margin-right: auto;
            width: min(1400px, calc(100vw - 96px));
            max-width: 1400px;
            padding-left: 48px;
        }
        .brand-title { font-size: 17px; font-weight: 700; letter-spacing: -.02em; }
        .brand-title span { text-transform: uppercase; letter-spacing: .08em; font-size: 11px; }
        .tab-link::after { background: #1a7a4a; }
        .tab-link.active { color: #1a7a4a; font-weight: 600; }
        .tab-link.active::after { transform: scaleX(1); }
        .ls-class-hero {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 420px;
            gap: 24px;
            align-items: stretch;
            margin-bottom: 28px;
        }
        .ls-class-card,
        .ls-side-card,
        .ls-section {
            background: #ffffff;
            border: 1px solid rgba(24,49,83,.10);
            border-radius: 14px;
            box-shadow: 0 12px 30px rgba(24,49,83,.08);
        }
        .ls-class-card {
            padding: 26px;
            position: relative;
            overflow: hidden;
        }
        .ls-class-card::after {
            content: "";
            position: absolute;
            right: -60px;
            top: -80px;
            width: 260px;
            height: 260px;
            border-radius: 44%;
            background: linear-gradient(135deg, rgba(120,241,218,.45), rgba(255,171,199,.42));
            transform: rotate(18deg);
        }
        .ls-eyebrow { color: var(--gc-text-tertiary); font-size: 12px; font-weight: 600; margin-bottom: 12px; }
        .ls-class-title { position: relative; z-index: 1; font-size: 30px; line-height: 1.12; color: var(--gc-text-primary); margin-bottom: 8px; }
        .ls-class-subtitle { position: relative; z-index: 1; max-width: 620px; color: var(--gc-text-secondary); font-size: 13px; }
        .ls-stats-row {
            position: relative;
            z-index: 1;
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
            margin-top: 28px;
            padding-top: 24px;
            border-top: 1px solid rgba(88,101,124,.13);
        }
        .ls-stat { display: flex; flex-direction: column; gap: 4px; }
        .ls-stat-icon {
            width: 34px;
            height: 34px;
            border-radius: 10px;
            display: grid;
            place-items: center;
            color: #455166;
            background: #f1f4f8;
            margin-bottom: 6px;
        }
        .ls-stat-icon svg { width: 18px; height: 18px; fill: currentColor; }
        .ls-stat-value { font-size: 24px; font-weight: 800; color: var(--gc-text-primary); line-height: 1; }
        .ls-stat-label { font-size: 12px; color: var(--gc-text-secondary); }
        .ls-side-card { padding: 22px; }
        .ls-side-card h2 { font-size: 16px; margin-bottom: 14px; color: var(--gc-text-primary); }
        .ls-due-list { display: flex; flex-direction: column; gap: 12px; }
        .ls-due-item {
            display: grid;
            grid-template-columns: 36px minmax(0, 1fr);
            gap: 12px;
            align-items: center;
            padding: 12px;
            border-radius: 14px;
            background: #f8fafc;
            color: inherit;
        }
        .ls-due-icon {
            width: 36px;
            height: 36px;
            border-radius: 12px;
            display: grid;
            place-items: center;
            color: #5b6fe8;
            background: rgba(91,111,232,.12);
        }
        .ls-due-icon svg { width: 18px; height: 18px; fill: currentColor; }
        .ls-due-title { font-size: 13px; font-weight: 700; color: var(--gc-text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .ls-due-meta { font-size: 11px; color: var(--gc-text-secondary); margin-top: 2px; }
        .hero-banner,
        .layout-sidebar,
        .hero-actions-container { display: none; }
        .ls-subjects { margin-bottom: 30px; }
        .ls-section-head {
            display: flex;
            align-items: end;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 16px;
        }
        .ls-section-head h2 { font-size: 20px; color: var(--gc-text-primary); }
        .ls-section-head span { font-size: 12px; color: var(--gc-text-secondary); font-weight: 700; }
        .ls-subject-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 20px;
        }
        .ls-subject-card {
            min-height: 178px;
            border-radius: 20px;
            padding: 22px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            color: #172033;
            overflow: hidden;
            position: relative;
            box-shadow: 0 8px 28px rgba(37,48,67,.13);
            transition: transform 0.18s ease, box-shadow 0.18s ease;
            cursor: default;
            text-decoration: none;
        }
        a.ls-subject-card { cursor: pointer; }
        .ls-subject-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 16px 40px rgba(37,48,67,.18);
        }
        /* decorative blobs */
        .ls-subject-card::before {
            content: "";
            position: absolute;
            top: -40px; right: -40px;
            width: 130px; height: 130px;
            border-radius: 50%;
            background: rgba(255,255,255,.22);
        }
        .ls-subject-card::after {
            content: "";
            position: absolute;
            bottom: -30px; right: 30px;
            width: 90px; height: 90px;
            border-radius: 50%;
            background: rgba(255,255,255,.15);
        }
        .ls-subject-card:nth-child(4n+1) { background: linear-gradient(140deg, #93c5fd, #bfdbfe); }
        .ls-subject-card:nth-child(4n+2) { background: linear-gradient(140deg, #34d399, #6ee7b7); }
        .ls-subject-card:nth-child(4n+3) { background: linear-gradient(140deg, #60a5fa, #93c5fd); }
        .ls-subject-card:nth-child(4n+4) { background: linear-gradient(140deg, #818cf8, #a5b4fc); }
        .ls-subject-icon {
            position: relative;
            z-index: 1;
            width: 40px; height: 40px;
            border-radius: 12px;
            background: rgba(255,255,255,.35);
            display: flex; align-items: center; justify-content: center;
            margin-bottom: 14px;
        }
        .ls-subject-icon svg { width: 20px; height: 20px; fill: rgba(23,32,51,.7); }
        .ls-subject-name {
            position: relative;
            z-index: 1;
            font-size: 15px;
            font-weight: 500;
            font-family: 'Poppins', sans-serif;
            color: #172033;
            line-height: 1.35;
            margin-bottom: 6px;
        }
        .ls-subject-meta {
            position: relative;
            z-index: 1;
            font-size: 11px;
            font-weight: 500;
            color: rgba(23,32,51,.58);
        }
        .ls-subject-footer {
            position: relative;
            z-index: 1;
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 18px;
            padding-top: 14px;
            border-top: 1px solid rgba(255,255,255,.35);
        }
        .ls-subject-footer-label {
            font-size: 11px;
            font-weight: 600;
            font-family: 'Poppins', sans-serif;
            color: rgba(23,32,51,.55);
            text-transform: uppercase;
            letter-spacing: .04em;
        }
        .ls-subject-footer-badge {
            display: flex; align-items: center; gap: 5px;
            background: rgba(255,255,255,.40);
            border-radius: 999px;
            padding: 3px 10px;
            font-size: 11px;
            font-weight: 600;
            color: rgba(23,32,51,.72);
        }
        .ls-subject-footer-badge svg { width: 12px; height: 12px; fill: rgba(23,32,51,.55); }
        .ls-subject-progress {
            position: relative;
            z-index: 1;
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-top: 18px;
            padding: 10px 12px;
            border-radius: 10px;
            background: rgba(255,255,255,.62);
        }
        .ls-subject-progress-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 11px;
            font-weight: 700;
            color: rgba(23,32,51,.65);
        }
        .ls-subject-progress-pct {
            font-size: 12px;
            font-weight: 800;
            color: rgba(23,32,51,.82);
        }
        .ls-subject-progress-track {
            width: 100%;
            height: 8px;
            border-radius: 999px;
            background: rgba(23,32,51,.12);
            overflow: hidden;
        }
        .ls-subject-progress-track::before {
            content: "";
            display: block;
            height: 100%;
            width: var(--p, 60%);
            background: #5f6de8;
            border-radius: 999px;
        }
        .layout-stream {
            grid-template-columns: minmax(0, 1fr);
            gap: 0;
            margin-top: 0;
        }
        .layout-main-feed,
        .layout-people,
        .layout-grades,
        .layout-work {
            background: #ffffff;
            border: 1px solid rgba(24,49,83,.10);
            border-radius: 14px;
            box-shadow: 0 12px 30px rgba(24,49,83,.08);
            padding: 24px;
        }
        .layout-people,
        .layout-grades,
        .layout-work { margin-top: 24px; }
        .composer-trigger,
        .feed-post-card,
        .widget-panel,
        .cw-group,
        .grades-table-wrapper,
        .empty-illustration {
            box-shadow: none;
            border-color: rgba(88,101,124,.12);
            background: #f8fafc;
            border-radius: 14px;
        }
        .feed-post-card { overflow: hidden; }
        .post-indicator { display: none; }
        .post-type-icon,
        .cw-row-icon { border-radius: 12px; }
        .roster-title,
        .roster-count { color: var(--gc-text-primary); }
        .ls-task-board-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 18px;
        }
        .ls-task-board-title {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            color: var(--gc-text-primary);
            font-size: 17px;
            font-weight: 700;
        }
        .ls-task-board-title svg { width: 16px; height: 16px; fill: currentColor; color: var(--gc-text-secondary); }
        .ls-task-count { color: var(--gc-text-tertiary); font-weight: 600; }
        .ls-add-task-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            min-height: 34px;
            padding: 0 14px;
            border-radius: 999px;
            background: #f7f8fa;
            border: 1px solid rgba(88,101,124,.12);
            color: var(--gc-text-primary);
            font-size: 13px;
            font-weight: 700;
            box-shadow: 0 8px 20px rgba(37,48,67,.06);
        }
        .ls-add-task-btn svg { width: 16px; height: 16px; fill: currentColor; }
        .ls-add-task-btn:hover { background: #fff; color: var(--gc-blue); }
        .layout-feed {
            display: grid;
            gap: 14px;
        }
        .feed-post-card {
            border-radius: 22px;
            background: #fff;
            border: 1px solid rgba(88,101,124,.10);
            box-shadow: 0 14px 34px rgba(37,48,67,.08);
            padding: 16px;
        }
        .feed-post-card:hover { transform: translateY(-2px); box-shadow: 0 18px 42px rgba(37,48,67,.11); }
        .feed-post-card .post-header { padding: 0; align-items: flex-start; }
        .feed-post-card .post-author-block { gap: 12px; }
        .feed-post-card .post-type-icon {
            width: 28px;
            height: 28px;
            border-radius: 8px;
            background: #eef1f4 !important;
            color: var(--gc-text-secondary) !important;
        }
        .feed-post-card .post-type-icon svg { width: 16px !important; height: 16px !important; }
        .feed-post-card .post-metadata-title {
            font-size: 18px;
            line-height: 1.25;
            color: var(--gc-text-primary);
        }
        .feed-post-card .post-metadata-title b,
        .feed-post-card .post-metadata-title::first-letter { font-weight: 800; }
        .feed-post-card .post-metadata-time { font-size: 12px; color: var(--gc-text-tertiary); margin-top: 8px; }
        .feed-post-card .post-content-body {
            margin-top: 14px;
            padding: 14px;
            border: 0 !important;
            border-radius: 16px;
            background: #fffaf0;
        }
        .feed-post-card .status-indicator,
        .feed-post-card .post-header span[style*="Assigned"] {
            border-radius: 10px !important;
            padding: 5px 10px !important;
            background: #eef1f4;
            color: var(--gc-text-secondary) !important;
            font-size: 12px !important;
            font-weight: 700 !important;
        }
        .gc-detail-wrap { max-width: 1200px; }
        .gc-detail-layout {
            grid-template-columns: minmax(0, 1fr) 360px;
            gap: 32px;
        }
        .gc-detail-main,
        .gc-your-work-panel {
            border: 0;
            border-radius: 28px;
            background: rgba(255,255,255,.96);
            box-shadow: 0 24px 56px rgba(37,48,67,.14);
            overflow: hidden;
        }
        .gc-detail-header {
            padding: 36px 36px 0;
            align-items: center;
        }
        .gc-detail-icon {
            width: 28px;
            height: 28px;
            border-radius: 9px;
            background: #eef1f4 !important;
            color: transparent;
            box-shadow: inset 0 0 0 1px rgba(88,101,124,.06);
        }
        .gc-detail-icon svg { display: none; }
        .gc-detail-title {
            font-size: 24px;
            font-weight: 800;
            letter-spacing: 0;
        }
        .gc-detail-meta { margin-top: 8px; color: var(--gc-text-tertiary); }
        .gc-detail-points-row {
            width: min(340px, 100%);
            margin-top: 18px;
            padding: 12px 14px;
            border-radius: 16px;
            background: #f5f6f8;
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 12px;
        }
        .gc-detail-points-row span {
            min-height: 34px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0 14px;
            border-radius: 12px;
            background: #fff;
            box-shadow: 0 5px 14px rgba(37,48,67,.05);
            color: var(--gc-text-primary);
            font-weight: 700;
        }
        .gc-detail-divider { border-color: transparent; margin: 18px 30px; }
        .gc-detail-body {
            margin: 0 30px 18px;
            padding: 18px;
            min-height: 132px;
            border-radius: 18px;
            background: #fff9e9;
            color: #8a7a43;
        }
        .gc-detail-attachment { padding: 0 30px 18px; }
        .gc-detail-stats {
            margin: 0 30px 20px;
            padding: 14px;
            border: 0;
            border-radius: 18px;
            background: #f6f7f9;
        }
        .gc-detail-comments {
            margin: 0 30px 30px;
            padding: 16px 0 0;
            border-top: 1px solid rgba(88,101,124,.12);
        }
        .gc-comments-label { font-weight: 800; }
        .comment-textarea {
            border-radius: 14px;
            background: #f8fafc;
        }
        .gc-your-work-panel {
            padding: 18px;
        }
        .gc-yw-header { align-items: flex-start; }
        .gc-yw-title { font-weight: 800; }
        .gc-yw-grade {
            font-size: 26px;
            font-weight: 800;
        }
        .attachment-item {
            border-radius: 12px;
            background: #f8fafc;
        }
        [data-theme="dark"] .ls-add-task-btn,
        [data-theme="dark"] .feed-post-card,
        [data-theme="dark"] .gc-detail-main,
        [data-theme="dark"] .gc-your-work-panel {
            background: rgba(38,45,58,.96);
            border-color: rgba(125,139,164,.16);
            box-shadow: 0 18px 42px rgba(0,0,0,.24);
        }
        [data-theme="dark"] .feed-post-card .post-type-icon,
        [data-theme="dark"] .feed-post-card .status-indicator,
        [data-theme="dark"] .feed-post-card .post-header span[style*="Assigned"],
        [data-theme="dark"] .gc-detail-icon,
        [data-theme="dark"] .gc-detail-points-row,
        [data-theme="dark"] .gc-detail-stats,
        [data-theme="dark"] .attachment-item,
        [data-theme="dark"] .comment-textarea {
            background: rgba(255,255,255,.08) !important;
        }
        [data-theme="dark"] .feed-post-card .post-content-body,
        [data-theme="dark"] .gc-detail-body {
            background: rgba(255,244,214,.10);
            color: #e8d99f;
        }
        [data-theme="dark"] .gc-detail-points-row span { background: rgba(255,255,255,.10); }
        @media(max-width: 900px) {
            .gc-detail-layout { grid-template-columns: 1fr; }
        }
        [data-theme="dark"] .app-bar,
        [data-theme="dark"] .ls-class-card,
        [data-theme="dark"] .ls-side-card,
        [data-theme="dark"] .layout-main-feed,
        [data-theme="dark"] .layout-people,
        [data-theme="dark"] .layout-grades,
        [data-theme="dark"] .layout-work {
            background: rgba(10,18,30,.96);
            border-color: rgba(42,57,80,.95);
            box-shadow: 0 18px 42px rgba(0,0,0,.32);
        }
        [data-theme="dark"] .ls-due-item,
        [data-theme="dark"] .composer-trigger,
        [data-theme="dark"] .feed-post-card,
        [data-theme="dark"] .widget-panel,
        [data-theme="dark"] .cw-group,
        [data-theme="dark"] .grades-table-wrapper,
        [data-theme="dark"] .empty-illustration {
            background: rgba(38,45,58,.94);
            border-color: rgba(125,139,164,.16);
        }
        [data-theme="dark"] .ls-stat-icon { background: rgba(255,255,255,.08); color: #d8e1f2; }
        @media (max-width: 1100px) {
            .ls-class-hero { grid-template-columns: 1fr; }
            .ls-subject-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .ls-stats-row { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 720px) {
            .app-bar { left: 0; right: 0; top: 0; padding: 0 12px; }
            .app-bar-center { display: none; }
            .class-sidebar {
                top: 64px;
                box-shadow: 0 18px 40px rgba(37,48,67,.22);
            }
            .workspace-container,
            .workspace-container.sidebar-collapsed {
                margin-left: auto;
                margin-right: auto;
                width: min(100vw - 24px, 1040px);
                padding-left: 0;
            }
            .ls-subject-grid { grid-template-columns: 1fr; }
            .ls-class-card,
            .ls-side-card,
            .layout-main-feed,
            .layout-people,
            .layout-grades,
            .layout-work { padding: 18px; }
        }
        .app-bar-right > .theme-toggle { display: none; }
        .account-popover {
            width: min(340px, calc(100vw - 32px));
            padding: 14px;
            border-radius: 28px;
            background: rgba(255,255,255,.94);
            text-align: left;
        }
        .account-popover-close { top: 14px; right: 14px; background: #f4f6f8; }
        .account-email { padding: 10px 42px 4px 10px; color: var(--gc-text-secondary); font-size: 12px; }
        .account-avatar-large {
            width: 72px;
            height: 72px;
            margin: 12px auto 10px;
            border-radius: 22px;
            font-size: 28px;
            background: linear-gradient(135deg,#1a7a4a,#67c9b0);
        }
        .account-greeting { text-align: center; font-size: 19px; font-weight: 800; margin-bottom: 8px; }
        .account-role-pill {
            display: flex;
            width: max-content;
            margin: 0 auto 14px;
            min-height: 30px;
            padding: 0 14px;
            border: 0;
            background: #eef1f4;
            color: var(--gc-text-secondary);
            font-size: 12px;
        }
        .account-actions {
            display: grid;
            gap: 10px;
            background: transparent;
            border-radius: 0;
        }
        .account-action-link,
        .account-theme-toggle {
            min-height: 48px;
            border-radius: 16px;
            background: #f7f8fa;
            border: 1px solid rgba(88,101,124,.10);
            box-shadow: 0 8px 20px rgba(37,48,67,.05);
        }
        .account-theme-toggle {
            display: flex !important;
            align-items: center;
            justify-content: space-between;
            width: 100%;
            padding: 0 18px;
            color: var(--gc-text-primary);
            font-size: 15px;
            font-weight: 700;
        }
        .account-theme-toggle .theme-copy { display: inline-flex; align-items: center; gap: 12px; }
        .account-theme-toggle svg { width: 19px; height: 19px; stroke: currentColor; fill: none; stroke-width: 2; }
        .theme-switch {
            width: 48px;
            height: 28px;
            border-radius: 999px;
            background: rgba(88,101,124,.16);
            padding: 3px;
            display: inline-flex;
            align-items: center;
            flex-shrink: 0;
            transition: background-color .2s ease;
        }
        .theme-switch-knob {
            width: 22px;
            height: 22px;
            border-radius: 50%;
            background: #ffffff;
            box-shadow: 0 4px 10px rgba(15,23,42,.16);
            transform: translateX(0);
            transition: transform .2s ease;
        }
        [data-theme="dark"] .theme-switch { background: rgba(103,201,176,.38); }
        [data-theme="dark"] .theme-switch-knob { transform: translateX(20px); }
        .gc-detail-comments { display: none !important; }
        .gc-detail-layout:not(:has(.gc-detail-sidebar)) {
            display: flex;
            justify-content: center;
        }
        .gc-detail-layout:not(:has(.gc-detail-sidebar)) .gc-detail-main {
            width: min(520px, 100%);
        }
        [data-theme="dark"] .account-popover { background: rgba(28,34,45,.96); }
        [data-theme="dark"] .account-popover-close,
        [data-theme="dark"] .account-role-pill,
        [data-theme="dark"] .account-action-link,
        [data-theme="dark"] .account-theme-toggle { background: rgba(255,255,255,.08); border-color: rgba(125,139,164,.16); }
        [data-theme="dark"] .account-popover-close:hover,
        [data-theme="dark"] .account-action-link:hover,
        [data-theme="dark"] .account-theme-toggle:hover {
            background: rgba(255,255,255,.12);
        }
        [data-theme="dark"] .ls-add-task-btn:hover {
            background: rgba(255,255,255,.10);
            color: var(--gc-blue);
        }
        [data-theme="dark"] .gc-yw-done-btn.is-secondary {
            background: rgba(255,255,255,.08) !important;
            color: var(--gc-text-secondary) !important;
            border-color: rgba(125,139,164,.24) !important;
        }

    </style>
</head>
<body>

<div class="icon-sprite-container">
    <svg xmlns="http://www.w3.org/2000/svg">
        <symbol id="icon-menu" viewBox="0 0 24 24"><path fill="currentColor" d="M3 18h18v-2H3v2zm0-5h18v-2H3v2zm0-7v2h18V6H3z"/></symbol>
        <symbol id="icon-assignment" viewBox="0 0 24 24"><path fill="currentColor" d="M19 3h-4.18C14.4 1.84 13.3 1 12 1c-1.3 0-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 0c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1zm2 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z"/></symbol>
        <symbol id="icon-material" viewBox="0 0 24 24"><path fill="currentColor" d="M18 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zM9 4h2v5l-1-.75L9 9V4zm9 16H6V4h1v9l3-2.25L13 13V4h5v16z"/></symbol>
        <symbol id="icon-announcement" viewBox="0 0 24 24"><path fill="currentColor" d="M20 2H4c-1.1 0-1.99.9-1.99 2L2 22l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-7 9h-2V5h2v6zm0 4h-2v-2h2v2z"/></symbol>
        <symbol id="icon-more" viewBox="0 0 24 24"><path fill="currentColor" d="M12 8c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2zm0 2c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm0 6c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2z"/></symbol>
        <symbol id="icon-close" viewBox="0 0 24 24"><path fill="currentColor" d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></symbol>
        <symbol id="icon-upload" viewBox="0 0 24 24"><path fill="currentColor" d="M19.35 10.04C18.67 6.59 15.64 4 12 4 9.11 4 6.6 5.64 5.35 8.04 2.34 8.36 0 10.91 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96zM14 13v4h-4v-4H7l5-5 5 5h-3z"/></symbol>
        <symbol id="icon-send" viewBox="0 0 24 24"><path fill="currentColor" d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></symbol>
        <symbol id="icon-expand" viewBox="0 0 24 24"><path fill="currentColor" d="M16.59 8.59L12 13.17 7.41 8.59 6 10l6 6 6-6z"/></symbol>
        <symbol id="icon-check-circle" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></symbol>
        <symbol id="icon-error-circle" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></symbol>
        <symbol id="icon-info-circle" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></symbol>
        <symbol id="icon-settings" viewBox="0 0 24 24"><path fill="currentColor" d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.06-.94l2.03-1.58c.18-.14.23-.41.12-.61l-1.92-3.32c-.12-.22-.37-.29-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54c-.04-.24-.24-.41-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59-.24-1.13-.56-1.62-.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.49.12.61l2.03 1.58c-.05.3-.09.63-.09.94s.02.64.06.94l-2.03 1.58c-.18.14-.23.41-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .43-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.49-.12-.61l-2.01-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z"/></symbol>
        <symbol id="icon-copy" viewBox="0 0 24 24"><path fill="currentColor" d="M16 1H4c-1.1 0-2 .9-2 2v14h2V3h12V1zm3 4H8c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h11c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2zm0 16H8V7h11v14z"/></symbol>
        <symbol id="icon-delete" viewBox="0 0 24 24"><path fill="currentColor" d="M16 9v10H8V9h8m-1.5-6h-5l-1 1H5v2h14V4h-3.5l-1-1zM18 7H6v12c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7z"/></symbol>
        <symbol id="icon-add" viewBox="0 0 24 24"><path fill="currentColor" d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></symbol>
        <symbol id="icon-people" viewBox="0 0 24 24"><path fill="currentColor" d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></symbol>
        <symbol id="icon-bell" viewBox="0 0 24 24"><path fill="currentColor" d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6v-5c0-3.07-1.64-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.63 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z"/></symbol>
    </svg>
</div>

<!-- ── APP BAR ── -->
<nav class="app-bar" id="globalAppBar">
    <div class="app-bar-left">
        <button class="btn-icon-circular nav-burger" onclick="toggleSidebar()" aria-label="Toggle navigation">
            <svg><use href="#icon-menu"></use></svg>
        </button>
        <div class="brand-container">
            <img class="brand-logo-img brand-logo-light" src="img/Light Icon.png" alt="Helios University">
            <img class="brand-logo-img brand-logo-dark" src="img/Dark Icon.png" alt="Helios University">
            <div class="brand-title">Helios University<span><?= $role === 'faculty' ? 'Faculty Class Workspace' : 'Student Class Workspace' ?></span></div>
        </div>
    </div>
    
    <div class="app-bar-center">
        <a href="#class-stream" class="tab-link active">Board</a>
        <?php if ($role === 'faculty'): ?>
        <a href="#class-people" class="tab-link">People</a>
        <?php endif; ?>
        <a href="#class-work" class="tab-link"><?= $role === 'faculty' ? 'Grades' : 'Classwork' ?></a>
    </div>
    
    <div class="app-bar-right">
        <span class="topbar-clock" id="liveClock"></span>
        <button class="theme-toggle" id="themeToggle" aria-label="Toggle theme">
            <svg class="icon-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
            <svg class="icon-moon" viewBox="0 0 24 24"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
        </button>
        <button type="button" class="user-avatar-trigger" id="accountButton" title="Account" aria-haspopup="dialog" aria-expanded="false">
            <span class="user-avatar-circle">
                <?php if ($myAvatarUrl): ?>
                    <img src="<?= $myAvatarUrl ?>" alt="Profile Picture">
                <?php elseif ($role === 'faculty'): ?>
                    <svg viewBox="0 0 24 24" style="width:18px;height:18px;fill:#fff;"><path d="M18 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zM9 4h2v5l-1-.75L9 9V4zm9 16H6V4h1v9l3-2.25L13 13V4h5v16z"/></svg>
                <?php elseif ($role === 'student'): ?>
                    <svg viewBox="0 0 24 24" style="width:18px;height:18px;fill:#fff;"><path d="M12 3L1 9l11 6 9-4.91V17h2V9L12 3zM5 13.18v4L12 21l7-3.82v-4L12 17l-7-3.82z"/></svg>
                <?php else: ?>
                    <?= htmlspecialchars($myInitials) ?>
                <?php endif; ?>
            </span>
            <span class="user-avatar-meta">
                <span class="user-avatar-name"><?= htmlspecialchars($myDisplayName) ?></span>
                <span class="user-avatar-role"><?= ucfirst($role) ?></span>
            </span>
        </button>
    </div>
</nav>

<div class="account-popover" id="accountPopover" role="dialog" aria-label="Account overview">
    <button type="button" class="account-popover-close" id="accountPopoverClose" aria-label="Close account overview">&times;</button>
    <div class="account-email"><?= htmlspecialchars($username) ?></div>
    <div class="account-avatar-large">
        <?php if ($myAvatarUrl): ?>
            <img src="<?= $myAvatarUrl ?>" alt="Profile Picture">
        <?php else: ?>
            <?= htmlspecialchars($myInitials) ?>
        <?php endif; ?>
    </div>
    <div class="account-greeting">Hi, <?= htmlspecialchars($myDisplayName) ?>!</div>
    <div class="account-role-pill"><?= ucfirst($role) ?> Account</div>
    <div class="account-actions">
        <button type="button" class="account-theme-toggle theme-toggle" aria-label="Toggle dark mode">
            <span class="theme-copy">
                <svg class="icon-moon" viewBox="0 0 24 24"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
                <svg class="icon-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
                Dark mode
            </span>
            <span class="theme-switch" aria-hidden="true"><span class="theme-switch-knob"></span></span>
        </button>
        <a href="<?= htmlspecialchars($accountSettingsHref) ?>" class="account-action-link">
            <svg><use href="#icon-settings"></use></svg>
            Account settings
        </a>
        <a href="logout.php" class="account-action-link">
            <svg><use href="#icon-people"></use></svg>
            Sign out
        </a>
    </div>
</div>

<div class="app-shell">

<!-- ── PERSISTENT SIDEBAR ── -->
<nav class="class-sidebar" id="classSidebar">
    <ul class="sidebar-nav-list" style="list-style:none;padding:0;margin:0;">
        <li>
            <a href="dashboard.php" class="sidebar-nav-item">
                <svg viewBox="0 0 24 24"><path fill="currentColor" d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg>
                Dashboard
            </a>
        </li>

    </ul>

    <div class="sidebar-divider"></div>
    <div class="sidebar-section-label"><?= $role === 'faculty' ? 'Assigned Classes' : 'Enrolled' ?></div>

    <?php
    $allClassesForSidebar = SystemCore::load('classes');
    $sidebarClasses = [];
    foreach ($allClassesForSidebar['classes'] ?? [] as $sc) {
        $facultyAssigned = false;
        $studentSubjectEnrollment = false;
        foreach ($sc['subjects'] ?? [] as $subject) {
            if (($subject['faculty'] ?? '') === $username) $facultyAssigned = true;
            if (in_array($username, $subject['students'] ?? [], true)) $studentSubjectEnrollment = true;
        }
        if ($role === 'faculty' && $facultyAssigned) $sidebarClasses[] = $sc;
        elseif ($role === 'student' && (in_array($username, $sc['members'] ?? [], true) || $studentSubjectEnrollment)) $sidebarClasses[] = $sc;
    }
    foreach (array_slice($sidebarClasses, 0, 8) as $sc):
        $scInitial = strtoupper(substr($sc['subject'] ?? $sc['name'], 0, 1));
        $isCurrentClass = ($sc['id'] === $classId);
    ?>
    <a href="class.php?id=<?= urlencode($sc['id']) ?>" class="sidebar-class-item <?= $isCurrentClass ? 'active' : '' ?>">
        <div class="sidebar-class-avatar" style="<?= $isCurrentClass ? 'background:var(--class-theme-grad);color:#fff;' : '' ?>"><?= $scInitial ?></div>
        <div class="sidebar-class-info">
            <span class="sidebar-class-name"><?= htmlspecialchars($sc['name']) ?></span>
            <span class="sidebar-class-sub"><?= htmlspecialchars($sc['subject'] ?? '') ?></span>
        </div>
    </a>
    <?php endforeach; ?>

    <div class="sidebar-divider"></div>
    <div class="sidebar-section-label">Workspace</div>
    <ul class="sidebar-nav-list" style="list-style:none;padding:0;margin:0;">
        <li>
            <a href="<?= htmlspecialchars($accountSettingsHref) ?>" class="sidebar-nav-item">
                <svg viewBox="0 0 24 24"><use href="#icon-settings"></use></svg>
                Account Settings
            </a>
        </li>
    </ul>
</nav>

<main class="workspace-container" id="mainWorkspace">

    <!-- ═════════════════════════════════════════════════════════════ -->
    <!-- VIEWPORT: STREAM                                              -->
    <!-- ═════════════════════════════════════════════════════════════ -->
    <?php if ($activePost): ?>
        <?php
            $postType  = $activePost['type'] ?? 'announcement';
            $isAssign  = ($postType === 'assignment');
            $mySub     = ($role === 'student' && $isAssign && isset($activePost['submissions'][$username]))
                         ? $activePost['submissions'][$username] : null;
            $isLate    = $isAssign && !empty($activePost['deadline']) && strtotime($activePost['deadline']) < time();
            $postTheme = ViewRenderer::getPostTheme($postType);
            $activePostFacultyName = $getPostFacultyName($activePost);
            $activePostSubjectName = $getSubjectName($activePost);
        ?>
        <div class="gc-detail-wrap">

            <!-- Back breadcrumb -->
            <a href="?id=<?= urlencode($classId) ?>#class-stream" class="gc-detail-back">
                <svg viewBox="0 0 24 24" style="width:20px;height:20px;fill:currentColor;"><path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z"/></svg>
                Board
            </a>

            <div class="gc-detail-layout">

                <!-- LEFT: Assignment info -->
                <div class="gc-detail-main">
                    <div class="gc-detail-header">
                        <div class="gc-detail-icon" style="background:<?= $postTheme['color'] ?>;">
                            <svg style="width:28px;height:28px;fill:#fff;" viewBox="0 0 24 24"><use href="#<?= $postTheme['icon'] ?>"></use></svg>
                        </div>
                        <div class="gc-detail-title-group">
                            <h1 class="gc-detail-title"><?= htmlspecialchars($activePost['title'] ?? 'Untitled') ?></h1>
                            <div class="gc-detail-meta">
                                <?= htmlspecialchars($activePostFacultyName) ?> &bull; <?= htmlspecialchars($activePostSubjectName) ?> &bull; <?= date('M j', strtotime($activePost['posted_at'] ?? 'now')) ?>
                            </div>
                            <div class="gc-detail-points-row">
                                <?php if ($isAssign): ?>
                                    <span><?= htmlspecialchars((string)($activePost['points'] ?? 100)) ?> points</span>
                                    <?php if (!empty($activePost['deadline'])): ?>
                                        <span class="gc-detail-due">Due <?= date('M j, g:i A', strtotime($activePost['deadline'])) ?></span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if ($role === 'faculty'): ?>
                        <div class="post-dropdown" style="margin-left:auto;">
                            <button class="btn-icon-standard" onclick="toggleDropdown('detail-dropdown')"><svg><use href="#icon-more"></use></svg></button>
                            <div class="post-dropdown-menu" id="detail-dropdown">
                                <form method="POST" onsubmit="return confirm('Delete this post permanently?');">
                                    <input type="hidden" name="action" value="delete_post">
                                    <input type="hidden" name="post_id" value="<?= htmlspecialchars($activePost['id']) ?>">
                                    <button type="submit" class="dropdown-item danger"><svg><use href="#icon-delete"></use></svg> Delete</button>
                                </form>
                                <button type="button" class="dropdown-item" onclick='event.stopPropagation(); openEditPostDialog(<?= json_encode($activePost, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'><svg><use href="#icon-edit"></use></svg> Edit</button>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <hr class="gc-detail-divider">

                    <?php if (!empty($activePost['body'])): ?>
                        <div class="gc-detail-body"><?= nl2br(htmlspecialchars($activePost['body'])) ?></div>
                    <?php endif; ?>

                    <?php if (!empty($activePost['file'])): 
                        $fd = $activePost['file']; $fe = $fd['ext'] ?? 'file'; ?>
                        <div class="gc-detail-attachment">
                            <a href="<?= htmlspecialchars($fd['path']) ?>" class="attachment-item" download target="_blank" rel="noopener noreferrer">
                                <div class="attachment-icon-box"><svg><?= ViewRenderer::getFileIcon($fe) ?></svg></div>
                                <div class="attachment-info">
                                    <h4 class="attachment-filename"><?= htmlspecialchars($fd['name']) ?></h4>
                                    <span class="attachment-filetype"><?= htmlspecialchars(strtoupper($fe)) ?></span>
                                </div>
                            </a>
                        </div>
                    <?php endif; ?>

                    <?php if ($role === 'faculty' && $isAssign):
                        $subs = $activePost['submissions'] ?? [];
                        $turnedIn = count($subs);
                        $assigned = max(0, count($enrolledMembers) - $turnedIn);
                    ?>
                        <div class="gc-detail-stats">
                            <div class="stat-block" onclick="openSubmissionsDialog('<?= htmlspecialchars($activePost['id']) ?>')" role="button" tabindex="0">
                                <span class="stat-num"><?= $turnedIn ?></span><span class="stat-lbl">Turned in</span>
                            </div>
                            <div class="stat-block" onclick="openSubmissionsDialog('<?= htmlspecialchars($activePost['id']) ?>')" role="button" tabindex="0">
                                <span class="stat-num"><?= $assigned ?></span><span class="stat-lbl">Assigned</span>
                            </div>
                            <button class="btn-contained" style="margin-left:auto;" onclick="openSubmissionsDialog('<?= htmlspecialchars($activePost['id']) ?>')">Review Submissions</button>
                        </div>
                    <?php endif; ?>

                </div>

                <!-- RIGHT: Your work panel (student only) -->
                <?php if ($role === 'student' && $isAssign): ?>
                <aside class="gc-detail-sidebar">
                    <div class="gc-your-work-panel">
                        <div class="gc-yw-header">
                            <span class="gc-yw-title">Your work</span>
                            <?php if ($mySub): ?>
                                <?php if (isset($mySub['score']) && $mySub['score'] !== null && $mySub['score'] !== ''): ?>
                                    <span class="gc-yw-status graded">Graded</span>
                                <?php else: ?>
                                    <span class="gc-yw-status turned-in">Turned in</span>
                                <?php endif; ?>
                            <?php elseif ($isLate): ?>
                                <span class="gc-yw-status missing">Missing</span>
                            <?php else: ?>
                                <span class="gc-yw-status assigned">Assigned</span>
                            <?php endif; ?>
                        </div>

                        <?php if ($mySub && isset($mySub['score']) && $mySub['score'] !== null && $mySub['score'] !== ''): ?>
                            <div class="gc-yw-grade"><?= htmlspecialchars((string)$mySub['score']) ?> / <?= htmlspecialchars((string)($activePost['points'] ?? 100)) ?></div>
                        <?php endif; ?>

                        <?php if ($mySub && !empty($mySub['score_note'])): ?>
                            <div class="gc-yw-feedback">
                                <span class="gc-yw-feedback-label">Feedback from teacher</span>
                                <p class="gc-yw-feedback-text"><?= htmlspecialchars($mySub['score_note']) ?></p>
                            </div>
                        <?php endif; ?>

                        <?php if ($mySub && !empty($mySub['file'])): ?>
                            <div class="gc-yw-file">
                                <a href="<?= htmlspecialchars($mySub['file']['path']) ?>" class="attachment-item" style="max-width:100%;" download target="_blank">
                                    <div class="attachment-icon-box" style="width:32px;height:32px;"><svg><?= ViewRenderer::getFileIcon($mySub['file']['ext'] ?? 'file') ?></svg></div>
                                    <div class="attachment-info"><h4 class="attachment-filename"><?= htmlspecialchars($mySub['file']['name']) ?></h4></div>
                                </a>
                            </div>
                        <?php endif; ?>

                        <?php if (!$mySub): ?>
                            <button class="gc-yw-add-btn" onclick="openStudentSubmitDialog('<?= htmlspecialchars($activePost['id']) ?>', '<?= htmlspecialchars(addslashes($activePost['title'] ?? '')) ?>')">
                                <svg viewBox="0 0 24 24" style="width:18px;height:18px;fill:currentColor;"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
                                Add or create
                            </button>
                            <?php if ($isLate): ?>
                                <p class="gc-yw-late-note">Work cannot be turned in after the due date</p>
                            <?php else: ?>
                                <button class="gc-yw-done-btn" onclick="openStudentSubmitDialog('<?= htmlspecialchars($activePost['id']) ?>', '<?= htmlspecialchars(addslashes($activePost['title'] ?? '')) ?>')">Mark as done</button>
                            <?php endif; ?>
                        <?php else: ?>
                            <?php if (empty($mySub['score'])): ?>
                                <form method="POST" onsubmit="return confirm('Unsubmit your work?');">
                                    <input type="hidden" name="action" value="unsubmit_assignment">
                                    <input type="hidden" name="post_id" value="<?= htmlspecialchars($activePost['id']) ?>">
                                    <input type="hidden" name="_redirect_post" value="<?= htmlspecialchars($activePostId) ?>">
                                    <button type="submit" class="gc-yw-done-btn is-secondary">Unsubmit</button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>

                        </form>
                    </div>
                </aside>
                <?php endif; ?>

            </div><!-- /.gc-detail-layout -->
        </div><!-- /.gc-detail-wrap -->

    <?php else: // Stream list view ?>

        <section class="ls-class-hero" id="class-stream" aria-label="Class overview">
            <div class="ls-class-card">
                <h1 class="ls-class-title"><?= htmlspecialchars($currentClass['name']) ?></h1>
                <p class="ls-class-subtitle"><?= htmlspecialchars($currentClass['description'] ?? 'Track subjects, assignments, class activity, and student progress in one workspace.') ?></p>
                <div class="ls-stats-row">
                    <div class="ls-stat">
                        <span class="ls-stat-icon"><svg><use href="#icon-material"></use></svg></span>
                        <span class="ls-stat-value"><?= count($visibleClassSubjects) ?></span>
                        <span class="ls-stat-label">Subjects</span>
                    </div>
                    <div class="ls-stat">
                        <span class="ls-stat-icon"><svg><use href="#icon-assignment"></use></svg></span>
                        <span class="ls-stat-value"><?= $dueTasksCount ?></span>
                        <span class="ls-stat-label">Due Tasks</span>
                    </div>
                    <div class="ls-stat">
                        <span class="ls-stat-icon"><svg><use href="#icon-error-circle"></use></svg></span>
                        <span class="ls-stat-value"><?= $missingTasksCount ?></span>
                        <span class="ls-stat-label">Missing Tasks</span>
                    </div>
                    <div class="ls-stat">
                        <span class="ls-stat-icon"><svg><use href="#icon-people"></use></svg></span>
                        <span class="ls-stat-value"><?= count($enrolledMembers) ?></span>
                        <span class="ls-stat-label">Students</span>
                    </div>
                </div>
            </div>
            <aside class="ls-side-card">
                <h2>Due Soon</h2>
                <div class="ls-due-list">
                    <?php
                    $lsUpcoming = array_values(array_filter($assignmentPosts, fn($task) => !empty($task['deadline']) && strtotime($task['deadline']) >= time()));
                    usort($lsUpcoming, fn($a, $b) => strtotime($a['deadline']) <=> strtotime($b['deadline']));
                    ?>
                    <?php if (empty($lsUpcoming)): ?>
                        <p class="upcoming-empty-state">No upcoming work for this class.</p>
                    <?php else: ?>
                        <?php foreach (array_slice($lsUpcoming, 0, 3) as $task): ?>
                        <a class="ls-due-item" href="?id=<?= urlencode($classId) ?>&post=<?= urlencode($task['id']) ?>">
                            <span class="ls-due-icon"><svg><use href="#icon-assignment"></use></svg></span>
                            <span>
                                <span class="ls-due-title"><?= htmlspecialchars($task['title'] ?? 'Untitled task') ?></span>
                                <span class="ls-due-meta"><?= htmlspecialchars($getSubjectName($task)) ?> &bull; <?= htmlspecialchars(date('M j, g:i A', strtotime($task['deadline']))) ?></span>
                            </span>
                        </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </aside>
        </section>

        <section class="ls-subjects" aria-label="Class subjects">
            <div class="ls-section-head">
                <h2>My Courses</h2>
                <span><?= count($visibleClassSubjects) ?> assigned subjects</span>
            </div>
            <div class="ls-subject-grid">
                <?php if (empty($visibleClassSubjects)): ?>
                    <div class="empty-illustration"><?= $role === 'student' ? 'You are not enrolled in any subjects for this class yet.' : 'No subjects have been assigned to this class yet.' ?></div>
                <?php else: ?>
                    <?php foreach ($visibleClassSubjects as $idx => $subject):
                        $subjectStudents = $subject['students'] ?? [];
                        $subjectProgress = min(100, 35 + (($idx * 23) % 66));
                    ?>
                    <?php
                        $rawName   = $subject['name'] ?? 'Untitled subject';
                        $cleanName = trim(preg_replace('/\s*\(.*\)\s*$/', '', $rawName)) ?: $rawName;
                        $subjectFacultyUser = $subject['faculty'] ?? '';
                        $subjectFacultyProfile = $usersDictionary[$subjectFacultyUser] ?? [];
                        $subjectFacultyName = ($subjectFacultyProfile['display_name'] ?? $subjectFacultyProfile['fullname'] ?? $subjectFacultyUser) ?: 'No faculty assigned';
                        $subjectHref = '?id=' . urlencode($classId) . '&subject=' . urlencode($subject['id'] ?? '') . '#class-work';
                    ?>
                    <<?= $role === 'student' ? 'a' : 'article' ?> class="ls-subject-card" <?= $role === 'student' ? 'href="' . htmlspecialchars($subjectHref, ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
                        <div>
                            <div class="ls-subject-icon">
                                <svg viewBox="0 0 24 24"><path d="M12 3L1 9l11 6 9-4.91V17h2V9L12 3zM5 13.18v4L12 21l7-3.82v-4L12 17l-7-3.82z"/></svg>
                            </div>
                            <div class="ls-subject-name"><?= htmlspecialchars($cleanName) ?></div>
                            <div class="ls-subject-meta"><?= count($subjectStudents) ?> enrolled students</div>
                            <div class="ls-subject-meta">Faculty: <?= htmlspecialchars($subjectFacultyName) ?></div>
                        </div>
                        <div class="ls-subject-footer">
                            <span class="ls-subject-footer-label">Subject</span>
                            <span class="ls-subject-footer-badge">
                                <svg viewBox="0 0 24 24"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>
                                <?= count($subjectStudents) ?>
                            </span>
                        </div>
                    </<?= $role === 'student' ? 'a' : 'article' ?>>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>

        <header class="hero-banner">
            <div class="hero-content">
                <h1 class="hero-title"><?= htmlspecialchars($currentClass['name']) ?></h1>
                <h2 class="hero-subtitle"><?= htmlspecialchars($currentClass['subject'] ?? '') ?></h2>
            </div>
            <?php if ($role === 'student'): ?>
            <div class="hero-actions-container">
                <div class="hero-badge-pill">Prof. <?= htmlspecialchars($facultyName) ?></div>
            </div>
            <?php endif; ?>
            <?php if ($role === 'faculty'): ?>
            <button class="hero-edit-btn" onclick="openDialog('dialogBanner')" title="Customize banner">
                <svg viewBox="0 0 24 24"><path fill="currentColor" d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34a1 1 0 0 0-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
            </button>
            <?php endif; ?>
        </header>

        <div class="layout-stream">
            <aside class="layout-sidebar">
                <div class="widget-panel">
                    <h3 class="widget-header">Upcoming</h3>
                    <?php
                    $now2 = time();
                    $upcomingTasks = [];
                    foreach ($assignmentPosts as $task) {
                        if (!empty($task['deadline'])) {
                            $dl = strtotime($task['deadline']);
                            if ($dl > $now2) {
                                if ($role === 'student' && isset($task['submissions'][$username])) continue;
                                $upcomingTasks[] = $task;
                            }
                        }
                    }
                    usort($upcomingTasks, fn($a, $b) => strtotime($a['deadline']) - strtotime($b['deadline']));
                    $displayTasks = array_slice($upcomingTasks, 0, 3);
                    ?>
                    <?php if (empty($displayTasks)): ?>
                        <p class="upcoming-empty-state">No tasks detected. Rest easy!</p>
                    <?php else: ?>
                        <div class="upcoming-task-list">
                            <?php foreach ($displayTasks as $task): ?>
                                <a class="upcoming-task-item" href="?id=<?= urlencode($classId) ?>&post=<?= urlencode($task['id']) ?>" style="display:flex;flex-direction:column;gap:2px;text-decoration:none;color:inherit;padding:6px 8px;border-radius:var(--gc-radius-sm);margin:-6px -8px;transition:background-color var(--transition-fast);" onmouseover="this.style.backgroundColor='var(--gc-bg-hover)'" onmouseout="this.style.backgroundColor=''">
                                    <span class="upcoming-task-title" title="<?= htmlspecialchars($task['title']) ?>"><?= htmlspecialchars($task['title']) ?></span>
                                    <span class="upcoming-task-due"><?= htmlspecialchars($getSubjectName($task)) ?> &bull; Due <?= date('l, g:i A', strtotime($task['deadline'])) ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <a href="#class-work" class="btn-text-action" style="padding-left:0;padding-right:0;">View all</a>
                </div>
            </aside>

            <section class="layout-feed">
                <div class="ls-task-board-head">
                    <h2 class="ls-task-board-title">
                        <svg><use href="#icon-expand"></use></svg>
                        In Progress <span class="ls-task-count"><?= count($classPosts) ?></span>
                    </h2>
                    <?php if ($role === 'faculty'): ?>
                    <button type="button" class="ls-add-task-btn" onclick="openCreatePostDialog('assignment')">
                        <svg><use href="#icon-add"></use></svg>
                        Add task
                    </button>
                    <?php endif; ?>
                </div>

                <?php if (empty($classPosts)): ?>
                    <div class="empty-illustration">
                        <svg viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-5 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z"/></svg>
                        <h3>This is where you can talk to your class</h3>
                        <p>Use the stream to share announcements, assignments, and questions with your class.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($classPosts as $post):
                        $postType  = $post['type'] ?? 'announcement';
                        $postTheme = ViewRenderer::getPostTheme($postType);
                        $isAssign  = ($postType === 'assignment');
                        $mySub     = ($role === 'student' && $isAssign && isset($post['submissions'][$username])) ? $post['submissions'][$username] : null;
                        $postUrl   = '?id=' . urlencode($classId) . '&post=' . urlencode($post['id']);
                        $postFacultyName = $getPostFacultyName($post);
                        $postSubjectName = $getSubjectName($post);
                    ?>
                        <article class="feed-post-card stream-card-clickable" id="post-<?= htmlspecialchars($post['id']) ?>" onclick="window.location.href='<?= $postUrl ?>'" style="cursor:pointer;">
                            <div class="post-indicator" style="background-color:<?= $postTheme['color'] ?>;"></div>
                            <div class="post-header" style="cursor:pointer;">
                                <div class="post-author-block">
                                    <div class="post-type-icon" style="background-color:<?= $postTheme['color'] ?>;">
                                        <svg style="width:24px;height:24px;fill:currentColor;"><use href="#<?= $postTheme['icon'] ?>"></use></svg>
                                    </div>
                                    <div class="post-metadata">
                                        <h3 class="post-metadata-title">
                                            <?= htmlspecialchars($post['title'] ?? 'Untitled') ?>
                                        </h3>
                                        <span class="post-metadata-time">
                                            <?= htmlspecialchars($postTheme['label']) ?> &bull; <?= htmlspecialchars($postSubjectName) ?> &bull; <?= htmlspecialchars($postFacultyName) ?> &bull; <?= ViewRenderer::formatRelativeTime($post['posted_at'] ?? '') ?>
                                            <?php if (!empty($post['deadline'])): ?>
                                                &bull; Due <?= date('M j, g:i A', strtotime($post['deadline'])) ?>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                </div>
                                <div style="display:flex;align-items:center;gap:8px;flex-shrink:0;">
                                    <?php if ($role === 'student' && $isAssign): ?>
                                        <?php if ($mySub && isset($mySub['score']) && $mySub['score'] !== ''): ?>
                                            <span class="status-indicator status-graded" style="font-size:12px;padding:4px 10px;">Graded</span>
                                        <?php elseif ($mySub): ?>
                                            <span class="status-indicator status-done" style="font-size:12px;padding:4px 10px;">Turned in</span>
                                        <?php elseif (!empty($post['deadline']) && strtotime($post['deadline']) < time()): ?>
                                            <span class="status-indicator status-missing" style="font-size:12px;padding:4px 10px;">Missing</span>
                                        <?php else: ?>
                                            <span style="font-size:12px;color:var(--gc-text-secondary);font-weight:500;">Assigned</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php if ($role === 'faculty'): ?>
                                    <div class="post-dropdown" onclick="event.stopPropagation();">
                                        <button class="btn-icon-standard" aria-label="Options" onclick="toggleDropdown('dropdown-<?= htmlspecialchars($post['id']) ?>')">
                                            <svg><use href="#icon-more"></use></svg>
                                        </button>
                                        <div class="post-dropdown-menu" id="dropdown-<?= htmlspecialchars($post['id']) ?>">
                                            <form method="POST" onsubmit="return confirm('Delete this post permanently?');">
                                                <input type="hidden" name="action" value="delete_post">
                                                <input type="hidden" name="post_id" value="<?= htmlspecialchars($post['id']) ?>">
                                                <button type="submit" class="dropdown-item danger"><svg><use href="#icon-delete"></use></svg> Delete</button>
                                            </form>
                                            <button type="button" class="dropdown-item" onclick='event.stopPropagation(); openEditPostDialog(<?= json_encode($post, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'><svg><use href="#icon-edit"></use></svg> Edit</button>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if (!empty($post['body'])): ?>
                                <div class="post-content-body" style="border-bottom:none;">
                                    <div class="post-text-content" style="color:var(--gc-text-secondary);font-size:13px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;"><?= htmlspecialchars($post['body']) ?></div>
                                </div>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </section>
        </div>

    <?php endif; // end activePost vs stream list ?>

    <!-- ═════════════════════════════════════════════════════════════ -->
    <!-- VIEWPORT: PEOPLE (FACULTY ONLY)                               -->
    <!-- ═════════════════════════════════════════════════════════════ -->
    <?php if (!$activePost && $role === 'faculty'): ?>
        
        <div class="layout-people" id="class-people">
            <section class="roster-section">
                <header class="roster-header">
                    <h2 class="roster-title">Teachers</h2>
                    <span class="roster-count"><?= count($assignedTeachers) ?> <?= count($assignedTeachers) === 1 ? 'teacher' : 'teachers' ?></span>
                </header>
                <?php if (empty($assignedTeachers)): ?>
                    <p style="text-align: center; color: var(--gc-text-secondary); padding: 24px;">No faculty users are assigned to subjects in this class yet.</p>
                <?php else: ?>
                    <div class="roster-list">
                        <?php foreach ($assignedTeachers as $teacher): ?>
                        <div class="person-item">
                            <div class="person-info-group">
                                <div class="person-avatar" style="background-color: var(--gc-blue); color: white;">
                                    <?= htmlspecialchars($teacher['initials']) ?>
                                </div>
                                <div>
                                    <span class="person-name"><?= htmlspecialchars($teacher['name']) ?></span>
                                    <div style="font-size:12px;color:var(--gc-text-secondary);margin-top:2px;">
                                        <?= htmlspecialchars(implode(', ', array_map(fn($subject) => $subject['name'] ?? 'Untitled subject', $teacher['subjects'] ?? []))) ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="roster-section">
                <header class="roster-header">
                    <h2 class="roster-title">Students</h2>
                    <span class="roster-count"><?= count($enrolledMembers) ?> <?= count($enrolledMembers) === 1 ? 'student' : 'students' ?></span>
                </header>
                
                <?php if (empty($enrolledMembers)): ?>
                    <p style="text-align: center; color: var(--gc-text-secondary); padding: 24px;">No students are enrolled in this class yet.</p>
                <?php else: ?>
                    <div class="roster-list">
                        <?php 
                        $sortedMembers = $enrolledMembers;
                        usort($sortedMembers, function($a, $b) use ($usersDictionary) {
                            $nameA = $usersDictionary[$a]['fullname'] ?? $a;
                            $nameB = $usersDictionary[$b]['fullname'] ?? $b;
                            return strcasecmp($nameA, $nameB);
                        });
                        
                        foreach ($sortedMembers as $peerUsername): 
                            $pData = $usersDictionary[$peerUsername] ?? [];
                            $pName = $pData['fullname'] ?? $peerUsername;
                            $pInit = strtoupper(substr($pName, 0, 1));
                        ?>
                            <div class="person-item">
                                <div class="person-info-group">
                                    <div class="person-avatar">
                                        <?= htmlspecialchars($pInit) ?>
                                    </div>
                                    <span class="person-name"><?= htmlspecialchars($pName) ?></span>
                                </div>
                                <div class="person-actions">
                                    <form method="POST" onsubmit="return confirm('Permanently remove <?= htmlspecialchars($pName) ?> from the roster?');">
                                        <input type="hidden" name="action" value="remove_student">
                                        <input type="hidden" name="target_user" value="<?= htmlspecialchars($peerUsername) ?>">
                                        <button type="submit" class="btn-icon-danger" aria-label="Remove student" title="Remove student">
                                            <svg><use href="#icon-close"></use></svg>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>

    <!-- ═════════════════════════════════════════════════════════════ -->
    <!-- VIEWPORT: GRADES / MY WORK                                    -->
    <!-- ═════════════════════════════════════════════════════════════ -->
    <?php endif; ?>

    <?php if (!$activePost): ?>
        
        <?php if ($role === 'faculty'): ?>
            <div class="layout-grades" id="class-work">
                <?php if (empty($assignmentPosts)): ?>
                    <div class="empty-illustration">
                        <svg viewBox="0 0 24 24"><path d="M19 3h-4.18C14.4 1.84 13.3 1 12 1c-1.3 0-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 0c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1zm2 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z"/></svg>
                        <h3>No grades to display</h3>
                        <p>Create assignments in the Stream to begin tracking performance.</p>
                    </div>
                <?php elseif (empty($enrolledMembers)): ?>
                    <div class="empty-illustration">
                        <svg><use href="#icon-people"></use></svg>
                        <h3>No students</h3>
                        <p>Your gradebook will populate once students enroll.</p>
                    </div>
                <?php else: ?>
                    <div class="grades-table-wrapper">
                        <table class="grades-table">
                            <thead>
                                <tr>
                                    <th class="th-student">Students</th>
                                    <?php 
                                    $orderedAssignments = array_reverse($assignmentPosts); 
                                    foreach ($orderedAssignments as $task): 
                                    ?>
                                        <th class="th-assignment">
                                            <div class="th-assignment-title" title="<?= htmlspecialchars($task['title']) ?>">
                                                <?= htmlspecialchars($task['title']) ?>
                                            </div>
                                            <div class="th-assignment-meta">out of <?= htmlspecialchars((string)($task['points'] ?? '100')) ?></div>
                                        </th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $sortedMembers = $enrolledMembers;
                                usort($sortedMembers, function($a, $b) use ($usersDictionary) {
                                    $nameA = $usersDictionary[$a]['fullname'] ?? $a;
                                    $nameB = $usersDictionary[$b]['fullname'] ?? $b;
                                    return strcasecmp($nameA, $nameB);
                                });
                                
                                foreach ($sortedMembers as $studentUsername): 
                                    $stuData = $usersDictionary[$studentUsername] ?? [];
                                    $stuName = $stuData['fullname'] ?? $studentUsername;
                                    $stuInit = strtoupper(substr($stuName, 0, 1));
                                ?>
                                    <tr>
                                        <td class="th-student">
                                            <div style="display:flex; align-items:center; gap:12px;">
                                                <div style="width:32px;height:32px;border-radius:50%;background-color:var(--gc-bg-hover);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:500;color:var(--gc-text-secondary);flex-shrink:0;">
                                                    <?= htmlspecialchars($stuInit) ?>
                                                </div>
                                                <div style="font-weight:500; font-size:14px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                                    <?= htmlspecialchars($stuName) ?>
                                                </div>
                                            </div>
                                        </td>
                                        
                                        <?php foreach ($orderedAssignments as $task): 
                                            $sub   = $task['submissions'][$studentUsername] ?? null;
                                            $score = $sub['score'] ?? '';
                                            $isMissing = (!$sub && !empty($task['deadline']) && strtotime($task['deadline']) < time());
                                        ?>
                                            <td>
                                                <div class="grade-cell-content">
                                                    <form method="POST" style="margin:0;">
                                                        <input type="hidden" name="action" value="score_submission">
                                                        <input type="hidden" name="post_id" value="<?= htmlspecialchars($task['id']) ?>">
                                                        <input type="hidden" name="target_user" value="<?= htmlspecialchars($studentUsername) ?>">
                                                        
                                                        <div class="grade-input-wrapper">
                                                            <input type="number" name="score" class="grade-input-field" value="<?= htmlspecialchars((string)$score) ?>" placeholder="___" min="0" max="<?= htmlspecialchars((string)($task['points'] ?? '100')) ?>" step="0.5" onchange="var m=parseFloat(this.max),v=parseFloat(this.value);if(!isNaN(v)&&v>m)this.value=m;this.form.submit()">
                                                            <span class="grade-denominator">/<?= htmlspecialchars((string)($task['points'] ?? '100')) ?></span>
                                                        </div>
                                                    </form>
                                                    
                                                    <?php if ($isMissing && $score === ''): ?>
                                                        <span class="status-badge missing">Missing</span>
                                                    <?php elseif ($sub && $score === ''): ?>
                                                        <span class="status-badge turned-in">Turned In</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            
        <?php else: // Student Classwork View ?>
            
            <div class="layout-work" id="class-work">
                <?php if ($selectedSubject): ?>
                    <div style="margin-bottom:20px;">
                        <h2 style="font-size:20px;color:var(--gc-text-primary);margin-bottom:4px;"><?= htmlspecialchars($selectedSubject['name'] ?? 'Subject tasks') ?></h2>
                        <p style="font-size:13px;color:var(--gc-text-secondary);">Tasks assigned for this subject.</p>
                    </div>
                <?php endif; ?>
                <?php 
                $workGroups = ['Assigned' => [], 'Turned in' => [], 'Returned' => [], 'Missing' => []];
                $currentTime = time();
                
                foreach (array_reverse($assignmentPosts) as $a) {
                    $s = $a['submissions'][$username] ?? null;
                    $deadline = !empty($a['deadline']) ? strtotime($a['deadline']) : null;
                    $isPastDue = $deadline && $deadline < $currentTime;
                    
                    if ($s && isset($s['score']) && $s['score'] !== null && $s['score'] !== '') {
                        $workGroups['Returned'][] = ['a' => $a, 's' => $s];
                    } elseif ($s) {
                        $workGroups['Turned in'][] = ['a' => $a, 's' => $s];
                    } elseif ($isPastDue) {
                        $workGroups['Missing'][] = ['a' => $a];
                    } else {
                        $workGroups['Assigned'][] = ['a' => $a, 's' => null];
                    }
                }
                ?>
                
                <?php if (empty($assignmentPosts)): ?>
                    <div class="empty-illustration">
                        <svg viewBox="0 0 24 24"><path d="M19 3h-4.18C14.4 1.84 13.3 1 12 1c-1.3 0-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 0c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1zm2 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z"/></svg>
                        <h3>No assignments yet</h3>
                        <p>Your teacher hasn't posted any assignments.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($workGroups as $groupTitle => $items): if (empty($items)) continue; ?>
                        <section class="cw-group">
                            <header class="cw-group-header"><?= $groupTitle ?></header>
                            <?php foreach ($items as $item): 
                                $a = $item['a'];
                                $s = $item['s'] ?? null;
                                $deadline = !empty($a['deadline']) ? strtotime($a['deadline']) : null;
                            ?>
                                <a href="?id=<?= urlencode($classId) ?>&post=<?= htmlspecialchars($a['id']) ?>" class="cw-row">
                                    <div class="cw-row-icon">
                                        <svg viewBox="0 0 24 24"><use href="#icon-assignment"></use></svg>
                                    </div>
                                    <div class="cw-row-main">
                                        <div class="cw-row-title"><?= htmlspecialchars($a['title'] ?? 'Untitled') ?></div>
                                        <div class="cw-row-meta">
                                            <?= htmlspecialchars($getSubjectName($a)) ?> &bull; <?= htmlspecialchars($getPostFacultyName($a)) ?>
                                            <?php if ($deadline): ?>
                                                &bull; Due <?= date('M j, g:i A', $deadline) ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="cw-row-status">
                                        <?php if ($s && isset($s['score']) && $s['score'] !== null && $s['score'] !== ''): ?>
                                            <span class="cw-badge cw-badge-returned"><?= htmlspecialchars((string)$s['score']) ?>/<?= htmlspecialchars((string)($a['points'] ?? 100)) ?></span>
                                        <?php elseif ($groupTitle === 'Turned in'): ?>
                                            <span class="cw-badge cw-badge-submitted">Turned in</span>
                                        <?php elseif ($groupTitle === 'Missing'): ?>
                                            <span class="cw-badge cw-badge-missing">Missing</span>
                                        <?php else: ?>
                                            <span class="cw-badge cw-badge-assigned">Assigned</span>
                                        <?php endif; ?>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </section>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    <?php endif; ?>

</main>

</div><!-- /.app-shell -->

<!-- ═════════════════════════════════════════════════════════════ -->
<!-- DIALOGS & MODALS ARCHITECTURE                                 -->
<!-- ═════════════════════════════════════════════════════════════ -->

<?php if ($role === 'faculty'): ?>
    <!-- Modal: Faculty Post Creator -->
    <div class="modal-backdrop" id="dialogCreatePost">
        <div class="dialog-surface">
            <header class="dialog-header">
                <h2 class="dialog-title">Create Post</h2>
                <button class="btn-dialog-close" onclick="closeModal('dialogCreatePost')"><svg><use href="#icon-close"></use></svg></button>
            </header>
            
            <form method="POST" enctype="multipart/form-data" id="createPostForm" style="display:flex; flex-direction:column; flex:1; min-height:0;">
                <input type="hidden" name="action" value="create_post">
                <input type="hidden" name="post_type" id="inputPostType" value="announcement">
                
                <div class="dialog-content">
                    <div class="type-selector-tabs">
                        <button type="button" class="type-tab active" data-type="announcement" onclick="updatePostTypeContext('announcement', this)">
                            <svg><use href="#icon-announcement"></use></svg> Announcement
                        </button>
                        <button type="button" class="type-tab" data-type="assignment" onclick="updatePostTypeContext('assignment', this)">
                            <svg><use href="#icon-assignment"></use></svg> Assignment
                        </button>
                        <button type="button" class="type-tab" data-type="material" onclick="updatePostTypeContext('material', this)">
                            <svg><use href="#icon-material"></use></svg> Material
                        </button>
                    </div>
                    
                    <div class="static-input-wrap" style="margin-bottom:16px;">
                        <label class="static-input-label">Subject</label>
                        <select class="static-input-field" name="subject_id" required>
                            <?php foreach ($visibleClassSubjects as $subject): ?>
                                <option value="<?= htmlspecialchars($subject['id'] ?? '') ?>"><?= htmlspecialchars($subject['name'] ?? 'Untitled subject') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="md-input-container">
                        <input type="text" class="md-input-field" name="title" id="inputPostTitle" placeholder=" ">
                        <label class="md-input-label" for="inputPostTitle">Title</label>
                    </div>
                    
                    <div class="md-input-container">
                        <textarea class="md-input-field md-textarea" name="body" id="inputPostBody" placeholder=" "></textarea>
                        <label class="md-input-label" for="inputPostBody">Description (optional)</label>
                    </div>

                    <div id="assignmentExtraFields" style="display: none;">
                        <div class="static-grid-row">
                            <div class="static-input-wrap">
                                <label class="static-input-label">Points</label>
                                <input type="number" class="static-input-field" name="points" value="100" min="0">
                            </div>
                            <div class="static-input-wrap">
                                <label class="static-input-label">Due Date</label>
                                <input type="datetime-local" class="static-input-field" name="deadline">
                            </div>
                        </div>
                    </div>

                    <label class="upload-dropzone" for="inputPostFile" tabindex="0" role="button">
                        <svg><use href="#icon-upload"></use></svg>
                        <span class="upload-dropzone-text">Browse files to attach</span>
                        <input type="file" name="post_file" id="inputPostFile" style="display:none;" onchange="validateFileState(this, 'postFileDisplay')">
                        <div class="upload-file-display" id="postFileDisplay">No file selected</div>
                    </label>
                </div>
                
                <footer class="dialog-actions">
                    <button type="button" class="btn-text-action" onclick="closeModal('dialogCreatePost')">Cancel</button>
                    <button type="submit" class="btn-contained" id="btnSubmitPost" disabled>Post</button>
                </footer>
            </form>
        </div>
    </div>

    <!-- Modal: Faculty Post Editor -->
    <div class="modal-backdrop" id="dialogEditPost">
        <div class="dialog-surface">
            <header class="dialog-header">
                <h2 class="dialog-title">Edit Task</h2>
                <button class="btn-dialog-close" onclick="closeModal('dialogEditPost')"><svg><use href="#icon-close"></use></svg></button>
            </header>
            <form method="POST" enctype="multipart/form-data" id="editPostForm" style="display:flex; flex-direction:column; flex:1; min-height:0;">
                <input type="hidden" name="action" value="update_post">
                <input type="hidden" name="post_id" id="editPostId" value="">
                <div class="dialog-content">
                    <div class="static-input-wrap" style="margin-bottom:16px;">
                        <label class="static-input-label">Subject</label>
                        <select class="static-input-field" name="subject_id" id="editPostSubject" required>
                            <?php foreach ($visibleClassSubjects as $subject): ?>
                                <option value="<?= htmlspecialchars($subject['id'] ?? '') ?>"><?= htmlspecialchars($subject['name'] ?? 'Untitled subject') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="md-input-container">
                        <input type="text" class="md-input-field" name="title" id="editPostTitle" placeholder=" " required>
                        <label class="md-input-label" for="editPostTitle">Title</label>
                    </div>
                    <div class="md-input-container">
                        <textarea class="md-input-field md-textarea" name="body" id="editPostBody" placeholder=" "></textarea>
                        <label class="md-input-label" for="editPostBody">Description (optional)</label>
                    </div>
                    <div class="static-grid-row">
                        <div class="static-input-wrap">
                            <label class="static-input-label">Points</label>
                            <input type="number" class="static-input-field" name="points" id="editPostPoints" value="100" min="0">
                        </div>
                        <div class="static-input-wrap">
                            <label class="static-input-label">Due Date</label>
                            <input type="datetime-local" class="static-input-field" name="deadline" id="editPostDeadline">
                        </div>
                    </div>
                    <label class="upload-dropzone" for="editPostFile" tabindex="0" role="button">
                        <svg><use href="#icon-upload"></use></svg>
                        <span class="upload-dropzone-text">Replace attached file</span>
                        <input type="file" name="post_file" id="editPostFile" style="display:none;" onchange="validateFileState(this, 'editPostFileDisplay')">
                        <div class="upload-file-display" id="editPostFileDisplay">No file selected</div>
                    </label>
                    <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--gc-text-secondary);">
                        <input type="checkbox" name="remove_file" value="1" id="editPostRemoveFile">
                        Remove current attachment
                    </label>
                </div>
                <footer class="dialog-actions">
                    <button type="button" class="btn-text-action" onclick="closeModal('dialogEditPost')">Cancel</button>
                    <button type="submit" class="btn-contained">Save changes</button>
                </footer>
            </form>
        </div>
    </div>

    <!-- Modal: Class Settings -->
    <div class="modal-backdrop" id="dialogSettings">
        <div class="dialog-surface">
            <header class="dialog-header">
                <h2 class="dialog-title">Class settings</h2>
                <button class="btn-dialog-close" onclick="closeModal('dialogSettings')"><svg><use href="#icon-close"></use></svg></button>
            </header>
            
            <form method="POST" style="display:flex; flex-direction:column; flex:1; min-height:0;">
                <input type="hidden" name="action" value="update_settings">
                
                <div class="dialog-content">
                    <h3 style="font-size:13px; font-weight:600; text-transform:uppercase; letter-spacing:0.06em; color:var(--gc-blue); margin-bottom:4px;">Class details</h3>

                    <div class="md-input-container">
                        <input type="text" class="md-input-field" name="class_name" id="sClassname" placeholder=" " value="<?= htmlspecialchars($currentClass['name']) ?>" required>
                        <label class="md-input-label" for="sClassname">Class name (required)</label>
                    </div>

                    <div class="md-input-container">
                        <input type="text" class="md-input-field" name="subject" id="sSubject" placeholder=" " value="<?= htmlspecialchars($currentClass['subject'] ?? '') ?>">
                        <label class="md-input-label" for="sSubject">Subject</label>
                    </div>

                    <div class="md-input-container">
                        <textarea class="md-input-field md-textarea" name="description" id="sDesc" placeholder=" "><?= htmlspecialchars($currentClass['description'] ?? '') ?></textarea>
                        <label class="md-input-label" for="sDesc">Class description (optional)</label>
                    </div>

                    <div style="margin-top:8px; padding-top:20px; border-top:1px solid var(--gc-border);">
                        <h3 style="font-size:13px; font-weight:600; text-transform:uppercase; letter-spacing:0.06em; color:var(--gc-red); margin-bottom:8px;">Danger Zone</h3>
                        <p style="font-size:13px; color:var(--gc-text-secondary);">Deleting a class is irreversible. All data will be permanently wiped.</p>
                    </div>
                </div>
                
                <footer class="dialog-actions" style="justify-content: space-between;">
                    <button type="button" class="btn-text-action color-danger" onclick="triggerClassPurge()">Delete class</button>
                    <div style="display:flex; gap:8px;">
                        <button type="button" class="btn-text-action" onclick="closeModal('dialogSettings')">Cancel</button>
                        <button type="submit" class="btn-contained">Save</button>
                    </div>
                </footer>
            </form>
            
            <form method="POST" id="purgeClassForm" style="display:none;">
                <input type="hidden" name="action" value="delete_class">
            </form>
        </div>
    </div>

    <!-- Modal: Submissions Viewer -->
    <div class="modal-backdrop" id="dialogSubmissions">
        <div class="dialog-surface large">
            <header class="dialog-header">
                <h2 class="dialog-title">Student Work</h2>
                <button class="btn-dialog-close" onclick="closeModal('dialogSubmissions')"><svg><use href="#icon-close"></use></svg></button>
            </header>
            <div class="dialog-content" id="submissionsContentArea">
            </div>
        </div>
    </div>
    <!-- Modal: Banner Color Picker -->
    <div class="modal-backdrop" id="dialogBanner">
        <div class="dialog-surface" style="max-width:400px;">
            <header class="dialog-header">
                <h2 class="dialog-title">Customize banner</h2>
                <button class="btn-dialog-close" onclick="closeModal('dialogBanner')"><svg><use href="#icon-close"></use></svg></button>
            </header>
            <form method="POST">
                <input type="hidden" name="action" value="update_banner">
                <div class="dialog-content" style="gap:16px;">
                    <p style="font-size:13px;color:var(--gc-text-secondary);">Choose a banner color for your class.</p>
                    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;" id="bannerColorGrid">
                        <?php
                        $bannerOptions = [
                            '#1967d2' => 'Blue',
                            '#0d652d' => 'Green',
                            '#b06000' => 'Orange',
                            '#c5221f' => 'Red',
                            '#6200ea' => 'Purple',
                            '#00796b' => 'Teal',
                            '#ad1457' => 'Pink',
                            '#37474f' => 'Slate',
                        ];
                        $currentBanner = $currentClass['banner_color'] ?? '#1967d2';
                        foreach ($bannerOptions as $hex => $label):
                        ?>
                        <label style="cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:6px;">
                            <input type="radio" name="banner_color" value="<?= $hex ?>" <?= $currentBanner === $hex ? 'checked' : '' ?> style="display:none;" onchange="document.getElementById('bannerPreview').style.background='<?= $hex ?>'">
                            <div onclick="this.previousElementSibling.click();document.querySelectorAll('.banner-swatch').forEach(s=>s.style.outline='none');this.style.outline='3px solid #fff';" class="banner-swatch" style="width:100%;height:40px;border-radius:6px;background:<?= $hex ?>;<?= $currentBanner===$hex ? 'outline:3px solid #fff;box-shadow:0 0 0 4px '.$hex.';' : '' ?>"></div>
                            <span style="font-size:11px;color:var(--gc-text-secondary);"><?= $label ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <div id="bannerPreview" style="height:56px;border-radius:6px;background:<?= htmlspecialchars($currentBanner) ?>;transition:background 0.2s;"></div>
                </div>
                <footer class="dialog-actions">
                    <button type="button" class="btn-text-action" onclick="closeModal('dialogBanner')">Cancel</button>
                    <button type="submit" class="btn-contained">Save</button>
                </footer>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php if ($role === 'student'): ?>
    <!-- Modal: Student Submit Work -->
    <div class="modal-backdrop" id="dialogSubmitWork">
        <div class="dialog-surface">
            
            <header class="dialog-header">
                <h2 class="dialog-title">Your Work</h2>
                <button class="btn-dialog-close" onclick="closeModal('dialogSubmitWork')"><svg><use href="#icon-close"></use></svg></button>
            </header>
            
            <form method="POST" enctype="multipart/form-data" id="submissionForm" style="display:flex; flex-direction:column; flex:1; min-height:0;">
                <input type="hidden" name="action" value="submit_assignment">
                <input type="hidden" name="post_id" id="hiddenSubmitPostId" value="">
                
                <div class="dialog-content">
                    <p id="displaySubmitTitle" style="font-weight: 500; color: var(--gc-text-primary); margin-bottom: 24px; line-height: 1.4; border-left: 3px solid var(--gc-blue); padding-left: 12px;"></p>
                    
                    <label class="upload-dropzone" for="submissionFile" tabindex="0" role="button">
                        <svg><use href="#icon-upload"></use></svg>
                        <span class="upload-dropzone-text">Browse files to attach</span>
                        <input type="file" name="submission_file" id="submissionFile" style="display:none;" onchange="validateFileState(this, 'submitFileDisplay')">
                        <div class="upload-file-display" id="submitFileDisplay">No file selected</div>
                    </label>
                    
                    <div class="md-input-container" style="margin-bottom: 0;">
                        <textarea class="md-input-field md-textarea" name="note" id="inputPrivateNote" placeholder=" "></textarea>
                        <label class="md-input-label" for="inputPrivateNote">Private comment to teacher (optional)</label>
                    </div>
                </div>
                
                <footer class="dialog-actions">
                    <button type="button" class="btn-text-action" onclick="closeModal('dialogSubmitWork')">Cancel</button>
                    <button type="submit" class="btn-contained" id="btnSubmitWork" disabled>Turn in</button>
                </footer>
            </form>
        </div>
    </div>
<?php endif; ?>

<!-- ── JAVASCRIPT FRONTEND ENGINE ── -->
<script>
    <?php if ($role === 'faculty'): ?>
    const CLASS_CONTEXT = { 
        id: <?= json_encode($classId) ?>, 
        users: <?= json_encode($usersDictionary) ?>, 
        posts: <?= json_encode($classPosts) ?>,
        members: <?= json_encode($enrolledMembers) ?> 
    };
    <?php endif; ?>
    const accountButton = document.getElementById('accountButton');
    const accountPopover = document.getElementById('accountPopover');
    const accountPopoverClose = document.getElementById('accountPopoverClose');
    const themeToggles = document.querySelectorAll('.theme-toggle');
    const liveClock = document.getElementById('liveClock');
    
    window.addEventListener('scroll', function() {
        const appBar = document.getElementById('globalAppBar');
        if (window.scrollY > 5) appBar.classList.add('scrolled');
        else appBar.classList.remove('scrolled');
    });

    // Tab active state follows clicks
    document.querySelectorAll('.tab-link').forEach(link => {
        link.addEventListener('click', function() {
            document.querySelectorAll('.tab-link').forEach(t => t.classList.remove('active'));
            this.classList.add('active');
        });
    });

    themeToggles.forEach((toggle) => {
        toggle.addEventListener('click', () => {
            const nextTheme = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', nextTheme);
            localStorage.setItem('theme', nextTheme);
        });
    });

    function updateLiveClock() {
        if (!liveClock) return;
        const now = new Date();
        liveClock.textContent = now.toLocaleString(undefined, {
            weekday: 'short',
            month: 'short',
            day: 'numeric',
            hour: 'numeric',
            minute: '2-digit'
        });
    }
    updateLiveClock();
    setInterval(updateLiveClock, 60000);

    if (accountButton && accountPopover) {
        accountButton.addEventListener('click', (e) => {
            e.stopPropagation();
            const isOpen = accountPopover.classList.toggle('open');
            accountButton.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });
    }

    if (accountPopoverClose && accountPopover && accountButton) {
        accountPopoverClose.addEventListener('click', () => {
            accountPopover.classList.remove('open');
            accountButton.setAttribute('aria-expanded', 'false');
            accountButton.focus();
        });
    }

    function openDialog(id) { 
        document.getElementById(id).classList.add('active'); 
        document.body.style.overflow = 'hidden'; 
    }

    function closeModal(id) { 
        document.getElementById(id).classList.remove('active'); 
        document.body.style.overflow = ''; 
    }

    document.querySelectorAll('.modal-backdrop').forEach(layer => {
        layer.addEventListener('click', function(e) {
            if (e.target === this) closeModal(this.id);
        });
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-backdrop.active').forEach(layer => {
                closeModal(layer.id);
            });
            if (accountPopover) {
                accountPopover.classList.remove('open');
                accountButton?.setAttribute('aria-expanded', 'false');
            }
        }
    });

    document.addEventListener('click', function(e) {
        if (accountPopover && accountPopover.classList.contains('open') &&
            !accountPopover.contains(e.target) &&
            !accountButton.contains(e.target)) {
            accountPopover.classList.remove('open');
            accountButton.setAttribute('aria-expanded', 'false');
        }
    });

    <?php if ($role === 'faculty'): ?>
    function openCreatePostDialog(type) {
        document.getElementById('createPostForm').reset();
        document.getElementById('postFileDisplay').textContent = "No file selected";
        
        const tabs = document.querySelectorAll('.type-tab');
        tabs.forEach(tab => {
            if (tab.getAttribute('data-type') === type) {
                updatePostTypeContext(type, tab);
            }
        });
        
        openDialog('dialogCreatePost');
    }

    function toDatetimeLocal(value) {
        if (!value) return '';
        return String(value).replace(' ', 'T').slice(0, 16);
    }

    function openEditPostDialog(post) {
        document.getElementById('editPostForm').reset();
        document.getElementById('editPostFileDisplay').textContent = post.file ? `Current: ${post.file.name}` : 'No file selected';
        document.getElementById('editPostId').value = post.id || '';
        document.getElementById('editPostTitle').value = post.title || '';
        document.getElementById('editPostBody').value = post.body || '';
        document.getElementById('editPostPoints').value = post.points || 100;
        document.getElementById('editPostDeadline').value = toDatetimeLocal(post.deadline || '');
        document.getElementById('editPostSubject').value = post.subject || '';
        document.getElementById('editPostRemoveFile').checked = false;
        openDialog('dialogEditPost');
    }

    function updatePostTypeContext(type, targetEl) {
        document.getElementById('inputPostType').value = type;
        
        document.querySelectorAll('.type-tab').forEach(btn => btn.classList.remove('active'));
        targetEl.classList.add('active');
        
        const extraFields = document.getElementById('assignmentExtraFields');
        extraFields.style.display = (type === 'assignment') ? 'block' : 'none';
        
        validatePostState();
    }

    const pTitle = document.getElementById('inputPostTitle');
    const pBody  = document.getElementById('inputPostBody');
    if (pTitle && pBody) {
        pTitle.addEventListener('input', validatePostState);
        pBody.addEventListener('input', validatePostState);
    }

    function validatePostState() {
        const t = pTitle ? pTitle.value.trim() : '';
        const b = pBody ? pBody.value.trim() : '';
        const s = document.getElementById('btnSubmitPost');
        if (s) s.disabled = (t === '' && b === '');
    }

    function triggerClassPurge() {
        if (confirm("Are you absolutely sure you want to trigger a data purge? This class and all its data will be lost forever.")) {
            document.getElementById('purgeClassForm').submit();
        }
    }

    function openSubmissionsDialog(postId) {
        const container = document.getElementById('submissionsContentArea');
        container.innerHTML = ''; 
        
        let targetAssignment = null;
        for (const [k, v] of Object.entries(CLASS_CONTEXT.posts)) {
            if (v.id === postId) { targetAssignment = v; break; }
        }
        
        if (!targetAssignment || CLASS_CONTEXT.members.length === 0) {
            container.innerHTML = `
                <div class="empty-illustration">
                    <svg viewBox="0 0 24 24"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>
                    <h3>No students to review</h3>
                    <p>There are no students enrolled in the system to submit work.</p>
                </div>
            `;
            openDialog('dialogSubmissions');
            return;
        }

        const pointCeiling = targetAssignment.points || 100;
        let generatedMarkup = '';

        CLASS_CONTEXT.members.forEach(memberId => {
            const uData = CLASS_CONTEXT.users[memberId] || {};
            const fName = uData.fullname || memberId;
            const sub = (targetAssignment.submissions && targetAssignment.submissions[memberId]) ? targetAssignment.submissions[memberId] : null;
            
            let badgeHtml = `<span class="status-indicator status-missing" style="padding: 2px 8px; font-size:11px;">Missing</span>`;
            if (sub) {
                if (sub.score !== null && sub.score !== undefined && sub.score !== '') {
                    badgeHtml = `<span class="status-indicator status-graded" style="padding: 2px 8px; font-size:11px;">Graded: ${sub.score}/${pointCeiling}</span>`;
                } else {
                    badgeHtml = `<span class="status-indicator status-done" style="padding: 2px 8px; font-size:11px;">Turned in</span>`;
                }
            }

            generatedMarkup += `
                <div class="submission-list-item">
                    <div class="submission-student-info">
                        <div class="submission-student-name">
                            <div class="btn-icon-circular" style="width: 32px; height: 32px; background: var(--gc-bg-hover); font-size:12px; font-weight: 500;">
                                ${fName.charAt(0).toUpperCase()}
                            </div>
                            ${fName}
                        </div>
                        ${badgeHtml}
                    </div>
            `;
            
            if (sub) {
                generatedMarkup += `<div class="submission-content-area">`;
                if (sub.note) {
                    generatedMarkup += `<div class="submission-note">"${sub.note}"</div>`;
                }
                if (sub.file) {
                    generatedMarkup += `
                        <a href="${sub.file.path}" class="attachment-item" download target="_blank" style="max-width: 400px; padding: 8px;">
                            <div class="attachment-icon-box" style="width: 32px; height: 32px;"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg></div>
                            <div class="attachment-info">
                                <h4 class="attachment-filename">${sub.file.name}</h4>
                            </div>
                        </a>
                    `;
                }
                
                const cScore = (sub.score !== null && sub.score !== undefined) ? sub.score : '';
                const cNote  = sub.score_note || '';
                
                generatedMarkup += `
                    <form method="POST" class="submission-grading-form">
                        <input type="hidden" name="action" value="score_submission">
                        <input type="hidden" name="post_id" value="${postId}">
                        <input type="hidden" name="target_user" value="${memberId}">
                        
                        <div class="static-input-wrap" style="width: 80px;">
                            <label class="static-input-label">Score</label>
                            <input type="number" class="static-input-field" name="score" value="${cScore}" placeholder=" " step="0.5" min="0" max="${pointCeiling}">
                        </div>
                        <div class="static-input-wrap" style="flex: 1;">
                            <label class="static-input-label">Feedback</label>
                            <input type="text" class="static-input-field" name="score_note" value="${cNote}" placeholder="Optional">
                        </div>
                        <button type="submit" class="btn-contained" style="padding: 12px 16px;" onclick="var inp=this.form.querySelector('[name=score]'),m=parseFloat(inp.max),v=parseFloat(inp.value);if(!isNaN(v)&&v>m){inp.value=m;}">Save</button>
                    </form>
                </div>`;
            }
            
            generatedMarkup += `</div>`;
        });

        container.innerHTML = generatedMarkup;
        openDialog('dialogSubmissions');
    }
    <?php endif; ?>

    <?php if ($role === 'student'): ?>
    function openStudentSubmitDialog(postId, postTitle) { 
        document.getElementById('hiddenSubmitPostId').value = postId; 
        document.getElementById('displaySubmitTitle').textContent = postTitle;
        
        document.getElementById('submissionForm').reset();
        document.getElementById('submitFileDisplay').textContent = "No file selected";
        document.getElementById('btnSubmitWork').disabled = true;
        
        openDialog('dialogSubmitWork'); 
    }
    
    document.getElementById('inputPrivateNote')?.addEventListener('input', validateStudentSubmit);
    
    function validateStudentSubmit() {
        const fileInput = document.getElementById('submissionFile');
        const noteInput = document.getElementById('inputPrivateNote');
        const btn = document.getElementById('btnSubmitWork');
        
        const hasFile = fileInput.files && fileInput.files.length > 0;
        const hasNote = noteInput && noteInput.value.trim().length > 0;
        
        if (btn) btn.disabled = !(hasFile || hasNote);
    }
    <?php endif; ?>

    function validateFileState(input, targetId) {
        const node = document.getElementById(targetId);
        if (input.files && input.files.length > 0) {
            node.textContent = "Attached: " + input.files[0].name;
            node.style.color = "var(--gc-text-primary)";
        } else {
            node.textContent = "No file selected";
            node.style.color = "var(--gc-text-secondary)";
        }
        
        if (typeof validateStudentSubmit === "function") validateStudentSubmit();
    }

    function toggleDropdown(menuId) {
        const menu = document.getElementById(menuId);
        if (!menu) return;
        
        document.querySelectorAll('.post-dropdown-menu.show').forEach(m => {
            if (m.id !== menuId) m.classList.remove('show');
        });
        
        menu.classList.toggle('show');
    }

    document.addEventListener('click', function(e) {
        if (!e.target.closest('.post-dropdown')) {
            document.querySelectorAll('.post-dropdown-menu.show').forEach(m => m.classList.remove('show'));
        }
    });

    function toggleElementState(cid, caller) {
        const container = document.getElementById(cid);
        if (!container) return;
        
        if (container.classList.contains('expanded')) {
            container.classList.remove('expanded');
            caller.classList.remove('expanded');
        } else {
            container.classList.add('expanded');
            caller.classList.add('expanded');
            const txt = container.querySelector('.comment-textarea');
            if (txt) txt.focus();
        }
    }

    function resizeTextarea(el) {
        el.style.height = '40px'; 
        el.style.height = Math.min(el.scrollHeight, 150) + 'px';
    }

    function validateCommentInput(el, btnId) {
        const btn = document.getElementById(btnId);
        if (btn) btn.disabled = (el.value.trim() === '');
    }

    function copyToClipboard() {
        const code = document.getElementById('classCodeDisplay').textContent;
        navigator.clipboard.writeText(code).then(() => {
            alert('Security access code copied to clipboard!');
        });
    }

    function toggleSidebar() {
        const sidebar = document.getElementById('classSidebar');
        const workspace = document.getElementById('mainWorkspace');
        sidebar.classList.toggle('collapsed');
        workspace.classList.toggle('sidebar-collapsed');
    }
</script>
</body>
</html>
