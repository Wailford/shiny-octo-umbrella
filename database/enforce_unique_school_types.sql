-- Ensure each school_type_id can only be used by ONE school
-- This prevents data isolation issues

-- First, check for duplicates
SELECT school_type_id, COUNT(*) as count, GROUP_CONCAT(school_name) as schools
FROM school_info
GROUP BY school_type_id
HAVING count > 1;

-- Add unique constraint to prevent future duplicates
ALTER TABLE school_info
ADD UNIQUE KEY unique_school_type (school_type_id);

-- Verify the constraint was added
SHOW CREATE TABLE school_info;
