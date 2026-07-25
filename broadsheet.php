<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/controllers/AuthController.php';
require_once __DIR__ . '/controllers/StudentController.php';
require_once __DIR__ . '/controllers/ClassController.php';
require_once __DIR__ . '/controllers/ScoreController.php';
require_once __DIR__ . '/helpers/GradingSystem.php';

$auth = new Auth();
$auth->requireLogin();

$currentUser = $auth->getCurrentUser();

// Get user role and check access
$userRole = $_SESSION['role'] ?? $_SESSION['user_type'] ?? 'teacher';
$isAdmin = $auth->isAdmin();

// Get school type and database connection
$db = Database::getInstance()->getConnection();

// Load grading system from database with error handling
try {
    $gradingSystemData = GradingSystem::loadGrades();
    if (empty($gradingSystemData)) {
        error_log("Warning: No grading system data loaded, using defaults");
    }
} catch (Exception $e) {
    error_log("Error loading grading system: " . $e->getMessage());
    // Use GradingSystem helper to load from database
    require_once __DIR__ . '/helpers/GradingSystem.php';
    $gradingSystemData = GradingSystem::loadGrades();
}

$schoolTypeCode = null;
if (isset($_SESSION['school_type_id'])) {
    $stmt = $db->prepare("SELECT st.type_code FROM school_info si LEFT JOIN school_types st ON si.school_type_id = st.id WHERE si.id = ?");
    $stmt->execute([$_SESSION['school_id'] ?? null]);
    $schoolType = $stmt->fetch(PDO::FETCH_ASSOC);
    $schoolTypeCode = $schoolType['type_code'] ?? null;
}
$isPrimarySchool = ($schoolTypeCode === 'PRIMARY');

// Check if user has form master assignment
$hasFormMasterAssignment = false;
$formMasterClassId = null;
$school_id = $_SESSION['school_id'] ?? null;
if (!$isAdmin) {
    $stmt = $db->prepare("SELECT fm.class_id FROM form_masters fm JOIN classes c ON fm.class_id = c.id WHERE fm.user_id = ? AND c.school_id = ?");
    $stmt->execute([$_SESSION['user_id'], $school_id]);
    $fmClass = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($fmClass) {
        $hasFormMasterAssignment = true;
        $formMasterClassId = $fmClass['class_id'];
    }
}

// Subject teachers without form master assignment cannot access broadsheet
if ($userRole === 'subject_master' && !$isAdmin && !$hasFormMasterAssignment) {
    header('Location: scores.php?error=access_denied');
    exit;
}

// Get school info
$schoolInfo = null;
if (isset($_SESSION['school_id'])) {
    $stmt = $db->prepare("SELECT * FROM school_info WHERE id = ?");
    $stmt->execute([$_SESSION['school_id']]);
    $schoolInfo = $stmt->fetch(PDO::FETCH_ASSOC);
}

$classController = new ClassController();
$studentController = new StudentController();
$scoreController = new ScoreController();

// Get assigned class for class teachers and form masters
$assignedClassId = $formMasterClassId;
$isClassTeacher = ($userRole === 'form_master' && !$isAdmin) || $hasFormMasterAssignment;

// Get selected class (filtered by school type)
$schoolTypeId = $_SESSION['school_type_id'] ?? null;
$classes = $classController->getAllClasses($schoolTypeId);

// Filter classes for class teachers/form masters - only show assigned class
if ($hasFormMasterAssignment && $assignedClassId && !$isAdmin) {
    $classes = array_filter($classes, function($class) use ($assignedClassId) {
        return $class['id'] == $assignedClassId;
    });
    $classes = array_values($classes);
}

$selectedClassId = isset($_GET['class']) ? (int)$_GET['class'] : ($classes[0]['id'] ?? null);

// Verify form masters can only access their assigned class
if ($hasFormMasterAssignment && $assignedClassId && $selectedClassId != $assignedClassId && !$isAdmin) {
    $selectedClassId = $assignedClassId;
}

$selectedClass = $selectedClassId ? $classController->getClass($selectedClassId) : null;
// Ensure the class belongs to this school (prevents cross-school data leakage)
if ($selectedClass && (int)($selectedClass['school_id'] ?? 0) !== (int)($schoolInfo['id'] ?? 0)) {
    $selectedClass  = null;
    $selectedClassId = null;
}

// Check if this class level has multiple streams
$hasMultipleStreams = false;
$allStreamsForClass = [];
if ($selectedClass && isset($selectedClass['class_name']) && !empty($selectedClass['class_name']) && $schoolInfo) {
    $stmt = $db->prepare("SELECT id, stream FROM classes 
                          WHERE class_name = ? AND school_id = ? AND stream IS NOT NULL 
                          ORDER BY stream");
    $stmt->execute([$selectedClass['class_name'], $schoolInfo['id']]);
    $allStreamsForClass = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $hasMultipleStreams = count($allStreamsForClass) > 1;
}

// Get students and subjects
$students = $selectedClassId ? $studentController->getStudentsByClass($selectedClassId) : [];
$subjects = [];
if ($selectedClassId && $schoolInfo) {
    // Get subjects with school_id verification to prevent cross-school access
    $stmt = $db->prepare("SELECT s.* FROM subjects s 
                         JOIN classes c ON s.class_id = c.id 
                         WHERE s.class_id = ? AND c.school_id = ? 
                         ORDER BY s.subject_name");
    $stmt->execute([$selectedClassId, $schoolInfo['id']]);
    $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Check if this is a JHS class (Basic 7, 8, 9)
$isJHS = $selectedClass && in_array($selectedClass['class_name'], ['Basic Seven', 'Basic Eight', 'Basic Nine']);

// Check if mock examination is enabled and handle Basic 9 stream combination
$isMockBasic9Combined = false;
if ($selectedClass && $selectedClass['class_name'] === 'Basic Nine' && $schoolInfo) {
    try {
        $mockStmt = $db->prepare("SELECT is_enabled FROM mock_exam_settings WHERE school_id = ?");
        $mockStmt->execute([$schoolInfo['id']]);
        $mockSettings = $mockStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($mockSettings && $mockSettings['is_enabled'] == 1) {
            // Get all Basic 9 class IDs for this school
            $stmt = $db->prepare("SELECT id FROM classes WHERE class_name = 'Basic Nine' AND school_id = ?");
            $stmt->execute([$schoolInfo['id']]);
            $basic9Classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (count($basic9Classes) > 0) {
                $basic9Ids = array_column($basic9Classes, 'id');
                $placeholders = str_repeat('?,', count($basic9Ids) - 1) . '?';
                
                // Fetch all Basic 9 students from all streams
                $stmt = $db->prepare("SELECT s.*, c.stream FROM students s 
                                      LEFT JOIN classes c ON s.class_id = c.id 
                                      WHERE s.class_id IN ($placeholders) 
                                      ORDER BY s.student_name");
                $stmt->execute($basic9Ids);
                $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Get all subjects from all Basic 9 classes (use DISTINCT to avoid duplicates)
                $stmt = $db->prepare("SELECT DISTINCT s.id, s.subject_name, s.class_id 
                                     FROM subjects s 
                                     JOIN classes c ON s.class_id = c.id 
                                     WHERE s.class_id IN ($placeholders) AND c.school_id = ? 
                                     ORDER BY s.subject_name");
                $stmt->execute(array_merge($basic9Ids, [$schoolInfo['id']]));
                $allSubjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Use the first stream's subjects as the base (they should all be similar)
                if (!empty($allSubjects)) {
                    $subjects = $allSubjects;
                }
                
                // Set flag to show stream column
                $isMockBasic9Combined = true;
            }
        }
    } catch (Exception $e) {
        error_log("Mock exam check error: " . $e->getMessage());
        // Continue with regular student/subject list if there's an error
    }
}

// Calculate aggregate for JHS students using dynamic grading system
function calculateStudentAggregate($studentScores, $subjects, $gradingSystemData) {
    $coreSubjects = ['English Language', 'Mathematics', 'Integrated Science', 'Social Studies'];
    $coreGrades = [];
    $otherGrades = [];
    
    foreach ($subjects as $subject) {
        $subjectName = $subject['subject_name'];
        if (isset($studentScores[$subject['id']]) && $studentScores[$subject['id']]['score'] > 0) {
            $score = $studentScores[$subject['id']]['score'];
            
            // Convert score to grade using database grading system
            $grade = 9; // Default to lowest grade
            foreach ($gradingSystemData as $gradeData) {
                if ($score >= $gradeData['min_score'] && $score <= $gradeData['max_score']) {
                    // Convert grade string to integer (e.g., "1" -> 1, "2" -> 2)
                    $grade = (int)$gradeData['grade'];
                    break;
                }
            }
            
            if (in_array($subjectName, $coreSubjects)) {
                $coreGrades[$subjectName] = $grade;
            } else {
                $otherGrades[$subjectName] = $grade;
            }
        }
    }
    
    // Must have all 4 core subjects to calculate valid aggregate
    if (count($coreGrades) < 4) {
        return null;
    }
    
    // Sort other subjects by grade (ascending - lower is better)
    asort($otherGrades);
    
    // Take best 2 other subjects - must have at least 2
    $bestOthers = array_slice($otherGrades, 0, 2, true);
    if (count($bestOthers) < 2) {
        return null;
    }
    
    // Calculate aggregate
    $aggregate = array_sum($coreGrades) + array_sum($bestOthers);
    
    return [
        'aggregate' => $aggregate,
        'core_grades' => $coreGrades,
        'best_others' => $bestOthers
    ];
}

// Build broadsheet data
$broadsheetData = [];
foreach ($students as $student) {
    $studentData = [
        'student' => $student,
        'stream' => $student['stream'] ?? null, // Include stream for mock Basic 9
        'subjects' => [],
        'total' => 0,
        'average' => 0,
        'position' => 0,
        'aggregate' => null
    ];
    
    foreach ($subjects as $subject) {
        $score = $scoreController->getScore($student['id'], $subject['id']);
        $totalScore = $score['total_score'] ?? 0;
        $studentData['subjects'][$subject['id']] = [
            'score' => $totalScore,
            'grade' => ''
        ];
        
        // Calculate grade using dynamic grading system from database
        foreach ($gradingSystemData as $gradeData) {
            if ($totalScore >= $gradeData['min_score'] && $totalScore <= $gradeData['max_score']) {
                $studentData['subjects'][$subject['id']]['grade'] = $gradeData['grade'];
                break;
            }
        }
        
        $studentData['total'] += $totalScore;
    }
    
    $studentData['average'] = count($subjects) > 0 ? $studentData['total'] / count($subjects) : 0;
    
    // Calculate aggregate for JHS students
    if ($isJHS) {
        $aggregateData = calculateStudentAggregate($studentData['subjects'], $subjects, $gradingSystemData);
        $studentData['aggregate'] = $aggregateData['aggregate'];
    }
    
    $broadsheetData[] = $studentData;
}

// Sort by total score descending
usort($broadsheetData, function($a, $b) {
    return $b['total'] <=> $a['total'];
});

// Calculate positions with proper tie handling
$position = 1;
$currentRank = 1;
$previousTotal = null;
foreach ($broadsheetData as &$data) {
    if ($previousTotal !== null && $data['total'] < $previousTotal) {
        $position = $currentRank;
    }
    $data['position'] = $position;
    $previousTotal = $data['total'];
    $currentRank++;
}
unset($data);

// Build cross-stream comparison data if multiple streams exist
$crossStreamData = [];
if ($hasMultipleStreams && $selectedClass && !empty($allStreamsForClass)) {
    // Get all students from all streams of this class level
    $allStreamIds = array_column($allStreamsForClass, 'id');
    
    // Skip if no stream IDs found
    if (!empty($allStreamIds)) {
        $placeholders = str_repeat('?,', count($allStreamIds) - 1) . '?';
    
        $stmt = $db->prepare("SELECT s.*, c.stream, c.id as class_id 
                              FROM students s 
                              JOIN classes c ON s.class_id = c.id 
                              WHERE s.class_id IN ($placeholders) AND c.school_id = ? 
                              ORDER BY c.stream, s.student_name");
        $stmt->execute(array_merge($allStreamIds, [$schoolInfo['id']]));
        $allStudents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
        // Build a unified subject list by name (to handle different subject IDs across streams)
        $unifiedSubjects = [];
        foreach ($allStreamsForClass as $streamClass) {
            $stmt = $db->prepare("SELECT s.* FROM subjects s 
                                 JOIN classes c ON s.class_id = c.id 
                                 WHERE s.class_id = ? AND c.school_id = ? 
                                 ORDER BY s.subject_name");
            $stmt->execute([$streamClass['id'], $schoolInfo['id']]);
            $streamSubjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($streamSubjects as $subject) {
                if (!isset($unifiedSubjects[$subject['subject_name']])) {
                    $unifiedSubjects[$subject['subject_name']] = $subject;
                }
            }
        }
        $unifiedSubjects = array_values($unifiedSubjects);
    
        // Get subjects for each stream (they should be similar)
        foreach ($allStudents as $student) {
        $studentData = [
            'student' => $student,
            'stream' => $student['stream'],
            'subjects' => [],
            'total' => 0,
            'average' => 0,
            'position' => 0,
            'aggregate' => null
        ];
        
        // Get subjects for this student's class
        $stmt = $db->prepare("SELECT s.* FROM subjects s 
                             JOIN classes c ON s.class_id = c.id 
                             WHERE s.class_id = ? AND c.school_id = ? 
                             ORDER BY s.subject_name");
        $stmt->execute([$student['class_id'], $schoolInfo['id']]);
        $studentSubjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Index student's subjects by name for easy lookup
        $subjectsByName = [];
        foreach ($studentSubjects as $subject) {
            $subjectsByName[$subject['subject_name']] = $subject;
        }
        
        // Process each unified subject
        foreach ($unifiedSubjects as $unifiedSubject) {
            $subjectName = $unifiedSubject['subject_name'];
            $totalScore = 0;
            $grade = '';
            
            // Find this subject in the student's subjects
            if (isset($subjectsByName[$subjectName])) {
                $subject = $subjectsByName[$subjectName];
                $score = $scoreController->getScore($student['id'], $subject['id']);
                $totalScore = $score['total_score'] ?? 0;
                
                // Calculate grade using dynamic grading system from database
                foreach ($gradingSystemData as $gradeData) {
                    if ($totalScore >= $gradeData['min_score'] && $totalScore <= $gradeData['max_score']) {
                        $grade = $gradeData['grade'];
                        break;
                    }
                }
            }
            
            // Store score indexed by unified subject ID
            $studentData['subjects'][$unifiedSubject['id']] = [
                'score' => $totalScore,
                'grade' => $grade
            ];
            
            $studentData['total'] += $totalScore;
        }
        
        $studentData['average'] = count($unifiedSubjects) > 0 ? $studentData['total'] / count($unifiedSubjects) : 0;
        
        // Calculate aggregate for JHS students
        if ($isJHS) {
            // Build subjects array in the format expected by calculateStudentAggregate
            $aggregateSubjects = [];
            foreach ($unifiedSubjects as $unifiedSubject) {
                $aggregateSubjects[] = $unifiedSubject;
            }
            $aggregateData = calculateStudentAggregate($studentData['subjects'], $aggregateSubjects, $gradingSystemData);
            $studentData['aggregate'] = $aggregateData['aggregate'];
        }
        
        $crossStreamData[] = $studentData;
    }
    
    // Sort by total score descending for cross-stream ranking
    usort($crossStreamData, function($a, $b) {
        return $b['total'] <=> $a['total'];
    });
    
    // Calculate cross-stream positions with proper tie handling
    $position = 1;
    $currentRank = 1;
    $previousTotal = null;
    foreach ($crossStreamData as &$data) {
        if ($previousTotal !== null && $data['total'] < $previousTotal) {
            $position = $currentRank;
        }
        $data['position'] = $position;
        $previousTotal = $data['total'];
        $currentRank++;
    }
    unset($data);
    } // End if (!empty($allStreamIds))
}

$pageTitle = 'Class Broadsheet';
require_once __DIR__ . '/components/header.php';
?>
    <style>
        .container {
            max-width: 100%;
            margin: 2rem auto;
            padding: 0 2rem;
        }
        
        .controls {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        .class-selector {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .class-selector label {
            font-weight: 600;
        }
        
        .class-selector select {
            padding: 0.6rem 1rem;
            border: 2px solid #e1e8ed;
            border-radius: 5px;
            font-size: 1rem;
        }
        
        .class-selector select.view-toggle {
            background: #667eea;
            color: white;
            border-color: #667eea;
            font-weight: 600;
        }
        
        .btn {
            padding: 0.6rem 1.5rem;
            border: none;
            border-radius: 5px;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s;
            font-weight: 600;
            background: #667eea;
            color: white;
        }
        
        .btn:hover {
            background: #5568d3;
        }
        
        .broadsheet-wrapper {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            overflow-x: auto;
        }
        
        .broadsheet-info {
            padding: 1.5rem;
            border-bottom: 2px solid #e2e8f0;
            text-align: center;
        }
        
        .broadsheet-info h2 {
            color: #2d3748;
            margin-bottom: 0.5rem;
        }
        
        .broadsheet-info p {
            color: #718096;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }
        
        thead {
            background: #f7fafc;
        }
        
        th {
            padding: 0.8rem 0.5rem;
            text-align: center;
            font-weight: 600;
            color: #2d3748;
            border: 1px solid #e2e8f0;
            white-space: nowrap;
        }
        
        td {
            padding: 0.6rem 0.5rem;
            border: 1px solid #e2e8f0;
            text-align: center;
        }
        
        tbody tr:hover {
            background: #f7fafc;
        }
        
        .student-name {
            text-align: left;
            font-weight: 500;
        }
        
        .total-col {
            background: #edf2f7;
            font-weight: 600;
        }
        
        .position-col {
            background: #feebc8;
            font-weight: 600;
            color: #c05621;
        }
        
        .subject-header {
            writing-mode: vertical-rl;
            text-orientation: mixed;
            min-height: 120px;
            padding: 0.5rem;
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #718096;
        }
        
        @media (max-width: 768px) {
            .container {
                margin: 1rem auto;
                padding: 0 1rem;
            }
            
            .controls {
                flex-direction: column;
                align-items: stretch;
                gap: 1rem;
            }
            
            .class-selector, .btn {
                width: 100%;
            }
            
            .btn {
                padding: 0.75rem;
                font-size: 1rem;
            }
            
            .broadsheet-wrapper {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                margin: 0 -1rem;
                padding: 0 1rem;
            }
            
            table {
                font-size: 0.7rem;
            }
            
            th, td {
                padding: 0.4rem 0.3rem !important;
            }
            
            .subject-header {
                min-height: 80px;
                font-size: 0.7rem;
            }
            
            table th:first-child,
            table td:first-child {
                position: sticky;
                left: 0;
                background: white;
                z-index: 2;
                box-shadow: 2px 0 5px rgba(0,0,0,0.1);
            }
            
            table thead th:first-child {
                background: #f8f9fa;
                z-index: 3;
            }
        }
        
        @media print {
            .header, .controls, .nav-links {
                display: none;
            }
            
            body {
                background: white;
            }
            
            .container {
                padding: 0;
                margin: 0;
            }
            
            .broadsheet-wrapper {
                box-shadow: none;
            }
        }
    </style>

    <div class="container">
        <div class="controls">
            <div class="class-selector">
                <label>Select Class:</label>
                <select id="classSelect" onchange="window.location.href='broadsheet.php?class=' + this.value + '&view=<?php echo htmlspecialchars($_GET['view'] ?? 'stream'); ?>'">
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
            
            <?php if ($hasMultipleStreams): ?>
            <div class="class-selector">
                <label>View:</label>
                <select id="viewSelect" onchange="window.location.href='broadsheet.php?class=<?php echo $selectedClassId; ?>&view=' + this.value" class="view-toggle">
                    <option value="stream" <?php echo ($_GET['view'] ?? 'stream') == 'stream' ? 'selected' : ''; ?>>My Stream Only</option>
                    <option value="all" <?php echo ($_GET['view'] ?? 'stream') == 'all' ? 'selected' : ''; ?>>All Streams Combined</option>
                </select>
            </div>
            <?php endif; ?>
            
            <button class="btn" onclick="window.print()">Print Broadsheet</button>
        </div>
        
        <?php if (empty($students)): ?>
            <div class="broadsheet-wrapper">
                <div class="empty-state">
                    <h3>No Students Found</h3>
                    <p>Please add students to this class first.</p>
                </div>
            </div>
        <?php elseif (empty($subjects)): ?>
            <div class="broadsheet-wrapper">
                <div class="empty-state">
                    <h3>No Subjects Found</h3>
                    <p>Please configure subjects for this class.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="broadsheet-wrapper">
                <div class="broadsheet-info">
                    <h2><?php echo htmlspecialchars($schoolInfo['school_name'] ?? 'School Based Assessment System'); ?></h2>
                    <p>
                        <?php 
                            require_once __DIR__ . '/controllers/ClassController.php';
                            echo htmlspecialchars(ClassController::getDisplayName($selectedClass));
                        ?> - Broadsheet
                    </p>
                    <?php 
                    $currentView = $_GET['view'] ?? 'stream';
                    if ($hasMultipleStreams && $currentView == 'all'): 
                    ?>
                        <p style="font-size: 1rem; color: #007bff; font-weight: 600; margin-top: 10px;">
                            <i class="fas fa-trophy"></i> All Streams Combined - 
                            <?php 
                            $streamNames = array_column($allStreamsForClass, 'stream');
                            echo implode(', ', $streamNames);
                            ?>
                        </p>
                    <?php elseif (!empty($selectedClass['stream'])): ?>
                        <p style="font-size: 0.9rem; color: #666;">Stream: <?php echo htmlspecialchars($selectedClass['stream']); ?> | Rankings shown are for this stream only</p>
                    <?php if ($isMockBasic9Combined): ?>
                        <p style="font-size: 1rem; color: #e53e3e; font-weight: 600; margin-top: 10px;">
                            <i class="fas fa-exclamation-triangle"></i> MOCK EXAMINATION MODE - All Basic Nine Streams Combined
                        </p>
                    <?php endif; ?>
                    <?php endif; ?>
                    <p>Academic Year: <?php echo htmlspecialchars($schoolInfo['academic_year'] ?? date('Y')); ?></p>
                </div>
                
                <table>
                    <thead>
                        <tr>
                            <th rowspan="2">No.</th>
                            <?php 
                            $currentView = $_GET['view'] ?? 'stream';
                            if ($hasMultipleStreams && $currentView == 'all'): 
                            ?>
                            <th rowspan="2">Stream</th>
                            <?php endif; ?>
                            <th rowspan="2">Student ID</th>
                            <th rowspan="2">Student Name</th>
                            <th colspan="<?php 
                            $currentView = $_GET['view'] ?? 'stream';
                            $displaySubjects = ($hasMultipleStreams && $currentView == 'all' && isset($unifiedSubjects)) ? $unifiedSubjects : $subjects;
                            echo count($displaySubjects); 
                            ?>">Subjects</th>
                            <th rowspan="2">Total</th>
                            <th rowspan="2">Average</th>
                            <?php if ($isJHS): ?>
                                <th rowspan="2" style="background: #fef3c7;">Aggregate</th>
                            <?php endif; ?>
                            <th rowspan="2">Position</th>
                        </tr>
                        <tr>
                            <?php 
                            $currentView = $_GET['view'] ?? 'stream';
                            $displaySubjects = ($hasMultipleStreams && $currentView == 'all' && isset($unifiedSubjects)) ? $unifiedSubjects : $subjects;
                            foreach ($displaySubjects as $subject): 
                            ?>
                                <th class="subject-header"><?php echo htmlspecialchars($subject['subject_name']); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $currentView = $_GET['view'] ?? 'stream';
                        $displayData = ($hasMultipleStreams && $currentView == 'all') ? $crossStreamData : $broadsheetData;
                        // Use unified subjects for cross-stream view
                        $displaySubjects = ($hasMultipleStreams && $currentView == 'all' && isset($unifiedSubjects)) ? $unifiedSubjects : $subjects;
                        
                        foreach ($displayData as $index => $data): 
                            $student = $data['student'];
                        ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <?php if (($hasMultipleStreams && $currentView == 'all') || $isMockBasic9Combined): ?>
                                <td>
                                    <span style="background: #007bff; color: white; padding: 3px 10px; border-radius: 4px; font-size: 0.9rem; font-weight: 600;">
                                        <?php echo htmlspecialchars($data['stream'] ?? 'N/A'); ?>
                                    </span>
                                </td>
                                <?php endif; ?>
                                <td><?php echo htmlspecialchars($student['student_id']); ?></td>
                                <td class="student-name"><?php echo htmlspecialchars($student['student_name']); ?></td>
                                <?php foreach ($displaySubjects as $subject): ?>
                                    <td>
                                        <?php 
                                        if (isset($data['subjects'][$subject['id']])) {
                                            $subjectData = $data['subjects'][$subject['id']];
                                            echo number_format($subjectData['score'], 0);
                                            if ($subjectData['grade']) {
                                                echo '<br><small>(' . $subjectData['grade'] . ')</small>';
                                            }
                                        } else {
                                            echo '-';
                                        }
                                        ?>
                                    </td>
                                <?php endforeach; ?>
                                <td class="total-col"><?php echo number_format($data['total'], 1); ?></td>
                                <td><?php echo number_format($data['average'], 1); ?></td>
                                <?php if ($isJHS): ?>
                                    <td style="background: #fef3c7; font-weight: 700; color: #92400e;">
                                        <?php echo $data['aggregate'] ?? 'N/A'; ?>
                                    </td>
                                <?php endif; ?>
                                <td class="position-col"><?php echo $data['position']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
<?php require_once __DIR__ . '/components/footer.php'; ?>
