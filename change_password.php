<?php
session_start();
require_once __DIR__ . '/config/database.php';

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

$db = Database::getInstance()->getConnection();
$userId = $_SESSION['user_id'];
$error = '';
$success = '';

// Get user info
$stmt = $db->prepare("SELECT username, full_name, must_change_password, password_reset_count, password_reset_month FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$currentMonth = date('Y-m');
$resetCount = 0;

// Check reset count for current month
if ($user['password_reset_month'] === $currentMonth) {
    $resetCount = $user['password_reset_count'];
}

$canReset = $_SESSION['user_type'] === 'admin' || $resetCount < 3;

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    // Validate inputs
    if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
        $error = 'All fields are required';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'New passwords do not match';
    } elseif (strlen($newPassword) < 6) {
        $error = 'Password must be at least 6 characters long';
    } elseif (!$canReset) {
        $error = 'You have reached the maximum of 3 password resets for this month';
    } else {
        // Verify current password
        $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $userData = $stmt->fetch();
        
        if (!password_verify($currentPassword, $userData['password'])) {
            $error = 'Current password is incorrect';
        } else {
            try {
                $db->beginTransaction();
                
                // Hash new password
                $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                
                // Update reset count
                if ($user['password_reset_month'] !== $currentMonth) {
                    // New month - reset counter
                    $newResetCount = 1;
                } else {
                    $newResetCount = $resetCount + 1;
                }
                
                // Update user password and tracking
                $stmt = $db->prepare("
                    UPDATE users 
                    SET password = ?,
                        must_change_password = 0,
                        password_changed_at = NOW(),
                        password_reset_count = ?,
                        password_reset_month = ?
                    WHERE id = ?
                ");
                $stmt->execute([$newPasswordHash, $newResetCount, $currentMonth, $userId]);
                
                // Log the reset
                $stmt = $db->prepare("
                    INSERT INTO password_reset_log 
                    (user_id, reset_by_user_id, reset_type, old_password_hash, ip_address, user_agent)
                    VALUES (?, ?, 'self', ?, ?, ?)
                ");
                $stmt->execute([
                    $userId,
                    $userId,
                    $userData['password'],
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    $_SERVER['HTTP_USER_AGENT'] ?? null
                ]);
                
                $db->commit();
                
                $success = 'Password changed successfully! You will be redirected to dashboard...';
                
                // Redirect after 2 seconds
                header("refresh:2;url=index.php");
                
            } catch (Exception $e) {
                $db->rollBack();
                $error = 'Error changing password: ' . $e->getMessage();
            }
        }
    }
}

$isForced = $user['must_change_password'] == 1;
?>
<?php
$pageTitle = 'Change Password';
require_once __DIR__ . '/components/header.php';
?>
<div class="container" style="max-width:520px;">
    <div class="card">
        <h2 style="font-size:1.4rem;font-weight:700;color:#2d3748;margin-bottom:0.25rem;">🔒 Change Password</h2>
        <p style="color:#718096;margin-bottom:1.25rem;">Welcome, <?php echo htmlspecialchars($user['full_name']); ?></p>

        <?php if ($isForced): ?>
        <div class="alert" style="background:#fffbeb;border-color:#f59e0b;color:#92400e;">
            <strong>⚠️ Password Change Required</strong><br>
            You must change your password before accessing the system.
        </div>
        <?php endif; ?>

        <?php if (!$canReset): ?>
        <div class="alert alert-error">
            <strong>❌ Reset Limit Reached</strong><br>
            You have used all 3 password resets for this month. Please contact your administrator.
        </div>
        <?php else: ?>
        <div class="alert alert-info" style="margin-bottom:1rem;">
            <strong>📊 Reset Status:</strong> <?php echo $resetCount; ?> of 3 resets used this month
            <?php if ($resetCount > 0): ?><br><small>Resets remaining: <?php echo (3 - $resetCount); ?></small><?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($error): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <?php if ($canReset && !$success): ?>
        <form method="POST" action="">
            <div class="form-group">
                <label>Current Password</label>
                <input type="password" class="form-control" name="current_password" required autofocus>
            </div>

            <div class="form-group">
                <label>New Password</label>
                <input type="password" class="form-control" name="new_password" required minlength="6">
                <div class="password-requirements" style="font-size:0.8rem;color:#718096;margin-top:0.5rem;">
                    ✓ Minimum 6 characters<br>
                    ✓ Mix of letters and numbers recommended
                </div>
            </div>

            <div class="form-group">
                <label>Confirm New Password</label>
                <input type="password" class="form-control" name="confirm_password" required minlength="6">
            </div>

            <div style="display:flex;gap:0.75rem;margin-top:1rem;">
                <button type="submit" class="btn btn-primary" style="flex:1;">
                    🔐 Change Password
                </button>
                <?php if (!$isForced): ?>
                <a href="index.php" class="btn" style="flex:1;background:#e2e8f0;color:#4a5568;text-align:center;">Cancel</a>
                <?php endif; ?>
            </div>
        </form>
        <?php endif; ?>

        <?php if (!$canReset): ?>
        <div style="margin-top:1rem;">
            <a href="index.php" class="btn" style="background:#e2e8f0;color:#4a5568;">← Back to Dashboard</a>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/components/footer.php'; ?>
