-- ========================================
-- STEP 0: CHECK CURRENT STATE
-- ========================================
-- Run this FIRST to see what constraints exist
-- ========================================

-- Check what foreign keys exist on scores table
SELECT 
    CONSTRAINT_NAME,
    COLUMN_NAME,
    REFERENCED_TABLE_NAME
FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
WHERE TABLE_NAME = 'scores' 
AND CONSTRAINT_SCHEMA = DATABASE()
AND REFERENCED_TABLE_NAME IS NOT NULL;

-- Check what indexes exist on scores table
SELECT 
    INDEX_NAME,
    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) as columns
FROM INFORMATION_SCHEMA.STATISTICS 
WHERE TABLE_NAME = 'scores' 
AND TABLE_SCHEMA = DATABASE()
GROUP BY INDEX_NAME;
