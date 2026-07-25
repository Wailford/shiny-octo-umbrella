-- System Improvements: Subject Masters, Form Masters, Remove Logo2
-- Run this script to update the database

-- 1. Remove logo2_url column from school_info (keep only logo1)
ALTER TABLE school_info DROP COLUMN IF EXISTS logo2_url;

-- 2. Add role column to users table if not exists
ALTER TABLE users ADD COLUMN role VARCHAR(20) DEFAULT 'teacher' AFTER user_type;

-- 3. Update existing users to set appropriate roles
UPDATE users SET role = 'admin' WHERE user_type = 'admin';
UPDATE users SET role = 'teacher' WHERE user_type = 'teacher';

-- 4. Create subject_teachers table for subject master assignments
CREATE TABLE IF NOT EXISTS subject_teachers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    subject_id INT NOT NULL,
    class_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE SET NULL,
    UNIQUE KEY unique_assignment (user_id, subject_id, class_id)
);

-- 5. Create form_masters table for class teacher assignments
CREATE TABLE IF NOT EXISTS form_masters (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    class_id INT NOT NULL,
    academic_year VARCHAR(20) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    UNIQUE KEY unique_form_master (class_id, academic_year)
);

-- 6. Add indexes for performance
CREATE INDEX idx_subject_teachers_user ON subject_teachers(user_id);
CREATE INDEX idx_subject_teachers_subject ON subject_teachers(subject_id);
CREATE INDEX idx_form_masters_user ON form_masters(user_id);
CREATE INDEX idx_form_masters_class ON form_masters(class_id);
