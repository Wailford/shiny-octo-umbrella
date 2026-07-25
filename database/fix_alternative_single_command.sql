-- ========================================
-- ALTERNATIVE FIX - NO FOREIGN KEY DROPS
-- ========================================
-- If foreign keys can't be dropped, try this approach
-- This works if MySQL allows dropping the index directly
-- ========================================

-- TRY THIS SINGLE COMMAND:
-- This attempts to drop the old index and add the new one in one statement
ALTER TABLE `scores` 
DROP INDEX `student_subject`,
ADD UNIQUE KEY `student_subject_term_year` (`student_id`, `subject_id`, `term`, `academic_year`);

-- If that works, you're done! Verify with:
SELECT 'Fix completed!' AS status, COUNT(*) as total_scores FROM `scores`;

-- ========================================
-- If you get error "Cannot drop index needed in foreign key"
-- Then the foreign keys exist but have DIFFERENT NAMES
-- Go back and run: check_constraints_first.sql
-- To find the actual constraint names
-- ========================================
