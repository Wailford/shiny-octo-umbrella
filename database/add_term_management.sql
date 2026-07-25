-- Add term management and historical data tracking
USE `school_management_system`;

-- Add term tracking to scores table
ALTER TABLE `scores`
ADD COLUMN `term` VARCHAR(50) DEFAULT 'First Term' AFTER `subject_id`,
ADD COLUMN `academic_year` VARCHAR(20) DEFAULT '2024/2025' AFTER `term`,
ADD KEY `idx_term_year` (`term`, `academic_year`);

-- Create promotion history table
CREATE TABLE IF NOT EXISTS `promotion_history` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `student_id` INT(11) NOT NULL,
  `from_class_id` INT(11) NOT NULL,
  `to_class_id` INT(11) NOT NULL,
  `academic_year` VARCHAR(20) NOT NULL,
  `promoted_date` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `promoted_by` INT(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `student_id` (`student_id`),
  KEY `from_class_id` (`from_class_id`),
  KEY `to_class_id` (`to_class_id`),
  CONSTRAINT `promotion_history_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `promotion_history_ibfk_2` FOREIGN KEY (`from_class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `promotion_history_ibfk_3` FOREIGN KEY (`to_class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create archived scores table for term history
CREATE TABLE IF NOT EXISTS `archived_scores` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `student_id` INT(11) NOT NULL,
  `subject_id` INT(11) NOT NULL,
  `term` VARCHAR(50) NOT NULL,
  `academic_year` VARCHAR(20) NOT NULL,
  `test1` DECIMAL(5,2) DEFAULT NULL,
  `group_work` DECIMAL(5,2) DEFAULT NULL,
  `test2` DECIMAL(5,2) DEFAULT NULL,
  `project_work` DECIMAL(5,2) DEFAULT NULL,
  `class_score` DECIMAL(5,2) DEFAULT NULL,
  `exam_score` DECIMAL(5,2) DEFAULT NULL,
  `total_score` DECIMAL(5,2) DEFAULT NULL,
  `grade` VARCHAR(20) DEFAULT NULL,
  `position` INT(11) DEFAULT NULL,
  `remarks` VARCHAR(100) DEFAULT NULL,
  `archived_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `student_id` (`student_id`),
  KEY `subject_id` (`subject_id`),
  KEY `idx_term_year` (`term`, `academic_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add system settings table for term preparation tracking
CREATE TABLE IF NOT EXISTS `system_settings` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `setting_key` VARCHAR(100) NOT NULL,
  `setting_value` TEXT,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default settings
INSERT INTO `system_settings` (`setting_key`, `setting_value`) VALUES
('last_term_preparation', NULL),
('promotion_allowed', 'false'),
('current_academic_year', '2024/2025')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

COMMIT;
