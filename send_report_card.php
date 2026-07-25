<?php
/**
 * send_report_card.php
 *
 * AJAX endpoint: sends a student's report card summary to their linked parents
 * via the channels (SMS, WhatsApp, Email) chosen by the admin.
 *
 * POST params:
 *   student_id  – int
 *   channels    – JSON array, e.g. ["sms","whatsapp","email"]
 *
 * Returns JSON: { success, results: { sms: [], whatsapp: [], email: [] }, errors: [] }
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/controllers/AuthController.php';

$auth = new Auth();
$auth->requireLogin();

header('Content-Type: application/json');

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/helpers/NotificationService.php';
require_once __DIR__ . '/helpers/GradingSystem.php';
require_once __DIR__ . '/controllers/ScoreController.php';

$db       = Database::getInstance()->getConnection();
$schoolId = (int)($_SESSION['school_id'] ?? 0);

// ── Validate input ───────────────────────────────────────────────────────────
$studentId = (int)($_POST['student_id'] ?? 0);
$rawCh     = $_POST['channels'] ?? '[]';
$channels  = json_decode($rawCh, true);

if (!$studentId || !is_array($channels) || empty($channels)) {
    echo json_encode(['success' => false, 'error' => 'Missing student_id or channels.']);
    exit;
}

// ── Load student (must belong to this school) ────────────────────────────────
$stmtStu = $db->prepare("
    SELECT s.*, c.class_name
    FROM students s
    JOIN classes c ON c.id = s.class_id
    WHERE s.id = ? AND c.school_id = ?
");
$stmtStu->execute([$studentId, $schoolId]);
$student = $stmtStu->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    echo json_encode(['success' => false, 'error' => 'Student not found.']);
    exit;
}

// ── Load school info ──────────────────────────────────────────────────────────
$stmtSch = $db->prepare("SELECT * FROM school_info WHERE id = ?");
$stmtSch->execute([$schoolId]);
$school = $stmtSch->fetch(PDO::FETCH_ASSOC);

// ── Load parents for this student ────────────────────────────────────────────
// Primary: parent_contacts linked via parent_student_links (auto-synced from student record)
$parents = [];
$stmtPar = $db->prepare("
    SELECT pc.full_name, pc.phone, pc.whatsapp_number, pc.email, pc.relationship
    FROM parent_contacts pc
    JOIN parent_student_links psl ON psl.parent_id = pc.id
    WHERE psl.student_id = ? AND pc.school_id = ?
");
$stmtPar->execute([$studentId, $schoolId]);
$linkedParents = $stmtPar->fetchAll(PDO::FETCH_ASSOC);
foreach ($linkedParents as $lp) {
    $parents[] = $lp;
}

// Fallback: inline parent fields on the student record (for legacy data not yet synced)
if (empty($parents) && (!empty($student['parent_phone']) || !empty($student['parent_email']))) {
    $parents[] = [
        'full_name'        => $student['parent_name'] ?: 'Parent/Guardian',
        'phone'            => $student['parent_phone'] ?? '',
        'whatsapp_number'  => $student['parent_whatsapp'] ?? ($student['parent_phone'] ?? ''),
        'email'            => $student['parent_email'] ?? '',
        'relationship'     => $student['parent_relationship'] ?? 'Guardian',
    ];
}

// Helper: derive the possessive child word (son/daughter/ward) from relationship + gender
function childWord(string $relationship, string $gender): string {
    $rel = strtolower(trim($relationship));
    $parentRels = ['father','mother','parent','dad','mum','mom','mummy','daddy','papa','mama'];
    if (in_array($rel, $parentRels)) {
        return match(strtoupper($gender)) { 'M' => 'son', 'F' => 'daughter', default => 'child' };
    }
    return 'ward';
}

if (empty($parents)) {
    echo json_encode(['success' => false, 'error' => 'No parent contacts found for this student. Add a guardian contact when editing the student, or use Manage Parents to link one.']);
    exit;
}

// ── Load subject scores ──────────────────────────────────────────────────────
$stmtSubj = $db->prepare("
    SELECT s.* FROM subjects s
    WHERE s.class_id = ?
    ORDER BY s.subject_name
");
$stmtSubj->execute([$student['class_id']]);
$subjects = $stmtSubj->fetchAll(PDO::FETCH_ASSOC);

$scoreCtrl   = new ScoreController();
$subjectRows = [];
$totalScore  = 0;
$subjectCount= 0;

foreach ($subjects as $subj) {
    $score = $scoreCtrl->getScore($studentId, $subj['id']);
    if (!$score) continue;

    $calc = GradingSystem::calculateSubjectTotal($score);

    $subjectRows[] = [
        'subject'   => $subj['subject_name'],
        'total'     => $calc['total'],
        'grade'     => $calc['grade'],
        'remarks'   => $calc['remarks'],
    ];
    $totalScore += $calc['total'];
    $subjectCount++;
}

// ── Calculate class position ─────────────────────────────────────────────────
$stmtAllStu = $db->prepare("SELECT id FROM students WHERE class_id = ?");
$stmtAllStu->execute([$student['class_id']]);
$allStuIds = $stmtAllStu->fetchAll(PDO::FETCH_COLUMN);

$classTotals = [];
foreach ($allStuIds as $sid) {
    $t = 0;
    foreach ($subjects as $subj) {
        $sc = $scoreCtrl->getScore((int)$sid, $subj['id']);
        if (!$sc) continue;
        if (isset($sc['total_score'])) {
            $t += round($sc['total_score']);
        } else {
            $t += GradingSystem::calculateSubjectTotal($sc)['total'];
        }
    }
    $classTotals[$sid] = $t;
}
arsort($classTotals);
$position = 1;
$prev     = null;
$skip     = 0;
$studentPos = 0;
foreach ($classTotals as $sid => $tot) {
    if ($prev !== null && $tot === $prev) {
        $skip++;
    } else {
        $position += $skip;
        $skip = 0;
        $position = ($prev === null) ? 1 : $position;
    }
    if ($sid == $studentId) {
        $studentPos = $position;
        break;
    }
    $position++;
    $prev = $tot;
}

$classSize = count($allStuIds);

// ── Build position suffix ────────────────────────────────────────────────────
function posSuffix(int $n): string {
    if ($n <= 0) return 'N/A';
    if ($n % 100 >= 11 && $n % 100 <= 13) return $n . 'th';
    return $n . match($n % 10) { 1 => 'st', 2 => 'nd', 3 => 'rd', default => 'th' };
}

// ── Format subject names for narrative SMS ─────────────────────────────────────────
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

// ── Load notification settings ───────────────────────────────────────────────
$settings       = NotificationService::loadSettings($db);
$notifSvc       = new NotificationService($settings);

// Use APP_URL from config as primary source for report links
$reportBaseUrl = rtrim(APP_URL, '/');
// Fall back to saved setting if APP_URL is still localhost
if (strpos($reportBaseUrl, 'localhost') !== false || strpos($reportBaseUrl, '127.0.0.1') !== false) {
    $savedUrl = rtrim($settings['report_base_url'] ?? '', '/');
    if ($savedUrl !== '' && strpos($savedUrl, 'localhost') === false) {
        $reportBaseUrl = $savedUrl;
    }
}

$schoolName     = $school['school_name'] ?? 'School';
$academicYear   = '';
$currentTerm    = '';
$termStmt = $db->query("SELECT setting_value FROM system_settings WHERE setting_key='current_academic_year' LIMIT 1");
$termRow  = $termStmt ? $termStmt->fetch(PDO::FETCH_ASSOC) : null;
$academicYear = $termRow['setting_value'] ?? date('Y');
$currentTerm = $school['current_term'] ?? 'First Term';

// ── Outstanding fees ──────────────────────────────────────────────────────────
$feesEnabled      = !empty($school['fees_enabled']);
$feesArrears      = [];
$totalFeesBalance = 0.0;
$feeDetails       = [];
if ($feesEnabled) {
    require_once __DIR__ . '/controllers/FeeController.php';
    $feeCtrl    = new FeeController();
    $feeYear    = $school['academic_year'] ?? date('Y') . '/' . (date('Y') + 1);
    $feeTermNum = match(strtolower(trim($school['current_term'] ?? '1'))) {
        'first term', 'term 1', '1'  => '1',
        'second term','term 2', '2'  => '2',
        'third term', 'term 3', '3'  => '3',
        default => '1',
    };
    $feeDetails = $feeCtrl->getStudentFeeDetails(
        $studentId, (int)$student['class_id'], $schoolId, $feeYear, $feeTermNum
    );
    if (!empty($feeDetails['success']) && !empty($feeDetails['fees'])) {
        foreach ($feeDetails['fees'] as $f) {
            if ($f['balance'] > 0) {
                $feesArrears[]    = $f;
                $totalFeesBalance += $f['balance'];
            }
        }
    }
}

// ── Build messages ───────────────────────────────────────────────────────────
$studentName = $student['student_name'];
$className   = $student['class_name'];
$posFormatted= posSuffix($studentPos);
$reportLink  = $reportBaseUrl
    ? (function() use ($reportBaseUrl, $studentId, $student, $schoolId) {
        $secret = hash('sha256', $schoolId . 'SBA_PUB_REPORT');
        $token  = substr(hash_hmac('sha256', "{$studentId}:{$student['class_id']}:{$schoolId}", $secret), 0, 24);
        return $reportBaseUrl . '/public_report.php?sid=' . $studentId . '&cid=' . $student['class_id'] . '&schid=' . $schoolId . '&t=' . $token;
    })()
    : '';

// Build subject list for narrative format (done once per student)
$subjectList = [];
foreach ($subjectRows as $row) {
    $subjName = formatSubjectForSMS($row['subject']);
    $subjectList[] = $subjName . ' ' . $row['total'] . '(' . $row['grade'] . ')';
}
$subjectSummary = implode(', ', $subjectList);

// Build fee notice (email HTML block + SMS text)
$feeEmailHtml = '';
if ($feesEnabled && !empty($feesArrears)) {
    $feeRows = '';
    foreach ($feesArrears as $fa) {
        $feeRows .= "<tr>"
            . "<td style='padding:3px 8px;border:1px solid #feb2b2;'>" . htmlspecialchars($fa['fee_name']) . "</td>"
            . "<td style='padding:3px 8px;border:1px solid #feb2b2;text-align:right;'>GH&#x20B5;" . number_format($fa['amount_due'], 2) . "</td>"
            . "<td style='padding:3px 8px;border:1px solid #feb2b2;text-align:right;'>GH&#x20B5;" . number_format($fa['amount_paid'], 2) . "</td>"
            . "<td style='padding:3px 8px;border:1px solid #feb2b2;text-align:right;font-weight:700;color:#c53030;'>GH&#x20B5;" . number_format($fa['balance'], 2) . "</td>"
            . "</tr>";
    }
    $feeEmailHtml = "
    <div style='margin-top:16px;border:2px solid #e53e3e;border-radius:6px;padding:12px;background:#fff5f5;'>
      <div style='font-size:1rem;font-weight:700;color:#c53030;margin-bottom:8px;'>&#9888;&#65039; Outstanding Fees &ndash; {$currentTerm}</div>
      <table style='width:100%;border-collapse:collapse;font-size:0.85rem;'>
        <tr style='background:#fed7d7;'>
          <th style='padding:4px 8px;text-align:left;border:1px solid #fc8181;'>Fee</th>
          <th style='padding:4px 8px;text-align:right;border:1px solid #fc8181;'>Due</th>
          <th style='padding:4px 8px;text-align:right;border:1px solid #fc8181;'>Paid</th>
          <th style='padding:4px 8px;text-align:right;border:1px solid #fc8181;'>Balance</th>
        </tr>
        {$feeRows}
        <tr style='background:#fed7d7;font-weight:700;'>
          <td colspan='3' style='padding:4px 8px;border:1px solid #fc8181;'>Total Outstanding</td>
          <td style='padding:4px 8px;border:1px solid #fc8181;text-align:right;color:#c53030;'>GH&#x20B5;" . number_format($totalFeesBalance, 2) . "</td>
        </tr>
      </table>
      <p style='font-size:0.8rem;color:#742a2a;margin:6px 0 0;'>Please settle the outstanding balance at the school's accounts office.</p>
    </div>";
}

// Build email HTML (done once per student)
$subjectTableRows = '';
foreach ($subjectRows as $i => $row) {
    $bg = ($i % 2 === 0) ? '#f9f9f9' : '#ffffff';
    $subjectTableRows .= "
        <tr style='background:{$bg};'>
            <td style='padding:6px 10px;border:1px solid #ddd;'>" . htmlspecialchars($row['subject']) . "</td>
            <td style='padding:6px 10px;border:1px solid #ddd;text-align:center;'>{$row['total']}/100</td>
            <td style='padding:6px 10px;border:1px solid #ddd;text-align:center;'><strong>" . htmlspecialchars($row['grade']) . "</strong></td>
            <td style='padding:6px 10px;border:1px solid #ddd;'>" . htmlspecialchars($row['remarks']) . "</td>
        </tr>";
}

$reportLinkHtml = $reportLink
    ? "<p style='text-align:center;margin-top:20px;'><a href='{$reportLink}' style='display:inline-block;background:#667eea;color:#fff;padding:10px 24px;border-radius:6px;text-decoration:none;font-weight:600;'>📄 View Full Report Card Online</a></p>"
    : '';

$emailHtml = "
<!DOCTYPE html><html><head><meta charset='UTF-8'></head>
<body style='font-family:Arial,sans-serif;background:#f5f5f5;margin:0;padding:0;'>
<div style='max-width:600px;margin:30px auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.1);'>
  <div style='background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;padding:24px;text-align:center;'>
    <h1 style='margin:0;font-size:1.3rem;'>" . htmlspecialchars($schoolName) . "</h1>
    <p style='margin:4px 0 0;font-size:0.9rem;opacity:0.9;'>Student Report Card — {$academicYear}</p>
  </div>
  <div style='padding:20px 24px;'>
    <table style='width:100%;border-collapse:collapse;margin-bottom:16px;'>
      <tr><td style='padding:4px 0;color:#555;width:130px;'>Student</td><td style='font-weight:600;'>" . htmlspecialchars($studentName) . "</td></tr>
      <tr><td style='padding:4px 0;color:#555;'>Class</td><td>" . htmlspecialchars($className) . "</td></tr>
      <tr><td style='padding:4px 0;color:#555;'>Position</td><td><strong>{$posFormatted}</strong> of {$classSize} students</td></tr>
      <tr><td style='padding:4px 0;color:#555;'>Total Score</td><td><strong>{$totalScore}</strong> / " . (count($subjectRows) * 100) . "</td></tr>
    </table>
    <table style='width:100%;border-collapse:collapse;font-size:0.9rem;'>
      <thead>
        <tr style='background:#667eea;color:#fff;'>
          <th style='padding:8px 10px;text-align:left;'>Subject</th>
          <th style='padding:8px 10px;text-align:center;'>Score</th>
          <th style='padding:8px 10px;text-align:center;'>Grade</th>
          <th style='padding:8px 10px;text-align:left;'>Remarks</th>
        </tr>
      </thead>
      <tbody>{$subjectTableRows}</tbody>
    </table>
    {$feeEmailHtml}
    {$reportLinkHtml}
  </div>
  <div style='background:#f7f7f7;padding:12px 24px;font-size:0.8rem;color:#999;text-align:center;'>
    This report was generated by " . htmlspecialchars($schoolName) . " School Management System.
  </div>
</div>
</body></html>";

// ── Dispatch to each parent via selected channels ────────────────────────────
$results = ['sms' => []];
$errors  = [];

foreach ($parents as $parent) {
    $parentName = $parent['full_name'];

    // Personalize SMS with parent name (plain text for SMS)
    $relWord   = childWord($parent['relationship'] ?? 'Guardian', $student['gender'] ?? '');
    $smsLines  = [];
    $smsLines[] = strtoupper($schoolName);
    $smsLines[] = '';
    $smsLines[] = 'Dear ' . $parentName . ',';
    $smsLines[] = 'Your ' . $relWord . ' ' . $studentName . ' of ' . $className . ' has successfully completed the ' . $currentTerm . ' of the ' . $academicYear . ' academic year and placed ' . $posFormatted . ' out of ' . $classSize . ' students with an overall score of ' . $totalScore . '/' . (count($subjectRows) * 100) . '.';
    $smsLines[] = '';
    $smsLines[] = 'Performance Summary:';
    $smsLines[] = $subjectSummary . '.';
    if ($feesEnabled && !empty($feesArrears)) {
        $smsLines[] = '';
        $smsLines[] = 'FEES NOTICE: GHS' . number_format($totalFeesBalance, 2) . ' outstanding for ' . $currentTerm . '. Please settle at the school accounts office.';
    }
    $smsLines[] = '';
    $smsLines[] = 'View Full Report:';
    $smsLines[] = $reportLink;
    $smsLines[] = '';
    $smsLines[] = 'Thank you.';
    $smsLines[] = strtoupper($schoolName);
    $smsText = implode("\n", $smsLines);

    if (in_array('sms', $channels)) {
        $phone  = $parent['phone'];
        $result = $notifSvc->sendSMS($phone, $smsText);
        $results['sms'][] = [
            'parent' => $parentName,
            'phone'  => $phone,
            'ok'     => $result['success'],
            'detail' => $result['error'] ?? 'Sent',
        ];
        if (!$result['success']) $errors[] = "SMS to {$parentName}: " . ($result['error'] ?? 'failed');
    }
}

$overallSuccess = empty($errors);
echo json_encode([
    'success' => $overallSuccess,
    'results' => $results,
    'errors'  => $errors,
    'student' => $studentName,
    'parents' => count($parents),
]);
