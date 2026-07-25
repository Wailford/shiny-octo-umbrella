-- ========================================
-- PRODUCTION FIX - SCORES TABLE CONSTRAINT
-- ========================================
-- For shared hosting with limited permissions
-- Run this entire script in phpMyAdmin
-- ========================================

-- Step 1: Add term column (skip if error about duplicate column)
ALTER TABLE `scores` 
ADD COLUMN `term` VARCHAR(50) NOT NULL DEFAULT 'First Term' AFTER `subject_id`;

-- Step 2: Add academic_year column (skip if error about duplicate column)
ALTER TABLE `scores`
ADD COLUMN `academic_year` VARCHAR(20) NOT NULL DEFAULT '2024/2025' AFTER `term`;

-- Step 3: Update empty values
UPDATE `scores` sc
LEFT JOIN `students` st ON sc.student_id = st.id
LEFT JOIN `classes` c ON st.class_id = c.id
LEFT JOIN `school_info` si ON c.school_id = si.id
SET sc.term = COALESCE(NULLIF(sc.term, ''), si.current_term, 'First Term'),
    sc.academic_year = COALESCE(NULLIF(sc.academic_year, ''), si.academic_year, '2024/2025')
WHERE sc.term = '' OR sc.academic_year = '';

-- Step 4: Drop foreign keys
ALTER TABLE `scores` DROP FOREIGN KEY `scores_ibfk_1`;
ALTER TABLE `scores` DROP FOREIGN KEY `scores_ibfk_2`;

-- Step 5: Drop old unique index
ALTER TABLE `scores` DROP INDEX `student_subject`;

-- Step 6: Add new unique constraint with term and academic_year
ALTER TABLE `scores` 
ADD UNIQUE KEY `student_subject_term_year` (`student_id`, `subject_id`, `term`, `academic_year`);

-- Step 7: Recreate foreign keys
ALTER TABLE `scores` 
ADD CONSTRAINT `scores_ibfk_1` 
FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE;

ALTER TABLE `scores` 
ADD CONSTRAINT `scores_ibfk_2` 
FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE;

-- Step 8: Add performance index
ALTER TABLE `scores` ADD INDEX `idx_term_year` (`term`, `academic_year`);

-- ========================================
-- VERIFICATION (Simple count only)
-- ========================================

SELECT 'Migration completed successfully!' AS status;
SELECT COUNT(*) as total_scores FROM `scores`;
SELECT 'Now try entering scores for Computing - it should work!' AS next_step;
