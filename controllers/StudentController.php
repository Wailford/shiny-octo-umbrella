<?php
/**
 * Student Controller
 * 
 * Handles student CRUD operations and management
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

class StudentController {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * Get students by class
     * 
     * @param int $classId Class ID
     * @return array List of students
     */
    public function getStudentsByClass($classId) {
        try {
            // Ensure students returned belong to the same school as the current session
            $schoolId = $_SESSION['school_id'] ?? null;

            $sql = "SELECT s.*, c.class_name, c.class_code 
                    FROM students s
                    JOIN classes c ON s.class_id = c.id
                    WHERE s.class_id = ?";

            if ($schoolId !== null) {
                $sql .= " AND c.school_id = ?";
                $sql .= " ORDER BY s.student_name";
                $stmt = $this->db->prepare($sql);
                $stmt->execute([$classId, $schoolId]);
            } else {
                $sql .= " ORDER BY s.student_name";
                $stmt = $this->db->prepare($sql);
                $stmt->execute([$classId]);
            }
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Get students error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get student by ID
     * 
     * @param int $studentId Student ID
     * @return array|null Student data
     */
    public function getStudent($studentId) {
        try {
            // Ensure student belongs to current session school
            $schoolId = $_SESSION['school_id'] ?? null;
            
            // Get school_id from school_info

            $sql = "SELECT s.*, c.class_name, c.class_code 
                    FROM students s
                    JOIN classes c ON s.class_id = c.id
                    WHERE s.id = ?";

            if ($schoolId !== null) {
                $sql .= " AND c.school_id = ?";
                $stmt = $this->db->prepare($sql);
                $stmt->execute([$studentId, $schoolId]);
            } else {
                $stmt = $this->db->prepare($sql);
                $stmt->execute([$studentId]);
            }
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Get student error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Generate candidate index number for Basic 9 students
     * Format: SchoolCode(7) + Sequential(3) + Year(2)
     * Example: 0514096 + 001 + 25 = 051409600125
     * 
     * @param int $classId Class ID
     * @param int $schoolId School ID
     * @return string|null Candidate index number or null if not Basic 9
     */
    public function generateCandidateIndexNumber($classId, $schoolId, $sequentialNumber = null) {
        try {
            // Check if this is Basic 9 class (supports "Basic 9", "Basic Nine", "Basic9")
            $stmt = $this->db->prepare("SELECT class_name FROM classes WHERE id = ? AND school_id = ?");
            $stmt->execute([$classId, $schoolId]);
            $class = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$class) {
                return null; // Class not found
            }
            
            // Check if class name contains Basic 9, Basic Nine, or Basic9
            $className = $class['class_name'];
            $isBasic9 = stripos($className, 'Basic 9') !== false || 
                        stripos($className, 'Basic Nine') !== false || 
                        stripos($className, 'Basic9') !== false;
            
            if (!$isBasic9) {
                return null; // Not a Basic 9 class
            }

            $columnCheckStmt = $this->db->query("SHOW COLUMNS FROM school_info LIKE 'school_code'");
            $hasSchoolCodeColumn = (bool) $columnCheckStmt->fetch(PDO::FETCH_ASSOC);
            if (!$hasSchoolCodeColumn) {
                return null; // Schema not migrated yet
            }
            
            // Get school code
            $stmt = $this->db->prepare("SELECT school_code FROM school_info WHERE id = ?");
            $stmt->execute([$schoolId]);
            $schoolInfo = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$schoolInfo || empty($schoolInfo['school_code'])) {
                return null; // School code not set
            }
            
            $schoolCode = $schoolInfo['school_code'];
            
            // Validate school code is 7 digits
            if (!preg_match('/^\d{7}$/', $schoolCode)) {
                return null; // Invalid school code format
            }
            
            // Get last 2 digits of current year
            $year = date('y'); // e.g., 26 for 2026
            
            // Use provided sequential number directly (no auto-increment logic)
            if ($sequentialNumber !== null) {
                $nextSequential = intval($sequentialNumber);
            } else {
                // If not provided, find the highest existing number for this school/year
                $prefix = $schoolCode;
                $suffix = $year;
                $likePattern = $prefix . '%' . $suffix;
                
                $stmt = $this->db->prepare("
                    SELECT candidate_index_number 
                    FROM students s
                    JOIN classes c ON s.class_id = c.id
                    WHERE c.school_id = ? 
                      AND s.candidate_index_number LIKE ?
                      AND LENGTH(s.candidate_index_number) = 12
                    ORDER BY s.candidate_index_number DESC
                    LIMIT 1
                ");
                $stmt->execute([$schoolId, $likePattern]);
                $lastStudent = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($lastStudent && $lastStudent['candidate_index_number']) {
                    // Extract the sequential part (positions 8-10, after 7-digit school code)
                    $lastIndex = $lastStudent['candidate_index_number'];
                    $sequential = intval(substr($lastIndex, 7, 3)); // Extract middle 3 digits
                    $nextSequential = $sequential + 1;
                } else {
                    // First student for this school/year
                    $nextSequential = 1;
                }
            }
            
            // Format as 3-digit sequential number with leading zeros
            $sequentialStr = str_pad($nextSequential, 3, '0', STR_PAD_LEFT);
            
            // Build the index: SchoolCode (7) + Sequential (3) + Year (2) = 12 digits
            $candidateIndex = $schoolCode . $sequentialStr . $year;
            
            return $candidateIndex;
            
        } catch (Exception $e) {
            error_log("Generate candidate index error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Add new student
     * 
     * @param array $data Student data
     * @return array Response
     */
    public function addStudent($data) {
        try {
            // Validate required fields
            if (empty($data['student_name']) || empty($data['class_id'])) {
                return ['success' => false, 'error' => 'Student name and class are required'];
            }
            
            // Generate student ID if not provided
            if (empty($data['student_id'])) {
                $data['student_id'] = $this->generateStudentId($data['class_id']);
            }
            
            // DO NOT auto-generate candidate index numbers when adding students
            // Index numbers should only be generated in batch using "Generate Index Numbers" button
            // This prevents wrong sequential numbers
            
            $sql = "INSERT INTO students (student_id, candidate_index_number, student_name, class_id, gender, photo_url,
                    parent_name, parent_relationship, parent_phone, parent_whatsapp, parent_email,
                    attendance, total_attendance,
                    interest, conduct, form_master_remarks, headmaster_remarks, promoted, promoted_to_class)
                    VALUES (:student_id, :candidate_index_number, :student_name, :class_id, :gender, :photo_url,
                    :parent_name, :parent_relationship, :parent_phone, :parent_whatsapp, :parent_email,
                    :attendance, :total_attendance,
                    :interest, :conduct, :form_master_remarks, :headmaster_remarks, :promoted, :promoted_to_class)";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':student_id' => $data['student_id'],
                ':candidate_index_number' => $data['candidate_index_number'] ?? null,
                ':student_name' => $data['student_name'],
                ':class_id' => $data['class_id'],
                ':gender' => $data['gender'] ?? null,
                ':photo_url' => $data['photo_url'] ?? null,
                ':parent_name' => $data['parent_name'] ?? null,
                ':parent_relationship' => $data['parent_relationship'] ?? null,
                ':parent_phone' => $data['parent_phone'] ?? null,
                ':parent_whatsapp' => $data['parent_whatsapp'] ?? null,
                ':parent_email' => $data['parent_email'] ?? null,
                ':attendance' => $data['attendance'] ?? 0,
                ':total_attendance' => $data['total_attendance'] ?? 71,
                ':interest' => $data['interest'] ?? null,
                ':conduct' => $data['conduct'] ?? null,
                ':form_master_remarks' => $data['form_master_remarks'] ?? null,
                ':headmaster_remarks' => $data['headmaster_remarks'] ?? null,
                ':promoted' => $data['promoted'] ?? null,
                ':promoted_to_class' => $data['promoted_to_class'] ?? null
            ]);
            
            $newId = $this->db->lastInsertId();

            // Auto-sync parent contact to parent_contacts table
            $this->syncParentContact($newId, $data);

            return [
                'success' => true,
                'message' => 'Student added successfully',
                'student_id' => $newId
            ];
        } catch (Exception $e) {
            error_log("Add student error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to add student'];
        }
    }
    
    /**
     * Update student
     * 
     * @param int $studentId Student ID
     * @param array $data Student data
     * @return array Response
     */
    public function updateStudent($studentId, $data) {
        try {
            // Verify student belongs to the user's school
            $school_id = $_SESSION['school_id'] ?? null;
            if (!$school_id) {
                return ['success' => false, 'error' => 'School ID not found'];
            }
            
            // Verify student belongs to this school through their class
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
            
            // Build SQL dynamically - only update photo_url if explicitly provided
            $sql = "UPDATE students SET 
                    student_id = :student_id,
                    candidate_index_number = :candidate_index_number,
                    student_name = :student_name,
                    gender = :gender";
            
            $params = [
                ':student_id' => $data['student_id'] ?? null,
                ':candidate_index_number' => $data['candidate_index_number'] ?? null,
                ':student_name' => $data['student_name'] ?? null,
                ':gender' => $data['gender'] ?? null
            ];
            
            // Only update photo_url if a new photo was provided
            if (isset($data['photo_url'])) {
                $sql .= ",
                    photo_url = :photo_url";
                $params[':photo_url'] = $data['photo_url'];
            }
            
            $sql .= ",
                    parent_name = :parent_name,
                    parent_relationship = :parent_relationship,
                    parent_phone = :parent_phone,
                    parent_whatsapp = :parent_whatsapp,
                    parent_email = :parent_email,
                    attendance = :attendance,
                    total_attendance = :total_attendance,
                    interest = :interest,
                    conduct = :conduct,
                    form_master_remarks = :form_master_remarks,
                    headmaster_remarks = :headmaster_remarks,
                    promoted = :promoted,
                    promoted_to_class = :promoted_to_class
                    WHERE id = :id";
            
            $params = array_merge($params, [
                ':parent_name' => $data['parent_name'] ?? null,
                ':parent_relationship' => $data['parent_relationship'] ?? null,
                ':parent_phone' => $data['parent_phone'] ?? null,
                ':parent_whatsapp' => $data['parent_whatsapp'] ?? null,
                ':parent_email' => $data['parent_email'] ?? null,
                ':attendance' => $data['attendance'] ?? null,
                ':total_attendance' => $data['total_attendance'] ?? 71,
                ':interest' => $data['interest'] ?? null,
                ':conduct' => $data['conduct'] ?? null,
                ':form_master_remarks' => $data['form_master_remarks'] ?? null,
                ':headmaster_remarks' => $data['headmaster_remarks'] ?? null,
                ':promoted' => $data['promoted'] ?? null,
                ':promoted_to_class' => $data['promoted_to_class'] ?? null,
                ':id' => $studentId
            ]);
            
            $stmt = $this->db->prepare($sql);
            $result = $stmt->execute($params);
            
            if ($result) {
                // Auto-sync parent contact to parent_contacts table
                $this->syncParentContact($studentId, $data);

                return ['success' => true, 'message' => 'Student updated successfully'];
            } else {
                return ['success' => false, 'error' => 'Update failed - no rows affected'];
            }
        } catch (Exception $e) {
            error_log("Update student error: " . $e->getMessage());
            error_log("Student ID: " . $studentId);
            error_log("Data: " . print_r($data, true));
            return ['success' => false, 'error' => 'Failed to update student: ' . $e->getMessage()];
        }
    }
    
    /**
     * Auto-sync inline parent fields on a student to the parent_contacts + parent_student_links tables.
     * If a parent with the same phone already exists for this school, reuse them (handles siblings).
     * If the parent info is cleared, the link is removed.
     */
    private function syncParentContact($studentId, $data) {
        try {
            $schoolId = $_SESSION['school_id'] ?? null;
            if (!$schoolId) return;

            $parentName  = trim($data['parent_name'] ?? '');
            $parentPhone = trim($data['parent_phone'] ?? '');
            $relationship = trim($data['parent_relationship'] ?? 'Parent');
            $whatsapp    = trim($data['parent_whatsapp'] ?? '');
            $email       = trim($data['parent_email'] ?? '');

            // If no phone provided, remove any existing link and return
            if ($parentPhone === '') {
                $this->db->prepare("
                    DELETE psl FROM parent_student_links psl
                    JOIN parent_contacts pc ON psl.parent_id = pc.id
                    WHERE psl.student_id = ? AND pc.school_id = ?
                ")->execute([$studentId, $schoolId]);
                return;
            }

            // Normalize phone: strip spaces, dashes
            $phoneNorm = preg_replace('/[\s\-]/', '', $parentPhone);

            // Check if a parent_contact with this phone already exists for this school
            $stmt = $this->db->prepare("
                SELECT id FROM parent_contacts
                WHERE school_id = ? AND REPLACE(REPLACE(phone, ' ', ''), '-', '') = ?
                LIMIT 1
            ");
            $stmt->execute([$schoolId, $phoneNorm]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $parentId = (int)$existing['id'];
                // Update existing parent contact with latest info
                $this->db->prepare("
                    UPDATE parent_contacts
                    SET full_name = ?, relationship = ?, phone = ?,
                        whatsapp_number = COALESCE(NULLIF(?, ''), whatsapp_number),
                        email = COALESCE(NULLIF(?, ''), email),
                        updated_at = NOW()
                    WHERE id = ?
                ")->execute([$parentName ?: 'Parent/Guardian', $relationship, $parentPhone, $whatsapp, $email, $parentId]);
            } else {
                // Create new parent contact
                $this->db->prepare("
                    INSERT INTO parent_contacts (school_id, full_name, relationship, phone, whatsapp_number, email)
                    VALUES (?, ?, ?, ?, ?, ?)
                ")->execute([
                    $schoolId,
                    $parentName ?: 'Parent/Guardian',
                    $relationship,
                    $parentPhone,
                    $whatsapp ?: null,
                    $email ?: null
                ]);
                $parentId = (int)$this->db->lastInsertId();
            }

            // Remove any previous link for this student (one active parent per student via inline)
            $this->db->prepare("
                DELETE psl FROM parent_student_links psl
                JOIN parent_contacts pc ON psl.parent_id = pc.id
                WHERE psl.student_id = ? AND pc.school_id = ?
            ")->execute([$studentId, $schoolId]);

            // Create the link
            $this->db->prepare("
                INSERT IGNORE INTO parent_student_links (parent_id, student_id) VALUES (?, ?)
            ")->execute([$parentId, $studentId]);

        } catch (Exception $e) {
            error_log("syncParentContact error: " . $e->getMessage());
        }
    }

    /**
     * Delete student
     * 
     * @param int $studentId Student ID
     * @return array Response
     */
    public function deleteStudent($studentId) {
        try {
            // Verify student belongs to the user's school
            $school_id = $_SESSION['school_id'] ?? null;
            if (!$school_id) {
                return ['success' => false, 'error' => 'School ID not found'];
            }
            
            // Verify student belongs to this school through their class
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
            
            $sql = "DELETE FROM students WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':id' => $studentId]);
            
            return ['success' => true, 'message' => 'Student deleted successfully'];
        } catch (Exception $e) {
            error_log("Delete student error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to delete student'];
        }
    }
    
    /**
     * Search students
     * 
     * @param string $searchTerm Search term
     * @return array List of students
     */
    public function searchStudents($searchTerm) {
        try {
            $sql = "SELECT s.*, c.class_name, c.class_code 
                    FROM students s
                    JOIN classes c ON s.class_id = c.id
                    WHERE s.student_name LIKE ? OR s.student_id LIKE ?
                    ORDER BY s.student_name";
            $search = "%$searchTerm%";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$search, $search]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Search students error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Bulk import students
     * 
     * @param int $classId Class ID
     * @param array $studentNames Array of student names
     * @return array Response
     */
    public function bulkImport($classId, $studentNames) {
        try {
            $this->db->beginTransaction();
            
            $addedCount = 0;
            $errors = [];
            
            foreach ($studentNames as $line) {
                $line = trim($line);
                if (empty($line)) continue;

                // Support: "Name" or "Name | Parent Name | Phone" or "Name | Parent Name | Phone | WhatsApp | Email"
                $parts       = array_map('trim', explode('|', $line));
                $studentName = $parts[0] ?? '';
                if (empty($studentName)) continue;

                $data = [
                    'student_name'        => $studentName,
                    'class_id'            => $classId,
                    'parent_name'         => $parts[1] ?? null,
                    'parent_phone'        => $parts[2] ?? null,
                    'parent_whatsapp'     => $parts[3] ?? ($parts[2] ?? null),
                    'parent_email'        => $parts[4] ?? null,
                    'parent_relationship' => null,
                ];

                $result = $this->addStudent($data);
                
                if ($result['success']) {
                    $addedCount++;
                } else {
                    $errors[] = "Failed to add {$studentName}: " . $result['error'];
                }
            }
            
            $this->db->commit();
            
            return [
                'success' => true,
                'message' => "Successfully added {$addedCount} students",
                'added_count' => $addedCount,
                'errors' => !empty($errors) ? $errors : null
            ];
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("Bulk import error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Bulk import failed'];
        }
    }
    
    /**
     * Generate unique student ID
     * 
     * @param int $classId Class ID
     * @return string Student ID
     */
    private function generateStudentId($classId) {
        $year = date('Y');
        $sql = "SELECT MAX(CAST(SUBSTRING(student_id, -4) AS UNSIGNED)) as max_id 
                FROM students 
                WHERE class_id = ? AND student_id LIKE ?";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$classId, "{$year}%"]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $nextNumber = ($result['max_id'] ?? 0) + 1;
        return sprintf("%s%04d", $year, $nextNumber);
    }
}
