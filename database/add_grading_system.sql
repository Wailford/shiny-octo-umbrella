-- Create grading_system table to allow admin to customize grades
-- This table stores custom grading rules that will override the default system

CREATE TABLE IF NOT EXISTS `grading_system` (
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

-- Insert default Ghana Education Service grading system
-- These are the default values that can be modified by admin
INSERT INTO `grading_system` (`grade`, `min_score`, `max_score`, `remarks`, `display_order`) VALUES
('1', 80.00, 100.00, 'Highest', 1),
('2', 75.00, 79.99, 'Higher', 2),
('3', 70.00, 74.99, 'High', 3),
('4', 65.00, 69.99, 'High Average', 4),
('5', 60.00, 64.99, 'Average', 5),
('6', 55.00, 59.99, 'Low Average', 6),
('7', 50.00, 54.99, 'Low', 7),
('8', 40.00, 49.99, 'Lower', 8),
('9', 0.00, 39.99, 'Lowest', 9);
