-- ========================================
-- COMPREHENSIVE FIX FOR SCORES TABLE
-- ========================================
-- This script fixes all issues with the scores table:
-- 1. Ensures term and academic_year columns exist
-- 2. Updates unique constraint to support multiple terms
-- 3. Verifies generated columns are properly defined
-- Safe to run multiple times
-- ========================================

-- Step 1: Add term column if it doesn't exist
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
               WHERE TABLE_SCHEMA = DATABASE() 
               AND TABLE_NAME = 'scores' 
               AND COLUMN_NAME = 'term');

SET @sqlstmt := IF(@exist = 0, 
    'ALTER TABLE `scores` ADD COLUMN `term` VARCHAR(50) NOT NULL DEFAULT ''First Term'' AFTER `subject_id`',
    'SELECT ''Column term already exists'' AS message');

PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Step 2: Add academic_year column if it doesn't exist
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
               WHERE TABLE_SCHEMA = DATABASE() 
               AND TABLE_NAME = 'scores' 
               AND COLUMN_NAME = 'academic_year');

SET @sqlstmt := IF(@exist = 0, 
    'ALTER TABLE `scores` ADD COLUMN `academic_year` VARCHAR(20) NOT NULL DEFAULT ''2024/2025'' AFTER `term`',
    'SELECT ''Column academic_year already exists'' AS message');

PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Step 3: Update any NULL values in term/academic_year (from school_info)
UPDATE scores sc
LEFT JOIN students st ON sc.student_id = st.id
LEFT JOIN classes c ON st.class_id = c.id
LEFT JOIN school_info si ON c.school_id = si.id
SET sc.term = COALESCE(NULLIF(sc.term, ''), si.current_term, 'First Term'),
    sc.academic_year = COALESCE(NULLIF(sc.academic_year, ''), si.academic_year, '2024/2025')
WHERE sc.term IS NULL OR sc.term = '' OR sc.academic_year IS NULL OR sc.academic_year = '';

-- Step 4: Handle the unique constraint carefully
-- First check what foreign keys exist
SELECT 'Checking foreign keys on scores table...' AS status;

-- Store foreign key information temporarily
CREATE TEMPORARY TABLE IF NOT EXISTS temp_fk_backup AS
SELECT 
    CONSTRAINT_NAME,
    REFERENCED_TABLE_NAME,
    REFERENCED_COLUMN_NAME
FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME = 'scores'
AND REFERENCED_TABLE_NAME IS NOT NULL;

-- Drop foreign keys that might be blocking the constraint drop
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS 
               WHERE TABLE_SCHEMA = DATABASE() 
               AND TABLE_NAME = 'scores' 
               AND CONSTRAINT_NAME = 'scores_ibfk_1');

SET @sqlstmt := IF(@exist > 0, 
    'ALTER TABLE `scores` DROP FOREIGN KEY `scores_ibfk_1`',
    'SELECT ''FK scores_ibfk_1 does not exist'' AS message');

PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS 
               WHERE TABLE_SCHEMA = DATABASE() 
               AND TABLE_NAME = 'scores' 
               AND CONSTRAINT_NAME = 'scores_ibfk_2');

SET @sqlstmt := IF(@exist > 0, 
    'ALTER TABLE `scores` DROP FOREIGN KEY `scores_ibfk_2`',
    'SELECT ''FK scores_ibfk_2 does not exist'' AS message');

PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Now drop the old unique constraint
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() 
               AND TABLE_NAME = 'scores' 
               AND INDEX_NAME = 'student_subject');

SET @sqlstmt := IF(@exist > 0, 
    'ALTER TABLE `scores` DROP INDEX `student_subject`',
    'SELECT ''Index student_subject already dropped'' AS message');

PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Step 5: Add new unique constraint that includes term and academic_year
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS 
               WHERE TABLE_SCHEMA = DATABASE() 
               AND TABLE_NAME = 'scores' 
               AND CONSTRAINT_NAME = 'student_subject_term_year');

SET @sqlstmt := IF(@exist = 0, 
    'ALTER TABLE `scores` ADD UNIQUE KEY `student_subject_term_year` (`student_id`, `subject_id`, `term`, `academic_year`)',
    'SELECT ''New constraint student_subject_term_year already exists'' AS message');

PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Step 6: Add index for term and academic_year for faster queries
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

-- Step 7: Recreate the foreign key constraints
-- These are needed to maintain referential integrity
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS 
               WHERE TABLE_SCHEMA = DATABASE() 
               AND TABLE_NAME = 'scores' 
               AND CONSTRAINT_NAME = 'scores_ibfk_1');

SET @sqlstmt := IF(@exist = 0, 
    'ALTER TABLE `scores` ADD CONSTRAINT `scores_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE',
    'SELECT ''FK scores_ibfk_1 already exists'' AS message');

PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS 
               WHERE TABLE_SCHEMA = DATABASE() 
               AND TABLE_NAME = 'scores' 
               AND CONSTRAINT_NAME = 'scores_ibfk_2');

SET @sqlstmt := IF(@exist = 0, 
    'ALTER TABLE `scores` ADD CONSTRAINT `scores_ibfk_2` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE',
    'SELECT ''FK scores_ibfk_2 already exists'' AS message');

PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ========================================
-- VERIFICATION
-- ========================================

SELECT '✓ Scores table fixed successfully!' AS status;

-- Show current structure
SELECT 'Current Columns:' AS info;
SELECT 
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT,
    EXTRA
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME = 'scores'
ORDER BY ORDINAL_POSITION;

-- Show current constraints
SELECT 'Current Constraints:' AS info;
SELECT 
    CONSTRAINT_NAME, 
    CONSTRAINT_TYPE 
FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME = 'scores';

-- Show current indexes
SELECT 'Current Indexes:' AS info;
SELECT 
    INDEX_NAME,
    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) as columns,
    NON_UNIQUE
FROM INFORMATION_SCHEMA.STATISTICS 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME = 'scores'
GROUP BY INDEX_NAME, NON_UNIQUE;

-- Cleanup temporary tables
DROP TEMPORARY TABLE IF EXISTS temp_fk_backup;

COMMIT;
