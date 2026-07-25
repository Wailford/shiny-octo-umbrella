-- Add school type support to the system
-- This allows the system to manage Primary, JHS, and Basic schools separately

-- Add school_types table
CREATE TABLE IF NOT EXISTS school_types (
    id INT PRIMARY KEY AUTO_INCREMENT,
    type_name VARCHAR(100) NOT NULL,
    type_code VARCHAR(50) NOT NULL UNIQUE,
    description TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert school types
INSERT INTO school_types (type_name, type_code, description) VALUES
('Primary School', 'PRIMARY', 'Primary School (Basic 1-6)'),
('Junior High School', 'JHS', 'Junior High School (Basic 7-9)'),
('Basic School', 'BASIC', 'Basic School (Primary + JHS: Basic 1-9)');

-- Add school_type_id to school_info
ALTER TABLE school_info 
ADD COLUMN school_type_id INT NOT NULL DEFAULT 3 AFTER school_name,
ADD FOREIGN KEY (school_type_id) REFERENCES school_types(id);

-- Update existing school info to Basic School
UPDATE school_info SET school_type_id = 3 WHERE id = 1;

-- Add school_type_id to classes
ALTER TABLE classes 
ADD COLUMN school_type_id INT NOT NULL DEFAULT 3 AFTER class_code,
ADD FOREIGN KEY (school_type_id) REFERENCES school_types(id);

-- Update existing classes
-- Primary School classes (Basic 1-6)
UPDATE classes SET school_type_id = 1 
WHERE class_code IN ('Basic1', 'Basic2', 'Basic3', 'Basic4', 'Basic5', 'Basic6');

-- JHS classes (Basic 7-9)
UPDATE classes SET school_type_id = 2 
WHERE class_code IN ('BasicSeven', 'BasicEight', 'BasicNine');

-- For Basic School, all classes are available (id=3 is default)
UPDATE classes SET school_type_id = 3;

-- Add more Primary School classes if they don't exist
INSERT IGNORE INTO classes (class_name, class_code, school_type_id, class_order) VALUES
('Basic One', 'Basic1', 1, 1),
('Basic Two', 'Basic2', 1, 2),
('Basic Three', 'Basic3', 1, 3),
('Basic Four', 'Basic4', 1, 4),
('Basic Five', 'Basic5', 1, 5),
('Basic Six', 'Basic6', 1, 6);

-- Add school_type_id to users so each user belongs to a school type
ALTER TABLE users 
ADD COLUMN school_type_id INT NULL AFTER user_type,
ADD FOREIGN KEY (school_type_id) REFERENCES school_types(id) ON DELETE SET NULL;

-- Admin can access all school types (set to NULL for admin)
UPDATE users SET school_type_id = NULL WHERE user_type = 'admin';

-- Add school_type_id to students
ALTER TABLE students 
ADD COLUMN school_type_id INT NOT NULL DEFAULT 3 AFTER class_id,
ADD FOREIGN KEY (school_type_id) REFERENCES school_types(id);

-- Update existing students based on their class
UPDATE students s
JOIN classes c ON s.class_id = c.id
SET s.school_type_id = c.school_type_id;

-- Create view for easier querying
CREATE OR REPLACE VIEW v_students_full AS
SELECT 
    s.*,
    c.class_name,
    c.class_code,
    st.type_name as school_type_name,
    st.type_code as school_type_code
FROM students s
JOIN classes c ON s.class_id = c.id
JOIN school_types st ON s.school_type_id = st.id;

-- Create view for classes with school type
CREATE OR REPLACE VIEW v_classes_full AS
SELECT 
    c.*,
    st.type_name as school_type_name,
    st.type_code as school_type_code,
    COUNT(DISTINCT s.id) as total_students
FROM classes c
JOIN school_types st ON c.school_type_id = st.id
LEFT JOIN students s ON c.id = s.class_id
GROUP BY c.id, st.id;

-- Create view for subjects with school type
CREATE OR REPLACE VIEW v_subjects_full AS
SELECT 
    subj.*,
    c.class_name,
    c.class_code,
    st.type_name as school_type_name,
    st.type_code as school_type_code
FROM subjects subj
JOIN classes c ON subj.class_id = c.id
JOIN school_types st ON c.school_type_id = st.id;
