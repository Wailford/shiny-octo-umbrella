<?php
if (!isset($_SESSION)) {
    session_start();
}

// Get school info from database, not session
require_once __DIR__ . '/../config/database.php';
$db = Database::getInstance()->getConnection();
$schoolInfo = [];
if (isset($_SESSION['school_id'])) {
    $stmt = $db->prepare("SELECT * FROM school_info WHERE id = ?");
    $stmt->execute([$_SESSION['school_id']]);
    $schoolInfo = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

// Get user role properly
$userRole = $_SESSION['role'] ?? $_SESSION['user_type'] ?? 'teacher';
$userName = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User';

// Display role name based on school type and role
$roleDisplay = 'Teacher';
if ($userRole === 'admin' || ($_SESSION['user_type'] ?? '') === 'admin') {
    $roleDisplay = 'Administrator';
} elseif ($userRole === 'form_master') {
    $roleDisplay = ($_SESSION['school_type_id'] == 1) ? 'Class Teacher' : 'Form Master';
} elseif ($userRole === 'subject_master') {
    $roleDisplay = 'Subject Teacher';
} elseif ($userRole === 'teacher') {
    $roleDisplay = ($_SESSION['school_type_id'] == 1) ? 'Class Teacher' : 'Teacher';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="description" content="School Management System">
    <meta name="theme-color" content="#667eea">
    <title><?php echo $pageTitle ?? 'School Management System'; ?> - <?php echo htmlspecialchars($schoolInfo['school_name'] ?? 'School'); ?></title>
    <link rel="stylesheet" href="assets/mobile-responsive.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: #f5f7fa;
            min-height: 100vh;
            padding-bottom: 60px;
        }
        
        /* Header Styles */
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .header-content {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
        }
        
        .school-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .school-logo {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: white;
            padding: 4px;
        }
        
        .school-name {
            font-size: 1.1rem;
            font-weight: 600;
            line-height: 1.2;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
        }
        
        .user-badge {
            background: rgba(255,255,255,0.2);
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
            font-size: 0.75rem;
        }
        
        /* Navigation */
        .nav-container {
            background: white;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .nav-content {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
        }
        
        .nav-content::-webkit-scrollbar {
            display: none;
        }
        
        .nav-links {
            display: flex;
            gap: 0;
            padding: 0 0.5rem;
        }
        
        .nav-links a {
            color: #2d3748 !important;
            text-decoration: none;
            padding: 1rem 1rem;
            white-space: nowrap;
            border-bottom: 3px solid transparent;
            transition: all 0.2s;
            font-weight: 500;
            font-size: 0.95rem !important;
            display: inline-block;
            visibility: visible !important;
            opacity: 1 !important;
        }
        
        .nav-links a:hover,
        .nav-links a.active {
            color: #667eea !important;
            border-bottom-color: #667eea;
            background: rgba(102, 126, 234, 0.05);
        }
        
        /* Hamburger toggle button */
        .menu-toggle {
            display: none;
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            width: 40px;
            height: 40px;
            border-radius: 8px;
            cursor: pointer;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 5px;
            flex-shrink: 0;
        }
        .menu-toggle span {
            display: block;
            width: 22px;
            height: 2px;
            background: white;
            border-radius: 2px;
            transition: all 0.3s ease;
        }
        .menu-toggle.open span:nth-child(1) { transform: translateY(7px) rotate(45deg); }
        .menu-toggle.open span:nth-child(2) { opacity: 0; }
        .menu-toggle.open span:nth-child(3) { transform: translateY(-7px) rotate(-45deg); }

        /* Mobile drawer overlay */
        .drawer-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            z-index: 9998;
        }
        .drawer-overlay.open { display: block; }

        /* Mobile slide-in drawer */
        .mobile-drawer {
            position: fixed;
            top: 0;
            left: -280px;
            width: 280px;
            height: 100%;
            background: white;
            z-index: 9999;
            box-shadow: 4px 0 20px rgba(0,0,0,0.15);
            transition: left 0.3s ease;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
        }
        .mobile-drawer.open { left: 0; }
        .mobile-drawer-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 1.25rem 1rem;
            color: white;
        }
        .mobile-drawer-header .school-name-drawer {
            font-weight: 700;
            font-size: 1rem;
            margin-bottom: 0.2rem;
        }
        .mobile-drawer-header .user-name-drawer {
            font-size: 0.8rem;
            opacity: 0.85;
        }
        .mobile-drawer-links {
            display: flex;
            flex-direction: column;
            padding: 0.5rem 0;
            flex: 1;
        }
        .mobile-drawer-links a {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.9rem 1.25rem;
            text-decoration: none;
            color: #2d3748;
            font-size: 0.95rem;
            font-weight: 500;
            border-left: 3px solid transparent;
            transition: all 0.15s;
        }
        .mobile-drawer-links a:hover,
        .mobile-drawer-links a.active {
            background: rgba(102, 126, 234, 0.08);
            color: #667eea;
            border-left-color: #667eea;
        }
        .mobile-drawer-links a.logout-link {
            color: #e53e3e;
            margin-top: auto;
            border-top: 1px solid #f0f0f0;
        }
        .mobile-drawer-links a.logout-link:hover {
            background: rgba(229,62,62,0.08);
            border-left-color: #e53e3e;
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            .header {
                padding: 0.75rem;
            }
            .school-name {
                font-size: 0.9rem;
                max-width: 150px;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }
            .school-logo {
                width: 35px;
                height: 35px;
            }
            .user-info {
                font-size: 0.8rem;
            }
            .user-badge {
                display: none;
            }
            /* Hide desktop nav, show hamburger */
            .nav-container {
                display: none;
            }
            .menu-toggle {
                display: flex;
            }
        }

        @media (max-width: 480px) {
            .school-name {
                font-size: 0.85rem;
                max-width: 120px;
            }
            .school-logo {
                width: 30px;
                height: 30px;
            }
            .user-info span:first-child {
                display: none;
            }
        }
        
        /* Container */
        .container {
            max-width: 1400px;
            margin: 1.5rem auto;
            padding: 0 1rem;
        }
        
        @media (max-width: 768px) {
            .container {
                margin: 1rem auto;
                padding: 0 0.75rem;
            }
        }
        
        /* Card Styles */
        .card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        @media (max-width: 768px) {
            .card {
                padding: 1rem;
                border-radius: 8px;
                margin-bottom: 1rem;
            }
        }
        
        /* Button Styles */
        .btn {
            padding: 0.625rem 1.25rem;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-block;
            font-size: 0.95rem;
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none !important;
            box-shadow: none !important;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            box-shadow: 0 4px 6px rgba(102, 126, 234, 0.2);
        }
        
        .btn-primary:hover {
            opacity: 0.9;
            transform: translateY(-1px);
            box-shadow: 0 6px 12px rgba(102, 126, 234, 0.3);
        }

        .btn-success {
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
            color: white;
            box-shadow: 0 4px 6px rgba(72, 187, 120, 0.2);
        }

        .btn-success:hover {
            opacity: 0.9;
            transform: translateY(-1px);
            box-shadow: 0 6px 12px rgba(72, 187, 120, 0.3);
        }

        .btn-danger {
            background: linear-gradient(135deg, #f56565 0%, #e53e3e 100%);
            color: white;
            box-shadow: 0 4px 6px rgba(245, 101, 101, 0.2);
        }

        .btn-danger:hover {
            opacity: 0.9;
            transform: translateY(-1px);
            box-shadow: 0 6px 12px rgba(245, 101, 101, 0.3);
        }

        .btn-info {
            background: #4299e1;
            color: white;
        }

        .btn-info:hover {
            background: #3182ce;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(66, 153, 225, 0.4);
        }

        .btn-secondary {
            background: #a0aec0;
            color: white;
        }

        .btn-secondary:hover {
            background: #718096;
            transform: translateY(-1px);
        }

        .btn-outline {
            background: white;
            border: 1px solid #e2e8f0;
            color: #4a5568;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        }

        .btn-outline:hover {
            background: #f7fafc;
            border-color: #cbd5e0;
            transform: translateY(-1px);
        }

        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.8rem;
        }
        
        @media (max-width: 768px) {
            .btn {
                padding: 0.5rem 1rem;
                font-size: 0.875rem;
            }
        }
        
        /* Form Styles */
        .form-group {
            margin-bottom: 1.25rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: #2d3748;
        }
        
        .form-control {
            width: 100%;
            padding: 0.625rem;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 0.95rem;
            transition: border-color 0.2s;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        @media (max-width: 768px) {
            .form-control {
                padding: 0.5rem;
                font-size: 0.875rem;
            }
        }
        
        /* Table Responsive */
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            margin: 0 -1rem;
            padding: 0 1rem;
        }
        
        @media (max-width: 768px) {
            .table-responsive {
                margin: 0 -0.75rem;
                padding: 0 0.75rem;
            }
        }
        
        /* Alert Messages */
        .alert {
            padding: 1rem;
            border-radius: 6px;
            margin-bottom: 1.5rem;
            border-left: 4px solid;
        }
        
        .alert-success {
            background: #f0fdf4;
            border-color: #22c55e;
            color: #15803d;
        }
        
        .alert-error {
            background: #fef2f2;
            border-color: #ef4444;
            color: #991b1b;
        }
        
        .alert-info {
            background: #eff6ff;
            border-color: #3b82f6;
            color: #1e40af;
        }
        
        @media (max-width: 768px) {
            .alert {
                padding: 0.75rem;
                font-size: 0.875rem;
            }
        }

        @media print {
            .header,
            .nav-container,
            footer,
            .footer,
            .alert,
            .btn,
            .no-print {
                display: none !important;
            }
            body {
                margin: 0;
                padding: 0;
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <div class="school-info">
                <?php 
                $logoUrl = $schoolInfo['logo1_url'] ?? '';
                // Fix relative path if needed
                if ($logoUrl && !preg_match('/^(https?:\/\/|data:)/i', $logoUrl)) {
                    // Remove any leading slash and 'uploads/' prefix
                    $logoUrl = ltrim($logoUrl, '/');
                    if (strpos($logoUrl, 'uploads/') !== 0) {
                        $logoUrl = 'uploads/' . $logoUrl;
                    }
                    // Add leading slash
                    $logoUrl = '/' . $logoUrl;
                }
                if ($logoUrl): 
                ?>
                    <img src="<?php echo htmlspecialchars($logoUrl); ?>" alt="School Logo" class="school-logo" onerror="this.style.display='none'">
                <?php endif; ?>
                <div class="school-name">
                    <div><?php echo htmlspecialchars($schoolInfo['school_name'] ?? 'School Based Assessment System'); ?></div>
                    <?php if (!empty($schoolInfo['academic_year']) || !empty($schoolInfo['current_term'])): ?>
                        <div style="font-size: 0.7rem; opacity: 0.9; font-weight: normal;">
                            <?php if (!empty($schoolInfo['academic_year'])): ?>Year: <?php echo htmlspecialchars($schoolInfo['academic_year']); ?><?php endif; ?>
                            <?php if (!empty($schoolInfo['current_term'])): ?> | <?php echo htmlspecialchars($schoolInfo['current_term']); ?><?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="user-info">
                <span><?php echo htmlspecialchars($userName); ?></span>
                <span class="user-badge"><?php echo htmlspecialchars($roleDisplay); ?></span>
            </div>
            <button class="menu-toggle" id="menuToggle" aria-label="Open menu" onclick="toggleDrawer()">
                <span></span><span></span><span></span>
            </button>
        </div>
    </header>
    
    <nav class="nav-container">
        <div class="nav-content">
            <div class="nav-links">
                <?php 
                $isAdmin = ($userRole === 'admin' || ($_SESSION['user_type'] ?? '') === 'admin');
                $hasFormMasterAssignment = false;
                $isSubjectTeacher = ($userRole === 'subject_master' && !$isAdmin);

                // A subject teacher can also be assigned as form master/class teacher.
                // In that case they should still see Dashboard navigation.
                if (!$isAdmin && isset($_SESSION['user_id'], $_SESSION['school_id'])) {
                    try {
                        $stmtFm = $db->prepare("SELECT COUNT(*) as count FROM form_masters fm JOIN classes c ON fm.class_id = c.id WHERE fm.user_id = ? AND c.school_id = ?");
                        $stmtFm->execute([$_SESSION['user_id'], $_SESSION['school_id']]);
                        $hasFormMasterAssignment = ((int)($stmtFm->fetch(PDO::FETCH_ASSOC)['count'] ?? 0)) > 0;
                    } catch (Exception $e) {
                        $hasFormMasterAssignment = false;
                    }
                }

                $isFormMasterOnly = ((($userRole === 'form_master') || $hasFormMasterAssignment) && !$isAdmin);
                if ($isFormMasterOnly && $isSubjectTeacher) {
                    $isSubjectTeacher = false;
                }
                
                // Get school type to determine menu access
                $schoolTypeCode = null;
                $isPrimarySchool = false;
                
                if (isset($_SESSION['school_type_id'])) {
                    try {
                        $stmtType = $db->prepare("SELECT type_code FROM school_types WHERE id = ?");
                        $stmtType->execute([$_SESSION['school_type_id']]);
                        $schoolType = $stmtType->fetch(PDO::FETCH_ASSOC);
                        $schoolTypeCode = $schoolType['type_code'] ?? null;
                        $isPrimarySchool = ($schoolTypeCode === 'PRIMARY');
                    } catch (Exception $e) {
                        // Fallback: check by ID (1 = Primary, 2 = JHS)
                        $isPrimarySchool = ($_SESSION['school_type_id'] == 1);
                    }
                }
                
                // Subject teachers only see Scores (JHS)
                if ($isSubjectTeacher): 
                ?>
                    <a href="scores.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'scores.php' ? 'active' : ''; ?>">✍️ Scores</a>
                    <a href="logout.php" style="color: #ef4444;">🚪 Logout</a>
                <?php 
                // Primary school class teachers see Dashboard and Scores
                elseif ($isFormMasterOnly && $isPrimarySchool): 
                ?>
                    <a href="dashboard.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>">📊 Dashboard</a>
                    <a href="scores.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'scores.php' ? 'active' : ''; ?>">✍️ Scores</a>
                    <a href="broadsheet.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'broadsheet.php' ? 'active' : ''; ?>">📋 Broadsheet</a>
                    <a href="logout.php" style="color: #ef4444;">🚪 Logout</a>
                <?php 
                // JHS form masters only see Dashboard
                elseif ($isFormMasterOnly): 
                ?>
                    <a href="dashboard.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>">📊 Dashboard</a>
                    <a href="logout.php" style="color: #ef4444;">🚪 Logout</a>
                <?php 
                // Admin sees everything
                else: 
                ?>
                    <a href="index.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : ''; ?>">🏠 Home</a>
                    <a href="dashboard.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>">📊 Dashboard</a>
                    <a href="scores.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'scores.php' ? 'active' : ''; ?>">✍️ Scores</a>
                    <a href="manage_subjects.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'manage_subjects.php' ? 'active' : ''; ?>">📚 Subjects</a>
                    <a href="grading_system.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'grading_system.php' ? 'active' : ''; ?>">🎯 Grading</a>
                    <a href="kg_grading_system.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'kg_grading_system.php' ? 'active' : ''; ?>">🎨 KG Grading</a>
                    <a href="broadsheet.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'broadsheet.php' ? 'active' : ''; ?>">📋 Broadsheet</a>
                    <a href="student-report.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'student-report.php' || basename($_SERVER['PHP_SELF']) == 'student-report-full.php' ? 'active' : ''; ?>">📄 Reports</a>
                    <a href="result-analysis.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'result-analysis.php' ? 'active' : ''; ?>">📈 Analysis</a>
                    <a href="notify_class.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'notify_class.php' ? 'active' : ''; ?>">📨 Send Reports</a>
                    <?php if (!empty($schoolInfo['fees_enabled'])): ?>
                    <a href="fees.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'fees.php' ? 'active' : ''; ?>">💰 Fees</a>
                    <?php endif; ?>
                    <a href="manage_parents.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'manage_parents.php' ? 'active' : ''; ?>">👨‍👩‍👧 Parents</a>
                    
                    <?php if ($isAdmin): ?>
                    <a href="settings.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'settings.php' ? 'active' : ''; ?>">⚙️ Settings</a>
                    <?php endif; ?>
                    
                    <a href="logout.php" style="color: #ef4444;">🚪 Logout</a>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <!-- Mobile Drawer Overlay -->
    <div class="drawer-overlay" id="drawerOverlay" onclick="toggleDrawer()"></div>

    <!-- Mobile Slide-in Drawer -->
    <div class="mobile-drawer" id="mobileDrawer">
        <div class="mobile-drawer-header">
            <div class="school-name-drawer"><?php echo htmlspecialchars($schoolInfo['school_name'] ?? 'School Based Assessment'); ?></div>
            <div class="user-name-drawer"><?php echo htmlspecialchars($userName); ?> &bull; <?php echo htmlspecialchars($roleDisplay); ?></div>
        </div>
        <div class="mobile-drawer-links">
            <?php if ($isSubjectTeacher): ?>
                <a href="scores.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'scores.php' ? 'active' : ''; ?>">✍️ Scores</a>
            <?php elseif ($isFormMasterOnly && $isPrimarySchool): ?>
                <a href="dashboard.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>">📊 Dashboard</a>
                <a href="scores.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'scores.php' ? 'active' : ''; ?>">✍️ Scores</a>
                <a href="broadsheet.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'broadsheet.php' ? 'active' : ''; ?>">📋 Broadsheet</a>
            <?php elseif ($isFormMasterOnly): ?>
                <a href="dashboard.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>">📊 Dashboard</a>
            <?php else: ?>
                <a href="index.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : ''; ?>">🏠 Home</a>
                <a href="dashboard.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>">📊 Dashboard</a>
                <a href="scores.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'scores.php' ? 'active' : ''; ?>">✍️ Scores</a>
                <a href="manage_subjects.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'manage_subjects.php' ? 'active' : ''; ?>">📚 Subjects</a>
                <a href="grading_system.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'grading_system.php' ? 'active' : ''; ?>">🎯 Grading</a>
                <a href="kg_grading_system.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'kg_grading_system.php' ? 'active' : ''; ?>">🎨 KG Grading</a>
                <a href="broadsheet.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'broadsheet.php' ? 'active' : ''; ?>">📋 Broadsheet</a>
                <a href="student-report.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'student-report.php' || basename($_SERVER['PHP_SELF']) == 'student-report-full.php') ? 'active' : ''; ?>">📄 Reports</a>
                <a href="result-analysis.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'result-analysis.php' ? 'active' : ''; ?>">📈 Analysis</a>
                <a href="notify_class.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'notify_class.php' ? 'active' : ''; ?>">📨 Send Reports</a>
                <?php if (!empty($schoolInfo['fees_enabled'])): ?>
                <a href="fees.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'fees.php' ? 'active' : ''; ?>">💰 Fees</a>
                <?php endif; ?>
                <a href="manage_parents.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'manage_parents.php' ? 'active' : ''; ?>">👨‍👩‍👧 Parents</a>
                <a href="manage_classes.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'manage_classes.php' ? 'active' : ''; ?>">🏫 Classes</a>
                <?php if ($isAdmin): ?>
                <a href="settings.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'settings.php' ? 'active' : ''; ?>">⚙️ Settings</a>
                <?php endif; ?>
            <?php endif; ?>
            <a href="logout.php" class="logout-link">🚪 Logout</a>
        </div>
    </div>

    <script>
    function toggleDrawer() {
        const drawer  = document.getElementById('mobileDrawer');
        const overlay = document.getElementById('drawerOverlay');
        const toggle  = document.getElementById('menuToggle');
        const isOpen  = drawer.classList.contains('open');
        drawer.classList.toggle('open', !isOpen);
        overlay.classList.toggle('open', !isOpen);
        toggle.classList.toggle('open', !isOpen);
        document.body.style.overflow = isOpen ? '' : 'hidden';
    }
    // Close drawer on escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const drawer = document.getElementById('mobileDrawer');
            if (drawer.classList.contains('open')) toggleDrawer();
        }
    });
    </script>
