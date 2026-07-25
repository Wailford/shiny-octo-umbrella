<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/controllers/AuthController.php';
require_once __DIR__ . '/config/database.php';

$auth = new Auth();
$auth->requireLogin();
$auth->requireAdmin(); // Only admins can access

$db = Database::getInstance()->getConnection();
$message = '';
$error = '';

// Check if school has multiple streams
$hasMultipleStreams = false;
$school_id = $_SESSION['school_id'] ?? null;
if ($school_id) {
    $streamStmt = $db->prepare("SELECT COUNT(DISTINCT stream) as stream_count FROM classes WHERE school_id = ? AND stream IS NOT NULL AND stream != ''");
    $streamStmt->execute([$school_id]);
    $streamCount = $streamStmt->fetch(PDO::FETCH_ASSOC);
    $hasMultipleStreams = ($streamCount['stream_count'] > 1);
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'create_teacher':
                try {
                    $teacherName = trim($_POST['teacher_name']);
                    $email = trim($_POST['email']);
                    $username = strtolower(str_replace(' ', '.', $teacherName));
                    $password = bin2hex(random_bytes(4)); // Generate 8-char password
                    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                    
                    // Check if username exists
                    $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
                    $stmt->execute([$username]);
                    if ($stmt->fetch()) {
                        $username .= rand(10, 99);
                    }
                    
                    // Get school_id for the current user's school
                    $schoolId = $_SESSION['school_id'] ?? null;
                    $schoolTypeId = $_SESSION['school_type_id'] ?? null;
                    
                    if (!$schoolId || !$schoolTypeId) {
                        throw new Exception("School information not found in session");
                    }
                    
                    $stmt = $db->prepare("INSERT INTO users (username, password, role, full_name, email, school_type_id, school_id, created_at) VALUES (?, ?, 'teacher', ?, ?, ?, ?, NOW())");
                    $stmt->execute([$username, $hashedPassword, $teacherName, $email, $schoolTypeId, $schoolId]);
                    
                    $message = "Teacher created successfully! Username: <strong>$username</strong> | Password: <strong>$password</strong> (Please save these credentials)";
                } catch (Exception $e) {
                    $error = "Error creating teacher: " . $e->getMessage();
                }
                break;
                
            case 'assign_subject':
                try {
                    $userId = (int)$_POST['user_id'];
                    $subjectId = (int)$_POST['subject_id'];
                    $classId = (int)$_POST['class_id'];
                    
                    // Check if assignment exists
                    $stmt = $db->prepare("SELECT id FROM subject_teachers WHERE user_id = ? AND subject_id = ? AND class_id = ?");
                    $stmt->execute([$userId, $subjectId, $classId]);
                    
                    if ($stmt->fetch()) {
                        $error = "This teacher is already assigned to this subject and class";
                    } else {
                        $stmt = $db->prepare("INSERT INTO subject_teachers (user_id, subject_id, class_id, created_at) VALUES (?, ?, ?, NOW())");
                        $stmt->execute([$userId, $subjectId, $classId]);
                        
                        // Update user role to subject_master if not already
                        $stmt = $db->prepare("UPDATE users SET role = 'subject_master' WHERE id = ? AND role = 'teacher'");
                        $stmt->execute([$userId]);
                        
                        $message = "Subject assigned successfully!";
                    }
                } catch (Exception $e) {
                    $error = "Error assigning subject: " . $e->getMessage();
                }
                break;
                
            case 'remove_assignment':
                try {
                    $assignmentId = (int)$_POST['assignment_id'];
                    
                    // Verify assignment belongs to this school
                    $verifyStmt = $db->prepare("
                        SELECT st.id 
                        FROM subject_teachers st
                        JOIN users u ON st.user_id = u.id
                        WHERE st.id = ? AND u.school_id = ?
                    ");
                    $verifyStmt->execute([$assignmentId, $_SESSION['school_id']]);
                    
                    if (!$verifyStmt->fetch()) {
                        $error = "Unauthorized: Assignment does not belong to your school";
                        break;
                    }
                    
                    $stmt = $db->prepare("DELETE FROM subject_teachers WHERE id = ?");
                    $stmt->execute([$assignmentId]);
                    $message = "Assignment removed successfully!";
                } catch (Exception $e) {
                    $error = "Error removing assignment: " . $e->getMessage();
                }
                break;
                
            case 'update_password':
                try {
                    $userId = (int)$_POST['user_id'];
                    
                    // Verify user belongs to this school
                    $verifyStmt = $db->prepare("SELECT id FROM users WHERE id = ? AND school_id = ?");
                    $verifyStmt->execute([$userId, $_SESSION['school_id']]);
                    
                    if (!$verifyStmt->fetch()) {
                        $error = "Unauthorized: User does not belong to your school";
                        break;
                    }
                    
                    $newPassword = bin2hex(random_bytes(4));
                    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                    
                    $stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $stmt->execute([$hashedPassword, $userId]);
                    
                    $stmt = $db->prepare("SELECT username FROM users WHERE id = ?");
                    $stmt->execute([$userId]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    $message = "Password reset successfully! Username: <strong>{$user['username']}</strong> | New Password: <strong>$newPassword</strong>";
                } catch (Exception $e) {
                    $error = "Error resetting password: " . $e->getMessage();
                }
                break;
        }
    }
}

// Get school_id directly from session
$schoolId = $_SESSION['school_id'] ?? null;

if (!$schoolId) {
    die("Error: School ID not found in session. Please log out and log in again.");
}

// Get all teachers and subject masters
$stmt = $db->prepare("SELECT * FROM users WHERE role IN ('teacher', 'subject_master') AND school_id = ? ORDER BY full_name");
$stmt->execute([$schoolId]);
$teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all classes - filter by school_id, include stream
$stmt = $db->prepare("SELECT id, class_name, class_code, stream, school_id FROM classes WHERE school_id = ? ORDER BY class_name, stream");
$stmt->execute([$schoolId]);
$classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all subjects - filter by school_id and include stream
$stmt = $db->prepare("SELECT s.*, c.class_name, c.stream FROM subjects s JOIN classes c ON s.class_id = c.id WHERE c.school_id = ? ORDER BY c.class_name, c.stream, s.subject_name");
$stmt->execute([$schoolId]);
$subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all assignments with details including stream
$stmt = $db->prepare("
    SELECT st.id, st.user_id, st.subject_id, st.class_id,
           u.full_name as teacher_name, u.username,
           s.subject_name, c.class_name, c.stream
    FROM subject_teachers st
    JOIN users u ON st.user_id = u.id
    JOIN subjects s ON st.subject_id = s.id
    JOIN classes c ON st.class_id = c.id
    WHERE u.school_id = ?
    ORDER BY u.full_name, c.class_name, c.stream, s.subject_name
");
$stmt->execute([$schoolId]);
$assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<?php
$pageTitle = 'Subject Teacher Assignment';
require_once __DIR__ . '/components/header.php';
?>
<style>
    /* ── Page layout ───────────────────────────────────────────────── */
    .back-link { display: inline-flex; align-items: center; gap: 5px; margin-bottom: 1.25rem; color: #667eea; text-decoration: none; font-size: 0.875rem; font-weight: 500; }
    .back-link:hover { text-decoration: underline; }
    .page-subtitle { color: #718096; font-size: 0.9rem; margin-bottom: 1.75rem; }

    /* ── Section cards ─────────────────────────────────────────────── */
    .section {
        margin-bottom: 1.75rem;
        padding: 1.75rem;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        background: #fff;
        box-shadow: 0 1px 4px rgba(0,0,0,.05);
    }
    .section-header {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 1.25rem;
        padding-bottom: 0.875rem;
        border-bottom: 1px solid #f0f4f8;
    }
    .section-icon {
        width: 34px; height: 34px;
        border-radius: 8px;
        display: flex; align-items: center; justify-content: center;
        font-size: 1rem; flex-shrink: 0;
    }
    .icon-blue  { background: #ebf4ff; color: #3b82f6; }
    .icon-green { background: #f0fff4; color: #38a169; }
    .icon-purple{ background: #f5f3ff; color: #7c3aed; }
    .icon-amber { background: #fffbeb; color: #d97706; }
    .section-header h2 { color: #2d3748; margin: 0; font-size: 1.05rem; font-weight: 600; }
    .section-header p  { color: #718096; margin: 1px 0 0; font-size: 0.8rem; }

    /* ── Form grid ─────────────────────────────────────────────────── */
    .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.1rem; margin-bottom: 1.25rem; }
    .form-group { margin-bottom: 0; }
    .form-group label { display: flex; align-items: center; gap: 4px; margin-bottom: 0.4rem; font-size: 0.82rem; font-weight: 600; color: #4a5568; text-transform: uppercase; letter-spacing: .4px; }
    .required-star { color: #e53e3e; }
    .form-control {
        width: 100%; padding: 0.6rem 0.85rem;
        border: 1.5px solid #e2e8f0;
        border-radius: 7px;
        font-size: 0.9rem;
        color: #2d3748;
        background: #fafbfc;
        transition: border-color .2s, box-shadow .2s;
        box-sizing: border-box;
        height: 42px;
    }
    .form-control:focus {
        outline: none;
        border-color: #667eea;
        background: #fff;
        box-shadow: 0 0 0 3px rgba(102,126,234,.12);
    }
    select.form-control { appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath fill='%23667eea' d='M1 1l5 5 5-5'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 0.85rem center; padding-right: 2.2rem; }

    /* ── Credentials banner ────────────────────────────────────────── */
    .credentials-banner {
        background: linear-gradient(135deg, #ebf8ff 0%, #f0fff4 100%);
        border: 1.5px solid #bee3f8;
        border-radius: 10px;
        padding: 1rem 1.25rem;
        margin-bottom: 1.5rem;
        display: flex;
        align-items: flex-start;
        gap: 12px;
    }
    .credentials-banner .cb-icon { font-size: 1.4rem; flex-shrink: 0; margin-top: 2px; }
    .credentials-banner .cb-title { font-weight: 700; color: #2c7a7b; margin-bottom: 4px; font-size: 0.9rem; }
    .credentials-banner .cb-items { display: flex; gap: 1rem; flex-wrap: wrap; }
    .credentials-banner .cb-pill {
        display: inline-flex; align-items: center; gap: 6px;
        background: white; border: 1px solid #b2d8d8; border-radius: 6px;
        padding: 4px 12px; font-size: 0.875rem;
    }
    .credentials-banner .cb-pill span { color: #718096; font-size: 0.78rem; }
    .credentials-banner .cb-pill strong { color: #2d3748; font-family: monospace; font-size: 0.92rem; }

    /* ── Table ─────────────────────────────────────────────────────── */
    .table-wrap { overflow-x: auto; border-radius: 8px; border: 1px solid #e2e8f0; margin-top: 0.5rem; }
    table { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
    table th {
        background: #f7f8fb;
        padding: 0.7rem 1rem;
        text-align: left;
        font-size: 0.75rem;
        font-weight: 700;
        color: #4a5568;
        text-transform: uppercase;
        letter-spacing: .5px;
        border-bottom: 1.5px solid #e2e8f0;
        white-space: nowrap;
    }
    table td { padding: 0.75rem 1rem; border-bottom: 1px solid #f0f4f8; color: #2d3748; vertical-align: middle; }
    table tbody tr:last-child td { border-bottom: none; }
    table tbody tr:hover td { background: #f8fafc; }

    /* ── Badges ────────────────────────────────────────────────────── */
    .badge { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 0.73rem; font-weight: 600; text-transform: capitalize; }
    .badge-teacher        { background: #ebf8ff; color: #2b6cb0; }
    .badge-subject-master { background: #f0fff4; color: #276749; }

    /* ── Action buttons ────────────────────────────────────────────── */
    .actions { display: flex; gap: 6px; flex-wrap: wrap; }
    .btn-sm { padding: 5px 12px; font-size: 0.78rem; font-weight: 600; border: none; border-radius: 6px; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; transition: background .15s, transform .1s; }
    .btn-sm:active { transform: scale(.97); }
    .btn-danger  { background: #fff5f5; color: #c53030; border: 1px solid #fed7d7; }
    .btn-danger:hover  { background: #fed7d7; }
    .btn-warning { background: #fffbeb; color: #b7791f; border: 1px solid #fbd38d; }
    .btn-warning:hover { background: #fbd38d; }
    .btn-success { background: #48bb78; color: white; border: none; padding: 9px 20px; border-radius: 7px; cursor: pointer; font-size: 0.875rem; font-weight: 600; display: inline-flex; align-items: center; gap: 6px; transition: background .15s; }
    .btn-success:hover { background: #38a169; }

    /* ── Empty state ───────────────────────────────────────────────── */
    .no-data { text-align: center; padding: 2.5rem 1rem; color: #a0aec0; }
    .no-data-icon { font-size: 2rem; margin-bottom: 8px; }
    .no-data p { font-size: 0.875rem; margin: 0; }

    @media (max-width: 768px) {
        .section { padding: 1.1rem; }
        .form-grid { grid-template-columns: 1fr; }
        input, select { font-size: 16px !important; min-height: 44px; }
        .btn, button[type=submit] { width: 100%; padding: 0.85rem; font-size: 1rem; }
    }
</style>
<div class="container">
    <a href="settings.php" class="back-link">&#8592; Back to Settings</a>
    <h1 style="font-size:1.5rem;font-weight:700;color:#2d3748;margin-bottom:0.25rem;">Subject Teacher Assignment</h1>
    <p class="page-subtitle">Create teachers, assign them to subjects and classes, and manage their credentials.</p>

    <?php if ($message): ?>
        <?php
        // Detect credentials banner (contains Username: and Password:)
        $isCredentials = strpos($message, 'Username:') !== false && strpos($message, 'Password:') !== false;
        if ($isCredentials):
            preg_match('/Username: <strong>(.*?)<\/strong>/', $message, $uMatch);
            preg_match('/Password: <strong>(.*?)<\/strong>/', $message, $pMatch);
            $credUser = htmlspecialchars($uMatch[1] ?? '');
            $credPass = htmlspecialchars($pMatch[1] ?? '');
        ?>
        <div class="credentials-banner">
            <div class="cb-icon">&#9989;</div>
            <div>
                <div class="cb-title">Teacher created &mdash; save these login credentials now</div>
                <div class="cb-items">
                    <div class="cb-pill"><span>Username</span><strong><?php echo $credUser; ?></strong></div>
                    <div class="cb-pill"><span>Password</span><strong><?php echo $credPass; ?></strong></div>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="alert alert-success"><?php echo $message; ?></div>
        <?php endif; ?>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <!-- Create New Teacher -->
    <div class="section">
        <div class="section-header">
            <div class="section-icon icon-blue">&#43;</div>
            <div>
                <h2>Create New Teacher</h2>
                <p>A username and password will be auto-generated</p>
            </div>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="create_teacher">
            <div class="form-grid">
                <div class="form-group">
                    <label>Teacher Name <span class="required-star">*</span></label>
                    <input type="text" name="teacher_name" class="form-control" placeholder="e.g. Kwame Mensah" required>
                </div>
                <div class="form-group">
                    <label>Email Address</label>
                    <input type="email" name="email" class="form-control" placeholder="teacher@school.edu.gh">
                </div>
            </div>
            <button type="submit" class="btn btn-primary">&#43; Create Teacher &amp; Generate Credentials</button>
        </form>
    </div>

    <!-- Assign Subject to Teacher -->
    <div class="section">
        <div class="section-header">
            <div class="section-icon icon-green">&#9654;</div>
            <div>
                <h2>Assign Subject to Teacher</h2>
                <p>Link a teacher to a specific subject and class</p>
            </div>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="assign_subject">
            <div class="form-grid">
                <div class="form-group">
                    <label>Teacher <span class="required-star">*</span></label>
                    <select name="user_id" class="form-control" required>
                    <option value="">-- Select Teacher --</option>
                            <?php foreach ($teachers as $teacher): ?>
                                <option value="<?php echo $teacher['id']; ?>">
                                    <?php echo htmlspecialchars($teacher['full_name']); ?> (<?php echo htmlspecialchars($teacher['username']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                </div>
                <div class="form-group">
                    <label>Subject <span class="required-star">*</span></label>
                    <select name="subject_id" class="form-control" required id="subjectSelect">
                        <option value="">-- Select Subject --</option>
                            <?php 
                            $currentClassStream = '';
                            foreach ($subjects as $subject): 
                                $classLabel = $subject['class_name'];
                                if (!empty($subject['stream'])) {
                                    $classLabel .= ' - Stream ' . $subject['stream'];
                                }
                                
                                if ($currentClassStream !== $classLabel) {
                                    if ($currentClassStream !== '') echo '</optgroup>';
                                    echo '<optgroup label="' . htmlspecialchars($classLabel) . '">';
                                    $currentClassStream = $classLabel;
                                }
                            ?>
                                <option value="<?php echo $subject['id']; ?>" data-class="<?php echo $subject['class_id']; ?>">
                                    <?php echo htmlspecialchars($subject['subject_name']); ?>
                                </option>
                            <?php endforeach; ?>
                            <?php if ($currentClassStream !== '') echo '</optgroup>'; ?>
                        </select>
                    </div>
                <div class="form-group">
                    <label>Class <span class="required-star">*</span></label>
                    <select name="class_id" class="form-control" required id="classSelect">
                        <option value="">-- Select Class --</option>
                            <?php foreach ($classes as $class): 
                                $displayName = $class['class_name'];
                                if (!empty($class['stream'])) {
                                    $displayName .= ' - Stream ' . $class['stream'];
                                }
                            ?>
                                <option value="<?php echo $class['id']; ?>">
                                    <?php echo htmlspecialchars($displayName); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                </div>
            </div>
            <button type="submit" class="btn-success" style="margin-top:0.25rem;">&#10003; Assign Subject</button>
        </form>
    </div>

    <!-- Current Assignments -->
    <div class="section">
        <div class="section-header">
            <div class="section-icon icon-purple">&#9783;</div>
            <div>
                <h2>Current Subject Assignments</h2>
                <p>All teacher&ndash;subject&ndash;class links for your school</p>
            </div>
        </div>
        <?php if (empty($assignments)): ?>
            <div class="no-data"><div class="no-data-icon">&#128203;</div><p>No subject assignments yet. Use the form above to get started.</p></div>
        <?php else: ?>
            <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Teacher</th>
                        <th>Username</th>
                        <th>Subject</th>
                        <th>Class</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($assignments as $assignment): 
                        $classDisplay = $assignment['class_name'];
                        if (!empty($assignment['stream'])) {
                            $classDisplay .= ' &mdash; Stream ' . $assignment['stream'];
                        }
                    ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($assignment['teacher_name']); ?></strong></td>
                            <td style="font-family:monospace;color:#667eea;"><?php echo htmlspecialchars($assignment['username']); ?></td>
                            <td><?php echo htmlspecialchars($assignment['subject_name']); ?></td>
                            <td><?php echo $classDisplay; ?></td>
                            <td class="actions">
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Reset password for this teacher?');">
                                    <input type="hidden" name="action" value="update_password">
                                    <input type="hidden" name="user_id" value="<?php echo $assignment['user_id']; ?>">
                                    <button type="submit" class="btn-sm btn-warning">&#8635; Reset Password</button>
                                </form>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Remove this assignment?');">
                                    <input type="hidden" name="action" value="remove_assignment">
                                    <input type="hidden" name="assignment_id" value="<?php echo $assignment['id']; ?>">
                                    <button type="submit" class="btn-sm btn-danger">&#215; Remove</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- All Teachers List -->
    <div class="section">
        <div class="section-header">
            <div class="section-icon icon-amber">&#128100;</div>
            <div>
                <h2>All Teachers</h2>
                <p>Manage credentials for all staff accounts</p>
            </div>
        </div>
        <?php if (empty($teachers)): ?>
            <div class="no-data"><div class="no-data-icon">&#128100;</div><p>No teachers created yet.</p></div>
        <?php else: ?>
            <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($teachers as $teacher): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($teacher['full_name']); ?></strong></td>
                            <td style="font-family:monospace;color:#667eea;"><?php echo htmlspecialchars($teacher['username']); ?></td>
                            <td style="color:#718096;"><?php echo htmlspecialchars($teacher['email'] ?? '—'); ?></td>
                            <td>
                                <?php 
                                $role = $teacher['role'];
                                $badgeClass = $role === 'subject_master' ? 'badge-subject-master' : 'badge-teacher';
                                $roleLabel = ucfirst(str_replace('_', ' ', $role));
                                ?>
                                <span class="badge <?php echo $badgeClass; ?>"><?php echo $roleLabel; ?></span>
                            </td>
                            <td>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Reset password for <?php echo htmlspecialchars(addslashes($teacher['full_name'])); ?>?');">
                                    <input type="hidden" name="action" value="update_password">
                                    <input type="hidden" name="user_id" value="<?php echo $teacher['id']; ?>">
                                    <button type="submit" class="btn-sm btn-warning">&#8635; Reset Password</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
    document.getElementById('subjectSelect').addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        const classId = selectedOption.getAttribute('data-class');
        if (classId) {
            document.getElementById('classSelect').value = classId;
        }
    });
</script>

<?php require_once __DIR__ . '/components/footer.php'; ?>
