-- Add absenteeism tracking for mock exams
CREATE TABLE IF NOT EXISTS mock_exam_absenteeism (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    student_id INT NOT NULL,
    academic_year VARCHAR(10) NOT NULL,
    term VARCHAR(20) NOT NULL,
    reason ENUM('PREGNANT/MARRIED', 'DEATH', 'TRAVELLED', 'ILLNESS', 'TRUANCY/DROP-OUT', 'WITHDRAWAL/TRANSFER', 'UNKNOWN') NOT NULL,
    entered_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_student_absence (school_id, student_id, academic_year, term),
    FOREIGN KEY (school_id) REFERENCES school_info(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (entered_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
