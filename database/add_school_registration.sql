-- School Self-Registration System
-- Add approval and trial management to school_info

-- Add new columns to school_info for self-registration (one at a time for MySQL compatibility)
ALTER TABLE school_info ADD COLUMN IF NOT EXISTS is_approved TINYINT(1) DEFAULT 0 COMMENT 'Whether school is approved by developer';
ALTER TABLE school_info ADD COLUMN IF NOT EXISTS trial_start_date DATETIME NULL COMMENT 'Trial period start date';
ALTER TABLE school_info ADD COLUMN IF NOT EXISTS trial_end_date DATETIME NULL COMMENT 'Trial period end date (3 days from start)';
ALTER TABLE school_info ADD COLUMN IF NOT EXISTS is_paid TINYINT(1) DEFAULT 0 COMMENT 'Whether school has paid for forever use';
ALTER TABLE school_info ADD COLUMN IF NOT EXISTS registered_by_admin VARCHAR(255) NULL COMMENT 'Admin who registered the school';
ALTER TABLE school_info ADD COLUMN IF NOT EXISTS registration_date DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'When school was registered';

-- Update existing schools to be approved and paid
UPDATE school_info 
SET is_approved = 1, 
    is_paid = 1,
    trial_start_date = NOW(),
    trial_end_date = DATE_ADD(NOW(), INTERVAL 10 YEAR),
    registration_date = COALESCE(created_at, NOW())
WHERE is_approved = 0;
