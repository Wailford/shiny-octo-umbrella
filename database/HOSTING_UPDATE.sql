-- =====================================================
-- SAFE UPDATE SCRIPT FOR HOSTED DATABASE
-- This script safely adds missing columns and tables
-- =====================================================

-- Add columns only if they don't exist (using stored procedure)
DELIMITER $$

CREATE PROCEDURE AddColumnIfNotExists(
    IN tableName VARCHAR(100),
    IN columnName VARCHAR(100),
    IN columnDefinition VARCHAR(500)
)
BEGIN
    IF NOT EXISTS (
        SELECT * FROM information_schema.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = tableName 
        AND COLUMN_NAME = columnName
    ) THEN
        SET @ddl = CONCAT('ALTER TABLE `', tableName, '` ADD COLUMN `', columnName, '` ', columnDefinition);
        PREPARE stmt FROM @ddl;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$

DELIMITER ;

-- Add school_type_id to school_info
CALL AddColumnIfNotExists('school_info', 'school_type_id', 'INT(11) NOT NULL DEFAULT 3 AFTER `school_name`');

-- Add school_code to school_info
CALL AddColumnIfNotExists('school_info', 'school_code', 'VARCHAR(7) DEFAULT NULL COMMENT \'7-digit school code for candidate index generation\'');

-- Add school_type_id to users table
CALL AddColumnIfNotExists('users', 'school_type_id', 'INT(11) NULL AFTER `user_type`');

-- Add school_id to users table
CALL AddColumnIfNotExists('users', 'school_id', 'INT(11) NULL AFTER `school_type_id`');

-- Add school_type_id to classes table
CALL AddColumnIfNotExists('classes', 'school_type_id', 'INT(11) NOT NULL DEFAULT 3 AFTER `class_code`');

-- Add school_id to classes table
CALL AddColumnIfNotExists('classes', 'school_id', 'INT(11) NULL AFTER `school_type_id`');

-- Add stream to classes table
CALL AddColumnIfNotExists('classes', 'stream', 'VARCHAR(1) DEFAULT NULL AFTER `school_id`');

-- Add school_type_id to subjects table
CALL AddColumnIfNotExists('subjects', 'school_type_id', 'INT(11) NOT NULL DEFAULT 3 AFTER `class_id`');

-- Add school_id to students table
CALL AddColumnIfNotExists('students', 'school_id', 'INT(11) NULL AFTER `class_id`');

-- Add candidate_index_number to students table
CALL AddColumnIfNotExists('students', 'candidate_index_number', 'VARCHAR(12) DEFAULT NULL UNIQUE COMMENT \'BECE candidate index: SchoolCode(7) + Sequential(3) + Year(2)\'');

-- Drop the procedure
DROP PROCEDURE IF EXISTS AddColumnIfNotExists;

-- Create school_types table if not exists
CREATE TABLE IF NOT EXISTS `school_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type_name` varchar(100) NOT NULL,
  `type_code` varchar(20) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default school types
INSERT IGNORE INTO `school_types` (`id`, `type_name`, `type_code`, `description`) VALUES
(1, 'Primary School', 'PRIMARY', 'Basic 1-6 with Class Teachers'),
(2, 'JHS', 'JHS', 'Basic 7-9 with Form Masters'),
(3, 'Basic School', 'BASIC', 'Basic 1-9 with both Class Teachers and Form Masters');

-- Create subject_teachers table if not exists
CREATE TABLE IF NOT EXISTS `subject_teachers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_assignment` (`user_id`, `subject_id`, `class_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_subject` (`subject_id`),
  KEY `idx_class` (`class_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create form_masters table if not exists
CREATE TABLE IF NOT EXISTS `form_masters` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `academic_year` varchar(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_class` (`class_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create global_messages table if not exists
CREATE TABLE IF NOT EXISTS `global_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `message` text NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add approval and trial columns to school_info
CALL AddColumnIfNotExists('school_info', 'is_approved', 'TINYINT(1) DEFAULT 1');
CALL AddColumnIfNotExists('school_info', 'is_paid', 'TINYINT(1) DEFAULT 1');
CALL AddColumnIfNotExists('school_info', 'trial_end_date', 'DATETIME DEFAULT NULL');
CALL AddColumnIfNotExists('school_info', 'is_locked', 'TINYINT(1) DEFAULT 0');
CALL AddColumnIfNotExists('school_info', 'lock_reason', 'TEXT DEFAULT NULL');

-- Add role column to users
CALL AddColumnIfNotExists('users', 'role', 'VARCHAR(50) DEFAULT NULL');

-- Add district and circuit to school_info
CALL AddColumnIfNotExists('school_info', 'district', 'VARCHAR(100) DEFAULT NULL');
CALL AddColumnIfNotExists('school_info', 'circuit', 'VARCHAR(100) DEFAULT NULL');

-- Add headmaster_signature to school_info
CALL AddColumnIfNotExists('school_info', 'headmaster_signature', 'VARCHAR(500) DEFAULT NULL');

-- Create indexes for better performance (only if they don't exist)
SET @exist := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'students' AND index_name = 'idx_candidate_index');
SET @sqlstmt := IF(@exist > 0, 'SELECT ''Index idx_candidate_index already exists''', 'CREATE INDEX `idx_candidate_index` ON `students` (`candidate_index_number`)');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'classes' AND index_name = 'idx_class_school');
SET @sqlstmt := IF(@exist > 0, 'SELECT ''Index idx_class_school already exists''', 'CREATE INDEX `idx_class_school` ON `classes` (`school_id`)');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'users' AND index_name = 'idx_user_school');
SET @sqlstmt := IF(@exist > 0, 'SELECT ''Index idx_user_school already exists''', 'CREATE INDEX `idx_user_school` ON `users` (`school_id`)');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'students' AND index_name = 'idx_student_school');
SET @sqlstmt := IF(@exist > 0, 'SELECT ''Index idx_student_school already exists''', 'CREATE INDEX `idx_student_school` ON `students` (`school_id`)');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Update existing data: Link users to their school_info.id
UPDATE `users` u
INNER JOIN `school_info` si ON u.school_type_id = si.school_type_id
SET u.school_id = si.id
WHERE u.school_id IS NULL;

-- Update existing data: Link classes to their school_info.id
UPDATE `classes` c
INNER JOIN `school_info` si ON c.school_type_id = si.school_type_id
SET c.school_id = si.id
WHERE c.school_id IS NULL;

-- Update existing data: Set school_type_id for school_info if NULL
UPDATE `school_info` SET `school_type_id` = 1 WHERE `school_type_id` IS NULL;

COMMIT;

SELECT 'Database updated successfully! All tables and columns are now in place.' AS Status;
