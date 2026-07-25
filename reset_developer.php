<?php
/**
 * Developer Password Reset Script
 * 
 * Instructions:
 * 1. Upload this file to the root directory of your SBA installation on your hosting server.
 * 2. Access this script via your web browser (e.g., https://yourdomain.com/reset_developer.php).
 * 3. The script will reset the developer password to a secure new value and immediately delete itself.
 */

// Set the new developer password you want to use
$newPassword = 'developer123'; // <-- Change this to your desired password before uploading!

// Enable error reporting to diagnose connection issues
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/config/database.php';

try {
    $db = Database::getInstance()->getConnection();
    
    // Check if the developer user exists
    $checkStmt = $db->prepare("SELECT id FROM users WHERE username = 'developer' AND user_type = 'developer'");
    $checkStmt->execute();
    $user = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        // If the developer user doesn't exist, create it
        $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
        $insertStmt = $db->prepare("INSERT INTO users (username, password, user_type, role, is_active) VALUES ('developer', ?, 'developer', 'teacher', 1)");
        $insertStmt->execute([$hashedPassword]);
        echo "<h3>Success: The developer account did not exist, so it has been created!</h3>";
    } else {
        // If the developer user exists, update the password
        $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
        $updateStmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
        $updateStmt->execute([$hashedPassword, $user['id']]);
        echo "<h3>Success: The developer password has been reset successfully!</h3>";
    }
    
    echo "<p>New Username: <strong>developer</strong></p>";
    echo "<p>New Password: <strong>" . htmlspecialchars($newPassword) . "</strong></p>";

} catch (Exception $e) {
    echo "<h3>Error: " . htmlspecialchars($e->getMessage()) . "</h3>";
} finally {
    // Self-delete the file for security
    if (file_exists(__FILE__)) {
        unlink(__FILE__);
        echo "<p style='color: green;'><strong>Security notice:</strong> This script file has successfully self-deleted from the server to prevent unauthorized execution.</p>";
    }
}
