-- Fix scores table to calculate scaled totals correctly
USE school_management_system;

-- Drop the existing generated columns
ALTER TABLE scores 
DROP COLUMN grade,
DROP COLUMN remarks,
DROP COLUMN total_score;

-- Recreate total_score with correct scaling: (class_score/60)*50 + (exam_score/100)*50
ALTER TABLE scores 
ADD COLUMN total_score DECIMAL(5,2) GENERATED ALWAYS AS (
    ROUND((COALESCE(`class_score`, 0) / 60) * 50, 0) + 
    ROUND((COALESCE(`exam_score`, 0) / 100) * 50, 0)
) STORED;

-- Recreate grade based on scaled total_score
ALTER TABLE scores 
ADD COLUMN grade VARCHAR(20) GENERATED ALWAYS AS (
    CASE 
        WHEN (ROUND((COALESCE(`class_score`, 0) / 60) * 50, 0) + ROUND((COALESCE(`exam_score`, 0) / 100) * 50, 0)) >= 80 THEN '1-Highest'
        WHEN (ROUND((COALESCE(`class_score`, 0) / 60) * 50, 0) + ROUND((COALESCE(`exam_score`, 0) / 100) * 50, 0)) >= 75 THEN '2-Higher'
        WHEN (ROUND((COALESCE(`class_score`, 0) / 60) * 50, 0) + ROUND((COALESCE(`exam_score`, 0) / 100) * 50, 0)) >= 70 THEN '3-High'
        WHEN (ROUND((COALESCE(`class_score`, 0) / 60) * 50, 0) + ROUND((COALESCE(`exam_score`, 0) / 100) * 50, 0)) >= 65 THEN '4-High Average'
        WHEN (ROUND((COALESCE(`class_score`, 0) / 60) * 50, 0) + ROUND((COALESCE(`exam_score`, 0) / 100) * 50, 0)) >= 60 THEN '5-Average'
        WHEN (ROUND((COALESCE(`class_score`, 0) / 60) * 50, 0) + ROUND((COALESCE(`exam_score`, 0) / 100) * 50, 0)) >= 55 THEN '6-Low Average'
        WHEN (ROUND((COALESCE(`class_score`, 0) / 60) * 50, 0) + ROUND((COALESCE(`exam_score`, 0) / 100) * 50, 0)) >= 50 THEN '7-Low'
        WHEN (ROUND((COALESCE(`class_score`, 0) / 60) * 50, 0) + ROUND((COALESCE(`exam_score`, 0) / 100) * 50, 0)) >= 40 THEN '8-Lower'
        ELSE '9-Lowest'
    END
) STORED;

-- Recreate remarks based on scaled total_score
ALTER TABLE scores 
ADD COLUMN remarks VARCHAR(100) GENERATED ALWAYS AS (
    CASE 
        WHEN (ROUND((COALESCE(`class_score`, 0) / 60) * 50, 0) + ROUND((COALESCE(`exam_score`, 0) / 100) * 50, 0)) >= 80 THEN 'Highest'
        WHEN (ROUND((COALESCE(`class_score`, 0) / 60) * 50, 0) + ROUND((COALESCE(`exam_score`, 0) / 100) * 50, 0)) >= 75 THEN 'Higher'
        WHEN (ROUND((COALESCE(`class_score`, 0) / 60) * 50, 0) + ROUND((COALESCE(`exam_score`, 0) / 100) * 50, 0)) >= 70 THEN 'High'
        WHEN (ROUND((COALESCE(`class_score`, 0) / 60) * 50, 0) + ROUND((COALESCE(`exam_score`, 0) / 100) * 50, 0)) >= 65 THEN 'High Average'
        WHEN (ROUND((COALESCE(`class_score`, 0) / 60) * 50, 0) + ROUND((COALESCE(`exam_score`, 0) / 100) * 50, 0)) >= 60 THEN 'Average'
        WHEN (ROUND((COALESCE(`class_score`, 0) / 60) * 50, 0) + ROUND((COALESCE(`exam_score`, 0) / 100) * 50, 0)) >= 55 THEN 'Low Average'
        WHEN (ROUND((COALESCE(`class_score`, 0) / 60) * 50, 0) + ROUND((COALESCE(`exam_score`, 0) / 100) * 50, 0)) >= 50 THEN 'Low'
        WHEN (ROUND((COALESCE(`class_score`, 0) / 60) * 50, 0) + ROUND((COALESCE(`exam_score`, 0) / 100) * 50, 0)) >= 40 THEN 'Lower'
        ELSE 'Lowest'
    END
) STORED;
