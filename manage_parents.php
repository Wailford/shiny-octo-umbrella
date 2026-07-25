<?php
/**
 * Manage Parent Contacts
 * Admin-only page to add / edit / delete parent contacts and link them to students.
 */
$pageTitle = 'Manage Parents';
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/controllers/AuthController.php';

$auth = new Auth();
$auth->requireLogin();
$auth->requireAdmin();

require_once __DIR__ . '/config/database.php';
$db        = Database::getInstance()->getConnection();
$schoolId  = $_SESSION['school_id'];
$message   = '';
$error     = '';

// ─────────────────────────────────────────── POST handlers ──────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Add / Edit parent ──────────────────────────────────────────────────
    if ($action === 'save_parent') {
        $parentId    = (int)($_POST['parent_id'] ?? 0);
        $fullName    = trim($_POST['full_name']    ?? '');
        $relationship= trim($_POST['relationship'] ?? 'Parent');
        $phone       = trim($_POST['phone']        ?? '');
        $whatsapp    = trim($_POST['whatsapp_number'] ?? '');
        $email       = trim($_POST['email']        ?? '');
        $notes       = trim($_POST['notes']        ?? '');

        if ($fullName === '' || $phone === '') {
            $error = 'Full name and phone number are required.';
        } else {
            if ($parentId > 0) {
                // Update
                $stmt = $db->prepare("UPDATE parent_contacts SET full_name=?, relationship=?, phone=?, whatsapp_number=?, email=?, notes=?, updated_at=NOW() WHERE id=? AND school_id=?");
                $stmt->execute([$fullName, $relationship, $phone, $whatsapp ?: null, $email ?: null, $notes ?: null, $parentId, $schoolId]);
                $message = 'Parent contact updated successfully.';
            } else {
                // Insert
                $stmt = $db->prepare("INSERT INTO parent_contacts (school_id, full_name, relationship, phone, whatsapp_number, email, notes) VALUES (?,?,?,?,?,?,?)");
                $stmt->execute([$schoolId, $fullName, $relationship, $phone, $whatsapp ?: null, $email ?: null, $notes ?: null]);
                $parentId = (int)$db->lastInsertId();
                $message  = 'Parent contact added successfully.';
            }
        }
    }

    // ── Delete parent ──────────────────────────────────────────────────────
    if ($action === 'delete_parent') {
        $parentId = (int)($_POST['parent_id'] ?? 0);
        $stmt = $db->prepare("DELETE FROM parent_contacts WHERE id=? AND school_id=?");
        $stmt->execute([$parentId, $schoolId]);
        $message = 'Parent contact deleted.';
    }

    // ── Link student to parent ─────────────────────────────────────────────
    if ($action === 'link_student') {
        $parentId  = (int)($_POST['parent_id']  ?? 0);
        $studentId = (int)($_POST['student_id'] ?? 0);
        if ($parentId && $studentId) {
            // Verify student belongs to this school
            $chk = $db->prepare("SELECT id FROM students s JOIN classes c ON s.class_id=c.id WHERE s.id=? AND c.school_id=?");
            $chk->execute([$studentId, $schoolId]);
            if ($chk->fetch()) {
                $ins = $db->prepare("INSERT IGNORE INTO parent_student_links (parent_id, student_id) VALUES (?,?)");
                $ins->execute([$parentId, $studentId]);
                $message = 'Student linked to parent.';
            } else {
                $error = 'Student not found in this school.';
            }
        }
    }

    // ── Unlink student from parent ─────────────────────────────────────────
    if ($action === 'unlink_student') {
        $linkId = (int)($_POST['link_id'] ?? 0);
        $stmt = $db->prepare("DELETE psl FROM parent_student_links psl JOIN parent_contacts pc ON psl.parent_id=pc.id WHERE psl.id=? AND pc.school_id=?");
        $stmt->execute([$linkId, $schoolId]);
        $message = 'Student unlinked from parent.';
    }
}

// ─────────────────────────────────────────── Fetch data ─────────────────────
// All parents for this school, with count of linked children
$stmtParents = $db->prepare("
    SELECT pc.*, COUNT(psl.id) as child_count
    FROM parent_contacts pc
    LEFT JOIN parent_student_links psl ON psl.parent_id = pc.id
    WHERE pc.school_id = ?
    GROUP BY pc.id
    ORDER BY pc.full_name
");
$stmtParents->execute([$schoolId]);
$parents = $stmtParents->fetchAll(PDO::FETCH_ASSOC);

// All classes + students for the link-student dropdown
$stmtClasses = $db->prepare("SELECT id, class_name FROM classes WHERE school_id=? ORDER BY class_name");
$stmtClasses->execute([$schoolId]);
$classes = $stmtClasses->fetchAll(PDO::FETCH_ASSOC);

// Students indexed by class for the JS picker
$allStudents = [];
foreach ($classes as $cls) {
    $stmtStu = $db->prepare("SELECT id, student_name FROM students WHERE class_id=? ORDER BY student_name");
    $stmtStu->execute([$cls['id']]);
    $allStudents[$cls['id']] = $stmtStu->fetchAll(PDO::FETCH_ASSOC);
}

// Edit mode: fetch single parent if ?edit=ID
$editParent = null;
if (isset($_GET['edit']) && (int)$_GET['edit'] > 0) {
    $editId = (int)$_GET['edit'];
    $stmtEP = $db->prepare("SELECT * FROM parent_contacts WHERE id=? AND school_id=?");
    $stmtEP->execute([$editId, $schoolId]);
    $editParent = $stmtEP->fetch(PDO::FETCH_ASSOC);
}

// View parent children: ?view=ID
$viewParent = null;
$parentChildren = [];
if (isset($_GET['view']) && (int)$_GET['view'] > 0) {
    $viewId = (int)$_GET['view'];
    $stmtVP = $db->prepare("SELECT * FROM parent_contacts WHERE id=? AND school_id=?");
    $stmtVP->execute([$viewId, $schoolId]);
    $viewParent = $stmtVP->fetch(PDO::FETCH_ASSOC);

    if ($viewParent) {
        $stmtCh = $db->prepare("
            SELECT psl.id as link_id, s.id as student_id, s.student_name, c.class_name
            FROM parent_student_links psl
            JOIN students s ON s.id = psl.student_id
            JOIN classes  c ON c.id = s.class_id
            WHERE psl.parent_id = ?
            ORDER BY s.student_name
        ");
        $stmtCh->execute([$viewId]);
        $parentChildren = $stmtCh->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>
<?php include __DIR__ . '/components/header.php'; ?>
<style>
    .modal-form .mf-group { margin-bottom: 1rem; }
    .modal-form .mf-group:last-of-type { margin-bottom: 1.25rem; }
    .modal-form label { display: block; font-weight: 600; margin-bottom: 0.35rem; color: #374151; font-size: 0.875rem; }
    .modal-form label small { font-weight: 400; color: #718096; }
    .modal-form .form-control { border-color: #cbd5e0; }
    .modal-form .form-control:focus { border-color: #667eea; }
    .modal-cancel { padding: 0.5rem 1rem; border: 1px solid #cbd5e0; border-radius: 6px; background: white; cursor: pointer; font-size: 0.875rem; color: #4a5568; }
    .modal-cancel:hover { background: #f7fafc; }

    @media (max-width: 768px) {
        /* Table scroll */
        .container > .card, .container > div { overflow-x: auto; -webkit-overflow-scrolling: touch; }

        /* Page header row */
        div[style*="justify-content: space-between"] {
            flex-direction: column !important;
            align-items: flex-start !important;
            gap: 0.75rem !important;
        }
        div[style*="justify-content: space-between"] button { width: 100%; }

        /* Modal form inputs */
        .modal-form .form-control { font-size: 16px !important; min-height: 48px; }
        .modal-form select         { font-size: 16px !important; min-height: 48px; }
        .modal-form textarea       { font-size: 16px !important; }

        /* Modal action buttons */
        div[style*="display:flex"][style*="gap:0.75rem"],
        div[style*="display: flex"][style*="gap: 0.75rem"] {
            flex-direction: column !important;
        }
        div[style*="display:flex"][style*="gap:0.75rem"] button,
        div[style*="display:flex"][style*="gap:0.75rem"] a { width: 100% !important; }

        .modal-cancel { width: 100% !important; padding: 0.75rem; font-size: 1rem; }
    }
</style>

<div class="container">

    <?php if ($message): ?>
        <div class="alert alert-success">&#10003; <?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error">&#10005; <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <!-- ── Page header ──────────────────────────────────────────────────── -->
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;flex-wrap:wrap;gap:0.75rem;">
        <div>
            <h1 style="font-size:1.5rem;font-weight:700;color:#2d3748;">👨‍👩‍👧 Parent Contacts</h1>
            <p style="color:#718096;margin-top:0.25rem;">Manage parent/guardian contacts and link them to their ward(s).</p>
        </div>
        <button onclick="document.getElementById('addParentModal').style.display='flex'" class="btn btn-primary">
            + Add Parent / Guardian
        </button>
    </div>

    <!-- ── Add / Edit parent modal ──────────────────────────────────────── -->
    <div id="addParentModal" style="display:<?php echo $editParent ? 'flex' : 'none'; ?>;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;padding:1rem;">
        <div style="background:white;border-radius:10px;padding:1.5rem;width:100%;max-width:520px;max-height:90vh;overflow-y:auto;">
            <h2 style="font-size:1.2rem;font-weight:700;margin-bottom:1.25rem;">
                <?php echo $editParent ? '✏️ Edit Parent Contact' : '➕ Add Parent / Guardian'; ?>
            </h2>
            <form method="POST" class="modal-form">
                <input type="hidden" name="action"    value="save_parent">
                <input type="hidden" name="parent_id" value="<?php echo $editParent ? (int)$editParent['id'] : 0; ?>">

                <div class="mf-group">
                    <label>Full Name <span style="color:#e53e3e">*</span></label>
                    <input type="text" name="full_name" class="form-control" required
                           value="<?php echo htmlspecialchars($editParent['full_name'] ?? ''); ?>"
                           placeholder="e.g. Ama Owusu">
                </div>

                <div class="mf-group">
                    <label>Relationship</label>
                    <select name="relationship" class="form-control">
                        <?php foreach (['Father','Mother','Parent','Guardian','Uncle','Aunt','Grandparent','Other'] as $rel): ?>
                            <option value="<?php echo $rel; ?>" <?php echo ($editParent['relationship'] ?? 'Parent') === $rel ? 'selected' : ''; ?>><?php echo $rel; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mf-group">
                    <label>Phone Number <span style="color:#e53e3e">*</span> <small>(used for SMS)</small></label>
                    <input type="tel" name="phone" class="form-control" required
                           value="<?php echo htmlspecialchars($editParent['phone'] ?? ''); ?>"
                           placeholder="e.g. 0244123456">
                </div>

                <div class="mf-group">
                    <label>WhatsApp Number <small>(leave blank to use phone)</small></label>
                    <input type="tel" name="whatsapp_number" class="form-control"
                           value="<?php echo htmlspecialchars($editParent['whatsapp_number'] ?? ''); ?>"
                           placeholder="e.g. 0244123456">
                </div>

                <div class="mf-group">
                    <label>Email Address <small>(for email delivery)</small></label>
                    <input type="email" name="email" class="form-control"
                           value="<?php echo htmlspecialchars($editParent['email'] ?? ''); ?>"
                           placeholder="e.g. parent@example.com">
                </div>

                <div class="mf-group">
                    <label>Notes</label>
                    <textarea name="notes" rows="2" class="form-control" style="height:auto;resize:vertical;"><?php echo htmlspecialchars($editParent['notes'] ?? ''); ?></textarea>
                </div>

                <div style="display:flex;gap:0.75rem;justify-content:flex-end;">
                    <button type="button" class="modal-cancel" onclick="closeModal('addParentModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <?php echo $editParent ? '&#128190; Save Changes' : '&#43; Add Parent'; ?>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ── Link student modal ───────────────────────────────────────────── -->
    <div id="linkModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;padding:1rem;">
        <div style="background:white;border-radius:10px;padding:1.5rem;width:100%;max-width:460px;">
            <h2 style="font-size:1.1rem;font-weight:700;margin-bottom:1rem;">🔗 Link Student to Parent</h2>
            <form method="POST" id="linkForm">
                <input type="hidden" name="action"    value="link_student">
                <input type="hidden" name="parent_id" id="linkParentId">

                <div style="margin-bottom:1rem;">
                    <label style="display:block;font-weight:600;margin-bottom:0.35rem;font-size:.875rem;color:#374151;">Select Class</label>
                    <select id="classSelect" onchange="updateStudentDropdown(this.value)" class="form-control" style="border-color:#cbd5e0;">
                        <option value="">-- Choose class --</option>
                        <?php foreach ($classes as $cls): ?>
                            <option value="<?php echo $cls['id']; ?>"><?php echo htmlspecialchars($cls['class_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="margin-bottom:1.25rem;">
                    <label style="display:block;font-weight:600;margin-bottom:0.35rem;font-size:.875rem;color:#374151;">Select Student</label>
                    <select name="student_id" id="studentSelect" required class="form-control" style="border-color:#cbd5e0;">
                        <option value="">-- Select class first --</option>
                    </select>
                </div>

                <div style="display:flex;gap:0.75rem;justify-content:flex-end;">
                    <button type="button" class="modal-cancel" onclick="closeModal('linkModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">🔗 Link Student</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ── View children panel (inline, collapsible) ────────────────────── -->
    <?php if ($viewParent): ?>
    <div class="card" style="border-left:4px solid #667eea;">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:1rem;gap:1rem;flex-wrap:wrap;">
            <div>
                <h2 style="font-size:1.1rem;font-weight:700;">
                    👁 Children of <?php echo htmlspecialchars($viewParent['full_name']); ?>
                </h2>
                <p style="color:#718096;font-size:0.9rem;margin-top:0.2rem;">
                    📞 <?php echo htmlspecialchars($viewParent['phone']); ?>
                    <?php if ($viewParent['email']): ?> &nbsp;|&nbsp; ✉️ <?php echo htmlspecialchars($viewParent['email']); ?><?php endif; ?>
                </p>
            </div>
            <div style="display:flex;gap:0.5rem;flex-wrap:wrap;">
                <button onclick="openLinkModal(<?php echo $viewParent['id']; ?>)" class="btn btn-primary" style="font-size:0.85rem;padding:0.4rem 0.8rem;">
                    + Link Another Student
                </button>
                <a href="manage_parents.php" style="padding:0.4rem 0.8rem;border:1px solid #cbd5e0;border-radius:6px;text-decoration:none;color:#2d3748;font-size:0.85rem;">
                    ✖ Close
                </a>
            </div>
        </div>

        <?php if (empty($parentChildren)): ?>
            <p style="color:#718096;">No students linked yet. Use the button above to link a ward.</p>
        <?php else: ?>
            <table style="width:100%;border-collapse:collapse;font-size:0.9rem;">
                <thead>
                    <tr style="background:#f7fafc;">
                        <th style="padding:0.6rem 0.75rem;text-align:left;border-bottom:1px solid #e2e8f0;">Student Name</th>
                        <th style="padding:0.6rem 0.75rem;text-align:left;border-bottom:1px solid #e2e8f0;">Class</th>
                        <th style="padding:0.6rem 0.75rem;text-align:center;border-bottom:1px solid #e2e8f0;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($parentChildren as $child): ?>
                        <tr style="border-bottom:1px solid #f0f0f0;">
                            <td style="padding:0.6rem 0.75rem;"><?php echo htmlspecialchars($child['student_name']); ?></td>
                            <td style="padding:0.6rem 0.75rem;"><?php echo htmlspecialchars($child['class_name']); ?></td>
                            <td style="padding:0.6rem 0.75rem;text-align:center;">
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Unlink this student from the parent?');">
                                    <input type="hidden" name="action"  value="unlink_student">
                                    <input type="hidden" name="link_id" value="<?php echo $child['link_id']; ?>">
                                    <button type="submit" style="background:none;border:none;color:#e53e3e;cursor:pointer;font-size:0.85rem;">🗑 Unlink</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ── Parents list ─────────────────────────────────────────────────── -->
    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;gap:0.5rem;flex-wrap:wrap;">
            <h2 style="font-size:1.1rem;font-weight:600;">All Parents / Guardians</h2>
            <input type="text" id="parentSearch" placeholder="🔍 Search by name or phone…"
                   onkeyup="filterParents()"
                   class="form-control" style="width:220px;">
        </div>

        <?php if (empty($parents)): ?>
            <p style="color:#718096;text-align:center;padding:2rem;">No parent contacts yet. Click <strong>+ Add Parent / Guardian</strong> to get started.</p>
        <?php else: ?>
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:0.9rem;" id="parentsTable">
                <thead>
                    <tr style="background:#f7fafc;">
                        <th style="padding:0.6rem 0.75rem;text-align:left;border-bottom:1px solid #e2e8f0;">Name</th>
                        <th style="padding:0.6rem 0.75rem;text-align:left;border-bottom:1px solid #e2e8f0;">Relationship</th>
                        <th style="padding:0.6rem 0.75rem;text-align:left;border-bottom:1px solid #e2e8f0;">Phone</th>
                        <th style="padding:0.6rem 0.75rem;text-align:left;border-bottom:1px solid #e2e8f0;">WhatsApp</th>
                        <th style="padding:0.6rem 0.75rem;text-align:left;border-bottom:1px solid #e2e8f0;">Email</th>
                        <th style="padding:0.6rem 0.75rem;text-align:center;border-bottom:1px solid #e2e8f0;">Children</th>
                        <th style="padding:0.6rem 0.75rem;text-align:center;border-bottom:1px solid #e2e8f0;">Actions</th>
                    </tr>
                </thead>
                <tbody id="parentsBody">
                    <?php foreach ($parents as $p): ?>
                    <tr class="parent-row" style="border-bottom:1px solid #f0f0f0;">
                        <td style="padding:0.65rem 0.75rem;font-weight:500;"><?php echo htmlspecialchars($p['full_name']); ?></td>
                        <td style="padding:0.65rem 0.75rem;color:#718096;"><?php echo htmlspecialchars($p['relationship']); ?></td>
                        <td style="padding:0.65rem 0.75rem;"><?php echo htmlspecialchars($p['phone']); ?></td>
                        <td style="padding:0.65rem 0.75rem;color:#718096;"><?php echo htmlspecialchars($p['whatsapp_number'] ?? '—'); ?></td>
                        <td style="padding:0.65rem 0.75rem;font-size:0.85rem;color:#718096;"><?php echo htmlspecialchars($p['email'] ?? '—'); ?></td>
                        <td style="padding:0.65rem 0.75rem;text-align:center;">
                            <a href="manage_parents.php?view=<?php echo $p['id']; ?>"
                               style="display:inline-block;background:<?php echo $p['child_count'] > 0 ? '#ebf8ff' : '#f7fafc'; ?>;color:<?php echo $p['child_count'] > 0 ? '#2b6cb0' : '#718096'; ?>;padding:0.2rem 0.6rem;border-radius:12px;font-size:0.8rem;text-decoration:none;font-weight:600;">
                               👦 <?php echo $p['child_count']; ?> ward<?php echo $p['child_count'] != 1 ? 's' : ''; ?>
                            </a>
                        </td>
                        <td style="padding:0.65rem 0.75rem;text-align:center;white-space:nowrap;">
                            <a href="manage_parents.php?edit=<?php echo $p['id']; ?>"
                               style="margin-right:0.5rem;color:#667eea;text-decoration:none;font-size:0.85rem;">✏️ Edit</a>
                            <button onclick="openLinkModal(<?php echo $p['id']; ?>)"
                                    style="background:none;border:none;color:#38a169;cursor:pointer;font-size:0.85rem;margin-right:0.5rem;">🔗 Link</button>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this parent contact? All student links will also be removed.');">
                                <input type="hidden" name="action"    value="delete_parent">
                                <input type="hidden" name="parent_id" value="<?php echo $p['id']; ?>">
                                <button type="submit" style="background:none;border:none;color:#e53e3e;cursor:pointer;font-size:0.85rem;">🗑 Delete</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div><!-- /.container -->

<!-- Students JSON for JS picker -->
<script>
const allStudents = <?php echo json_encode($allStudents, JSON_UNESCAPED_UNICODE); ?>;

function openLinkModal(parentId) {
    document.getElementById('linkParentId').value = parentId;
    document.getElementById('classSelect').value  = '';
    document.getElementById('studentSelect').innerHTML = '<option value="">-- Select class first --</option>';
    document.getElementById('linkModal').style.display = 'flex';
}

function closeModal(id) {
    document.getElementById(id).style.display = 'none';
    <?php if ($editParent): ?>
    if (id === 'addParentModal') window.location.href = 'manage_parents.php';
    <?php endif; ?>
}

function updateStudentDropdown(classId) {
    const sel = document.getElementById('studentSelect');
    sel.innerHTML = '<option value="">-- Select student --</option>';
    if (!classId || !allStudents[classId]) return;
    allStudents[classId].forEach(s => {
        const opt = document.createElement('option');
        opt.value       = s.id;
        opt.textContent = s.student_name;
        sel.appendChild(opt);
    });
}

function filterParents() {
    const q = document.getElementById('parentSearch').value.toLowerCase();
    document.querySelectorAll('#parentsBody .parent-row').forEach(row => {
        const text = row.innerText.toLowerCase();
        row.style.display = text.includes(q) ? '' : 'none';
    });
}

// Close modals on backdrop click
['addParentModal','linkModal'].forEach(id => {
    document.getElementById(id).addEventListener('click', function(e) {
        if (e.target === this) closeModal(id);
    });
});
</script>

<?php include __DIR__ . '/components/footer.php'; ?>
