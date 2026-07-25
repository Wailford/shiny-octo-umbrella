<?php
    require_once __DIR__ . '/config/config.php';
    require_once __DIR__ . '/controllers/AuthController.php';
    require_once __DIR__ . '/controllers/StudentController.php';
    require_once __DIR__ . '/controllers/ClassController.php';
    
    $auth = new Auth();
    $auth->requireLogin();

    // Check if user must change password
    require_once __DIR__ . '/config/database.php';
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT must_change_password FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $userData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($userData && $userData['must_change_password'] == 1) {
        header('Location: change_password.php');
        exit;
    }

    $currentUser = $auth->getCurrentUser();
    $isAdmin = $auth->isAdmin();

    // Home page is admin-only; redirect other roles to their default page
    if (!$isAdmin) {
        $role = $_SESSION['role'] ?? $_SESSION['user_type'] ?? 'teacher';
        if ($role === 'subject_master') {
            header('Location: scores.php');
        } else {
            header('Location: dashboard.php');
        }
        exit;
    }
    
    // Get school info
    $schoolInfo = null;
    if (isset($_SESSION['school_id'])) {
        $stmt = $db->prepare("SELECT * FROM school_info WHERE id = ?");
        $stmt->execute([$_SESSION['school_id']]);
        $schoolInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Get statistics
    $studentController = new StudentController();
    $classController = new ClassController();
    $schoolTypeId = $_SESSION['school_type_id'] ?? null;
    $classes = $classController->getAllClasses($schoolTypeId);

    $totalStudents = 0;
    foreach ($classes as $class) {
        $students = $studentController->getStudentsByClass($class['id']);
        $totalStudents += count($students);
    }
    
    // Count total subjects for this school
    $totalSubjects = 0;
    if (!empty($classes)) {
        $classIds = array_column($classes, 'id');
        $placeholders = implode(',', array_fill(0, count($classIds), '?'));
        $stmtSub = $db->prepare("SELECT COUNT(DISTINCT subject_name) FROM subjects WHERE class_id IN ($placeholders)");
        $stmtSub->execute($classIds);
        $totalSubjects = (int)$stmtSub->fetchColumn();
    }

    // Count teachers assigned to this school
    $totalTeachers = 0;
    if (isset($_SESSION['school_id'])) {
        $stmtTch = $db->prepare("SELECT COUNT(DISTINCT user_id) FROM form_masters fm JOIN classes c ON fm.class_id = c.id WHERE c.school_id = ?");
        $stmtTch->execute([$_SESSION['school_id']]);
        $totalTeachers = (int)$stmtTch->fetchColumn();
        $stmtTch2 = $db->prepare("SELECT COUNT(DISTINCT user_id) FROM subject_teachers st JOIN classes c ON st.class_id = c.id WHERE c.school_id = ?");
        $stmtTch2->execute([$_SESSION['school_id']]);
        $totalTeachers = max($totalTeachers, (int)$stmtTch2->fetchColumn());
    }

    $pageTitle = 'Home';
    require_once __DIR__ . '/components/header.php';
?>
    <style>
        .dash-wrap { max-width: 1280px; margin: 0 auto; padding: 1.5rem 1rem 2rem; }

        /* Welcome Banner */
        .welcome-banner {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 16px;
            padding: 2.5rem 2rem;
            color: white;
            margin-bottom: 1.75rem;
            position: relative;
            overflow: hidden;
        }
        .welcome-banner::after {
            content: '';
            position: absolute;
            top: -40%;
            right: -10%;
            width: 300px;
            height: 300px;
            background: rgba(255,255,255,0.06);
            border-radius: 50%;
        }
        .welcome-banner::before {
            content: '';
            position: absolute;
            bottom: -30%;
            left: 30%;
            width: 200px;
            height: 200px;
            background: rgba(255,255,255,0.04);
            border-radius: 50%;
        }
        .welcome-banner h1 { font-size: 1.65rem; font-weight: 700; margin: 0 0 0.35rem; position: relative; z-index: 1; }
        .welcome-banner .welcome-sub { font-size: 0.95rem; opacity: 0.9; margin: 0; position: relative; z-index: 1; }
        .welcome-meta { display: flex; gap: 1.5rem; margin-top: 1rem; flex-wrap: wrap; position: relative; z-index: 1; }
        .welcome-meta span { display: flex; align-items: center; gap: 0.35rem; font-size: 0.85rem; background: rgba(255,255,255,0.15); padding: 0.35rem 0.75rem; border-radius: 20px; }

        /* Stat Cards */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin-bottom: 1.75rem;
        }
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 1.25rem 1rem;
            box-shadow: 0 1px 6px rgba(0,0,0,0.06);
            display: flex;
            align-items: center;
            gap: 1rem;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 4px 16px rgba(0,0,0,0.1); }
        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            flex-shrink: 0;
        }
        .stat-icon.blue   { background: #eff3ff; color: #667eea; }
        .stat-icon.green  { background: #ecfdf5; color: #10b981; }
        .stat-icon.purple { background: #f5f3ff; color: #8b5cf6; }
        .stat-icon.amber  { background: #fffbeb; color: #f59e0b; }
        .stat-data .stat-number { font-size: 1.6rem; font-weight: 800; color: #1e293b; line-height: 1; }
        .stat-data .stat-label  { font-size: 0.8rem; color: #64748b; margin-top: 0.15rem; }

        /* Quick Actions Section Title */
        .section-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Module Cards Grid */
        .modules-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 1rem;
        }
        .module-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 1px 6px rgba(0,0,0,0.06);
            text-decoration: none;
            color: inherit;
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            transition: all 0.25s;
            border: 1px solid transparent;
        }
        .module-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 24px rgba(0,0,0,0.1);
            border-color: #e2e8f0;
        }
        .mod-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            flex-shrink: 0;
        }
        .mod-body h3 { font-size: 1rem; font-weight: 600; color: #1e293b; margin: 0 0 0.25rem; }
        .mod-body p  { font-size: 0.82rem; color: #64748b; margin: 0; line-height: 1.45; }

        /* Color themes for module icons */
        .mod-icon.mi-students  { background: linear-gradient(135deg, #667eea, #764ba2); color: #fff; }
        .mod-icon.mi-scores    { background: linear-gradient(135deg, #f59e0b, #d97706); color: #fff; }
        .mod-icon.mi-broadsheet{ background: linear-gradient(135deg, #10b981, #059669); color: #fff; }
        .mod-icon.mi-analysis  { background: linear-gradient(135deg, #3b82f6, #2563eb); color: #fff; }
        .mod-icon.mi-reports   { background: linear-gradient(135deg, #8b5cf6, #7c3aed); color: #fff; }
        .mod-icon.mi-send      { background: linear-gradient(135deg, #ec4899, #db2777); color: #fff; }
        .mod-icon.mi-settings  { background: linear-gradient(135deg, #6b7280, #4b5563); color: #fff; }
        .mod-icon.mi-password  { background: linear-gradient(135deg, #6b7280, #4b5563); color: #fff; }
        .mod-icon.mi-subjects  { background: linear-gradient(135deg, #14b8a6, #0d9488); color: #fff; }
        .mod-icon.mi-grading   { background: linear-gradient(135deg, #f43f5e, #e11d48); color: #fff; }
        .mod-icon.mi-parents   { background: linear-gradient(135deg, #0ea5e9, #0284c7); color: #fff; }
        .mod-icon.mi-fees      { background: linear-gradient(135deg, #84cc16, #65a30d); color: #fff; }

        /* Responsive */
        @media (max-width: 992px) {
            .stats-row { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 768px) {
            .dash-wrap { padding: 1rem 0.75rem; }
            .welcome-banner { padding: 1.5rem 1.25rem; border-radius: 12px; }
            .welcome-banner h1 { font-size: 1.3rem; }
            .welcome-meta { gap: 0.75rem; }
            .modules-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 480px) {
            .stats-row { grid-template-columns: 1fr 1fr; gap: 0.75rem; }
            .stat-card { padding: 1rem 0.75rem; }
            .stat-data .stat-number { font-size: 1.35rem; }
            .welcome-banner h1 { font-size: 1.15rem; }
        }
    </style>

    <div class="dash-wrap">
        <!-- Welcome Banner -->
        <div class="welcome-banner">
            <h1>Welcome, <?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User'); ?></h1>
            <p class="welcome-sub"><?php echo $schoolInfo ? htmlspecialchars($schoolInfo['school_name']) : 'School Based Assessment System'; ?></p>
            <div class="welcome-meta">
                <?php if (!empty($schoolInfo['academic_year'])): ?>
                <span>Academic Year: <?php echo htmlspecialchars($schoolInfo['academic_year']); ?></span>
                <?php endif; ?>
                <?php if (!empty($schoolInfo['current_term'])): ?>
                <span><?php echo htmlspecialchars($schoolInfo['current_term']); ?></span>
                <?php endif; ?>
                <?php if (!empty($schoolInfo['location'])): ?>
                <span><?php echo htmlspecialchars($schoolInfo['location']); ?></span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Stats Row -->
        <div class="stats-row">
            <div class="stat-card">
                <div class="stat-icon blue">&#x1F393;</div>
                <div class="stat-data">
                    <div class="stat-number"><?php echo $totalStudents; ?></div>
                    <div class="stat-label">Students</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon green">&#x1F3EB;</div>
                <div class="stat-data">
                    <div class="stat-number"><?php echo count($classes); ?></div>
                    <div class="stat-label">Classes</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon purple">&#x1F4DA;</div>
                <div class="stat-data">
                    <div class="stat-number"><?php echo $totalSubjects; ?></div>
                    <div class="stat-label">Subjects</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon amber">&#x1F464;</div>
                <div class="stat-data">
                    <div class="stat-number">Admin</div>
                    <div class="stat-label">Access Level</div>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="section-title">Quick Actions</div>
        <div class="modules-grid">
            <a href="dashboard.php" class="module-card">
                <div class="mod-icon mi-students">&#x1F4CA;</div>
                <div class="mod-body">
                    <h3>Student Records</h3>
                    <p>Manage student information, attendance, conduct, and remarks.</p>
                </div>
            </a>

            <a href="scores.php" class="module-card">
                <div class="mod-icon mi-scores">&#x270D;&#xFE0F;</div>
                <div class="mod-body">
                    <h3>Score Entry</h3>
                    <p>Enter and manage scores with automatic grade calculation.</p>
                </div>
            </a>

            <a href="broadsheet.php" class="module-card">
                <div class="mod-icon mi-broadsheet">&#x1F4CB;</div>
                <div class="mod-body">
                    <h3>Class Broadsheet</h3>
                    <p>Generate broadsheets showing all students and subjects.</p>
                </div>
            </a>

            <a href="result-analysis.php" class="module-card">
                <div class="mod-icon mi-analysis">&#x1F4C8;</div>
                <div class="mod-body">
                    <h3>Result Analysis</h3>
                    <p>Analyse performance with charts and recommendations.</p>
                </div>
            </a>

            <a href="student-report.php" class="module-card">
                <div class="mod-icon mi-reports">&#x1F4C4;</div>
                <div class="mod-body">
                    <h3>Student Reports</h3>
                    <p>Generate individual report cards with PDF export.</p>
                </div>
            </a>

            <a href="notify_class.php" class="module-card">
                <div class="mod-icon mi-send">&#x1F4E8;</div>
                <div class="mod-body">
                    <h3>Send Reports</h3>
                    <p>Send report cards to parents via SMS, WhatsApp, or Email.</p>
                </div>
            </a>

            <a href="manage_subjects.php" class="module-card">
                <div class="mod-icon mi-subjects">&#x1F4DA;</div>
                <div class="mod-body">
                    <h3>Manage Subjects</h3>
                    <p>Add, edit, or remove subjects for each class.</p>
                </div>
            </a>

            <a href="manage_parents.php" class="module-card">
                <div class="mod-icon mi-parents">&#x1F468;&#x200D;&#x1F469;&#x200D;&#x1F467;</div>
                <div class="mod-body">
                    <h3>Parents</h3>
                    <p>Manage parent contacts and student-parent links.</p>
                </div>
            </a>

            <a href="settings.php" class="module-card">
                <div class="mod-icon mi-settings">&#x2699;&#xFE0F;</div>
                <div class="mod-body">
                    <h3>Settings</h3>
                    <p>School info, teachers, passwords, and system configuration.</p>
                </div>
            </a>

            <a href="change_password.php" class="module-card">
                <div class="mod-icon mi-password">&#x1F512;</div>
                <div class="mod-body">
                    <h3>Change Password</h3>
                    <p>Update your account password and security settings.</p>
                </div>
            </a>
        </div>
    </div>

<?php require_once __DIR__ . '/components/footer.php'; ?>
