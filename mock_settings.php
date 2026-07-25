<?php
session_start();
require_once 'config/config.php';
require_once 'config/database.php';

// Check if user is logged in and is admin (check user_type first, then role)
$is_admin = ($_SESSION['user_type'] ?? null) === 'admin' || ($_SESSION['role'] ?? null) === 'admin';
if (!isset($_SESSION['user_id']) || !$is_admin) {
    header('Location: login.php');
    exit();
}

$db = Database::getInstance()->getConnection();

// Get school context from the logged-in session
$school_id = $_SESSION['school_id'] ?? null;
$school_type_id = $_SESSION['school_type_id'] ?? null;

// First try to get school_id from session if available
if (isset($_SESSION['school_id'])) {
    $school_id = $_SESSION['school_id'];
    // Verify it exists
    $verify_stmt = $db->prepare("SELECT school_type_id FROM school_info WHERE id = ?");
    $verify_stmt->execute([$school_id]);
    $verify_result = $verify_stmt->fetch(PDO::FETCH_ASSOC);
    if ($verify_result) {
        $school_type_id = $verify_result['school_type_id'];
    } else {
        $school_id = null;
    }
}

if (!$school_id) {
    $_SESSION['error'] = 'School information not found. Please ensure school data is properly configured.';
    header('Location: dashboard.php');
    exit();
}

// Only JHS and Basic schools can use mock exams
if (!in_array($school_type_id, [2, 3])) {
    $_SESSION['error'] = 'Mock examinations are only available for JHS and Basic schools.';
    header('Location: dashboard.php');
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $is_enabled = isset($_POST['is_enabled']) ? 1 : 0;
    $academic_year = $_POST['academic_year'];
    $term = $_POST['term'];
    $start_date = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
    $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
    
    try {
        // Check if settings exist
        $check_query = "SELECT id FROM mock_exam_settings WHERE school_id = ? AND academic_year = ? AND term = ?";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->execute([$school_id, $academic_year, $term]);
        
        if ($check_stmt->fetch()) {
            // Update existing settings
            $update_query = "UPDATE mock_exam_settings 
                           SET is_enabled = ?, start_date = ?, end_date = ?, updated_at = NOW()
                           WHERE school_id = ? AND academic_year = ? AND term = ?";
            $update_stmt = $db->prepare($update_query);
            $update_stmt->execute([$is_enabled, $start_date, $end_date, $school_id, $academic_year, $term]);
        } else {
            // Insert new settings
            $insert_query = "INSERT INTO mock_exam_settings 
                           (school_id, school_type_id, is_enabled, academic_year, term, start_date, end_date) 
                           VALUES (?, ?, ?, ?, ?, ?, ?)";
            $insert_stmt = $db->prepare($insert_query);
            $insert_stmt->execute([$school_id, $school_type_id, $is_enabled, $academic_year, $term, $start_date, $end_date]);
        }
        
        $_SESSION['success'] = 'Mock examination settings updated successfully.';
        header('Location: mock_settings.php');
        exit();
        
    } catch (PDOException $e) {
        $_SESSION['error'] = 'Failed to update settings: ' . $e->getMessage();
    }
}

// Get current settings
$current_year = date('Y');
$next_year = $current_year + 1;
$default_academic_year = $current_year . '/' . $next_year;

$query = "SELECT * FROM mock_exam_settings 
          WHERE school_id = ? 
          ORDER BY academic_year DESC, term DESC 
          LIMIT 1";
$stmt = $db->prepare($query);
$stmt->execute([$school_id]);
$settings = $stmt->fetch(PDO::FETCH_ASSOC);

// If no settings, use defaults
if (!$settings) {
    $settings = [
        'is_enabled' => 0,
        'academic_year' => $default_academic_year,
        'term' => 'Term 1',
        'start_date' => null,
        'end_date' => null
    ];
}

// Get Basic 9 classes
$classes_query = "SELECT id, class_name FROM classes 
                  WHERE school_id = ? AND (
                      class_name LIKE '%Basic Nine%' OR 
                      class_name LIKE '%Basic 9%' OR 
                      class_name LIKE '%JHS 3%' OR
                      class_name LIKE '%JHS3%'
                  )
                  ORDER BY class_name";
$classes_stmt = $db->prepare($classes_query);
$classes_stmt->execute([$school_id]);
$basic9_classes = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get students without candidate index numbers
$missing_index_query = "SELECT COUNT(*) as count FROM students s
                       INNER JOIN classes c ON s.class_id = c.id
                       WHERE c.school_id = ?
                       AND (
                           c.class_name LIKE '%Basic Nine%' OR 
                           c.class_name LIKE '%Basic 9%' OR 
                           c.class_name LIKE '%JHS 3%' OR
                           c.class_name LIKE '%JHS3%'
                       )
                       AND (s.candidate_index_number IS NULL OR s.candidate_index_number = '')";
$missing_stmt = $db->prepare($missing_index_query);
$missing_stmt->execute([$school_id]);
$missing_count = $missing_stmt->fetch(PDO::FETCH_ASSOC)['count'];

include 'components/header.php';
?>

<style>
/* ── Design system (matches assign_subject_masters / assign_form_masters) ── */
.ms-wrap { max-width: 860px; margin: 0 auto; padding: 1.5rem 1rem 3rem; }

/* Section cards */
.section-card {
    background: #fff;
    border-radius: 10px;
    box-shadow: 0 1px 4px rgba(0,0,0,.05);
    margin-bottom: 1.75rem;
    overflow: hidden;
}
.section-header {
    display: flex;
    align-items: flex-start;
    gap: .9rem;
    padding: 1.1rem 1.4rem;
    border-bottom: 1px solid #f1f5f9;
}
.section-icon {
    width: 38px; height: 38px; border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.1rem; flex-shrink: 0;
}
.icon-orange  { background: #fff7ed; }
.icon-blue    { background: #eff6ff; }
.icon-green   { background: #f0fdf4; }
.icon-purple  { background: #faf5ff; }
.section-title { font-size: 1rem; font-weight: 700; color: #1e293b; margin: 0 0 .15rem; }
.section-sub   { font-size: .83rem; color: #64748b; margin: 0; }
.section-body  { padding: 1.4rem; }

/* Form elements */
.form-label {
    display: block;
    font-size: .82rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .4px;
    color: #374151;
    margin-bottom: .4rem;
}
.form-hint { font-size: .8rem; color: #9ca3af; margin-top: .3rem; }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
@media (max-width: 600px) { .form-grid { grid-template-columns: 1fr; } }

/* Toggle switch */
.toggle-row {
    display: flex; align-items: center; gap: 1rem;
    background: #f8fafc; border: 1px solid #e2e8f0;
    border-radius: 8px; padding: .9rem 1rem;
    margin-bottom: 1.2rem;
}
.toggle-switch { position: relative; width: 46px; height: 24px; flex-shrink: 0; }
.toggle-switch input { opacity: 0; width: 0; height: 0; }
.toggle-slider {
    position: absolute; inset: 0; cursor: pointer;
    background: #cbd5e0; border-radius: 24px; transition: .2s;
}
.toggle-slider:before {
    content: ''; position: absolute;
    width: 18px; height: 18px; left: 3px; bottom: 3px;
    background: white; border-radius: 50%; transition: .2s;
}
.toggle-switch input:checked + .toggle-slider { background: #667eea; }
.toggle-switch input:checked + .toggle-slider:before { transform: translateX(22px); }
.toggle-label { font-weight: 600; color: #1e293b; font-size: .95rem; }
.toggle-desc { font-size: .8rem; color: #64748b; margin: 0; }

/* Info banner */
.info-banner {
    background: #eff6ff; border: 1px solid #bfdbfe;
    border-radius: 8px; padding: .85rem 1rem;
    font-size: .88rem; color: #1e40af; margin-bottom: 1.25rem;
    display: flex; gap: .6rem; align-items: flex-start;
}
.warn-banner {
    background: #fffbeb; border: 1px solid #fde68a;
    border-radius: 8px; padding: .85rem 1rem;
    font-size: .88rem; color: #92400e; margin-bottom: 1.25rem;
    display: flex; gap: .6rem; align-items: flex-start;
}

/* Tables */
.table-wrap { border-radius: 8px; border: 1px solid #e2e8f0; overflow: hidden; }
.table-wrap table { width: 100%; border-collapse: collapse; font-size: .88rem; }
.table-wrap th {
    background: #f8fafc; padding: .6rem .9rem;
    text-align: left; font-size: .75rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: .5px; color: #64748b;
    border-bottom: 1px solid #e2e8f0;
}
.table-wrap td { padding: .65rem .9rem; border-bottom: 1px solid #f1f5f9; color: #374151; }
.table-wrap tr:last-child td { border-bottom: none; }
.table-wrap tr:hover td { background: #f8fafc; }

/* Grade badges */
.grade-num {
    display: inline-flex; align-items: center; justify-content: center;
    width: 28px; height: 28px; border-radius: 6px;
    font-weight: 700; font-size: .9rem;
}
.g-green  { background: #dcfce7; color: #166534; }
.g-teal   { background: #ccfbf1; color: #065f46; }
.g-blue   { background: #dbeafe; color: #1e40af; }
.g-orange { background: #ffedd5; color: #9a3412; }
.g-red    { background: #fee2e2; color: #991b1b; }

.status-pass { display:inline-block; padding:.15rem .55rem; border-radius:20px; font-size:.75rem; font-weight:600; background:#dcfce7; color:#166534; }
.status-fail { display:inline-block; padding:.15rem .55rem; border-radius:20px; font-size:.75rem; font-weight:600; background:#fee2e2; color:#991b1b; }

/* Aggregate badges */
.agg-badge {
    display:inline-block; padding:.2rem .7rem; border-radius:20px;
    font-size:.78rem; font-weight:700;
}

/* Index progress */
.idx-bar-bg { background:#e2e8f0; border-radius:4px; height:7px; width:100px; overflow:hidden; }
.idx-bar    { background:#667eea; border-radius:4px; height:100%; }

/* Feature list */
.feature-grid { display:grid; grid-template-columns:1fr 1fr; gap:.75rem; }
@media(max-width:600px){ .feature-grid { grid-template-columns:1fr; } }
.feature-item {
    display:flex; gap:.7rem; align-items:flex-start;
    background:#f8fafc; border-radius:8px; padding:.8rem 1rem;
}
.feature-dot {
    width:8px; height:8px; border-radius:50%; background:#667eea;
    margin-top:.35rem; flex-shrink:0;
}
.feature-title { font-size:.88rem; font-weight:700; color:#1e293b; margin:0 0 .15rem; }
.feature-desc  { font-size:.8rem; color:#64748b; margin:0; }

/* Buttons */
.btn-save {
    background: linear-gradient(135deg,#667eea,#764ba2);
    color:#fff; border:none; padding:.65rem 1.75rem;
    border-radius:7px; font-size:.92rem; font-weight:600;
    cursor:pointer; transition:opacity .2s;
}
.btn-save:hover { opacity:.88; }
.btn-cancel {
    background:#f1f5f9; color:#64748b; border:1px solid #e2e8f0;
    padding:.65rem 1.25rem; border-radius:7px; font-size:.92rem;
    font-weight:600; text-decoration:none; display:inline-block;
    transition:background .15s;
}
.btn-cancel:hover { background:#e2e8f0; color:#374151; }
.btn-manage {
    background:#f0f4ff; color:#667eea; border:1px solid #c7d2fe;
    padding:.3rem .8rem; border-radius:6px; font-size:.8rem;
    font-weight:600; text-decoration:none; transition:background .15s;
    white-space:nowrap;
}
.btn-manage:hover { background:#e0e7ff; }

/* Page heading */
.page-heading { margin-bottom:1.5rem; }
.page-heading h1 { font-size:1.5rem; font-weight:800; color:#1e293b; margin:0 0 .25rem; }
.page-heading p  { color:#64748b; margin:0; font-size:.92rem; }

@media (max-width: 768px) {
    .ms-wrap { padding: 0 0.5rem; }
    .page-heading h1 { font-size: 1.2rem; }
    .form-grid { grid-template-columns: 1fr !important; }
    .feature-grid { grid-template-columns: 1fr !important; }
    .table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .table-wrap table { min-width: 400px; }
    input, select, textarea { font-size: 16px !important; min-height: 44px; }
    .btn-save, .btn-cancel { width: 100%; display: block; text-align: center; padding: 0.85rem; }
    .toggle-row { flex-wrap: wrap; gap: 0.5rem; }
    /* Table buttons */
    .btn-manage { white-space: nowrap; }
}
</style>

<div class="ms-wrap">

    <div class="page-heading">
        <h1>⚙️ Mock Examination Settings</h1>
        <p>Configure BECE Mock Examination for Basic 9 students</p>
    </div>

    <?php if (isset($_SESSION['success'])): ?>
        <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:.85rem 1rem;color:#166534;font-size:.9rem;margin-bottom:1.2rem;display:flex;gap:.6rem;align-items:center;">
            ✅ <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
        <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:.85rem 1rem;color:#991b1b;font-size:.9rem;margin-bottom:1.2rem;display:flex;gap:.6rem;align-items:center;">
            ❌ <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
        </div>
    <?php endif; ?>

    <?php if ($missing_count > 0): ?>
        <div class="warn-banner">
            <span>⚠️</span>
            <div>
                <strong><?php echo $missing_count; ?> Basic 9 student(s)</strong> do not have candidate index numbers assigned.
                <?php if (!empty($basic9_classes)): ?>
                <a href="dashboard.php?filter_class=<?php echo $basic9_classes[0]['id']; ?>" class="btn-manage" style="margin-left:.5rem;">Assign Index Numbers</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- ── Section 1: Configuration ───────────────────────────────────── -->
    <div class="section-card">
        <div class="section-header">
            <div class="section-icon icon-orange">🛠️</div>
            <div>
                <p class="section-title">Mock Exam Configuration</p>
                <p class="section-sub">Enable mock mode and set exam period for Basic 9</p>
            </div>
        </div>
        <div class="section-body">
            <form method="POST" action="">
                <div class="toggle-row">
                    <label class="toggle-switch">
                        <input type="checkbox" name="is_enabled" value="1" <?php echo $settings['is_enabled'] ? 'checked' : ''; ?>>
                        <span class="toggle-slider"></span>
                    </label>
                    <div>
                        <div class="toggle-label">Enable Mock Examination Mode</div>
                        <p class="toggle-desc">When on, score entry for Basic 9 uses candidate index numbers and 100-point scoring</p>
                    </div>
                </div>

                <div class="form-grid" style="margin-bottom:1rem;">
                    <div>
                        <label class="form-label" for="academic_year">Academic Year</label>
                        <input type="text" class="form-control" id="academic_year" name="academic_year"
                               value="<?php echo htmlspecialchars($settings['academic_year']); ?>"
                               placeholder="e.g. 2025/2026" required>
                    </div>
                    <div>
                        <label class="form-label" for="term">Term</label>
                        <select class="form-control" id="term" name="term" required>
                            <option value="Term 1" <?php echo $settings['term'] === 'Term 1' ? 'selected' : ''; ?>>Term 1</option>
                            <option value="Term 2" <?php echo $settings['term'] === 'Term 2' ? 'selected' : ''; ?>>Term 2</option>
                            <option value="Term 3" <?php echo $settings['term'] === 'Term 3' ? 'selected' : ''; ?>>Term 3</option>
                        </select>
                    </div>
                </div>

                <div class="form-grid" style="margin-bottom:1.5rem;">
                    <div>
                        <label class="form-label" for="start_date">Start Date <span style="font-weight:400;text-transform:none;letter-spacing:0;">(Optional)</span></label>
                        <input type="date" class="form-control" id="start_date" name="start_date"
                               value="<?php echo htmlspecialchars($settings['start_date'] ?? ''); ?>">
                    </div>
                    <div>
                        <label class="form-label" for="end_date">End Date <span style="font-weight:400;text-transform:none;letter-spacing:0;">(Optional)</span></label>
                        <input type="date" class="form-control" id="end_date" name="end_date"
                               value="<?php echo htmlspecialchars($settings['end_date'] ?? ''); ?>">
                    </div>
                </div>

                <div style="display:flex;gap:.75rem;align-items:center;">
                    <button type="submit" class="btn-save">Save Settings</button>
                    <a href="dashboard.php" class="btn-cancel">Cancel</a>
                </div>
            </form>
        </div>
    </div>

    <!-- ── Section 2: BECE Grading ────────────────────────────────────── -->
    <div class="section-card">
        <div class="section-header">
            <div class="section-icon icon-blue">🎯</div>
            <div>
                <p class="section-title">Mock Exam Grading System (BECE)</p>
                <p class="section-sub">Configure grading specifically for mock examinations</p>
            </div>
        </div>
        <div class="section-body">
            <div class="info-banner">
                <span>ℹ️</span>
                <div>Ghana BECE uses a <strong>1–9 grading scale</strong> (1 = highest, 9 = lowest). <strong>Aggregate</strong> = sum of best 4 core + 2 best elective grades. Range: 6–48, lower is better.</div>
            </div>

            <p style="font-size:.82rem;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#64748b;margin:0 0 .6rem;">Grade Scale (1–9)</p>
            <div class="table-wrap" style="margin-bottom:1.5rem;">
                <table>
                    <thead>
                        <tr>
                            <th>Grade</th>
                            <th>Score Range</th>
                            <th>Description</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td><span class="grade-num g-green">1</span></td><td>90 – 100%</td><td>Highest</td><td><span class="status-pass">Pass</span></td></tr>
                        <tr><td><span class="grade-num g-green">2</span></td><td>80 – 89%</td><td>Higher</td><td><span class="status-pass">Pass</span></td></tr>
                        <tr><td><span class="grade-num g-teal">3</span></td><td>70 – 79%</td><td>High</td><td><span class="status-pass">Pass</span></td></tr>
                        <tr><td><span class="grade-num g-blue">4</span></td><td>60 – 69%</td><td>High Average</td><td><span class="status-pass">Pass</span></td></tr>
                        <tr><td><span class="grade-num g-blue">5</span></td><td>55 – 59%</td><td>Average</td><td><span class="status-pass">Pass</span></td></tr>
                        <tr><td><span class="grade-num g-blue">6</span></td><td>50 – 54%</td><td>Low Average</td><td><span class="status-pass">Pass</span></td></tr>
                        <tr><td><span class="grade-num g-orange">7</span></td><td>40 – 49%</td><td>Low</td><td><span class="status-pass">Pass</span></td></tr>
                        <tr><td><span class="grade-num g-orange">8</span></td><td>35 – 39%</td><td>Lower</td><td><span class="status-pass">Pass</span></td></tr>
                        <tr><td><span class="grade-num g-red">9</span></td><td>0 – 34%</td><td>Lowest</td><td><span class="status-fail">Fail</span></td></tr>
                    </tbody>
                </table>
            </div>

            <p style="font-size:.82rem;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#64748b;margin:0 0 .6rem;">Aggregate Categories</p>
            <div class="table-wrap" style="margin-bottom:1.25rem;">
                <table>
                    <thead>
                        <tr><th>Aggregate Range</th><th>Category</th><th>Description</th></tr>
                    </thead>
                    <tbody>
                        <tr><td><span class="agg-badge" style="background:#dcfce7;color:#166534;">6 – 12</span></td><td>Highest</td><td>Outstanding performance across all subjects</td></tr>
                        <tr><td><span class="agg-badge" style="background:#dbeafe;color:#1e40af;">13 – 24</span></td><td>Higher</td><td>Very good performance in most subjects</td></tr>
                        <tr><td><span class="agg-badge" style="background:#ffedd5;color:#9a3412;">25 – 36</span></td><td>High</td><td>Acceptable performance, meets requirements</td></tr>
                        <tr><td><span class="agg-badge" style="background:#fee2e2;color:#991b1b;">37 – 48</span></td><td>Low</td><td>Below passing standard, needs improvement</td></tr>
                    </tbody>
                </table>
            </div>

            <div class="warn-banner">
                <span>⚠️</span>
                <div>This grading system applies <strong>only to mock examinations</strong>. Regular term assessments use your school's standard grading system.</div>
            </div>
        </div>
    </div>

    <?php if (count($basic9_classes) > 0): ?>
    <!-- ── Section 3: Basic 9 Classes ─────────────────────────────────── -->
    <div class="section-card">
        <div class="section-header">
            <div class="section-icon icon-green">🏫</div>
            <div>
                <p class="section-title">Basic 9 Classes</p>
                <p class="section-sub">Index number coverage for mock-eligible classes</p>
            </div>
        </div>
        <div class="section-body" style="padding:0;">
            <div class="table-wrap" style="border-radius:0;border:none;">
                <table>
                    <thead>
                        <tr>
                            <th>Class</th>
                            <th>Students</th>
                            <th>Index Coverage</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($basic9_classes as $class):
                            $count_query = "SELECT COUNT(*) as total,
                                SUM(CASE WHEN candidate_index_number IS NOT NULL AND candidate_index_number != '' THEN 1 ELSE 0 END) as with_index
                                FROM students WHERE class_id = ?";
                            $count_stmt = $db->prepare($count_query);
                            $count_stmt->execute([$class['id']]);
                            $counts = $count_stmt->fetch(PDO::FETCH_ASSOC);
                            $total = (int)$counts['total'];
                            $withIdx = (int)$counts['with_index'];
                            $pct = $total > 0 ? round($withIdx / $total * 100) : 0;
                        ?>
                        <tr>
                            <td style="font-weight:600;"><?php echo htmlspecialchars($class['class_name']); ?></td>
                            <td><?php echo $total; ?></td>
                            <td>
                                <div style="display:flex;align-items:center;gap:.6rem;">
                                    <div class="idx-bar-bg"><div class="idx-bar" style="width:<?php echo $pct; ?>%"></div></div>
                                    <span style="font-size:.8rem;color:#64748b;"><?php echo $withIdx; ?>/<?php echo $total; ?></span>
                                </div>
                            </td>
                            <td><a href="dashboard.php?filter_class=<?php echo $class['id']; ?>" class="btn-manage">Manage Students</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Section 4: Features ────────────────────────────────────────── -->
    <div class="section-card">
        <div class="section-header">
            <div class="section-icon icon-purple">✨</div>
            <div>
                <p class="section-title">Mock Examination Features</p>
                <p class="section-sub">What changes when mock mode is enabled</p>
            </div>
        </div>
        <div class="section-body">
            <div class="feature-grid">
                <div class="feature-item">
                    <div class="feature-dot"></div>
                    <div>
                        <p class="feature-title">Candidate Index Numbers</p>
                        <p class="feature-desc">Students are identified by index numbers instead of names</p>
                    </div>
                </div>
                <div class="feature-item">
                    <div class="feature-dot"></div>
                    <div>
                        <p class="feature-title">100-Point Scoring</p>
                        <p class="feature-desc">All subjects scored out of 100 — no class scores or homework</p>
                    </div>
                </div>
                <div class="feature-item">
                    <div class="feature-dot"></div>
                    <div>
                        <p class="feature-title">Automatic Grading</p>
                        <p class="feature-desc">Grades assigned based on your school's grading system</p>
                    </div>
                </div>
                <div class="feature-item">
                    <div class="feature-dot"></div>
                    <div>
                        <p class="feature-title">Comprehensive Analysis</p>
                        <p class="feature-desc">Subject breakdown, grade distribution, and pass rates</p>
                    </div>
                </div>
                <div class="feature-item">
                    <div class="feature-dot"></div>
                    <div>
                        <p class="feature-title">Printable Reports</p>
                        <p class="feature-desc">Candidate reports and broadsheets using index numbers</p>
                    </div>
                </div>
                <div class="feature-item">
                    <div class="feature-dot"></div>
                    <div>
                        <p class="feature-title">Basic 9 Only</p>
                        <p class="feature-desc">Other classes continue to use regular scoring unaffected</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

<?php include 'components/footer.php'; ?>

