-- Add headmaster_signature column to school_info table
USE `school_management_system`;

ALTER TABLE `school_info` 
ADD COLUMN `headmaster_signature` VARCHAR(500) DEFAULT 'https://imgur.com/ryWbBpr.png' AFTER `logo2_url`;

-- Update existing record with default signature
UPDATE `school_info` SET `headmaster_signature` = 'https://imgur.com/ryWbBpr.png' WHERE `headmaster_signature` IS NULL OR `headmaster_signature` = '';
