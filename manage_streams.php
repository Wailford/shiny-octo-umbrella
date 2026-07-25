<?php
/**
 * Stream Management Interface
 * Allows admins to create and manage multiple streams (A, B, C, D) for each class
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/controllers/AuthController.php';
require_once __DIR__ . '/controllers/ClassController.php';

$auth = new Auth();
$auth->requireLogin();
$auth->requireAdmin();

$classController = new ClassController();

require_once __DIR__ . '/config/database.php';
$db = Database::getInstance()->getConnection();

$message = '';
$error = '';

// Get school info
$schoolId = null;
if (isset($_SESSION['school_id'])) {
    $stmt = $db->prepare("SELECT id FROM school_info WHERE id = ?");
    $stmt->execute([$_SESSION['school_id']]);
    $schoolData = $stmt->fetch(PDO::FETCH_ASSOC);
    $schoolId = $schoolData['id'] ?? null;
}

// Handle stream creation/deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'create_stream':
                $baseClassId = $_POST['base_class_id'] ?? null;
                $streamLetter = strtoupper(trim($_POST['stream_letter'] ?? ''));
                
                if ($baseClassId && $streamLetter) {
                    $result = $classController->createStream($baseClassId, $streamLetter);
                    if ($result['success']) {
                        $message = $result['message'];
                    } else {
                        $error = $result['error'];
                    }
                } else {
                    $error = 'Please provide all required information';
                }
                break;
                
            case 'delete_stream':
                $classId = $_POST['class_id'] ?? null;
                if ($classId) {
                    $result = $classController->deleteStream($classId);
                    if ($result['success']) {
                        $message = $result['message'];
                    } else {
                        $error = $result['error'];
                    }
                }
                break;
                
            case 'convert_to_streams':
                // Convert single class to multi-stream system
                $baseClassId = $_POST['base_class_id'] ?? null;
                $streams = $_POST['streams'] ?? [];
                
                if ($baseClassId && !empty($streams)) {
                    $successCount = 0;
                    $errors = [];
                    
                    foreach ($streams as $streamLetter) {
                        $result = $classController->createStream($baseClassId, strtoupper($streamLetter));
                        if ($result['success']) {
                            $successCount++;
                        } else {
                            $errors[] = $result['error'];
                        }
                    }
                    
                    if ($successCount > 0) {
                        $message = "Created $successCount stream(s) successfully!";
                    }
                    if (!empty($errors)) {
                        $error = implode('; ', $errors);
                    }
                }
                break;
        }
    }
}

// Get classes grouped by name
$classesGrouped = $classController->getClassesGroupedByName($schoolId);

require_once __DIR__ . '/components/header.php';
?>

<style>
    .page-header {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 2rem;
        border-radius: 10px;
        margin-bottom: 2rem;
    }
    
    .streams-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
        gap: 1.5rem;
        margin-bottom: 2rem;
    }
    
    .class-card {
        background: white;
        border-radius: 10px;
        padding: 1.5rem;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    }
    
    .class-card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1rem;
        padding-bottom: 1rem;
        border-bottom: 2px solid #e2e8f0;
    }
    
    .class-name {
        font-size: 1.25rem;
        font-weight: 600;
        color: #2d3748;
    }
    
    .stream-badge {
        display: inline-block;
        padding: 0.4rem 0.8rem;
        background: #667eea;
        color: white;
        border-radius: 20px;
        font-size: 0.875rem;
        font-weight: 600;
        margin: 0.25rem;
    }
    
    .stream-badge.single {
        background: #48bb78;
    }
    
    .stream-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.75rem;
        background: #f7fafc;
        border-radius: 5px;
        margin-bottom: 0.5rem;
    }
    
    .stream-name {
        font-weight: 500;
        color: #2d3748;
    }
    
    .stream-info {
        font-size: 0.875rem;
        color: #718096;
    }
    
    .add-stream-btn {
        width: 100%;
        padding: 0.75rem;
        background: #667eea;
        color: white;
        border: none;
        border-radius: 5px;
        cursor: pointer;
        font-weight: 600;
        margin-top: 1rem;
    }
    
    .add-stream-btn:hover {
        background: #5568d3;
    }
    
    .delete-btn {
        padding: 0.25rem 0.5rem;
        background: #e53e3e;
        color: white;
        border: none;
        border-radius: 3px;
        cursor: pointer;
        font-size: 0.75rem;
    }
    
    .delete-btn:hover {
        background: #c53030;
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
    
    .modal.active {
        display: flex;
        justify-content: center;
        align-items: center;
    }
    
    .modal-content {
        background: white;
        padding: 2rem;
        border-radius: 10px;
        max-width: 500px;
        width: 90%;
    }
    
    .modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1.5rem;
    }
    
    .modal-header h2 {
        margin: 0;
        color: #2d3748;
    }
    
    .close-btn {
        background: none;
        border: none;
        font-size: 1.5rem;
        cursor: pointer;
        color: #718096;
    }
    
    .close-btn:hover {
        color: #2d3748;
    }
    
    .form-group {
        margin-bottom: 1rem;
    }
    
    .form-group label {
        display: block;
        font-weight: 600;
        margin-bottom: 0.5rem;
        color: #2d3748;
    }
    
    .form-group select,
    .form-group input {
        width: 100%;
        padding: 0.75rem;
        border: 1px solid #e2e8f0;
        border-radius: 5px;
        font-size: 1rem;
    }
    
    .checkbox-group {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 0.5rem;
        margin-top: 0.5rem;
    }
    
    .checkbox-label {
        display: flex;
        align-items: center;
        padding: 0.5rem;
        background: #f7fafc;
        border-radius: 5px;
        cursor: pointer;
    }
    
    .checkbox-label input {
        margin-right: 0.5rem;
        width: auto;
    }
    
    .btn-group {
        display: flex;
        gap: 1rem;
        justify-content: flex-end;
        margin-top: 1.5rem;
    }
    
    .btn {
        padding: 0.75rem 1.5rem;
        border: none;
        border-radius: 5px;
        cursor: pointer;
        font-weight: 600;
    }
    
    .btn-primary {
        background: #667eea;
        color: white;
    }
    
    .btn-primary:hover {
        background: #5568d3;
    }
    
    .btn-secondary {
        background: #e2e8f0;
        color: #2d3748;
    }
    
    .btn-secondary:hover {
        background: #cbd5e0;
    }
    
    .info-box {
        background: #ebf8ff;
        border-left: 4px solid #3182ce;
        padding: 1rem;
        border-radius: 5px;
        margin-bottom: 2rem;
    }
    
    .info-box h3 {
        margin: 0 0 0.5rem 0;
        color: #2c5282;
    }
    
    .info-box ul {
        margin: 0;
        padding-left: 1.5rem;
    }
    
    .success-message {
        background: #c6f6d5;
        border-left: 4px solid #48bb78;
        padding: 1rem;
        border-radius: 5px;
        margin-bottom: 1rem;
        color: #22543d;
    }
    
    .error-message {
        background: #fed7d7;
        border-left: 4px solid #e53e3e;
        padding: 1rem;
        border-radius: 5px;
        margin-bottom: 1rem;
        color: #742a2a;
    }

    @media (max-width: 768px) {
        .page-header { padding: 1.25rem; }
        .page-header h1 { font-size: 1.25rem; }

        /* Grid: single column on small screens */
        .streams-grid { grid-template-columns: 1fr !important; gap: 1rem; }

        /* Class card stream items: stack info */
        .stream-item { flex-direction: column; align-items: flex-start; gap: 0.4rem; }

        /* Checkbox grid: 2 columns instead of 4 */
        .checkbox-group { grid-template-columns: repeat(2, 1fr) !important; }

        /* Modal full width */
        .modal-content { width: 95% !important; max-width: 100% !important; padding: 1.25rem; }
        .form-group select, .form-group input { font-size: 16px !important; min-height: 48px; }

        /* Buttons */
        .add-stream-btn { padding: 0.85rem; font-size: 1rem; }
    }

    @media (max-width: 480px) {
        .checkbox-group { grid-template-columns: 1fr !important; }
    }
</style>

<div class="container">
    <div class="page-header">
        <h1>🎓 Class Streams Management</h1>
        <p style="margin: 0; opacity: 0.9;">Create and manage multiple streams (A, B, C, D) for each class level</p>
    </div>
    
    <?php if ($message): ?>
        <div class="success-message">✅ <?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="error-message">❌ <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    
    <div class="info-box">
        <h3>💡 How Streams Work</h3>
        <ul>
            <li><strong>Multiple Classes:</strong> Create A, B, C, D streams for the same class level (e.g., "Basic 8 A", "Basic 8 B")</li>
            <li><strong>Separate Students:</strong> Each stream has different students but same subjects</li>
            <li><strong>Unified Reporting:</strong> Rank students within their stream AND across all streams combined</li>
            <li><strong>Teacher Assignment:</strong> Teachers can be assigned to teach multiple streams of the same subject</li>
        </ul>
    </div>
    
    <div class="streams-grid">
        <?php foreach ($classesGrouped as $group): ?>
            <div class="class-card">
                <div class="class-card-header">
                    <div class="class-name"><?php echo htmlspecialchars($group['base_name']); ?></div>
                    <div>
                        <?php if (count($group['streams']) == 1 && empty($group['streams'][0]['stream'])): ?>
                            <span class="stream-badge single">Single Stream</span>
                        <?php else: ?>
                            <span class="stream-badge"><?php echo count($group['streams']); ?> Streams</span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <?php foreach ($group['streams'] as $stream): ?>
                    <div class="stream-item">
                        <div>
                            <div class="stream-name">
                                <?php 
                                if (empty($stream['stream'])) {
                                    echo htmlspecialchars($stream['class_name']);
                                } else {
                                    echo htmlspecialchars($stream['class_name']) . ' <strong>' . htmlspecialchars($stream['stream']) . '</strong>';
                                }
                                ?>
                            </div>
                            <div class="stream-info">
                                Class ID: <?php echo $stream['id']; ?>
                                <?php
                                // Count students
                                $stmt = $db->prepare("SELECT COUNT(*) as count FROM students WHERE class_id = ?");
                                $stmt->execute([$stream['id']]);
                                $studentCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
                                echo " | $studentCount student(s)";
                                ?>
                            </div>
                        </div>
                        <?php if (!empty($stream['stream'])): ?>
                            <form method="POST" style="display: inline;" onsubmit="return confirm('Delete stream <?php echo htmlspecialchars($stream['stream']); ?>? This cannot be undone!');">
                                <input type="hidden" name="action" value="delete_stream">
                                <input type="hidden" name="class_id" value="<?php echo $stream['id']; ?>">
                                <button type="submit" class="delete-btn">Delete</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                
                <?php
                // Get first stream as base for adding new streams
                $baseClass = $group['streams'][0];
                ?>
                
                <button class="add-stream-btn" onclick="showAddStreamModal(<?php echo $baseClass['id']; ?>, '<?php echo htmlspecialchars($baseClass['class_name'], ENT_QUOTES); ?>')">
                    + Add Stream
                </button>
                
                <?php if (count($group['streams']) == 1 && empty($group['streams'][0]['stream'])): ?>
                    <button class="add-stream-btn" style="background: #48bb78; margin-top: 0.5rem;" onclick="showConvertModal(<?php echo $baseClass['id']; ?>, '<?php echo htmlspecialchars($baseClass['class_name'], ENT_QUOTES); ?>')">
                        🔄 Convert to Multi-Stream
                    </button>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Add Stream Modal -->
<div class="modal" id="addStreamModal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Add New Stream</h2>
            <button class="close-btn" onclick="closeModals()">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="create_stream">
            <input type="hidden" name="base_class_id" id="add_base_class_id">
            
            <div class="form-group">
                <label>Class</label>
                <input type="text" id="add_class_name" readonly style="background: #f7fafc;">
            </div>
            
            <div class="form-group">
                <label>Stream Letter</label>
                <select name="stream_letter" required>
                    <option value="">-- Select Stream --</option>
                    <option value="A">A</option>
                    <option value="B">B</option>
                    <option value="C">C</option>
                    <option value="D">D</option>
                    <option value="E">E</option>
                    <option value="F">F</option>
                    <option value="G">G</option>
                    <option value="H">H</option>
                </select>
            </div>
            
            <div class="btn-group">
                <button type="button" class="btn btn-secondary" onclick="closeModals()">Cancel</button>
                <button type="submit" class="btn btn-primary">Create Stream</button>
            </div>
        </form>
    </div>
</div>

<!-- Convert to Multi-Stream Modal -->
<div class="modal" id="convertModal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Convert to Multi-Stream System</h2>
            <button class="close-btn" onclick="closeModals()">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="convert_to_streams">
            <input type="hidden" name="base_class_id" id="convert_base_class_id">
            
            <div class="form-group">
                <label>Class</label>
                <input type="text" id="convert_class_name" readonly style="background: #f7fafc;">
            </div>
            
            <div class="form-group">
                <label>Select Streams to Create</label>
                <div class="checkbox-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="streams[]" value="A"> A
                    </label>
                    <label class="checkbox-label">
                        <input type="checkbox" name="streams[]" value="B"> B
                    </label>
                    <label class="checkbox-label">
                        <input type="checkbox" name="streams[]" value="C"> C
                    </label>
                    <label class="checkbox-label">
                        <input type="checkbox" name="streams[]" value="D"> D
                    </label>
                    <label class="checkbox-label">
                        <input type="checkbox" name="streams[]" value="E"> E
                    </label>
                    <label class="checkbox-label">
                        <input type="checkbox" name="streams[]" value="F"> F
                    </label>
                    <label class="checkbox-label">
                        <input type="checkbox" name="streams[]" value="G"> G
                    </label>
                    <label class="checkbox-label">
                        <input type="checkbox" name="streams[]" value="H"> H
                    </label>
                </div>
            </div>
            
            <div class="info-box" style="margin-top: 1rem;">
                <strong>Note:</strong> Each stream will have the same subjects as the original class. Existing students will remain in the original class.
            </div>
            
            <div class="btn-group">
                <button type="button" class="btn btn-secondary" onclick="closeModals()">Cancel</button>
                <button type="submit" class="btn btn-primary">Create Streams</button>
            </div>
        </form>
    </div>
</div>

<script>
    function showAddStreamModal(classId, className) {
        document.getElementById('add_base_class_id').value = classId;
        document.getElementById('add_class_name').value = className;
        document.getElementById('addStreamModal').classList.add('active');
    }
    
    function showConvertModal(classId, className) {
        document.getElementById('convert_base_class_id').value = classId;
        document.getElementById('convert_class_name').value = className;
        document.getElementById('convertModal').classList.add('active');
    }
    
    function closeModals() {
        document.getElementById('addStreamModal').classList.remove('active');
        document.getElementById('convertModal').classList.remove('active');
    }
    
    // Close modal when clicking outside
    window.onclick = function(event) {
        if (event.target.classList.contains('modal')) {
            closeModals();
        }
    }
</script>

<?php require_once __DIR__ . '/components/footer.php'; ?>
