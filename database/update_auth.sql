-- Update authentication system to match original requirements
-- Drop existing users table and recreate with proper structure

DROP TABLE IF EXISTS users;

CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    user_type ENUM('admin', 'class_teacher', 'subject_teacher') NOT NULL,
    full_name VARCHAR(255),
    email VARCHAR(255),
    phone VARCHAR(50),
    class_id INT NULL, -- For class teachers - which class they manage
    subject_id INT NULL, -- For subject teachers - which subject they teach
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE SET NULL,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create default admin user (password: admin123)
INSERT INTO users (username, password, user_type, full_name, email) VALUES 
('admin', '$2y$10$92IXUNpkjO0rOy24vSpXKeztWme.Rvs8gGKlJh3fJFGfHQzF.1Cju', 'admin', 'System Administrator', 'admin@school.com');

-- Add subject-based authentication
-- Each subject can have its own password for subject teachers
ALTER TABLE subjects 
ADD COLUMN password VARCHAR(255) NULL COMMENT 'Password for subject teachers to access this subject';

-- Set default password for all subjects (admin123)
UPDATE subjects SET password = '$2y$10$92IXUNpkjO0rOy24vSpXKeztWme.Rvs8gGKlJh3fJFGfHQzF.1Cju';

-- Create table to track teacher-subject assignments (for subject teachers teaching multiple subjects)
CREATE TABLE IF NOT EXISTS teacher_subjects (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    subject_id INT NOT NULL,
    class_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_assignment (user_id, subject_id, class_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
