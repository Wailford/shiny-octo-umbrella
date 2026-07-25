<?php
/**
 * Grading Helper Functions
 * Load grading system from database instead of hardcoded constants
 */

class GradingSystem {
    /**
     * Load grading system from database
     */
    public static function loadGrades() {
        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->query("SELECT * FROM grading_system ORDER BY display_order ASC");
            $grades = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // If no grades in database, use default
            if (empty($grades)) {
                return self::getDefaultGrades();
            }
            
            return $grades;
        } catch (Exception $e) {
            // Fallback to default if database error
            return self::getDefaultGrades();
        }
    }
    
    /**
     * Get grade and remark for a score
     */
    public static function getGradeForScore($score) {
        $grades = self::loadGrades();
        
        foreach ($grades as $grade) {
            if ($score >= $grade['min_score'] && $score <= $grade['max_score']) {
                return [
                    'grade' => $grade['grade'],
                    'remarks' => $grade['remarks']
                ];
            }
        }
        
        // Default if no match
        return ['grade' => '9', 'remarks' => 'Lowest'];
    }
    
    /**
     * Get all grades as array (for use in JavaScript/templates)
     */
    public static function getGradesArray() {
        return self::loadGrades();
    }
    
    /**
     * BECE mock exam grading scale (1–9, 100-point input).
     * Kept separate from the SBA scale so the two can evolve independently.
     */
    public static function getMockGradesArray(): array {
        return [
            ['grade' => '1', 'min_score' => 80, 'remarks' => 'Highest'],
            ['grade' => '2', 'min_score' => 70, 'remarks' => 'Higher'],
            ['grade' => '3', 'min_score' => 60, 'remarks' => 'High'],
            ['grade' => '4', 'min_score' => 55, 'remarks' => 'High Average'],
            ['grade' => '5', 'min_score' => 50, 'remarks' => 'Average'],
            ['grade' => '6', 'min_score' => 45, 'remarks' => 'Low Average'],
            ['grade' => '7', 'min_score' => 40, 'remarks' => 'Low'],
            ['grade' => '8', 'min_score' => 35, 'remarks' => 'Lower'],
            ['grade' => '9', 'min_score' =>  0, 'remarks' => 'Lowest'],
        ];
    }

    public static function getMockGradeForScore($score): array {
        foreach (self::getMockGradesArray() as $g) {
            if ($score >= $g['min_score']) {
                return ['grade' => $g['grade'], 'remarks' => $g['remarks']];
            }
        }
        return ['grade' => '9', 'remarks' => 'Lowest'];
    }

    /**
     * Scale raw SBA components to a 100-point final score.
     * SBA components (max 60) → 50%; Exam (max 100) → 50%.
     * Returns ['class_score', 'exam_score_scaled', 'total', 'grade', 'remarks'].
     */
    public static function calculateSubjectTotal(array $scores): array {
        $ta    = ($scores['test1'] ?? 0) + ($scores['group_work'] ?? 0)
               + ($scores['test2'] ?? 0) + ($scores['project_work'] ?? 0);
        $cs    = round(($ta / 60) * 50);
        $es    = round((($scores['exam_score'] ?? 0) / 100) * 50);
        $total = $cs + $es;
        $gi    = self::getGradeForScore($total);
        return [
            'class_score'       => $cs,
            'exam_score_scaled' => $es,
            'total'             => $total,
            'grade'             => $gi['grade'],
            'remarks'           => $gi['remarks'],
        ];
    }

    /**
     * Format a rank/position integer as an ordinal string (1st, 2nd, 3rd…).
     * Returns 'N/A' for zero or negative values.
     */
    public static function formatPosition(int $n): string {
        if ($n <= 0) return 'N/A';
        // 11–13 always use "th" regardless of the units digit
        if ($n % 100 >= 11 && $n % 100 <= 13) return $n . 'th';
        return $n . match ($n % 10) {
            1       => 'st',
            2       => 'nd',
            3       => 'rd',
            default => 'th',
        };
    }

    /**
     * Default Ghana Education Service grading system
     */
    private static function getDefaultGrades() {
        return [
            ['id' => 1, 'grade' => '1', 'min_score' => 80, 'max_score' => 100, 'remarks' => 'Highest', 'display_order' => 1],
            ['id' => 2, 'grade' => '2', 'min_score' => 75, 'max_score' => 79.99, 'remarks' => 'Higher', 'display_order' => 2],
            ['id' => 3, 'grade' => '3', 'min_score' => 70, 'max_score' => 74.99, 'remarks' => 'High', 'display_order' => 3],
            ['id' => 4, 'grade' => '4', 'min_score' => 65, 'max_score' => 69.99, 'remarks' => 'High Average', 'display_order' => 4],
            ['id' => 5, 'grade' => '5', 'min_score' => 60, 'max_score' => 64.99, 'remarks' => 'Average', 'display_order' => 5],
            ['id' => 6, 'grade' => '6', 'min_score' => 55, 'max_score' => 59.99, 'remarks' => 'Low Average', 'display_order' => 6],
            ['id' => 7, 'grade' => '7', 'min_score' => 50, 'max_score' => 54.99, 'remarks' => 'Low', 'display_order' => 7],
            ['id' => 8, 'grade' => '8', 'min_score' => 40, 'max_score' => 49.99, 'remarks' => 'Lower', 'display_order' => 8],
            ['id' => 9, 'grade' => '9', 'min_score' => 0, 'max_score' => 39.99, 'remarks' => 'Lowest', 'display_order' => 9]
        ];
    }
}
