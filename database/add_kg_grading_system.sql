-- Create KG grading system table for Kindergarten assessments
-- Separate from primary/JHS grading since KG uses different assessment criteria

CREATE TABLE IF NOT EXISTS `kg_grading_system` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `grade` varchar(20) NOT NULL,
  `min_score` decimal(5,2) NOT NULL,
  `max_score` decimal(5,2) NOT NULL,
  `remarks` varchar(100) NOT NULL,
  `display_order` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `grade` (`grade`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default KG grading system
-- Based on early childhood education standards
INSERT INTO `kg_grading_system` (`grade`, `min_score`, `max_score`, `remarks`, `display_order`) VALUES
('EE', 80.00, 100.00, 'Exceeds Expectations', 1),
('ME', 70.00, 79.99, 'Meets Expectations', 2),
('AE', 60.00, 69.99, 'Approaching Expectations', 3),
('BE', 0.00, 59.99, 'Below Expectations', 4);

-- Create KG subjects table
CREATE TABLE IF NOT EXISTS `kg_subjects` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `class_id` int(11) NOT NULL,
  `subject_name` varchar(100) NOT NULL,
  `display_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `class_subject` (`class_id`, `subject_name`),
  FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert KG subjects for KG One and KG Two classes
INSERT INTO `kg_subjects` (`class_id`, `subject_name`, `display_order`)
SELECT c.id, 'Literacy', 1 FROM classes c WHERE c.class_name IN ('KG One', 'KG Two')
UNION ALL
SELECT c.id, 'Numeracy', 2 FROM classes c WHERE c.class_name IN ('KG One', 'KG Two')
UNION ALL
SELECT c.id, 'Creative Arts', 3 FROM classes c WHERE c.class_name IN ('KG One', 'KG Two')
UNION ALL
SELECT c.id, 'Our World Our People', 4 FROM classes c WHERE c.class_name IN ('KG One', 'KG Two');
