-- Fix school_type_id assignments for classes
-- Basic 7-9 should be JHS (school_type_id = 2)
-- Basic 1-6 should remain PRIMARY (school_type_id = 1)

UPDATE classes SET school_type_id = 2 WHERE class_name IN ('Basic Seven', 'Basic Eight', 'Basic Nine');

-- Verify the update
SELECT class_name, school_type_id, 
       CASE 
           WHEN school_type_id = 1 THEN 'PRIMARY'
           WHEN school_type_id = 2 THEN 'JHS'
           WHEN school_type_id = 3 THEN 'BASIC'
       END as school_type
FROM classes 
ORDER BY id;
