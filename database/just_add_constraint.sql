-- ========================================
-- SIMPLE ADD - Just Add the New Constraint
-- ========================================
-- Since student_subject doesn't exist, try just adding the new one
-- ========================================

-- Add the correct unique constraint
ALTER TABLE `scores` 
ADD UNIQUE KEY `student_subject_term_year` (`student_id`, `subject_id`, `term`, `academic_year`);

-- Verify it was added
SHOW INDEX FROM `scores` WHERE Key_name = 'student_subject_term_year';

SELECT 'Constraint added successfully!' AS status, COUNT(*) as total_scores FROM `scores`;

-- ========================================
-- If this gives error "Duplicate key name"
-- Then the constraint already exists and your issue is elsewhere
-- ========================================
