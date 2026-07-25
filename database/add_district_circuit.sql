-- Add district and circuit columns to school_info table
ALTER TABLE school_info 
ADD COLUMN district VARCHAR(255) DEFAULT NULL AFTER address,
ADD COLUMN circuit VARCHAR(255) DEFAULT NULL AFTER district;
