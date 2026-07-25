<?php
require_once __DIR__ . '/../config/database.php';

class ExportController {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * Export all students data to CSV (Excel compatible)
     */
    public function exportAllStudentsToExcel($classId = null) {
        // Get school_id from session
        $school_id = $_SESSION['school_id'] ?? null;
        if (!$school_id) {
            return ['success' => false, 'error' => 'School ID not found in session'];
        }
        
        // Get school info for current school only
        $stmt = $this->db->prepare("SELECT * FROM school_info WHERE id = ?");
        $stmt->execute([$school_id]);
        $schoolInfo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get students query - filter by school
        if ($classId) {
            $stmt = $this->db->prepare("
                SELECT s.*, c.class_name 
                FROM students s
                LEFT JOIN classes c ON s.class_id = c.id
                WHERE s.class_id = ? AND c.school_id = ?
                ORDER BY c.class_name, s.student_name
            ");
            $stmt->execute([$classId, $school_id]);
        } else {
            $stmt = $this->db->prepare("
                SELECT s.*, c.class_name 
                FROM students s
                LEFT JOIN classes c ON s.class_id = c.id
                WHERE c.school_id = ?
                ORDER BY c.class_name, s.student_name
            ");
            $stmt->execute([$school_id]);
        }
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($students)) {
            return ['success' => false, 'error' => 'No students found'];
        }
        
        // Get all subjects
        $stmt = $this->db->query("SELECT * FROM subjects ORDER BY class_id, subject_name");
        $allSubjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Create filename
        $fileName = 'students_export_' . date('Y-m-d_His') . '.csv';
        $filePath = __DIR__ . '/../temp/' . $fileName;
        
        // Ensure temp directory exists
        if (!is_dir(__DIR__ . '/../temp')) {
            mkdir(__DIR__ . '/../temp', 0755, true);
        }
        
        // Open file for writing
        $file = fopen($filePath, 'w');
        
        // Add BOM for Excel UTF-8 compatibility
        fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // Write school header
        fputcsv($file, [$schoolInfo['school_name']]);
        fputcsv($file, ['Students Data Export']);
        fputcsv($file, ['Academic Year: ' . $schoolInfo['academic_year'] . ' | Term: ' . $schoolInfo['current_term']]);
        fputcsv($file, ['Exported: ' . date('Y-m-d H:i:s')]);
        fputcsv($file, []); // Empty row
        
        // Get subjects for first student's class to determine columns
        $firstStudent = $students[0];
        $stmt = $this->db->prepare("SELECT * FROM subjects WHERE class_id = ? ORDER BY subject_name");
        $stmt->execute([$firstStudent['class_id']]);
        $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Write header row
        $headers = ['Student ID', 'Student Name', 'Class', 'Gender', 'Attendance'];
        foreach ($subjects as $subject) {
            $headers[] = $subject['subject_name'] . ' - Score';
            $headers[] = $subject['subject_name'] . ' - Grade';
        }
        $headers = array_merge($headers, ['Total Average', 'Overall Grade', 'Position', 'Conduct', 'Interest', 'Remarks']);
        fputcsv($file, $headers);
        
        // Write student data
        foreach ($students as $student) {
            // Get subjects for this student's class
            $stmt = $this->db->prepare("SELECT * FROM subjects WHERE class_id = ? ORDER BY subject_name");
            $stmt->execute([$student['class_id']]);
            $studentSubjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $row = [
                $student['student_id'],
                $student['student_name'],
                $student['class_name'],
                $student['gender'] ?? '',
                $student['attendance'] ?? ''
            ];
            
            $totalScore = 0;
            $scoreCount = 0;
            
            // Get scores for each subject (current term only)
            foreach ($studentSubjects as $subject) {
                $stmt = $this->db->prepare("
                    SELECT total_score, grade 
                    FROM scores 
                    WHERE student_id = ? AND subject_id = ? 
                    AND term = ? AND academic_year = ?
                ");
                $stmt->execute([$student['id'], $subject['id'], $schoolInfo['current_term'], $schoolInfo['academic_year']]);
                $score = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($score) {
                    $row[] = $score['total_score'];
                    $row[] = $score['grade'];
                    $totalScore += $score['total_score'];
                    $scoreCount++;
                } else {
                    $row[] = '';
                    $row[] = '';
                }
            }
            
            // Calculate average
            $average = $scoreCount > 0 ? round($totalScore / $scoreCount, 2) : 0;
            $row[] = $average;
            
            // Overall grade based on average
            $overallGrade = $this->getGradeFromScore($average);
            $row[] = $overallGrade;
            
            // Get position (simplified)
            $row[] = ''; // Position would need complex calculation
            
            $row[] = $student['conduct'] ?? '';
            $row[] = $student['interest'] ?? '';
            $row[] = $student['form_master_remarks'] ?? $student['headmaster_remarks'] ?? '';
            
            fputcsv($file, $row);
        }
        
        fclose($file);
        
        return ['success' => true, 'file' => $fileName, 'path' => $filePath];
    }
    
    /**
     * Export current term scores
     */
    public function exportTermScores($term = null, $academicYear = null) {
        // Get school_id from session
        $school_id = $_SESSION['school_id'] ?? null;
        if (!$school_id) {
            return ['success' => false, 'error' => 'School ID not found in session'];
        }
        
        $stmt = $this->db->prepare("SELECT * FROM school_info WHERE id = ?");
        $stmt->execute([$school_id]);
        $schoolInfo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $term = $term ?? $schoolInfo['current_term'];
        $academicYear = $academicYear ?? $schoolInfo['academic_year'];
        
        // Create filename
        $fileName = 'term_scores_' . str_replace(' ', '_', $term) . '_' . str_replace('/', '-', $academicYear) . '_' . date('YmdHis') . '.csv';
        $filePath = __DIR__ . '/../temp/' . $fileName;
        
        $file = fopen($filePath, 'w');
        fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // Header
        fputcsv($file, [$schoolInfo['school_name']]);
        fputcsv($file, ['Term Scores Export']);
        fputcsv($file, ['Academic Year: ' . $academicYear . ' | Term: ' . $term]);
        fputcsv($file, ['Exported: ' . date('Y-m-d H:i:s')]);
        fputcsv($file, []);
        
        // Headers
        fputcsv($file, ['Student ID', 'Student Name', 'Class', 'Subject', 'Test 1', 'Group Work', 'Test 2', 'Project Work', 'Class Score', 'Exam Score', 'Total Score', 'Grade', 'Position', 'Remarks']);
        
        // Get all scores - filter by school
        $stmt = $this->db->prepare("
            SELECT 
                st.student_id, st.student_name,
                c.class_name,
                sub.subject_name,
                sc.test1, sc.group_work, sc.test2, sc.project_work,
                sc.class_score, sc.exam_score, sc.total_score,
                sc.grade, sc.position, sc.remarks
            FROM scores sc
            JOIN students st ON sc.student_id = st.id
            JOIN subjects sub ON sc.subject_id = sub.id
            JOIN classes c ON st.class_id = c.id
            WHERE COALESCE(sc.term, ?) = ?
            AND COALESCE(sc.academic_year, ?) = ?
            AND c.school_id = ?
            ORDER BY c.class_name, st.student_name, sub.subject_name
        ");
        $stmt->execute([$term, $term, $academicYear, $academicYear, $school_id]);
        $scores = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($scores as $score) {
            fputcsv($file, [
                $score['student_id'],
                $score['student_name'],
                $score['class_name'],
                $score['subject_name'],
                $score['test1'],
                $score['group_work'],
                $score['test2'],
                $score['project_work'],
                $score['class_score'],
                $score['exam_score'],
                $score['total_score'],
                $score['grade'],
                $score['position'],
                $score['remarks']
            ]);
        }
        
        fclose($file);
        
        return ['success' => true, 'file' => $fileName, 'path' => $filePath];
    }
    
    /**
     * Export and permanently delete old academic year scores to save database space
     * IMPORTANT: This PERMANENTLY deletes scores after backing up to CSV
     * Use this at end of academic year to free up database space
     * 
     * @param string $academicYear - The academic year to delete (e.g., '2024/2025')
     * @param bool $confirmDelete - Must be true to actually delete (safety check)
     * @return array - Result with backup file info and deletion count
     */
    public function exportAndDeleteOldScores($academicYear, $confirmDelete = false) {
        // Get school_id from session
        $school_id = $_SESSION['school_id'] ?? null;
        if (!$school_id) {
            return ['success' => false, 'error' => 'School ID not found in session'];
        }
        
        // Get school info
        $stmt = $this->db->prepare("SELECT * FROM school_info WHERE id = ?");
        $stmt->execute([$school_id]);
        $schoolInfo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Safety check: Don't allow deletion of current academic year
        if ($academicYear === $schoolInfo['academic_year']) {
            return ['success' => false, 'error' => 'Cannot delete current academic year scores. Change to new academic year first.'];
        }
        
        // Check how many scores will be affected
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as score_count
            FROM scores sc
            JOIN students st ON sc.student_id = st.id
            JOIN classes c ON st.class_id = c.id
            WHERE sc.academic_year = ?
            AND c.school_id = ?
        ");
        $stmt->execute([$academicYear, $school_id]);
        $countResult = $stmt->fetch(PDO::FETCH_ASSOC);
        $scoreCount = $countResult['score_count'];
        
        if ($scoreCount == 0) {
            return ['success' => false, 'error' => 'No scores found for academic year: ' . $academicYear];
        }
        
        // STEP 1: Export all scores to CSV backup
        $safeName = preg_replace('/[\/\\\\:*?"<>|]/', '_', $schoolInfo['school_name']);
        $fileName = 'BACKUP_' . str_replace('/', '-', $academicYear) . '_' . $safeName . '_' . date('YmdHis') . '.csv';
        $filePath = __DIR__ . '/../backups/' . $fileName;
        
        // Ensure backups directory exists
        if (!is_dir(__DIR__ . '/../backups')) {
            mkdir(__DIR__ . '/../backups', 0755, true);
        }
        
        $file = fopen($filePath, 'w');
        fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // Header
        fputcsv($file, ['PERMANENT BACKUP - ' . $schoolInfo['school_name']]);
        fputcsv($file, ['Academic Year: ' . $academicYear . ' (ALL TERMS)']);
        fputcsv($file, ['Exported: ' . date('Y-m-d H:i:s')]);
        fputcsv($file, ['Total Scores: ' . $scoreCount]);
        fputcsv($file, ['WARNING: Original scores will be PERMANENTLY DELETED from database']);
        fputcsv($file, []);
        
        // Headers
        fputcsv($file, [
            'Student ID', 'Student Name', 'Class', 'Subject', 'Term',
            'Test 1', 'Group Work', 'Test 2', 'Project Work',
            'Class Score', 'Exam Score', 'Total Score', 'Grade', 'Position', 'Remarks'
        ]);
        
        // Get all scores for this academic year
        $stmt = $this->db->prepare("
            SELECT 
                st.student_id, st.student_name,
                c.class_name,
                sub.subject_name,
                sc.term,
                sc.test1, sc.group_work, sc.test2, sc.project_work,
                sc.class_score, sc.exam_score, sc.total_score,
                sc.grade, sc.position, sc.remarks
            FROM scores sc
            JOIN students st ON sc.student_id = st.id
            JOIN subjects sub ON sc.subject_id = sub.id
            JOIN classes c ON st.class_id = c.id
            WHERE sc.academic_year = ?
            AND c.school_id = ?
            ORDER BY sc.term, c.class_name, st.student_name, sub.subject_name
        ");
        $stmt->execute([$academicYear, $school_id]);
        $scores = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($scores as $score) {
            fputcsv($file, [
                $score['student_id'],
                $score['student_name'],
                $score['class_name'],
                $score['subject_name'],
                $score['term'],
                $score['test1'],
                $score['group_work'],
                $score['test2'],
                $score['project_work'],
                $score['class_score'],
                $score['exam_score'],
                $score['total_score'],
                $score['grade'],
                $score['position'],
                $score['remarks']
            ]);
        }
        
        fclose($file);
        
        $result = [
            'success' => true,
            'backup_file' => $fileName,
            'backup_path' => $filePath,
            'score_count' => $scoreCount,
            'academic_year' => $academicYear,
            'deleted' => false
        ];
        
        // STEP 2: Permanently delete if confirmed
        if ($confirmDelete === true) {
            try {
                $this->db->beginTransaction();
                
                // Delete scores for this academic year (school-filtered)
                $stmt = $this->db->prepare("
                    DELETE sc FROM scores sc
                    JOIN students st ON sc.student_id = st.id
                    JOIN classes c ON st.class_id = c.id
                    WHERE sc.academic_year = ?
                    AND c.school_id = ?
                ");
                $stmt->execute([$academicYear, $school_id]);
                $deletedCount = $stmt->rowCount();
                
                $this->db->commit();
                
                $result['deleted'] = true;
                $result['deleted_count'] = $deletedCount;
                $result['message'] = "Successfully backed up and deleted {$deletedCount} scores from {$academicYear}";
                
            } catch (Exception $e) {
                $this->db->rollBack();
                $result['success'] = false;
                $result['error'] = 'Deletion failed: ' . $e->getMessage();
            }
        } else {
            $result['message'] = "Backup created with {$scoreCount} scores. Set confirmDelete=true to permanently delete.";
        }
        
        return $result;
    }
    
    /**
     * Get list of old academic years that can be deleted
     */
    public function getOldAcademicYears() {
        $school_id = $_SESSION['school_id'] ?? null;
        if (!$school_id) {
            return ['success' => false, 'error' => 'School ID not found in session'];
        }
        
        // Get current academic year
        $stmt = $this->db->prepare("SELECT academic_year FROM school_info WHERE id = ?");
        $stmt->execute([$school_id]);
        $schoolInfo = $stmt->fetch(PDO::FETCH_ASSOC);
        $currentYear = $schoolInfo['academic_year'];
        
        // Get all academic years with scores (excluding current)
        $stmt = $this->db->prepare("
            SELECT 
                sc.academic_year,
                COUNT(*) as score_count,
                COUNT(DISTINCT sc.term) as term_count
            FROM scores sc
            JOIN students st ON sc.student_id = st.id
            JOIN classes c ON st.class_id = c.id
            WHERE c.school_id = ?
            AND sc.academic_year != ?
            GROUP BY sc.academic_year
            ORDER BY sc.academic_year DESC
        ");
        $stmt->execute([$school_id, $currentYear]);
        $oldYears = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'success' => true,
            'current_year' => $currentYear,
            'old_years' => $oldYears
        ];
    }
    
    private function getGradeFromScore($score) {
        if ($score >= 80) return '1-Highest';
        if ($score >= 75) return '2-Higher';
        if ($score >= 70) return '3-High';
        if ($score >= 65) return '4-High Average';
        if ($score >= 60) return '5-Average';
        if ($score >= 55) return '6-Low Average';
        if ($score >= 50) return '7-Low';
        if ($score >= 40) return '8-Lower';
        return '9-Lowest';
    }
}
