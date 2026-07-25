-- ========================================
-- STEP-BY-STEP INSTRUCTIONS
-- ========================================
-- Copy and paste ONE command at a time into phpMyAdmin
-- If a command gives an error, read the note and continue to the next
-- ========================================

-- STEP 1A: Add term column
-- ▶ Copy and run this:
ALTER TABLE `scores` ADD COLUMN `term` VARCHAR(50) NOT NULL DEFAULT 'First Term' AFTER `subject_id`;
-- ⚠️ If error "Duplicate column 'term'" - that's OK! Continue to Step 1B

-- STEP 1B: Add academic_year column  
-- ▶ Copy and run this:
ALTER TABLE `scores` ADD COLUMN `academic_year` VARCHAR(20) NOT NULL DEFAULT '2024/2025' AFTER `term`;
-- ⚠️ If error "Duplicate column 'academic_year'" - that's OK! Continue to Step 2

-- STEP 2: Update empty values
-- ▶ Copy and run this:
UPDATE `scores` sc LEFT JOIN `students` st ON sc.student_id = st.id LEFT JOIN `classes` c ON st.class_id = c.id LEFT JOIN `school_info` si ON c.school_id = si.id SET sc.term = COALESCE(NULLIF(sc.term, ''), si.current_term, 'First Term'), sc.academic_year = COALESCE(NULLIF(sc.academic_year, ''), si.academic_year, '2024/2025') WHERE sc.term = '' OR sc.academic_year = '';

-- STEP 3: Drop first foreign key
-- ▶ Copy and run this:
ALTER TABLE `scores` DROP FOREIGN KEY `scores_ibfk_1`;

-- STEP 4: Drop second foreign key
-- ▶ Copy and run this:
ALTER TABLE `scores` DROP FOREIGN KEY `scores_ibfk_2`;

-- STEP 5: Drop old unique index (THE KEY STEP!)
-- ▶ Copy and run this:
ALTER TABLE `scores` DROP INDEX `student_subject`;

-- STEP 6: Add new unique constraint with term and year
-- ▶ Copy and run this:
ALTER TABLE `scores` ADD UNIQUE KEY `student_subject_term_year` (`student_id`, `subject_id`, `term`, `academic_year`);

-- STEP 7: Recreate first foreign key
-- ▶ Copy and run this:
ALTER TABLE `scores` ADD CONSTRAINT `scores_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE;

-- STEP 8: Recreate second foreign key
-- ▶ Copy and run this:
ALTER TABLE `scores` ADD CONSTRAINT `scores_ibfk_2` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE;

-- STEP 9: Add performance index
-- ▶ Copy and run this:
ALTER TABLE `scores` ADD INDEX `idx_term_year` (`term`, `academic_year`);

-- STEP 10: Verify (optional)
-- ▶ Copy and run this:
SELECT 'Migration completed!' AS status, COUNT(*) as total_scores FROM `scores`;

-- ========================================
-- ALL DONE! 
-- Now try entering scores for Computing - it should work!
-- ========================================
