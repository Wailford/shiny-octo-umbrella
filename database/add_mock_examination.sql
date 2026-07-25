-- Mock Examination System Schema
-- This adds support for BECE Mock Examinations for Basic 9 students

-- 1. Add candidate_index_number to students table
ALTER TABLE students 
ADD COLUMN candidate_index_number VARCHAR(20) NULL AFTER student_id,
ADD INDEX idx_candidate_index (candidate_index_number);

-- 2. Create mock_exam_settings table
CREATE TABLE IF NOT EXISTS mock_exam_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    school_id INT NOT NULL,
    school_type_id INT NOT NULL,
    is_enabled TINYINT(1) DEFAULT 0,
    academic_year VARCHAR(20) NOT NULL,
    term VARCHAR(20) NOT NULL,
    start_date DATE NULL,
    end_date DATE NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (school_id) REFERENCES school_info(id) ON DELETE CASCADE,
    UNIQUE KEY unique_school_year_term (school_id, academic_year, term)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Create mock_exam_scores table
CREATE TABLE IF NOT EXISTS mock_exam_scores (
    id INT PRIMARY KEY AUTO_INCREMENT,
    school_id INT NOT NULL,
    student_id INT NOT NULL,
    subject_id INT NOT NULL,
    class_id INT NOT NULL,
    candidate_index_number VARCHAR(20) NOT NULL,
    score DECIMAL(5,2) NOT NULL CHECK (score >= 0 AND score <= 100),
    grade VARCHAR(2) NULL,
    remark VARCHAR(50) NULL,
    academic_year VARCHAR(20) NOT NULL,
    term VARCHAR(20) NOT NULL,
    entered_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (school_id) REFERENCES school_info(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (entered_by) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_candidate_subject (school_id, candidate_index_number, subject_id, academic_year, term),
    INDEX idx_school_year_term (school_id, academic_year, term),
    INDEX idx_candidate (candidate_index_number),
    INDEX idx_subject (subject_id),
    INDEX idx_class (class_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Create mock_exam_analysis table for caching analysis results
CREATE TABLE IF NOT EXISTS mock_exam_analysis (
    id INT PRIMARY KEY AUTO_INCREMENT,
    school_id INT NOT NULL,
    class_id INT NOT NULL,
    subject_id INT NULL,
    academic_year VARCHAR(20) NOT NULL,
    term VARCHAR(20) NOT NULL,
    total_candidates INT DEFAULT 0,
    highest_score DECIMAL(5,2) DEFAULT 0,
    lowest_score DECIMAL(5,2) DEFAULT 0,
    average_score DECIMAL(5,2) DEFAULT 0,
    pass_rate DECIMAL(5,2) DEFAULT 0,
    grade_distribution JSON NULL,
    analysis_data JSON NULL,
    generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (school_id) REFERENCES school_info(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
    UNIQUE KEY unique_analysis (school_id, class_id, subject_id, academic_year, term)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert default comment
INSERT INTO mock_exam_settings (school_id, school_type_id, is_enabled, academic_year, term) 
SELECT id, school_type_id, 0, '2024/2025', 'Term 1' 
FROM school_info 
WHERE school_type_id = 2 -- Only JHS schools
ON DUPLICATE KEY UPDATE is_enabled = is_enabled;
