<?php
/**
 * Comprehensive Student Report Card
 * Matches Google Apps Script display format
 */

session_start();
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/controllers/AuthController.php';
require_once __DIR__ . '/controllers/StudentController.php';
require_once __DIR__ . '/controllers/ClassController.php';
require_once __DIR__ . '/controllers/ScoreController.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/helpers/GradingSystem.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$db = Database::getInstance()->getConnection();
$studentController = new StudentController();
$classController = new ClassController();
$scoreController = new ScoreController();

// Get school info
$schoolInfo = null;
if (isset($_SESSION['school_id'])) {
    $stmt = $db->prepare("SELECT * FROM school_info WHERE id = ?");
    $stmt->execute([$_SESSION['school_id']]);
    $schoolInfo = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get classes
$schoolTypeId = $_SESSION['school_type_id'] ?? null;
$classes = $classController->getAllClasses($schoolTypeId);

// Get selected class and student
$selectedClassId = isset($_GET['class']) ? (int)$_GET['class'] : ($classes[0]['id'] ?? null);
$selectedStudentId = isset($_GET['student']) ? (int)$_GET['student'] : null;

// Get students for selected class
$students = $selectedClassId ? $studentController->getStudentsByClass($selectedClassId) : [];

// Get total attendance for class
$totalAttendance = null; // Set from DB below; null means no default fallback
if ($selectedClassId) {
    $stmt = $db->prepare("SELECT total_attendance FROM classes WHERE id = ? AND school_id = ?");
    $stmt->execute([$selectedClassId, $_SESSION['school_id']]);
    $classData = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($classData) {
        $totalAttendance = $classData['total_attendance'];
    }
}

// Get subjects for selected class
$subjects = [];
if ($selectedClassId) {
    $stmt = $db->prepare("SELECT s.* FROM subjects s JOIN classes c ON s.class_id = c.id WHERE s.class_id = ? AND c.school_id = ? ORDER BY s.subject_name");
    $stmt->execute([$selectedClassId, $_SESSION['school_id']]);
    $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Load grading system from database
$gradingSystemData = GradingSystem::loadGrades();

// Calculate positions for all students in the class
$classPositions = [];
if ($selectedClassId && count($subjects) > 0) {
    $studentTotals = [];
    
    foreach ($students as $student) {
        $totalScore = 0;
        $subjectCount = 0;
        
        foreach ($subjects as $subject) {
            $score = $scoreController->getScore($student['id'], $subject['id']);
            if ($score && isset($score['total_score'])) {
                $totalScore += round($score['total_score']);
                $subjectCount++;
            }
        }
        
        $studentTotals[] = [
            'id' => $student['id'],
            'name' => $student['student_name'],
            'total' => $totalScore
        ];
    }
    
    // Sort by total score descending
    usort($studentTotals, function($a, $b) {
        return $b['total'] - $a['total'];
    });
    
    // Assign positions with tie handling
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

// Get selected student data
$studentData = null;
$subjectScores = [];
if ($selectedStudentId) {
    foreach ($students as $student) {
        if ($student['id'] == $selectedStudentId) {
            $studentData = $student;
            break;
        }
    }
    
    // Get scores for all subjects
    if ($studentData) {
        foreach ($subjects as $subject) {
            $score = $scoreController->getScore($selectedStudentId, $subject['id']);
            
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
                    'position' => $score['position'] ?? '',
                    'remarks' => $remarks
                ];
        }
    }
}

// ── Outstanding fees (if fees module is enabled) ──────────────────────────────
$feesEnabled      = !empty($schoolInfo['fees_enabled']);
$feesArrears      = [];
$totalFeesBalance = 0.0;
$feeDetails       = [];
if ($feesEnabled && $selectedStudentId && $selectedClassId) {
    require_once __DIR__ . '/controllers/FeeController.php';
    $feeCtrl    = new FeeController();
    $feeYear    = $schoolInfo['academic_year'] ?? date('Y') . '/' . (date('Y') + 1);
    $feeTermNum = match(strtolower(trim($schoolInfo['current_term'] ?? '1'))) {
        'first term', 'term 1', '1'  => '1',
        'second term','term 2', '2'  => '2',
        'third term', 'term 3', '3'  => '3',
        default => '1',
    };
    $feeDetails = $feeCtrl->getStudentFeeDetails(
        $selectedStudentId, $selectedClassId, (int)($_SESSION['school_id'] ?? 0), $feeYear, $feeTermNum
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

// Position formatting delegated to GradingSystem::formatPosition()
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>School Scores Management System</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            background-color: #f9f9f9;
        }
        
        select {
            padding: 10px;
            margin: 10px 0;
            border: 1px solid #ccc;
            border-radius: 4px;
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
        }
        .report h2, .report p {
            margin: 0 0 5px 0;
            text-align: center;
            color: #333;
            font-size: 14pt;
        }
        .report h2 {
            font-size: 17pt;
            font-weight: bold;
        }
        .report h3 {
            font-size: 13pt;
            margin: 5px 0;
        }
        .report h4 {
            font-size: 12pt;
            margin: 10px 0 5px 0;
        }
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
        .report table th.center, .report table td.center {
            text-align: center;
        }
        .report table td.center {
            font-weight: 600;
        }
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
        /* Student info table - narrow first column for label/value pairs */
        .report table.info-table th:first-child {
            width: 25%;
            font-weight: bold;
        }
        .report table.info-table td {
            width: 75%;
            word-wrap: break-word;
            white-space: normal;
        }
        .report table th.center, .report table td.center {
            text-align: center;
        }
        .report table th {
            background-color: #f2f2f2;
        }
        .buttons {
            margin-top: 20px;
            text-align: center;
        }
        .buttons button {
            padding: 10px 20px;
            margin-right: 10px;
            border: none;
            background-color: #007bff;
            color: #fff;
            border-radius: 4px;
            cursor: pointer;
            position: relative;
        }
        .buttons button:hover {
            background-color: #0056b3;
        }
        .signature-area {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            margin-top: 80px;
            padding-top: 30px;
            border-top: 1px solid #ddd;
        }
        .signature-area div {
            width: 45%;
            text-align: center;
        }
        .signature-area img {
            max-width: 100px;
            height: auto;
            margin-bottom: 5px;
        }
        .signature-area p {
            margin: 5px 0;
            font-size: 10pt;
        }
        .logo {
            position: absolute;
            width: 25mm;
            height: 25mm;
            object-fit: contain;
        }
        .logo.top-left {
            top: 5mm;
            left: 5mm;
        }
        .logo.top-right {
            top: 5mm;
            right: 5mm;
        }
        .student-photo {
            position: absolute;
            width: 25mm;
            height: 25mm;
            object-fit: cover;
            top: 5mm;
            left: 5mm;
            border: 1px solid #333;
        }
        .web-only {
            display: block;
        }
        .print-only {
            display: none;
        }

        .header {
            background-color: #007bff;
            color: white;
            padding: 15px;
            text-align: center;
            border-radius: 4px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header h1 {
            margin: 0;
            flex: 1;
        }
        .nav-links {
            display: flex;
            gap: 15px;
        }
        .nav-links a {
            color: white;
            text-decoration: none;
            padding: 8px 15px;
            background-color: rgba(255, 255, 255, 0.1);
            border-radius: 4px;
            transition: background-color 0.3s;
        }
        .nav-links a:hover {
            background-color: rgba(255, 255, 255, 0.2);
        }
        .control-panel {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            background: white;
            padding: 1.25rem 1.5rem;
            border-radius: 10px;
            box-shadow: 0 1px 4px rgba(0,0,0,.05);
            margin-bottom: 1.5rem;
        }
        .control-group {
            flex: 1;
            min-width: 200px;
        }
        .control-group label {
            display: block;
            margin-bottom: .4rem;
            font-weight: 700;
            color: #374151;
            font-size: .82rem;
            text-transform: uppercase;
            letter-spacing: .4px;
        }
        .table-container {
            overflow-x: auto;
            background-color: white;
            border-radius: 4px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }
        .table-container table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 0;
        }
        .table-container th, .table-container td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        .table-container th {
            background-color: #f2f2f2;
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
            }
            .report h2 {
                font-size: 16px;
                margin: 2px 0;
            }
            .report p {
                font-size: 14px;
                margin: 1px 0;
            }
            .report table {
                font-size: 12px;
                margin-top: 2px;
                table-layout: fixed;
            }
            .report th, 
            .report td {
                padding: 4px;
                line-height: 1.2;
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
                margin-top: 8px;
                position: absolute;
                bottom: 5mm;
                width: calc(100% - 10mm);
            }
            .web-only {
                display: none !important;
            }
            .print-only {
                display: block !important;
            }
        }
    </style>
</head>
<body>
    <div class="container">
    <div class="control-panel web-only">
        <div class="control-group">
            <label for="classSelect">Select Class:</label>
            <select id="classSelect" onchange="loadStudents()" class="form-control">
                <option value="">--Select Class--</option>
                <?php foreach ($classes as $class): ?>
                    <option value="<?php echo $class['id']; ?>" <?php echo $selectedClassId == $class['id'] ? 'selected' : ''; ?>>
                        <?php 
                            require_once __DIR__ . '/controllers/ClassController.php';
                            echo htmlspecialchars(ClassController::getDisplayName($class));
                        ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="control-group">
            <label for="studentSelect">Select Student:</label>
            <select id="studentSelect" onchange="generateReport()" class="form-control">
                <option value="">--Select Student--</option>
                <?php foreach ($students as $student): ?>
                    <option value="<?php echo $student['id']; ?>" <?php echo $selectedStudentId == $student['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($student['student_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    
    <div class="table-container web-only">
        <?php if ($selectedClassId && $selectedStudentId && count($subjectScores) > 0): ?>
        <table>
            <tr>
                <th>S/N</th>
                <th>Subject</th>
                <th>Test 1 (15)</th>
                <th>Group Work (15)</th>
                <th>Test 2 (15)</th>
                <th>Project Work (15)</th>
                <th>Total A (60)</th>
                <th>Class Score (50%)</th>
                <th>EXAMS SCORE B (100)</th>
                <th>Exams Score (50%)</th>
                <th>TOTAL (A+B) 100</th>
                <th>GRADE</th>
                <th>POSITION</th>
                <th>Remarks</th>
            </tr>
            <?php 
            $sn = 1;
            foreach ($subjectScores as $score):
            ?>
            <tr>
                <td><?php echo $sn++; ?></td>
                <td><?php echo htmlspecialchars($score['subject']); ?></td>
                <td><?php echo $score['test1']; ?></td>
                <td><?php echo $score['group_work']; ?></td>
                <td><?php echo $score['test2']; ?></td>
                <td><?php echo $score['project_work']; ?></td>
                <td><?php echo $score['total_a']; ?></td>
                <td><?php echo $score['class_score']; ?></td>
                <td><?php echo $score['exam_raw']; ?></td>
                <td><?php echo $score['exam_score']; ?></td>
                <td><?php echo $score['total']; ?></td>
                <td><?php echo htmlspecialchars($score['grade']); ?></td>
                <td><?php echo $score['position'] ? GradingSystem::formatPosition((int)$score['position']) : ''; ?></td>
                <td><?php echo htmlspecialchars($score['remarks']); ?></td>
            </tr>
            <?php 
            endforeach; 
            ?>
        </table>
        <?php endif; ?>
    </div>
    
    <div class="buttons web-only">
        <button onclick="previousStudent()" id="prevButton">Previous</button>
        <button onclick="nextStudent()" id="nextButton">Next</button>
        <button onclick="printReport()" id="printButton">Print</button>
    </div>
    
    <?php if ($studentData && count($subjectScores) > 0): 
        $totalMarks = array_sum(array_column($subjectScores, 'total'));
        $studentPosition = $classPositions[$selectedStudentId] ?? 0;
        $formattedPosition = GradingSystem::formatPosition((int)$studentPosition);
    ?>
    <div id="report" class="report">
        <?php 
        // Display left logo (logo1) or default GES logo
        $logo1Url = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyMDAgMjAwIj48ZGVmcz48bGluZWFyR3JhZGllbnQgaWQ9ImciIHgxPSIwJSIgeTE9IjAlIiB4Mj0iMCUiIHkyPSIxMDAlIj48c3RvcCBvZmZzZXQ9IjAlIiBzdHlsZT0ic3RvcC1jb2xvcjojMDA3YmZmO3N0b3Atb3BhY2l0eToxIi8+PHN0b3Agb2Zmc2V0PSIxMDAlIiBzdHlsZT0ic3RvcC1jb2xvcjojMDA1NmIzO3N0b3Atb3BhY2l0eToxIi8+PC9saW5lYXJHcmFkaWVudD48L2RlZnM+PGNpcmNsZSBjeD0iMTAwIiBjeT0iMTAwIiByPSI5NSIgZmlsbD0idXJsKCNnKSIgc3Ryb2tlPSIjZmZkNzAwIiBzdHJva2Utd2lkdGg9IjgiLz48cGF0aCBkPSJNMTAwIDMwTDEyMCA3MEg4MEwxMDAgMzBaIiBmaWxsPSIjZmZkNzAwIi8+PHBhdGggZD0iTTcwIDgwSDEzMFYxNDBINzBWODBaIiBmaWxsPSJ3aGl0ZSIgc3Ryb2tlPSIjMDA1NmIzIiBzdHJva2Utd2lkdGg9IjMiLz48bGluZSB4MT0iODUiIHkxPSI5NSIgeDI9IjExNSIgeTI9Ijk1IiBzdHJva2U9IiMwMDdiZmYiIHN0cm9rZS13aWR0aD0iMyIvPjxsaW5lIHgxPSI4NSIgeTE9IjExMCIgeDI9IjExNSIgeTI9IjExMCIgc3Ryb2tlPSIjMDA3YmZmIiBzdHJva2Utd2lkdGg9IjMiLz48bGluZSB4MT0iODUiIHkxPSIxMjUiIHgyPSIxMTUiIHkyPSIxMjUiIHN0cm9rZT0iIzAwN2JmZiIgc3Ryb2tlLXdpZHRoPSIzIi8+PHRleHQgeD0iMTAwIiB5PSIxNzAiIGZvbnQtZmFtaWx5PSJBcmlhbCwgc2Fucy1zZXJpZiIgZm9udC1zaXplPSIyMCIgZm9udC13ZWlnaHQ9ImJvbGQiIGZpbGw9IndoaXRlIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIj5HRVM8L3RleHQ+PC9zdmc+';
        if ($schoolInfo && !empty($schoolInfo['logo1_url'])) {
            $raw = $schoolInfo['logo1_url'];
            $logo1Url = (str_starts_with($raw, 'http://') || str_starts_with($raw, 'https://'))
                ? htmlspecialchars($raw)
                : htmlspecialchars(rtrim(APP_URL, '/') . '/' . ltrim($raw, '/'));
        }
        ?>
        <img src="<?php echo $logo1Url; ?>" alt="School Logo" class="logo top-right">

        <?php
        // Student photo — only render if uploaded (no default avatar placeholder)
        $photoUrl = ($studentData && !empty($studentData['photo_url']) && file_exists($studentData['photo_url']))
            ? $studentData['photo_url'] : '';
        ?>
        <?php if ($photoUrl !== ''): ?>
        <img src="<?php echo htmlspecialchars($photoUrl); ?>" alt="Student Photo" class="student-photo">
        <?php endif; ?>
        
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
            <tr><th>TERM:</th><td><?php echo htmlspecialchars($schoolInfo['current_term'] ?? 'Second Term'); ?></td></tr>
            <tr><th>CLASS:</th><td><?php 
                require_once __DIR__ . '/controllers/ClassController.php';
                foreach ($classes as $c) {
                    if ($c['id'] == $selectedClassId) {
                        echo strtoupper(htmlspecialchars(ClassController::getDisplayName($c)));
                        break;
                    }
                }
            ?></td></tr>
            <tr><th>REOPEN DATE:</th><td><?php echo htmlspecialchars($schoolInfo['reopen_date'] ?? ''); ?></td></tr>
        </table>
        
        <h4>Subject Scores</h4>
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
                <p>___________________________<br>
                Class Teacher's Signature</p>
            </div>
            <div>
                <?php if ($schoolInfo && !empty($schoolInfo['headmaster_signature'])): ?>
                <?php
                    $sigRaw = $schoolInfo['headmaster_signature'];
                    $sigUrl = (str_starts_with($sigRaw, 'http://') || str_starts_with($sigRaw, 'https://'))
                        ? htmlspecialchars($sigRaw)
                        : htmlspecialchars(rtrim(APP_URL, '/') . '/' . ltrim($sigRaw, '/'));
                ?>
                <img src="<?php echo $sigUrl; ?>" alt="Head Teacher's Signature" style="max-width: 108px; height: auto; margin-top: 8mm;">
                <?php else: ?>
                <div style="width: 108px; height: 50px; margin-top: 8mm;"></div>
                <?php endif; ?>
                <p><?php echo htmlspecialchars($schoolInfo['headmaster_name'] ?? 'Head Teacher'); ?><br>
                Head Teacher's Signature</p>
            </div>
        </div>
    </div>
    <?php elseif ($selectedStudentId): ?>
    <div class="alert alert-warning web-only">
        No scores found for this student. Please ensure scores have been entered for all subjects.
    </div>
    <?php endif; ?>
    
    <script>
        let students = <?php echo json_encode($students); ?>;
        let currentStudentIndex = <?php echo $selectedStudentId ? array_search($selectedStudentId, array_column($students, 'id')) : -1; ?>;
        
        function loadStudents() {
            const classId = document.getElementById('classSelect').value;
            if (classId) {
                window.location.href = '?class=' + classId;
            }
        }
        
        function generateReport() {
            const studentId = document.getElementById('studentSelect').value;
            const classId = document.getElementById('classSelect').value;
            if (studentId && classId) {
                window.location.href = '?class=' + classId + '&student=' + studentId;
            }
        }
        
        function previousStudent() {
            if (currentStudentIndex > 0) {
                currentStudentIndex--;
                const classId = document.getElementById('classSelect').value;
                window.location.href = '?class=' + classId + '&student=' + students[currentStudentIndex].id;
            }
        }
        
        function nextStudent() {
            if (currentStudentIndex < students.length - 1) {
                currentStudentIndex++;
                const classId = document.getElementById('classSelect').value;
                window.location.href = '?class=' + classId + '&student=' + students[currentStudentIndex].id;
            }
        }
        
        function printReport() {
            window.print();
        }
    </script>
</body>
</html>
