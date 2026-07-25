-- ========================================
-- DIAGNOSTIC SCRIPT - Run This First
-- ========================================
-- This script checks the current state of your scores table
-- Run this in phpMyAdmin to see what needs to be fixed
-- ========================================

-- Check if term and academic_year columns exist
SELECT 'Checking columns...' AS step;
SELECT 
    COLUMN_NAME,
    COLUMN_TYPE,
    COLUMN_DEFAULT
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE()
AND TABLE_NAME = 'scores' 
AND COLUMN_NAME IN ('term', 'academic_year', 'student_id', 'subject_id');

-- Check current indexes and constraints
SELECT 'Checking indexes...' AS step;
SELECT 
    INDEX_NAME,
    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) as columns,
    NON_UNIQUE
FROM INFORMATION_SCHEMA.STATISTICS 
WHERE TABLE_SCHEMA = DATABASE()
AND TABLE_NAME = 'scores'
GROUP BY INDEX_NAME, NON_UNIQUE;

-- Check foreign keys
SELECT 'Checking foreign keys...' AS step;
SELECT 
    CONSTRAINT_NAME,
    COLUMN_NAME,
    REFERENCED_TABLE_NAME,
    REFERENCED_COLUMN_NAME
FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = DATABASE()
AND TABLE_NAME = 'scores'
AND REFERENCED_TABLE_NAME IS NOT NULL;

-- Check for records that might cause issues (if columns exist)
SELECT 'Checking for potential duplicates...' AS step;
SELECT 
    COUNT(*) as total_scores,
    (SELECT COUNT(DISTINCT student_id, subject_id) FROM scores) as unique_combinations
FROM scores;

-- If term column exists, show term distribution
SET @term_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                    WHERE TABLE_SCHEMA = DATABASE() 
                    AND TABLE_NAME = 'scores' 
                    AND COLUMN_NAME = 'term');

SELECT 'Results Summary' AS step;
SELECT 
    CASE WHEN @term_exists > 0 
    THEN 'term column EXISTS' 
    ELSE 'term column MISSING' 
    END as term_status;
