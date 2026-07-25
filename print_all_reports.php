<?php
/**
 * Print All Reports - Display all student reports for printing
 */

session_start();
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/controllers/AuthController.php';
require_once __DIR__ . '/controllers/StudentController.php';
require_once __DIR__ . '/controllers/ClassController.php';
require_once __DIR__ . '/controllers/ScoreController.php';
require_once __DIR__ . '/config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$db = Database::getInstance()->getConnection();
$studentController = new StudentController();
$classController = new ClassController();
$scoreController = new ScoreController();

// Get default avatar from system settings
$defaultAvatar = 'uploads/students/default-avatar.svg';
$avatarStmt = $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'default_avatar' LIMIT 1");
$avatarSetting = $avatarStmt->fetch(PDO::FETCH_ASSOC);
if ($avatarSetting) {
    $defaultAvatar = $avatarSetting['setting_value'];
}

// Get school info
$schoolInfo = null;
// Get school information for the logged-in user's school
$stmt = $db->prepare("SELECT * FROM school_info WHERE id = ?");
$stmt->execute([$_SESSION['school_id']]);
$schoolInfo = $stmt->fetch(PDO::FETCH_ASSOC);

// Get selected class
$selectedClassId = isset($_GET['class']) ? (int)$_GET['class'] : null;

if (!$selectedClassId) {
    die('No class selected');
}

// Get class name
$schoolTypeId = $_SESSION['school_type_id'] ?? null;
$classes = $classController->getAllClasses($schoolTypeId);
$className = '';
foreach ($classes as $c) {
    if ($c['id'] == $selectedClassId) {
        $className = $c['class_name'];
        break;
    }
}

// Get students for selected class
$students = $studentController->getStudentsByClass($selectedClassId);

if (empty($students)) {
    die('No students found for this class');
}

// Get subjects for selected class
$subjects = [];
$stmt = $db->prepare("SELECT s.* FROM subjects s JOIN classes c ON s.class_id = c.id WHERE s.class_id = ? AND c.school_id = ? ORDER BY s.subject_name");
$stmt->execute([$selectedClassId, $_SESSION['school_id']]);
$subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate positions for all students in the class
$classPositions = [];
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
        
        $studentTotals[] = [
            'id' => $student['id'],
            'total' => $totalScore
        ];
    }
    
    // Sort by total descending
    usort($studentTotals, function($a, $b) {
        return $b['total'] - $a['total'];
    });
    
    // Assign positions
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

// Position formatting delegated to GradingSystem::formatPosition()
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Reports - <?php echo htmlspecialchars($className); ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            background-color: #f9f9f9;
        }
        .report {
            margin-top: 20px;
            border: 1px solid #ddd;
            padding: 20px;
            font-size: 16px;
            background-color: #fff;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            position: relative;
            page-break-after: always;
        }
        .report:last-child {
            page-break-after: auto;
        }
        .report h2 {
            font-size: 22px;
            margin: 0 0 3px 0;
            text-align: center;
            color: #333;
            font-weight: bold;
        }
        .report h3 {
            font-size: 18px;
            margin: 5px 0 8px 0;
            text-align: center;
            color: #333;
            font-weight: bold;
        }
        .report h4 {
            font-size: 16px;
            margin: 6px 0 3px 0;
            color: #333;
            font-weight: bold;
        }
        .report p {
            margin: 0 0 2px 0;
            text-align: center;
            color: #333;
            font-size: 14px;
        }
        .report table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 3px;
            font-size: 18px;
            border: 1px solid #333;
        }
        .report table th, .report table td {
            border: 1px solid #333;
            padding: 5px 6px;
            text-align: left;
        }
        .report table td {
            white-space: nowrap;
        }
        .report table th.center, .report table td.center {
            text-align: center;
        }
        .report table td.center {
            font-weight: 600;
        }
        .report table th {
            background-color: #f2f2f2;
            font-weight: bold;
        }
        /* Subject scores table - specific column widths */
        .report table.scores-table {
            table-layout: fixed;
        }
        .report table.scores-table th:nth-child(1),
        .report table.scores-table td:nth-child(1) {
            width: 25%;
            word-wrap: break-word;
            white-space: normal;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 0;
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
        .report table.scores-table td:nth-child(6) {
            width: 9%;
            text-align: center;
        }
        .report table.scores-table th:nth-child(7),
        .report table.scores-table td:nth-child(7) {
            width: 30%;
            word-wrap: break-word;
            white-space: normal;
            overflow: hidden;
        }
        .signature-area {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            margin-top: 15px;
        }
        .signature-area div {
            width: 45%;
            text-align: center;
        }
        .logo {
            position: absolute;
            width: 20mm;
            height: 20mm;
            object-fit: contain;
            top: 5mm;
        }
        .logo.top-left {
            left: 5mm;
        }
        .logo.top-right {
            right: 5mm;
        }
        .student-photo {
            position: absolute;
            top: 5mm;
            left: 5mm;
            width: 22mm;
            height: 26mm;
            border: 2px solid #ddd;
            object-fit: cover;
            background: #f0f0f0;
        }
        
        @media print {
            @page {
                size: A4 portrait;
                margin: 3mm;
            }
            body {
                margin: 0 !important;
                padding: 0 !important;
                font-size: 16px;
                line-height: 1.1;
            }
            .report {
                margin: 0 !important;
                padding: 5mm !important;
                width: 210mm !important;
                height: 297mm !important;
                min-height: 297mm !important;
                page-break-after: always;
                box-sizing: border-box;
                position: relative;
                overflow: hidden !important;
                font-size: 16px !important;
                box-shadow: none;
            }
            .report h2 {
                font-size: 18px;
                margin: 2mm 0;
            }
            .report h3 {
                font-size: 15px;
                margin: 3mm 0;
            }
            .report h4 {
                font-size: 13px;
                margin: 4mm 0 2mm 0;
            }
            .report p {
                font-size: 12px;
                margin: 1.5mm 0;
            }
            .report table {
                font-size: 12px;
                margin-top: 3mm;
                table-layout: fixed;
            }
            .report th, 
            .report td {
                padding: 3mm 2mm;
                line-height: 1.3;
            }
            .logo {
                width: 20mm;
                height: 20mm;
                top: 5mm;
            }
            .logo.top-left {
                left: 5mm;
            }
            .logo.top-right {
                right: 5mm;
            }
            .signature-area {
                margin-top: 15mm;
                position: absolute;
                bottom: 5mm;
                width: calc(100% - 10mm);
            }
            .web-only {
                margin-top: 5mm;
                position: absolute;
                bottom: 5mm;
                width: calc(100% - 10mm);
            }
        }
    </style>
</head>
<body>

<?php foreach ($students as $student): 
    // Get scores for this student
    $subjectScores = [];
    foreach ($subjects as $subject) {
        $score = $scoreController->getScore($student['id'], $subject['id']);
        
        if ($score) {
            $test1 = round($score['test1'] ?? 0);
            $groupWork = round($score['group_work'] ?? 0);
            $test2 = round($score['test2'] ?? 0);
            $projectWork = round($score['project_work'] ?? 0);
            $examScore = round($score['exam_score'] ?? 0);

            $calc       = GradingSystem::calculateSubjectTotal($score);
            $classScore = $calc['class_score'];
            $examScaled = $calc['exam_score_scaled'];
            $total      = $calc['total'];
            $grade      = $calc['grade'];
            $remarks    = $calc['remarks'];
            $totalA     = $test1 + $groupWork + $test2 + $projectWork;
            $position   = $score['position'] ?? '';
        } else {
            $test1 = 0;
            $groupWork = 0;
            $test2 = 0;
            $projectWork = 0;
            $examScore = 0;
            $totalA = 0;
            $classScore = 0;
            $examScaled = 0;
            $total = 0;
            $grade = '';
            $remarks = '';
            $position = '';
        }
        
        $subjectScores[] = [
            'subject' => $subject['subject_name'],
            'test1' => $test1,
            'group_work' => $groupWork,
            'test2' => $test2,
            'project_work' => $projectWork,
            'total_a' => $totalA,
            'class_score' => $classScore,
            'exam_raw' => $examScore,
            'exam_score' => $examScaled,
            'total' => $total,
            'grade' => $grade,
            'position' => $position,
            'remarks' => $remarks
        ];
    }
    
    if (count($subjectScores) > 0):
        $totalMarks = array_sum(array_column($subjectScores, 'total'));
        $studentPosition = $classPositions[$student['id']] ?? 0;
        $formattedPosition = GradingSystem::formatPosition((int)$studentPosition);
?>

    <div class="report">
        <?php 
        // Use student's photo if available AND file exists, otherwise use default
        $photoUrl = $defaultAvatar;
        if (!empty($student['photo_url']) && file_exists($student['photo_url'])) {
            $photoUrl = $student['photo_url'];
        }
        ?>
        <img src="<?php echo htmlspecialchars($photoUrl); ?>" alt="Student Photo" class="student-photo">
        
        <?php if ($schoolInfo && !empty($schoolInfo['logo1_url'])): ?>
            <img src="<?php echo htmlspecialchars($schoolInfo['logo1_url']); ?>" alt="School Logo" class="logo top-right">
        <?php endif; ?>
        
        <h2><?php echo htmlspecialchars($schoolInfo['school_name'] ?? 'School Name'); ?></h2>
        <p><?php echo htmlspecialchars($schoolInfo['address'] ?? ''); ?></p>
        <p><?php echo htmlspecialchars($schoolInfo['location'] ?? ''); ?></p>
        <p>Tel: <?php echo htmlspecialchars($schoolInfo['phone'] ?? ''); ?> | Email: <?php echo htmlspecialchars($schoolInfo['email'] ?? ''); ?></p>
        <h3>Overall Position: <?php echo $formattedPosition; ?></h3>
        
        <table>
            <tr><th>NAME:</th><td><?php echo htmlspecialchars($student['student_name']); ?></td></tr>
            <tr><th>ACADEMIC YEAR:</th><td><?php echo htmlspecialchars($schoolInfo['academic_year'] ?? '2024/2025'); ?></td></tr>
            <tr><th>STUDENT ID:</th><td><?php echo htmlspecialchars($student['student_id']); ?></td></tr>
            <tr><th>TERM:</th><td><?php echo htmlspecialchars($schoolInfo['current_term'] ?? 'Second Term'); ?></td></tr>
            <tr><th>CLASS:</th><td><?php echo strtoupper(htmlspecialchars($className)); ?></td></tr>
            <tr><th>REOPEN DATE:</th><td><?php echo htmlspecialchars($schoolInfo['reopen_date'] ?? ''); ?></td></tr>
        </table>
        
        <h4>Subject Scores</h4>
        <table class="scores-table">
            <tr>
                <th>Subject</th>
                <th class="center">Class Score</th>
                <th class="center">Exam Score</th>
                <th class="center">Total</th>
                <th class="center">Grade</th>
                <th class="center">Position</th>
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
        
        <h4>Grading System</h4>
        <table>
            <tr>
                <?php 
                $gradingSystem = GradingSystem::loadGrades();
                foreach ($gradingSystem as $grade): 
                ?>
                <th><?php echo round($grade['min_score']); ?>-<?php echo round($grade['max_score']); ?></th>
                <?php endforeach; ?>
            </tr>
            <tr>
                <?php foreach ($gradingSystem as $grade): ?>
                <td style="white-space: normal;"><?php echo htmlspecialchars($grade['grade'] . '-' . $grade['remarks']); ?></td>
                <?php endforeach; ?>
            </tr>
        </table>
        
        <table>
            <tr><th>No. on Roll:</th><td><?php echo count($students); ?></td></tr>
            <tr><th>Attendance:</th><td><?php echo htmlspecialchars($student['attendance'] ?? 0); ?></td></tr>
            <tr><th>Total Marks:</th><td><?php echo $totalMarks; ?></td></tr>
            <tr><th>Position:</th><td><?php echo $formattedPosition; ?></td></tr>
            <tr><th>Conduct:</th><td><?php echo htmlspecialchars($student['conduct'] ?? ''); ?></td></tr>
            <tr><th>Interest:</th><td><?php echo htmlspecialchars($student['interest'] ?? ''); ?></td></tr>
            <tr><th>Teacher Remarks:</th><td><?php echo htmlspecialchars($student['form_master_remarks'] ?? ''); ?></td></tr>
            <tr><th>Headmaster Remarks:</th><td><?php echo htmlspecialchars($student['headmaster_remarks'] ?? ''); ?></td></tr>
            <tr><th>Promoted to:</th><td><?php echo htmlspecialchars($student['promoted_to_class'] ?? '-'); ?></td></tr>
        </table>
        
        <div class="signature-area">
            <div>
                <div style="width: 100px; height: 75px;"></div>
                <p>___________________________<br>
                Class Teacher's Signature</p>
            </div>
            <div>
                <?php if ($schoolInfo && !empty($schoolInfo['headmaster_signature'])): ?>
                <img src="<?php echo htmlspecialchars($schoolInfo['headmaster_signature']); ?>" alt="Head Teacher's Signature" style="max-width: 108px; height: auto;">
                <?php else: ?>
                <img src="https://imgur.com/ryWbBpr.png" alt="Head Teacher's Signature" style="max-width: 108px; height: auto;">
                <?php endif; ?>
                <p><?php echo htmlspecialchars($schoolInfo['headmaster_name'] ?? 'Head Teacher'); ?><br>
                Head Teacher's Signature</p>
            </div>
        </div>
    </div>

<?php 
    endif;
endforeach; 
?>

<script>
    // Auto-trigger print dialog when page loads
    window.addEventListener('load', function() {
        setTimeout(function() {
            window.print();
        }, 500);
    });
</script>

</body>
</html>
