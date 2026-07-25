<?php
/**
 * Custom Class Management
 * Allows admins to add custom classes (e.g., Nursery, Pre-KG, etc.)
 * in addition to the default classes created during school registration
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/controllers/AuthController.php';
require_once __DIR__ . '/controllers/ClassController.php';

$auth = new Auth();
$auth->requireLogin();
$auth->requireAdmin();

require_once __DIR__ . '/config/database.php';
$db = Database::getInstance()->getConnection();
$classController = new ClassController();

$message = '';
$error = '';
$schoolId = $_SESSION['school_id'] ?? null;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'add_class') {
        try {
            $className = trim($_POST['class_name'] ?? '');
            $schoolTypeId = $_SESSION['school_type_id'] ?? null;
            $useStreams = isset($_POST['use_streams']) && $_POST['use_streams'] == '1';
            $streamsList = $_POST['streams'] ?? [];
            
            // Validate input
            if (empty($className)) {
                throw new Exception("Class name cannot be empty");
            }
            
            if (strlen($className) > 100) {
                throw new Exception("Class name must be 100 characters or less");
            }
            
            // Check if class name already exists for this school
            $stmt = $db->prepare("SELECT id FROM classes WHERE LOWER(class_name) = LOWER(?) AND school_id = ?");
            $stmt->execute([$className, $schoolId]);
            if ($stmt->fetch()) {
                throw new Exception("A class with this name already exists in your school");
            }
            
            $successCount = 0;
            $errors = [];
            
            if ($useStreams && !empty($streamsList)) {
                // Create class with multiple streams
                foreach ($streamsList as $stream) {
                    $stream = trim(strtoupper($stream));
                    if (empty($stream)) continue;
                    
                    $classCode = 'SCH' . $schoolId . '_' . strtoupper(str_replace(' ', '', $className)) . '-' . $stream;
                    
                    try {
                        $stmt = $db->prepare("
                            INSERT INTO classes (class_name, class_code, stream, school_type_id, school_id, total_attendance)
                            VALUES (?, ?, ?, ?, ?, 71)
                        ");
                        $stmt->execute([$className, $classCode, $stream, $schoolTypeId, $schoolId]);
                        $successCount++;
                    } catch (Exception $e) {
                        $errors[] = "Stream $stream: " . $e->getMessage();
                    }
                }
            } else {
                // Create single class without stream
                $classCode = 'SCH' . $schoolId . '_' . strtoupper(str_replace(' ', '', $className));
                
                $stmt = $db->prepare("
                    INSERT INTO classes (class_name, class_code, school_type_id, school_id, total_attendance)
                    VALUES (?, ?, ?, ?, 71)
                ");
                $stmt->execute([$className, $classCode, $schoolTypeId, $schoolId]);
                $successCount++;
            }
            
            if ($successCount > 0) {
                $message = "Successfully added '<strong>" . htmlspecialchars($className) . "</strong>' with $successCount class instance(s)!";
            }
            if (!empty($errors)) {
                $error = "Some instances failed: " . implode("; ", $errors);
            }
            
        } catch (Exception $e) {
            $error = "Error adding class: " . $e->getMessage();
        }
    } 
    elseif ($action === 'delete_class') {
        try {
            $classId = (int)($_POST['class_id'] ?? 0);
            
            if (!$classId) {
                throw new Exception("Invalid class ID");
            }
            
            // Verify class belongs to this school
            $stmt = $db->prepare("SELECT class_name, school_id FROM classes WHERE id = ?");
            $stmt->execute([$classId]);
            $classData = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$classData) {
                throw new Exception("Class not found");
            }
            
            if ($classData['school_id'] != $schoolId) {
                throw new Exception("Unauthorized: This class does not belong to your school");
            }
            
            // Check if class has students
            $stmt = $db->prepare("SELECT COUNT(*) as count FROM students WHERE class_id = ?");
            $stmt->execute([$classId]);
            $studentCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
            
            if ($studentCount > 0) {
                throw new Exception("Cannot delete class with assigned students. Please remove students first.");
            }
            
            // Check if class has form master assignments
            $stmt = $db->prepare("SELECT COUNT(*) as count FROM form_masters WHERE class_id = ?");
            $stmt->execute([$classId]);
            $formMasterCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
            
            if ($formMasterCount > 0) {
                throw new Exception("Cannot delete class with form master assignments. Please remove assignments first.");
            }
            
            // Check if class has subject assignments
            $stmt = $db->prepare("SELECT COUNT(*) as count FROM subject_teachers WHERE class_id = ?");
            $stmt->execute([$classId]);
            $subjectCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
            
            if ($subjectCount > 0) {
                throw new Exception("Cannot delete class with subject assignments. Please remove assignments first.");
            }
            
            // Delete the class
            $stmt = $db->prepare("DELETE FROM classes WHERE id = ?");
            $stmt->execute([$classId]);
            
            $message = "Class '<strong>" . htmlspecialchars($classData['class_name']) . "</strong>' deleted successfully!";
            
        } catch (Exception $e) {
            $error = "Error deleting class: " . $e->getMessage();
        }
    }
}

// Get all classes for this school with their IDs
$stmt = $db->prepare("
    SELECT id, class_name, stream
    FROM classes 
    WHERE school_id = ?
    ORDER BY class_name, stream
");
$stmt->execute([$schoolId]);
$classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/components/header.php';
?>

<style>
    .container {
        max-width: 1000px;
        margin: 0 auto;
        padding: 20px;
    }
    
    .card {
        background: white;
        border-radius: 8px;
        padding: 20px;
        margin-bottom: 20px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }
    
    .card-header {
        border-bottom: 2px solid #f0f0f0;
        padding-bottom: 15px;
        margin-bottom: 15px;
    }
    
    .card-header h2 {
        margin: 0;
        color: #333;
        font-size: 20px;
    }
    
    .alert {
        padding: 15px;
        border-radius: 4px;
        margin-bottom: 20px;
    }
    
    .alert-success {
        background-color: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
    }
    
    .alert-error {
        background-color: #f8d7da;
        color: #721c24;
        border: 1px solid #f5c6cb;
    }
    
    .form-group {
        margin-bottom: 15px;
    }
    
    .form-group label {
        display: block;
        margin-bottom: 5px;
        font-weight: bold;
        color: #555;
    }
    
    .form-group input[type="text"],
    .form-group input[type="number"] {
        width: 100%;
        padding: 10px;
        border: 1px solid #ddd;
        border-radius: 4px;
        font-size: 14px;
        box-sizing: border-box;
    }
    
    .form-group input[type="checkbox"] {
        margin-right: 5px;
    }
    
    .checkbox-label {
        display: flex;
        align-items: center;
        margin-top: 10px;
    }
    
    .streams-input {
        display: none;
        margin-top: 15px;
        padding: 15px;
        background: #f9f9f9;
        border-left: 4px solid #007bff;
        border-radius: 4px;
    }
    
    .streams-input.active {
        display: block;
    }
    
    .stream-checkboxes {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 10px;
    }
    
    .btn {
        padding: 10px 20px;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-size: 14px;
        font-weight: bold;
        transition: all 0.3s;
    }
    
    .btn-primary {
        background-color: #007bff;
        color: white;
    }
    
    .btn-primary:hover {
        background-color: #0056b3;
    }
    
    .btn-danger {
        background-color: #dc3545;
        color: white;
        padding: 5px 10px;
        font-size: 12px;
    }
    
    .btn-danger:hover {
        background-color: #c82333;
    }
    
    .table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 15px;
    }
    
    .table th {
        background-color: #f8f9fa;
        padding: 12px;
        text-align: left;
        border: 1px solid #ddd;
        font-weight: bold;
        color: #333;
    }
    
    .table td {
        padding: 12px;
        border: 1px solid #ddd;
    }
    
    .table tr:hover {
        background-color: #f8f9fa;
    }
    
    .no-data {
        text-align: center;
        padding: 30px;
        color: #999;
    }
    
    .info-box {
        background-color: #e7f3ff;
        border-left: 4px solid #2196F3;
        padding: 15px;
        margin-bottom: 20px;
        border-radius: 4px;
    }
    
    .info-box p {
        margin: 0;
        color: #1565c0;
        font-size: 14px;
    }
    
    .back-link {
        display: inline-block;
        margin-bottom: 20px;
        color: #007bff;
        text-decoration: none;
        font-size: 14px;
    }
    
    .back-link:hover {
        text-decoration: underline;
    }

    @media (max-width: 768px) {
        .container {
            padding: 10px;
        }
        
        .card {
            padding: 15px;
        }
        
        .stream-checkboxes {
            grid-template-columns: 1fr;
        }
        
        .table {
            font-size: 12px;
        }
        
        .table th,
        .table td {
            padding: 8px;
        }
    }
</style>

<div class="container">
    <a href="settings.php" class="back-link">← Back to Settings</a>
    
    <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 8px; margin-bottom: 20px;">
        <h1 style="margin: 0 0 10px 0; font-size: 28px;">📚 Manage Classes</h1>
        <p style="margin: 0; opacity: 0.9;">Add custom classes like Nursery, Pre-KG, or other special classes to your school</p>
    </div>
    
    <?php if ($message): ?>
        <div class="alert alert-success"><?php echo $message; ?></div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert alert-error"><?php echo $error; ?></div>
    <?php endif; ?>
    
    <!-- Add New Class Section -->
    <div class="card">
        <div class="card-header">
            <h2>Add Custom Class</h2>
        </div>
        
        <div class="info-box">
            <p><strong>ℹ️ Info:</strong> Add custom classes beyond the default templates. These classes will function the same way as standard classes.</p>
        </div>
        
        <form method="POST">
            <input type="hidden" name="action" value="add_class">
            
            <div class="form-group">
                <label for="class_name">Class Name *</label>
                <input 
                    type="text" 
                    id="class_name" 
                    name="class_name" 
                    placeholder="e.g., Nursery, Pre-KG, Montessori"
                    required
                    autocomplete="off"
                >
                <small style="color: #666; margin-top: 5px; display: block;">Examples: Nursery, Pre-KG, Reception, Play Group</small>
            </div>
            
            <div class="checkbox-label">
                <input 
                    type="checkbox" 
                    id="use_streams" 
                    name="use_streams" 
                    value="1"
                    onchange="toggleStreamsInput()"
                >
                <label for="use_streams" style="margin: 0; font-weight: normal;">
                    Create multiple streams (A, B, C, D, etc.) for this class
                </label>
            </div>
            
            <div class="streams-input" id="streams_input">
                <label style="font-weight: bold; margin-bottom: 10px; display: block;">Select Streams:</label>
                <div class="stream-checkboxes">
                    <div>
                        <input type="checkbox" id="stream_a" name="streams[]" value="A">
                        <label for="stream_a" style="margin: 0; font-weight: normal;">Stream A</label>
                    </div>
                    <div>
                        <input type="checkbox" id="stream_b" name="streams[]" value="B">
                        <label for="stream_b" style="margin: 0; font-weight: normal;">Stream B</label>
                    </div>
                    <div>
                        <input type="checkbox" id="stream_c" name="streams[]" value="C">
                        <label for="stream_c" style="margin: 0; font-weight: normal;">Stream C</label>
                    </div>
                    <div>
                        <input type="checkbox" id="stream_d" name="streams[]" value="D">
                        <label for="stream_d" style="margin: 0; font-weight: normal;">Stream D</label>
                    </div>
                </div>
            </div>
            
            <button type="submit" class="btn btn-primary" style="margin-top: 20px;">Add Class</button>
        </form>
    </div>
    
    <!-- Existing Classes Section -->
    <div class="card">
        <div class="card-header">
            <h2>Your Classes</h2>
        </div>
        
        <?php if (empty($classes)): ?>
            <div class="no-data">No classes found for your school</div>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Class Name</th>
                        <th>Stream</th>
                        <th style="text-align: center;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($classes as $class): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($class['class_name']); ?></td>
                            <td><?php echo $class['stream'] ? htmlspecialchars($class['stream']) : '<em style="color: #999;">None</em>'; ?></td>
                            <td style="text-align: center;">
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this class? This action cannot be undone.');">
                                    <input type="hidden" name="action" value="delete_class">
                                    <input type="hidden" name="class_id" value="<?php echo htmlspecialchars($class['id']); ?>">
                                    <button type="submit" class="btn btn-danger">
                                        Delete
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    
    <div style="margin-top: 30px; padding: 15px; background: #f0f0f0; border-radius: 4px; color: #666; font-size: 13px;">
        <strong>ℹ️ Note:</strong> Custom classes integrate seamlessly with the existing system. You can assign teachers, subjects, students, and manage everything the same way as default classes.
    </div>
</div>

<script>
function toggleStreamsInput() {
    const checkbox = document.getElementById('use_streams');
    const streamsInput = document.getElementById('streams_input');
    
    if (checkbox.checked) {
        streamsInput.classList.add('active');
    } else {
        streamsInput.classList.remove('active');
        // Uncheck all stream checkboxes when streams option is disabled
        document.querySelectorAll('[name="streams[]"]').forEach(cb => cb.checked = false);
    }
}
</script>

</body>
</html>
