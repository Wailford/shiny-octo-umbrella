<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/controllers/AuthController.php';
require_once __DIR__ . '/controllers/StudentController.php';
require_once __DIR__ . '/controllers/ClassController.php';
require_once __DIR__ . '/controllers/ScoreController.php';

$auth = new Auth();
$auth->requireLogin();

$currentUser = $auth->getCurrentUser();
$isAdmin = $auth->isAdmin();

// Get user role and check access
$userRole = $_SESSION['role'] ?? $_SESSION['user_type'] ?? 'teacher';

// Get school type to determine access rules
require_once __DIR__ . '/config/database.php';
$db = Database::getInstance()->getConnection();
$schoolTypeCode = null;
$school_id = $_SESSION['school_id'] ?? null;

if ($school_id) {
    $stmt = $db->prepare("SELECT st.type_code FROM school_info si LEFT JOIN school_types st ON si.school_type_id = st.id WHERE si.id = ?");
    $stmt->execute([$school_id]);
    $schoolType = $stmt->fetch(PDO::FETCH_ASSOC);
    $schoolTypeCode = $schoolType['type_code'] ?? null;
}

$isPrimarySchool = ($schoolTypeCode === 'PRIMARY');

// Check if school has multiple streams
$hasMultipleStreams = false;
if ($school_id) {
    $streamStmt = $db->prepare("SELECT COUNT(DISTINCT stream) as stream_count FROM classes WHERE school_id = ? AND stream IS NOT NULL AND stream != ''");
    $streamStmt->execute([$school_id]);
    $streamCount = $streamStmt->fetch(PDO::FETCH_ASSOC);
    $hasMultipleStreams = ($streamCount['stream_count'] > 1);
}

// Access control based on school type
if ($isPrimarySchool) {
    // Primary: Only assigned class teachers (form_masters) and admin can submit scores
    if (!$isAdmin) {
        // Check if user has form master assignment
        $stmt = $db->prepare("SELECT fm.class_id FROM form_masters fm JOIN classes c ON fm.class_id = c.id WHERE fm.user_id = ? AND c.school_id = ?");
        $stmt->execute([$_SESSION['user_id'], $school_id]);
        $assignedClass = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$assignedClass) {
            header('Location: dashboard.php?error=not_assigned_class_teacher');
            exit;
        }
    }
}
// NO ACCESS BLOCKING FOR JHS - Form masters can access their class, subject teachers can access their subjects

// Get subject teacher's assigned subjects and classes
$assignedSubjects = [];
$assignedClassIds = [];

// Check if user has subject assignments (not just role)
$stmt = $db->prepare("SELECT COUNT(*) as count FROM subject_teachers st JOIN classes c ON st.class_id = c.id WHERE st.user_id = ? AND c.school_id = ?");
$stmt->execute([$_SESSION['user_id'], $school_id]);
$hasSubjectAssignments = $stmt->fetch(PDO::FETCH_ASSOC)['count'] > 0;

// Check if user is a form master
$formMasterClassId = null;
$stmt = $db->prepare("SELECT fm.class_id FROM form_masters fm JOIN classes c ON fm.class_id = c.id WHERE fm.user_id = ? AND c.school_id = ?");
$stmt->execute([$_SESSION['user_id'], $school_id]);
$formMasterData = $stmt->fetch(PDO::FETCH_ASSOC);
$hasFormMasterAssignment = false;
if ($formMasterData) {
    $formMasterClassId = $formMasterData['class_id'];
    $hasFormMasterAssignment = true;
}

// Determine user type based on actual assignments
$isSubjectTeacher = ($hasSubjectAssignments && !$isAdmin);
$isClassTeacher = ($hasFormMasterAssignment && !$hasSubjectAssignments && !$isAdmin);

// For primary school class teachers - they can access ALL subjects in their class
if ($isPrimarySchool && $isClassTeacher) {
    if ($formMasterClassId) {
        $assignedClassIds[] = $formMasterClassId;
        
        // Get ALL subjects for this class
        $stmt = $db->prepare("
            SELECT s.id as subject_id, s.subject_name
            FROM subjects s
            JOIN classes c ON s.class_id = c.id
            WHERE s.class_id = ? AND c.school_id = ?
            ORDER BY s.subject_name
        ");
        $stmt->execute([$formMasterClassId, $school_id]);
        $classSubjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($classSubjects as $subject) {
            $assignedSubjects[$subject['subject_id']] = $subject['subject_name'];
        }
    }
}

// For form masters who are NOT subject teachers (JHS/Basic schools)
// They can access ALL subjects in their assigned class
if (!$isPrimarySchool && $formMasterClassId && !$hasSubjectAssignments && !$isAdmin) {
    $stmt = $db->prepare("
        SELECT s.id as subject_id, s.class_id, s.subject_name, c.class_name, c.stream
        FROM subjects s
        JOIN classes c ON s.class_id = c.id
        WHERE s.class_id = ? AND c.school_id = ?
        ORDER BY s.subject_name
    ");
    $stmt->execute([$formMasterClassId, $school_id]);
    $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($assignments as $assignment) {
        $assignedSubjects[$assignment['subject_id']] = $assignment['subject_name'];
        if (!in_array($assignment['class_id'], $assignedClassIds)) {
            $assignedClassIds[] = $assignment['class_id'];
        }
    }
}

// For JHS subject teachers
if ($isSubjectTeacher) {
    $stmt = $db->prepare("
        SELECT DISTINCT st.subject_id, st.class_id, s.subject_name, c.class_name, c.stream
        FROM subject_teachers st
        JOIN subjects s ON st.subject_id = s.id
        JOIN classes c ON st.class_id = c.id
        WHERE st.user_id = ? AND c.school_id = ?
        ORDER BY s.subject_name, c.class_name, c.stream
    ");
    $stmt->execute([$_SESSION['user_id'], $school_id]);
    $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // If user is also a form master, add ALL subjects from their form class
    $formMasterStmt = $db->prepare("SELECT fm.class_id FROM form_masters fm JOIN classes c ON fm.class_id = c.id WHERE fm.user_id = ? AND c.school_id = ?");
    $formMasterStmt->execute([$_SESSION['user_id'], $school_id]);
    $formMasterClass = $formMasterStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($formMasterClass) {
        // Get all subjects for the form master's class
        $allSubjectsStmt = $db->prepare("
            SELECT s.id as subject_id, s.class_id, s.subject_name, c.class_name, c.stream
            FROM subjects s
            JOIN classes c ON s.class_id = c.id
            WHERE s.class_id = ? AND c.school_id = ?
            ORDER BY s.subject_name
        ");
        $allSubjectsStmt->execute([$formMasterClass['class_id'], $school_id]);
        $formClassSubjects = $allSubjectsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Merge with existing assignments (avoid duplicates)
        $existingSubjectIds = array_column($assignments, 'subject_id');
        foreach ($formClassSubjects as $formSubject) {
            if (!in_array($formSubject['subject_id'], $existingSubjectIds)) {
                $assignments[] = $formSubject;
            }
        }
    }
    
    // Group by subject for easier access
    $subjectAssignments = [];
    foreach ($assignments as $assignment) {
        $subjectId = $assignment['subject_id'];
        if (!isset($subjectAssignments[$subjectId])) {
            $subjectAssignments[$subjectId] = [
                'subject_name' => $assignment['subject_name'],
                'classes' => []
            ];
        }
        $subjectAssignments[$subjectId]['classes'][] = [
            'class_id' => $assignment['class_id'],
            'class_name' => $assignment['class_name'],
            'stream' => $assignment['stream']
        ];
        
        $assignedSubjects[$assignment['subject_id']] = $assignment['subject_name'];
        if (!in_array($assignment['class_id'], $assignedClassIds)) {
            $assignedClassIds[] = $assignment['class_id'];
        }
    }
    
    // Store subject assignments in session for easy access
    $_SESSION['subject_assignments'] = $subjectAssignments;
}

// Get school info
require_once __DIR__ . '/config/database.php';
$db = Database::getInstance()->getConnection();
$schoolInfo = null;
if ($school_id) {
    $stmt = $db->prepare("SELECT * FROM school_info WHERE id = ?");
    $stmt->execute([$school_id]);
    $schoolInfo = $stmt->fetch(PDO::FETCH_ASSOC);
}

$studentController = new StudentController();
$classController = new ClassController();
$scoreController = new ScoreController();

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    if ($_POST['action'] === 'save_score') {
        // Check if this is a mock exam score
        if (isset($_POST['is_mock']) && $_POST['is_mock'] == '1') {
            // Save to mock_exam_scores table
            try {
                $studentId = $_POST['student_id'];
                $subjectId = $_POST['subject_id'];
                $mockScore = $_POST['mock_score'] ?? 0;
                $absentReason = $_POST['absent_reason'] ?? null;
                
                // Get student's class_id and candidate_index_number
                $studentStmt = $db->prepare("SELECT s.class_id, s.candidate_index_number FROM students s JOIN classes c ON s.class_id = c.id WHERE s.id = ? AND c.school_id = ?");
                $studentStmt->execute([$studentId, $school_id]);
                $studentInfo = $studentStmt->fetch(PDO::FETCH_ASSOC);
                if (!$studentInfo) {
                    echo json_encode(['success' => false, 'message' => 'Unauthorized student access']);
                    exit;
                }
                $classId = $studentInfo['class_id'];
                $candidateIndexNumber = $studentInfo['candidate_index_number'] ?? '';
                
                // Get school_id
                $school_id = $_SESSION['school_id'] ?? null;
                $schoolStmt = $db->prepare("SELECT id FROM school_info WHERE id = ?");
                $schoolStmt->execute([$school_id]);
                $schoolData = $schoolStmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$schoolData) {
                    echo json_encode(['success' => false, 'message' => 'School not found']);
                    exit;
                }
                
                $schoolId = $schoolData['id'];

                // Verify subject belongs to this school
                $subjCheck = $db->prepare("SELECT s.id FROM subjects s JOIN classes c ON s.class_id = c.id WHERE s.id = ? AND c.school_id = ?");
                $subjCheck->execute([$subjectId, $schoolId]);
                if (!$subjCheck->fetch()) {
                    echo json_encode(['success' => false, 'message' => 'Unauthorized: subject does not belong to your school']);
                    exit;
                }
                
                // Get current mock exam settings
                $settingsStmt = $db->prepare("SELECT academic_year, term FROM mock_exam_settings WHERE school_id = ? AND is_enabled = 1 LIMIT 1");
                $settingsStmt->execute([$schoolId]);
                $settings = $settingsStmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$settings) {
                    echo json_encode(['success' => false, 'message' => 'Mock exam not enabled']);
                    exit;
                }
                
                // Calculate grade (1-9 BECE scale) via centralized helper
                $mockGi = GradingSystem::getMockGradeForScore($mockScore);
                $grade  = $mockGi['grade'];
                $remark = $mockGi['remarks'];
                
                // Check if record exists
                $checkStmt = $db->prepare("SELECT id FROM mock_exam_scores 
                                          WHERE school_id = ? AND student_id = ? AND subject_id = ? 
                                          AND academic_year = ? AND term = ?");
                $checkStmt->execute([$schoolId, $studentId, $subjectId, $settings['academic_year'], $settings['term']]);
                $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($existing) {
                    // Update existing record with remark
                    $updateStmt = $db->prepare("UPDATE mock_exam_scores SET score = ?, grade = ?, remark = ? 
                                                WHERE id = ?");
                    $updateStmt->execute([$mockScore, $grade, $remark, $existing['id']]);
                } else {
                    // Insert new record with remark
                    $insertStmt = $db->prepare("INSERT INTO mock_exam_scores 
                                                (school_id, student_id, subject_id, class_id, candidate_index_number, score, grade, remark, academic_year, term, entered_by) 
                                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $insertStmt->execute([$schoolId, $studentId, $subjectId, $classId, $candidateIndexNumber, $mockScore, $grade, $remark, $settings['academic_year'], $settings['term'], $_SESSION['user_id']]);
                }
                
                // Handle absenteeism tracking (only save once per student per academic year/term)
                if (!empty($absentReason) && $absentReason !== 'PRESENT') {
                    // Check if absenteeism record exists for this student
                    $checkAbsent = $db->prepare("SELECT id FROM mock_exam_absenteeism 
                                                WHERE school_id = ? AND student_id = ? AND academic_year = ? AND term = ?");
                    $checkAbsent->execute([$schoolId, $studentId, $settings['academic_year'], $settings['term']]);
                    $existingAbsent = $checkAbsent->fetch(PDO::FETCH_ASSOC);
                    
                    if ($existingAbsent) {
                        // Update absenteeism reason
                        $updateAbsent = $db->prepare("UPDATE mock_exam_absenteeism SET reason = ? WHERE id = ?");
                        $updateAbsent->execute([$absentReason, $existingAbsent['id']]);
                    } else {
                        // Insert new absenteeism record
                        $insertAbsent = $db->prepare("INSERT INTO mock_exam_absenteeism 
                                                    (school_id, student_id, academic_year, term, reason, entered_by) 
                                                    VALUES (?, ?, ?, ?, ?, ?)");
                        $insertAbsent->execute([$schoolId, $studentId, $settings['academic_year'], $settings['term'], $absentReason, $_SESSION['user_id']]);
                    }
                } elseif (empty($absentReason) || $absentReason === 'PRESENT') {
                    // Remove absenteeism record if student is marked present
                    $deleteAbsent = $db->prepare("DELETE FROM mock_exam_absenteeism 
                                                WHERE school_id = ? AND student_id = ? AND academic_year = ? AND term = ?");
                    $deleteAbsent->execute([$schoolId, $studentId, $settings['academic_year'], $settings['term']]);
                }
                
                echo json_encode(['success' => true, 'message' => 'Mock exam score saved']);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            }
        } else {
            // Regular SBA score
            $result = $scoreController->submitScore($_POST);
            echo json_encode($result);
        }
        exit;
    } elseif ($_POST['action'] === 'delete_all_scores') {
        try {
            $subjectId = (int)($_POST['subject_id'] ?? 0);
            $classId   = (int)($_POST['class_id']   ?? 0);
            $isMock = $_POST['is_mock'] ?? '0';
            
            if (!$subjectId || !$classId) {
                echo json_encode(['success' => false, 'message' => 'Missing subject or class ID']);
                exit;
            }
            
            // Get school_id
            $school_id = $_SESSION['school_id'] ?? null;
            $schoolStmt = $db->prepare("SELECT id FROM school_info WHERE id = ?");
            $schoolStmt->execute([$school_id]);
            $schoolData = $schoolStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$schoolData) {
                echo json_encode(['success' => false, 'message' => 'School not found']);
                exit;
            }
            
            $schoolId = $schoolData['id'];

            // Verify subject belongs to this school
            $subjCheck = $db->prepare("SELECT s.id FROM subjects s JOIN classes c ON s.class_id = c.id WHERE s.id = ? AND c.school_id = ?");
            $subjCheck->execute([$subjectId, $schoolId]);
            if (!$subjCheck->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Unauthorized: subject does not belong to your school']);
                exit;
            }
            $deleted = 0;
            
            if ($isMock == '1') {
                // Delete from mock_exam_scores table
                $stmt = $db->prepare("DELETE mes FROM mock_exam_scores mes JOIN students s ON mes.student_id = s.id JOIN classes c ON s.class_id = c.id WHERE mes.school_id = ? AND mes.subject_id = ? AND s.class_id = ? AND c.school_id = ?");
                if ($stmt->execute([$schoolId, $subjectId, $classId, $schoolId])) {
                    $deleted = $stmt->rowCount();
                }
            } else {
                // Get all students in the class
                $stmt = $db->prepare("SELECT s.id FROM students s JOIN classes c ON s.class_id = c.id WHERE s.class_id = ? AND c.school_id = ?");
                $stmt->execute([$classId, $schoolId]);
                $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($students as $student) {
                    $stmt = $db->prepare("DELETE FROM scores WHERE student_id = ? AND subject_id = ?");
                    if ($stmt->execute([$student['id'], $subjectId])) {
                        $deleted++;
                    }
                }
            }
            
            echo json_encode(['success' => true, 'message' => "Deleted $deleted score records"]);

            // ── Audit trail (requires database/add_audit_log.sql migration) ────
            try {
                $auditStmt = $db->prepare(
                    "INSERT INTO audit_log (school_id, user_id, action, target_type, target_id, details, ip_address)
                     VALUES (?, ?, 'delete_all_scores', 'subject', ?, ?, ?)"
                );
                $details = json_encode([
                    'class_id'     => $classId,
                    'deleted_rows' => $deleted,
                    'is_mock'      => $isMock,
                    'academic_year'=> $_SESSION['academic_year'] ?? null,
                    'term'         => $_SESSION['current_term']  ?? null,
                ]);
                $auditStmt->execute([
                    $schoolId,
                    $_SESSION['user_id'] ?? 0,
                    $subjectId,
                    $details,
                    $_SERVER['REMOTE_ADDR'] ?? null,
                ]);
            } catch (Exception $auditEx) {
                error_log('audit_log write failed: ' . $auditEx->getMessage());
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // ── Batch save all scores for a class subject in one atomic transaction ──
    if ($_POST['action'] === 'batch_save_scores') {
        try {
            $payload   = json_decode($_POST['payload'] ?? '{}', true);
            $subjectId = (int)($payload['subject_id'] ?? 0);
            $isMock    = ($payload['is_mock'] ?? '0') === '1';
            $scoresArr = $payload['scores'] ?? [];

            if (!$subjectId || empty($scoresArr)) {
                echo json_encode(['success' => false, 'message' => 'Missing subject_id or scores']);
                exit;
            }

            // Verify subject belongs to this school
            $school_id = $_SESSION['school_id'] ?? null;
            $subjCheck = $db->prepare("SELECT s.id FROM subjects s JOIN classes c ON s.class_id = c.id WHERE s.id = ? AND c.school_id = ?");
            $subjCheck->execute([$subjectId, $school_id]);
            if (!$subjCheck->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Unauthorized: subject does not belong to your school']);
                exit;
            }

            $saved  = 0;
            $failed = 0;
            $errors = [];

            if ($isMock) {
                // ── Mock exam batch save ─────────────────────────────────────
                $schoolStmt = $db->prepare("SELECT id FROM school_info WHERE id = ?");
                $schoolStmt->execute([$school_id]);
                $schoolData = $schoolStmt->fetch(PDO::FETCH_ASSOC);
                $schoolId   = $schoolData ? $schoolData['id'] : $school_id;

                $settingsStmt = $db->prepare("SELECT academic_year, term FROM mock_exam_settings WHERE school_id = ? AND is_enabled = 1 LIMIT 1");
                $settingsStmt->execute([$schoolId]);
                $mockSettings = $settingsStmt->fetch(PDO::FETCH_ASSOC);
                if (!$mockSettings) {
                    echo json_encode(['success' => false, 'message' => 'Mock exam not enabled']);
                    exit;
                }

                $db->beginTransaction();
                foreach ($scoresArr as $entry) {
                    $studentId   = (int)($entry['student_id'] ?? 0);
                    $mockScore   = floatval($entry['mock_score'] ?? 0);
                    $absentReason = trim($entry['absent_reason'] ?? '');

                    // Verify student belongs to this school
                    $stuStmt = $db->prepare("SELECT s.class_id, s.candidate_index_number FROM students s JOIN classes c ON s.class_id = c.id WHERE s.id = ? AND c.school_id = ?");
                    $stuStmt->execute([$studentId, $schoolId]);
                    $stuInfo = $stuStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$stuInfo) { $failed++; $errors[] = "Student $studentId not found"; continue; }

                    $gi = GradingSystem::getMockGradeForScore($mockScore);
                    $checkStmt = $db->prepare("SELECT id FROM mock_exam_scores WHERE school_id = ? AND student_id = ? AND subject_id = ? AND academic_year = ? AND term = ?");
                    $checkStmt->execute([$schoolId, $studentId, $subjectId, $mockSettings['academic_year'], $mockSettings['term']]);
                    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
                    if ($existing) {
                        $db->prepare("UPDATE mock_exam_scores SET score = ?, grade = ?, remark = ? WHERE id = ?")->execute([$mockScore, $gi['grade'], $gi['remarks'], $existing['id']]);
                    } else {
                        $db->prepare("INSERT INTO mock_exam_scores (school_id,student_id,subject_id,class_id,candidate_index_number,score,grade,remark,academic_year,term,entered_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                           ->execute([$schoolId, $studentId, $subjectId, $stuInfo['class_id'], $stuInfo['candidate_index_number'] ?? '', $mockScore, $gi['grade'], $gi['remarks'], $mockSettings['academic_year'], $mockSettings['term'], $_SESSION['user_id'] ?? null]);
                    }
                    $saved++;
                }
                $db->commit();
            } else {
                // ── Regular SBA batch save ───────────────────────────────────
                $db->beginTransaction();
                foreach ($scoresArr as $entry) {
                    $entry['subject_id'] = $subjectId;
                    $result = $scoreController->submitScore($entry);
                    if ($result['success']) { $saved++; } else { $failed++; $errors[] = $result['error'] ?? 'Save failed'; }
                }
                $db->commit();
            }

            echo json_encode(['success' => true, 'saved' => $saved, 'failed' => $failed, 'errors' => $errors]);
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
}

// Get selected class (filtered by school type)
$schoolTypeId = $_SESSION['school_type_id'] ?? null;
$classes = $classController->getAllClasses($schoolTypeId);

// Filter classes for subject teachers (JHS)
if ($isSubjectTeacher && !empty($assignedClassIds)) {
    $classes = array_filter($classes, function($class) use ($assignedClassIds) {
        return in_array($class['id'], $assignedClassIds);
    });
    $classes = array_values($classes); // Re-index array
}

// Filter classes for class teachers (Primary)
if ($isClassTeacher && !empty($assignedClassIds)) {
    $classes = array_filter($classes, function($class) use ($assignedClassIds) {
        return in_array($class['id'], $assignedClassIds);
    });
    $classes = array_values($classes); // Re-index array
}

$selectedClassId = isset($_GET['class']) ? (int)$_GET['class'] : ($classes[0]['id'] ?? null);

// Check if user is a form master
$formMasterClassId = null;
if ($isSubjectTeacher) {
    $fmStmt = $db->prepare("SELECT fm.class_id FROM form_masters fm JOIN classes c ON fm.class_id = c.id WHERE fm.user_id = ? AND c.school_id = ?");
    $fmStmt->execute([$_SESSION['user_id'], $school_id]);
    $fmClass = $fmStmt->fetch(PDO::FETCH_ASSOC);
    if ($fmClass) {
        $formMasterClassId = $fmClass['class_id'];
    }
}

// Verify subject teacher has access to selected class
// Allow if: 1) In their assignedClassIds OR 2) Is their form master class
if ($isSubjectTeacher && $selectedClassId && !in_array($selectedClassId, $assignedClassIds)) {
    // Check if this is their form master class (they can monitor)
    if ($selectedClassId != $formMasterClassId) {
        $selectedClassId = $assignedClassIds[0] ?? null;
    }
}

// Verify class teacher has access to selected class (Primary)
if ($isClassTeacher && $selectedClassId && !in_array($selectedClassId, $assignedClassIds)) {
    $selectedClassId = $assignedClassIds[0] ?? null;
}

$selectedClass = $selectedClassId ? $classController->getClass($selectedClassId) : null;

// EARLY CHECK: Check if mock exam is enabled for Basic 9/JHS 3
$showMockExamInterface = false;
if ($selectedClass && $school_id) {
    // Get school type first
    $schoolInfoStmt = $db->prepare("SELECT school_type_id FROM school_info WHERE id = ?");
    $schoolInfoStmt->execute([$school_id]);
    $schoolInfoData = $schoolInfoStmt->fetch(PDO::FETCH_ASSOC);
    $school_type_id = $schoolInfoData['school_type_id'] ?? null;
    
    if (in_array($school_type_id, [2, 3])) { // JHS or Basic School
        $className = strtolower($selectedClass['class_name']);
        // Support multiple naming variations
        $isBasic9 = (strpos($className, 'basic nine') !== false || 
                     strpos($className, 'basic 9') !== false ||
                     strpos($className, 'basic9') !== false ||
                     strpos($className, 'jhs 3') !== false ||
                     strpos($className, 'jhs3') !== false);
        
        if ($isBasic9) {
            // Check if mock exam is enabled
            $mockCheckStmt = $db->prepare("SELECT is_enabled FROM mock_exam_settings WHERE school_id = ? AND is_enabled = 1 LIMIT 1");
            $mockCheckStmt->execute([$school_id]);
            
            if ($mockCheckStmt->fetch()) {
                // Mock exam is enabled - set flag to show mock interface
                $showMockExamInterface = true;
            }
        }
    }
}

// Get students - filter by school to prevent showing other schools' students
$students = [];
if ($selectedClassId && isset($schoolInfo['id'])) {
    $stmt = $db->prepare("SELECT s.*, c.class_name, c.class_code 
                          FROM students s
                          JOIN classes c ON s.class_id = c.id
                          WHERE s.class_id = ? AND c.school_id = ?
                          ORDER BY s.student_name");
    $stmt->execute([$selectedClassId, $schoolInfo['id']]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Check if mock exam is enabled and this is Basic 9 - combine all streams
    if ($selectedClass && $selectedClass['class_name'] === 'Basic Nine' && $showMockExamInterface) {
        try {
            // Get all Basic 9 class IDs for this school
            $stmt = $db->prepare("SELECT id FROM classes WHERE class_name = 'Basic Nine' AND school_id = ?");
            $stmt->execute([$schoolInfo['id']]);
            $basic9Classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (count($basic9Classes) > 0) {
                $basic9Ids = array_column($basic9Classes, 'id');
                $placeholders = str_repeat('?,', count($basic9Ids) - 1) . '?';
                
                // Fetch all Basic 9 students from all streams
                $stmt = $db->prepare("SELECT s.*, c.class_name, c.class_code, c.stream 
                                      FROM students s
                                      JOIN classes c ON s.class_id = c.id
                                      WHERE s.class_id IN ($placeholders) AND c.school_id = ?
                                      ORDER BY s.student_name");
                $stmt->execute(array_merge($basic9Ids, [$schoolInfo['id']]));
                $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (Exception $e) {
            error_log("Mock exam check error in scores.php: " . $e->getMessage());
        }
    }
}

// Get subjects for the class
$subjects = [];
if ($selectedClassId) {
    $stmt = $db->prepare("SELECT s.* FROM subjects s JOIN classes c ON s.class_id = c.id WHERE s.class_id = ? AND c.school_id = ? ORDER BY s.subject_name");
    $stmt->execute([$selectedClassId, $school_id]);
    $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Filter subjects for subject teachers (but NOT if they're viewing their form master class)
    if ($isSubjectTeacher && !empty($assignedSubjects)) {
        // Check if this is their form master class
        $isFormMasterOfThisClass = false;
        if ($formMasterClassId && $selectedClassId == $formMasterClassId) {
            $isFormMasterOfThisClass = true;
        }
        
        // Only filter if NOT viewing their form master class
        if (!$isFormMasterOfThisClass) {
            $subjects = array_filter($subjects, function($subject) use ($assignedSubjects) {
                return isset($assignedSubjects[$subject['id']]);
            });
            $subjects = array_values($subjects); // Re-index array
        }
    }
}

// Get selected subject
$selectedSubjectId = isset($_GET['subject']) ? (int)$_GET['subject'] : ($subjects[0]['id'] ?? null);
$selectedSubject = null;
if ($selectedSubjectId) {
    foreach ($subjects as $subject) {
        if ($subject['id'] == $selectedSubjectId) {
            $selectedSubject = $subject;
            break;
        }
    }
}

// Determine whether any scores exist for this selected subject in this class
$scoresExist = false;
if ($selectedClassId && $selectedSubjectId) {
    // Get current term from school_info
    $termStmt = $db->prepare("SELECT current_term, academic_year FROM school_info WHERE id = ?");
    $termStmt->execute([$school_id]);
    $termData = $termStmt->fetch(PDO::FETCH_ASSOC);
    $currentTerm = $termData['current_term'] ?? 'First Term';
    $currentYear = $termData['academic_year'] ?? '2024/2025';
    
    $cntStmt = $db->prepare("SELECT COUNT(*) as cnt FROM scores sc JOIN students st ON sc.student_id = st.id WHERE sc.subject_id = ? AND st.class_id = ? AND sc.term = ? AND sc.academic_year = ?");
    $cntStmt->execute([$selectedSubjectId, $selectedClassId, $currentTerm, $currentYear]);
    $scoresExist = (($cntStmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0) > 0);
}

// Get scores for selected class and subject
$scoresData = [];
if ($selectedClassId && $selectedSubjectId) {
    foreach ($students as $student) {
        // If mock exam is enabled for Basic 9, load from mock_exam_scores table
        if (isset($showMockExamInterface) && $showMockExamInterface) {
            // Get school_id
            $schoolStmt = $db->prepare("SELECT id FROM school_info WHERE id = ?");
            $schoolStmt->execute([$school_id]);
            $schoolData = $schoolStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($schoolData) {
                // Get mock exam settings to get current academic year/term
                $settingsStmt = $db->prepare("SELECT academic_year, term FROM mock_exam_settings WHERE school_id = ? AND is_enabled = 1 LIMIT 1");
                $settingsStmt->execute([$schoolData['id']]);
                $settings = $settingsStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($settings) {
                    // Load from mock_exam_scores table
                    $mockScoreStmt = $db->prepare("SELECT score, grade, remark FROM mock_exam_scores 
                                                   WHERE school_id = ? AND student_id = ? AND subject_id = ? 
                                                   AND academic_year = ? AND term = ?");
                    $mockScoreStmt->execute([$schoolData['id'], $student['id'], $selectedSubjectId, $settings['academic_year'], $settings['term']]);
                    $score = $mockScoreStmt->fetch(PDO::FETCH_ASSOC);
                    
                    // Load absenteeism reason
                    $absentStmt = $db->prepare("SELECT reason FROM mock_exam_absenteeism 
                                              WHERE school_id = ? AND student_id = ? AND academic_year = ? AND term = ?");
                    $absentStmt->execute([$schoolData['id'], $student['id'], $settings['academic_year'], $settings['term']]);
                    $absentData = $absentStmt->fetch(PDO::FETCH_ASSOC);
                    
                    $scoresData[] = [
                        'student' => $student,
                        'score' => $score ?: [],
                        'absent_reason' => $absentData['reason'] ?? ''
                    ];
                }
            }
        } else {
            // Regular SBA mode - load from scores table
            $score = $scoreController->getScore($student['id'], $selectedSubjectId);
            $scoresData[] = [
                'student' => $student,
                'score' => $score ?: []
            ];
        }
    }
}

$pageTitle = 'Score Entry';
require_once __DIR__ . '/components/header.php';
?>
    <style>
        html {
            scroll-behavior: smooth;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 1rem;
        }
        
        /* Mobile Responsive */
        @media (max-width: 768px) {
            .container { padding: 0.5rem; }
            .controls { padding: 0.75rem; }

            .selector-row {
                flex-direction: column;
                gap: 0.5rem !important;
                align-items: stretch !important;
            }
            .selector-group {
                width: 100%;
                flex-direction: column;
                align-items: stretch !important;
            }
            .selector-group select,
            .selector-group input {
                width: 100% !important;
                min-width: 100% !important;
                max-width: 100% !important;
                font-size: 16px !important;
                min-height: 48px;
            }
            .btn { width: 100%; padding: 0.85rem; font-size: 1rem; }
            .stats-container { grid-template-columns: repeat(2, 1fr) !important; gap: 0.5rem !important; }

            /* Score table: scrollable */
            .scores-table { overflow-x: auto; -webkit-overflow-scrolling: touch; }
            table { font-size: 0.8rem; }
            th, td { padding: 0.5rem 0.4rem; font-size: 0.8rem; }

            /* Score inputs — large touch targets */
            .score-input {
                width: 100% !important;
                min-width: 52px;
                padding: 0.5rem 0.25rem !important;
                font-size: 16px !important;
                min-height: 44px;
                text-align: center;
                border-radius: 6px;
            }

            /* Save button fixed at bottom on mobile */
            .save-btn-container {
                position: fixed;
                bottom: 0;
                left: 0;
                right: 0;
                background: white;
                padding: 0.75rem 1rem;
                box-shadow: 0 -2px 10px rgba(0,0,0,0.1);
                text-align: center;
                z-index: 100;
            }
            .save-btn-container .btn { width: 100%; max-width: 400px; padding: 1rem; font-size: 1rem; }

            h1 { font-size: 1.3rem; }
        }
        
        .controls {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 2rem;
        }
        
        .selector-row {
            display: flex;
            gap: 2rem;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .selector-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .selector-group label {
            font-weight: 600;
        }
        
        .selector-group select {
            padding: 0.6rem 1rem;
            border: 2px solid #e1e8ed;
            border-radius: 5px;
            font-size: 1rem;
            min-width: 200px;
        }
        
        .scores-table {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 820px;
        }
        
        thead {
            background: #f7fafc;
        }
        
        th {
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: #2d3748;
            border-bottom: 2px solid #e2e8f0;
            white-space: nowrap;
        }
        
        td {
            padding: 0.8rem;
            border-bottom: 1px solid #e2e8f0;
        }
        
        tbody tr:hover {
            background: #f7fafc;
        }
        
        .score-input {
            width: 60px;
            padding: 0.4rem 0.3rem;
            border: 2px solid #e1e8ed;
            border-radius: 5px;
            text-align: center;
        }
        
        .score-input:focus {
            border-color: #667eea;
            outline: none;
        }
        
        .total-score {
            font-weight: 600;
            color: #667eea;
        }
        
        .grade {
            font-weight: 600;
            padding: 0.3rem 0.6rem;
            border-radius: 5px;
            background: #edf2f7;
        }
        
        .btn {
            padding: 0.6rem 1.5rem;
            border: none;
            border-radius: 5px;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s;
            font-weight: 600;
        }
        
        .btn-success {
            background: #48bb78;
            color: white;
        }
        
        .btn-success:hover {
            background: #38a169;
        }
        
        .save-btn-container {
            margin-top: 1.5rem;
            text-align: right;
        }
        
        .alert {
            padding: 1rem;
            border-radius: 5px;
            margin-bottom: 1rem;
        }
        
        .alert-success {
            background: #c6f6d5;
            color: #22543d;
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #718096;
        }
        
    </style>

    <div class="container">
        <div class="controls">
            <?php if ($isSubjectTeacher && !empty($subjectAssignments)): 
                // Check if also form master
                $stmt = $db->prepare("SELECT fm.class_id, c.class_name, c.stream FROM form_masters fm JOIN classes c ON fm.class_id = c.id WHERE fm.user_id = ? AND c.school_id = ?");
                $stmt->execute([$_SESSION['user_id'], $school_id]);
                $formMasterClass = $stmt->fetch(PDO::FETCH_ASSOC);
                $isAlsoFormMaster = ($formMasterClass !== false);
                
                // Separate own teaching subjects from form class subjects
                $ownTeachingSubjects = [];
                $formClassSubjects = [];
                
                foreach ($subjectAssignments as $subId => $subInfo) {
                    foreach ($subInfo['classes'] as $classInfo) {
                        $assignment = [
                            'subject_id' => $subId,
                            'subject_name' => $subInfo['subject_name'],
                            'class_id' => $classInfo['class_id'],
                            'class_name' => $classInfo['class_name'],
                            'stream' => $classInfo['stream']
                        ];
                        
                        // Check if this is from subject_teachers table (own teaching)
                        $checkStmt = $db->prepare("SELECT id FROM subject_teachers WHERE user_id = ? AND subject_id = ? AND class_id = ?");
                        $checkStmt->execute([$_SESSION['user_id'], $subId, $classInfo['class_id']]);
                        
                        if ($checkStmt->fetch()) {
                            $ownTeachingSubjects[] = $assignment;
                        }
                    }
                }
                
                // If teacher is also a form master, get ALL subjects in that form class for monitoring
                if ($isAlsoFormMaster) {
                    // Get all subjects for the form class
                    $formClassQuery = $db->prepare("
                        SELECT DISTINCT s.id as subject_id, s.subject_name, 
                               ? as class_id
                        FROM subjects s
                        JOIN classes c ON s.class_id = c.id
                        WHERE s.class_id = ? AND c.school_id = ?
                        ORDER BY s.subject_name
                    ");
                    $formClassQuery->execute([$formMasterClass['class_id'], $formMasterClass['class_id'], $school_id]);
                    $allFormClassSubjects = $formClassQuery->fetchAll(PDO::FETCH_ASSOC);
                    
                    // Add class info to each subject
                    foreach ($allFormClassSubjects as &$subject) {
                        $subject['class_name'] = $formMasterClass['class_name'];
                        $subject['stream'] = $formMasterClass['stream'];
                    }
                    unset($subject);
                    
                    // Exclude subjects the teacher personally teaches in that same class
                    foreach ($allFormClassSubjects as $subject) {
                        $teachesThisSubject = false;
                        foreach ($ownTeachingSubjects as $ownSubject) {
                            if ($ownSubject['subject_id'] == $subject['subject_id'] && 
                                $ownSubject['class_id'] == $subject['class_id']) {
                                $teachesThisSubject = true;
                                break;
                            }
                        }
                        
                        if (!$teachesThisSubject) {
                            $formClassSubjects[] = $subject;
                        }
                    }
                }
                
                // Determine if form master is in VIEW-ONLY mode (monitoring a subject they don't teach)
                $isViewOnlyMode = false;
                if ($isAlsoFormMaster && $selectedClassId && $selectedSubjectId) {
                    // Check if they teach this subject in this class
                    $teachesCurrentSubject = false;
                    foreach ($ownTeachingSubjects as $ownSubject) {
                        if ($ownSubject['subject_id'] == $selectedSubjectId && $ownSubject['class_id'] == $selectedClassId) {
                            $teachesCurrentSubject = true;
                            break;
                        }
                    }
                    // If viewing their form class but don't teach this subject, it's view-only
                    if (!$teachesCurrentSubject && $selectedClassId == $formMasterClass['class_id']) {
                        $isViewOnlyMode = true;
                    }
                }
            ?>
            
            <?php if ($isAlsoFormMaster): ?>
            <!-- Dual Role Header -->
            <div class="selector-row" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 1.5rem; border-radius: 8px; margin-bottom: 1.5rem;">
                <div style="color: white;">
                    <h3 style="margin: 0 0 0.5rem 0; font-size: 1.2rem;">👨‍🏫 Dual Role: Subject Teacher & Form Master</h3>
                    <p style="margin: 0; opacity: 0.9; font-size: 0.95rem;">
                        Form Master of: <strong><?php echo htmlspecialchars($formMasterClass['class_name']); ?><?php echo !empty($formMasterClass['stream']) ? ' - Stream ' . $formMasterClass['stream'] : ''; ?></strong>
                    </p>
                    <a href="dashboard.php" style="display: inline-block; margin-top: 0.75rem; padding: 0.5rem 1rem; background: rgba(255,255,255,0.2); color: white; text-decoration: none; border-radius: 5px; font-size: 0.9rem;">
                        📊 Access Dashboard (View Reports, Students, etc.)
                    </a>
                </div>
            </div>
            <?php else: ?>
            <!-- Subject Teacher Only Header -->
            <div class="selector-row" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 1rem; border-radius: 8px; margin-bottom: 1rem;">
                <div style="color: white;">
                    <h3 style="margin: 0 0 0.5rem 0; font-size: 1.1rem;">👨‍🏫 Your Assigned Subjects & Classes</h3>
                    <p style="margin: 0; opacity: 0.9; font-size: 0.9rem;">Enter scores for all your assigned classes</p>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Your Teaching Subjects (Cards) -->
            <?php if (!empty($ownTeachingSubjects)): ?>
            <div style="margin-bottom: 2rem;">
                <h3 style="color: #2d3748; margin-bottom: 1rem; font-size: 1.1rem;">📚 Your Teaching Subjects (Click to Enter Scores)</h3>
                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 1rem;">
                    <?php foreach ($ownTeachingSubjects as $subject): ?>
                        <a href="scores.php?class=<?php echo $subject['class_id']; ?>&subject=<?php echo $subject['subject_id']; ?>" 
                           style="text-decoration: none; display: block; padding: 1.25rem; background: white; border: 2px solid <?php echo ($selectedClassId == $subject['class_id'] && $selectedSubjectId == $subject['subject_id']) ? '#667eea' : '#e2e8f0'; ?>; border-radius: 8px; transition: all 0.2s; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
                            <div style="display: flex; align-items: start; gap: 0.75rem;">
                                <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); width: 48px; height: 48px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; flex-shrink: 0;">
                                    📝
                                </div>
                                <div style="flex: 1;">
                                    <h4 style="margin: 0 0 0.5rem 0; color: #2d3748; font-size: 1rem; font-weight: 600;">
                                        <?php echo htmlspecialchars($subject['subject_name']); ?>
                                    </h4>
                                    <p style="margin: 0; color: #718096; font-size: 0.9rem;">
                                        <?php echo htmlspecialchars($subject['class_name']); ?>
                                        <?php if (!empty($subject['stream'])): ?>
                                            <span style="background: #667eea; color: white; padding: 0.15rem 0.5rem; border-radius: 3px; margin-left: 0.25rem; font-size: 0.8rem;">
                                                <?php echo htmlspecialchars($subject['stream']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </p>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Form Class Subjects (Dropdown for monitoring) -->
            <?php if ($isAlsoFormMaster): ?>
                <?php if (!empty($formClassSubjects)): ?>
                <div style="margin-bottom: 2rem; padding: 1.5rem; background: #f7fafc; border-radius: 8px; border-left: 4px solid #48bb78;">
                    <h3 style="color: #2d3748; margin-bottom: 0.75rem; font-size: 1.1rem;">
                        📋 Monitor Other Form Class Subjects
                        <span style="color: #718096; font-weight: normal; font-size: 0.9rem;">
                            (<?php echo htmlspecialchars($formMasterClass['class_name']); ?><?php echo !empty($formMasterClass['stream']) ? ' - Stream ' . $formMasterClass['stream'] : ''; ?>)
                        </span>
                    </h3>
                    <p style="color: #718096; margin-bottom: 1rem; font-size: 0.9rem;">
                        View scores entered by other teachers in your form class (<?php echo count($formClassSubjects); ?> subjects)
                    </p>
                    <select id="formClassSubjectSelect" class="form-control">
                        <option value="">-- Select Subject to View --</option>
                        <?php foreach ($formClassSubjects as $subject): ?>
                            <option value="scores.php?class=<?php echo $subject['class_id']; ?>&subject=<?php echo $subject['subject_id']; ?>"
                                    <?php echo (isset($isViewOnlyMode) && $isViewOnlyMode && $selectedSubjectId == $subject['subject_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($subject['subject_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <noscript>
                        <div style="margin-top:0.75rem">
                            <?php foreach ($formClassSubjects as $subject): ?>
                                <a href="scores.php?class=<?php echo $subject['class_id']; ?>&subject=<?php echo $subject['subject_id']; ?>" style="display:inline-block;margin-right:0.5rem;margin-bottom:0.5rem;padding:0.4rem 0.6rem;background:#edf2f7;border-radius:4px;text-decoration:none;color:#2d3748;">
                                    <?php echo htmlspecialchars($subject['subject_name']); ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </noscript>

                    <script>
                        (function(){
                            var sel = document.getElementById('formClassSubjectSelect');
                            if (!sel) return;
                            sel.addEventListener('change', function(){
                                var v = this.value;
                                if (v) {
                                    // ensure the selected URL is navigated to
                                    window.location.assign(v);
                                }
                            });
                        })();
                    </script>
                </div>
                <?php else: ?>
                <!-- No other subjects to monitor -->
                <div style="margin-bottom: 2rem; padding: 1.5rem; background: #e6fffa; border-radius: 8px; border-left: 4px solid #48bb78;">
                    <p style="color: #2d3748; margin: 0; font-size: 0.95rem;">
                        ✅ You teach all subjects in your form class (<?php echo htmlspecialchars($formMasterClass['class_name']); ?><?php echo !empty($formMasterClass['stream']) ? ' - Stream ' . $formMasterClass['stream'] : ''; ?>). Use the cards above to enter scores.
                    </p>
                </div>
                <?php endif; ?>
            <?php endif; ?>
            
            <!-- Hidden inputs for subject teachers (since they don't have dropdowns) -->
            <?php if ($isSubjectTeacher && $selectedClassId && $selectedSubjectId): ?>
            <input type="hidden" id="classSelect" value="<?php echo $selectedClassId; ?>">
            <input type="hidden" id="subjectSelect" value="<?php echo $selectedSubjectId; ?>">
            <?php endif; ?>
            
            <?php else: ?>
            <!-- Standard Class/Subject Selection for Admin and Form Masters -->
            <div class="selector-row">
                <div class="selector-group">
                    <label>Class:</label>
                    <select id="classSelect" onchange="changeClass()">
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
                <div class="selector-group">
                    <label>Subject:</label>
                    <select id="subjectSelect" onchange="changeSubject()">
                        <?php foreach ($subjects as $subject): ?>
                            <option value="<?php echo $subject['id']; ?>" <?php echo $selectedSubjectId == $subject['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($subject['subject_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <?php endif; ?>
            
            <?php 
            // Check if Mock Exam is enabled for Basic 9/JHS 3
            $showMockExamButton = false;
            if ($selectedClass && in_array($school_type_id, [2, 3])) {
                $className = strtolower($selectedClass['class_name']);
                $isBasic9 = (strpos($className, 'basic nine') !== false || 
                             strpos($className, 'basic 9') !== false ||
                             strpos($className, 'jhs 3') !== false ||
                             strpos($className, 'jhs3') !== false);
                
                if ($isBasic9) {
                    // Get school_id first
                    $schoolStmt = $db->prepare("SELECT id FROM school_info WHERE id = ?");
                    $schoolStmt->execute([$school_id]);
                    $schoolData = $schoolStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($schoolData) {
                        $mockCheckStmt = $db->prepare("SELECT is_enabled FROM mock_exam_settings WHERE school_id = ? AND is_enabled = 1 LIMIT 1");
                        $mockCheckStmt->execute([$schoolData['id']]);
                        $mockSettings = $mockCheckStmt->fetch(PDO::FETCH_ASSOC);
                        $showMockExamButton = ($mockSettings && $mockSettings['is_enabled'] == 1);
                    }
                }
            }
            ?>
            
            <?php if (isset($showMockExamInterface) && $showMockExamInterface): ?>
            <!-- Mock Exam Info Banner -->
            <div class="selector-row" style="margin-top: 1rem; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 1rem; border-radius: 8px;">
                <div style="flex: 1; color: white;">
                    <h3 style="margin: 0 0 0.5rem 0; font-size: 1.1rem;">📝 BECE Mock Examination Mode Enabled</h3>
                    <p style="margin: 0; opacity: 0.9; font-size: 0.9rem;">This class uses the 100-point Mock Exam system. Scores are saved to mock_exam_scores table.</p>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if (isset($isViewOnlyMode) && $isViewOnlyMode): ?>
            <!-- View-Only Mode Banner for Form Master Monitoring -->
            <div class="selector-row" style="margin-top: 1rem; background: linear-gradient(135deg, #48bb78 0%, #38a169 100%); padding: 1rem; border-radius: 8px;">
                <div style="flex: 1; color: white;">
                    <h3 style="margin: 0 0 0.5rem 0; font-size: 1.1rem;">👁️ Form Master Monitoring Mode</h3>
                    <p style="margin: 0; opacity: 0.9; font-size: 0.9rem;">
                        You are viewing scores entered by other teachers for <strong><?php echo htmlspecialchars($selectedSubject['subject_name'] ?? 'this subject'); ?></strong>. 
                        You cannot edit these scores. To make changes, contact the subject teacher.
                    </p>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($students) && !empty($subjects)): ?>
            <!-- View Mode Toggle -->
            <div class="selector-row" style="margin-top: 1rem; border-bottom: 2px solid #e1e8ed; padding-bottom: 1rem;">
                <div class="selector-group" style="gap: 0.5rem;">
                    <button class="btn" id="allStudentsBtn" onclick="setViewMode('all')" style="background: #667eea; color: white;">All Students</button>
                    <button class="btn" id="singleStudentBtn" onclick="setViewMode('single')" style="background: #e1e8ed; color: #4a5568;">Single Student</button>
                </div>
            </div>
            
            <!-- Single Student Selector (Hidden by default) -->
            <div id="singleStudentSelector" class="selector-row" style="margin-top: 1rem; display: none;">
                <div class="selector-group" style="flex: 1;">
                    <label>Select Student:</label>
                    <select id="studentSelect" onchange="loadSingleStudent()" style="padding: 0.6rem 1rem; border: 2px solid #e1e8ed; border-radius: 5px; font-size: 1rem; min-width: 250px;">
                        <option value="">-- Select a student --</option>
                        <?php foreach ($students as $student): ?>
                            <option value="<?php echo $student['id']; ?>">
                                <?php echo htmlspecialchars($student['student_id'] . ' - ' . $student['student_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <!-- Previous/Next Navigation (Single Student Mode) -->
            <div id="studentNavigation" class="selector-row" style="margin-top: 1rem; display: none; justify-content: space-between;">
                <button class="btn" onclick="navigateStudent('prev')" id="prevStudentBtn" style="background: #4299e1; color: white; display: flex; align-items: center; gap: 0.5rem;">
                    <span>←</span> Previous
                </button>
                <span id="studentPosition" style="padding: 0.6rem 1rem; font-weight: 600; color: #4a5568;"></span>
                <button class="btn" onclick="navigateStudent('next')" id="nextStudentBtn" style="background: #4299e1; color: white; display: flex; align-items: center; gap: 0.5rem;">
                    Next <span>→</span>
                </button>
            </div>
            
            <!-- Search and Filters (All Students Mode) -->
            <div id="allStudentsControls" class="selector-row" style="margin-top: 1rem;">
                <div class="selector-group" style="flex: 1;">
                    <label>Search Student:</label>
                    <input type="text" id="searchStudent" placeholder="Search by name or ID..." 
                           style="padding: 0.6rem 1rem; border: 2px solid #e1e8ed; border-radius: 5px; font-size: 1rem; width: 100%; max-width: 300px;">
                </div>
                <div class="selector-group">
                    <label>Filter by Status:</label>
                    <select id="filterStatus" onchange="filterByStatus()" style="padding: 0.6rem 1rem; border: 2px solid #e1e8ed; border-radius: 5px; font-size: 1rem;">
                        <option value="all">All Students</option>
                        <option value="submitted">Scores Submitted</option>
                        <option value="pending">Pending Scores</option>
                    </select>
                </div>
            </div>
            
            <!-- Action Buttons -->
            <?php if (!isset($isViewOnlyMode) || !$isViewOnlyMode): ?>
            <div class="selector-row" style="margin-top: 1rem; justify-content: space-between;">
                <div class="selector-group" style="gap: 0.5rem;">
                    <button class="btn" onclick="clearAllInputs()" style="background: #edf2f7; color: #4a5568;">Clear All</button>
                    <button class="btn" onclick="loadExistingScores()" style="background: #4299e1; color: white;">Load Existing Scores</button>
                    <?php if (isset($showMockExamButton) && $showMockExamButton): ?>
                    <button class="btn" onclick="window.location.href='mock_analysis.php?class=<?php echo $selectedClassId; ?>'" style="background: #38a169; color: white;">📊 View Mock Analysis</button>
                    <?php endif; ?>
                </div>
                <div class="selector-group" style="gap: 0.5rem;">
                    <button class="btn btn-danger" onclick="deleteAllScores()" style="background: #f56565; color: white;">Delete All Scores</button>
                </div>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
        
        <?php if (empty($students)): ?>
            <div class="scores-table">
                <div class="empty-state">
                    <h3>No Students Found</h3>
                    <p>Please add students to this class first.</p>
                </div>
            </div>
        <?php elseif (empty($subjects)): ?>
            <div class="scores-table">
                <div class="empty-state">
                    <h3>No Subjects Found</h3>
                    <p>Please configure subjects for this class.</p>
                </div>
            </div>
        <?php else: ?>
            <?php 
            // Calculate statistics
            $totalScores = array_filter(array_column($scoresData, 'score'), function($s) { 
                return isset($s['total_score']) && $s['total_score'] > 0; 
            });
            $classAvg = !empty($totalScores) ? array_sum(array_column($totalScores, 'total_score')) / count($totalScores) : 0;
            $highestScore = !empty($totalScores) ? max(array_column($totalScores, 'total_score')) : 0;
            $lowestScore = !empty($totalScores) ? min(array_column($totalScores, 'total_score')) : 0;
            $submittedCount = count($totalScores);
            ?>
            
            <?php if ($submittedCount > 0): ?>
            <div class="stats-container" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1rem;">
                <div style="background: white; padding: 1rem; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.05);">
                    <div style="font-size: 0.875rem; color: #718096;">Class Average</div>
                    <div style="font-size: 1.5rem; font-weight: 600; color: #667eea;"><?php echo number_format($classAvg, 1); ?>%</div>
                </div>
                <div style="background: white; padding: 1rem; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.05);">
                    <div style="font-size: 0.875rem; color: #718096;">Highest Score</div>
                    <div style="font-size: 1.5rem; font-weight: 600; color: #48bb78;"><?php echo number_format($highestScore, 1); ?>%</div>
                </div>
                <div style="background: white; padding: 1rem; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.05);">
                    <div style="font-size: 0.875rem; color: #718096;">Lowest Score</div>
                    <div style="font-size: 1.5rem; font-weight: 600; color: #f56565;"><?php echo number_format($lowestScore, 1); ?>%</div>
                </div>
                <div style="background: white; padding: 1rem; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.05);">
                    <div style="font-size: 0.875rem; color: #718096;">Scores Submitted</div>
                    <div style="font-size: 1.5rem; font-weight: 600; color: #4299e1;"><?php echo $submittedCount; ?> / <?php echo count($scoresData); ?></div>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="scores-table">
                <?php if (isset($showMockExamInterface) && $showMockExamInterface): ?>
                <!-- MOCK EXAM TABLE (100-point system) -->
                <table>
                    <thead>
                        <tr>
                            <th>Candidate Index</th>
                            <th>Student Name</th>
                            <th>Mock Exam Score<br>(100)</th>
                            <th>Grade</th>
                            <th>Remark</th>
                            <th>Absent Reason</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($scoresData as $data): 
                            $student = $data['student'];
                            $score = $data['score'];
                            
                            // For mock exams, use 'score' field directly (100-point)
                            $mockScore = $score['score'] ?? '';
                            
                            // Calculate grade via centralized helper
                            $grade = '';
                            if ($mockScore !== '') {
                                $mockGi = GradingSystem::getMockGradeForScore((float)$mockScore);
                                $grade  = $mockGi['grade'];
                            }
                            
                            $remark = $score['remark'] ?? '';
                            $absentReason = $data['absent_reason'] ?? '';
                        ?>
                            <tr data-student-id="<?php echo $student['id']; ?>">
                                <td><?php echo htmlspecialchars($student['candidate_index_number'] ?? $student['student_id']); ?></td>
                                <td><?php echo htmlspecialchars($student['student_name']); ?></td>
                                <td>
                                    <input type="number" class="score-input" name="mock_score" 
                                           min="0" max="100" step="0.1" 
                                           value="<?php echo $mockScore; ?>"
                                           oninput="calculateMockGrade(this)"
                                           <?php if (isset($isViewOnlyMode) && $isViewOnlyMode) echo 'readonly style="background:#f7fafc;cursor:not-allowed;"'; ?>>
                                </td>
                                <td><span class="grade"><?php echo htmlspecialchars($grade); ?></span></td>
                                <td class="remark-cell"><?php echo htmlspecialchars($remark); ?></td>
                                <td>
                                    <select class="absent-reason" name="absent_reason" style="width: 100%; padding: 5px;" <?php if (isset($isViewOnlyMode) && $isViewOnlyMode) echo 'disabled style="background:#f7fafc;cursor:not-allowed;"'; ?>>
                                        <option value="">PRESENT</option>
                                        <option value="PREGNANT/MARRIED" <?php echo $absentReason === 'PREGNANT/MARRIED' ? 'selected' : ''; ?>>PREGNANT/MARRIED</option>
                                        <option value="DEATH" <?php echo $absentReason === 'DEATH' ? 'selected' : ''; ?>>DEATH</option>
                                        <option value="TRAVELLED" <?php echo $absentReason === 'TRAVELLED' ? 'selected' : ''; ?>>TRAVELLED</option>
                                        <option value="ILLNESS" <?php echo $absentReason === 'ILLNESS' ? 'selected' : ''; ?>>ILLNESS</option>
                                        <option value="TRUANCY/DROP-OUT" <?php echo $absentReason === 'TRUANCY/DROP-OUT' ? 'selected' : ''; ?>>TRUANCY/DROP-OUT</option>
                                        <option value="WITHDRAWAL/TRANSFER" <?php echo $absentReason === 'WITHDRAWAL/TRANSFER' ? 'selected' : ''; ?>>WITHDRAWAL/TRANSFER</option>
                                        <option value="UNKNOWN" <?php echo $absentReason === 'UNKNOWN' ? 'selected' : ''; ?>>UNKNOWN</option>
                                    </select>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <!-- REGULAR SCORE TABLE (SBA system) -->
                <table>
                    <thead>
                        <tr>
                            <th>Student ID</th>
                            <th>Student Name</th>
                            <th>Test 1<br>(15)</th>
                            <th>Group Work<br>(15)</th>
                            <th>Test 2<br>(15)</th>
                            <th>Project Work<br>(15)</th>
                            <th>Exam<br>(100)</th>
                            <th>Total<br>(100%)</th>
                            <th>Grade</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($scoresData as $data): 
                            $student = $data['student'];
                            $score = $data['score'];
                            $test1 = $score['test1'] ?? '';
                            $groupWork = $score['group_work'] ?? '';
                            $test2 = $score['test2'] ?? '';
                            $projectWork = $score['project_work'] ?? '';
                            $examScore = $score['exam_score'] ?? '';
                            $totalScore = $score['total_score'] ?? 0;
                            
                            // Calculate grade
                            $grade = '';
                            foreach (GRADING_SYSTEM as $gradeData) {
                                if ($totalScore >= $gradeData['min'] && $totalScore <= $gradeData['max']) {
                                    $grade = $gradeData['grade'];
                                    break;
                                }
                            }
                        ?>
                            <tr data-student-id="<?php echo $student['id']; ?>">
                                <td><?php echo htmlspecialchars($student['student_id']); ?></td>
                                <td><?php echo htmlspecialchars($student['student_name']); ?></td>
                                <td>
                                    <input type="number" class="score-input" name="test1" 
                                           min="0" max="15" step="0.1" 
                                           value="<?php echo $test1; ?>"
                                           oninput="calculateTotal(this)"
                                           <?php if (isset($isViewOnlyMode) && $isViewOnlyMode) echo 'readonly style="background:#f7fafc;cursor:not-allowed;"'; ?>>
                                </td>
                                <td>
                                    <input type="number" class="score-input" name="group_work" 
                                           min="0" max="15" step="0.1" 
                                           value="<?php echo $groupWork; ?>"
                                           oninput="calculateTotal(this)"
                                           <?php if (isset($isViewOnlyMode) && $isViewOnlyMode) echo 'readonly style="background:#f7fafc;cursor:not-allowed;"'; ?>>
                                </td>
                                <td>
                                    <input type="number" class="score-input" name="test2" 
                                           min="0" max="15" step="0.1" 
                                           value="<?php echo $test2; ?>"
                                           oninput="calculateTotal(this)"
                                           <?php if (isset($isViewOnlyMode) && $isViewOnlyMode) echo 'readonly style="background:#f7fafc;cursor:not-allowed;"'; ?>>
                                </td>
                                <td>
                                    <input type="number" class="score-input" name="project_work" 
                                           min="0" max="15" step="0.1" 
                                           value="<?php echo $projectWork; ?>"
                                           oninput="calculateTotal(this)"
                                           <?php if (isset($isViewOnlyMode) && $isViewOnlyMode) echo 'readonly style="background:#f7fafc;cursor:not-allowed;"'; ?>>
                                </td>
                                <td>
                                    <input type="number" class="score-input" name="exam_score" 
                                           min="0" max="100" step="0.1" 
                                           value="<?php echo $examScore; ?>"
                                           oninput="calculateTotal(this)"
                                           <?php if (isset($isViewOnlyMode) && $isViewOnlyMode) echo 'readonly style="background:#f7fafc;cursor:not-allowed;"'; ?>>
                                </td>
                                <td class="total-score"><?php echo empty($score) ? '&mdash;' : number_format($totalScore, 1); ?></td>
                                <td><span class="grade"><?php echo empty($score) ? '' : htmlspecialchars($grade); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
            
            <div class="save-btn-container">
                <button class="btn btn-success" onclick="saveScores()" id="saveBtn">
                    <span id="saveBtnText">Save All Scores</span>
                    <span id="saveBtnLoader" style="display: none;">
                        <svg width="16" height="16" viewBox="0 0 50 50" style="display: inline-block; vertical-align: middle; margin-right: 5px;">
                            <circle cx="25" cy="25" r="20" fill="none" stroke="white" stroke-width="5" stroke-dasharray="31.4 31.4" transform="rotate(-90 25 25)">
                                <animateTransform attributeName="transform" type="rotate" from="0 25 25" to="360 25 25" dur="1s" repeatCount="indefinite"/>
                            </circle>
                        </svg>
                        Saving...
                    </span>
                </button>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        // Preserve scroll position across page interactions
        (function() {
            window.addEventListener('beforeunload', function() {
                sessionStorage.setItem('scrollPos', window.scrollY);
            });
            
            window.addEventListener('load', function() {
                const scrollPos = sessionStorage.getItem('scrollPos');
                if (scrollPos) {
                    setTimeout(function() {
                        window.scrollTo(0, parseInt(scrollPos));
                    }, 50);
                    sessionStorage.removeItem('scrollPos');
                }
            });
        })();
        
        // Grade scale loaded from PHP so JS stays in sync with server logic
        const MOCK_GRADE_SCALE = <?= json_encode(GradingSystem::getMockGradesArray()) ?>;

        // Calculate mock exam grade (BECE 1-9 scale)
        function calculateMockGrade(input) {
            const row = input.closest('tr');
            const score = parseFloat(input.value) || 0;
            const gradeCell = row.querySelector('.grade');
            let grade = '9';
            for (const g of MOCK_GRADE_SCALE) {
                if (score >= g.min_score) { grade = g.grade; break; }
            }
            gradeCell.textContent = grade;
        }
        
        // Search functionality
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('searchStudent');
            if (searchInput) {
                searchInput.addEventListener('keyup', function() {
                    const searchTerm = this.value.toLowerCase();
                    const rows = document.querySelectorAll('tbody tr');
                    
                    rows.forEach(row => {
                        const studentId = row.cells[0].textContent.toLowerCase();
                        const studentName = row.cells[1].textContent.toLowerCase();
                        
                        if (studentId.includes(searchTerm) || studentName.includes(searchTerm)) {
                            row.style.display = '';
                        } else {
                            row.style.display = 'none';
                        }
                    });
                });
            }
        });
        
        // Functions for multi-stream subject teacher interface
        function loadSubjectClasses() {
            const subjectSelect = document.getElementById('teacherSubjectSelect');
            const subjectId = subjectSelect.value;
            if (subjectId) {
                window.location.href = 'scores.php?subject=' + subjectId;
            }
        }
        
        function selectTeacherClass(classId, subjectId) {
            window.location.href = 'scores.php?class=' + classId + '&subject=' + subjectId;
        }
        
        function changeClass() {
            const classSelect = document.getElementById('classSelect');
            const classId = classSelect.value;
            
            window.location.href = 'scores.php?class=' + classId;
        }
        
        function changeSubject() {
            const classId = document.getElementById('classSelect').value;
            const subjectId = document.getElementById('subjectSelect').value;
            window.location.href = 'scores.php?class=' + classId + '&subject=' + subjectId;
        }
        
        function calculateTotal(input) {
            // Validate input value
            const max = parseFloat(input.max);
            const min = parseFloat(input.min);
            let value = parseFloat(input.value);
            
            if (value > max) {
                input.value = max;
                alert(`Maximum value for ${input.name} is ${max}`);
                value = max;
            } else if (value < min) {
                input.value = min;
                value = min;
            }
            
            const row = input.closest('tr');
            const test1 = parseFloat(row.querySelector('[name="test1"]').value) || 0;
            const groupWork = parseFloat(row.querySelector('[name="group_work"]').value) || 0;
            const test2 = parseFloat(row.querySelector('[name="test2"]').value) || 0;
            const projectWork = parseFloat(row.querySelector('[name="project_work"]').value) || 0;
            const examScore = parseFloat(row.querySelector('[name="exam_score"]').value) || 0;
            
            // Calculate class score (60 max scaled to 50%)
            const classScore = (test1 + groupWork + test2 + projectWork) / 60 * 50;
            // Exam score (100 max scaled to 50%)
            const examScaled = examScore / 100 * 50;
            // Total out of 100
            const total = classScore + examScaled;
            
            row.querySelector('.total-score').textContent = total.toFixed(1);
            
            // Update grade
            const gradingSystem = <?php echo json_encode(GRADING_SYSTEM); ?>;
            let grade = '';
            for (const gradeData of gradingSystem) {
                if (total >= gradeData.min && total <= gradeData.max) {
                    grade = gradeData.grade;
                    break;
                }
            }
            row.querySelector('.grade').textContent = grade;
        }
        
        // Search functionality
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('searchStudent');
            if (searchInput) {
                searchInput.addEventListener('keyup', function() {
                    const searchTerm = this.value.toLowerCase();
                    const rows = document.querySelectorAll('tbody tr');
                    
                    rows.forEach(row => {
                        const studentId = row.cells[0].textContent.toLowerCase();
                        const studentName = row.cells[1].textContent.toLowerCase();
                        
                        if (studentId.includes(searchTerm) || studentName.includes(searchTerm)) {
                            row.style.display = '';
                        } else {
                            row.style.display = 'none';
                        }
                    });
                });
            }
        });
        
        // View mode toggle
        let currentViewMode = 'all';
        
        function setViewMode(mode) {
            currentViewMode = mode;
            const allBtn = document.getElementById('allStudentsBtn');
            const singleBtn = document.getElementById('singleStudentBtn');
            const singleSelector = document.getElementById('singleStudentSelector');
            const allControls = document.getElementById('allStudentsControls');
            const tableBody = document.querySelector('tbody');
            const navigation = document.getElementById('studentNavigation');
            const saveBtn = document.getElementById('saveBtn');
            
            if (mode === 'all') {
                allBtn.style.background = '#667eea';
                allBtn.style.color = 'white';
                singleBtn.style.background = '#e1e8ed';
                singleBtn.style.color = '#4a5568';
                singleSelector.style.display = 'none';
                allControls.style.display = 'flex';
                navigation.style.display = 'none';
                saveBtn.textContent = 'Save All Scores';
                
                // Show all rows
                const rows = tableBody.querySelectorAll('tr');
                rows.forEach(row => row.style.display = '');
            } else {
                singleBtn.style.background = '#667eea';
                singleBtn.style.color = 'white';
                allBtn.style.background = '#e1e8ed';
                allBtn.style.color = '#4a5568';
                singleSelector.style.display = 'flex';
                allControls.style.display = 'none';
                navigation.style.display = 'flex';
                saveBtn.textContent = 'Save & Next';
                
                // Hide all rows initially
                const rows = tableBody.querySelectorAll('tr');
                rows.forEach(row => row.style.display = 'none');
            }
        }
        
        function loadSingleStudent() {
            const studentSelect = document.getElementById('studentSelect');
            const studentId = studentSelect.value;
            const rows = document.querySelectorAll('tbody tr');
            
            if (!studentId) {
                rows.forEach(row => row.style.display = 'none');
                document.getElementById('studentPosition').textContent = '';
                return;
            }
            
            rows.forEach(row => {
                if (row.dataset.studentId === studentId) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
            
            // Update position indicator
            const currentIndex = studentSelect.selectedIndex;
            const totalStudents = studentSelect.options.length - 1; // Exclude "Select student" option
            document.getElementById('studentPosition').textContent = `${currentIndex} / ${totalStudents}`;
            
            // Update navigation buttons
            document.getElementById('prevStudentBtn').disabled = currentIndex <= 1;
            document.getElementById('nextStudentBtn').disabled = currentIndex >= totalStudents;
        }
        
        function navigateStudent(direction) {
            const studentSelect = document.getElementById('studentSelect');
            const currentIndex = studentSelect.selectedIndex;
            
            if (direction === 'next' && currentIndex < studentSelect.options.length - 1) {
                studentSelect.selectedIndex = currentIndex + 1;
            } else if (direction === 'prev' && currentIndex > 1) {
                studentSelect.selectedIndex = currentIndex - 1;
            }
            
            loadSingleStudent();
        }
        
        function filterByStatus() {
            const status = document.getElementById('filterStatus').value;
            const rows = document.querySelectorAll('tbody tr');
            
            rows.forEach(row => {
                const totalScore = parseFloat(row.querySelector('.total-score').textContent);
                const hasScore = totalScore > 0;
                
                if (status === 'all') {
                    row.style.display = '';
                } else if (status === 'submitted' && hasScore) {
                    row.style.display = '';
                } else if (status === 'pending' && !hasScore) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }
        
        function clearAllInputs() {
            if (!confirm('Clear all input fields? This will not delete saved scores.')) {
                return;
            }
            
            const inputs = document.querySelectorAll('.score-input');
            inputs.forEach(input => {
                input.value = '';
            });
            
            // Reset totals and grades
            document.querySelectorAll('.total-score').forEach(el => el.textContent = '0.0');
            document.querySelectorAll('.grade').forEach(el => el.textContent = '');
        }
        
        function loadExistingScores() {
            const subjectId = document.getElementById('subjectSelect').value;
            const classId = document.getElementById('classSelect').value;
            
            if (!subjectId || !classId) {
                alert('Please select a class and subject first');
                return;
            }
            
            // Reload page to fetch fresh data
            location.reload();
        }
        
        function saveScores() {
            if (currentViewMode === 'single') {
                saveSingleStudent();
            } else {
                saveAllScores();
            }
        }
        
        function saveSingleStudent() {
            const studentSelect = document.getElementById('studentSelect');
            const studentId = studentSelect.value;
            
            if (!studentId) {
                alert('Please select a student first');
                return;
            }
            
            const row = document.querySelector(`tbody tr[data-student-id="${studentId}"]`);
            if (!row) return;
            
            const subjectSelectEl = document.getElementById('subjectSelect');
            const subjectId = subjectSelectEl ? subjectSelectEl.value : null;
            
            if (!subjectId) {
                alert('Subject not selected');
                return;
            }
            
            const saveBtn = document.getElementById('saveBtn');
            const saveBtnText = document.getElementById('saveBtnText');
            const saveBtnLoader = document.getElementById('saveBtnLoader');
            
            // Show loading state
            saveBtn.disabled = true;
            saveBtnText.style.display = 'none';
            saveBtnLoader.style.display = 'inline-block';
            
            const formData = new FormData();
            formData.append('action', 'save_score');
            formData.append('student_id', studentId);
            formData.append('subject_id', subjectId);
            
            // Check if this is mock exam mode
            const mockScoreInput = row.querySelector('[name="mock_score"]');
            if (mockScoreInput) {
                // Mock exam mode - send single score
                const absentReasonSelect = row.querySelector('[name="absent_reason"]');
                formData.append('mock_score', mockScoreInput.value || 0);
                formData.append('absent_reason', absentReasonSelect ? absentReasonSelect.value : '');
                formData.append('is_mock', '1');
            } else {
                // Regular SBA mode - send all components
                formData.append('test1', row.querySelector('[name="test1"]').value || 0);
                formData.append('group_work', row.querySelector('[name="group_work"]').value || 0);
                formData.append('test2', row.querySelector('[name="test2"]').value || 0);
                formData.append('project_work', row.querySelector('[name="project_work"]').value || 0);
                formData.append('exam_score', row.querySelector('[name="exam_score"]').value || 0);
            }
            
            fetch('scores.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json'
                }
            })
            .then(response => response.json())
            .then(data => {
                // Reset loading state
                saveBtn.disabled = false;
                saveBtnText.style.display = 'inline-block';
                saveBtnLoader.style.display = 'none';
                
                if (data.success) {
                    // Auto-advance to next student
                    const currentIndex = studentSelect.selectedIndex;
                    const totalStudents = studentSelect.options.length - 1;
                    
                    if (currentIndex < totalStudents) {
                        // Move to next student
                        setTimeout(() => {
                            navigateStudent('next');
                        }, 300);
                    } else {
                        alert('Score saved! This is the last student.');
                    }
                } else {
                    alert('Error: ' + (data.message || 'Failed to save score'));
                }
            })
            .catch(error => {
                // Reset loading state on error
                saveBtn.disabled = false;
                saveBtnText.style.display = 'inline-block';
                saveBtnLoader.style.display = 'none';
                
                console.error('Error:', error);
                alert('An error occurred while saving');
            });
        }
        
        function saveAllScores() {
            const rows = document.querySelectorAll('tbody tr');
            const subjectSelectEl = document.getElementById('subjectSelect');
            const subjectId = subjectSelectEl ? subjectSelectEl.value : null;

            if (!subjectId) {
                alert('Subject not selected. Please select a subject first.');
                return;
            }
            if (rows.length === 0) {
                alert('No students to save scores for');
                return;
            }

            const saveBtn     = document.getElementById('saveBtn');
            const saveBtnText = document.getElementById('saveBtnText');
            const saveBtnLoader = document.getElementById('saveBtnLoader');
            saveBtn.disabled = true;
            saveBtnText.style.display = 'none';
            saveBtnLoader.style.display = 'inline-block';

            // Collect all rows into one array instead of N separate requests
            const isMock  = !!rows[0].querySelector('[name="mock_score"]');
            const scores  = [];
            rows.forEach(row => {
                const studentId = row.dataset.studentId;
                if (isMock) {
                    const mockInput = row.querySelector('[name="mock_score"]');
                    // Skip rows where the score field is completely empty (not yet entered)
                    if (mockInput && mockInput.value.trim() === '') return;
                    const absentReasonSelect = row.querySelector('[name="absent_reason"]');
                    scores.push({
                        student_id:    studentId,
                        mock_score:    mockInput.value || 0,
                        absent_reason: absentReasonSelect ? absentReasonSelect.value : ''
                    });
                } else {
                    const t1 = row.querySelector('[name="test1"]').value.trim();
                    const gw = row.querySelector('[name="group_work"]').value.trim();
                    const t2 = row.querySelector('[name="test2"]').value.trim();
                    const pw = row.querySelector('[name="project_work"]').value.trim();
                    const ex = row.querySelector('[name="exam_score"]').value.trim();
                    // Skip rows where ALL fields are empty (teacher hasn't entered any data yet)
                    if (t1 === '' && gw === '' && t2 === '' && pw === '' && ex === '') return;
                    scores.push({
                        student_id:   studentId,
                        test1:        t1 === '' ? 0 : t1,
                        group_work:   gw === '' ? 0 : gw,
                        test2:        t2 === '' ? 0 : t2,
                        project_work: pw === '' ? 0 : pw,
                        exam_score:   ex === '' ? 0 : ex
                    });
                }
            });

            const formData = new FormData();
            formData.append('action', 'batch_save_scores');
            formData.append('payload', JSON.stringify({ subject_id: subjectId, is_mock: isMock ? '1' : '0', scores }));

            fetch('scores.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            })
            .then(response => response.json())
            .then(data => {
                saveBtn.disabled = false;
                saveBtnText.style.display = 'inline-block';
                saveBtnLoader.style.display = 'none';
                if (data.success) {
                    if (data.failed > 0) {
                        alert(`Saved ${data.saved} scores. ${data.failed} failed.`);
                    } else {
                        alert(`All ${data.saved} scores saved successfully!`);
                    }
                } else {
                    alert('Error: ' + (data.message || 'Save failed'));
                }
                location.reload();
            })
            .catch(error => {
                saveBtn.disabled = false;
                saveBtnText.style.display = 'inline-block';
                saveBtnLoader.style.display = 'none';
                console.error('Batch save error:', error);
                alert('Network error. Please try again.');
            });
        }
        
        // Search functionality
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('searchStudent');
            if (searchInput) {
                searchInput.addEventListener('keyup', function() {
                    const searchTerm = this.value.toLowerCase();
                    const rows = document.querySelectorAll('tbody tr');
                    
                    rows.forEach(row => {
                        const studentId = row.cells[0].textContent.toLowerCase();
                        const studentName = row.cells[1].textContent.toLowerCase();
                        
                        if (studentId.includes(searchTerm) || studentName.includes(searchTerm)) {
                            row.style.display = '';
                        } else {
                            row.style.display = 'none';
                        }
                    });
                });
            }
        });
        
        function deleteAllScores() {
            if (!confirm('Are you sure you want to delete ALL scores for this subject? This action cannot be undone!')) {
                return;
            }
            
            const subjectId = document.getElementById('subjectSelect').value;
            const classId = document.getElementById('classSelect').value;
            
            // Check if we're in mock exam mode
            const mockScoreInput = document.querySelector('[name="mock_score"]');
            const isMock = mockScoreInput ? '1' : '0';
            
            const formData = new FormData();
            formData.append('action', 'delete_all_scores');
            formData.append('subject_id', subjectId);
            formData.append('class_id', classId);
            formData.append('is_mock', isMock);
            
            fetch('scores.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('All scores deleted successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + (data.message || 'Failed to delete scores'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while deleting scores');
            });
        }
    </script>
<?php require_once __DIR__ . '/components/footer.php'; ?>
