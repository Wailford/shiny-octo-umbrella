-- ========================================
-- FIX SCORES UNIQUE CONSTRAINT FOR TERM SUPPORT
-- ========================================
-- This script updates the unique constraint on the scores table
-- to support multiple terms for the same student-subject combination
-- ========================================

-- Drop the old unique constraint that only considers student_id and subject_id
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS 
               WHERE TABLE_SCHEMA = DATABASE() 
               AND TABLE_NAME = 'scores' 
               AND CONSTRAINT_NAME = 'student_subject');

SET @sqlstmt := IF(@exist > 0, 
    'ALTER TABLE `scores` DROP INDEX `student_subject`',
    'SELECT ''Constraint student_subject does not exist'' AS message');

PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add new unique constraint that includes term and academic_year
-- This allows the same student to have scores for the same subject across different terms
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS 
               WHERE TABLE_SCHEMA = DATABASE() 
               AND TABLE_NAME = 'scores' 
               AND CONSTRAINT_NAME = 'student_subject_term_year');

SET @sqlstmt := IF(@exist = 0, 
    'ALTER TABLE `scores` ADD UNIQUE KEY `student_subject_term_year` (`student_id`, `subject_id`, `term`, `academic_year`)',
    'SELECT ''Constraint student_subject_term_year already exists'' AS message');

PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Verification
SELECT 'Constraint updated successfully!' AS status;

-- Show current constraints on scores table
SELECT 
    CONSTRAINT_NAME, 
    CONSTRAINT_TYPE 
FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME = 'scores';

-- Show current indexes on scores table
SELECT 
    INDEX_NAME,
    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) as columns
FROM INFORMATION_SCHEMA.STATISTICS 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME = 'scores'
GROUP BY INDEX_NAME;

COMMIT;
