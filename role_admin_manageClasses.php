<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

if (!isset($_SESSION['username']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/db.php'; // provides $pdo

$username = htmlspecialchars($_SESSION['username']);
$initials = strtoupper(substr($username, 0, 2));

/* ═══════════════════════════════════════════════
   HELPER FUNCTIONS
════════════════════════════════════════════════ */
function adminRedirectClasses(string $status = 'updated'): void {
    header('Location: role_admin_manageClasses.php?admin_class_status=' . urlencode($status));
    exit;
}

function adminNextClassId(PDO $pdo): string {
    $stmt = $pdo->query("SELECT id FROM classes ORDER BY id DESC LIMIT 1");
    $last = $stmt->fetchColumn();
    if ($last && preg_match('/C(\d+)/', $last, $m)) {
        return 'C' . str_pad((string)((int)$m[1] + 1), 3, '0', STR_PAD_LEFT);
    }
    return 'C001';
}

function adminNextSubjectId(PDO $pdo): string {
    $stmt = $pdo->query("SELECT id FROM subjects ORDER BY id DESC LIMIT 1");
    $last = $stmt->fetchColumn();
    if ($last && preg_match('/S(\d+)/', $last, $m)) {
        return 'S' . str_pad((string)((int)$m[1] + 1), 3, '0', STR_PAD_LEFT);
    }
    return 'S001';
}

function adminNextClassCode(PDO $pdo): string {
    $stmt = $pdo->query("SELECT code FROM classes ORDER BY created_at DESC LIMIT 1");
    $last = $stmt->fetchColumn();
    // generate a random 6-char alphanumeric code
    return strtoupper(substr(md5(uniqid()), 0, 6));
}

/* ═══════════════════════════════════════════════
   POST ACTIONS
════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_class_action'])) {
    $action = trim((string)$_POST['admin_class_action']);

    switch ($action) {

        case 'create_class':
            $name   = trim((string)($_POST['name']   ?? ''));
            $status = in_array(($_POST['status'] ?? 'active'), ['active','archived'], true)
                      ? $_POST['status'] : 'active';
            if ($name !== '') {
                $dup = $pdo->prepare("SELECT COUNT(*) FROM classes WHERE LOWER(name) = LOWER(?)");
                $dup->execute([$name]);
                if ((int)$dup->fetchColumn() > 0) {
                    adminRedirectClasses('duplicate_class_name');
                    break;
                }
                $newId   = adminNextClassId($pdo);
                $newCode = adminNextClassCode($pdo);
                $stmt = $pdo->prepare(
                    "INSERT INTO classes (id, name, subject, owner, status, code) VALUES (?,?,?,?,?,?)"
                );
                $stmt->execute([$newId, $name, '', $_SESSION['username'], $status, $newCode]);
            }
            adminRedirectClasses('class_created');
            break;

        case 'toggle_archive':
            $classId = trim((string)($_POST['class_id'] ?? ''));
            $stmt = $pdo->prepare("SELECT status FROM classes WHERE id = ?");
            $stmt->execute([$classId]);
            $current = $stmt->fetchColumn();
            if ($current !== false) {
                $newStatus = $current === 'archived' ? 'active' : 'archived';
                $upd = $pdo->prepare("UPDATE classes SET status = ? WHERE id = ?");
                $upd->execute([$newStatus, $classId]);
            }
            adminRedirectClasses('class_status_updated');
            break;

        case 'delete_class':
            $classId = trim((string)($_POST['class_id'] ?? ''));
            // Delete members, subject_members, subjects, then class
            $subStmt = $pdo->prepare("SELECT id FROM subjects WHERE class_id = ?");
            $subStmt->execute([$classId]);
            $subjectIds = $subStmt->fetchAll(PDO::FETCH_COLUMN);
            if ($subjectIds) {
                $in = implode(',', array_fill(0, count($subjectIds), '?'));
                $pdo->prepare("DELETE FROM subject_members WHERE subject_id IN ($in)")->execute($subjectIds);
            }
            $pdo->prepare("DELETE FROM subjects WHERE class_id = ?")->execute([$classId]);
            $pdo->prepare("DELETE FROM class_members WHERE class_id = ?")->execute([$classId]);
            $pdo->prepare("DELETE FROM classes WHERE id = ?")->execute([$classId]);
            adminRedirectClasses('class_deleted');
            break;

        case 'add_subject':
            $classId = trim((string)($_POST['class_id'] ?? ''));
            $name    = trim((string)($_POST['name']     ?? ''));
            $faculty = trim((string)($_POST['faculty']  ?? '')) ?: null;
            if ($classId !== '' && $name !== '') {
                if ($faculty !== null) {
                    $chk = $pdo->prepare("SELECT role FROM users WHERE username = ?");
                    $chk->execute([$faculty]);
                    if ($chk->fetchColumn() !== 'faculty') $faculty = null;
                }
                $newId = adminNextSubjectId($pdo);
                $stmt = $pdo->prepare(
                    "INSERT INTO subjects (id, class_id, name, faculty) VALUES (?,?,?,?)"
                );
                $stmt->execute([$newId, $classId, $name, $faculty]);
            }
            adminRedirectClasses('subject_added');
            break;

        case 'delete_subject':
            $subjectId = trim((string)($_POST['subject_id'] ?? ''));
            $pdo->prepare("DELETE FROM subject_members WHERE subject_id = ?")->execute([$subjectId]);
            $pdo->prepare("DELETE FROM subjects WHERE id = ?")->execute([$subjectId]);
            adminRedirectClasses('subject_deleted');
            break;

        case 'assign_faculty':
            $classId   = trim((string)($_POST['class_id']   ?? ''));
            $subjectId = trim((string)($_POST['subject_id'] ?? ''));
            $faculty   = trim((string)($_POST['faculty']    ?? '')) ?: null;
            if ($faculty !== null) {
                $chk = $pdo->prepare("SELECT role FROM users WHERE username = ?");
                $chk->execute([$faculty]);
                if ($chk->fetchColumn() !== 'faculty') $faculty = null;
            }
            $stmt = $pdo->prepare("UPDATE subjects SET faculty = ? WHERE id = ? AND class_id = ?");
            $stmt->execute([$faculty, $subjectId, $classId]);
            adminRedirectClasses('faculty_assigned');
            break;

        case 'enroll_student':
            $classId   = trim((string)($_POST['class_id']   ?? ''));
            $subjectId = trim((string)($_POST['subject_id'] ?? ''));
            $student   = trim((string)($_POST['student']    ?? ''));
            if ($classId !== '' && $subjectId !== '' && $student !== '') {
                // Validate student role
                $chk = $pdo->prepare("SELECT role FROM users WHERE username = ?");
                $chk->execute([$student]);
                if ($chk->fetchColumn() === 'student') {
                    // Add to class_members (ignore duplicate)
                    $pdo->prepare(
                        "INSERT IGNORE INTO class_members (class_id, username) VALUES (?,?)"
                    )->execute([$classId, $student]);
                    // Add to subject_members (ignore duplicate)
                    $pdo->prepare(
                        "INSERT IGNORE INTO subject_members (subject_id, username) VALUES (?,?)"
                    )->execute([$subjectId, $student]);
                }
            }
            adminRedirectClasses('student_enrolled');
            break;

        case 'remove_student':
            $classId   = trim((string)($_POST['class_id']   ?? ''));
            $subjectId = trim((string)($_POST['subject_id'] ?? ''));
            $student   = trim((string)($_POST['student']    ?? ''));
            if ($student !== '' && $subjectId !== '') {
                $pdo->prepare("DELETE FROM subject_members WHERE subject_id = ? AND username = ?")
                    ->execute([$subjectId, $student]);
                // Remove from class_members only if no longer in any subject of this class
                $stillIn = $pdo->prepare(
                    "SELECT COUNT(*) FROM subject_members sm
                     JOIN subjects s ON sm.subject_id = s.id
                     WHERE s.class_id = ? AND sm.username = ?"
                );
                $stillIn->execute([$classId, $student]);
                if ((int)$stillIn->fetchColumn() === 0) {
                    $pdo->prepare("DELETE FROM class_members WHERE class_id = ? AND username = ?")
                        ->execute([$classId, $student]);
                }
            }
            adminRedirectClasses('student_removed');
            break;
    }

    adminRedirectClasses();
}

/* ═══════════════════════════════════════════════
   LOAD DATA FROM SQL
════════════════════════════════════════════════ */

// All users (for sidebar counts, name maps, dropdowns)
$allUsers = $pdo->query(
    "SELECT username, fullname, role, status FROM users ORDER BY fullname ASC"
)->fetchAll(PDO::FETCH_ASSOC);

$facultyUsers  = array_values(array_filter($allUsers, fn($u) => $u['role'] === 'faculty'));
$studentUsers  = array_values(array_filter($allUsers, fn($u) => $u['role'] === 'student'));
$totalUsers    = count($allUsers);
$pendingCount  = count(array_filter($allUsers, fn($u) => $u['status'] === 'pending'));
$totalFaculty  = count($facultyUsers);

$nameMap = [];
foreach ($allUsers as $u) {
    $nameMap[$u['username']] = $u['fullname'] ?? $u['username'];
}

// Notifications unread count
$unreadNotifs = (int)$pdo->query(
    "SELECT COUNT(*) FROM notifications WHERE is_read = 0"
)->fetchColumn();

// Classes
$allClasses = $pdo->query(
    "SELECT id, name, subject, description, code, owner, status FROM classes ORDER BY created_at DESC"
)->fetchAll(PDO::FETCH_ASSOC);

// Subjects keyed by class_id
$allSubjects = $pdo->query(
    "SELECT id, class_id, name, faculty FROM subjects ORDER BY created_at ASC"
)->fetchAll(PDO::FETCH_ASSOC);
$subjectsByClass = [];
foreach ($allSubjects as $s) {
    $subjectsByClass[$s['class_id']][] = $s;
}

// Subject members keyed by subject_id
$allSubjectMembers = $pdo->query(
    "SELECT subject_id, username FROM subject_members"
)->fetchAll(PDO::FETCH_ASSOC);
$membersBySubject = [];
foreach ($allSubjectMembers as $sm) {
    $membersBySubject[$sm['subject_id']][] = $sm['username'];
}

// Class members count keyed by class_id
$allClassMembers = $pdo->query(
    "SELECT class_id, COUNT(*) as cnt FROM class_members GROUP BY class_id"
)->fetchAll(PDO::FETCH_ASSOC);
$memberCountByClass = [];
foreach ($allClassMembers as $cm) {
    $memberCountByClass[$cm['class_id']] = (int)$cm['cnt'];
}

// Stats
$totalClasses    = count($allClasses);
$activeClasses   = count(array_filter($allClasses, fn($c) => $c['status'] === 'active'));
$archivedClasses = count(array_filter($allClasses, fn($c) => $c['status'] === 'archived'));

// Build JS-friendly data structure
$jsClasses = [];
foreach ($allClasses as $c) {
    $cId      = $c['id'];
    $subjects = [];
    foreach ($subjectsByClass[$cId] ?? [] as $s) {
        $subjects[] = [
            'id'       => $s['id'],
            'name'     => $s['name'],
            'faculty'  => $s['faculty'] ?? '',
            'students' => $membersBySubject[$s['id']] ?? [],
        ];
    }
    $jsClasses[] = [
        'id'      => $cId,
        'name'    => $c['name'],
        'subject' => $c['subject'],
        'owner'   => $c['owner'] ?? '',
        'members' => array_values(array_unique(array_column(
            $pdo->query("SELECT username FROM class_members WHERE class_id = " . $pdo->quote($cId))->fetchAll(PDO::FETCH_ASSOC),
            'username'
        ))),
        'status'   => $c['status'],
        'subjects' => $subjects,
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Classes — Helios</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-base:#f5f6fa; --bg-surface:#ffffff; --border-light:#e8eaef;
            --text-primary:#1a1d2e; --text-secondary:#52576e; --text-muted:#8b90a7;
            --accent-green:#1a7a4a; --accent-green-hover:#155f3a; --accent-green-dim:rgba(26,122,74,0.10);
            --accent-blue:#1a7a4a; --accent-blue-hover:#155f3a; --accent-blue-dim:rgba(26,122,74,0.10);
            --accent-red:#d93025; --accent-red-hover:#a50e0e; --accent-red-dim:#fce8e6;
            --accent-yellow:#f9ab00; --accent-yellow-dim:#fef7e0;
            --accent-purple:#7c4dff; --accent-purple-dim:#ede7f6;
            --sidebar-w:260px; --topbar-h:60px;
            --ease:0.2s cubic-bezier(0.4,0,0.2,1);
            --shadow-subtle:0 1px 2px 0 rgba(60,64,67,0.3),0 1px 3px 1px rgba(60,64,67,0.15);
            --shadow-modal:0 8px 32px rgba(60,64,67,0.2);
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; background: var(--bg-base); color: var(--text-primary); font-family: 'Poppins', sans-serif; font-size: 14px; line-height: 1.5; -webkit-font-smoothing: antialiased; }
        a { text-decoration: none; color: inherit; }
        button { font-family: inherit; background: none; border: none; cursor: pointer; color: inherit; outline: none; }
        ul, ol { list-style: none; }

        /* ── Topbar ── */
        .topbar { position: fixed; top: 0; left: 0; right: 0; height: var(--topbar-h); z-index: 1000; display: flex; align-items: center; justify-content: space-between; padding: 0 24px 0 0; background: var(--bg-surface); border-bottom: 1px solid var(--border-light); box-shadow: 0 1px 4px rgba(0,0,0,0.06); }
        .topbar-brand { display: flex; align-items: center; width: var(--sidebar-w); padding: 0 24px; height: 100%; gap: 10px; flex-shrink: 0; border-right: 1px solid var(--border-light); }
        .brand-icon { width: 32px; height: 32px; background: linear-gradient(135deg,#1a7a4a,#0e9f6e); border-radius: 8px; display: flex; align-items: center; justify-content: center; color: white; overflow: hidden; }
        .brand-logo-img { width: 100%; height: 100%; object-fit: contain; display: block; }
        .brand-logo-dark { display: none; }
        .brand-wordmark { font-size: 17px; font-weight: 700; color: var(--text-primary); letter-spacing: -0.02em; }
        .brand-wordmark span { font-size: 11px; color: var(--text-muted); letter-spacing: 0.08em; text-transform: uppercase; display: block; margin-top: -3px; font-weight: 400; }
        .topbar-center { flex: 1; display: flex; align-items: center; padding: 0 24px; }
        .topbar-clock { font-size: 12px; color: var(--text-muted); font-variant-numeric: tabular-nums; letter-spacing: 0.04em; }
        .topbar-right { display: flex; align-items: center; gap: 8px; }
        .topbar-icon-btn { width: 36px; height: 36px; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: var(--text-secondary); position: relative; transition: background var(--ease), color var(--ease); }
        .topbar-icon-btn:hover { background: var(--bg-elevated); color: var(--text-primary); }
        .topbar-icon-btn svg { width: 18px; height: 18px; fill: currentColor; }
        .notif-badge { position: absolute; top: 5px; right: 5px; width: 8px; height: 8px; background: var(--accent-red); border-radius: 50%; border: 2px solid var(--bg-surface); }
        .topbar-divider { width: 1px; height: 24px; background: var(--border-light); margin: 0 8px; }
        .menu-toggle { width: 36px; height: 36px; border-radius: 8px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px; transition: background var(--ease); margin-right: 8px; }
        .menu-toggle:hover { background: var(--bg-elevated); }
        .menu-toggle span { display: block; width: 16px; height: 1.5px; background: var(--text-secondary); border-radius: 2px; }
        .avatar-btn { display: flex; align-items: center; gap: 12px; padding: 6px 10px; border-radius: 8px; border: 0; background: transparent; cursor: pointer; transition: background var(--ease); }
        .avatar-btn:hover { background: var(--bg-elevated); }
        .avatar-circle { width: 32px; height: 32px; border-radius: 8px; background: linear-gradient(135deg,#1a7a4a,#0e9f6e); display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 13px; color: #fff; }
        .avatar-meta { line-height: 1.3; text-align: left; }
        .avatar-name { font-size: 13px; font-weight: 500; color: var(--text-primary); }
        .avatar-role { font-size: 10px; text-transform: uppercase; letter-spacing: 0.08em; color: #1a7a4a; }

        /* ── Account popover ── */
        .account-popover { position: fixed; top: 72px; right: 24px; width: min(360px, calc(100vw - 32px)); background: var(--bg-surface); border: 1px solid var(--border-light); border-radius: 28px; box-shadow: 0 14px 40px rgba(26,29,46,.22); z-index: 1200; padding: 20px; display: none; text-align: center; }
        .account-popover.open { display: block; }
        .account-popover-close { position: absolute; top: 16px; right: 18px; width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: var(--text-secondary); font-size: 24px; line-height: 1; }
        .account-popover-close:hover { background: rgba(26,29,46,.08); color: var(--text-primary); }
        .account-email { padding: 4px 40px 14px; font-size: 14px; font-weight: 500; color: var(--text-primary); word-break: break-word; }
        .account-avatar-large { width: 98px; height: 98px; border-radius: 50%; margin: 14px auto 12px; background: #78909c; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 44px; font-weight: 500; box-shadow: inset 0 0 0 4px rgba(255,255,255,.14); }
        .account-greeting { font-size: 24px; font-weight: 500; color: var(--text-primary); margin-bottom: 16px; }
        .account-role-pill { display: inline-flex; align-items: center; justify-content: center; min-height: 38px; padding: 0 22px; border: 1px solid rgba(26,29,46,.35); border-radius: 999px; color: var(--accent-blue); font-weight: 600; background: var(--bg-elevated); margin-bottom: 18px; }
        .account-actions { background: var(--bg-elevated); border-radius: 18px; overflow: hidden; text-align: left; }
        .account-theme-toggle, .account-signout { display: flex; align-items: center; gap: 14px; width: 100%; padding: 16px 22px; color: var(--text-primary); font-size: 15px; font-weight: 600; text-decoration: none; }
        .account-theme-toggle { justify-content: space-between; border: 0; background: transparent; }
        .account-theme-toggle .theme-copy { display: inline-flex; align-items: center; gap: 12px; }
        .account-theme-toggle svg { width: 20px; height: 20px; stroke: currentColor; fill: none; stroke-width: 2; }
        .icon-sun { display: none; }
        .theme-switch { width: 48px; height: 28px; border-radius: 999px; background: rgba(88,101,124,.16); padding: 3px; display: inline-flex; align-items: center; flex-shrink: 0; transition: background-color .2s ease; }
        .theme-switch-knob { width: 22px; height: 22px; border-radius: 50%; background: #fff; box-shadow: 0 4px 10px rgba(15,23,42,.16); transition: transform .2s ease; }
        .account-signout:hover, .account-theme-toggle:hover { background: var(--bg-base); }
        .account-signout svg { width: 20px; height: 20px; fill: currentColor; color: var(--text-secondary); }

        /* ── Sidebar ── */
        .body-wrap { display: flex; flex: 1; margin-top: var(--topbar-h); }
        .sidebar { position: fixed; top: var(--topbar-h); bottom: 0; left: 0; width: var(--sidebar-w); background: var(--bg-surface); overflow-y: auto; z-index: 900; padding: 16px 0 24px; transition: transform var(--ease); display: flex; flex-direction: column; border-right: 1px solid var(--border-light); }
        .sidebar.collapsed { transform: translateX(-100%); }
        .nav-section-label { font-size: 10px; font-weight: 600; letter-spacing: 0.12em; text-transform: uppercase; color: var(--text-muted); padding: 16px 20px 6px; }
        .nav-link { display: flex; align-items: center; gap: 12px; padding: 9px 20px; color: var(--text-secondary); font-size: 13.5px; font-weight: 400; transition: all var(--ease); position: relative; }
        .nav-link:hover { color: var(--text-primary); background: var(--bg-elevated); }
        .nav-link.active { color: #1a7a4a; background: rgba(26,122,74,0.10); font-weight: 500; }
        .nav-link.active::before { content: ''; position: absolute; left: 0; top: 4px; bottom: 4px; width: 3px; background: #1a7a4a; border-radius: 0 3px 3px 0; }
        .nav-link svg { width: 16px; height: 16px; fill: currentColor; opacity: 0.7; }
        .nav-link.active svg { opacity: 1; }
        .nav-badge { margin-left: auto; background: var(--accent-red); color: white; font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 12px; }
        .nav-badge.dim { background: var(--border-light); color: var(--text-secondary); }
        .nav-divider { height: 1px; background: var(--border-light); margin: 8px 16px; }
        .sidebar-footer { margin-top: auto; padding: 12px 12px 0; border-top: 1px solid var(--border-light); }
        .sidebar-footer .nav-link { border-radius: 8px; }

        /* ── Main ── */
        .main { flex: 1; margin-left: var(--sidebar-w); padding: 32px 36px 48px; transition: margin-left var(--ease); max-width: 1440px; }
        .main.expanded { margin-left: 0; }
        .page-header { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 32px; }
        .page-title { font-size: 28px; font-weight: 600; color: var(--text-primary); letter-spacing: -0.02em; }
        .page-subtitle { font-size: 14px; color: var(--text-secondary); margin-top: 4px; }
        .page-date { font-size: 16px; font-weight: 600; color: var(--text-primary); text-align: right; }
        .page-date-sub { font-size: 12px; color: var(--text-secondary); font-weight: 400; }

        /* ── KPI strip ── */
        .kpi-strip { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-bottom: 40px; }
        .kpi-card { background: var(--bg-surface); border: 1px solid var(--border-light); border-radius: 14px; padding: 24px; display: flex; flex-direction: column; transition: box-shadow var(--ease); }
        .kpi-card:hover { box-shadow: var(--shadow-subtle); }
        .kpi-header { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; }
        .kpi-icon { width: 40px; height: 40px; border-radius: 8px; display: flex; align-items: center; justify-content: center; }
        .kpi-icon svg { width: 20px; height: 20px; fill: currentColor; }
        .kpi-card.blue   .kpi-icon { background: var(--accent-blue-dim);   color: var(--accent-blue); }
        .kpi-card.green  .kpi-icon { background: var(--accent-green-dim);  color: var(--accent-green); }
        .kpi-card.yellow .kpi-icon { background: var(--accent-yellow-dim); color: var(--accent-yellow); }
        .kpi-label { font-size: 13px; color: var(--text-secondary); font-weight: 500; }
        .kpi-value { font-size: 32px; font-weight: 600; color: var(--text-primary); line-height: 1; }

        /* ── Toolbar ── */
        .toolbar { display: flex; align-items: center; gap: 12px; margin-bottom: 20px; flex-wrap: wrap; }
        .search-wrap { position: relative; flex: 1; min-width: 220px; }
        .search-icon { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); width: 16px; height: 16px; fill: var(--text-muted); pointer-events: none; }
        .search-input { width: 100%; padding: 9px 12px 9px 38px; border: 1px solid var(--border-light); border-radius: 6px; font-family: 'Poppins', sans-serif; font-size: 14px; color: var(--text-primary); background: var(--bg-surface); outline: none; transition: border-color var(--ease); }
        .search-input:focus { border-color: var(--accent-blue); }
        .filter-select { padding: 9px 14px; border: 1px solid var(--border-light); border-radius: 6px; font-family: 'Poppins', sans-serif; font-size: 14px; color: var(--text-primary); background: var(--bg-surface); cursor: pointer; outline: none; }
        .count-text { font-size: 13px; color: var(--text-secondary); }

        /* ── Buttons ── */
        .btn { display: inline-flex; align-items: center; gap: 8px; padding: 9px 18px; border-radius: 6px; font-family: 'Poppins', sans-serif; font-size: 13px; font-weight: 500; cursor: pointer; border: none; transition: all var(--ease); }
        .btn-primary { background: var(--accent-blue); color: white; }
        .btn-primary:hover { background: var(--accent-blue-hover); }
        .btn-green { background: var(--accent-green); color: white; }
        .btn-green:hover { background: var(--accent-green-hover); }
        .btn-outline { background: transparent; color: var(--text-secondary); border: 1px solid var(--border-light); }
        .btn-outline:hover { background: var(--bg-base); }
        .btn-danger { background: var(--accent-red-dim); color: var(--accent-red); }
        .btn-danger:hover { background: var(--accent-red); color: white; }
        .btn svg { width: 16px; height: 16px; fill: currentColor; }
        .action-btns { display: flex; gap: 8px; }
        .btn-icon { width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: var(--text-secondary); transition: background var(--ease); }
        .btn-icon:hover { background: rgba(60,64,67,0.08); color: var(--text-primary); }
        .btn-icon.danger:hover { background: var(--accent-red-dim); color: var(--accent-red); }
        .btn-icon svg { width: 16px; height: 16px; fill: currentColor; }

        /* ── Status chip ── */
        .status-chip { display: inline-flex; padding: 4px 10px; border-radius: 16px; font-size: 12px; font-weight: 500; text-transform: capitalize; }
        .status-chip.active   { background: var(--accent-green-dim); color: var(--accent-green-hover); }
        .status-chip.archived { background: var(--bg-base); color: var(--text-secondary); border: 1px solid var(--border-light); }

        /* ── Class icon ── */
        .class-icon { width: 40px; height: 40px; border-radius: 8px; background: var(--bg-base); border: 1px solid var(--border-light); display: flex; align-items: center; justify-content: center; color: var(--text-secondary); font-weight: 600; font-size: 15px; flex-shrink: 0; }
        .class-icon.blue   { background: var(--accent-blue-dim);   color: var(--accent-blue);   border-color: transparent; }
        .class-icon.green  { background: var(--accent-green-dim);  color: var(--accent-green);  border-color: transparent; }
        .class-icon.yellow { background: var(--accent-yellow-dim); color: var(--accent-yellow); border-color: transparent; }
        .class-icon.red    { background: var(--accent-red-dim);    color: var(--accent-red);    border-color: transparent; }
        .class-name { font-size: 14px; font-weight: 500; color: var(--text-primary); }
        .class-id   { font-size: 12px; color: var(--text-secondary); margin-top: 2px; }

        /* ── Class Accordion ── */
        .class-accordion { margin-bottom: 12px; border: 1px solid var(--border-light); border-radius: 14px; overflow: hidden; background: var(--bg-surface); }
        .class-accordion-header { display: flex; align-items: center; padding: 16px 20px; cursor: pointer; transition: background var(--ease); user-select: none; gap: 12px; }
        .class-accordion-header:hover { background: rgba(60,64,67,0.03); }
        .class-accordion-header.open { background: var(--accent-blue-dim); }
        .accordion-chevron { width: 20px; height: 20px; fill: var(--text-muted); transition: transform var(--ease); flex-shrink: 0; margin-left: auto; }
        .class-accordion-header.open .accordion-chevron { transform: rotate(180deg); fill: var(--accent-blue); }
        .class-accordion-body { display: none; border-top: 1px solid var(--border-light); }
        .class-accordion-body.open { display: block; }

        /* ── Subject tabs ── */
        .subject-tabs { display: flex; border-bottom: 1px solid var(--border-light); background: var(--bg-base); overflow-x: auto; }
        .subject-tab { padding: 10px 20px; font-size: 13px; font-weight: 500; color: var(--text-secondary); cursor: pointer; white-space: nowrap; border-bottom: 2px solid transparent; transition: all var(--ease); display: flex; align-items: center; gap: 6px; }
        .subject-tab:hover { color: var(--text-primary); background: rgba(60,64,67,0.04); }
        .subject-tab.active { color: var(--accent-blue); border-bottom-color: var(--accent-blue); background: var(--bg-surface); }
        .subject-tab-add { padding: 10px 16px; font-size: 13px; color: var(--accent-green); cursor: pointer; white-space: nowrap; display: flex; align-items: center; gap: 6px; transition: background var(--ease); margin-left: auto; }
        .subject-tab-add:hover { background: var(--accent-green-dim); }
        .subject-tab-add svg { width: 16px; height: 16px; fill: currentColor; }

        /* ── Subject panel ── */
        .subject-panel { display: none; padding: 20px 24px; }
        .subject-panel.active { display: block; }
        .subject-section-title { font-size: 13px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 14px; display: flex; align-items: center; gap: 8px; }
        .subject-section-title svg { width: 15px; height: 15px; fill: currentColor; }
        .sp-divider { height: 1px; background: var(--border-light); margin: 20px 0; }

        /* ── Faculty assign ── */
        .faculty-assign-card { display: flex; align-items: center; justify-content: space-between; padding: 14px 16px; background: var(--bg-base); border: 1px solid var(--border-light); border-radius: 8px; margin-bottom: 12px; }
        .faculty-assign-info { display: flex; align-items: center; gap: 12px; }
        .faculty-avatar { width: 36px; height: 36px; border-radius: 50%; background: var(--accent-green-dim); color: var(--accent-green); font-weight: 600; font-size: 13px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .faculty-name { font-size: 14px; font-weight: 500; color: var(--text-primary); }
        .faculty-role { font-size: 12px; color: var(--text-secondary); }
        .faculty-unassigned { color: var(--text-muted); font-style: italic; font-size: 13px; }

        /* ── Student list ── */
        .student-list { display: flex; flex-direction: column; gap: 8px; margin-bottom: 14px; }
        .student-item { display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; background: var(--bg-base); border: 1px solid var(--border-light); border-radius: 8px; }
        .student-info { display: flex; align-items: center; gap: 12px; }
        .student-avatar { width: 32px; height: 32px; border-radius: 50%; background: var(--accent-blue-dim); color: var(--accent-blue); font-weight: 600; font-size: 12px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .student-name { font-size: 13px; font-weight: 500; color: var(--text-primary); }
        .student-username { font-size: 11px; color: var(--text-muted); }
        .remove-btn { padding: 4px 10px; font-size: 12px; font-weight: 500; color: var(--accent-red); background: var(--accent-red-dim); border-radius: 4px; cursor: pointer; transition: all var(--ease); border: none; font-family: inherit; }
        .remove-btn:hover { background: var(--accent-red); color: white; }
        .no-students { font-size: 13px; color: var(--text-muted); font-style: italic; padding: 8px 0; }

        /* ── Inline select row ── */
        .inline-select { width: 100%; padding: 10px 14px; border: 1px solid var(--border-light); border-radius: 6px; font-family: 'Poppins', sans-serif; font-size: 13px; color: var(--text-primary); background: var(--bg-surface); outline: none; }
        .inline-select:focus { border-color: var(--accent-blue); }
        .inline-select-row { display: flex; gap: 8px; align-items: center; margin-bottom: 12px; }
        .inline-select-row .inline-select { flex: 1; }

        /* ── No subjects / Empty ── */
        .no-subjects { text-align: center; padding: 40px 24px; color: var(--text-muted); }
        .no-subjects svg { width: 40px; height: 40px; fill: var(--border-light); margin-bottom: 10px; }
        .no-subjects p { font-size: 13px; }
        .empty-state { text-align: center; padding: 64px 24px; color: var(--text-muted); }
        .empty-state p { font-size: 14px; margin-top: 12px; }

        /* ── Modal ── */
        .modal-overlay { position: fixed; inset: 0; background: rgba(60,64,67,0.4); z-index: 2000; display: flex; align-items: center; justify-content: center; padding: 24px; opacity: 0; pointer-events: none; transition: opacity var(--ease); }
        .modal-overlay.open { opacity: 1; pointer-events: all; }
        .modal { background: var(--bg-surface); border-radius: 12px; width: 100%; max-width: 520px; box-shadow: var(--shadow-modal); transform: translateY(16px) scale(0.98); transition: transform var(--ease); }
        .modal-overlay.open .modal { transform: translateY(0) scale(1); }
        .modal-header { display: flex; align-items: center; justify-content: space-between; padding: 24px 24px 16px; border-bottom: 1px solid var(--border-light); }
        .modal-title { font-size: 18px; font-weight: 600; color: var(--text-primary); }
        .modal-close { width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: var(--text-muted); transition: background var(--ease); }
        .modal-close:hover { background: rgba(60,64,67,0.08); color: var(--text-primary); }
        .modal-close svg { width: 18px; height: 18px; fill: currentColor; }
        .modal-body { padding: 24px; }
        .modal-footer { display: flex; gap: 12px; justify-content: flex-end; padding: 16px 24px; border-top: 1px solid var(--border-light); }
        .form-group { margin-bottom: 18px; }
        .form-label { display: block; font-size: 13px; font-weight: 500; color: var(--text-secondary); margin-bottom: 6px; }
        .form-input { width: 100%; padding: 10px 14px; border: 1px solid var(--border-light); border-radius: 6px; font-family: 'Poppins', sans-serif; font-size: 14px; color: var(--text-primary); background: var(--bg-surface); outline: none; transition: border-color var(--ease); }
        .form-input:focus { border-color: var(--accent-blue); }
        .form-select { width: 100%; padding: 10px 14px; border: 1px solid var(--border-light); border-radius: 6px; font-family: 'Poppins', sans-serif; font-size: 14px; color: var(--text-primary); background: var(--bg-surface); outline: none; }
        .form-select:focus { border-color: var(--accent-blue); }

        /* ── Toast ── */
        .toast { position: fixed; bottom: 24px; right: 24px; z-index: 9999; padding: 12px 20px; border-radius: 8px; font-size: 14px; font-weight: 500; color: #fff; box-shadow: 0 4px 16px rgba(0,0,0,0.15); transition: opacity 0.3s; }

        /* ── Dark mode ── */
        [data-theme="dark"] {
            --bg-base:#07111f; --bg-surface:#0d1b2e; --bg-elevated:#13202e; --border-light:rgba(125,139,164,.22);
            --text-primary:#eef4ff; --text-secondary:#aeb9cb; --text-muted:#7f8ca3;
            --accent-green:#0e9f6e; --accent-green-hover:#0c8a5f; --accent-green-dim:rgba(14,159,110,0.12);
            --accent-blue:#0e9f6e; --accent-blue-hover:#0c8a5f; --accent-blue-dim:rgba(14,159,110,0.12);
        }
        [data-theme="dark"] body { background: var(--bg-base); }
        [data-theme="dark"] .brand-logo-light { display: none; }
        [data-theme="dark"] .brand-logo-dark  { display: block; }
        [data-theme="dark"] .icon-moon { display: none; }
        [data-theme="dark"] .icon-sun  { display: block; }
        [data-theme="dark"] .theme-switch { background: #1a7a4a; }
        [data-theme="dark"] .theme-switch-knob { transform: translateX(20px); }
        [data-theme="dark"] .account-popover { background: #1c222d; }
        
        [data-theme="dark"] .account-signout:hover,
        [data-theme="dark"] .account-theme-toggle:hover { background: #2d3547; }

        /* ── Responsive ── */
        @media (max-width: 1024px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.mobile-open { transform: translateX(0); }
            .main { margin-left: 0; padding: 24px; }
        }

        /* Dashboard-aligned admin shell */
        :root {
            --bg-base:#f5f6fa;
            --bg-surface:#ffffff;
            --bg-elevated:#f0f2f7;
            --border-light:#e8eaef;
            --text-primary:#1a1d2e;
            --text-secondary:#52576e;
            --text-muted:#8b90a7;
            --accent-green:#1a7a4a;
            --accent-green-hover:#155f3a;
            --accent-green-dim:rgba(26,122,74,0.10);
            --accent-blue:#1a7a4a;
            --accent-blue-hover:#155f3a;
            --accent-blue-dim:rgba(26,122,74,0.10);
            --accent-red:#d93025;
            --accent-red-hover:#a50e0e;
            --accent-red-dim:#fce8e6;
            --accent-yellow:#d97706;
            --accent-yellow-dim:rgba(217,119,6,0.10);
            --accent-purple:#52576e;
            --accent-purple-dim:#f0f2f7;
            --sidebar-w:260px;
            --topbar-h:60px;
        }
        body { background: var(--bg-base); }
        .main { padding: 32px 36px 48px; max-width: 1440px; }
        .panel,
        .kpi-card,
        .toolbar,
        .class-accordion { border-color: var(--border-light); border-radius: 14px; box-shadow: none; }
        .nav-badge { background: var(--accent-red); color: #fff; border-radius: 20px; }
        .nav-badge.dim { background: var(--bg-elevated); color: var(--text-muted); }
        .class-icon.blue,
        .class-icon.green { background: var(--accent-green-dim); color: var(--accent-green); }
        .class-icon.yellow { background: var(--accent-yellow-dim); color: var(--accent-yellow); }
        .class-icon.red { background: var(--accent-red-dim); color: var(--accent-red); }
        [data-theme="dark"] {
            --bg-base:#07111f;
            --bg-surface:#0d1b2e;
            --bg-elevated:#13202e;
            --border-light:rgba(125,139,164,.22);
            --text-primary:#eef4ff;
            --text-secondary:#aeb9cb;
            --text-muted:#7f8ca3;
            --accent-green:#0e9f6e;
            --accent-green-hover:#0c8a5f;
            --accent-green-dim:rgba(14,159,110,0.12);
            --accent-blue:#0e9f6e;
            --accent-blue-hover:#0c8a5f;
            --accent-blue-dim:rgba(14,159,110,0.12);
            --accent-red-dim:rgba(229,139,137,0.18);
            --accent-yellow-dim:rgba(217,119,6,0.18);
            --accent-purple-dim:rgba(125,139,164,0.14);
        }
        [data-theme="dark"] .btn-outline {
            background: rgba(255,255,255,0.04);
            border-color: rgba(125,139,164,0.22);
            color: var(--text-secondary);
        }
        [data-theme="dark"] .btn-outline:hover {
            background: rgba(255,255,255,0.10);
            color: var(--text-primary);
        }
        [data-theme="dark"] .account-popover-close,
        [data-theme="dark"] .account-role-pill,
        [data-theme="dark"] .account-signout,
        [data-theme="dark"] .account-theme-toggle {
            background: rgba(255,255,255,0.08);
            border-color: rgba(125,139,164,0.16);
        }
    </style>
</head>
<body>

<svg style="display:none" xmlns="http://www.w3.org/2000/svg">
    <symbol id="ic-menu"      viewBox="0 0 24 24"><path d="M3 18h18v-2H3v2zm0-5h18v-2H3v2zm0-7v2h18V6H3z"/></symbol>
    <symbol id="ic-class"     viewBox="0 0 24 24"><path d="M18 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-2 14H8v-2h8v2zm0-4H8v-2h8v2zm0-4H8V6h8v2z"/></symbol>
    <symbol id="ic-dashboard" viewBox="0 0 24 24"><path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/></symbol>
    <symbol id="ic-users"     viewBox="0 0 24 24"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></symbol>
    <symbol id="ic-bell"      viewBox="0 0 24 24"><path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6v-5c0-3.07-1.64-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.63 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z"/></symbol>
    <symbol id="ic-pending"   viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67V7z"/></symbol>
    <symbol id="ic-settings"  viewBox="0 0 24 24"><path d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.06-.94l2.03-1.58c.18-.14.23-.41.12-.61l-1.92-3.32c-.12-.22-.37-.29-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54c-.04-.24-.24-.41-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.56-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.04.3-.07.63-.07.94s.02.64.07.94l-2.03 1.58c-.18.14-.23.41-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z"/></symbol>
    <symbol id="ic-logout"    viewBox="0 0 24 24"><path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/></symbol>
    <symbol id="ic-archive"   viewBox="0 0 24 24"><path d="M20.54 5.23l-1.39-1.68C18.88 3.21 18.47 3 18 3H6c-.47 0-.88.21-1.16.55L3.46 5.23C3.17 5.57 3 6.02 3 6.5V19c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V6.5c0-.48-.17-.93-.46-1.27zM12 17.5L6.5 12H10v-2h4v2h3.5L12 17.5zM5.12 5l.81-1h12l.94 1H5.12z"/></symbol>
    <symbol id="ic-add"       viewBox="0 0 24 24"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></symbol>
    <symbol id="ic-close"     viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></symbol>
    <symbol id="ic-person"    viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></symbol>
    <symbol id="ic-book"      viewBox="0 0 24 24"><path d="M18 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zM6 4h5v8l-2.5-1.5L6 12V4z"/></symbol>
    <symbol id="ic-chevron"   viewBox="0 0 24 24"><path d="M7.41 8.59L12 13.17l4.59-4.58L18 10l-6 6-6-6 1.41-1.41z"/></symbol>
    <symbol id="ic-edit"      viewBox="0 0 24 24"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></symbol>
    <symbol id="ic-trash"     viewBox="0 0 24 24"><path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></symbol>
</svg>

<!-- TOPBAR -->
<header class="topbar">
    <div class="topbar-brand">
        <button class="menu-toggle" id="menuToggle" aria-label="Toggle menu">
            <span></span><span></span><span></span>
        </button>
        <div class="brand-icon">
            <img class="brand-logo-img brand-logo-light" src="img/Light Icon.png" alt="Helios">
            <img class="brand-logo-img brand-logo-dark"  src="img/Dark Icon.png"  alt="Helios">
        </div>
        <div class="brand-wordmark">Helios<span>Admin Center</span></div>
    </div>
    <div class="topbar-center">
        <span class="topbar-clock" id="liveClock"></span>
    </div>
    <div class="topbar-right">
        <a href="role_admin_systemSettings.php" class="topbar-icon-btn" title="System Settings">
            <svg><use href="#ic-settings"></use></svg>
        </a>
        <div class="topbar-divider"></div>
        <button type="button" class="avatar-btn" id="accountButton" aria-haspopup="dialog" aria-expanded="false">
            <div class="avatar-circle"><svg viewBox="0 0 24 24" style="width:18px;height:18px;fill:#fff;"><path d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.06-.94l2.03-1.58c.18-.14.23-.41.12-.61l-1.92-3.32c-.12-.22-.37-.29-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54c-.04-.24-.24-.41-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59-.24-1.13-.56-1.62-.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.49.12.61l2.03 1.58c-.05.3-.09.63-.09.94s.02.64.06.94l-2.03 1.58c-.18.14-.23.41-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .43-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.49-.12-.61l-2.01-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z"/></svg></div>
            <div class="avatar-meta">
                <div class="avatar-name"><?= htmlspecialchars($username) ?></div>
                <div class="avatar-role">Administrator</div>
            </div>
        </button>
    </div>
</header>

<div class="account-popover" id="accountPopover" role="dialog" aria-label="Account overview">
    <button type="button" class="account-popover-close" id="accountPopoverClose" aria-label="Close">&times;</button>
    <div class="account-email"><?= htmlspecialchars($username) ?></div>
    <div class="account-avatar-large"><?= $initials ?></div>
    <div class="account-greeting">Hi, <?= htmlspecialchars($username) ?>!</div>
    <div class="account-role-pill">Administrator Account</div>
    <div class="account-actions">
        <button type="button" class="account-theme-toggle theme-toggle" aria-label="Toggle dark mode">
            <span class="theme-copy">
                <svg class="icon-moon" viewBox="0 0 24 24"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
                <svg class="icon-sun"  viewBox="0 0 24 24"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
                Dark mode
            </span>
            <span class="theme-switch" aria-hidden="true"><span class="theme-switch-knob"></span></span>
        </button>
        <a href="logout.php" class="account-signout">
            <svg><use href="#ic-logout"></use></svg> Sign out
        </a>
    </div>
</div>

<div class="body-wrap">
    <!-- SIDEBAR -->
    <nav class="sidebar" id="sidebar">
        <div class="nav-section-label">Overview</div>
        <a href="dashboard_admin.php" class="nav-link">
            <svg><use href="#ic-dashboard"></use></svg> Dashboard
        </a>
        <div class="nav-divider"></div>
        <div class="nav-section-label">Management</div>
        <a href="role_admin_manageUsers.php" class="nav-link">
            <svg><use href="#ic-users"></use></svg> Manage Users
            <?php if ($totalUsers > 0): ?><span class="nav-badge dim"><?= $totalUsers ?></span><?php endif; ?>
        </a>
        <a href="pending_approval.php" class="nav-link">
            <svg><use href="#ic-pending"></use></svg> Pending Approval
            <?php if ($pendingCount > 0): ?><span class="nav-badge"><?= $pendingCount ?></span><?php endif; ?>
        </a>
        <a href="role_admin_manageClasses.php" class="nav-link active">
            <svg><use href="#ic-class"></use></svg> Manage Classes
        </a>
        <div class="nav-divider"></div>
        <div class="nav-section-label">System</div>
        <a href="role_admin_systemSettings.php" class="nav-link">
            <svg><use href="#ic-settings"></use></svg> System Settings
        </a>
        <div class="sidebar-footer">
            <a href="logout.php" class="nav-link">
                <svg><use href="#ic-logout"></use></svg> Sign Out
            </a>
        </div>
    </nav>

    <!-- MAIN -->
    <main class="main" id="mainContent">

        <div class="page-header">
            <div>
                <h1 class="page-title">Manage Classes</h1>
                <p class="page-subtitle">Create classes, manage subjects, assign faculty, and enroll students.</p>
            </div>
            <div style="display:flex;align-items:center;gap:16px;">
                <button class="btn btn-green" onclick="openModal('modalCreateClass')">
                    <svg><use href="#ic-add"></use></svg> New Class
                </button>
                <div>
                    <div class="page-date" id="currentDate"></div>
                    <div class="page-date-sub">Platform Time</div>
                </div>
            </div>
        </div>

        <!-- KPI Strip -->
        <div class="kpi-strip">
            <div class="kpi-card blue">
                <div class="kpi-header">
                    <div class="kpi-icon"><svg><use href="#ic-class"></use></svg></div>
                    <div class="kpi-label">Total Classes</div>
                </div>
                <div class="kpi-value" id="kpiTotal"><?= $totalClasses ?></div>
            </div>
            <div class="kpi-card green">
                <div class="kpi-header">
                    <div class="kpi-icon"><svg><use href="#ic-class"></use></svg></div>
                    <div class="kpi-label">Active Classes</div>
                </div>
                <div class="kpi-value" id="kpiActive"><?= $activeClasses ?></div>
            </div>
            <div class="kpi-card yellow">
                <div class="kpi-header">
                    <div class="kpi-icon"><svg><use href="#ic-archive"></use></svg></div>
                    <div class="kpi-label">Archived Classes</div>
                </div>
                <div class="kpi-value" id="kpiArchived"><?= $archivedClasses ?></div>
            </div>
            <div class="kpi-card blue">
                <div class="kpi-header">
                    <div class="kpi-icon"><svg><use href="#ic-users"></use></svg></div>
                    <div class="kpi-label">Faculty Members</div>
                </div>
                <div class="kpi-value"><?= $totalFaculty ?></div>
            </div>
        </div>

        <!-- Toolbar -->
        <div class="toolbar">
            <div class="search-wrap">
                <svg class="search-icon" viewBox="0 0 24 24"><path d="M15.5 14h-.79l-.28-.27C15.41 12.59 16 11.11 16 9.5 16 5.91 13.09 3 9.5 3S3 5.91 3 9.5 5.91 16 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>
                <input type="text" class="search-input" id="classSearch" placeholder="Search by class name, subject, or owner…">
            </div>
            <select class="filter-select" id="statusFilter">
                <option value="">All Status</option>
                <option value="active">Active</option>
                <option value="archived">Archived</option>
            </select>
            <span class="count-text" id="classCount"><?= $totalClasses ?> class<?= $totalClasses !== 1 ? 'es' : '' ?></span>
        </div>

        <!-- Classes Accordion List -->
        <div id="accordionList">
        <?php if (empty($allClasses)): ?>
            <div class="empty-state" id="emptyState">
                <svg viewBox="0 0 24 24" style="width:48px;height:48px;fill:var(--border-light);"><use href="#ic-class"></use></svg>
                <p>No classes yet. Click <strong>+ New Class</strong> to create your first one.</p>
            </div>
        <?php else: ?>
        <?php
        $iconColors = ['blue','green','yellow','red'];
        $i = 0;
        foreach ($allClasses as $class):
            $cId        = $class['id'];
            $cName      = $class['name'];
            $cSubject   = $class['subject'];
            $cOwner     = $class['owner'] ?? '';
            $cOwnerName = $nameMap[$cOwner] ?? $cOwner;
            $cMemberCnt = $memberCountByClass[$cId] ?? 0;
            $cStatus    = $class['status'];
            $cSubjects  = $subjectsByClass[$cId] ?? [];
            $color      = $iconColors[$i % count($iconColors)];
            $letter     = strtoupper(substr($cName, 0, 1));
            $i++;
        ?>
        <div class="class-accordion"
             data-name="<?= htmlspecialchars(strtolower($cName)) ?>"
             data-subject="<?= htmlspecialchars(strtolower($cSubject)) ?>"
             data-owner="<?= htmlspecialchars(strtolower($cOwner . ' ' . $cOwnerName)) ?>"
             data-status="<?= htmlspecialchars($cStatus) ?>">

            <div class="class-accordion-header" onclick="toggleAccordion(this)">
                <div class="class-icon <?= $color ?>"><?= htmlspecialchars($letter) ?></div>
                <div style="flex:1;min-width:0;">
                    <div class="class-name"><?= htmlspecialchars($cName) ?></div>
                    <div class="class-id"><?= htmlspecialchars($cId) ?> &mdash; <?= htmlspecialchars($cSubject) ?></div>
                </div>
                <div style="display:flex;align-items:center;gap:12px;margin-right:12px;">
                    <span class="status-chip <?= htmlspecialchars($cStatus) ?>"><?= htmlspecialchars($cStatus) ?></span>
                    <span style="font-size:12px;color:var(--text-secondary);"><?= $cMemberCnt ?> enrolled</span>
                    <span style="font-size:12px;color:var(--text-muted);"><?= count($cSubjects) ?> subject<?= count($cSubjects) !== 1 ? 's' : '' ?></span>
                    <div class="action-btns" onclick="event.stopPropagation()">
                        <button class="btn-icon" title="<?= $cStatus === 'archived' ? 'Restore' : 'Archive' ?> class"
                                onclick="archiveClass('<?= htmlspecialchars($cId) ?>')">
                            <svg><use href="#ic-archive"></use></svg>
                        </button>
                        <button class="btn-icon danger" title="Delete class"
                                onclick="deleteClass('<?= htmlspecialchars($cId) ?>', '<?= htmlspecialchars(addslashes($cName)) ?>')">
                            <svg><use href="#ic-trash"></use></svg>
                        </button>
                    </div>
                </div>
                <svg class="accordion-chevron"><use href="#ic-chevron"></use></svg>
            </div>

            <div class="class-accordion-body" id="body-<?= htmlspecialchars($cId) ?>">
                <!-- Subject Tabs -->
                <div class="subject-tabs" id="tabs-<?= htmlspecialchars($cId) ?>">
                    <?php foreach ($cSubjects as $sidx => $subj):
                        $sId   = $subj['id'];
                        $sName = $subj['name'];
                    ?>
                    <div class="subject-tab <?= $sidx === 0 ? 'active' : '' ?>"
                         onclick="switchTab('<?= htmlspecialchars($cId) ?>', '<?= htmlspecialchars($sId) ?>', this)">
                        <svg style="width:13px;height:13px;fill:currentColor;"><use href="#ic-book"></use></svg>
                        <?= htmlspecialchars($sName) ?>
                    </div>
                    <?php endforeach; ?>
                    <div class="subject-tab-add" onclick="openAddSubjectModal('<?= htmlspecialchars($cId) ?>')">
                        <svg><use href="#ic-add"></use></svg> Add Subject
                    </div>
                </div>

                <!-- No subjects placeholder -->
                <?php if (empty($cSubjects)): ?>
                <div class="no-subjects" id="nosubjects-<?= htmlspecialchars($cId) ?>">
                    <svg viewBox="0 0 24 24"><use href="#ic-book"></use></svg>
                    <p>No subjects yet. Click <strong>Add Subject</strong> to create one.</p>
                </div>
                <?php else: ?>
                <div id="nosubjects-<?= htmlspecialchars($cId) ?>" style="display:none;"></div>
                <?php endif; ?>

                <!-- Subject Panels -->
                <?php foreach ($cSubjects as $sidx => $subj):
                    $sId       = $subj['id'];
                    $sName     = $subj['name'];
                    $sFaculty  = $subj['faculty'] ?? '';
                    $sStudents = $membersBySubject[$sId] ?? [];
                    $sFacName  = $sFaculty ? ($nameMap[$sFaculty] ?? $sFaculty) : '';
                    $sFacInit  = $sFacName ? strtoupper(substr($sFacName, 0, 2)) : '';

                    // Students NOT yet enrolled in this subject (active students)
                    $enrolledInSubject = $sStudents;
                    $availableStudents = array_filter($studentUsers, fn($s) => !in_array($s['username'], $enrolledInSubject, true));
                ?>
                <div class="subject-panel <?= $sidx === 0 ? 'active' : '' ?>"
                     id="panel-<?= htmlspecialchars($cId) ?>-<?= htmlspecialchars($sId) ?>">

                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;">
                        <div>
                            <div style="font-size:16px;font-weight:600;color:var(--text-primary);"><?= htmlspecialchars($sName) ?></div>
                            <div style="font-size:12px;color:var(--text-muted);">Subject ID: <?= htmlspecialchars($sId) ?></div>
                        </div>
                        <button class="btn btn-danger"
                                onclick="deleteSubject('<?= htmlspecialchars($cId) ?>', '<?= htmlspecialchars($sId) ?>', '<?= htmlspecialchars(addslashes($sName)) ?>')">
                            <svg><use href="#ic-trash"></use></svg> Remove Subject
                        </button>
                    </div>

                    <!-- Faculty Section -->
                    <div class="subject-section-title">
                        <svg><use href="#ic-person"></use></svg> Assigned Faculty
                    </div>
                    <div class="faculty-assign-card" id="fac-card-<?= htmlspecialchars($cId) ?>-<?= htmlspecialchars($sId) ?>">
                        <div class="faculty-assign-info">
                            <?php if ($sFaculty && $sFacName): ?>
                            <div class="faculty-avatar"><?= htmlspecialchars($sFacInit) ?></div>
                            <div>
                                <div class="faculty-name"><?= htmlspecialchars($sFacName) ?></div>
                                <div class="faculty-role">Faculty &mdash; @<?= htmlspecialchars($sFaculty) ?></div>
                            </div>
                            <?php else: ?>
                            <div class="faculty-avatar" style="background:var(--bg-base);color:var(--text-muted);">—</div>
                            <div class="faculty-unassigned">No faculty assigned yet</div>
                            <?php endif; ?>
                        </div>
                        <button class="btn btn-outline"
                                onclick="openAssignFacultyModal('<?= htmlspecialchars($cId) ?>', '<?= htmlspecialchars($sId) ?>', '<?= htmlspecialchars(addslashes($sName)) ?>', '<?= htmlspecialchars($sFaculty) ?>')">
                            <svg><use href="#ic-edit"></use></svg>
                            <?= $sFaculty ? 'Change Faculty' : 'Assign Faculty' ?>
                        </button>
                    </div>

                    <div class="sp-divider"></div>

                    <!-- Students Section -->
                    <div class="subject-section-title">
                        <svg><use href="#ic-users"></use></svg> Enrolled Students
                        <span style="font-size:12px;font-weight:400;color:var(--text-muted);text-transform:none;"><?= count($sStudents) ?> enrolled</span>
                    </div>

                    <div class="student-list" id="studentlist-<?= htmlspecialchars($cId) ?>-<?= htmlspecialchars($sId) ?>">
                    <?php if (empty($sStudents)): ?>
                        <div class="no-students">No students enrolled in this subject yet.</div>
                    <?php else: ?>
                        <?php foreach ($sStudents as $stu):
                            $stuName = $nameMap[$stu] ?? $stu;
                            $stuInit = strtoupper(substr($stuName, 0, 2));
                        ?>
                        <div class="student-item" id="stuitem-<?= htmlspecialchars($cId) ?>-<?= htmlspecialchars($sId) ?>-<?= htmlspecialchars($stu) ?>">
                            <div class="student-info">
                                <div class="student-avatar"><?= htmlspecialchars($stuInit) ?></div>
                                <div>
                                    <div class="student-name"><?= htmlspecialchars($stuName) ?></div>
                                    <div class="student-username">@<?= htmlspecialchars($stu) ?></div>
                                </div>
                            </div>
                            <button class="remove-btn"
                                    onclick="removeStudent('<?= htmlspecialchars($cId) ?>', '<?= htmlspecialchars($sId) ?>', '<?= htmlspecialchars($stu) ?>', '<?= htmlspecialchars(addslashes($stuName)) ?>')">
                                Remove
                            </button>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </div>

                    <!-- Enroll Student -->
                    <div class="inline-select-row">
                        <select class="inline-select" id="enrollsel-<?= htmlspecialchars($cId) ?>-<?= htmlspecialchars($sId) ?>">
                            <option value=""> Enroll a student</option>
                            <?php foreach ($availableStudents as $s): ?>
                            <option value="<?= htmlspecialchars($s['username']) ?>">
                                <?= htmlspecialchars($s['fullname']) ?> (@<?= htmlspecialchars($s['username']) ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn btn-primary"
                                onclick="enrollStudent('<?= htmlspecialchars($cId) ?>', '<?= htmlspecialchars($sId) ?>')">
                            <svg><use href="#ic-add"></use></svg> Enroll
                        </button>
                    </div>

                </div><!-- /subject-panel -->
                <?php endforeach; ?>

            </div><!-- /class-accordion-body -->
        </div><!-- /class-accordion -->
        <?php endforeach; ?>

        <!-- Empty search state (hidden by default when there are classes) -->
        <div class="empty-state" id="emptyState" style="display:none;">
            <svg viewBox="0 0 24 24" style="width:48px;height:48px;fill:var(--border-light);"><use href="#ic-class"></use></svg>
            <p>No classes match your search or filter.</p>
        </div>
        <?php endif; ?>
        </div><!-- /accordionList -->

    </main>
</div>

<!-- ═══════════════════════════════════════════════
     MODAL: Create New Class
════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modalCreateClass">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-title">Create New Class</div>
            <button class="modal-close" onclick="closeModal('modalCreateClass')">
                <svg><use href="#ic-close"></use></svg>
            </button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label">Class Name <span style="color:var(--accent-red)">*</span></label>
                <input type="text" class="form-input" id="newClassName" placeholder="e.g. CLASS-1A">
            </div>
            <div class="form-group">
                <label class="form-label">Status</label>
                <select class="form-select" id="newClassStatus">
                    <option value="active">Active</option>
                    <option value="archived">Archived</option>
                </select>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('modalCreateClass')">Cancel</button>
            <button class="btn btn-green" onclick="createClass()">
                <svg><use href="#ic-add"></use></svg> Create Class
            </button>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════
     MODAL: Add Subject
════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modalAddSubject">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-title">Add Subject</div>
            <button class="modal-close" onclick="closeModal('modalAddSubject')">
                <svg><use href="#ic-close"></use></svg>
            </button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="addSubjectClassId">
            <div class="form-group">
                <label class="form-label">Subject Name <span style="color:var(--accent-red)">*</span></label>
                <input type="text" class="form-input" id="newSubjectName" placeholder="e.g. Differential Equations">
            </div>
            <div class="form-group">
                <label class="form-label">Assign Faculty (optional)</label>
                <select class="form-select" id="newSubjectFaculty">
                    <option value="">— No faculty yet —</option>
                    <?php foreach ($facultyUsers as $fac): ?>
                    <option value="<?= htmlspecialchars($fac['username']) ?>">
                        <?= htmlspecialchars($fac['fullname']) ?> (@<?= htmlspecialchars($fac['username']) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('modalAddSubject')">Cancel</button>
            <button class="btn btn-primary" onclick="addSubject()">
                <svg><use href="#ic-add"></use></svg> Add Subject
            </button>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════
     MODAL: Assign Faculty
════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modalAssignFaculty">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-title">Assign Faculty</div>
            <button class="modal-close" onclick="closeModal('modalAssignFaculty')">
                <svg><use href="#ic-close"></use></svg>
            </button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="assignFacClassId">
            <input type="hidden" id="assignFacSubjectId">
            <div style="font-size:13px;color:var(--text-secondary);margin-bottom:18px;">
                Assigning faculty to: <strong id="assignFacSubjectName"></strong>
            </div>
            <div class="form-group">
                <label class="form-label">Select Faculty Member</label>
                <select class="form-select" id="assignFacSelect">
                    <option value="">None</option>
                    <?php foreach ($facultyUsers as $fac): ?>
                    <option value="<?= htmlspecialchars($fac['username']) ?>">
                        <?= htmlspecialchars($fac['fullname']) ?> (@<?= htmlspecialchars($fac['username']) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('modalAssignFaculty')">Cancel</button>
            <button class="btn btn-primary" onclick="assignFaculty()">
                <svg><use href="#ic-person"></use></svg> Save 
            </button>
        </div>
    </div>
</div>

<script>
/* ══════════════════════════════════════════════════
   JAVASCRIPT
══════════════════════════════════════════════════ */

/* ── Helpers ──────────────────────────────────────────────────────── */
function esc(str) {
    return String(str)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function postAdminClassAction(action, payload = {}) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.style.display = 'none';
    const fields = { admin_class_action: action, ...payload };
    Object.entries(fields).forEach(([name, value]) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value ?? '';
        form.appendChild(input);
    });
    document.body.appendChild(form);
    form.submit();
}

function toast(msg, type = 'success') {
    const t = document.createElement('div');
    t.className = 'toast';
    t.style.background = type === 'error' ? 'var(--accent-red)'
                       : type === 'warn'  ? 'var(--accent-yellow)'
                       : 'var(--accent-green)';
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(() => { t.style.opacity = '0'; setTimeout(() => t.remove(), 300); }, 2800);
}

/* ── Modals ───────────────────────────────────────────────────────── */
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.modal-overlay').forEach(o => {
    o.addEventListener('click', e => { if (e.target === o) o.classList.remove('open'); });
});

/* ── Accordion toggle ─────────────────────────────────────────────── */
function toggleAccordion(header) {
    const body   = header.nextElementSibling;
    const isOpen = header.classList.contains('open');
    header.classList.toggle('open', !isOpen);
    body.classList.toggle('open', !isOpen);
}

/* ── Subject tab switching ────────────────────────────────────────── */
function switchTab(cId, sId, tabEl) {
    const tabsContainer = document.getElementById('tabs-' + cId);
    tabsContainer.querySelectorAll('.subject-tab').forEach(t => t.classList.remove('active'));
    tabEl.classList.add('active');
    const body = document.getElementById('body-' + cId);
    body.querySelectorAll('.subject-panel').forEach(p => p.classList.remove('active'));
    const panel = document.getElementById('panel-' + cId + '-' + sId);
    if (panel) panel.classList.add('active');
}

/* ── Search / Filter ──────────────────────────────────────────────── */
const searchInput   = document.getElementById('classSearch');
const statusFilter  = document.getElementById('statusFilter');
const classCount    = document.getElementById('classCount');
const emptyState    = document.getElementById('emptyState');

function applyFilters() {
    const q      = searchInput.value.toLowerCase().trim();
    const status = statusFilter.value;
    let visible  = 0;
    document.querySelectorAll('.class-accordion').forEach(acc => {
        const matchQ      = !q      || acc.dataset.name.includes(q) || acc.dataset.subject.includes(q) || acc.dataset.owner.includes(q);
        const matchStatus = !status || acc.dataset.status === status;
        const show = matchQ && matchStatus;
        acc.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    if (emptyState) emptyState.style.display = visible === 0 ? 'block' : 'none';
    classCount.textContent = visible + ' class' + (visible !== 1 ? 'es' : '');
}
searchInput.addEventListener('input', applyFilters);
statusFilter.addEventListener('change', applyFilters);

/* ── Create Class ─────────────────────────────────────────────────── */
function createClass() {
    const name   = document.getElementById('newClassName').value.trim();
    const status = document.getElementById('newClassStatus').value;
    if (!name) { toast('Please enter a class name.', 'error'); return; }
    postAdminClassAction('create_class', { name, status });
}

/* ── Archive / Restore Class ──────────────────────────────────────── */
function archiveClass(cId) {
    postAdminClassAction('toggle_archive', { class_id: cId });
}

/* ── Delete Class ─────────────────────────────────────────────────── */
function deleteClass(cId, cName) {
    if (!confirm(`Delete "${cName}"? This cannot be undone.`)) return;
    postAdminClassAction('delete_class', { class_id: cId });
}

/* ── Add Subject Modal ────────────────────────────────────────────── */
function openAddSubjectModal(cId) {
    document.getElementById('addSubjectClassId').value = cId;
    document.getElementById('newSubjectName').value    = '';
    document.getElementById('newSubjectFaculty').value = '';
    openModal('modalAddSubject');
}

function addSubject() {
    const cId    = document.getElementById('addSubjectClassId').value;
    const name   = document.getElementById('newSubjectName').value.trim();
    const faculty = document.getElementById('newSubjectFaculty').value;
    if (!name) { toast('Subject name is required.', 'error'); return; }
    postAdminClassAction('add_subject', { class_id: cId, name, faculty });
}

/* ── Delete Subject ───────────────────────────────────────────────── */
function deleteSubject(cId, sId, sName) {
    if (!confirm(`Remove subject "${sName}"?`)) return;
    postAdminClassAction('delete_subject', { class_id: cId, subject_id: sId });
}

/* ── Assign Faculty Modal ─────────────────────────────────────────── */
function openAssignFacultyModal(cId, sId, sName, currentFac) {
    document.getElementById('assignFacClassId').value       = cId;
    document.getElementById('assignFacSubjectId').value     = sId;
    document.getElementById('assignFacSubjectName').textContent = sName;
    document.getElementById('assignFacSelect').value        = currentFac || '';
    openModal('modalAssignFaculty');
}

function assignFaculty() {
    const cId = document.getElementById('assignFacClassId').value;
    const sId = document.getElementById('assignFacSubjectId').value;
    const fac = document.getElementById('assignFacSelect').value;
    postAdminClassAction('assign_faculty', { class_id: cId, subject_id: sId, faculty: fac });
}

/* ── Enroll Student ───────────────────────────────────────────────── */
function enrollStudent(cId, sId) {
    const sel = document.getElementById(`enrollsel-${cId}-${sId}`);
    const stu = sel ? sel.value : '';
    if (!stu) { toast('Please select a student.', 'error'); return; }
    postAdminClassAction('enroll_student', { class_id: cId, subject_id: sId, student: stu });
}

/* ── Remove Student ───────────────────────────────────────────────── */
function removeStudent(cId, sId, stu, stuName) {
    if (!confirm(`Remove ${stuName} from this subject?`)) return;
    postAdminClassAction('remove_student', { class_id: cId, subject_id: sId, student: stu });
}

/* ── Clock ────────────────────────────────────────────────────────── */
const clockEl = document.getElementById('liveClock');
const dateEl  = document.getElementById('currentDate');
function updateClock() {
    const now = new Date();
    const hh  = String(now.getHours()).padStart(2,'0');
    const mm  = String(now.getMinutes()).padStart(2,'0');
    const ss  = String(now.getSeconds()).padStart(2,'0');
    if (clockEl) clockEl.textContent = `${hh}:${mm}:${ss}`;
    if (dateEl)  dateEl.textContent  = now.toLocaleDateString('en-US', { weekday:'long', year:'numeric', month:'short', day:'numeric' });
}
updateClock();
setInterval(updateClock, 1000);

/* ── Theme ────────────────────────────────────────────────────────── */
const savedTheme = localStorage.getItem('theme') ||
    (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
document.documentElement.setAttribute('data-theme', savedTheme);
document.querySelectorAll('.theme-toggle').forEach(toggle => {
    toggle.addEventListener('click', () => {
        const next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', next);
        localStorage.setItem('theme', next);
    });
});

/* ── Sidebar toggle ───────────────────────────────────────────────── */
const sidebar      = document.getElementById('sidebar');
const mainContent  = document.getElementById('mainContent');
const menuToggle   = document.getElementById('menuToggle');
const accountButton  = document.getElementById('accountButton');
const accountPopover = document.getElementById('accountPopover');
const accountPopoverClose = document.getElementById('accountPopoverClose');

menuToggle.addEventListener('click', () => {
    if (window.innerWidth > 1024) {
        sidebar.classList.toggle('collapsed');
        mainContent.classList.toggle('expanded');
    } else {
        sidebar.classList.toggle('mobile-open');
    }
});
document.addEventListener('click', e => {
    if (window.innerWidth <= 1024 && sidebar.classList.contains('mobile-open')
        && !sidebar.contains(e.target) && !menuToggle.contains(e.target)) {
        sidebar.classList.remove('mobile-open');
    }
    if (accountPopover && accountPopover.classList.contains('open')
        && !accountPopover.contains(e.target) && !accountButton.contains(e.target)) {
        accountPopover.classList.remove('open');
        accountButton.setAttribute('aria-expanded', 'false');
    }
});
if (accountButton && accountPopover) {
    accountButton.addEventListener('click', e => {
        e.stopPropagation();
        const isOpen = accountPopover.classList.toggle('open');
        accountButton.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });
}
if (accountPopoverClose) {
    accountPopoverClose.addEventListener('click', () => {
        accountPopover.classList.remove('open');
        accountButton.setAttribute('aria-expanded', 'false');
        accountButton.focus();
    });
}
/* ── Status toasts from redirect ──────────────────────────────────── */
(function() {
    const msgs = {
        class_created:        ['Class created successfully!',       'success'],
        class_deleted:        ['Class deleted.',                     'success'],
        class_status_updated: ['Class status updated.',             'success'],
        subject_added:        ['Subject added successfully!',       'success'],
        subject_deleted:      ['Subject removed.',                  'success'],
        faculty_assigned:     ['Faculty assigned.',                 'success'],
        student_enrolled:     ['Student enrolled.',                 'success'],
        student_removed:      ['Student removed.',                  'success'],
        duplicate_class_name: ['A class with that name already exists.', 'error'],
        updated:              ['Changes saved.',                    'success'],
    };
    const s = new URLSearchParams(window.location.search).get('admin_class_status');
    if (s && msgs[s]) toast(msgs[s][0], msgs[s][1]);
})();
</script>

</body>
</html>
