-- Add is_core column to subjects table
-- This column determines if a subject is Core (used in aggregate) or Elective

ALTER TABLE `subjects` 
ADD COLUMN `is_core` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Core=1, Elective=0' AFTER `class_id`;

-- Update existing subjects to be core by default
-- You can manually change specific subjects to elective (0) later
UPDATE `subjects` SET `is_core` = 1;

-- Common core subjects for JHS (Ghana Education System)
-- Mathematics, English, Integrated Science, Social Studies are typically core
-- Creative Arts, RME, Languages, Computing, Career Tech can be elective

-- Example: Set some subjects as elective (optional - adjust as needed)
-- UPDATE `subjects` SET `is_core` = 0 WHERE subject_name IN ('Creative Arts', 'Computing', 'Career Technology', 'Asante Twi');
