-- Audit log for destructive data operations (score deletions, etc.)
CREATE TABLE IF NOT EXISTS `audit_log` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `school_id`   INT NOT NULL,
  `user_id`     INT NOT NULL,
  `action`      VARCHAR(100) NOT NULL,
  `target_type` VARCHAR(50)  NOT NULL,
  `target_id`   INT          NULL,
  `details`     TEXT         NULL,
  `ip_address`  VARCHAR(45)  NULL,
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_school_action`  (`school_id`, `action`),
  INDEX `idx_created_at`     (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
