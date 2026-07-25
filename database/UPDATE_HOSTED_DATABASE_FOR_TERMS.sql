-- ========================================
-- UPDATE HOSTED DATABASE FOR TERM MANAGEMENT
-- Run this script on your hosted database
-- Safe to run multiple times - will skip if already exists
-- ========================================
-- 
-- IMPORTANT: Make sure you have selected your database in phpMyAdmin
-- before running this script. Do NOT change the database name below.
-- ========================================

-- Add term and academic_year columns to scores table if they don't exist
-- This enables term-based score isolation

-- Check and add 'term' column
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
               WHERE TABLE_SCHEMA = DATABASE() 
               AND TABLE_NAME = 'scores' 
               AND COLUMN_NAME = 'term');

SET @sqlstmt := IF(@exist = 0, 
    'ALTER TABLE `scores` ADD COLUMN `term` VARCHAR(50) DEFAULT ''First Term'' AFTER `subject_id`',
    'SELECT ''Column term already exists'' AS message');

PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check and add 'academic_year' column
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
               WHERE TABLE_SCHEMA = DATABASE() 
               AND TABLE_NAME = 'scores' 
               AND COLUMN_NAME = 'academic_year');

SET @sqlstmt := IF(@exist = 0, 
    'ALTER TABLE `scores` ADD COLUMN `academic_year` VARCHAR(20) DEFAULT ''2024/2025'' AFTER `term`',
    'SELECT ''Column academic_year already exists'' AS message');

PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Update existing scores to have current term/year from school_info
UPDATE scores sc
JOIN students st ON sc.student_id = st.id
JOIN classes c ON st.class_id = c.id
JOIN school_info si ON c.school_id = si.id
SET sc.term = COALESCE(si.current_term, 'First Term'),
    sc.academic_year = COALESCE(si.academic_year, '2024/2025')
WHERE sc.term IS NULL OR sc.academic_year IS NULL;

-- Add index for term and academic_year for faster queries
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
               WHERE TABLE_SCHEMA = DATABASE() 
               AND TABLE_NAME = 'scores' 
               AND INDEX_NAME = 'idx_term_year');

SET @sqlstmt := IF(@exist = 0, 
    'ALTER TABLE `scores` ADD KEY `idx_term_year` (`term`, `academic_year`)',
    'SELECT ''Index idx_term_year already exists'' AS message');

PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Create system_settings table if it doesn't exist
CREATE TABLE IF NOT EXISTS `system_settings` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `setting_key` VARCHAR(100) NOT NULL,
  `setting_value` TEXT,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default settings
INSERT INTO `system_settings` (`setting_key`, `setting_value`) VALUES
('current_academic_year', '2024/2025'),
('promotion_allowed', 'false'),
('last_term_preparation', NULL)
ON DUPLICATE KEY UPDATE setting_key = setting_key;

-- Verify the changes
SELECT 'Database updated successfully! Term management enabled.' AS status;
SELECT CONCAT('Scores table now has ', COUNT(*), ' records') AS total_scores FROM scores;
SELECT CONCAT('Term column exists: ', IF(COUNT(*) > 0, 'YES', 'NO')) AS term_column_check
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME = 'scores' 
AND COLUMN_NAME = 'term';

COMMIT;
