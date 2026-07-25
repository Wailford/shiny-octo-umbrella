<?php
/**
 * Score Controller
 * 
 * Handles student scores and assessments
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

class ScoreController {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * Get scores for a student in a subject
     * 
     * @param int $studentId Student ID
     * @param int $subjectId Subject ID
     * @return array|null Score data
     */
    public function getScore($studentId, $subjectId) {
        try {
            $schoolId = $_SESSION['school_id'] ?? null;
            
            // Get current term and academic year from school_info
            $currentTerm = 'First Term';
            $currentYear = '2024/2025';
            if ($schoolId) {
                $schoolStmt = $this->db->prepare("SELECT current_term, academic_year FROM school_info WHERE id = ?");
                $schoolStmt->execute([$schoolId]);
                $schoolData = $schoolStmt->fetch(PDO::FETCH_ASSOC);
                if ($schoolData) {
                    $currentTerm = $schoolData['current_term'] ?? 'First Term';
                    $currentYear = $schoolData['academic_year'] ?? '2024/2025';
                }
            }
            
            $sql = "SELECT sc.* FROM scores sc
                    JOIN students st ON sc.student_id = st.id
                    JOIN classes c ON st.class_id = c.id
                    JOIN subjects sub ON sc.subject_id = sub.id
                    JOIN classes csub ON sub.class_id = csub.id
                    WHERE sc.student_id = ? AND sc.subject_id = ? 
                    AND sc.term = ? AND sc.academic_year = ?";
            
            // Filter both the student's class AND the subject's class by school_id
            // to prevent cross-school score leakage even if subject_id is tampered
            if ($schoolId !== null) {
                $sql .= " AND c.school_id = ? AND csub.school_id = ?";
                $stmt = $this->db->prepare($sql);
                $stmt->execute([$studentId, $subjectId, $currentTerm, $currentYear, $schoolId, $schoolId]);
            } else {
                $stmt = $this->db->prepare($sql);
                $stmt->execute([$studentId, $subjectId, $currentTerm, $currentYear]);
            }
            
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Get score error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get all scores for a student
     * 
     * @param int $studentId Student ID
     * @return array List of scores
     */
    public function getStudentScores($studentId) {
        try {
            $schoolId = $_SESSION['school_id'] ?? null;
            
            // Get current term and academic year from school_info
            $currentTerm = 'First Term';
            $currentYear = '2024/2025';
            if ($schoolId) {
                $schoolStmt = $this->db->prepare("SELECT current_term, academic_year FROM school_info WHERE id = ?");
                $schoolStmt->execute([$schoolId]);
                $schoolData = $schoolStmt->fetch(PDO::FETCH_ASSOC);
                if ($schoolData) {
                    $currentTerm = $schoolData['current_term'] ?? 'First Term';
                    $currentYear = $schoolData['academic_year'] ?? '2024/2025';
                }
            }
            
            $sql = "SELECT sc.*, sub.subject_name, sub.subject_code 
                    FROM scores sc
                    JOIN subjects sub ON sc.subject_id = sub.id
                    JOIN classes c ON sub.class_id = c.id
                    WHERE sc.student_id = ? AND sc.term = ? AND sc.academic_year = ?";
            
            if ($schoolId !== null) {
                $sql .= " AND c.school_id = ?";
            }
            $sql .= " ORDER BY sub.subject_name";
            
            $stmt = $this->db->prepare($sql);
            if ($schoolId !== null) {
                $stmt->execute([$studentId, $currentTerm, $currentYear, $schoolId]);
            } else {
                $stmt->execute([$studentId, $currentTerm, $currentYear]);
            }
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Get student scores error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get all scores for a subject
     * 
     * @param int $subjectId Subject ID
     * @return array List of scores with student info
     */
    public function getSubjectScores($subjectId) {
        try {
            $schoolId = $_SESSION['school_id'] ?? null;
            
            // Get current term and academic year from school_info
            $currentTerm = 'First Term';
            $currentYear = '2024/2025';
            if ($schoolId) {
                $schoolStmt = $this->db->prepare("SELECT current_term, academic_year FROM school_info WHERE id = ?");
                $schoolStmt->execute([$schoolId]);
                $schoolData = $schoolStmt->fetch(PDO::FETCH_ASSOC);
                if ($schoolData) {
                    $currentTerm = $schoolData['current_term'] ?? 'First Term';
                    $currentYear = $schoolData['academic_year'] ?? '2024/2025';
                }
            }
            
            $sql = "SELECT sc.*, st.student_name, st.student_id as student_number 
                    FROM scores sc
                    JOIN students st ON sc.student_id = st.id
                    JOIN classes c ON st.class_id = c.id
                    WHERE sc.subject_id = ? AND sc.term = ? AND sc.academic_year = ?";
            
            if ($schoolId !== null) {
                $sql .= " AND c.school_id = ?";
            }
            $sql .= " ORDER BY sc.total_score DESC, st.student_name";
            
            $stmt = $this->db->prepare($sql);
            if ($schoolId !== null) {
                $stmt->execute([$subjectId, $currentTerm, $currentYear, $schoolId]);
            } else {
                $stmt->execute([$subjectId, $currentTerm, $currentYear]);
            }
            $scores = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Calculate positions
            return $this->calculatePositions($scores);
        } catch (Exception $e) {
            error_log("Get subject scores error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Submit or update scores
     * 
     * @param array $data Score data
     * @return array Response
     */
    public function submitScore($data) {
        try {
            $test1       = floatval($data['test1']        ?? 0);
            $groupWork   = floatval($data['group_work']   ?? 0);
            $test2       = floatval($data['test2']        ?? 0);
            $projectWork = floatval($data['project_work'] ?? 0);
            $examScore   = floatval($data['exam_score']   ?? 0);

            $schoolId    = $_SESSION['school_id'] ?? null;
            $currentTerm = 'First Term';
            $currentYear = '2024/2025';
            if ($schoolId) {
                $schoolStmt = $this->db->prepare(
                    "SELECT current_term, academic_year FROM school_info WHERE id = ?"
                );
                $schoolStmt->execute([$schoolId]);
                $schoolData = $schoolStmt->fetch(PDO::FETCH_ASSOC);
                if ($schoolData) {
                    $currentTerm = $schoolData['current_term'] ?? 'First Term';
                    $currentYear = $schoolData['academic_year'] ?? '2024/2025';
                }
            }

            // Single atomic upsert — eliminates the SELECT-then-INSERT race condition
            $sql = "INSERT INTO scores
                        (student_id, subject_id, term, academic_year,
                         test1, group_work, test2, project_work, exam_score)
                    VALUES
                        (:student_id, :subject_id, :term, :academic_year,
                         :test1, :group_work, :test2, :project_work, :exam_score)
                    ON DUPLICATE KEY UPDATE
                        test1        = COALESCE(VALUES(test1),        test1),
                        group_work   = COALESCE(VALUES(group_work),   group_work),
                        test2        = COALESCE(VALUES(test2),        test2),
                        project_work = COALESCE(VALUES(project_work), project_work),
                        exam_score   = COALESCE(VALUES(exam_score),   exam_score),
                        updated_at   = NOW()";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':student_id'    => $data['student_id'],
                ':subject_id'    => $data['subject_id'],
                ':term'          => $currentTerm,
                ':academic_year' => $currentYear,
                ':test1'         => $test1,
                ':group_work'    => $groupWork,
                ':test2'         => $test2,
                ':project_work'  => $projectWork,
                ':exam_score'    => $examScore,
            ]);

            $this->updateSubjectPositions($data['subject_id']);

            return ['success' => true, 'message' => 'Score saved successfully'];
        } catch (Exception $e) {
            error_log("Submit score error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to submit score'];
        }
    }
    
    /**
     * Delete all scores for a subject
     * 
     * @param int $subjectId Subject ID
     * @return array Response
     */
    public function deleteSubjectScores($subjectId) {
        try {
            // Verify subject belongs to the user's school
            $school_id = $_SESSION['school_id'] ?? null;
            if (!$school_id) {
                return ['success' => false, 'error' => 'School ID not found'];
            }
            
            $verifyStmt = $this->db->prepare("
                SELECT s.id 
                FROM subjects s
                JOIN classes c ON s.class_id = c.id
                WHERE s.id = ? AND c.school_id = ?
            ");
            $verifyStmt->execute([$subjectId, $school_id]);
            
            if (!$verifyStmt->fetch()) {
                return ['success' => false, 'error' => 'Unauthorized: Subject does not belong to your school'];
            }
            
            $sql = "DELETE FROM scores WHERE subject_id = :subject_id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':subject_id' => $subjectId]);
            
            return ['success' => true, 'message' => 'All scores deleted successfully'];
        } catch (Exception $e) {
            error_log("Delete scores error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to delete scores'];
        }
    }
    
    /**
     * Calculate grade and remarks based on total score
     * 
     * @param float $totalScore Total score
     * @return array Grade data with 'grade' and 'remarks'
     */
    private function calculateGrade($totalScore) {
        // Get grading system from config
        $gradingSystem = GRADING_SYSTEM;
        
        foreach ($gradingSystem as $gradeLevel) {
            if ($totalScore >= $gradeLevel['min'] && $totalScore <= $gradeLevel['max']) {
                return [
                    'grade' => $gradeLevel['grade'],
                    'remarks' => $gradeLevel['remarks']
                ];
            }
        }
        
        // Default to lowest grade if no match
        return [
            'grade' => '9-Lowest',
            'remarks' => 'Poor'
        ];
    }
    
    /**
     * Calculate and update positions for a subject
     * 
     * @param int $subjectId Subject ID
     */
    private function updateSubjectPositions($subjectId) {
        try {
            // Get all scores for the subject, ordered by total_score descending
            $scores = $this->getSubjectScores($subjectId);
            
            $position = 1;
            $previousScore = null;
            $samePositionCount = 0;
            
            foreach ($scores as $score) {
                // Handle ties
                if ($previousScore !== null && $score['total_score'] == $previousScore) {
                    $samePositionCount++;
                } else {
                    $position += $samePositionCount;
                    $samePositionCount = 0;
                    if ($previousScore !== null) {
                        $position++;
                    }
                }
                
                // Update position
                $sql = "UPDATE scores SET position = :position WHERE id = :id";
                $stmt = $this->db->prepare($sql);
                $stmt->execute([
                    ':position' => $position,
                    ':id' => $score['id']
                ]);
                
                $previousScore = $score['total_score'];
            }
        } catch (Exception $e) {
            error_log("Update positions error: " . $e->getMessage());
        }
    }
    
    /**
     * Calculate positions for scores array
     * 
     * @param array $scores Array of scores
     * @return array Scores with positions
     */
    private function calculatePositions($scores) {
        $position = 1;
        $previousScore = null;
        $samePositionCount = 0;
        
        foreach ($scores as &$score) {
            if ($previousScore !== null && $score['total_score'] == $previousScore) {
                $score['calculated_position'] = $position;
                $samePositionCount++;
            } else {
                $position += $samePositionCount;
                $samePositionCount = 0;
                if ($previousScore !== null) {
                    $position++;
                }
                $score['calculated_position'] = $position;
            }
            
            $previousScore = $score['total_score'];
        }
        
        return $scores;
    }
    
    // formatPosition() is now in GradingSystem::formatPosition() — see helpers/GradingSystem.php
}
