-- Password Reset System Migration
-- Adds tracking for password changes and first-login requirements

-- Add columns to users table for password management
ALTER TABLE users
ADD COLUMN must_change_password TINYINT(1) DEFAULT 1 COMMENT 'Force password change on first login',
ADD COLUMN password_changed_at TIMESTAMP NULL COMMENT 'Last password change timestamp',
ADD COLUMN password_reset_count INT DEFAULT 0 COMMENT 'Number of password resets this month',
ADD COLUMN password_reset_month VARCHAR(7) NULL COMMENT 'YYYY-MM format to track reset month';

-- Set existing users to not require password change (backward compatibility)
UPDATE users SET must_change_password = 0 WHERE must_change_password IS NULL;

-- Create password reset log table
CREATE TABLE IF NOT EXISTS password_reset_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    reset_by_user_id INT NULL COMMENT 'NULL if self-reset, user_id if admin reset',
    reset_type ENUM('self', 'admin_forced') NOT NULL,
    old_password_hash VARCHAR(255) NULL COMMENT 'Hash of old password for audit',
    reset_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (reset_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user_resets (user_id, reset_at),
    INDEX idx_reset_month (reset_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add index for performance
CREATE INDEX idx_password_reset_month ON users(password_reset_month, password_reset_count);
