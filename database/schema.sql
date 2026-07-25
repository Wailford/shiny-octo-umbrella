-- School Management System Database Schema
-- MySQL Database

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

-- --------------------------------------------------------
-- Database: `school_management_system`
-- --------------------------------------------------------

CREATE DATABASE IF NOT EXISTS `school_management_system` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `school_management_system`;

-- --------------------------------------------------------
-- Table structure for table `school_info`
-- --------------------------------------------------------

CREATE TABLE `school_info` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `school_name` varchar(255) NOT NULL DEFAULT 'M.T.T.C. PRACTICE PRIMARY SCHOOL',
  `address` varchar(255) NOT NULL DEFAULT 'POST OFFICE BOX 379',
  `location` varchar(255) NOT NULL DEFAULT 'MAMPONG-ASHANTI',
  `headmaster_name` varchar(255) NOT NULL DEFAULT 'MR. EDMUND BEDIAKO',
  `email` varchar(255) NOT NULL DEFAULT 'mttcprap1972@gmail.com',
  `phone` varchar(50) NOT NULL DEFAULT '0244010696',
  `logo1_url` varchar(500) DEFAULT 'https://i.imgur.com/l9dDRok.png',
  `logo2_url` varchar(500) DEFAULT 'https://imgur.com/N0sEqK0.png',
  `current_term` varchar(50) NOT NULL DEFAULT 'Second Term',
  `academic_year` varchar(20) NOT NULL DEFAULT '2024/2025',
  `reopen_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default school info
INSERT INTO `school_info` (`id`, `school_name`, `address`, `location`, `headmaster_name`, `email`, `phone`, `reopen_date`) 
VALUES (1, 'M.T.T.C. PRACTICE PRIMARY SCHOOL', 'POST OFFICE BOX 379', 'MAMPONG-ASHANTI', 'MR. EDMUND BEDIAKO', 'mttcprap1972@gmail.com', '0244010696', '2025-05-06');

-- --------------------------------------------------------
-- Table structure for table `users`
-- --------------------------------------------------------

CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `user_type` enum('admin','class_teacher') NOT NULL DEFAULT 'class_teacher',
  `full_name` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default admin user (password: admin123)
INSERT INTO `users` (`username`, `password`, `user_type`, `full_name`) 
VALUES ('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 'System Administrator');

-- --------------------------------------------------------
-- Table structure for table `classes`
-- --------------------------------------------------------

CREATE TABLE `classes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `class_name` varchar(100) NOT NULL,
  `class_code` varchar(50) NOT NULL,
  `total_attendance` int(11) DEFAULT 71,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `class_code` (`class_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default classes
INSERT INTO `classes` (`class_name`, `class_code`) VALUES
('Basic Seven', 'BasicSeven'),
('Basic Eight', 'BasicEight'),
('Basic Nine', 'BasicNine');

-- --------------------------------------------------------
-- Table structure for table `subjects`
-- --------------------------------------------------------

CREATE TABLE `subjects` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `subject_name` varchar(255) NOT NULL,
  `subject_code` varchar(50) NOT NULL,
  `class_id` int(11) NOT NULL,
  `password` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `class_id` (`class_id`),
  CONSTRAINT `subjects_ibfk_1` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert subjects for each class
INSERT INTO `subjects` (`subject_name`, `subject_code`, `class_id`, `password`) VALUES
-- Basic Seven Subjects
('Mathematics', 'BS7Mathematics', 1, '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'),
('English Language', 'BS7English', 1, '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'),
('Integrated Science', 'BS7Science', 1, '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'),
('Social Studies', 'BS7Social', 1, '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'),
('Creative Arts', 'BS7Creative', 1, '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'),
('RME', 'BS7Religious', 1, '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'),
('Asante Twi', 'BS7AsanteTwi', 1, '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'),
('Computing', 'BS7Computing', 1, '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'),
('Career Technology', 'BS7Career', 1, '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'),
-- Basic Eight Subjects
('Mathematics', 'BS8Mathematics', 2, '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'),
('English Language', 'BS8English', 2, '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'),
('Integrated Science', 'BS8Science', 2, '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'),
('Social Studies', 'BS8Social', 2, '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'),
('Creative Arts', 'BS8Creative', 2, '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'),
('RME', 'BS8Religious', 2, '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'),
('Asante Twi', 'BS8AsanteTwi', 2, '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'),
('Computing', 'BS8Computing', 2, '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'),
('Career Technology', 'BS8Career', 2, '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'),
-- Basic Nine Subjects
('Mathematics', 'BS9Mathematics', 3, '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'),
('English Language', 'BS9English', 3, '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'),
('Integrated Science', 'BS9Science', 3, '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'),
('Social Studies', 'BS9Social', 3, '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'),
('Creative Arts', 'BS9Creative', 3, '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'),
('RME', 'BS9Religious', 3, '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'),
('Asante Twi', 'BS9AsanteTwi', 3, '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'),
('Computing', 'BS9Computing', 3, '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'),
('Career Technology', 'BS9Career', 3, '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi');

-- --------------------------------------------------------
-- Table structure for table `students`
-- --------------------------------------------------------

CREATE TABLE `students` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` varchar(50) NOT NULL,
  `student_name` varchar(255) NOT NULL,
  `class_id` int(11) NOT NULL,
  `gender` enum('M','F') DEFAULT NULL,
  `attendance` int(11) DEFAULT 0,
  `interest` varchar(255) DEFAULT NULL,
  `conduct` varchar(100) DEFAULT NULL,
  `form_master_remarks` text,
  `headmaster_remarks` text,
  `promoted` varchar(50) DEFAULT NULL,
  `promoted_to_class` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `student_id_class` (`student_id`, `class_id`),
  KEY `class_id` (`class_id`),
  CONSTRAINT `students_ibfk_1` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `scores`
-- --------------------------------------------------------

CREATE TABLE `scores` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `test1` decimal(5,2) DEFAULT NULL,
  `group_work` decimal(5,2) DEFAULT NULL,
  `test2` decimal(5,2) DEFAULT NULL,
  `project_work` decimal(5,2) DEFAULT NULL,
  `class_score` decimal(5,2) GENERATED ALWAYS AS (COALESCE(`test1`,0) + COALESCE(`group_work`,0) + COALESCE(`test2`,0) + COALESCE(`project_work`,0)) STORED,
  `exam_score` decimal(5,2) DEFAULT NULL,
  `total_score` decimal(5,2) GENERATED ALWAYS AS (COALESCE(`test1`,0) + COALESCE(`group_work`,0) + COALESCE(`test2`,0) + COALESCE(`project_work`,0) + COALESCE(`exam_score`,0)) STORED,
  `grade` varchar(20) GENERATED ALWAYS AS (
    CASE 
      WHEN (COALESCE(`test1`,0) + COALESCE(`group_work`,0) + COALESCE(`test2`,0) + COALESCE(`project_work`,0) + COALESCE(`exam_score`,0)) >= 80 THEN '1-Highest'
      WHEN (COALESCE(`test1`,0) + COALESCE(`group_work`,0) + COALESCE(`test2`,0) + COALESCE(`project_work`,0) + COALESCE(`exam_score`,0)) >= 75 THEN '2-Higher'
      WHEN (COALESCE(`test1`,0) + COALESCE(`group_work`,0) + COALESCE(`test2`,0) + COALESCE(`project_work`,0) + COALESCE(`exam_score`,0)) >= 70 THEN '3-High'
      WHEN (COALESCE(`test1`,0) + COALESCE(`group_work`,0) + COALESCE(`test2`,0) + COALESCE(`project_work`,0) + COALESCE(`exam_score`,0)) >= 65 THEN '4-High Average'
      WHEN (COALESCE(`test1`,0) + COALESCE(`group_work`,0) + COALESCE(`test2`,0) + COALESCE(`project_work`,0) + COALESCE(`exam_score`,0)) >= 60 THEN '5-Average'
      WHEN (COALESCE(`test1`,0) + COALESCE(`group_work`,0) + COALESCE(`test2`,0) + COALESCE(`project_work`,0) + COALESCE(`exam_score`,0)) >= 55 THEN '6-Low Average'
      WHEN (COALESCE(`test1`,0) + COALESCE(`group_work`,0) + COALESCE(`test2`,0) + COALESCE(`project_work`,0) + COALESCE(`exam_score`,0)) >= 50 THEN '7-Low'
      WHEN (COALESCE(`test1`,0) + COALESCE(`group_work`,0) + COALESCE(`test2`,0) + COALESCE(`project_work`,0) + COALESCE(`exam_score`,0)) >= 40 THEN '8-Lower'
      ELSE '9-Lowest'
    END
  ) STORED,
  `position` int(11) DEFAULT NULL,
  `remarks` varchar(100) GENERATED ALWAYS AS (
    CASE 
      WHEN (COALESCE(`test1`,0) + COALESCE(`group_work`,0) + COALESCE(`test2`,0) + COALESCE(`project_work`,0) + COALESCE(`exam_score`,0)) >= 80 THEN 'Highest'
      WHEN (COALESCE(`test1`,0) + COALESCE(`group_work`,0) + COALESCE(`test2`,0) + COALESCE(`project_work`,0) + COALESCE(`exam_score`,0)) >= 75 THEN 'Higher'
      WHEN (COALESCE(`test1`,0) + COALESCE(`group_work`,0) + COALESCE(`test2`,0) + COALESCE(`project_work`,0) + COALESCE(`exam_score`,0)) >= 70 THEN 'High'
      WHEN (COALESCE(`test1`,0) + COALESCE(`group_work`,0) + COALESCE(`test2`,0) + COALESCE(`project_work`,0) + COALESCE(`exam_score`,0)) >= 65 THEN 'High Average'
      WHEN (COALESCE(`test1`,0) + COALESCE(`group_work`,0) + COALESCE(`test2`,0) + COALESCE(`project_work`,0) + COALESCE(`exam_score`,0)) >= 60 THEN 'Average'
      WHEN (COALESCE(`test1`,0) + COALESCE(`group_work`,0) + COALESCE(`test2`,0) + COALESCE(`project_work`,0) + COALESCE(`exam_score`,0)) >= 55 THEN 'Low Average'
      WHEN (COALESCE(`test1`,0) + COALESCE(`group_work`,0) + COALESCE(`test2`,0) + COALESCE(`project_work`,0) + COALESCE(`exam_score`,0)) >= 50 THEN 'Low'
      WHEN (COALESCE(`test1`,0) + COALESCE(`group_work`,0) + COALESCE(`test2`,0) + COALESCE(`project_work`,0) + COALESCE(`exam_score`,0)) >= 40 THEN 'Lower'
      ELSE 'Lowest'
    END
  ) STORED,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `student_subject` (`student_id`, `subject_id`),
  KEY `subject_id` (`subject_id`),
  CONSTRAINT `scores_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `scores_ibfk_2` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `class_records`
-- --------------------------------------------------------

CREATE TABLE `class_records` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `term` varchar(50) DEFAULT NULL,
  `no_on_roll` int(11) DEFAULT NULL,
  `attendance` int(11) DEFAULT NULL,
  `interest` varchar(255) DEFAULT NULL,
  `conduct` varchar(100) DEFAULT NULL,
  `teacher_remarks` text,
  `headmaster_remarks` text,
  `promoted` varchar(50) DEFAULT NULL,
  `promoted_to` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `student_id` (`student_id`),
  KEY `class_id` (`class_id`),
  CONSTRAINT `class_records_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `class_records_ibfk_2` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Create indexes for better query performance
-- --------------------------------------------------------

CREATE INDEX idx_student_name ON students(student_name);
CREATE INDEX idx_class_code ON classes(class_code);
CREATE INDEX idx_subject_code ON subjects(subject_code);
CREATE INDEX idx_scores_total ON scores(total_score);
CREATE INDEX idx_student_class ON students(student_id, class_id);

COMMIT;
