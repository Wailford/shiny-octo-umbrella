-- ========================================
-- SIMPLIFIED FIX FOR YOUR PRODUCTION DATABASE
-- ========================================
-- Your database already has term and academic_year columns
-- We only need to fix the constraint
-- ========================================

-- Copy and paste ONE command at a time into phpMyAdmin

-- STEP 1: Drop first foreign key
-- ▶ Copy and run this:
ALTER TABLE `scores` DROP FOREIGN KEY `scores_ibfk_1`;

-- STEP 2: Drop second foreign key
-- ▶ Copy and run this:
ALTER TABLE `scores` DROP FOREIGN KEY `scores_ibfk_2`;

-- STEP 3: Drop the problematic unique index (THE MAIN FIX!)
-- ▶ Copy and run this:
ALTER TABLE `scores` DROP INDEX `student_subject`;

-- STEP 4: Add new unique constraint that includes term and academic_year
-- ▶ Copy and run this:
ALTER TABLE `scores` ADD UNIQUE KEY `student_subject_term_year` (`student_id`, `subject_id`, `term`, `academic_year`);

-- STEP 5: Recreate first foreign key
-- ▶ Copy and run this:
ALTER TABLE `scores` ADD CONSTRAINT `scores_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE;

-- STEP 6: Recreate second foreign key
-- ▶ Copy and run this:
ALTER TABLE `scores` ADD CONSTRAINT `scores_ibfk_2` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE;

-- DONE! Verify completion:
SELECT 'Fix completed successfully!' AS status, COUNT(*) as total_scores FROM `scores`;

-- ========================================
-- That's it! Now try entering scores for Computing
-- The "62 failed" error should be gone!
-- ========================================
