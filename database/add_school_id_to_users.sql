-- Migration: Add school_id to users table for proper multi-tenancy
-- This fixes the issue where multiple schools of the same type (e.g., multiple JHS schools)
-- could not exist because users were only linked to school_type_id

-- Add school_id column to users table
-- This links each user to a SPECIFIC school (not just a school type)
ALTER TABLE users
ADD COLUMN school_id INT NULL AFTER school_type_id,
ADD FOREIGN KEY (school_id) REFERENCES school_info(id) ON DELETE CASCADE;

-- Migrate existing data: Find each user's school_id based on their school_type_id
-- For each user, find the school_info record that matches their school_type_id
UPDATE users u
LEFT JOIN school_info si ON si.school_type_id = u.school_type_id
SET u.school_id = si.id
WHERE u.school_type_id IS NOT NULL;

-- Admin users (school_type_id = NULL) will have school_id = NULL (can access all schools)

-- Add index for faster lookups
CREATE INDEX idx_users_school_id ON users(school_id);

-- Note: After this migration, login.php should store school_id in session instead of school_type_id
-- And all queries should filter by school_id from school_info, not school_type_id
