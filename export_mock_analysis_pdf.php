<?php
session_start();
require_once 'config/config.php';
require_once 'config/database.php';
require_once 'helpers/GradingSystem.php';
require_once 'vendor/autoload.php';

// Check if mPDF is installed
if (!class_exists('\Mpdf\Mpdf')) {
    die('mPDF library not found. Please run: composer require mpdf/mpdf');
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    die('Unauthorized access');
}

$class_id = $_GET['class_id'] ?? null;

if (!$class_id) {
    die('Class ID is required');
}

$db = Database::getInstance()->getConnection();

// Get school information
$school_id = $_SESSION['school_id'] ?? null;
$stmt = $db->prepare("SELECT id FROM school_info WHERE id = ?");
$stmt->execute([$school_id]);
$school_info_result = $stmt->fetch(PDO::FETCH_ASSOC);
$school_id = $school_info_result['id'] ?? null;

// Get school details
$school_query = "SELECT * FROM school_info WHERE id = ?";
$school_stmt = $db->prepare($school_query);
$school_stmt->execute([$school_id]);
$school_data = $school_stmt->fetch(PDO::FETCH_ASSOC);

// Get mock exam settings
$settings_query = "SELECT * FROM mock_exam_settings 
                  WHERE school_id = ? AND is_enabled = 1 
                  ORDER BY academic_year DESC, term DESC LIMIT 1";
$settings_stmt = $db->prepare($settings_query);
$settings_stmt->execute([$school_id]);
$mock_settings = $settings_stmt->fetch(PDO::FETCH_ASSOC);

// Get class information — scoped to this school
$class_query = "SELECT class_name FROM classes WHERE id = ? AND school_id = ?";
$class_stmt = $db->prepare($class_query);
$class_stmt->execute([$class_id, $school_id]);
$class_info = $class_stmt->fetch(PDO::FETCH_ASSOC);
if (!$class_info) { die('Class not found or access denied.'); }

// Get subjects — scoped via class school_id
$subjects_query = "SELECT s.id, s.subject_name, s.subject_code FROM subjects s
                   JOIN classes c ON s.class_id = c.id
                   WHERE s.class_id = ? AND c.school_id = ? ORDER BY s.subject_name";
$subjects_stmt = $db->prepare($subjects_query);
$subjects_stmt->execute([$class_id, $school_id]);
$subjects = $subjects_stmt->fetchAll(PDO::FETCH_ASSOC);

// Convert score to Ghana grade
function convertScoreToGhanaGrade($score) {
    if ($score >= 90) return 1;
    if ($score >= 80) return 2;
    if ($score >= 70) return 3;
    if ($score >= 60) return 4;
    if ($score >= 55) return 5;
    if ($score >= 50) return 6;
    if ($score >= 40) return 7;
    if ($score >= 35) return 8;
    return 9;
}

// Get grade label
function getGradeLabel($grade) {
    $labels = [
        1 => 'Highest', 2 => 'Higher', 3 => 'High',
        4 => 'High Average', 5 => 'Average', 6 => 'Low Average',
        7 => 'Low', 8 => 'Lower', 9 => 'Lowest'
    ];
    return $labels[$grade] ?? 'N/A';
}

// Calculate comprehensive analysis - filter by school_id
$students_data = [];
// Get school_id for filtering
$schoolStmt = $db->prepare("SELECT id FROM school_info WHERE id = ?");
$schoolStmt->execute([$school_id]);
$schoolData = $schoolStmt->fetch(PDO::FETCH_ASSOC);
$currentSchoolId = $schoolData['id'] ?? null;

$students_query = "SELECT s.id, s.student_id, s.student_name, s.gender, s.candidate_index_number 
                  FROM students s
                  JOIN classes c ON s.class_id = c.id
                  WHERE s.class_id = ? AND c.school_id = ?
                  AND s.candidate_index_number IS NOT NULL AND s.candidate_index_number != ''
                  ORDER BY s.candidate_index_number";
$students_stmt = $db->prepare($students_query);
$students_stmt->execute([$class_id, $currentSchoolId]);
$students = $students_stmt->fetchAll(PDO::FETCH_ASSOC);

// Initialize class statistics
$class_stats = ['subjects' => []];
$total_candidates = count($students);
$aggregate_gender_distribution = [
    '6-12' => ['M' => 0, 'F' => 0, 'total' => 0],
    '13-24' => ['M' => 0, 'F' => 0, 'total' => 0],
    '25-36' => ['M' => 0, 'F' => 0, 'total' => 0],
    '37-48' => ['M' => 0, 'F' => 0, 'total' => 0]
];

foreach ($students as $student) {
    $student_scores = [];
    $total_score = 0;
    $subject_count = 0;
    
    foreach ($subjects as $subject) {
        $score_query = "SELECT score FROM mock_exam_scores 
                      WHERE school_id = ? AND student_id = ? AND subject_id = ?
                      AND academic_year = ? AND term = ?";
        $score_stmt = $db->prepare($score_query);
        $score_stmt->execute([$school_id, $student['id'], $subject['id'], 
                            $mock_settings['academic_year'], $mock_settings['term']]);
        $score_result = $score_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($score_result) {
            $score = $score_result['score'];
            $student_scores[$subject['id']] = $score;
            $total_score += $score;
            $subject_count++;
        }
    }
    
    $students_data[] = [
        'student' => $student,
        'scores' => $student_scores,
        'total' => $total_score,
        'average' => $subject_count > 0 ? $total_score / $subject_count : 0,
        'subjects_taken' => $subject_count
    ];
}

// Calculate subject statistics with detailed grade analysis
foreach ($subjects as $subject) {
    $subject_scores = [];
    $male_scores = [];
    $female_scores = [];
    $grade_counts = array_fill(1, 9, 0);
    $male_grade_counts = array_fill(1, 9, 0);
    $female_grade_counts = array_fill(1, 9, 0);
    
    // Check if core subject
    $is_core_query = "SELECT is_core FROM subjects WHERE id = ?";
    $is_core_stmt = $db->prepare($is_core_query);
    $is_core_stmt->execute([$subject['id']]);
    $is_core_result = $is_core_stmt->fetch(PDO::FETCH_ASSOC);
    $is_core = $is_core_result && $is_core_result['is_core'] == 1;
    
    foreach ($students_data as $student_data) {
        if (isset($student_data['scores'][$subject['id']])) {
            $score = $student_data['scores'][$subject['id']];
            $grade = convertScoreToGhanaGrade($score);
            $gender = strtoupper(substr($student_data['student']['gender'] ?? '', 0, 1));
            
            $subject_scores[] = $score;
            $grade_counts[$grade]++;
            
            if ($gender == 'M') {
                $male_scores[] = $score;
                $male_grade_counts[$grade]++;
            } else if ($gender == 'F') {
                $female_scores[] = $score;
                $female_grade_counts[$grade]++;
            }
        }
    }
    
    if (count($subject_scores) > 0) {
        $pass_count = count(array_filter($subject_scores, function($s) { return $s >= 50; }));
        
        $class_stats['subjects'][$subject['id']] = [
            'name' => $subject['subject_name'],
            'is_core' => $is_core,
            'total_scores' => count($subject_scores),
            'highest_score' => max($subject_scores),
            'lowest_score' => min($subject_scores),
            'avg_score' => array_sum($subject_scores) / count($subject_scores),
            'pass_rate' => ($pass_count / count($subject_scores)) * 100,
            'pass_count' => $pass_count,
            'fail_count' => count($subject_scores) - $pass_count,
            'grade_counts' => $grade_counts,
            'male_grade_counts' => $male_grade_counts,
            'female_grade_counts' => $female_grade_counts,
            'male_count' => count($male_scores),
            'female_count' => count($female_scores)
        ];
    }
}

// Calculate aggregates for each student
foreach ($students_data as &$student_data) {
    $core_grades = [];
    $elective_grades = [];
    
    foreach ($subjects as $subject) {
        if (isset($student_data['scores'][$subject['id']])) {
            $score = $student_data['scores'][$subject['id']];
            $grade = convertScoreToGhanaGrade($score);
            
            if (isset($class_stats['subjects'][$subject['id']]) && $class_stats['subjects'][$subject['id']]['is_core']) {
                $core_grades[] = $grade;
            } else {
                $elective_grades[] = $grade;
            }
        }
    }
    
    sort($core_grades);
    sort($elective_grades);
    
    $best_4_core = array_slice($core_grades, 0, 4);
    while (count($best_4_core) < 4) {
        $best_4_core[] = 9;
    }
    
    $best_2_electives = array_slice($elective_grades, 0, 2);
    while (count($best_2_electives) < 2) {
        $best_2_electives[] = 9;
    }
    
    $aggregate = array_sum($best_4_core) + array_sum($best_2_electives);
    $student_data['aggregate'] = $aggregate;
    
    $gender = strtoupper(substr($student_data['student']['gender'] ?? '', 0, 1));
    
    if ($aggregate >= 6 && $aggregate <= 12) {
        $aggregate_gender_distribution['6-12']['total']++;
        if ($gender == 'M') $aggregate_gender_distribution['6-12']['M']++;
        else if ($gender == 'F') $aggregate_gender_distribution['6-12']['F']++;
    } else if ($aggregate >= 13 && $aggregate <= 24) {
        $aggregate_gender_distribution['13-24']['total']++;
        if ($gender == 'M') $aggregate_gender_distribution['13-24']['M']++;
        else if ($gender == 'F') $aggregate_gender_distribution['13-24']['F']++;
    } else if ($aggregate >= 25 && $aggregate <= 36) {
        $aggregate_gender_distribution['25-36']['total']++;
        if ($gender == 'M') $aggregate_gender_distribution['25-36']['M']++;
        else if ($gender == 'F') $aggregate_gender_distribution['25-36']['F']++;
    } else if ($aggregate >= 37 && $aggregate <= 48) {
        $aggregate_gender_distribution['37-48']['total']++;
        if ($gender == 'M') $aggregate_gender_distribution['37-48']['M']++;
        else if ($gender == 'F') $aggregate_gender_distribution['37-48']['F']++;
    }
}
unset($student_data);

// Sort students by aggregate
usort($students_data, function($a, $b) {
    return $a['aggregate'] <=> $b['aggregate'];
});

// Flatten student data for easier access in PDF
$flattened_students = [];
foreach ($students_data as $student_data) {
    $flattened_students[] = [
        'full_name' => $student_data['student']['student_name'] ?? 'Unknown',
        'index_number' => $student_data['student']['candidate_index_number'] ?? 'N/A',
        'gender' => $student_data['student']['gender'] ?? '',
        'aggregate' => $student_data['aggregate'],
        'scores' => $student_data['scores']
    ];
}

$top_performers = array_slice($students_data, 0, 5);

try {
    // Generate HTML content for PDF
    ob_start();
    
    // Start building HTML
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <style>
            body { 
                font-family: 'DejaVu Sans', sans-serif; 
                font-size: 9pt; 
                margin: 0; 
                padding: 0;
                line-height: 1.4;
            }
            .header {
                text-align: center;
                margin-bottom: 10px;
                border-bottom: 2px solid #333;
                padding-bottom: 8px;
            }
            .header h1 {
                margin: 3px 0;
                font-size: 16pt;
                color: #2c3e50;
            }
            .header p {
                margin: 2px 0;
                font-size: 8pt;
            }
            .section-title {
                background-color: #667eea;
                color: white;
                padding: 6px;
                margin-top: 12px;
                margin-bottom: 8px;
                font-size: 11pt;
                font-weight: bold;
            }
            table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 10px;
                font-size: 8pt;
            }
            th {
                background-color: #f2f2f2;
                border: 1px solid #999;
                padding: 4px;
                text-align: left;
                font-weight: bold;
                font-size: 8pt;
            }
            td {
                border: 1px solid #ddd;
                padding: 4px;
            }
            tr:nth-child(even) {
                background-color: #f9f9f9;
            }
            .summary-grid {
                width: 100%;
                margin-bottom: 10px;
            }
            .summary-grid td {
                border: 1px solid #ddd;
                padding: 8px;
                text-align: center;
            }
            .summary-value {
                font-size: 16pt;
                font-weight: bold;
                color: #667eea;
            }
            .summary-label {
                font-size: 8pt;
                color: #666;
            }
            .grade-guide {
                background-color: #f8f9fa;
                padding: 6px;
                margin: 8px 0;
                border-radius: 3px;
                font-size: 7pt;
            }
            .text-center { text-align: center; }
            .text-success { color: #27ae60; font-weight: bold; }
            .text-danger { color: #e74c3c; font-weight: bold; }
            .text-primary { color: #667eea; font-weight: bold; }
            .footer {
                margin-top: 20px;
                padding-top: 10px;
                border-top: 1px solid #ddd;
                text-align: center;
                font-size: 7pt;
                color: #666;
            }
            .page-break {
                page-break-after: always;
            }
            .badge {
                padding: 2px 6px;
                border-radius: 3px;
                font-size: 7pt;
                font-weight: bold;
            }
            .badge-core { background-color: #667eea; color: white; }
            .badge-elective { background-color: #6c757d; color: white; }
            .grade-1-3 { background-color: #d4edda; }
            .grade-4-6 { background-color: #d1ecf1; }
            .grade-7-8 { background-color: #fff3cd; }
            .grade-9 { background-color: #f8d7da; }
        </style>
    </head>
    <body>
        <div class="header">
            <h1><?php echo htmlspecialchars($school_data['school_name'] ?? 'School'); ?></h1>
            <p><?php echo htmlspecialchars($school_data['address'] ?? ''); ?></p>
            <h1>BECE MOCK EXAMINATION DETAILED ANALYSIS</h1>
            <p><strong>Class:</strong> <?php echo htmlspecialchars($class_info['class_name']); ?> | 
               <strong>Academic Year:</strong> <?php echo htmlspecialchars($mock_settings['academic_year']); ?> | 
               <strong>Term:</strong> <?php echo htmlspecialchars($mock_settings['term']); ?></p>
            <p><strong>Generated:</strong> <?php echo date('F j, Y g:i A'); ?></p>
        </div>
        
        <div class="section-title">OVERALL CLASS SUMMARY</div>
        <table class="summary-grid">
            <tr>
                <td>
                    <div class="summary-value"><?php echo $total_candidates; ?></div>
                    <div class="summary-label">Total Candidates</div>
                </td>
                <td>
                    <div class="summary-value"><?php 
                        $total_passed = 0;
                        foreach ($flattened_students as $sd) {
                            if ($sd['aggregate'] <= 36) $total_passed++;
                        }
                        echo $total_passed;
                    ?></div>
                    <div class="summary-label">Passed (Agg ≤36)</div>
                </td>
                <td>
                    <div class="summary-value"><?php 
                        echo $total_candidates - $total_passed;
                    ?></div>
                    <div class="summary-label">Failed (Agg >36)</div>
                </td>
                <td>
                    <div class="summary-value"><?php 
                        echo $total_candidates > 0 ? number_format(($total_passed / $total_candidates) * 100, 1) : 0;
                    ?>%</div>
                    <div class="summary-label">Pass Rate</div>
                </td>
            </tr>
        </table>
        
        <div class="section-title">CLASS STATISTICS SUMMARY</div>
        <table>
            <tr>
                <th colspan="2" style="background-color: #667eea; color: white;">Performance Metrics</th>
            </tr>
            <tr>
                <td style="width: 60%;"><strong>Total Number of Candidates</strong></td>
                <td style="width: 40%;" class="text-center"><strong><?php echo $total_candidates; ?></strong></td>
            </tr>
            <tr>
                <td><strong>Candidates with Aggregate ≤36 (Pass)</strong></td>
                <td class="text-center text-success"><strong><?php echo $total_passed; ?> (<?php echo $total_candidates > 0 ? number_format(($total_passed / $total_candidates) * 100, 1) : 0; ?>%)</strong></td>
            </tr>
            <tr>
                <td><strong>Candidates with Aggregate >36 (Fail)</strong></td>
                <td class="text-center text-danger"><strong><?php echo $total_candidates - $total_passed; ?> (<?php echo $total_candidates > 0 ? number_format((($total_candidates - $total_passed) / $total_candidates) * 100, 1) : 0; ?>%)</strong></td>
            </tr>
            <tr>
                <td><strong>Best Aggregate Score</strong></td>
                <td class="text-center"><strong><?php echo $flattened_students[0]['aggregate'] ?? 'N/A'; ?></strong></td>
            </tr>
            <tr>
                <td><strong>Worst Aggregate Score</strong></td>
                <td class="text-center"><strong><?php echo end($flattened_students)['aggregate'] ?? 'N/A'; ?></strong></td>
            </tr>
            <?php
            $avg_aggregate = 0;
            foreach ($flattened_students as $s) {
                $avg_aggregate += $s['aggregate'];
            }
            $avg_aggregate = $total_candidates > 0 ? $avg_aggregate / $total_candidates : 0;
            ?>
            <tr>
                <td><strong>Average Class Aggregate</strong></td>
                <td class="text-center"><strong><?php echo number_format($avg_aggregate, 2); ?></strong></td>
            </tr>
        </table>
        
        <div class="section-title">GENDER DISTRIBUTION</div>
        <table>
            <tr>
                <th style="width: 50%;">Gender</th>
                <th style="width: 25%;">Count</th>
                <th style="width: 25%;">Percentage</th>
            </tr>
            <?php
            $male_total = 0;
            $female_total = 0;
            foreach ($flattened_students as $student) {
                $gender = strtoupper(substr($student['gender'] ?? '', 0, 1));
                if ($gender == 'M') $male_total++;
                else if ($gender == 'F') $female_total++;
            }
            $male_pct = $total_candidates > 0 ? ($male_total / $total_candidates) * 100 : 0;
            $female_pct = $total_candidates > 0 ? ($female_total / $total_candidates) * 100 : 0;
            ?>
            <tr>
                <td><strong>♂ Male Students</strong></td>
                <td class="text-center"><strong><?php echo $male_total; ?></strong></td>
                <td class="text-center"><strong><?php echo number_format($male_pct, 1); ?>%</strong></td>
            </tr>
            <tr>
                <td><strong>♀ Female Students</strong></td>
                <td class="text-center"><strong><?php echo $female_total; ?></strong></td>
                <td class="text-center"><strong><?php echo number_format($female_pct, 1); ?>%</strong></td>
            </tr>
        </table>
        
        <div class="section-title">AGGREGATE DISTRIBUTION ANALYSIS</div>
        <table>
            <tr>
                <th>Aggregate Range</th>
                <th>Category</th>
                <th>Male</th>
                <th>Male %</th>
                <th>Female</th>
                <th>Female %</th>
                <th>Total</th>
                <th>Total %</th>
            </tr>
            <?php
            $aggregate_ranges = [
                '6-12' => 'Highest',
                '13-24' => 'Higher',
                '25-36' => 'High',
                '37-48' => 'Low'
            ];
            foreach ($aggregate_ranges as $range => $category):
                $male_count = $aggregate_gender_distribution[$range]['M'] ?? 0;
                $female_count = $aggregate_gender_distribution[$range]['F'] ?? 0;
                $total_count = $male_count + $female_count;
                $male_pct = $total_candidates > 0 ? ($male_count / $total_candidates) * 100 : 0;
                $female_pct = $total_candidates > 0 ? ($female_count / $total_candidates) * 100 : 0;
                $total_pct = $total_candidates > 0 ? ($total_count / $total_candidates) * 100 : 0;
                
                $row_class = '';
                if ($category == 'Highest') $row_class = 'grade-1-3';
                elseif ($category == 'Higher') $row_class = 'grade-4-6';
                elseif ($category == 'High') $row_class = 'grade-7-8';
                else $row_class = 'grade-9';
            ?>
            <tr class="<?php echo $row_class; ?>">
                <td><strong><?php echo $range; ?></strong></td>
                <td><?php echo $category; ?></td>
                <td class="text-center"><?php echo $male_count; ?></td>
                <td class="text-center"><?php echo number_format($male_pct, 1); ?>%</td>
                <td class="text-center"><?php echo $female_count; ?></td>
                <td class="text-center"><?php echo number_format($female_pct, 1); ?>%</td>
                <td class="text-center"><strong><?php echo $total_count; ?></strong></td>
                <td class="text-center"><strong><?php echo number_format($total_pct, 1); ?>%</strong></td>
            </tr>
            <?php endforeach; ?>
        </table>
        
        <div class="grade-guide">
            <strong>BECE Grading System:</strong> 
            Grade 1 (90-100% Highest) | Grade 2 (80-89% Higher) | Grade 3 (70-79% High) | 
            Grade 4 (60-69% High Average) | Grade 5 (55-59% Average) | Grade 6 (50-54% Low Average) | 
            Grade 7 (40-49% Low) | Grade 8 (35-39% Lower) | Grade 9 (0-34% Lowest/Fail)
        </div>
        
        <div class="page-break"></div>
        
        <div class="section-title">ALL SUBJECTS PERFORMANCE OVERVIEW</div>
        <p style="margin: 5px 0; font-size: 8pt; color: #666;">
            Comprehensive performance analysis across all subjects before detailed breakdowns.
        </p>
        
        <table>
            <tr>
                <th style="width: 5%;">No.</th>
                <th style="width: 25%;">Subject Name</th>
                <th style="width: 10%;">Type</th>
                <th style="width: 10%;">Candidates</th>
                <th style="width: 12%;">Avg Score</th>
                <th style="width: 10%;">Avg Grade</th>
                <th style="width: 14%;">Pass Rate</th>
                <th style="width: 14%;">Performance</th>
            </tr>
            <?php
            $subject_num = 1;
            foreach ($subjects as $subject):
                $subject_id = $subject['id'];
                if (!isset($class_stats['subjects'][$subject_id])) continue;
                
                $stats = $class_stats['subjects'][$subject_id];
                if ($stats['total_scores'] == 0) continue;
                
                $avg_grade = convertScoreToGhanaGrade($stats['avg_score']);
                $row_class = '';
                if ($avg_grade <= 3) $row_class = 'grade-1-3';
                elseif ($avg_grade <= 6) $row_class = 'grade-4-6';
                elseif ($avg_grade <= 8) $row_class = 'grade-7-8';
                else $row_class = 'grade-9';
            ?>
            <tr class="<?php echo $row_class; ?>">
                <td class="text-center"><?php echo $subject_num; ?></td>
                <td><?php echo htmlspecialchars($subject['subject_name']); ?></td>
                <td class="text-center">
                    <span class="badge <?php echo $stats['is_core'] ? 'badge-core' : 'badge-elective'; ?>">
                        <?php echo $stats['is_core'] ? 'CORE' : 'ELECT'; ?>
                    </span>
                </td>
                <td class="text-center"><?php echo $stats['total_scores']; ?></td>
                <td class="text-center"><strong><?php echo number_format($stats['avg_score'], 1); ?>%</strong></td>
                <td class="text-center"><strong><?php echo $avg_grade; ?></strong></td>
                <td class="text-center" style="<?php echo $stats['pass_rate'] >= 50 ? 'color: #27ae60;' : 'color: #e74c3c;'; ?>">
                    <strong><?php echo number_format($stats['pass_rate'], 1); ?>%</strong>
                </td>
                <td><?php echo getGradeLabel($avg_grade); ?></td>
            </tr>
            <?php
                $subject_num++;
            endforeach;
            ?>
        </table>
        
        <div class="section-title" style="margin-top: 15px;">SUBJECT-BY-SUBJECT DETAILED ANALYSIS</div>
        <p style="margin: 5px 0; font-size: 8pt; color: #666;">
            Complete analysis for each subject showing all candidates' performance and grade distributions.
        </p>
        
        <div class="page-break"></div>
        
        <div class="section-title">INDIVIDUAL SUBJECT CANDIDATE ANALYSIS</div>
        <p style="margin: 5px 0; font-size: 8pt; color: #666;">
            This section shows all candidates' performance for each subject with detailed breakdown.
        </p>
        
        <?php foreach ($subjects as $subject):
            $subject_id = $subject['id'];
            if (!isset($class_stats['subjects'][$subject_id])) continue;
            
            $stats = $class_stats['subjects'][$subject_id];
            $total_scores = $stats['total_scores'];
            if ($total_scores == 0) continue;
        ?>
        
        <h3 style="margin: 10px 0 5px 0; color: #2c3e50; font-size: 10pt;">
            <?php echo htmlspecialchars($subject['subject_name']); ?>
            <span class="badge <?php echo $stats['is_core'] ? 'badge-core' : 'badge-elective'; ?>">
                <?php echo $stats['is_core'] ? 'CORE SUBJECT' : 'ELECTIVE SUBJECT'; ?>
            </span>
        </h3>
        
        
        <p style="margin: 8px 0 5px 0; font-weight: bold; font-size: 9pt;">Score Range Distribution:</p>
        <table>
            <tr>
                <th style="width: 15%;">Score Range</th>
                <th style="width: 15%;">Grade</th>
                <th style="width: 20%;">Performance</th>
                <th style="width: 12%;">Total</th>
                <th style="width: 12%;">Male</th>
                <th style="width: 12%;">Female</th>
                <th style="width: 14%;">Percentage</th>
            </tr>
            <?php
            $score_ranges = [
                ['min' => 90, 'max' => 100, 'label' => '90-100%', 'grade' => 1],
                ['min' => 80, 'max' => 89, 'label' => '80-89%', 'grade' => 2],
                ['min' => 70, 'max' => 79, 'label' => '70-79%', 'grade' => 3],
                ['min' => 60, 'max' => 69, 'label' => '60-69%', 'grade' => 4],
                ['min' => 55, 'max' => 59, 'label' => '55-59%', 'grade' => 5],
                ['min' => 50, 'max' => 54, 'label' => '50-54%', 'grade' => 6],
                ['min' => 40, 'max' => 49, 'label' => '40-49%', 'grade' => 7],
                ['min' => 35, 'max' => 39, 'label' => '35-39%', 'grade' => 8],
                ['min' => 0, 'max' => 34, 'label' => '0-34%', 'grade' => 9]
            ];
            
            foreach ($score_ranges as $range):
                $count = $stats['grade_counts'][$range['grade']];
                $male_count = $stats['male_grade_counts'][$range['grade']];
                $female_count = $stats['female_grade_counts'][$range['grade']];
                $percentage = $total_scores > 0 ? ($count / $total_scores) * 100 : 0;
                
                $row_class = '';
                if ($range['grade'] <= 3) $row_class = 'grade-1-3';
                elseif ($range['grade'] <= 6) $row_class = 'grade-4-6';
                elseif ($range['grade'] <= 8) $row_class = 'grade-7-8';
                else $row_class = 'grade-9';
            ?>
            <tr class="<?php echo $row_class; ?>">
                <td class="text-center"><strong><?php echo $range['label']; ?></strong></td>
                <td class="text-center"><strong><?php echo $range['grade']; ?></strong></td>
                <td><?php echo getGradeLabel($range['grade']); ?></td>
                <td class="text-center"><strong><?php echo $count; ?></strong></td>
                <td class="text-center"><?php echo $male_count; ?></td>
                <td class="text-center"><?php echo $female_count; ?></td>
                <td class="text-center"><strong><?php echo number_format($percentage, 1); ?>%</strong></td>
            </tr>
            <?php endforeach; ?>
        </table>
        
        <table style="margin-top: 5px; margin-bottom: 15px;">
            <tr>
                <th colspan="2" style="background-color: #667eea; color: white;">Subject Statistics Summary</th>
            </tr>
            <tr>
                <td style="width: 50%;"><strong>Total Candidates:</strong></td>
                <td style="width: 50%;"><strong><?php echo $total_scores; ?></strong></td>
            </tr>
            <tr>
                <td><strong>Average Score:</strong></td>
                <td><?php echo number_format($stats['avg_score'], 1); ?>% (Grade <?php echo convertScoreToGhanaGrade($stats['avg_score']); ?>)</td>
            </tr>
            <tr>
                <td><strong>Highest Score:</strong></td>
                <td><?php echo number_format($stats['highest_score'], 1); ?>%</td>
            </tr>
            <tr>
                <td><strong>Lowest Score:</strong></td>
                <td><?php echo number_format($stats['lowest_score'], 1); ?>%</td>
            </tr>
            <tr class="<?php echo $stats['pass_rate'] >= 50 ? 'grade-1-3' : 'grade-9'; ?>">
                <td><strong>Pass Rate (Grades 1-6):</strong></td>
                <td><strong><?php echo number_format($stats['pass_rate'], 1); ?>%</strong> (<?php echo $stats['pass_count']; ?> passed, <?php echo $stats['fail_count']; ?> failed)</td>
            </tr>
            <tr>
                <td><strong>Male Candidates:</strong></td>
                <td><?php echo $stats['male_count']; ?> students</td>
            </tr>
            <tr>
                <td><strong>Female Candidates:</strong></td>
                <td><?php echo $stats['female_count']; ?> students</td>
            </tr>
        </table>
        
        <?php endforeach; ?>
        
        <div class="page-break"></div>
        
        <div class="section-title">DETAILED SUBJECT ANALYSIS</div>
        
        <?php foreach ($subjects as $subject):
            $subject_id = $subject['id'];
            if (!isset($class_stats['subjects'][$subject_id])) continue;
            
            $stats = $class_stats['subjects'][$subject_id];
            $total_scores = $stats['total_scores'];
            if ($total_scores == 0) continue;
        ?>
        
        <h3 style="margin: 10px 0 5px 0; color: #2c3e50;">
            <?php echo htmlspecialchars($subject['subject_name']); ?>
            <span class="badge <?php echo $stats['is_core'] ? 'badge-core' : 'badge-elective'; ?>">
                <?php echo $stats['is_core'] ? 'CORE' : 'ELECTIVE'; ?>
            </span>
        </h3>
        
        <table>
            <tr>
                <th>Average Score</th>
                <th>Average Grade</th>
                <th>Highest Score</th>
                <th>Lowest Score</th>
                <th>Pass Rate (1-6)</th>
            </tr>
            <tr>
                <td><?php echo number_format($stats['avg_score'], 1); ?>%</td>
                <td><?php 
                    $avg_grade = convertScoreToGhanaGrade($stats['avg_score']);
                    echo $avg_grade . ' (' . getGradeLabel($avg_grade) . ')';
                ?></td>
                <td><?php echo number_format($stats['highest_score'], 1); ?>%</td>
                <td><?php echo number_format($stats['lowest_score'], 1); ?>%</td>
                <td class="<?php echo $stats['pass_rate'] >= 50 ? 'text-success' : 'text-danger'; ?>">
                    <?php echo number_format($stats['pass_rate'], 1); ?>%
                </td>
            </tr>
        </table>
        
        <p style="margin: 8px 0 5px 0; font-weight: bold; font-size: 9pt;">Complete Grade Breakdown:</p>
        <table>
            <tr>
                <th rowspan="2" style="width: 8%; vertical-align: middle;">Grade</th>
                <th rowspan="2" style="width: 15%; vertical-align: middle;">Performance Level</th>
                <th rowspan="2" style="width: 8%; vertical-align: middle;">Score Range</th>
                <th colspan="3" style="background-color: #e3f2fd;">Total Students</th>
                <th colspan="3" style="background-color: #fce4ec;">Male Students</th>
                <th colspan="3" style="background-color: #fff3e0;">Female Students</th>
            </tr>
            <tr>
                <th style="width: 6%; background-color: #e3f2fd;">No.</th>
                <th style="width: 7%; background-color: #e3f2fd;">%</th>
                <th style="width: 10%; background-color: #e3f2fd;">Of Total</th>
                <th style="width: 6%; background-color: #fce4ec;">No.</th>
                <th style="width: 7%; background-color: #fce4ec;">%</th>
                <th style="width: 10%; background-color: #fce4ec;">Of Grade</th>
                <th style="width: 6%; background-color: #fff3e0;">No.</th>
                <th style="width: 7%; background-color: #fff3e0;">%</th>
                <th style="width: 10%; background-color: #fff3e0;">Of Grade</th>
            </tr>
            <?php
            $grade_ranges = [
                1 => '90-100%', 2 => '80-89%', 3 => '70-79%',
                4 => '60-69%', 5 => '55-59%', 6 => '50-54%',
                7 => '40-49%', 8 => '35-39%', 9 => '0-34%'
            ];
            
            for ($grade = 1; $grade <= 9; $grade++):
                $count = $stats['grade_counts'][$grade];
                $percentage = $total_scores > 0 ? ($count / $total_scores) * 100 : 0;
                $male_count = $stats['male_grade_counts'][$grade];
                $female_count = $stats['female_grade_counts'][$grade];
                
                // Calculate percentages
                $male_pct_of_total = $total_scores > 0 ? ($male_count / $total_scores) * 100 : 0;
                $female_pct_of_total = $total_scores > 0 ? ($female_count / $total_scores) * 100 : 0;
                $male_pct_of_grade = $count > 0 ? ($male_count / $count) * 100 : 0;
                $female_pct_of_grade = $count > 0 ? ($female_count / $count) * 100 : 0;
                
                $row_class = '';
                if ($grade <= 3) $row_class = 'grade-1-3';
                elseif ($grade <= 6) $row_class = 'grade-4-6';
                elseif ($grade <= 8) $row_class = 'grade-7-8';
                else $row_class = 'grade-9';
            ?>
            <tr class="<?php echo $row_class; ?>">
                <td class="text-center"><strong><?php echo $grade; ?></strong></td>
                <td><?php echo getGradeLabel($grade); ?></td>
                <td class="text-center" style="font-size: 7pt;"><?php echo $grade_ranges[$grade]; ?></td>
                <td class="text-center"><strong><?php echo $count; ?></strong></td>
                <td class="text-center"><strong><?php echo number_format($percentage, 1); ?>%</strong></td>
                <td style="font-size: 7pt;"><?php echo $count; ?> of <?php echo $total_scores; ?></td>
                <td class="text-center"><?php echo $male_count; ?></td>
                <td class="text-center"><?php echo number_format($male_pct_of_total, 1); ?>%</td>
                <td style="font-size: 7pt;"><?php echo number_format($male_pct_of_grade, 1); ?>% of grade</td>
                <td class="text-center"><?php echo $female_count; ?></td>
                <td class="text-center"><?php echo number_format($female_pct_of_total, 1); ?>%</td>
                <td style="font-size: 7pt;"><?php echo number_format($female_pct_of_grade, 1); ?>% of grade</td>
            </tr>
            <?php endfor; ?>
        </table>
        
        <p style="margin: 8px 0 5px 0; font-weight: bold; font-size: 9pt;">Performance Summary:</p>
        <table>
            <tr>
                <th style="width: 30%;">Category</th>
                <th style="width: 15%;">Total</th>
                <th style="width: 15%;">Percentage</th>
                <th style="width: 12%;">Boys</th>
                <th style="width: 12%;">Girls</th>
                <th style="width: 16%;">Breakdown</th>
            </tr>
            <?php
            $pass_count = 0;
            $pass_boys = 0;
            $pass_girls = 0;
            $fail_count = 0;
            $fail_boys = 0;
            $fail_girls = 0;
            
            for ($g = 1; $g <= 6; $g++) {
                $pass_count += $stats['grade_counts'][$g];
                $pass_boys += $stats['male_grade_counts'][$g];
                $pass_girls += $stats['female_grade_counts'][$g];
            }
            for ($g = 7; $g <= 9; $g++) {
                $fail_count += $stats['grade_counts'][$g];
                $fail_boys += $stats['male_grade_counts'][$g];
                $fail_girls += $stats['female_grade_counts'][$g];
            }
            $pass_percentage = ($pass_count / $total_scores) * 100;
            $fail_percentage = ($fail_count / $total_scores) * 100;
            ?>
            <tr class="grade-1-3">
                <td><strong>Pass Rate (Grades 1-6)</strong></td>
                <td class="text-center"><strong><?php echo $pass_count; ?></strong></td>
                <td class="text-center"><strong><?php echo number_format($pass_percentage, 1); ?>%</strong></td>
                <td class="text-center"><?php echo $pass_boys; ?></td>
                <td class="text-center"><?php echo $pass_girls; ?></td>
                <td style="font-size: 7pt;"><?php echo $pass_boys; ?> boys, <?php echo $pass_girls; ?> girls</td>
            </tr>
            <tr class="grade-9">
                <td><strong>Fail Rate (Grades 7-9)</strong></td>
                <td class="text-center"><strong><?php echo $fail_count; ?></strong></td>
                <td class="text-center"><strong><?php echo number_format($fail_percentage, 1); ?>%</strong></td>
                <td class="text-center"><?php echo $fail_boys; ?></td>
                <td class="text-center"><?php echo $fail_girls; ?></td>
                <td style="font-size: 7pt;"><?php echo $fail_boys; ?> boys, <?php echo $fail_girls; ?> girls</td>
            </tr>
        </table>
        
        <p style="margin: 8px 0 5px 0; font-weight: bold; font-size: 9pt;">Top Grade Achievers (Grades 1-3):</p>
        <table>
            <tr>
                <th style="width: 15%;">Grade</th>
                <th style="width: 25%;">Label</th>
                <th style="width: 15%;">Total</th>
                <th style="width: 15%;">Percentage</th>
                <th style="width: 12%;">Boys</th>
                <th style="width: 12%;">Girls</th>
            </tr>
            <?php
            for ($g = 1; $g <= 3; $g++) {
                $g_count = $stats['grade_counts'][$g];
                if ($g_count > 0) {
                    $g_pct = ($g_count / $total_scores) * 100;
                    $g_boys = $stats['male_grade_counts'][$g];
                    $g_girls = $stats['female_grade_counts'][$g];
                    $grade_label = getGradeLabel($g);
            ?>
            <tr class="grade-1-3">
                <td class="text-center"><strong>Grade <?php echo $g; ?></strong></td>
                <td><?php echo $grade_label; ?></td>
                <td class="text-center"><strong><?php echo $g_count; ?></strong></td>
                <td class="text-center"><strong><?php echo number_format($g_pct, 1); ?>%</strong></td>
                <td class="text-center"><?php echo $g_boys; ?></td>
                <td class="text-center"><?php echo $g_girls; ?></td>
            </tr>
            <?php
                }
            }
            ?>
        </table>
        
        <p style="margin: 5px 0; font-size: 7pt; color: #666;">
            <?php if ($stats['is_core']): ?>
            <strong>Note:</strong> This is a <strong>CORE</strong> subject and counts toward the student's aggregate score.
            <?php else: ?>
            <strong>Note:</strong> This is an <strong>ELECTIVE</strong> subject. Only the best 2 elective grades count toward the aggregate.
            <?php endif; ?>
        </p>
        
        <?php endforeach; ?>
        
        <div class="page-break"></div>
        
        <div class="section-title">TOP 10 PERFORMERS</div>
        <table>
            <tr>
                <th style="width: 8%;">Rank</th>
                <th style="width: 37%;">Name</th>
                <th style="width: 20%;">Index Number</th>
                <th style="width: 10%;">Aggregate</th>
                <th style="width: 25%;">Category</th>
            </tr>
            <?php
            $rank = 1;
            foreach ($flattened_students as $student):
                if ($rank > 10) break;
                $aggregate = $student['aggregate'];
                $agg_category = '';
                if ($aggregate >= 6 && $aggregate <= 12) $agg_category = 'Highest';
                elseif ($aggregate <= 24) $agg_category = 'Higher';
                elseif ($aggregate <= 36) $agg_category = 'High';
                else $agg_category = 'Low';
                
                $row_class = '';
                if ($agg_category == 'Highest') $row_class = 'grade-1-3';
                elseif ($agg_category == 'Higher') $row_class = 'grade-4-6';
                elseif ($agg_category == 'High') $row_class = 'grade-7-8';
                else $row_class = 'grade-9';
            ?>
            <tr class="<?php echo $row_class; ?>">
                <td class="text-center"><strong><?php echo $rank; ?></strong></td>
                <td><?php echo htmlspecialchars($student['full_name']); ?></td>
                <td><?php echo htmlspecialchars($student['index_number']); ?></td>
                <td class="text-center"><strong><?php echo $aggregate; ?></strong></td>
                <td><?php echo $agg_category; ?></td>
            </tr>
            <?php
                $rank++;
            endforeach;
            ?>
        </table>
        
        <div class="page-break"></div>
        
        <div class="section-title">COMPLETE CANDIDATE RANKING</div>
        <table>
            <tr>
                <th style="width: 6%;">Rank</th>
                <th style="width: 28%;">Name</th>
                <th style="width: 16%;">Index Number</th>
                <th style="width: 8%;">Gender</th>
                <th style="width: 10%;">Aggregate</th>
                <th style="width: 16%;">Category</th>
                <th style="width: 16%;">Status</th>
            </tr>
            <?php
            $rank = 1;
            foreach ($flattened_students as $student):
                $aggregate = $student['aggregate'];
                $agg_category = '';
                if ($aggregate >= 6 && $aggregate <= 12) $agg_category = 'Highest';
                elseif ($aggregate <= 24) $agg_category = 'Higher';
                elseif ($aggregate <= 36) $agg_category = 'High';
                else $agg_category = 'Low';
                
                $status = $aggregate <= 36 ? 'Passed' : 'Failed';
                
                $row_class = '';
                if ($agg_category == 'Highest') $row_class = 'grade-1-3';
                elseif ($agg_category == 'Higher') $row_class = 'grade-4-6';
                elseif ($agg_category == 'High') $row_class = 'grade-7-8';
                else $row_class = 'grade-9';
                
                $gender_display = strtoupper(substr($student['gender'] ?? '', 0, 1)) == 'M' ? 'Male' : 'Female';
            ?>
            <tr class="<?php echo $row_class; ?>">
                <td class="text-center"><?php echo $rank; ?></td>
                <td><?php echo htmlspecialchars($student['full_name']); ?></td>
                <td><?php echo htmlspecialchars($student['index_number']); ?></td>
                <td class="text-center"><?php echo $gender_display; ?></td>
                <td class="text-center"><strong><?php echo $aggregate; ?></strong></td>
                <td><?php echo $agg_category; ?></td>
                <td class="<?php echo $status == 'Passed' ? 'text-success' : 'text-danger'; ?>">
                    <strong><?php echo $status; ?></strong>
                </td>
            </tr>
            <?php
                $rank++;
            endforeach;
            ?>
        </table>
        
        <div class="footer">
            <p><strong>Report Generated:</strong> <?php echo date('l, F j, Y g:i A'); ?></p>
            <p>This is a computer-generated document from the School Based Assessment System</p>
            <p><strong>BECE Mock Examination - Detailed Statistical Analysis</strong></p>
        </div>
    </body>
    </html>
    <?php
    
    $html = ob_get_clean();
    
    // Initialize mPDF
    $mpdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8',
        'format' => 'A4',
        'orientation' => 'P',
        'margin_left' => 15,
        'margin_right' => 15,
        'margin_top' => 15,
        'margin_bottom' => 15,
        'margin_header' => 5,
        'margin_footer' => 5
    ]);
    
    // Set document metadata
    $mpdf->SetTitle('BECE Mock Exam Analysis - ' . $class_info['class_name']);
    $mpdf->SetAuthor($school_data['school_name'] ?? 'School');
    $mpdf->SetSubject('Mock Examination Analysis Report');
    
    // Write HTML to PDF
    $mpdf->WriteHTML($html);
    
    // Output PDF
    $filename = 'BECE_Mock_Analysis_' . $class_info['class_name'] . '_' . date('Y-m-d') . '.pdf';
    $mpdf->Output($filename, \Mpdf\Output\Destination::INLINE);
    
} catch (Exception $e) {
    error_log("PDF Generation Error: " . $e->getMessage());
    die("Error generating PDF: " . $e->getMessage());
}
