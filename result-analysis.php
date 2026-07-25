<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Include required files
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/controllers/AuthController.php';
require_once __DIR__ . '/controllers/StudentController.php';
require_once __DIR__ . '/controllers/ClassController.php';
require_once __DIR__ . '/controllers/ScoreController.php';
require_once __DIR__ . '/helpers/GradingSystem.php';

// Get school info
$db = Database::getInstance()->getConnection();
$schoolInfo = null;
if (isset($_SESSION['school_id'])) {
    $stmt = $db->prepare("SELECT * FROM school_info WHERE id = ?");
    $stmt->execute([$_SESSION['school_id']]);
    $schoolInfo = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Load grading system from database
$gradingSystemData = GradingSystem::loadGrades();

// Convert score to Ghana grade using database grading system
function convertScoreToGhanaGrade($score, $gradingSystemData) {
    foreach ($gradingSystemData as $gradeInfo) {
        if ($score >= $gradeInfo['min_score'] && $score <= $gradeInfo['max_score']) {
            return (int)$gradeInfo['grade'];
        }
    }
    return 9; // Default to lowest grade if no match
}

// Get grade label from database grading system
function getGradeLabel($grade, $gradingSystemData) {
    foreach ($gradingSystemData as $gradeInfo) {
        if ((int)$gradeInfo['grade'] === (int)$grade) {
            return $gradeInfo['remarks'];
        }
    }
    return 'N/A';
}

// Calculate student aggregate (4 core + 2 best subjects) - OPTIMIZED
function calculateStudentAggregate($studentScores, $subjectNamesMap, $gradingSystemData) {
    static $coreSubjects = ['English Language', 'Mathematics', 'Integrated Science', 'Social Studies'];
    $coreGrades = [];
    $otherGrades = [];
    
    foreach ($studentScores as $subjectId => $score) {
        $subjectName = $subjectNamesMap[$subjectId] ?? '';
        
        if ($score['total_score'] > 0) {
            $grade = convertScoreToGhanaGrade($score['total_score'], $gradingSystemData);
            
            if (in_array($subjectName, $coreSubjects)) {
                $coreGrades[$subjectName] = $grade;
            } else {
                $otherGrades[$subjectName] = $grade;
            }
        }
    }
    
    // Must have at least all 4 core subjects to calculate aggregate
    // Return null if student doesn't have complete core subject scores
    if (count($coreGrades) < 4) {
        return null;
    }
    
    // Sort other subjects by grade (ascending - lower is better)
    asort($otherGrades);
    
    // Take best 2 other subjects - must have at least 2 electives
    $bestOthers = array_slice($otherGrades, 0, 2, true);
    if (count($bestOthers) < 2) {
        return null; // Cannot calculate aggregate without at least 2 elective subjects
    }
    
    // Calculate aggregate (4 core + 2 best electives)
    $aggregate = array_sum($coreGrades) + array_sum($bestOthers);
    
    return [
        'aggregate' => $aggregate,
        'core_grades' => $coreGrades,
        'best_others' => $bestOthers,
        'total_subjects' => count($coreGrades) + count($bestOthers)
    ];
}

// Get aggregate category
function getAggregateCategory($aggregate) {
    if ($aggregate >= 6 && $aggregate <= 12) return 'Excellent';
    if ($aggregate >= 13 && $aggregate <= 24) return 'Good';
    if ($aggregate >= 25 && $aggregate <= 36) return 'Satisfactory';
    if ($aggregate >= 37 && $aggregate <= 48) return 'Fail';
    return 'N/A';
}

$classController = new ClassController();
$studentController = new StudentController();
$scoreController = new ScoreController();

// Get selected class
$schoolTypeId = $_SESSION['school_type_id'] ?? null;
$classes = $classController->getAllClasses($schoolTypeId);
$selectedClassId = isset($_GET['class']) ? (int)$_GET['class'] : ($classes[0]['id'] ?? null);
$selectedClass = $selectedClassId ? $classController->getClass($selectedClassId) : null;

// Get students and subjects - OPTIMIZED
$students = [];
$subjects = [];
if ($selectedClassId) {
    // Fetch students with indexed array for faster lookups
    $students = $studentController->getStudentsByClass($selectedClassId);
    
    // Fetch subjects with school isolation
    $stmt = $db->prepare("SELECT s.id, s.subject_name, s.class_id FROM subjects s JOIN classes c ON s.class_id = c.id WHERE s.class_id = ? AND c.school_id = ? ORDER BY s.subject_name");
    $stmt->execute([$selectedClassId, $_SESSION['school_id']]);
    $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Initialize analysis data - EXACTLY like Google Apps Script
$analysis = [
    'overall' => [
        'totalStudents' => count($students),
        'maleCount' => 0,
        'femaleCount' => 0,
        'overallAverage' => 0,
        'passRate' => 0,
        'highestTotalScore' => 0,
        'lowestTotalScore' => 100
    ],
    'aggregateAnalysis' => [
        'distribution' => [
            '6-12' => 0,   // Excellent
            '13-24' => 0,  // Good
            '25-36' => 0,  // Satisfactory
            '37-48' => 0   // Fail
        ],
        'passRate' => 0,
        'averageAggregate' => 0,
        'highestAggregate' => 48,
        'lowestAggregate' => 6
    ],
    'gradeDistribution' => [],  // Will be populated dynamically from database grading system
    'subjects' => [],
    'topStudents' => [],
    'bottomStudents' => []
];

// Initialize grade distribution from database grading system
foreach ($gradingSystemData as $gradeInfo) {
    $rangeKey = $gradeInfo['min_score'] . '-' . $gradeInfo['max_score'];
    $analysis['gradeDistribution'][$rangeKey] = 0;
}

// Calculate student aggregates and performance
$studentAggregates = [];

// OPTIMIZATION: Fetch all scores at once with JOIN for maximum speed - filter by school_id
$allScoresMap = [];
if (!empty($students) && !empty($subjects) && $selectedClassId) {
    // Get school_id and current term first
    $schoolStmt = $db->prepare("SELECT id, current_term, academic_year FROM school_info WHERE id = ?");
    $schoolStmt->execute([$schoolTypeId]);
    $schoolData = $schoolStmt->fetch(PDO::FETCH_ASSOC);
    $schoolId = $schoolData['id'] ?? null;
    $currentTerm = $schoolData['current_term'] ?? 'First Term';
    $currentYear = $schoolData['academic_year'] ?? '2024/2025';
    
    // Single optimized query with JOIN - filter by school_id and term to prevent cross-school data
    $stmt = $db->prepare("
        SELECT s.student_id, s.subject_id, s.total_score 
        FROM scores s
        INNER JOIN subjects sub ON s.subject_id = sub.id
        INNER JOIN students st ON s.student_id = st.id
        INNER JOIN classes c ON st.class_id = c.id
        WHERE sub.class_id = ? AND st.class_id = ? AND c.school_id = ? AND s.term = ? AND s.academic_year = ? AND s.total_score > 0
    ");
    $stmt->execute([$selectedClassId, $selectedClassId, $schoolId, $currentTerm, $currentYear]);
    
# Build indexed map for O(1) lookups
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $allScoresMap[$row['student_id']][$row['subject_id']] = ['total_score' => $row['total_score']];
    }
}

// Pre-build subject name map for faster lookups
$subjectNamesMap = [];
foreach ($subjects as $subject) {
    $subjectNamesMap[$subject['id']] = $subject['subject_name'];
}

foreach ($students as $student) {
    // Count gender
    $gender = isset($student['gender']) ? strtoupper(substr($student['gender'], 0, 1)) : '';
    if ($gender == 'M') {
        $analysis['overall']['maleCount']++;
    } else if ($gender == 'F') {
        $analysis['overall']['femaleCount']++;
    }
    
    // Get all student scores from cached map
    $studentScores = $allScoresMap[$student['id']] ?? [];
    $totalScore = 0;
    $scoreCount = 0;
    
    foreach ($studentScores as $score) {
        if ($score['total_score'] > 0) {
            $totalScore += $score['total_score'];
            $scoreCount++;
        }
    }
    
    // Calculate aggregate
    $aggregateData = calculateStudentAggregate($studentScores, $subjectNamesMap, $gradingSystemData);
    $averageScore = $scoreCount > 0 ? $totalScore / $scoreCount : 0;
    
    if ($aggregateData) {
        $studentAggregates[] = [
            'id' => $student['id'],
            'name' => $student['student_name'],
            'gender' => $gender,
            'aggregate' => $aggregateData['aggregate'],
            'aggregateData' => $aggregateData,
            'averageScore' => number_format($averageScore, 2),
            'totalScore' => $totalScore
        ];
        
        // Aggregate distribution
        $agg = $aggregateData['aggregate'];
        if ($agg >= 6 && $agg <= 12) $analysis['aggregateAnalysis']['distribution']['6-12']++;
        else if ($agg >= 13 && $agg <= 24) $analysis['aggregateAnalysis']['distribution']['13-24']++;
        else if ($agg >= 25 && $agg <= 36) $analysis['aggregateAnalysis']['distribution']['25-36']++;
        else $analysis['aggregateAnalysis']['distribution']['37-48']++;
        
        // Update grade distribution based on average using database grading system
        $gradeAssigned = false;
        foreach ($gradingSystemData as $gradeInfo) {
            if ($averageScore >= $gradeInfo['min_score'] && $averageScore <= $gradeInfo['max_score']) {
                $rangeKey = $gradeInfo['min_score'] . '-' . $gradeInfo['max_score'];
                if (isset($analysis['gradeDistribution'][$rangeKey])) {
                    $analysis['gradeDistribution'][$rangeKey]++;
                    $gradeAssigned = true;
                    break;
                }
            }
        }
        
        // Track highest/lowest total scores
        if ($totalScore > $analysis['overall']['highestTotalScore']) {
            $analysis['overall']['highestTotalScore'] = $totalScore;
        }
        if ($totalScore < $analysis['overall']['lowestTotalScore'] && $totalScore > 0) {
            $analysis['overall']['lowestTotalScore'] = $totalScore;
        }
    }
}

// Sort by aggregate (ascending - lower is better)
usort($studentAggregates, function($a, $b) {
    return $a['aggregate'] <=> $b['aggregate'];
});

// Calculate overall statistics
if (!empty($studentAggregates)) {
    $totalAvg = array_sum(array_column($studentAggregates, 'averageScore'));
    $totalAgg = array_sum(array_column($studentAggregates, 'aggregate'));
    $analysis['overall']['overallAverage'] = number_format($totalAvg / count($studentAggregates), 2);
    $analysis['aggregateAnalysis']['averageAggregate'] = number_format($totalAgg / count($studentAggregates), 2);
    
    // Calculate pass rates
    $passedStudents = array_filter($studentAggregates, function($s) {
        return floatval($s['averageScore']) >= 50;
    });
    $analysis['overall']['passRate'] = number_format((count($passedStudents) / count($studentAggregates)) * 100, 2);
    
    $passedAggregate = array_filter($studentAggregates, function($s) {
        return $s['aggregate'] >= 6 && $s['aggregate'] <= 36;
    });
    $analysis['aggregateAnalysis']['passRate'] = number_format((count($passedAggregate) / count($studentAggregates)) * 100, 2);
    
    // Best and worst aggregates
    $aggregates = array_column($studentAggregates, 'aggregate');
    $analysis['aggregateAnalysis']['highestAggregate'] = min($aggregates);
    $analysis['aggregateAnalysis']['lowestAggregate'] = max($aggregates);
}

// Get top and bottom students
$analysis['topStudents'] = array_slice($studentAggregates, 0, 5);
$analysis['bottomStudents'] = array_slice(array_reverse($studentAggregates), 0, 5);

// Subject analysis - using cached scores
foreach ($subjects as $subject) {
    // Initialize grade distribution from database grading system
    $subjectGradeDistribution = [];
    foreach ($gradingSystemData as $gradeInfo) {
        $rangeKey = $gradeInfo['min_score'] . '-' . $gradeInfo['max_score'];
        $subjectGradeDistribution[$rangeKey] = 0;
    }
    
    $subjectAnalysis = [
        'name' => $subject['subject_name'],
        'totalStudents' => 0,
        'passCount' => 0,
        'failCount' => 0,
        'highestScore' => 0,
        'lowestScore' => 100,
        'averageScore' => 0,
        'gradeDistribution' => $subjectGradeDistribution,
        'ghanaGradeDistribution' => array_fill(1, 9, 0)
    ];
    
    $totalScore = 0;
    foreach ($students as $student) {
        $score = $allScoresMap[$student['id']][$subject['id']] ?? null;
        if ($score && $score['total_score'] > 0) {
            $scoreValue = $score['total_score'];
            $subjectAnalysis['totalStudents']++;
            $totalScore += $scoreValue;
            
            if ($scoreValue > $subjectAnalysis['highestScore']) {
                $subjectAnalysis['highestScore'] = $scoreValue;
            }
            if ($scoreValue < $subjectAnalysis['lowestScore']) {
                $subjectAnalysis['lowestScore'] = $scoreValue;
            }
            
            if ($scoreValue >= 50) {
                $subjectAnalysis['passCount']++;
            } else {
                $subjectAnalysis['failCount']++;
            }
            
            // Grade distribution using database grading system
            foreach ($gradingSystemData as $gradeInfo) {
                if ($scoreValue >= $gradeInfo['min_score'] && $scoreValue <= $gradeInfo['max_score']) {
                    $rangeKey = $gradeInfo['min_score'] . '-' . $gradeInfo['max_score'];
                    if (isset($subjectAnalysis['gradeDistribution'][$rangeKey])) {
                        $subjectAnalysis['gradeDistribution'][$rangeKey]++;
                        break;
                    }
                }
            }
            
            // Ghana grade distribution
            $ghanaGrade = convertScoreToGhanaGrade($scoreValue, $gradingSystemData);
            $subjectAnalysis['ghanaGradeDistribution'][$ghanaGrade]++;
        }
    }
    
    $subjectAnalysis['averageScore'] = $subjectAnalysis['totalStudents'] > 0 ? 
        number_format($totalScore / $subjectAnalysis['totalStudents'], 2) : 0;
    
    $analysis['subjects'][] = $subjectAnalysis;
}

$pageTitle = 'Result Analysis';
require_once __DIR__ . '/components/header.php';
?>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <style>
    .container {
      max-width: 1200px;
      margin: 0 auto;
      background-color: white;
      padding: 20px;
      border-radius: 8px;
      box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
    }
    .report-header {
      text-align: center;
      margin-bottom: 20px;
      position: relative;
    }
    .logo {
      max-width: 80px;
      max-height: 80px;
      position: absolute;
      top: 0;
    }
    .logo.left {
      left: 0;
    }
    .logo.right {
      right: 0;
    }
    h1 {
      margin: 0;
      padding: 10px 0;
      color: #2c3e50;
    }
    h2 {
      color: #34495e;
      margin: 5px 0;
    }
    .controls {
      background: white;
      padding: 1.25rem 1.5rem;
      border-radius: 10px;
      box-shadow: 0 1px 4px rgba(0,0,0,.05);
      margin-bottom: 1.5rem;
      display: flex;
      justify-content: space-between;
      align-items: flex-end;
      flex-wrap: wrap;
      gap: 1rem;
    }
    .control-group {
      margin: 0;
    }
    label {
      display: block;
      margin-bottom: 5px;
      font-weight: 700;
      color: #374151;
      font-size: .82rem;
      text-transform: uppercase;
      letter-spacing: .4px;
    }
    select {
      padding: .6rem 1rem;
      border: 1px solid #e2e8f0;
      border-radius: 6px;
      font-size: .95rem;
      transition: border-color .2s;
      min-width: 200px;
    }
    select:focus {
      outline: none;
      border-color: #667eea;
      box-shadow: 0 0 0 3px rgba(102,126,234,.1);
    }
    button {
      background: linear-gradient(135deg, #667eea, #764ba2);
      color: white;
      cursor: pointer;
      border: none;
      padding: .6rem 1.25rem;
      border-radius: 6px;
      font-size: .9rem;
      font-weight: 600;
      margin-left: .5rem;
      transition: opacity .2s;
    }
    button:hover {
      opacity: 0.88;
    }
    .summary-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
      gap: 20px;
      margin-bottom: 30px;
    }
    .summary-card {
      background-color: #fff;
      border-radius: 6px;
      box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
      padding: 15px;
      text-align: center;
    }
    .card-title {
      font-size: 16px;
      font-weight: bold;
      margin-bottom: 10px;
      color: #2c3e50;
    }
    .card-value {
      font-size: 28px;
      font-weight: bold;
      color: #3498db;
    }
    .card-subtitle {
      font-size: 14px;
      color: #7f8c8d;
      margin-top: 5px;
    }
    .analysis-section {
      margin-bottom: 30px;
      background-color: #fff;
      border-radius: 6px;
      padding: 20px;
      box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
    }
    .section-title {
      font-size: 18px;
      border-bottom: 2px solid #3498db;
      padding-bottom: 10px;
      margin-bottom: 15px;
      color: #2c3e50;
    }
    .chart-container {
      height: 300px;
      margin: 20px 0;
    }
    table {
      width: 100%;
      border-collapse: collapse;
      margin: 20px 0;
    }
    th, td {
      border: 1px solid #ddd;
      padding: 10px;
      text-align: left;
    }
    th {
      background-color: #f2f2f2;
      font-weight: bold;
    }
    tr:nth-child(even) {
      background-color: #f9f9f9;
    }
    .recommendations {
      background-color: #f1f8e9;
      padding: 15px;
      border-radius: 5px;
      margin-top: 20px;
    }
    .recommendation-item {
      margin-bottom: 10px;
      padding-left: 20px;
      position: relative;
    }
    .recommendation-item:before {
      content: "•";
      position: absolute;
      left: 0;
      color: #4caf50;
    }
    .grade-distribution {
      margin-top: 20px;
    }
    .grade-row {
      display: flex;
      justify-content: space-between;
      margin-bottom: 10px;
    }
    .grade-box {
      flex: 1;
      text-align: center;
      padding: 10px;
      border: 1px solid #ddd;
      background-color: #f9f9f9;
      border-radius: 4px;
      margin: 0 3px;
    }
    .grade-box .grade-range {
      font-weight: bold;
      font-size: 12px;
    }
    .grade-box .grade-count {
      font-size: 18px;
      font-weight: bold;
      margin: 5px 0;
      color: #3498db;
    }
    .grade-box .grade-desc {
      font-size: 11px;
      color: #777;
    }
    .gender-comparison {
      display: flex;
      justify-content: space-around;
      margin: 20px 0;
    }
    .gender-box {
      text-align: center;
      width: 45%;
      padding: 15px;
      border-radius: 5px;
    }
    .gender-male {
      background-color: #e3f2fd;
    }
    .gender-female {
      background-color: #fce4ec;
    }
    .gender-icon {
      font-size: 30px;
      margin-bottom: 10px;
    }
    .gender-count {
      font-size: 24px;
      font-weight: bold;
      margin-bottom: 5px;
    }
    .aggregate-card {
      border-left: 4px solid #27ae60;
      margin-bottom: 15px;
      background-color: #f9f9f9;
      border-radius: 4px;
      padding: 15px;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    .aggregate-excellent {
      border-left-color: #27ae60;
    }
    .aggregate-good {
      border-left-color: #2980b9;
    }
    .aggregate-satisfactory {
      border-left-color: #f39c12;
    }
    .aggregate-fail {
      border-left-color: #e74c3c;
    }
    .aggregate-range {
      font-size: 18px;
      font-weight: bold;
      color: #333;
    }
    .aggregate-count {
      font-size: 24px;
      font-weight: bold;
      margin: 0 10px;
    }
    .aggregate-desc {
      color: #555;
      font-size: 14px;
      margin-top: 5px;
    }
    .grade-guide {
      background-color: #f8f9fa;
      padding: 15px;
      border-radius: 5px;
      margin: 20px 0;
      font-size: 14px;
    }
    .grade-guide h4 {
      margin-top: 0;
      margin-bottom: 10px;
      color: #2c3e50;
    }
    .grade-guide-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 10px;
    }
    .grade-guide-item {
      display: flex;
      justify-content: space-between;
      padding: 5px 0;
      border-bottom: 1px dashed #ddd;
    }
    @media print {
      .controls, button {
        display: none;
      }
      .container {
        box-shadow: none;
        max-width: none;
      }
      body {
        background-color: white;
      }
      .analysis-section {
        page-break-inside: avoid;
      }
    }
    @media (max-width: 768px) {
      
      .container {
        padding: 10px;
      }
      
      .controls {
        flex-direction: column;
        align-items: stretch;
      }
      
      .control-group {
        margin: 5px 0;
      }
      
      .control-group select,
      .control-group button {
        width: 100%;
      }
      
      button {
        margin-left: 0;
        margin-top: 10px;
        padding: 12px;
        font-size: 16px;
      }
      
      .logo {
        position: static;
        display: block;
        margin: 0 auto 10px;
        max-width: 60px;
      }
      
      .summary-grid {
        grid-template-columns: 1fr;
        gap: 10px;
      }
      
      .summary-card {
        padding: 12px;
      }
      
      .card-value {
        font-size: 24px;
      }
      
      .gender-comparison {
        flex-direction: column;
      }
      
      .gender-box {
        width: auto;
        margin-bottom: 10px;
      }
      
      table {
        font-size: 0.75rem;
      }
      
      th, td {
        padding: 0.4rem 0.3rem !important;
      }
      
      .analysis-section {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
      }
      
      h1 {
        font-size: 1.3rem;
      }
      
      h2, h3 {
        font-size: 1.1rem;
      }
    }
  </style>

  <div class="container">
    <div class="report-header">
      <?php if ($schoolInfo && !empty($schoolInfo['logo'])): ?>
        <img src="<?php echo htmlspecialchars($schoolInfo['logo']); ?>" class="logo left" alt="School Logo">
      <?php endif; ?>
      <h1>RESULT ANALYSIS</h1>
      <p><?php echo htmlspecialchars($schoolInfo['school_name'] ?? 'School Based Assessment System'); ?></p>
      <p><?php echo htmlspecialchars($schoolInfo['address'] ?? ''); ?></p>
      <h2>ACADEMIC PERFORMANCE ANALYSIS</h2>
      <p>
        <?php 
          if ($selectedClass) {
            require_once __DIR__ . '/controllers/ClassController.php';
            echo htmlspecialchars(ClassController::getDisplayName($selectedClass));
          } else {
            echo 'Select a class';
          }
        ?>
      </p>
    </div>
    
    <div class="controls">
      <div class="control-group">
        <label for="classSelect">Select Class:</label>
        <select id="classSelect" onchange="window.location.href='result-analysis.php?class=' + this.value">
          <option value="">--Select Class--</option>
          <?php foreach ($classes as $class): ?>
            <option value="<?php echo $class['id']; ?>" <?php echo $selectedClassId == $class['id'] ? 'selected' : ''; ?>>
              <?php 
                  require_once __DIR__ . '/controllers/ClassController.php';
                  echo htmlspecialchars(ClassController::getDisplayName($class));
              ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      
      <div class="control-group">
        <button onclick="window.print()">Print</button>
        <?php if ($selectedClassId): ?>
        <button onclick="window.location.href='export_result_analysis_pdf.php?class_id=<?php echo $selectedClassId; ?>'">📄 Export Detailed PDF</button>
        <?php endif; ?>
      </div>
    </div>
    
    <div id="analysisContainer">
      <?php if (empty($students)): ?>
        <div class="analysis-placeholder">
          <p style="text-align: center; color: #777; padding: 100px 0;">
            Please select a class from the dropdown menu to generate the analysis.
          </p>
        </div>
      <?php else: ?>
        
        <!-- OVERALL SUMMARY -->
        <div class="summary-grid">
          <div class="summary-card">
            <div class="card-title">Total Students</div>
            <div class="card-value"><?php echo $analysis['overall']['totalStudents']; ?></div>
            <div class="card-subtitle">
              Enrolled in 
              <?php 
                require_once __DIR__ . '/controllers/ClassController.php';
                echo htmlspecialchars(ClassController::getDisplayName($selectedClass));
              ?>
            </div>
          </div>
          
          <div class="summary-card">
            <div class="card-title">Overall Average</div>
            <div class="card-value"><?php echo $analysis['overall']['overallAverage']; ?>%</div>
            <div class="card-subtitle">Class Performance</div>
          </div>
          
          <div class="summary-card">
            <div class="card-title">Pass Rate</div>
            <div class="card-value"><?php echo $analysis['overall']['passRate']; ?>%</div>
            <div class="card-subtitle">Students scoring 50% or higher</div>
          </div>
        </div>
        
        <!-- AGGREGATE ANALYSIS SECTION -->
        <div class="analysis-section">
          <div class="section-title">Aggregate Analysis (Ghana Education System)</div>
          
          <div class="grade-guide">
            <h4>Ghana Education Grading System Guide</h4>
            <div class="grade-guide-grid">
              <div class="grade-guide-item">
                <span>90-100%</span>
                <span>Grade 1</span>
              </div>
              <div class="grade-guide-item">
                <span>80-89%</span>
                <span>Grade 2</span>
              </div>
              <div class="grade-guide-item">
                <span>70-79%</span>
                <span>Grade 3</span>
              </div>
              <div class="grade-guide-item">
                <span>60-69%</span>
                <span>Grade 4</span>
              </div>
              <div class="grade-guide-item">
                <span>55-59%</span>
                <span>Grade 5</span>
              </div>
              <div class="grade-guide-item">
                <span>50-54%</span>
                <span>Grade 6</span>
              </div>
              <div class="grade-guide-item">
                <span>40-49%</span>
                <span>Grade 7</span>
              </div>
              <div class="grade-guide-item">
                <span>35-39%</span>
                <span>Grade 8</span>
              </div>
              <div class="grade-guide-item">
                <span>0-34%</span>
                <span>Grade 9</span>
              </div>
            </div>
            <p style="margin-top: 10px;">Aggregate is calculated using 4 core subjects (English, Mathematics, Science, Social Studies) plus 2 best other subjects.</p>
            <p>Pass aggregate is 6 to 36. Lower aggregate is better.</p>
          </div>
          
          <div class="summary-grid">
            <div class="summary-card">
              <div class="card-title">Average Aggregate</div>
              <div class="card-value"><?php echo $analysis['aggregateAnalysis']['averageAggregate']; ?></div>
              <div class="card-subtitle">Lower is better</div>
            </div>
            
            <div class="summary-card">
              <div class="card-title">Aggregate Pass Rate</div>
              <div class="card-value"><?php echo $analysis['aggregateAnalysis']['passRate']; ?>%</div>
              <div class="card-subtitle">Aggregate 6-36</div>
            </div>
            
            <div class="summary-card">
              <div class="card-title">Best Aggregate</div>
              <div class="card-value"><?php echo $analysis['aggregateAnalysis']['highestAggregate']; ?></div>
              <div class="card-subtitle">Lowest number is best</div>
            </div>
          </div>
          
          <div style="margin-top: 20px;">
            <div class="aggregate-card aggregate-excellent">
              <div>
                <div class="aggregate-range">Excellent (6-12)</div>
                <div class="aggregate-desc">Outstanding performance</div>
              </div>
              <div class="aggregate-count"><?php echo $analysis['aggregateAnalysis']['distribution']['6-12']; ?></div>
            </div>
            
            <div class="aggregate-card aggregate-good">
              <div>
                <div class="aggregate-range">Good (13-24)</div>
                <div class="aggregate-desc">Very good performance</div>
              </div>
              <div class="aggregate-count"><?php echo $analysis['aggregateAnalysis']['distribution']['13-24']; ?></div>
            </div>
            
            <div class="aggregate-card aggregate-satisfactory">
              <div>
                <div class="aggregate-range">Satisfactory (25-36)</div>
                <div class="aggregate-desc">Acceptable performance</div>
              </div>
              <div class="aggregate-count"><?php echo $analysis['aggregateAnalysis']['distribution']['25-36']; ?></div>
            </div>
            
            <div class="aggregate-card aggregate-fail">
              <div>
                <div class="aggregate-range">Fail (37-48)</div>
                <div class="aggregate-desc">Below passing threshold</div>
              </div>
              <div class="aggregate-count"><?php echo $analysis['aggregateAnalysis']['distribution']['37-48']; ?></div>
            </div>
          </div>
          
          <div class="chart-container">
            <canvas id="aggregateDistributionChart"></canvas>
          </div>
        </div>
        
        <!-- GENDER DISTRIBUTION -->
        <div class="analysis-section">
          <div class="section-title">Gender Distribution</div>
          <div class="gender-comparison">
            <div class="gender-box gender-male">
              <div class="gender-icon">♂</div>
              <div class="gender-count"><?php echo $analysis['overall']['maleCount']; ?></div>
              <div><?php echo $analysis['overall']['totalStudents'] > 0 ? number_format(($analysis['overall']['maleCount'] / $analysis['overall']['totalStudents']) * 100, 1) : 0; ?>% Male</div>
            </div>
            
            <div class="gender-box gender-female">
              <div class="gender-icon">♀</div>
              <div class="gender-count"><?php echo $analysis['overall']['femaleCount']; ?></div>
              <div><?php echo $analysis['overall']['totalStudents'] > 0 ? number_format(($analysis['overall']['femaleCount'] / $analysis['overall']['totalStudents']) * 100, 1) : 0; ?>% Female</div>
            </div>
          </div>
          
          <div class="chart-container">
            <canvas id="genderChart"></canvas>
          </div>
        </div>
        
        <!-- GRADE DISTRIBUTION -->
        <div class="analysis-section">
          <div class="section-title">Grade Distribution</div>
          <div class="grade-distribution">
            <div class="grade-row">
              <?php foreach (['80-100', '75-79', '70-74', '65-69', '60-64'] as $range): ?>
              <div class="grade-box">
                <div class="grade-range"><?php echo $range; ?>%</div>
                <div class="grade-count"><?php echo $analysis['gradeDistribution'][$range]; ?></div>
                <div class="grade-desc">
                  <?php 
                    $desc = ['80-100' => 'Highest', '75-79' => 'Higher', '70-74' => 'High', '65-69' => 'High Average', '60-64' => 'Average'];
                    echo $desc[$range];
                  ?>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
            <div class="grade-row">
              <?php foreach (['55-59', '50-54', '40-49', '0-39'] as $range): ?>
              <div class="grade-box">
                <div class="grade-range"><?php echo $range; ?>%</div>
                <div class="grade-count"><?php echo $analysis['gradeDistribution'][$range]; ?></div>
                <div class="grade-desc">
                  <?php 
                    $desc = ['55-59' => 'Low Average', '50-54' => 'Low', '40-49' => 'Lower', '0-39' => 'Lowest'];
                    echo $desc[$range];
                  ?>
                </div>
              </div>
              <?php endforeach; ?>
              <div class="grade-box" style="visibility: hidden;"></div>
            </div>
          </div>
          
          <div class="chart-container">
            <canvas id="gradeDistributionChart"></canvas>
          </div>
        </div>
        
        <!-- SUBJECT PERFORMANCE ANALYSIS -->
        <div class="analysis-section">
          <div class="section-title">Subject Performance Analysis</div>
          
          <div class="chart-container">
            <canvas id="subjectPerformanceChart"></canvas>
          </div>
          
          <table>
            <tr>
              <th>Subject</th>
              <th>Average Score</th>
              <th>Pass Rate</th>
              <th>Highest Score</th>
              <th>Lowest Score</th>
            </tr>
            <?php foreach ($analysis['subjects'] as $subject): ?>
            <tr>
              <td><?php echo htmlspecialchars($subject['name']); ?></td>
              <td><?php echo $subject['averageScore']; ?>%</td>
              <td><?php echo $subject['totalStudents'] > 0 ? number_format(($subject['passCount'] / $subject['totalStudents']) * 100, 2) : 0; ?>%</td>
              <td><?php echo $subject['highestScore']; ?>%</td>
              <td><?php echo $subject['lowestScore']; ?>%</td>
            </tr>
            <?php endforeach; ?>
          </table>
        </div>
        
        <!-- TOP PERFORMERS -->
        <div class="analysis-section">
          <div class="section-title">Top Performers</div>
          <table>
            <tr>
              <th>Position</th>
              <th>Student ID</th>
              <th>Name</th>
              <th>Gender</th>
              <th>Average Score</th>
              <th>Aggregate</th>
            </tr>
            <?php foreach ($analysis['topStudents'] as $index => $student): ?>
            <tr>
              <td><?php echo $index + 1; ?></td>
              <td><?php echo htmlspecialchars($student['id']); ?></td>
              <td><?php echo htmlspecialchars($student['name']); ?></td>
              <td><?php echo htmlspecialchars($student['gender']); ?></td>
              <td><?php echo $student['averageScore']; ?>%</td>
              <td><?php echo $student['aggregate']; ?></td>
            </tr>
            <?php endforeach; ?>
          </table>
        </div>
        
        <!-- RECOMMENDATIONS -->
        <div class="analysis-section">
          <div class="section-title">Recommendations</div>
          <div class="recommendations">
            <div class="recommendation-item">Implement additional tutorial sessions for subjects with pass rates below 60%</div>
            <div class="recommendation-item">Provide targeted support for the bottom <?php echo min(5, ceil($analysis['overall']['totalStudents'] * 0.1)); ?> performing students</div>
            <div class="recommendation-item">Review and possibly adjust the teaching methodologies for subjects with the lowest average scores</div>
            <div class="recommendation-item">Recognize and celebrate high performers to motivate the entire class</div>
            <div class="recommendation-item">Consider parent-teacher conferences for students who failed multiple subjects or have aggregate above 36</div>
          </div>
        </div>
        
      <?php endif; ?>
    </div>
  </div>
  
  <script>
    // Charts
    <?php if (!empty($students)): ?>
    
    // Gender Chart
    const genderCtx = document.getElementById('genderChart').getContext('2d');
    new Chart(genderCtx, {
      type: 'pie',
      data: {
        labels: ['Male', 'Female'],
        datasets: [{
          data: [<?php echo $analysis['overall']['maleCount']; ?>, <?php echo $analysis['overall']['femaleCount']; ?>],
          backgroundColor: ['#2980b9', '#e84393'],
          borderColor: ['#fff', '#fff'],
          borderWidth: 1
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          title: {
            display: true,
            text: 'Gender Distribution'
          },
          legend: {
            position: 'bottom'
          }
        }
      }
    });
    
    // Aggregate Distribution Chart
    const aggregateCtx = document.getElementById('aggregateDistributionChart').getContext('2d');
    new Chart(aggregateCtx, {
      type: 'bar',
      data: {
        labels: ['Excellent (6-12)', 'Good (13-24)', 'Satisfactory (25-36)', 'Fail (37-48)'],
        datasets: [{
          label: 'Number of Students',
          data: [
            <?php echo $analysis['aggregateAnalysis']['distribution']['6-12']; ?>,
            <?php echo $analysis['aggregateAnalysis']['distribution']['13-24']; ?>,
            <?php echo $analysis['aggregateAnalysis']['distribution']['25-36']; ?>,
            <?php echo $analysis['aggregateAnalysis']['distribution']['37-48']; ?>
          ],
          backgroundColor: ['#27ae60', '#2980b9', '#f39c12', '#e74c3c'],
          borderColor: 'rgba(0, 0, 0, 0.1)',
          borderWidth: 1
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
          y: {
            beginAtZero: true,
            title: {
              display: true,
              text: 'Number of Students'
            }
          },
          x: {
            title: {
              display: true,
              text: 'Aggregate Ranges'
            }
          }
        },
        plugins: {
          title: {
            display: true,
            text: 'Aggregate Distribution'
          },
          legend: {
            display: false
          }
        }
      }
    });
    
    // Grade Distribution Chart
    const gradeCtx = document.getElementById('gradeDistributionChart').getContext('2d');
    new Chart(gradeCtx, {
      type: 'bar',
      data: {
        labels: ['80-100%', '75-79%', '70-74%', '65-69%', '60-64%', '55-59%', '50-54%', '40-49%', '0-39%'],
        datasets: [{
          label: 'Number of Students',
          data: [
            <?php echo implode(',', array_values($analysis['gradeDistribution'])); ?>
          ],
          backgroundColor: [
            '#27ae60', '#2ecc71', '#3498db', '#3498db', '#f1c40f',
            '#e67e22', '#e67e22', '#e74c3c', '#c0392b'
          ],
          borderColor: 'rgba(0, 0, 0, 0.1)',
          borderWidth: 1
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
          y: {
            beginAtZero: true,
            title: {
              display: true,
              text: 'Number of Students'
            }
          },
          x: {
            title: {
              display: true,
              text: 'Grade Ranges'
            }
          }
        },
        plugins: {
          title: {
            display: true,
            text: 'Grade Distribution'
          },
          legend: {
            display: false
          }
        }
      }
    });
    
    // Subject Performance Chart
    const subjectCtx = document.getElementById('subjectPerformanceChart').getContext('2d');
    new Chart(subjectCtx, {
      type: 'bar',
      data: {
        labels: <?php echo json_encode(array_column($analysis['subjects'], 'name')); ?>,
        datasets: [
          {
            label: 'Average Score (%)',
            data: <?php echo json_encode(array_map('floatval', array_column($analysis['subjects'], 'averageScore'))); ?>,
            backgroundColor: 'rgba(52, 152, 219, 0.7)',
            borderColor: 'rgba(52, 152, 219, 1)',
            borderWidth: 1
          },
          {
            label: 'Pass Rate (%)',
            data: <?php echo json_encode(array_map(function($s) {
              return $s['totalStudents'] > 0 ? round(($s['passCount'] / $s['totalStudents']) * 100, 2) : 0;
            }, $analysis['subjects'])); ?>,
            backgroundColor: 'rgba(46, 204, 113, 0.7)',
            borderColor: 'rgba(46, 204, 113, 1)',
            borderWidth: 1
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
          y: {
            beginAtZero: true,
            max: 100,
            title: {
              display: true,
              text: 'Percentage (%)'
            }
          },
          x: {
            title: {
              display: true,
              text: 'Subjects'
            }
          }
        },
        plugins: {
          title: {
            display: true,
            text: 'Subject Performance Comparison'
          },
          legend: {
            position: 'bottom'
          }
        }
      }
    });
    
    <?php endif; ?>
  </script>
<?php 
ob_end_flush(); // Flush output buffer
require_once __DIR__ . '/components/footer.php'; 
?>
