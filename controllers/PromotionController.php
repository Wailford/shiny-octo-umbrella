<?php
require_once __DIR__ . '/../config/database.php';

class PromotionController {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * Check if promotions are allowed (only in Third Term)
     */
    public function canPromote() {
        $school_id = $_SESSION['school_id'] ?? null;
        if (!$school_id) {
            return false;
        }

        $stmt = $this->db->prepare("SELECT current_term FROM school_info WHERE id = ?");
        $stmt->execute([$school_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result && $result['current_term'] === 'Third Term';
    }
    
    /**
     * Get next class for promotion
     */
    public function getNextClass($currentClassId) {
        $school_id = $_SESSION['school_id'] ?? null;
        if (!$school_id) return null;

        $stmt = $this->db->prepare("SELECT * FROM classes WHERE id = ? AND school_id = ?");
        $stmt->execute([$currentClassId, $school_id]);
        $currentClass = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$currentClass) return null;
        
        // Define promotion paths for both Basic and JHS
        $promotionMap = [
            // Basic School
            'Basic One' => 'Basic Two',
            'Basic Two' => 'Basic Three',
            'Basic Three' => 'Basic Four',
            'Basic Four' => 'Basic Five',
            'Basic Five' => 'Basic Six',
            'Basic Six' => 'Basic Seven',
            'Basic Seven' => 'Basic Eight',
            'Basic Eight' => 'Basic Nine',
            'Basic Nine' => 'JHS One',
            // Junior High School
            'JHS One' => 'JHS Two',
            'JHS Two' => 'JHS Three',
            'JHS Three' => null // Graduated
        ];
        
        $nextClassName = $promotionMap[$currentClass['class_name']] ?? null;
        
        if (!$nextClassName) return null;
        
        $stmt = $this->db->prepare("SELECT * FROM classes WHERE class_name = ? AND school_id = ?");
        $stmt->execute([$nextClassName, $school_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Promote a single student
     */
    public function promoteStudent($studentId, $toClassId, $userId) {
        try {
            // Verify student belongs to the user's school
            $school_id = $_SESSION['school_id'] ?? null;
            if (!$school_id) {
                return ['success' => false, 'error' => 'School ID not found'];
            }
            
            // Verify both student and target class belong to this school
            $verifyStmt = $this->db->prepare("
                SELECT s.id 
                FROM students s
                JOIN classes c ON s.class_id = c.id
                WHERE s.id = ? AND c.school_id = ?
            ");
            $verifyStmt->execute([$studentId, $school_id]);
            
            if (!$verifyStmt->fetch()) {
                return ['success' => false, 'error' => 'Unauthorized: Student does not belong to your school'];
            }
            
            $verifyClassStmt = $this->db->prepare("SELECT id, class_name FROM classes WHERE id = ? AND school_id = ?");
            $verifyClassStmt->execute([$toClassId, $school_id]);
            $targetClass = $verifyClassStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$targetClass) {
                return ['success' => false, 'error' => 'Unauthorized: Target class does not belong to your school'];
            }
            
            // Check if this is a graduation (target class is a graduation class)
            $graduationClasses = ['Graduated', 'Alumni', 'JHS Three']; // JHS Three is final year
            $isGraduation = in_array($targetClass['class_name'], $graduationClasses);
            
            $this->db->beginTransaction();
            
            // Get current class
            $stmt = $this->db->prepare("SELECT class_id FROM students WHERE id = ?");
            $stmt->execute([$studentId]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);
            $fromClassId = $student['class_id'];
            
            // Archive current scores
            $this->archiveStudentScores($studentId);
            
            // Delete current scores
            $stmt = $this->db->prepare("DELETE FROM scores WHERE student_id = ?");
            $stmt->execute([$studentId]);
            
            // Update student class and promotion status
            if ($isGraduation) {
                // Mark as graduated
                $stmt = $this->db->prepare("
                    UPDATE students 
                    SET class_id = ?, promoted = 'Graduated', promoted_to_class = ?, 
                        graduation_date = CURDATE()
                    WHERE id = ?
                ");
                $stmt->execute([$toClassId, $targetClass['class_name'], $studentId]);
            } else {
                // Regular promotion
                $stmt = $this->db->prepare("
                    UPDATE students 
                    SET class_id = ?, promoted = 'Yes', promoted_to_class = (SELECT class_name FROM classes WHERE id = ?) 
                    WHERE id = ?
                ");
                $stmt->execute([$toClassId, $toClassId, $studentId]);
            }
            
            // Record promotion history
            $stmt = $this->db->prepare("
                INSERT INTO promotion_history (student_id, from_class_id, to_class_id, academic_year, promoted_by, is_graduation)
                SELECT ?, ?, ?, academic_year, ?, ? FROM school_info WHERE id = ?
            ");
            $stmt->execute([$studentId, $fromClassId, $toClassId, $userId, $isGraduation ? 1 : 0, $school_id]);
            
            $this->db->commit();
            
            if ($isGraduation) {
                return ['success' => true, 'message' => 'Student graduated successfully'];
            }
            return ['success' => true];
        } catch (Exception $e) {
            $this->db->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Promote entire class
     */
    public function promoteClass($classId, $userId) {
        try {
            $this->db->beginTransaction();
            
            // Get next class
            $nextClass = $this->getNextClass($classId);
            if (!$nextClass) {
                throw new Exception('No next class available. Students may have graduated.');
            }
            
            // Get all students in class - filter by school_id to prevent cross-school promotions
            $schoolId = $_SESSION['school_id'] ?? null;
            
            if ($schoolId !== null) {
                $stmt = $this->db->prepare("SELECT s.id FROM students s JOIN classes c ON s.class_id = c.id WHERE s.class_id = ? AND c.school_id = ?");
                $stmt->execute([$classId, $schoolId]);
            } else {
                $stmt = $this->db->prepare("SELECT id FROM students WHERE class_id = ?");
                $stmt->execute([$classId]);
            }
            $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $promoted = 0;
            foreach ($students as $student) {
                $result = $this->promoteStudent($student['id'], $nextClass['id'], $userId);
                if ($result['success']) $promoted++;
            }
            
            $this->db->commit();
            return ['success' => true, 'promoted' => $promoted, 'total' => count($students)];
        } catch (Exception $e) {
            $this->db->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Archive student scores before deletion
     */
    private function archiveStudentScores($studentId) {
        $school_id = $_SESSION['school_id'] ?? null;
        
        // Get current term and academic year from school_info
        $stmt = $this->db->prepare("SELECT current_term, academic_year FROM school_info WHERE id = ?");
        $stmt->execute([$school_id]);
        $schoolInfo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $currentTerm = $schoolInfo['current_term'] ?? 'Third Term';
        $academicYear = $schoolInfo['academic_year'] ?? date('Y');
        
        $stmt = $this->db->prepare("
            INSERT INTO archived_scores 
            (student_id, subject_id, term, academic_year, test1, group_work, test2, project_work, 
             class_score, exam_score, total_score, grade, position, remarks)
            SELECT 
                s.student_id, s.subject_id, 
                ?,
                ?,
                s.test1, s.group_work, s.test2, s.project_work,
                s.class_score, s.exam_score, s.total_score, s.grade, s.position, s.remarks
            FROM scores s
            WHERE s.student_id = ?
        ");
        $stmt->execute([$currentTerm, $academicYear, $studentId]);
    }
    
    /**
     * Prepare system for new term
     */
    public function prepareNewTerm($exportFirst = true) {
        try {
            // Verify user has a school_id
            $school_id = $_SESSION['school_id'] ?? null;
            if (!$school_id) {
                return ['success' => false, 'error' => 'School ID not found'];
            }
            
            $this->db->beginTransaction();
            
            // Get current term and academic year from school_info
            $stmt = $this->db->prepare("SELECT current_term, academic_year FROM school_info WHERE id = ?");
            $stmt->execute([$school_id]);
            $schoolInfo = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $currentTerm = $schoolInfo['current_term'] ?? 'Third Term';
            $academicYear = $schoolInfo['academic_year'] ?? date('Y');
            
            if ($exportFirst) {
                // Archive all current scores for this school only with proper term/year
                $stmt = $this->db->prepare("
                    INSERT INTO archived_scores 
                    (student_id, subject_id, term, academic_year, test1, group_work, test2, project_work, 
                     class_score, exam_score, total_score, grade, position, remarks)
                    SELECT 
                        sc.student_id, sc.subject_id,
                        ?,
                        ?,
                        sc.test1, sc.group_work, sc.test2, sc.project_work,
                        sc.class_score, sc.exam_score, sc.total_score, sc.grade, sc.position, sc.remarks
                    FROM scores sc
                    JOIN students s ON sc.student_id = s.id
                    JOIN classes c ON s.class_id = c.id
                    WHERE c.school_id = ?
                ");
                $stmt->execute([$currentTerm, $academicYear, $school_id]);
            }
            
            // Delete all current scores for this school only
            $stmt = $this->db->prepare("
                DELETE sc FROM scores sc
                JOIN students s ON sc.student_id = s.id
                JOIN classes c ON s.class_id = c.id
                WHERE c.school_id = ?
            ");
            $stmt->execute([$school_id]);
            
            // Update system settings
            $stmt = $this->db->prepare("
                INSERT INTO system_settings (setting_key, setting_value) 
                VALUES ('last_term_preparation', NOW())
                ON DUPLICATE KEY UPDATE setting_value = NOW()
            ");
            $stmt->execute();
            
            $this->db->commit();
            return ['success' => true];
        } catch (Exception $e) {
            $this->db->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Get promotion history for a student
     */
    public function getStudentPromotionHistory($studentId) {
        $stmt = $this->db->prepare("
            SELECT ph.*, 
                   c1.class_name as from_class,
                   c2.class_name as to_class,
                   u.full_name as promoted_by_name
            FROM promotion_history ph
            LEFT JOIN classes c1 ON ph.from_class_id = c1.id
            LEFT JOIN classes c2 ON ph.to_class_id = c2.id
            LEFT JOIN users u ON ph.promoted_by = u.id
            WHERE ph.student_id = ?
            ORDER BY ph.promoted_date DESC
        ");
        $stmt->execute([$studentId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
