<?php
ob_start();
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/controllers/AuthController.php';
require_once __DIR__ . '/controllers/ClassController.php';
require_once __DIR__ . '/controllers/FeeController.php';

$auth = new Auth();
$auth->requireLogin();
$isAdmin = $auth->isAdmin();

$db         = Database::getInstance()->getConnection();
$schoolId   = (int)($_SESSION['school_id'] ?? 0);
$userId     = (int)($_SESSION['user_id']   ?? 0);

// Load school info (academic year, current term, fees_enabled flag)
$schoolInfo = [];
if ($schoolId) {
    $si = $db->prepare("SELECT * FROM school_info WHERE id = ?");
    $si->execute([$schoolId]);
    $schoolInfo = $si->fetch(PDO::FETCH_ASSOC) ?: [];
}

// Guard: fees module must be enabled for this school
if (empty($schoolInfo['fees_enabled'])) {
    // For AJAX calls return JSON, for page loads redirect
    if (!empty($_POST['action'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Fees module is not enabled for this school.']);
    } else {
        header('Location: dashboard.php');
    }
    exit;
}

// Load notification settings for SMS
$settings = [];
try {
    $sStmt = $db->query("SELECT setting_key, setting_value FROM system_settings");
    while ($row = $sStmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {}

$feeCtrl = new FeeController();

// Map "First Term" → "1" etc.
function termToNum(string $t): string {
    return match(strtolower(trim($t))) {
        'first term','term 1','1'  => '1',
        'second term','term 2','2' => '2',
        'third term','term 3','3'  => '3',
        default => '1',
    };
}

$defaultYear = $schoolInfo['academic_year'] ?? date('Y') . '/' . (date('Y') + 1);
$defaultTerm = termToNum($schoolInfo['current_term'] ?? '1');

// ── AJAX handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    ob_clean();
    header('Content-Type: application/json');

    $action = $_POST['action'] ?? '';

    switch ($action) {

        case 'get_fee_structures':
            $year = trim($_POST['academic_year'] ?? $defaultYear);
            $term = trim($_POST['term'] ?? '');
            echo json_encode(['success' => true,
                'data' => $feeCtrl->getFeeStructures($schoolId, $year ?: null, $term ?: null)]);
            break;

        case 'save_fee_structure':
            if (!$isAdmin) { echo json_encode(['success'=>false,'error'=>'Admin only.']); exit; }
            echo json_encode($feeCtrl->saveFeeStructure($_POST, $schoolId));
            break;

        case 'delete_fee_structure':
            if (!$isAdmin) { echo json_encode(['success'=>false,'error'=>'Admin only.']); exit; }
            $id = (int)($_POST['id'] ?? 0);
            echo json_encode($feeCtrl->deleteFeeStructure($id, $schoolId));
            break;

        case 'get_class_fees':
            $classId = (int)($_POST['class_id'] ?? 0);
            $year    = trim($_POST['academic_year'] ?? $defaultYear);
            $term    = trim($_POST['term'] ?? $defaultTerm);
            if (!$classId) { echo json_encode(['success'=>false,'error'=>'No class selected.']); exit; }
            $data = $feeCtrl->getClassFeesSummary($classId, $schoolId, $year, $term);
            echo json_encode(['success' => true, 'data' => $data]);
            break;

        case 'get_student_fees':
            $studentId = (int)($_POST['student_id'] ?? 0);
            $classId   = (int)($_POST['class_id']   ?? 0);
            $year      = trim($_POST['academic_year'] ?? $defaultYear);
            $term      = trim($_POST['term'] ?? $defaultTerm);
            echo json_encode($feeCtrl->getStudentFeeDetails($studentId, $classId, $schoolId, $year, $term));
            break;

        case 'record_payment':
            $result = $feeCtrl->recordPayment($_POST, $schoolId, $userId, $settings);
            echo json_encode($result);
            break;

        case 'get_payments':
            $filters = [
                'class_id'      => (int)($_POST['class_id'] ?? 0) ?: null,
                'term'          => trim($_POST['term'] ?? ''),
                'academic_year' => trim($_POST['academic_year'] ?? ''),
                'date_from'     => trim($_POST['date_from'] ?? ''),
                'date_to'       => trim($_POST['date_to'] ?? ''),
            ];
            $data = $feeCtrl->getPaymentHistory($schoolId, array_filter($filters));
            echo json_encode(['success' => true, 'data' => $data,
                'total' => array_sum(array_column($data, 'amount_paid'))]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action.']);
    }
    exit;
}

// ── Page data ─────────────────────────────────────────────────────────────────
$classCtrl = new ClassController();
$classes   = $classCtrl->getAllClasses();

$feeStructures = $feeCtrl->getFeeStructures($schoolId);

// Distinct academic years found in fee structures + default
$years = [$defaultYear];
foreach ($feeStructures as $fs) {
    if (!in_array($fs['academic_year'], $years)) $years[] = $fs['academic_year'];
}
sort($years);

$pageTitle = 'Fees Management';
include __DIR__ . '/components/header.php';
?>
    <style>
        .fees-container { max-width: 1300px; margin: 0 auto; padding: 1.5rem 1rem 3rem; }
        .page-title { font-size: 1.5rem; font-weight: 700; color: #2d3748; margin-bottom: 1.2rem; }

        /* Tabs */
        .tab-bar { display: flex; gap: 0.5rem; border-bottom: 2px solid #e2e8f0; margin-bottom: 1.5rem; flex-wrap: wrap; padding-bottom: 2px; }
        .tab-btn { padding: 0.8rem 1.5rem; font-size: 0.95rem; font-weight: 600; cursor: pointer;
                   border: none; background: none; color: #718096; position: relative;
                   transition: all .2s; border-radius: 8px 8px 0 0; }
        .tab-btn:hover  { color: #667eea; background: rgba(102, 126, 234, 0.05); }
        .tab-btn.active { color: #667eea; }
        .tab-btn.active::after { content: ''; position: absolute; bottom: -2px; left: 0; right: 0; height: 3px; background: #667eea; border-radius: 3px; }
        .tab-panel { display: none; }
        .tab-panel.active { display: block; }

        /* Cards */
        .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px;
                padding: 1.2rem; margin-bottom: 1.2rem; box-shadow: 0 1px 4px rgba(0,0,0,.06); }
        .card-title { font-size: 1rem; font-weight: 700; color: #2d3748; margin-bottom: 1rem;
                      padding-bottom: .6rem; border-bottom: 1px solid #edf2f7; }

        /* Filter bar */
        .filter-bar { display: flex; gap: .8rem; align-items: flex-end; flex-wrap: wrap; margin-bottom: 1.2rem; }
        .filter-bar .fg { display: flex; flex-direction: column; gap: .25rem; }
        .filter-bar label { font-size: .78rem; font-weight: 600; color: #718096; text-transform: uppercase; }
        .filter-bar select, .filter-bar input[type=text], .filter-bar input[type=date] {
            padding: .45rem .7rem; border: 1px solid #cbd5e0; border-radius: 6px;
            font-size: .9rem; background: #fff; min-width: 160px; }

        /* Tables */
        .data-table { width: 100%; border-collapse: collapse; font-size: .88rem; }
        .data-table th { background: #f7fafc; color: #4a5568; font-weight: 700; padding: .7rem .9rem;
                         text-align: left; border-bottom: 2px solid #e2e8f0; white-space: nowrap; }
        .data-table td { padding: .65rem .9rem; border-bottom: 1px solid #edf2f7; vertical-align: middle; }
        .data-table tr:hover td { background: #fafbff; }
        .data-table tbody tr:last-child td { border-bottom: none; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }

        /* Badges */
        .badge { display: inline-block; padding: .25rem .65rem; border-radius: 20px;
                 font-size: .75rem; font-weight: 700; white-space: nowrap; }
        .badge-paid    { background: #c6f6d5; color: #22543d; }
        .badge-partial { background: #fefcbf; color: #744210; }
        .badge-unpaid  { background: #fed7d7; color: #742a2a; }
        .badge-nofees  { background: #e2e8f0; color: #718096; }
        .badge-tuition { background: #bee3f8; color: #2b6cb0; }
        .badge-pta     { background: #c6f6d5; color: #276749; }
        .badge-sports  { background: #fbd38d; color: #7b341e; }
        .badge-exam    { background: #e9d8fd; color: #553c9a; }
        .badge-books   { background: #fed7d7; color: #742a2a; }
        .badge-uniform { background: #fefcbf; color: #744210; }
        .badge-other   { background: #e2e8f0; color: #4a5568; }

        /* Summary stats */
        .stat-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
        .stat-box { background: #fff; border-radius: 12px; padding: 1.25rem; text-align: center;
                    box-shadow: 0 4px 12px rgba(0,0,0,0.03); border: 1px solid #edf2f7; transition: transform 0.2s; }
        .stat-box:hover { transform: translateY(-2px); }
        .stat-box .num { font-size: 1.5rem; font-weight: 700; margin-bottom: 0.25rem; }
        .stat-box .lbl { font-size: 0.8rem; color: #718096; font-weight: 500; }
        .stat-box.green { border-top: 4px solid #48bb78; }
        .stat-box.orange { border-top: 4px solid #ed8936; }
        .stat-box.red    { border-top: 4px solid #f56565; }
        .stat-box.blue   { border-top: 4px solid #4299e1; }

        /* Buttons overrides - removing redundant local styles to use global ones from header.php */
        .btn-pay { background: linear-gradient(135deg, #48bb78 0%, #38a169 100%); color: #fff; padding: .4rem 1rem; font-size: .85rem;
                   border: none; border-radius: 6px; cursor: pointer; font-weight: 600; box-shadow: 0 4px 6px rgba(72,187,120,0.2); transition: all 0.2s; }
        .btn-pay:hover { transform: translateY(-1px); box-shadow: 0 6px 12px rgba(72,187,120,0.3); opacity: 0.9; }
        .btn-edit   { background: #4299e1; color: #fff; padding: .35rem .75rem; font-size: .8rem;
                       border: none; border-radius: 6px; cursor: pointer; font-weight: 600; }
        .btn-delete { background: #f56565; color: #fff; padding: .35rem .75rem; font-size: .8rem;
                       border: none; border-radius: 6px; cursor: pointer; font-weight: 600; }



        /* Modal */
        .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.5);
                         z-index: 1000; justify-content: center; align-items: flex-start;
                         padding: 2rem 1rem; overflow-y: auto; }
        .modal-overlay.open { display: flex; }
        .modal-box { background: #fff; border-radius: 16px; padding: 2rem;
                     width: 100%; max-width: 580px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);
                     position: relative; border: 1px solid rgba(255,255,255,0.1); }
        .modal-box.wide { max-width: 720px; }
        .modal-header { display: flex; justify-content: space-between; align-items: center;
                        margin-bottom: 1.2rem; padding-bottom: .8rem; border-bottom: 1px solid #edf2f7; }
        .modal-title { font-size: 1.1rem; font-weight: 700; color: #2d3748; }
        .modal-close { background: none; border: none; font-size: 1.4rem; cursor: pointer; color: #718096; }
        .form-row { display: flex; gap: .8rem; flex-wrap: wrap; }
        .form-group { display: flex; flex-direction: column; gap: .3rem; flex: 1; min-width: 180px; }
        .form-group label { font-size: .8rem; font-weight: 600; color: #4a5568; }
        .form-group input, .form-group select, .form-group textarea {
            padding: .5rem .75rem; border: 1px solid #cbd5e0; border-radius: 6px; font-size: .9rem; }
        .form-group textarea { resize: vertical; }
        .form-group small { color: #718096; font-size: .75rem; }
        .form-actions { display: flex; gap: .7rem; justify-content: flex-end; margin-top: 1.2rem; }

        /* Fee breakdown in payment modal */
        .fee-row { display: flex; align-items: center; gap: 1rem; padding: 1rem;
                   border: 1px solid #e2e8f0; border-radius: 10px; margin-bottom: 0.75rem; cursor: pointer;
                   transition: all 0.2s; }
        .fee-row:hover { background: #f8fafc; border-color: #667eea; }
        .fee-row.selected { border-color: #667eea; background: rgba(102, 126, 234, 0.05); box-shadow: 0 0 0 2px rgba(102, 126, 234, 0.1); }
        .fee-row.fully-paid { opacity: .55; cursor: not-allowed; background: #f8fafc; }
        .fee-info { flex: 1; }
        .fee-name { font-weight: 700; font-size: 0.95rem; color: #2d3748; margin-bottom: 0.2rem; }
        .fee-meta { font-size: .8rem; color: #718096; }
        .fee-amounts { text-align: right; font-size: .85rem; }
        .fee-amounts .due   { color: #718096; font-size: .75rem; text-transform: uppercase; letter-spacing: 0.025em; font-weight: 600; }
        .fee-amounts .bal   { font-weight: 800; color: #2d3748; font-size: 1rem; }
        .fee-amounts .paid0 { color: #f56565; }
        .fee-amounts .paidf { color: #48bb78; }

        /* Alert box */
        .alert { padding: 1rem 1.25rem; border-radius: 10px; margin-bottom: 1.5rem; font-size: .9rem; font-weight: 500; display: flex; align-items: center; gap: 0.75rem; }
        .alert-success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
        .alert-error   { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
        .alert-info    { background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }

        /* Loading spinner */
        .loading { color: #718096; font-style: italic; padding: 1rem; text-align: center; }

        /* Receipt block */
        .receipt-box { background: #f7fafc; border: 1px dashed #cbd5e0; border-radius: 8px;
                       padding: 1rem 1.2rem; font-size: .88rem; line-height: 1.7; }
        .receipt-box strong { color: #2d3748; }

        @media (max-width: 768px) {
            .fees-container { padding: 0.75rem 0.75rem 5rem; }
            .page-title { font-size: 1.2rem; }

            /* Tabs scroll horizontally */
            .tab-bar { flex-wrap: nowrap; overflow-x: auto; -webkit-overflow-scrolling: touch;
                       scrollbar-width: none; gap: 0; border-bottom: 2px solid #e2e8f0; }
            .tab-bar::-webkit-scrollbar { display: none; }
            .tab-btn { padding: 0.65rem 1rem; font-size: 0.85rem; white-space: nowrap; }

            /* Filters stack */
            .filter-bar { flex-direction: column; align-items: stretch; }
            .filter-bar .fg { width: 100%; }
            .filter-bar select, .filter-bar input[type=text], .filter-bar input[type=date] {
                width: 100% !important; min-width: 100% !important; font-size: 16px !important; min-height: 48px; }

            /* Stats: 2 column grid on mobile */
            .stat-row { grid-template-columns: repeat(2, 1fr) !important; }

            /* Table: scrollable — only the table, not the whole card */
            .table-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; margin: 0 -0.25rem; }
            .data-table { min-width: 520px; }
            .data-table th, .data-table td { padding: 0.5rem 0.5rem; font-size: 0.8rem; }

            /* Modals */
            .modal-overlay { padding: 0.5rem; align-items: flex-end; }
            .modal-box { max-width: 100% !important; border-radius: 16px 16px 0 0 !important;
                         padding: 1.25rem; max-height: 90vh; overflow-y: auto; }
            .form-row { flex-direction: column; }
            .form-group { min-width: 100% !important; }
            .form-group input, .form-group select, .form-group textarea {
                font-size: 16px !important; min-height: 48px; }
            .form-actions { flex-direction: column; }
            .form-actions button, .form-actions a { width: 100% !important; }

            /* Fee rows in payment modal */
            .fee-row { flex-wrap: wrap; gap: 0.5rem; }
            .fee-amounts { text-align: left; }

            /* Buttons */
            .btn-pay { width: 100%; padding: 0.75rem; font-size: 0.95rem; }
        }

        @media (max-width: 480px) {
            .stat-row { grid-template-columns: 1fr !important; }
        }
    </style>

<div class="fees-container">
    <div class="page-title">💰 Fees Management</div>

    <!-- Tab navigation -->
    <div class="tab-bar">
        <button class="tab-btn active" onclick="switchTab('setup',this)">⚙️ Fee Setup</button>
        <button class="tab-btn" onclick="switchTab('collect',this)">📋 Collect Fees</button>
        <button class="tab-btn" onclick="switchTab('history',this)">📊 Payment History</button>
    </div>

    <!-- ══════════════ TAB 1: FEE SETUP ══════════════ -->
    <div id="tab-setup" class="tab-panel active">
        <div class="card">
            <div class="card-title">
                Fee Structures
                <?php if ($isAdmin): ?>
                <button class="btn btn-primary btn-sm" style="float:right;" onclick="openFeeStructModal()">+ Add Fee</button>
                <?php endif; ?>
            </div>

            <!-- Filters -->
            <div class="filter-bar" style="margin-bottom:.8rem;">
                <div class="fg">
                    <label>Academic Year</label>
                    <select id="setupYear" onchange="loadFeeStructures()">
                        <?php foreach (array_reverse($years) as $y): ?>
                        <option value="<?php echo htmlspecialchars($y); ?>" <?php echo $y===$defaultYear?'selected':''; ?>>
                            <?php echo htmlspecialchars($y); ?>
                        </option>
                        <?php endforeach; ?>
                        <option value="">All Years</option>
                    </select>
                </div>
                <div class="fg">
                    <label>Term</label>
                    <select id="setupTerm" onchange="loadFeeStructures()">
                        <option value="">All Terms</option>
                        <option value="1" <?php echo $defaultTerm==='1'?'selected':''; ?>>Term 1</option>
                        <option value="2" <?php echo $defaultTerm==='2'?'selected':''; ?>>Term 2</option>
                        <option value="3" <?php echo $defaultTerm==='3'?'selected':''; ?>>Term 3</option>
                    </select>
                </div>
            </div>

            <div id="feeStructuresList"><div class="loading">Loading fee structures…</div></div>
        </div>
    </div>

    <!-- ══════════════ TAB 2: COLLECT FEES ══════════════ -->
    <div id="tab-collect" class="tab-panel">
        <div class="card">
            <div class="card-title">Student Fee Status</div>

            <div class="filter-bar">
                <div class="fg">
                    <label>Academic Year</label>
                    <select id="collectYear">
                        <?php foreach (array_reverse($years) as $y): ?>
                        <option value="<?php echo htmlspecialchars($y); ?>" <?php echo $y===$defaultYear?'selected':''; ?>>
                            <?php echo htmlspecialchars($y); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="fg">
                    <label>Term</label>
                    <select id="collectTerm">
                        <option value="1" <?php echo $defaultTerm==='1'?'selected':''; ?>>Term 1</option>
                        <option value="2" <?php echo $defaultTerm==='2'?'selected':''; ?>>Term 2</option>
                        <option value="3" <?php echo $defaultTerm==='3'?'selected':''; ?>>Term 3</option>
                    </select>
                </div>
                <div class="fg">
                    <label>Class</label>
                    <select id="collectClass">
                        <option value="">— Select Class —</option>
                        <?php foreach ($classes as $cls): ?>
                        <option value="<?php echo $cls['id']; ?>"><?php echo htmlspecialchars($cls['class_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="fg" style="justify-content:flex-end;">
                    <label>&nbsp;</label>
                    <button class="btn btn-primary" onclick="loadClassFees()">Load Students</button>
                </div>
            </div>

            <div id="collectStats"></div>
            <div id="collectTable"><p style="color:#718096;font-size:.9rem;">Select a class and click Load Students.</p></div>
        </div>
    </div>

    <!-- ══════════════ TAB 3: PAYMENT HISTORY ══════════════ -->
    <div id="tab-history" class="tab-panel">
        <div class="card">
            <div class="card-title">Payment History</div>

            <div class="filter-bar">
                <div class="fg">
                    <label>Academic Year</label>
                    <select id="histYear">
                        <option value="">All Years</option>
                        <?php foreach (array_reverse($years) as $y): ?>
                        <option value="<?php echo htmlspecialchars($y); ?>" <?php echo $y===$defaultYear?'selected':''; ?>>
                            <?php echo htmlspecialchars($y); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="fg">
                    <label>Term</label>
                    <select id="histTerm">
                        <option value="">All Terms</option>
                        <option value="1">Term 1</option>
                        <option value="2">Term 2</option>
                        <option value="3">Term 3</option>
                    </select>
                </div>
                <div class="fg">
                    <label>Class</label>
                    <select id="histClass">
                        <option value="">All Classes</option>
                        <?php foreach ($classes as $cls): ?>
                        <option value="<?php echo $cls['id']; ?>"><?php echo htmlspecialchars($cls['class_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="fg">
                    <label>From</label>
                    <input type="date" id="histFrom">
                </div>
                <div class="fg">
                    <label>To</label>
                    <input type="date" id="histTo" value="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="fg" style="justify-content:flex-end;">
                    <label>&nbsp;</label>
                    <button class="btn btn-primary" onclick="loadHistory()">Load</button>
                </div>
            </div>

            <div id="histStats"></div>
            <div id="histTable"><p style="color:#718096;font-size:.9rem;">Set filters and click Load.</p></div>
        </div>
    </div>
</div>

<!-- ══════ MODAL: Add / Edit Fee Structure ══════ -->
<div class="modal-overlay" id="feeStructModal">
    <div class="modal-box">
        <div class="modal-header">
            <span class="modal-title" id="feeStructModalTitle">Add Fee Structure</span>
            <button class="modal-close" onclick="closeFeeStructModal()">&times;</button>
        </div>
        <form id="feeStructForm" onsubmit="saveFeeStructure(event)">
            <input type="hidden" id="fs_id" name="id" value="">
            <input type="hidden" name="action" value="save_fee_structure">

            <div class="form-row">
                <div class="form-group" style="flex:2;">
                    <label>Fee Name *</label>
                    <input type="text" id="fs_fee_name" name="fee_name" required
                           placeholder="e.g. Tuition Fee, PTA Levy">
                </div>
                <div class="form-group">
                    <label>Fee Type *</label>
                    <select id="fs_fee_type" name="fee_type">
                        <option value="tuition">Tuition</option>
                        <option value="pta">PTA</option>
                        <option value="sports">Sports</option>
                        <option value="exam">Exam</option>
                        <option value="books">Books</option>
                        <option value="uniform">Uniform</option>
                        <option value="other">Other</option>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Amount (GH¢) *</label>
                    <input type="number" id="fs_amount" name="amount" required min="0.01" step="0.01" placeholder="0.00">
                </div>
                <div class="form-group">
                    <label>Term *</label>
                    <select id="fs_term" name="term">
                        <option value="1" <?php echo $defaultTerm==='1'?'selected':''; ?>>Term 1</option>
                        <option value="2" <?php echo $defaultTerm==='2'?'selected':''; ?>>Term 2</option>
                        <option value="3">Term 3</option>
                        <option value="all">All Terms (Annual)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Academic Year *</label>
                    <input type="text" id="fs_academic_year" name="academic_year"
                           value="<?php echo htmlspecialchars($defaultYear); ?>" required
                           placeholder="e.g. 2024/2025">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Class (leave blank = all classes)</label>
                    <select id="fs_class_id" name="class_id">
                        <option value="">All Classes</option>
                        <?php foreach ($classes as $cls): ?>
                        <option value="<?php echo $cls['id']; ?>"><?php echo htmlspecialchars($cls['class_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Active</label>
                    <select id="fs_is_active" name="is_active">
                        <option value="1">Yes</option>
                        <option value="0">No</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>Notes (optional)</label>
                <textarea id="fs_notes" name="notes" rows="2" placeholder="Any notes about this fee…"></textarea>
            </div>

            <div id="feeStructMsg"></div>
            <div class="form-actions">
                <button type="button" class="btn btn-outline" onclick="closeFeeStructModal()">Cancel</button>
                <button type="submit" class="btn btn-success" id="feeStructSaveBtn">Save Fee Structure</button>
            </div>
        </form>
    </div>
</div>

<!-- ══════ MODAL: Record Payment ══════ -->
<div class="modal-overlay" id="paymentModal">
    <div class="modal-box wide">
        <div class="modal-header">
            <span class="modal-title" id="paymentModalTitle">Record Payment</span>
            <button class="modal-close" onclick="closePaymentModal()">&times;</button>
        </div>

        <div id="paymentModalBody">
            <div class="loading">Loading student fee details…</div>
        </div>
    </div>
</div>

<!-- ══════ MODAL: Payment Receipt ══════ -->
<div class="modal-overlay" id="receiptModal">
    <div class="modal-box">
        <div class="modal-header">
            <span class="modal-title">✅ Payment Recorded</span>
            <button class="modal-close" onclick="closeReceiptModal()">&times;</button>
        </div>
        <div id="receiptBody"></div>
        <div class="form-actions">
            <button class="btn btn-outline" onclick="closeReceiptModal()">Close</button>
            <button class="btn btn-primary" onclick="window.print()">🖨️ Print Receipt</button>
        </div>
    </div>
</div>

<script>
// ── State ─────────────────────────────────────────────────────────────────────
let collectClassId = null;
let collectYear    = '';
let collectTerm    = '';
let currentStudentFees = null; // for payment modal
let feeStructuresData  = [];   // for edit modal

// ── Tab switching ─────────────────────────────────────────────────────────────
function switchTab(name, btn) {
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('tab-' + name).classList.add('active');
    btn.classList.add('active');
    if (name === 'setup')   loadFeeStructures();
    if (name === 'history') loadHistory();
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function post(data) {
    const fd = new FormData();
    for (const [k,v] of Object.entries(data)) fd.append(k, v ?? '');
    return fetch('fees.php', { method:'POST', body:fd }).then(r => r.json());
}

function fmtGhc(n) { return 'GH¢' + parseFloat(n||0).toFixed(2); }
function termLabel(t) { return t==='all'?'All Terms':'Term '+t; }
function methodLabel(m) {
    return {cash:'Cash',momo:'MoMo',bank_transfer:'Bank Transfer',cheque:'Cheque',other:'Other'}[m]||m;
}
function statusBadge(s) {
    const map = {
        paid:    '<span class="badge badge-paid">FULLY PAID ✓</span>',
        partial: '<span class="badge badge-partial">PART PAID</span>',
        unpaid:  '<span class="badge badge-unpaid">UNPAID</span>',
        no_fees: '<span class="badge badge-nofees">NO FEES SET</span>',
    };
    return map[s] || s;
}
function feeBadge(t) { return `<span class="badge badge-${t}">${t.toUpperCase()}</span>`; }

// ── Fee Structures Tab ────────────────────────────────────────────────────────
function loadFeeStructures() {
    const year = document.getElementById('setupYear').value;
    const term = document.getElementById('setupTerm').value;
    document.getElementById('feeStructuresList').innerHTML = '<div class="loading">Loading…</div>';
    post({ action:'get_fee_structures', academic_year:year, term }).then(res => {
        if (!res.success) {
            document.getElementById('feeStructuresList').innerHTML = '<p class="alert alert-error">' + res.error + '</p>';
            return;
        }
        const rows = res.data;
        feeStructuresData = rows; // Store for edit modal
        if (rows.length === 0) {
            document.getElementById('feeStructuresList').innerHTML =
                '<p style="color:#718096;padding:.8rem;">No fee structures found. ' +
                (<?php echo $isAdmin ? 'true' : 'false'; ?> ? '<button class="btn btn-primary btn-sm" onclick="openFeeStructModal()">+ Add First Fee</button>' : '') + '</p>';
            return;
        }
        let html = `<table class="data-table">
            <thead><tr>
                <th>Fee Name</th><th>Type</th><th>Amount</th>
                <th>Term</th><th>Year</th><th>Class</th><th>Status</th>
                <?php echo $isAdmin ? '<th>Actions</th>' : ''; ?>
            </tr></thead><tbody>`;
        rows.forEach(r => {
            html += `<tr>
                <td><strong>${r.fee_name}</strong>${r.notes?'<br><small style="color:#718096">'+r.notes+'</small>':''}</td>
                <td>${feeBadge(r.fee_type)}</td>
                <td class="text-right"><strong>${fmtGhc(r.amount)}</strong></td>
                <td>${termLabel(r.term)}</td>
                <td>${r.academic_year}</td>
                <td>${r.class_name || '<em style="color:#718096">All Classes</em>'}</td>
                <td>${r.is_active=='1'?'<span style="color:#38a169;font-weight:600;">Active</span>':'<span style="color:#e53e3e;">Inactive</span>'}</td>
                ${<?php echo $isAdmin ? 'true' : 'false'; ?> ? `<td><button class='btn-edit' onclick='editFeeStruct(${r.id})'>Edit</button> <button class='btn-delete' onclick='deleteFeeStruct(${r.id})'>Delete</button></td>` : ''}
            </tr>`;
        });
        html += '</tbody></table>';
        document.getElementById('feeStructuresList').innerHTML = '<div class="table-scroll">' + html + '</div>';
    });
}

// ── Fee Structure Modal ───────────────────────────────────────────────────────
function openFeeStructModal(edit) {
    document.getElementById('feeStructModalTitle').textContent = edit ? 'Edit Fee Structure' : 'Add Fee Structure';
    document.getElementById('feeStructForm').reset();
    document.getElementById('feeStructMsg').innerHTML = '';
    if (edit) {
        document.getElementById('fs_id').value           = edit.id;
        document.getElementById('fs_fee_name').value     = edit.fee_name;
        document.getElementById('fs_fee_type').value     = edit.fee_type;
        document.getElementById('fs_amount').value       = edit.amount;
        document.getElementById('fs_term').value         = edit.term;
        document.getElementById('fs_academic_year').value= edit.academic_year;
        document.getElementById('fs_class_id').value     = edit.class_id || '';
        document.getElementById('fs_is_active').value    = edit.is_active;
        document.getElementById('fs_notes').value        = edit.notes || '';
    }
    document.getElementById('feeStructModal').classList.add('open');
}
function closeFeeStructModal() { document.getElementById('feeStructModal').classList.remove('open'); }
function editFeeStruct(id) {
    const r = feeStructuresData.find(x => x.id == id);
    if (r) openFeeStructModal(r);
}
function deleteFeeStruct(id) {
    if (!confirm('Delete this fee structure? (If payments exist it will be deactivated instead.)')) return;
    post({ action:'delete_fee_structure', id }).then(res => {
        alert(res.message || (res.success ? 'Done.' : res.error));
        if (res.success) loadFeeStructures();
    });
}
function saveFeeStructure(e) {
    e.preventDefault();
    const btn = document.getElementById('feeStructSaveBtn');
    btn.disabled = true; btn.textContent = 'Saving…';
    const fd = new FormData(document.getElementById('feeStructForm'));
    fetch('fees.php', { method:'POST', body:fd }).then(r=>r.json()).then(res => {
        btn.disabled = false; btn.textContent = 'Save Fee Structure';
        const msgEl = document.getElementById('feeStructMsg');
        if (res.success) {
            msgEl.innerHTML = '<div class="alert alert-success">Saved successfully!</div>';
            setTimeout(() => { closeFeeStructModal(); loadFeeStructures(); }, 800);
        } else {
            msgEl.innerHTML = '<div class="alert alert-error">' + res.error + '</div>';
        }
    });
}

// ── Collect Fees Tab ──────────────────────────────────────────────────────────
function loadClassFees() {
    collectClassId = document.getElementById('collectClass').value;
    collectYear    = document.getElementById('collectYear').value;
    collectTerm    = document.getElementById('collectTerm').value;
    if (!collectClassId) { alert('Please select a class.'); return; }

    document.getElementById('collectTable').innerHTML = '<div class="loading">Loading students…</div>';
    document.getElementById('collectStats').innerHTML = '';

    post({ action:'get_class_fees', class_id:collectClassId, academic_year:collectYear, term:collectTerm }).then(res => {
        if (!res.success) {
            document.getElementById('collectTable').innerHTML = '<p class="alert alert-error">' + res.error + '</p>';
            return;
        }
        const students = res.data;
        if (students.length === 0) {
            document.getElementById('collectTable').innerHTML = '<p style="color:#718096;padding:.8rem;">No students found in this class.</p>';
            return;
        }

        // Stats
        const totalDue   = students.reduce((s,x) => s + parseFloat(x.total_due||0), 0);
        const totalPaid  = students.reduce((s,x) => s + parseFloat(x.total_paid||0), 0);
        const countPaid    = students.filter(s => s.status==='paid').length;
        const countPartial = students.filter(s => s.status==='partial').length;
        const countUnpaid  = students.filter(s => s.status==='unpaid').length;

        document.getElementById('collectStats').innerHTML = `
            <div class="stat-row">
                <div class="stat-box blue"><div class="num">${students.length}</div><div class="lbl">Students</div></div>
                <div class="stat-box blue"><div class="num">${fmtGhc(totalDue)}</div><div class="lbl">Total Due</div></div>
                <div class="stat-box green"><div class="num">${fmtGhc(totalPaid)}</div><div class="lbl">Total Collected</div></div>
                <div class="stat-box red"><div class="num">${fmtGhc(totalDue-totalPaid)}</div><div class="lbl">Outstanding</div></div>
                <div class="stat-box green"><div class="num">${countPaid}</div><div class="lbl">Fully Paid</div></div>
                <div class="stat-box orange"><div class="num">${countPartial}</div><div class="lbl">Part Paid</div></div>
                <div class="stat-box red"><div class="num">${countUnpaid}</div><div class="lbl">Unpaid</div></div>
            </div>`;

        let html = `<table class="data-table">
            <thead><tr>
                <th>#</th><th>Student Name</th><th>ID</th>
                <th class="text-right">Total Due</th>
                <th class="text-right">Paid</th>
                <th class="text-right">Balance</th>
                <th class="text-center">Status</th>
                <th class="text-center">Action</th>
            </tr></thead><tbody>`;
        students.forEach((s,i) => {
            const canPay = s.status !== 'paid' && s.status !== 'no_fees';
            html += `<tr>
                <td>${i+1}</td>
                <td><strong>${s.student_name}</strong>${s.parent_name?'<br><small style="color:#718096">'+s.parent_name+'</small>':''}</td>
                <td style="font-size:.8rem;color:#718096;">${s.student_id_no}</td>
                <td class="text-right">${fmtGhc(s.total_due)}</td>
                <td class="text-right" style="color:#38a169;">${fmtGhc(s.total_paid)}</td>
                <td class="text-right" style="font-weight:700;color:${s.balance>0?'#e53e3e':'#38a169'};">${fmtGhc(s.balance)}</td>
                <td class="text-center">${statusBadge(s.status)}</td>
                <td class="text-center">${canPay ? `<button class="btn-pay" onclick="openPaymentModal(${s.student_id},'${collectClassId}')">💰 Pay</button>` : '<span style="color:#a0aec0;font-size:.8rem;">—</span>'}</td>
            </tr>`;
        });
        html += '</tbody></table>';
        document.getElementById('collectTable').innerHTML = '<div class="table-scroll">' + html + '</div>';
    });
}

// ── Payment Modal ─────────────────────────────────────────────────────────────
function openPaymentModal(studentId, classId) {
    document.getElementById('paymentModalTitle').textContent = 'Record Payment';
    document.getElementById('paymentModalBody').innerHTML = '<div class="loading">Loading fee details…</div>';
    document.getElementById('paymentModal').classList.add('open');

    post({ action:'get_student_fees', student_id:studentId, class_id:classId||collectClassId,
           academic_year:collectYear, term:collectTerm }).then(res => {
        if (!res.success) {
            document.getElementById('paymentModalBody').innerHTML = '<p class="alert alert-error">' + res.error + '</p>';
            return;
        }
        currentStudentFees = res;
        renderPaymentForm(res);
    });
}
function closePaymentModal() { document.getElementById('paymentModal').classList.remove('open'); }

function renderPaymentForm(d) {
    const s = d.student;
    let feeRows = '';
    d.fees.forEach(f => {
        const paid = f.status === 'paid';
        feeRows += `
            <div class="fee-row ${paid?'fully-paid':''}" data-fid="${f.fee_structure_id}" data-bal="${f.balance}" onclick="${paid?'':'selectFeeRow(this)'}">
                <div class="fee-info">
                    <div class="fee-name">${f.fee_name} ${feeBadge(f.fee_type)}</div>
                    <div class="fee-meta">Due: ${fmtGhc(f.amount_due)} | Paid: ${fmtGhc(f.amount_paid)}</div>
                </div>
                <div class="fee-amounts">
                    <div class="due">Balance</div>
                    <div class="bal ${paid?'paidf':'paid0'}">${paid?'PAID ✓':fmtGhc(f.balance)}</div>
                </div>
            </div>`;
    });

    if (d.fees.length === 0) {
        feeRows = '<p class="alert alert-info">No fee structures are set for this term. Ask admin to add fees in the Fee Setup tab.</p>';
    }

    document.getElementById('paymentModalTitle').textContent = 'Record Payment — ' + s.student_name;
    document.getElementById('paymentModalBody').innerHTML = `
        <div class="alert alert-info" style="margin-bottom:.8rem;">
            <strong>${s.student_name}</strong> | Balance: <strong>${fmtGhc(d.balance)}</strong> | ${termLabel(d.term)}, ${d.academic_year}
        </div>

        <p style="font-size:.82rem;font-weight:600;color:#4a5568;margin-bottom:.4rem;">Select fee to pay toward:</p>
        <div id="feeRowsContainer">${feeRows}</div>

        <form id="paymentForm" onsubmit="submitPayment(event)" style="margin-top:1rem;">
            <input type="hidden" name="action" value="record_payment">
            <input type="hidden" name="student_id" value="${s.id}">
            <input type="hidden" id="pFeeStructId" name="fee_structure_id" value="">

            <div class="form-row">
                <div class="form-group">
                    <label>Amount Paid (GH¢) *</label>
                    <input type="number" id="pAmount" name="amount_paid" required min="0.01" step="0.01"
                           value="${d.balance > 0 ? parseFloat(d.balance).toFixed(2) : ''}">
                    <small id="pAmountHint" style="color:#718096;"></small>
                </div>
                <div class="form-group">
                    <label>Payment Date *</label>
                    <input type="date" name="payment_date" required value="${new Date().toISOString().slice(0,10)}">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Payment Method *</label>
                    <select name="payment_method">
                        <option value="cash">Cash</option>
                        <option value="momo">Mobile Money (MoMo)</option>
                        <option value="bank_transfer">Bank Transfer</option>
                        <option value="cheque">Cheque</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Notes (optional)</label>
                    <input type="text" name="notes" placeholder="e.g. First instalment">
                </div>
            </div>
            <div class="form-group" style="flex-direction:row;align-items:center;gap:.5rem;margin-top:.3rem;">
                <input type="checkbox" id="pSendSms" name="send_sms" value="1" ${s.parent_phone?'checked':''}
                       style="width:auto;">
                <label for="pSendSms" style="margin:0;font-size:.9rem;">
                    📱 Send SMS to parent
                    ${s.parent_phone?'(<strong>'+s.parent_phone+'</strong>)':'<span style="color:#e53e3e;">(no phone set)</span>'}
                </label>
            </div>
            <div id="paymentMsg"></div>
            <div class="form-actions">
                <button type="button" class="btn btn-outline" onclick="closePaymentModal()">Cancel</button>
                <button type="submit" class="btn btn-success" id="paymentSubmitBtn" disabled>💰 Record Payment</button>
            </div>
        </form>`;

    // Auto-select if only one unpaid fee
    const unpaidRows = document.querySelectorAll('#feeRowsContainer .fee-row:not(.fully-paid)');
    if (unpaidRows.length === 1) selectFeeRow(unpaidRows[0]);
}

function selectFeeRow(el) {
    document.querySelectorAll('#feeRowsContainer .fee-row').forEach(r => r.classList.remove('selected'));
    el.classList.add('selected');
    const fid = el.dataset.fid;
    const bal = parseFloat(el.dataset.bal || 0);
    document.getElementById('pFeeStructId').value = fid;
    document.getElementById('pAmount').value = bal.toFixed(2);
    document.getElementById('pAmountHint').textContent = 'Balance for this fee: ' + fmtGhc(bal);
    document.getElementById('paymentSubmitBtn').disabled = false;
}

function submitPayment(e) {
    e.preventDefault();
    const btn = document.getElementById('paymentSubmitBtn');
    btn.disabled = true; btn.textContent = 'Processing…';

    const fd = new FormData(document.getElementById('paymentForm'));
    fetch('fees.php', { method:'POST', body:fd }).then(r=>r.json()).then(res => {
        btn.disabled = false; btn.textContent = '💰 Record Payment';
        const msgEl = document.getElementById('paymentMsg');
        if (!res.success) {
            msgEl.innerHTML = '<div class="alert alert-error">' + res.error + '</div>';
            return;
        }
        closePaymentModal();
        showReceipt(res);
        loadClassFees(); // refresh the student table
    });
}

function showReceipt(r) {
    const balText = r.balance <= 0 ? '<span style="color:#38a169;font-weight:700;">FULLY PAID ✓</span>'
                                    : '<span style="color:#e53e3e;font-weight:700;">Balance: ' + fmtGhc(r.balance) + '</span>';
    const smsText = r.sms && r.sms.sent
        ? '<span style="color:#38a169;">✓ SMS sent to parent</span>'
        : (r.sms ? '<span style="color:#718096;">SMS not sent: ' + r.sms.detail + '</span>' : '');

    document.getElementById('receiptBody').innerHTML = `
        <div class="receipt-box">
            <div style="text-align:center;font-size:1rem;font-weight:700;border-bottom:1px dashed #cbd5e0;padding-bottom:.6rem;margin-bottom:.6rem;">
                PAYMENT RECEIPT
            </div>
            <table style="width:100%;font-size:.88rem;border-collapse:collapse;">
                <tr><td style="padding:.3rem 0;color:#718096;">Receipt No.</td><td><strong>${r.receipt_number}</strong></td></tr>
                <tr><td style="color:#718096;">Student</td><td><strong>${r.student_name}</strong></td></tr>
                <tr><td style="color:#718096;">Class</td><td>${r.class_name}</td></tr>
                <tr><td style="color:#718096;">Fee</td><td>${r.fee_name}</td></tr>
                <tr><td style="color:#718096;">Term</td><td>${r.term_label}, ${r.academic_year}</td></tr>
                <tr><td style="color:#718096;">Amount Paid</td><td><strong style="color:#38a169;font-size:1.05rem;">${fmtGhc(r.amount_paid)}</strong></td></tr>
                <tr><td style="color:#718096;">Total Fee</td><td>${fmtGhc(r.total_fee)}</td></tr>
                <tr><td style="color:#718096;">Status</td><td>${balText}</td></tr>
            </table>
            ${smsText ? '<div style="margin-top:.8rem;font-size:.82rem;">' + smsText + '</div>' : ''}
        </div>`;
    document.getElementById('receiptModal').classList.add('open');
}

function closeReceiptModal() { document.getElementById('receiptModal').classList.remove('open'); }

// ── Payment History Tab ───────────────────────────────────────────────────────
function loadHistory() {
    document.getElementById('histTable').innerHTML = '<div class="loading">Loading…</div>';
    document.getElementById('histStats').innerHTML = '';
    post({
        action:        'get_payments',
        academic_year: document.getElementById('histYear').value,
        term:          document.getElementById('histTerm').value,
        class_id:      document.getElementById('histClass').value,
        date_from:     document.getElementById('histFrom').value,
        date_to:       document.getElementById('histTo').value,
    }).then(res => {
        if (!res.success) {
            document.getElementById('histTable').innerHTML = '<p class="alert alert-error">' + res.error + '</p>';
            return;
        }
        const rows = res.data;
        document.getElementById('histStats').innerHTML = `
            <div class="stat-row">
                <div class="stat-box blue"><div class="num">${rows.length}</div><div class="lbl">Transactions</div></div>
                <div class="stat-box green"><div class="num">${fmtGhc(res.total)}</div><div class="lbl">Total Collected</div></div>
            </div>`;
        if (rows.length === 0) {
            document.getElementById('histTable').innerHTML = '<p style="color:#718096;padding:.8rem;">No payments found for selected filters.</p>';
            return;
        }
        let html = `<div class="table-scroll"><table class="data-table">
            <thead><tr>
                <th>Date</th><th>Receipt</th><th>Student</th><th>Class</th>
                <th>Fee</th><th>Term</th><th class="text-right">Amount</th>
                <th>Method</th><th>SMS</th><th>Recorded By</th>
            </tr></thead><tbody>`;
        rows.forEach(r => {
            html += `<tr>
                <td style="white-space:nowrap;">${r.payment_date}</td>
                <td style="font-size:.8rem;font-family:monospace;">${r.receipt_number}</td>
                <td><strong>${r.student_name}</strong><br><small style="color:#718096">${r.student_id_no}</small></td>
                <td>${r.class_name}</td>
                <td>${r.fee_name} ${feeBadge(r.fee_type)}</td>
                <td>${termLabel(r.term)}, ${r.academic_year}</td>
                <td class="text-right"><strong style="color:#38a169;">${fmtGhc(r.amount_paid)}</strong></td>
                <td>${methodLabel(r.payment_method)}</td>
                <td class="text-center">${r.sms_sent=='1'?'<span style="color:#38a169;">✓</span>':'<span style="color:#a0aec0;">—</span>'}</td>
                <td style="font-size:.8rem;color:#718096;">${r.recorded_by_name||'—'}</td>
            </tr>`;
        });
        html += '</tbody></table></div>';
        document.getElementById('histTable').innerHTML = html;
    });
}

// ── Init ──────────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    loadFeeStructures();
});
</script>
<?php include __DIR__ . '/components/footer.php'; ?>
