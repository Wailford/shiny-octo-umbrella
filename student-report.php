<?php
/**
 * Student Report Card - Matches Google Apps Script display format
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

// Get user role and check access
$userRole = $_SESSION['role'] ?? $_SESSION['user_type'] ?? 'teacher';
$isAdmin = ($_SESSION['user_type'] ?? '') === 'admin';

// Check if user has form master assignment
$hasFormMasterAssignment = false;
$formMasterClassId = null;
if (!$isAdmin) {
    $db_temp = Database::getInstance()->getConnection();
    $school_id = $_SESSION['school_id'] ?? null;
    $stmt = $db_temp->prepare("SELECT fm.class_id FROM form_masters fm JOIN classes c ON fm.class_id = c.id WHERE fm.user_id = ? AND c.school_id = ?");
    $stmt->execute([$_SESSION['user_id'], $school_id]);
    $fmClass = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($fmClass) {
        $hasFormMasterAssignment = true;
        $formMasterClassId = $fmClass['class_id'];
    }
}

// Subject teachers without form master assignment cannot access reports
if ($userRole === 'subject_master' && !$isAdmin && !$hasFormMasterAssignment) {
    header('Location: scores.php?error=access_denied');
    exit;
}

$db = Database::getInstance()->getConnection();
$studentController = new StudentController();
$classController = new ClassController();
$scoreController = new ScoreController();

// Get school info
$schoolInfo = null;
if (isset($_SESSION['school_id'])) {
    // Get school information for the logged-in user's school
    $stmt = $db->prepare("SELECT * FROM school_info WHERE id = ?");
    $stmt->execute([$_SESSION['school_id']]);
    $schoolInfo = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get classes
$schoolTypeId = $_SESSION['school_type_id'] ?? null;
$classes = $classController->getAllClasses($schoolTypeId);

// Filter classes for form masters - only show assigned class
if ($hasFormMasterAssignment && $formMasterClassId && !$isAdmin) {
    $classes = array_filter($classes, function($class) use ($formMasterClassId) {
        return $class['id'] == $formMasterClassId;
    });
    $classes = array_values($classes);
}

// Get selected class and student
$selectedClassId = isset($_GET['class']) ? (int)$_GET['class'] : ($classes[0]['id'] ?? null);

// Verify form masters can only access their assigned class
if ($hasFormMasterAssignment && $formMasterClassId && $selectedClassId != $formMasterClassId && !$isAdmin) {
    $selectedClassId = $formMasterClassId;
}
$selectedStudentId = isset($_GET['student']) ? (int)$_GET['student'] : null;

// Get students for selected class
$students = $selectedClassId ? $studentController->getStudentsByClass($selectedClassId) : [];

// Get subjects for selected class
$subjects = [];
if ($selectedClassId) {
    $stmt = $db->prepare("SELECT s.* FROM subjects s JOIN classes c ON s.class_id = c.id WHERE s.class_id = ? AND c.school_id = ? ORDER BY s.subject_name");
    $stmt->execute([$selectedClassId, $_SESSION['school_id']]);
    $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

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
            
            // Always add subject, use defaults if no score exists
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
                // Default values for subjects without scores
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
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            border: 1px solid #333;
        }
        th, td {
            border: 1px solid #333;
            padding: 8px;
            text-align: left;
        }
        th {
            background-color: #f2f2f2;
        }
        .report {
            margin-top: 20px;
            border: 1px solid #ddd;
            padding: 20px;
            font-size: 16px;
            background-color: #fff;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            position: relative;
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
            margin: 8px 0 4px 0;
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
            margin-top: 4px;
            font-size: 18px;
            border: 1px solid #333;
        }
        .report table th, .report table td {
            border: 1px solid #333;
            padding: 6px 7px;
            text-align: left;
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
        .report table.subject-scores {
            table-layout: fixed;
        }
        .report table.subject-scores th:nth-child(1),
        .report table.subject-scores td:nth-child(1) {
            width: 25%;
            word-wrap: break-word;
            white-space: normal;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 0;
        }
        .report table.subject-scores th:nth-child(2),
        .report table.subject-scores td:nth-child(2),
        .report table.subject-scores th:nth-child(3),
        .report table.subject-scores td:nth-child(3),
        .report table.subject-scores th:nth-child(4),
        .report table.subject-scores td:nth-child(4) {
            width: 9%;
        }
        .report table.subject-scores th:nth-child(5),
        .report table.subject-scores td:nth-child(5),
        .report table.subject-scores th:nth-child(6),
        .report table.subject-scores td:nth-child(6) {
            width: 9%;
        }
        .report table.subject-scores th:nth-child(7),
        .report table.subject-scores td:nth-child(7) {
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
        .buttons {
            margin-top: 30px;
            margin-bottom: 20px;
            text-align: center;
            display: flex;
            justify-content: center;
            gap: 12px;
            flex-wrap: wrap;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }
        .buttons .btn {
            margin-right: 0;
            padding: 10px 20px;
            font-size: 14px;
            font-weight: 600;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s ease;
            border: none;
            min-width: 120px;
        }
        .buttons .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .buttons .btn:active {
            transform: translateY(0);
        }
        .buttons .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .buttons .btn-success {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            color: white;
        }
        .signature-area {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            margin-top: 40px;
            padding-top: 20px;
        }
        .signature-area div {
            width: 45%;
            text-align: center;
        }
        .signature-area img {
            max-width: 100px;
            height: auto;
        }
        .logo {
            position: absolute;
            width: 25mm;
            height: 25mm;
            object-fit: contain;
        }
        .logo.top-right {
            top: 5mm;
            right: 5mm;
        }
        .student-photo {
            position: absolute;
            top: -3mm;
            left: 5mm;
            width: 28mm;
            height: 33mm;
            border: 2px solid #fff;
            object-fit: cover;
            background: #f0f0f0;
        }
        .web-only {
            top: 5mm;
            right: 5mm;
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
            border: 1px solid #333;
        }
        .table-container th, .table-container td {
            border: 1px solid #333;
            padding: 8px;
            text-align: left;
        }
        .table-container th {
            background-color: #f2f2f2;
        }
        
        @media (max-width: 768px) {
            body {
                padding: 10px;
            }
            
            .form-group {
                flex-direction: column;
                align-items: stretch;
            }
            
            .form-group label,
            .form-group select,
            .form-group button {
                width: 100%;
                margin: 5px 0;
            }
            
            button {
                padding: 12px;
                font-size: 16px;
            }
            
            .report {
                width: 100%;
                padding: 10px;
                overflow-x: auto;
            }
            
            table {
                font-size: 0.75rem;
            }
            
            th, td {
                padding: 0.4rem 0.3rem !important;
            }
            
            .header-logo {
                max-width: 60px !important;
            }
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
            }
            .report table.subject-scores {
                table-layout: fixed;
            }
            .report th, 
            .report td {
                padding: 2mm 1.5mm;
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
                margin-top: 5mm;
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
    <div class="header web-only">
        <h1>SCORES MANAGEMENT SYSTEM</h1>
    </div>
    
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
        <button onclick="previousStudent()" id="prevButton" class="btn btn-primary">Previous</button>
        <button onclick="nextStudent()" id="nextButton" class="btn btn-primary">Next</button>
        <button onclick="printReport()" id="printButton" class="btn btn-primary">Print</button>
        <?php if ($selectedClassId): ?>
        <button onclick="printAllReports()" id="printAllButton" class="btn btn-success">Print All Reports</button>
        <?php endif; ?>
        <?php if ($selectedStudentId && $selectedClassId): ?>
        <button onclick="openNotifyModal()" id="notifyParentsBtn" class="btn btn-primary">
            📲 Notify Parents
        </button>
        <?php endif; ?>
    </div>

    <!-- ── Notify Parents Modal ──────────────────────────────────────────── -->
    <div id="notifyModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:9000;align-items:center;justify-content:center;padding:1rem;">
        <div style="background:#fff;border-radius:10px;padding:1.5rem;width:100%;max-width:480px;max-height:90vh;overflow-y:auto;">
            <h2 style="font-size:1.15rem;font-weight:700;margin-bottom:0.25rem;">📲 Notify Parents</h2>
            <p style="color:#718096;font-size:0.88rem;margin-bottom:1rem;">
                Send <strong><?php echo htmlspecialchars($studentData['student_name'] ?? 'this student'); ?>'s</strong>
                report card to linked parent contacts.
            </p>

            <div style="margin-bottom:1rem;">
                <label style="display:flex;align-items:center;gap:0.5rem;margin-bottom:0.4rem;cursor:pointer;">
                    <input type="checkbox" id="chkSms" value="sms" checked> 📱 SMS
                </label>
            </div>

            <div id="notifyResult" style="display:none;margin-bottom:1rem;padding:0.75rem;border-radius:6px;font-size:0.88rem;"></div>

            <div style="display:flex;gap:0.75rem;justify-content:flex-end;">
                <button onclick="closeNotifyModal()"
                        style="padding:0.5rem 1rem;border:1px solid #cbd5e0;border-radius:6px;background:#fff;cursor:pointer;">
                    Close
                </button>
                <button id="sendNotifyBtn" onclick="sendNotification()" class="btn btn-primary" style="width: 100%; margin-top: 10px; padding: 0.8rem; font-size: 1.05rem; font-weight: 700;">
                    🚀 Send Now
                </button>
            </div>
        </div>
    </div>

    <script>
    function openNotifyModal() {
        document.getElementById('notifyModal').style.display = 'flex';
        document.getElementById('notifyResult').style.display = 'none';
    }
    function closeNotifyModal() {
        document.getElementById('notifyModal').style.display = 'none';
    }
    document.getElementById('notifyModal').addEventListener('click', function(e) {
        if (e.target === this) closeNotifyModal();
    });

    function sendNotification() {
        const channels = [];
        if (document.getElementById('chkSms')?.checked)      channels.push('sms');
        if (document.getElementById('chkWhatsapp')?.checked) channels.push('whatsapp');
        if (document.getElementById('chkEmail')?.checked)    channels.push('email');

        if (channels.length === 0) {
            alert('Please select at least one channel.');
            return;
        }

        const btn = document.getElementById('sendNotifyBtn');
        btn.disabled    = true;
        btn.textContent = '⏳ Sending…';

        const fd = new FormData();
        fd.append('student_id', '<?php echo (int)$selectedStudentId; ?>');
        fd.append('channels',   JSON.stringify(channels));

        fetch('send_report_card.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                const box = document.getElementById('notifyResult');
                box.style.display = 'block';

                if (data.success) {
                    box.style.background = '#d4edda';
                    box.style.border     = '1px solid #c3e6cb';
                    box.style.color      = '#155724';
                    box.innerHTML = '✅ Report card sent to <strong>' + data.parents + '</strong> parent(s) for <strong>' + data.student + '</strong>.';
                } else if (data.error) {
                    box.style.background = '#f8d7da';
                    box.style.border     = '1px solid #f5c6cb';
                    box.style.color      = '#721c24';
                    box.innerHTML = '❌ ' + data.error;
                } else {
                    // Partial success — show per-parent breakdown
                    let html = '<strong>Dispatch results:</strong><ul style="margin:6px 0 0 16px;">';
                    ['sms','whatsapp','email'].forEach(ch => {
                        (data.results[ch] || []).forEach(r => {
                            const icon = r.ok ? '✅' : '❌';
                            html += '<li>' + icon + ' ' + ch.toUpperCase() + ' → ' + r.parent + ': ' + r.detail + '</li>';
                        });
                    });
                    html += '</ul>';
                    box.style.background = '#fff3cd';
                    box.style.border     = '1px solid #ffeeba';
                    box.style.color      = '#856404';
                    box.innerHTML = html;
                }
            })
            .catch(err => {
                const box = document.getElementById('notifyResult');
                box.style.display = 'block';
                box.style.background = '#f8d7da';
                box.style.border     = '1px solid #f5c6cb';
                box.style.color      = '#721c24';
                box.innerHTML = '❌ Request failed: ' + err.message;
            })
            .finally(() => {
                btn.disabled    = false;
                btn.textContent = '🚀 Send Now';
            });
    }
    </script>
    
    <?php if ($studentData && count($subjectScores) > 0): 
        $totalMarks = array_sum(array_column($subjectScores, 'total'));
        $studentPosition = $classPositions[$selectedStudentId] ?? 0;
        $formattedPosition = GradingSystem::formatPosition((int)$studentPosition);
        
        // Get default avatar from system settings
        $defaultAvatar = 'uploads/students/default-avatar.svg';
        $debugInfo = "Default: $defaultAvatar<br>";
        
        try {
            $avatarStmt = $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'default_avatar' LIMIT 1");
            $avatarSetting = $avatarStmt->fetch(PDO::FETCH_ASSOC);
            $debugInfo .= "Query result: " . ($avatarSetting ? 'found' : 'not found') . "<br>";
            if ($avatarSetting && !empty($avatarSetting['setting_value'])) {
                $defaultAvatar = $avatarSetting['setting_value'];
                $debugInfo .= "Avatar from DB: $defaultAvatar<br>";
            }
        } catch (Exception $e) {
            // Fallback to default SVG if query fails
            $debugInfo .= "Query error: " . $e->getMessage() . "<br>";
        }
    ?>
    <div id="report" class="report">
        <?php 
        // Student photo — only render if uploaded (no default avatar placeholder)
        $photoUrl = (!empty($studentData['photo_url']) && file_exists($studentData['photo_url']))
            ? $studentData['photo_url'] : '';
        ?>
        <?php if ($photoUrl !== ''): ?>
        <img src="<?php echo htmlspecialchars($photoUrl); ?>" alt="Student Photo" class="student-photo">
        <?php endif; ?>
        
        <?php 
        // Display school logo or default GES logo
        $logoUrl = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyMDAgMjAwIj48ZGVmcz48bGluZWFyR3JhZGllbnQgaWQ9ImciIHgxPSIwJSIgeTE9IjAlIiB4Mj0iMCUiIHkyPSIxMDAlIj48c3RvcCBvZmZzZXQ9IjAlIiBzdHlsZT0ic3RvcC1jb2xvcjojMDA3YmZmO3N0b3Atb3BhY2l0eToxIi8+PHN0b3Agb2Zmc2V0PSIxMDAlIiBzdHlsZT0ic3RvcC1jb2xvcjojMDA1NmIzO3N0b3Atb3BhY2l0eToxIi8+PC9saW5lYXJHcmFkaWVudD48L2RlZnM+PGNpcmNsZSBjeD0iMTAwIiBjeT0iMTAwIiByPSI5NSIgZmlsbD0idXJsKCNnKSIgc3Ryb2tlPSIjZmZkNzAwIiBzdHJva2Utd2lkdGg9IjgiLz48cGF0aCBkPSJNMTAwIDMwTDEyMCA3MEg4MEwxMDAgMzBaIiBmaWxsPSIjZmZkNzAwIi8+PHBhdGggZD0iTTcwIDgwSDEzMFYxNDBINzBWODBaIiBmaWxsPSJ3aGl0ZSIgc3Ryb2tlPSIjMDA1NmIzIiBzdHJva2Utd2lkdGg9IjMiLz48bGluZSB4MT0iODUiIHkxPSI5NSIgeDI9IjExNSIgeTI9Ijk1IiBzdHJva2U9IiMwMDdiZmYiIHN0cm9rZS13aWR0aD0iMyIvPjxsaW5lIHgxPSI4NSIgeTE9IjExMCIgeDI9IjExNSIgeTI9IjExMCIgc3Ryb2tlPSIjMDA3YmZmIiBzdHJva2Utd2lkdGg9IjMiLz48bGluZSB4MT0iODUiIHkxPSIxMjUiIHgyPSIxMTUiIHkyPSIxMjUiIHN0cm9rZT0iIzAwN2JmZiIgc3Ryb2tlLXdpZHRoPSIzIi8+PHRleHQgeD0iMTAwIiB5PSIxNzAiIGZvbnQtZmFtaWx5PSJBcmlhbCwgc2Fucy1zZXJpZiIgZm9udC1zaXplPSIyMCIgZm9udC13ZWlnaHQ9ImJvbGQiIGZpbGw9IndoaXRlIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIj5HRVM8L3RleHQ+PC9zdmc+';
        if ($schoolInfo && !empty($schoolInfo['logo1_url'])) {
            $raw = $schoolInfo['logo1_url'];
            $logoUrl = (str_starts_with($raw, 'http://') || str_starts_with($raw, 'https://'))
                ? htmlspecialchars($raw)
                : htmlspecialchars(rtrim(APP_URL, '/') . '/' . ltrim($raw, '/'));
        }
        ?>
        <img src="<?php echo $logoUrl; ?>" alt="School Logo" class="logo top-right" loading="lazy">
        
        <h2><?php echo htmlspecialchars($schoolInfo['school_name'] ?? 'School Name'); ?></h2>
        <p><?php echo htmlspecialchars($schoolInfo['address'] ?? ''); ?></p>
        <p><?php echo htmlspecialchars($schoolInfo['location'] ?? ''); ?></p>
        <p>Tel: <?php echo htmlspecialchars($schoolInfo['phone'] ?? ''); ?> | Email: <?php echo htmlspecialchars($schoolInfo['email'] ?? ''); ?></p>
        <h3>Overall Position: <?php echo $formattedPosition; ?></h3>
        
        <table>
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
        
        <h4 style="margin-top: 8px; margin-bottom: 4px;">Subject Scores</h4>
        <table class="subject-scores">
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
        
        <h4 style="margin-top: 8px; margin-bottom: 4px;">Grading System</h4>
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
                <td><?php echo htmlspecialchars($grade['grade'] . '-' . $grade['remarks']); ?></td>
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
                <img src="<?php echo $sigUrl; ?>" alt="Head Teacher's Signature" style="max-width: 108px; height: auto;">
                <?php else: ?>
                <div style="width: 108px; height: 50px;"></div>
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
        
        function printAllReports() {
            const classId = document.getElementById('classSelect').value;
            if (!classId) {
                alert('Please select a class first.');
                return;
            }
            
            // Open new window with all reports
            const printWindow = window.open('print_all_reports.php?class=' + classId, '_blank');
            if (printWindow) {
                printWindow.addEventListener('load', function() {
                    printWindow.print();
                });
            }
        }
    </script>
</body>
</html>
