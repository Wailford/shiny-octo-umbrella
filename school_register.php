<?php
session_start();

// If already logged in, redirect
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'developer') {
        header('Location: developer.php');
    } else {
        header('Location: index.php');
    }
    exit;
}

require_once __DIR__ . '/config/database.php';
$db = Database::getInstance()->getConnection();

/**
 * Create default subjects for a class based on class name and school type
 */
function createSubjectsForClass($db, $classId, $className, $schoolTypeId) {
    // Define subjects based on class level
    $subjects = [];
    
    if (stripos($className, 'KG') !== false) {
        // KG subjects
        $subjects = [
            'Language & Literacy', 'Numeracy', 'Creative Arts', 
            'Our World Our People', 'Physical Development'
        ];
    } elseif (in_array($className, ['Basic One', 'Basic Two', 'Basic Three', 'Basic Four', 'Basic Five', 'Basic Six'])) {
        // Primary subjects
        $subjects = [
            'English Language', 'Mathematics', 'Science', 'Social Studies',
            'Computing', 'Creative Arts', 'Religious & Moral Education', 'Ghanaian Language'
        ];
    } elseif (in_array($className, ['Basic Seven', 'Basic Eight', 'Basic Nine'])) {
        // JHS core subjects
        $subjects = [
            ['name' => 'English Language', 'is_core' => 1],
            ['name' => 'Mathematics', 'is_core' => 1],
            ['name' => 'Integrated Science', 'is_core' => 1],
            ['name' => 'Social Studies', 'is_core' => 1],
            ['name' => 'Religious & Moral Education', 'is_core' => 1],
            ['name' => 'Computing', 'is_core' => 1],
            ['name' => 'Ghanaian Language', 'is_core' => 1],
            ['name' => 'Creative Arts', 'is_core' => 1],
            ['name' => 'Career Technology', 'is_core' => 1],
            ['name' => 'French', 'is_core' => 0],
            ['name' => 'B.D.T', 'is_core' => 0],
            ['name' => 'Home Economics', 'is_core' => 0],
            ['name' => 'Visual Arts', 'is_core' => 0],
            ['name' => 'Performing Arts', 'is_core' => 0]
        ];
    }
    
    // Insert subjects
    $order = 1;
    foreach ($subjects as $subject) {
        if (is_array($subject)) {
            // JHS with is_core flag
            $subjectCode = 'C' . $classId . '_' . strtoupper(str_replace(' ', '', $subject['name']));
            $stmt = $db->prepare("
                INSERT INTO subjects (class_id, subject_name, subject_code, is_core, display_order)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$classId, $subject['name'], $subjectCode, $subject['is_core'], $order]);
        } else {
            // Primary/KG
            $subjectCode = 'C' . $classId . '_' . strtoupper(str_replace(' ', '', $subject));
            $stmt = $db->prepare("
                INSERT INTO subjects (class_id, subject_name, subject_code, display_order)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$classId, $subject, $subjectCode, $order]);
        }
        $order++;
    }
}

// Get school types
$stmt = $db->query("SELECT * FROM school_types WHERE is_active = 1 ORDER BY id");
$schoolTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$successMessage = '';
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db->beginTransaction();
        
        // Validate required fields
        $requiredFields = ['school_name', 'location', 'school_type_id', 'admin_username', 'admin_password', 'admin_name', 'admin_email', 'phone'];
        foreach ($requiredFields as $field) {
            if (empty($_POST[$field])) {
                throw new Exception("Please fill in all required fields");
            }
        }
        
        // Check if school already exists (same name and location)
        $stmt = $db->prepare("SELECT id FROM school_info WHERE LOWER(school_name) = LOWER(?) AND LOWER(location) = LOWER(?)");
        $stmt->execute([$_POST['school_name'], $_POST['location']]);
        if ($stmt->fetch()) {
            throw new Exception("A school with this name and location already exists. Please contact developer if this is your school.");
        }
        
        // Check if username already exists
        $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$_POST['admin_username']]);
        if ($stmt->fetch()) {
            throw new Exception("Username already exists. Please choose a different username.");
        }
        
        // Check if email already exists
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$_POST['admin_email']]);
        if ($stmt->fetch()) {
            throw new Exception("Email already exists. Please use a different email address.");
        }
        
        // Handle logo upload (optional)
        $logoUrl = null;
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = __DIR__ . '/uploads/logos/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            
            $fileExt = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
            $allowedExts = ['jpg', 'jpeg', 'png', 'gif'];
            $allowedMimes = ['image/jpeg', 'image/png', 'image/gif'];
            $imageInfo = getimagesize($_FILES['logo']['tmp_name']);
            $uploadedMime = $imageInfo ? $imageInfo['mime'] : '';
            
            if (in_array($fileExt, $allowedExts) && in_array($uploadedMime, $allowedMimes) && $_FILES['logo']['size'] <= 2097152) {
                $fileName = 'school_' . time() . '_' . uniqid() . '.' . $fileExt;
                $targetPath = $uploadDir . $fileName;
                
                if (move_uploaded_file($_FILES['logo']['tmp_name'], $targetPath)) {
                    $logoUrl = 'uploads/logos/' . $fileName;
                }
            }
        }
        
        // Set trial dates (3 days from now)
        $trialStart = date('Y-m-d H:i:s');
        $trialEnd = date('Y-m-d H:i:s', strtotime('+3 days'));
        
        // Insert school (not approved yet)
        $stmt = $db->prepare("
            INSERT INTO school_info 
            (school_name, school_type_id, address, location, circuit_name, headmaster_name, email, phone, logo1_url, 
             current_term, academic_year, is_approved, trial_start_date, trial_end_date, is_paid, 
             registered_by_admin, registration_date, district, circuit)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'First Term', '2024/2025', 0, ?, ?, 0, ?, NOW(), ?, ?)
        ");
        
        $result = $stmt->execute([
            $_POST['school_name'],
            $_POST['school_type_id'],
            !empty($_POST['address']) ? $_POST['address'] : 'N/A',
            $_POST['location'],
            !empty($_POST['circuit_name']) ? $_POST['circuit_name'] : null,
            !empty($_POST['headmaster_name']) ? $_POST['headmaster_name'] : 'N/A',
            $_POST['admin_email'],
            $_POST['phone'],
            $logoUrl,
            $trialStart,
            $trialEnd,
            $_POST['admin_name'],
            !empty($_POST['district']) ? $_POST['district'] : null,
            !empty($_POST['circuit']) ? $_POST['circuit'] : null
        ]);
        
        if (!$result) {
            throw new Exception("Failed to register school. Please try again.");
        }
        
        // Get the newly created school ID
        $schoolId = $db->lastInsertId();
        
        // Auto-create classes for this school based on school type
        $classTemplates = [
            1 => [ // Primary School
                'KG One', 'KG Two', 'Basic One', 'Basic Two', 'Basic Three', 
                'Basic Four', 'Basic Five', 'Basic Six'
            ],
            2 => [ // JHS
                'Basic Seven', 'Basic Eight', 'Basic Nine'
            ],
            3 => [ // Basic School (has BOTH Primary + JHS)
                'KG One', 'KG Two', 'Basic One', 'Basic Two', 'Basic Three', 
                'Basic Four', 'Basic Five', 'Basic Six', 
                'Basic Seven', 'Basic Eight', 'Basic Nine'
            ]
        ];
        
        $schoolTypeId = $_POST['school_type_id'];
        $templates = $classTemplates[$schoolTypeId] ?? [];
        
        // Check if multi-stream setup is requested
        $useStreams = isset($_POST['use_streams']) && $_POST['use_streams'] == '1';
        $selectedStreams = isset($_POST['streams']) && is_array($_POST['streams']) ? $_POST['streams'] : [];
        
        if ($useStreams && !empty($selectedStreams)) {
            // Create classes with streams
            foreach ($templates as $className) {
                foreach ($selectedStreams as $stream) {
                    $classCode = 'SCH' . $schoolId . '_' . strtoupper(str_replace(' ', '', $className)) . '-' . $stream;
                    $stmt = $db->prepare("
                        INSERT INTO classes (class_name, class_code, stream, school_type_id, school_id, total_attendance)
                        VALUES (?, ?, ?, ?, ?, 71)
                    ");
                    $stmt->execute([$className, $classCode, $stream, $schoolTypeId, $schoolId]);
                    
                    // Get the class ID for creating subjects
                    $classId = $db->lastInsertId();
                    
                    // Auto-create subjects for this class (we'll add this logic)
                    createSubjectsForClass($db, $classId, $className, $schoolTypeId);
                }
            }
        } else {
            // Create single-stream classes (original behavior)
            foreach ($templates as $className) {
                $classCode = 'SCH' . $schoolId . '_' . strtoupper(str_replace(' ', '', $className));
                $stmt = $db->prepare("
                    INSERT INTO classes (class_name, class_code, school_type_id, school_id, total_attendance)
                    VALUES (?, ?, ?, ?, 71)
                ");
                $stmt->execute([$className, $classCode, $schoolTypeId, $schoolId]);
                
                // Get the class ID for creating subjects
                $classId = $db->lastInsertId();
                
                // Auto-create subjects for this class
                createSubjectsForClass($db, $classId, $className, $schoolTypeId);
            }
        }
        
        // Create admin user for this school (inactive until approved)
        $passwordHash = password_hash($_POST['admin_password'], PASSWORD_DEFAULT);
        $stmt = $db->prepare("
            INSERT INTO users 
            (username, password, user_type, full_name, email, school_type_id, school_id, is_active)
            VALUES (?, ?, 'admin', ?, ?, ?, ?, 0)
        ");
        
        $result = $stmt->execute([
            $_POST['admin_username'],
            $passwordHash,
            $_POST['admin_name'],
            $_POST['admin_email'],
            $_POST['school_type_id'],
            $schoolId  // CRITICAL: Link user to specific school
        ]);
        
        if (!$result) {
            throw new Exception("Failed to create admin account. Please try again.");
        }
        
        $db->commit();
        
        $successMessage = "Registration successful! Your school has been registered and is pending approval. You will receive a 3-day free trial once the developer approves your school. Please wait for approval before attempting to login.";
        
    } catch (PDOException $e) {
        $db->rollBack();
        $errorMessage = "Database Error: " . $e->getMessage();
    } catch (Exception $e) {
        $db->rollBack();
        $errorMessage = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- Primary SEO -->
    <title>Register Your School - SBA System | Free School Based Assessment Platform</title>
    <meta name="description" content="Register your school on the SBA System for free. Manage student assessments, exam scores, broadsheets, and result analysis online.">
    <meta name="keywords" content="register school SBA, school based assessment registration, free school assessment system, SBA system signup, student assessment platform">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="<?php echo rtrim(defined('APP_URL') ? APP_URL : 'https://sba.techlawsoftwares.com', '/'); ?>/school_register.php">

    <!-- Open Graph -->
    <meta property="og:type" content="website">
    <meta property="og:title" content="Register Your School - SBA System">
    <meta property="og:description" content="Register your school and start managing student assessments, broadsheets, and exam analysis for free.">
    <meta property="og:url" content="<?php echo rtrim(defined('APP_URL') ? APP_URL : 'https://sba.techlawsoftwares.com', '/'); ?>/school_register.php">
    <meta property="og:site_name" content="SBA System">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .container { background: white; border-radius: 12px; box-shadow: 0 8px 32px rgba(0,0,0,0.15); max-width: 700px; width: 100%; padding: 35px; }
        .header { text-align: center; margin-bottom: 25px; }
        .header h1 { color: #1a202c; font-size: 1.75rem; margin-bottom: 8px; font-weight: 600; display: flex; align-items: center; justify-content: center; gap: 10px; }
        .header p { color: #718096; font-size: 0.95rem; line-height: 1.5; }
        .alert { padding: 14px 18px; border-radius: 8px; margin-bottom: 20px; font-size: 0.95rem; }
        .alert-success { background: #c6f6d5; color: #22543d; border-left: 4px solid #48bb78; }
        .alert-error { background: #fed7d7; color: #742a2a; border-left: 4px solid #f56565; }
        .form-group { margin-bottom: 18px; }
        .form-group label { display: block; margin-bottom: 6px; color: #2d3748; font-weight: 600; font-size: 0.9rem; }
        .form-group label .required { color: #f56565; }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 10px 12px; border: 2px solid #e2e8f0; border-radius: 6px; font-size: 0.95rem; transition: all 0.2s; }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus { outline: none; border-color: #667eea; box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1); }
        .form-group textarea { min-height: 80px; resize: vertical; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
        .form-group small { display: block; margin-top: 5px; color: #718096; font-size: 0.85rem; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
        .section-title { font-size: 1.1rem; color: #1a202c; margin: 25px 0 15px; padding-bottom: 10px; border-bottom: 2px solid #667eea; font-weight: 600; }
        .btn { width: 100%; padding: 12px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; border-radius: 6px; font-size: 1rem; font-weight: 600; cursor: pointer; transition: all 0.2s; }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4); }
        .btn:active { transform: translateY(0); }
        .footer-links { text-align: center; margin-top: 20px; font-size: 0.9rem; }
        .footer-links a { color: #667eea; text-decoration: none; margin: 0 10px; font-weight: 500; }
        .footer-links a:hover { text-decoration: underline; }
        .info-box { background: linear-gradient(135deg, #edf2f7 0%, #e2e8f0 100%); border-left: 4px solid #667eea; padding: 15px; border-radius: 8px; margin-bottom: 25px; }
        .info-box h3 { color: #1a202c; font-size: 1rem; margin-bottom: 10px; font-weight: 600; }
        .info-box ul { margin-left: 20px; color: #4a5568; font-size: 0.9rem; line-height: 1.8; }
        @media (max-width: 768px) {
            body { padding: 15px; }
            .container { padding: 20px; }
            .form-row { grid-template-columns: 1fr; gap: 15px; }
            .header h1 { font-size: 1.4rem; }
        }
        @media (max-width: 480px) {
            .header h1 { font-size: 1.25rem; flex-direction: column; gap: 5px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🎓 School Registration</h1>
            <p>Register your school to start using our management system</p>
        </div>
        
        <?php if ($successMessage): ?>
            <div class="alert alert-success">
                <strong>✓ Success!</strong> <?php echo htmlspecialchars($successMessage); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($errorMessage): ?>
            <div class="alert alert-error">
                <strong>✗ Error!</strong> <?php echo htmlspecialchars($errorMessage); ?>
            </div>
        <?php endif; ?>
        
        <?php if (empty($successMessage)): ?>
            <div class="info-box">
                <h3>📋 Registration Process:</h3>
                <ul>
                    <li><strong>Step 1:</strong> Fill in your school details below</li>
                    <li><strong>Step 2:</strong> Wait for developer approval (usually within 24 hours)</li>
                    <li><strong>Step 3:</strong> Enjoy 3 days FREE trial once approved</li>
                    <li><strong>Step 4:</strong> Contact developer to pay for forever use after trial</li>
                </ul>
            </div>
            
            <form action="school_register.php" method="POST" enctype="multipart/form-data">
                <div class="section-title">School Information</div>
                
                <div class="form-group">
                    <label>School Name <span class="required">*</span></label>
                    <input type="text" name="school_name" required placeholder="Enter full school name">
                </div>
                
                <div class="form-group">
                    <label>School Type <span class="required">*</span></label>
                    <select name="school_type_id" required id="schoolTypeSelect">
                        <option value="">-- Select School Type --</option>
                        <?php foreach ($schoolTypes as $type): ?>
                            <option value="<?php echo $type['id']; ?>">
                                <?php echo htmlspecialchars($type['type_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="use_streams" id="useStreams" value="1" style="width: auto; margin-right: 8px;">
                        <strong>My school has multiple streams (A, B, C, D)</strong>
                    </label>
                    <small style="display: block; margin-top: 5px; color: #666;">
                        Check this if your school has parallel classes like "Basic 8 A", "Basic 8 B", etc. 
                        You can add more streams later from Settings.
                    </small>
                </div>
                
                <div id="streamsConfig" style="display: none; background: #f7fafc; padding: 15px; border-radius: 8px; border: 2px solid #e2e8f0; margin-bottom: 15px;">
                    <div class="form-group" style="margin-bottom: 10px;">
                        <label style="color: #2d3748; font-size: 0.95rem;">Select streams to create for ALL classes:</label>
                        <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-top: 8px;">
                            <label style="display: flex; align-items: center; font-weight: normal; cursor: pointer;">
                                <input type="checkbox" name="streams[]" value="A" style="width: auto; margin-right: 6px;"> Stream A
                            </label>
                            <label style="display: flex; align-items: center; font-weight: normal; cursor: pointer;">
                                <input type="checkbox" name="streams[]" value="B" style="width: auto; margin-right: 6px;"> Stream B
                            </label>
                            <label style="display: flex; align-items: center; font-weight: normal; cursor: pointer;">
                                <input type="checkbox" name="streams[]" value="C" style="width: auto; margin-right: 6px;"> Stream C
                            </label>
                            <label style="display: flex; align-items: center; font-weight: normal; cursor: pointer;">
                                <input type="checkbox" name="streams[]" value="D" style="width: auto; margin-right: 6px;"> Stream D
                            </label>
                            <label style="display: flex; align-items: center; font-weight: normal; cursor: pointer;">
                                <input type="checkbox" name="streams[]" value="E" style="width: auto; margin-right: 6px;"> Stream E
                            </label>
                            <label style="display: flex; align-items: center; font-weight: normal; cursor: pointer;">
                                <input type="checkbox" name="streams[]" value="F" style="width: auto; margin-right: 6px;"> Stream F
                            </label>
                        </div>
                        <small style="color: #2c5282; margin-top: 8px; display: block;">
                            💡 Example: If you select A, B, C, then "Basic Eight" becomes "Basic Eight A", "Basic Eight B", "Basic Eight C"
                        </small>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Location <span class="required">*</span></label>
                    <input type="text" name="location" required placeholder="City/Town/Region">
                    <small>We use school name + location to prevent duplicate registrations</small>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>District</label>
                        <input type="text" name="district" placeholder="e.g., MAMPONG MUNICIPAL">
                        <small>Educational district name (for BECE analysis reports)</small>
                    </div>
                    
                    <div class="form-group">
                        <label>Circuit</label>
                        <input type="text" name="circuit" placeholder="e.g., MAMPONG CENTRAL">
                        <small>Educational circuit name (for BECE analysis reports)</small>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Circuit Name</label>
                    <input type="text" name="circuit_name" placeholder="e.g., KOFIASE CIRCUIT">
                    <small>Legacy circuit field (optional)</small>
                </div>
                
                <div class="form-group">
                    <label>Address</label>
                    <textarea name="address" placeholder="P.O. Box, Street address, etc."></textarea>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Phone <span class="required">*</span></label>
                        <input type="tel" name="phone" required placeholder="0244XXXXXX">
                    </div>
                    
                    <div class="form-group">
                        <label>Headmaster/Headmistress Name</label>
                        <input type="text" name="headmaster_name" placeholder="Full name">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>School Logo (Optional)</label>
                    <input type="file" name="logo" accept="image/*">
                    <small>Recommended: PNG/JPG, max 2MB</small>
                </div>
                
                <div class="section-title">Administrator Account</div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Username <span class="required">*</span></label>
                        <input type="text" name="admin_username" required placeholder="Choose a username">
                    </div>
                    
                    <div class="form-group">
                        <label>Password <span class="required">*</span></label>
                        <input type="password" name="admin_password" required minlength="6" placeholder="Minimum 6 characters">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Full Name <span class="required">*</span></label>
                    <input type="text" name="admin_name" required placeholder="Administrator's full name">
                </div>
                
                <div class="form-group">
                    <label>Email <span class="required">*</span></label>
                    <input type="email" name="admin_email" required placeholder="your.email@example.com">
                </div>
                
                <button type="submit" class="btn">Register School</button>
            </form>
        <?php endif; ?>
        
        <div class="footer-links">
            <a href="login.php">← Back to Login</a>
            <?php if (!empty($successMessage)): ?>
                <a href="school_register.php">Register Another School</a>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        // Toggle streams configuration
        document.getElementById('useStreams').addEventListener('change', function() {
            document.getElementById('streamsConfig').style.display = this.checked ? 'block' : 'none';
        });
    </script>
</body>
</html>
