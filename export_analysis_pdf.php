<?php
session_start();
require_once 'config/config.php';
require_once 'config/database.php';
require_once 'vendor/autoload.php';

// Check if mPDF is installed
if (!class_exists('\Mpdf\Mpdf')) {
    die('mPDF library not found. Please run: composer require mpdf/mpdf');
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    die('Unauthorized access');
}

$db = Database::getInstance()->getConnection();

// Get school information
$school_id = $_SESSION['school_id'] ?? null;
$stmt = $db->prepare("SELECT * FROM school_info WHERE id = ?");
$stmt->execute([$school_id]);
$school_info = $stmt->fetch(PDO::FETCH_ASSOC);
$school_id = $school_info['id'] ?? null;

if (!$school_id) {
    die('School information not found');
}

// Get current academic year and term
$settings_query = "SELECT * FROM system_settings LIMIT 1";
$settings_stmt = $db->prepare($settings_query);
$settings_stmt->execute();
$system_settings = $settings_stmt->fetch(PDO::FETCH_ASSOC);

$academic_year = $_GET['year'] ?? ($system_settings['current_academic_year'] ?? date('Y'));
$term = $_GET['term'] ?? ($system_settings['current_term'] ?? 'Term 1');

// Get all classes for this school
$classes_query = "SELECT id, class_name FROM classes WHERE school_id = ? ORDER BY class_name";
$classes_stmt = $db->prepare($classes_query);
$classes_stmt->execute([$school_id]);
$classes = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);

$selected_class_id = $_GET['class_id'] ?? ($classes[0]['id'] ?? null);

// SECURITY: Verify the selected class belongs to this school
if ($selected_class_id) {
    $verify_class = $db->prepare("SELECT id, school_id, class_name FROM classes WHERE id = ?");
    $verify_class->execute([$selected_class_id]);
    $class_check = $verify_class->fetch(PDO::FETCH_ASSOC);
    
    if (!$class_check || $class_check['school_id'] != $school_id) {
        die('Unauthorized access to class data');
    }
    $class_name = $class_check['class_name'];
}

// Helper functions
function convertScoreToGrade($score) {
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

function getGradeLabel($grade) {
    $labels = [1 => 'Highest', 2 => 'Higher', 3 => 'High', 4 => 'High Average', 
               5 => 'Average', 6 => 'Low Average', 7 => 'Low', 8 => 'Lower', 9 => 'Lowest'];
    return $labels[$grade] ?? 'N/A';
}

function getScoreRange($grade) {
    $ranges = [1 => '90-100%', 2 => '80-89%', 3 => '70-79%', 4 => '60-69%',
               5 => '55-59%', 6 => '50-54%', 7 => '40-49%', 8 => '35-39%', 9 => '0-34%'];
    return $ranges[$grade] ?? 'N/A';
}

function getAggregateCategory($aggregate) {
    if ($aggregate >= 6 && $aggregate <= 12) return 'Highest';
    if ($aggregate >= 13 && $aggregate <= 24) return 'Higher';
    if ($aggregate >= 25 && $aggregate <= 36) return 'High';
    if ($aggregate >= 37 && $aggregate <= 48) return 'Low';
    return 'Lowest';
}

// Get subjects for selected class
$subjects = [];
$core_subjects = [];
$elective_subjects = [];

if ($selected_class_id) {
    $subjects_query = "SELECT s.*, IF(s.is_core = 1, 'Core', 'Elective') as subject_type 
                      FROM subjects s
                      WHERE s.class_id = ? 
                      ORDER BY s.is_core DESC, s.display_order, s.subject_name";
    $subjects_stmt = $db->prepare($subjects_query);
    $subjects_stmt->execute([$selected_class_id]);
    $subjects = $subjects_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($subjects as $subject) {
        $type = strtoupper($subject['subject_type'] ?? 'ELECTIVE');
        if ($type === 'CORE') {
            $core_subjects[] = $subject;
        } else {
            $elective_subjects[] = $subject;
        }
    }
}

// Get students with scores
$students_data = [];

if ($selected_class_id) {
    $students_query = "SELECT s.id, s.student_id, s.student_name, s.gender, s.candidate_index_number 
                      FROM students s
                      JOIN classes c ON s.class_id = c.id
                      WHERE s.class_id = ? AND c.school_id = ?
                      ORDER BY s.student_name";
    $students_stmt = $db->prepare($students_query);
    $students_stmt->execute([$selected_class_id, $school_id]);
    $all_students = $students_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Process each student
    foreach ($all_students as $student) {
        $student_scores = [];
        $has_scores = false;
        $has_index = !empty($student['candidate_index_number']);
        
        // Get scores for all subjects
        foreach ($subjects as $subject) {
            $score_query = "SELECT class_score as score FROM scores 
                          WHERE student_id = ? AND subject_id = ?
                          AND academic_year = ? AND term = ?";
            $score_stmt = $db->prepare($score_query);
            $score_stmt->execute([$student['id'], $subject['id'], $academic_year, $term]);
            $score_data = $score_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($score_data && $score_data['score'] !== null) {
                $score = floatval($score_data['score']);
                $grade = convertScoreToGrade($score);
                $remark = getGradeLabel($grade);
                
                $student_scores[$subject['id']] = [
                    'score' => $score,
                    'grade' => $grade,
                    'remark' => $remark,
                    'subject_name' => $subject['subject_name'],
                    'subject_type' => strtoupper($subject['subject_type'] ?? 'ELECTIVE')
                ];
                $has_scores = true;
            }
        }
        
        // Only include students with index numbers and scores
        if ($has_index && $has_scores) {
            // Calculate aggregate (4 core + 2 best electives)
            $core_grades = [];
            $elective_grades = [];
            
            foreach ($student_scores as $subj_id => $score_info) {
                if ($score_info['subject_type'] === 'CORE') {
                    $core_grades[] = $score_info['grade'];
                } else {
                    $elective_grades[] = $score_info['grade'];
                }
            }
            
            // Sort electives to get best 2
            sort($elective_grades);
            $best_electives = array_slice($elective_grades, 0, 2);
            
            // Calculate aggregate
            $aggregate = array_sum($core_grades) + array_sum($best_electives);
            
            $students_data[] = [
                'student' => $student,
                'scores' => $student_scores,
                'aggregate' => $aggregate,
                'category' => getAggregateCategory($aggregate),
                'status' => ($aggregate <= 36) ? 'Passed' : 'Failed'
            ];
        }
    }
}

// Sort students by aggregate
usort($students_data, function($a, $b) {
    return $a['aggregate'] - $b['aggregate'];
});

// Calculate overall statistics
$total_candidates = count($students_data);
$male_count = 0;
$female_count = 0;
$pass_count = 0;
$fail_count = 0;
$total_aggregate = 0;
$best_aggregate = $total_candidates > 0 ? $students_data[0]['aggregate'] : 0;
$worst_aggregate = $total_candidates > 0 ? $students_data[count($students_data) - 1]['aggregate'] : 0;

// Aggregate distribution
$aggregate_distribution = [
    '6-12' => ['M' => 0, 'F' => 0],
    '13-24' => ['M' => 0, 'F' => 0],
    '25-36' => ['M' => 0, 'F' => 0],
    '37-48' => ['M' => 0, 'F' => 0],
];

foreach ($students_data as $data) {
    $gender = strtoupper($data['student']['gender']);
    $gender_key = ($gender === 'MALE' || $gender === 'M') ? 'M' : 'F';
    
    if ($gender_key === 'M') $male_count++;
    else $female_count++;
    
    if ($data['aggregate'] <= 36) $pass_count++;
    else $fail_count++;
    
    $total_aggregate += $data['aggregate'];
    
    // Categorize aggregate
    $agg = $data['aggregate'];
    if ($agg >= 6 && $agg <= 12) $aggregate_distribution['6-12'][$gender_key]++;
    elseif ($agg >= 13 && $agg <= 24) $aggregate_distribution['13-24'][$gender_key]++;
    elseif ($agg >= 25 && $agg <= 36) $aggregate_distribution['25-36'][$gender_key]++;
    elseif ($agg >= 37 && $agg <= 48) $aggregate_distribution['37-48'][$gender_key]++;
}

$average_aggregate = $total_candidates > 0 ? $total_aggregate / $total_candidates : 0;
$pass_rate = $total_candidates > 0 ? ($pass_count / $total_candidates) * 100 : 0;

// Subject analysis
$subject_analysis = [];

foreach ($subjects as $subject) {
    $subj_id = $subject['id'];
    $subj_name = $subject['subject_name'];
    $subj_type = strtoupper($subject['subject_type'] ?? 'ELECTIVE');
    
    $candidates_count = 0;
    $total_score = 0;
    $total_grade = 0;
    $pass_count_subj = 0;
    $highest_score = 0;
    $lowest_score = 100;
    
    // Grade distribution for this subject
    $grade_dist = [];
    for ($g = 1; $g <= 9; $g++) {
        $grade_dist[$g] = ['total' => 0, 'male' => 0, 'female' => 0];
    }
    
    foreach ($students_data as $data) {
        if (isset($data['scores'][$subj_id])) {
            $score_info = $data['scores'][$subj_id];
            $score = $score_info['score'];
            $grade = $score_info['grade'];
            
            $gender = strtoupper($data['student']['gender']);
            $gender_key = ($gender === 'MALE' || $gender === 'M') ? 'male' : 'female';
            
            $candidates_count++;
            $total_score += $score;
            $total_grade += $grade;
            
            if ($grade <= 6) $pass_count_subj++;
            if ($score > $highest_score) $highest_score = $score;
            if ($score < $lowest_score) $lowest_score = $score;
            
            $grade_dist[$grade]['total']++;
            $grade_dist[$grade][$gender_key]++;
        }
    }
    
    $subject_analysis[$subj_id] = [
        'name' => $subj_name,
        'type' => $subj_type,
        'candidates' => $candidates_count,
        'avg_score' => $candidates_count > 0 ? $total_score / $candidates_count : 0,
        'avg_grade' => $candidates_count > 0 ? $total_grade / $candidates_count : 0,
        'pass_rate' => $candidates_count > 0 ? ($pass_count_subj / $candidates_count) * 100 : 0,
        'highest_score' => $highest_score,
        'lowest_score' => $candidates_count > 0 ? $lowest_score : 0,
        'grade_distribution' => $grade_dist
    ];
}

// Build HTML
$html = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; font-size: 10pt; }
        .header { text-align: center; border-bottom: 2px solid #000; padding-bottom: 10px; margin-bottom: 20px; }
        .school-name { font-size: 16pt; font-weight: bold; margin-bottom: 5px; }
        .school-address { font-size: 9pt; color: #666; margin-bottom: 10px; }
        .report-title { font-size: 14pt; font-weight: bold; color: #c00; margin: 10px 0; }
        .report-meta { font-size: 9pt; margin: 5px 0; }
        .generated-date { font-size: 8pt; color: #999; margin-top: 5px; }
        
        .section-title { background: #333; color: white; padding: 8px; font-size: 11pt; font-weight: bold; margin: 20px 0 10px 0; }
        
        .summary-boxes { width: 100%; margin: 15px 0; }
        .summary-box { display: inline-block; width: 23%; padding: 15px; margin: 5px; background: #f0f0f0; text-align: center; vertical-align: top; border: 1px solid #ddd; }
        .summary-box-value { font-size: 24pt; font-weight: bold; margin-bottom: 5px; }
        .summary-box-label { font-size: 9pt; }
        
        table { width: 100%; border-collapse: collapse; margin: 10px 0; font-size: 9pt; }
        th { background: #333; color: white; padding: 6px; text-align: left; font-weight: bold; }
        td { padding: 6px; border-bottom: 1px solid #ddd; }
        tr:nth-child(even) { background: #f9f9f9; }
        
        .grade-breakdown-table { font-size: 8pt; }
        .grade-breakdown-table th { background: #666; padding: 4px; text-align: center; }
        .grade-breakdown-table td { padding: 4px; border: 1px solid #ddd; text-align: center; }
        
        .subject-section { margin: 20px 0; padding: 10px; background: #f8f8f8; border-left: 3px solid #00a; }
        .subject-header { font-size: 12pt; font-weight: bold; margin-bottom: 10px; }
        .subject-type-badge { display: inline-block; padding: 2px 8px; background: #c00; color: white; font-size: 8pt; font-weight: bold; margin-left: 10px; }
        .subject-type-badge.core { background: #0a0; }
        
        .note { background: #fff8dc; border-left: 3px solid #ffa500; padding: 8px; margin: 10px 0; font-size: 9pt; }
        .footer { text-align: center; margin-top: 30px; padding-top: 15px; border-top: 2px solid #ddd; font-size: 8pt; color: #666; }
        
        .rank-badge { display: inline-block; width: 20px; height: 20px; line-height: 20px; background: #00a; color: white; border-radius: 50%; text-align: center; font-weight: bold; font-size: 9pt; }
        .rank-badge.gold { background: #ffa500; }
        .rank-badge.silver { background: #c0c0c0; }
        .rank-badge.bronze { background: #cd7f32; }
        
        .status-passed { color: #0a0; font-weight: bold; }
        .status-failed { color: #c00; font-weight: bold; }
    </style>
</head>
<body>';

// Header
$html .= '
<div class="header">
    <div class="school-name">' . strtoupper(htmlspecialchars($school_info['school_name'] ?? 'SCHOOL NAME')) . '</div>
    <div class="school-address">' . htmlspecialchars($school_info['address'] ?? 'School Address') . '</div>
    <div class="report-title">BECE MOCK EXAMINATION DETAILED ANALYSIS</div>
    <div class="report-meta">
        Class: ' . htmlspecialchars($class_name ?? 'N/A') . ' | 
        Academic Year: ' . htmlspecialchars($academic_year) . ' | 
        Term: ' . htmlspecialchars($term) . '
    </div>
    <div class="generated-date">Generated: ' . date('F j, Y g:i A') . '</div>
</div>';

// Overall Class Summary
$html .= '
<div class="section-title">OVERALL CLASS SUMMARY</div>
<div class="summary-boxes">
    <div class="summary-box">
        <div class="summary-box-value">' . $total_candidates . '</div>
        <div class="summary-box-label">Total Candidates</div>
    </div>
    <div class="summary-box">
        <div class="summary-box-value">' . $pass_count . '</div>
        <div class="summary-box-label">Passed (Agg ≤36)</div>
    </div>
    <div class="summary-box">
        <div class="summary-box-value">' . $fail_count . '</div>
        <div class="summary-box-label">Failed (Agg &gt;36)</div>
    </div>
    <div class="summary-box">
        <div class="summary-box-value">' . number_format($pass_rate, 1) . '%</div>
        <div class="summary-box-label">Pass Rate</div>
    </div>
</div>';

// Class Statistics Summary
$html .= '
<div class="section-title">CLASS STATISTICS SUMMARY</div>
<table>
    <tr><th>Performance Metrics</th><th style="text-align: right;"></th></tr>
    <tr><td><strong>Total Number of Candidates</strong></td><td style="text-align: right;">' . $total_candidates . '</td></tr>
    <tr><td><strong>Candidates with Aggregate ≤36 (Pass)</strong></td><td style="text-align: right;">' . $pass_count . ' (' . number_format($pass_rate, 1) . '%)</td></tr>
    <tr><td><strong>Candidates with Aggregate &gt;36 (Fail)</strong></td><td style="text-align: right;">' . $fail_count . ' (' . number_format(100 - $pass_rate, 1) . '%)</td></tr>
    <tr><td><strong>Best Aggregate Score</strong></td><td style="text-align: right;">' . $best_aggregate . '</td></tr>
    <tr><td><strong>Worst Aggregate Score</strong></td><td style="text-align: right;">' . $worst_aggregate . '</td></tr>
    <tr><td><strong>Average Class Aggregate</strong></td><td style="text-align: right;">' . number_format($average_aggregate, 2) . '</td></tr>
</table>';

// Gender Distribution
$html .= '
<div class="section-title">GENDER DISTRIBUTION</div>
<table>
    <tr><th>Gender</th><th style="text-align: center;">Count</th><th style="text-align: center;">Percentage</th></tr>
    <tr>
        <td>♂ Male Students</td>
        <td style="text-align: center;">' . $male_count . '</td>
        <td style="text-align: center;">' . ($total_candidates > 0 ? number_format(($male_count / $total_candidates) * 100, 1) : 0) . '%</td>
    </tr>
    <tr>
        <td>♀ Female Students</td>
        <td style="text-align: center;">' . $female_count . '</td>
        <td style="text-align: center;">' . ($total_candidates > 0 ? number_format(($female_count / $total_candidates) * 100, 1) : 0) . '%</td>
    </tr>
</table>';

// Aggregate Distribution Analysis
$html .= '
<div class="section-title">AGGREGATE DISTRIBUTION ANALYSIS</div>
<table>
    <tr>
        <th>Aggregate Range</th><th>Category</th>
        <th style="text-align: center;">Male</th><th style="text-align: center;">Male %</th>
        <th style="text-align: center;">Female</th><th style="text-align: center;">Female %</th>
        <th style="text-align: center;">Total</th><th style="text-align: center;">Total %</th>
    </tr>';

foreach ($aggregate_distribution as $range => $data) {
    $total = $data['M'] + $data['F'];
    $category = '';
    if ($range === '6-12') $category = 'Highest';
    elseif ($range === '13-24') $category = 'Higher';
    elseif ($range === '25-36') $category = 'High';
    elseif ($range === '37-48') $category = 'Low';
    
    $html .= '<tr>
        <td>' . $range . '</td>
        <td>' . $category . '</td>
        <td style="text-align: center;">' . $data['M'] . '</td>
        <td style="text-align: center;">' . ($total_candidates > 0 ? number_format(($data['M'] / $total_candidates) * 100, 1) : 0) . '%</td>
        <td style="text-align: center;">' . $data['F'] . '</td>
        <td style="text-align: center;">' . ($total_candidates > 0 ? number_format(($data['F'] / $total_candidates) * 100, 1) : 0) . '%</td>
        <td style="text-align: center;">' . $total . '</td>
        <td style="text-align: center;">' . ($total_candidates > 0 ? number_format(($total / $total_candidates) * 100, 1) : 0) . '%</td>
    </tr>';
}

$html .= '</table>';

$html .= '
<div class="note">
    <strong>BECE Grading System:</strong> Grade 1 (90-100% Highest) | Grade 2 (80-89% Higher) | Grade 3 (70-79% High) | 
    Grade 4 (60-69% High Average) | Grade 5 (55-59% Average) | Grade 6 (50-54% Low Average) | 
    Grade 7 (40-49% Low) | Grade 8 (35-39% Lower) | Grade 9 (0-34% Lowest/Fail)
</div>';

// All Subjects Performance Overview
$html .= '
<div class="section-title">ALL SUBJECTS PERFORMANCE OVERVIEW</div>
<div class="note">Comprehensive performance analysis across all subjects before detailed breakdowns.</div>
<table>
    <tr>
        <th>No.</th><th>Subject Name</th><th>Type</th>
        <th style="text-align: center;">Candidates</th><th style="text-align: center;">Avg Score</th>
        <th style="text-align: center;">Avg Grade</th><th style="text-align: center;">Pass Rate</th>
        <th style="text-align: center;">Performance</th>
    </tr>';

$counter = 1;
foreach ($subject_analysis as $subj_id => $analysis) {
    $avg_grade_rounded = round($analysis['avg_grade']);
    $html .= '<tr>
        <td>' . $counter++ . '</td>
        <td>' . htmlspecialchars($analysis['name']) . '</td>
        <td>' . $analysis['type'] . '</td>
        <td style="text-align: center;">' . $analysis['candidates'] . '</td>
        <td style="text-align: center;">' . number_format($analysis['avg_score'], 1) . '%</td>
        <td style="text-align: center;">' . $avg_grade_rounded . '</td>
        <td style="text-align: center;">' . number_format($analysis['pass_rate'], 1) . '%</td>
        <td style="text-align: center;">' . getGradeLabel($avg_grade_rounded) . '</td>
    </tr>';
}

$html .= '</table>';

// Subject-by-Subject Detailed Analysis
$html .= '<div class="section-title">SUBJECT-BY-SUBJECT DETAILED ANALYSIS</div>';
$html .= '<div class="note">Complete analysis for each subject showing all candidates\' performance and grade distributions.</div>';

foreach ($subject_analysis as $subj_id => $analysis) {
    $html .= '<div class="subject-section">
        <div class="subject-header">
            ' . htmlspecialchars($analysis['name']) . '
            <span class="subject-type-badge ' . strtolower($analysis['type']) . '">' . $analysis['type'] . ' SUBJECT</span>
        </div>
        
        <h4>Score Range Distribution:</h4>
        <table class="grade-breakdown-table">
            <tr>
                <th>Score Range</th><th>Grade</th><th>Performance</th>
                <th>Total</th><th>Male</th><th>Female</th><th>Percentage</th>
            </tr>';
    
    for ($grade = 1; $grade <= 9; $grade++) {
        $dist = $analysis['grade_distribution'][$grade];
        $html .= '<tr>
            <td>' . getScoreRange($grade) . '</td>
            <td>' . $grade . '</td>
            <td>' . getGradeLabel($grade) . '</td>
            <td>' . $dist['total'] . '</td>
            <td>' . $dist['male'] . '</td>
            <td>' . $dist['female'] . '</td>
            <td>' . ($analysis['candidates'] > 0 ? number_format(($dist['total'] / $analysis['candidates']) * 100, 1) : 0) . '%</td>
        </tr>';
    }
    
    $html .= '</table>
        
        <h4>Subject Statistics Summary</h4>
        <table>
            <tr><td><strong>Total Candidates:</strong></td><td>' . $analysis['candidates'] . '</td></tr>
            <tr><td><strong>Average Score:</strong></td><td>' . number_format($analysis['avg_score'], 1) . '% (Grade ' . round($analysis['avg_grade']) . ')</td></tr>
            <tr><td><strong>Highest Score:</strong></td><td>' . number_format($analysis['highest_score'], 1) . '%</td></tr>
            <tr><td><strong>Lowest Score:</strong></td><td>' . number_format($analysis['lowest_score'], 1) . '%</td></tr>
            <tr><td><strong>Pass Rate (Grades 1-6):</strong></td><td>' . number_format($analysis['pass_rate'], 1) . '%</td></tr>
        </table>';
    
    if ($analysis['type'] === 'ELECTIVE') {
        $html .= '<div class="note"><strong>Note:</strong> This is an ELECTIVE subject. Only the best 2 elective grades count toward the aggregate.</div>';
    } else {
        $html .= '<div class="note"><strong>Note:</strong> This is a CORE subject and counts toward the student\'s aggregate score.</div>';
    }
    
    $html .= '</div>';
}

// Top 10 Performers
$html .= '
<div class="section-title">TOP 10 PERFORMERS</div>
<table>
    <tr>
        <th>Rank</th><th>Name</th><th>Index Number</th>
        <th style="text-align: center;">Aggregate</th><th style="text-align: center;">Category</th>
    </tr>';

$top_performers = array_slice($students_data, 0, 10);
foreach ($top_performers as $index => $data) {
    $rank_class = '';
    if ($index === 0) $rank_class = ' gold';
    elseif ($index === 1) $rank_class = ' silver';
    elseif ($index === 2) $rank_class = ' bronze';
    
    $html .= '<tr>
        <td><span class="rank-badge' . $rank_class . '">' . ($index + 1) . '</span></td>
        <td>' . htmlspecialchars($data['student']['student_name']) . '</td>
        <td>' . htmlspecialchars($data['student']['candidate_index_number']) . '</td>
        <td style="text-align: center;">' . $data['aggregate'] . '</td>
        <td style="text-align: center;">' . $data['category'] . '</td>
    </tr>';
}

$html .= '</table>';

// Complete Candidate Ranking
$html .= '
<div class="section-title">COMPLETE CANDIDATE RANKING</div>
<table>
    <tr>
        <th>Rank</th><th>Name</th><th>Index Number</th>
        <th style="text-align: center;">Gender</th><th style="text-align: center;">Aggregate</th>
        <th style="text-align: center;">Category</th><th style="text-align: center;">Status</th>
    </tr>';

foreach ($students_data as $index => $data) {
    $status_class = $data['status'] === 'Passed' ? 'status-passed' : 'status-failed';
    $html .= '<tr>
        <td>' . ($index + 1) . '</td>
        <td>' . htmlspecialchars($data['student']['student_name']) . '</td>
        <td>' . htmlspecialchars($data['student']['candidate_index_number']) . '</td>
        <td style="text-align: center;">' . htmlspecialchars(ucfirst($data['student']['gender'])) . '</td>
        <td style="text-align: center;">' . $data['aggregate'] . '</td>
        <td style="text-align: center;">' . $data['category'] . '</td>
        <td style="text-align: center;" class="' . $status_class . '">' . $data['status'] . '</td>
    </tr>';
}

$html .= '</table>';

// Footer
$html .= '
<div class="footer">
    <p><strong>Report Generated:</strong> ' . date('l, F j, Y g:i A') . '</p>
    <p>This is a computer-generated document from the School Based Assessment System</p>
    <p>BECE Mock Examination - Detailed Statistical Analysis</p>
</div>';

$html .= '</body></html>';

// Generate PDF
try {
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
    
    $mpdf->SetTitle('Detailed Analysis - ' . ($class_name ?? 'Class'));
    $mpdf->SetAuthor($school_info['school_name'] ?? 'School');
    $mpdf->SetSubject('Academic Performance Analysis Report');
    
    $mpdf->WriteHTML($html);
    
    $filename = 'Analysis_' . ($class_name ?? 'Class') . '_' . date('Y-m-d') . '.pdf';
    $mpdf->Output($filename, \Mpdf\Output\Destination::INLINE);
    
} catch (\Mpdf\MpdfException $e) {
    die('PDF Generation Error: ' . $e->getMessage());
}
