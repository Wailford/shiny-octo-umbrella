<?php
/**
 * Public Report Card — no login required.
 * Protected by a signed HMAC token generated in notify_class.php.
 * Renders the EXACT same report card as student-report-full.php.
 *
 * URL: public_report.php?sid={studentId}&cid={classId}&schid={schoolId}&t={token}
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/helpers/GradingSystem.php';
require_once __DIR__ . '/controllers/ScoreController.php';
require_once __DIR__ . '/controllers/ClassController.php';

// ── Validate & sanitise inputs ────────────────────────────────────────────────
$studentId = isset($_GET['sid'])   ? (int)$_GET['sid']   : 0;
$classId   = isset($_GET['cid'])   ? (int)$_GET['cid']   : 0;
$schoolId  = isset($_GET['schid']) ? (int)$_GET['schid'] : 0;
$token     = isset($_GET['t'])     ? preg_replace('/[^a-f0-9]/i', '', $_GET['t']) : '';

if (!$studentId || !$classId || !$schoolId || strlen($token) < 16) {
    http_response_code(400);
    die('<h2 style="font-family:Arial;padding:2rem;">Invalid or missing report link.</h2>');
}

// ── Verify HMAC token ─────────────────────────────────────────────────────────
$secret   = hash('sha256', $schoolId . 'SBA_PUB_REPORT');
$expected = substr(hash_hmac('sha256', "$studentId:$classId:$schoolId", $secret), 0, 24);
if (!hash_equals($expected, $token)) {
    http_response_code(403);
    die('<h2 style="font-family:Arial;padding:2rem;">Report link is invalid or has been tampered with.</h2>');
}

// ── Load data ─────────────────────────────────────────────────────────────────
$db = Database::getInstance()->getConnection();

// School info
$stmt = $db->prepare("SELECT * FROM school_info WHERE id = ?");
$stmt->execute([$schoolId]);
$schoolInfo = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$schoolInfo) { http_response_code(404); die('<h2 style="font-family:Arial;padding:2rem;">School not found.</h2>'); }

// Expose school_id in session so ScoreController resolves the correct term/year
// (public_report.php has no login, but the HMAC token already proved $schoolId is trusted)
$_SESSION['school_id'] = $schoolId;

// Student — must belong to this school via class
$stmt = $db->prepare("
    SELECT s.* FROM students s
    JOIN classes c ON s.class_id = c.id
    WHERE s.id = ? AND s.class_id = ? AND c.school_id = ?
");
$stmt->execute([$studentId, $classId, $schoolId]);
$studentData = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$studentData) { http_response_code(404); die('<h2 style="font-family:Arial;padding:2rem;">Student not found.</h2>'); }

// Class
$stmt = $db->prepare("SELECT * FROM classes WHERE id = ? AND school_id = ?");
$stmt->execute([$classId, $schoolId]);
$classRow = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$classRow) { http_response_code(404); die('<h2 style="font-family:Arial;padding:2rem;">Class not found.</h2>'); }

// All students in the class (for position & roll count)
$stmt = $db->prepare("SELECT * FROM students WHERE class_id = ? ORDER BY student_name");
$stmt->execute([$classId]);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Total attendance for class
$totalAttendance = null; // Set from DB below; null means no default fallback
$stmt = $db->prepare("SELECT total_attendance FROM classes WHERE id = ? AND school_id = ?");
$stmt->execute([$classId, $schoolId]);
$classData = $stmt->fetch(PDO::FETCH_ASSOC);
if ($classData && !empty($classData['total_attendance'])) {
    $totalAttendance = $classData['total_attendance'];
}

// Subjects
$stmt = $db->prepare("SELECT s.* FROM subjects s JOIN classes c ON s.class_id = c.id WHERE s.class_id = ? AND c.school_id = ? ORDER BY s.subject_name");
$stmt->execute([$classId, $schoolId]);
$subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Grading system
$gradingSystemData = GradingSystem::loadGrades();

// ── Fees arrears (only if fees module is enabled for this school) ─────────────
$feesEnabled = !empty($schoolInfo['fees_enabled']);
$feesArrears = []; // list of outstanding fee items
$totalFeesBalance = 0.0;
if ($feesEnabled) {
    require_once __DIR__ . '/controllers/FeeController.php';
    $feeCtrl = new FeeController();
    $academicYear = $schoolInfo['academic_year'] ?? date('Y') . '/' . (date('Y')+1);
    $termNum = match(strtolower(trim($schoolInfo['current_term'] ?? '1'))) {
        'first term','term 1','1'  => '1',
        'second term','term 2','2' => '2',
        'third term','term 3','3'  => '3',
        default => '1',
    };
    $feeDetails = $feeCtrl->getStudentFeeDetails($studentId, $classId, $schoolId, $academicYear, $termNum);
    if (!empty($feeDetails['success']) && !empty($feeDetails['fees'])) {
        foreach ($feeDetails['fees'] as $f) {
            if ($f['balance'] > 0) {
                $feesArrears[]  = $f;
                $totalFeesBalance += $f['balance'];
            }
        }
    }
}

// ── Calculate class positions (identical to student-report-full.php) ──────────
$classPositions = [];
$scoreController = new ScoreController();

if (count($subjects) > 0) {
    $studentTotals = [];
    foreach ($students as $student) {
        $totalScore = 0;
        foreach ($subjects as $subject) {
            $score = $scoreController->getScore($student['id'], $subject['id']);
            if ($score && isset($score['total_score'])) {
                $totalScore += round($score['total_score']);
            }
        }
        $studentTotals[] = ['id' => $student['id'], 'total' => $totalScore];
    }
    usort($studentTotals, fn($a, $b) => $b['total'] - $a['total']);

    $position = 1;
    $previousScore = null;
    $samePositionCount = 0;
    foreach ($studentTotals as $st) {
        if ($previousScore !== null && $st['total'] === $previousScore) {
            $classPositions[$st['id']] = $position;
            $samePositionCount++;
        } else {
            $position += $samePositionCount;
            $classPositions[$st['id']] = $position;
            $samePositionCount = 0;
            $position++;
        }
        $previousScore = $st['total'];
    }
}

// ── Build subject scores (identical to student-report-full.php) ───────────────
$subjectScores = [];
foreach ($subjects as $subject) {
    $score = $scoreController->getScore($studentId, $subject['id']);

    $test1       = round($score['test1']        ?? 0);
    $groupWork   = round($score['group_work']   ?? 0);
    $test2       = round($score['test2']        ?? 0);
    $projectWork = round($score['project_work'] ?? 0);
    $examScore   = round($score['exam_score']   ?? 0);

    $calc       = GradingSystem::calculateSubjectTotal($score);
    $classScore = $calc['class_score'];
    $examScaled = $calc['exam_score_scaled'];
    $total      = $calc['total'];
    $grade      = $calc['grade'];
    $totalA     = $test1 + $groupWork + $test2 + $projectWork;

    $subjectScores[] = [
        'subject'      => $subject['subject_name'],
        'test1'        => $test1,
        'group_work'   => $groupWork,
        'test2'        => $test2,
        'project_work' => $projectWork,
        'total_a'      => $totalA,
        'class_score'  => $classScore,
        'exam_raw'     => $examScore,
        'exam_score'   => $examScaled,
        'total'        => $total,
        'grade'        => $grade,
        'position'     => $score['position'] ?? '',
        'remarks'      => $calc['remarks'],
    ];
}

// Position formatting: GradingSystem::formatPosition()

$totalMarks       = array_sum(array_column($subjectScores, 'total'));
$studentPosition  = $classPositions[$studentId] ?? 0;
$formattedPosition = GradingSystem::formatPosition((int)$studentPosition);

/**
 * Resolve an image path from the DB for use as an HTML src attribute.
 * Paths like "uploads/logos/x.png" are stored relative to the project root.
 * External URLs (http/https/data:) are passed through unchanged.
 * For local paths: verify the file exists using __DIR__ before trusting it.
 */
function resolveImageSrc(string $path, string $fallback = ''): string {
    if (empty($path)) return $fallback;
    // Already an absolute URL or data URI — use as-is
    if (preg_match('#^(https?:|data:)#i', $path)) {
        return htmlspecialchars($path);
    }
    // Local relative path — check existence from project root
    $absPath = __DIR__ . '/' . ltrim($path, '/');
    if (file_exists($absPath)) {
        return htmlspecialchars($path); // kept relative so browser resolves it correctly
    }
    return $fallback;
}

// Default logo SVG (same as student-report-full.php)
$defaultLogoSvg = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyMDAgMjAwIj48ZGVmcz48bGluZWFyR3JhZGllbnQgaWQ9ImciIHgxPSIwJSIgeTE9IjAlIiB4Mj0iMCUiIHkyPSIxMDAlIj48c3RvcCBvZmZzZXQ9IjAlIiBzdHlsZT0ic3RvcC1jb2xvcjojMDA3YmZmO3N0b3Atb3BhY2l0eToxIi8+PHN0b3Agb2Zmc2V0PSIxMDAlIiBzdHlsZT0ic3RvcC1jb2xvcjojMDA1NmIzO3N0b3Atb3BhY2l0eToxIi8+PC9saW5lYXJHcmFkaWVudD48L2RlZnM+PGNpcmNsZSBjeD0iMTAwIiBjeT0iMTAwIiByPSI5NSIgZmlsbD0idXJsKCNnKSIgc3Ryb2tlPSIjZmZkNzAwIiBzdHJva2Utd2lkdGg9IjgiLz48cGF0aCBkPSJNMTAwIDMwTDEyMCA3MEg4MEwxMDAgMzBaIiBmaWxsPSIjZmZkNzAwIi8+PHBhdGggZD0iTTcwIDgwSDEzMFYxNDBINzBWODBaIiBmaWxsPSJ3aGl0ZSIgc3Ryb2tlPSIjMDA1NmIzIiBzdHJva2Utd2lkdGg9IjMiLz48bGluZSB4MT0iODUiIHkxPSI5NSIgeDI9IjExNSIgeTI9Ijk1IiBzdHJva2U9IiMwMDdiZmYiIHN0cm9rZS13aWR0aD0iMyIvPjxsaW5lIHgxPSI4NSIgeTE9IjExMCIgeDI9IjExNSIgeTI9IjExMCIgc3Ryb2tlPSIjMDA3YmZmIiBzdHJva2Utd2lkdGg9IjMiLz48bGluZSB4MT0iODUiIHkxPSIxMjUiIHgyPSIxMTUiIHkyPSIxMjUiIHN0cm9rZT0iIzAwN2JmZiIgc3Ryb2tlLXdpZHRoPSIzIi8+PHRleHQgeD0iMTAwIiB5PSIxNzAiIGZvbnQtZmFtaWx5PSJBcmlhbCwgc2Fucy1zZXJpZiIgZm9udC1zaXplPSIyMCIgZm9udC13ZWlnaHQ9ImJvbGQiIGZpbGw9IndoaXRlIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIj5HRVM8L3RleHQ+PC9zdmc+';
// RIGHT: school logo if uploaded, otherwise fall back to developer default
$logo1Url = resolveImageSrc($schoolInfo['logo1_url'] ?? '', $defaultLogoSvg);
$signatureUrl = resolveImageSrc($schoolInfo['headmaster_signature'] ?? '', 'https://imgur.com/ryWbBpr.png');

// LEFT: student photo — empty string means no photo uploaded, so nothing renders on the left
$photoUrl = resolveImageSrc($studentData['photo_url'] ?? '', '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report Card — <?php echo htmlspecialchars($studentData['student_name']); ?></title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            background-color: #f9f9f9;
        }
        .report {
            margin-top: 20px;
            border: 1px solid #ddd;
            padding: 20px;
            padding-top: 32mm;
            font-size: 13pt;
            background-color: #fff;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            position: relative;
            max-width: 210mm;
            margin-left: auto;
            margin-right: auto;
        }
        .report h2, .report p {
            margin: 0 0 5px 0;
            text-align: center;
            color: #333;
            font-size: 14pt;
        }
        .report h2 { font-size: 17pt; font-weight: bold; }
        .report h3 { font-size: 13pt; margin: 5px 0; }
        .report h4 { font-size: 12pt; margin: 10px 0 5px 0; }
        .report table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
            font-size: 11pt;
            border: 2px solid #000;
        }
        .report table th, .report table td {
            border: 1px solid #000;
            padding: 5px;
            text-align: left;
            font-size: 11pt;
        }
        .report table th.center, .report table td.center { text-align: center; }
        .report table.scores-table { table-layout: fixed; }
        .report table.scores-table th:nth-child(1),
        .report table.scores-table td:nth-child(1) {
            width: 25%; word-wrap: break-word; white-space: normal;
            overflow: hidden; text-overflow: ellipsis; max-width: 0;
        }
        .report table.scores-table th:nth-child(2),
        .report table.scores-table td:nth-child(2),
        .report table.scores-table th:nth-child(3),
        .report table.scores-table td:nth-child(3),
        .report table.scores-table th:nth-child(4),
        .report table.scores-table td:nth-child(4),
        .report table.scores-table th:nth-child(5),
        .report table.scores-table td:nth-child(5),
        .report table.scores-table th:nth-child(6),
        .report table.scores-table td:nth-child(6) { width: 9%; text-align: center; }
        .report table.scores-table th:nth-child(7),
        .report table.scores-table td:nth-child(7) { width: 30%; word-wrap: break-word; white-space: normal; }
        .report table.info-table th:first-child { width: 25%; font-weight: bold; }
        .report table.info-table td { width: 75%; word-wrap: break-word; white-space: normal; }
        .report table th { background-color: #f2f2f2; }
        .logo {
            position: absolute;
            width: 25mm; height: 25mm; object-fit: contain;
        }
        .logo.top-left { top: 5mm; left: 5mm; }
        .logo.top-right { top: 5mm; right: 5mm; }
        .student-photo {
            position: absolute;
            width: 25mm; height: 25mm; object-fit: cover;
            top: 5mm; left: 5mm; border: 1px solid #333;
        }
        .signature-area {
            display: flex;
            justify-content: space-between;
            margin-top: 80px;
            padding-top: 30px;
            border-top: 1px solid #ddd;
        }
        .signature-area div { width: 45%; text-align: center; }
        .signature-area img { max-width: 100px; height: auto; margin-bottom: 5px; }
        .signature-area p { margin: 5px 0; font-size: 10pt; }
        .print-btn {
            display: block; margin: 16px auto;
            padding: 10px 28px; background: #007bff; color: #fff;
            border: none; border-radius: 4px; font-size: 14px;
            cursor: pointer; font-weight: bold;
        }
        .print-btn:hover { background: #0056b3; }
        @media print {
            @page { size: A4 portrait; margin: 3mm; }
            body { margin: 0 !important; padding: 0 !important; font-size: 16px; line-height: 1.1; }
            .report {
                margin: 0 !important; padding: 5mm !important;
                width: 210mm !important; height: 297mm !important;
                min-height: 297mm !important; page-break-after: always;
                box-sizing: border-box; position: relative; overflow: hidden !important;
                font-size: 16px !important; box-shadow: none;
            }
            .report h2 { font-size: 16px; margin: 2px 0; }
            .report p   { font-size: 14px; margin: 1px 0; }
            .report table { font-size: 12px; margin-top: 2px; table-layout: fixed; }
            .report th, .report td { padding: 4px; line-height: 1.2; }
            .logo { width: 20mm; height: 20mm; top: 5mm; }
            .logo.top-left { left: 5mm; }
            .logo.top-right { right: 5mm; }
            .signature-area {
                margin-top: 8px; position: absolute;
                bottom: 5mm; width: calc(100% - 10mm);
            }
            .no-print { display: none !important; }
        }

        /* Mobile — make report readable on phones */
        @media (max-width: 768px) {
            body { margin: 0; padding: 0; background: #f0f0f0; }
            .report {
                margin: 0.5rem !important;
                padding: 1rem !important;
                padding-top: 80px !important;
                font-size: 11pt;
                max-width: 100%;
                border-radius: 8px;
            }
            .report h2 { font-size: 13pt; }
            .report h3, .report h4 { font-size: 11pt; }

            /* Score table: horizontal scroll */
            .report table.scores-table { min-width: 520px; }
            .report table { font-size: 9pt !important; }
            .report table th, .report table td { padding: 4px 3px !important; font-size: 9pt !important; }

            /* Wrap table in scroll container */
            .table-scroll-wrapper { overflow-x: auto; -webkit-overflow-scrolling: touch; margin: 0 -0.5rem; }

            .signature-area { flex-direction: column; align-items: center; gap: 1.5rem; margin-top: 2rem; }
            .signature-area div { width: 80%; }

            .logo { width: 18mm; height: 18mm; }
        }

        @media (max-width: 480px) {
            .report { margin: 0.25rem !important; padding: 0.75rem !important; padding-top: 70px !important; }
            .report h2 { font-size: 11pt; }
        }
    </style>
</head>
<body>

<div class="no-print" style="text-align:center;margin-bottom:10px;">
    <button class="print-btn" onclick="window.print()">🖨️ Print / Save as PDF</button>
</div>

<?php if ($studentData && count($subjectScores) > 0): ?>
<div id="report" class="report">

    <?php if ($photoUrl !== ''): ?>
        <img src="<?php echo htmlspecialchars($photoUrl); ?>" alt="Student Photo" class="student-photo">
    <?php endif; ?>

    <img src="<?php echo $logo1Url; ?>" alt="School Logo" class="logo top-right">

    <h2><?php echo htmlspecialchars($schoolInfo['school_name'] ?? 'School Name'); ?></h2>
    <p><?php echo htmlspecialchars($schoolInfo['address'] ?? ''); ?></p>
    <p><?php echo htmlspecialchars($schoolInfo['location'] ?? ''); ?></p>
    <p>Tel: <?php echo htmlspecialchars($schoolInfo['phone'] ?? ''); ?> | Email: <?php echo htmlspecialchars($schoolInfo['email'] ?? ''); ?></p>

    <div style="margin-top: 8mm;"></div>

    <h3>Overall Position: <?php echo $formattedPosition; ?></h3>

    <table class="info-table">
        <tr><th>NAME:</th><td><?php echo htmlspecialchars($studentData['student_name']); ?></td></tr>
        <tr><th>ACADEMIC YEAR:</th><td><?php echo htmlspecialchars($schoolInfo['academic_year'] ?? '2024/2025'); ?></td></tr>
        <tr><th>STUDENT ID:</th><td><?php echo htmlspecialchars($studentData['student_id']); ?></td></tr>
        <tr><th>TERM:</th><td><?php echo htmlspecialchars($schoolInfo['current_term'] ?? ''); ?></td></tr>
        <tr><th>CLASS:</th><td><?php echo strtoupper(htmlspecialchars(ClassController::getDisplayName($classRow))); ?></td></tr>
        <tr><th>REOPEN DATE:</th><td><?php echo htmlspecialchars($schoolInfo['reopen_date'] ?? ''); ?></td></tr>
    </table>

    <h4>Subject Scores</h4>
    <div class="table-scroll-wrapper">
    <table class="scores-table">
        <tr>
            <th>Subject</th>
            <th>Class Score</th>
            <th>Exam Score</th>
            <th>Total</th>
            <th>Grade</th>
            <th>Position</th>
            <th>Remarks</th>
        </tr>
        <?php foreach ($subjectScores as $score): ?>
        <tr>
            <td><?php echo htmlspecialchars($score['subject']); ?></td>
            <td class="center"><?php echo $score['class_score']; ?></td>
            <td class="center"><?php echo $score['exam_score']; ?></td>
            <td class="center"><?php echo $score['total']; ?></td>
            <td class="center"><?php echo htmlspecialchars($score['grade']); ?></td>
            <td class="center"><?php echo $score['position'] ? GradingSystem::formatPosition((int)$score['position']) : ''; ?></td>
            <td><?php echo htmlspecialchars($score['remarks']); ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    </div>

    <h4>Grading System</h4>
    <table>
        <tr>
            <?php foreach ($gradingSystemData as $gradeInfo): ?>
                <th><?php echo htmlspecialchars($gradeInfo['min_score'] . '-' . $gradeInfo['max_score']); ?></th>
            <?php endforeach; ?>
        </tr>
        <tr>
            <?php foreach ($gradingSystemData as $gradeInfo): ?>
                <td><?php echo htmlspecialchars($gradeInfo['grade'] . '-' . $gradeInfo['remarks']); ?></td>
            <?php endforeach; ?>
        </tr>
    </table>

    <table class="info-table">
        <tr><th>No. on Roll:</th><td><?php echo count($students); ?></td></tr>
        <tr><th>Attendance:</th><td><?php echo htmlspecialchars($studentData['attendance'] ?? 0); ?></td></tr>
        <tr><th>Total Marks:</th><td><?php echo $totalMarks; ?></td></tr>
        <tr><th>Position:</th><td><?php echo $formattedPosition; ?></td></tr>
        <tr><th>Conduct:</th><td><?php echo htmlspecialchars($studentData['conduct'] ?? ''); ?></td></tr>
        <tr><th>Interest:</th><td><?php echo htmlspecialchars($studentData['interest'] ?? ''); ?></td></tr>
        <tr><th>Teacher Remarks:</th><td><?php echo htmlspecialchars($studentData['form_master_remarks'] ?? ''); ?></td></tr>
        <tr><th>Headmaster Remarks:</th><td><?php echo htmlspecialchars($studentData['headmaster_remarks'] ?? ''); ?></td></tr>
        <tr><th>Promoted to:</th><td><?php echo htmlspecialchars($studentData['promoted_to_class'] ?? '-'); ?></td></tr>
    </table>



    <div class="signature-area">
        <div>
            <div style="width: 100px; height: 75px;"></div>
            <p>___________________________<br>Class Teacher's Signature</p>
        </div>
        <div>
            <img src="<?php echo $signatureUrl; ?>" alt="Head Teacher's Signature" style="max-width:108px;height:auto;margin-top:8mm;">
            <p><?php echo htmlspecialchars($schoolInfo['headmaster_name'] ?? 'Head Teacher'); ?><br>Head Teacher's Signature</p>
        </div>
    </div>

</div>
<?php else: ?>
<div style="font-family:Arial;padding:2rem;text-align:center;color:#555;">
    <p>No scores have been entered yet for this student. Please check back later.</p>
</div>
<?php endif; ?>

</body>
</html>
