-- ========================================
-- PRODUCTION FIX - SAFE VERSION
-- ========================================
-- This handles cases where foreign keys might not exist
-- or have different names
-- ========================================

-- OPTION 1: Try dropping the index directly (easiest)
-- ▶ Copy and run this FIRST:
ALTER TABLE `scores` DROP INDEX `student_subject`;

-- If that worked, add the new constraint:
-- ▶ Copy and run this:
ALTER TABLE `scores` ADD UNIQUE KEY `student_subject_term_year` (`student_id`, `subject_id`, `term`, `academic_year`);

-- Verify:
SELECT 'Fix completed successfully!' AS status, COUNT(*) as total_scores FROM `scores`;

-- ========================================
-- IF OPTION 1 GIVES ERROR: 
-- "Cannot drop index 'student_subject': needed in a foreign key constraint"
-- 
-- Then use OPTION 2 below:
-- ========================================

-- OPTION 2: Find and drop foreign keys first
-- ▶ First, find what foreign key constraints exist:
SHOW CREATE TABLE `scores`;

-- ▶ Look for lines like: CONSTRAINT `actual_name_here` FOREIGN KEY
-- ▶ Then drop them using the actual names you found:

-- Example (replace with actual names):
-- ALTER TABLE `scores` DROP FOREIGN KEY `actual_fk_name_1`;
-- ALTER TABLE `scores` DROP FOREIGN KEY `actual_fk_name_2`;

-- ▶ Then drop the index:
-- ALTER TABLE `scores` DROP INDEX `student_subject`;

-- ▶ Add new constraint:
-- ALTER TABLE `scores` ADD UNIQUE KEY `student_subject_term_year` (`student_id`, `subject_id`, `term`, `academic_year`);

-- ▶ Recreate FKs (use the same names you found):
-- ALTER TABLE `scores` ADD CONSTRAINT `actual_fk_name_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE;
-- ALTER TABLE `scores` ADD CONSTRAINT `actual_fk_name_2` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE;
