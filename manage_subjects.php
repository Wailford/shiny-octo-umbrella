<?php
session_start();
require_once __DIR__ . '/controllers/AuthController.php';
require_once __DIR__ . '/controllers/ClassController.php';
require_once __DIR__ . '/config/database.php';

// Check if user is logged in and is admin
$auth = new Auth();
$auth->requireLogin();

if (!$auth->isAdmin()) {
    header('Location: dashboard.php');
    exit;
}

$db = Database::getInstance()->getConnection();
$classController = new ClassController();

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        switch ($_POST['action']) {
            case 'add_subject':
                $subjectName = trim($_POST['subject_name']);
                $subjectCode = trim($_POST['subject_code']);
                $classId = (int)$_POST['class_id'];
                $isCore = isset($_POST['is_core']) ? 1 : 0;

                // Verify class belongs to this school
                $ownerCheck = $db->prepare("SELECT id FROM classes WHERE id = ? AND school_id = ?");
                $ownerCheck->execute([$classId, $_SESSION['school_id']]);
                if (!$ownerCheck->fetch()) {
                    echo json_encode(['success' => false, 'message' => 'Unauthorized: class does not belong to your school']);
                    exit;
                }

                // Check if subject already exists
                $stmt = $db->prepare("SELECT id FROM subjects WHERE subject_code = ? AND class_id = ?");
                $stmt->execute([$subjectCode, $classId]);
                
                if ($stmt->fetch()) {
                    echo json_encode(['success' => false, 'message' => 'Subject already exists for this class']);
                    exit;
                }
                
                // Insert new subject
                $stmt = $db->prepare("INSERT INTO subjects (subject_name, subject_code, class_id, is_core, password) VALUES (?, ?, ?, ?, ?)");
                $defaultPassword = password_hash('subject123', PASSWORD_DEFAULT);
                $stmt->execute([$subjectName, $subjectCode, $classId, $isCore, $defaultPassword]);
                
                echo json_encode(['success' => true, 'message' => 'Subject added successfully']);
                break;
                
            case 'update_subject':
                $subjectId = $_POST['subject_id'];
                $subjectName = trim($_POST['subject_name']);
                $isCore = isset($_POST['is_core']) ? 1 : 0;
                
                // Verify subject belongs to this school
                $verifyStmt = $db->prepare("
                    SELECT s.id 
                    FROM subjects s
                    JOIN classes c ON s.class_id = c.id
                    WHERE s.id = ? AND c.school_id = ?
                ");
                $verifyStmt->execute([$subjectId, $_SESSION['school_id']]);
                
                if (!$verifyStmt->fetch()) {
                    echo json_encode(['success' => false, 'message' => 'Unauthorized: Subject does not belong to your school']);
                    exit;
                }
                
                $stmt = $db->prepare("UPDATE subjects SET subject_name = ?, is_core = ? WHERE id = ?");
                $stmt->execute([$subjectName, $isCore, $subjectId]);
                
                echo json_encode(['success' => true, 'message' => 'Subject updated successfully']);
                break;
                
            case 'delete_subject':
                $subjectId = $_POST['subject_id'];
                
                // Verify subject belongs to this school
                $verifyStmt = $db->prepare("
                    SELECT s.id 
                    FROM subjects s
                    JOIN classes c ON s.class_id = c.id
                    WHERE s.id = ? AND c.school_id = ?
                ");
                $verifyStmt->execute([$subjectId, $_SESSION['school_id']]);
                
                if (!$verifyStmt->fetch()) {
                    echo json_encode(['success' => false, 'message' => 'Unauthorized: Subject does not belong to your school']);
                    exit;
                }
                
                // Check if subject has scores
                $stmt = $db->prepare("SELECT COUNT(*) as count FROM scores WHERE subject_id = ?");
                $stmt->execute([$subjectId]);
                $result = $stmt->fetch();
                
                if ($result['count'] > 0) {
                    echo json_encode(['success' => false, 'message' => 'Cannot delete subject with existing scores. Delete scores first.']);
                    exit;
                }
                
                $stmt = $db->prepare("DELETE FROM subjects WHERE id = ?");
                $stmt->execute([$subjectId]);
                
                echo json_encode(['success' => true, 'message' => 'Subject deleted successfully']);
                break;
                
            case 'toggle_core':
                $subjectId = $_POST['subject_id'];
                $isCore = $_POST['is_core'];
                
                // Verify subject belongs to this school
                $verifyStmt = $db->prepare("
                    SELECT s.id 
                    FROM subjects s
                    JOIN classes c ON s.class_id = c.id
                    WHERE s.id = ? AND c.school_id = ?
                ");
                $verifyStmt->execute([$subjectId, $_SESSION['school_id']]);
                
                if (!$verifyStmt->fetch()) {
                    echo json_encode(['success' => false, 'message' => 'Unauthorized: Subject does not belong to your school']);
                    exit;
                }
                
                $stmt = $db->prepare("UPDATE subjects SET is_core = ? WHERE id = ?");
                $stmt->execute([$isCore, $subjectId]);
                
                echo json_encode(['success' => true, 'message' => 'Subject type updated']);
                break;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Get all classes
$schoolTypeId = $_SESSION['school_type_id'] ?? null;
$classes = $classController->getAllClasses($schoolTypeId);

// Get selected class — validate it belongs to this school
$selectedClassId = isset($_GET['class']) ? (int)$_GET['class'] : ($classes[0]['id'] ?? null);
$validClassIds = array_column($classes, 'id');
if ($selectedClassId && !in_array($selectedClassId, $validClassIds)) {
    $selectedClassId = $classes[0]['id'] ?? null;
}

// Get subjects for selected class (scoped through school via JOIN)
$subjects = [];
if ($selectedClassId) {
    $stmt = $db->prepare("SELECT s.* FROM subjects s
                          JOIN classes c ON s.class_id = c.id
                          WHERE s.class_id = ? AND c.school_id = ?
                          ORDER BY s.is_core DESC, s.subject_name ASC");
    $stmt->execute([$selectedClassId, $_SESSION['school_id']]);
    $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$pageTitle = 'Manage Subjects';
require_once __DIR__ . '/components/header.php';
?>
    <style>
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .header-section {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 2rem;
        }
        
        .header-section h1 {
            color: #2d3748;
            margin-bottom: 0.5rem;
        }
        
        .controls {
            display: flex;
            gap: 1rem;
            align-items: center;
            flex-wrap: wrap;
            margin-top: 1rem;
        }
        
        .control-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .control-group label {
            font-weight: 600;
            color: #4a5568;
        }
        
        select, input {
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
        }
        
        .btn-success {
            background: #48bb78;
            color: white;
        }
        
        .btn-danger {
            background: #f56565;
            color: white;
        }
        
        .subjects-section {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .subjects-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        
        .subjects-table {
            overflow-x: auto;
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
        
        .badge {
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 600;
        }
        
        .badge-core {
            background: #667eea;
            color: white;
        }
        
        .badge-elective {
            background: #f6ad55;
            color: white;
        }
        
        .action-buttons {
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
        }
        
        .modal-content {
            background: white;
            max-width: 500px;
            margin: 5% auto;
            padding: 2rem;
            border-radius: 10px;
            position: relative;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        
        .close {
            font-size: 1.5rem;
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
            color: #4a5568;
        }
        
        .form-group input, .form-group select {
            width: 100%;
        }
        
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .checkbox-group input[type="checkbox"] {
            width: auto;
        }
        
        @media (max-width: 768px) {
            .controls {
                flex-direction: column;
                align-items: stretch;
            }
            
            .control-group {
                flex-direction: column;
                align-items: stretch;
            }
            
            .subjects-header {
                flex-direction: column;
                gap: 1rem;
            }
            
            .action-buttons {
                flex-direction: column;
            }
        }
    </style>

    <div class="container">
        <div class="header-section">
            <h1>Manage Subjects</h1>
            <p style="color: #718096;">Add, edit, or remove subjects and set core/elective status</p>
            
            <div class="controls">
                <div class="control-group">
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
                <button class="btn btn-primary" onclick="openAddModal()">+ Add New Subject</button>
            </div>
        </div>
        
        <div class="subjects-section">
            <div class="subjects-header">
                <h2 style="color: #2d3748;">Subjects List</h2>
                <div style="color: #718096;">
                    <strong>Core:</strong> <?php echo count(array_filter($subjects, fn($s) => $s['is_core'] == 1)); ?> | 
                    <strong>Elective:</strong> <?php echo count(array_filter($subjects, fn($s) => $s['is_core'] == 0)); ?>
                </div>
            </div>
            
            <?php if (empty($subjects)): ?>
                <div style="text-align: center; padding: 3rem; color: #718096;">
                    <p>No subjects found for this class.</p>
                    <button class="btn btn-primary" onclick="openAddModal()" style="margin-top: 1rem;">Add First Subject</button>
                </div>
            <?php else: ?>
                <div class="subjects-table">
                    <table>
                        <thead>
                            <tr>
                                <th>Subject Name</th>
                                <th>Subject Code</th>
                                <th>Type</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($subjects as $subject): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($subject['subject_name']); ?></td>
                                    <td><?php echo htmlspecialchars($subject['subject_code']); ?></td>
                                    <td>
                                        <span class="badge <?php echo $subject['is_core'] ? 'badge-core' : 'badge-elective'; ?>">
                                            <?php echo $subject['is_core'] ? 'Core' : 'Elective'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="btn btn-sm btn-primary" onclick="toggleCore(<?php echo $subject['id']; ?>, <?php echo $subject['is_core']; ?>)">
                                                Toggle Type
                                            </button>
                                            <button class="btn btn-sm btn-success" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($subject)); ?>)">
                                                Edit
                                            </button>
                                            <button class="btn btn-sm btn-danger" onclick="deleteSubject(<?php echo $subject['id']; ?>, '<?php echo htmlspecialchars($subject['subject_name']); ?>')">
                                                Delete
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Add Subject Modal -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Add New Subject</h2>
                <span class="close" onclick="closeAddModal()">&times;</span>
            </div>
            <form id="addForm">
                <div class="form-group">
                    <label>Subject Name *</label>
                    <input type="text" id="addSubjectName" required>
                </div>
                <div class="form-group">
                    <label>Subject Code *</label>
                    <input type="text" id="addSubjectCode" required>
                </div>
                <div class="form-group checkbox-group">
                    <input type="checkbox" id="addIsCore">
                    <label for="addIsCore">Core Subject (used in aggregate calculation)</label>
                </div>
                <button type="submit" class="btn btn-success" style="width: 100%;">Add Subject</button>
            </form>
        </div>
    </div>
    
    <!-- Edit Subject Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Edit Subject</h2>
                <span class="close" onclick="closeEditModal()">&times;</span>
            </div>
            <form id="editForm">
                <input type="hidden" id="editSubjectId">
                <div class="form-group">
                    <label>Subject Name *</label>
                    <input type="text" id="editSubjectName" required>
                </div>
                <div class="form-group checkbox-group">
                    <input type="checkbox" id="editIsCore">
                    <label for="editIsCore">Core Subject (used in aggregate calculation)</label>
                </div>
                <button type="submit" class="btn btn-success" style="width: 100%;">Update Subject</button>
            </form>
        </div>
    </div>
    
    <script>
        function changeClass() {
            const classId = document.getElementById('classSelect').value;
            window.location.href = 'manage_subjects.php?class=' + classId;
        }
        
        function openAddModal() {
            document.getElementById('addModal').style.display = 'block';
        }
        
        function closeAddModal() {
            document.getElementById('addModal').style.display = 'none';
            document.getElementById('addForm').reset();
        }
        
        function openEditModal(subject) {
            document.getElementById('editSubjectId').value = subject.id;
            document.getElementById('editSubjectName').value = subject.subject_name;
            document.getElementById('editIsCore').checked = subject.is_core == 1;
            document.getElementById('editModal').style.display = 'block';
        }
        
        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
            document.getElementById('editForm').reset();
        }
        
        document.getElementById('addForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData();
            formData.append('action', 'add_subject');
            formData.append('subject_name', document.getElementById('addSubjectName').value);
            formData.append('subject_code', document.getElementById('addSubjectCode').value);
            formData.append('class_id', document.getElementById('classSelect').value);
            if (document.getElementById('addIsCore').checked) {
                formData.append('is_core', '1');
            }
            
            fetch('manage_subjects.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                alert(data.message);
                if (data.success) {
                    location.reload();
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred');
            });
        });
        
        document.getElementById('editForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData();
            formData.append('action', 'update_subject');
            formData.append('subject_id', document.getElementById('editSubjectId').value);
            formData.append('subject_name', document.getElementById('editSubjectName').value);
            if (document.getElementById('editIsCore').checked) {
                formData.append('is_core', '1');
            }
            
            fetch('manage_subjects.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                alert(data.message);
                if (data.success) {
                    location.reload();
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred');
            });
        });
        
        function toggleCore(subjectId, currentIsCore) {
            const newIsCore = currentIsCore ? 0 : 1;
            const typeName = newIsCore ? 'Core' : 'Elective';
            
            if (!confirm(`Change this subject to ${typeName}?`)) {
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'toggle_core');
            formData.append('subject_id', subjectId);
            formData.append('is_core', newIsCore);
            
            fetch('manage_subjects.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert(data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred');
            });
        }
        
        function deleteSubject(subjectId, subjectName) {
            if (!confirm(`Are you sure you want to delete "${subjectName}"? This action cannot be undone.`)) {
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'delete_subject');
            formData.append('subject_id', subjectId);
            
            fetch('manage_subjects.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                alert(data.message);
                if (data.success) {
                    location.reload();
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred');
            });
        }
        
        // Close modals when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }
    </script>
<?php require_once __DIR__ . '/components/footer.php'; ?>
