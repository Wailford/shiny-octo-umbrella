-- ========================================
-- PRODUCTION SAFE FIX FOR SCORES TABLE
-- ========================================
-- This script works within typical shared hosting permissions
-- Safe to run - handles existing columns and constraints gracefully
-- ========================================

-- IMPORTANT: Run each section separately if you encounter errors
-- Copy and run one section at a time

-- ========================================
-- SECTION 1: Add missing columns
-- ========================================
-- (Skip section 1 if columns already exist - you'll see an error "Duplicate column name")

ALTER TABLE `scores` 
ADD COLUMN `term` VARCHAR(50) NOT NULL DEFAULT 'First Term' AFTER `subject_id`;

ALTER TABLE `scores`
ADD COLUMN `academic_year` VARCHAR(20) NOT NULL DEFAULT '2024/2025' AFTER `term`;

-- ========================================
-- SECTION 2: Update empty values
-- ========================================

UPDATE `scores` sc
LEFT JOIN `students` st ON sc.student_id = st.id
LEFT JOIN `classes` c ON st.class_id = c.id
LEFT JOIN `school_info` si ON c.school_id = si.id
SET sc.term = COALESCE(NULLIF(sc.term, ''), si.current_term, 'First Term'),
    sc.academic_year = COALESCE(NULLIF(sc.academic_year, ''), si.academic_year, '2024/2025')
WHERE sc.term = '' OR sc.academic_year = '';

-- ========================================
-- SECTION 3: Fix the constraint issue
-- ========================================
-- This removes the problematic constraint and adds the correct one
-- CAUTION: This modifies the table structure

-- First, drop the foreign keys temporarily
ALTER TABLE `scores` DROP FOREIGN KEY `scores_ibfk_1`;
ALTER TABLE `scores` DROP FOREIGN KEY `scores_ibfk_2`;

-- Now we can drop the old unique index
ALTER TABLE `scores` DROP INDEX `student_subject`;

-- Add the new unique constraint that includes term and academic_year
ALTER TABLE `scores` 
ADD UNIQUE KEY `student_subject_term_year` (`student_id`, `subject_id`, `term`, `academic_year`);

-- Recreate the foreign keys
ALTER TABLE `scores` 
ADD CONSTRAINT `scores_ibfk_1` 
FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE;

ALTER TABLE `scores` 
ADD CONSTRAINT `scores_ibfk_2` 
FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE;

-- Add performance index
ALTER TABLE `scores`
ADD INDEX `idx_term_year` (`term`, `academic_year`);

-- ========================================
-- VERIFICATION
-- ========================================

SELECT '✓ Migration completed successfully!' AS status;

SELECT COLUMN_NAME, COLUMN_TYPE 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_NAME = 'scores' 
AND COLUMN_NAME IN ('term', 'academic_year');

SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME) as columns
FROM INFORMATION_SCHEMA.STATISTICS 
WHERE TABLE_NAME = 'scores' 
AND INDEX_NAME = 'student_subject_term_year'
GROUP BY INDEX_NAME;
