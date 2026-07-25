-- Add grading system configuration to mock_exam_settings
-- This allows schools to configure Mock exam grading separately from regular grading

ALTER TABLE mock_exam_settings 
ADD COLUMN use_custom_grading TINYINT(1) DEFAULT 1 COMMENT 'Use BECE grading (1-9) for mock exams',
ADD COLUMN grading_config JSON NULL COMMENT 'Custom grading configuration for mock exams';

-- Update existing settings to use BECE grading by default
UPDATE mock_exam_settings 
SET use_custom_grading = 1,
    grading_config = JSON_OBJECT(
        'system', 'BECE',
        'scale', '1-9',
        'grades', JSON_ARRAY(
            JSON_OBJECT('grade', 1, 'min', 90, 'max', 100, 'label', 'Highest'),
            JSON_OBJECT('grade', 2, 'min', 80, 'max', 89, 'label', 'Higher'),
            JSON_OBJECT('grade', 3, 'min', 70, 'max', 79, 'label', 'High'),
            JSON_OBJECT('grade', 4, 'min', 60, 'max', 69, 'label', 'High Average'),
            JSON_OBJECT('grade', 5, 'min', 55, 'max', 59, 'label', 'Average'),
            JSON_OBJECT('grade', 6, 'min', 50, 'max', 54, 'label', 'Low Average'),
            JSON_OBJECT('grade', 7, 'min', 40, 'max', 49, 'label', 'Low'),
            JSON_OBJECT('grade', 8, 'min', 35, 'max', 39, 'label', 'Lower'),
            JSON_OBJECT('grade', 9, 'min', 0, 'max', 34, 'label', 'Lowest')
        ),
        'aggregate_system', JSON_OBJECT(
            'method', '4_core_plus_2_best',
            'core_subjects', JSON_ARRAY('English Language', 'Mathematics', 'Integrated Science', 'Social Studies'),
            'ranges', JSON_ARRAY(
                JSON_OBJECT('min', 6, 'max', 12, 'category', 'Highest'),
                JSON_OBJECT('min', 13, 'max', 24, 'category', 'Higher'),
                JSON_OBJECT('min', 25, 'max', 36, 'category', 'High'),
                JSON_OBJECT('min', 37, 'max', 48, 'category', 'Low')
            )
        )
    )
WHERE use_custom_grading IS NULL OR use_custom_grading = 1;
