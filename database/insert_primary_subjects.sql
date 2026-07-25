-- Insert default primary school subjects for all classes
-- This script populates subjects based on Ghana Education Service curriculum

-- First, get the school_type_id for Primary schools
SET @primary_type_id = (SELECT id FROM school_types WHERE type_code = 'PRIMARY' LIMIT 1);

-- KG One subjects
INSERT INTO subjects (subject_name, subject_code, class_id, is_core, password)
SELECT 'Literacy', 'LIT', c.id, 1, 'literacy'
FROM classes c WHERE c.class_name = 'KG One' AND c.school_type_id = @primary_type_id
ON DUPLICATE KEY UPDATE subject_name = subject_name;

INSERT INTO subjects (subject_name, subject_code, class_id, is_core, password)
SELECT 'Numeracy', 'NUM', c.id, 1, 'numeracy'
FROM classes c WHERE c.class_name = 'KG One' AND c.school_type_id = @primary_type_id
ON DUPLICATE KEY UPDATE subject_name = subject_name;

INSERT INTO subjects (subject_name, subject_code, class_id, is_core, password)
SELECT 'Creative Arts', 'CA', c.id, 1, 'arts'
FROM classes c WHERE c.class_name = 'KG One' AND c.school_type_id = @primary_type_id
ON DUPLICATE KEY UPDATE subject_name = subject_name;

INSERT INTO subjects (subject_name, subject_code, class_id, is_core, password)
SELECT 'Our World Our People', 'OWOP', c.id, 1, 'world'
FROM classes c WHERE c.class_name = 'KG One' AND c.school_type_id = @primary_type_id
ON DUPLICATE KEY UPDATE subject_name = subject_name;

-- KG Two subjects (same as KG One)
INSERT INTO subjects (subject_name, subject_code, class_id, is_core, password)
SELECT 'Literacy', 'LIT', c.id, 1, 'literacy'
FROM classes c WHERE c.class_name = 'KG Two' AND c.school_type_id = @primary_type_id
ON DUPLICATE KEY UPDATE subject_name = subject_name;

INSERT INTO subjects (subject_name, subject_code, class_id, is_core, password)
SELECT 'Numeracy', 'NUM', c.id, 1, 'numeracy'
FROM classes c WHERE c.class_name = 'KG Two' AND c.school_type_id = @primary_type_id
ON DUPLICATE KEY UPDATE subject_name = subject_name;

INSERT INTO subjects (subject_name, subject_code, class_id, is_core, password)
SELECT 'Creative Arts', 'CA', c.id, 1, 'arts'
FROM classes c WHERE c.class_name = 'KG Two' AND c.school_type_id = @primary_type_id
ON DUPLICATE KEY UPDATE subject_name = subject_name;

INSERT INTO subjects (subject_name, subject_code, class_id, is_core, password)
SELECT 'Our World Our People', 'OWOP', c.id, 1, 'world'
FROM classes c WHERE c.class_name = 'KG Two' AND c.school_type_id = @primary_type_id
ON DUPLICATE KEY UPDATE subject_name = subject_name;

-- Basic One subjects
INSERT INTO subjects (subject_name, subject_code, class_id, is_core, password)
SELECT 'Mathematics', 'MATH', c.id, 1, 'math'
FROM classes c WHERE c.class_name = 'Basic One' AND c.school_type_id = @primary_type_id
ON DUPLICATE KEY UPDATE subject_name = subject_name;

INSERT INTO subjects (subject_name, subject_code, class_id, is_core, password)
SELECT 'English', 'ENG', c.id, 1, 'english'
FROM classes c WHERE c.class_name = 'Basic One' AND c.school_type_id = @primary_type_id
ON DUPLICATE KEY UPDATE subject_name = subject_name;

INSERT INTO subjects (subject_name, subject_code, class_id, is_core, password)
SELECT 'Science', 'SCI', c.id, 1, 'science'
FROM classes c WHERE c.class_name = 'Basic One' AND c.school_type_id = @primary_type_id
ON DUPLICATE KEY UPDATE subject_name = subject_name;

INSERT INTO subjects (subject_name, subject_code, class_id, is_core, password)
SELECT 'History', 'HIST', c.id, 1, 'history'
FROM classes c WHERE c.class_name = 'Basic One' AND c.school_type_id = @primary_type_id
ON DUPLICATE KEY UPDATE subject_name = subject_name;

INSERT INTO subjects (subject_name, subject_code, class_id, is_core, password)
SELECT 'Creative Arts', 'CA', c.id, 1, 'arts'
FROM classes c WHERE c.class_name = 'Basic One' AND c.school_type_id = @primary_type_id
ON DUPLICATE KEY UPDATE subject_name = subject_name;

INSERT INTO subjects (subject_name, subject_code, class_id, is_core, password)
SELECT 'RME', 'RME', c.id, 1, 'rme'
FROM classes c WHERE c.class_name = 'Basic One' AND c.school_type_id = @primary_type_id
ON DUPLICATE KEY UPDATE subject_name = subject_name;

INSERT INTO subjects (subject_name, subject_code, class_id, is_core, password)
SELECT 'Asante Twi', 'TWI', c.id, 1, 'twi'
FROM classes c WHERE c.class_name = 'Basic One' AND c.school_type_id = @primary_type_id
ON DUPLICATE KEY UPDATE subject_name = subject_name;

-- Basic Two subjects (same as Basic One)
INSERT INTO subjects (subject_name, subject_code, class_id, is_core, password)
SELECT 'Mathematics', 'MATH', c.id, 1, 'math'
FROM classes c WHERE c.class_name = 'Basic Two' AND c.school_type_id = @primary_type_id
ON DUPLICATE KEY UPDATE subject_name = subject_name;

INSERT INTO subjects (subject_name, subject_code, class_id, is_core, password)
SELECT 'English', 'ENG', c.id, 1, 'english'
FROM classes c WHERE c.class_name = 'Basic Two' AND c.school_type_id = @primary_type_id
ON DUPLICATE KEY UPDATE subject_name = subject_name;

INSERT INTO subjects (subject_name, subject_code, class_id, is_core, password)
SELECT 'Science', 'SCI', c.id, 1, 'science'
FROM classes c WHERE c.class_name = 'Basic Two' AND c.school_type_id = @primary_type_id
ON DUPLICATE KEY UPDATE subject_name = subject_name;

INSERT INTO subjects (subject_name, subject_code, class_id, is_core, password)
SELECT 'History', 'HIST', c.id, 1, 'history'
FROM classes c WHERE c.class_name = 'Basic Two' AND c.school_type_id = @primary_type_id
ON DUPLICATE KEY UPDATE subject_name = subject_name;

INSERT INTO subjects (subject_name, subject_code, class_id, is_core, password)
SELECT 'Creative Arts', 'CA', c.id, 1, 'arts'
FROM classes c WHERE c.class_name = 'Basic Two' AND c.school_type_id = @primary_type_id
ON DUPLICATE KEY UPDATE subject_name = subject_name;

INSERT INTO subjects (subject_name, subject_code, class_id, is_core, password)
SELECT 'RME', 'RME', c.id, 1, 'rme'
FROM classes c WHERE c.class_name = 'Basic Two' AND c.school_type_id = @primary_type_id
ON DUPLICATE KEY UPDATE subject_name = subject_name;

INSERT INTO subjects (subject_name, subject_code, class_id, is_core, password)
SELECT 'Asante Twi', 'TWI', c.id, 1, 'twi'
FROM classes c WHERE c.class_name = 'Basic Two' AND c.school_type_id = @primary_type_id
ON DUPLICATE KEY UPDATE subject_name = subject_name;

-- Basic Three subjects (same as Basic One & Two)
INSERT INTO subjects (subject_name, subject_code, class_id, is_core, password)
SELECT 'Mathematics', 'MATH', c.id, 1, 'math'
FROM classes c WHERE c.class_name = 'Basic Three' AND c.school_type_id = @primary_type_id
ON DUPLICATE KEY UPDATE subject_name = subject_name;

INSERT INTO subjects (subject_name, subject_code, class_id, is_core, password)
SELECT 'English', 'ENG', c.id, 1, 'english'
FROM classes c WHERE c.class_name = 'Basic Three' AND c.school_type_id = @primary_type_id
ON DUPLICATE KEY UPDATE subject_name = subject_name;

INSERT INTO subjects (subject_name, subject_code, class_id, is_core, password)
SELECT 'Science', 'SCI', c.id, 1, 'science'
FROM classes c WHERE c.class_name = 'Basic Three' AND c.school_type_id = @primary_type_id
ON DUPLICATE KEY UPDATE subject_name = subject_name;

INSERT INTO subjects (subject_name, subject_code, class_id, is_core, password)
SELECT 'History', 'HIST', c.id, 1, 'history'
FROM classes c WHERE c.class_name = 'Basic Three' AND c.school_type_id = @primary_type_id
ON DUPLICATE KEY UPDATE subject_name = subject_name;

INSERT INTO subjects (subject_name, subject_code, class_id, is_core, password)
SELECT 'Creative Arts', 'CA', c.id, 1, 'arts'
FROM classes c WHERE c.class_name = 'Basic Three' AND c.school_type_id = @primary_type_id
ON DUPLICATE KEY UPDATE subject_name = subject_name;

INSERT INTO subjects (subject_name, subject_code, class_id, is_core, password)
SELECT 'RME', 'RME', c.id, 1, 'rme'
FROM classes c WHERE c.class_name = 'Basic Three' AND c.school_type_id = @primary_type_id
ON DUPLICATE KEY UPDATE subject_name = subject_name;

INSERT INTO subjects (subject_name, subject_code, class_id, is_core, password)
SELECT 'Asante Twi', 'TWI', c.id, 1, 'twi'
FROM classes c WHERE c.class_name = 'Basic Three' AND c.school_type_id = @primary_type_id
ON DUPLICATE KEY UPDATE subject_name = subject_name;

-- Basic Four subjects (adds Computing)
INSERT INTO subjects (subject_name, subject_code, class_id, is_core, password)
SELECT 'Mathematics', 'MATH', c.id, 1, 'math'
FROM classes c WHERE c.class_name = 'Basic Four' AND c.school_type_id = @primary_type_id
ON DUPLICATE KEY UPDATE subject_name = subject_name;

INSERT INTO subjects (subject_name, subject_code, class_id, is_core, password)
SELECT 'English', 'ENG', c.id, 1, 'english'
FROM classes c WHERE c.class_name = 'Basic Four' AND c.school_type_id = @primary_type_id
ON DUPLICATE KEY UPDATE subject_name = subject_name;

INSERT INTO subjects (subject_name, subject_code, class_id, is_core, password)
SELECT 'Science', 'SCI', c.id, 1, 'science'
FROM classes c WHERE c.class_name = 'Basic Four' AND c.school_type_id = @primary_type_id
ON DUPLICATE KEY UPDATE subject_name = subject_name;

INSERT INTO subjects (subject_name, subject_code, class_id, is_core, password)
SELECT 'History', 'HIST', c.id, 1, 'history'
FROM classes c WHERE c.class_name = 'Basic Four' AND c.school_type_id = @primary_type_id
ON DUPLICATE KEY UPDATE subject_name = subject_name;

INSERT INTO subjects (subject_name, subject_code, class_id, is_core, password)
SELECT 'Creative Arts', 'CA', c.id, 1, 'arts'
FROM classes c WHERE c.class_name = 'Basic Four' AND c.school_type_id = @primary_type_id
ON DUPLICATE KEY UPDATE subject_name = subject_name;

INSERT INTO subjects (subject_name, subject_code, class_id, is_core, password)
SELECT 'RME', 'RME', c.id, 1, 'rme'
FROM classes c WHERE c.class_name = 'Basic Four' AND c.school_type_id = @primary_type_id
ON DUPLICATE KEY UPDATE subject_name = subject_name;

INSERT INTO subjects (subject_name, subject_code, class_id, is_core, password)
SELECT 'Asante Twi', 'TWI', c.id, 1, 'twi'
FROM classes c WHERE c.class_name = 'Basic Four' AND c.school_type_id = @primary_type_id
ON DUPLICATE KEY UPDATE subject_name = subject_name;

INSERT INTO subjects (subject_name, subject_code, class_id, is_core, password)
SELECT 'Computing', 'ICT', c.id, 1, 'computing'
FROM classes c WHERE c.class_name = 'Basic Four' AND c.school_type_id = @primary_type_id
ON DUPLICATE KEY UPDATE subject_name = subject_name;

-- Basic Five subjects (same as Basic Four)
INSERT INTO subjects (subject_name, subject_code, class_id, is_core, password)
SELECT 'Mathematics', 'MATH', c.id, 1, 'math'
FROM classes c WHERE c.class_name = 'Basic Five' AND c.school_type_id = @primary_type_id
ON DUPLICATE KEY UPDATE subject_name = subject_name;

INSERT INTO subjects (subject_name, subject_code, class_id, is_core, password)
SELECT 'English', 'ENG', c.id, 1, 'english'
FROM classes c WHERE c.class_name = 'Basic Five' AND c.school_type_id = @primary_type_id
ON DUPLICATE KEY UPDATE subject_name = subject_name;

INSERT INTO subjects (subject_name, subject_code, class_id, is_core, password)
SELECT 'Science', 'SCI', c.id, 1, 'science'
FROM classes c WHERE c.class_name = 'Basic Five' AND c.school_type_id = @primary_type_id
ON DUPLICATE KEY UPDATE subject_name = subject_name;

INSERT INTO subjects (subject_name, subject_code, class_id, is_core, password)
SELECT 'History', 'HIST', c.id, 1, 'history'
FROM classes c WHERE c.class_name = 'Basic Five' AND c.school_type_id = @primary_type_id
ON DUPLICATE KEY UPDATE subject_name = subject_name;

INSERT INTO subjects (subject_name, subject_code, class_id, is_core, password)
SELECT 'Creative Arts', 'CA', c.id, 1, 'arts'
FROM classes c WHERE c.class_name = 'Basic Five' AND c.school_type_id = @primary_type_id
ON DUPLICATE KEY UPDATE subject_name = subject_name;

INSERT INTO subjects (subject_name, subject_code, class_id, is_core, password)
SELECT 'RME', 'RME', c.id, 1, 'rme'
FROM classes c WHERE c.class_name = 'Basic Five' AND c.school_type_id = @primary_type_id
ON DUPLICATE KEY UPDATE subject_name = subject_name;

INSERT INTO subjects (subject_name, subject_code, class_id, is_core, password)
SELECT 'Asante Twi', 'TWI', c.id, 1, 'twi'
FROM classes c WHERE c.class_name = 'Basic Five' AND c.school_type_id = @primary_type_id
ON DUPLICATE KEY UPDATE subject_name = subject_name;

INSERT INTO subjects (subject_name, subject_code, class_id, is_core, password)
SELECT 'Computing', 'ICT', c.id, 1, 'computing'
FROM classes c WHERE c.class_name = 'Basic Five' AND c.school_type_id = @primary_type_id
ON DUPLICATE KEY UPDATE subject_name = subject_name;

-- Basic Six subjects (same as Basic Four & Five)
INSERT INTO subjects (subject_name, subject_code, class_id, is_core, password)
SELECT 'Mathematics', 'MATH', c.id, 1, 'math'
FROM classes c WHERE c.class_name = 'Basic Six' AND c.school_type_id = @primary_type_id
ON DUPLICATE KEY UPDATE subject_name = subject_name;

INSERT INTO subjects (subject_name, subject_code, class_id, is_core, password)
SELECT 'English', 'ENG', c.id, 1, 'english'
FROM classes c WHERE c.class_name = 'Basic Six' AND c.school_type_id = @primary_type_id
ON DUPLICATE KEY UPDATE subject_name = subject_name;

INSERT INTO subjects (subject_name, subject_code, class_id, is_core, password)
SELECT 'Science', 'SCI', c.id, 1, 'science'
FROM classes c WHERE c.class_name = 'Basic Six' AND c.school_type_id = @primary_type_id
ON DUPLICATE KEY UPDATE subject_name = subject_name;

INSERT INTO subjects (subject_name, subject_code, class_id, is_core, password)
SELECT 'History', 'HIST', c.id, 1, 'history'
FROM classes c WHERE c.class_name = 'Basic Six' AND c.school_type_id = @primary_type_id
ON DUPLICATE KEY UPDATE subject_name = subject_name;

INSERT INTO subjects (subject_name, subject_code, class_id, is_core, password)
SELECT 'Creative Arts', 'CA', c.id, 1, 'arts'
FROM classes c WHERE c.class_name = 'Basic Six' AND c.school_type_id = @primary_type_id
ON DUPLICATE KEY UPDATE subject_name = subject_name;

INSERT INTO subjects (subject_name, subject_code, class_id, is_core, password)
SELECT 'RME', 'RME', c.id, 1, 'rme'
FROM classes c WHERE c.class_name = 'Basic Six' AND c.school_type_id = @primary_type_id
ON DUPLICATE KEY UPDATE subject_name = subject_name;

INSERT INTO subjects (subject_name, subject_code, class_id, is_core, password)
SELECT 'Asante Twi', 'TWI', c.id, 1, 'twi'
FROM classes c WHERE c.class_name = 'Basic Six' AND c.school_type_id = @primary_type_id
ON DUPLICATE KEY UPDATE subject_name = subject_name;

INSERT INTO subjects (subject_name, subject_code, class_id, is_core, password)
SELECT 'Computing', 'ICT', c.id, 1, 'computing'
FROM classes c WHERE c.class_name = 'Basic Six' AND c.school_type_id = @primary_type_id
ON DUPLICATE KEY UPDATE subject_name = subject_name;

-- Display summary
SELECT 'Primary School Subjects Inserted Successfully' as Status;
SELECT c.class_name, COUNT(s.id) as subject_count
FROM classes c
LEFT JOIN subjects s ON s.class_id = c.id
WHERE c.school_type_id = @primary_type_id
GROUP BY c.class_name
ORDER BY c.id;
