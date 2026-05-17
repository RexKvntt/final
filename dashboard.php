<?php


session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once __DIR__ . '/auth_helpers.php';
enforceTemporaryPasswordDeadline();

$role = $_SESSION['role'] ?? null;

if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

if ($role === 'admin') {
    header("Location: dashboard_admin.php");
    exit();
}

$usernameRaw = $_SESSION['username'];
$username    = htmlspecialchars($usernameRaw);

require_once __DIR__ . '/db.php';

function heliosInitials(string $name): string {
    $parts = preg_split('/\s+/', trim($name));
    $letters = '';
    foreach ($parts as $part) {
        if ($part !== '') $letters .= strtoupper(substr($part, 0, 1));
        if (strlen($letters) >= 2) break;
    }
    return $letters !== '' ? $letters : '?';
}

$allUserRows = $pdo->query("SELECT username, fullname, role, status FROM users ORDER BY fullname ASC")->fetchAll(PDO::FETCH_ASSOC);
$nameMap = [];
foreach ($allUserRows as $userRow) {
    $nameMap[$userRow['username']] = $userRow['fullname'] ?? $userRow['username'];
}

// Load current user display name
$stmtMe = $pdo->prepare("SELECT firstname, lastname, fullname, role FROM users WHERE username = ?");
$stmtMe->execute([$usernameRaw]);
$meRow = $stmtMe->fetch();
$displayName = $meRow['fullname'] ?? $usernameRaw;
$initials = heliosInitials($displayName);

// Load classes based on role
$myClasses = [];

if ($role === 'faculty') {
    // Classes where this faculty is assigned to at least one subject
    $stmt = $pdo->prepare("
        SELECT DISTINCT c.*
        FROM classes c
        INNER JOIN subjects s ON s.class_id = c.id
        WHERE s.faculty = ?
    ");
    $stmt->execute([$usernameRaw]);
    $myClasses = $stmt->fetchAll();

} elseif ($role === 'student') {
    // Classes where this student is a member
    $stmt = $pdo->prepare("
        SELECT DISTINCT c.*
        FROM classes c
        JOIN class_members cm ON cm.class_id = c.id
        WHERE cm.username = ?
    ");
    $stmt->execute([$usernameRaw]);
    $myClasses = $stmt->fetchAll();
}

// Enrich each class with subjects, members, and upcoming assignment posts
foreach ($myClasses as &$cls) {
    // Get subjects for this class
    $stmtS = $pdo->prepare("SELECT * FROM subjects WHERE class_id = ?");
    $stmtS->execute([$cls['id']]);
    $cls['subjects'] = $stmtS->fetchAll();
    foreach ($cls['subjects'] as &$subject) {
        $stmtSM = $pdo->prepare("SELECT username FROM subject_members WHERE subject_id = ?");
        $stmtSM->execute([$subject['id']]);
        $subject['students'] = array_column($stmtSM->fetchAll(), 'username');
    }
    unset($subject);
    if ($role === 'student') {
        $cls['subjects'] = array_values(array_filter(
            $cls['subjects'],
            fn($subject) => in_array($usernameRaw, $subject['students'] ?? [], true)
        ));
    }

    // Get members (students) for this class
    $stmtM = $pdo->prepare("SELECT username FROM class_members WHERE class_id = ?");
    $stmtM->execute([$cls['id']]);
    $cls['members'] = array_column($stmtM->fetchAll(), 'username');

    // Get assignment posts (for "Due soon" / "To-do" on the dashboard card)
    $postSql = "SELECT id, title, type, deadline, posted_by, subject
                  FROM posts
                 WHERE class_id = ? AND type = 'assignment'";
    $postParams = [$cls['id']];
    if ($role === 'faculty') {
        $postSql .= " AND posted_by = ?";
        $postParams[] = $usernameRaw;
    }
    $postSql .= " ORDER BY deadline IS NULL ASC, deadline ASC, posted_at DESC";
    $stmtP = $pdo->prepare($postSql);
    $stmtP->execute($postParams);
    $assignPosts = $stmtP->fetchAll();

    // Attach submissions to each post (keyed by student_username)
    foreach ($assignPosts as &$post) {
        $stmtSub = $pdo->prepare(
            "SELECT student_username, score FROM submissions WHERE post_id = ?"
        );
        $stmtSub->execute([$post['id']]);
        $post['submissions'] = [];
        foreach ($stmtSub->fetchAll() as $sub) {
            $post['submissions'][$sub['student_username']] = ['score' => $sub['score']];
        }
    }
    unset($post);

    $cls['posts'] = $assignPosts;
}
unset($cls);

// Notifications
$allNotifications = [];
$stmtN = $pdo->prepare("SELECT * FROM notifications");
$stmtN->execute();
$allNotifications = $stmtN->fetchAll();

$facultyNotifications = array_values(array_filter(
    $allNotifications,
    fn($n) => in_array($usernameRaw, explode(',', $n['targets'] ?? ''), true)
));

$facultyUnreadNotificationCount = 0; // update if you track read status

$stats = [];
$facultyDueItems = [];
$facultyStudents = [];
$facultySubjects = [];
$facultyQuickActions = [];
$studentExtras = [];

if ($role === 'faculty') {
    foreach ($myClasses as $cls) {
        foreach ($cls['members'] as $member) {
            $facultyStudents[$member] = $member;
        }
        foreach ($cls['subjects'] as $subject) {
            $facultySubjects[$subject['id']] = $subject['name'];
        }
    }
    $firstClassId = $myClasses[0]['id'] ?? '';
    $facultyQuickActions = [
        ['label'=>'Open Gradebook','href'=>$firstClassId ? 'class.php?id='.urlencode($firstClassId).'#class-work' : '#','icon'=>'grade','meta'=>'Review submissions'],
        ['label'=>'Class Stream',  'href'=>$firstClassId ? 'class.php?id='.urlencode($firstClassId).'#class-stream' : '#','icon'=>'assignment','meta'=>'Post and discuss'],
        ['label'=>'Due Calendar',  'href'=>'#faculty-calendar','icon'=>'calendar','meta'=>'Track deadlines'],
    ];
}

$hour     = (int)date('H');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$heroName = $displayName;
$accountSettingsHref = $role === 'faculty' ? 'role_faculty_profile.php' : 'role_student_profile.php';
$subjectCounterSeed  = 0;
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= ucfirst($role ?: 'User') ?> Dashboard - Helios University</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:ital,wght@0,100;0,200;0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,100;1,200;1,300;1,400;1,500;1,600;1,700;1,800;1,900&display=swap" rel="stylesheet">
    <script>
        (function(){
            var t = localStorage.getItem('theme') || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
            document.documentElement.setAttribute('data-theme', t);
        })();
    </script>

    <style>
        /* ══════════════════════════════════════════════════
           DESIGN TOKENS  (exact same palette as original)
           ══════════════════════════════════════════════════ */
        :root {
            --gc-bg-main:               #f5f6fa;
            --gc-bg-surface:            #ffffff;
            --gc-bg-hover:              #f0f2f7;
            --gc-bg-active:             #e8edf5;
            --gc-border-light:          #e8eaef;
            --gc-border-medium:         #d4d9e3;
            --gc-text-primary:          #1a1d2e;
            --gc-text-secondary:        #52576e;
            --gc-text-tertiary:         #6d7388;
            --gc-accent-blue:           #1a7a4a;
            --gc-accent-blue-hover:     #14633c;
            --gc-accent-blue-dim:       rgba(26,122,74,.10);
            --gc-accent-green:          #1a7a4a;
            --gc-accent-green-hover:    #14633c;
            --gc-accent-green-dim:      rgba(26,122,74,.10);
            --gc-accent-red:            #d93025;
            --gc-accent-red-dim:        #fce8e6;
            --gc-accent-yellow:         #e3a535;
            --gc-accent-yellow-dim:     #fef7e0;
            --gc-shadow-elevation-1:    0 1px 2px 0 rgba(26,29,46,.08);
            --gc-shadow-elevation-2:    0 10px 24px rgba(26,29,46,.10);
            --gc-shadow-elevation-card: 0 8px 22px rgba(26,29,46,.08);
            --gc-shadow-card-hover:     0 14px 32px rgba(26,29,46,.12);
            --gc-shadow-modal:          0 24px 38px 3px rgba(0,0,0,.14), 0 9px 46px 8px rgba(0,0,0,.12), 0 11px 15px -7px rgba(0,0,0,.2);
            --drawer-width:             260px;
            --topbar-height:            60px;
            --border-radius-card:       14px;
            --border-radius-pill:       999px;
            --transition-standard:      200ms cubic-bezier(.4,0,.2,1);
        }

        /* ══════════════════════════════════════════════════
           RESET & BASE
           ══════════════════════════════════════════════════ */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; width: 100%; background-color: var(--gc-bg-main); color: var(--gc-text-primary); font-family: 'Poppins', system-ui, -apple-system, sans-serif !important; font-size: 14px; line-height: 1.5; -webkit-font-smoothing: antialiased; overflow-x: hidden; }
        a { text-decoration: none; color: var(--gc-accent-blue); transition: color var(--transition-standard); }
        a:hover { color: var(--gc-accent-blue-hover); }
        button { font-family: 'Poppins', sans-serif !important; background: none; border: none; cursor: pointer; color: inherit; outline: none; }
        ul, ol { list-style: none; }

        /* ══════════════════════════════════════════════════
           LAYOUT — App Shell
           ══════════════════════════════════════════════════ */
        .app-container { display: flex; flex-direction: column; min-height: 100vh; }

        /* ── Topbar ── */
        .topbar { display: flex; align-items: center; justify-content: space-between; padding: 0 24px; height: var(--topbar-height); background-color: var(--gc-bg-surface); border-bottom: 1px solid var(--gc-border-light); position: fixed; top: 0; left: 0; right: 0; z-index: 1000; }
        .topbar-left { display: flex; align-items: center; gap: 16px; }
        .menu-btn { display: flex; align-items: center; justify-content: center; width: 48px; height: 48px; border-radius: 50%; color: var(--gc-text-secondary); transition: background-color var(--transition-standard); margin-left: -12px; }
        .menu-btn:hover { background-color: var(--gc-bg-hover); }
        .brand-logo { display: flex; align-items: center; gap: 12px; font-size: 22px; font-weight: 500; color: var(--gc-text-primary); letter-spacing: -0.01em; }
        .brand-logo svg { width: 28px; height: 28px; fill: var(--gc-accent-green); }
        .topbar-right { display: flex; align-items: center; gap: 8px; }
        .icon-btn { display: flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 50%; color: var(--gc-text-secondary); transition: background-color var(--transition-standard); }
        .icon-btn:hover { background-color: var(--gc-bg-hover); }
        .icon-btn svg { width: 24px; height: 24px; fill: currentColor; }
        .user-avatar-btn { width: 40px; height: 40px; border-radius: 50%; background-color: var(--gc-accent-blue); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 16px; font-weight: 600; margin-left: 8px; border: 2px solid transparent; transition: border-color var(--transition-standard); text-transform: uppercase; }
        .user-avatar-btn:hover { border-color: rgba(26,115,232,.4); box-shadow: 0 0 0 4px rgba(26,115,232,.1); }

        /* ── Body wrapper ── */
        .body-wrapper { display: flex; flex: 1; margin-top: var(--topbar-height); position: relative; }

        /* ── Sidebar ── */
        .sidebar { width: var(--drawer-width); background-color: var(--gc-bg-surface); border-right: 1px solid var(--gc-border-light); position: fixed; top: var(--topbar-height); bottom: 0; left: 0; overflow-y: auto; transform: translateX(0); transition: transform var(--transition-standard); z-index: 900; padding: 12px 0; }
        .sidebar.collapsed { transform: translateX(-100%); }
        .nav-list { display: flex; flex-direction: column; gap: 2px; }
        .nav-item { display: flex; align-items: center; gap: 20px; padding: 12px 24px; color: var(--gc-text-primary); font-weight: 500; font-size: 14px; border-radius: 0 24px 24px 0; margin-right: 16px; transition: background-color var(--transition-standard), color var(--transition-standard); }
        .nav-item:hover { background-color: var(--gc-bg-hover); }
        .nav-item.active { background-color: rgba(26,115,232,.1); color: var(--gc-accent-blue); }
        .nav-item.active .nav-icon { fill: var(--gc-accent-blue); }
        .nav-icon { width: 24px; height: 24px; fill: var(--gc-text-secondary); flex-shrink: 0; }
        .nav-divider { height: 1px; background-color: var(--gc-border-light); margin: 12px 0; }
        .nav-section-title { font-size: 12px; font-weight: 500; color: var(--gc-text-secondary); text-transform: uppercase; letter-spacing: .05em; padding: 12px 24px 4px; }

        /* ── Main content ── */
        .main-content { flex: 1; margin-left: var(--drawer-width); padding: 32px 40px; transition: margin-left var(--transition-standard); max-width: 1600px; }
        .main-content.expanded { margin-left: 0; }

        @media (max-width: 1024px) {
            .sidebar { transform: translateX(-100%); box-shadow: var(--gc-shadow-elevation-2); }
            .sidebar.mobile-open { transform: translateX(0); }
            .main-content { margin-left: 0; padding: 24px 16px; }
        }

        /* ══════════════════════════════════════════════════
           DASHBOARD HERO
           ══════════════════════════════════════════════════ */
        .dash-header { margin-bottom: 32px; display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 16px; }
        .dash-greeting { font-size: 32px; font-weight: 400; color: var(--gc-text-primary); letter-spacing: -0.01em; line-height: 1.2; }
        .dash-subtext { font-size: 14px; color: var(--gc-text-secondary); margin-top: 8px; font-weight: 400; }
        .role-badge { display: inline-flex; align-items: center; padding: 4px 12px; border-radius: var(--border-radius-pill); font-size: 12px; font-weight: 600; letter-spacing: .05em; text-transform: uppercase; margin-left: 12px; vertical-align: middle; }
        .role-badge.admin   { background: rgba(217,48,37,.1);  color: var(--gc-accent-red); }
        .role-badge.faculty { background: rgba(30,142,62,.1);  color: var(--gc-accent-green); }
        .role-badge.student { background: rgba(26,115,232,.1); color: var(--gc-accent-blue); }

        /* ══════════════════════════════════════════════════
           CLASSROOM CARDS  (original design, unchanged)
           ══════════════════════════════════════════════════ */
        .classroom-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(360px, 1fr)); gap: 24px; margin-bottom: 48px; }

        .gc-card { background: var(--gc-bg-surface); border: 1px solid var(--gc-border-light); border-radius: var(--border-radius-card); overflow: hidden; display: flex; flex-direction: column; position: relative; transition: box-shadow var(--transition-standard); min-height: 280px; box-shadow: var(--gc-shadow-elevation-card); }
        .gc-card:hover { box-shadow: var(--gc-shadow-card-hover); }

        .gc-card-header { padding: 20px 24px; border-bottom: 1px solid var(--gc-border-light); background: linear-gradient(180deg,#fff 0%,#fcfcfd 100%); position: relative; min-height: 110px; }
        .gc-card-top-accent { position: absolute; top: 0; left: 0; right: 0; height: 4px; border-radius: var(--border-radius-card) var(--border-radius-card) 0 0; }
        .gc-card:nth-child(4n+1) .gc-card-top-accent { background: #1a73e8; }
        .gc-card:nth-child(4n+2) .gc-card-top-accent { background: #1e8e3e; }
        .gc-card:nth-child(4n+3) .gc-card-top-accent { background: #fbbc04; }
        .gc-card:nth-child(4n+4) .gc-card-top-accent { background: #d93025; }

        .gc-card-title { font-size: 20px; font-weight: 500; color: var(--gc-text-primary); line-height: 1.3; margin-bottom: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; padding-right: 32px; }
        .gc-card-title a { color: inherit; }
        .gc-card-title a:hover { text-decoration: underline; }
        .gc-card-subtitle { font-size: 14px; color: var(--gc-text-secondary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        .gc-card-menu-btn { position: absolute; top: 16px; right: 16px; width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: var(--gc-text-secondary); transition: background-color var(--transition-standard); }
        .gc-card-menu-btn:hover { background-color: rgba(0,0,0,.04); }
        .gc-card-menu-btn svg { width: 24px; height: 24px; fill: currentColor; }

        .gc-card-body { flex: 1; padding: 16px 24px; background: var(--gc-bg-surface); display: flex; flex-direction: column; }
        .gc-upcoming-label { font-size: 12px; font-weight: 600; color: var(--gc-text-secondary); text-transform: uppercase; letter-spacing: .05em; margin-bottom: 12px; }
        .gc-task-list { display: flex; flex-direction: column; gap: 12px; }
        .gc-task-item { display: flex; align-items: flex-start; gap: 12px; }
        .gc-task-icon { width: 20px; height: 20px; fill: var(--gc-accent-blue); margin-top: 2px; flex-shrink: 0; }
        .gc-task-details { flex: 1; min-width: 0; }
        .gc-task-title { font-size: 14px; font-weight: 500; color: var(--gc-text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-bottom: 2px; }
        .gc-task-due { font-size: 12px; color: var(--gc-text-secondary); }
        .gc-empty-tasks { font-size: 13px; color: var(--gc-text-tertiary); font-style: italic; }

        .gc-card-footer { padding: 12px 24px; border-top: 1px solid var(--gc-border-light); background: var(--gc-bg-surface); display: flex; justify-content: space-between; align-items: center; }
        .gc-footer-right { display: flex; gap: 8px; }
        .gc-footer-icon { width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: var(--gc-text-secondary); transition: background-color var(--transition-standard), color var(--transition-standard); }
        .gc-footer-icon:hover { background-color: rgba(0,0,0,.04); color: var(--gc-text-primary); }
        .gc-footer-icon svg { width: 20px; height: 20px; fill: currentColor; }
        .gc-card-goto { display: inline-flex; align-items: center; gap: 4px; font-size: 12px; font-weight: 600; color: var(--gc-accent-blue); padding: 5px 12px; border-radius: var(--border-radius-pill); background: var(--gc-accent-blue-dim); transition: background var(--transition-standard), color var(--transition-standard); white-space: nowrap; }
        .gc-card-goto:hover { background: var(--gc-accent-blue); color: #fff; }

        /* Subject pill shown in card footer */
        .subject-pill-row { display: flex; flex-wrap: wrap; gap: 6px; }
        .subject-pill { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: var(--border-radius-pill); font-size: 11px; font-weight: 500; background: var(--gc-bg-hover); color: var(--gc-text-secondary); border: 1px solid var(--gc-border-light); white-space: nowrap; cursor: pointer; transition: background var(--transition-standard), color var(--transition-standard); }
        .subject-pill:hover { background: var(--gc-accent-blue-dim); color: var(--gc-accent-blue); border-color: rgba(26,115,232,.2); }
        .subject-pill svg { width: 11px; height: 11px; fill: currentColor; }

        /* "Manage subjects" link in footer */
        .manage-subjects-link { font-size: 12px; font-weight: 500; color: var(--gc-accent-blue); display: flex; align-items: center; gap: 4px; padding: 4px 0; transition: color var(--transition-standard); }
        .manage-subjects-link:hover { color: var(--gc-accent-blue-hover); }
        .manage-subjects-link svg { width: 14px; height: 14px; fill: currentColor; }

        /* ── Action card (Join / Create) — original ── */
        .gc-action-card { background: transparent; border: 2px dashed var(--gc-border-medium); border-radius: var(--border-radius-card); display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 40px 24px; text-align: center; cursor: pointer; transition: all var(--transition-standard); min-height: 280px; color: var(--gc-text-secondary); }
        .gc-action-card:hover { border-color: var(--gc-accent-blue); background: rgba(26,115,232,.02); color: var(--gc-accent-blue); }
        .gc-action-icon { width: 48px; height: 48px; border-radius: 50%; background: var(--gc-bg-hover); display: flex; align-items: center; justify-content: center; margin-bottom: 16px; transition: background var(--transition-standard); }
        .gc-action-card:hover .gc-action-icon { background: rgba(26,115,232,.1); }
        .gc-action-icon svg { width: 24px; height: 24px; fill: currentColor; }
        .gc-action-title { font-size: 16px; font-weight: 500; margin-bottom: 8px; }
        .gc-action-subtitle { font-size: 13px; max-width: 200px; line-height: 1.4; }

        /* ══════════════════════════════════════════════════
           SUBJECT DRAWER  (slides in from the right)
           ══════════════════════════════════════════════════ */
        .subject-drawer-overlay { position: fixed; inset: 0; background: rgba(32,33,36,.35); z-index: 1500; opacity: 0; pointer-events: none; transition: opacity var(--transition-standard); }
        .subject-drawer-overlay.open { opacity: 1; pointer-events: all; }

        .subject-drawer { position: fixed; top: 0; right: 0; bottom: 0; width: 480px; max-width: 96vw; background: var(--gc-bg-surface); box-shadow: -4px 0 24px rgba(0,0,0,.12); z-index: 1600; display: flex; flex-direction: column; transform: translateX(100%); transition: transform .3s cubic-bezier(.4,0,.2,1); }
        .subject-drawer.open { transform: translateX(0); }

        .sd-header { display: flex; align-items: center; justify-content: space-between; padding: 20px 24px; border-bottom: 1px solid var(--gc-border-light); flex-shrink: 0; }
        .sd-class-name { font-size: 18px; font-weight: 500; color: var(--gc-text-primary); }
        .sd-class-sub { font-size: 13px; color: var(--gc-text-secondary); margin-top: 2px; }
        .sd-close { width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: var(--gc-text-secondary); transition: background var(--transition-standard); flex-shrink: 0; }
        .sd-close:hover { background: var(--gc-bg-hover); color: var(--gc-text-primary); }
        .sd-close svg { width: 22px; height: 22px; fill: currentColor; }

        /* Subject tabs inside drawer */
        .sd-tabs { display: flex; border-bottom: 1px solid var(--gc-border-light); background: var(--gc-bg-main); overflow-x: auto; flex-shrink: 0; }
        .sd-tab { padding: 12px 20px; font-size: 13px; font-weight: 500; color: var(--gc-text-secondary); cursor: pointer; white-space: nowrap; border-bottom: 2px solid transparent; transition: all var(--transition-standard); display: flex; align-items: center; gap: 6px; }
        .sd-tab:hover { color: var(--gc-text-primary); background: rgba(0,0,0,.02); }
        .sd-tab.active { color: var(--gc-accent-blue); border-bottom-color: var(--gc-accent-blue); background: var(--gc-bg-surface); }
        .sd-tab svg { width: 13px; height: 13px; fill: currentColor; }

        /* Faculty-only: "Add Subject" tab button */
        .sd-tab-add { padding: 12px 16px; font-size: 13px; color: var(--gc-accent-green); cursor: pointer; white-space: nowrap; display: flex; align-items: center; gap: 5px; margin-left: auto; transition: background var(--transition-standard); border-bottom: 2px solid transparent; }
        .sd-tab-add:hover { background: var(--gc-accent-green-dim); }
        .sd-tab-add svg { width: 15px; height: 15px; fill: currentColor; }

        .sd-body { flex: 1; overflow-y: auto; }

        /* Subject panel (one per subject) */
        .sd-panel { display: none; padding: 24px; }
        .sd-panel.active { display: block; }

        .sd-panel-title { font-size: 17px; font-weight: 500; color: var(--gc-text-primary); margin-bottom: 4px; }
        .sd-panel-id { font-size: 12px; color: var(--gc-text-tertiary); margin-bottom: 20px; }

        /* Section heading inside panel */
        .sd-section-heading { display: flex; align-items: center; gap: 8px; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .06em; color: var(--gc-text-secondary); margin-bottom: 12px; margin-top: 20px; }
        .sd-section-heading:first-child { margin-top: 0; }
        .sd-section-heading svg { width: 14px; height: 14px; fill: currentColor; }

        /* Faculty card */
        .sd-faculty-card { display: flex; align-items: center; justify-content: space-between; padding: 14px 16px; background: var(--gc-bg-main); border: 1px solid var(--gc-border-light); border-radius: var(--border-radius-card); }
        .sd-faculty-info { display: flex; align-items: center; gap: 12px; }
        .sd-avatar { width: 36px; height: 36px; border-radius: 50%; background: rgba(30,142,62,.1); color: var(--gc-accent-green); font-weight: 600; font-size: 13px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .sd-avatar.blue { background: rgba(26,115,232,.1); color: var(--gc-accent-blue); }
        .sd-faculty-name { font-size: 14px; font-weight: 500; color: var(--gc-text-primary); }
        .sd-faculty-role { font-size: 12px; color: var(--gc-text-secondary); }
        .sd-unassigned { font-size: 13px; color: var(--gc-text-tertiary); font-style: italic; }

        /* Student list */
        .sd-student-list { display: flex; flex-direction: column; gap: 8px; }
        .sd-student-item { display: flex; align-items: center; justify-content: space-between; padding: 10px 14px; background: var(--gc-bg-main); border: 1px solid var(--gc-border-light); border-radius: var(--border-radius-card); }
        .sd-student-info { display: flex; align-items: center; gap: 10px; }
        .sd-student-name { font-size: 13px; font-weight: 500; color: var(--gc-text-primary); }
        .sd-student-user { font-size: 11px; color: var(--gc-text-tertiary); }
        .sd-remove-btn { padding: 4px 10px; font-size: 12px; font-weight: 500; color: var(--gc-accent-red); background: rgba(217,48,37,.08); border-radius: 4px; transition: all var(--transition-standard); border: none; font-family: inherit; cursor: pointer; }
        .sd-remove-btn:hover { background: var(--gc-accent-red); color: #fff; }

        .sd-no-content { font-size: 13px; color: var(--gc-text-tertiary); font-style: italic; padding: 4px 0 12px; }

        /* Enroll row (faculty only) */
        .sd-enroll-row { display: flex; gap: 8px; margin-top: 14px; }
        .sd-select { flex: 1; padding: 9px 12px; border: 1px solid var(--gc-border-light); border-radius: 4px; font-family: 'Poppins', sans-serif; font-size: 13px; color: var(--gc-text-primary); background: var(--gc-bg-surface); outline: none; }
        .sd-select:focus { border-color: var(--gc-accent-blue); }

        .sd-divider { height: 1px; background: var(--gc-border-light); margin: 20px 0; }

        /* No subjects state */
        .sd-empty { text-align: center; padding: 48px 24px; color: var(--gc-text-tertiary); }
        .sd-empty svg { width: 48px; height: 48px; fill: var(--gc-border-light); margin-bottom: 12px; }
        .sd-empty p { font-size: 13px; line-height: 1.6; }

        /* Faculty: remove subject button */
        .sd-panel-header { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 4px; }

        /* ══════════════════════════════════════════════════
           BUTTONS  (original design, kept identical)
           ══════════════════════════════════════════════════ */
        .btn-primary { background: var(--gc-accent-blue); color: #fff; padding: 10px 24px; border-radius: 4px; font-size: 14px; font-weight: 500; letter-spacing: .02em; transition: background var(--transition-standard), box-shadow var(--transition-standard); display: inline-flex; align-items: center; gap: 8px; border: none; cursor: pointer; font-family: inherit; }
        .btn-primary:hover { background: var(--gc-accent-blue-hover); box-shadow: var(--gc-shadow-elevation-1); }
        .btn-primary.sm { padding: 7px 14px; font-size: 13px; }

        .btn-green { background: var(--gc-accent-green); color: #fff; padding: 10px 24px; border-radius: 4px; font-size: 14px; font-weight: 500; letter-spacing: .02em; transition: background var(--transition-standard); display: inline-flex; align-items: center; gap: 8px; border: none; cursor: pointer; font-family: inherit; }
        .btn-green:hover { background: var(--gc-accent-green-hover); }
        .btn-green.sm { padding: 7px 14px; font-size: 13px; }

        .btn-text { background: transparent; color: var(--gc-text-secondary); padding: 10px 16px; font-weight: 500; border-radius: 4px; transition: background var(--transition-standard); border: none; cursor: pointer; font-family: inherit; }
        .btn-text:hover { background: var(--gc-bg-hover); color: var(--gc-text-primary); }
        .btn-text.danger { color: var(--gc-accent-red); }
        .btn-text.danger:hover { background: rgba(217,48,37,.08); }

        /* ══════════════════════════════════════════════════
           MODALS  (original design — Create / Join + new Add-Subject)
           ══════════════════════════════════════════════════ */
        .modal-overlay { position: fixed; inset: 0; background: rgba(32,33,36,.6); z-index: 2000; display: flex; align-items: center; justify-content: center; padding: 24px; opacity: 0; visibility: hidden; transition: opacity var(--transition-standard), visibility var(--transition-standard); }
        .modal-overlay.active { opacity: 1; visibility: visible; }
        .modal-container { background: var(--gc-bg-surface); width: 100%; max-width: 500px; border-radius: 8px; box-shadow: var(--gc-shadow-modal); transform: scale(.95) translateY(20px); transition: transform .3s cubic-bezier(.4,0,.2,1); overflow: hidden; }
        .modal-overlay.active .modal-container { transform: scale(1) translateY(0); }
        .modal-header { padding: 24px; border-bottom: 1px solid var(--gc-border-light); }
        .modal-title { font-size: 20px; font-weight: 500; color: var(--gc-text-primary); }
        .modal-body { padding: 24px; }
        .modal-footer { padding: 16px 24px; display: flex; justify-content: flex-end; gap: 12px; }

        /* GC-style floating-label inputs (original) */
        .input-group { position: relative; margin-bottom: 24px; }
        .gc-input { width: 100%; padding: 16px; font-family: 'Poppins', sans-serif; font-size: 16px; color: var(--gc-text-primary); background: #f1f3f4; border: none; border-bottom: 2px solid transparent; border-radius: 4px 4px 0 0; outline: none; transition: background .2s, border-color .2s; }
        .gc-input:focus { background: #e8f0fe; border-bottom-color: var(--gc-accent-blue); }
        .gc-input-label { position: absolute; left: 16px; top: 16px; font-size: 16px; color: var(--gc-text-secondary); pointer-events: none; transition: transform .2s, font-size .2s, color .2s; transform-origin: top left; }
        .gc-input:focus + .gc-input-label,
        .gc-input:not(:placeholder-shown) + .gc-input-label { transform: translateY(-10px) scale(.75); color: var(--gc-accent-blue); }

        /* Simple select inside modal */
        .gc-select { width: 100%; padding: 12px 14px; border: 1px solid var(--gc-border-light); border-radius: 4px; font-family: 'Poppins', sans-serif; font-size: 14px; color: var(--gc-text-primary); background: var(--gc-bg-surface); outline: none; }
        .gc-select:focus { border-color: var(--gc-accent-blue); }
        .gc-label { display: block; font-size: 13px; font-weight: 500; color: var(--gc-text-secondary); margin-bottom: 6px; }
        .form-group { margin-bottom: 20px; }

        /* Helios dashboard refresh */
        :root {
            --helios-ink: #183153;
            --helios-muted: #53627a;
            --helios-paper: #ffffff;
            --helios-paper-soft: #f4f7fb;
            --helios-brand: #3f70b8;
            --helios-brand-deep: #284d84;
            --helios-teal: #2f9d93;
            --helios-gold: #e3a535;
            --helios-line: rgba(223,231,243,.82);
            --helios-surface: #ffffff;
            --helios-surface-strong: #ffffff;
            --helios-shadow: 0 18px 50px rgba(24,49,83,.14);
            --helios-shadow-soft: 0 12px 30px rgba(24,49,83,.08);
            --helios-glow: rgba(63,112,184,.16);
        }

        [data-theme="dark"] {
            --gc-bg-main: #07111f;
            --gc-bg-surface: #0f1826;
            --gc-bg-hover: #13243a;
            --gc-bg-active: #162b44;
            --gc-border-light: rgba(42,57,80,.95);
            --gc-border-medium: rgba(70,90,118,.95);
            --gc-text-primary: #dbe5f3;
            --gc-text-secondary: #8fa0b8;
            --gc-text-tertiary: #62738d;
            --gc-accent-blue: #67c9b0;
            --gc-accent-blue-hover: #82d7c1;
            --gc-accent-blue-dim: rgba(47,157,147,.18);
            --gc-accent-green: #67c9b0;
            --gc-accent-green-hover: #82d7c1;
            --gc-accent-green-dim: rgba(47,157,147,.18);
            --gc-accent-red: #e58b89;
            --gc-accent-red-dim: rgba(217,48,37,.18);
            --gc-accent-yellow: #d8ae62;
            --gc-accent-yellow-dim: rgba(227,165,53,.16);
            --gc-shadow-elevation-card: 0 16px 36px rgba(0,0,0,.42);
            --gc-shadow-card-hover: 0 22px 48px rgba(0,0,0,.5);
            --helios-ink: #eef4ff;
            --helios-muted: #8fa0b8;
            --helios-paper: #07111f;
            --helios-paper-soft: #0b182a;
            --helios-brand: #5f91dd;
            --helios-brand-deep: #dbe9ff;
            --helios-teal: #67c9b0;
            --helios-gold: #f0c46a;
            --helios-line: rgba(42,57,80,.9);
            --helios-surface: rgba(10,18,30,.88);
            --helios-surface-strong: rgba(9,16,27,.95);
            --helios-shadow: 0 24px 60px rgba(0,0,0,.42);
            --helios-shadow-soft: 0 14px 34px rgba(0,0,0,.34);
            --helios-glow: rgba(95,145,221,.2);
        }

        html { min-height: 100%; }
        body {
            background: var(--gc-bg-main);
            color: var(--helios-ink);
            transition: background 520ms cubic-bezier(.4,0,.2,1), color 520ms cubic-bezier(.4,0,.2,1);
            position: relative;
        }
        [data-theme="dark"] body {
            background:
                linear-gradient(90deg, rgba(3,12,25,.96), rgba(16,37,66,.9)),
                linear-gradient(135deg, #07111f 0 22%, #10233d 22% 44%, #0b182a 44% 66%, #18365e 66% 100%);
        }
        body::before {
            content: "";
            position: fixed;
            inset: 0;
            pointer-events: none;
            background: none;
            opacity: 0;
        }
        [data-theme="dark"] body::before {
            background:
                linear-gradient(90deg, transparent 0 11%, rgba(255,255,255,.14) 11% 11.35%, transparent 11.35% 100%),
                radial-gradient(circle at 74% 34%, rgba(47,157,147,.22) 0 8%, transparent 8.3%),
                radial-gradient(circle at 81% 31%, rgba(227,165,53,.18) 0 5%, transparent 5.3%),
                repeating-linear-gradient(90deg, transparent 0 70px, rgba(255,255,255,.055) 70px 71px);
            opacity: .72;
        }
        .app-container { position: relative; z-index: 1; min-height: 100vh; }
        .topbar {
            height: var(--topbar-height);
            border-bottom: 1px solid var(--gc-border-light);
            box-shadow: 0 1px 4px rgba(0,0,0,.06);
            background: var(--helios-surface-strong);
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
            padding: 0 24px 0 0;
        }
        .body-wrapper { margin-top: var(--topbar-height); }
        .sidebar {
            top: var(--topbar-height);
            background: var(--helios-surface);
            border-right: 1px solid var(--gc-border-light);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            padding: 16px 0 24px;
            display: flex;
            flex-direction: column;
        }
        .topbar-brand {
            display: flex;
            align-items: center;
            width: var(--drawer-width);
            padding: 0 24px;
            height: 100%;
            gap: 10px;
            flex-shrink: 0;
            border-right: 1px solid var(--gc-border-light);
        }
        .topbar-left { gap: 10px; width: auto; }
        .topbar-center {
            flex: 1;
            display: flex;
            align-items: center;
            padding: 0 24px;
        }
        .topbar-clock {
            font-size: 12px;
            color: var(--gc-text-tertiary);
            font-variant-numeric: tabular-nums;
            letter-spacing: .04em;
        }
        .brand-logo { gap: 10px; }
        .brand-logo-img {
            width: 42px;
            height: 42px;
            object-fit: contain;
            object-position: center;
            display: block;
            border-radius: 10px;
            overflow: hidden;
            background: none;
            padding: 0;
        }
        .brand-logo-dark { display: none; }
        [data-theme="dark"] .brand-logo-light { display: none; }
        [data-theme="dark"] .brand-logo-dark { display: block; }
        .brand-wordmark {
            font-size: 17px;
            font-weight: 700;
            letter-spacing: -.02em;
            line-height: 1.15;
            color: var(--gc-text-primary);
        }
        .brand-wordmark span {
            display: block;
            font-size: 11px;
            font-weight: 400;
            color: var(--gc-text-tertiary);
            letter-spacing: .08em;
            text-transform: uppercase;
            margin-top: -1px;
        }
        .menu-btn {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            color: var(--gc-text-secondary);
            margin-left: 0;
            background: rgba(255,255,255,.42);
            border: 1px solid rgba(24,49,83,.08);
        }
        .menu-btn:hover { background: var(--gc-bg-hover); }
        .menu-btn svg { width: 18px; height: 18px; }
        [data-theme="dark"] .menu-btn {
            background: rgba(19,36,58,.78);
            border-color: rgba(70,90,118,.5);
            color: var(--gc-text-primary);
        }
        [data-theme="dark"] .menu-btn,
        [data-theme="dark"] .menu-btn svg {
            color: #e8f0fe !important;
            fill: currentColor !important;
            stroke: currentColor !important;
        }
        .icon-btn {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            color: var(--gc-text-secondary);
            border: 1px solid transparent;
        }
        .icon-btn:hover { color: var(--gc-text-primary); background: var(--gc-bg-hover); border-color: transparent; }
        .topbar-divider {
            width: 1px;
            height: 24px;
            background: var(--gc-border-light);
            margin: 0 8px;
        }
        .theme-toggle {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            border: 1px solid transparent;
            background: transparent;
            color: var(--gc-text-secondary);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: transform 260ms cubic-bezier(.16,1,.3,1), background 260ms ease, border-color 260ms ease;
        }
        .theme-toggle:hover { transform: translateY(-1px) rotate(8deg); background: var(--gc-bg-hover); border-color: transparent; color: var(--gc-text-primary); }
        .theme-toggle:active { transform: scale(.94); }
        .theme-toggle svg { width: 17px; height: 17px; stroke: currentColor; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
        .icon-sun { display: none; }
        [data-theme="dark"] .icon-sun { display: block; }
        [data-theme="dark"] .icon-moon { display: none; }
        .user-avatar-btn {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 6px 10px 6px 6px;
            border-radius: 8px;
            background: transparent;
            box-shadow: none;
            border: 1px solid transparent;
            width: auto;
            height: auto;
            margin-left: 0;
            font-size: inherit;
            font-weight: inherit;
            color: inherit;
        }
        .user-avatar-btn:hover { background: var(--gc-bg-hover); border-color: var(--gc-border-light); }
        .user-avatar-circle {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: linear-gradient(135deg, #1a7a4a, #0e9f6e);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 13px;
            font-weight: 600;
            flex-shrink: 0;
        }
        .user-avatar-meta { line-height: 1.3; text-align: left; display: flex; flex-direction: column; align-items: flex-start; }
        .user-avatar-name { display: block; font-size: 13px; font-weight: 500; color: var(--gc-text-primary); }
        .user-avatar-role { display: block; font-size: 10px; text-transform: uppercase; letter-spacing: .08em; color: var(--gc-accent-blue); margin-top: 1px; }
        .account-popover {
            position: fixed;
            top: 72px;
            right: 24px;
            width: min(360px, calc(100vw - 32px));
            background: #eef3fb;
            border: 1px solid var(--gc-border-light);
            border-radius: 28px;
            box-shadow: 0 14px 40px rgba(26,29,46,.22);
            z-index: 1200;
            padding: 20px;
            display: none;
            text-align: center;
        }
        .account-popover.open { display: block; }
        [data-theme="dark"] .account-popover {
            background: var(--helios-surface-strong);
            border-color: var(--gc-border-light);
            box-shadow: 0 18px 46px rgba(0,0,0,.52);
        }
        .account-popover-close {
            position: absolute;
            top: 16px;
            right: 18px;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--gc-text-secondary);
            font-size: 24px;
            line-height: 1;
        }
        .account-popover-close:hover { background: rgba(26,29,46,.08); color: var(--gc-text-primary); }
        [data-theme="dark"] .account-popover-close:hover { background: rgba(255,255,255,.08); }
        .account-email {
            padding: 4px 40px 14px;
            font-size: 14px;
            font-weight: 500;
            color: var(--gc-text-primary);
            word-break: break-word;
        }
        .account-avatar-large {
            width: 98px;
            height: 98px;
            border-radius: 50%;
            margin: 14px auto 12px;
            background: #78909c;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 44px;
            font-weight: 500;
            box-shadow: inset 0 0 0 4px rgba(255,255,255,.14);
        }
        .account-greeting {
            font-size: 24px;
            font-weight: 500;
            color: var(--gc-text-primary);
            margin-bottom: 16px;
        }
        .account-role-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 38px;
            padding: 0 22px;
            border: 1px solid rgba(26,29,46,.35);
            border-radius: 999px;
            color: #0b57d0;
            font-weight: 600;
            background: rgba(255,255,255,.35);
            margin-bottom: 18px;
        }
        [data-theme="dark"] .account-role-pill {
            color: var(--helios-teal);
            background: rgba(47,157,147,.14);
            border-color: rgba(103,201,176,.35);
        }
        .account-actions {
            background: #fff;
            border-radius: 18px;
            overflow: hidden;
            text-align: left;
        }
        [data-theme="dark"] .account-actions { background: rgba(15,24,38,.96); }
        .account-action-link {
            display: flex;
            align-items: center;
            gap: 14px;
            width: 100%;
            padding: 16px 22px;
            color: var(--gc-text-primary);
            font-size: 15px;
            font-weight: 600;
            text-decoration: none;
        }
        .account-action-link:hover { background: #f6f8fc; }
        [data-theme="dark"] .account-action-link:hover { background: rgba(19,36,58,.96); }
        .account-action-link svg {
            width: 20px;
            height: 20px;
            fill: currentColor;
            color: var(--gc-text-secondary);
            flex-shrink: 0;
        }
        .nav-item { color: var(--helios-ink); }
        .nav-item {
            gap: 12px;
            padding: 9px 20px;
            color: var(--gc-text-secondary);
            font-size: 13.5px;
            font-weight: 400;
            border-radius: 0;
            margin-right: 0;
            position: relative;
        }
        .nav-item:hover { background: var(--gc-bg-hover); color: var(--gc-text-primary); }
        .nav-item.active { background: var(--gc-accent-blue-dim); color: var(--gc-accent-blue); font-weight: 500; }
        .nav-item.active .nav-icon { fill: var(--helios-teal); }
        .nav-item.active::before {
            content: "";
            position: absolute;
            left: 0;
            top: 4px;
            bottom: 4px;
            width: 3px;
            background: var(--gc-accent-blue);
            border-radius: 0 3px 3px 0;
        }
        .nav-icon { width: 16px; height: 16px; opacity: .7; }
        .nav-divider { background: var(--gc-border-light); margin: 8px 16px; }
        .nav-section-title {
            font-size: 10px;
            font-weight: 600;
            letter-spacing: .12em;
            color: var(--gc-text-tertiary);
            padding: 16px 20px 6px;
        }
        .sidebar-footer {
            margin-top: auto;
            padding: 12px 12px 0;
            border-top: 1px solid var(--gc-border-light);
        }
        .sidebar-footer .nav-item { border-radius: 8px; }
        .main-content { max-width: none; width: 100%; }
        .main-content { padding: 32px 36px 48px; }
        .dash-header {
            margin-bottom: 28px;
            padding: 28px 30px;
            border: 1px solid var(--helios-line);
            border-radius: 14px;
            background: var(--helios-surface);
            box-shadow: var(--helios-shadow-soft);
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
        }
        .dash-greeting { font-size: 34px; font-weight: 700; color: var(--helios-ink); letter-spacing: -.02em; }
        .dash-subtext { color: var(--helios-muted); max-width: 720px; margin-top: 10px; }
        .role-badge { background: rgba(255,255,255,.72); color: var(--helios-brand-deep); border: 1px solid rgba(63,112,184,.15); }
        [data-theme="dark"] .role-badge { background: rgba(19,36,61,.9); color: var(--helios-brand-deep); border-color: rgba(95,145,221,.2); }
        .role-badge.faculty { color: var(--helios-teal); }
        .role-badge.student { color: var(--helios-brand); }
        .role-badge.admin { color: #d93025; }
        .faculty-dashboard { display: grid; grid-template-columns: minmax(0, 1fr) 360px; gap: 24px; margin-bottom: 34px; }
        .faculty-primary { display: flex; flex-direction: column; gap: 20px; min-width: 0; }
        .faculty-hero {
            background: var(--helios-surface-strong);
            color: var(--helios-ink);
            border: 1px solid var(--helios-line);
            border-radius: 14px;
            padding: 30px;
            overflow: hidden;
            position: relative;
            box-shadow: var(--helios-shadow-soft);
        }
        [data-theme="dark"] .faculty-hero {
            background:
                linear-gradient(135deg, rgba(16,37,66,.98), rgba(21,52,92,.95)),
                linear-gradient(135deg, #0b182a 0 22%, #10233d 22% 44%, #0e1c32 44% 66%, #153156 66% 100%);
            color: #fff;
            box-shadow: 0 20px 42px rgba(0,0,0,.38);
        }
        .faculty-hero::after {
            content: "";
            position: absolute;
            right: -70px;
            top: -70px;
            width: 220px;
            height: 220px;
            border: 34px solid rgba(63,112,184,.08);
            border-radius: 50%;
        }
        [data-theme="dark"] .faculty-hero::after {
            border-color: rgba(255,255,255,.12);
        }
        .faculty-hero::before {
            content: "";
            position: absolute;
            inset: 0;
            background: none;
            pointer-events: none;
        }
        [data-theme="dark"] .faculty-hero::before {
            background:
                linear-gradient(90deg, transparent 0 11%, rgba(255,255,255,.12) 11% 11.35%, transparent 11.35% 100%),
                repeating-linear-gradient(90deg, transparent 0 70px, rgba(255,255,255,.05) 70px 71px);
        }
        .faculty-kicker { font-size: 12px; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; color: var(--helios-muted); }
        [data-theme="dark"] .faculty-kicker { color: rgba(255,255,255,.72); }
        .faculty-title { font-size: 34px; line-height: 1.15; font-weight: 700; letter-spacing: 0; margin-top: 12px; max-width: 720px; color: var(--helios-ink); }
        [data-theme="dark"] .faculty-title { color: #fff; }
        .faculty-copy { color: var(--helios-muted); font-size: 15px; margin-top: 10px; max-width: 660px; }
        [data-theme="dark"] .faculty-copy { color: rgba(255,255,255,.78); }
        .faculty-metrics { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; margin-top: 26px; position: relative; z-index: 1; }
        .metric-card {
            background: rgba(63,112,184,.06);
            border: 1px solid rgba(63,112,184,.14);
            border-radius: 8px;
            padding: 16px;
        }
        [data-theme="dark"] .metric-card {
            background: rgba(255,255,255,.13);
            border: 1px solid rgba(255,255,255,.22);
            backdrop-filter: blur(10px);
        }
        .metric-value { font-size: 28px; font-weight: 700; line-height: 1; color: var(--helios-ink); }
        [data-theme="dark"] .metric-value { color: #fff; }
        .metric-label { font-size: 12px; text-transform: uppercase; letter-spacing: .08em; color: var(--helios-muted); margin-top: 8px; }
        [data-theme="dark"] .metric-label { color: rgba(255,255,255,.7); }
        .overview-panel {
            background: var(--helios-surface);
            border: 1px solid rgba(24,49,83,.1);
            border-radius: 14px;
            padding: 22px;
            box-shadow: var(--helios-shadow-soft);
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
        }
        .panel-head { display: flex; align-items: center; justify-content: space-between; gap: 14px; margin-bottom: 16px; }
        .panel-title { font-size: 18px; font-weight: 700; color: var(--helios-ink); }
        .panel-note { font-size: 12px; color: var(--helios-muted); }
        .quick-actions-panel { min-height: 260px; }
        .quick-actions-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; align-content: stretch; }
        .quick-action {
            display: flex;
            align-items: center;
            gap: 12px;
            min-height: 108px;
            padding: 18px;
            border: 1px solid rgba(24,49,83,.1);
            border-radius: 14px;
            color: var(--helios-ink);
            background: rgba(255,255,255,.48);
            backdrop-filter: blur(12px);
        }
        .quick-action:hover { color: var(--helios-brand-deep); border-color: rgba(63,112,184,.35); background: rgba(255,255,255,.82); }
        [data-theme="dark"] .quick-action {
            background: rgba(15,24,38,.92);
            border-color: rgba(42,57,80,.95);
        }
        [data-theme="dark"] .quick-action:hover { background: rgba(19,36,58,.96); }
        .quick-icon { width: 42px; height: 42px; border-radius: 8px; display: grid; place-items: center; color: #fff; flex-shrink: 0; }
        .quick-action:nth-child(1) .quick-icon { background: var(--helios-brand); }
        .quick-action:nth-child(2) .quick-icon { background: var(--helios-teal); }
        .quick-action:nth-child(3) .quick-icon { background: var(--helios-gold); }
        .quick-icon svg { width: 20px; height: 20px; fill: currentColor; }
        .quick-label { font-weight: 700; font-size: 14px; }
        .quick-meta { display: block; font-size: 12px; color: var(--helios-muted); margin-top: 2px; }
        .faculty-side { display: flex; flex-direction: column; gap: 20px; min-width: 0; }
        .calendar-card { position: sticky; top: 96px; }
        .calendar-month { font-size: 15px; font-weight: 700; color: var(--helios-brand-deep); }
        .calendar-grid { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 6px; margin-top: 14px; }
        .calendar-weekday { color: var(--helios-muted); font-size: 11px; font-weight: 700; text-align: center; }
        .calendar-day {
            min-height: 38px;
            border: 1px solid rgba(24,49,83,.08);
            border-radius: 10px;
            display: grid;
            place-items: center;
            font-size: 12px;
            color: var(--helios-muted);
            background: rgba(255,255,255,.52);
            position: relative;
        }
        [data-theme="dark"] .calendar-day { background: rgba(15,24,38,.92); border-color: rgba(42,57,80,.95); color: var(--gc-text-secondary); }
        .calendar-day.has-due { color: var(--helios-brand-deep); background: rgba(63,112,184,.08); border-color: rgba(63,112,184,.24); font-weight: 700; }
        .calendar-day.today { box-shadow: inset 0 0 0 2px var(--helios-teal); color: var(--helios-teal); }
        .calendar-day.has-due::after { content: ""; width: 5px; height: 5px; background: var(--helios-gold); border-radius: 50%; position: absolute; bottom: 6px; }
        .due-list { display: flex; flex-direction: column; gap: 10px; margin-top: 18px; }
        .due-item {
            display: grid;
            grid-template-columns: 48px minmax(0, 1fr);
            gap: 12px;
            padding: 12px;
            border: 1px solid rgba(24,49,83,.1);
            border-radius: 14px;
            background: rgba(255,255,255,.74);
        }
        [data-theme="dark"] .due-item { background: rgba(15,24,38,.94); border-color: rgba(42,57,80,.95); }
        .due-date { background: rgba(47,157,147,.12); color: var(--helios-teal); border-radius: 8px; text-align: center; padding: 6px 4px; font-weight: 700; }
        .due-date span { display: block; font-size: 10px; text-transform: uppercase; letter-spacing: .08em; }
        .due-title { font-size: 13px; font-weight: 700; color: var(--helios-ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .due-meta { font-size: 12px; color: var(--helios-muted); margin-top: 3px; }
        .empty-panel {
            border: 1px dashed rgba(24,49,83,.18);
            border-radius: 8px;
            padding: 28px;
            color: var(--helios-muted);
            background: rgba(255,255,255,.62);
            backdrop-filter: blur(16px);
        }
        [data-theme="dark"] .empty-panel {
            background: rgba(10,18,30,.94);
            border-color: rgba(70,90,118,.55);
            color: var(--gc-text-secondary);
        }
        .faculty-notification-list { display: flex; flex-direction: column; gap: 12px; }
        .faculty-notification-item {
            padding: 16px 18px;
            border: 1px solid rgba(24,49,83,.1);
            border-radius: 14px;
            background: rgba(255,255,255,.54);
            box-shadow: var(--helios-shadow-soft);
        }
        [data-theme="dark"] .faculty-notification-item {
            background: rgba(10,18,30,.94);
            border-color: rgba(42,57,80,.95);
        }
        .faculty-notification-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 8px;
        }
        .faculty-notification-title { font-size: 15px; font-weight: 700; color: var(--helios-ink); }
        .faculty-notification-time { font-size: 12px; color: var(--helios-muted); white-space: nowrap; }
        .faculty-notification-body { font-size: 13px; line-height: 1.65; color: var(--helios-muted); }
        .faculty-notification-type {
            display: inline-flex;
            align-items: center;
            margin-top: 10px;
            padding: 4px 10px;
            border-radius: 999px;
            background: rgba(26,122,74,.1);
            color: var(--gc-accent-blue);
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .06em;
        }
        .section-heading { display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; margin: 8px 0 18px; }
        .section-heading h2 { font-size: 22px; color: var(--helios-ink); line-height: 1.2; }
        .section-heading p { color: var(--helios-muted); font-size: 13px; margin-top: 4px; }
        .student-dashboard-shell {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 300px;
            gap: 24px;
            align-items: start;
        }
        .student-main-panel,
        .student-side-panel {
            background: var(--helios-surface);
            border: 1px solid rgba(24,49,83,.1);
            border-radius: 18px;
            box-shadow: var(--helios-shadow-soft);
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
        }
        .student-main-panel { padding: 24px; min-width: 0; }
        .student-search-row { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 20px; }
        .student-search {
            display: flex; align-items: center; gap: 10px;
            min-height: 42px; width: min(360px, 100%);
            background: rgba(255,255,255,.62);
            border: 1px solid rgba(24,49,83,.08);
            border-radius: 12px;
            padding: 0 14px;
            color: var(--helios-muted);
        }
        .student-search svg { width: 17px; height: 17px; fill: currentColor; }
        .student-search span { font-size: 12px; }
        .student-date { font-size: 12px; color: var(--helios-muted); white-space: nowrap; }
        .student-welcome-card {
            min-height: 150px;
            border-radius: 14px;
            padding: 26px;
            background:
                linear-gradient(100deg, rgba(255,255,255,.94) 0 58%, rgba(226,235,249,.76) 58% 100%);
            border: 1px solid rgba(24,49,83,.08);
            display: grid;
            grid-template-columns: minmax(0, 1fr) 210px;
            gap: 18px;
            align-items: center;
            overflow: hidden;
            margin-bottom: 24px;
        }
        .student-welcome-card h2 { color: var(--helios-ink); font-size: 20px; line-height: 1.2; margin-bottom: 8px; }
        .student-welcome-card p { color: var(--helios-muted); font-size: 13px; max-width: 480px; }
        .student-hero-action {
            display: inline-flex; align-items: center; justify-content: center;
            margin-top: 16px;
            min-height: 34px;
            padding: 0 22px;
            border-radius: 999px;
            background: linear-gradient(135deg, var(--helios-brand), var(--helios-teal));
            color: #fff;
            font-size: 12px;
            font-weight: 700;
        }
        .student-book-stack {
            position: relative;
            height: 120px;
            transform: rotate(-8deg);
        }
        .student-book-stack span {
            position: absolute;
            left: 28px;
            right: 12px;
            height: 28px;
            border-radius: 8px;
            box-shadow: 0 12px 24px rgba(24,49,83,.14);
        }
        .student-book-stack span:nth-child(1) { bottom: 16px; background: #4267c9; }
        .student-book-stack span:nth-child(2) { bottom: 40px; background: #f08a98; left: 12px; }
        .student-book-stack span:nth-child(3) { bottom: 64px; background: #f6c6ab; left: 42px; right: 0; }
        .student-book-stack span:nth-child(4) { bottom: 88px; background: #ffffff; left: 60px; right: 18px; border: 2px solid #dbe5f4; }
        .student-section-bar { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin: 18px 0 12px; }
        .student-section-bar h2 { font-size: 15px; color: var(--helios-ink); }
        .student-section-bar a, .student-section-bar span { font-size: 12px; color: var(--helios-muted); font-weight: 700; }
        .student-main-panel .classroom-grid {
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }
        .student-main-panel .gc-card {
            min-height: 220px;
            border-radius: 14px;
            overflow: hidden;
            transition: box-shadow var(--transition-standard), transform var(--transition-standard);
        }
        .student-main-panel .gc-card:hover { transform: translateY(-4px); }
        .student-main-panel .gc-card-header { min-height: 110px; padding: 20px 22px 16px; }
        .student-main-panel .gc-card-body { display: flex; padding: 14px 22px; }
        .student-main-panel .gc-card-footer { display: flex; padding: 10px 22px; }
        .student-main-panel .gc-card-title { font-size: 16px; font-weight: 600; white-space: normal; padding-right: 28px; }
        .student-main-panel .gc-card-subtitle { font-size: 12px; margin-top: 3px; }
        /* Color-coded top accents per card position */
        .student-main-panel .gc-card:nth-child(4n+1) .gc-card-top-accent { background: #1a7a4a; }
        .student-main-panel .gc-card:nth-child(4n+2) .gc-card-top-accent { background: #3f70b8; }
        .student-main-panel .gc-card:nth-child(4n+3) .gc-card-top-accent { background: #e3a535; }
        .student-main-panel .gc-card:nth-child(4n+4) .gc-card-top-accent { background: #d45f5d; }
        .student-main-panel .gc-card-top-accent { height: 5px; }
        .student-lessons-table {
            width: 100%;
            border-collapse: collapse;
            overflow: hidden;
            border-radius: 12px;
            background: rgba(255,255,255,.55);
        }
        .student-lessons-table th,
        .student-lessons-table td {
            padding: 12px 14px;
            text-align: left;
            border-bottom: 1px solid rgba(24,49,83,.08);
            font-size: 12px;
            color: var(--helios-muted);
        }
        .student-lessons-table th { color: var(--helios-ink); font-weight: 700; }
        .student-lessons-table td:first-child { color: var(--helios-ink); font-weight: 700; }
        .student-status-dot { display: inline-flex; align-items: center; gap: 7px; }
        .student-status-dot::before { content: ""; width: 7px; height: 7px; border-radius: 50%; background: var(--helios-brand); }
        .student-status-dot.pending::before { background: #ef6f86; }
        .student-side-panel { padding: 22px; position: sticky; top: 88px; }
        .student-profile-card { text-align: center; padding-bottom: 20px; border-bottom: 1px solid rgba(24,49,83,.08); margin-bottom: 20px; }
        .student-profile-avatar {
            width: 96px; height: 96px; border-radius: 50%;
            margin: 0 auto 14px;
            display: grid; place-items: center;
            background: #cfa56e;
            color: #2f1d0b;
            font-size: 34px;
            font-weight: 800;
        }
        [data-theme="dark"] .student-profile-avatar {
            background: #6f4a1f;
            color: #fff3dd;
        }
        .student-profile-card h3 { font-size: 15px; color: var(--helios-ink); }
        .student-profile-card p { font-size: 12px; color: var(--helios-muted); margin-top: 2px; }
        .student-profile-card a {
            display: inline-flex;
            min-height: 30px;
            align-items: center;
            justify-content: center;
            padding: 0 24px;
            border-radius: 999px;
            background: var(--helios-brand);
            color: #fff;
            font-size: 12px;
            font-weight: 700;
            margin-top: 12px;
        }
        .student-mini-calendar { margin-bottom: 22px; }
        .student-mini-calendar h3,
        .student-reminders h3 { font-size: 13px; color: var(--helios-ink); margin-bottom: 12px; }
        .student-calendar-grid { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 6px; }
        .student-calendar-grid span {
            min-height: 28px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            font-size: 11px;
            color: var(--helios-muted);
        }
        .student-calendar-grid .weekday { color: var(--helios-ink); font-weight: 700; }
        .student-calendar-grid .marked { background: rgba(95,145,221,.16); color: var(--helios-brand-deep); font-weight: 700; }
        .student-calendar-grid .today { background: #ef6f86; color: #fff; font-weight: 800; }
        .student-reminder-list { display: flex; flex-direction: column; gap: 10px; }
        .student-reminder-item {
            display: grid;
            grid-template-columns: 32px minmax(0, 1fr);
            gap: 10px;
            align-items: center;
            padding: 10px;
            border-radius: 12px;
            background: rgba(255,255,255,.46);
            border: 1px solid rgba(24,49,83,.08);
        }
        .student-reminder-icon {
            width: 32px; height: 32px; border-radius: 10px;
            display: grid; place-items: center;
            background: rgba(95,145,221,.14);
            color: var(--helios-brand);
        }
        .student-reminder-icon svg { width: 16px; height: 16px; fill: currentColor; }
        .student-reminder-title { font-size: 12px; font-weight: 700; color: var(--helios-ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .student-reminder-date { font-size: 11px; color: var(--helios-muted); margin-top: 2px; }
        [data-theme="dark"] .student-welcome-card,
        [data-theme="dark"] .student-search,
        [data-theme="dark"] .student-lessons-table,
        [data-theme="dark"] .student-reminder-item {
            background: rgba(15,24,38,.92);
            border-color: rgba(42,57,80,.95);
        }
        [data-theme="dark"] .student-welcome-card {
            background:
                linear-gradient(100deg, rgba(15,24,38,.96) 0 58%, rgba(19,36,58,.88) 58% 100%);
        }
        .classroom-grid { gap: 22px; }
        .gc-card {
            border: 1px solid rgba(24,49,83,.1);
            box-shadow: var(--helios-shadow-soft);
            background: var(--helios-surface);
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
            border-radius: 14px;
        }
        .gc-card:hover { transform: translateY(-4px); }
        .gc-card-header {
            background:
                linear-gradient(180deg, rgba(255,255,255,.92) 0%, rgba(245,248,252,.9) 100%);
        }
        [data-theme="dark"] .gc-card-header {
            background:
                linear-gradient(180deg, rgba(16,26,40,.96) 0%, rgba(11,24,42,.92) 100%);
        }
        .gc-card-top-accent { height: 5px; }
        .gc-card:nth-child(4n+1) .gc-card-top-accent { background: #1a7a4a !important; }
        .gc-card:nth-child(4n+2) .gc-card-top-accent { background: #3f70b8 !important; }
        .gc-card:nth-child(4n+3) .gc-card-top-accent { background: #e3a535 !important; }
        .gc-card:nth-child(4n+4) .gc-card-top-accent { background: #d45f5d !important; }
        .gc-card-title,
        .gc-card-title a,
        .gc-card-subtitle,
        .gc-task-title,
        .gc-upcoming-label { color: var(--helios-ink); }
        .gc-task-due,
        .gc-empty-tasks { color: var(--helios-muted); }
        .gc-task-item { border-radius: 8px; padding: 8px; margin: 0 -8px; transition: background var(--transition-standard), transform var(--transition-standard); }
        .gc-task-item:hover { background: rgba(63,112,184,.08); transform: translateX(2px); }
        .gc-card-footer { background: rgba(255,255,255,.4); }
        [data-theme="dark"] .gc-card-footer { background: rgba(10,18,30,.92); }
        .subject-pill {
            background: rgba(255,255,255,.62);
            color: var(--helios-muted);
            border-color: rgba(24,49,83,.1);
        }
        [data-theme="dark"] .subject-pill {
            background: rgba(15,24,38,.96);
            border-color: rgba(42,57,80,.95);
            color: var(--gc-text-secondary);
        }
        .subject-pill:hover { background: rgba(63,112,184,.1); color: var(--helios-brand-deep); }
        .gc-footer-icon:hover { background-color: rgba(63,112,184,.1); color: var(--helios-brand); }
        .gc-action-card {
            border: 2px dashed rgba(24,49,83,.18);
            background: rgba(255,255,255,.62);
            color: var(--helios-ink);
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
            box-shadow: var(--helios-shadow-soft);
        }
        .gc-action-card:hover { border-color: rgba(26,122,74,.35); background: rgba(255,255,255,.82); color: var(--helios-ink); }
        [data-theme="dark"] .gc-action-card {
            background: rgba(10,18,30,.94);
            border-color: rgba(42,57,80,.95);
            color: var(--gc-text-primary);
        }
        [data-theme="dark"] .gc-action-card:hover { background: rgba(15,24,38,.98); }
        .gc-action-icon { background: rgba(26,122,74,.10); }
        [data-theme="dark"] .gc-action-icon { background: rgba(47,157,147,.16); }
        .subject-drawer-overlay { background: rgba(7,17,31,.38); backdrop-filter: blur(4px); }
        .subject-drawer {
            background: var(--helios-surface-strong);
            box-shadow: var(--helios-shadow);
            backdrop-filter: blur(22px);
            -webkit-backdrop-filter: blur(22px);
        }
        .sd-tabs { background: transparent; }
        .sd-tab.active { background: rgba(255,255,255,.58); }
        [data-theme="dark"] .sd-tab.active { background: rgba(19,36,61,.88); }
        .gc-input,
        .gc-select {
            background: rgba(255,255,255,.68);
            border: 1px solid rgba(24,49,83,.1);
            border-radius: 10px;
        }
        [data-theme="dark"] .gc-input,
        [data-theme="dark"] .gc-select { background: rgba(19,36,61,.88); }
        .gc-input:focus,
        .gc-select:focus { background: rgba(255,255,255,.92); box-shadow: 0 0 0 3px var(--helios-glow); }
        [data-theme="dark"] .gc-input:focus,
        [data-theme="dark"] .gc-select:focus { background: rgba(19,36,61,.96); }
        @media (max-width: 1180px) {
            .faculty-dashboard { grid-template-columns: 1fr; }
            .student-dashboard-shell { grid-template-columns: 1fr; }
            .student-side-panel { position: static; }
            .calendar-card { position: static; }
        }
        @media (max-width: 760px) {
            .faculty-title { font-size: 26px; }
            .faculty-metrics, .quick-actions-grid { grid-template-columns: 1fr; }
            .faculty-hero { padding: 24px; }
            .student-main-panel { padding: 16px; }
            .student-search-row,
            .student-welcome-card { grid-template-columns: 1fr; }
            .student-book-stack { display: none; }
            .student-main-panel .classroom-grid { grid-template-columns: 1fr; }
            .student-lessons-table { min-width: 620px; }
            .student-lessons-scroll { overflow-x: auto; }
            .classroom-grid { grid-template-columns: 1fr; }
            .brand-logo-img { width: 98px; }
            .dash-header { padding: 24px 20px; }
            .dash-greeting { font-size: 28px; }
            .topbar-center { display: none; }
            .main-content { padding: 24px 16px; }
        }
        .topbar-right > .theme-toggle { display: none; }
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
    </style>
</head>
<body>

<!-- ── SVG sprite (original icons + new ones) ── -->
<svg style="display:none">
    <symbol id="icon-menu"        viewBox="0 0 24 24"><path d="M3 18h18v-2H3v2zm0-5h18v-2H3v2zm0-7v2h18V6H3z"/></symbol>
    <symbol id="icon-logo-edu"    viewBox="0 0 24 24"><path d="M12 3L1 9l4 2.18v6L12 21l7-3.82v-6l2-1.09V17h2V9L12 3zm6.82 6L12 12.72 5.18 9 12 5.28 18.82 9zM17 15.99l-5 2.73-5-2.73v-3.72l5 2.73 5-2.73v3.72z"/></symbol>
    <symbol id="icon-home"        viewBox="0 0 24 24"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></symbol>
    <symbol id="icon-calendar"    viewBox="0 0 24 24"><path d="M19 4h-1V2h-2v2H8V2H6v2H5c-1.11 0-1.99.9-1.99 2L3 20c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H5V10h14v10zm0-12H5V6h14v2z"/></symbol>
    <symbol id="icon-class"       viewBox="0 0 24 24"><path d="M18 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zM9 4h2v5l-1-.75L9 9V4zm9 16H6V4h1v9l3-2.25L13 13V4h5v16z"/></symbol>
    <symbol id="icon-folder"      viewBox="0 0 24 24"><path d="M10 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z"/></symbol>
    <symbol id="icon-assignment"  viewBox="0 0 24 24"><path d="M19 3h-4.18C14.4 1.84 13.3 1 12 1c-1.3 0-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 0c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1zm2 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z"/></symbol>
    <symbol id="icon-more-vert"   viewBox="0 0 24 24"><path d="M12 8c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2zm0 2c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm0 6c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2z"/></symbol>
    <symbol id="icon-add"         viewBox="0 0 24 24"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></symbol>
    <symbol id="icon-apps"        viewBox="0 0 24 24"><path d="M4 8h4V4H4v4zm6 12h4v-4h-4v4zm-6 0h4v-4H4v4zm0-6h4v-4H4v4zm6 0h4v-4h-4v4zm6-10v4h4V4h-4zm-6 4h4V4h-4v4zm6 6h4v-4h-4v4zm0 6h4v-4h-4v4z"/></symbol>
    <symbol id="icon-search"      viewBox="0 0 24 24"><path d="M9.5 3a6.5 6.5 0 0 1 5.16 10.45l4.45 4.44-1.42 1.42-4.44-4.45A6.5 6.5 0 1 1 9.5 3zm0 2a4.5 4.5 0 1 0 0 9 4.5 4.5 0 0 0 0-9z"/></symbol>
    <symbol id="icon-bell"        viewBox="0 0 24 24"><path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6v-5c0-3.07-1.64-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.63 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z"/></symbol>
    <symbol id="icon-person"      viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></symbol>
    <symbol id="icon-grade"       viewBox="0 0 24 24"><path d="M5 3.5A2.5 2.5 0 0 1 7.5 1H19v18H7.5A2.5 2.5 0 0 0 5 21.5v-18zM7.5 3A.5.5 0 0 0 7 3.5v14.55c.17-.03.33-.05.5-.05H17V3H7.5zM8.5 6h7v2h-7V6zm0 4h7v2h-7v-2z"/></symbol>
    <symbol id="icon-close"       viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></symbol>
    <symbol id="icon-book"        viewBox="0 0 24 24"><path d="M18 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zM6 4h5v8l-2.5-1.5L6 12V4z"/></symbol>
    <symbol id="icon-edit"        viewBox="0 0 24 24"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></symbol>
    <symbol id="icon-trash"       viewBox="0 0 24 24"><path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></symbol>
    <symbol id="icon-settings"    viewBox="0 0 24 24"><path d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.06-.94l2.03-1.58c.18-.14.23-.41.12-.61l-1.92-3.32c-.12-.22-.37-.29-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54c-.04-.24-.24-.41-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.56-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.04.3-.07.63-.07.94s.02.64.07.94l-2.03 1.58c-.18.14-.23.41-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .43-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z"/></symbol>
</svg>

<div class="app-container">

    <!-- ══════════════════════════════════════════════
         TOPBAR
         ══════════════════════════════════════════════ -->
    <header class="topbar">
        <div class="topbar-brand">
            <button class="menu-btn" id="drawerToggle" aria-label="Toggle navigation drawer">
                <svg><use href="#icon-menu"></use></svg>
            </button>
            <div class="brand-logo">
                <img class="brand-logo-img brand-logo-light" src="img/Light Icon.png" alt="Helios University">
                <img class="brand-logo-img brand-logo-dark" src="img/Dark Icon.png" alt="Helios University">
            </div>
            <div class="brand-wordmark">Helios<span><?= $role === 'faculty' ? 'Faculty Dashboard' : 'Student Dashboard' ?></span></div>
        </div>
        <div class="topbar-center">
            <span class="topbar-clock" id="liveClock"></span>
        </div>
        <div class="topbar-right">
            <button class="theme-toggle" id="themeToggle" aria-label="Toggle theme">
                <svg class="icon-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
                <svg class="icon-moon" viewBox="0 0 24 24"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
            </button>
            <div class="topbar-divider"></div>
            <button type="button" class="user-avatar-btn" id="accountButton" title="Account" aria-haspopup="dialog" aria-expanded="false">
                <span class="user-avatar-circle">
                <?php if ($role === 'faculty'): ?>
                    <svg viewBox="0 0 24 24" style="width:18px;height:18px;fill:#fff;"><path d="M18 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zM9 4h2v5l-1-.75L9 9V4zm9 16H6V4h1v9l3-2.25L13 13V4h5v16z"/></svg>
                <?php elseif ($role === 'student'): ?>
                    <svg viewBox="0 0 24 24" style="width:18px;height:18px;fill:#fff;"><path d="M12 3L1 9l11 6 9-4.91V17h2V9L12 3zM5 13.18v4L12 21l7-3.82v-4L12 17l-7-3.82z"/></svg>
                <?php else: ?>
                    <?= $initials ?>
                <?php endif; ?>
            </span>
                <span class="user-avatar-meta">
                    <span class="user-avatar-name"><?= htmlspecialchars($heroName) ?></span>
                    <span class="user-avatar-role"><?= ucfirst($role) ?></span>
                </span>
            </button>
        </div>
    </header>

    <div class="account-popover" id="accountPopover" role="dialog" aria-label="Account overview">
        <button type="button" class="account-popover-close" id="accountPopoverClose" aria-label="Close account overview">&times;</button>
        <div class="account-avatar-large"><?= $initials ?></div>
        <div class="account-greeting">Hi, <?= htmlspecialchars($heroName) ?>!</div>
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
                <svg><use href="#icon-person"></use></svg>
                Sign out
            </a>
        </div>
    </div>

    <div class="body-wrapper">

        <!-- ══════════════════════════════════════════
             SIDEBAR
             ══════════════════════════════════════════ -->
        <nav class="sidebar" id="sidebar">
            <ul class="nav-list">
                <li>
                    <a href="dashboard.php" class="nav-item active">
                        <svg class="nav-icon"><use href="#icon-home"></use></svg>
                        <span>Dashboard</span>
                    </a>
                </li>

            </ul>

            <div class="nav-divider"></div>

            <?php if ($role === 'student' || $role === 'faculty'): ?>
            <div class="nav-section-title"><?= $role === 'faculty' ? 'Assigned Classes' : 'Enrolled' ?></div>
            <ul class="nav-list">
                <?php foreach (array_slice($myClasses, 0, 8) as $cls): ?>
                <li>
                    <a href="class.php?id=<?= urlencode($cls['id']) ?>" class="nav-item" style="padding-top:8px;padding-bottom:8px;">
                        <div style="width:32px;height:32px;border-radius:50%;background:#e8eaed;display:flex;align-items:center;justify-content:center;margin-left:-4px;color:#5f6368;font-weight:600;font-size:12px;flex-shrink:0;">
                            <?= strtoupper(substr($cls['subject_name'] ?? $cls['name'], 0, 1)) ?>
                        </div>
                        <div style="display:flex;flex-direction:column;overflow:hidden;">
                            <span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:13px;font-weight:500;color:var(--gc-text-primary);"><?= htmlspecialchars($cls['name']) ?></span>
                            <span style="font-size:11px;color:var(--gc-text-secondary);"><?= htmlspecialchars($cls['subject']) ?></span>
                        </div>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>

            <div class="nav-divider"></div>
            <div class="nav-section-title">Workspace</div>
            <ul class="nav-list">
                <li>
                    <a href="<?= htmlspecialchars($accountSettingsHref) ?>" class="nav-item">
                        <svg class="nav-icon"><use href="#icon-settings"></use></svg>
                        <span>Account Settings</span>
                    </a>
                </li>
            </ul>
        </nav>

        <!-- ══════════════════════════════════════════
             MAIN CONTENT
             ══════════════════════════════════════════ -->
        <main class="main-content" id="mainContent">

            <?php if ($role === 'admin'): ?>
            <div style="background:#fff;border:1px solid var(--gc-border-light);border-radius:8px;padding:32px;margin-bottom:32px;">
                <h2 style="font-weight:500;margin-bottom:24px;">System Overview</h2>
                <div style="display:flex;gap:24px;">
                    <?php foreach ($stats as $s): ?>
                    <div style="flex:1;border:1px solid #e8eaed;border-radius:8px;padding:24px;display:flex;align-items:center;gap:16px;">
                        <div style="width:48px;height:48px;border-radius:50%;background:rgba(26,115,232,.1);display:flex;align-items:center;justify-content:center;color:var(--gc-accent-blue);">
                            <svg width="24" height="24" fill="currentColor"><use href="#icon-<?= $s['icon'] ?>"></use></svg>
                        </div>
                        <div>
                            <div style="font-size:28px;font-weight:500;color:var(--gc-text-primary);line-height:1;"><?= $s['value'] ?></div>
                            <div style="font-size:13px;color:var(--gc-text-secondary);margin-top:4px;font-weight:500;"><?= strtoupper($s['label']) ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($role === 'faculty'): ?>
            <section class="faculty-dashboard" aria-label="Faculty dashboard overview">
                <div class="faculty-primary">
                    <div class="faculty-hero">
                        <div class="faculty-kicker"><?= date('l, F j') ?> - Helios Faculty Center</div>
                        <h2 class="faculty-title">Welcome, <?= htmlspecialchars($displayName) ?>.</h2>
                        <p class="faculty-copy">See your assigned classes, upcoming due dates, and the everyday actions that keep coursework moving.</p>
                        <div class="faculty-metrics">
                            <div class="metric-card">
                                <div class="metric-value"><?= count($myClasses) ?></div>
                                <div class="metric-label">Assigned Classes</div>
                            </div>
                            <div class="metric-card">
                                <div class="metric-value"><?= count($facultySubjects) ?></div>
                                <div class="metric-label">Subjects</div>
                            </div>
                            <div class="metric-card">
                                <div class="metric-value"><?= count($facultyStudents) ?></div>
                                <div class="metric-label">Students</div>
                            </div>
                        </div>
                    </div>

                    <div class="overview-panel quick-actions-panel">
                        <div class="panel-head">
                            <div>
                                <div class="panel-title">System Functions</div>
                                <div class="panel-note">Here's what you can do.</div>
                            </div>
                        </div>
                        <div class="quick-actions-grid">
                            <?php foreach ($facultyQuickActions as $action): ?>
                            <a class="quick-action" href="<?= htmlspecialchars($action['href']) ?>">
                                <span class="quick-icon"><svg><use href="#icon-<?= htmlspecialchars($action['icon']) ?>"></use></svg></span>
                                <span>
                                    <span class="quick-label"><?= htmlspecialchars($action['label']) ?></span>
                                    <span class="quick-meta"><?= htmlspecialchars($action['meta']) ?></span>
                                </span>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </div>


                </div>

                <aside class="faculty-side" id="faculty-calendar">
                    <?php include 'calendar_widget.php'; ?>
                </aside>
            </section>
            <?php endif; ?>

            <?php if ($role === 'student'): ?>
            <section class="student-dashboard-shell" aria-label="Student dashboard overview">
                <div class="student-main-panel">
                    <div class="student-search-row">
                        <div class="student-search" aria-label="Search placeholder">
                            <svg><use href="#icon-search"></use></svg>
                            <span>Search classes, lessons, and assignments</span>
                        </div>
                        <div class="student-date"><?= date('d M Y, l') ?></div>
                    </div>
                    <div class="student-welcome-card">
                        <div>
                            <h2>Welcome, <?= htmlspecialchars($heroName) ?>!</h2>
                            <p>Pick up your classes, review upcoming work, and keep your lessons moving from one clean workspace.</p>
                        </div>
                        <div class="student-book-stack" aria-hidden="true">
                            <span></span><span></span><span></span><span></span>
                        </div>
                    </div>
            <?php endif; ?>

            <?php if ($role === 'faculty' || $role === 'student'): ?>
            <div class="section-heading" id="assigned-classes">
                <div>
                    <h2><?= $role === 'faculty' ? 'Assigned Classes' : 'Your Classes' ?></h2>
                    <p><?= $role === 'faculty' ? 'Classes and subjects are assigned by the administrator.' : 'Pick up where you left off.' ?></p>
                </div>
            </div>
            <div class="classroom-grid">

                <?php if (empty($myClasses)): ?>
                <div class="empty-panel" style="grid-column:1/-1;">
                    <?= $role === 'faculty' ? 'No classes have been assigned to this faculty account yet. An administrator can assign classes and subjects from the admin dashboard.' : 'You are not enrolled in any classes yet.' ?>
                </div>
                <?php endif; ?>

                <?php
                $now = time();
                foreach ($myClasses as $index => $cls):
                    $classTitle  = $cls['name']    ?? 'Untitled Class';
                    $classSection= $cls['subject']  ?? 'General';
                    $classSubjects = $cls['subjects'] ?? [];
                    if ($role === 'student') {
                        $classSubjects = array_values(array_filter(
                            $classSubjects,
                            fn($subject) => in_array($usernameRaw, $subject['students'] ?? [], true)
                        ));
                    }

                    $upcomingTasks = [];
                    $classPosts = $cls['posts'] ?? [];
                    if (!empty($classPosts) && is_array($classPosts[0] ?? null)) {
                        foreach ($classPosts as $post) {
                            if (($post['type'] ?? '') !== 'assignment') continue;
                            $deadline = !empty($post['deadline']) ? strtotime($post['deadline']) : null;
                            if ($role === 'student') {
                                if ($deadline && $deadline > $now && !isset($post['submissions'][$usernameRaw])) {
                                    $upcomingTasks[] = ['id'=>$post['id'],'title'=>$post['title'] ?? 'Untitled','due'=>date('D, M j',$deadline)];
                                }
                            } else {
                                $ungraded = count(array_filter($post['submissions'] ?? [], fn($s) => !isset($s['score']) || $s['score'] === ''));
                                $dueText = $ungraded > 0
                                    ? $ungraded . ' ungraded submission' . ($ungraded > 1 ? 's' : '')
                                    : (!empty($post['deadline']) ? 'Due ' . date('D, M j', strtotime($post['deadline'])) : 'Active task');
                                $upcomingTasks[] = ['id'=>$post['id'],'title'=>$post['title'] ?? 'Untitled','due'=>$dueText];
                            }
                            if (count($upcomingTasks) >= 2) break;
                        }
                    }
                ?>

                <!-- CLASS CARD -->
                <div class="gc-card">
                    <div class="gc-card-top-accent"></div>

                    <div class="gc-card-header">
                        <h2 class="gc-card-title">
                            <a href="class.php?id=<?= urlencode($cls['id']) ?>"><?= htmlspecialchars($classTitle) ?></a>
                        </h2>
                        <div class="gc-card-subtitle"><?= htmlspecialchars($classSection) ?></div>

                        <div class="subject-pill-row" style="margin-top:10px;">
                            <?php if (!empty($classSubjects)): ?>
                                <?php foreach (array_slice($classSubjects, 0, 3) as $subj): ?>
                                <button class="subject-pill"
                                        onclick="openSubjectDrawer('<?= htmlspecialchars(addslashes($cls['id'])) ?>', '<?= htmlspecialchars(addslashes($subj['id'])) ?>')"
                                        title="<?= htmlspecialchars($subj['name']) ?>">
                                    <svg><use href="#icon-book"></use></svg>
                                    <?= htmlspecialchars($subj['name']) ?>
                                </button>
                                <?php endforeach; ?>
                                <?php if (count($classSubjects) > 3): ?>
                                <button class="subject-pill" onclick="openSubjectDrawer('<?= htmlspecialchars(addslashes($cls['id'])) ?>', null)">
                                    +<?= count($classSubjects) - 3 ?> more
                                </button>
                                <?php endif; ?>
                            <?php else: ?>
                                <button class="subject-pill"
                                        onclick="openSubjectDrawer('<?= htmlspecialchars(addslashes($cls['id'])) ?>', null)"
                                        style="color:var(--gc-text-tertiary);">
                                    <svg><use href="#icon-book"></use></svg>
                                    <?= $role === 'faculty' ? 'No subjects assigned' : 'No subjects yet' ?>
                                </button>
                            <?php endif; ?>
                        </div>

                        <button class="gc-card-menu-btn" aria-label="Class options">
                            <svg><use href="#icon-more-vert"></use></svg>
                        </button>
                    </div>

                    <div class="gc-card-body">
                        <div class="gc-upcoming-label"><?= $role === 'student' ? 'Due soon' : 'To-do' ?></div>
                        <?php if (!empty($upcomingTasks)): ?>
                        <div class="gc-task-list">
                            <?php foreach ($upcomingTasks as $task): ?>
                            <a href="class.php?id=<?= urlencode($cls['id']) ?>&post=<?= htmlspecialchars($task['id']) ?>" class="gc-task-item" style="text-decoration:none;color:inherit;">
                                <svg class="gc-task-icon"><use href="#icon-assignment"></use></svg>
                                <div class="gc-task-details">
                                    <div class="gc-task-title"><?= htmlspecialchars($task['title']) ?></div>
                                    <div class="gc-task-due"><?= htmlspecialchars($task['due']) ?></div>
                                </div>
                            </a>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <div class="gc-empty-tasks">No tasks detected. Rest easy!</div>
                        <?php endif; ?>
                    </div>

                    <!-- Card Footer — subjects pills + actions -->
                    <div class="gc-card-footer">
                        <!-- Subject pills (clickable → opens drawer) -->
                        <div style="display:none;">
                            <?php if (!empty($classSubjects)): ?>
                                <?php foreach (array_slice($classSubjects, 0, 3) as $subj): ?>
                                <button class="subject-pill"
                                        onclick="openSubjectDrawer('<?= htmlspecialchars(addslashes($cls['id'])) ?>', '<?= htmlspecialchars(addslashes($subj['id'])) ?>')"
                                        title="<?= htmlspecialchars($subj['name']) ?>">
                                    <svg><use href="#icon-book"></use></svg>
                                    <?= htmlspecialchars($subj['name']) ?>
                                </button>
                                <?php endforeach; ?>
                                <?php if (count($classSubjects) > 3): ?>
                                <button class="subject-pill" onclick="openSubjectDrawer('<?= htmlspecialchars(addslashes($cls['id'])) ?>', null)">
                                    +<?= count($classSubjects) - 3 ?> more
                                </button>
                                <?php endif; ?>
                            <?php else: ?>
                                <button class="subject-pill"
                                        onclick="openSubjectDrawer('<?= htmlspecialchars(addslashes($cls['id'])) ?>', null)"
                                        style="color:var(--gc-text-tertiary);">
                                    <svg><use href="#icon-book"></use></svg>
                                    <?= $role === 'faculty' ? 'No subjects assigned' : 'No subjects yet' ?>
                                </button>
                            <?php endif; ?>
                        </div>

                        <!-- Right-side icons -->
                        <div class="gc-footer-right">
                            <a href="class.php?id=<?= urlencode($cls['id']) ?>" class="gc-card-goto" title="Open class">
                                Open
                                <svg viewBox="0 0 24 24" style="width:14px;height:14px;fill:currentColor;"><path d="M8.59 16.59L13.17 12 8.59 7.41 10 6l6 6-6 6z"/></svg>
                            </a>
                            <?php if ($role === 'student'): ?>
                            <a href="class.php?id=<?= urlencode($cls['id']) ?>#class-work" class="gc-footer-icon" title="Your work">
                                <svg><use href="#icon-person"></use></svg>
                            </a>
                            <?php elseif ($role === 'faculty'): ?>
                            <a href="class.php?id=<?= urlencode($cls['id']) ?>#class-work" class="gc-footer-icon" title="Gradebook">
                                <svg><use href="#icon-grade"></use></svg>
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <!-- / CLASS CARD -->

                <?php endforeach; ?>

            </div>
            <?php endif; ?>

            <?php if ($role === 'student'): ?>
                </div>
                <aside class="student-side-panel" aria-label="Student profile and reminders">
                    <div class="student-profile-card">
                        <div class="student-profile-avatar"><?= htmlspecialchars($initials) ?></div>
                        <h3><?= htmlspecialchars($heroName) ?></h3>
                        <p>Student</p>
                        <a href="<?= htmlspecialchars($accountSettingsHref) ?>">Profile</a>
                    </div>
                    <?php include 'calendar_widget.php'; ?>
                </aside>
            </section>
            <?php endif; ?>

        </main>
    </div>
</div>


<!-- ══════════════════════════════════════════════════════════════════
     SUBJECT DRAWER  (slides in from right)
     ══════════════════════════════════════════════════════════════════ -->
<div class="subject-drawer-overlay" id="sdOverlay" onclick="closeSubjectDrawer()"></div>

<div class="subject-drawer" id="subjectDrawer">
    <div class="sd-header">
        <div>
            <div class="sd-class-name" id="sdClassName">—</div>
            <div class="sd-class-sub"  id="sdClassSub">—</div>
        </div>
        <button class="sd-close" onclick="closeSubjectDrawer()">
            <svg><use href="#icon-close"></use></svg>
        </button>
    </div>

    <!-- Tabs rendered by JS -->
    <div class="sd-tabs" id="sdTabs"></div>

    <!-- Panels rendered by JS -->
    <div class="sd-body" id="sdBody"></div>
</div>


<!-- ══════════════════════════════════════════════════════════════════
     MODAL: Faculty — Create Class  (original)
     ══════════════════════════════════════════════════════════════════ -->
<?php if (false && $role === 'faculty'): ?>
<div class="modal-overlay" id="createModal">
    <div class="modal-container">
        <div class="modal-header"><h2 class="modal-title">Create class</h2></div>
        <form method="POST" action="dashboard.php" id="createClassForm">
            <div class="modal-body">
                <div class="input-group">
                    <input type="text" id="class_name" name="class_name" class="gc-input" placeholder=" " required autocomplete="off">
                    <label for="class_name" class="gc-input-label">Class name (required)</label>
                </div>
                <div class="input-group">
                    <input type="text" id="section" name="section" class="gc-input" placeholder=" " autocomplete="off">
                    <label for="section" class="gc-input-label">Section</label>
                </div>
                <div class="input-group">
                    <input type="text" id="subject" name="subject" class="gc-input" placeholder=" " autocomplete="off">
                    <label for="subject" class="gc-input-label">Subject</label>
                </div>
                <div class="input-group">
                    <input type="text" id="room" name="room" class="gc-input" placeholder=" " autocomplete="off">
                    <label for="room" class="gc-input-label">Room</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-text" onclick="closeCreateModal()">Cancel</button>
                <button type="submit" class="btn-text" style="color:var(--gc-accent-blue);font-weight:600;">Create</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>


<!-- ══════════════════════════════════════════════════════════════════
     MODAL: Student — Join Class  (original)
     ══════════════════════════════════════════════════════════════════ -->



<!-- ══════════════════════════════════════════════════════════════════
     MODAL: Faculty — Add Subject
     ══════════════════════════════════════════════════════════════════ -->
<?php if (false && $role === 'faculty'): ?>
<div class="modal-overlay" id="addSubjectModal">
    <div class="modal-container">
        <div class="modal-header"><h2 class="modal-title">Add Subject</h2></div>
        <div class="modal-body">
            <input type="hidden" id="asModalClassId">
            <div class="input-group">
                <input type="text" id="asName" class="gc-input" placeholder=" " autocomplete="off">
                <label for="asName" class="gc-input-label">Subject name (required)</label>
            </div>
            <div class="form-group">
                <label class="gc-label">Assign Faculty <span style="color:var(--gc-text-tertiary);font-weight:400;">(optional)</span></label>
                <select class="gc-select" id="asFaculty">
                    <option value="">— No faculty yet —</option>
                    <?php foreach ($facultyUsers as $fac): ?>
                    <option value="<?= htmlspecialchars($fac['username']) ?>"><?= htmlspecialchars($fac['fullname'] ?? $fac['username']) ?> (@<?= htmlspecialchars($fac['username']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn-text" onclick="closeModal('addSubjectModal')">Cancel</button>
            <button class="btn-primary" onclick="commitAddSubject()">
                <svg style="width:16px;height:16px;fill:currentColor;"><use href="#icon-add"></use></svg> Add Subject
            </button>
        </div>
    </div>
</div>

<!-- MODAL: Assign / Change Faculty -->
<div class="modal-overlay" id="assignFacultyModal">
    <div class="modal-container">
        <div class="modal-header"><h2 class="modal-title">Assign Faculty</h2></div>
        <div class="modal-body">
            <input type="hidden" id="afClassId">
            <input type="hidden" id="afSubjectId">
            <p style="font-size:13px;color:var(--gc-text-secondary);margin-bottom:20px;">Assigning faculty to: <strong id="afSubjectName"></strong></p>
            <div class="form-group">
                <label class="gc-label">Select Faculty Member</label>
                <select class="gc-select" id="afSelect">
                    <option value="">— Remove assignment —</option>
                    <?php foreach ($facultyUsers as $fac): ?>
                    <option value="<?= htmlspecialchars($fac['username']) ?>"><?= htmlspecialchars($fac['fullname'] ?? $fac['username']) ?> (@<?= htmlspecialchars($fac['username']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn-text" onclick="closeModal('assignFacultyModal')">Cancel</button>
            <button class="btn-primary" onclick="commitAssignFaculty()">Save</button>
        </div>
    </div>
</div>
<?php endif; ?>


<!-- ══════════════════════════════════════════════════════════════════
     PHP → JS DATA BRIDGE
     ══════════════════════════════════════════════════════════════════ -->
<script>
const ROLE         = <?= json_encode($role) ?>;
const USERNAME     = <?= json_encode($usernameRaw) ?>;
const NAME_MAP     = <?= json_encode($nameMap) ?>;
const ALL_FACULTY  = [];
const ALL_STUDENTS = [];

// In-memory class store (mirrors PHP)
let classStore = <?= json_encode(array_values($myClasses)) ?>;
let subjectSeed = <?= $subjectCounterSeed ?>;

function getClass(cId)   { return classStore.find(c => c.id === cId); }
function getSubject(cId, sId) { const c = getClass(cId); return c ? (c.subjects||[]).find(s => s.id === sId) : null; }

/* ── util ─────────────────────────────────── */
function esc(s) {
    return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function initials(name) { return (name||'').substring(0,2).toUpperCase() || '?'; }
function toast(msg, type='success') {
    const t = document.createElement('div');
    const bg = type==='error'?'#d93025':type==='warn'?'#f9ab00':'#1e8e3e';
    t.style.cssText=`position:fixed;bottom:24px;right:24px;z-index:9999;padding:12px 20px;border-radius:8px;font-family:Poppins,sans-serif;font-size:14px;font-weight:500;color:#fff;box-shadow:0 4px 16px rgba(0,0,0,.15);background:${bg};transition:opacity .3s;`;
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(()=>{ t.style.opacity='0'; setTimeout(()=>t.remove(),300); }, 2800);
}

/* ── Modal helpers ─────────────────────────── */
function openModal(id)  { document.getElementById(id)?.classList.add('active'); }
function closeModal(id) { document.getElementById(id)?.classList.remove('active'); }
document.querySelectorAll('.modal-overlay').forEach(o => {
    o.addEventListener('click', e => { if (e.target===o) o.classList.remove('active'); });
});
document.addEventListener('keydown', e => {
    if (e.key==='Escape') document.querySelectorAll('.modal-overlay.active').forEach(m=>m.classList.remove('active'));
});

/* ── Original modal functions (kept identical) ─ */
window.openCreateModal = () => { openModal('createModal'); setTimeout(()=>document.getElementById('class_name')?.focus(),100); };
window.closeCreateModal= () => closeModal('createModal');

/* ══════════════════════════════════════════════════════
   SUBJECT DRAWER
   ══════════════════════════════════════════════════════ */
let _drawerClassId   = null;
let _drawerActiveTab = null;

function openSubjectDrawer(cId, focusSid) {
    const cls = getClass(cId);
    if (!cls) return;
    _drawerClassId = cId;

    document.getElementById('sdClassName').textContent = cls.name;
    document.getElementById('sdClassSub').textContent  = cls.subject;

    renderDrawer(cls, focusSid);

    document.getElementById('sdOverlay').classList.add('open');
    document.getElementById('subjectDrawer').classList.add('open');
}

function closeSubjectDrawer() {
    document.getElementById('sdOverlay').classList.remove('open');
    document.getElementById('subjectDrawer').classList.remove('open');
    _drawerClassId   = null;
    _drawerActiveTab = null;
}

function renderDrawer(cls, focusSid) {
    const subjects = cls.subjects || [];
    const tabs  = document.getElementById('sdTabs');
    const body  = document.getElementById('sdBody');

    tabs.innerHTML = '';
    body.innerHTML = '';

    if (subjects.length === 0) {
        // Empty state
        body.innerHTML = `
            <div class="sd-empty">
                <svg viewBox="0 0 24 24"><use href="#icon-book"></use></svg>
                <p>${ROLE==='faculty'
                    ? 'No subjects have been assigned to this class yet.'
                    : 'No subjects have been added to this class yet.'}</p>
            </div>`;
    }

    // Build tabs + panels
    subjects.forEach((subj, idx) => {
        const isActive = focusSid ? subj.id === focusSid : idx === 0;

        // Tab
        const tab = document.createElement('div');
        tab.className = 'sd-tab' + (isActive ? ' active' : '');
        tab.dataset.sid = subj.id;
        tab.innerHTML  = `<svg><use href="#icon-book"></use></svg> ${esc(subj.name)}`;
        tab.onclick = () => switchDrawerTab(cls.id, subj.id);
        tabs.appendChild(tab);

        // Panel
        body.appendChild(buildPanel(cls, subj, isActive));
    });

    // Faculty: "Add Subject" button at end of tabs
    if (false && ROLE === 'faculty') {
        const addTab = document.createElement('div');
        addTab.className = 'sd-tab-add';
        addTab.innerHTML = `<svg><use href="#icon-add"></use></svg> Add Subject`;
        addTab.onclick = () => openAddSubjectModal(cls.id);
        tabs.appendChild(addTab);
    }

    _drawerActiveTab = focusSid || (subjects[0]?.id ?? null);
}

function buildPanel(cls, subj, isActive) {
    const facName = subj.faculty ? (NAME_MAP[subj.faculty] || subj.faculty) : '';
    const facInit = facName ? initials(facName) : '—';
    const students = subj.students || [];

    const panel = document.createElement('div');
    panel.className = 'sd-panel' + (isActive ? ' active' : '');
    panel.id = `sdPanel-${cls.id}-${subj.id}`;

    /* ── Faculty card HTML ── */
    const facCardHTML = facName
        ? `<div class="sd-avatar">${esc(facInit)}</div>
           <div><div class="sd-faculty-name">${esc(facName)}</div><div class="sd-faculty-role">Faculty · @${esc(subj.faculty)}</div></div>`
        : `<div class="sd-avatar" style="background:var(--gc-bg-active);color:var(--gc-text-tertiary);">—</div>
           <div class="sd-unassigned">No faculty assigned yet</div>`;

    const assignBtn = false && ROLE === 'faculty'
        ? `<button class="btn-text" style="font-size:13px;padding:6px 12px;"
                   onclick="openAssignFacultyModal('${esc(cls.id)}','${esc(subj.id)}','${esc(subj.name)}','${esc(subj.faculty||'')}')">
               <svg style="width:14px;height:14px;fill:currentColor;vertical-align:middle;margin-right:4px;"><use href="#icon-edit"></use></svg>
               ${subj.faculty ? 'Change' : 'Assign'}
           </button>`
        : '';

    /* ── Student list HTML ── */
    let studentsHTML = '';
    if (students.length === 0) {
        studentsHTML = `<div class="sd-no-content">No students enrolled in this subject yet.</div>`;
    } else {
        studentsHTML = students.map(stu => {
            const sName = NAME_MAP[stu] || stu;
            const sInit = initials(sName);
            const removeBtn = false && ROLE === 'faculty'
                ? `<button class="sd-remove-btn" onclick="removeStudentFromSubject('${esc(cls.id)}','${esc(subj.id)}','${esc(stu)}','${esc(sName)}')">Remove</button>`
                : '';
            return `
            <div class="sd-student-item" id="sdStu-${esc(cls.id)}-${esc(subj.id)}-${esc(stu)}">
                <div class="sd-student-info">
                    <div class="sd-avatar blue" style="width:32px;height:32px;font-size:12px;">${esc(sInit)}</div>
                    <div>
                        <div class="sd-student-name">${esc(sName)}</div>
                        <div class="sd-student-user">@${esc(stu)}</div>
                    </div>
                </div>
                ${removeBtn}
            </div>`;
        }).join('');
    }

    /* ── Enroll dropdown (faculty only) ── */
    const enrolledSet = new Set(students);
    const enrollHTML = false && ROLE === 'faculty' ? `
        <div class="sd-enroll-row">
            <select class="sd-select" id="sdEnrollSel-${esc(cls.id)}-${esc(subj.id)}">
                <option value=""> Enroll a student </option>
                ${ALL_STUDENTS.filter(s => !enrolledSet.has(s.username)).map(s =>
                    `<option value="${esc(s.username)}">${esc(s.fullname)} (@${esc(s.username)})</option>`
                ).join('')}
            </select>
            <button class="btn-primary sm"
                    onclick="enrollStudentInSubject('${esc(cls.id)}','${esc(subj.id)}')">
                <svg style="width:15px;height:15px;fill:currentColor;"><use href="#icon-add"></use></svg> Enroll
            </button>
        </div>` : '';

    /* ── Remove subject (faculty only) ── */
    const removeSubjBtn = false && ROLE === 'faculty'
        ? `<button class="btn-text danger" style="font-size:12px;padding:4px 10px;"
                   onclick="removeSubject('${esc(cls.id)}','${esc(subj.id)}','${esc(subj.name)}')">
               <svg style="width:13px;height:13px;fill:currentColor;vertical-align:middle;margin-right:3px;"><use href="#icon-trash"></use></svg>Remove
           </button>`
        : '';

    panel.innerHTML = `
        <div class="sd-panel-header">
            <div>
                <div class="sd-panel-title">${esc(subj.name)}</div>
                <div class="sd-panel-id">ID: ${esc(subj.id)}</div>
            </div>
            ${removeSubjBtn}
        </div>

        <div class="sd-section-heading"><svg><use href="#icon-person"></use></svg> Assigned Faculty</div>
        <div class="sd-faculty-card" id="sdFacCard-${esc(cls.id)}-${esc(subj.id)}">
            <div class="sd-faculty-info">${facCardHTML}</div>
            ${assignBtn}
        </div>

        <div class="sd-divider"></div>

        <div class="sd-section-heading">
            <svg><use href="#icon-person"></use></svg> Enrolled Students
            <span style="font-size:11px;font-weight:400;color:var(--gc-text-tertiary);text-transform:none;margin-left:4px;" id="sdStuCount-${esc(cls.id)}-${esc(subj.id)}">${students.length} enrolled</span>
        </div>
        <div class="sd-student-list" id="sdStuList-${esc(cls.id)}-${esc(subj.id)}">${studentsHTML}</div>
        ${enrollHTML}
    `;
    return panel;
}

function switchDrawerTab(cId, sId) {
    document.querySelectorAll('.sd-tab').forEach(t => t.classList.toggle('active', t.dataset.sid === sId));
    document.querySelectorAll('.sd-panel').forEach(p => p.classList.toggle('active', p.id === `sdPanel-${cId}-${sId}`));
    _drawerActiveTab = sId;
}

/* ══════════════════════════════════════════════════════
   FACULTY — ADD SUBJECT
   ══════════════════════════════════════════════════════ */
function openAddSubjectModal(cId) {
    document.getElementById('asModalClassId').value = cId;
    document.getElementById('asName').value         = '';
    document.getElementById('asFaculty').value      = '';
    openModal('addSubjectModal');
}

function commitAddSubject() {
    const cId    = document.getElementById('asModalClassId').value;
    const name   = document.getElementById('asName').value.trim();
    const faculty= document.getElementById('asFaculty').value;
    if (!name) { toast('Subject name is required.','error'); return; }

    subjectSeed++;
    const sId  = 'S' + String(subjectSeed).padStart(3,'0');
    const cls  = getClass(cId);
    if (!cls) return;
    if (!cls.subjects) cls.subjects = [];
    const newSubj = { id:sId, name, faculty, students:[] };
    cls.subjects.push(newSubj);

    // Re-render drawer
    renderDrawer(cls, sId);

    // Refresh pill row on card (page-level)
    refreshCardPills(cId);

    closeModal('addSubjectModal');
    toast(`Subject "${name}" added!`);
}

/* ══════════════════════════════════════════════════════
   FACULTY — REMOVE SUBJECT
   ══════════════════════════════════════════════════════ */
function removeSubject(cId, sId, sName) {
    if (!confirm(`Remove subject "${sName}"?`)) return;
    const cls = getClass(cId);
    if (!cls) return;
    cls.subjects = (cls.subjects||[]).filter(s => s.id !== sId);
    renderDrawer(cls, cls.subjects[0]?.id ?? null);
    refreshCardPills(cId);
    toast(`Subject "${sName}" removed.`, 'warn');
}

/* ══════════════════════════════════════════════════════
   FACULTY — ASSIGN FACULTY TO SUBJECT
   ══════════════════════════════════════════════════════ */
function openAssignFacultyModal(cId, sId, sName, currentFac) {
    document.getElementById('afClassId').value     = cId;
    document.getElementById('afSubjectId').value   = sId;
    document.getElementById('afSubjectName').textContent = sName;
    document.getElementById('afSelect').value      = currentFac || '';
    openModal('assignFacultyModal');
}

function commitAssignFaculty() {
    const cId  = document.getElementById('afClassId').value;
    const sId  = document.getElementById('afSubjectId').value;
    const fac  = document.getElementById('afSelect').value;
    const subj = getSubject(cId, sId);
    if (!subj) return;
    subj.faculty = fac;

    const cls = getClass(cId);
    renderDrawer(cls, sId);

    closeModal('assignFacultyModal');
    toast(fac ? `Faculty "${NAME_MAP[fac]||fac}" assigned!` : 'Faculty assignment removed.', fac ? 'success' : 'warn');
}

/* ══════════════════════════════════════════════════════
   FACULTY — ENROLL STUDENT
   ══════════════════════════════════════════════════════ */
function enrollStudentInSubject(cId, sId) {
    const sel = document.getElementById(`sdEnrollSel-${cId}-${sId}`);
    const stu = sel?.value;
    if (!stu) { toast('Please select a student.','error'); return; }

    const subj = getSubject(cId, sId);
    if (!subj) return;
    if (!subj.students) subj.students = [];
    if (subj.students.includes(stu)) { toast('Student already enrolled.','warn'); return; }
    subj.students.push(stu);

    const cls = getClass(cId);
    renderDrawer(cls, sId);
    toast(`${NAME_MAP[stu]||stu} enrolled!`);
}

/* ══════════════════════════════════════════════════════
   FACULTY — REMOVE STUDENT
   ══════════════════════════════════════════════════════ */
function removeStudentFromSubject(cId, sId, stu, stuName) {
    if (!confirm(`Remove ${stuName} from this subject?`)) return;
    const subj = getSubject(cId, sId);
    if (!subj) return;
    subj.students = (subj.students||[]).filter(s => s !== stu);

    const cls = getClass(cId);
    renderDrawer(cls, sId);
    toast(`${stuName} removed.`, 'warn');
}

/* ══════════════════════════════════════════════════════
   REFRESH PILL ROW ON CARD  (after add/remove subject)
   ══════════════════════════════════════════════════════ */
function refreshCardPills(cId) {
    const cls = getClass(cId);
    if (!cls) return;
    // Find the card's pill row — locate card by its footer link hrefs
    document.querySelectorAll('.gc-card').forEach(card => {
        const link = card.querySelector(`a[href*="id=${encodeURIComponent(cId)}"]`);
        if (!link) return;
        const pillRow = card.querySelector('.subject-pill-row');
        if (!pillRow) return;
        const subjects = cls.subjects || [];
        if (subjects.length === 0) {
            pillRow.innerHTML = `<button class="subject-pill" onclick="openSubjectDrawer('${esc(cId)}',null)" style="color:var(--gc-text-tertiary);">
                <svg style="width:11px;height:11px;fill:currentColor;"><use href="#icon-book"></use></svg>
                ${ROLE==='faculty'?'No subjects assigned':'No subjects yet'}
            </button>`;
            return;
        }
        const visible = subjects.slice(0,3);
        const extra   = subjects.length - visible.length;
        pillRow.innerHTML = visible.map(s =>
            `<button class="subject-pill" onclick="openSubjectDrawer('${esc(cId)}','${esc(s.id)}')" title="${esc(s.name)}">
                <svg style="width:11px;height:11px;fill:currentColor;"><use href="#icon-book"></use></svg>
                ${esc(s.name)}
             </button>`
        ).join('') + (extra > 0
            ? `<button class="subject-pill" onclick="openSubjectDrawer('${esc(cId)}',null)">+${extra} more</button>`
            : '');
    });
}

/* ══════════════════════════════════════════════════════
   SIDEBAR & DRAWER TOGGLE  (original logic, unchanged)
   ══════════════════════════════════════════════════════ */
document.addEventListener('DOMContentLoaded', () => {
    const drawerToggle = document.getElementById('drawerToggle');
    const sidebar      = document.getElementById('sidebar');
    const mainContent  = document.getElementById('mainContent');
    const themeToggles = document.querySelectorAll('.theme-toggle');
    const liveClock    = document.getElementById('liveClock');
    const accountButton = document.getElementById('accountButton');
    const accountPopover = document.getElementById('accountPopover');
    const accountPopoverClose = document.getElementById('accountPopoverClose');

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

    function checkWidth() {
        if (window.innerWidth > 1024) {
            sidebar.classList.remove('mobile-open');
        } else {
            sidebar.classList.remove('collapsed');
            mainContent.classList.remove('expanded');
        }
    }
    window.addEventListener('resize', checkWidth);
    checkWidth();

    drawerToggle.addEventListener('click', () => {
        if (window.innerWidth > 1024) {
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('expanded');
        } else {
            sidebar.classList.toggle('mobile-open');
        }
    });

    document.addEventListener('click', e => {
        if (window.innerWidth <= 1024 &&
            sidebar.classList.contains('mobile-open') &&
            !sidebar.contains(e.target) &&
            !drawerToggle.contains(e.target)) {
            sidebar.classList.remove('mobile-open');
        }

        if (accountPopover && accountPopover.classList.contains('open') &&
            !accountPopover.contains(e.target) &&
            !accountButton.contains(e.target)) {
            accountPopover.classList.remove('open');
            accountButton.setAttribute('aria-expanded', 'false');
        }
    });

    // Original floating-label enhancement
    document.querySelectorAll('.gc-input').forEach(input => {
        input.addEventListener('blur', function() {
            if (this.value.trim()) this.setAttribute('data-has-value','true');
            else { this.removeAttribute('data-has-value'); this.value=''; }
        });
    });
});
</script>

</body>
</html>
