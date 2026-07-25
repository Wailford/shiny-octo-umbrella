<?php
/**
 * School Controller
 * 
 * Handles school information management
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

class SchoolController {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * Get school metadata
     * 
     * @return array School information
     */
public function getSchoolInfo(int $schoolId) {
        try {
            $stmt = $this->db->prepare("SELECT * FROM school_info WHERE id = ?");
            $stmt->execute([$schoolId]);
            $info = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$info) {
                return $this->getDefaultSchoolInfo();
            }
            
            return $info;
        } catch (Exception $e) {
            error_log("Get school info error: " . $e->getMessage());
            return $this->getDefaultSchoolInfo();
        }
    }
    
    /**
     * Update school information
     * 
     * @param array $data School information data
     * @return array Response
     */
    public function updateSchoolInfo(int $schoolId, array $data) {
        try {
            $sql = "UPDATE school_info SET 
                    school_name = :school_name,
                    address = :address,
                    location = :location,
                    headmaster_name = :headmaster_name,
                    email = :email,
                    phone = :phone,
                    logo1_url = :logo1_url,
                    logo2_url = :logo2_url,
                    current_term = :current_term,
                    academic_year = :academic_year,
                    reopen_date = :reopen_date
                    WHERE id = :school_id";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':school_name'     => $data['school_name'] ?? '',
                ':address'         => $data['address'] ?? '',
                ':location'        => $data['location'] ?? '',
                ':headmaster_name' => $data['headmaster_name'] ?? '',
                ':email'           => $data['email'] ?? '',
                ':phone'           => $data['phone'] ?? '',
                ':logo1_url'       => $data['logo1_url'] ?? '',
                ':logo2_url'       => $data['logo2_url'] ?? '',
                ':current_term'    => $data['current_term'] ?? '',
                ':academic_year'   => $data['academic_year'] ?? '',
                ':reopen_date'     => $data['reopen_date'] ?? null,
                ':school_id'       => $schoolId,
            ]);

            return ['success' => true, 'message' => 'School information updated successfully'];
        } catch (Exception $e) {
            error_log("Update school info error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to update school information'];
        }
    }
    
    /**
     * Get default school info
     * 
     * @return array
     */
    private function getDefaultSchoolInfo() {
        return [
            'school_name' => 'M.T.T.C. PRACTICE PRIMARY SCHOOL',
            'address' => 'POST OFFICE BOX 379',
            'location' => 'MAMPONG-ASHANTI',
            'headmaster_name' => 'MR. EDMUND BEDIAKO',
            'email' => 'mttcprap1972@gmail.com',
            'phone' => '0244010696',
            'logo1_url' => 'https://i.imgur.com/l9dDRok.png',
            'logo2_url' => 'https://imgur.com/N0sEqK0.png',
            'current_term' => 'Second Term',
            'academic_year' => '2024/2025',
            'reopen_date' => '2025-05-06'
        ];
    }
}
