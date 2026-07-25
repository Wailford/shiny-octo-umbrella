<?php
/**
 * Score Entry Controller
 * 
 * Handles score entry, bulk uploads, and score management
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

class ScoreEntryController {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * Get students for a class
     * 
     * @param int $classId Class ID
     * @return array List of students
     */
    public function getAllStudentsByClass($classId) {
        try {
            $stmt = $this->db->prepare("
                SELECT id, student_id, student_name, gender, candidate_index_number
                FROM students
                WHERE class_id = ?
                ORDER BY 
                    CASE WHEN candidate_index_number IS NOT NULL AND candidate_index_number != '' 
                    THEN CAST(candidate_index_number AS UNSIGNED) ELSE 999999999999 END,
                    student_name
            ");
            $stmt->execute([$classId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Get students error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get existing scores for a student in a subject
     * 
     * @param int $studentId Student ID
     * @param int $subjectId Subject ID
     * @return array|null Existing scores
     */
    public function getExistingScores($studentId, $subjectId) {
        try {
            $stmt = $this->db->prepare("
                SELECT test1, group_work, test2, project_work, exam_score,
                       class_score, total_score, grade, remarks, position
                FROM scores
                WHERE student_id = ? AND subject_id = ?
            ");
            $stmt->execute([$studentId, $subjectId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Get existing scores error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Submit or update scores for a student
     * 
     * @param array $data Score data
     * @return array Response
     */
    public function submitScores($data) {
        try {
            $studentId = $data['student_id'];
            $subjectId = $data['subject_id'];
            
            // Check if scores already exist
            $existing = $this->getExistingScores($studentId, $subjectId);
            
            $test1 = isset($data['test1']) && $data['test1'] !== '' ? $data['test1'] : null;
            $groupWork = isset($data['group_work']) && $data['group_work'] !== '' ? $data['group_work'] : null;
            $test2 = isset($data['test2']) && $data['test2'] !== '' ? $data['test2'] : null;
            $projectWork = isset($data['project_work']) && $data['project_work'] !== '' ? $data['project_work'] : null;
            $examScore = isset($data['exam_score']) && $data['exam_score'] !== '' ? $data['exam_score'] : null;
            
            if ($existing) {
                // Update existing scores
                $sql = "UPDATE scores SET 
                        test1 = COALESCE(?, test1),
                        group_work = COALESCE(?, group_work),
                        test2 = COALESCE(?, test2),
                        project_work = COALESCE(?, project_work),
                        exam_score = COALESCE(?, exam_score),
                        updated_at = NOW()
                        WHERE student_id = ? AND subject_id = ?";
                
                $stmt = $this->db->prepare($sql);
                $stmt->execute([
                    $test1, $groupWork, $test2, $projectWork, $examScore,
                    $studentId, $subjectId
                ]);
            } else {
                // Insert new scores
                $sql = "INSERT INTO scores 
                        (student_id, subject_id, test1, group_work, test2, project_work, exam_score)
                        VALUES (?, ?, ?, ?, ?, ?, ?)";
                
                $stmt = $this->db->prepare($sql);
                $stmt->execute([
                    $studentId, $subjectId, $test1, $groupWork, $test2, $projectWork, $examScore
                ]);
            }
            
            // Update positions for this subject
            $this->updatePositions($subjectId);
            
            return ['success' => true, 'message' => 'Scores saved successfully'];
        } catch (Exception $e) {
            error_log("Submit scores error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to save scores: ' . $e->getMessage()];
        }
    }
    
    /**
     * Submit scores for all students in a class
     * 
     * @param array $scoresData Array of score data for multiple students
     * @return array Response
     */
    public function submitAllScores($scoresData) {
        try {
            $this->db->beginTransaction();
            
            $successCount = 0;
            $errors = [];
            
            foreach ($scoresData as $data) {
                $result = $this->submitScores($data);
                if ($result['success']) {
                    $successCount++;
                } else {
                    $errors[] = $result['error'];
                }
            }
            
            $this->db->commit();
            
            return [
                'success' => true,
                'message' => "Successfully saved scores for {$successCount} students",
                'success_count' => $successCount,
                'errors' => $errors
            ];
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("Submit all scores error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to save scores: ' . $e->getMessage()];
        }
    }
    
    /**
     * Update positions for a subject based on total scores
     * 
     * @param int $subjectId Subject ID
     */
    private function updatePositions($subjectId) {
        try {
            // Get all scores for this subject ordered by total_score descending
            $stmt = $this->db->prepare("
                SELECT id, total_score
                FROM scores
                WHERE subject_id = ?
                ORDER BY total_score DESC, id ASC
            ");
            $stmt->execute([$subjectId]);
            $scores = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $position = 1;
            $previousScore = null;
            $sameScoreCount = 0;
            
            foreach ($scores as $score) {
                if ($previousScore !== null && $score['total_score'] == $previousScore) {
                    // Same score as previous, give same position
                    $sameScoreCount++;
                } else {
                    // Different score, update position
                    $position += $sameScoreCount;
                    $sameScoreCount = 0;
                    $position = ($previousScore === null) ? 1 : $position + 1;
                }
                
                if ($previousScore === null) {
                    $position = 1;
                }
                
                // Update position
                $updateStmt = $this->db->prepare("UPDATE scores SET position = ? WHERE id = ?");
                $updateStmt->execute([$position, $score['id']]);
                
                $previousScore = $score['total_score'];
            }
        } catch (Exception $e) {
            error_log("Update positions error: " . $e->getMessage());
        }
    }
    
    /**
     * Delete all scores for a class and subject
     * 
     * @param int $classId Class ID
     * @param int $subjectId Subject ID
     * @return array Response
     */
    public function deleteAllScores($classId, $subjectId) {
        try {
            // Verify class belongs to the user's school
            $school_id = $_SESSION['school_id'] ?? null;
            if (!$school_id) {
                return ['success' => false, 'error' => 'School ID not found'];
            }
            
            $verifyStmt = $this->db->prepare("SELECT id FROM classes WHERE id = ? AND school_id = ?");
            $verifyStmt->execute([$classId, $school_id]);
            
            if (!$verifyStmt->fetch()) {
                return ['success' => false, 'error' => 'Unauthorized: Class does not belong to your school'];
            }
            
            $sql = "DELETE s FROM scores s
                    JOIN students st ON s.student_id = st.id
                    WHERE st.class_id = ? AND s.subject_id = ?";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$classId, $subjectId]);
            
            return ['success' => true, 'message' => 'All scores deleted successfully'];
        } catch (Exception $e) {
            error_log("Delete all scores error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to delete scores'];
        }
    }
    
    /**
     * Update student name
     * 
     * @param int $studentId Student ID
     * @param string $newName New student name
     * @return array Response
     */
    public function updateStudentName($studentId, $newName) {
        try {
            // Verify student belongs to the user's school
            $school_id = $_SESSION['school_id'] ?? null;
            if (!$school_id) {
                return ['success' => false, 'error' => 'School ID not found'];
            }
            
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
            
            $sql = "UPDATE students SET student_name = ? WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([trim($newName), $studentId]);
            
            return ['success' => true, 'message' => 'Student name updated successfully'];
        } catch (Exception $e) {
            error_log("Update student name error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to update student name'];
        }
    }
    
    /**
     * Search students by name
     * 
     * @param int $classId Class ID
     * @param string $searchTerm Search term
     * @return array List of students
     */
    public function searchStudents($classId, $searchTerm) {
        try {
            $stmt = $this->db->prepare("
                SELECT id, student_id, student_name, gender
                FROM students
                WHERE class_id = ? AND student_name LIKE ?
                ORDER BY student_name
            ");
            $stmt->execute([$classId, '%' . $searchTerm . '%']);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Search students error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get score statistics for a class and subject
     * 
     * @param int $classId Class ID
     * @param int $subjectId Subject ID
     * @return array Statistics
     */
    public function getScoreStatistics($classId, $subjectId) {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    COUNT(*) as total_students,
                    COUNT(s.id) as students_with_scores,
                    AVG(s.total_score) as average_score,
                    MAX(s.total_score) as highest_score,
                    MIN(s.total_score) as lowest_score,
                    SUM(CASE WHEN s.total_score >= 50 THEN 1 ELSE 0 END) as pass_count,
                    SUM(CASE WHEN s.total_score < 50 THEN 1 ELSE 0 END) as fail_count
                FROM students st
                LEFT JOIN scores s ON st.id = s.student_id AND s.subject_id = ?
                WHERE st.class_id = ?
            ");
            $stmt->execute([$subjectId, $classId]);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Calculate pass rate
            $stats['pass_rate'] = $stats['students_with_scores'] > 0
                ? ($stats['pass_count'] / $stats['students_with_scores']) * 100
                : 0;
            
            return $stats;
        } catch (Exception $e) {
            error_log("Get score statistics error: " . $e->getMessage());
            return [];
        }
    }
}
