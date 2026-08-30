<?php
/**
 * Lib/Enrollment.php — Exam enrollment & payment access helper.
 *
 * migration_v51 moved ALL pricing (fee, default discount %, coupon
 * targeting) from subjectinfo down to examinfo. Every exam is its own
 * priced product now — there is no more "subject fee that every exam under
 * it inherits". The exam-level API below is the only pricing/access
 * authority for anything created going forward:
 *
 *   Enrollment::canAccess($examId, $userId)               → bool
 *   Enrollment::getExamFee($examId)                        → float
 *   Enrollment::getExamPayment($examId, $userId)           → row|null
 *   Enrollment::applyExamCoupon($code, $examId, $fee)      → ['final'=>float,'discount'=>float,'error'=>string]
 *   Enrollment::resolveExamPrice($examId, $userId, $coupon) → full price resolution (institute → coupon → exam default discount)
 *   Enrollment::createExamPending(...)                      → PaymentId
 *   Enrollment::submitManualExamPayment(...)                → ['ok'=>bool,'error'=>string]
 *
 * The old subject-scoped methods (getSubjectFee, getPayment, resolvePrice,
 * applyCoupon, createPending, submitManualPayment) are kept below, UNCHANGED
 * and untouched by the exam-level rewrite, purely so:
 *   (a) the historical enrollment_payments ledger still renders in
 *       Admin/EnrollmentPayments.php, and
 *   (b) nothing 500s if an old bookmark hits the retired subject-level
 *       checkout pages (exam/enroll.php etc. — see those files' headers).
 * They are not called by any current student-facing flow. Do not wire new
 * features to them — extend the exam-level API instead.
 */
class Enrollment
{
    /* ── Can this user access this exam? ──────────────────────────────────
       Access is granted when ANY of the following is true:
       1. Exam-level ExamFreeFor = 'All'  (HIGHEST PRIORITY)
       2. Exam-level ExamFreeFor = 'Institute' AND user's InstituteId matches ExamInstituteId
       3. Admin has explicitly assigned this exam to the student (exam_assignments row)
       4. The student has ScholarshipFlag = 'Y'
       5. Institute-level IsFree = 1 (institute_subject_discounts, resolved via
          the exam's parent SubjectInfoId — institute deals are still
          negotiated per course/subject, not per individual exam)
       6. This exam's own ExamFee is <= 0 — an explicit per-exam decision an
          admin made on exam/manage.php (the field always has a real value
          post-migration_v51, never an invisible inherited default)
       7. Otherwise: a Paid/Waived/Free row exists in exam_fee_payments for
          this exam + student, and it hasn't expired (EndDate)
       8. Caller is an admin (checked separately in controllers)
    */
    public static function canAccess(int $examId, int $userId): bool
    {
        if (!$userId) return false;

        try {
            $exam = Database::fetchOne(
                "SELECT SubjectInfoId, ExamFreeFor, ExamInstituteId, COALESCE(ExamFee,0) AS ExamFee
                   FROM examinfo WHERE ExamInfoId = ? LIMIT 1", [$examId]);
        } catch (Exception $e) {
            // migration_v50/v51 not yet run — ExamFee/ExamFreeFor columns missing.
            $exam = Database::fetchOne(
                "SELECT SubjectInfoId FROM examinfo WHERE ExamInfoId = ? LIMIT 1", [$examId]);
            if ($exam) { $exam['ExamFreeFor'] = 'None'; $exam['ExamInstituteId'] = null; $exam['ExamFee'] = 0.0; }
        }
        if (!$exam) return false;
        $subjectId = (int)$exam['SubjectInfoId'];

        /* ── EXAM-LEVEL FREE OVERRIDE (highest priority) ── */
        $freeFor = $exam['ExamFreeFor'] ?? 'None';
        if ($freeFor === 'All') {
            return true; // free for everyone — no payment needed
        }
        if ($freeFor === 'Institute') {
            $examInstId = (int)($exam['ExamInstituteId'] ?? 0);
            if ($examInstId > 0) {
                $u = Database::fetchOne(
                    "SELECT InstituteId FROM userinfo WHERE UserInfoId = ? LIMIT 1", [$userId]);
                if ((int)($u['InstituteId'] ?? 0) === $examInstId) {
                    return true; // student belongs to the exam's designated institute → free
                }
            }
        }

        /* ── EXPLICIT ADMIN ASSIGNMENT — always sufficient, regardless of fee ──
           An admin picking this student on exam/assign.php is itself the
           grant, so payment status (or lack of it) never blocks them. */
        if (self::hasAssignment($examId, $userId)) {
            return true;
        }

        /* Check scholarship flag */
        try {
            $u = Database::fetchOne(
                "SELECT ScholarshipFlag FROM userinfo WHERE UserInfoId = ? LIMIT 1", [$userId]);
            if (($u['ScholarshipFlag'] ?? 'N') === 'Y') return true;
        } catch (Exception $e) {}

        /* Institute-level free access (full waiver, priority over payment record) */
        if (file_exists(__DIR__ . '/Institute.php')) {
            require_once __DIR__ . '/Institute.php';
            try {
                if (Institute::isFreeForStudent($userId, $subjectId)) return true;
            } catch (Exception $e) { /* institutes table not yet created — skip */ }
        }

        /* This exam's own fee (the only fee source now) */
        $fee = (float)($exam['ExamFee'] ?? 0);
        if ($fee <= 0) return true; // admin explicitly priced this exam at ₹0

        /* Check exam_fee_payments record + validity window */
        $pmt = self::getExamPayment($examId, $userId);
        if ($pmt && in_array($pmt['PaymentStatus'], ['Paid', 'Waived', 'Free'], true)) {
            if (!empty($pmt['EndDate']) && $pmt['EndDate'] < date('Y-m-d')) {
                return false; // enrollment expired
            }
            return true;
        }

        return false;
    }

    /** True when an admin has explicitly assigned this exam to this student
     *  (any status — Assigned or Completed both count as "was granted access"). */
    public static function hasAssignment(int $examId, int $userId): bool
    {
        try {
            $row = Database::fetchOne(
                "SELECT AssignmentId FROM exam_assignments WHERE ExamInfoId = ? AND UserInfoId = ? LIMIT 1",
                [$examId, $userId]);
            return (bool)$row;
        } catch (Exception $e) {
            return false; // exam_assignments not yet created
        }
    }

    /**
     * Returns enrollment expiry information for an exam+user combination.
     * Returns ['active'=>bool, 'expired'=>bool, 'endDate'=>string|null, 'startDate'=>string|null]
     */
    public static function getExamEnrollmentDates(int $examId, int $userId): array
    {
        $pmt = self::getExamPayment($examId, $userId);
        if (!$pmt || !in_array($pmt['PaymentStatus'], ['Paid', 'Waived', 'Free'], true)) {
            return ['active' => false, 'expired' => false, 'startDate' => null, 'endDate' => null];
        }
        $today   = date('Y-m-d');
        $endDate = $pmt['EndDate'] ?? null;
        $expired = ($endDate && $endDate < $today);
        return [
            'active'    => !$expired,
            'expired'   => $expired,
            'startDate' => $pmt['StartDate'] ?? null,
            'endDate'   => $endDate,
        ];
    }

    /** Returns the exam's own fee (0 if unset — always a real value post-migration_v51). */
    public static function getExamFee(int $examId): float
    {
        try {
            $row = Database::fetchOne(
                "SELECT COALESCE(ExamFee,0) AS ExamFee FROM examinfo WHERE ExamInfoId = ? LIMIT 1", [$examId]);
            return (float)($row['ExamFee'] ?? 0);
        } catch (Exception $e) {
            return 0.0;
        }
    }

    /** Returns the exam_fee_payments row for this user+exam, or null. */
    public static function getExamPayment(int $examId, int $userId): ?array
    {
        try {
            return Database::fetchOne(
                "SELECT * FROM exam_fee_payments
                  WHERE ExamInfoId = ? AND UserInfoId = ? LIMIT 1",
                [$examId, $userId]) ?: null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Validate a coupon code for a specific exam and compute discount.
     * Returns ['final' => float, 'discount' => float, 'error' => string].
     * error='' means success (a blank code is always success, no-op).
     */
    public static function applyExamCoupon(string $code, int $examId, float $fee): array
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return ['final' => $fee, 'discount' => 0.0, 'error' => ''];
        }

        try {
            $c = Database::fetchOne(
                "SELECT * FROM discount_coupons WHERE Code = ? AND Active = 'Y' LIMIT 1",
                [$code]);
        } catch (Exception $e) {
            return ['final' => $fee, 'discount' => 0.0, 'error' => 'Coupon service unavailable.'];
        }

        if (!$c) {
            return ['final' => $fee, 'discount' => 0.0, 'error' => 'Invalid or inactive coupon code.'];
        }

        /* Exam restriction (migration_v51) — NULL/0 = valid for any exam.
           Legacy coupons that still only carry a SubjectInfoId restriction
           (pre-migration_v51) are honoured too, checked against this exam's
           subject. */
        $examRestrict = (int)($c['ExamInfoId'] ?? 0);
        if ($examRestrict > 0 && $examRestrict !== $examId) {
            return ['final' => $fee, 'discount' => 0.0, 'error' => 'This coupon is not valid for this exam.'];
        }
        if ($examRestrict === 0 && !empty($c['SubjectInfoId'])) {
            try {
                $ex = Database::fetchOne("SELECT SubjectInfoId FROM examinfo WHERE ExamInfoId = ? LIMIT 1", [$examId]);
                if ($ex && (int)$ex['SubjectInfoId'] !== (int)$c['SubjectInfoId']) {
                    return ['final' => $fee, 'discount' => 0.0, 'error' => 'This coupon is not valid for this exam.'];
                }
            } catch (Exception $e) {}
        }

        /* Date validity */
        $today = date('Y-m-d');
        if ($c['ValidFrom'] && $today < $c['ValidFrom']) {
            return ['final' => $fee, 'discount' => 0.0, 'error' => 'This coupon is not yet active.'];
        }
        if ($c['ValidTo'] && $today > $c['ValidTo']) {
            return ['final' => $fee, 'discount' => 0.0, 'error' => 'This coupon has expired.'];
        }

        /* Usage limit */
        if ((int)$c['MaxUses'] > 0 && (int)$c['UsedCount'] >= (int)$c['MaxUses']) {
            return ['final' => $fee, 'discount' => 0.0, 'error' => 'This coupon has reached its usage limit.'];
        }

        /* Compute discount */
        $discount = ($c['DiscountType'] === 'PCT')
            ? round($fee * (float)$c['DiscountValue'] / 100, 2)
            : min((float)$c['DiscountValue'], $fee);

        $final = max(0.0, $fee - $discount);
        return ['final' => $final, 'discount' => $discount, 'error' => ''];
    }

    /**
     * Resolve the final price for a student enrolling in an exam.
     *
     * Institute discount takes PRIORITY — if an institute rule applies,
     * coupon and the exam's own default discount % are both skipped.
     * Otherwise: coupon (if provided) beats the exam's default discount %.
     *
     * Returns:
     *   'base_fee'       => the exam's own ExamFee
     *   'final'          => amount student pays
     *   'discount'       => total discount applied
     *   'is_free'        => bool
     *   'source'         => 'institute' | 'coupon' | 'exam_default' | 'none'
     *   'institute_rule' => isd row or null
     *   'coupon_applied' => bool
     *   'error'          => validation error string ('' = ok)
     */
    public static function resolveExamPrice(int $examId, int $userId, string $coupon = ''): array
    {
        /* Exam-level pricing (migration_v51) may not be applied on every
           install yet — same resilience pattern as getExamFee()/
           exam/browse-subjects.php: prefer examinfo's own ExamFee/
           ExamDiscountPct once they exist, otherwise fall back to the
           subject-level fee/discount they superseded, rather than a hard
           SQL error either way. */
        try {
            $exam = Database::fetchOne(
                "SELECT SubjectInfoId, COALESCE(ExamFee,0) AS ExamFee, COALESCE(ExamDiscountPct,0) AS ExamDiscountPct
                   FROM examinfo WHERE ExamInfoId = ? LIMIT 1", [$examId]);
        } catch (Exception $e) {
            $exam = Database::fetchOne(
                "SELECT e.SubjectInfoId, COALESCE(s.ExamFee,0) AS ExamFee, COALESCE(s.DiscountPct,0) AS ExamDiscountPct
                   FROM examinfo e LEFT JOIN subjectinfo s ON s.SubjectInfoId = e.SubjectInfoId
                  WHERE e.ExamInfoId = ? LIMIT 1", [$examId]);
        }
        $fee       = (float)($exam['ExamFee'] ?? 0);
        $subjectId = (int)($exam['SubjectInfoId'] ?? 0);

        $base = [
            'base_fee' => $fee, 'final' => $fee, 'discount' => 0.0,
            'is_free' => ($fee <= 0), 'source' => 'none',
            'institute_rule' => null, 'group_rule' => null, 'coupon_applied' => false, 'error' => '',
        ];
        if ($fee <= 0) return $base;

        /* 1. Institute discount — still resolved per subject (institutes
           negotiate a rate for a whole course), applied to THIS exam's fee */
        if (file_exists(__DIR__ . '/Institute.php')) try {
            require_once __DIR__ . '/Institute.php';
            $inst = Institute::applyDiscount($userId, $subjectId, $fee);
            if ($inst['applied']) {
                return array_merge($base, [
                    'final'          => $inst['final'],
                    'discount'       => $inst['discount'],
                    'is_free'        => $inst['is_free'],
                    'source'         => 'institute',
                    'institute_rule' => $inst['rule'],
                ]);
            }
        } catch (Exception $e) {}

        /* 1.5. Student group discount (migration_v53) — a blanket % set on
           whichever active group grants the student the best deal. Same
           tier as institute discount (automatic, no code needed) but
           institute still wins if both apply, since that's a negotiated
           per-course rate rather than a per-cohort perk. */
        if (file_exists(__DIR__ . '/StudentGroup.php')) try {
            require_once __DIR__ . '/StudentGroup.php';
            $grp = StudentGroup::applyDiscount($userId, $fee);
            if ($grp['applied']) {
                return array_merge($base, [
                    'final'      => $grp['final'],
                    'discount'   => $grp['discount'],
                    'is_free'    => $grp['is_free'],
                    'source'     => 'student_group',
                    'group_rule' => $grp['rule'],
                ]);
            }
        } catch (Exception $e) {}

        /* 2. Coupon discount */
        if ($coupon !== '') {
            $c = self::applyExamCoupon($coupon, $examId, $fee);
            if ($c['error'] !== '') {
                return array_merge($base, ['error' => $c['error']]);
            }
            if ($c['discount'] > 0) {
                return array_merge($base, [
                    'final'          => $c['final'],
                    'discount'       => $c['discount'],
                    'is_free'        => ($c['final'] <= 0),
                    'source'         => 'coupon',
                    'coupon_applied' => true,
                ]);
            }
        }

        /* 3. Exam's own default discount % (migration_v51 — replaces the
           retired subjectinfo.DiscountPct) */
        $exDisc = (float)($exam['ExamDiscountPct'] ?? 0);
        if ($exDisc > 0) {
            $disc  = round($fee * $exDisc / 100, 2);
            $final = max(0.0, $fee - $disc);
            return array_merge($base, [
                'final' => $final, 'discount' => $disc,
                'is_free' => ($final <= 0), 'source' => 'exam_default',
            ]);
        }

        return $base;
    }

    /** Increment coupon usage count after successful payment. */
    public static function incrementCoupon(string $code): void
    {
        if ($code === '') return;
        try {
            Database::execute(
                "UPDATE discount_coupons SET UsedCount = UsedCount + 1 WHERE Code = ?",
                [strtoupper($code)]);
        } catch (Exception $e) {}
    }

    /**
     * Create (or refresh) a Pending exam_fee_payments record, with full
     * coupon/discount tracking (migration_v51). Returns the PaymentId.
     */
    public static function createExamPending(
        int $userId, int $examId, float $fee,
        string $coupon = '', float $discount = 0.0, ?float $final = null
    ): int {
        if ($final === null) $final = $fee;
        Database::execute(
            "INSERT INTO exam_fee_payments
                (ExamInfoId, UserInfoId, FeeAtTime, CouponCode, DiscountApplied, FinalAmount, PaymentStatus)
             VALUES (?, ?, ?, ?, ?, ?, 'Pending')
             ON DUPLICATE KEY UPDATE
                FeeAtTime       = VALUES(FeeAtTime),
                CouponCode      = VALUES(CouponCode),
                DiscountApplied = VALUES(DiscountApplied),
                FinalAmount     = VALUES(FinalAmount),
                PaymentStatus   = IF(PaymentStatus IN ('Paid','Waived','Free'),
                                     PaymentStatus, 'Pending'),
                UpdatedAt       = CURRENT_TIMESTAMP",
            [$examId, $userId, $fee, strtoupper($coupon), $discount, $final]);
        $row = Database::fetchOne(
            "SELECT PaymentId FROM exam_fee_payments WHERE ExamInfoId = ? AND UserInfoId = ? LIMIT 1",
            [$examId, $userId]);
        return (int)($row['PaymentId'] ?? 0);
    }

    /**
     * Submit a manual (UPI/QR) transaction ID for an exam, for admin
     * verification. Status stays 'Pending' until an admin confirms it in
     * Admin/EnrollmentPayments.php. Returns ['ok'=>bool, 'error'=>string].
     */
    public static function submitManualExamPayment(
        int $userId, int $examId, string $transactionId,
        string $coupon = '', string $method = 'UPI'
    ): array {
        $transactionId = trim($transactionId);
        if ($userId <= 0 || $examId <= 0) {
            return ['ok' => false, 'error' => 'Invalid request.'];
        }
        if ($transactionId === '') {
            return ['ok' => false, 'error' => 'Please enter the Transaction / UTR ID from your UPI app.'];
        }
        if (mb_strlen($transactionId) > 100) {
            return ['ok' => false, 'error' => 'Transaction ID is too long.'];
        }

        $existing = self::getExamPayment($examId, $userId);
        if ($existing && in_array($existing['PaymentStatus'], ['Paid', 'Waived', 'Free'], true)) {
            return ['ok' => false, 'error' => 'This exam is already paid for.'];
        }

        $fee    = self::getExamFee($examId);
        $result = self::resolveExamPrice($examId, $userId, $coupon);
        if ($result['error'] !== '') {
            return ['ok' => false, 'error' => $result['error']];
        }

        self::createExamPending($userId, $examId, $fee, $coupon, $result['discount'], $result['final']);

        try {
            Database::execute(
                "UPDATE exam_fee_payments
                    SET PaymentMethod = ?,
                        TransactionId = ?,
                        SubmittedAt   = NOW(),
                        UpdatedAt     = CURRENT_TIMESTAMP
                  WHERE UserInfoId = ? AND ExamInfoId = ?",
                [$method, $transactionId, $userId, $examId]);
        } catch (Exception $e) {
            return ['ok' => false, 'error' => 'Manual payment is not available yet. Contact administrator.'];
        }

        return ['ok' => true, 'error' => ''];
    }

    /* ═══════════════════════════════════════════════════════════════════
       LEGACY — subject-scoped pricing (pre-migration_v51). Not used by any
       current student flow; kept only for the historical enrollment_payments
       ledger in Admin/EnrollmentPayments.php and so old bookmarks pointing
       at the retired exam/enroll.php etc. don't 500. Do not extend these —
       use the exam-level methods above instead.
       ═══════════════════════════════════════════════════════════════════ */

    /** @deprecated Legacy subject-level fee lookup. Use getExamFee() instead. */
    public static function getSubjectFee(int $subjectId): float
    {
        try {
            $row = Database::fetchOne(
                "SELECT ExamFee FROM subjectinfo WHERE SubjectInfoId = ? LIMIT 1", [$subjectId]);
            return (float)($row['ExamFee'] ?? 0);
        } catch (Exception $e) {
            return 0.0;
        }
    }

    /** @deprecated Legacy subject-level payment lookup (enrollment_payments). Use getExamPayment() instead. */
    public static function getPayment(int $subjectId, int $userId): ?array
    {
        try {
            return Database::fetchOne(
                "SELECT * FROM enrollment_payments
                  WHERE SubjectInfoId = ? AND UserInfoId = ? LIMIT 1",
                [$subjectId, $userId]) ?: null;
        } catch (Exception $e) {
            return null;
        }
    }

    /** @deprecated Legacy subject-level coupon check. Use applyExamCoupon() instead. */
    public static function applyCoupon(string $code, int $subjectId, float $fee): array
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return ['final' => $fee, 'discount' => 0.0, 'error' => ''];
        }
        try {
            $c = Database::fetchOne(
                "SELECT * FROM discount_coupons WHERE Code = ? AND Active = 'Y' LIMIT 1",
                [$code]);
        } catch (Exception $e) {
            return ['final' => $fee, 'discount' => 0.0, 'error' => 'Coupon service unavailable.'];
        }
        if (!$c) {
            return ['final' => $fee, 'discount' => 0.0, 'error' => 'Invalid or inactive coupon code.'];
        }
        if ($c['SubjectInfoId'] && (int)$c['SubjectInfoId'] !== $subjectId) {
            return ['final' => $fee, 'discount' => 0.0, 'error' => 'This coupon is not valid for this subject.'];
        }
        $today = date('Y-m-d');
        if ($c['ValidFrom'] && $today < $c['ValidFrom']) {
            return ['final' => $fee, 'discount' => 0.0, 'error' => 'This coupon is not yet active.'];
        }
        if ($c['ValidTo'] && $today > $c['ValidTo']) {
            return ['final' => $fee, 'discount' => 0.0, 'error' => 'This coupon has expired.'];
        }
        if ((int)$c['MaxUses'] > 0 && (int)$c['UsedCount'] >= (int)$c['MaxUses']) {
            return ['final' => $fee, 'discount' => 0.0, 'error' => 'This coupon has reached its usage limit.'];
        }
        $discount = ($c['DiscountType'] === 'PCT')
            ? round($fee * (float)$c['DiscountValue'] / 100, 2)
            : min((float)$c['DiscountValue'], $fee);
        $final = max(0.0, $fee - $discount);
        return ['final' => $final, 'discount' => $discount, 'error' => ''];
    }

    /** @deprecated Legacy subject-level price resolution. Use resolveExamPrice() instead. */
    public static function resolvePrice(int $subjectId, int $userId, string $coupon = '', int $examId = 0): array
    {
        $fee = self::getSubjectFee($subjectId);
        $base = [
            'base_fee' => $fee, 'final' => $fee, 'discount' => 0.0,
            'is_free' => ($fee <= 0), 'source' => 'none',
            'institute_rule' => null, 'coupon_applied' => false, 'error' => '',
        ];
        if ($fee <= 0) return $base;

        if (file_exists(__DIR__ . '/Institute.php')) try {
            require_once __DIR__ . '/Institute.php';
            $inst = Institute::applyDiscount($userId, $subjectId, $fee);
            if ($inst['applied']) {
                return array_merge($base, [
                    'final'          => $inst['final'],
                    'discount'       => $inst['discount'],
                    'is_free'        => $inst['is_free'],
                    'source'         => 'institute',
                    'institute_rule' => $inst['rule'],
                ]);
            }
        } catch (Exception $e) {}

        if ($coupon !== '') {
            $c = self::applyCoupon($coupon, $subjectId, $fee);
            if ($c['error'] !== '') {
                return array_merge($base, ['error' => $c['error']]);
            }
            if ($c['discount'] > 0) {
                return array_merge($base, [
                    'final'          => $c['final'],
                    'discount'       => $c['discount'],
                    'is_free'        => ($c['final'] <= 0),
                    'source'         => 'coupon',
                    'coupon_applied' => true,
                ]);
            }
        }

        try {
            $sub = Database::fetchOne(
                "SELECT COALESCE(DiscountPct,0) AS DiscountPct FROM subjectinfo
                  WHERE SubjectInfoId = ? LIMIT 1", [$subjectId]);
            $defPct = (float)($sub['DiscountPct'] ?? 0);
            if ($defPct > 0) {
                $disc  = round($fee * $defPct / 100, 2);
                $final = max(0.0, $fee - $disc);
                return array_merge($base, [
                    'final' => $final, 'discount' => $disc,
                    'is_free' => ($final <= 0), 'source' => 'subject_default',
                ]);
            }
        } catch (Exception $e) {}

        return $base;
    }

    /** @deprecated Legacy subject-level pending record. Use createExamPending() instead. */
    public static function createPending(
        int $userId, int $subjectId, float $fee,
        string $coupon, float $discount, float $final
    ): int {
        Database::execute(
            "INSERT INTO enrollment_payments
                (UserInfoId, SubjectInfoId, ExamFeeAtTime, CouponCode, DiscountApplied,
                 FinalAmount, PaymentStatus)
             VALUES (?, ?, ?, ?, ?, ?, 'Pending')
             ON DUPLICATE KEY UPDATE
                ExamFeeAtTime   = VALUES(ExamFeeAtTime),
                CouponCode      = VALUES(CouponCode),
                DiscountApplied = VALUES(DiscountApplied),
                FinalAmount     = VALUES(FinalAmount),
                PaymentStatus   = IF(PaymentStatus IN ('Paid','Waived','Free'),
                                     PaymentStatus, 'Pending'),
                UpdatedAt       = CURRENT_TIMESTAMP",
            [$userId, $subjectId, $fee, strtoupper($coupon), $discount, $final]);
        $row = Database::fetchOne(
            "SELECT PaymentId FROM enrollment_payments
              WHERE UserInfoId = ? AND SubjectInfoId = ? LIMIT 1",
            [$userId, $subjectId]);
        return (int)($row['PaymentId'] ?? 0);
    }

    /** @deprecated Legacy subject-level manual payment. Use submitManualExamPayment() instead. */
    public static function submitManualPayment(
        int $userId, int $subjectId, string $transactionId,
        string $coupon = '', string $method = 'UPI'
    ): array {
        $transactionId = trim($transactionId);
        if ($userId <= 0 || $subjectId <= 0) {
            return ['ok' => false, 'error' => 'Invalid request.'];
        }
        if ($transactionId === '') {
            return ['ok' => false, 'error' => 'Please enter the Transaction / UTR ID from your UPI app.'];
        }
        if (mb_strlen($transactionId) > 100) {
            return ['ok' => false, 'error' => 'Transaction ID is too long.'];
        }

        $existing = self::getPayment($subjectId, $userId);
        if ($existing && in_array($existing['PaymentStatus'], ['Paid', 'Waived', 'Free'], true)) {
            return ['ok' => false, 'error' => 'This subject is already paid for.'];
        }

        $fee    = self::getSubjectFee($subjectId);
        $result = self::resolvePrice($subjectId, $userId, $coupon);
        if ($result['error'] !== '') {
            return ['ok' => false, 'error' => $result['error']];
        }

        self::createPending($userId, $subjectId, $fee, $coupon, $result['discount'], $result['final']);

        try {
            Database::execute(
                "UPDATE enrollment_payments
                    SET PaymentMethod = ?,
                        TransactionId = ?,
                        SubmittedAt   = NOW(),
                        UpdatedAt     = CURRENT_TIMESTAMP
                  WHERE UserInfoId = ? AND SubjectInfoId = ?",
                [$method, $transactionId, $userId, $subjectId]);
        } catch (Exception $e) {
            return ['ok' => false, 'error' => 'Manual payment is not available yet. Contact administrator.'];
        }

        return ['ok' => true, 'error' => ''];
    }

    /* ── Attempt limits (migration_v36) ───────────────────────────────────
       Precedence (highest wins):
         1. exam_attempt_overrides row for (ExamInfoId, UserInfoId) — per-student
         2. examinfo.MaxAttempts — per-exam
         3. hard default of 5 (also the column default, so this only matters
            if migration_v36 hasn't been run yet on this environment)
       A max of 0 means unlimited attempts. */

    /** Number of completed submissions this student has for this exam. */
    public static function getAttemptCount(int $examId, int $userId): int
    {
        if ($examId <= 0 || $userId <= 0) return 0;
        try {
            $row = Database::fetchOne(
                "SELECT COUNT(*) AS c FROM studentexam WHERE ExamInfoId = ? AND UserInfoId = ?",
                [$examId, $userId]);
            return (int)($row['c'] ?? 0);
        } catch (Exception $e) {
            return 0;
        }
    }

    /** Resolves the max-attempts limit for this student+exam (0 = unlimited). */
    public static function getMaxAttempts(int $examId, int $userId): int
    {
        if ($examId <= 0) return 5;

        /* 1. Per-student override — highest priority */
        try {
            $ov = Database::fetchOne(
                "SELECT MaxAttempts FROM exam_attempt_overrides
                  WHERE ExamInfoId = ? AND UserInfoId = ? LIMIT 1",
                [$examId, $userId]);
            if ($ov !== null && $ov !== false) {
                return (int)$ov['MaxAttempts'];
            }
        } catch (Exception $e) {
            /* migration_v36 not yet run — table missing, fall through */
        }

        /* 2. Exam-level setting */
        try {
            $exam = Database::fetchOne(
                "SELECT MaxAttempts FROM examinfo WHERE ExamInfoId = ? LIMIT 1", [$examId]);
            if ($exam !== null && $exam !== false && $exam['MaxAttempts'] !== null) {
                return (int)$exam['MaxAttempts'];
            }
        } catch (Exception $e) {
            /* migration_v36 not yet run — column missing, fall through */
        }

        /* 3. Hard default */
        return 5;
    }

    /**
     * Full attempt status for this student+exam.
     * Returns ['used'=>int, 'max'=>int, 'unlimited'=>bool, 'remaining'=>int, 'allowed'=>bool]
     * 'remaining' is 0 when unlimited (check 'unlimited' instead of relying on it).
     */
    public static function getAttemptStatus(int $examId, int $userId): array
    {
        $max  = self::getMaxAttempts($examId, $userId);
        $used = self::getAttemptCount($examId, $userId);
        $unlimited = ($max <= 0);
        $remaining = $unlimited ? 0 : max(0, $max - $used);
        return [
            'used'      => $used,
            'max'       => $max,
            'unlimited' => $unlimited,
            'remaining' => $remaining,
            'allowed'   => $unlimited || $used < $max,
        ];
    }

    /** Convenience wrapper — true if this student may start another attempt. */
    public static function canAttempt(int $examId, int $userId): bool
    {
        return self::getAttemptStatus($examId, $userId)['allowed'];
    }
}
