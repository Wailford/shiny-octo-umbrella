<?php
session_start();
require_once 'config/config.php';
require_once 'config/database.php';
require_once 'helpers/GradingSystem.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$db = Database::getInstance()->getConnection();

// Get school_id from session
$school_id = $_SESSION['school_id'] ?? null;
$user_role = $_SESSION['role'] ?? $_SESSION['user_type'] ?? 'teacher';
$is_admin = ($_SESSION['user_type'] ?? null) === 'admin' || ($_SESSION['role'] ?? null) === 'admin';

// Get school information
$school_name = 'SCHOOL NAME';
$district = 'DISTRICT';
$circuit = 'CIRCUIT';

if ($school_id) {
    $stmt = $db->prepare("SELECT school_name, district, circuit FROM school_info WHERE id = ?");
    $stmt->execute([$school_id]);
    $school_info = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($school_info) {
        $school_name = $school_info['school_name'] ?? 'SCHOOL NAME';
        $district = $school_info['district'] ?? 'DISTRICT';
        $circuit = $school_info['circuit'] ?? 'CIRCUIT';
    }
}

if (!$school_id) {
    $_SESSION['error'] = 'School information not found.';
    header('Location: dashboard.php');
    exit();
}

// Get current academic year and term from system settings (key-value structure)
$settings_query = "SELECT setting_key, setting_value FROM system_settings";
$settings_stmt = $db->prepare($settings_query);
$settings_stmt->execute();
$settings_raw = $settings_stmt->fetchAll(PDO::FETCH_ASSOC);

// Convert key-value pairs to associative array
$system_settings = [];
foreach ($settings_raw as $setting) {
    $system_settings[$setting['setting_key']] = $setting['setting_value'];
}

$academic_year = $_GET['year'] ?? ($system_settings['current_academic_year'] ?? date('Y') . '/' . (date('Y') + 1));
$term = $_GET['term'] ?? ($system_settings['current_term'] ?? 'Term 1');

// Get all classes for this school
$classes_query = "SELECT id, class_name FROM classes 
                 WHERE school_id = ?
                 ORDER BY class_name";
$classes_stmt = $db->prepare($classes_query);
$classes_stmt->execute([$school_id]);
$classes = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);

$selected_class_id = $_GET['class_id'] ?? $_GET['class'] ?? ($classes[0]['id'] ?? null);

// SECURITY: Verify the selected class belongs to this school
if ($selected_class_id) {
    $verify_class = $db->prepare("SELECT id, school_id FROM classes WHERE id = ?");
    $verify_class->execute([$selected_class_id]);
    $class_check = $verify_class->fetch(PDO::FETCH_ASSOC);
    
    if (!$class_check || $class_check['school_id'] != $school_id) {
        $_SESSION['error'] = 'Unauthorized access to class data.';
        header('Location: dashboard.php');
        exit();
    }
}

// Get subjects for selected class
$subjects = [];
if ($selected_class_id) {
    $subjects_query = "SELECT DISTINCT id, subject_name, subject_code FROM subjects 
                      WHERE class_id = ? ORDER BY subject_name";
    $subjects_stmt = $db->prepare($subjects_query);
    $subjects_stmt->execute([$selected_class_id]);
    $subjects = $subjects_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get students with their mock exam scores - filter by school_id
$students_data = [];
if ($selected_class_id) {
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
    $students_stmt->execute([$selected_class_id, $currentSchoolId]);
    $students = $students_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get all mock scores for these students
    foreach ($students as $student) {
        $student_scores = [];
        $total_score = 0;
        $subject_count = 0;
        
        foreach ($subjects as $subject) {
            $score_query = "SELECT score FROM mock_exam_scores 
                          WHERE student_id = ? AND subject_id = ? AND school_id = ?";
            $score_stmt = $db->prepare($score_query);
            $score_stmt->execute([
                $student['id'],
                $subject['id'],
                $school_id
            ]);
            $score_data = $score_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($score_data) {
                // Calculate grade and remark from score
                $score = $score_data['score'];
                if ($score >= 80) { $grade = 1; $remark = 'Highest'; }
                elseif ($score >= 70) { $grade = 2; $remark = 'Higher'; }
                elseif ($score >= 60) { $grade = 3; $remark = 'High'; }
                elseif ($score >= 55) { $grade = 4; $remark = 'High Average'; }
                elseif ($score >= 50) { $grade = 5; $remark = 'Average'; }
                elseif ($score >= 45) { $grade = 6; $remark = 'Low Average'; }
                elseif ($score >= 40) { $grade = 7; $remark = 'Low'; }
                elseif ($score >= 35) { $grade = 8; $remark = 'Lower'; }
                else { $grade = 9; $remark = 'Lowest'; }
                
                $student_scores[$subject['id']] = [
                    'score' => $score,
                    'grade' => $grade,
                    'remark' => $remark
                ];
                $total_score += $score;
                $subject_count++;
            } else {
                $student_scores[$subject['id']] = null;
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
}

// Calculate overall statistics
$total_candidates = count($students_data);

// Convert 100-point score to Ghana grade (1-9)
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

// Get grade label (BECE terminology)
function getGradeLabel($grade) {
    $labels = [
        1 => 'Highest', 2 => 'Higher', 3 => 'High',
        4 => 'High Average', 5 => 'Average', 6 => 'Low Average',
        7 => 'Low', 8 => 'Lower', 9 => 'Lowest'
    ];
    return $labels[$grade] ?? 'N/A';
}

// Get aggregate category (BECE terminology)
function getAggregateCategory($aggregate) {
    if ($aggregate >= 6 && $aggregate <= 12) return 'Highest';
    if ($aggregate >= 13 && $aggregate <= 24) return 'Higher';
    if ($aggregate >= 25 && $aggregate <= 36) return 'High';
    return 'Low';
}

// NEW: Comprehensive analysis structure matching BECE Analysis format
$analysis = [
    'overall' => [
        'total_candidates' => $total_candidates,
        'male_count' => 0,
        'female_count' => 0,
        'overall_average' => 0,
        'pass_rate' => 0,
        'pass_count' => 0,
        'fail_count' => 0,
        'best_aggregate' => 48,
        'worst_aggregate' => 6,
        'average_aggregate' => 0
    ],
    // Subject Grades Table (like PDF Section 6)
    'subject_grades' => [],
    // Aggregate Distribution (like PDF Section 8)
    'aggregate_categories' => [
        '6' => ['M' => 0, 'F' => 0, 'total' => 0],
        '7-15' => ['M' => 0, 'F' => 0, 'total' => 0],
        '16-24' => ['M' => 0, 'F' => 0, 'total' => 0],
        '25-30' => ['M' => 0, 'F' => 0, 'total' => 0],
        '31-36' => ['M' => 0, 'F' => 0, 'total' => 0],
        '37-40' => ['M' => 0, 'F' => 0, 'total' => 0],
        '41+' => ['M' => 0, 'F' => 0, 'total' => 0]
    ],
    // Detailed frequency distribution (like PDF detailed tables)
    'aggregate_frequency' => [],
    // Pass statistics
    'pass_stats' => [
        '6-30' => ['M' => 0, 'F' => 0, 'total' => 0],
        '6-36' => ['M' => 0, 'F' => 0, 'total' => 0],
        '6-40' => ['M' => 0, 'F' => 0, 'total' => 0]
    ],
    'gender_performance' => [
        'male' => ['total_scores' => 0, 'pass_count' => 0, 'fail_count' => 0, 'avg_score' => 0, 'avg_aggregate' => 0],
        'female' => ['total_scores' => 0, 'pass_count' => 0, 'fail_count' => 0, 'avg_score' => 0, 'avg_aggregate' => 0]
    ],
    'subjects' => [],
    'core_subjects' => [],
    'elective_subjects' => []
];

// Get core and elective subjects with is_core flag
$core_subjects = [];
$elective_subjects = [];
$core_subject_names = ['English Language', 'Mathematics', 'Integrated Science', 'Social Studies'];

foreach ($subjects as $subject) {
    $is_core = in_array($subject['subject_name'], $core_subject_names);
    
    if ($is_core) {
        $core_subjects[] = $subject;
    } else {
        $elective_subjects[] = $subject;
    }
    
    // Initialize subject grades structure (BECE format)
    $analysis['subject_grades'][$subject['id']] = [
        'name' => $subject['subject_name'],
        'code' => $subject['subject_code'],
        'is_core' => $is_core,
        'grades_1_3' => ['M' => 0, 'F' => 0, 'total' => 0],
        'grades_4_5' => ['M' => 0, 'F' => 0, 'total' => 0],
        'grades_1_5' => ['M' => 0, 'F' => 0, 'total' => 0],
        'percentage' => ['M' => 0, 'F' => 0, 'total' => 0],
        'position' => 0
    ];
}

// Initialize aggregate frequency for each value (6-54)
for ($i = 6; $i <= 54; $i++) {
    $analysis['aggregate_frequency'][$i] = ['M' => 0, 'F' => 0, 'total' => 0];
}

// Calculate student aggregates (4 core + 2 best electives)
$student_aggregates = [];
$all_aggregates_male = [];
$all_aggregates_female = [];

foreach ($students_data as $student_data) {
    $gender = strtoupper(substr($student_data['student']['gender'] ?? '', 0, 1));
    
    // Ensure gender is either M or F, default to M if empty/invalid
    if ($gender !== 'M' && $gender !== 'F') {
        $gender = 'M'; // Default to male if gender not set
    }
    
    if ($gender == 'M') {
        $analysis['overall']['male_count']++;
    } else if ($gender == 'F') {
        $analysis['overall']['female_count']++;
    }
    
    $avg = $student_data['average'];
    
    // Calculate aggregate (4 core subjects + 2 best electives)
    $core_grades = [];
    $elective_grades = [];
    
    // Process each subject score and count grades
    foreach ($subjects as $subject) {
        if (isset($student_data['scores'][$subject['id']]) && $student_data['scores'][$subject['id']]) {
            $score = $student_data['scores'][$subject['id']]['score'];
            $grade = convertScoreToGhanaGrade($score);
            
            // Count grades for subject grades table
            if ($grade >= 1 && $grade <= 3) {
                $analysis['subject_grades'][$subject['id']]['grades_1_3'][$gender]++;
                $analysis['subject_grades'][$subject['id']]['grades_1_3']['total']++;
            } elseif ($grade >= 4 && $grade <= 5) {
                $analysis['subject_grades'][$subject['id']]['grades_4_5'][$gender]++;
                $analysis['subject_grades'][$subject['id']]['grades_4_5']['total']++;
            }
            
            if ($grade >= 1 && $grade <= 5) {
                $analysis['subject_grades'][$subject['id']]['grades_1_5'][$gender]++;
                $analysis['subject_grades'][$subject['id']]['grades_1_5']['total']++;
            }
            
            // Collect grades for aggregate calculation
            $is_core = $analysis['subject_grades'][$subject['id']]['is_core'];
            if ($is_core) {
                $core_grades[$subject['subject_name']] = $grade;
            } else {
                $elective_grades[$subject['subject_name']] = $grade;
            }
        }
    }
    
    // Only calculate aggregate if student has all 4 core subjects and at least 2 electives
    if (count($core_grades) < 4 || count($elective_grades) < 2) {
        continue; // Skip this student - incomplete data
    }
    
    // Get best 2 electives (sort ascending - lower is better)
    asort($elective_grades);
    $best_electives = array_slice($elective_grades, 0, 2, true);
    
    // Calculate aggregate (4 core + 2 best electives)
    $aggregate = array_sum($core_grades) + array_sum($best_electives);
    
    // Update aggregate statistics
    if ($aggregate < $analysis['overall']['best_aggregate']) {
        $analysis['overall']['best_aggregate'] = $aggregate;
    }
    if ($aggregate > $analysis['overall']['worst_aggregate']) {
        $analysis['overall']['worst_aggregate'] = $aggregate;
    }
    
    // Track aggregates by gender for mean calculation
    if ($gender == 'M') {
        $all_aggregates_male[] = $aggregate;
        $analysis['gender_performance']['male']['avg_aggregate'] += $aggregate;
    } else if ($gender == 'F') {
        $all_aggregates_female[] = $aggregate;
        $analysis['gender_performance']['female']['avg_aggregate'] += $aggregate;
    }
    
    // Count aggregate frequency (for detailed frequency table)
    if ($aggregate >= 6 && $aggregate <= 54) {
        $analysis['aggregate_frequency'][$aggregate][$gender]++;
        $analysis['aggregate_frequency'][$aggregate]['total']++;
    }
    
    // Categorize aggregate (BECE format: 6, 7-15, 16-24, 25-30, 31-36, 37-40, 41+)
    if ($aggregate == 6) {
        $analysis['aggregate_categories']['6'][$gender]++;
        $analysis['aggregate_categories']['6']['total']++;
    } elseif ($aggregate >= 7 && $aggregate <= 15) {
        $analysis['aggregate_categories']['7-15'][$gender]++;
        $analysis['aggregate_categories']['7-15']['total']++;
    } elseif ($aggregate >= 16 && $aggregate <= 24) {
        $analysis['aggregate_categories']['16-24'][$gender]++;
        $analysis['aggregate_categories']['16-24']['total']++;
    } elseif ($aggregate >= 25 && $aggregate <= 30) {
        $analysis['aggregate_categories']['25-30'][$gender]++;
        $analysis['aggregate_categories']['25-30']['total']++;
    } elseif ($aggregate >= 31 && $aggregate <= 36) {
        $analysis['aggregate_categories']['31-36'][$gender]++;
        $analysis['aggregate_categories']['31-36']['total']++;
    } elseif ($aggregate >= 37 && $aggregate <= 40) {
        $analysis['aggregate_categories']['37-40'][$gender]++;
        $analysis['aggregate_categories']['37-40']['total']++;
    } else {
        $analysis['aggregate_categories']['41+'][$gender]++;
        $analysis['aggregate_categories']['41+']['total']++;
    }
    
    // Count pass stats (6-30, 6-36, 6-40)
    if ($aggregate >= 6 && $aggregate <= 30) {
        $analysis['pass_stats']['6-30'][$gender]++;
        $analysis['pass_stats']['6-30']['total']++;
    }
    if ($aggregate >= 6 && $aggregate <= 36) {
        $analysis['pass_stats']['6-36'][$gender]++;
        $analysis['pass_stats']['6-36']['total']++;
    }
    if ($aggregate >= 6 && $aggregate <= 40) {
        $analysis['pass_stats']['6-40'][$gender]++;
        $analysis['pass_stats']['6-40']['total']++;
    }
    
    // Track for average calculation
    if ($gender == 'M') {
        $analysis['gender_performance']['male']['total_scores'] += $avg;
    } else if ($gender == 'F') {
        $analysis['gender_performance']['female']['total_scores'] += $avg;
    }
    
    $student_aggregates[] = [
        'student' => $student_data['student'],
        'aggregate' => $aggregate,
        'core_grades' => $core_grades,
        'best_electives' => $best_electives,
        'average_score' => $avg,
        'gender' => $gender
    ];
}

// Calculate averages
if ($total_candidates > 0) {
    $total_avg = array_sum(array_column($students_data, 'average'));
    $total_agg = array_sum(array_column($student_aggregates, 'aggregate'));
    $analysis['overall']['overall_average'] = $total_avg / $total_candidates;
    $analysis['overall']['average_aggregate'] = $total_agg / $total_candidates;
}

// Calculate gender-specific averages
if ($analysis['overall']['male_count'] > 0) {
    $analysis['gender_performance']['male']['avg_score'] = 
        $analysis['gender_performance']['male']['total_scores'] / $analysis['overall']['male_count'];
    $analysis['gender_performance']['male']['avg_aggregate'] = 
        $analysis['gender_performance']['male']['avg_aggregate'] / $analysis['overall']['male_count'];
}

if ($analysis['overall']['female_count'] > 0) {
    $analysis['gender_performance']['female']['avg_score'] = 
        $analysis['gender_performance']['female']['total_scores'] / $analysis['overall']['female_count'];
    $analysis['gender_performance']['female']['avg_aggregate'] = 
        $analysis['gender_performance']['female']['avg_aggregate'] / $analysis['overall']['female_count'];
}

// Calculate subject percentages and positions
foreach ($analysis['subject_grades'] as $subj_id => &$subj_data) {
    // Calculate percentages (grades 1-5 / total candidates * 100)
    if ($analysis['overall']['male_count'] > 0) {
        $subj_data['percentage']['M'] = round(($subj_data['grades_1_5']['M'] / $analysis['overall']['male_count']) * 100, 1);
    }
    if ($analysis['overall']['female_count'] > 0) {
        $subj_data['percentage']['F'] = round(($subj_data['grades_1_5']['F'] / $analysis['overall']['female_count']) * 100, 1);
    }
    if ($total_candidates > 0) {
        $subj_data['percentage']['total'] = round(($subj_data['grades_1_5']['total'] / $total_candidates) * 100, 1);
    }
}
unset($subj_data);

// Rank subjects by overall percentage (descending)
$subject_ranking = [];
foreach ($analysis['subject_grades'] as $subj_id => $subj_data) {
    $subject_ranking[$subj_id] = $subj_data['percentage']['total'];
}
arsort($subject_ranking);
$position = 1;
foreach ($subject_ranking as $subj_id => $percentage) {
    $analysis['subject_grades'][$subj_id]['position'] = $position++;
}

// Sort student aggregates by aggregate (ascending - lower is better)
usort($student_aggregates, function($a, $b) {
    return $a['aggregate'] <=> $b['aggregate'];
});

$class_stats = [
    'total_candidates' => $total_candidates,
    'subjects' => []
];

foreach ($subjects as $subject) {
    $subject_scores = [];
    $male_scores = [];
    $female_scores = [];
    
    foreach ($students_data as $student_data) {
        if ($student_data['scores'][$subject['id']]) {
            $score = $student_data['scores'][$subject['id']]['score'];
            $subject_scores[] = $score;
            
            $gender = strtoupper(substr($student_data['student']['gender'] ?? '', 0, 1));
            if ($gender == 'M') {
                $male_scores[] = $score;
            } else if ($gender == 'F') {
                $female_scores[] = $score;
            }
        }
    }
    
    if (count($subject_scores) > 0) {
        $highest = max($subject_scores);
        $lowest = min($subject_scores);
        $average = array_sum($subject_scores) / count($subject_scores);
        $pass_count = count(array_filter($subject_scores, function($score) { return $score >= 50; }));
        $pass_rate = ($pass_count / count($subject_scores)) * 100;
        
        // Gender-specific statistics
        $male_stats = [
            'count' => count($male_scores),
            'highest' => count($male_scores) > 0 ? max($male_scores) : 0,
            'lowest' => count($male_scores) > 0 ? min($male_scores) : 0,
            'average' => count($male_scores) > 0 ? array_sum($male_scores) / count($male_scores) : 0,
            'pass_count' => count(array_filter($male_scores, function($s) { return $s >= 50; })),
            'fail_count' => count(array_filter($male_scores, function($s) { return $s < 50; })),
            'pass_rate' => count($male_scores) > 0 ? (count(array_filter($male_scores, function($s) { return $s >= 50; })) / count($male_scores)) * 100 : 0
        ];
        
        $female_stats = [
            'count' => count($female_scores),
            'highest' => count($female_scores) > 0 ? max($female_scores) : 0,
            'lowest' => count($female_scores) > 0 ? min($female_scores) : 0,
            'average' => count($female_scores) > 0 ? array_sum($female_scores) / count($female_scores) : 0,
            'pass_count' => count(array_filter($female_scores, function($s) { return $s >= 50; })),
            'fail_count' => count(array_filter($female_scores, function($s) { return $s < 50; })),
            'pass_rate' => count($female_scores) > 0 ? (count(array_filter($female_scores, function($s) { return $s >= 50; })) / count($female_scores)) * 100 : 0
        ];
        
        // Grade distribution
        $grades = GradingSystem::loadGrades();
        $grade_distribution = [];
        $male_grade_dist = [];
        $female_grade_dist = [];
        
        foreach ($grades as $grade_info) {
            $grade_distribution[$grade_info['grade']] = 0;
            $male_grade_dist[$grade_info['grade']] = 0;
            $female_grade_dist[$grade_info['grade']] = 0;
        }
        
        foreach ($students_data as $student_data) {
            if ($student_data['scores'][$subject['id']]) {
                $score = $student_data['scores'][$subject['id']]['score'];
                $grade_info = GradingSystem::getGradeForScore($score);
                $gender = strtoupper(substr($student_data['student']['gender'] ?? '', 0, 1));
                
                if (isset($grade_distribution[$grade_info['grade']])) {
                    $grade_distribution[$grade_info['grade']]++;
                    
                    if ($gender == 'M' && isset($male_grade_dist[$grade_info['grade']])) {
                        $male_grade_dist[$grade_info['grade']]++;
                    } else if ($gender == 'F' && isset($female_grade_dist[$grade_info['grade']])) {
                        $female_grade_dist[$grade_info['grade']]++;
                    }
                }
            }
        }
        
        // Check if this is a core subject
        $is_core = in_array($subject['subject_name'], $core_subject_names);
        
        $class_stats['subjects'][$subject['id']] = [
            'name' => $subject['subject_name'],
            'code' => $subject['subject_code'],
            'is_core' => $is_core,
            'total_scores' => count($subject_scores),
            'highest' => $highest,
            'lowest' => $lowest,
            'average' => $average,
            'pass_rate' => $pass_rate,
            'pass_count' => $pass_count,
            'fail_count' => count($subject_scores) - $pass_count,
            'grade_distribution' => $grade_distribution,
            'male_stats' => $male_stats,
            'female_stats' => $female_stats,
            'male_grade_distribution' => $male_grade_dist,
            'female_grade_distribution' => $female_grade_dist
        ];
        
        // Also add to analysis array
        $analysis['subjects'][$subject['id']] = $class_stats['subjects'][$subject['id']];
        
        // Categorize into core/elective
        if ($is_core) {
            $analysis['core_subjects'][$subject['id']] = $class_stats['subjects'][$subject['id']];
        } else {
            $analysis['elective_subjects'][$subject['id']] = $class_stats['subjects'][$subject['id']];
        }
    }
}

// Calculate individual student aggregates and aggregate gender distribution
$aggregate_gender_distribution = [
    '6-12' => ['M' => 0, 'F' => 0, 'total' => 0],
    '13-24' => ['M' => 0, 'F' => 0, 'total' => 0],
    '25-36' => ['M' => 0, 'F' => 0, 'total' => 0],
    '37-48' => ['M' => 0, 'F' => 0, 'total' => 0]
];

$best_aggregate_male = 48;
$best_aggregate_female = 48;
$worst_aggregate_male = 6;
$worst_aggregate_female = 6;
$male_aggregates = [];
$female_aggregates = [];

foreach ($students_data as &$student_data) {
    $student_scores = [];
    
    // Get all subject scores with core flag
    foreach ($subjects as $subject) {
        if ($student_data['scores'][$subject['id']]) {
            $score = $student_data['scores'][$subject['id']]['score'];
            $grade = convertScoreToGhanaGrade($score);
            
            // Check if core subject
            $is_core = false;
            if (isset($analysis['core_subjects'][$subject['id']])) {
                $is_core = true;
            }
            
            $student_scores[] = [
                'subject_id' => $subject['id'],
                'score' => $score,
                'grade' => $grade,
                'is_core' => $is_core
            ];
        }
    }
    
    // Calculate aggregate (4 core + 2 best electives)
    $core_grades = [];
    $elective_grades = [];
    
    foreach ($student_scores as $subj) {
        if ($subj['is_core']) {
            $core_grades[] = $subj['grade'];
        } else {
            $elective_grades[] = $subj['grade'];
        }
    }
    
    // Sort to get best grades (lowest numbers are best)
    sort($core_grades);
    sort($elective_grades);
    
    // Take 4 best core (or fill with 9 if less than 4)
    $best_4_core = array_slice($core_grades, 0, 4);
    while (count($best_4_core) < 4) {
        $best_4_core[] = 9;
    }
    
    // Take 2 best electives (or fill with 9 if less than 2)
    $best_2_electives = array_slice($elective_grades, 0, 2);
    while (count($best_2_electives) < 2) {
        $best_2_electives[] = 9;
    }
    
    $aggregate = array_sum($best_4_core) + array_sum($best_2_electives);
    $student_data['aggregate'] = $aggregate;
    
    $gender = strtoupper(substr($student_data['student']['gender'] ?? '', 0, 1));
    
    // Track by gender
    if ($gender == 'M') {
        $male_aggregates[] = $aggregate;
        $best_aggregate_male = min($best_aggregate_male, $aggregate);
        $worst_aggregate_male = max($worst_aggregate_male, $aggregate);
    } else if ($gender == 'F') {
        $female_aggregates[] = $aggregate;
        $best_aggregate_female = min($best_aggregate_female, $aggregate);
        $worst_aggregate_female = max($worst_aggregate_female, $aggregate);
    }
    
    // Categorize aggregate
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
unset($student_data); // Break reference

// Calculate percentages for aggregate distribution
foreach ($aggregate_gender_distribution as $range => &$data) {
    if ($total_candidates > 0) {
        $data['total_percent'] = round(($data['total'] / $total_candidates) * 100, 1);
        $data['M_percent'] = $data['total'] > 0 ? round(($data['M'] / $data['total']) * 100, 1) : 0;
        $data['F_percent'] = $data['total'] > 0 ? round(($data['F'] / $data['total']) * 100, 1) : 0;
    } else {
        $data['total_percent'] = 0;
        $data['M_percent'] = 0;
        $data['F_percent'] = 0;
    }
}
unset($data);

// Calculate average aggregates by gender
$avg_aggregate_male = count($male_aggregates) > 0 ? round(array_sum($male_aggregates) / count($male_aggregates), 2) : 0;
$avg_aggregate_female = count($female_aggregates) > 0 ? round(array_sum($female_aggregates) / count($female_aggregates), 2) : 0;

// Add to analysis
$analysis['aggregate_distribution'] = $aggregate_gender_distribution;
$analysis['aggregate_stats'] = [
    'male' => [
        'best' => $best_aggregate_male,
        'worst' => $worst_aggregate_male,
        'average' => $avg_aggregate_male,
        'count' => count($male_aggregates)
    ],
    'female' => [
        'best' => $best_aggregate_female,
        'worst' => $worst_aggregate_female,
        'average' => $avg_aggregate_female,
        'count' => count($female_aggregates)
    ]
];

// Calculate Section 9, 10, 11 statistics and absenteeism reasons
$candidates_41_above = 0;
$candidates_all_nines = 0;

// Get total registered students (including absent ones)
$registered_query = "SELECT s.id, s.student_id, s.student_name, s.gender, s.candidate_index_number
                     FROM students s
                     JOIN classes c ON s.class_id = c.id
                     WHERE s.class_id = ? AND c.school_id = ?
                     AND s.candidate_index_number IS NOT NULL AND s.candidate_index_number != ''
                     ORDER BY s.candidate_index_number";
$registered_stmt = $db->prepare($registered_query);
$registered_stmt->execute([$selected_class_id, $currentSchoolId]);
$all_registered = $registered_stmt->fetchAll(PDO::FETCH_ASSOC);

$total_registered = count($all_registered);
$candidates_absent = $total_registered - $total_candidates;

// Count absenteeism by reason (all go to unknown for now since column doesn't exist)
$absenteeism_reasons = [
    'pregnant' => ['M' => 0, 'F' => 0],
    'death' => ['M' => 0, 'F' => 0],
    'travelled' => ['M' => 0, 'F' => 0],
    'illness' => ['M' => 0, 'F' => 0],
    'truancy' => ['M' => 0, 'F' => 0],
    'withdrawal' => ['M' => 0, 'F' => 0],
    'unknown' => ['M' => 0, 'F' => 0]
];

// Count absent students by gender (all marked as unknown reason)
foreach ($all_registered as $student) {
    // Check if student has any scores (is present)
    $check_score = $db->prepare("SELECT COUNT(*) as cnt FROM mock_exam_scores WHERE student_id = ? AND school_id = ?");
    $check_score->execute([$student['id'], $school_id]);
    $has_scores = $check_score->fetch(PDO::FETCH_ASSOC)['cnt'] > 0;
    
    if (!$has_scores) {
        // Student is absent, count as unknown reason
        $gender = strtoupper(substr($student['gender'] ?? '', 0, 1));
        if ($gender !== 'M' && $gender !== 'F') $gender = 'M';
        $absenteeism_reasons['unknown'][$gender]++;
    }
}

foreach ($students_data as $student_data) {
    // Count candidates with aggregate 41-above (failed)
    if ($student_data['aggregate'] >= 41) {
        $candidates_41_above++;
    }
    
    // Count candidates with all nines (worst performance)
    $all_nines = true;
    foreach ($student_data['scores'] as $subject_score) {
        if ($subject_score && $subject_score['grade'] != '9') {
            $all_nines = false;
            break;
        }
    }
    if ($all_nines && count(array_filter($student_data['scores'])) > 0) {
        $candidates_all_nines++;
    }
}

$analysis['special_stats'] = [
    'aggregate_41_above' => $candidates_41_above,
    'all_nines' => $candidates_all_nines,
    'absent' => $candidates_absent
];

$analysis['absenteeism_reasons'] = $absenteeism_reasons;

// Sort students by average score
usort($students_data, function($a, $b) {
    return $b['average'] <=> $a['average'];
});

// Get top and bottom 5 performers
$top_performers = array_slice($students_data, 0, 5);
$bottom_performers = array_slice(array_reverse($students_data), 0, 5);

$pageTitle = 'BECE Mock Analysis';
include 'components/header.php';
?>
    <style>
        @page {
            size: A4 landscape;
            margin: 15mm;
        }
        .report-container {
            max-width: 100%;
            margin: 0 auto;
            background: white;
            padding: 15px;
        }
        .header-section {
            text-align: center;
            margin-bottom: 20px;
            border-bottom: 2px solid #000;
            padding-bottom: 10px;
        }
        .header-section h1 {
            margin: 3px 0;
            font-size: 14pt;
            font-weight: bold;
        }
        .header-section h2 {
            margin: 3px 0;
            font-size: 12pt;
        }
        .school-info {
            display: flex;
            justify-content: space-between;
            margin: 10px 0;
            font-weight: bold;
            font-size: 10pt;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
            font-size: 9pt;
        }
        table, th, td {
            border: 1px solid #000;
        }
        th, td {
            padding: 6px 4px;
            text-align: center;
        }
        th {
            background-color: #f0f0f0;
            font-weight: bold;
            font-size: 8pt;
        }
        .section-title {
            font-weight: bold;
            font-size: 11pt;
            margin: 20px 0 8px 0;
            text-transform: uppercase;
        }
        .stat-box {
            padding: 8px;
            text-align: center;
        }
        .stat-box h4 {
            margin: 0 0 8px 0;
            font-size: 8pt;
            font-weight: normal;
        }
        .stat-box .value {
            font-size: 18pt;
            font-weight: bold;
        }
        .no-print {
            margin: 20px 0;
        }
        .btn {
            padding: 10px 20px;
            margin: 5px;
            cursor: pointer;
            border: none;
            border-radius: 4px;
            font-size: 14px;
        }
        .btn-primary {
            background-color: #007bff;
            color: white;
        }
        .btn-secondary {
            background-color: #6c757d;
            color: white;
        }
        @media (max-width: 768px) {
            .report-container { padding: 0.75rem; }

            /* Controls: stack class selector and buttons */
            .no-print { display: flex !important; flex-direction: column; align-items: stretch; gap: 0.5rem; }
            .no-print select, .no-print .form-control { width: 100% !important; font-size: 16px !important; min-height: 48px; }
            .no-print .btn { width: 100%; }

            /* All tables in the analysis: scrollable */
            table { font-size: 0.75rem !important; }
            th, td { padding: 0.4rem 0.35rem !important; }

            /* Stat boxes: 2 columns */
            div[style*="display: flex"][style*="flex: 1"],
            .stat-box { flex: none !important; min-width: calc(50% - 0.5rem) !important; }

            /* Wrap all tables */
            .report-container > div, .analysis-section { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        }

        @media print {
            .no-print {
                display: none !important;
            }
            body {
                margin: 0;
            }
            header, .header, nav, .navbar, .navigation, .menu, .sidebar, .top-bar, 
            .nav-container, .user-info, .school-info-header, .breadcrumb {
                display: none !important;
            }
            .report-container {
                padding: 10px;
                margin: 0;
            }
            .header-section {
                page-break-after: avoid;
            }
        }
    </style>

<div class="report-container">
    
    <!-- No-print controls -->
    <div class="no-print" style="margin-bottom: 20px; text-align: center;">
        <select onchange="window.location.href='?class_id='+this.value" class="form-control" style="display:inline-block;width:auto;min-width:200px;margin:0 0.5rem 0.5rem;">
            <?php foreach ($classes as $class): ?>
                <option value="<?php echo $class['id']; ?>" <?php echo ($selected_class_id == $class['id']) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($class['class_name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button onclick="window.print()" class="btn btn-primary">Print Report</button>
        <a href="export_mock_analysis_pdf.php?class_id=<?php echo $selected_class_id; ?>" target="_blank" class="btn btn-secondary">Download PDF</a>
    </div>

    <?php if ($total_candidates === 0): ?>
        <p style="text-align: center; color: red;">No mock exam scores found for this class.</p>
    <?php else: ?>

    <!-- Header -->
    <div class="header-section">
        <h1><?php echo strtoupper(htmlspecialchars($district)); ?> EDUCATION DIRECTORATE : B.E.C.E./MOCK ANALYSIS (<?php echo strtoupper(htmlspecialchars($circuit)); ?> CIRCUIT)</h1>
        <h2><?php echo date('Y'); ?></h2>
    </div>

    <div class="school-info">
        <div>1. NAME OF SCHOOL: <?php echo strtoupper(htmlspecialchars($school_name)); ?></div>
        <div>2. NAME OF CIRCUIT: <?php echo strtoupper(htmlspecialchars($circuit)); ?></div>
    </div>

    <!-- SECTION 3: ENROLMENT IN JHS -->
    <div style="display: inline-block; width: 32%; vertical-align: top; margin-right: 1%;">
        <div class="section-title" style="font-size: 10pt;">3. ENROLMENT IN JHS</div>
        <table>
            <tbody>
                <tr>
                    <td><strong>BOYS</strong></td>
                    <td><?php echo $analysis['overall']['male_count']; ?></td>
                </tr>
                <tr>
                    <td><strong>GIRLS</strong></td>
                    <td><?php echo $analysis['overall']['female_count']; ?></td>
                </tr>
                <tr style="background-color: #e0e0e0;">
                    <td><strong>TOTAL</strong></td>
                    <td><strong><?php echo $total_candidates; ?></strong></td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- SECTION 4: NO OF CAND. REGISTERED -->
    <div style="display: inline-block; width: 32%; vertical-align: top; margin-right: 1%;">
        <div class="section-title" style="font-size: 10pt;">4. NO OF CAND. REGISTERED</div>
        <table>
            <tbody>
                <tr>
                    <td><strong>BOYS</strong></td>
                    <td><?php echo $analysis['overall']['male_count']; ?></td>
                </tr>
                <tr>
                    <td><strong>GIRLS</strong></td>
                    <td><?php echo $analysis['overall']['female_count']; ?></td>
                </tr>
                <tr style="background-color: #e0e0e0;">
                    <td><strong>TOTAL</strong></td>
                    <td><strong><?php echo $total_candidates; ?></strong></td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- SECTION 5: NO. OF CANDIDATES PRESENT -->
    <div style="display: inline-block; width: 32%; vertical-align: top;">
        <div class="section-title" style="font-size: 10pt;">5. NO. OF CANDIDATES PRESENT DURING EXAMINATION</div>
        <table>
            <tbody>
                <tr>
                    <td><strong>BOYS</strong></td>
                    <td><?php echo $analysis['overall']['male_count']; ?></td>
                </tr>
                <tr>
                    <td><strong>GIRLS</strong></td>
                    <td><?php echo $analysis['overall']['female_count']; ?></td>
                </tr>
                <tr style="background-color: #e0e0e0;">
                    <td><strong>TOTAL</strong></td>
                    <td><strong><?php echo $total_candidates; ?></strong></td>
                </tr>
            </tbody>
        </table>
    </div>

    <div style="clear: both;"></div>

    <!-- SECTION 6: SUBJECT GRADES -->
    <div class="section-title">6. SUBJECT GRADES</div>
    <table>
        <thead>
            <tr>
                <th rowspan="2">SUBJECTS</th>
                <th colspan="3">TOTAL NO. OF CAND.<br/>OBTAINING GRADE 1-3</th>
                <th colspan="3">TOTAL NO.OF CAND<br/>OBTAINING GRADE 4-5</th>
                <th colspan="3">OVERALL TOTAL FOR<br/>GRADES 1 TO 5</th>
                <th colspan="3">OVERALL PERCENTAGE<br/>PASS</th>
                <th rowspan="2">POSITION<br/>IN SUBJECTS</th>
            </tr>
            <tr>
                <th>BOYS</th>
                <th>GIRLS</th>
                <th>TOTAL</th>
                <th>BOYS</th>
                <th>GIRLS</th>
                <th>TOTAL</th>
                <th>BOYS</th>
                <th>GIRLS</th>
                <th>TOTAL</th>
                <th>BOYS</th>
                <th>GIRLS</th>
                <th>TOTAL</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($analysis['subject_grades'] as $subj): ?>
            <tr>
                <td style="text-align: left;"><strong><?php echo htmlspecialchars($subj['name']); ?></strong></td>
                <td><?php echo $subj['grades_1_3']['M']; ?></td>
                <td><?php echo $subj['grades_1_3']['F']; ?></td>
                <td><?php echo $subj['grades_1_3']['total']; ?></td>
                <td><?php echo $subj['grades_4_5']['M']; ?></td>
                <td><?php echo $subj['grades_4_5']['F']; ?></td>
                <td><?php echo $subj['grades_4_5']['total']; ?></td>
                <td><?php echo $subj['grades_1_5']['M']; ?></td>
                <td><?php echo $subj['grades_1_5']['F']; ?></td>
                <td><?php echo $subj['grades_1_5']['total']; ?></td>
                <td><?php echo number_format($subj['percentage']['M'], 1); ?>%</td>
                <td><?php echo number_format($subj['percentage']['F'], 1); ?>%</td>
                <td><?php echo number_format($subj['percentage']['total'], 1); ?>%</td>
                <td><?php echo $subj['position']; ?><?php 
                    $pos = $subj['position'];
                    if ($pos == 1) echo 'st';
                    elseif ($pos == 2) echo 'nd';
                    elseif ($pos == 3) echo 'rd';
                    else echo 'th';
                ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- SECTION 7: LANGUAGE AND ICT -->
    <div style="display: inline-block; width: 48%; vertical-align: top; margin-right: 2%;">
        <div class="section-title" style="font-size: 10pt;">7. LANGUAGE AND ICT</div>
        <table>
            <thead>
                <tr>
                    <th>SUBJECT</th>
                    <th colspan="3">NO. OF CANDIDATES PRESENT</th>
                </tr>
                <tr>
                    <th></th>
                    <th>BOYS</th>
                    <th>GIRLS</th>
                    <th>TOTAL</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td style="text-align: left;"><strong>GHANAIAN LANGUAGE</strong></td>
                    <td><?php echo $analysis['overall']['male_count']; ?></td>
                    <td><?php echo $analysis['overall']['female_count']; ?></td>
                    <td><strong><?php echo $total_candidates; ?></strong></td>
                </tr>
                <tr>
                    <td style="text-align: left;"><strong>CREATIVE ARTS & DESIGN</strong></td>
                    <td><?php echo $analysis['overall']['male_count']; ?></td>
                    <td><?php echo $analysis['overall']['female_count']; ?></td>
                    <td><strong><?php echo $total_candidates; ?></strong></td>
                </tr>
                <tr>
                    <td style="text-align: left;"><strong>COMPUTING</strong></td>
                    <td><?php echo $analysis['overall']['male_count']; ?></td>
                    <td><?php echo $analysis['overall']['female_count']; ?></td>
                    <td><strong><?php echo $total_candidates; ?></strong></td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- NUMBER OF ONES (Aggregates) -->
    <div style="display: inline-block; width: 48%; vertical-align: top;">
        <div class="section-title" style="font-size: 10pt;">NUMBER OF ONES (Aggregates)</div>
        <table>
            <thead>
                <tr>
                    <th></th>
                    <th>NINE</th>
                    <th>EIGHT</th>
                    <th>SEVEN</th>
                    <th>SIX</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>BOYS</strong></td>
                    <td><?php echo ($analysis['aggregate_categories']['6']['M'] ?? 0); ?></td>
                    <td>0</td>
                    <td>0</td>
                    <td>0</td>
                </tr>
                <tr>
                    <td><strong>GIRLS</strong></td>
                    <td><?php echo ($analysis['aggregate_categories']['6']['F'] ?? 0); ?></td>
                    <td>0</td>
                    <td>0</td>
                    <td>0</td>
                </tr>
                <tr style="background-color: #e0e0e0;">
                    <td><strong>TOTAL</strong></td>
                    <td><strong><?php echo ($analysis['aggregate_categories']['6']['total'] ?? 0); ?></strong></td>
                    <td><strong>0</strong></td>
                    <td><strong>0</strong></td>
                    <td><strong>0</strong></td>
                </tr>
            </tbody>
        </table>
    </div>

    <div style="clear: both;"></div>

    <!-- SECTION 8: AGGREGATE DISTRIBUTION -->
    <div class="section-title">8. NO. OF CANDIDATES OBTAINING AGGREGATES AS INDICATED</div>
    <table>
        <thead>
            <tr>
                <th>AGGREGATES</th>
                <th>6</th>
                <th>7-15</th>
                <th>16-24</th>
                <th>25-30</th>
                <th>31-36</th>
                <th>37-40</th>
                <th>T. PASS<br/>6-30</th>
                <th>% PASS<br/>6-30</th>
                <th>OVERALL %<br/>PASS 6-30</th>
                <th>T. PASS<br/>6-36</th>
                <th>% PASS<br/>6-36</th>
                <th>OVERALL %<br/>PASS 6-36</th>
                <th>TOTAL PASS<br/>6-40</th>
                <th>% PASS<br/>6-40</th>
                <th>OVERALL %<br/>PASS 6-40</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><strong>BOYS</strong></td>
                <td><?php echo $analysis['aggregate_categories']['6']['M']; ?></td>
                <td><?php echo $analysis['aggregate_categories']['7-15']['M']; ?></td>
                <td><?php echo $analysis['aggregate_categories']['16-24']['M']; ?></td>
                <td><?php echo $analysis['aggregate_categories']['25-30']['M']; ?></td>
                <td><?php echo $analysis['aggregate_categories']['31-36']['M']; ?></td>
                <td><?php echo $analysis['aggregate_categories']['37-40']['M']; ?></td>
                <td><?php echo $analysis['pass_stats']['6-30']['M']; ?></td>
                <td><?php echo $analysis['overall']['male_count'] > 0 ? number_format(($analysis['pass_stats']['6-30']['M'] / $analysis['overall']['male_count']) * 100, 1) : 0; ?>%</td>
                <td rowspan="2"><strong><?php echo $total_candidates > 0 ? number_format(($analysis['pass_stats']['6-30']['total'] / $total_candidates) * 100, 1) : 0; ?>%</strong></td>
                <td><?php echo $analysis['pass_stats']['6-36']['M']; ?></td>
                <td><?php echo $analysis['overall']['male_count'] > 0 ? number_format(($analysis['pass_stats']['6-36']['M'] / $analysis['overall']['male_count']) * 100, 1) : 0; ?>%</td>
                <td rowspan="2"><strong><?php echo $total_candidates > 0 ? number_format(($analysis['pass_stats']['6-36']['total'] / $total_candidates) * 100, 1) : 0; ?>%</strong></td>
                <td><?php echo $analysis['pass_stats']['6-40']['M']; ?></td>
                <td><?php echo $analysis['overall']['male_count'] > 0 ? number_format(($analysis['pass_stats']['6-40']['M'] / $analysis['overall']['male_count']) * 100, 1) : 0; ?>%</td>
                <td rowspan="2"><strong><?php echo $total_candidates > 0 ? number_format(($analysis['pass_stats']['6-40']['total'] / $total_candidates) * 100, 1) : 0; ?>%</strong></td>
            </tr>
            <tr>
                <td><strong>GIRLS</strong></td>
                <td><?php echo $analysis['aggregate_categories']['6']['F']; ?></td>
                <td><?php echo $analysis['aggregate_categories']['7-15']['F']; ?></td>
                <td><?php echo $analysis['aggregate_categories']['16-24']['F']; ?></td>
                <td><?php echo $analysis['aggregate_categories']['25-30']['F']; ?></td>
                <td><?php echo $analysis['aggregate_categories']['31-36']['F']; ?></td>
                <td><?php echo $analysis['aggregate_categories']['37-40']['F']; ?></td>
                <td><?php echo $analysis['pass_stats']['6-30']['F']; ?></td>
                <td><?php echo $analysis['overall']['female_count'] > 0 ? number_format(($analysis['pass_stats']['6-30']['F'] / $analysis['overall']['female_count']) * 100, 1) : 0; ?>%</td>
                <td><?php echo $analysis['pass_stats']['6-36']['F']; ?></td>
                <td><?php echo $analysis['overall']['female_count'] > 0 ? number_format(($analysis['pass_stats']['6-36']['F'] / $analysis['overall']['female_count']) * 100, 1) : 0; ?>%</td>
                <td><?php echo $analysis['pass_stats']['6-40']['F']; ?></td>
                <td><?php echo $analysis['overall']['female_count'] > 0 ? number_format(($analysis['pass_stats']['6-40']['F'] / $analysis['overall']['female_count']) * 100, 1) : 0; ?>%</td>
            </tr>
            <tr style="background-color: #e0e0e0;">
                <td><strong>TOTAL</strong></td>
                <td><strong><?php echo $analysis['aggregate_categories']['6']['total']; ?></strong></td>
                <td><strong><?php echo $analysis['aggregate_categories']['7-15']['total']; ?></strong></td>
                <td><strong><?php echo $analysis['aggregate_categories']['16-24']['total']; ?></strong></td>
                <td><strong><?php echo $analysis['aggregate_categories']['25-30']['total']; ?></strong></td>
                <td><strong><?php echo $analysis['aggregate_categories']['31-36']['total']; ?></strong></td>
                <td><strong><?php echo $analysis['aggregate_categories']['37-40']['total']; ?></strong></td>
                <td><strong><?php echo $analysis['pass_stats']['6-30']['total']; ?></strong></td>
                <td><strong><?php echo $total_candidates > 0 ? number_format(($analysis['pass_stats']['6-30']['total'] / $total_candidates) * 100, 1) : 0; ?>%</strong></td>
                <td>-</td>
                <td><strong><?php echo $analysis['pass_stats']['6-36']['total']; ?></strong></td>
                <td><strong><?php echo $total_candidates > 0 ? number_format(($analysis['pass_stats']['6-36']['total'] / $total_candidates) * 100, 1) : 0; ?>%</strong></td>
                <td>-</td>
                <td><strong><?php echo $analysis['pass_stats']['6-40']['total']; ?></strong></td>
                <td><strong><?php echo $total_candidates > 0 ? number_format(($analysis['pass_stats']['6-40']['total'] / $total_candidates) * 100, 1) : 0; ?>%</strong></td>
                <td>-</td>
            </tr>
        </tbody>
    </table>

    <br/>

    <!-- SECTIONS 9, 10, 11 -->
    <div style="display: flex; justify-content: space-between; gap: 15px; margin: 20px 0;">
        <div class="stat-box" style="flex: 1;">
            <h4>9. NO. OF CANDIDATES OBTAINING AGGREGATE 41-ABOVE</h4>
            <div class="value"><?php echo $analysis['special_stats']['aggregate_41_above']; ?></div>
        </div>
        <div style="border-left: 1px solid #000; margin: 0 10px;"></div>
        <div class="stat-box" style="flex: 1;">
            <h4>10. NO. OF CANDIDATES OBTAINED NINE FOR ALL SUBJECTS</h4>
            <div class="value"><?php echo $analysis['special_stats']['all_nines']; ?></div>
        </div>
        <div style="border-left: 1px solid #000; margin: 0 10px;"></div>
        <div class="stat-box" style="flex: 1;">
            <h4>11. NO. OF CANDIDATES ABSENT DURING EXAMINATION</h4>
            <div class="value"><?php echo $analysis['special_stats']['absent']; ?></div>
        </div>
    </div>

    <br/>

    <!-- SECTION 12: REASONS FOR ABSENTEEISM -->
    <div class="section-title">12. REASONS FOR ABSENTEEISM</div>
    <table style="width: 50%;">
        <thead>
            <tr>
                <th style="text-align: left;">REASONS</th>
                <th>BOYS</th>
                <th>GIRLS</th>
                <th>TOTAL</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td style="text-align: left;">PREGNANT/MARRIED</td>
                <td><?php echo $analysis['absenteeism_reasons']['pregnant']['M']; ?></td>
                <td><?php echo $analysis['absenteeism_reasons']['pregnant']['F']; ?></td>
                <td><?php echo $analysis['absenteeism_reasons']['pregnant']['M'] + $analysis['absenteeism_reasons']['pregnant']['F']; ?></td>
            </tr>
            <tr>
                <td style="text-align: left;">DEATH</td>
                <td><?php echo $analysis['absenteeism_reasons']['death']['M']; ?></td>
                <td><?php echo $analysis['absenteeism_reasons']['death']['F']; ?></td>
                <td><?php echo $analysis['absenteeism_reasons']['death']['M'] + $analysis['absenteeism_reasons']['death']['F']; ?></td>
            </tr>
            <tr>
                <td style="text-align: left;">TRAVELLED</td>
                <td><?php echo $analysis['absenteeism_reasons']['travelled']['M']; ?></td>
                <td><?php echo $analysis['absenteeism_reasons']['travelled']['F']; ?></td>
                <td><?php echo $analysis['absenteeism_reasons']['travelled']['M'] + $analysis['absenteeism_reasons']['travelled']['F']; ?></td>
            </tr>
            <tr>
                <td style="text-align: left;">ILLNESS</td>
                <td><?php echo $analysis['absenteeism_reasons']['illness']['M']; ?></td>
                <td><?php echo $analysis['absenteeism_reasons']['illness']['F']; ?></td>
                <td><?php echo $analysis['absenteeism_reasons']['illness']['M'] + $analysis['absenteeism_reasons']['illness']['F']; ?></td>
            </tr>
            <tr>
                <td style="text-align: left;">TRUANCY/DROP-OUT</td>
                <td><?php echo $analysis['absenteeism_reasons']['truancy']['M']; ?></td>
                <td><?php echo $analysis['absenteeism_reasons']['truancy']['F']; ?></td>
                <td><?php echo $analysis['absenteeism_reasons']['truancy']['M'] + $analysis['absenteeism_reasons']['truancy']['F']; ?></td>
            </tr>
            <tr>
                <td style="text-align: left;">WITHDRAWAL/TRANSFER</td>
                <td><?php echo $analysis['absenteeism_reasons']['withdrawal']['M']; ?></td>
                <td><?php echo $analysis['absenteeism_reasons']['withdrawal']['F']; ?></td>
                <td><?php echo $analysis['absenteeism_reasons']['withdrawal']['M'] + $analysis['absenteeism_reasons']['withdrawal']['F']; ?></td>
            </tr>
            <tr>
                <td style="text-align: left;">UNKNOWN</td>
                <td><?php echo $analysis['absenteeism_reasons']['unknown']['M']; ?></td>
                <td><?php echo $analysis['absenteeism_reasons']['unknown']['F']; ?></td>
                <td><?php echo $analysis['absenteeism_reasons']['unknown']['M'] + $analysis['absenteeism_reasons']['unknown']['F']; ?></td>
            </tr>
            <tr style="background-color: #e0e0e0;">
                <td style="text-align: left;"><strong>TOTAL</strong></td>
                <td><strong><?php echo array_sum(array_column($analysis['absenteeism_reasons'], 'M')); ?></strong></td>
                <td><strong><?php echo array_sum(array_column($analysis['absenteeism_reasons'], 'F')); ?></strong></td>
                <td><strong><?php echo $analysis['special_stats']['absent']; ?></strong></td>
            </tr>
        </tbody>
    </table>

    <br/>

    <!-- DETAILED FREQUENCY DISTRIBUTION -->
    <div class="section-title">DETAILED AGGREGATE FREQUENCY DISTRIBUTION</div>
    
    <div style="overflow: hidden; margin-bottom: 20px;">
        <div style="width: 33%; float: left; padding-right: 10px;">
            <table>
                <thead>
                    <tr>
                        <th>AGGREGATE</th>
                        <th>f(BOYS)</th>
                        <th>f(GIRLS)</th>
                        <th>TOTAL</th>
                    </tr>
                </thead>
                <tbody>
                    <?php for ($i = 6; $i <= 20; $i++): ?>
                    <tr>
                        <td><strong><?php echo $i; ?></strong></td>
                        <td><?php echo $analysis['aggregate_frequency'][$i]['M']; ?></td>
                        <td><?php echo $analysis['aggregate_frequency'][$i]['F']; ?></td>
                        <td><strong><?php echo $analysis['aggregate_frequency'][$i]['total']; ?></strong></td>
                    </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>

        <div style="width: 33%; float: left; padding-right: 10px;">
            <table>
                <thead>
                    <tr>
                        <th>AGGREGATE</th>
                        <th>f(BOYS)</th>
                        <th>f(GIRLS)</th>
                        <th>TOTAL</th>
                    </tr>
                </thead>
                <tbody>
                    <?php for ($i = 21; $i <= 35; $i++): ?>
                    <tr>
                        <td><strong><?php echo $i; ?></strong></td>
                        <td><?php echo $analysis['aggregate_frequency'][$i]['M']; ?></td>
                        <td><?php echo $analysis['aggregate_frequency'][$i]['F']; ?></td>
                        <td><strong><?php echo $analysis['aggregate_frequency'][$i]['total']; ?></strong></td>
                    </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>

        <div style="width: 33%; float: left;">
            <table>
                <thead>
                    <tr>
                        <th>AGGREGATE</th>
                        <th>f(BOYS)</th>
                        <th>f(GIRLS)</th>
                        <th>TOTAL</th>
                    </tr>
                </thead>
                <tbody>
                    <?php for ($i = 36; $i <= 50; $i++): ?>
                    <tr>
                        <td><strong><?php echo $i; ?></strong></td>
                        <td><?php echo $analysis['aggregate_frequency'][$i]['M']; ?></td>
                        <td><?php echo $analysis['aggregate_frequency'][$i]['F']; ?></td>
                        <td><strong><?php echo $analysis['aggregate_frequency'][$i]['total']; ?></strong></td>
                    </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div style="clear: both;"></div>

    <!-- SUMMARY TABLE -->
    <table style="margin-top: 20px;">
        <thead>
            <tr>
                <th>AGGREGATE</th>
                <th>51</th>
                <th>52</th>
                <th>53</th>
                <th>54</th>
                <th>TOTAL</th>
                <th>MEAN</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><strong>f(BOYS)</strong></td>
                <td><?php echo $analysis['aggregate_frequency'][51]['M']; ?></td>
                <td><?php echo $analysis['aggregate_frequency'][52]['M']; ?></td>
                <td><?php echo $analysis['aggregate_frequency'][53]['M']; ?></td>
                <td><?php echo $analysis['aggregate_frequency'][54]['M']; ?></td>
                <td><?php echo $analysis['overall']['male_count']; ?></td>
                <td><?php echo number_format($analysis['aggregate_stats']['male']['average'], 3); ?></td>
            </tr>
            <tr>
                <td><strong>f(GIRLS)</strong></td>
                <td><?php echo $analysis['aggregate_frequency'][51]['F']; ?></td>
                <td><?php echo $analysis['aggregate_frequency'][52]['F']; ?></td>
                <td><?php echo $analysis['aggregate_frequency'][53]['F']; ?></td>
                <td><?php echo $analysis['aggregate_frequency'][54]['F']; ?></td>
                <td><?php echo $analysis['overall']['female_count']; ?></td>
                <td><?php echo number_format($analysis['aggregate_stats']['female']['average'], 3); ?></td>
            </tr>
            <tr style="background-color: #e0e0e0;">
                <td><strong>TOTAL</strong></td>
                <td><strong><?php echo $analysis['aggregate_frequency'][51]['total']; ?></strong></td>
                <td><strong><?php echo $analysis['aggregate_frequency'][52]['total']; ?></strong></td>
                <td><strong><?php echo $analysis['aggregate_frequency'][53]['total']; ?></strong></td>
                <td><strong><?php echo $analysis['aggregate_frequency'][54]['total']; ?></strong></td>
                <td><strong><?php echo $total_candidates; ?></strong></td>
                <td><strong><?php echo number_format($analysis['overall']['average_aggregate'], 3); ?></strong></td>
            </tr>
        </tbody>
    </table>

    <div style="margin-top: 40px;">
        <p><strong>HEAD'S TELEPHONE:</strong> _________________________</p>
        <p><strong>SIGN(HEAD/ASSIST./PROPRIETOR):</strong> _________________________</p>
    </div>

    <?php endif; ?>

</div>

<?php include 'components/footer.php'; ?>
