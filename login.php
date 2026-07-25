<?php
session_start();

// Developer mode check
$isDeveloperMode = isset($_GET['dev']) && $_GET['dev'] === 'true';

// Redirect logged-in users to index
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    header('Location: index.php');
    exit;
}

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username']) && isset($_POST['password'])) {
    require_once __DIR__ . '/config/database.php';
    require_once __DIR__ . '/controllers/AuthController.php';
    
    $db = Database::getInstance()->getConnection();
    
    // Check for developer login - works from any login page
    if ($_POST['username'] === 'developer' || $_POST['username'] === 'dev') {
        // Developer login - verify against database
        $stmt = $db->prepare("SELECT id, password FROM users WHERE username = ? AND user_type = 'developer'");
        $stmt->execute([$_POST['username']]);
        $devUser = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($devUser && password_verify($_POST['password'], $devUser['password'])) {
            // Developer login successful
            $_SESSION['logged_in'] = true;
            $_SESSION['is_developer'] = true;
            $_SESSION['user_id'] = $devUser['id'];
            $_SESSION['username'] = 'Developer';
            $_SESSION['role'] = 'developer';
            $_SESSION['user_type'] = 'developer';
            header('Location: developer_dashboard.php');
            exit;
        } else {
            header('Location: login.php?error=credentials');
            exit;
        }
    }
    
    // User login (admin or class_teacher)
    // First check if username exists
    $stmt = $db->prepare("SELECT u.*, si.is_approved, si.is_paid, si.trial_end_date, si.school_name 
                          FROM users u
                          LEFT JOIN school_info si ON u.school_id = si.id
                          WHERE u.username = ?");
    $stmt->execute([$_POST['username']]);
    $userCheck = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($userCheck) {
        // Verify password first
        if (!password_verify($_POST['password'], $userCheck['password'])) {
            header('Location: login.php?error=credentials');
            exit;
        }
        
        // Check if school is approved by developer
        if (!$userCheck['is_approved']) {
            $errorMsg = urlencode("Your school registration is pending approval by the developer. Please wait for approval before attempting to login.");
            header('Location: login.php?error=not_approved&msg=' . $errorMsg);
            exit;
        }
        
        // Check if user account is active
        if (!$userCheck['is_active']) {
            $errorMsg = urlencode("Your account has been deactivated. Please contact your administrator or the developer.");
            header('Location: login.php?error=inactive&msg=' . $errorMsg);
            exit;
        }
        
        // Check trial and payment status
        if (!$userCheck['is_paid']) {
            $trialEnd = strtotime($userCheck['trial_end_date']);
            $now = time();
            
            if ($now > $trialEnd) {
                // Trial expired
                $contactMsg = urlencode("Your 3-day free trial has expired. To continue using the system, please contact the developer to pay for forever use.\n\nContact Developer:\n📱 0257514418\n📱 0502160502\n\nThank you for using our School Based Assessment System!");
                header('Location: login.php?error=trial_expired&msg=' . $contactMsg . '&school=' . urlencode($userCheck['school_name']));
                exit;
            }
            // else: Trial still active, allow login
        }
        
        // All checks passed, proceed with login
        $auth = new Auth();
        $result = $auth->login(
            $_POST['username'],
            $_POST['password'],
            $userCheck['user_type']
        );
        
        if ($result['success']) {
            // SECURITY FIX: Use ONLY the school_id and school_type_id from the user's database record
            // Never allow POST data to override this - prevents unauthorized access
            $_SESSION['school_id'] = $userCheck['school_id']; // CRITICAL: Specific school for isolation
            $_SESSION['school_type_id'] = $userCheck['school_type_id']; // School type (for backward compatibility)
            header('Location: index.php');
            exit;
        } elseif (isset($result['error']) && $result['error'] === 'school_locked') {
            // School is locked
            $lockReason = urlencode($result['lock_reason']);
            header('Location: login.php?error=school_locked&reason=' . $lockReason);
            exit;
        }
    }
    
    header('Location: login.php?error=credentials');
    exit;
}

// Initialize database connection for the login page
require_once __DIR__ . '/config/database.php';
$db = Database::getInstance()->getConnection();

// Production mode only
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">

    <!-- Google Search Console Verification -->
    <meta name="google-site-verification" content="c77de5fcf644dfde" />

    <!-- Primary SEO -->
    <title>SBA System - School Based Assessment &amp; Exam Analysis Platform</title>
    <meta name="description" content="Manage school-based assessments, student scores, broadsheets, and exam result analysis online. The complete SBA system for primary and JHS schools.">
    <meta name="keywords" content="SBA system, school based assessment, student assessment app, exam analysis system, exams analysis, school management system, JHS broadsheet, student scores, result analysis, SBA Ghana, school based assessment app, students assessment app">
    <meta name="robots" content="index, follow">
    <meta name="author" content="SBA System">
    <link rel="canonical" href="<?php echo rtrim(defined('APP_URL') ? APP_URL : 'https://sba.techlawsoftwares.com', '/'); ?>/login.php">

    <!-- Open Graph (Facebook, WhatsApp, LinkedIn previews) -->
    <meta property="og:type" content="website">
    <meta property="og:title" content="SBA System - School Based Assessment &amp; Exam Analysis">
    <meta property="og:description" content="Complete online SBA platform: student assessments, scores, broadsheets, and exam analysis for primary and JHS schools.">
    <meta property="og:url" content="<?php echo rtrim(defined('APP_URL') ? APP_URL : 'https://sba.techlawsoftwares.com', '/'); ?>/login.php">
    <meta property="og:site_name" content="SBA System">
    <meta property="og:image" content="<?php echo rtrim(defined('APP_URL') ? APP_URL : 'https://sba.techlawsoftwares.com', '/'); ?>/assets/og-image.png">

    <!-- Twitter / X Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="SBA System - School Based Assessment &amp; Exam Analysis">
    <meta name="twitter:description" content="Complete online SBA platform: student assessments, scores, broadsheets, and exam analysis for primary and JHS schools.">

    <!-- JSON-LD Structured Data -->
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "WebApplication",
      "name": "SBA System",
      "alternateName": ["School Based Assessment System", "Student Assessment App", "Exam Analysis System"],
      "description": "Complete school-based assessment and exam analysis platform for primary and JHS schools.",
      "applicationCategory": "EducationApplication",
      "operatingSystem": "Web Browser",
      "url": "<?php echo rtrim(defined('APP_URL') ? APP_URL : 'https://sba.techlawsoftwares.com', '/'); ?>/login.php",
      "offers": {
        "@type": "Offer",
        "price": "0",
        "priceCurrency": "GHS"
      },
      "featureList": [
        "School Based Assessment (SBA)",
        "Student exam scores and analysis",
        "Class broadsheet generation",
        "Result analysis and PDF export",
        "Mock examination management",
        "Fee management"
      ]
    }
    </script>

    <link rel="stylesheet" href="assets/mobile-responsive.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        html {
            font-size: 16px;
        }
        
        @media (max-width: 768px) {
            html {
                font-size: 14px;
            }
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: radial-gradient(ellipse at bottom, #1b2735 0%, #090a0f 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            overflow: hidden;
        }
        
        /* Stars background */
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-image: 
                radial-gradient(2px 2px at 20% 30%, white, transparent),
                radial-gradient(2px 2px at 60% 70%, white, transparent),
                radial-gradient(1px 1px at 50% 50%, white, transparent),
                radial-gradient(1px 1px at 80% 10%, white, transparent),
                radial-gradient(2px 2px at 90% 60%, white, transparent),
                radial-gradient(1px 1px at 33% 80%, white, transparent),
                radial-gradient(1px 1px at 15% 90%, white, transparent),
                radial-gradient(1px 1px at 70% 40%, white, transparent),
                radial-gradient(2px 2px at 40% 20%, white, transparent),
                radial-gradient(1px 1px at 10% 60%, white, transparent);
            background-size: 200% 200%;
            background-position: 0 0, 40% 60%, 130% 270%, 70% 100%, 20% 50%, 60% 150%, 90% 30%, 110% 180%, 30% 90%, 50% 120%;
            animation: twinkle 4s ease-in-out infinite;
            z-index: 1;
            pointer-events: none;
        }
        
        @keyframes twinkle {
            0%, 100% { opacity: 0.8; transform: scale(1); }
            50% { opacity: 1; transform: scale(1.2); }
        }
        
        /* Solar system container */
        .solar-system {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 100%;
            height: 100%;
            z-index: 2;
            pointer-events: none;
        }
        
        /* Sun */
        .sun {
            position: absolute;
            top: 50%;
            left: 50%;
            width: 80px;
            height: 80px;
            background: radial-gradient(circle, #ffd700, #ff8c00);
            border-radius: 50%;
            transform: translate(-50%, -50%);
            box-shadow: 
                0 0 40px #ffd700, 
                0 0 80px #ff8c00,
                0 0 120px rgba(255, 140, 0, 0.4);
            animation: pulse 3s ease-in-out infinite;
        }
        
        @keyframes pulse {
            0%, 100% { 
                box-shadow: 0 0 40px #ffd700, 0 0 80px #ff8c00, 0 0 120px rgba(255, 140, 0, 0.4);
                transform: translate(-50%, -50%) scale(1);
            }
            50% { 
                box-shadow: 0 0 60px #ffd700, 0 0 120px #ff8c00, 0 0 160px rgba(255, 140, 0, 0.6);
                transform: translate(-50%, -50%) scale(1.05);
            }
        }
        
        /* Orbit paths */
        .orbit {
            position: absolute;
            top: 50%;
            left: 50%;
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            transform: translate(-50%, -50%);
        }
        
        .orbit1 { width: 200px; height: 200px; }
        .orbit2 { width: 300px; height: 300px; }
        .orbit3 { width: 420px; height: 420px; }
        .orbit4 { width: 540px; height: 540px; }
        .orbit5 { width: 680px; height: 680px; }
        .orbit6 { width: 820px; height: 820px; }
        
        /* Planets */
        .planet {
            position: absolute;
            border-radius: 50%;
            top: 50%;
            left: 50%;
            box-shadow: 0 0 10px rgba(255, 255, 255, 0.5);
        }
        
        /* Shooting stars */
        .shooting-star {
            position: fixed;
            width: 2px;
            height: 2px;
            background: white;
            border-radius: 50%;
            box-shadow: 0 0 8px 2px rgba(255, 255, 255, 0.8);
        }
        
        .shooting-star1 {
            top: 20%;
            left: -5%;
            animation: shoot1 3s linear infinite;
        }
        
        .shooting-star2 {
            top: 60%;
            left: -5%;
            animation: shoot2 4s linear infinite 1.5s;
        }
        
        .shooting-star3 {
            top: 40%;
            right: -5%;
            animation: shoot3 3.5s linear infinite 2s;
        }
        
        @keyframes shoot1 {
            0% { transform: translate(0, 0); opacity: 1; }
            70% { opacity: 1; }
            100% { transform: translate(120vw, 80vh); opacity: 0; }
        }
        
        @keyframes shoot2 {
            0% { transform: translate(0, 0); opacity: 1; }
            70% { opacity: 1; }
            100% { transform: translate(120vw, -60vh); opacity: 0; }
        }
        
        @keyframes shoot3 {
            0% { transform: translate(0, 0); opacity: 1; }
            70% { opacity: 1; }
            100% { transform: translate(-120vw, 70vh); opacity: 0; }
        }
        
        .mercury {
            width: 12px;
            height: 12px;
            background: radial-gradient(circle at 30% 30%, #b8a89a, #8c7853);
            animation: orbit1 10s linear infinite;
        }
        
        .venus {
            width: 16px;
            height: 16px;
            background: radial-gradient(circle at 30% 30%, #ffd666, #c69121);
            box-shadow: 0 0 15px rgba(255, 198, 73, 0.6);
            animation: orbit2 15s linear infinite;
        }
        
        .earth {
            width: 18px;
            height: 18px;
            background: radial-gradient(circle at 30% 30%, #6ab7ff, #2e5c8a);
            box-shadow: 0 0 20px rgba(74, 144, 226, 0.8);
            animation: orbit3 20s linear infinite;
        }
        
        .mars {
            width: 14px;
            height: 14px;
            background: radial-gradient(circle at 30% 30%, #ff8866, #c1440e);
            box-shadow: 0 0 15px rgba(226, 123, 88, 0.6);
            animation: orbit4 25s linear infinite;
        }
        
        .jupiter {
            width: 24px;
            height: 24px;
            background: radial-gradient(circle at 30% 30%, #d4a574, #b87333);
            box-shadow: 0 0 25px rgba(212, 165, 116, 0.7);
            animation: orbit5 35s linear infinite;
        }
        
        .saturn {
            width: 20px;
            height: 20px;
            background: radial-gradient(circle at 30% 30%, #f4e7c3, #c9a77c);
            box-shadow: 0 0 20px rgba(244, 231, 195, 0.6);
            animation: orbit6 40s linear infinite;
        }
        
        .saturn::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 35px;
            height: 8px;
            border: 2px solid rgba(244, 231, 195, 0.6);
            border-radius: 50%;
        }
        
        @keyframes orbit1 {
            from { transform: translate(-50%, -50%) rotate(0deg) translateX(100px) rotate(0deg); }
            to { transform: translate(-50%, -50%) rotate(360deg) translateX(100px) rotate(-360deg); }
        }
        
        @keyframes orbit2 {
            from { transform: translate(-50%, -50%) rotate(0deg) translateX(150px) rotate(0deg); }
            to { transform: translate(-50%, -50%) rotate(360deg) translateX(150px) rotate(-360deg); }
        }
        
        @keyframes orbit3 {
            from { transform: translate(-50%, -50%) rotate(0deg) translateX(210px) rotate(0deg); }
            to { transform: translate(-50%, -50%) rotate(360deg) translateX(210px) rotate(-360deg); }
        }
        
        @keyframes orbit4 {
            from { transform: translate(-50%, -50%) rotate(0deg) translateX(270px) rotate(0deg); }
            to { transform: translate(-50%, -50%) rotate(360deg) translateX(270px) rotate(-360deg); }
        }
        
        @keyframes orbit5 {
            from { transform: translate(-50%, -50%) rotate(45deg) translateX(340px) rotate(-45deg); }
            to { transform: translate(-50%, -50%) rotate(405deg) translateX(340px) rotate(-405deg); }
        }
        
        @keyframes orbit6 {
            from { transform: translate(-50%, -50%) rotate(90deg) translateX(410px) rotate(-90deg); }
            to { transform: translate(-50%, -50%) rotate(450deg) translateX(410px) rotate(-450deg); }
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-20px); }
        }
        
        .login-container {
            background: rgba(15, 32, 39, 0.25);
            backdrop-filter: blur(30px);
            -webkit-backdrop-filter: blur(30px);
            border: 2px solid rgba(255, 255, 255, 0.15);
            border-radius: 20px;
            box-shadow: 
                0 20px 60px rgba(0, 0, 0, 0.8),
                0 0 100px rgba(74, 144, 226, 0.3),
                0 0 150px rgba(118, 75, 162, 0.2),
                0 0 200px rgba(255, 215, 0, 0.1),
                inset 0 2px 4px rgba(255, 255, 255, 0.2),
                inset 0 -2px 4px rgba(0, 0, 0, 0.2);
            max-width: 400px;
            width: 100%;
            max-height: 95vh;
            overflow-y: auto;
            position: relative;
            z-index: 10;
            animation: slideUp 0.5s ease-out, formGlowRainbow 8s ease-in-out infinite;
            scrollbar-width: none; /* Firefox */
            -ms-overflow-style: none; /* IE and Edge */
        }
        
        .login-container::-webkit-scrollbar {
            display: none; /* Chrome, Safari, Opera */
        }
        
        @keyframes formGlowRainbow {
            0% { 
                box-shadow: 
                    0 20px 60px rgba(0, 0, 0, 0.8),
                    0 0 80px rgba(74, 144, 226, 0.5),
                    0 0 120px rgba(33, 150, 243, 0.3),
                    inset 0 2px 4px rgba(255, 255, 255, 0.2),
                    inset 0 -2px 4px rgba(0, 0, 0, 0.2);
            }
            25% { 
                box-shadow: 
                    0 20px 60px rgba(0, 0, 0, 0.8),
                    0 0 80px rgba(118, 75, 162, 0.5),
                    0 0 120px rgba(156, 39, 176, 0.3),
                    inset 0 2px 4px rgba(255, 255, 255, 0.2),
                    inset 0 -2px 4px rgba(0, 0, 0, 0.2);
            }
            50% { 
                box-shadow: 
                    0 20px 60px rgba(0, 0, 0, 0.8),
                    0 0 80px rgba(255, 193, 7, 0.5),
                    0 0 120px rgba(255, 215, 0, 0.3),
                    inset 0 2px 4px rgba(255, 255, 255, 0.2),
                    inset 0 -2px 4px rgba(0, 0, 0, 0.2);
            }
            75% { 
                box-shadow: 
                    0 20px 60px rgba(0, 0, 0, 0.8),
                    0 0 80px rgba(76, 175, 80, 0.5),
                    0 0 120px rgba(46, 125, 50, 0.3),
                    inset 0 2px 4px rgba(255, 255, 255, 0.2),
                    inset 0 -2px 4px rgba(0, 0, 0, 0.2);
            }
            100% { 
                box-shadow: 
                    0 20px 60px rgba(0, 0, 0, 0.8),
                    0 0 80px rgba(74, 144, 226, 0.5),
                    0 0 120px rgba(33, 150, 243, 0.3),
                    inset 0 2px 4px rgba(255, 255, 255, 0.2),
                    inset 0 -2px 4px rgba(0, 0, 0, 0.2);
            }
        }
        
        @media (max-width: 768px) {
            .login-container {
                max-width: 100%;
                margin: 0.5rem;
                border-radius: 16px;
            }
        }
        
        @media (max-width: 480px) {
            .login-container {
                margin: 0.25rem;
                border-radius: 12px;
            }
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .login-header {
            background: linear-gradient(135deg, rgba(74, 144, 226, 0.4) 0%, rgba(44, 83, 100, 0.6) 50%, rgba(118, 75, 162, 0.4) 100%);
            backdrop-filter: blur(10px);
            color: white;
            padding: 1.2rem 1.2rem 0.8rem;
            text-align: center;
            text-shadow: 0 0 10px rgba(74, 144, 226, 0.8), 0 2px 4px rgba(0, 0, 0, 0.8);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .logo-container {
            width: 45px;
            height: 45px;
            background: white;
            border-radius: 10px;
            margin: 0 auto 0.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        
        .logo-container svg {
            width: 30px;
            height: 30px;
            fill: #2c5364;
        }
        
        .login-header h1 {
            font-size: 1.2rem;
            margin-bottom: 0.15rem;
            font-weight: 600;
        }
        
        .login-header p {
            font-size: 0.8rem;
            opacity: 0.9;
            font-weight: 400;
        }
        .content {
            padding: 1.2rem 1.5rem 1.5rem;
        }
        
        @media (max-width: 768px) {
            .content {
                padding: 1rem;
            }
        }
        
        .school-selector h2 {
            font-size: 0.85rem;
            margin-bottom: 0.5rem;
            color: #ffffff;
            text-align: center;
            font-weight: 600;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.5);
        }
        
        .school-option {
            border: 2px solid transparent;
            border-radius: 8px;
            padding: 0.6rem;
            margin-bottom: 0.5rem;
            cursor: pointer;
            transition: all 0.3s ease;
            background: linear-gradient(135deg, #2c5364 0%, #203a43 100%);
            color: white;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        }
        
        .school-option:hover {
            transform: translateY(-2px);
            box-shadow: 
                0 4px 12px rgba(44, 83, 100, 0.4),
                0 0 20px rgba(74, 144, 226, 0.3);
        }
        
        .school-option:active {
            transform: translateY(0);
        }
        
        .school-option:has(input[type="radio"]:checked) {
            background: linear-gradient(135deg, #4caf50 0%, #388e3c 100%);
            border-color: #66bb6a;
            box-shadow: 0 4px 12px rgba(76, 175, 80, 0.3);
        }
        
        .school-option input[type="radio"] {
            display: none;
        }
        
        .school-option input[type="radio"]:checked + label::before {
            content: '✓ ';
            font-weight: bold;
        }
        
        .school-option:has(input[type="radio"]:checked) {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            border-color: #34d399;
            box-shadow: 0 12px 35px rgba(16, 185, 129, 0.5);
            transform: translateY(-8px) scale(1.02);
        }
        
        .school-option label {
            cursor: pointer;
            display: block;
            font-size: 0.9rem;
            color: white;
            font-weight: 600;
        }
        
        .school-option small {
            display: block;
            color: rgba(255,255,255,0.9);
            margin-top: 0.25rem;
            font-size: 0.75rem;
        }
        
        @media (max-width: 768px) {
            .school-option {
                padding: 0.6rem;
                margin-bottom: 0.6rem;
            }
            
            .school-option label {
                font-size: 0.9rem;
            }
            
            .school-option small {
                font-size: 0.7rem;
            }
        }
        
        .form-group {
            margin-bottom: 0.85rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.4rem;
            color: #ffffff;
            font-weight: 500;
            font-size: 0.9rem;
            text-shadow: 0 1px 2px rgba(0,0,0,0.5);
        }
        
        .form-group input {
            width: 100%;
            padding: 0.6rem;
            border: 2px solid rgba(255, 255, 255, 0.4);
            border-radius: 8px;
            font-size: 0.9rem;
            transition: all 0.3s ease;
            background: rgba(255, 255, 255, 0.25);
            backdrop-filter: blur(10px);
            color: #ffffff;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2), inset 0 1px 0 rgba(255, 255, 255, 0.3);
        }
        
        .form-group input::placeholder {
            color: rgba(255, 255, 255, 0.6);
        }
        
        .form-group input:focus {
            outline: none;
            background: rgba(255, 255, 255, 0.35);
            border-color: rgba(76, 175, 80, 0.8);
            box-shadow: 0 4px 16px rgba(76, 175, 80, 0.3), 0 0 0 3px rgba(76, 175, 80, 0.2), inset 0 1px 0 rgba(255, 255, 255, 0.4);
            transform: translateY(-1px);
        }
        
        .btn {
            width: 100%;
            padding: 0.65rem;
            background: linear-gradient(135deg, #2c5364 0%, #203a43 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 0.25rem;
            box-shadow: 0 4px 15px rgba(44, 83, 100, 0.3), inset 0 1px 0 rgba(255, 255, 255, 0.1);
            position: relative;
            overflow: hidden;
        }
        
        .btn::after {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s;
        }
        
        .btn:hover::after {
            left: 100%;
        }
        
        .btn:hover {
            background: linear-gradient(135deg, #4caf50 0%, #388e3c 100%);
            transform: translateY(-2px);
            box-shadow: 
                0 6px 20px rgba(76, 175, 80, 0.5), 
                0 0 30px rgba(76, 175, 80, 0.3),
                inset 0 1px 0 rgba(255, 255, 255, 0.3);
        }
        
        .btn:active {
            transform: translateY(0);
        }
        
        .btn-secondary {
            background: linear-gradient(135deg, #e2e8f0 0%, #cbd5e0 100%);
            color: #4a5568;
            margin-top: 0.6rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
        
        .btn-secondary:hover {
            background: linear-gradient(135deg, #cbd5e0 0%, #a0aec0 100%);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.15);
        }
        
        .alert {
            padding: 0.6rem;
            border-radius: 8px;
            margin-bottom: 0.75rem;
            font-size: 0.8rem;
            border-left: 3px solid;
            line-height: 1.4;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        
        .alert-error {
            background: rgba(254, 215, 215, 0.85);
            backdrop-filter: blur(10px);
            color: #991b1b;
            border-color: #fc8181;
        }
        
        .alert-success {
            background: rgba(198, 246, 213, 0.85);
            backdrop-filter: blur(10px);
            color: #065f46;
            border-color: #68d391;
        }
        
        .footer {
            text-align: center;
            padding: 0.7rem;
            background: #f7fafc;
            color: #718096;
            font-size: 0.7rem;
            border-top: 1px solid #e2e8f0;
            flex-shrink: 0;
        }
        
        .footer strong {
            color: #2d3748;
        }
        
        .dev-link {
            text-align: center;
            padding: 0.5rem;
        }
        
        .dev-link a {
            color: #2c5364;
            text-decoration: none;
            font-size: 0.8rem;
            font-weight: 500;
            transition: color 0.3s;
        }
        
        .dev-link a:hover {
            color: #203a43;
            text-decoration: underline;
        }
        
        .info-note {
            background: rgba(187, 222, 251, 0.85);
            backdrop-filter: blur(10px);
            border-left: 3px solid rgba(44, 83, 100, 0.8);
            padding: 0.6rem;
            margin-bottom: 0.75rem;
            font-size: 0.8rem;
            color: #1a1a1a;
            border-radius: 6px;
            line-height: 1.4;
            box-shadow: 0 2px 8px rgba(44, 83, 100, 0.2);
        }
        
        @media (max-width: 480px) {
            .login-container {
                border-radius: 15px;
            }
            
            .login-header {
                padding: 2rem 1.5rem;
            }
            
            .login-header h1 {
                font-size: 1.5rem;
            }
            
            .content {
                padding: 1.5rem;
            }
            
            .logo-container {
                width: 70px;
                height: 70px;
            }
        }
    </style>
</head>
<body>
    <!-- Solar System Background -->
    <div class="solar-system">
        <div class="sun"></div>
        <div class="orbit orbit1"></div>
        <div class="orbit orbit2"></div>
        <div class="orbit orbit3"></div>
        <div class="orbit orbit4"></div>
        <div class="orbit orbit5"></div>
        <div class="orbit orbit6"></div>
        <div class="planet mercury"></div>
        <div class="planet venus"></div>
        <div class="planet earth"></div>
        <div class="planet mars"></div>
        <div class="planet jupiter"></div>
        <div class="planet saturn"></div>
    </div>
    
    <!-- Shooting Stars -->
    <div class="shooting-star shooting-star1"></div>
    <div class="shooting-star shooting-star2"></div>
    <div class="shooting-star shooting-star3"></div>
    
    <div class="login-container">
        <?php
        // Get global message from developer
        $stmt = $db->query("SELECT * FROM global_messages WHERE is_active = 1 ORDER BY created_at DESC LIMIT 1");
        $globalMessage = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($globalMessage):
        ?>
        <div style="background: #fff3cd; border: 2px solid #ffc107; border-radius: 8px; padding: 15px; margin-bottom: 20px; text-align: center;">
            <div style="animation: zoomInOut 2s ease-in-out infinite;">
                <strong style="color: #856404;">📢 Important Notice:</strong>
                <p style="margin: 8px 0 0 0; color: #856404;"><?php echo nl2br(htmlspecialchars($globalMessage['message'])); ?></p>
            </div>
            <a href="https://chat.whatsapp.com/IL4a46NYHEw4IgbXEZCDEs?mode=wwt" target="_blank" style="display: inline-block; margin-top: 12px; background: #25D366; color: white; padding: 10px 20px; border-radius: 25px; text-decoration: none; font-weight: bold; transition: background 0.3s;">
                <svg style="width: 20px; height: 20px; vertical-align: middle; margin-right: 5px; fill: white;" viewBox="0 0 24 24">
                    <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/>
                </svg>
                Join WhatsApp Group
            </a>
        </div>
        <style>
            @keyframes zoomInOut {
                0%, 100% {
                    transform: scale(1);
                }
                50% {
                    transform: scale(1.05);
                }
            }
            a[href*="whatsapp"]:hover {
                background: #128C7E !important;
            }
        </style>
        <?php endif; ?>
        
        <div class="login-header">
            <div class="logo-container">
                <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path d="M12 3L1 9L5 11.18V17.18L12 21L19 17.18V11.18L21 10.09V17H23V9L12 3ZM18.82 9L12 12.72L5.18 9L12 5.28L18.82 9ZM17 15.99L12 18.72L7 15.99V12.27L12 15L17 12.27V15.99Z"/>
                </svg>
            </div>
            <h1>School Based Assessment System</h1>
            <p>Empowering Education Through Technology</p>
        </div>
        
        <?php if ($isDeveloperMode): ?>
            <!-- Developer Login -->
            <div class="content">
                <div class="info-note">
                    <strong>Developer Access</strong><br>
                    Manage all schools in the system
                </div>
                
                <form method="POST">
                    <?php if (isset($_GET['error'])): ?>
                        <div class="alert alert-error">Invalid developer credentials</div>
                    <?php endif; ?>
                    
                    <div class="form-group">
                        <label for="username">Developer Username</label>
                        <input type="text" id="username" name="username" required value="developer" readonly style="background: #f5f5f5;">
                    </div>
                    
                    <div class="form-group">
                        <label for="password">Developer Password</label>
                        <input type="password" id="password" name="password" required autocomplete="current-password" autofocus>
                    </div>
                    
                    <button type="submit" class="btn">Developer Login</button>
                </form>
                <div class="dev-link">
                    <a href="login.php">Back to User Login</a>
                </div>
            </div>
        <?php else: ?>
            <!-- User Login -->
            <div class="content">
                <form method="POST">
                    <?php 
                    if (isset($_GET['error'])): 
                        if ($_GET['error'] === 'school_locked'):
                    ?>
                        <div class="alert alert-error" style="white-space: pre-line; line-height: 1.6;">
                            <strong>🔒 School Access Locked</strong><br><br>
                            <?php 
                            $reason = isset($_GET['reason']) ? htmlspecialchars(urldecode($_GET['reason'])) : 'Your school has been temporarily locked by the developer.';
                            echo $reason;
                            ?>
                            <br><br>
                            <strong>Contact Developer:</strong><br>
                            📱 0257514418<br>
                            📱 0502160502
                        </div>
                    <?php 
                        elseif ($_GET['error'] === 'trial_expired' && isset($_GET['msg'])):
                    ?>
                        <div class="alert alert-error" style="white-space: pre-line; line-height: 1.6;">
                            <strong>⏰ Trial Period Expired</strong><br><br>
                            <?php echo htmlspecialchars(urldecode($_GET['msg'])); ?>
                        </div>
                    <?php 
                        elseif ($_GET['error'] === 'not_approved' && isset($_GET['msg'])):
                    ?>
                        <div class="alert alert-error">
                            <strong>⏳ Pending Approval</strong><br><br>
                            <?php echo htmlspecialchars(urldecode($_GET['msg'])); ?>
                        </div>
                    <?php 
                        elseif ($_GET['error'] === 'inactive' && isset($_GET['msg'])):
                    ?>
                        <div class="alert alert-error">
                            <strong>❌ Account Inactive</strong><br><br>
                            <?php echo htmlspecialchars(urldecode($_GET['msg'])); ?>
                        </div>
                    <?php 
                        else:
                    ?>
                        <div class="alert alert-error">
                            Invalid username or password
                        </div>
                    <?php 
                        endif;
                    endif; 
                    ?>
                    
                    <?php if (isset($_GET['success'])): ?>
                        <div class="alert alert-success">Logged out successfully</div>
                    <?php endif; ?>
                    
                    <div class="form-group">
                        <label for="username">Username</label>
                        <input type="text" id="username" name="username" required autocomplete="username" autofocus>
                    </div>
                    
                    <div class="form-group">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" required autocomplete="current-password">
                    </div>
                    
                    <button type="submit" class="btn">Login</button>
                </form>
                
                <div class="dev-link" style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #e2e8f0; text-align: center;">
                    <p style="color: #718096; font-size: 0.9rem; margin-bottom: 10px;">Don't have an account?</p>
                    <a href="school_register.php" style="display: inline-block; padding: 12px 24px; background: #27ae60; color: white; text-decoration: none; border-radius: 8px; font-weight: 600; margin-top: 5px; transition: all 0.3s; box-shadow: 0 4px 12px rgba(39, 174, 96, 0.3);">📝 Register Your School</a>
                </div>
                
                <div class="dev-link" style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #e2e8f0; text-align: center;">
                    <p style="color: #718096; font-size: 0.9rem; margin-bottom: 10px;">Want to learn about the system?</p>
                    <a href="system_features.php" style="display: inline-block; padding: 12px 24px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; text-decoration: none; border-radius: 8px; font-weight: 600; margin-top: 5px; transition: all 0.3s; box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);">📚 Check Full System Features</a>
                </div>
            </div>
        <?php endif; ?>
        
        <div class="footer">
            <p>Powered by <strong>TechLaw Softwares</strong></p>
            <p style="margin-top: 0.5rem; opacity: 0.7;">© <?php echo date('Y'); ?> All Rights Reserved</p>
            <p style="margin-top: 0.5rem; font-size: 0.85rem; opacity: 0.8;">
                📱 Contact Developer: 0257514418 / 0502160502
            </p>
            <?php if (!$isDeveloperMode): ?>
                <p style="margin-top: 1rem;">
                    <a href="login.php?dev=true" style="color: #667eea; text-decoration: none; font-size: 0.85rem; opacity: 0.6;">🔧 Developer Access</a>
                </p>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
