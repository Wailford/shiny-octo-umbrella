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
                
                $stmt = $db->prepare("UPDATE kg_grading_system SET min_score = ?, max_score = ?, remarks = ? WHERE id = ?");
                $stmt->execute([$minScore, $maxScore, $remarks, $gradeId]);
                
                echo json_encode(['success' => true, 'message' => 'Grade updated successfully']);
                break;
                
            case 'reset_default':
                // Reset to default KG grading system
                $db->exec("DELETE FROM kg_grading_system");
                
                $stmt = $db->prepare("INSERT INTO kg_grading_system (grade, min_score, max_score, remarks, display_order) VALUES (?, ?, ?, ?, ?)");
                
                $defaultGrades = [
                    ['EE', 80.00, 100.00, 'Exceeds Expectations', 1],
                    ['ME', 70.00, 79.99, 'Meets Expectations', 2],
                    ['AE', 60.00, 69.99, 'Approaching Expectations', 3],
                    ['BE', 0.00, 59.99, 'Below Expectations', 4]
                ];
                
                foreach ($defaultGrades as $grade) {
                    $stmt->execute($grade);
                }
                
                echo json_encode(['success' => true, 'message' => 'KG grading system reset to default']);
                break;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Get KG grading system
$stmt = $db->query("SELECT * FROM kg_grading_system ORDER BY display_order ASC");
$grades = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'KG Grading System';
require_once __DIR__ . '/components/header.php';
?>
<style>
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
        }
        
        .page-header p {
            color: #718096;
            font-size: 0.95rem;
            line-height: 1.5;
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
        
        .card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            margin-bottom: 20px;
        }
        
        .card-title {
            color: #1a202c;
            font-size: 1.3rem;
            margin-bottom: 15px;
            padding-bottom: 12px;
            border-bottom: 2px solid #e2e8f0;
            font-weight: 600;
        }
        
        .info-box {
            background: linear-gradient(135deg, #f7fafc 0%, #edf2f7 100%);
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #667eea;
        }
        
        .info-box p {
            color: #4a5568;
            line-height: 1.6;
            margin-bottom: 8px;
            font-size: 0.9rem;
        }
        
        .info-box p:last-child {
            margin-bottom: 0;
        }
        
        .info-box strong {
            color: #2d3748;
        }
        
        /* Desktop table view */
        .grade-table-container {
            overflow-x: auto;
            margin-top: 20px;
        }
        
        .grade-table {
            width: 100%;
            border-collapse: collapse;
            display: table;
        }
        
        .grade-table thead {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        
        .grade-table th {
            color: white;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            font-size: 0.9rem;
            white-space: nowrap;
        }
        
        .grade-table td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: middle;
        }
        
        .grade-table tbody tr:hover {
            background: #f7fafc;
        }
        
        .grade-table tbody tr:last-child td {
            border-bottom: none;
        }
        
        /* Mobile card view */
        .grade-cards {
            display: none;
        }
        
        .grade-card {
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            padding: 16px;
            margin-bottom: 15px;
            transition: all 0.2s;
        }
        
        .grade-card:hover {
            border-color: #667eea;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.15);
        }
        
        .grade-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 12px;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .grade-badge {
            display: inline-block;
            padding: 6px 16px;
            border-radius: 20px;
            font-weight: 700;
            font-size: 1.1rem;
            letter-spacing: 0.5px;
        }
        
        .grade-ee {
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
            color: white;
            box-shadow: 0 2px 8px rgba(72, 187, 120, 0.3);
        }
        
        .grade-me {
            background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%);
            color: white;
            box-shadow: 0 2px 8px rgba(66, 153, 225, 0.3);
        }
        
        .grade-ae {
            background: linear-gradient(135deg, #ed8936 0%, #dd6b20 100%);
            color: white;
            box-shadow: 0 2px 8px rgba(237, 137, 54, 0.3);
        }
        
        .grade-be {
            background: linear-gradient(135deg, #f56565 0%, #e53e3e 100%);
            color: white;
            box-shadow: 0 2px 8px rgba(245, 101, 101, 0.3);
        }
        
        .form-group {
            margin-bottom: 12px;
        }
        
        .form-label {
            display: block;
            color: #4a5568;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        input[type="number"],
        input[type="text"] {
            padding: 10px 12px;
            border: 2px solid #e2e8f0;
            border-radius: 6px;
            font-size: 0.95rem;
            width: 100%;
            transition: all 0.2s;
            background: white;
        }
        
        input[type="number"]:focus,
        input[type="text"]:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            box-shadow: 0 2px 8px rgba(102, 126, 234, 0.3);
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }
        
        .btn-primary:active {
            transform: translateY(0);
        }
        
        .btn-reset {
            background: linear-gradient(135deg, #ed8936 0%, #dd6b20 100%);
            color: white;
            margin-top: 15px;
            width: 100%;
            padding: 12px;
            font-size: 1rem;
            box-shadow: 0 2px 8px rgba(237, 137, 54, 0.3);
        }
        
        .btn-reset:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(237, 137, 54, 0.4);
        }
        
        .message {
            padding: 14px 18px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: none;
            font-size: 0.95rem;
            font-weight: 500;
        }
        
        .message.success {
            background: #c6f6d5;
            color: #22543d;
            border-left: 4px solid #48bb78;
        }
        
        .message.error {
            background: #fed7d7;
            color: #742a2a;
            border-left: 4px solid #f56565;
        }
        
        .subjects-card {
            background: linear-gradient(135deg, #edf2f7 0%, #e2e8f0 100%);
            padding: 20px;
            border-radius: 10px;
        }
        
        .subjects-card h3 {
            color: #1a202c;
            margin-bottom: 15px;
            font-size: 1.2rem;
        }
        
        .subjects-card p {
            color: #718096;
            margin-bottom: 15px;
            font-size: 0.9rem;
        }
        
        .subject-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 12px;
        }
        
        .subject-item {
            background: white;
            padding: 14px;
            border-radius: 8px;
            border-left: 3px solid #667eea;
            font-weight: 600;
            color: #2d3748;
            font-size: 0.9rem;
            transition: all 0.2s;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        .subject-item:hover {
            transform: translateX(3px);
            box-shadow: 0 3px 8px rgba(0,0,0,0.1);
        }
        
        /* Mobile responsive */
        @media (max-width: 768px) {
            
            .page-header h1 {
                font-size: 1.4rem;
            }
            
            .page-header p {
                font-size: 0.85rem;
            }
            
            .card {
                padding: 16px;
            }
            
            .grade-table-container {
                display: none;
            }
            
            .grade-cards {
                display: block;
            }
            
            .subject-grid {
                grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
                gap: 10px;
            }
            
            .page-subnav {
                gap: 8px;
            }
            
            .page-subnav a {
                font-size: 0.85rem;
                padding: 7px 12px;
            }
        }
        
        @media (max-width: 480px) {
            .page-header h1 {
                font-size: 1.25rem;
                flex-direction: column;
                align-items: flex-start;
            }
            
            .subject-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
    
    <div class="container">
        <div class="page-header">
            <h1>🎨 KG Grading System</h1>
            <p>Configure grading specifically for Kindergarten (KG One & KG Two). Mobile-friendly interface for easy configuration.</p>
            <div class="page-subnav">
                <a href="grading_system.php">📊 Primary/JHS Grading</a>
                <a href="dashboard.php">🏠 Dashboard</a>
            </div>
        </div>
        
        <div id="message" class="message"></div>
        
        <div class="card">
            <h2 class="card-title">Grade Configuration</h2>
            
            <div class="info-box">
                <p><strong>ℹ️ About KG Grading</strong></p>
                <p>Early childhood assessment uses developmentally appropriate descriptors instead of numeric grades.</p>
                <p><strong>Note:</strong> This applies ONLY to KG One and KG Two. Primary/JHS use the standard system.</p>
            </div>
            
            <!-- Desktop Table View -->
            <div class="grade-table-container">
                <table class="grade-table">
                    <thead>
                        <tr>
                            <th>Grade</th>
                            <th>Min Score (%)</th>
                            <th>Max Score (%)</th>
                            <th>Remarks</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($grades as $grade): ?>
                        <tr>
                            <td>
                                <span class="grade-badge grade-<?php echo strtolower($grade['grade']); ?>">
                                    <?php echo htmlspecialchars($grade['grade']); ?>
                                </span>
                            </td>
                            <td>
                                <input type="number" 
                                       id="min_<?php echo $grade['id']; ?>" 
                                       value="<?php echo $grade['min_score']; ?>" 
                                       min="0" 
                                       max="100" 
                                       step="0.01">
                            </td>
                            <td>
                                <input type="number" 
                                       id="max_<?php echo $grade['id']; ?>" 
                                       value="<?php echo $grade['max_score']; ?>" 
                                       min="0" 
                                       max="100" 
                                       step="0.01">
                            </td>
                            <td>
                                <input type="text" 
                                       id="remarks_<?php echo $grade['id']; ?>" 
                                       value="<?php echo htmlspecialchars($grade['remarks']); ?>">
                            </td>
                            <td>
                                <button class="btn btn-primary" onclick="updateGrade(<?php echo $grade['id']; ?>)">
                                    💾 Save
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Mobile Card View -->
            <div class="grade-cards">
                <?php foreach ($grades as $grade): ?>
                <div class="grade-card">
                    <div class="grade-card-header">
                        <span class="grade-badge grade-<?php echo strtolower($grade['grade']); ?>">
                            <?php echo htmlspecialchars($grade['grade']); ?>
                        </span>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Min Score (%)</label>
                        <input type="number" 
                               id="min_mobile_<?php echo $grade['id']; ?>" 
                               value="<?php echo $grade['min_score']; ?>" 
                               min="0" 
                               max="100" 
                               step="0.01">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Max Score (%)</label>
                        <input type="number" 
                               id="max_mobile_<?php echo $grade['id']; ?>" 
                               value="<?php echo $grade['max_score']; ?>" 
                               min="0" 
                               max="100" 
                               step="0.01">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Remarks</label>
                        <input type="text" 
                               id="remarks_mobile_<?php echo $grade['id']; ?>" 
                               value="<?php echo htmlspecialchars($grade['remarks']); ?>">
                    </div>
                    
                    <button class="btn btn-primary" style="width: 100%;" onclick="updateGradeMobile(<?php echo $grade['id']; ?>)">
                        💾 Save Changes
                    </button>
                </div>
                <?php endforeach; ?>
            </div>
            
            <button class="btn btn-reset" onclick="resetToDefault()">
                🔄 Reset to Default KG Grading
            </button>
        </div>
        
        <div class="card subjects-card">
            <h3>📚 KG Subjects</h3>
            <p>These subjects are assessed using the KG grading system:</p>
            <div class="subject-grid">
                <div class="subject-item">📖 Literacy</div>
                <div class="subject-item">🔢 Numeracy</div>
                <div class="subject-item">🎨 Creative Arts</div>
                <div class="subject-item">🌍 Our World Our People</div>
            </div>
        </div>
    </div>
    
    <script>
        function showMessage(message, type) {
            const messageDiv = document.getElementById('message');
            messageDiv.textContent = message;
            messageDiv.className = `message ${type}`;
            messageDiv.style.display = 'block';
            
            setTimeout(() => {
                messageDiv.style.display = 'none';
            }, 5000);
            
            // Scroll to message
            messageDiv.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
        
        function updateGrade(gradeId) {
            const minScore = document.getElementById(`min_${gradeId}`).value;
            const maxScore = document.getElementById(`max_${gradeId}`).value;
            const remarks = document.getElementById(`remarks_${gradeId}`).value;
            
            fetch('kg_grading_system.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=update_grade&grade_id=${gradeId}&min_score=${minScore}&max_score=${maxScore}&remarks=${encodeURIComponent(remarks)}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showMessage('✓ ' + data.message, 'success');
                } else {
                    showMessage('✗ ' + data.message, 'error');
                }
            })
            .catch(error => {
                showMessage('✗ An error occurred while updating', 'error');
            });
        }
        
        function updateGradeMobile(gradeId) {
            const minScore = document.getElementById(`min_mobile_${gradeId}`).value;
            const maxScore = document.getElementById(`max_mobile_${gradeId}`).value;
            const remarks = document.getElementById(`remarks_mobile_${gradeId}`).value;
            
            fetch('kg_grading_system.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=update_grade&grade_id=${gradeId}&min_score=${minScore}&max_score=${maxScore}&remarks=${encodeURIComponent(remarks)}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showMessage('✓ ' + data.message, 'success');
                } else {
                    showMessage('✗ ' + data.message, 'error');
                }
            })
            .catch(error => {
                showMessage('✗ An error occurred while updating', 'error');
            });
        }
        
        function resetToDefault() {
            if (!confirm('Reset to default KG grading system? This will override all your custom settings.')) {
                return;
            }
            
            fetch('kg_grading_system.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=reset_default'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showMessage('✓ ' + data.message, 'success');
                    setTimeout(() => {
                        location.reload();
                    }, 1500);
                } else {
                    showMessage('✗ ' + data.message, 'error');
                }
            })
            .catch(error => {
                showMessage('✗ An error occurred while resetting', 'error');
            });
        }
    </script>

<?php require_once __DIR__ . '/components/footer.php'; ?>
