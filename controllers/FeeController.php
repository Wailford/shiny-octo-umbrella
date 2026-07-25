<?php
/**
 * FeeController
 * Manages fee structures, payments, and SMS notifications for fees.
 */

require_once __DIR__ . '/../config/database.php';

class FeeController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    // ─────────────────────────────── FEE STRUCTURES ──────────────────────────

    /** List all active fee structures for a school, optionally filtered. */
    public function getFeeStructures(int $schoolId, ?string $academicYear = null, ?string $term = null): array
    {
        $sql = "SELECT fs.*, c.class_name
                FROM fee_structures fs
                LEFT JOIN classes c ON fs.class_id = c.id
                WHERE fs.school_id = ?";
        $params = [$schoolId];

        if ($academicYear !== null) { $sql .= " AND fs.academic_year = ?"; $params[] = $academicYear; }
        if ($term !== null && $term !== 'all') { $sql .= " AND (fs.term = ? OR fs.term = 'all')"; $params[] = $term; }

        $sql .= " ORDER BY fs.academic_year DESC, fs.term, fs.fee_type, fs.fee_name";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Insert or update a fee structure. */
    public function saveFeeStructure(array $data, int $schoolId): array
    {
        $id           = isset($data['id']) ? (int)$data['id'] : 0;
        $feeName      = trim($data['fee_name']      ?? '');
        $feeType      = $data['fee_type']            ?? 'tuition';
        $amount       = (float)($data['amount']      ?? 0);
        $term         = $data['term']                ?? '1';
        $academicYear = trim($data['academic_year']  ?? '');
        $classId      = !empty($data['class_id'])    ? (int)$data['class_id'] : null;
        $notes        = trim($data['notes']          ?? '');
        $isActive     = isset($data['is_active'])    ? (int)(bool)$data['is_active'] : 1;

        if ($feeName === '' || $amount <= 0 || $academicYear === '') {
            return ['success' => false, 'error' => 'Fee name, amount and academic year are required.'];
        }

        $validTypes  = ['tuition','pta','sports','books','exam','uniform','other'];
        $validTerms  = ['1','2','3','all'];
        if (!in_array($feeType, $validTypes)) $feeType = 'other';
        if (!in_array($term, $validTerms))    $term    = '1';

        try {
            if ($id > 0) {
                // Verify ownership before update
                $chk = $this->db->prepare("SELECT id FROM fee_structures WHERE id = ? AND school_id = ?");
                $chk->execute([$id, $schoolId]);
                if (!$chk->fetch()) return ['success' => false, 'error' => 'Fee structure not found.'];

                $stmt = $this->db->prepare("UPDATE fee_structures SET
                    fee_name=?, fee_type=?, amount=?, term=?, academic_year=?,
                    class_id=?, notes=?, is_active=?, updated_at=NOW()
                    WHERE id=? AND school_id=?");
                $stmt->execute([$feeName, $feeType, $amount, $term, $academicYear,
                                $classId, $notes, $isActive, $id, $schoolId]);
            } else {
                $stmt = $this->db->prepare("INSERT INTO fee_structures
                    (school_id, fee_name, fee_type, amount, term, academic_year, class_id, notes, is_active)
                    VALUES (?,?,?,?,?,?,?,?,?)");
                $stmt->execute([$schoolId, $feeName, $feeType, $amount, $term,
                                $academicYear, $classId, $notes, $isActive]);
                $id = (int)$this->db->lastInsertId();
            }
            return ['success' => true, 'id' => $id];
        } catch (Exception $e) {
            error_log("saveFeeStructure: " . $e->getMessage());
            return ['success' => false, 'error' => 'Database error saving fee structure.'];
        }
    }

    /** Delete (or deactivate) a fee structure. Returns error if payments exist. */
    public function deleteFeeStructure(int $id, int $schoolId): array
    {
        $chk = $this->db->prepare("SELECT COUNT(*) FROM fee_payments WHERE fee_structure_id = ? AND school_id = ?");
        $chk->execute([$id, $schoolId]);
        if ((int)$chk->fetchColumn() > 0) {
            // Deactivate rather than hard-delete so payment history is preserved
            $stmt = $this->db->prepare("UPDATE fee_structures SET is_active=0 WHERE id=? AND school_id=?");
            $stmt->execute([$id, $schoolId]);
            return ['success' => true, 'message' => 'Fee structure deactivated (payments already recorded against it cannot be deleted).'];
        }
        $stmt = $this->db->prepare("DELETE FROM fee_structures WHERE id=? AND school_id=?");
        $stmt->execute([$id, $schoolId]);
        return ['success' => true, 'message' => 'Fee structure deleted.'];
    }

    // ─────────────────────────────── FEE SUMMARIES ───────────────────────────

    /** Get fee summary (total due / paid / balance / status) for every student in a class. */
    public function getClassFeesSummary(int $classId, int $schoolId, string $academicYear, string $term): array
    {
        // Students in the class
        $stmt = $this->db->prepare(
            "SELECT id, student_name, student_id as student_id_no, parent_phone, parent_name
             FROM students WHERE class_id = ? ORDER BY student_name"
        );
        $stmt->execute([$classId]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Applicable fee structures
        $structs = $this->getApplicableFeeStructures($classId, $schoolId, $academicYear, $term);
        $totalDueClass = array_sum(array_column($structs, 'amount'));

        $result = [];
        foreach ($students as $s) {
            $totalPaid = 0;
            if (!empty($structs)) {
                $ids  = implode(',', array_column($structs, 'id'));
                $pStmt = $this->db->prepare(
                    "SELECT COALESCE(SUM(amount_paid),0) as paid
                     FROM fee_payments
                     WHERE student_id = ? AND school_id = ? AND fee_structure_id IN ($ids)"
                );
                $pStmt->execute([$s['id'], $schoolId]);
                $totalPaid = (float)($pStmt->fetchColumn() ?? 0);
            }

            $balance = max(0, $totalDueClass - $totalPaid);
            $status  = $totalDueClass == 0
                ? 'no_fees'
                : ($totalPaid >= $totalDueClass ? 'paid' : ($totalPaid > 0 ? 'partial' : 'unpaid'));

            $result[] = [
                'student_id'     => $s['id'],
                'student_name'   => $s['student_name'],
                'student_id_no'  => $s['student_id_no'],
                'parent_phone'   => $s['parent_phone']  ?? '',
                'parent_name'    => $s['parent_name']   ?? '',
                'total_due'      => $totalDueClass,
                'total_paid'     => $totalPaid,
                'balance'        => $balance,
                'status'         => $status,
            ];
        }
        return $result;
    }

    /** Get full per-fee breakdown for one student (for payment modal). */
    public function getStudentFeeDetails(int $studentId, int $classId, int $schoolId, string $academicYear, string $term): array
    {
        // Student info
        $sStmt = $this->db->prepare("SELECT * FROM students WHERE id = ? AND class_id = ?");
        $sStmt->execute([$studentId, $classId]);
        $student = $sStmt->fetch(PDO::FETCH_ASSOC);
        if (!$student) return ['success' => false, 'error' => 'Student not found.'];

        $structs   = $this->getApplicableFeeStructures($classId, $schoolId, $academicYear, $term);
        $fees      = [];
        $totalDue  = 0;
        $totalPaid = 0;

        foreach ($structs as $fs) {
            $pStmt = $this->db->prepare(
                "SELECT COALESCE(SUM(amount_paid),0) FROM fee_payments
                 WHERE student_id = ? AND fee_structure_id = ? AND school_id = ?"
            );
            $pStmt->execute([$studentId, $fs['id'], $schoolId]);
            $paid    = (float)($pStmt->fetchColumn() ?? 0);
            $due     = (float)$fs['amount'];
            $balance = max(0, $due - $paid);

            $fees[] = [
                'fee_structure_id' => $fs['id'],
                'fee_name'         => $fs['fee_name'],
                'fee_type'         => $fs['fee_type'],
                'amount_due'       => $due,
                'amount_paid'      => $paid,
                'balance'          => $balance,
                'status'           => $balance <= 0 ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid'),
            ];
            $totalDue  += $due;
            $totalPaid += $paid;
        }

        $totalBalance = max(0, $totalDue - $totalPaid);
        $overallStatus = $totalDue == 0 ? 'no_fees'
            : ($totalPaid >= $totalDue ? 'paid' : ($totalPaid > 0 ? 'partial' : 'unpaid'));

        return [
            'success'      => true,
            'student'      => $student,
            'fees'         => $fees,
            'total_due'    => $totalDue,
            'total_paid'   => $totalPaid,
            'balance'      => $totalBalance,
            'status'       => $overallStatus,
            'academic_year'=> $academicYear,
            'term'         => $term,
        ];
    }

    // ─────────────────────────────── PAYMENTS ────────────────────────────────

    /** Record a payment and (optionally) send SMS to parent. */
    public function recordPayment(array $data, int $schoolId, int $recordedBy, array $settings = []): array
    {
        $studentId      = (int)($data['student_id']       ?? 0);
        $feeStructureId = (int)($data['fee_structure_id'] ?? 0);
        $amountPaid     = (float)($data['amount_paid']    ?? 0);
        $paymentDate    = $data['payment_date']            ?? date('Y-m-d');
        $method         = $data['payment_method']          ?? 'cash';
        $notes          = trim($data['notes']              ?? '');
        $sendSms        = !empty($data['send_sms']);

        if (!$studentId || !$feeStructureId || $amountPaid <= 0) {
            return ['success' => false, 'error' => 'Student, fee, and amount are required.'];
        }

        // Verify fee structure belongs to this school
        $fsStmt = $this->db->prepare("SELECT * FROM fee_structures WHERE id=? AND school_id=?");
        $fsStmt->execute([$feeStructureId, $schoolId]);
        $feeStruct = $fsStmt->fetch(PDO::FETCH_ASSOC);
        if (!$feeStruct) return ['success' => false, 'error' => 'Invalid fee structure.'];

        // Verify student belongs to this school
        $sStmt = $this->db->prepare(
            "SELECT s.*, c.class_name, c.id as class_id
             FROM students s JOIN classes c ON s.class_id = c.id
             WHERE s.id=? AND c.school_id=?"
        );
        $sStmt->execute([$studentId, $schoolId]);
        $student = $sStmt->fetch(PDO::FETCH_ASSOC);
        if (!$student) return ['success' => false, 'error' => 'Student not found.'];

        // Current paid amount for this fee
        $paidStmt = $this->db->prepare(
            "SELECT COALESCE(SUM(amount_paid),0) FROM fee_payments WHERE student_id=? AND fee_structure_id=? AND school_id=?"
        );
        $paidStmt->execute([$studentId, $feeStructureId, $schoolId]);
        $alreadyPaid = (float)($paidStmt->fetchColumn() ?? 0);
        $balance     = max(0, (float)$feeStruct['amount'] - $alreadyPaid);

        // Cap payment at remaining balance (no overpayment)
        $amountPaid = min($amountPaid, $balance > 0 ? $balance : $amountPaid);

        $receiptNo = $this->generateReceiptNumber($schoolId);

        try {
            $ins = $this->db->prepare("INSERT INTO fee_payments
                (school_id, student_id, fee_structure_id, amount_paid, payment_date,
                 payment_method, receipt_number, notes, recorded_by)
                VALUES (?,?,?,?,?,?,?,?,?)");
            $ins->execute([
                $schoolId, $studentId, $feeStructureId, $amountPaid, $paymentDate,
                $method, $receiptNo, $notes, $recordedBy
            ]);
            $paymentId = (int)$this->db->lastInsertId();
        } catch (Exception $e) {
            error_log("recordPayment insert: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to save payment.'];
        }

        // Recalculate balance after this payment
        $newPaidStmt = $this->db->prepare(
            "SELECT COALESCE(SUM(amount_paid),0) FROM fee_payments WHERE student_id=? AND fee_structure_id=? AND school_id=?"
        );
        $newPaidStmt->execute([$studentId, $feeStructureId, $schoolId]);
        $newTotalPaid = (float)($newPaidStmt->fetchColumn() ?? 0);
        $newBalance   = max(0, (float)$feeStruct['amount'] - $newTotalPaid);
        $isPaid       = $newBalance <= 0;

        $termLabel = $this->termLabel($feeStruct['term']);
        $smsResult = ['sent' => false];

        // Send SMS if requested and parent phone exists
        if ($sendSms && !empty($student['parent_phone'])) {
            $smsResult = $this->sendPaymentSms($student, $feeStruct, $amountPaid,
                $newTotalPaid, $newBalance, $receiptNo, $paymentDate, $termLabel, $settings);

            // Update sms_sent flag
            $upd = $this->db->prepare("UPDATE fee_payments SET sms_sent=?, sms_status=? WHERE id=?");
            $upd->execute([$smsResult['sent'] ? 1 : 0, $smsResult['detail'] ?? '', $paymentId]);
        }

        return [
            'success'       => true,
            'receipt_number'=> $receiptNo,
            'student_name'  => $student['student_name'],
            'class_name'    => $student['class_name'],
            'fee_name'      => $feeStruct['fee_name'],
            'term_label'    => $termLabel,
            'academic_year' => $feeStruct['academic_year'],
            'amount_paid'   => $amountPaid,
            'total_fee'     => (float)$feeStruct['amount'],
            'total_paid'    => $newTotalPaid,
            'balance'       => $newBalance,
            'status'        => $isPaid ? 'paid' : ($newTotalPaid > 0 ? 'partial' : 'unpaid'),
            'sms'           => $smsResult,
        ];
    }

    /** Get payment history with filters. */
    public function getPaymentHistory(int $schoolId, array $filters = []): array
    {
        $sql = "SELECT fp.*, s.student_name, s.student_id as student_id_no,
                       c.class_name, fs.fee_name, fs.fee_type, fs.term, fs.academic_year,
                       u.full_name as recorded_by_name
                FROM fee_payments fp
                JOIN students s        ON fp.student_id = s.id
                JOIN classes c         ON s.class_id = c.id
                JOIN fee_structures fs ON fp.fee_structure_id = fs.id
                LEFT JOIN users u      ON fp.recorded_by = u.id
                WHERE fp.school_id = ?";
        $params = [$schoolId];

        if (!empty($filters['class_id'])) {
            $sql .= " AND c.id = ?"; $params[] = (int)$filters['class_id'];
        }
        if (!empty($filters['term'])) {
            $sql .= " AND fs.term = ?"; $params[] = $filters['term'];
        }
        if (!empty($filters['academic_year'])) {
            $sql .= " AND fs.academic_year = ?"; $params[] = $filters['academic_year'];
        }
        if (!empty($filters['date_from'])) {
            $sql .= " AND fp.payment_date >= ?"; $params[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $sql .= " AND fp.payment_date <= ?"; $params[] = $filters['date_to'];
        }
        if (!empty($filters['student_id'])) {
            $sql .= " AND fp.student_id = ?"; $params[] = (int)$filters['student_id'];
        }
        $sql .= " ORDER BY fp.payment_date DESC, fp.created_at DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ─────────────────────────────── HELPERS ─────────────────────────────────

    /** Get fee structures applicable to a class (class-specific + school-wide). */
    public function getApplicableFeeStructures(int $classId, int $schoolId, string $academicYear, string $term): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM fee_structures
             WHERE school_id = ? AND is_active = 1 AND academic_year = ?
               AND (term = ? OR term = 'all')
               AND (class_id IS NULL OR class_id = ?)
             ORDER BY fee_type, fee_name"
        );
        $stmt->execute([$schoolId, $academicYear, $term, $classId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function generateReceiptNumber(int $schoolId): string
    {
        $date = date('Ymd');
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM fee_payments
             WHERE school_id = ? AND DATE(created_at) = CURDATE()"
        );
        $stmt->execute([$schoolId]);
        $todayCount = (int)$stmt->fetchColumn();
        return sprintf('RCP-%s-%04d', $date, $todayCount + 1);
    }

    public function termLabel(string $term): string
    {
        return match($term) {
            '1'   => 'Term 1',
            '2'   => 'Term 2',
            '3'   => 'Term 3',
            'all' => 'All Terms',
            default => "Term $term",
        };
    }

    private function sendPaymentSms(array $student, array $feeStruct, float $amountPaid,
        float $totalPaid, float $balance, string $receiptNo, string $paymentDate,
        string $termLabel, array $settings): array
    {
        if (empty($student['parent_phone'])) {
            return ['sent' => false, 'detail' => 'No parent phone number.'];
        }

        require_once __DIR__ . '/../helpers/NotificationService.php';
        $svc = new NotificationService($settings);

        $parentName  = $student['parent_name'] ?? 'Parent/Guardian';
        $studentName = $student['student_name'];
        $className   = $student['class_name'] ?? '';
        $feeName     = $feeStruct['fee_name'];
        $totalFee    = (float)$feeStruct['amount'];
        $year        = $feeStruct['academic_year'];
        $date        = date('d/m/Y', strtotime($paymentDate));
        $totalPaidText = number_format($totalPaid, 2);
        $totalFeeText  = number_format($totalFee, 2);
        $balanceText   = number_format($balance, 2);
        $schoolName    = $settings['email_from_name'] ?? 'School Management';

        $msg = "Dear {$parentName},\n\n"
             . "Official Payment Acknowledgment\n"
             . "We have received a payment of GH\xC2\xA2" . number_format($amountPaid, 2) . " on {$date} for {$studentName} ({$className}).\n\n"
             . "Details:\n"
             . "- Category: {$feeName}\n"
             . "- Period: {$termLabel}, {$year}\n"
             . "- Total Fee: GH\xC2\xA2{$totalFeeText}\n"
             . "- Total Paid: GH\xC2\xA2{$totalPaidText}\n"
             . "- Current Balance: GH\xC2\xA2{$balanceText}\n\n"
             . "Receipt No: {$receiptNo}\n\n"
             . "Thank you.\n"
             . "{$schoolName}.";

        $result = $svc->sendSMS($student['parent_phone'], $msg);
        return [
            'sent'   => $result['success'] ?? false,
            'detail' => $result['error']   ?? ('Sent. Batch: ' . ($result['batch_id'] ?? '-')),
        ];
    }
}
