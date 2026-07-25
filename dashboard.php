<?php
// Start output buffering to prevent any output before JSON
ob_start();

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/controllers/AuthController.php';
require_once __DIR__ . '/controllers/StudentController.php';
require_once __DIR__ . '/controllers/ClassController.php';

$auth = new Auth();
$auth->requireLogin();

$currentUser = $auth->getCurrentUser();
$isAdmin = $auth->isAdmin();

// Get school info using the SPECIFIC school_id from session
require_once __DIR__ . '/config/database.php';
$db = Database::getInstance()->getConnection();
$schoolInfo = null;
if (isset($_SESSION['school_id'])) {
    $stmt = $db->prepare("SELECT * FROM school_info WHERE id = ?");
    $stmt->execute([$_SESSION['school_id']]);
    $schoolInfo = $stmt->fetch(PDO::FETCH_ASSOC);
}

$studentController = new StudentController();
$classController = new ClassController();

// Initialize ScoreController for performance endpoint
require_once __DIR__ . '/controllers/ScoreController.php';
$scoreController = new ScoreController();

// Check if user is form master/class teacher and get their assigned classes
$userRole = $_SESSION['role'] ?? $_SESSION['user_type'] ?? 'teacher';
$assignedClasses = [];
$isFormMaster = false;

// Subject teachers who are ALSO form masters can access dashboard
// Check if user has form master assignment
$school_id = $_SESSION['school_id'] ?? null;
$stmt = $db->prepare("SELECT COUNT(*) as count FROM form_masters fm JOIN classes c ON fm.class_id = c.id WHERE fm.user_id = ? AND c.school_id = ?");
$stmt->execute([$_SESSION['user_id'], $school_id]);
$hasFormMasterAssignment = $stmt->fetch(PDO::FETCH_ASSOC)['count'] > 0;

// Subject teachers without form master role should go to scores page
if ($userRole === 'subject_master' && !$isAdmin && !$hasFormMasterAssignment) {
    header('Location: scores.php');
    exit;
}

if (!$isAdmin && (in_array($userRole, ['form_master', 'teacher']) || $hasFormMasterAssignment)) {
    // Get classes assigned to this teacher as form master/class teacher
    $stmt = $db->prepare("SELECT DISTINCT fm.class_id FROM form_masters fm JOIN classes c ON fm.class_id = c.id WHERE fm.user_id = ? AND c.school_id = ?");
    $stmt->execute([$currentUser['id'], $school_id]);
    $assignedClasses = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (!empty($assignedClasses)) {
        $isFormMaster = true;
    }
}

// Handle GET requests for student performance
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_student_performance' && isset($_GET['student_id'])) {
    try {
        ob_clean();
        header('Content-Type: application/json');
        
        $studentId = (int)$_GET['student_id'];
        
        // Verify student belongs to this school
        $stmt = $db->prepare("SELECT s.*, c.id as class_id FROM students s JOIN classes c ON s.class_id = c.id WHERE s.id = ? AND c.school_id = ?");
        $stmt->execute([$studentId, $_SESSION['school_id']]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$student) {
            echo json_encode(['success' => false, 'error' => 'Student not found']);
            exit;
        }
        
        // Get subjects for this student's class
        $stmt = $db->prepare("SELECT s.* FROM subjects s JOIN classes c ON s.class_id = c.id WHERE s.class_id = ? AND c.school_id = ? ORDER BY s.subject_name");
        $stmt->execute([$student['class_id'], $_SESSION['school_id']]);
        $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get all students in same class for position calculation
        $stmt = $db->prepare("SELECT id FROM students WHERE class_id = ?");
        $stmt->execute([$student['class_id']]);
        $classStudents = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate scores and position
        $total = 0;
        $scores = [];
        $studentTotals = [];
        
        foreach ($classStudents as $classStudent) {
            $classStudentTotal = 0;
            foreach ($subjects as $subject) {
                $score = $scoreController->getScore($classStudent['id'], $subject['id']);
                $classStudentTotal += round($score['total_score'] ?? 0);
            }
            $studentTotals[$classStudent['id']] = $classStudentTotal;
        }
        
        // Sort to get position
        arsort($studentTotals);
        $position = array_search($studentId, array_keys($studentTotals)) + 1;
        
        // Get this student's scores
        foreach ($subjects as $subject) {
            $score = $scoreController->getScore($studentId, $subject['id']);
            $totalScore = round($score['total_score'] ?? 0);
            $total += $totalScore;
            $scores[] = [
                'subject' => $subject['subject_name'],
                'total' => $totalScore
            ];
        }
        
        $average = count($subjects) > 0 ? $total / count($subjects) : 0;
        
        echo json_encode([
            'success' => true,
            'total' => $total,
            'average' => round($average, 1),
            'position' => $position,
            'subject_count' => count($subjects),
            'scores' => $scores
        ]);
        exit;
    } catch (Exception $e) {
        error_log("Student performance error: " . $e->getMessage());
        ob_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Clear any previous output
    ob_clean();
    header('Content-Type: application/json');
    
    switch ($_POST['action']) {
        case 'add_student':
            // Handle photo upload with compression
            $data = $_POST;
            if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                try {
                    $uploadDir = __DIR__ . '/uploads/students/';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }
                    
                    $fileExtension = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
                    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];
                    
                    if (in_array($fileExtension, $allowedExtensions)) {
                        // Load image
                        $sourceImage = null;
                        switch ($fileExtension) {
                            case 'jpeg':
                            case 'jpg':
                                $sourceImage = @imagecreatefromjpeg($_FILES['photo']['tmp_name']);
                                break;
                            case 'png':
                                $sourceImage = @imagecreatefrompng($_FILES['photo']['tmp_name']);
                                break;
                            case 'gif':
                                $sourceImage = @imagecreatefromgif($_FILES['photo']['tmp_name']);
                                break;
                        }
                        
                        if ($sourceImage) {
                            // Get original dimensions
                            $originalWidth = imagesx($sourceImage);
                            $originalHeight = imagesy($sourceImage);
                            
                            // Set maximum dimensions for passport photo (113px × 132px at 96 DPI ≈ 30mm × 35mm)
                            $maxWidth = 113;
                            $maxHeight = 132;
                            
                            // Calculate new dimensions maintaining aspect ratio
                            $ratio = min($maxWidth / $originalWidth, $maxHeight / $originalHeight);
                            $newWidth = round($originalWidth * $ratio);
                            $newHeight = round($originalHeight * $ratio);
                            
                            // Create resized image
                            $resizedImage = imagecreatetruecolor($newWidth, $newHeight);
                            
                            // Preserve transparency for PNG/GIF
                            if ($fileExtension === 'png' || $fileExtension === 'gif') {
                                imagealphablending($resizedImage, false);
                                imagesavealpha($resizedImage, true);
                                $transparent = imagecolorallocatealpha($resizedImage, 255, 255, 255, 127);
                                imagefilledrectangle($resizedImage, 0, 0, $newWidth, $newHeight, $transparent);
                            }
                            
                            imagecopyresampled($resizedImage, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $originalWidth, $originalHeight);
                            
                            // Save compressed image
                            $fileName = 'student_' . uniqid() . '.jpg'; // Always save as JPG for smallest size
                            $targetPath = $uploadDir . $fileName;
                            
                            imagejpeg($resizedImage, $targetPath, 75); // 75% quality for good balance
                            
                            imagedestroy($sourceImage);
                            imagedestroy($resizedImage);
                            
                            $data['photo_url'] = 'uploads/students/' . $fileName;
                        }
                    }
                } catch (Exception $e) {
                    error_log('Photo upload error: ' . $e->getMessage());
                }
            }
            try {
                $result = $studentController->addStudent($data);
                echo json_encode($result);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            exit;
            
        case 'update_student':
            // Handle photo upload with compression
            $data = $_POST;
            if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                try {
                    $uploadDir = __DIR__ . '/uploads/students/';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }
                    
                    $fileExtension = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
                    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];
                    
                    if (in_array($fileExtension, $allowedExtensions)) {
                        // Delete old photo if exists
                        if (!empty($data['old_photo_url'])) {
                            $oldPhotoPath = __DIR__ . '/' . $data['old_photo_url'];
                            if (file_exists($oldPhotoPath)) {
                                @unlink($oldPhotoPath);
                            }
                        }
                        
                        // Load image
                        $sourceImage = null;
                        switch ($fileExtension) {
                            case 'jpeg':
                            case 'jpg':
                                $sourceImage = @imagecreatefromjpeg($_FILES['photo']['tmp_name']);
                                break;
                            case 'png':
                                $sourceImage = @imagecreatefrompng($_FILES['photo']['tmp_name']);
                                break;
                            case 'gif':
                                $sourceImage = @imagecreatefromgif($_FILES['photo']['tmp_name']);
                                break;
                        }
                        
                        if ($sourceImage) {
                            // Get original dimensions
                            $originalWidth = imagesx($sourceImage);
                            $originalHeight = imagesy($sourceImage);
                            
                            // Set maximum dimensions for passport photo (113px × 132px at 96 DPI ≈ 30mm × 35mm)
                            $maxWidth = 113;
                            $maxHeight = 132;
                            
                            // Calculate new dimensions maintaining aspect ratio
                            $ratio = min($maxWidth / $originalWidth, $maxHeight / $originalHeight);
                            $newWidth = round($originalWidth * $ratio);
                            $newHeight = round($originalHeight * $ratio);
                            
                            // Create resized image
                            $resizedImage = imagecreatetruecolor($newWidth, $newHeight);
                            
                            // Preserve transparency for PNG/GIF
                            if ($fileExtension === 'png' || $fileExtension === 'gif') {
                                imagealphablending($resizedImage, false);
                                imagesavealpha($resizedImage, true);
                                $transparent = imagecolorallocatealpha($resizedImage, 255, 255, 255, 127);
                                imagefilledrectangle($resizedImage, 0, 0, $newWidth, $newHeight, $transparent);
                            }
                            
                            imagecopyresampled($resizedImage, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $originalWidth, $originalHeight);
                            
                            // Save compressed image
                            $fileName = 'student_' . uniqid() . '.jpg'; // Always save as JPG for smallest size
                            $targetPath = $uploadDir . $fileName;
                            
                            imagejpeg($resizedImage, $targetPath, 75); // 75% quality for good balance
                            
                            imagedestroy($sourceImage);
                            imagedestroy($resizedImage);
                            
                            $data['photo_url'] = 'uploads/students/' . $fileName;
                        }
                    }
                } catch (Exception $e) {
                    error_log('Photo upload error: ' . $e->getMessage());
                }
            }
            try {
                $result = $studentController->updateStudent($data['id'], $data);
                echo json_encode($result);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            exit;
            
        case 'delete_student':
            $result = $studentController->deleteStudent($_POST['id']);
            echo json_encode($result);
            exit;
            
        case 'graduate_class':
            try {
                if (empty($_POST['class_id'])) {
                    echo json_encode(['success' => false, 'error' => 'Class ID is required']);
                    exit;
                }
                
                $classId = intval($_POST['class_id']);
                
                // Verify class belongs to this school
                $schoolTypeId = $_SESSION['school_type_id'] ?? null;
                $schoolStmt = $db->prepare("SELECT id FROM school_info WHERE id = ?");
                $schoolStmt->execute([$_SESSION['school_id']]);
                $schoolData = $schoolStmt->fetch(PDO::FETCH_ASSOC);
                $schoolId = $schoolData['id'] ?? null;
                
                $classStmt = $db->prepare("SELECT id, class_name FROM classes WHERE id = ? AND school_id = ?");
                $classStmt->execute([$classId, $schoolId]);
                $targetClass = $classStmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$targetClass) {
                    echo json_encode(['success' => false, 'error' => 'Invalid class']);
                    exit;
                }
                
                // Verify class belongs to this school
                $verifyStmt = $db->prepare("SELECT school_id FROM classes WHERE id = ?");
                $verifyStmt->execute([$classId]);
                $classInfo = $verifyStmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$classInfo || $classInfo['school_id'] != $_SESSION['school_id']) {
                    echo json_encode(['success' => false, 'error' => 'Unauthorized: Class does not belong to your school']);
                    exit;
                }
                
                // Get count of students to be graduated
                $countStmt = $db->prepare("SELECT COUNT(*) as count FROM students WHERE class_id = ?");
                $countStmt->execute([$classId]);
                $studentCount = $countStmt->fetch(PDO::FETCH_ASSOC)['count'];
                
                if ($studentCount == 0) {
                    echo json_encode(['success' => false, 'error' => 'No students in this class to graduate']);
                    exit;
                }
                
                // Delete all students from the class (their scores remain in database for records)
                $deleteStmt = $db->prepare("DELETE FROM students WHERE class_id = ?");
                $deleteStmt->execute([$classId]);
                
                echo json_encode([
                    'success' => true,
                    'message' => "Successfully graduated {$studentCount} student(s) from {$targetClass['class_name']}. The class is now empty and ready for new students."
                ]);
                exit;
            } catch (Exception $e) {
                error_log("Graduate class exception: " . $e->getMessage());
                echo json_encode(['success' => false, 'error' => 'Error graduating class: ' . $e->getMessage()]);
                exit;
            }
            
        case 'bulk_promote':
            try {
                if (empty($_POST['student_ids']) || !is_array($_POST['student_ids'])) {
                    echo json_encode(['success' => false, 'error' => 'No students selected']);
                    exit;
                }
                if (empty($_POST['target_class_id'])) {
                    echo json_encode(['success' => false, 'error' => 'Target class is required']);
                    exit;
                }

                require_once __DIR__ . '/controllers/PromotionController.php';
                $promoCtrl = new PromotionController();

                $studentIds = array_map('intval', $_POST['student_ids']);
                $targetClassId = intval($_POST['target_class_id']);
                $userId = $_SESSION['user_id'] ?? 0;

                // Verify target class exists and belongs to this school
                $schoolId = $_SESSION['school_id'] ?? null;
                $classStmt = $db->prepare("SELECT id, class_name FROM classes WHERE id = ? AND school_id = ?");
                $classStmt->execute([$targetClassId, $schoolId]);
                $targetClass = $classStmt->fetch(PDO::FETCH_ASSOC);

                if (!$targetClass) {
                    echo json_encode(['success' => false, 'error' => 'Invalid target class']);
                    exit;
                }

                // Check if target class has existing students
                $checkStmt = $db->prepare("SELECT COUNT(*) as count FROM students WHERE class_id = ?");
                $checkStmt->execute([$targetClassId]);
                $existingCount = $checkStmt->fetch(PDO::FETCH_ASSOC)['count'];

                if ($existingCount > 0) {
                    echo json_encode([
                        'success' => false,
                        'error' => 'Cannot promote to ' . $targetClass['class_name'] . '! This class has ' . $existingCount . ' student(s) already.',
                        'needs_clear' => true,
                        'target_class_id' => $targetClassId,
                        'target_class_name' => $targetClass['class_name'],
                        'existing_count' => $existingCount
                    ]);
                    exit;
                }

                // Promote each student using PromotionController (includes archiving and history)
                $promoted = 0;
                $failed = 0;
                $errors = [];

                foreach ($studentIds as $studentId) {
                    $result = $promoCtrl->promoteStudent($studentId, $targetClassId, $userId);
                    if ($result['success']) {
                        $promoted++;
                    } else {
                        $failed++;
                        $errors[] = "Student ID $studentId: " . ($result['error'] ?? 'Unknown error');
                    }
                }

                if ($promoted === count($studentIds)) {
                    echo json_encode([
                        'success' => true,
                        'message' => "Successfully promoted {$promoted} student(s) to {$targetClass['class_name']} with score archiving and history tracking"
                    ]);
                } elseif ($promoted > 0) {
                    echo json_encode([
                        'success' => true,
                        'message' => "Promoted {$promoted} student(s) to {$targetClass['class_name']}. {$failed} failed.",
                        'partial_success' => true,
                        'errors' => $errors
                    ]);
                } else {
                    echo json_encode([
                        'success' => false,
                        'error' => 'Failed to promote any students. Errors: ' . implode('; ', $errors)
                    ]);
                }
                exit;
            } catch (Exception $e) {
                error_log("Bulk promotion exception: " . $e->getMessage());
                echo json_encode(['success' => false, 'error' => 'Error promoting students: ' . $e->getMessage()]);
                exit;
            }
            
        case 'bulk_import':
            try {
                if (empty($_POST['class_id'])) {
                    echo json_encode(['success' => false, 'error' => 'Class ID is required']);
                    exit;
                }
                if (empty($_POST['student_names'])) {
                    echo json_encode(['success' => false, 'error' => 'Student names are required']);
                    exit;
                }
                
                $classId = intval($_POST['class_id']);
                
                // Validate that the class belongs to the current school
                $schoolTypeId = $_SESSION['school_type_id'] ?? null;
                if ($schoolTypeId !== null) {
                    $schoolStmt = $db->prepare("SELECT id FROM school_info WHERE id = ?");
                    $schoolStmt->execute([$_SESSION['school_id']]);
                    $schoolData = $schoolStmt->fetch(PDO::FETCH_ASSOC);
                    $schoolId = $schoolData['id'] ?? null;
                    
                    if ($schoolId) {
                        // Check if class belongs to this school
                        $classStmt = $db->prepare("SELECT school_id FROM classes WHERE id = ?");
                        $classStmt->execute([$classId]);
                        $classData = $classStmt->fetch(PDO::FETCH_ASSOC);
                        
                        if (!$classData || $classData['school_id'] != $schoolId) {
                            echo json_encode(['success' => false, 'error' => 'Invalid class selection. This class does not belong to your school.']);
                            exit;
                        }
                    }
                }
                
                $studentNames = array_filter(array_map('trim', explode("\n", $_POST['student_names'])));
                
                if (empty($studentNames)) {
                    echo json_encode(['success' => false, 'error' => 'No valid student names provided']);
                    exit;
                }
                
                $result = $studentController->bulkImport($classId, $studentNames);
                echo json_encode($result);
                exit;
            } catch (Exception $e) {
                error_log("Bulk import exception: " . $e->getMessage());
                echo json_encode(['success' => false, 'error' => 'An error occurred: ' . $e->getMessage()]);
                exit;
            }
            
        case 'update_class_settings':
            try {
                if (empty($_POST['class_id'])) {
                    echo json_encode(['success' => false, 'error' => 'Class ID is required']);
                    exit;
                }
                
                $classId = intval($_POST['class_id']);
                $totalAttendance = $_POST['total_attendance'] ?? null;
                
                if (!$totalAttendance) {
                    echo json_encode(['success' => false, 'error' => 'Total attendance is required']);
                    exit;
                }
                
                // Update all students in this class
                $updateCount = 0;
                $students = $studentController->getStudentsByClass($classId);
                
                foreach ($students as $student) {
                    $updateData = [
                        'student_id' => $student['student_id'],
                        'student_name' => $student['student_name'],
                        'gender' => $student['gender'],
                        'attendance' => $student['attendance'],
                        'total_attendance' => intval($totalAttendance),
                        'interest' => $student['interest'],
                        'conduct' => $student['conduct'],
                        'form_master_remarks' => $student['form_master_remarks'],
                        'headmaster_remarks' => $student['headmaster_remarks'],
                        'promoted' => $student['promoted'],
                        'promoted_to_class' => $student['promoted_to_class']
                    ];
                    
                    $result = $studentController->updateStudent($student['id'], $updateData);
                    if ($result['success']) {
                        $updateCount++;
                    }
                }
                
                echo json_encode([
                    'success' => true, 
                    'message' => "Updated total attendance to {$totalAttendance} for {$updateCount} students successfully"
                ]);
                exit;
            } catch (Exception $e) {
                error_log("Update class settings exception: " . $e->getMessage());
                echo json_encode(['success' => false, 'error' => 'An error occurred: ' . $e->getMessage()]);
                exit;
            }
            
        case 'delete_all_students':
            try {
                if (empty($_POST['class_id'])) {
                    echo json_encode(['success' => false, 'error' => 'Class ID is required']);
                    exit;
                }
                
                $classId = intval($_POST['class_id']);
                $students = $studentController->getStudentsByClass($classId);
                
                $deleteCount = 0;
                foreach ($students as $student) {
                    $result = $studentController->deleteStudent($student['id']);
                    if ($result['success']) {
                        $deleteCount++;
                    }
                }
                
                echo json_encode([
                    'success' => true,
                    'message' => "Deleted {$deleteCount} students successfully"
                ]);
                exit;
            } catch (Exception $e) {
                error_log("Delete all students exception: " . $e->getMessage());
                echo json_encode(['success' => false, 'error' => 'An error occurred: ' . $e->getMessage()]);
                exit;
            }
    }
}

// Get selected class or default to first class (filtered by school type)
$schoolTypeId = $_SESSION['school_type_id'] ?? null;
$classes = $classController->getAllClasses($schoolTypeId);

// Filter classes for form masters/class teachers
if ($isFormMaster && !empty($assignedClasses)) {
    $classes = array_filter($classes, function($class) use ($assignedClasses) {
        return in_array($class['id'], $assignedClasses);
    });
    $classes = array_values($classes); // Re-index array
}

$selectedClassId = isset($_GET['class']) ? (int)$_GET['class'] : ($classes[0]['id'] ?? null);

// Verify form master has access to selected class
if ($isFormMaster && $selectedClassId && !in_array($selectedClassId, $assignedClasses)) {
    $selectedClassId = $assignedClasses[0] ?? null;
}

$selectedClass = $selectedClassId ? $classController->getClass($selectedClassId) : null;
$students = $selectedClassId ? $studentController->getStudentsByClass($selectedClassId) : [];

// Check if mock exam is enabled and this is Basic 9 - combine all streams
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
                $stmt = $db->prepare("SELECT s.*, c.stream, c.class_name FROM students s 
                                      LEFT JOIN classes c ON s.class_id = c.id 
                                      WHERE s.class_id IN ($placeholders) 
                                      ORDER BY s.student_name");
                $stmt->execute($basic9Ids);
                $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }
    } catch (Exception $e) {
        error_log("Mock exam check error in dashboard: " . $e->getMessage());
    }
}

$pageTitle = 'Student Records';
require_once __DIR__ . '/components/header.php';
?>
    <style>
        
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
        
        .btn {
            padding: 0.6rem 1.5rem;
            border: none;
            border-radius: 5px;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s;
            font-weight: 600;
        }
        
        .btn-primary {
            background: #667eea;
            color: white;
        }
        
        .btn-primary:hover {
            background: #5568d3;
            transform: translateY(-2px);
        }
        
        .btn-success {
            background: #48bb78;
            color: white;
        }
        
        .btn-success:hover {
            background: #38a169;
        }
        
        .btn-danger {
            background: #f56565;
            color: white;
        }
        
        .btn-danger:hover {
            background: #e53e3e;
        }
        
        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .students-table {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            overflow: hidden;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
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
        }
        
        td {
            padding: 1rem;
            border-bottom: 1px solid #e2e8f0;
        }
        
        tbody tr:hover {
            background: #f7fafc;
        }
        
        .actions {
            display: flex;
            gap: 0.5rem;
        }
        
        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.875rem;
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        
        .modal.active {
            display: flex;
        }
        
        .modal-content {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            max-width: 600px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        
        .modal-header h2 {
            color: #2d3748;
        }
        
        .close-btn {
            background: none;
            border: none;
            font-size: 2rem;
            cursor: pointer;
            color: #718096;
        }
        
        .form-group {
            margin-bottom: 1rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: #2d3748;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 0.6rem;
            border: 2px solid #e1e8ed;
            border-radius: 5px;
            font-size: 1rem;
        }
        
        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
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
        
        .alert-error {
            background: #fed7d7;
            color: #742a2a;
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #718096;
        }
        
        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .controls {
                flex-direction: column;
                align-items: stretch;
            }
            
            .controls > div {
                width: 100% !important;
                flex: none !important;
                min-width: auto !important;
            }
            
            .controls button {
                width: 100%;
                margin: 0.25rem 0;
            }
            
            .students-table {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                margin: 0 -1rem;
                padding: 0 1rem;
            }
            
            table {
                font-size: 0.75rem;
            }
            
            th, td {
                padding: 0.4rem 0.3rem !important;
                white-space: nowrap;
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
            
            .btn-sm {
                padding: 0.3rem 0.5rem;
                font-size: 0.75rem;
            }

            /* Class settings grid collapse */
            [style*="grid-template-columns: 2fr 1fr"] {
                display: flex !important;
                flex-direction: column !important;
                gap: 0.75rem !important;
            }

            /* Student form: prevent inputs from being too small */
            input, select, textarea { font-size: 16px !important; }
        }
    </style>

    <div class="container">
        <div class="controls">
            <div class="class-selector">
                <label>Select Class:</label>
                <select id="classSelect" onchange="window.location.href='dashboard.php?class=' + this.value" <?php echo empty($classes) ? 'disabled' : ''; ?>>
                    <?php if (empty($classes)): ?>
                        <option value="">No classes available</option>
                    <?php else: ?>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?php echo $class['id']; ?>" <?php echo $selectedClassId == $class['id'] ? 'selected' : ''; ?>>
                                <?php 
                                    // Display with stream if applicable
                                    require_once __DIR__ . '/controllers/ClassController.php';
                                    echo htmlspecialchars(ClassController::getDisplayName($class));
                                ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>
            <div style="display: flex; gap: 1rem;">
                <button class="btn btn-success" onclick="showBulkImportModal()" <?php echo empty($selectedClassId) ? 'disabled title="Please select a class first"' : ''; ?>>📥 Bulk Import</button>
                <button class="btn btn-success" onclick="showAddModal()" <?php echo empty($selectedClassId) ? 'disabled title="Please select a class first"' : ''; ?>>+ Add Student</button>
                <?php if ($isAdmin): ?>
                <button class="btn" style="background: #3498db; color: white;" onclick="showBulkPromoteModal()" id="promoteBtn" disabled title="Select students to promote">🎓 Promote Selected</button>
                <?php endif; ?>
            </div>
        </div>
        
        <?php 
        // Show candidate index info for Basic 9 classes
        if ($selectedClassId) {
            $stmt = $db->prepare("SELECT class_name FROM classes WHERE id = ? AND school_id = ?");
            $stmt->execute([$selectedClassId, $_SESSION['school_id']]);
            $currentClass = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($currentClass) {
                // Check for Basic 9, Basic Nine, or Basic9
                $isBasic9 = stripos($currentClass['class_name'], 'Basic 9') !== false || 
                           stripos($currentClass['class_name'], 'Basic Nine') !== false || 
                           stripos($currentClass['class_name'], 'Basic9') !== false;
                
                if ($isBasic9) {
                    $schoolCodeColumnExists = false;
                    try {
                        $columnCheckStmt = $db->query("SHOW COLUMNS FROM school_info LIKE 'school_code'");
                        $schoolCodeColumnExists = (bool) $columnCheckStmt->fetch(PDO::FETCH_ASSOC);
                    } catch (Exception $e) {
                        $schoolCodeColumnExists = false;
                    }

                    $schoolInfo = null;
                    if ($schoolCodeColumnExists) {
                        $stmt = $db->prepare("SELECT school_code FROM school_info WHERE id = ?");
                        $stmt->execute([$_SESSION['school_id']]);
                        $schoolInfo = $stmt->fetch(PDO::FETCH_ASSOC);
                    }
                    
                    if ($schoolCodeColumnExists && $schoolInfo && !empty($schoolInfo['school_code'])) {
                        echo '<div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 15px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">';
                        echo '<strong>🎓 Basic 9 Class - Candidate Index Auto-Generation Enabled</strong><br>';
                        echo '<span style="font-size: 14px; opacity: 0.9;">Candidate index numbers will be automatically generated when adding new students. Format: ' . htmlspecialchars($schoolInfo['school_code']) . 'XXX' . date('y') . '</span>';
                        echo '</div>';
                    } elseif (!$schoolCodeColumnExists) {
                        echo '<div style="background: #fff3cd; color: #856404; padding: 15px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #ffc107;">';
                        echo '<strong>⚠️ Candidate Index Setup Incomplete</strong><br>';
                        echo '<span style="font-size: 14px;">Run database/add_candidate_index_generation.sql to add the school code column required for index generation.</span>';
                        echo '</div>';
                    } else {
                        echo '<div style="background: #fff3cd; color: #856404; padding: 15px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #ffc107;">';
                        echo '<strong>⚠️ Set School Code for Candidate Index Generation</strong><br>';
                        echo '<span style="font-size: 14px;">Go to Settings → School Information to set your 7-digit school code for automatic candidate index generation.</span>';
                        echo '</div>';
                    }
                }
            }
        }
        ?>
        
        <!-- Class Settings Section (Admin Only) -->
        <?php if ($isAdmin && $selectedClassId): ?>
        <div class="controls" style="background: #f7fafc; border-left: 4px solid #667eea;">
            <div style="width: 100%;">
                <h3 style="margin-bottom: 1rem; color: #667eea;">⚙️ Class Settings - Apply to All Students</h3>
                <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1rem; align-items: end;">
                    <div>
                        <label style="display: block; margin-bottom: 0.5rem; font-weight: 600;">Total Attendance (Total Days)</label>
                        <input type="number" id="classTotalAttendance" class="btn" style="width: 100%; text-align: left; background: white; border: 2px solid #e1e8ed;" placeholder="e.g., 71" min="1" max="365">
                        <small style="color: #718096;">This will set the total attendance days for all students in this class. Students' individual attendance (days present) will remain unchanged.</small>
                    </div>
                    <div>
                        <button class="btn btn-primary" onclick="applyClassSettings()" style="width: 100%;">
                            💾 Apply to All Students
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Show/Search/Actions Section -->
        <div class="controls">
            <?php if ($selectedClass && !empty($selectedClass['stream'])): ?>
            <div style="background: #ebf8ff; border-left: 4px solid #3182ce; padding: 1rem; margin-bottom: 1rem; border-radius: 5px;">
                <strong>📚 Stream View:</strong> You are viewing <strong><?php echo htmlspecialchars(ClassController::getDisplayName($selectedClass)); ?></strong>
                <br><small style="color: #2c5282;">Students and data shown are specific to this stream only.</small>
            </div>
            <?php endif; ?>
            <div style="display: flex; gap: 1rem; flex-wrap: wrap; width: 100%;">
                <div style="flex: 0 0 150px;">
                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 600;">Show Entries</label>
                    <select id="showEntries" class="btn" style="width: 100%; text-align: left; background: white; border: 2px solid #e1e8ed;">
                        <option value="10">Show 10</option>
                        <option value="50">Show 50</option>
                        <option value="100">Show 100</option>
                        <option value="all">Show All</option>
                    </select>
                </div>
                <div style="flex: 1; min-width: 200px;">
                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 600;">Search Student</label>
                    <input type="text" id="searchStudent" class="btn" style="width: 100%; text-align: left; background: white; border: 2px solid #e1e8ed;" placeholder="Search by name...">
                </div>
                <?php if ($isAdmin && $selectedClassId): ?>
                <div style="display: flex; align-items: flex-end; gap: 0.5rem;">
                    <button class="btn btn-danger" onclick="confirmDeleteAll()">
                        🗑️ Delete All Students
                    </button>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="students-table">
            <?php if (empty($students)): ?>
                <div class="empty-state">
                    <h3>No Students Found</h3>
                    <p>Click "Add Student" to add students to this class.</p>
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)" title="Select All"></th>
                            <th>Student ID</th>
                            <th>Name</th>
                            <th>Gender</th>
                            <th>Attendance</th>
                            <th>Total Days</th>
                            <th>Interest</th>
                            <th>Conduct</th>
                            <th>Form Master Remarks</th>
                            <th>Head Master Remarks</th>
                            <th>Promoted</th>
                            <th>To Class</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $student): ?>
                            <tr>
                                <?php if ($isAdmin): ?><td><input type="checkbox" class="student-checkbox" value="<?php echo $student['id']; ?>" onchange="updatePromoteButton()"></td><?php endif; ?>
                                <td><?php echo htmlspecialchars($student['student_id'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($student['student_name'] ?? ''); ?></td>
                                <td><?php echo $student['gender'] ? ($student['gender'] === 'M' ? 'Male' : ($student['gender'] === 'F' ? 'Female' : htmlspecialchars($student['gender']))) : '-'; ?></td>
                                <td><?php echo $student['attendance'] ? htmlspecialchars((string)$student['attendance']) : '0'; ?></td>
                                <td><?php echo $student['total_attendance'] ? htmlspecialchars((string)$student['total_attendance']) : '-'; ?></td>
                                <td><?php echo $student['interest'] ? htmlspecialchars($student['interest']) : '-'; ?></td>
                                <td><?php echo $student['conduct'] ? htmlspecialchars($student['conduct']) : '-'; ?></td>
                                <td><?php echo $student['form_master_remarks'] ? htmlspecialchars($student['form_master_remarks']) : '-'; ?></td>
                                <td><?php echo $student['headmaster_remarks'] ? htmlspecialchars($student['headmaster_remarks']) : '-'; ?></td>
                                <?php if ($isAdmin): ?>
                                <td><?php echo $student['promoted'] ? htmlspecialchars($student['promoted']) : '-'; ?></td>
                                <td><?php echo $student['promoted_to_class'] ? htmlspecialchars($student['promoted_to_class']) : '-'; ?></td>
                                <?php endif; ?>
                                <td>
                                    <div class="actions">
                                        <button class="btn btn-primary btn-sm" onclick='editStudent(<?php echo json_encode($student); ?>)'>Edit</button>
                                        <button class="btn btn-danger btn-sm" onclick="deleteStudent(<?php echo $student['id']; ?>)">Delete</button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Bulk Promote Modal -->
    <div class="modal" id="bulkPromoteModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>🎓 Promote Selected Students</h2>
                <button class="close-btn" onclick="closeBulkPromoteModal()">&times;</button>
            </div>
            <form id="bulkPromoteForm">
                <input type="hidden" name="action" value="bulk_promote">
                <div class="form-group">
                    <label>Target Class</label>
                    <select name="target_class_id" required>
                        <option value="">Select destination class...</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?php echo $class['id']; ?>"><?php require_once __DIR__ . '/controllers/ClassController.php'; echo htmlspecialchars(ClassController::getDisplayName($class)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <p id="selectedCount" style="color: #667eea; font-weight: bold; margin: 10px 0;"></p>
                <div class="form-actions">
                    <button type="button" class="btn" onclick="closeBulkPromoteModal()">Cancel</button>
                    <button type="submit" class="btn" style="background: #3498db;">Promote Students</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Bulk Promote Modal -->
    <div class="modal" id="bulkPromoteModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>🎓 Promote Selected Students</h2>
                <button class="close-btn" onclick="closeBulkPromoteModal()">&times;</button>
            </div>
            <form id="bulkPromoteForm">
                <input type="hidden" name="action" value="bulk_promote">
                <div class="form-group">
                    <label>Target Class</label>
                    <select name="target_class_id" required>
                        <option value="">Select destination class...</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?php echo $class['id']; ?>"><?php require_once __DIR__ . '/controllers/ClassController.php'; echo htmlspecialchars(ClassController::getDisplayName($class)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <p id="selectedCount" style="color: #667eea; font-weight: bold; margin: 10px 0;"></p>
                <div class="form-actions">
                    <button type="button" class="btn" onclick="closeBulkPromoteModal()">Cancel</button>
                    <button type="submit" class="btn" style="background: #3498db;">Promote Students</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Bulk Import Modal -->
    <div class="modal" id="bulkImportModal">
        <div class="modal-content" style="max-width:640px;">
            <div class="modal-header">
                <h2>📥 Bulk Import Students</h2>
                <button class="close-btn" onclick="closeBulkImportModal()">&times;</button>
            </div>

            <!-- Step 1: Download Template -->
            <div style="background:#eef6ff;border:1px solid #bee3f8;border-radius:6px;padding:12px 16px;margin-bottom:14px;">
                <strong style="color:#2b6cb0;">Step 1 — Download the template</strong>
                <p style="margin:6px 0 10px 0;font-size:13px;color:#4a5568;">
                    Download the CSV template, fill in your students' details in Excel or Google Sheets, then upload it below.
                </p>
                <a href="download_student_template.php" class="btn" style="background:#2b6cb0;color:#fff;text-decoration:none;display:inline-block;padding:7px 18px;font-size:13px;">
                    ⬇️ Download CSV Template
                </a>
                <div style="margin-top:10px;font-size:12px;color:#718096;">
                    <strong>Columns:</strong>
                    Student Name <em>(required)</em> &nbsp;|&nbsp;
                    Parent/Guardian Name &nbsp;|&nbsp;
                    Parent Phone &nbsp;|&nbsp;
                    Parent WhatsApp &nbsp;|&nbsp;
                    Parent Email
                </div>
            </div>

            <!-- Step 2: Upload -->
            <div style="background:#f0fff4;border:1px solid #9ae6b4;border-radius:6px;padding:12px 16px;margin-bottom:14px;">
                <strong style="color:#276749;">Step 2 — Upload completed template</strong>
                <div style="margin-top:8px;">
                    <input type="file" id="csvTemplateUpload" accept=".csv,text/csv"
                           style="font-size:13px;padding:4px;"
                           onchange="parseCsvTemplate(this)">
                    <small style="display:block;margin-top:4px;color:#718096;">Accepted format: .csv (the downloaded template)</small>
                </div>
                <div id="csvParseStatus" style="display:none;margin-top:8px;font-size:13px;font-weight:600;"></div>
            </div>

            <form id="bulkImportForm">
                <input type="hidden" name="action" value="bulk_import">
                <input type="hidden" name="class_id" value="<?php echo $selectedClassId; ?>">

                <!-- Step 3: Review / manual edit -->
                <div class="form-group">
                    <label style="font-weight:600;color:#2d3748;">Step 3 — Review data before importing</label>
                    <textarea name="student_names" id="student_names" rows="10"
                        placeholder="After uploading the CSV, data will appear here for review.&#10;&#10;You can also type manually:&#10;Nana Yaw | Kofi Mensah | 0244123456&#10;Ama Tipa | Akua Serwah | 0554987654"
                        required style="font-family:monospace;font-size:12px;"></textarea>
                    <small style="color:#718096;">One student per line. Columns: Name | Guardian Name | Phone | WhatsApp | Email. Student IDs are auto-generated.</small>
                </div>

                <div class="form-group" style="display:flex;gap:1rem;justify-content:flex-end;align-items:center;">
                    <button type="button" class="btn" onclick="closeBulkImportModal(); document.getElementById('csvTemplateUpload').value=''; document.getElementById('csvParseStatus').style.display='none';">Cancel</button>
                    <button type="submit" class="btn btn-success">✅ Import Students</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Add/Edit Student Modal -->
    <div class="modal" id="studentModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modalTitle">Add Student</h2>
                <button class="close-btn" onclick="closeModal()">&times;</button>
            </div>
            <form id="studentForm">
                <input type="hidden" id="studentId" name="id">
                <input type="hidden" name="action" id="formAction" value="add_student">
                <input type="hidden" name="class_id" value="<?php echo $selectedClassId; ?>">
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Student ID *</label>
                        <input type="text" name="student_id" id="student_id" required>
                    </div>
                    <div class="form-group">
                        <label>Student Name *</label>
                        <input type="text" name="student_name" id="student_name" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Candidate Index Number</label>
                        <input type="text" name="candidate_index_number" id="candidate_index_number" placeholder="For BECE Mock Exam">
                        <small style="color: #718096; font-size: 0.85rem;">Required for Basic 9 Mock Examinations</small>
                    </div>
                    <div class="form-group">
                        <label>Gender *</label>
                        <select name="gender" id="gender" required>
                            <option value="">Select Gender</option>
                            <option value="M">Male</option>
                            <option value="F">Female</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Student Photo</label>
                        <input type="file" name="photo" id="photo" accept="image/*">
                        <small style="color: #718096; font-size: 0.85rem;">Upload student photo (optional)</small>
                        <div id="photoPreview" style="margin-top: 0.5rem; display: none;">
                            <img id="photoPreviewImg" src="" alt="Preview" style="max-width: 100px; max-height: 100px; border: 1px solid #ddd; border-radius: 4px;">
                        </div>
                    </div>
                    
                    <!-- Student Performance Summary (shown only when editing) -->
                    <div class="form-group">
                        <div id="studentPerformanceSummary" style="display: none; background: #eef2ff; border: 2px solid #6366f1; padding: 1rem; border-radius: 8px; box-shadow: 0 2px 4px rgba(99, 102, 241, 0.1);">
                            <h4 style="margin: 0 0 0.75rem 0; color: #4338ca; font-size: 14px; font-weight: 700; text-align: center;">
                                📊 Student Performance
                            </h4>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                                <div style="background: white; padding: 0.75rem; border-radius: 6px; text-align: center; border: 1px solid #e0e7ff;">
                                    <div style="font-size: 11px; color: #6b7280; font-weight: 600; text-transform: uppercase; margin-bottom: 0.25rem;">Total Marks</div>
                                    <div style="font-size: 28px; font-weight: 700; color: #4338ca;" id="perf_total">-</div>
                                </div>
                                <div style="background: white; padding: 0.75rem; border-radius: 6px; text-align: center; border: 1px solid #e0e7ff;">
                                    <div style="font-size: 11px; color: #6b7280; font-weight: 600; text-transform: uppercase; margin-bottom: 0.25rem;">Position</div>
                                    <div style="font-size: 28px; font-weight: 700; color: #dc2626;" id="perf_position">-</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Attendance (Days Present)</label>
                        <input type="number" name="attendance" id="attendance" placeholder="e.g., 65" min="0">
                    </div>
                    <div class="form-group">
                        <label>Total Days</label>
                        <input type="number" name="total_attendance" id="total_attendance" placeholder="e.g., 71" min="1">
                        <small style="color: #718096; font-size: 0.85rem;">Leave blank to use class setting</small>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Interest</label>
                    <select name="interest" id="interest" onchange="toggleCustomRemarks('interest')">
                        <option value="">Select Interest</option>
                        <?php foreach (DROPDOWN_OPTIONS['interest'] as $option): ?>
                            <option value="<?php echo htmlspecialchars($option); ?>"><?php echo htmlspecialchars($option); ?></option>
                        <?php endforeach; ?>
                        <option value="__custom__">Others (Type your own)</option>
                    </select>
                    <input type="text" id="interest_custom" class="form-control" style="margin-top: 0.5rem; display: none;" placeholder="Type custom interest...">
                </div>
                
                <div class="form-group">
                    <label>Conduct</label>
                    <select name="conduct" id="conduct" onchange="toggleCustomRemarks('conduct')">
                        <option value="">Select Conduct</option>
                        <?php foreach (DROPDOWN_OPTIONS['conduct'] as $option): ?>
                            <option value="<?php echo htmlspecialchars($option); ?>"><?php echo htmlspecialchars($option); ?></option>
                        <?php endforeach; ?>
                        <option value="__custom__">Others (Type your own)</option>
                    </select>
                    <input type="text" id="conduct_custom" class="form-control" style="margin-top: 0.5rem; display: none;" placeholder="Type custom conduct...">
                </div>
                
                <div class="form-group">
                    <label>Form Master Remarks</label>
                    <select name="form_master_remarks" id="form_master_remarks" onchange="toggleCustomRemarks('form_master')">
                        <option value="">Select Remarks</option>
                        <?php foreach (DROPDOWN_OPTIONS['formMasterRemarks'] as $option): ?>
                            <option value="<?php echo htmlspecialchars($option); ?>"><?php echo htmlspecialchars($option); ?></option>
                        <?php endforeach; ?>
                        <option value="__custom__">Others (Type your own)</option>
                    </select>
                    <input type="text" id="form_master_remarks_custom" class="form-control" style="margin-top: 0.5rem; display: none;" placeholder="Type custom remarks...">
                </div>
                
                <div class="form-group">
                    <label>Head Master Remarks</label>
                    <select name="headmaster_remarks" id="headmaster_remarks" onchange="toggleCustomRemarks('headmaster')">
                        <option value="">Select Remarks</option>
                        <?php foreach (DROPDOWN_OPTIONS['headMasterRemarks'] as $option): ?>
                            <option value="<?php echo htmlspecialchars($option); ?>"><?php echo htmlspecialchars($option); ?></option>
                        <?php endforeach; ?>
                        <option value="__custom__">Others (Type your own)</option>
                    </select>
                    <input type="text" id="headmaster_remarks_custom" class="form-control" style="margin-top: 0.5rem; display: none;" placeholder="Type custom remarks...">
                </div>
                
                <div class="form-group">
                    <label>Promoted</label>
                    <select name="promoted" id="promoted">
                        <option value="">Select Status</option>
                        <?php foreach (DROPDOWN_OPTIONS['promoted'] as $option): ?>
                            <option value="<?php echo htmlspecialchars($option); ?>"><?php echo htmlspecialchars($option); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Promoted To Class</label>
                    <select name="promoted_to_class" id="promoted_to_class">
                        <option value="">Select Class</option>
                        <?php foreach (DROPDOWN_OPTIONS['toClass'] as $option): ?>
                            <option value="<?php echo htmlspecialchars($option); ?>"><?php echo htmlspecialchars($option); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Remarks</label>
                    <textarea name="remarks" id="remarks"></textarea>
                </div>

                <!-- ══ Parent / Guardian Contact ══ -->
                <div style="background:#f0fdf4;border:2px solid #22c55e;border-radius:8px;padding:1rem 1.25rem;margin-top:0.5rem;">
                    <h4 style="margin:0 0 0.75rem;color:#15803d;font-size:14px;font-weight:700;">
                        👨‍👩‍👧 Parent / Guardian Contact
                        <span style="font-weight:400;color:#6b7280;font-size:12px;"> — receives report card notifications</span>
                    </h4>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Guardian Full Name</label>
                            <input type="text" name="parent_name" id="parent_name" placeholder="e.g. Kofi Mensah">
                        </div>
                        <div class="form-group">
                            <label>Relationship</label>
                            <select name="parent_relationship" id="parent_relationship">
                                <option value="">Select…</option>
                                <option value="Father">Father</option>
                                <option value="Mother">Mother</option>
                                <option value="Guardian">Guardian</option>
                                <option value="Uncle">Uncle</option>
                                <option value="Aunt">Aunt</option>
                                <option value="Grandparent">Grandparent</option>
                                <option value="Sibling">Sibling</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>📱 Mobile / SMS Number</label>
                            <input type="text" name="parent_phone" id="parent_phone" placeholder="e.g. 0244123456">
                            <small style="color:#6b7280;font-size:0.8rem;">Used for SMS notifications</small>
                        </div>
                        <div class="form-group">
                            <label>💬 WhatsApp Number</label>
                            <input type="text" name="parent_whatsapp" id="parent_whatsapp" placeholder="e.g. 0244123456">
                            <small style="color:#6b7280;font-size:0.8rem;">Leave blank if same as above</small>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>📧 Email Address <span style="color:#9ca3af;font-size:0.8rem;">(optional)</span></label>
                        <input type="email" name="parent_email" id="parent_email" placeholder="parent@example.com">
                    </div>
                </div>

                <div class="form-group" style="display: flex; gap: 1rem; justify-content: flex-end; margin-top:1rem;">
                    <button type="button" class="btn" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn btn-success">Save Student</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        // Photo preview
        document.getElementById('photo').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('photoPreviewImg').src = e.target.result;
                    document.getElementById('photoPreview').style.display = 'block';
                };
                reader.readAsDataURL(file);
            } else {
                document.getElementById('photoPreview').style.display = 'none';
            }
        });
        
        function showAddModal() {
            document.getElementById('modalTitle').textContent = 'Add Student';
            document.getElementById('formAction').value = 'add_student';
            document.getElementById('studentForm').reset();
            document.getElementById('studentId').value = '';
            document.getElementById('photoPreview').style.display = 'none';
            document.getElementById('interest_custom').style.display = 'none';
            document.getElementById('conduct_custom').style.display = 'none';
            document.getElementById('form_master_remarks_custom').style.display = 'none';
            document.getElementById('headmaster_remarks_custom').style.display = 'none';
            document.getElementById('parent_name').value = '';
            document.getElementById('parent_relationship').value = '';
            document.getElementById('parent_phone').value = '';
            document.getElementById('parent_whatsapp').value = '';
            document.getElementById('parent_email').value = '';
            
            // Clear old photo URL when adding new student
            const oldPhotoInput = document.getElementById('old_photo_url');
            if (oldPhotoInput) {
                oldPhotoInput.value = '';
            }
            
            document.getElementById('studentModal').classList.add('active');
        }
        
        function openModal() {
            document.getElementById('modalTitle').textContent = 'Add Student';
            document.getElementById('formAction').value = 'add_student';
            document.getElementById('studentForm').reset();
            document.getElementById('studentId').value = '';
            document.getElementById('photoPreview').style.display = 'none';
            document.getElementById('studentPerformanceSummary').style.display = 'none';
            document.getElementById('studentModal').classList.add('active');
        }
        
        function loadStudentPerformance(studentId) {
            console.log('Loading performance for student ID:', studentId);
            
            // Fetch student scores via AJAX
            fetch(`?action=get_student_performance&student_id=${studentId}`)
                .then(response => {
                    console.log('Response status:', response.status);
                    return response.json();
                })
                .then(data => {
                    console.log('Performance data received:', data);
                    if (data.success) {
                        const perfDiv = document.getElementById('studentPerformanceSummary');
                        console.log('Performance div found:', perfDiv);
                        perfDiv.style.display = 'block';
                        document.getElementById('perf_total').textContent = data.total || '0';
                        document.getElementById('perf_position').textContent = data.position || 'N/A';
                    } else {
                        console.log('Performance data not successful:', data);
                        document.getElementById('studentPerformanceSummary').style.display = 'none';
                    }
                })
                .catch(error => {
                    console.error('Error loading student performance:', error);
                    document.getElementById('studentPerformanceSummary').style.display = 'none';
                });
        }
        
        function toggleCustomRemarks(type) {
            const select = document.getElementById(type === 'interest' || type === 'conduct' ? type : type + '_remarks');
            const customInput = document.getElementById(type === 'interest' || type === 'conduct' ? type + '_custom' : type + '_remarks_custom');
            
            if (select.value === '__custom__') {
                customInput.style.display = 'block';
                customInput.focus();
            } else {
                customInput.style.display = 'none';
                customInput.value = '';
            }
        }
        
        function editStudent(student) {
            document.getElementById('modalTitle').textContent = 'Edit Student';
            document.getElementById('formAction').value = 'update_student';
            document.getElementById('studentId').value = student.id;
            document.getElementById('student_id').value = student.student_id;
            document.getElementById('student_name').value = student.student_name;
            document.getElementById('candidate_index_number').value = student.candidate_index_number || '';
            document.getElementById('gender').value = student.gender || '';
            document.getElementById('attendance').value = student.attendance || '';
            document.getElementById('total_attendance').value = student.total_attendance || '';
            
            // Load student performance data
            loadStudentPerformance(student.id);
            
            // Handle interest
            const interestSelect = document.getElementById('interest');
            const interestCustom = document.getElementById('interest_custom');
            const interestValue = student.interest || '';
            
            let interestFound = false;
            for (let i = 0; i < interestSelect.options.length; i++) {
                if (interestSelect.options[i].value === interestValue) {
                    interestSelect.value = interestValue;
                    interestFound = true;
                    break;
                }
            }
            
            if (!interestFound && interestValue) {
                interestSelect.value = '__custom__';
                interestCustom.style.display = 'block';
                interestCustom.value = interestValue;
            } else {
                interestCustom.style.display = 'none';
            }
            
            // Handle conduct
            const conductSelect = document.getElementById('conduct');
            const conductCustom = document.getElementById('conduct_custom');
            const conductValue = student.conduct || '';
            
            let conductFound = false;
            for (let i = 0; i < conductSelect.options.length; i++) {
                if (conductSelect.options[i].value === conductValue) {
                    conductSelect.value = conductValue;
                    conductFound = true;
                    break;
                }
            }
            
            if (!conductFound && conductValue) {
                conductSelect.value = '__custom__';
                conductCustom.style.display = 'block';
                conductCustom.value = conductValue;
            } else {
                conductCustom.style.display = 'none';
            }
            
            // Handle form master remarks
            const formMasterSelect = document.getElementById('form_master_remarks');
            const formMasterCustom = document.getElementById('form_master_remarks_custom');
            const formMasterValue = student.form_master_remarks || '';
            
            // Check if value exists in dropdown
            let formMasterFound = false;
            for (let i = 0; i < formMasterSelect.options.length; i++) {
                if (formMasterSelect.options[i].value === formMasterValue) {
                    formMasterSelect.value = formMasterValue;
                    formMasterFound = true;
                    break;
                }
            }
            
            if (!formMasterFound && formMasterValue) {
                formMasterSelect.value = '__custom__';
                formMasterCustom.style.display = 'block';
                formMasterCustom.value = formMasterValue;
            } else {
                formMasterCustom.style.display = 'none';
            }
            
            // Handle head master remarks
            const headMasterSelect = document.getElementById('headmaster_remarks');
            const headMasterCustom = document.getElementById('headmaster_remarks_custom');
            const headMasterValue = student.headmaster_remarks || '';
            
            // Check if value exists in dropdown
            let headMasterFound = false;
            for (let i = 0; i < headMasterSelect.options.length; i++) {
                if (headMasterSelect.options[i].value === headMasterValue) {
                    headMasterSelect.value = headMasterValue;
                    headMasterFound = true;
                    break;
                }
            }
            
            if (!headMasterFound && headMasterValue) {
                headMasterSelect.value = '__custom__';
                headMasterCustom.style.display = 'block';
                headMasterCustom.value = headMasterValue;
            } else {
                headMasterCustom.style.display = 'none';
            }
            
            document.getElementById('promoted').value = student.promoted || '';
            document.getElementById('promoted_to_class').value = student.promoted_to_class || '';
            document.getElementById('remarks').value = student.remarks || '';

            // Parent contact fields
            document.getElementById('parent_name').value = student.parent_name || '';
            document.getElementById('parent_relationship').value = student.parent_relationship || '';
            document.getElementById('parent_phone').value = student.parent_phone || '';
            document.getElementById('parent_whatsapp').value = student.parent_whatsapp || '';
            document.getElementById('parent_email').value = student.parent_email || '';
            
            const photoPreview = document.getElementById('photoPreview');
            const photoPreviewImg = document.getElementById('photoPreviewImg');
            if (student.photo_url) {
                photoPreviewImg.src = student.photo_url;
                photoPreview.style.display = 'block';
                // Store old photo URL in a hidden field
                let oldPhotoInput = document.getElementById('old_photo_url');
                if (!oldPhotoInput) {
                    oldPhotoInput = document.createElement('input');
                    oldPhotoInput.type = 'hidden';
                    oldPhotoInput.id = 'old_photo_url';
                    oldPhotoInput.name = 'old_photo_url';
                    document.getElementById('studentForm').appendChild(oldPhotoInput);
                }
                oldPhotoInput.value = student.photo_url;
            } else {
                photoPreview.style.display = 'none';
                const oldPhotoInput = document.getElementById('old_photo_url');
                if (oldPhotoInput) {
                    oldPhotoInput.value = '';
                }
            }
            
            document.getElementById('studentModal').classList.add('active');
        }
        
        function closeModal() {
            document.getElementById('studentModal').classList.remove('active');
        }
        
        function toggleSelectAll(checkbox) {
            const checkboxes = document.querySelectorAll('.student-checkbox');
            checkboxes.forEach(cb => cb.checked = checkbox.checked);
            updatePromoteButton();
        }

        function updatePromoteButton() {
            const selected = document.querySelectorAll('.student-checkbox:checked');
            const promoteBtn = document.getElementById('promoteBtn');
            if (selected.length > 0) {
                promoteBtn.disabled = false;
                promoteBtn.title = `Promote ${selected.length} student(s)`;
            } else {
                promoteBtn.disabled = true;
                promoteBtn.title = 'Select students to promote';
            }
        }

        function showBulkPromoteModal() {
            const selected = document.querySelectorAll('.student-checkbox:checked');
            if (selected.length === 0) {
                alert('Please select at least one student to promote');
                return;
            }
            document.getElementById('selectedCount').textContent = `Promoting ${selected.length} student(s)`;
            document.getElementById('bulkPromoteModal').classList.add('active');
        }

        function closeBulkPromoteModal() {
            document.getElementById('bulkPromoteModal').classList.remove('active');
            document.getElementById('bulkPromoteForm').reset();
        }
        
        function parseCsvTemplate(input) {
            const file = input.files[0];
            if (!file) return;

            const statusEl = document.getElementById('csvParseStatus');
            statusEl.style.display = 'block';
            statusEl.style.color = '#718096';
            statusEl.textContent = 'Reading file…';

            const reader = new FileReader();
            reader.onload = function(e) {
                const text = e.target.result.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
                const lines = text.split('\n').map(l => l.trim()).filter(l => l.length > 0);

                if (lines.length < 2) {
                    statusEl.style.color = '#e53e3e';
                    statusEl.textContent = '⚠ File appears empty (no data rows found).';
                    return;
                }

                // Skip header row (first line)
                const dataLines = lines.slice(1);
                const rows = [];
                let skipped = 0;

                dataLines.forEach(function(line) {
                    // Simple CSV parse: handles quoted fields
                    const cols = [];
                    let cur = '', inQ = false;
                    for (let i = 0; i < line.length; i++) {
                        const ch = line[i];
                        if (ch === '"') { inQ = !inQ; }
                        else if (ch === ',' && !inQ) { cols.push(cur.trim()); cur = ''; }
                        else { cur += ch; }
                    }
                    cols.push(cur.trim());

                    // col 0 = Student Name (required)
                    const name = cols[0] ? cols[0].replace(/^"+|"+$/g, '').trim() : '';
                    if (!name) { skipped++; return; }

                    const parts = [
                        name,
                        (cols[1] || '').replace(/^"+|"+$/g, '').trim(),
                        (cols[2] || '').replace(/^"+|"+$/g, '').trim(),
                        (cols[3] || '').replace(/^"+|"+$/g, '').trim(),
                        (cols[4] || '').replace(/^"+|"+$/g, '').trim(),
                    ];

                    // Drop trailing empty fields
                    while (parts.length > 1 && parts[parts.length - 1] === '') parts.pop();

                    rows.push(parts.join(' | '));
                });

                if (rows.length === 0) {
                    statusEl.style.color = '#e53e3e';
                    statusEl.textContent = '⚠ No valid student names found in the file.';
                    return;
                }

                document.getElementById('student_names').value = rows.join('\n');
                statusEl.style.color = '#276749';
                statusEl.textContent = '✓ ' + rows.length + ' student' + (rows.length !== 1 ? 's' : '') + ' loaded' +
                    (skipped > 0 ? ' (' + skipped + ' blank row' + (skipped !== 1 ? 's' : '') + ' skipped)' : '') +
                    '. Review below before importing.';
            };
            reader.onerror = function() {
                statusEl.style.color = '#e53e3e';
                statusEl.textContent = '⚠ Could not read file. Please try again.';
            };
            reader.readAsText(file, 'UTF-8');
        }

        function showBulkImportModal() {
            document.getElementById('student_names').value = '';
            document.getElementById('bulkImportModal').classList.add('active');
        }
        
        function closeBulkImportModal() {
            document.getElementById('bulkImportModal').classList.remove('active');
        }
        
        function deleteStudent(id) {
            if (confirm('Are you sure you want to delete this student?')) {
                const formData = new FormData();
                formData.append('action', 'delete_student');
                formData.append('id', id);
                
                fetch('dashboard.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert('Error: ' + data.error);
                    }
                });
            }
        }
        
        document.getElementById('studentForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            
            // Handle custom interest
            const interestSelect = document.getElementById('interest');
            const interestCustom = document.getElementById('interest_custom');
            if (interestSelect.value === '__custom__' && interestCustom.value) {
                formData.set('interest', interestCustom.value);
            }
            
            // Handle custom conduct
            const conductSelect = document.getElementById('conduct');
            const conductCustom = document.getElementById('conduct_custom');
            if (conductSelect.value === '__custom__' && conductCustom.value) {
                formData.set('conduct', conductCustom.value);
            }
            
            // Handle custom remarks
            const formMasterSelect = document.getElementById('form_master_remarks');
            const formMasterCustom = document.getElementById('form_master_remarks_custom');
            if (formMasterSelect.value === '__custom__' && formMasterCustom.value) {
                formData.set('form_master_remarks', formMasterCustom.value);
            }
            
            const headMasterSelect = document.getElementById('headmaster_remarks');
            const headMasterCustom = document.getElementById('headmaster_remarks_custom');
            if (headMasterSelect.value === '__custom__' && headMasterCustom.value) {
                formData.set('headmaster_remarks', headMasterCustom.value);
            }
            
            // Log form data for debugging
            console.log('Submitting student form:');
            for (let [key, value] of formData.entries()) {
                console.log(key + ': ' + value);
            }
            
            fetch('dashboard.php', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(data => {
                console.log('Response:', data);
                if (data.success) {
                    alert(data.message || 'Student saved successfully');
                    location.reload();
                } else {
                    alert('Error: ' + (data.error || 'Unknown error occurred'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred: ' + error.message);
            });
        });
        
        // Bulk promote form submission
        document.getElementById('bulkPromoteForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const selected = document.querySelectorAll('.student-checkbox:checked');
            const studentIds = Array.from(selected).map(cb => cb.value);
            
            if (studentIds.length === 0) {
                alert('No students selected');
                return;
            }
            
            const formData = new FormData(this);
            studentIds.forEach(id => formData.append('student_ids[]', id));
            
            if (!confirm(`Are you sure you want to promote ${studentIds.length} student(s)?`)) {
                return;
            }
            
            fetch('dashboard.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    closeBulkPromoteModal();
                    location.reload();
                } else if (data.needs_clear) {
                    // Class needs to be cleared first
                    if (confirm(data.error + '\n\nDo you want to GRADUATE/CLEAR the ' + data.target_class_name + ' class now?\n\n⚠️ WARNING: This will remove all ' + data.existing_count + ' student(s) from ' + data.target_class_name + '. Their records will be deleted but scores remain in database for historical purposes.\n\nClick OK to graduate them, or Cancel to stop.')) {
                        graduateClass(data.target_class_id, data.target_class_name);
                    }
                } else {
                    alert('Error: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while promoting students');
            });
        });

        function graduateClass(classId, className) {
            const formData = new FormData();
            formData.append('action', 'graduate_class');
            formData.append('class_id', classId);
            
            fetch('dashboard.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message + '\n\nYou can now promote students to ' + className + '.');
                    closeBulkPromoteModal();
                    location.reload();
                } else {
                    alert('Error: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while graduating the class');
            });
        }

        // Bulk import form submission
        document.getElementById('bulkImportForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const studentNames = formData.get('student_names').trim();
            
            if (!studentNames) {
                alert('Please enter at least one student name');
                return;
            }
            
            // Show loading state
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.textContent;
            submitBtn.disabled = true;
            submitBtn.textContent = 'Importing...';
            
            fetch('dashboard.php', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(data => {
                submitBtn.disabled = false;
                submitBtn.textContent = originalText;
                
                if (data.success) {
                    alert(data.message || 'Students imported successfully');
                    closeBulkImportModal();
                    location.reload();
                } else {
                    alert('Error: ' + (data.error || 'Unknown error occurred'));
                }
            })
            .catch(error => {
                submitBtn.disabled = false;
                submitBtn.textContent = originalText;
                console.error('Error:', error);
                alert('An error occurred: ' + error.message);
            });
        });
        
        // Apply class settings to all students
        function applyClassSettings() {
            const totalAttendance = document.getElementById('classTotalAttendance').value;
            
            if (!totalAttendance) {
                alert('Please enter total attendance');
                return;
            }
            
            if (!confirm(`This will set attendance to ${totalAttendance} for all students in this class. Continue?`)) {
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'update_class_settings');
            formData.append('class_id', document.getElementById('classSelect').value);
            formData.append('total_attendance', totalAttendance);
            
            fetch('dashboard.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message || 'Class settings applied successfully');
                    location.reload();
                } else {
                    alert('Error: ' + (data.error || 'Unknown error occurred'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred: ' + error.message);
            });
        }
        
        // Confirm delete all students
        function confirmDeleteAll() {
            if (confirm('WARNING: This will permanently delete ALL students in this class. This action cannot be undone. Are you absolutely sure?')) {
                const formData = new FormData();
                formData.append('action', 'delete_all_students');
                formData.append('class_id', document.getElementById('classSelect').value);
                
                fetch('dashboard.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert(data.message || 'All students deleted successfully');
                        location.reload();
                    } else {
                        alert('Error: ' + (data.error || 'Unknown error occurred'));
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred: ' + error.message);
                });
            }
        }
        
        // Search functionality
        let searchTimeout;
        document.getElementById('searchStudent').addEventListener('input', function(e) {
            clearTimeout(searchTimeout);
            const searchTerm = e.target.value.toLowerCase();
            
            searchTimeout = setTimeout(function() {
                const rows = document.querySelectorAll('#studentTableBody tr');
                rows.forEach(row => {
                    const studentName = row.cells[1]?.textContent.toLowerCase() || '';
                    const studentId = row.cells[0]?.textContent.toLowerCase() || '';
                    
                    if (studentName.includes(searchTerm) || studentId.includes(searchTerm)) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                });
            }, 300);
        });
        
        // Show entries functionality
        document.getElementById('showEntries').addEventListener('change', function(e) {
            const limit = e.target.value;
            const rows = document.querySelectorAll('#studentTableBody tr');
            
            if (limit === 'all') {
                rows.forEach(row => row.style.display = '');
            } else {
                const limitNum = parseInt(limit);
                rows.forEach((row, index) => {
                    row.style.display = index < limitNum ? '' : 'none';
                });
            }
        });
    </script>
<?php require_once __DIR__ . '/components/footer.php'; ?>

