-- =====================================================
-- QUICK SQL TO ADD CANDIDATE INDEX COLUMN
-- Run this directly on your hosted database
-- =====================================================

-- Add candidate_index_number column to students table
ALTER TABLE `students` 
ADD COLUMN `candidate_index_number` VARCHAR(12) DEFAULT NULL 
COMMENT 'BECE candidate index: SchoolCode(7) + Sequential(3) + Year(2)';

-- Add unique index for candidate numbers
CREATE INDEX `idx_candidate_index` ON `students` (`candidate_index_number`);

-- Add school_code column to school_info table (required for generation)
ALTER TABLE `school_info` 
ADD COLUMN `school_code` VARCHAR(7) DEFAULT NULL 
COMMENT '7-digit school code for candidate index generation';

SELECT 'Candidate index system ready! Set your school_code in Settings.' AS Status;
