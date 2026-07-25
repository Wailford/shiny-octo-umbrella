-- Remove UNIQUE constraint on school_type_id in school_info table
-- This allows multiple schools of the same type (e.g., multiple JHS schools)

-- Drop the unique constraint
ALTER TABLE school_info
DROP INDEX unique_school_type;

-- Add regular index for performance (non-unique)
ALTER TABLE school_info
ADD INDEX idx_school_type_id (school_type_id);
