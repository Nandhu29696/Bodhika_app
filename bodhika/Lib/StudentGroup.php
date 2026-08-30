<?php
/**
 * Lib/StudentGroup.php — Student group (batch/cohort) membership, exam
 * assignment, blanket-discount resolution, the group→individual
 * exam_assignments bridge (migration_v53), and self-service group joining
 * via a shareable registration code (migration_v68).
 *
 * A student group is organisational, a pricing benefit, AND (since the
 * bridge below) a bulk way to individually assign exams:
 *   - Membership (student_group_members) does NOT itself gate access.
 *   - Exam assignment (student_group_exam_assignments) tags an exam as
 *     "recommended for this group" for display/reporting/discount purposes.
 *     Every exam is already visible/enrollable to every student regardless
 *     (see exam/browse-subjects.php).
 *   - DiscountPct on the group is applied automatically (no coupon needed)
 *     to any exam a member enrolls in and pays for, via getBestDiscount().
 *   - THE BRIDGE: syncAssignments() / pruneOrphanedAssignments() below keep
 *     the real exam_assignments table (the one exam/history.php's "Assigned
 *     Exams" tab reads, and exam/assign.php writes one-student-at-a-time) in
 *     sync with group recommendations, so admins don't have to individually
 *     assign every exam to every member by hand:
 *       - Assigning an exam to a group, or adding a student to a group that
 *         already has exams assigned, auto-creates the matching
 *         exam_assignments row(s) (never duplicates, never reopens a
 *         Completed assignment).
 *       - Unassigning an exam from a group, or removing a student from a
 *         group, auto-revokes the matching exam_assignments row(s) — but
 *         ONLY if never attempted (StudentExamId IS NULL, Status <>
 *         'Completed') and no other active group still recommends that exam
 *         to that student. Attempted/completed assignments are never
 *         touched by the bridge.
 *
 * A student can belong to more than one group; when resolving price we use
 * whichever active group grants the best (highest %) discount.
 *
 * Mirrors Lib/Institute.php's applyDiscount() return shape so
 * Lib/Enrollment.php::resolveExamPrice() can treat both the same way.
 *
 * A SECOND, separate bridge (migration_v67, student_group_direct_assignments)
 * covers real access-granting group assignment — made via exam/assign.php's
 * "Assign Entire Group" action, distinct from the Recommended/discount-only
 * bridge above. See syncDirectAssignments() / pruneOrphanedDirectAssignments()
 * further down. The two are intentionally kept as separate tables/functions
 * so "Recommended for a group" (still pay) is never confused with "directly
 * assigned to a group" (real access, no payment gate).
 *
 * SELF-SERVICE JOINING (migration_v68): a group can optionally carry a
 * short, unique StudentGroupCode. Admins generate one from
 * Admin/StudentGroupEdit.php and share it as
 * auth/register-group.php?code=XXXXXXXX — a student who completes
 * self-registration through that link is added to the group automatically
 * via enrollSelfByCode() below, which reuses the exact same bridge-sync
 * calls the admin "Add Students" bulk-add flow already uses, so a
 * self-joined student picks up the group's recommended/directly-assigned
 * exams exactly like an admin-added one. See findByCode() / generateCode()
 * / enrollSelfByCode().
 */
class StudentGroup
{
    // ── Public API ─────────────────────────────────────────────────────────────

    /**
     * All groups (active only by default), with member/exam counts for
     * admin listing.
     */
    public static function listAll(bool $activeOnly = true): array
    {
        try {
            $where = $activeOnly ? "WHERE g.IsActive = 'Y'" : '';
            return Database::fetchAll(
                "SELECT g.*,
                        (SELECT COUNT(*) FROM student_group_members m WHERE m.StudentGroupId = g.StudentGroupId) AS MemberCount,
                        (SELECT COUNT(*) FROM student_group_exam_assignments a WHERE a.StudentGroupId = g.StudentGroupId) AS ExamCount
                   FROM student_groups g
                   $where
                  ORDER BY g.GroupName");
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * The best (highest) active discount % across every active group this
     * student belongs to. Returns 0.0 if the student isn't in any group, or
     * every group they're in has a 0% discount.
     */
    public static function getBestDiscountPct(int $userId): float
    {
        static $cache = [];
        if (isset($cache[$userId])) return $cache[$userId];

        try {
            $row = Database::fetchOne(
                "SELECT MAX(g.DiscountPct) AS BestPct
                   FROM student_group_members m
                   JOIN student_groups g ON g.StudentGroupId = m.StudentGroupId
                  WHERE m.UserInfoId = ? AND g.IsActive = 'Y'",
                [$userId]);
            $cache[$userId] = (float)($row['BestPct'] ?? 0);
        } catch (Exception $e) {
            $cache[$userId] = 0.0;
        }
        return $cache[$userId];
    }

    /**
     * Apply the student's best group discount to a fee. Same return shape
     * as Institute::applyDiscount() so callers can treat them identically:
     *   ['final'=>float, 'discount'=>float, 'is_free'=>bool, 'rule'=>array|null, 'applied'=>bool]
     */
    public static function applyDiscount(int $userId, float $fee): array
    {
        $pct = self::getBestDiscountPct($userId);
        if ($pct <= 0) {
            return ['final' => $fee, 'discount' => 0.0, 'is_free' => false, 'rule' => null, 'applied' => false];
        }

        $discount = round($fee * $pct / 100, 2);
        $final    = max(0.0, $fee - $discount);
        return [
            'final'    => $final,
            'discount' => $discount,
            'is_free'  => ($final <= 0),
            'rule'     => ['DiscountPct' => $pct],
            'applied'  => true,
        ];
    }

    /** Which active groups a student belongs to (for admin/profile display). */
    public static function getGroupsForUser(int $userId): array
    {
        try {
            return Database::fetchAll(
                "SELECT g.* FROM student_group_members m
                   JOIN student_groups g ON g.StudentGroupId = m.StudentGroupId
                  WHERE m.UserInfoId = ? AND g.IsActive = 'Y'
               ORDER BY g.GroupName",
                [$userId]);
        } catch (Exception $e) {
            return [];
        }
    }

    /** Whether a student is a member of a specific group. */
    public static function isMember(int $groupId, int $userId): bool
    {
        try {
            $row = Database::fetchOne(
                "SELECT 1 FROM student_group_members WHERE StudentGroupId = ? AND UserInfoId = ? LIMIT 1",
                [$groupId, $userId]);
            return (bool)$row;
        } catch (Exception $e) {
            return false;
        }
    }

    // ── Self-registration via shareable code (migration_v68) ────────────────────

    /**
     * The safe alphabet used for generated codes: uppercase letters and
     * digits, minus 0/O and 1/I — the pair that's hardest to tell apart
     * when a code is read aloud, hand-written on a whiteboard, or retyped
     * from a printout, which is exactly how these codes get shared with a
     * classroom of students.
     */
    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /**
     * Look up an ACTIVE group by its shareable registration code. Returns
     * null if the code is blank, doesn't match any group, matches an
     * inactive group (a deactivated group shouldn't keep accepting
     * self-joins), or the column doesn't exist yet (migration_v68 not run).
     */
    public static function findByCode(string $code): ?array
    {
        $code = trim($code);
        if ($code === '' || !Database::hasColumn('student_groups', 'StudentGroupCode')) {
            return null;
        }
        try {
            return Database::fetchOne(
                "SELECT * FROM student_groups WHERE StudentGroupCode = ? AND IsActive = 'Y' LIMIT 1",
                [$code]
            ) ?: null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Generate a fresh, guaranteed-unique registration code (not yet saved
     * to any group — the caller UPDATEs student_groups with the result).
     * Collisions are astronomically unlikely at 8 chars from a 33-symbol
     * alphabet (33^8 ≈ 1.2×10^12 combinations) but this still checks and
     * retries rather than trusting the odds, since a duplicate code would
     * silently register students into the wrong group.
     */
    public static function generateCode(int $length = 8): string
    {
        $alphabet   = self::CODE_ALPHABET;
        $alphabetLen = strlen($alphabet);
        $attempts   = 0;

        do {
            $code = '';
            for ($i = 0; $i < $length; $i++) {
                $code .= $alphabet[random_int(0, $alphabetLen - 1)];
            }
            $attempts++;
            $taken = Database::fetchOne(
                "SELECT 1 FROM student_groups WHERE StudentGroupCode = ? LIMIT 1", [$code]
            );
        } while ($taken && $attempts < 20);

        return $code;
    }

    /**
     * Enroll a freshly self-registered student into the group identified by
     * a registration code, then fan out both exam-assignment bridges
     * exactly like the admin "Add Students" bulk-add flow
     * (Admin/StudentGroupMembers.php) does — so a self-joined student
     * automatically picks up any exam already recommended to, or directly
     * assigned to, the group. Idempotent: INSERT IGNORE means calling this
     * twice for the same student/group is harmless.
     *
     * @param string $code   The registration code from the shared URL.
     * @param int    $userId The new student's UserInfoId (already inserted
     *                       into userinfo by the caller).
     * @return array{ok:bool, group:?array, assignedCount:int}
     *   ok=false + group=null means the code didn't match any active group
     *   (caller should not block registration on this — the account is
     *   still created either way, see auth/register-group.php).
     */
    public static function enrollSelfByCode(string $code, int $userId): array
    {
        $group = self::findByCode($code);
        if (!$group || $userId <= 0) {
            return ['ok' => false, 'group' => null, 'assignedCount' => 0];
        }

        $gid = (int)$group['StudentGroupId'];
        try {
            Database::execute(
                "INSERT IGNORE INTO student_group_members (StudentGroupId, UserInfoId, AddedBy) VALUES (?, ?, ?)",
                [$gid, $userId, 'Self-registration (group code)']
            );
        } catch (Exception $e) {
            return ['ok' => false, 'group' => $group, 'assignedCount' => 0];
        }

        // AssignedBy = 0: no admin performed this action, matching the
        // "system" sentinel these two bridge functions already default to.
        $assignedCount = self::syncAssignments($gid, $userId, 0)
                        + self::syncDirectAssignments($gid, $userId, 0);

        return ['ok' => true, 'group' => $group, 'assignedCount' => $assignedCount];
    }

    /**
     * ExamInfoIds recommended/assigned to any of this student's active
     * groups — purely for a "Recommended for you" display badge, not an
     * access gate. Empty array if the student isn't in any group or
     * migration_v53 hasn't run.
     */
    public static function getRecommendedExamIds(int $userId): array
    {
        try {
            $rows = Database::fetchAll(
                "SELECT DISTINCT a.ExamInfoId
                   FROM student_group_members m
                   JOIN student_groups g ON g.StudentGroupId = m.StudentGroupId
                   JOIN student_group_exam_assignments a ON a.StudentGroupId = g.StudentGroupId
                  WHERE m.UserInfoId = ? AND g.IsActive = 'Y'",
                [$userId]);
            return array_map('intval', array_column($rows, 'ExamInfoId'));
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Names of the group(s) recommending a specific exam to a specific
     * student — used to label the badge (e.g. "Recommended for JEE Batch A").
     */
    public static function getRecommendingGroupNames(int $userId, int $examId): array
    {
        try {
            $rows = Database::fetchAll(
                "SELECT DISTINCT g.GroupName
                   FROM student_group_members m
                   JOIN student_groups g ON g.StudentGroupId = m.StudentGroupId
                   JOIN student_group_exam_assignments a ON a.StudentGroupId = g.StudentGroupId
                  WHERE m.UserInfoId = ? AND g.IsActive = 'Y' AND a.ExamInfoId = ?
               ORDER BY g.GroupName",
                [$userId, $examId]);
            return array_column($rows, 'GroupName');
        } catch (Exception $e) {
            return [];
        }
    }

    // ── Group → individual exam_assignments bridge ──────────────────────────────

    /**
     * Create real exam_assignments rows for exams recommended to a group, so
     * group members don't need to be assigned one-by-one via exam/assign.php.
     *
     * Set-based, idempotent (safe to call repeatedly) and conservative on
     * purpose: never touches a row that already exists for a given
     * (ExamInfoId, UserInfoId) pair regardless of its status — no duplicates
     * (exam_assignments has no unique key), and no silently reopening a
     * Completed assignment via this bulk path.
     *
     * @param int      $groupId           Group whose recommended exams to sync.
     * @param int|null $onlyUserId        If given, sync only this member (e.g. just added to the group).
     *                                    If null, sync every current member (e.g. exam just assigned to the group).
     * @param int      $assignedByLoginId Auth::currentLoginId() of the admin performing the action.
     * @return int Number of new exam_assignments rows created.
     */
    public static function syncAssignments(int $groupId, ?int $onlyUserId = null, int $assignedByLoginId = 0): int
    {
        try {
            $params = [$assignedByLoginId, $groupId];
            $userFilter = '';
            if ($onlyUserId !== null) {
                $userFilter = 'AND m.UserInfoId = ?';
                $params[] = $onlyUserId;
            }
            return Database::execute(
                "INSERT INTO exam_assignments (ExamInfoId, UserInfoId, AssignedBy, AssignedAt, Status)
                 SELECT a.ExamInfoId, m.UserInfoId, ?, NOW(), 'Assigned'
                   FROM student_group_exam_assignments a
                   JOIN student_group_members m ON m.StudentGroupId = a.StudentGroupId
                  WHERE a.StudentGroupId = ? $userFilter
                    AND NOT EXISTS (
                          SELECT 1 FROM exam_assignments ea
                           WHERE ea.ExamInfoId = a.ExamInfoId AND ea.UserInfoId = m.UserInfoId
                        )",
                $params);
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Revoke exam_assignments rows that exist only because of a group
     * recommendation which has now gone away (exam unassigned from a group,
     * or a student removed from a group) — but only if the assignment was
     * never attempted, and only if no OTHER active group still recommends
     * that exam to that student.
     *
     * "Never attempted" = StudentExamId IS NULL AND Status <> 'Completed',
     * mirroring the reopen/skip convention already used by exam/assign.php.
     * Attempted or completed assignments are always left untouched.
     *
     * @param int[] $userIds Candidate UserInfoIds to re-check (e.g. the group's members).
     * @param int[] $examIds Candidate ExamInfoIds to re-check (e.g. the group's recommended exams).
     * @return int Number of exam_assignments rows deleted.
     */
    public static function pruneOrphanedAssignments(array $userIds, array $examIds): int
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        $examIds = array_values(array_unique(array_map('intval', $examIds)));
        if (!$userIds || !$examIds) return 0;

        try {
            $userPh = implode(',', array_fill(0, count($userIds), '?'));
            $examPh = implode(',', array_fill(0, count($examIds), '?'));
            return Database::execute(
                "DELETE ea FROM exam_assignments ea
                  WHERE ea.UserInfoId IN ($userPh)
                    AND ea.ExamInfoId IN ($examPh)
                    AND ea.StudentExamId IS NULL
                    AND ea.Status <> 'Completed'
                    AND NOT EXISTS (
                          SELECT 1 FROM student_group_members m
                            JOIN student_groups g ON g.StudentGroupId = m.StudentGroupId
                            JOIN student_group_exam_assignments a ON a.StudentGroupId = g.StudentGroupId
                           WHERE m.UserInfoId = ea.UserInfoId AND a.ExamInfoId = ea.ExamInfoId AND g.IsActive = 'Y'
                        )",
                array_merge($userIds, $examIds));
        } catch (Exception $e) {
            return 0;
        }
    }

    // ── Group → individual exam_assignments bridge, DIRECT (real access grant) ──
    // migration_v67's student_group_exam_assignments counterpart: while that
    // table only tags an exam "Recommended" (still paid, no access granted on
    // its own — see file docblock), student_group_direct_assignments records a
    // genuine access grant made via exam/assign.php's "Assign Entire Group"
    // action. Same idempotent/conservative shape as syncAssignments() /
    // pruneOrphanedAssignments() above, just reading from the direct-assignment
    // table and (unlike the recommended-only bridge) propagating DueDate.

    /**
     * Create real exam_assignments rows for exams DIRECTLY assigned to a
     * group (student_group_direct_assignments), so group members — including
     * ones added after the group assignment was made — automatically get
     * access without an admin re-assigning them one-by-one.
     *
     * @param int      $groupId           Group whose direct assignments to sync.
     * @param int|null $onlyUserId        If given, sync only this member (e.g. just added to the group).
     *                                    If null, sync every current member (e.g. exam just assigned to the group).
     * @param int      $assignedByLoginId Auth::currentLoginId() of the admin performing the action.
     * @return int Number of new exam_assignments rows created.
     */
    public static function syncDirectAssignments(int $groupId, ?int $onlyUserId = null, int $assignedByLoginId = 0): int
    {
        try {
            $params = [$assignedByLoginId, $groupId];
            $userFilter = '';
            if ($onlyUserId !== null) {
                $userFilter = 'AND m.UserInfoId = ?';
                $params[] = $onlyUserId;
            }
            return Database::execute(
                "INSERT INTO exam_assignments (ExamInfoId, UserInfoId, AssignedBy, AssignedAt, DueDate, Status)
                 SELECT a.ExamInfoId, m.UserInfoId, ?, NOW(), a.DueDate, 'Assigned'
                   FROM student_group_direct_assignments a
                   JOIN student_group_members m ON m.StudentGroupId = a.StudentGroupId
                  WHERE a.StudentGroupId = ? $userFilter
                    AND NOT EXISTS (
                          SELECT 1 FROM exam_assignments ea
                           WHERE ea.ExamInfoId = a.ExamInfoId AND ea.UserInfoId = m.UserInfoId
                        )",
                $params);
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Revoke exam_assignments rows that exist only because of a DIRECT group
     * assignment which has now gone away (exam unassigned from a group via
     * exam/assign.php, or a student removed from a group) — but only if the
     * assignment was never attempted, and only if no OTHER active group's
     * direct assignment still covers that exam for that student.
     *
     * @param int[] $userIds Candidate UserInfoIds to re-check (e.g. the group's members).
     * @param int[] $examIds Candidate ExamInfoIds to re-check (e.g. the group's directly-assigned exams).
     * @return int Number of exam_assignments rows deleted.
     */
    public static function pruneOrphanedDirectAssignments(array $userIds, array $examIds): int
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        $examIds = array_values(array_unique(array_map('intval', $examIds)));
        if (!$userIds || !$examIds) return 0;

        try {
            $userPh = implode(',', array_fill(0, count($userIds), '?'));
            $examPh = implode(',', array_fill(0, count($examIds), '?'));
            return Database::execute(
                "DELETE ea FROM exam_assignments ea
                  WHERE ea.UserInfoId IN ($userPh)
                    AND ea.ExamInfoId IN ($examPh)
                    AND ea.StudentExamId IS NULL
                    AND ea.Status <> 'Completed'
                    AND NOT EXISTS (
                          SELECT 1 FROM student_group_members m
                            JOIN student_groups g ON g.StudentGroupId = m.StudentGroupId
                            JOIN student_group_direct_assignments a ON a.StudentGroupId = g.StudentGroupId
                           WHERE m.UserInfoId = ea.UserInfoId AND a.ExamInfoId = ea.ExamInfoId AND g.IsActive = 'Y'
                        )",
                array_merge($userIds, $examIds));
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Groups that directly-assigned (real access grant) a specific exam, with
     * member counts — for the "Assigned to Groups" panel on exam/assign.php.
     */
    public static function getDirectAssigningGroups(int $examId): array
    {
        try {
            return Database::fetchAll(
                "SELECT g.StudentGroupId, g.GroupName, a.DueDate, a.AssignedAt,
                        (SELECT COUNT(*) FROM student_group_members m WHERE m.StudentGroupId = g.StudentGroupId) AS MemberCount
                   FROM student_group_direct_assignments a
                   JOIN student_groups g ON g.StudentGroupId = a.StudentGroupId
                  WHERE a.ExamInfoId = ?
               ORDER BY g.GroupName",
                [$examId]);
        } catch (Exception $e) {
            return [];
        }
    }
}
