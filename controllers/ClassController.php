<?php
/**
 * Class Controller
 * 
 * Handles class and subject management
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

class ClassController {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * Get all classes (optionally filter by school type)
     * CRITICAL: Uses school_id from session for proper multi-tenancy
     * 
     * @param int|null $schoolTypeId Optional school type ID filter (DEPRECATED - use session)
     * @return array List of classes
     */
    public function getAllClasses($schoolTypeId = null) {
        try {
            // PRIORITY 1: Use school_id from session if available (proper isolation)
            if (isset($_SESSION['school_id']) && $_SESSION['school_id'] !== null) {
                $stmt = $this->db->prepare("SELECT c.*, st.type_name, st.type_code 
                                           FROM classes c 
                                           JOIN school_types st ON c.school_type_id = st.id
                                           WHERE c.school_id = ?
                                           ORDER BY c.class_name, 
                                                    CASE WHEN c.stream IS NULL THEN 0 ELSE 1 END,
                                                    c.stream");
                $stmt->execute([$_SESSION['school_id']]);
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
            // PRIORITY 2: Fallback to school_type_id parameter (backward compatibility)
            if ($schoolTypeId !== null) {
                // Use school_id from session instead of looking it up
                $schoolId = $_SESSION['school_id'] ?? null;
                
                if ($schoolId !== null) {
                    $stmt = $this->db->prepare("SELECT c.*, st.type_name, st.type_code 
                                               FROM classes c 
                                               JOIN school_types st ON c.school_type_id = st.id
                                               WHERE c.school_id = ?
                                               ORDER BY c.class_name, 
                                                        CASE WHEN c.stream IS NULL THEN 0 ELSE 1 END,
                                                        c.stream");
                    $stmt->execute([$schoolId]);
                    return $stmt->fetchAll(PDO::FETCH_ASSOC);
                }
                return [];
            }
            
            // PRIORITY 3: Admin/Developer mode - show all classes with school names
            $stmt = $this->db->query("SELECT c.*, st.type_name, st.type_code, si.school_name
                                      FROM classes c 
                                      JOIN school_types st ON c.school_type_id = st.id
                                      LEFT JOIN school_info si ON c.school_id = si.id
                                      ORDER BY c.school_id, c.class_name");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Get classes error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get class by ID
     * 
     * @param int $classId Class ID
     * @return array|null Class data
     */
    public function getClass($classId) {
        try {
            $stmt = $this->db->prepare("SELECT * FROM classes WHERE id = ?");
            $stmt->execute([$classId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Get class error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get class by code
     * 
     * @param string $classCode Class code
     * @return array|null Class data
     */
    public function getClassByCode($classCode) {
        try {
            $stmt = $this->db->prepare("SELECT * FROM classes WHERE class_code = ?");
            $stmt->execute([$classCode]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Get class by code error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get subjects for a class
     * 
     * @param int $classId Class ID
     * @return array List of subjects
     */
    public function getClassSubjects($classId) {
        try {
            $stmt = $this->db->prepare("SELECT * FROM subjects WHERE class_id = ? ORDER BY subject_name");
            $stmt->execute([$classId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Get class subjects error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get subject by ID
     * 
     * @param int $subjectId Subject ID
     * @return array|null Subject data
     */
    public function getSubject($subjectId) {
        try {
            $sql = "SELECT s.*, c.class_name, c.class_code 
                    FROM subjects s
                    JOIN classes c ON s.class_id = c.id
                    WHERE s.id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$subjectId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Get subject error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get subject by code
     * 
     * @param string $subjectCode Subject code
     * @return array|null Subject data
     */
    public function getSubjectByCode($subjectCode) {
        try {
            $sql = "SELECT s.*, c.class_name, c.class_code 
                    FROM subjects s
                    JOIN classes c ON s.class_id = c.id
                    WHERE s.subject_code = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$subjectCode]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Get subject by code error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get students for a class and subject
     * 
     * @param int $classId Class ID
     * @param int $subjectId Subject ID
     * @return array List of students
     */
    public function getClassSubjectStudents($classId, $subjectId = null) {
        try {
            $sql = "SELECT DISTINCT s.id, s.student_id as student_number, s.student_name, s.gender
                    FROM students s
                    WHERE s.class_id = ?
                    ORDER BY s.student_name";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$classId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Get class subject students error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Update class settings
     * 
     * @param int $classId Class ID
     * @param array $settings Settings data
     * @return array Response
     */
    public function updateClassSettings($classId, $settings) {
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
            
            if (isset($settings['total_attendance'])) {
                $sql = "UPDATE classes SET total_attendance = :total_attendance WHERE id = :id";
                $this->db->query($sql, [
                    ':total_attendance' => $settings['total_attendance'],
                    ':id' => $classId
                ]);
            }
            
            // Update students' attendance if needed
            if (isset($settings['update_students']) && $settings['update_students']) {
                $sql = "UPDATE students SET 
                        attendance = :attendance
                        WHERE class_id = :class_id";
                
                $params = [':class_id' => $classId];
                
                if (isset($settings['attendance'])) {
                    $params[':attendance'] = $settings['attendance'];
                } else {
                    $sql = str_replace('attendance = :attendance', 'attendance = 0', $sql);
                }
                
                $this->db->query($sql, $params);
            }
            
            return ['success' => true, 'message' => 'Class settings updated successfully'];
        } catch (Exception $e) {
            error_log("Update class settings error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to update class settings'];
        }
    }
    
    /**
     * Get classes grouped by base name (for streams)
     * 
     * @param int $schoolId School ID
     * @return array List of classes grouped by base name
     */
    public function getClassesGroupedByName($schoolId) {
        try {
            $stmt = $this->db->prepare("
                SELECT id, class_name, stream, school_id, school_type_id
                FROM classes
                WHERE school_id = ?
                ORDER BY class_name, 
                         CASE WHEN stream IS NULL THEN 0 ELSE 1 END,
                         stream
            ");
            $stmt->execute([$schoolId]);
            $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $grouped = [];
            foreach ($classes as $class) {
                $baseName = $class['class_name'];
                if (!isset($grouped[$baseName])) {
                    $grouped[$baseName] = [
                        'base_name' => $baseName,
                        'streams' => []
                    ];
                }
                $grouped[$baseName]['streams'][] = $class;
            }
            
            return array_values($grouped);
        } catch (Exception $e) {
            error_log("Get classes grouped error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Create a new stream for a class
     * 
     * @param int $baseClassId Base class ID (to copy from)
     * @param string $streamLetter Stream letter (A, B, C, D, etc.)
     * @return array Response
     */
    public function createStream($baseClassId, $streamLetter) {
        try {
            // Get base class info
            $stmt = $this->db->prepare("SELECT * FROM classes WHERE id = ?");
            $stmt->execute([$baseClassId]);
            $baseClass = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$baseClass) {
                return ['success' => false, 'error' => 'Base class not found'];
            }
            
            // Check if stream already exists
            $stmt = $this->db->prepare("
                SELECT id FROM classes 
                WHERE class_name = ? AND stream = ? AND school_id = ?
            ");
            $stmt->execute([$baseClass['class_name'], $streamLetter, $baseClass['school_id']]);
            if ($stmt->fetch()) {
                return ['success' => false, 'error' => 'Stream already exists'];
            }
            
            // Create new stream
            $stmt = $this->db->prepare("
                INSERT INTO classes (class_name, class_code, stream, school_type_id, school_id, total_attendance)
                VALUES (?, ?, ?, ?, ?, 0)
            ");
            $newCode = $baseClass['class_code'] . '-' . $streamLetter;
            $stmt->execute([
                $baseClass['class_name'],
                $newCode,
                $streamLetter,
                $baseClass['school_type_id'],
                $baseClass['school_id']
            ]);
            
            $newClassId = $this->db->lastInsertId();
            
            // Copy subjects from base class to new stream
            $stmt = $this->db->prepare("
                SELECT subject_name, subject_code, is_core, display_order 
                FROM subjects 
                WHERE class_id = ?
            ");
            $stmt->execute([$baseClassId]);
            $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $insertSubject = $this->db->prepare("
                INSERT INTO subjects (class_id, subject_name, subject_code, is_core, display_order)
                VALUES (?, ?, ?, ?, ?)
            ");
            
            foreach ($subjects as $subject) {
                $newSubjectCode = $subject['subject_code'] . '-' . $streamLetter;
                $insertSubject->execute([
                    $newClassId,
                    $subject['subject_name'],
                    $newSubjectCode,
                    $subject['is_core'],
                    $subject['display_order']
                ]);
            }
            
            return [
                'success' => true, 
                'message' => 'Stream created successfully',
                'class_id' => $newClassId
            ];
        } catch (Exception $e) {
            error_log("Create stream error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to create stream: ' . $e->getMessage()];
        }
    }
    
    /**
     * Delete a stream
     * 
     * @param int $classId Class ID to delete
     * @return array Response
     */
    public function deleteStream($classId) {
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
            
            // Check if stream has students
            $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM students WHERE class_id = ?");
            $stmt->execute([$classId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result['count'] > 0) {
                return ['success' => false, 'error' => 'Cannot delete stream with students. Please move or remove students first.'];
            }
            
            // Delete subjects first
            $stmt = $this->db->prepare("DELETE FROM subjects WHERE class_id = ?");
            $stmt->execute([$classId]);
            
            // Delete class
            $stmt = $this->db->prepare("DELETE FROM classes WHERE id = ?");
            $stmt->execute([$classId]);
            
            return ['success' => true, 'message' => 'Stream deleted successfully'];
        } catch (Exception $e) {
            error_log("Delete stream error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to delete stream'];
        }
    }
    
    /**
     * Get display name for a class (with stream if applicable)
     * 
     * @param array $class Class data
     * @return string Display name
     */
    public static function getDisplayName($class) {
        if (!empty($class['stream'])) {
            return $class['class_name'] . ' ' . $class['stream'];
        }
        return $class['class_name'];
    }
}
