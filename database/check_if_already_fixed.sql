-- ========================================
-- CHECK IF FIX IS ALREADY APPLIED
-- ========================================

-- Check what unique constraints currently exist on scores table
SHOW INDEX FROM `scores` WHERE Non_unique = 0;

-- This will show if student_subject_term_year already exists
