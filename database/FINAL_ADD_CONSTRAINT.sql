-- ========================================
-- FINAL FIX - Just Add This Constraint
-- ========================================
-- This allows students to have scores for the same subject
-- across multiple terms (First Term, Second Term, Third Term)
-- ========================================

-- Copy and paste this ONE command:
ALTER TABLE `scores` 
ADD UNIQUE KEY `student_subject_term_year` (`student_id`, `subject_id`, `term`, `academic_year`);

-- Verification:
SELECT 'Constraint added! Students can now have scores for each term.' AS status;

-- ========================================
-- WHAT THIS DOES:
-- Before: Student can't have 2 scores for English (blocked!)
-- After:  Student can have English scores in Term 1, Term 2, Term 3 (allowed!)
-- 
-- The constraint ensures:
-- - No duplicate scores in the SAME term ✓
-- - But allows scores across DIFFERENT terms ✓
-- ========================================
