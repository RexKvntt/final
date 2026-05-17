<?php
session_start();

if (!isset($_SESSION['username']) || $_SESSION['role'] !== 'student') {
    header("Location: login.php");
    exit();
}

$username    = $_SESSION['username'];
$classesFile = __DIR__ . '/classes.json';
$usersFile   = __DIR__ . '/users.json';

if (!file_exists($classesFile)) {
    header("Location: dashboard.php");
    exit();
}

$classesData = json_decode(file_get_contents($classesFile), true);
$usersData   = json_decode(file_get_contents($usersFile), true);

/* ── Find the class ── */
$classId = trim($_GET['class_id'] ?? '');
$cls     = null;
foreach ($classesData['classes'] as $c) {
    if ($c['id'] === $classId && in_array($username, $c['members'] ?? [])) {
        $cls = $c;
        break;
    }
}

if (!$cls) {
    header("Location: dashboard.php?error=not_found");
    exit();
}

/* ── Build user lookup ── */
$usersLookup = [];
foreach ($usersData['user'] as $u) {
    $usersLookup[$u['username']] = $u;
}

/* ── Student info ── */
$studentUser = $usersLookup[$username] ?? [];
$myFullname  = $studentUser['fullname'] ?? $username;
$myAvatar    = $studentUser['avatar'] ?? null;
$myInitials  = strtoupper(substr($myFullname, 0, 1));

/* ── Faculty info ── */
$facultyUsername = $cls['owner'] ?? '';
$facultyUser     = $usersLookup[$facultyUsername] ?? [];
$facultyName     = $facultyUser['display_name'] ?? $facultyUser['fullname'] ?? $facultyUsername;
$facultyAvatar   = $facultyUser['avatar'] ?? null;
$facultyInitials = strtoupper(substr($facultyName, 0, 1));

/* ── Active tab ── */
$tab = $_GET['tab'] ?? 'feed'; // feed | members | grades

/* ── Posts & members ── */
$members = $cls['members'] ?? [];
$posts   = array_reverse($cls['posts'] ?? []); // newest first

/* ── Helpers ── */
function fileIcon(string $ext): string {
    return match(strtolower($ext)) {
        'pdf'                    => '📄',
        'doc','docx'             => '📝',
        'ppt','pptx'             => '📊',
        'xls','xlsx'             => '📈',
        'zip'                    => '🗜️',
        'jpg','jpeg','png','gif' => '🖼️',
        'mp4'                    => '🎬',
        'mp3'                    => '🎵',
        default                  => '📁',
    };
}

function timeAgo(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)     return 'just now';
    if ($diff < 3600)   return floor($diff / 60) . 'm ago';
    if ($diff < 86400)  return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M j', strtotime($datetime));
}

function postTypeConfig(string $type): array {
    return match($type) {
        'assignment'   => ['label' => 'Assignment',   'color' => 'var(--accent)',   'bg' => 'var(--accent-light)',  'icon' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>'],
        'material'     => ['label' => 'Material',     'color' => 'var(--success)',  'bg' => 'var(--success-light)', 'icon' => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>'],
        default        => ['label' => 'Announcement', 'color' => 'var(--warning)',  'bg' => 'var(--warning-light)', 'icon' => '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>'],
    };
}

/* ── My grades summary ── */
$myGrades = [];
foreach ($cls['posts'] ?? [] as $p) {
    if ($p['type'] === 'assignment') {
        $sub = $p['submissions'][$username] ?? null;
        $myGrades[] = [
            'title'    => $p['title'] ?? 'Untitled',
            'points'   => $p['points'] ?? null,
            'deadline' => $p['deadline'] ?? null,
            'submitted'=> $sub !== null,
            'score'    => $sub['score'] ?? null,
            'note'     => $sub['note'] ?? null,
            'post_id'  => $p['id'],
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($cls['name']) ?> — Helios</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='8' fill='%231e40af'/><text x='50%25' y='54%25' dominant-baseline='middle' text-anchor='middle' font-size='18' font-family='sans-serif' font-weight='bold' fill='white'>S</text></svg>">
    <link rel="stylesheet" href="style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Instrument+Serif:ital@0;1&family=Sora:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script>
        (function(){
            var t = localStorage.getItem('theme') || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
            document.documentElement.setAttribute('data-theme', t);
        })();
    </script>
    <style>
        .class-wrapper { display: flex; flex-direction: column; min-height: 100vh; background: var(--bg); }

        /* ── Hero ── */
        .class-hero {
            background: linear-gradient(135deg, #0d1f40 0%, #1e40af 60%, #2563eb 100%);
            padding: 36px 40px 0;
            position: relative; overflow: hidden;
        }
        [data-theme="light"] .class-hero { background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 60%, #2563eb 100%); }
        .class-hero::before {
            content: '';
            position: absolute; inset: 0;
            background:
                radial-gradient(ellipse 50% 80% at 90% 50%, rgba(255,255,255,0.05) 0%, transparent 60%),
                radial-gradient(ellipse 30% 60% at 10% 80%, rgba(255,255,255,0.03) 0%, transparent 55%);
            pointer-events: none;
        }
        .class-hero-inner { max-width: 1100px; margin: 0 auto; position: relative; z-index: 1; }
        .class-hero-top {
            display: flex; align-items: flex-start;
            justify-content: space-between; gap: 20px;
            margin-bottom: 28px; flex-wrap: wrap;
        }
        .class-hero-back {
            display: inline-flex; align-items: center; gap: 6px;
            font-size: 13px; font-weight: 600;
            color: rgba(255,255,255,0.7);
            text-decoration: none; padding: 6px 12px;
            border-radius: var(--radius-sm);
            transition: color 0.18s, background 0.18s;
        }
        .class-hero-back:hover { color: #fff; background: rgba(255,255,255,0.1); }
        .class-hero-back svg { width: 14px; height: 14px; stroke: currentColor; fill: none; stroke-width: 2.5; stroke-linecap: round; stroke-linejoin: round; }
        .class-hero-actions { display: flex; align-items: center; gap: 10px; }
        .hero-icon-btn {
            width: 36px; height: 36px;
            background: rgba(255,255,255,0.12);
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; transition: background 0.18s; color: #fff;
        }
        .hero-icon-btn:hover { background: rgba(255,255,255,0.22); }
        .hero-icon-btn svg { width: 15px; height: 15px; stroke: currentColor; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }

        .class-hero-subject {
            font-size: 12px; font-weight: 700;
            letter-spacing: 0.1em; text-transform: uppercase;
            color: rgba(255,255,255,0.6); margin-bottom: 8px;
        }
        .class-hero-name {
            font-family: 'Instrument Serif', serif;
            font-size: 44px; font-style: italic;
            color: #fff; line-height: 1.1;
            letter-spacing: -0.01em; margin-bottom: 12px;
        }
        .class-hero-meta { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; margin-bottom: 28px; }
        .hero-meta-chip {
            display: inline-flex; align-items: center; gap: 6px;
            background: rgba(255,255,255,0.12);
            border: 1px solid rgba(255,255,255,0.18);
            border-radius: 999px; padding: 5px 14px;
            font-size: 12.5px; font-weight: 600;
            color: rgba(255,255,255,0.85);
        }
        .hero-meta-chip svg { width: 12px; height: 12px; stroke: currentColor; fill: none; stroke-width: 2.5; stroke-linecap: round; stroke-linejoin: round; }

        /* ── Tabs ── */
        .class-tabs { display: flex; gap: 0; margin-top: 28px; position: relative; z-index: 1; }
        .class-tab {
            padding: 12px 24px;
            font-family: 'Sora', sans-serif;
            font-size: 13.5px; font-weight: 600;
            color: rgba(255,255,255,0.6);
            text-decoration: none;
            border-radius: 12px 12px 0 0;
            transition: color 0.18s, background 0.18s;
            display: flex; align-items: center; gap: 7px;
        }
        .class-tab svg { width: 14px; height: 14px; stroke: currentColor; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
        .class-tab:hover { color: rgba(255,255,255,0.9); background: rgba(255,255,255,0.08); }
        .class-tab.active { color: var(--text-primary); background: var(--bg); }

        /* ── Content ── */
        .class-content {
            flex: 1;
            max-width: 1100px; width: 100%;
            margin: 0 auto;
            padding: 36px 40px;
            display: grid;
            grid-template-columns: 1fr 300px;
            gap: 28px;
            align-items: start;
        }
        @media (max-width: 860px) {
            .class-content { grid-template-columns: 1fr; padding: 24px 20px; }
            .class-sidebar { order: -1; }
            .class-hero { padding: 28px 20px 0; }
            .class-hero-name { font-size: 32px; }
        }

        /* ── Post cards ── */
        .post-card {
            background: var(--surface);
            border: 1.5px solid var(--border);
            border-radius: var(--radius-xl);
            margin-bottom: 16px;
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            animation: fadeUp 0.4s cubic-bezier(0.16,1,0.3,1) both;
            transition: border-color 0.2s;
        }
        .post-card:hover { border-color: var(--border-focus); }
        @keyframes fadeUp { from{opacity:0;transform:translateY(12px)} to{opacity:1;transform:translateY(0)} }

        .post-card-header { display: flex; align-items: center; gap: 12px; padding: 20px 24px 16px; }
        .post-author-avatar {
            width: 36px; height: 36px; border-radius: 50%;
            background: linear-gradient(135deg, var(--accent), #2563eb);
            display: flex; align-items: center; justify-content: center;
            font-size: 13px; font-weight: 800; color: #fff;
            flex-shrink: 0; overflow: hidden;
        }
        .post-author-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .post-author-info { flex: 1; }
        .post-author-name { font-size: 13.5px; font-weight: 700; color: var(--text-primary); }
        .post-meta { font-size: 12px; color: var(--text-muted); margin-top: 1px; }
        .post-type-badge {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 4px 10px; border-radius: 999px;
            font-size: 11px; font-weight: 700;
        }
        .post-type-badge svg { width: 11px; height: 11px; stroke: currentColor; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }

        .post-body { padding: 0 24px 20px; }
        .post-title { font-size: 17px; font-weight: 800; color: var(--text-primary); letter-spacing: -0.02em; margin-bottom: 6px; }
        .post-text  { font-size: 14px; color: var(--text-secondary); line-height: 1.65; white-space: pre-wrap; }
        .post-deadline {
            display: inline-flex; align-items: center; gap: 6px;
            margin-top: 12px; padding: 6px 12px;
            background: var(--danger-light); border-radius: var(--radius-sm);
            font-size: 12.5px; font-weight: 700; color: var(--danger);
        }
        .post-deadline svg { width: 13px; height: 13px; stroke: currentColor; fill: none; stroke-width: 2.5; stroke-linecap: round; stroke-linejoin: round; }
        .post-points {
            display: inline-flex; align-items: center; gap: 6px;
            margin-top: 8px; margin-left: 8px; padding: 6px 12px;
            background: var(--accent-light); border-radius: var(--radius-sm);
            font-size: 12.5px; font-weight: 700; color: var(--accent-text);
        }

        /* File attachment */
        .post-file {
            display: flex; align-items: center; gap: 12px;
            margin: 16px 24px; padding: 14px 18px;
            background: var(--surface-2);
            border: 1.5px solid var(--border);
            border-radius: var(--radius-md);
        }
        .post-file-icon { font-size: 22px; }
        .post-file-name { font-size: 13.5px; font-weight: 700; color: var(--text-primary); flex: 1; }
        .post-file-dl {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 7px 14px;
            background: var(--accent); color: #fff;
            border-radius: var(--radius-sm);
            font-size: 12px; font-weight: 700;
            text-decoration: none; transition: background 0.18s;
        }
        .post-file-dl:hover { background: var(--accent-hover); color: #fff; }
        .post-file-dl svg { width: 12px; height: 12px; stroke: #fff; fill: none; stroke-width: 2.5; stroke-linecap: round; stroke-linejoin: round; }

        /* Submission area */
        .submission-area {
            margin: 0 24px 16px;
            border: 1.5px solid var(--border);
            border-radius: var(--radius-md);
            overflow: hidden;
        }
        .submission-status-bar {
            display: flex; align-items: center; justify-content: space-between;
            padding: 12px 18px;
            background: var(--surface-2);
            flex-wrap: wrap; gap: 10px;
        }
        .sub-status-chip {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 5px 12px; border-radius: 999px;
            font-size: 12px; font-weight: 700;
        }
        .sub-status-chip.not-submitted { background: var(--danger-light); color: var(--danger); }
        .sub-status-chip.submitted { background: var(--success-light); color: var(--success); }
        .sub-status-chip.graded { background: var(--accent-light); color: var(--accent-text); }
        .sub-status-chip svg { width: 12px; height: 12px; stroke: currentColor; fill: none; stroke-width: 2.5; stroke-linecap: round; stroke-linejoin: round; }

        .btn-submit-toggle {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 8px 16px;
            background: var(--accent); color: #fff;
            border: none; border-radius: var(--radius-sm);
            font-family: 'Sora', sans-serif; font-size: 12.5px; font-weight: 700;
            cursor: pointer; transition: background 0.18s;
        }
        .btn-submit-toggle:hover { background: var(--accent-hover); }
        .btn-submit-toggle svg { width: 13px; height: 13px; stroke: #fff; fill: none; stroke-width: 2.5; stroke-linecap: round; stroke-linejoin: round; }

        .submit-form-panel {
            padding: 20px 18px;
            border-top: 1.5px solid var(--border);
            background: var(--surface);
            display: none;
        }
        .submit-form-panel.open { display: block; }
        .submit-form-panel textarea {
            width: 100%; padding: 10px 14px;
            font-family: 'Sora', sans-serif; font-size: 13px;
            color: var(--text-primary); background: var(--surface-2);
            border: 1.5px solid var(--border); border-radius: var(--radius-sm);
            outline: none; resize: vertical; min-height: 80px;
            transition: border-color 0.18s; box-sizing: border-box;
            margin-bottom: 12px;
        }
        .submit-form-panel textarea:focus { border-color: var(--border-focus); }
        .submit-form-footer { display: flex; justify-content: flex-end; gap: 10px; }
        .btn-cancel-sub {
            padding: 8px 16px;
            background: var(--surface-2); border: 1.5px solid var(--border);
            border-radius: var(--radius-sm);
            font-family: 'Sora', sans-serif; font-size: 12.5px; font-weight: 700;
            color: var(--text-secondary); cursor: pointer; transition: all 0.18s;
        }
        .btn-cancel-sub:hover { border-color: var(--accent); color: var(--accent-text); }
        .btn-submit-final {
            padding: 8px 18px;
            background: var(--success); color: #fff; border: none;
            border-radius: var(--radius-sm);
            font-family: 'Sora', sans-serif; font-size: 12.5px; font-weight: 700;
            cursor: pointer; transition: background 0.18s;
        }
        .btn-submit-final:hover { background: #047857; }

        .score-display {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 5px 14px; border-radius: 999px;
            background: var(--accent-light); color: var(--accent-text);
            font-size: 13px; font-weight: 800;
        }
        .score-note { font-size: 12.5px; color: var(--text-muted); font-style: italic; margin-top: 6px; }

        /* Comments */
        .comments-section { border-top: 1px solid var(--border); padding: 16px 24px; }
        .comments-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: var(--text-muted); margin-bottom: 14px; }
        .comment-row { display: flex; gap: 10px; margin-bottom: 12px; }
        .comment-avatar {
            width: 28px; height: 28px; border-radius: 50%;
            background: var(--surface-3);
            display: flex; align-items: center; justify-content: center;
            font-size: 10px; font-weight: 800; color: var(--text-muted);
            flex-shrink: 0; overflow: hidden;
        }
        .comment-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .comment-bubble { flex: 1; background: var(--surface-2); border-radius: 0 12px 12px 12px; padding: 10px 14px; }
        .comment-author { font-size: 12px; font-weight: 700; color: var(--text-primary); }
        .comment-faculty-tag {
            display: inline-block; font-size: 10px; font-weight: 700;
            background: var(--accent-light); color: var(--accent-text);
            border-radius: 999px; padding: 1px 7px; margin-left: 6px;
        }
        .comment-body { font-size: 13px; color: var(--text-secondary); margin-top: 3px; line-height: 1.5; }
        .comment-time { font-size: 11px; color: var(--text-muted); margin-top: 4px; }

        .comment-input-row { display: flex; gap: 10px; margin-top: 14px; }
        .comment-input {
            flex: 1; padding: 9px 14px;
            background: var(--surface-2); border: 1.5px solid var(--border);
            border-radius: var(--radius-md);
            font-family: 'Sora', sans-serif; font-size: 13px; color: var(--text-primary);
            outline: none; transition: border-color 0.18s;
        }
        .comment-input:focus { border-color: var(--border-focus); }
        .comment-submit {
            padding: 9px 18px; background: var(--accent); color: #fff;
            border: none; border-radius: var(--radius-md);
            font-family: 'Sora', sans-serif; font-size: 12.5px; font-weight: 700;
            cursor: pointer; transition: background 0.18s; white-space: nowrap;
        }
        .comment-submit:hover { background: var(--accent-hover); }

        /* ── Sidebar ── */
        .class-sidebar { display: flex; flex-direction: column; gap: 20px; }
        .sidebar-card {
            background: var(--surface); border: 1.5px solid var(--border);
            border-radius: var(--radius-xl); padding: 24px; box-shadow: var(--shadow-sm);
        }
        .sidebar-card-title {
            font-size: 13px; font-weight: 800; letter-spacing: -0.01em;
            color: var(--text-primary); margin-bottom: 16px;
            display: flex; align-items: center; gap: 8px;
        }
        .sidebar-card-title svg { width: 14px; height: 14px; stroke: var(--accent); fill: none; stroke-width: 2.5; stroke-linecap: round; stroke-linejoin: round; }

        .member-row { display: flex; align-items: center; gap: 10px; padding: 8px 0; border-bottom: 1px solid var(--border); }
        .member-row:last-child { border-bottom: none; }
        .member-avatar {
            width: 30px; height: 30px; border-radius: 50%;
            background: var(--surface-3);
            display: flex; align-items: center; justify-content: center;
            font-size: 11px; font-weight: 800; color: var(--text-muted);
            flex-shrink: 0; overflow: hidden;
        }
        .member-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .member-name { flex: 1; font-size: 13px; font-weight: 600; color: var(--text-primary); }
        .member-you { font-size: 10px; font-weight: 700; color: var(--accent-text); background: var(--accent-light); border-radius: 999px; padding: 2px 7px; }

        .stat-row-sm { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid var(--border); font-size: 13px; }
        .stat-row-sm:last-child { border-bottom: none; }
        .stat-row-sm .lbl { color: var(--text-muted); font-weight: 500; }
        .stat-row-sm .val { font-weight: 800; color: var(--text-primary); }

        /* ── Empty state ── */
        .empty-feed { text-align: center; padding: 60px 24px; color: var(--text-muted); }
        .empty-feed svg { width: 48px; height: 48px; stroke: var(--border); fill: none; stroke-width: 1.5; stroke-linecap: round; stroke-linejoin: round; margin: 0 auto 16px; display: block; }
        .empty-feed p { font-size: 15px; font-weight: 700; color: var(--text-secondary); margin-bottom: 6px; }
        .empty-feed small { font-size: 13px; }

        /* ── Grades tab ── */
        .grades-table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
        .grades-table th { padding: 10px 14px; text-align: left; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: var(--text-muted); border-bottom: 1.5px solid var(--border); background: var(--surface-2); }
        .grades-table td { padding: 14px; border-bottom: 1px solid var(--border); color: var(--text-primary); vertical-align: middle; }
        .grades-table tr:last-child td { border-bottom: none; }
        .grades-table tr:hover td { background: var(--surface-2); }

        /* ── Members tab ── */
        .members-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 14px; }
        .member-card {
            background: var(--surface); border: 1.5px solid var(--border);
            border-radius: var(--radius-lg); padding: 20px 16px; text-align: center;
            transition: border-color 0.18s, box-shadow 0.18s;
        }
        .member-card:hover { border-color: var(--accent); box-shadow: var(--shadow-sm); }
        .member-card-avatar {
            width: 52px; height: 52px; border-radius: 50%;
            background: linear-gradient(135deg, var(--surface-3), var(--border));
            display: flex; align-items: center; justify-content: center;
            font-size: 18px; font-weight: 800; color: var(--text-muted);
            margin: 0 auto 12px; overflow: hidden;
        }
        .member-card-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .member-card-name { font-size: 13.5px; font-weight: 700; color: var(--text-primary); }
        .member-card-username { font-size: 12px; color: var(--text-muted); margin-top: 2px; }

        /* ── Toast ── */
        .toast {
            position: fixed; bottom: 28px; left: 50%; transform: translateX(-50%) translateY(80px);
            background: var(--text-primary); color: var(--bg);
            padding: 12px 24px; border-radius: var(--radius-md);
            font-size: 13.5px; font-weight: 700;
            box-shadow: var(--shadow-lg); z-index: 9999;
            transition: transform 0.3s cubic-bezier(0.16,1,0.3,1); white-space: nowrap;
        }
        .toast.show { transform: translateX(-50%) translateY(0); }

        /* Leave modal */
        .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.55); backdrop-filter: blur(6px); z-index: 400; align-items: center; justify-content: center; padding: 24px; }
        .modal-overlay.open { display: flex; }
        .leave-modal {
            background: var(--surface); border: 1.5px solid var(--border);
            border-radius: var(--radius-2xl); padding: 36px;
            width: 100%; max-width: 420px;
            box-shadow: var(--shadow-xl);
            animation: cardIn 0.3s cubic-bezier(0.16,1,0.3,1) both;
        }
        @keyframes cardIn { from{opacity:0;transform:scale(0.95)} to{opacity:1;transform:scale(1)} }
        .leave-modal h3 { font-size: 22px; font-weight: 800; color: var(--text-primary); letter-spacing: -0.02em; margin-bottom: 10px; }
        .leave-modal p { font-size: 14px; color: var(--text-secondary); line-height: 1.65; margin-bottom: 28px; }
        .modal-footer { display: flex; justify-content: flex-end; gap: 10px; }
        .btn-cancel { padding: 11px 22px; background: var(--surface-2); border: 1.5px solid var(--border); border-radius: var(--radius-sm); font-family: 'Sora', sans-serif; font-size: 13.5px; font-weight: 700; color: var(--text-secondary); cursor: pointer; transition: all 0.18s; }
        .btn-cancel:hover { border-color: var(--accent); color: var(--accent-text); }
        .btn-leave { padding: 11px 22px; background: var(--danger); color: #fff; border: none; border-radius: var(--radius-sm); font-family: 'Sora', sans-serif; font-size: 13.5px; font-weight: 700; cursor: pointer; transition: background 0.18s; }
        .btn-leave:hover { background: #b91c1c; }
    </style>
</head>
<body>
<div class="class-wrapper">

    <!-- ══ HERO ══ -->
    <div class="class-hero">
        <div class="class-hero-inner">
            <div class="class-hero-top">
                <a href="dashboard.php" class="class-hero-back">
                    <svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
                    Dashboard
                </a>
                <div class="class-hero-actions">
                    <button class="hero-icon-btn" onclick="openLeaveModal()" title="Leave Class">
                        <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                    </button>
                    <button class="hero-icon-btn theme-toggle" aria-label="Toggle theme">
                        <svg class="icon-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
                        <svg class="icon-moon" viewBox="0 0 24 24"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
                    </button>
                    <a href="logout.php" class="class-hero-back" style="color:rgba(255,255,255,0.7);">Logout</a>
                </div>
            </div>

            <div class="class-hero-subject"><?= htmlspecialchars($cls['subject'] ?? '') ?></div>
            <div class="class-hero-name"><?= htmlspecialchars($cls['name']) ?></div>

            <div class="class-hero-meta">
                <span class="hero-meta-chip">
                    <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    <?= htmlspecialchars($facultyName) ?>
                </span>
                <span class="hero-meta-chip">
                    <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                    <?= count($members) ?> Member<?= count($members) !== 1 ? 's' : '' ?>
                </span>
                <span class="hero-meta-chip">
                    <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    <?= count($cls['posts'] ?? []) ?> Post<?= count($cls['posts'] ?? []) !== 1 ? 's' : '' ?>
                </span>
            </div>
        </div>

        <!-- Tabs -->
        <div class="class-hero-inner">
            <div class="class-tabs">
                <a href="?class_id=<?= $classId ?>&tab=feed" class="class-tab <?= $tab === 'feed' ? 'active' : '' ?>">
                    <svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                    Feed
                </a>
                <a href="?class_id=<?= $classId ?>&tab=members" class="class-tab <?= $tab === 'members' ? 'active' : '' ?>">
                    <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    Members
                </a>
                <a href="?class_id=<?= $classId ?>&tab=grades" class="class-tab <?= $tab === 'grades' ? 'active' : '' ?>">
                    <svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                    My Grades
                </a>
            </div>
        </div>
    </div>

    <!-- ══ FEED TAB ══ -->
    <?php if ($tab === 'feed'): ?>
    <div class="class-content">

        <!-- Feed column -->
        <div class="class-feed">

            <?php if (empty($posts)): ?>
            <div class="empty-feed">
                <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                <p>Nothing posted yet.</p>
                <small>Your teacher hasn't posted anything yet. Check back later.</small>
            </div>
            <?php else: ?>

            <?php foreach ($posts as $post):
                $ptc     = postTypeConfig($post['type']);
                $mySub   = $post['submissions'][$username] ?? null;
                $isAssign = $post['type'] === 'assignment';
            ?>
            <div class="post-card" id="post-<?= $post['id'] ?>">
                <div class="post-card-header">
                    <div class="post-author-avatar">
                        <?php if ($facultyAvatar && file_exists(__DIR__ . '/' . $facultyAvatar)): ?>
                            <img src="<?= htmlspecialchars($facultyAvatar) ?>" alt="">
                        <?php else: ?>
                            <?= $facultyInitials ?>
                        <?php endif; ?>
                    </div>
                    <div class="post-author-info">
                        <div class="post-author-name"><?= htmlspecialchars($facultyName) ?></div>
                        <div class="post-meta"><?= timeAgo($post['posted_at']) ?></div>
                    </div>
                    <span class="post-type-badge" style="background:<?= $ptc['bg'] ?>; color:<?= $ptc['color'] ?>;">
                        <svg viewBox="0 0 24 24"><?= $ptc['icon'] ?></svg>
                        <?= $ptc['label'] ?>
                    </span>
                </div>

                <div class="post-body">
                    <?php if (!empty($post['title'])): ?>
                    <div class="post-title"><?= htmlspecialchars($post['title']) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($post['body'])): ?>
                    <div class="post-text"><?= htmlspecialchars($post['body']) ?></div>
                    <?php endif; ?>
                    <?php if ($isAssign): ?>
                        <?php if (!empty($post['deadline'])): ?>
                        <span class="post-deadline">
                            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                            Due <?= htmlspecialchars($post['deadline']) ?>
                        </span>
                        <?php endif; ?>
                        <?php if (isset($post['points'])): ?>
                        <span class="post-points"><?= $post['points'] ?> pts</span>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if (!empty($post['link_url'])): ?>
                    <div style="margin-top:12px;">
                        <a href="<?= htmlspecialchars($post['link_url']) ?>" target="_blank"
                           style="display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:700;color:var(--accent);">
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                            Open Link
                        </a>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if (!empty($post['file_name'])): ?>
                <div class="post-file">
                    <span class="post-file-icon"><?= fileIcon(pathinfo($post['file_name'], PATHINFO_EXTENSION)) ?></span>
                    <span class="post-file-name"><?= htmlspecialchars($post['file_name']) ?></span>
                    <a href="<?= htmlspecialchars($post['file_path'] ?? '#') ?>" download class="post-file-dl">
                        <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                        Download
                    </a>
                </div>
                <?php endif; ?>

                <!-- Submission area (assignments only) -->
                <?php if ($isAssign): ?>
                <div class="submission-area">
                    <div class="submission-status-bar">
                        <div>
                            <?php if ($mySub && isset($mySub['score'])): ?>
                                <span class="sub-status-chip graded">
                                    <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                                    Graded
                                </span>
                                <span class="score-display" style="margin-left:10px;">
                                    <?= $mySub['score'] ?><?= isset($post['points']) ? ' / ' . $post['points'] : '' ?> pts
                                </span>
                            <?php elseif ($mySub): ?>
                                <span class="sub-status-chip submitted">
                                    <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                                    Submitted
                                </span>
                            <?php else: ?>
                                <span class="sub-status-chip not-submitted">
                                    <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                                    Not Submitted
                                </span>
                            <?php endif; ?>
                        </div>
                        <?php if (!$mySub): ?>
                        <button class="btn-submit-toggle" onclick="toggleSubmit('<?= $post['id'] ?>')">
                            <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                            Submit
                        </button>
                        <?php endif; ?>
                    </div>

                    <?php if ($mySub && !empty($mySub['note'])): ?>
                    <div style="padding:10px 18px; border-top:1px solid var(--border); background:var(--surface);">
                        <div class="score-note">Teacher note: <?= htmlspecialchars($mySub['note']) ?></div>
                    </div>
                    <?php endif; ?>

                    <?php if ($mySub && !empty($mySub['text'])): ?>
                    <div style="padding:10px 18px; border-top:1px solid var(--border); background:var(--surface);">
                        <div style="font-size:12px;font-weight:700;color:var(--text-muted);margin-bottom:6px;text-transform:uppercase;letter-spacing:0.06em;">Your Submission</div>
                        <div style="font-size:13.5px;color:var(--text-secondary);"><?= htmlspecialchars($mySub['text']) ?></div>
                    </div>
                    <?php endif; ?>

                    <!-- Submit form (hidden by default) -->
                    <?php if (!$mySub): ?>
                    <div class="submit-form-panel" id="submit-panel-<?= $post['id'] ?>">
                        <form method="POST" action="process_class.php">
                            <input type="hidden" name="action" value="submit_assignment">
                            <input type="hidden" name="class_id" value="<?= $classId ?>">
                            <input type="hidden" name="post_id" value="<?= $post['id'] ?>">
                            <textarea name="submission_text" placeholder="Write your answer or describe your submission…"></textarea>
                            <div class="submit-form-footer">
                                <button type="button" class="btn-cancel-sub" onclick="toggleSubmit('<?= $post['id'] ?>')">Cancel</button>
                                <button type="submit" class="btn-submit-final">Submit Assignment</button>
                            </div>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Comments -->
                <div class="comments-section">
                    <div class="comments-label">Comments</div>

                    <?php foreach ($post['comments'] ?? [] as $cmt): ?>
                    <?php
                        $isMe = ($cmt['author'] === $username);
                        $isFac = ($cmt['author'] === $facultyUsername);
                        $cmtUser = $usersLookup[$cmt['author']] ?? [];
                        $cmtName = $cmtUser['display_name'] ?? $cmtUser['fullname'] ?? $cmt['author'];
                        $cmtAvatar = $cmtUser['avatar'] ?? null;
                        $cmtInit = strtoupper(substr($cmtName, 0, 1));
                    ?>
                    <div class="comment-row">
                        <div class="comment-avatar">
                            <?php if ($cmtAvatar && file_exists(__DIR__ . '/' . $cmtAvatar)): ?>
                                <img src="<?= htmlspecialchars($cmtAvatar) ?>" alt="">
                            <?php else: ?>
                                <?= $cmtInit ?>
                            <?php endif; ?>
                        </div>
                        <div class="comment-bubble">
                            <div class="comment-author">
                                <?= htmlspecialchars($cmtName) ?>
                                <?php if ($isFac): ?><span class="comment-faculty-tag">Teacher</span><?php endif; ?>
                            </div>
                            <div class="comment-body"><?= htmlspecialchars($cmt['text']) ?></div>
                            <div class="comment-time"><?= timeAgo($cmt['posted_at']) ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <form method="POST" action="process_class.php" class="comment-input-row">
                        <input type="hidden" name="action" value="add_comment">
                        <input type="hidden" name="class_id" value="<?= $classId ?>">
                        <input type="hidden" name="post_id" value="<?= $post['id'] ?>">
                        <input type="text" name="comment_text" class="comment-input" placeholder="Add a comment…" required>
                        <button type="submit" class="comment-submit">Post</button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>

            <?php endif; ?>
        </div>

        <!-- Sidebar -->
        <div class="class-sidebar">
            <div class="sidebar-card">
                <div class="sidebar-card-title">
                    <svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>
                    Class Info
                </div>
                <div class="stat-row-sm"><span class="lbl">Subject</span><span class="val"><?= htmlspecialchars($cls['subject'] ?? '—') ?></span></div>
                <div class="stat-row-sm"><span class="lbl">Teacher</span><span class="val"><?= htmlspecialchars($facultyName) ?></span></div>
                <div class="stat-row-sm"><span class="lbl">Members</span><span class="val"><?= count($members) ?></span></div>
                <div class="stat-row-sm"><span class="lbl">Posts</span><span class="val"><?= count($cls['posts'] ?? []) ?></span></div>
                <?php
                $totalAssign  = count(array_filter($cls['posts'] ?? [], fn($p) => $p['type'] === 'assignment'));
                $mySubmitted  = 0;
                foreach ($cls['posts'] ?? [] as $p) {
                    if ($p['type'] === 'assignment' && isset($p['submissions'][$username])) $mySubmitted++;
                }
                ?>
                <div class="stat-row-sm"><span class="lbl">Assignments</span><span class="val"><?= $totalAssign ?></span></div>
                <div class="stat-row-sm"><span class="lbl">Submitted</span><span class="val"><?= $mySubmitted ?> / <?= $totalAssign ?></span></div>
            </div>

            <!-- Upcoming deadlines -->
            <?php
            $upcoming = array_filter($cls['posts'] ?? [], fn($p) => $p['type'] === 'assignment' && !empty($p['deadline']) && !isset($p['submissions'][$username]));
            usort($upcoming, fn($a, $b) => strtotime($a['deadline']) <=> strtotime($b['deadline']));
            $upcoming = array_slice(array_values($upcoming), 0, 3);
            ?>
            <?php if (!empty($upcoming)): ?>
            <div class="sidebar-card">
                <div class="sidebar-card-title">
                    <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    Upcoming
                </div>
                <?php foreach ($upcoming as $u): ?>
                <div class="member-row">
                    <div style="flex:1;">
                        <div style="font-size:13px;font-weight:700;color:var(--text-primary);"><?= htmlspecialchars($u['title'] ?? 'Untitled') ?></div>
                        <div style="font-size:12px;color:var(--danger);margin-top:2px;">Due <?= htmlspecialchars($u['deadline']) ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="sidebar-card">
                <div class="sidebar-card-title">
                    <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                    Classmates
                </div>
                <?php foreach ($members as $m):
                    $mu = $usersLookup[$m] ?? [];
                    $mn = $mu['fullname'] ?? $m;
                    $ma = $mu['avatar'] ?? null;
                    $mi = strtoupper(substr($mn, 0, 1));
                ?>
                <div class="member-row">
                    <div class="member-avatar">
                        <?php if ($ma && file_exists(__DIR__ . '/' . $ma)): ?><img src="<?= htmlspecialchars($ma) ?>" alt=""><?php else: ?><?= $mi ?><?php endif; ?>
                    </div>
                    <div class="member-name"><?= htmlspecialchars($mn) ?></div>
                    <?php if ($m === $username): ?><span class="member-you">You</span><?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- ══ MEMBERS TAB ══ -->
    <?php elseif ($tab === 'members'): ?>
    <div style="max-width:1100px;width:100%;margin:0 auto;padding:36px 40px;">
        <div style="margin-bottom:24px;">
            <div style="font-size:13px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.08em;margin-bottom:6px;">Teacher</div>
            <div class="member-card" style="display:inline-flex;align-items:center;gap:14px;text-align:left;padding:16px 22px;width:auto;min-width:220px;">
                <div class="member-card-avatar" style="margin:0;width:44px;height:44px;">
                    <?php if ($facultyAvatar && file_exists(__DIR__ . '/' . $facultyAvatar)): ?><img src="<?= htmlspecialchars($facultyAvatar) ?>" alt=""><?php else: ?><?= $facultyInitials ?><?php endif; ?>
                </div>
                <div>
                    <div class="member-card-name"><?= htmlspecialchars($facultyName) ?></div>
                    <div class="member-card-username">@<?= htmlspecialchars($facultyUsername) ?></div>
                </div>
            </div>
        </div>
        <div style="font-size:13px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.08em;margin-bottom:16px;">
            Students — <?= count($members) ?>
        </div>
        <div class="members-grid">
            <?php foreach ($members as $m):
                $mu = $usersLookup[$m] ?? [];
                $mn = $mu['fullname'] ?? $m;
                $ma = $mu['avatar'] ?? null;
                $mi = strtoupper(substr($mn, 0, 1));
            ?>
            <div class="member-card">
                <div class="member-card-avatar">
                    <?php if ($ma && file_exists(__DIR__ . '/' . $ma)): ?><img src="<?= htmlspecialchars($ma) ?>" alt=""><?php else: ?><?= $mi ?><?php endif; ?>
                </div>
                <div class="member-card-name"><?= htmlspecialchars($mn) ?></div>
                <div class="member-card-username">@<?= htmlspecialchars($m) ?><?= $m === $username ? ' (You)' : '' ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ══ GRADES TAB ══ -->
    <?php elseif ($tab === 'grades'): ?>
    <div style="max-width:1100px;width:100%;margin:0 auto;padding:36px 40px;">
        <?php if (empty($myGrades)): ?>
        <div class="empty-feed">
            <svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
            <p>No assignments yet.</p>
            <small>Your teacher hasn't posted any assignments yet.</small>
        </div>
        <?php else: ?>
        <div style="background:var(--surface);border:1.5px solid var(--border);border-radius:var(--radius-xl);overflow:hidden;box-shadow:var(--shadow-sm);">
            <table class="grades-table">
                <thead>
                    <tr>
                        <th>Assignment</th>
                        <th>Deadline</th>
                        <th>Status</th>
                        <th>Score</th>
                        <th>Note</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($myGrades as $g): ?>
                    <tr>
                        <td style="font-weight:700;"><?= htmlspecialchars($g['title']) ?></td>
                        <td style="color:var(--text-muted);"><?= $g['deadline'] ? htmlspecialchars($g['deadline']) : '—' ?></td>
                        <td>
                            <?php if ($g['score'] !== null): ?>
                                <span class="sub-status-chip graded" style="display:inline-flex;">✓ Graded</span>
                            <?php elseif ($g['submitted']): ?>
                                <span class="sub-status-chip submitted" style="display:inline-flex;">✓ Submitted</span>
                            <?php else: ?>
                                <span class="sub-status-chip not-submitted" style="display:inline-flex;">✗ Missing</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-weight:800;">
                            <?php if ($g['score'] !== null): ?>
                                <?= $g['score'] ?><?= $g['points'] !== null ? ' / ' . $g['points'] : '' ?>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td style="color:var(--text-muted);font-style:italic;"><?= $g['note'] ? htmlspecialchars($g['note']) : '—' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

</div><!-- end .class-wrapper -->

<!-- Leave class modal -->
<div class="modal-overlay" id="leaveModal">
    <div class="leave-modal">
        <h3>Leave this class?</h3>
        <p>You'll lose access to all posts, materials, and grades in <strong><?= htmlspecialchars($cls['name']) ?></strong>.</p>
        <div class="modal-footer">
            <button class="btn-cancel" onclick="closeLeaveModal()">Cancel</button>
            <form method="POST" action="process_class.php" style="margin:0;">
                <input type="hidden" name="action" value="leave_class">
                <input type="hidden" name="class_id" value="<?= $classId ?>">
                <button type="submit" class="btn-leave">Leave Class</button>
            </form>
        </div>
    </div>
</div>

<div class="toast" id="toast"></div>

<script src="theme.js"></script>
<script>
function toggleSubmit(postId) {
    var panel = document.getElementById('submit-panel-' + postId);
    if (panel) panel.classList.toggle('open');
}

function openLeaveModal() {
    document.getElementById('leaveModal').classList.add('open');
}
function closeLeaveModal() {
    document.getElementById('leaveModal').classList.remove('open');
}
document.getElementById('leaveModal').addEventListener('click', function(e) {
    if (e.target === this) closeLeaveModal();
});

function showToast(msg) {
    var t = document.getElementById('toast');
    t.textContent = msg;
    t.classList.add('show');
    setTimeout(function() { t.classList.remove('show'); }, 2500);
}

<?php if (isset($_GET['success'])): ?>
showToast('<?= htmlspecialchars($_GET['success']) ?>');
<?php endif; ?>
</script>
</body>
</html>