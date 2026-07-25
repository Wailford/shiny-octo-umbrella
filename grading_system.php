<?php
session_start();
require_once __DIR__ . '/controllers/AuthController.php';
require_once __DIR__ . '/config/database.php';

// Check if user is logged in and is admin
$auth = new Auth();
$auth->requireLogin();

if (!$auth->isAdmin()) {
    header('Location: dashboard.php');
    exit;
}

$db = Database::getInstance()->getConnection();

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        switch ($_POST['action']) {
            case 'update_grade':
                $gradeId = $_POST['grade_id'];
                $minScore = floatval($_POST['min_score']);
                $maxScore = floatval($_POST['max_score']);
                $remarks = trim($_POST['remarks']);
                
                // Validate
                if ($minScore >= $maxScore) {
                    echo json_encode(['success' => false, 'message' => 'Minimum score must be less than maximum score']);
                    exit;
                }
                
                if ($minScore < 0 || $maxScore > 100) {
                    echo json_encode(['success' => false, 'message' => 'Scores must be between 0 and 100']);
                    exit;
                }
                
                $stmt = $db->prepare("UPDATE grading_system SET min_score = ?, max_score = ?, remarks = ? WHERE id = ?");
                $stmt->execute([$minScore, $maxScore, $remarks, $gradeId]);
                
                echo json_encode(['success' => true, 'message' => 'Grade updated successfully']);
                break;
                
            case 'reset_default':
                // Reset to default Ghana Education Service grading system
                $db->exec("DELETE FROM grading_system");
                
                $stmt = $db->prepare("INSERT INTO grading_system (grade, min_score, max_score, remarks, display_order) VALUES (?, ?, ?, ?, ?)");
                
                $defaultGrades = [
                    ['1', 80.00, 100.00, 'Highest', 1],
                    ['2', 75.00, 79.99, 'Higher', 2],
                    ['3', 70.00, 74.99, 'High', 3],
                    ['4', 65.00, 69.99, 'High Average', 4],
                    ['5', 60.00, 64.99, 'Average', 5],
                    ['6', 55.00, 59.99, 'Low Average', 6],
                    ['7', 50.00, 54.99, 'Low', 7],
                    ['8', 40.00, 49.99, 'Lower', 8],
                    ['9', 0.00, 39.99, 'Lowest', 9]
                ];
                
                foreach ($defaultGrades as $grade) {
                    $stmt->execute($grade);
                }
                
                echo json_encode(['success' => true, 'message' => 'Grading system reset to default']);
                break;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Get grading system
$stmt = $db->query("SELECT * FROM grading_system ORDER BY display_order ASC");
$grades = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Grading System';
require_once __DIR__ . '/components/header.php';
?>
    <style>
        .container {
            max-width: 1000px;
            margin: 0 auto;
        }
        
        .page-header {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            margin-bottom: 20px;
        }
        
        .page-header h1 {
            color: #1a202c;
            font-size: 1.75rem;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
        }
        
        .page-header p,
        .page-header .description {
            color: #718096;
            font-size: 0.95rem;
            line-height: 1.5;
            margin: 5px 0 0 0;
        }
        
        .page-subnav {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 15px;
        }
        
        .page-subnav a {
            padding: 6px 14px;
            background: #edf2f7;
            color: #4a5568;
            text-decoration: none;
            border-radius: 6px;
            font-size: 0.875rem;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-weight: 500;
        }
        
        .page-subnav a:hover {
            background: #e2e8f0;
            color: #2d3748;
        }
        
        .header-section {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 2rem;
        }
        
        .header-section h1 {
            color: #2d3748;
            margin-bottom: 0.5rem;
        }
        
        .info-box {
            background: #edf2f7;
            padding: 1rem;
            border-radius: 5px;
            margin-top: 1rem;
            color: #4a5568;
        }
        
        .info-box strong {
            color: #2d3748;
        }
        
        .grading-section {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        
        .btn {
            padding: 0.6rem 1.5rem;
            border: none;
            border-radius: 5px;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s;
            font-weight: 600;
        }
        
        .btn-primary {
            background: #667eea;
            color: white;
        }
        
        .btn-danger {
            background: #f56565;
            color: white;
        }
        
        .btn:hover {
            opacity: 0.9;
        }
        
        .grades-table {
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        thead {
            background: #f7fafc;
        }
        
        th {
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: #2d3748;
            border-bottom: 2px solid #e2e8f0;
        }
        
        td {
            padding: 1rem;
            border-bottom: 1px solid #e2e8f0;
        }
        
        tbody tr:hover {
            background: #f7fafc;
        }
        
        .grade-badge {
            display: inline-block;
            padding: 0.4rem 0.8rem;
            background: #667eea;
            color: white;
            border-radius: 5px;
            font-weight: 600;
            font-size: 1.1rem;
        }
        
        .editable-input {
            padding: 0.5rem;
            border: 2px solid #e1e8ed;
            border-radius: 5px;
            font-size: 0.9rem;
            width: 80px;
        }
        
        .editable-input:focus {
            border-color: #667eea;
            outline: none;
        }
        
        .remarks-input {
            width: 150px;
        }
        
        .btn-sm {
            padding: 0.4rem 1rem;
            font-size: 0.875rem;
        }
        
        .example-section {
            background: #f7fafc;
            padding: 1.5rem;
            border-radius: 10px;
            margin-top: 2rem;
        }
        
        .example-section h3 {
            color: #2d3748;
            margin-bottom: 1rem;
        }
        
        .example-scores {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
        }
        
        .score-example {
            background: white;
            padding: 1rem;
            border-radius: 5px;
            text-align: center;
        }
        
        .score-value {
            font-size: 1.5rem;
            font-weight: 600;
            color: #667eea;
        }
        
        .score-grade {
            font-weight: 600;
            color: #2d3748;
            margin-top: 0.5rem;
        }
        
        .score-remark {
            color: #718096;
            font-size: 0.875rem;
            margin-top: 0.25rem;
        }
        
        @media (max-width: 768px) {
            
            .section-header {
                flex-direction: column;
                gap: 1rem;
                align-items: stretch;
            }
            
            .editable-input {
                width: 60px;
            }
            
            table {
                font-size: 0.875rem;
            }
            
            th, td {
                padding: 0.5rem;
            }
        }
    </style>
    <div class="container">
        <div class="page-header">
            <h1>🎯 Grading System</h1>
            <p class="description">Customize the grading scale and remarks for student assessments</p>
            <div class="page-subnav">
                <a href="kg_grading_system.php">🎨 KG Grading</a>
                <a href="dashboard.php">🏠 Dashboard</a>
            </div>
        </div>
            
            <div class="info-box">
                <strong>ℹ️ How it works:</strong> Set the score ranges for each grade. These grades will automatically appear on reports, broadsheets, and result analysis. The system currently uses the Ghana Education Service (GES) standard grading scale.
            </div>
        </div>
        
        <div class="grading-section">
            <div class="section-header">
                <h2 style="color: #2d3748;">Grade Configuration</h2>
                <button class="btn btn-danger" onclick="resetToDefault()">Reset to Default</button>
            </div>
            
            <div class="grades-table">
                <table>
                    <thead>
                        <tr>
                            <th>Grade</th>
                            <th>Minimum Score</th>
                            <th>Maximum Score</th>
                            <th>Remarks</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($grades as $grade): ?>
                            <tr data-grade-id="<?php echo $grade['id']; ?>">
                                <td>
                                    <span class="grade-badge"><?php echo htmlspecialchars($grade['grade']); ?></span>
                                </td>
                                <td>
                                    <input type="number" 
                                           class="editable-input min-score" 
                                           value="<?php echo $grade['min_score']; ?>"
                                           min="0" 
                                           max="100" 
                                           step="0.01">
                                </td>
                                <td>
                                    <input type="number" 
                                           class="editable-input max-score" 
                                           value="<?php echo $grade['max_score']; ?>"
                                           min="0" 
                                           max="100" 
                                           step="0.01">
                                </td>
                                <td>
                                    <input type="text" 
                                           class="editable-input remarks-input remarks" 
                                           value="<?php echo htmlspecialchars($grade['remarks']); ?>">
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-primary" onclick="updateGrade(<?php echo $grade['id']; ?>)">
                                        Save Changes
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="example-section">
                <h3>Live Preview: Sample Scores</h3>
                <p style="color: #718096; margin-bottom: 1rem;">See how different scores would be graded with your current settings</p>
                <div class="example-scores" id="exampleScores">
                    <?php
                    $sampleScores = [85, 77, 72, 68, 62, 57, 52, 45, 35];
                    foreach ($sampleScores as $score) {
                        $currentGrade = '';
                        $currentRemark = '';
                        foreach ($grades as $g) {
                            if ($score >= $g['min_score'] && $score <= $g['max_score']) {
                                $currentGrade = $g['grade'];
                                $currentRemark = $g['remarks'];
                                break;
                            }
                        }
                        ?>
                        <div class="score-example">
                            <div class="score-value"><?php echo $score; ?>%</div>
                            <div class="score-grade">Grade: <?php echo $currentGrade; ?></div>
                            <div class="score-remark"><?php echo $currentRemark; ?></div>
                        </div>
                    <?php } ?>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        function updateGrade(gradeId) {
            const row = document.querySelector(`tr[data-grade-id="${gradeId}"]`);
            const minScore = row.querySelector('.min-score').value;
            const maxScore = row.querySelector('.max-score').value;
            const remarks = row.querySelector('.remarks').value;
            
            // Validation
            if (parseFloat(minScore) >= parseFloat(maxScore)) {
                alert('Minimum score must be less than maximum score');
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'update_grade');
            formData.append('grade_id', gradeId);
            formData.append('min_score', minScore);
            formData.append('max_score', maxScore);
            formData.append('remarks', remarks);
            
            fetch('grading_system.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Grade updated successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while updating');
            });
        }
        
        function resetToDefault() {
            if (!confirm('Reset to default Ghana Education Service grading system? This will override all your custom settings.')) {
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'reset_default');
            
            fetch('grading_system.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Grading system reset to default!');
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred');
            });
        }
        
        // Auto-save on Enter key
        document.querySelectorAll('.editable-input').forEach(input => {
            input.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    const row = this.closest('tr');
                    const gradeId = row.dataset.gradeId;
                    updateGrade(gradeId);
                }
            });
        });
    </script>
<?php require_once __DIR__ . '/components/footer.php'; ?>
