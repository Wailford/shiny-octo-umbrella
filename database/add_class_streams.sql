-- Add Class Streams Support
-- This allows schools to have multiple streams (A, B, C, D) for each class
-- Example: Basic 8 A, Basic 8 B, Basic 8 C, Basic 8 D

-- Add stream column to classes table
ALTER TABLE classes ADD COLUMN IF NOT EXISTS stream VARCHAR(10) DEFAULT NULL COMMENT 'Stream identifier: A, B, C, D, etc.';

-- Add index for better query performance
ALTER TABLE classes ADD INDEX IF NOT EXISTS idx_class_stream (class_name, stream, school_id);

-- Update class_code to include stream for uniqueness
-- Note: Existing classes without streams will have NULL stream value
-- They will be treated as single-stream classes

-- Examples of how data will look:
-- class_name: "Basic Eight", stream: "A" = Displayed as "Basic Eight A"
-- class_name: "Basic Eight", stream: "B" = Displayed as "Basic Eight B"
-- class_name: "Basic Eight", stream: NULL = Displayed as "Basic Eight" (legacy/single stream)

-- No data migration needed - existing classes work as single-stream classes
