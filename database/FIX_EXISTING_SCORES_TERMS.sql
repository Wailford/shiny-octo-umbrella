-- ========================================
-- FIX EXISTING SCORES - Set Term Values
-- ========================================
-- This script updates all existing scores that have NULL term/academic_year
-- Run this in phpMyAdmin on your hosted database
-- ========================================

-- Step 1: Check current state (DIAGNOSTIC - see results)
SELECT 
    COUNT(*) as total_scores,
    SUM(CASE WHEN term IS NULL THEN 1 ELSE 0 END) as null_term,
    SUM(CASE WHEN academic_year IS NULL THEN 1 ELSE 0 END) as null_year
FROM scores;

-- Step 2: Show what terms currently exist
SELECT 
    term, 
    academic_year, 
    COUNT(*) as count 
FROM scores 
GROUP BY term, academic_year;

-- Step 3: Get the current term setting from school_info
SELECT id, current_term, academic_year 
FROM school_info 
LIMIT 1;

-- ========================================
-- ACTUAL FIX - Update NULL scores
-- ========================================

-- Update all scores with NULL term to 'First Term'
UPDATE scores 
SET term = 'First Term' 
WHERE term IS NULL OR term = '';

-- Update all scores with NULL academic_year to '2024/2025'
UPDATE scores 
SET academic_year = '2024/2025' 
WHERE academic_year IS NULL OR academic_year = '';

-- ========================================
-- VERIFICATION - Check results
-- ========================================

-- Verify all scores now have values
SELECT 
    COUNT(*) as total_scores,
    SUM(CASE WHEN term IS NULL THEN 1 ELSE 0 END) as still_null_term,
    SUM(CASE WHEN academic_year IS NULL THEN 1 ELSE 0 END) as still_null_year
FROM scores;

-- Show the distribution of terms
SELECT 
    term, 
    academic_year, 
    COUNT(*) as count 
FROM scores 
GROUP BY term, academic_year;
