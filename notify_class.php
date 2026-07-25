<?php
/**
 * notify_class.php
 *
 * Class-based report card notification centre.
 * Admin / Head Teacher / Form Master selects a class then sends
 * all students' report summaries to their parents in one click.
 *
 * AJAX endpoint (POST action=send_class_reports) returns a stream of
 * newline-delimited JSON objects so the browser can show live progress.
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/controllers/AuthController.php';

$auth = new Auth();
$auth->requireLogin();
$auth->requireAdmin();

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/helpers/NotificationService.php';
require_once __DIR__ . '/helpers/GradingSystem.php';
require_once __DIR__ . '/controllers/ScoreController.php';
require_once __DIR__ . '/controllers/ClassController.php';

$db       = Database::getInstance()->getConnection();
$schoolId = (int)($_SESSION['school_id'] ?? 0);
$userType = $_SESSION['user_type'] ?? '';

// ── AJAX: send reports for a whole class ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_class_reports') {
    // Prevent PHP timeouts for large classes; keep streaming alive
    set_time_limit(0);
    ignore_user_abort(true);
    // Disable output buffering so we can stream progress
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/x-ndjson; charset=utf-8');
    header('X-Accel-Buffering: no');   // nginx
    header('Cache-Control: no-cache');

    $classId  = (int)($_POST['class_id']  ?? 0);
    $rawCh    = $_POST['channels'] ?? '[]';
    $channels = json_decode($rawCh, true);

    if (!$classId || !is_array($channels) || empty($channels)) {
        echo json_encode(['type' => 'error', 'msg' => 'Missing class_id or channels.']) . "\n";
        flush();
        exit;
    }

    // ── Verify class belongs to school ───────────────────────────────────────
    $stmtCls = $db->prepare("SELECT * FROM classes WHERE id = ? AND school_id = ?");
    $stmtCls->execute([$classId, $schoolId]);
    $classInfo = $stmtCls->fetch(PDO::FETCH_ASSOC);
    if (!$classInfo) {
        echo json_encode(['type' => 'error', 'msg' => 'Class not found.']) . "\n";
        flush();
        exit;
    }

    // ── Load school info ─────────────────────────────────────────────────────
    $stmtSch = $db->prepare("SELECT * FROM school_info WHERE id = ?");
    $stmtSch->execute([$schoolId]);
    $school = $stmtSch->fetch(PDO::FETCH_ASSOC);
    $schoolName = $school['school_name'] ?? 'School';

    // ── Academic year and term ─────────────────────────────────────────────────
    $termRow = $db->query("SELECT setting_value FROM system_settings WHERE setting_key='current_academic_year' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $academicYear = $termRow['setting_value'] ?? date('Y');
    $currentTerm = $school['current_term'] ?? 'First Term';

    // ── Notification service ─────────────────────────────────────────────────
    $settings      = NotificationService::loadSettings($db);
    $notifSvc      = new NotificationService($settings);

    // Use APP_URL from config as primary source for public report links
    $reportBaseUrl = rtrim(APP_URL, '/');
    // Fall back to saved setting if APP_URL is still localhost
    if (strpos($reportBaseUrl, 'localhost') !== false || strpos($reportBaseUrl, '127.0.0.1') !== false) {
        $savedUrl = rtrim($settings['report_base_url'] ?? '', '/');
        if ($savedUrl !== '' && strpos($savedUrl, 'localhost') === false) {
            $reportBaseUrl = $savedUrl;
        }
    }

    // ── Load all subjects for this class ─────────────────────────────────────
    $stmtSubj = $db->prepare("SELECT s.* FROM subjects s JOIN classes c ON s.class_id = c.id WHERE s.class_id = ? AND c.school_id = ? ORDER BY s.subject_name");
    $stmtSubj->execute([$classId, $schoolId]);
    $subjects = $stmtSubj->fetchAll(PDO::FETCH_ASSOC);

    // ── Load all students ────────────────────────────────────────────────────
    $stmtStu = $db->prepare("SELECT * FROM students WHERE class_id = ? ORDER BY student_name");
    $stmtStu->execute([$classId]);
    $students = $stmtStu->fetchAll(PDO::FETCH_ASSOC);

    $total = count($students);
    echo json_encode(['type' => 'start', 'total' => $total, 'class' => ClassController::getDisplayName($classInfo)]) . "\n";
    flush();

    if ($total === 0) {
        echo json_encode(['type' => 'done', 'sent' => 0, 'skipped' => 0]) . "\n";
        flush();
        exit;
    }

    // ── Pre-compute class totals for position ranking ─────────────────────────
    // Use REPEATABLE READ so all score reads in this batch see the same snapshot,
    // preventing position inconsistency if a teacher saves scores concurrently.
    $scoreCtrl   = new ScoreController();
    $classTotals = [];

    $db->exec("SET TRANSACTION ISOLATION LEVEL REPEATABLE READ");
    $db->beginTransaction();
    try {
        foreach ($students as $stu) {
            $t = 0;
            foreach ($subjects as $subj) {
                $sc = $scoreCtrl->getScore((int)$stu['id'], $subj['id']);
                if (!$sc) continue;
                $t += GradingSystem::calculateSubjectTotal($sc)['total'];
            }
            $classTotals[$stu['id']] = $t;
        }
    } finally {
        // Read-only snapshot — always roll back (no writes needed)
        $db->rollBack();
    }
    arsort($classTotals);

    // Build ranked positions (handle ties)
    $positions  = [];
    $rank       = 0;
    $prevScore  = null;
    $sameRankCt = 0;
    foreach ($classTotals as $sid => $tot) {
        if ($tot !== $prevScore) {
            $rank += 1 + $sameRankCt;
            $sameRankCt = 0;
        } else {
            $sameRankCt++;
        }
        $positions[$sid] = $rank;
        $prevScore = $tot;
    }
    $classSize = count($students);

    // ── Helper: format subject names for narrative SMS ─────────────────────────────
    function formatSubjectForSMS(string $subject): string {
        $abbrev = [
            'Career Technology' => 'Career Tech',
            'Creative Arts and Design' => 'Creative Arts & Design',
            'English Language' => 'English Language',
            'Ghanaian Language' => 'Ghanaian Language',
            'Religious and Moral Education (RME)' => 'R.M.E',
            'Religious and Moral Education' => 'R.M.E',
            'Social Studies' => 'Social Studies',
        ];
        return $abbrev[$subject] ?? $subject;
    }

    // ── Pre-load already-notified students (enables safe retry of incomplete batches) ──
    $alreadyNotified = [];
    try {
        $stmtNL = $db->prepare(
            "SELECT DISTINCT student_id FROM notification_log
              WHERE school_id = ? AND class_id = ? AND term = ? AND academic_year = ?"
        );
        $stmtNL->execute([$schoolId, $classId, $currentTerm, $academicYear]);
        $alreadyNotified = array_flip($stmtNL->fetchAll(PDO::FETCH_COLUMN));
    } catch (Exception $e) {
        // Table may not exist yet — run database/add_notification_log.sql to enable retry-safe batches
        error_log('notification_log pre-load failed: ' . $e->getMessage());
    }

    $sent    = 0;
    $skipped = 0;

    foreach ($students as $idx => $student) {
        $studentId   = (int)$student['id'];
        $studentName = $student['student_name'];
        $className   = ClassController::getDisplayName($classInfo);

        // Skip students who were already successfully notified in this batch (retry safety)
        if (isset($alreadyNotified[$studentId])) {
            $skipped++;
            echo json_encode([
                'type'   => 'progress',
                'index'  => $idx + 1,
                'name'   => $studentName,
                'status' => 'skipped',
                'reason' => 'Already notified this term',
            ]) . "\n";
            flush();
            continue;
        }

        // Determine parent recipient(s) via parent_contacts links (auto-synced from student record)
        $stmtPar = $db->prepare("
            SELECT pc.full_name AS name, pc.phone,
                   COALESCE(pc.whatsapp_number, pc.phone) AS whatsapp, pc.email
            FROM parent_contacts pc
            JOIN parent_student_links psl ON psl.parent_id = pc.id
            WHERE psl.student_id = ? AND pc.school_id = ?
        ");
        $stmtPar->execute([$studentId, $schoolId]);
        $recipients = $stmtPar->fetchAll(PDO::FETCH_ASSOC);

        // Fallback: inline parent fields on the student record (for legacy data not yet synced)
        if (empty($recipients) && (!empty($student['parent_phone']) || !empty($student['parent_email']))) {
            $recipients[] = [
                'name'      => $student['parent_name'] ?: 'Parent/Guardian',
                'phone'     => $student['parent_phone'] ?? '',
                'whatsapp'  => $student['parent_whatsapp'] ?? ($student['parent_phone'] ?? ''),
                'email'     => $student['parent_email'] ?? '',
            ];
        }

        if (empty($recipients)) {
            $skipped++;
            echo json_encode([
                'type'    => 'progress',
                'index'   => $idx + 1,
                'name'    => $studentName,
                'status'  => 'skipped',
                'reason'  => 'No parent contact saved',
            ]) . "\n";
            flush();
            continue;
        }

        // ── Build subject score rows ──────────────────────────────────────────
        $subjectRows = [];
        $totalScore  = 0;
        foreach ($subjects as $subj) {
            $sc = $scoreCtrl->getScore($studentId, $subj['id']);
            if (!$sc) continue;
            $calc = GradingSystem::calculateSubjectTotal($sc);
            $subjectRows[] = ['subject' => $subj['subject_name'], 'total' => $calc['total'], 'grade' => $calc['grade'], 'remarks' => $calc['remarks']];
            $totalScore += $calc['total'];
        }

        $pos         = $positions[$studentId] ?? 0;
        $posFormatted = GradingSystem::formatPosition((int)$pos);

        // Build signed public report URL (no login required for parents)
        $reportSecret = hash('sha256', $schoolId . 'SBA_PUB_REPORT');
        $reportToken  = substr(hash_hmac('sha256', "$studentId:$classId:$schoolId", $reportSecret), 0, 24);
        $reportLink   = $reportBaseUrl . '/public_report.php?sid=' . $studentId . '&cid=' . $classId . '&schid=' . $schoolId . '&t=' . $reportToken;

        // Build subject list for narrative format (done once per student)
        $subjectList = [];
        foreach ($subjectRows as $row) {
            $subjName = formatSubjectForSMS($row['subject']);
            $subjectList[] = $subjName . ' ' . $row['total'] . '(' . $row['grade'] . ')';
        }
        $subjectSummary = implode(', ', $subjectList);

        // Build email HTML (done once per student)
        $subjectTableRows = '';
        foreach ($subjectRows as $i => $row) {
            $bg = ($i % 2 === 0) ? '#f9f9f9' : '#ffffff';
            $subjectTableRows .= "<tr style='background:{$bg};'>"
                . "<td style='padding:6px 10px;border:1px solid #ddd;'>" . htmlspecialchars($row['subject']) . "</td>"
                . "<td style='padding:6px 10px;border:1px solid #ddd;text-align:center;'><strong>{$row['total']}/100</strong></td>"
                . "<td style='padding:6px 10px;border:1px solid #ddd;text-align:center;'><strong>" . htmlspecialchars($row['grade']) . "</strong></td>"
                . "<td style='padding:6px 10px;border:1px solid #ddd;'>" . htmlspecialchars($row['remarks']) . "</td>"
                . "</tr>";
        }
        $reportLinkHtml = $reportLink
            ? "<p style='text-align:center;margin-top:20px;'><a href='{$reportLink}' style='display:inline-block;background:#667eea;color:#fff;padding:10px 24px;border-radius:6px;text-decoration:none;font-weight:600;'>📄 View Full Report Card Online</a></p>"
            : '';
        $emailHtml = "<!DOCTYPE html><html><head><meta charset='UTF-8'></head><body style='font-family:Arial,sans-serif;background:#f5f5f5;margin:0;padding:0;'>
<div style='max-width:600px;margin:30px auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1);'>
  <div style='background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;padding:24px;text-align:center;'>
    <h1 style='margin:0;font-size:1.3rem;'>" . htmlspecialchars($schoolName) . "</h1>
    <p style='margin:4px 0 0;font-size:.9rem;opacity:.9;'>Student Report Card — {$academicYear}</p>
  </div>
  <div style='padding:20px 24px;'>
    <table style='width:100%;border-collapse:collapse;margin-bottom:16px;'>
      <tr><td style='padding:4px 0;color:#555;width:130px;'>Student</td><td style='font-weight:600;'>" . htmlspecialchars($studentName) . "</td></tr>
      <tr><td style='padding:4px 0;color:#555;'>Class</td><td><strong>" . htmlspecialchars($className) . "</strong></td></tr>
      <tr><td style='padding:4px 0;color:#555;'>Term</td><td><strong>{$currentTerm}</strong></td></tr>
      <tr><td style='padding:4px 0;color:#555;'>Position</td><td><strong>{$posFormatted}</strong> of {$classSize} students</td></tr>
      <tr><td style='padding:4px 0;color:#555;'>Total Score</td><td><strong>{$totalScore}</strong> / " . (count($subjectRows) * 100) . "</td></tr>
    </table>
    <table style='width:100%;border-collapse:collapse;font-size:.9rem;'>
      <thead><tr style='background:#667eea;color:#fff;'>
        <th style='padding:8px 10px;text-align:left;'>Subject</th>
        <th style='padding:8px 10px;text-align:center;'>Score</th>
        <th style='padding:8px 10px;text-align:center;'>Grade</th>
        <th style='padding:8px 10px;text-align:left;'>Remarks</th>
      </tr></thead>
      <tbody>{$subjectTableRows}</tbody>
    </table>
    {$reportLinkHtml}
  </div>
  <div style='background:#f7f7f7;padding:12px 24px;font-size:.8rem;color:#999;text-align:center;'>
    This report was generated by " . htmlspecialchars($schoolName) . " School Management System.
  </div>
</div></body></html>";

        // ── Dispatch ──────────────────────────────────────────────────────────
        $dispatchResults = [];
        $anyFail = false;

        foreach ($recipients as $recipient) {
            $rName = htmlspecialchars($recipient['name']);

            // Personalize SMS with parent name (plain text for SMS)
            $smsLines = [
                strtoupper($schoolName),
                '',
                'Dear ' . $rName . ', ' . $studentName . ' of ' . $className . ' has successfully completed the ' . $currentTerm . ' of the ' . $academicYear . ' academic year and placed ' . $posFormatted . ' out of ' . $classSize . ' students with an overall score of ' . $totalScore . '/' . (count($subjectRows) * 100) . '.',
                '',
                'Performance Summary:',
                $subjectSummary . '.',
                '',
                'View Full Report:',
                $reportLink,
                '',
                'Thank you.',
                strtoupper($schoolName)
            ];
            $smsText = implode("\n", $smsLines);

            if (in_array('sms', $channels) && !empty($recipient['phone'])) {
                $r = $notifSvc->sendSMS($recipient['phone'], $smsText);
                $dispatchResults[] = ['channel' => 'SMS', 'to' => $recipient['phone'], 'ok' => $r['success'], 'detail' => $r['error'] ?? 'Sent'];
                if (!$r['success']) $anyFail = true;
            }
        }

        $sent++;

        // Log the send so a retried batch skips this student
        if (!$anyFail) {
            try {
                foreach ($channels as $ch) {
                    $db->prepare(
                        "INSERT IGNORE INTO notification_log
                         (school_id, student_id, class_id, term, academic_year, channel)
                         VALUES (?, ?, ?, ?, ?, ?)"
                    )->execute([$schoolId, $studentId, $classId, $currentTerm, $academicYear, $ch]);
                }
            } catch (Exception $e) {
                error_log('notification_log insert failed: ' . $e->getMessage());
            }
        }

        echo json_encode([
            'type'     => 'progress',
            'index'    => $idx + 1,
            'name'     => $studentName,
            'status'   => $anyFail ? 'partial' : 'sent',
            'results'  => $dispatchResults,
            'position' => $posFormatted,
            'total'    => $totalScore,
        ]) . "\n";
        flush();
    }

    echo json_encode(['type' => 'done', 'sent' => $sent, 'skipped' => $skipped]) . "\n";
    flush();
    exit;
}

// ── HTML PAGE ─────────────────────────────────────────────────────────────────
// Load classes for this school
$stmtCls = $db->prepare("SELECT * FROM classes WHERE school_id = ? ORDER BY class_name");
$stmtCls->execute([$schoolId]);
$classes = $stmtCls->fetchAll(PDO::FETCH_ASSOC);

// Preload selected class students (if class_id in GET)
$selectedClassId  = (int)($_GET['class_id'] ?? 0);
$previewStudents  = [];
$selectedClassInfo = null;
if ($selectedClassId) {
    // Verify class belongs to this school before loading students
    $stmtCI = $db->prepare("SELECT * FROM classes WHERE id = ? AND school_id = ?");
    $stmtCI->execute([$selectedClassId, $schoolId]);
    $selectedClassInfo = $stmtCI->fetch(PDO::FETCH_ASSOC);

    if ($selectedClassInfo) {
        $stmtPre = $db->prepare("SELECT * FROM students WHERE class_id = ? ORDER BY student_name");
        $stmtPre->execute([$selectedClassId]);
        $previewStudents = $stmtPre->fetchAll(PDO::FETCH_ASSOC);
    }
}

$pageTitle = 'Send Class Reports';
require_once __DIR__ . '/components/header.php';
?>
<style>
:root{--green:#16a34a;--blue:#667eea;--red:#dc2626;}
.notify-wrap{max-width:960px;margin:2rem auto;padding:0 1rem;}
.card{background:#fff;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,.08);padding:1.5rem;margin-bottom:1.5rem;}
.card h2{margin:0 0 1rem;font-size:1.15rem;display:flex;align-items:center;gap:.5rem;}
.controls-row{display:flex;flex-wrap:wrap;gap:1rem;align-items:flex-end;}
.controls-row .fg{flex:1;min-width:220px;}
.controls-row label{display:block;font-size:.85rem;font-weight:600;margin-bottom:.3rem;color:#374151;}
.controls-row select,.controls-row input{width:100%;padding:.55rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.95rem;}
.channels-row{display:flex;gap:1rem;flex-wrap:wrap;margin-top:.5rem;}
.ch-chip{display:flex;align-items:center;gap:.4rem;background:#f3f4f6;border:2px solid #e5e7eb;border-radius:8px;padding:.45rem .9rem;cursor:pointer;user-select:none;font-size:.9rem;transition:all .15s;}
.ch-chip input{margin:0;}
.ch-chip.sms:has(input:checked){background:#dcfce7;border-color:#16a34a;color:#15803d;}
.ch-chip.whatsapp:has(input:checked){background:#d1fae5;border-color:#059669;color:#047857;}
.ch-chip.email:has(input:checked){background:#dbeafe;border-color:#3b82f6;color:#1d4ed8;}
.send-btn{
    margin-top: 0.5rem;
}

/* Students table */
.students-table{width:100%;border-collapse:collapse;font-size:.9rem;}
.students-table th{background:#f1f5f9;padding:.6rem .75rem;text-align:left;font-size:.8rem;font-weight:700;text-transform:uppercase;color:#64748b;border-bottom:2px solid #e2e8f0;}
.students-table td{padding:.6rem .75rem;border-bottom:1px solid #f1f5f9;vertical-align:middle;}
.students-table tr:last-child td{border-bottom:none;}
.badge{display:inline-block;padding:.2rem .55rem;border-radius:999px;font-size:.75rem;font-weight:600;}
.badge-ok{background:#dcfce7;color:#15803d;}
.badge-warn{background:#fef9c3;color:#854d0e;}
.badge-no{background:#fee2e2;color:#991b1b;}
.has-contact{color:#16a34a;}
.no-contact{color:#9ca3af;}

/* Progress panel */
#progressPanel{display:none;}
.prog-bar-wrap{background:#e5e7eb;border-radius:999px;height:12px;margin:.5rem 0 1rem;overflow:hidden;}
.prog-bar{height:100%;border-radius:999px;background:linear-gradient(90deg,#667eea,#764ba2);transition:width .3s;}
.log-list{max-height:380px;overflow-y:auto;border:1px solid #e5e7eb;border-radius:8px;padding:.5rem;}
.log-item{display:flex;align-items:center;gap:.6rem;padding:.45rem .6rem;border-radius:6px;margin-bottom:.25rem;font-size:.88rem;}
.log-item:last-child{margin-bottom:0;}
.log-sent{background:#f0fdf4;}
.log-partial{background:#fefce8;}
.log-skipped{background:#fef2f2;}
.log-item .li-name{flex:1;font-weight:600;}
.log-item .li-detail{font-size:.8rem;color:#6b7280;}
.wa-preview-link{color:#059669;text-decoration:none;font-weight:500;}
.wa-preview-link:hover{text-decoration:underline;color:#047857;}
.wa-log-btn{display:inline-block;margin-left:6px;padding:2px 8px;background:#dcfce7;color:#166534;border-radius:4px;font-size:.78rem;font-weight:600;text-decoration:none;border:1px solid #bbf7d0;}
.wa-log-btn:hover{background:#bbf7d0;color:#14532d;}
.summary-box{display:flex;gap:1rem;flex-wrap:wrap;margin-top:1rem;}
.sum-stat{flex:1;min-width:120px;background:#f8fafc;border-radius:8px;padding:.85rem;text-align:center;}
.sum-stat .sv{font-size:2rem;font-weight:800;line-height:1;}
.sum-stat .sl{font-size:.78rem;color:#64748b;margin-top:.25rem;}

@media (max-width: 768px) {
    .notify-wrap { margin: 1rem auto; padding: 0 0.5rem; }

    /* Page header */
    div[style*="display:flex"][style*="align-items:center"][style*="gap:1rem"] {
        flex-direction: column !important;
        align-items: flex-start !important;
    }

    /* Controls row: stack */
    .controls-row { flex-direction: column !important; }
    .controls-row .fg { min-width: 100% !important; width: 100%; }
    .controls-row select, .controls-row input { font-size: 16px !important; min-height: 48px; }

    /* Channel chips: wrap is already set, just ensure full width on tiny screens */
    .channels-row { gap: 0.5rem; }
    .ch-chip { flex: 1; justify-content: center; min-height: 44px; }

    /* Send button */
    .send-btn { width: 100% !important; padding: 0.85rem; font-size: 1rem; }

    /* Students table: scroll */
    .card { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .students-table { min-width: 460px; }
    .students-table th, .students-table td { padding: 0.4rem 0.5rem; font-size: 0.78rem; }

    /* Summary stats: 2 columns */
    .summary-box { display: grid !important; grid-template-columns: repeat(2, 1fr); gap: 0.75rem; }
    .sum-stat { flex: none; min-width: auto; }
    .sum-stat .sv { font-size: 1.5rem; }
}

@media (max-width: 480px) {
    .summary-box { grid-template-columns: 1fr !important; }
}
</style>

<div class="notify-wrap">

    <!-- Page header -->
    <div style="display:flex;align-items:center;gap:1rem;margin-bottom:1.5rem;">
        <div style="background:linear-gradient(135deg,#667eea,#764ba2);width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.5rem;">📨</div>
        <div>
            <h1 style="margin:0;font-size:1.4rem;font-weight:800;">Send Class Report Cards</h1>
            <p style="margin:0;color:#6b7280;font-size:.9rem;">Notify all parents in a class with one click via SMS</p>
        </div>
    </div>

    <!-- Step 1: Configure & Send -->
    <div class="card">
        <h2>⚙️ Step 1 — Choose Class &amp; Channels</h2>

        <div class="controls-row">
            <div class="fg">
                <label>Select Class</label>
                <select id="classSelect" onchange="loadPreview()">
                    <option value="">— pick a class —</option>
                    <?php foreach ($classes as $cls): ?>
                    <option value="<?= $cls['id'] ?>" <?= $cls['id'] == $selectedClassId ? 'selected' : '' ?>>
                        <?= htmlspecialchars(ClassController::getDisplayName($cls)) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div style="margin-top:1rem;">
            <label style="font-size:.85rem;font-weight:600;color:#374151;display:block;margin-bottom:.5rem;">Notification Channel</label>
            <div class="channels-row">
                <label class="ch-chip sms">
                    <input type="checkbox" id="chSms" value="sms" checked> 📱 SMS
                </label>
            </div>
        </div>

        <div style="margin-top:1.25rem;">
            <button class="btn btn-primary send-btn" id="sendBtn" onclick="startSending()" disabled style="padding: 0.75rem 2.5rem; font-size: 1.1rem; width: auto; display: flex; align-items: center; gap: 0.5rem; justify-content: center;">
                🚀 Send Reports to All Parents
            </button>
        </div>
    </div>

    <!-- Step 2: Preview -->
    <div class="card" id="previewCard">
        <h2>👥 Step 2 — Students in Class <span id="previewClassName" style="color:#667eea;"></span></h2>
        <div id="previewTableWrap">
            <p style="color:#9ca3af;font-size:.9rem;">Select a class above to preview students and their parent contacts.</p>
        </div>
    </div>

    <!-- Step 3: Live Progress -->
    <div class="card" id="progressPanel">
        <h2>📡 Step 3 — Sending Progress</h2>
        <p id="progressLabel" style="font-size:.9rem;color:#374151;margin-bottom:.25rem;">Starting…</p>
        <div class="prog-bar-wrap"><div class="prog-bar" id="progBar" style="width:0%"></div></div>
        <div class="log-list" id="logList"></div>
        <div class="summary-box" id="summaryBox" style="display:none;">
            <div class="sum-stat"><div class="sv" id="sumSent" style="color:#16a34a;">0</div><div class="sl">✅ Reports Sent</div></div>
            <div class="sum-stat"><div class="sv" id="sumSkipped" style="color:#dc2626;">0</div><div class="sl">⚠️ Skipped (no contact)</div></div>
            <div class="sum-stat"><div class="sv" id="sumTotal" style="color:#667eea;">0</div><div class="sl">Total Students</div></div>
        </div>
    </div>

</div>

<script>
let previewData = <?= json_encode($previewStudents) ?>;

// ── Load preview when class changes ──────────────────────────────────────────
function loadPreview() {
    const classId = document.getElementById('classSelect').value;
    if (!classId) {
        document.getElementById('sendBtn').disabled = true;
        document.getElementById('previewTableWrap').innerHTML =
            '<p style="color:#9ca3af;font-size:.9rem;">Select a class above to preview students and their parent contacts.</p>';
        document.getElementById('previewClassName').textContent = '';
        previewData = [];
        return;
    }
    window.location.href = 'notify_class.php?class_id=' + classId;
}

// If page loaded with class_id, show the table immediately
(function init() {
    const classId = document.getElementById('classSelect').value;
    if (classId && previewData.length >= 0) {
        renderPreview(previewData, classId);
    }
})();

function renderPreview(students, classId) {
    const wrap = document.getElementById('previewTableWrap');
    // Only update className from select if it isn't already set by PHP
    const clsLabel = document.getElementById('previewClassName');
    if (!clsLabel.textContent.trim()) {
        const clsOpt = document.getElementById('classSelect').selectedOptions[0];
        if (clsOpt) clsLabel.textContent = clsOpt.textContent.trim();
    }
    document.getElementById('sendBtn').disabled = students.length === 0;

    if (students.length === 0) {
        wrap.innerHTML = '<p style="color:#dc2626;font-size:.9rem;">⚠️ No students found in this class.</p>';
        return;
    }

    const withContact  = students.filter(s => s.parent_phone || s.parent_whatsapp || s.parent_email).length;
    const withoutContact = students.length - withContact;

    let html = `<div style="display:flex;gap:1rem;margin-bottom:1rem;flex-wrap:wrap;">
        <span class="badge badge-ok">✅ ${withContact} with contact</span>
        ${withoutContact > 0 ? `<span class="badge badge-no">⚠️ ${withoutContact} missing contact</span>` : ''}
        <span class="badge" style="background:#e0e7ff;color:#4338ca;">📊 ${students.length} total students</span>
    </div>
    <div style="overflow-x:auto;">
    <table class="students-table">
        <thead><tr>
            <th>#</th><th>Student Name</th>
            <th>Guardian</th><th>Relationship</th>
            <th>SMS Number</th>
            <th>Status</th>
        </tr></thead><tbody>`;

    students.forEach((s, i) => {
        const hasPhone = s.parent_phone;
        html += `<tr>
            <td style="color:#9ca3af;">${i+1}</td>
            <td><strong>${esc(s.student_name)}</strong></td>
            <td>${s.parent_name ? esc(s.parent_name) : '<span style="color:#d1d5db;">—</span>'}</td>
            <td>${s.parent_relationship ? `<span class="badge" style="background:#f3f4f6;color:#374151;">${esc(s.parent_relationship)}</span>` : '<span style="color:#d1d5db;">—</span>'}</td>
            <td>${s.parent_phone ? `<span class="has-contact">📱 ${esc(s.parent_phone)}</span>` : '<span class="no-contact">—</span>'}</td>
            <td>${hasPhone
                ? '<span class="badge badge-ok">Ready</span>'
                : '<span class="badge badge-no">No contact</span>'}</td>
        </tr>`;
    });
    html += '</tbody></table></div>';
    wrap.innerHTML = html;
}

function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// Build a wa.me link for a phone number with a generic "report ready" message
function waLink(phone, studentName) {
    let p = String(phone).replace(/\D/g, '');
    if (p.startsWith('0') && p.length === 10) p = '233' + p.slice(1);
    const msg = encodeURIComponent(`Hello, the report card for ${studentName} is ready. Please contact the school for more details.`);
    return `https://wa.me/${p}?text=${msg}`;
}

// ── Send all reports ──────────────────────────────────────────────────────────
async function startSending() {
    const classId = document.getElementById('classSelect').value;
    if (!classId) return alert('Please select a class first.');

    const channels = [];
    if (document.getElementById('chSms')?.checked) channels.push('sms');
    if (channels.length === 0) return alert('Please select at least one channel.');

    // Confirm
    const cls = document.getElementById('classSelect').selectedOptions[0]?.textContent?.trim() ?? '';
    const ch  = channels.map(c => c.toUpperCase()).join(', ');
    if (!confirm(`Send report cards for all students in "${cls}" via ${ch}?\n\nThis will send notifications to all parents with contact details saved.`)) return;

    // Reset UI
    document.getElementById('progressPanel').style.display = 'block';
    document.getElementById('summaryBox').style.display    = 'none';
    document.getElementById('logList').innerHTML           = '';
    document.getElementById('progBar').style.width         = '0%';
    document.getElementById('progressLabel').textContent   = 'Connecting…';
    document.getElementById('sendBtn').disabled            = true;
    document.getElementById('sendBtn').textContent         = '⏳ Sending…';

    const formData = new FormData();
    formData.append('action',   'send_class_reports');
    formData.append('class_id', classId);
    formData.append('channels', JSON.stringify(channels));

    const response = await fetch('notify_class.php', {method: 'POST', body: formData});
    const reader   = response.body.getReader();
    const decoder  = new TextDecoder();
    let buffer     = '';
    let total      = 0, sentCount = 0, skippedCount = 0;

    while (true) {
        const {value, done} = await reader.read();
        if (done) break;
        buffer += decoder.decode(value, {stream: true});
        const lines = buffer.split('\n');
        buffer = lines.pop(); // Keep incomplete line

        for (const line of lines) {
            if (!line.trim()) continue;
            let obj;
            try { obj = JSON.parse(line); } catch { continue; }

            if (obj.type === 'error') {
                addLog('❌ Error: ' + obj.msg, 'skipped');
                break;
            }
            if (obj.type === 'start') {
                total = obj.total;
                document.getElementById('progressLabel').textContent = `Sending to ${total} students in ${obj.class}…`;
                document.getElementById('sumTotal').textContent = total;
            }
            if (obj.type === 'progress') {
                const pct = Math.round((obj.index / total) * 100);
                document.getElementById('progBar').style.width = pct + '%';
                document.getElementById('progressLabel').textContent = `Processing ${obj.index} / ${total} — ${obj.name}`;

                if (obj.status === 'skipped') {
                    skippedCount++;
                    addLog(`⚠️ ${obj.name} — ${obj.reason}`, 'skipped');
                } else {
                    sentCount++;
                    const pos = obj.position ? ` · ${obj.position} / ${total}` : '';
                    let details = '';
                    let waButtons = '';
                    (obj.results || []).forEach(r => {
                        if (r.channel === 'WhatsApp' && r.wa_url) {
                            waButtons += ` <a href="${r.wa_url}" target="_blank" rel="noopener" class="wa-log-btn" title="Open WhatsApp for ${obj.name}">💬 Open WhatsApp</a>`;
                        } else {
                            const icon = r.ok ? '✓' : '✗';
                            const err  = !r.ok && r.detail ? ` [${r.detail}]` : '';
                            details += (details ? ' ' : '') + `${r.channel}(${icon}${err})`;
                        }
                    });
                    const statusIcon = obj.status === 'partial' ? '⚠️' : '✅';
                    addLog(`${statusIcon} ${obj.name}${pos}${details ? ' — ' + details : ''}${waButtons}`, obj.status === 'partial' ? 'partial' : 'sent');
                }
                document.getElementById('sumSent').textContent    = sentCount;
                document.getElementById('sumSkipped').textContent = skippedCount;
            }
            if (obj.type === 'done') {
                document.getElementById('progBar').style.width = '100%';
                document.getElementById('progressLabel').textContent = `✅ Done! ${obj.sent} sent, ${obj.skipped} skipped.`;
                document.getElementById('summaryBox').style.display = 'flex';
                document.getElementById('sendBtn').disabled = false;
                document.getElementById('sendBtn').innerHTML = '🔁 Send Again';
            }
        }
    }
}

function addLog(text, type) {
    const list = document.getElementById('logList');
    const item = document.createElement('div');
    item.className = 'log-item log-' + (type || 'sent');
    item.innerHTML = `<span class="li-name">${text}</span>`;
    list.appendChild(item);
    list.scrollTop = list.scrollHeight;
}

// Init if class pre-selected
<?php if ($selectedClassId && $selectedClassInfo): ?>
document.getElementById('previewClassName').textContent = <?= json_encode(ClassController::getDisplayName($selectedClassInfo)) ?>;
renderPreview(previewData, <?= $selectedClassId ?>);
<?php elseif ($selectedClassId): ?>
renderPreview(previewData, <?= $selectedClassId ?>);
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/components/footer.php'; ?>
