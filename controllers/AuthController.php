<?php
/**
 * Authentication Controller
 * 
 * Handles user login, logout, and session management
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

class Auth {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * Authenticate user (admin or class teacher)
     * 
     * @param string $username Username
     * @param string $password Password
     * @param string $userType Type: 'admin' or 'class_teacher'
     * @return array Response with success status and user data
     */
    public function login($username, $password, $userType = 'admin') {
        try {
            $sql = "SELECT u.*, st.type_code as school_type_code, si.school_name, 
                    si.is_locked, si.lock_reason
                    FROM users u
                    LEFT JOIN school_types st ON u.school_type_id = st.id
                    LEFT JOIN school_info si ON u.school_id = si.id
                    WHERE u.username = ? AND u.is_active = 1";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user && password_verify($password, $user['password'])) {
                // Rotate session ID to prevent session fixation attacks
                session_regenerate_id(true);

                // Check if school is locked
                if ($user['is_locked'] == 1) {
                    return [
                        'success' => false,
                        'error' => 'school_locked',
                        'lock_reason' => $user['lock_reason'] ?? 'Your school has been temporarily locked. Please contact support.'
                    ];
                }
                // Set session variables
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['user_type'] = $user['user_type'];
                $_SESSION['role'] = $user['role'] ?? $user['user_type']; // Use role if exists, fallback to user_type
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['school_id'] = $user['school_id']; // CRITICAL: Track specific school ID for isolation
                $_SESSION['school_type_id'] = $user['school_type_id']; // Track school type (for backward compatibility)
                $_SESSION['school_type_code'] = $user['school_type_code']; // Track school type code
                $_SESSION['school_name'] = $user['school_name']; // Track school name
                $_SESSION['logged_in'] = true;
                $_SESSION['login_time'] = time();
                
                return [
                    'success' => true,
                    'message' => 'Login successful',
                    'user' => [
                        'id' => $user['id'],
                        'username' => $user['username'],
                        'user_type' => $user['user_type'],
                        'full_name' => $user['full_name'],
                        'school_id' => $user['school_id'],
                        'school_type_id' => $user['school_type_id'],
                        'school_type_code' => $user['school_type_code'],
                        'school_name' => $user['school_name']
                    ]
                ];
            }
            
            return [
                'success' => false,
                'error' => 'Invalid credentials'
            ];
        } catch (Exception $e) {
            error_log("Login error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Login failed. Please try again.'
            ];
        }
    }
    
    /**
     * Authenticate subject access with password
     * 
     * @param int $classId Class ID
     * @param int $subjectId Subject ID
     * @param string $password Subject password
     * @return array Response with success status
     */
    public function authenticateSubject($classId, $subjectId, $password) {
        try {
            $sql = "SELECT password FROM subjects WHERE id = ? AND class_id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$subjectId, $classId]);
            $subject = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($subject && password_verify($password, $subject['password'])) {
                // Store subject access in session
                if (!isset($_SESSION['subject_access'])) {
                    $_SESSION['subject_access'] = [];
                }
                $_SESSION['subject_access'][$subjectId] = time();
                
                return [
                    'success' => true,
                    'message' => 'Subject authentication successful'
                ];
            }
            
            return [
                'success' => false,
                'error' => 'Invalid subject password'
            ];
        } catch (Exception $e) {
            error_log("Subject authentication error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Authentication failed'
            ];
        }
    }
    
    /**
     * Check if user is logged in
     * 
     * @return bool
     */
    public function isLoggedIn() {
        if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
            return false;
        }
        
        // Check session timeout
        if (isset($_SESSION['login_time'])) {
            if (time() - $_SESSION['login_time'] > SESSION_LIFETIME) {
                $this->logout();
                return false;
            }
            // Refresh session time
            $_SESSION['login_time'] = time();
        }
        
        return true;
    }
    
    /**
     * Check if user is admin
     * 
     * @return bool
     */
    public function isAdmin() {
        return $this->isLoggedIn() && isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin';
    }
    
    /**
     * Check if user is super admin (system-wide developer or global admin)
     * 
     * @return bool
     */
    public function isSuperAdmin() {
        return $this->isLoggedIn() && 
               (($_SESSION['user_type'] ?? '') === 'developer' || empty($_SESSION['school_id']));
    }
    
    /**
     * Check if user has access to a subject
     * 
     * @param int $subjectId Subject ID
     * @return bool
     */
    public function hasSubjectAccess($subjectId) {
        if ($this->isAdmin()) {
            return true; // Admin has access to all subjects
        }
        
        if (isset($_SESSION['subject_access'][$subjectId])) {
            // Check if access hasn't expired (1 hour)
            if (time() - $_SESSION['subject_access'][$subjectId] < SESSION_LIFETIME) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Logout user
     */
    public function logout() {
        // Unset all session variables
        $_SESSION = [];
        
        // Destroy the session cookie
        if (isset($_COOKIE[session_name()])) {
            setcookie(session_name(), '', time() - 3600, '/');
        }
        
        // Destroy the session
        session_destroy();
    }
    
    /**
     * Change password for user
     * 
     * @param int $userId User ID
     * @param string $newPassword New password
     * @return array Response with success status
     */
    public function changePassword($userId, $newPassword) {
        try {
            if (strlen($newPassword) < PASSWORD_MIN_LENGTH) {
                return [
                    'success' => false,
                    'error' => 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters'
                ];
            }
            
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            
            $sql = "UPDATE users SET password = ? WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$hashedPassword, $userId]);
            
            return [
                'success' => true,
                'message' => 'Password changed successfully'
            ];
        } catch (Exception $e) {
            error_log("Change password error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Failed to change password'
            ];
        }
    }
    
    /**
     * Change subject password
     * 
     * @param int $subjectId Subject ID
     * @param string $newPassword New password
     * @return array Response with success status
     */
    public function changeSubjectPassword($subjectId, $newPassword) {
        try {
            if (strlen($newPassword) < PASSWORD_MIN_LENGTH) {
                return [
                    'success' => false,
                    'error' => 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters'
                ];
            }
            
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            
            $sql = "UPDATE subjects SET password = ? WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$hashedPassword, $subjectId]);
            
            return [
                'success' => true,
                'message' => 'Subject password changed successfully'
            ];
        } catch (Exception $e) {
            error_log("Change subject password error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Failed to change subject password'
            ];
        }
    }
    
    /**
     * Get current user info
     * 
     * @return array|null
     */
    public function getCurrentUser() {
        if (!$this->isLoggedIn()) {
            return null;
        }
        
        return [
            'id' => $_SESSION['user_id'] ?? null,
            'username' => $_SESSION['username'] ?? null,
            'user_type' => $_SESSION['user_type'] ?? null,
            'full_name' => $_SESSION['full_name'] ?? null
        ];
    }
    
    /**
     * Require login - redirect to login page if not authenticated
     * Also validates session integrity with database
     */
    public function requireLogin() {
        if (!$this->isLoggedIn()) {
            header('Location: ' . APP_URL . '/login.php');
            exit;
        }
        
        // CRITICAL: Validate session matches database on every request
        $this->validateSessionIntegrity();
    }
    
    /**
     * Validate that session data matches current database values
     * Prevents session hijacking and ensures correct school access
     */
    private function validateSessionIntegrity() {
        if (!isset($_SESSION['user_id']) || !isset($_SESSION['school_id'])) {
            return; // Can't validate without these
        }
        
        try {
            // Get current user data and school lock status from database
            $stmt = $this->db->prepare("
                SELECT u.school_id, u.user_type, u.is_active, 
                       si.is_locked, si.lock_reason
                FROM users u
                LEFT JOIN school_info si ON u.school_id = si.id
                WHERE u.id = ?
            ");
            $stmt->execute([$_SESSION['user_id']]);
            $dbUser = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$dbUser) {
                // User no longer exists in database
                $this->logout();
                header('Location: ' . APP_URL . '/login.php?error=account_deleted');
                exit;
            }
            
            // Check if user is still active
            if ($dbUser['is_active'] != 1) {
                $this->logout();
                header('Location: ' . APP_URL . '/login.php?error=account_inactive');
                exit;
            }
            
            // Check if school is locked
            if ($dbUser['is_locked'] == 1) {
                $this->logout();
                $lockReason = urlencode($dbUser['lock_reason'] ?? 'Your school has been temporarily locked.');
                header('Location: ' . APP_URL . '/login.php?error=school_locked&reason=' . $lockReason);
                exit;
            }
            
            // CRITICAL CHECK: Verify school_id matches database
            if ($dbUser['school_id'] != $_SESSION['school_id']) {
                // School mismatch - force re-login with correct school
                error_log("Session integrity failure: User {$_SESSION['user_id']} session school_id {$_SESSION['school_id']} doesn't match database school_id {$dbUser['school_id']}");
                
                // Update session with correct school_id
                $_SESSION['school_id'] = $dbUser['school_id'];
                
                // Get updated school info
                $stmt = $this->db->prepare("SELECT si.*, st.type_code FROM school_info si LEFT JOIN school_types st ON si.school_type_id = st.id WHERE si.id = ?");
                $stmt->execute([$dbUser['school_id']]);
                $schoolInfo = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($schoolInfo) {
                    $_SESSION['school_type_id'] = $schoolInfo['school_type_id'];
                    $_SESSION['school_type_code'] = $schoolInfo['type_code'];
                    $_SESSION['school_name'] = $schoolInfo['school_name'];
                }
                
                // Force page refresh to load correct school data
                header('Location: ' . $_SERVER['PHP_SELF'] . '?' . http_build_query($_GET));
                exit;
            }
            
        } catch (Exception $e) {
            error_log("Session validation error: " . $e->getMessage());
            // Don't logout on database errors, but log it
        }
    }
    
    /**
     * Require admin - redirect if not admin
     */
    public function requireAdmin() {
        $this->requireLogin();
        if (!$this->isAdmin()) {
            header('Location: ' . APP_URL . '/index.php');
            exit;
        }
    }
}
