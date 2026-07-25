-- Add school lock functionality
-- This allows developers to lock schools and prevent all users from that school from logging in

ALTER TABLE school_info 
ADD COLUMN is_locked TINYINT(1) DEFAULT 0 COMMENT 'Whether school is locked by developer',
ADD COLUMN lock_reason TEXT NULL COMMENT 'Reason for locking the school',
ADD COLUMN locked_at DATETIME NULL COMMENT 'When the school was locked',
ADD COLUMN locked_by INT NULL COMMENT 'Developer user ID who locked the school';
