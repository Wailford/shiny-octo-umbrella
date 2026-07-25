<?php
session_start();

// Developer-only access
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true ||
    ($_SESSION['user_type'] ?? '') !== 'developer') {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/config/database.php';
$db = Database::getInstance()->getConnection();

// ── Handle AJAX/GET requests for fetching school users ───────────────────────
if (isset($_GET['get_users']) && isset($_GET['school_id'])) {
    header('Content-Type: application/json');
    $sId = (int)$_GET['school_id'];
    $stmt = $db->prepare("SELECT id, username, full_name, user_type, role FROM users WHERE school_id = ? AND user_type != 'developer' ORDER BY user_type, username");
    $stmt->execute([$sId]);
    $schoolUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($schoolUsers);
    exit;
}

$message = '';
$messageType = '';

// ── Handle POST actions ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action    = $_POST['action'] ?? '';
    $schoolId  = isset($_POST['school_id']) ? (int)$_POST['school_id'] : 0;

    if ($action === 'approve' && $schoolId) {
        $stmt = $db->prepare("UPDATE school_info SET is_approved = 1,
            trial_start_date = NOW(),
            trial_end_date   = DATE_ADD(NOW(), INTERVAL 3 DAY)
            WHERE id = ?");
        $stmt->execute([$schoolId]);
        $message = 'School approved. 3-day trial started.';
        $messageType = 'success';

    } elseif ($action === 'unapprove' && $schoolId) {
        $stmt = $db->prepare("UPDATE school_info SET is_approved = 0 WHERE id = ?");
        $stmt->execute([$schoolId]);
        $message = 'School approval revoked.';
        $messageType = 'warning';

    } elseif ($action === 'mark_paid' && $schoolId) {
        $stmt = $db->prepare("UPDATE school_info SET is_paid = 1,
            trial_end_date = DATE_ADD(NOW(), INTERVAL 10 YEAR) WHERE id = ?");
        $stmt->execute([$schoolId]);
        $message = 'School marked as paid.';
        $messageType = 'success';

    } elseif ($action === 'mark_unpaid' && $schoolId) {
        $stmt = $db->prepare("UPDATE school_info SET is_paid = 0 WHERE id = ?");
        $stmt->execute([$schoolId]);
        $message = 'School marked as unpaid.';
        $messageType = 'warning';

    } elseif ($action === 'lock' && $schoolId) {
        $reason = trim($_POST['lock_reason'] ?? 'Locked by developer.');
        $stmt = $db->prepare("UPDATE school_info SET is_locked = 1, lock_reason = ?,
            locked_at = NOW(), locked_by = ? WHERE id = ?");
        $stmt->execute([$reason, $_SESSION['user_id'], $schoolId]);
        $message = 'School locked.';
        $messageType = 'warning';

    } elseif ($action === 'unlock' && $schoolId) {
        $stmt = $db->prepare("UPDATE school_info SET is_locked = 0, lock_reason = NULL,
            locked_at = NULL, locked_by = NULL WHERE id = ?");
        $stmt->execute([$schoolId]);
        $message = 'School unlocked.';
        $messageType = 'success';

    } elseif ($action === 'delete_school' && $schoolId) {
        // Cascade via FK; delete users first if no FK cascade
        $stmt = $db->prepare("DELETE FROM school_info WHERE id = ?");
        $stmt->execute([$schoolId]);
        $message = 'School deleted.';
        $messageType = 'danger';

    } elseif ($action === 'send_global_message') {
        $msg = trim($_POST['global_message'] ?? '');
        if ($msg !== '') {
            // Deactivate previous messages, add new one
            $db->exec("UPDATE global_messages SET is_active = 0");
            $stmt = $db->prepare("INSERT INTO global_messages (message, is_active) VALUES (?, 1)");
            $stmt->execute([$msg]);
            $message = 'Global message sent.';
            $messageType = 'success';
        }

    } elseif ($action === 'clear_global_message') {
        $db->exec("UPDATE global_messages SET is_active = 0");
        $message = 'Global message cleared.';
        $messageType = 'info';
    } elseif ($action === 'reset_password' && $schoolId) {
        $userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
        $newPassword = trim($_POST['new_password'] ?? '');
        
        if ($userId && !empty($newPassword)) {
            // Verify user belongs to the school
            $check = $db->prepare("SELECT id, username FROM users WHERE id = ? AND school_id = ?");
            $check->execute([$userId, $schoolId]);
            $user = $check->fetch(PDO::FETCH_ASSOC);
            
            if ($user) {
                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmt = $db->prepare("UPDATE users SET password = ?, must_change_password = 1 WHERE id = ?");
                $stmt->execute([$hashedPassword, $userId]);
                $message = "Password for user '" . htmlspecialchars($user['username']) . "' has been reset successfully and forced to change on next login.";
                $messageType = 'success';
            } else {
                $message = 'User not found or does not belong to this school.';
                $messageType = 'danger';
            }
        } else {
            $message = 'Invalid user or password.';
            $messageType = 'danger';
        }
    }
}

// ── Fetch stats ───────────────────────────────────────────────────────────────
$totalSchools   = $db->query("SELECT COUNT(*) FROM school_info")->fetchColumn();
$pendingSchools = $db->query("SELECT COUNT(*) FROM school_info WHERE is_approved = 0")->fetchColumn();
$paidSchools    = $db->query("SELECT COUNT(*) FROM school_info WHERE is_paid = 1")->fetchColumn();
$lockedSchools  = $db->query("SELECT COUNT(*) FROM school_info WHERE is_locked = 1")->fetchColumn();
$totalUsers     = $db->query("SELECT COUNT(*) FROM users WHERE user_type != 'developer'")->fetchColumn();

// ── Fetch all schools ─────────────────────────────────────────────────────────
$filter = $_GET['filter'] ?? 'all';
$whereClause = match($filter) {
    'pending' => 'WHERE si.is_approved = 0',
    'paid'    => 'WHERE si.is_paid = 1',
    'locked'  => 'WHERE si.is_locked = 1',
    'trial'   => 'WHERE si.is_approved = 1 AND si.is_paid = 0',
    default   => '',
};

$schools = $db->query("SELECT si.*,
    (SELECT COUNT(*) FROM users u WHERE u.school_id = si.id AND u.user_type != 'developer') AS user_count,
    (SELECT COUNT(*) FROM students s JOIN classes c ON s.class_id = c.id WHERE c.school_id = si.id) AS student_count
    FROM school_info si $whereClause ORDER BY si.registration_date DESC, si.id DESC")->fetchAll(PDO::FETCH_ASSOC);

// ── Active global message ─────────────────────────────────────────────────────
$activeMsg = $db->query("SELECT message FROM global_messages WHERE is_active = 1 ORDER BY created_at DESC LIMIT 1")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Developer Dashboard – SBA System</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#0f172a;color:#e2e8f0;min-height:100vh}
a{color:inherit;text-decoration:none}

/* ── Top bar ── */
.topbar{background:#1e293b;border-bottom:1px solid #334155;padding:14px 24px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:100}
.topbar-brand{font-size:1.15rem;font-weight:700;color:#f8fafc;display:flex;align-items:center;gap:8px}
.topbar-brand span{background:linear-gradient(135deg,#6366f1,#8b5cf6);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.topbar-actions{display:flex;align-items:center;gap:12px;font-size:.875rem;color:#94a3b8}
.btn-logout{padding:7px 14px;border-radius:6px;background:#ef4444;color:#fff;border:none;cursor:pointer;font-size:.8rem;font-weight:600}
.btn-logout:hover{background:#dc2626}

/* ── Layout ── */
.container{max-width:1400px;margin:0 auto;padding:24px 20px}

/* ── Flash ── */
.flash{padding:12px 18px;border-radius:8px;margin-bottom:20px;font-size:.9rem;font-weight:500}
.flash.success{background:#14532d;color:#86efac;border:1px solid #166534}
.flash.warning{background:#451a03;color:#fcd34d;border:1px solid #92400e}
.flash.danger {background:#450a0a;color:#fca5a5;border:1px solid #7f1d1d}
.flash.info   {background:#0c1a2e;color:#7dd3fc;border:1px solid #1e40af}

/* ── Stats ── */
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin-bottom:28px}
.stat-card{background:#1e293b;border:1px solid #334155;border-radius:12px;padding:18px 20px;text-align:center}
.stat-card .num{font-size:2rem;font-weight:800;line-height:1}
.stat-card .lbl{font-size:.75rem;color:#94a3b8;margin-top:6px;text-transform:uppercase;letter-spacing:.05em}
.stat-card.blue  .num{color:#60a5fa}
.stat-card.yellow .num{color:#fcd34d}
.stat-card.green .num{color:#4ade80}
.stat-card.red   .num{color:#f87171}
.stat-card.purple .num{color:#c084fc}

/* ── Sections ── */
.section-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px}
.section-title{font-size:1rem;font-weight:700;color:#f1f5f9}
.card{background:#1e293b;border:1px solid #334155;border-radius:12px;padding:20px;margin-bottom:24px}

/* ── Filters ── */
.filter-bar{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:18px}
.filter-btn{padding:6px 16px;border-radius:20px;font-size:.8rem;font-weight:600;border:1px solid #475569;color:#94a3b8;background:transparent;cursor:pointer;transition:all .15s}
.filter-btn.active,.filter-btn:hover{background:#6366f1;border-color:#6366f1;color:#fff}

/* ── Table ── */
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:.82rem}
th{text-align:left;padding:10px 12px;color:#64748b;font-weight:600;text-transform:uppercase;font-size:.72rem;letter-spacing:.05em;border-bottom:1px solid #334155;white-space:nowrap}
td{padding:10px 12px;border-bottom:1px solid #1e293b;vertical-align:middle}
tr:last-child td{border-bottom:none}
tr:hover td{background:#1a2840}

/* ── Badges ── */
.badge{display:inline-block;padding:3px 9px;border-radius:20px;font-size:.72rem;font-weight:700;letter-spacing:.03em}
.badge-green {background:#14532d;color:#4ade80}
.badge-red   {background:#450a0a;color:#f87171}
.badge-yellow{background:#422006;color:#fcd34d}
.badge-blue  {background:#1e3a5f;color:#60a5fa}
.badge-gray  {background:#1e293b;color:#94a3b8}

/* ── Action buttons ── */
.action-group{display:flex;gap:5px;flex-wrap:wrap}
.btn{padding:5px 10px;border-radius:5px;border:none;cursor:pointer;font-size:.73rem;font-weight:600;transition:opacity .15s}
.btn:hover{opacity:.85}
.btn-green  {background:#16a34a;color:#fff}
.btn-red    {background:#dc2626;color:#fff}
.btn-yellow {background:#d97706;color:#fff}
.btn-blue   {background:#2563eb;color:#fff}
.btn-purple {background:#8b5cf6;color:#fff}
.btn-gray   {background:#475569;color:#fff}

/* ── Global message form ── */
.msg-form{display:flex;flex-direction:column;gap:10px}
.msg-form textarea{background:#0f172a;border:1px solid #475569;border-radius:8px;padding:10px;color:#e2e8f0;font-size:.875rem;resize:vertical;min-height:80px}
.msg-form textarea:focus{outline:none;border-color:#6366f1}
.active-msg-box{background:#1e3a5f;border:1px solid #2563eb;border-radius:8px;padding:12px 16px;font-size:.875rem;color:#bfdbfe;margin-bottom:12px;position:relative}
.active-msg-box strong{display:block;font-size:.72rem;color:#60a5fa;margin-bottom:4px;text-transform:uppercase;letter-spacing:.05em}

/* ── Lock modal ── */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:200;align-items:center;justify-content:center}
.modal-overlay.open{display:flex}
.modal{background:#1e293b;border:1px solid #475569;border-radius:14px;padding:28px 26px;width:100%;max-width:440px}
.modal h3{font-size:1rem;font-weight:700;margin-bottom:14px;color:#f1f5f9}
.modal input[type=text],.modal input[type=password],.modal select,.modal textarea{width:100%;background:#0f172a;border:1px solid #475569;border-radius:7px;padding:9px 12px;color:#e2e8f0;font-size:.875rem;margin-bottom:14px}
.modal input:focus,.modal select:focus,.modal textarea:focus{outline:none;border-color:#6366f1}
.modal-actions{display:flex;gap:8px;justify-content:flex-end}

@media(max-width:640px){
  .stats-grid{grid-template-columns:repeat(2,1fr)}
  .action-group{flex-direction:column}
  .topbar{flex-direction:column;gap:10px;align-items:flex-start}
}
</style>
</head>
<body>

<!-- Top bar -->
<div class="topbar">
  <div class="topbar-brand">
    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#6366f1" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg>
    <span>Developer</span> Dashboard
  </div>
  <div class="topbar-actions">
    <span>👤 <?php echo htmlspecialchars($_SESSION['username'] ?? 'Developer'); ?></span>
    <form method="post" action="logout.php" style="display:inline">
      <button type="submit" class="btn-logout">Log Out</button>
    </form>
  </div>
</div>

<div class="container">

<?php if ($message): ?>
<div class="flash <?php echo htmlspecialchars($messageType); ?>"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<!-- Stats -->
<div class="stats-grid">
  <div class="stat-card blue">
    <div class="num"><?php echo $totalSchools; ?></div>
    <div class="lbl">Total Schools</div>
  </div>
  <div class="stat-card yellow">
    <div class="num"><?php echo $pendingSchools; ?></div>
    <div class="lbl">Pending Approval</div>
  </div>
  <div class="stat-card green">
    <div class="num"><?php echo $paidSchools; ?></div>
    <div class="lbl">Paid Schools</div>
  </div>
  <div class="stat-card red">
    <div class="num"><?php echo $lockedSchools; ?></div>
    <div class="lbl">Locked</div>
  </div>
  <div class="stat-card purple">
    <div class="num"><?php echo $totalUsers; ?></div>
    <div class="lbl">Total Users</div>
  </div>
</div>

<!-- Global message -->
<div class="card">
  <div class="section-header">
    <div class="section-title">📢 Global Message (shown to all schools)</div>
  </div>

  <?php if ($activeMsg): ?>
  <div class="active-msg-box">
    <strong>Current active message</strong>
    <?php echo htmlspecialchars($activeMsg); ?>
  </div>
  <form method="post" style="display:inline">
    <input type="hidden" name="action" value="clear_global_message">
    <button type="submit" class="btn btn-gray" style="margin-bottom:14px">Clear Message</button>
  </form>
  <?php endif; ?>

  <form method="post" class="msg-form">
    <input type="hidden" name="action" value="send_global_message">
    <textarea name="global_message" placeholder="Type a message to broadcast to all school dashboards…" required></textarea>
    <div>
      <button type="submit" class="btn btn-blue">Send Message</button>
    </div>
  </form>
</div>

<!-- Schools table -->
<div class="card">
  <div class="section-header">
    <div class="section-title">🏫 Registered Schools</div>
  </div>

  <!-- Filter tabs -->
  <div class="filter-bar">
    <?php
    $filters = ['all' => 'All', 'pending' => 'Pending', 'trial' => 'On Trial', 'paid' => 'Paid', 'locked' => 'Locked'];
    foreach ($filters as $key => $label):
    ?>
    <a href="?filter=<?php echo $key; ?>">
      <span class="filter-btn <?php echo ($filter === $key) ? 'active' : ''; ?>"><?php echo $label; ?></span>
    </a>
    <?php endforeach; ?>
  </div>

  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>School Name</th>
          <th>Location</th>
          <th>Contact</th>
          <th>Registered</th>
          <th>Trial Ends</th>
          <th>Users</th>
          <th>Students</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($schools)): ?>
        <tr><td colspan="10" style="text-align:center;color:#64748b;padding:30px">No schools found.</td></tr>
      <?php endif; ?>
      <?php foreach ($schools as $s):
        $isApproved = (bool)($s['is_approved'] ?? 0);
        $isPaid     = (bool)($s['is_paid'] ?? 0);
        $isLocked   = (bool)($s['is_locked'] ?? 0);
        $trialEnd   = $s['trial_end_date'] ?? null;
        $trialExpired = $trialEnd && strtotime($trialEnd) < time();
      ?>
        <tr>
          <td style="color:#64748b"><?php echo (int)$s['id']; ?></td>
          <td style="font-weight:600;color:#f1f5f9;max-width:200px"><?php echo htmlspecialchars($s['school_name'] ?? '—'); ?></td>
          <td style="color:#94a3b8"><?php echo htmlspecialchars($s['location'] ?? '—'); ?></td>
          <td style="color:#94a3b8">
            <?php echo htmlspecialchars($s['phone'] ?? ''); ?>
            <?php if (!empty($s['email'])): ?><br><span style="font-size:.72rem"><?php echo htmlspecialchars($s['email']); ?></span><?php endif; ?>
          </td>
          <td style="color:#94a3b8;white-space:nowrap">
            <?php echo $s['registration_date'] ? date('d M Y', strtotime($s['registration_date'])) : '—'; ?>
          </td>
          <td style="white-space:nowrap">
            <?php if ($isPaid): ?>
              <span class="badge badge-green">Forever</span>
            <?php elseif ($trialEnd): ?>
              <?php if ($trialExpired): ?>
                <span class="badge badge-red">Expired</span>
              <?php else: ?>
                <span class="badge badge-yellow"><?php echo date('d M', strtotime($trialEnd)); ?></span>
              <?php endif; ?>
            <?php else: ?>
              <span class="badge badge-gray">—</span>
            <?php endif; ?>
          </td>
          <td style="text-align:center"><?php echo (int)$s['user_count']; ?></td>
          <td style="text-align:center"><?php echo (int)$s['student_count']; ?></td>
          <td>
            <?php if ($isLocked): ?>
              <span class="badge badge-red">Locked</span>
            <?php elseif (!$isApproved): ?>
              <span class="badge badge-yellow">Pending</span>
            <?php elseif ($isPaid): ?>
              <span class="badge badge-green">Paid</span>
            <?php else: ?>
              <span class="badge badge-blue">Trial</span>
            <?php endif; ?>
          </td>
          <td>
            <div class="action-group">
              <?php if (!$isApproved): ?>
                <form method="post" style="display:inline">
                  <input type="hidden" name="action" value="approve">
                  <input type="hidden" name="school_id" value="<?php echo (int)$s['id']; ?>">
                  <button type="submit" class="btn btn-green">Approve</button>
                </form>
              <?php else: ?>
                <form method="post" style="display:inline" onsubmit="return confirm('Revoke approval?')">
                  <input type="hidden" name="action" value="unapprove">
                  <input type="hidden" name="school_id" value="<?php echo (int)$s['id']; ?>">
                  <button type="submit" class="btn btn-gray">Revoke</button>
                </form>
              <?php endif; ?>

              <?php if (!$isPaid): ?>
                <form method="post" style="display:inline">
                  <input type="hidden" name="action" value="mark_paid">
                  <input type="hidden" name="school_id" value="<?php echo (int)$s['id']; ?>">
                  <button type="submit" class="btn btn-blue">Mark Paid</button>
                </form>
              <?php else: ?>
                <form method="post" style="display:inline" onsubmit="return confirm('Mark as unpaid?')">
                  <input type="hidden" name="action" value="mark_unpaid">
                  <input type="hidden" name="school_id" value="<?php echo (int)$s['id']; ?>">
                  <button type="submit" class="btn btn-gray">Unpaid</button>
                </form>
              <?php endif; ?>

              <?php if (!$isLocked): ?>
                <button type="button" class="btn btn-yellow"
                  onclick="openLockModal(<?php echo (int)$s['id']; ?>, '<?php echo htmlspecialchars(addslashes($s['school_name'] ?? ''), ENT_QUOTES); ?>')">
                  Lock
                </button>
              <?php else: ?>
                <form method="post" style="display:inline">
                  <input type="hidden" name="action" value="unlock">
                  <input type="hidden" name="school_id" value="<?php echo (int)$s['id']; ?>">
                  <button type="submit" class="btn btn-green">Unlock</button>
                </form>
                <?php if (!empty($s['lock_reason'])): ?>
                  <span title="<?php echo htmlspecialchars($s['lock_reason']); ?>" style="cursor:help;color:#fcd34d">ℹ</span>
                <?php endif; ?>
              <?php endif; ?>

              <button type="button" class="btn btn-purple"
                onclick="openResetPasswordModal(<?php echo (int)$s['id']; ?>, '<?php echo htmlspecialchars(addslashes($s['school_name'] ?? ''), ENT_QUOTES); ?>')">
                Reset Pwd
              </button>

              <form method="post" style="display:inline"
                onsubmit="return confirm('PERMANENTLY delete this school and all its data?')">
                <input type="hidden" name="action" value="delete_school">
                <input type="hidden" name="school_id" value="<?php echo (int)$s['id']; ?>">
                <button type="submit" class="btn btn-red">Delete</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

</div><!-- /container -->

<!-- Lock modal -->
<div class="modal-overlay" id="lockModal">
  <div class="modal">
    <h3>🔒 Lock School: <span id="lockSchoolName"></span></h3>
    <form method="post">
      <input type="hidden" name="action" value="lock">
      <input type="hidden" name="school_id" id="lockSchoolId">
      <textarea name="lock_reason" placeholder="Reason for locking (optional)…" rows="3"></textarea>
      <div class="modal-actions">
        <button type="button" class="btn btn-gray" onclick="closeLockModal()">Cancel</button>
        <button type="submit" class="btn btn-yellow">Confirm Lock</button>
      </div>
    </form>
  </div>
</div>

<!-- Reset Password modal -->
<div class="modal-overlay" id="resetPasswordModal">
  <div class="modal">
    <h3>🔑 Reset Password: <span id="resetSchoolName"></span></h3>
    <form method="post" id="resetPasswordForm">
      <input type="hidden" name="action" value="reset_password">
      <input type="hidden" name="school_id" id="resetSchoolId">
      
      <div style="margin-bottom: 14px;">
        <label for="resetUserId" style="display:block;margin-bottom:6px;font-size:.85rem;color:#94a3b8">Select User</label>
        <select name="user_id" id="resetUserId" required>
          <option value="">Loading users...</option>
        </select>
      </div>

      <div style="margin-bottom: 14px;">
        <label for="newPassword" style="display:block;margin-bottom:6px;font-size:.85rem;color:#94a3b8">New Password</label>
        <input type="password" name="new_password" id="newPassword" placeholder="Enter new password…" required minlength="6">
        <small style="display:block;margin-top:4px;color:#94a3b8;font-size:.75rem;">Minimum 6 characters. Will force change on next login.</small>
      </div>

      <div class="modal-actions">
        <button type="button" class="btn btn-gray" onclick="closeResetPasswordModal()">Cancel</button>
        <button type="submit" class="btn btn-purple">Reset Password</button>
      </div>
    </form>
  </div>
</div>

<script>
function openLockModal(id, name) {
  document.getElementById('lockSchoolId').value = id;
  document.getElementById('lockSchoolName').textContent = name;
  document.getElementById('lockModal').classList.add('open');
}
function closeLockModal() {
  document.getElementById('lockModal').classList.remove('open');
}
document.getElementById('lockModal').addEventListener('click', function(e) {
  if (e.target === this) closeLockModal();
});

function openResetPasswordModal(id, name) {
  document.getElementById('resetSchoolId').value = id;
  document.getElementById('resetSchoolName').textContent = name;
  
  const userSelect = document.getElementById('resetUserId');
  userSelect.innerHTML = '<option value="">Loading users...</option>';
  document.getElementById('newPassword').value = '';
  document.getElementById('resetPasswordModal').classList.add('open');
  
  fetch(`developer_dashboard.php?get_users=1&school_id=${id}`)
    .then(response => response.json())
    .then(data => {
      if (data.error) {
        userSelect.innerHTML = `<option value="">Error: ${data.error}</option>`;
        return;
      }
      
      if (data.length === 0) {
        userSelect.innerHTML = '<option value="">No users found for this school</option>';
        return;
      }
      
      userSelect.innerHTML = '';
      data.forEach(user => {
        const option = document.createElement('option');
        option.value = user.id;
        const roleStr = user.role ? ` / ${user.role}` : '';
        option.textContent = `${user.username} (${user.full_name} - ${user.user_type}${roleStr})`;
        userSelect.appendChild(option);
      });
    })
    .catch(err => {
      console.error(err);
      userSelect.innerHTML = '<option value="">Failed to load users</option>';
    });
}

function closeResetPasswordModal() {
  document.getElementById('resetPasswordModal').classList.remove('open');
}

document.getElementById('resetPasswordModal').addEventListener('click', function(e) {
  if (e.target === this) closeResetPasswordModal();
});
</script>
</body>
</html>
