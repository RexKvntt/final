<?php
/**
 * calendar_widget.php — Helios University
 * Drop-in calendar widget for dashboard.php
 * Reads: $pdo, $_SESSION['role'], $_SESSION['username']
 */

$_calRole     = $_SESSION['role']     ?? '';
$_calUser     = $_SESSION['username'] ?? '';
$_calYear     = (int)date('Y');
$_calMonth    = (int)date('n');
$_calFirst    = strtotime(sprintf('%04d-%02d-01', $_calYear, $_calMonth));
$_calDays     = (int)date('t', $_calFirst);
$_calOffset   = (int)date('w', $_calFirst); // 0=Sun

// Fetch events for this month
$_calStart = sprintf('%04d-%02d-01', $_calYear, $_calMonth);
$_calEnd   = date('Y-m-t', strtotime($_calStart));

$_calStmt = $pdo->prepare(
    "SELECT e.id, e.title, e.description, e.event_date,
            e.start_time, e.end_time, e.created_by, e.class_id,
            c.name AS class_name
     FROM calendar_events e
     LEFT JOIN classes c ON c.id = e.class_id
     WHERE e.event_date BETWEEN ? AND ?
     ORDER BY e.event_date ASC"
);
$_calStmt->execute([$_calStart, $_calEnd]);
$_calEvents = $_calStmt->fetchAll(PDO::FETCH_ASSOC);

// Build a map: date => [events]
$_calMap = [];
foreach ($_calEvents as $_ev) {
    $_calMap[$_ev['event_date']][] = $_ev;
}

// Faculty: load their assigned subjects for the add-event form
$_calClasses = [];
if ($_calRole === 'faculty') {
    $_cs = $pdo->prepare(
        "SELECT s.id, s.name, s.class_id, c.name AS class_name
         FROM subjects s
         LEFT JOIN classes c ON c.id = s.class_id
         WHERE s.faculty = ?
         ORDER BY c.name, s.name"
    );
    $_cs->execute([$_calUser]);
    $_calClasses = $_cs->fetchAll(PDO::FETCH_ASSOC);
}

$_calMonthLabel = date('F Y', $_calFirst);
$_calToday      = date('Y-m-d');
$_widgetId      = 'cal_' . substr(md5($_calRole . $_calUser), 0, 6); // unique per include
?>

<?php if ($_calRole === 'faculty'): ?>
<!-- ══════════════════════════════════════
     FACULTY CALENDAR WIDGET
     ══════════════════════════════════════ -->
<div class="overview-panel calendar-card" id="<?= $_widgetId ?>">
    <div class="panel-head">
        <div>
            <div class="panel-title">Event Calendar</div>
            <div class="calendar-month" id="<?= $_widgetId ?>_label"><?= htmlspecialchars($_calMonthLabel) ?></div>
        </div>
        <div style="display:flex;gap:6px;align-items:center;">
            <button onclick="heliosCal('<?= $_widgetId ?>',-1)" title="Previous month"
                style="width:28px;height:28px;border-radius:50%;border:1px solid var(--gc-border-light);background:var(--gc-bg-surface);color:var(--gc-text-secondary);cursor:pointer;font-size:14px;display:grid;place-items:center;">&#8249;</button>
            <button onclick="heliosCal('<?= $_widgetId ?>',1)" title="Next month"
                style="width:28px;height:28px;border-radius:50%;border:1px solid var(--gc-border-light);background:var(--gc-bg-surface);color:var(--gc-text-secondary);cursor:pointer;font-size:14px;display:grid;place-items:center;">&#8250;</button>
        </div>
    </div>

    <!-- Calendar grid -->
    <div class="calendar-grid" id="<?= $_widgetId ?>_grid">
        <?php foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $_dn): ?>
        <div class="calendar-weekday"><?= $_dn ?></div>
        <?php endforeach; ?>
        <?php for ($b = 0; $b < $_calOffset; $b++): ?>
        <div></div>
        <?php endfor; ?>
        <?php for ($d = 1; $d <= $_calDays; $d++):
            $dk = sprintf('%04d-%02d-%02d', $_calYear, $_calMonth, $d);
            $dc = trim(($dk === $_calToday ? 'today ' : '') . (!empty($_calMap[$dk]) ? 'has-due' : ''));
            $tt = !empty($_calMap[$dk]) ? count($_calMap[$dk]).' event(s)' : '';
        ?>
        <div class="calendar-day <?= $dc ?>"
             title="<?= htmlspecialchars($tt) ?>"
             style="cursor:pointer;"
             onclick="heliosCalShowDay('<?= $_widgetId ?>','<?= $dk ?>',<?= json_encode($_calMap[$dk] ?? []) ?>)"><?= $d ?></div>
        <?php endfor; ?>
    </div>

    <!-- Day event list -->
    <div id="<?= $_widgetId ?>_dayview" style="margin-top:14px;display:none;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
            <span id="<?= $_widgetId ?>_daytitle" style="font-size:13px;font-weight:700;color:var(--helios-ink);"></span>
            <button onclick="document.getElementById('<?= $_widgetId ?>_dayview').style.display='none'"
                style="font-size:11px;color:var(--helios-muted);background:none;border:none;cursor:pointer;">✕ close</button>
        </div>
        <div id="<?= $_widgetId ?>_daylist"></div>
    </div>

    <!-- Event list for month -->
    <div class="due-list" id="<?= $_widgetId ?>_list">
        <?php if (empty($_calEvents)): ?>
        <div class="empty-panel">No events scheduled this month.</div>
        <?php else: ?>
            <?php foreach (array_slice($_calEvents, 0, 5) as $_ev): ?>
            <div class="due-item" id="evrow_<?= (int)$_ev['id'] ?>">
                <div class="due-date">
                    <?= date('j', strtotime($_ev['event_date'])) ?>
                    <span><?= date('M', strtotime($_ev['event_date'])) ?></span>
                </div>
                <div style="min-width:0;">
                    <div class="due-title"><?= htmlspecialchars($_ev['title']) ?></div>
                    <div class="due-meta">
                        <?= $_ev['class_name'] ? htmlspecialchars($_ev['class_name']) : 'All classes' ?>
                        <?= $_ev['start_time'] ? ' · '.substr($_ev['start_time'],0,5).($_ev['end_time'] ? '–'.substr($_ev['end_time'],0,5) : '') : '' ?>
                        <?= $_ev['description'] ? ' — '.htmlspecialchars(mb_strimwidth($_ev['description'],0,60,'…')) : '' ?>
                    </div>
                </div>
                <?php if ($_ev['created_by'] === $_calUser): ?>
                <button onclick="heliosCalDelete('<?= $_widgetId ?>',<?= (int)$_ev['id'] ?>)"
                    title="Delete event"
                    style="align-self:center;width:24px;height:24px;border-radius:50%;border:none;background:var(--gc-accent-red-dim);color:var(--gc-accent-red);cursor:pointer;font-size:14px;display:grid;place-items:center;flex-shrink:0;">×</button>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Add event toggle -->
    <button onclick="heliosCalOpenForm('<?= $_widgetId ?>')"
        style="margin-top:14px;width:100%;padding:9px;border-radius:10px;border:1px dashed var(--gc-border-medium);background:none;color:var(--helios-brand);font-family:'Poppins',sans-serif;font-size:13px;font-weight:600;cursor:pointer;">
        + Schedule Event
    </button>
</div>

<!-- Add event MODAL (floating, outside the card so it doesn't push layout) -->
<div id="<?= $_widgetId ?>_overlay"
    onclick="if(event.target===this)heliosCalCloseForm('<?= $_widgetId ?>')"
    style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;align-items:center;justify-content:center;">
    <div style="background:var(--gc-bg-surface);border-radius:14px;padding:24px;width:100%;max-width:400px;margin:16px;box-shadow:0 24px 60px rgba(0,0,0,.3);display:flex;flex-direction:column;gap:12px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
            <span style="font-size:15px;font-weight:700;color:var(--helios-ink);">Schedule Event</span>
            <button onclick="heliosCalCloseForm('<?= $_widgetId ?>')"
                style="width:28px;height:28px;border-radius:50%;border:none;background:var(--gc-bg-hover);color:var(--gc-text-secondary);cursor:pointer;font-size:16px;display:grid;place-items:center;">×</button>
        </div>
        <input id="<?= $_widgetId ?>_ftitle" type="text" placeholder="Event title *"
            style="padding:10px 12px;border:1px solid var(--gc-border-light);border-radius:8px;font-family:'Poppins',sans-serif;font-size:13px;background:var(--gc-bg-surface);color:var(--gc-text-primary);outline:none;width:100%;">
        <input id="<?= $_widgetId ?>_fdate" type="date" value="<?= $_calToday ?>"
            style="padding:10px 12px;border:1px solid var(--gc-border-light);border-radius:8px;font-family:'Poppins',sans-serif;font-size:13px;background:var(--gc-bg-surface);color:var(--gc-text-primary);outline:none;width:100%;">
        <div style="display:flex;gap:8px;">
            <input id="<?= $_widgetId ?>_fstart" type="time"
                style="flex:1;padding:10px 12px;border:1px solid var(--gc-border-light);border-radius:8px;font-family:'Poppins',sans-serif;font-size:13px;background:var(--gc-bg-surface);color:var(--gc-text-primary);outline:none;">
            <input id="<?= $_widgetId ?>_fend" type="time"
                style="flex:1;padding:10px 12px;border:1px solid var(--gc-border-light);border-radius:8px;font-family:'Poppins',sans-serif;font-size:13px;background:var(--gc-bg-surface);color:var(--gc-text-primary);outline:none;">
        </div>
        <textarea id="<?= $_widgetId ?>_fdesc" placeholder="Description (optional)" rows="2"
            style="padding:10px 12px;border:1px solid var(--gc-border-light);border-radius:8px;font-family:'Poppins',sans-serif;font-size:13px;background:var(--gc-bg-surface);color:var(--gc-text-primary);outline:none;resize:vertical;width:100%;"></textarea>
        <select id="<?= $_widgetId ?>_fclass"
            style="padding:10px 12px;border:1px solid var(--gc-border-light);border-radius:8px;font-family:'Poppins',sans-serif;font-size:13px;background:var(--gc-bg-surface);color:var(--gc-text-primary);outline:none;width:100%;">
            <option value="">— Select subject —</option>
            <?php foreach ($_calClasses as $_cls): ?>
            <option value="<?= htmlspecialchars($_cls['id']) ?>"><?= htmlspecialchars($_cls['name']) ?> (<?= htmlspecialchars($_cls['class_name']) ?>)</option>
            <?php endforeach; ?>
        </select>
        <div style="display:flex;gap:8px;">
            <button onclick="heliosCalAdd('<?= $_widgetId ?>')"
                style="flex:1;padding:10px;border-radius:8px;border:none;background:var(--gc-accent-blue);color:#fff;font-family:'Poppins',sans-serif;font-size:13px;font-weight:600;cursor:pointer;">
                Save Event
            </button>
            <button onclick="heliosCalCloseForm('<?= $_widgetId ?>')"
                style="padding:10px 16px;border-radius:8px;border:1px solid var(--gc-border-light);background:none;color:var(--gc-text-secondary);font-family:'Poppins',sans-serif;font-size:13px;cursor:pointer;">
                Cancel
            </button>
        </div>
        <div id="<?= $_widgetId ?>_msg" style="font-size:12px;color:var(--gc-accent-red);display:none;"></div>
    </div>
</div>

<?php elseif ($_calRole === 'student'): ?>
<!-- ══════════════════════════════════════
     STUDENT CALENDAR WIDGET
     ══════════════════════════════════════ -->
<div class="student-mini-calendar" id="<?= $_widgetId ?>">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
        <h3 style="margin:0;" id="<?= $_widgetId ?>_label"><?= htmlspecialchars($_calMonthLabel) ?></h3>
        <div style="display:flex;gap:4px;">
            <button onclick="heliosCal('<?= $_widgetId ?>',-1)"
                style="width:24px;height:24px;border-radius:50%;border:1px solid var(--gc-border-light);background:var(--gc-bg-surface);color:var(--gc-text-secondary);cursor:pointer;font-size:13px;display:grid;place-items:center;">&#8249;</button>
            <button onclick="heliosCal('<?= $_widgetId ?>',1)"
                style="width:24px;height:24px;border-radius:50%;border:1px solid var(--gc-border-light);background:var(--gc-bg-surface);color:var(--gc-text-secondary);cursor:pointer;font-size:13px;display:grid;place-items:center;">&#8250;</button>
        </div>
    </div>
    <div class="student-calendar-grid" id="<?= $_widgetId ?>_grid">
        <?php foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $_dn): ?>
            <span class="weekday"><?= $_dn ?></span>
        <?php endforeach; ?>
        <?php for ($b = 0; $b < $_calOffset; $b++): ?>
            <span></span>
        <?php endfor; ?>
        <?php for ($d = 1; $d <= $_calDays; $d++):
            $dk = sprintf('%04d-%02d-%02d', $_calYear, $_calMonth, $d);
            $sc = ($dk === $_calToday) ? 'today' : (!empty($_calMap[$dk]) ? 'marked' : '');
            $tt = !empty($_calMap[$dk]) ? count($_calMap[$dk]).' event(s)' : '';
        ?>
            <span class="<?= $sc ?>"
                  title="<?= htmlspecialchars($tt) ?>"
                  style="<?= !empty($_calMap[$dk]) ? 'cursor:pointer;' : '' ?>"
                  onclick="heliosCalDay('<?= $_widgetId ?>','<?= $dk ?>')"><?= $d ?></span>
        <?php endfor; ?>
    </div>

    <!-- Day event popup for student -->
    <div id="<?= $_widgetId ?>_dayview" style="margin-top:10px;display:none;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
            <span id="<?= $_widgetId ?>_daytitle" style="font-size:12px;font-weight:700;color:var(--helios-ink);"></span>
            <button onclick="document.getElementById('<?= $_widgetId ?>_dayview').style.display='none'"
                style="font-size:11px;color:var(--helios-muted);background:none;border:none;cursor:pointer;">✕</button>
        </div>
        <div id="<?= $_widgetId ?>_daylist"></div>
    </div>
</div>

<!-- Student reminders section showing upcoming events -->
<div class="student-reminders">
    <h3>Upcoming Events</h3>
    <div class="student-reminder-list">
        <?php if (empty($_calEvents)): ?>
        <div class="empty-panel">No events this month.</div>
        <?php else: ?>
            <?php foreach (array_slice($_calEvents, 0, 4) as $_ev): ?>
            <div class="student-reminder-item">
                <span class="student-reminder-icon">
                    <svg viewBox="0 0 24 24"><path d="M19 4h-1V2h-2v2H8V2H6v2H5c-1.11 0-1.99.9-1.99 2L3 20c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H5V10h14v10zm0-12H5V6h14v2z"/></svg>
                </span>
                <span>
                    <span class="student-reminder-title"><?= htmlspecialchars($_ev['title']) ?></span>
                    <span class="student-reminder-date"><?= date('d M Y, l', strtotime($_ev['event_date'])) ?><?= $_ev['class_name'] ? ' · '.htmlspecialchars($_ev['class_name']) : '' ?></span>
                </span>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php endif; ?>

<!-- ══════════════════════════════════════
     SHARED JS (loaded once via flag)
     ══════════════════════════════════════ -->
<?php if (empty($GLOBALS['_heliosCalJsLoaded'])): $GLOBALS['_heliosCalJsLoaded'] = true; ?>
<script>
// Navigate month: dir = -1 or +1
function heliosCal(wid, dir) {
    const label = document.getElementById(wid + '_label');
    const grid  = document.getElementById(wid + '_grid');
    const list  = document.getElementById(wid + '_list');
    if (!label || !grid) return;

    // Parse current month from label
    const d = new Date(label.textContent + ' 1');
    d.setMonth(d.getMonth() + dir);
    const year  = d.getFullYear();
    const month = d.getMonth() + 1; // 1-based

    label.textContent = d.toLocaleString('default', { month: 'long', year: 'numeric' });

    // Fetch events for new month
    fetch(`api_calendar.php?year=${year}&month=${month}`)
        .then(r => r.json())
        .then(data => {
            if (!data.ok) return;
            const events = data.events || [];

            // Build map date => events
            const map = {};
            events.forEach(ev => {
                if (!map[ev.event_date]) map[ev.event_date] = [];
                map[ev.event_date].push(ev);
            });

            // Rebuild grid
            const today = new Date().toISOString().slice(0,10);
            const firstDow = new Date(year, month-1, 1).getDay(); // 0=Sun
            const daysInMonth = new Date(year, month, 0).getDate();

            // Detect widget type
            const isStudent = grid.classList.contains('student-calendar-grid');
            const dayClass  = isStudent ? '' : 'calendar-day';
            const tag       = isStudent ? 'span' : 'div';

            // Keep weekday headers (first 7 children)
            const headers = Array.from(grid.children).slice(0, 7);
            grid.innerHTML = '';
            headers.forEach(h => grid.appendChild(h));

            // Blank offset cells
            for (let b = 0; b < firstDow; b++) {
                const el = document.createElement(tag);
                grid.appendChild(el);
            }

            // Day cells
            for (let d2 = 1; d2 <= daysInMonth; d2++) {
                const dk = `${String(year).padStart(4,'0')}-${String(month).padStart(2,'0')}-${String(d2).padStart(2,'0')}`;
                const el = document.createElement(tag);
                el.textContent = d2;
                if (isStudent) {
                    el.className = dk === today ? 'today' : (map[dk] ? 'marked' : '');
                } else {
                    let cls = dayClass;
                    if (dk === today) cls += ' today';
                    if (map[dk])     cls += ' has-due';
                    el.className = cls.trim();
                }
                // ALL days are clickable
                el.style.cursor = 'pointer';
                el.title = map[dk] ? map[dk].length + ' event(s)' : '';
                const _dk = dk; // capture for closure
                const _map = map;
                el.onclick = () => heliosCalShowDay(wid, _dk, _map[_dk] || []);
                grid.appendChild(el);
            }

            // Update month event list (faculty only)
            if (list) {
                if (events.length === 0) {
                    list.innerHTML = '<div class="empty-panel">No events scheduled this month.</div>';
                } else {
                    list.innerHTML = events.slice(0,5).map(ev => `
                        <div class="due-item" id="evrow_${ev.id}">
                            <div class="due-date">${new Date(ev.event_date + 'T00:00').getDate()}<span>${new Date(ev.event_date + 'T00:00').toLocaleString('default',{month:'short'})}</span></div>
                            <div style="min-width:0;">
                                <div class="due-title">${escHtml(ev.title)}</div>
                                <div class="due-meta">${escHtml(ev.class_name||'All classes')}${ev.description?' — '+escHtml(ev.description.substring(0,60)):''}</div>
                            </div>
                        </div>`).join('');
                }
            }

            // Update student reminder list
            const reminderList = document.querySelector('#' + wid + ' ~ .student-reminders .student-reminder-list');
            if (reminderList) {
                if (events.length === 0) {
                    reminderList.innerHTML = '<div class="empty-panel">No events this month.</div>';
                } else {
                    reminderList.innerHTML = events.slice(0,4).map(ev => `
                        <div class="student-reminder-item">
                            <span class="student-reminder-icon"><svg viewBox="0 0 24 24"><path d="M19 4h-1V2h-2v2H8V2H6v2H5c-1.11 0-1.99.9-1.99 2L3 20c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H5V10h14v10zm0-12H5V6h14v2z"/></svg></span>
                            <span>
                                <span class="student-reminder-title">${escHtml(ev.title)}</span>
                                <span class="student-reminder-date">${new Date(ev.event_date+'T00:00').toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric',weekday:'long'})}${ev.class_name?' · '+escHtml(ev.class_name):''}</span>
                            </span>
                        </div>`).join('');
                }
            }
        });
}

// Show day events in the day-view panel
function heliosCalDay(wid, date) {
    fetch(`api_calendar.php?year=${date.slice(0,4)}&month=${parseInt(date.slice(5,7))}`)
        .then(r => r.json())
        .then(data => {
            const events = (data.events || []).filter(ev => ev.event_date === date);
            heliosCalShowDay(wid, date, events);
        });
}

function heliosCalShowDay(wid, date, events) {
    const panel = document.getElementById(wid + '_dayview');
    const title = document.getElementById(wid + '_daytitle');
    const list  = document.getElementById(wid + '_daylist');
    if (!panel) return;

    const d = new Date(date + 'T00:00');
    title.textContent = d.toLocaleDateString('en-US', { weekday:'long', month:'long', day:'numeric' });

    if (events.length === 0) {
        list.innerHTML = '<div style="font-size:12px;color:var(--helios-muted);padding:6px 0;">No events on this day.</div>';
    } else {
        list.innerHTML = events.map(ev => `
            <div style="padding:8px 10px;border-radius:8px;background:var(--gc-bg-hover);margin-bottom:6px;">
                <div style="font-size:13px;font-weight:700;color:var(--helios-ink);">${escHtml(ev.title)}</div>
                ${ev.class_name ? `<div style="font-size:11px;color:var(--helios-brand);margin-top:2px;">${escHtml(ev.class_name)}</div>` : ''}
                ${ev.description ? `<div style="font-size:12px;color:var(--helios-muted);margin-top:4px;">${escHtml(ev.description)}</div>` : ''}
            </div>`).join('');
    }
    panel.style.display = 'block';

    // Auto-fill date in modal form if faculty
    const fdate = document.getElementById(wid + '_fdate');
    if (fdate) fdate.value = date;
}

function heliosCalOpenForm(wid) {
    const overlay = document.getElementById(wid + '_overlay');
    if (overlay) { overlay.style.display = 'flex'; }
}

function heliosCalCloseForm(wid) {
    const overlay = document.getElementById(wid + '_overlay');
    if (overlay) { overlay.style.display = 'none'; }
}

// Add event (faculty)
function heliosCalAdd(wid) {
    const title = document.getElementById(wid + '_ftitle').value.trim();
    const date  = document.getElementById(wid + '_fdate').value;
    const desc  = document.getElementById(wid + '_fdesc').value.trim();
    const cls   = document.getElementById(wid + '_fclass').value;
    const start = document.getElementById(wid + '_fstart').value;
    const end   = document.getElementById(wid + '_fend').value;
    const msg   = document.getElementById(wid + '_msg');

    if (!title || !date) {
        msg.textContent = 'Title and date are required.';
        msg.style.display = 'block';
        return;
    }
    msg.style.display = 'none';

    fetch('api_calendar.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ title, description: desc, event_date: date, class_id: cls || null, start_time: start || null, end_time: end || null })
    })
    .then(r => r.json())
    .then(data => {
        if (!data.ok) {
            msg.textContent = data.error || 'Error saving event.';
            msg.style.display = 'block';
            return;
        }
        // Reset form & close modal & reload month
        document.getElementById(wid + '_ftitle').value = '';
        document.getElementById(wid + '_fdesc').value  = '';
        heliosCalCloseForm(wid);
        heliosCal(wid, 0);
    })
    .catch(() => {
        msg.textContent = 'Network error. Please try again.';
        msg.style.display = 'block';
    });
}

// Delete event (faculty)
function heliosCalDelete(wid, id) {
    if (!confirm('Delete this event?')) return;
    fetch('api_calendar.php', {
        method: 'DELETE',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id })
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            const row = document.getElementById('evrow_' + id);
            if (row) row.remove();
        } else {
            alert(data.error || 'Could not delete event.');
        }
    });
}

function escHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>
<?php endif; ?>