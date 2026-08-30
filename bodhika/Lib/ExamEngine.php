<?php
/**
 * Lib/ExamEngine.php — Exam listing, attempt, and scoring logic for the
 * mobile REST API.
 *
 * This is a clean-room re-implementation of the logic in exam/search.php,
 * exam/write.php and exam/submit.php — informed by those files (the eligibility
 * rules, question-selection rules, and scoring rules below are intentionally
 * identical to what the web app does) but NOT a refactor of them in place.
 * Those three files are working in production and there is no PHP runtime
 * available in this environment to test changes against a live database, so
 * editing them directly to also serve JSON carried unacceptable regression
 * risk. The API gets its own faithful copy of the rules instead.
 *
 * KNOWN DUPLICATION — flagged for a future cleanup once there's a way to test
 * both call paths end-to-end: the eligibility/question-selection/scoring rules
 * here and in exam/write.php + exam/submit.php must be kept in sync by hand.
 * If you change one, change the other.
 *
 * Requires Config.php, Database.php, Auth.php, Enrollment.php, Marking.php,
 * Institute.php, SignedToken.php to already be loaded.
 */
final class ExamEngine
{
    // ── Student exam list (mirrors exam/search.php's student branch) ────────

    public static function listAvailableExams(int $userId): array
    {
        $myInstituteId = Institute::getInstituteId($userId);

        // 1. Admin-assigned exams
        $assignedExams = [];
        $assignedIds   = [];
        try {
            $assignedExams = Database::fetchAll(
                "SELECT e.*, ea.AssignmentId, ea.Status AS AssignStatus, ea.DueDate,
                        ea.StudentExamId AS AsgStudentExamId
                   FROM examinfo e
                   JOIN exam_assignments ea ON ea.ExamInfoId = e.ExamInfoId
                  WHERE ea.UserInfoId = ? AND COALESCE(e.IsActive,'Y') = 'Y'
                    AND COALESCE(e.IsDeleted,'N') = 'N'
                  ORDER BY ea.Status ASC, ea.AssignedAt DESC",
                [$userId]
            );
            $assignedIds = array_column($assignedExams, 'ExamInfoId');
        } catch (Exception $e) { /* exam_assignments not yet created */ }

        // 2. Self-enrolled (migration_v51 — pricing is exam-level only). A
        //    Paid/Waived/Free row in exam_fee_payments for THIS exam
        //    specifically; there is no more subject-wide enrollment that
        //    unlocks every exam under a subject.
        $selfEnrolled = [];
        try {
            $selfEnrolled = Database::fetchAll(
                "SELECT e.*, NULL AS AssignmentId, 'SelfEnrolled' AS AssignStatus,
                        NULL AS DueDate, NULL AS AsgStudentExamId
                   FROM examinfo e
                   JOIN exam_fee_payments efp ON efp.ExamInfoId = e.ExamInfoId
                  WHERE efp.UserInfoId = ?
                    AND efp.PaymentStatus IN ('Paid','Waived','Free')
                    AND COALESCE(e.IsActive,'Y') = 'Y'
                    AND COALESCE(e.IsDeleted,'N') = 'N'
                  ORDER BY e.ExamInfoId DESC",
                [$userId]
            );
            if ($assignedIds) {
                $selfEnrolled = array_values(array_filter(
                    $selfEnrolled, fn($ex) => !in_array($ex['ExamInfoId'], $assignedIds)
                ));
            }
        } catch (Exception $e) { /* migration_v50/v51 not yet run */ }

        // (Removed) "Free exams — no enrollment row needed" block. Previously
        // any ExamScope='All' exam whose subject had a ₹0 fee was listed and
        // made instantly attemptable for every student with zero action —
        // effectively auto-assigning it to the whole user base. An exam (free
        // or not) now only ever appears here via an explicit admin assignment
        // or an explicit self-enrollment record (including a ₹0 "Free" one
        // created deliberately, mirrored from the mobile client's own
        // enroll flow). See Lib/Enrollment.php::canAccess().

        $exams = array_merge($assignedExams, $selfEnrolled);
        if (!$exams) return [];

        // Scholarship lookup (still a per-student blanket waiver, independent
        // of any single exam's price)
        $scholarshipUser = false;
        try {
            $uRow = Database::fetchOne("SELECT ScholarshipFlag FROM userinfo WHERE UserInfoId = ? LIMIT 1", [$userId]);
            $scholarshipUser = (($uRow['ScholarshipFlag'] ?? 'N') === 'Y');
        } catch (Exception $e) {}

        // Per-exam fee payments (migration_v50/v51) — ExamInfoId -> row. Every
        // exam prices and gates itself now; there is no subject-wide fallback.
        $examPayMap = [];
        $examIdsForFee = array_values(array_unique(array_column($exams, 'ExamInfoId')));
        if ($examIdsForFee) {
            $efph = implode(',', array_fill(0, count($examIdsForFee), '?'));
            try {
                foreach (Database::fetchAll(
                    "SELECT ExamInfoId, PaymentStatus, EndDate FROM exam_fee_payments
                      WHERE UserInfoId = ? AND ExamInfoId IN ($efph)",
                    array_merge([$userId], $examIdsForFee)) as $er) {
                    $examPayMap[(int)$er['ExamInfoId']] = $er;
                }
            } catch (Exception $e) { /* migration_v50/v51 not yet run */ }
        }

        // Attempt limits (migration_v36)
        $examIds = array_values(array_unique(array_column($exams, 'ExamInfoId')));
        $usedMap = $overrideMap = [];
        if ($examIds) {
            $eph = implode(',', array_fill(0, count($examIds), '?'));
            try {
                foreach (Database::fetchAll(
                    "SELECT ExamInfoId, COUNT(*) AS c FROM studentexam
                      WHERE UserInfoId = ? AND ExamInfoId IN ($eph) GROUP BY ExamInfoId",
                    array_merge([$userId], $examIds)) as $ur) {
                    $usedMap[(int)$ur['ExamInfoId']] = (int)$ur['c'];
                }
            } catch (Exception $e) {}
            try {
                foreach (Database::fetchAll(
                    "SELECT ExamInfoId, MaxAttempts FROM exam_attempt_overrides
                      WHERE UserInfoId = ? AND ExamInfoId IN ($eph)",
                    array_merge([$userId], $examIds)) as $or) {
                    $overrideMap[(int)$or['ExamInfoId']] = (int)$or['MaxAttempts'];
                }
            } catch (Exception $e) {}
        }

        $out = [];
        foreach ($exams as $ex) {
            $examId    = (int)$ex['ExamInfoId'];
            $subjectId = (int)($ex['SubjectInfoId'] ?? 0);
            $isAssigned = (($ex['AssignStatus'] ?? null) !== null && ($ex['AssignStatus'] ?? null) !== 'SelfEnrolled');

            // migration_v51: every exam prices itself — ExamFee/ExamDiscountPct
            // come straight off $ex (SELECT e.* above), no subject fallback.
            $fee  = (float)($ex['ExamFee'] ?? 0);
            $disc = (float)($ex['ExamDiscountPct'] ?? 0);
            $finalFee = round($fee * (1 - $disc / 100), 2);

            $examPay = $examPayMap[$examId] ?? null;
            $paymentActive = $examPay
                && in_array($examPay['PaymentStatus'], ['Paid', 'Waived', 'Free'], true)
                && (empty($examPay['EndDate']) || $examPay['EndDate'] >= date('Y-m-d'));

            // NOTE: "$fee <= 0" IS enough on its own — a real, explicit
            // per-exam value (post-migration_v51 every exam has one), not an
            // invisible inherited default. Mirrors Lib/Enrollment.php::canAccess().
            $isFree = $isAssigned || $fee <= 0 || $scholarshipUser || $paymentActive;
            $paymentStatusOut = $examPay['PaymentStatus'] ?? ($isFree ? 'Free' : 'Unpaid');

            $max  = $overrideMap[$examId] ?? (int)($ex['MaxAttempts'] ?? 5);
            $used = $usedMap[$examId] ?? 0;
            $unlimited = ($max === 0);

            // ExamCategory (migration_v55) — free-text exam type (NEET/JEE/...
            // or custom). $ex already carries it via "SELECT e.*" above when
            // the column exists; absent on older DBs, hence the ?? fallback.
            // ExamType::flag()/color() degrade gracefully for blank/custom
            // values too, so this is safe to compute unconditionally.
            $category = trim((string)($ex['ExamCategory'] ?? '')) ?: 'Uncategorized';

            $out[] = [
                'examId'         => $examId,
                'examName'       => $ex['ExamName'] ?? '',
                'gradeId'        => (int)($ex['GradeInfoId'] ?? 0),
                'subjectId'      => $subjectId,
                'examCategory'      => $category,
                'examCategoryFlag'  => ExamType::flag($category),
                'examCategoryColor' => ExamType::color($category),
                'numQuestions'   => (int)($ex['NumOfQuestions'] ?? 0),
                'timeAllotedMin' => (int)($ex['TimeAlloted'] ?? 30),
                // MinPassing is stored as a 0-100 percent; clamp defensively — same
                // guard used when actually scoring an attempt (see resolveDetail()
                // below and exam/submit.php) — so a legacy/misconfigured value can
                // never surface as an impossible pass requirement to the client.
                'minPassingPct'  => min(100, max(0, (int)($ex['MinPassing'] ?? 0))),
                'assignmentStatus' => $ex['AssignStatus'] ?? 'SelfEnrolled',
                'dueDate'        => $ex['DueDate'] ?? null,
                'isFree'         => $isFree,
                'fee'            => $fee,
                'discountPct'    => $disc,
                'finalFee'       => $isFree ? 0.0 : $finalFee,
                'paymentStatus'  => $paymentStatusOut,
                'attemptsUsed'   => $used,
                'attemptsMax'    => $unlimited ? null : $max,
                'attemptsLeft'   => $unlimited ? null : max(0, $max - $used),
                'canAttempt'     => $unlimited || $used < $max,
            ];
        }
        return $out;
    }

    // ── Single exam detail ───────────────────────────────────────────────────

    public static function getExamDetail(int $examId, int $userId): ?array
    {
        $exam = Database::fetchOne(
            "SELECT e.*, g.GradeName, s.SubjectName
               FROM examinfo e
          LEFT JOIN gradeinfo   g ON g.GradeInfoId   = e.GradeInfoId
          LEFT JOIN subjectinfo s ON s.SubjectInfoId = e.SubjectInfoId
              WHERE e.ExamInfoId = ? AND COALESCE(e.IsDeleted,'N') = 'N' LIMIT 1", [$examId]
        );
        if (!$exam) return null;

        $access = Enrollment::canAccess($examId, $userId);
        $attemptStatus = Enrollment::getAttemptStatus($examId, $userId);

        // Multi-subject exam (migration_v54) — e.g. a NEET paper. [] for
        // every exam that hasn't opted in, which is every exam that existed
        // before this feature.
        $examSections = self::loadExamSections($examId, $exam);
        $category     = trim((string)($exam['ExamCategory'] ?? '')) ?: 'Uncategorized';

        return [
            'examId'         => $examId,
            'examName'       => $exam['ExamName'] ?? '',
            'gradeName'      => $exam['GradeName'] ?? '',
            'subjectName'    => $exam['SubjectName'] ?? '',
            'examCategory'      => $category,
            'examCategoryFlag'  => ExamType::flag($category),
            'examCategoryColor' => ExamType::color($category),
            'numQuestions'   => $examSections
                                ? (int)array_sum(array_column($examSections, 'NumOfQuestions'))
                                : (int)($exam['NumOfQuestions'] ?? 0),
            'timeAllotedMin' => (int)($exam['TimeAlloted'] ?? 30),
            // Clamp for the same reason as listExams() above — 0-100 percent only.
            'minPassingPct'  => min(100, max(0, (int)($exam['MinPassing'] ?? 0))),
            'hasAccess'      => $access,
            'attemptStatus'  => $attemptStatus,
            'isMultiSubject' => !empty($examSections),
            'sections'       => array_map(fn($s) => [
                'subjectName'  => $s['SectionLabel'] ?: ($s['SubjectName'] ?? 'Subject'),
                'numQuestions' => (int)$s['NumOfQuestions'],
            ], $examSections),
        ];
    }

    // ── Start an attempt: access checks + lazy enrollment + question pick ───

    public static function startAttempt(int $examId, int $userId): array
    {
        try {
            $asgRow = Database::fetchOne(
                "SELECT Status FROM exam_assignments WHERE ExamInfoId = ? AND UserInfoId = ? LIMIT 1",
                [$examId, $userId]
            );
        } catch (Exception $e) { $asgRow = null; }
        if ($asgRow && $asgRow['Status'] === 'Completed') {
            return ['ok' => false, 'code' => 'ALREADY_COMPLETED', 'error' => 'You have already completed this exam.'];
        }

        if (!Enrollment::canAccess($examId, $userId)) {
            return ['ok' => false, 'code' => 'NOT_ENROLLED', 'error' => 'You are not enrolled in this exam yet.'];
        }

        $attemptStatus = Enrollment::getAttemptStatus($examId, $userId);
        if (!$attemptStatus['allowed']) {
            return [
                'ok' => false, 'code' => 'ATTEMPT_LIMIT_REACHED',
                'error' => "You've used all {$attemptStatus['max']} of your allowed attempts.",
                'attemptStatus' => $attemptStatus,
            ];
        }

        self::lazyEnrollFree($examId, $userId);

        $exam = Database::fetchOne(
            "SELECT e.*, g.GradeName, s.SubjectName
               FROM examinfo e
          LEFT JOIN gradeinfo   g ON g.GradeInfoId   = e.GradeInfoId
          LEFT JOIN subjectinfo s ON s.SubjectInfoId = e.SubjectInfoId
              WHERE e.ExamInfoId = ? AND COALESCE(e.IsDeleted,'N') = 'N' LIMIT 1", [$examId]
        );
        if (!$exam) return ['ok' => false, 'code' => 'EXAM_NOT_FOUND', 'error' => 'Exam not found.'];

        // Multi-subject exam (migration_v54) — e.g. a NEET paper: draw each
        // subject section separately instead of one flat pool. [] for every
        // exam that hasn't opted in, so numQuestions/pickQuestions() behave
        // exactly as before for every existing exam.
        $examSections = self::loadExamSections($examId, $exam);
        $numQuestions = $examSections
            ? (int)array_sum(array_column($examSections, 'NumOfQuestions'))
            : (int)$exam['NumOfQuestions'];
        $questions    = self::pickQuestions($examId, $userId, $numQuestions, $examSections);
        if (!$questions) {
            return ['ok' => false, 'code' => 'NO_QUESTIONS', 'error' => 'This exam has no active questions yet.'];
        }

        $timeAllotedMin = (int)($exam['TimeAlloted'] ?? 30);
        $ttl = $timeAllotedMin * 60 + API_ATTEMPT_GRACE_SECONDS;
        $attemptToken = SignedToken::encode([
            'examId'    => $examId,
            'userId'    => $userId,
            'startedAt' => time(),
            'qids'      => array_column($questions, 'questionId'),
        ], $ttl);

        return [
            'ok' => true,
            'attemptToken'   => $attemptToken,
            'examId'         => $examId,
            'examName'       => $exam['ExamName'] ?? '',
            'gradeName'      => $exam['GradeName'] ?? '',
            'subjectName'    => $exam['SubjectName'] ?? '',
            'numQuestions'   => count($questions),
            'timeAllotedMin' => $timeAllotedMin,
            'serverTime'     => time(),
            'isMultiSubject' => !empty($examSections),
            'questions'      => $questions,
        ];
    }

    /**
     * Mirrors exam/write.php's lazy-enrollment step for free exams
     * (migration_v51: writes to exam_fee_payments, not the retired
     * subject-wide enrollment_payments). Purely bookkeeping — by the time
     * this runs, Enrollment::canAccess() has already granted access via
     * assignment/scholarship/institute-free/₹0 fee, so this just backfills a
     * record for history/consistency.
     */
    private static function lazyEnrollFree(int $examId, int $userId): void
    {
        try {
            $row = Database::fetchOne(
                "SELECT COALESCE(ExamFee, 0) AS ExamFee FROM examinfo WHERE ExamInfoId = ? LIMIT 1", [$examId]
            );
            if ($row && (float)$row['ExamFee'] <= 0) {
                Database::execute(
                    "INSERT IGNORE INTO exam_fee_payments
                     (ExamInfoId, UserInfoId, FeeAtTime, FinalAmount, PaymentStatus, StartDate)
                     VALUES (?, ?, 0, 0, 'Free', CURDATE())",
                    [$examId, $userId]
                );
            }
        } catch (Exception $e) { /* exam_fee_payments not yet created — skip */ }
    }

    /**
     * Mirrors exam/write.php's question-selection block: excludes questions
     * the student saw in their last 5 attempts (only when enough fresh
     * questions remain), random order, with the same 3-tier schema fallback.
     * Returns a CLIENT-SAFE payload — no CorrectAnswer / YesNo1-4 /
     * MatchCorrect1-4 fields, since this is sent to the device before the
     * student answers.
     */
    private static function pickQuestions(int $examId, int $userId, int $numQuestions, array $examSections = []): array
    {
        $recentQids = [];
        try {
            $prevAttempts = Database::fetchAll(
                "SELECT StudentExamId FROM studentexam
                  WHERE ExamInfoId = ? AND UserInfoId = ? ORDER BY CreateDate DESC LIMIT 5",
                [$examId, $userId]
            );
            if ($prevAttempts) {
                $seIds = array_column($prevAttempts, 'StudentExamId');
                $sePh  = implode(',', array_fill(0, count($seIds), '?'));
                $recentQids = array_column(Database::fetchAll(
                    "SELECT DISTINCT QuestionId FROM studentexamresults WHERE StudentExamId IN ($sePh)", $seIds
                ), 'QuestionId');
            }
        } catch (Exception $e) { /* no history tables yet */ }

        $excludeSql = '';
        $excludeParams = [];
        if ($recentQids) {
            $exPh = implode(',', array_fill(0, count($recentQids), '?'));
            try {
                $freshRow = Database::fetchOne(
                    "SELECT COUNT(*) AS cnt FROM exam_questions eq
                       JOIN questions q ON q.QuestionId = eq.QuestionId
                      WHERE eq.ExamInfoId = ? AND COALESCE(eq.IsActive,'Y') = 'Y'
                        AND COALESCE(q.IsDeleted,'N') = 'N'
                        AND q.QuestionId NOT IN ($exPh)",
                    array_merge([$examId], $recentQids)
                );
            } catch (Exception $e) {
                try {
                    $freshRow = Database::fetchOne(
                        "SELECT COUNT(*) AS cnt FROM questions q
                          WHERE q.ExamInfoId = ? AND COALESCE(q.IsActive,'Y') = 'Y'
                            AND COALESCE(q.IsDeleted,'N') = 'N'
                            AND q.QuestionId NOT IN ($exPh)",
                        array_merge([$examId], $recentQids)
                    );
                } catch (Exception $e2) { $freshRow = null; }
            }
            if ((int)($freshRow['cnt'] ?? 0) >= $numQuestions) {
                $excludeSql = " AND q.QuestionId NOT IN ($exPh)";
                $excludeParams = $recentQids;
            }
        }

        // Multi-subject exam (migration_v54): draw per-subject-section
        // instead of one flat pool — mirrors exam/write.php's sectioned
        // branch. $examSections is empty for every exam that hasn't opted
        // into IsMultiSubject='Y', so this is a no-op for all existing exams.
        if ($examSections) {
            return self::pickSectionedQuestions($examId, $examSections, $excludeSql, $excludeParams);
        }

        // Pick questions WITHOUT ORDER BY RAND(): fetch matching QuestionIds
        // only (index-friendly, no filesort), shuffle in PHP, then fetch full
        // rows for just the chosen ids. ORDER BY RAND() LIMIT forces MySQL to
        // generate a random value and sort EVERY matching row on EVERY exam
        // start — doesn't scale with question-bank size or with concurrent
        // starts (e.g. a batch of students starting a scheduled mock test at
        // the same time, all hitting this query at once).
        $idParams = array_merge([$examId], $excludeParams);
        $rows = [];
        try {
            $idRows = Database::fetchAll(
                "SELECT q.QuestionId
                   FROM exam_questions eq
                   JOIN questions q ON q.QuestionId = eq.QuestionId
                  WHERE eq.ExamInfoId = ? AND COALESCE(eq.IsActive,'Y') = 'Y'
                    AND COALESCE(q.IsDeleted,'N') = 'N'" . $excludeSql,
                $idParams
            );
            $pickedIds = self::sampleQuestionIds($idRows, $numQuestions);
            if ($pickedIds) {
                [$orderSql, $orderParams] = self::orderByFieldClause('q.QuestionId', $pickedIds);
                $ph = implode(',', array_fill(0, count($pickedIds), '?'));
                $rows = Database::fetchAll(
                    "SELECT q.QuestionId, q.QuestionDesc, q.ImageInd, q.ImageLoc, q.NumofImages,
                            COALESCE(q.QuestionType, 'MCQ') AS QuestionType,
                            COALESCE(q.ExpectedAnswerCount, 0) AS ExpectedAnswerCount,
                            a.AnswerId, a.Answer1, a.Answer2, a.Answer3, a.Answer4,
                            a.AnsImageInd, a.MultiImageInd,
                            COALESCE(a.NumStatements, 4) AS NumStatements,
                            a.MatchStatement1, a.MatchStatement2, a.MatchStatement3, a.MatchStatement4
                       FROM questions q
                  LEFT JOIN answers   a ON a.QuestionId = q.QuestionId
                      WHERE q.QuestionId IN ($ph)" . $orderSql,
                    array_merge($pickedIds, $orderParams)
                );
            }
        } catch (Exception $e) {
            $legacyIdParams = array_merge([$examId], $excludeParams);
            try {
                $idRows = Database::fetchAll(
                    "SELECT q.QuestionId
                       FROM questions q
                      WHERE q.ExamInfoId = ?" . $excludeSql . "
                        AND COALESCE(q.IsActive,'Y') = 'Y'
                        AND COALESCE(q.IsDeleted,'N') = 'N'",
                    $legacyIdParams
                );
                $pickedIds = self::sampleQuestionIds($idRows, $numQuestions);
                $rows = [];
                if ($pickedIds) {
                    [$orderSql, $orderParams] = self::orderByFieldClause('q.QuestionId', $pickedIds);
                    $ph = implode(',', array_fill(0, count($pickedIds), '?'));
                    $rows = Database::fetchAll(
                        "SELECT q.QuestionId,
                                COALESCE(sq.QuestionDesc, q.QuestionDesc) AS QuestionDesc,
                                COALESCE(sq.ImageInd, q.ImageInd) AS ImageInd,
                                COALESCE(sq.ImageLoc, q.ImageLoc) AS ImageLoc,
                                COALESCE(sq.NumofImages, q.NumofImages) AS NumofImages,
                                COALESCE(sq.QuestionType, q.QuestionType, 'MCQ') AS QuestionType,
                                COALESCE(sq.ExpectedAnswerCount, q.ExpectedAnswerCount, 0) AS ExpectedAnswerCount,
                                COALESCE(sa.AnswerId, a.AnswerId) AS AnswerId,
                                COALESCE(sa.Answer1, a.Answer1) AS Answer1,
                                COALESCE(sa.Answer2, a.Answer2) AS Answer2,
                                COALESCE(sa.Answer3, a.Answer3) AS Answer3,
                                COALESCE(sa.Answer4, a.Answer4) AS Answer4,
                                COALESCE(sa.AnsImageInd, a.AnsImageInd) AS AnsImageInd,
                                COALESCE(sa.MultiImageInd, a.MultiImageInd) AS MultiImageInd,
                                COALESCE(sa.NumStatements, a.NumStatements, 4) AS NumStatements,
                                COALESCE(sa.MatchStatement1, a.MatchStatement1) AS MatchStatement1,
                                COALESCE(sa.MatchStatement2, a.MatchStatement2) AS MatchStatement2,
                                COALESCE(sa.MatchStatement3, a.MatchStatement3) AS MatchStatement3,
                                COALESCE(sa.MatchStatement4, a.MatchStatement4) AS MatchStatement4
                           FROM questions q
                      LEFT JOIN questions sq ON sq.QuestionId = q.LinkedFromQuestionId
                      LEFT JOIN answers   a  ON a.QuestionId  = q.QuestionId
                      LEFT JOIN answers   sa ON sa.QuestionId = q.LinkedFromQuestionId
                          WHERE q.QuestionId IN ($ph)" . $orderSql,
                        array_merge($pickedIds, $orderParams)
                    );
                }
            } catch (Exception $e2) {
                $idRows = Database::fetchAll(
                    "SELECT q.QuestionId
                       FROM answers a JOIN questions q ON a.QuestionId = q.QuestionId
                      WHERE q.ExamInfoId = ?",
                    [$examId]
                );
                $pickedIds = self::sampleQuestionIds($idRows, $numQuestions);
                $rows = [];
                if ($pickedIds) {
                    [$orderSql, $orderParams] = self::orderByFieldClause('q.QuestionId', $pickedIds);
                    $ph = implode(',', array_fill(0, count($pickedIds), '?'));
                    $rows = Database::fetchAll(
                        "SELECT a.AnswerId, a.Answer1, a.Answer2, a.Answer3, a.Answer4,
                                a.AnsImageInd, a.MultiImageInd,
                                q.QuestionId, q.QuestionDesc, q.ImageInd, q.ImageLoc, q.NumofImages,
                                'MCQ' AS QuestionType, 0 AS ExpectedAnswerCount, 4 AS NumStatements
                           FROM answers a JOIN questions q ON a.QuestionId = q.QuestionId
                          WHERE q.QuestionId IN ($ph)" . $orderSql,
                        array_merge($pickedIds, $orderParams)
                    );
                }
            }
        }

        // Batch-load per-answer images (answerimages table)
        $answerIds = array_filter(array_column($rows, 'AnswerId'));
        $answerImages = [];
        if ($answerIds) {
            $aph = implode(',', array_fill(0, count($answerIds), '?'));
            try {
                foreach (Database::fetchAll(
                    "SELECT AnswerId, AnswerImage1Loc, AnswerImage2Loc, AnswerImage3Loc, AnswerImage4Loc
                       FROM answerimages WHERE AnswerId IN ($aph)", $answerIds) as $img) {
                    $answerImages[$img['AnswerId']] = $img;
                }
            } catch (Exception $e) {}
        }

        return array_values(array_map(
            fn($r) => self::sanitizeQuestion($r, $answerImages[$r['AnswerId'] ?? 0] ?? null),
            $rows
        ));
    }

    /**
     * Loads this exam's subject sections (migration_v54), if any. Returns
     * [] for every exam that hasn't opted into IsMultiSubject='Y' — which
     * is every exam that existed before this feature, and any exam on a
     * database that hasn't run migration_v54 yet (query failure is treated
     * the same as "not multi-subject", same convention as every other
     * optional-table lookup in this file).
     */
    public static function loadExamSections(int $examId, array $exam): array
    {
        if (($exam['IsMultiSubject'] ?? 'N') !== 'Y') return [];
        try {
            return Database::fetchAll(
                "SELECT es.*, sub.SubjectName
                   FROM exam_sections es
              LEFT JOIN subjectinfo sub ON sub.SubjectInfoId = es.SubjectInfoId
                  WHERE es.ExamInfoId = ?
                  ORDER BY es.SortOrder, es.ExamSectionId",
                [$examId]
            );
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Draws questions section-by-section for a multi-subject exam (e.g. a
     * NEET paper: 45 Physics + 45 Chemistry + 45 Botany + 45 Zoology),
     * instead of pickQuestions()'s single flat-pool draw — each section is
     * capped at its own configured NumOfQuestions, and results stay grouped
     * in exam_sections.SortOrder. Structurally mirrors pickQuestions()'s
     * single-pool path (sampleQuestionIds + orderByFieldClause + one batched
     * answerimages lookup at the end) so both stay easy to compare/keep in
     * sync. Every returned question carries sectionId/sectionLabel so the
     * client can render section headers the same way exam/write.php does.
     */
    private static function pickSectionedQuestions(
        int $examId, array $examSections, string $excludeSql, array $excludeParams
    ): array {
        $rows = [];
        foreach ($examSections as $sec) {
            $subjectId = (int)$sec['SubjectInfoId'];
            $secCount  = (int)$sec['NumOfQuestions'];
            if ($secCount < 1) continue;

            try {
                $idRows = Database::fetchAll(
                    "SELECT q.QuestionId
                       FROM exam_questions eq
                       JOIN questions q ON q.QuestionId = eq.QuestionId
                      WHERE eq.ExamInfoId = ? AND q.SubjectInfoId = ?
                        AND COALESCE(eq.IsActive,'Y') = 'Y'
                        AND COALESCE(q.IsDeleted,'N') = 'N'" . $excludeSql,
                    array_merge([$examId, $subjectId], $excludeParams)
                );
            } catch (Exception $e) { $idRows = []; }

            $pickedIds = self::sampleQuestionIds($idRows, $secCount);
            if (!$pickedIds) continue;

            [$orderSql, $orderParams] = self::orderByFieldClause('q.QuestionId', $pickedIds);
            $ph = implode(',', array_fill(0, count($pickedIds), '?'));
            $sectionLabel = $sec['SectionLabel'] ?: ($sec['SubjectName'] ?? 'Section');

            try {
                $secRows = Database::fetchAll(
                    "SELECT q.QuestionId, q.QuestionDesc, q.ImageInd, q.ImageLoc, q.NumofImages,
                            COALESCE(q.QuestionType, 'MCQ') AS QuestionType,
                            COALESCE(q.ExpectedAnswerCount, 0) AS ExpectedAnswerCount,
                            a.AnswerId, a.Answer1, a.Answer2, a.Answer3, a.Answer4,
                            a.AnsImageInd, a.MultiImageInd,
                            COALESCE(a.NumStatements, 4) AS NumStatements,
                            a.MatchStatement1, a.MatchStatement2, a.MatchStatement3, a.MatchStatement4
                       FROM questions q
                  LEFT JOIN answers   a ON a.QuestionId = q.QuestionId
                      WHERE q.QuestionId IN ($ph)" . $orderSql,
                    array_merge($pickedIds, $orderParams)
                );
            } catch (Exception $e) { $secRows = []; }

            foreach ($secRows as $r) {
                $r['_SectionId']    = (int)$sec['ExamSectionId'];
                $r['_SectionLabel'] = $sectionLabel;
                $rows[] = $r;
            }
        }

        // Batch-load per-answer images once across every section's drawn
        // rows — same as pickQuestions()'s single-pool path.
        $answerIds = array_filter(array_column($rows, 'AnswerId'));
        $answerImages = [];
        if ($answerIds) {
            $aph = implode(',', array_fill(0, count($answerIds), '?'));
            try {
                foreach (Database::fetchAll(
                    "SELECT AnswerId, AnswerImage1Loc, AnswerImage2Loc, AnswerImage3Loc, AnswerImage4Loc
                       FROM answerimages WHERE AnswerId IN ($aph)", $answerIds) as $img) {
                    $answerImages[$img['AnswerId']] = $img;
                }
            } catch (Exception $e) {}
        }

        return array_values(array_map(function ($r) use ($answerImages) {
            $sq = self::sanitizeQuestion($r, $answerImages[$r['AnswerId'] ?? 0] ?? null);
            $sq['sectionId']    = $r['_SectionId'];
            $sq['sectionLabel'] = $r['_SectionLabel'];
            return $sq;
        }, $rows));
    }

    /**
     * Randomly samples up to $n QuestionIds from a result set, in PHP —
     * replaces ORDER BY RAND() LIMIT so MySQL never has to sort the full
     * matching set just to hand back a handful of rows.
     */
    private static function sampleQuestionIds(array $idRows, int $n): array
    {
        $ids = array_column($idRows, 'QuestionId');
        shuffle($ids);
        return array_slice($ids, 0, $n);
    }

    /**
     * Builds an "ORDER BY FIELD(col, ?, ?, ...)" clause that preserves the
     * given id order, since a WHERE ... IN (...) fetch doesn't guarantee the
     * result comes back in the same order as the id list (it typically comes
     * back in index/PK order instead). Keeps the already-shuffled order from
     * sampleQuestionIds() intact through the second query.
     *
     * @return array{0:string,1:array} [" ORDER BY FIELD(...)" or "", params]
     */
    private static function orderByFieldClause(string $col, array $ids): array
    {
        if (!$ids) return ['', []];
        $ph = implode(',', array_fill(0, count($ids), '?'));
        return [" ORDER BY FIELD($col, $ph)", $ids];
    }

    /** Strips correct-answer-bearing fields and resolves image URLs for client consumption. */
    private static function sanitizeQuestion(array $q, ?array $imgRow): array
    {
        $qType = $q['QuestionType'] ?? 'MCQ';
        // Auto-detect legacy YESNO rows (QuestionType=NULL/MCQ but YesNo data present)
        // — note YesNo1-4 themselves are never selected by pickQuestions(), so this
        // detection (unlike write.php's) can only rely on QuestionType being set correctly.
        $numStmt = max(2, min(4, (int)($q['NumStatements'] ?? 4)));

        $imgUrl = '';
        if (($q['ImageInd'] ?? 'N') === 'Y' && !empty($q['ImageLoc'])) {
            $imgUrl = self::resolveMediaUrl($q['ImageLoc']);
        }

        $optionImgKeys = ['AnswerImage1Loc', 'AnswerImage2Loc', 'AnswerImage3Loc', 'AnswerImage4Loc'];
        $rawOptions = [];
        for ($i = 1; $i <= 4; $i++) {
            $text = trim($q['Answer' . $i] ?? '');
            if ($text === '') continue;
            $rawOptions[] = [
                'num'      => $i,
                'text'     => $text,
                'imageUrl' => !empty($imgRow[$optionImgKeys[$i - 1]] ?? '')
                              ? self::resolveMediaUrl($imgRow[$optionImgKeys[$i - 1]]) : '',
            ];
        }

        $base = [
            'questionId'         => (int)$q['QuestionId'],
            'questionDesc'       => $q['QuestionDesc'] ?? '',
            'imageUrl'           => $imgUrl,
            'questionType'       => $qType,
            'expectedAnswerCount'=> (int)($q['ExpectedAnswerCount'] ?? 0),
        ];

        if ($qType === 'YESNO') {
            // Answer1-4 hold the statement text for a YESNO grid (YesNo1-4, the
            // correct Y/N per statement, are deliberately never fetched here).
            $base['statements'] = array_map(fn($o) => ['num' => $o['num'], 'text' => $o['text']], $rawOptions);
        } elseif ($qType === 'MATCH') {
            // Answer1-4 = draggable pool options; MatchStatement1-4 = drop targets.
            $base['poolOptions'] = $rawOptions;
            $targets = [];
            for ($s = 1; $s <= $numStmt; $s++) {
                $t = trim($q['MatchStatement' . $s] ?? '');
                if ($t !== '') $targets[] = ['num' => $s, 'text' => $t];
            }
            $base['targets'] = $targets;
        } else {
            // MCQ, DROPDOWN, MULTI
            $base['options'] = $rawOptions;
        }

        return $base;
    }

    /** Resolves a stored relative media path to an absolute URL for the mobile client. */
    private static function resolveMediaUrl(string $raw): string
    {
        $raw = str_replace(' ', '', trim($raw));
        if ($raw === '') return '';
        if (preg_match('#^(https?://|//)#i', $raw)) return $raw;
        $base = rtrim(API_PUBLIC_BASE_URL, '/');
        // Stored paths are relative to Admin/ (uploads live there), mirroring
        // exam/submit.php's resolveImgPath() convention on the web side.
        $rel = preg_replace('#^(\.\./|\./)+#', '', $raw);
        return strpos($rel, 'Admin/') === 0 ? "$base/$rel" : "$base/Admin/$rel";
    }

    // ── Submit an attempt: score + persist (mirrors exam/submit.php) ────────

    public static function submitAttempt(
        int $examId, int $userId, string $attemptToken, array $answers, int $violations = 0
    ): array {
        $pending = SignedToken::decode($attemptToken);
        if (!$pending || (int)$pending['examId'] !== $examId || (int)$pending['userId'] !== $userId) {
            return ['ok' => false, 'code' => 'ATTEMPT_EXPIRED', 'error' => 'This attempt has expired. Please start the exam again.'];
        }

        $startedAt = (int)$pending['startedAt'];
        $timeTaken = max(0, time() - $startedAt);
        $violations = max(0, $violations);

        $exam = ['ExamName' => ''];
        try {
            $examRow = Database::fetchOne(
                "SELECT ExamName, GradeInfoId, SubjectInfoId, MarkingScheme, TotalMarks, MarksPerQuestion, NegativeMarks, MinPassing
                   FROM examinfo WHERE ExamInfoId = ? LIMIT 1", [$examId]
            );
            if ($examRow) $exam = $examRow;
        } catch (Exception $e) {
            try {
                $examRow = Database::fetchOne(
                    "SELECT ExamName, GradeInfoId, SubjectInfoId, MinPassing FROM examinfo WHERE ExamInfoId = ? LIMIT 1", [$examId]
                );
                if ($examRow) $exam = $examRow;
            } catch (Exception $e2) {}
        }
        $gradeId   = (int)($exam['GradeInfoId'] ?? 0);
        $subjectId = (int)($exam['SubjectInfoId'] ?? 0);

        // ── Normalise submitted answers (same per-type rules as exam/submit.php) ──
        $submittedAnswers = [];
        $questionTypes    = [];
        foreach ($answers as $a) {
            $qid = (int)($a['questionId'] ?? 0);
            if (!$qid) continue;
            $qtype = in_array($a['questionType'] ?? '', ['MCQ', 'DROPDOWN', 'YESNO', 'MULTI', 'MATCH'], true)
                   ? $a['questionType'] : 'MCQ';
            $questionTypes[$qid] = $qtype;

            if ($qtype === 'YESNO') {
                // answer: {"1":"Y","2":"N",...} -> "Y,N"
                $parts = [];
                for ($s = 1; $s <= 4; $s++) {
                    $v = $a['answer'][(string)$s] ?? $a['answer'][$s] ?? '';
                    if ($v !== '') $parts[] = ($v === 'Y') ? 'Y' : 'N';
                }
                $submittedAnswers[$qid] = implode(',', $parts);
            } elseif ($qtype === 'MATCH') {
                $raw = is_array($a['answer'] ?? null) ? implode(',', $a['answer']) : trim((string)($a['answer'] ?? ''));
                $parts = array_map(
                    fn($v) => (ctype_digit((string)$v) && $v >= 0 && $v <= 4) ? (string)$v : '0',
                    array_map('trim', explode(',', $raw))
                );
                $submittedAnswers[$qid] = array_filter($parts, fn($v) => $v !== '0') ? implode(',', $parts) : '';
            } elseif ($qtype === 'MULTI') {
                $raw = is_array($a['answer'] ?? null) ? implode(',', $a['answer']) : trim((string)($a['answer'] ?? ''));
                if ($raw === '') {
                    $submittedAnswers[$qid] = '';
                } else {
                    $sel = array_filter(array_map('trim', explode(',', $raw)), fn($v) => ctype_digit($v) && $v >= 1 && $v <= 4);
                    sort($sel, SORT_NUMERIC);
                    $submittedAnswers[$qid] = implode(',', $sel);
                }
            } else {
                $submittedAnswers[$qid] = trim((string)($a['answer'] ?? ''));
            }
        }
        if (!$submittedAnswers) {
            return ['ok' => false, 'code' => 'EMPTY_SUBMISSION', 'error' => 'No questions in submission.'];
        }

        $qids = array_keys($submittedAnswers);
        $ph   = implode(',', array_fill(0, count($qids), '?'));
        try {
            $rows = Database::fetchAll(
                "SELECT q.QuestionId, q.CorrectAnswer, q.QuestionDesc,
                        COALESCE(q.QuestionType, 'MCQ') AS QuestionType,
                        a.AnswerId,
                        COALESCE(a.NumStatements, 4) AS NumStatements,
                        a.YesNo1, a.YesNo2, a.YesNo3, a.YesNo4,
                        a.MatchCorrect1, a.MatchCorrect2, a.MatchCorrect3, a.MatchCorrect4
                   FROM questions q
              LEFT JOIN answers a ON a.QuestionId = q.QuestionId
                  WHERE q.QuestionId IN ($ph)", $qids
            );
        } catch (Exception $e) {
            $rows = Database::fetchAll(
                "SELECT q.QuestionId, q.CorrectAnswer, q.QuestionDesc, 'MCQ' AS QuestionType,
                        a.AnswerId, 4 AS NumStatements,
                        NULL AS YesNo1, NULL AS YesNo2, NULL AS YesNo3, NULL AS YesNo4,
                        NULL AS MatchCorrect1, NULL AS MatchCorrect2, NULL AS MatchCorrect3, NULL AS MatchCorrect4
                   FROM questions q JOIN answers a ON a.QuestionId = q.QuestionId
                  WHERE q.QuestionId IN ($ph)", $qids
            );
        }

        $questionData = [];
        foreach ($rows as $r) {
            $qid   = (int)$r['QuestionId'];
            $qtype = $questionTypes[$qid] ?? ($r['QuestionType'] ?? 'MCQ');
            if ($qtype === 'YESNO') {
                $numStmt = max(1, min(4, (int)($r['NumStatements'] ?? 4)));
                $parts = [];
                for ($s = 1; $s <= $numStmt; $s++) $parts[] = $r['YesNo' . $s] ?? 'Y';
                $r['_correct'] = implode(',', $parts);
            } elseif ($qtype === 'MATCH') {
                $numStmt = max(1, min(4, (int)($r['NumStatements'] ?? 4)));
                $parts = [];
                for ($s = 1; $s <= $numStmt; $s++) $parts[] = (string)((int)($r['MatchCorrect' . $s] ?? 0));
                $r['_correct'] = implode(',', $parts);
            } elseif ($qtype === 'MULTI') {
                $rawParts = array_filter(array_map('trim', explode(',', $r['CorrectAnswer'] ?? '')));
                $rawParts = array_map(fn($p) => ltrim(str_ireplace('Answer', '', $p)), $rawParts);
                usort($rawParts, fn($a, $b) => strcmp($a, $b));
                $r['_correct'] = implode(',', $rawParts);
            } else {
                $raw = trim($r['CorrectAnswer'] ?? '');
                $r['_correct'] = ltrim(str_ireplace('Answer', '', $raw));
            }
            $questionData[$qid] = $r;
        }

        $totalQ      = count($submittedAnswers);
        $marking     = Marking::resolve($exam, $totalQ);
        $marksPerQ   = $marking['marksPerQuestion'];
        $negMarks    = $marking['negativeMarks'];
        $totalMarks  = $marking['totalMarks'];
        $totalScore  = 0.0;
        $correct = $wrong = $skipped = 0;
        $qScores = [];
        $breakdown = [];

        foreach ($submittedAnswers as $qid => $chosen) {
            if (!isset($questionData[$qid])) { $skipped++; $qScores[$qid] = 0; continue; }
            $qtype       = $questionData[$qid]['QuestionType'] ?? $questionTypes[$qid] ?? 'MCQ';
            $correctNorm = $questionData[$qid]['_correct'];
            $earned = 0.0;
            $status = 'skipped';

            if ($chosen === '') {
                $skipped++;
            } elseif ($qtype === 'MULTI') {
                $chosenSet  = array_filter(array_map('trim', explode(',', $chosen)));
                $correctSet = array_filter(array_map('trim', explode(',', $correctNorm)));
                $totalCorrect    = max(1, count($correctSet));
                $correctSelected = count(array_intersect($chosenSet, $correctSet));
                $wrongSelected   = count(array_diff($chosenSet, $correctSet));
                $netCorrect      = max(0, $correctSelected - $wrongSelected);
                if ($correctSelected === $totalCorrect && $wrongSelected === 0) {
                    $correct++; $earned = round($marksPerQ); $status = 'correct';
                } else {
                    $wrong++; $status = 'wrong';
                    $earned = ($netCorrect === 0) ? -round($negMarks) : round($netCorrect / $totalCorrect * $marksPerQ);
                }
            } elseif ($qtype === 'YESNO' || $qtype === 'MATCH') {
                $chosenParts  = explode(',', $chosen);
                $correctParts = ($correctNorm !== '') ? explode(',', $correctNorm) : [];
                $numStmt      = max(1, count($correctParts));
                $correctStmts = 0;
                for ($s = 0; $s < $numStmt; $s++) {
                    $cVal = $chosenParts[$s] ?? ($qtype === 'MATCH' ? '0' : '');
                    if ($qtype === 'MATCH') {
                        if ($cVal !== '0' && $cVal === ($correctParts[$s] ?? '')) $correctStmts++;
                    } else {
                        if ($cVal !== '' && $cVal === ($correctParts[$s] ?? '')) $correctStmts++;
                    }
                }
                if ($correctStmts === $numStmt) { $correct++; $earned = round($marksPerQ); $status = 'correct'; }
                elseif ($correctStmts === 0)    { $wrong++;   $earned = -round($negMarks); $status = 'wrong'; }
                else                             { $wrong++;   $earned = round($correctStmts / $numStmt * $marksPerQ); $status = 'partial'; }
            } else {
                if ($chosen === $correctNorm) { $correct++; $earned = round($marksPerQ); $status = 'correct'; }
                else                          { $wrong++;   $earned = -round($negMarks); $status = 'wrong'; }
            }

            $qScores[$qid] = $earned;
            $totalScore   += $earned;
            $breakdown[] = [
                'questionId'    => $qid,
                'questionDesc'  => $questionData[$qid]['QuestionDesc'] ?? '',
                'questionType'  => $qtype,
                'yourAnswer'    => $chosen,
                'correctAnswer' => $correctNorm,
                'status'        => $status,
                'earnedMarks'   => $earned,
            ];
        }

        $totalScore    = (int)round($totalScore);
        $passThreshold = min(100, max(0, (int)($exam['MinPassing'] ?? 0)));
        $percentScore  = $totalMarks > 0 ? ($totalScore / $totalMarks) * 100 : 0;
        $passed        = ($percentScore >= $passThreshold);
        $description   = $passed ? 'Pass' : 'Fail';

        $m = (int)date('m'); $y = (int)date('Y');
        $curYear = ($m <= 5) ? $y - 1 : $y;

        $studentExamId = 0;
        Database::beginTransaction();
        try {
            $insertedFull = false;
            try {
                Database::execute(
                    "INSERT INTO studentexam
                        (UserInfoId,ExamInfoId,GradeInfoId,SubjectInfoId,
                         Score,MarksOutOf,Description,
                         TotalQuestions,CorrectCount,WrongCount,SkippedCount,
                         TimeTaken,ExamYear,ExamDate)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())",
                    [$userId, $examId, $gradeId, $subjectId,
                     $totalScore, $totalMarks, $description,
                     $totalQ, $correct, $wrong, $skipped,
                     $timeTaken, $curYear]
                );
                $insertedFull = true;
            } catch (Exception $e) {
                try {
                    Database::execute(
                        "INSERT INTO studentexam
                            (UserInfoId,ExamInfoId,GradeInfoId,SubjectInfoId,Score,MarksOutOf,Description,TimeTaken,ExamYear,ExamDate)
                         VALUES (?,?,?,?,?,?,?,?,?,NOW())",
                        [$userId, $examId, $gradeId, $subjectId, $totalScore, $totalMarks, $description, $timeTaken, $curYear]
                    );
                } catch (Exception $e2) {
                    Database::execute(
                        "INSERT INTO studentexam (ExamInfoId,UserInfoId,TimeTaken,CreateDate) VALUES (?,?,?,NOW())",
                        [$examId, $userId, $timeTaken]
                    );
                }
            }
            $studentExamId = (int)Database::lastInsertId();

            if ($violations > 0) {
                try {
                    Database::execute("UPDATE studentexam SET violations = ? WHERE StudentExamId = ?", [$violations, $studentExamId]);
                } catch (Exception $e) {}
            }

            if (!$insertedFull) {
                try {
                    Database::execute(
                        "UPDATE studentexam SET Score=?,MarksOutOf=?,Description=?,ExamYear=?,ExamDate=NOW() WHERE StudentExamId=?",
                        [$totalScore, $totalMarks, $description, $curYear, $studentExamId]
                    );
                } catch (Exception $e) {}
                try {
                    Database::execute(
                        "UPDATE studentexam SET TotalQuestions=?,CorrectCount=?,WrongCount=?,SkippedCount=? WHERE StudentExamId=?",
                        [$totalQ, $correct, $wrong, $skipped, $studentExamId]
                    );
                } catch (Exception $e) {}
            }

            // Build all rows first, then insert with one multi-row INSERT
            // (chunked) instead of one INSERT per question. A 90-180 question
            // NEET/JEE submission used to mean that many sequential
            // round-trips all held inside this open transaction — the exact
            // pattern that turns "everyone submits near the same cutoff
            // time" into row-lock contention. Same 3-tier column-set
            // fallback as before for older-schema installs, just batched.
            $resultRows = [];
            foreach ($submittedAnswers as $qid => $chosen) {
                $qtype       = $questionData[$qid]['QuestionType'] ?? $questionTypes[$qid] ?? 'MCQ';
                $correctNorm = $questionData[$qid]['_correct'] ?? '';
                $correctRaw  = in_array($qtype, ['YESNO', 'MATCH'], true) ? $correctNorm : ($questionData[$qid]['CorrectAnswer'] ?? '');
                $earned      = (float)($qScores[$qid] ?? 0);
                $isCorrect   = ($earned >= $marksPerQ - 0.01 && $chosen !== '') ? 'Y' : 'N';
                $resultRows[] = [
                    'studentExamId' => $studentExamId,
                    'qid'           => $qid,
                    'chosen'        => $chosen,
                    'correctRaw'    => $correctRaw,
                    'isCorrect'     => $isCorrect,
                    'earned'        => $earned,
                    'marksPerQ'     => round($marksPerQ, 4),
                ];
            }
            self::insertResultsBatch($resultRows);

            Database::commit();
        } catch (Exception $ex) {
            Database::rollBack();
            error_log('ExamEngine::submitAttempt transaction failed: ' . $ex->getMessage());
            return ['ok' => false, 'code' => 'SAVE_FAILED', 'error' => 'An error occurred saving your results. Please try again.'];
        }

        /* Also stamps StudentExamId (this attempt) and falls back to a bare
           Status update on an older schema — mirrors exam/submit.php's web
           equivalent; see that file for the full rationale. Logs a real
           failure instead of swallowing it, since that used to leave an
           exam permanently stuck on "Assigned"/"Pending" with no trace. */
        try {
            try {
                Database::execute(
                    "UPDATE exam_assignments SET Status='Completed', CompletedAt=NOW(), StudentExamId=?
                      WHERE ExamInfoId=? AND UserInfoId=?",
                    [$studentExamId, $examId, $userId]
                );
            } catch (Exception $eCols) {
                Database::execute(
                    "UPDATE exam_assignments SET Status='Completed' WHERE ExamInfoId=? AND UserInfoId=?",
                    [$examId, $userId]
                );
            }
        } catch (Exception $e) {
            error_log('ExamEngine::submitAttempt: could not mark exam_assignments Completed for ExamInfoId='
                . $examId . ' UserInfoId=' . $userId . ': ' . $e->getMessage());
        }
        try {
            Database::execute(
                "INSERT INTO exam_changelog (ExamInfoId,ExamName,Action,ActionBy,Details) VALUES (?,?,?,?,?)",
                [$examId, $exam['ExamName'] ?? '', 'SUBMIT', Auth::currentUser() ?: 'mobile',
                 "Score:{$totalScore} Correct:{$correct} Wrong:{$wrong} Skipped:{$skipped} [mobile]"]
            );
        } catch (Exception $e) {}

        return [
            'ok' => true,
            'studentExamId' => $studentExamId,
            'score'         => $totalScore,
            'marksOutOf'    => $totalMarks,
            'percent'       => round($percentScore, 1),
            'passed'        => $passed,
            'description'   => $description,
            'correctCount'  => $correct,
            'wrongCount'    => $wrong,
            'skippedCount'  => $skipped,
            'totalQuestions'=> $totalQ,
            'timeTakenSec'  => $timeTaken,
            'breakdown'     => $breakdown,
        ];
    }

    /**
     * Batch-inserts studentexamresults rows in one multi-row INSERT per
     * chunk, with the same 3-tier column-set fallback the original per-row
     * code used for installs on an older schema (missing EarnedMarks/MarksPerQ,
     * or missing SelectedAnswer/CorrectAnswer/IsCorrect entirely). Chunked at
     * 300 rows/statement to stay well clear of MySQL's placeholder and
     * max_allowed_packet limits on very large exams.
     */
    private static function insertResultsBatch(array $rows): void
    {
        foreach (array_chunk($rows, 300) as $chunk) {
            try {
                self::insertResultsChunk(
                    $chunk,
                    "INSERT INTO studentexamresults
                        (StudentExamId,QuestionId,StdAnswerId,SelectedAnswer,CorrectAnswer,IsCorrect,EarnedMarks,MarksPerQ) VALUES ",
                    fn($r) => [$r['studentExamId'], $r['qid'], $r['chosen'], $r['chosen'], $r['correctRaw'], $r['isCorrect'], $r['earned'], $r['marksPerQ']],
                    8
                );
            } catch (Exception $e) {
                try {
                    self::insertResultsChunk(
                        $chunk,
                        "INSERT INTO studentexamresults (StudentExamId,QuestionId,StdAnswerId,SelectedAnswer,CorrectAnswer,IsCorrect) VALUES ",
                        fn($r) => [$r['studentExamId'], $r['qid'], $r['chosen'], $r['chosen'], $r['correctRaw'], $r['isCorrect']],
                        6
                    );
                } catch (Exception $e2) {
                    self::insertResultsChunk(
                        $chunk,
                        "INSERT INTO studentexamresults (StudentExamId,QuestionId,StdAnswerId) VALUES ",
                        fn($r) => [$r['studentExamId'], $r['qid'], $r['chosen']],
                        3
                    );
                }
            }
        }
    }

    /** Runs one multi-row INSERT for a chunk of rows against a given column set/width. */
    private static function insertResultsChunk(array $chunk, string $sqlPrefix, callable $mapRow, int $cols): void
    {
        $placeholderGroup = '(' . implode(',', array_fill(0, $cols, '?')) . ')';
        $sql    = $sqlPrefix . implode(',', array_fill(0, count($chunk), $placeholderGroup));
        $params = [];
        foreach ($chunk as $r) {
            array_push($params, ...$mapRow($r));
        }
        Database::execute($sql, $params);
    }

    // ── History (mirrors exam/history.php's student branch) ─────────────────

    public static function getHistory(int $userId, ?int $examId = null): array
    {
        $examCols = "e.ExamName, e.NumOfQuestions, e.MinPassing, e.MaxAttempts, g.GradeName, s.SubjectName";
        $joins    = "LEFT JOIN examinfo e ON e.ExamInfoId = se.ExamInfoId
                     LEFT JOIN gradeinfo g ON g.GradeInfoId = e.GradeInfoId
                     LEFT JOIN subjectinfo s ON s.SubjectInfoId = e.SubjectInfoId";
        $extraWhere = $examId ? ' AND se.ExamInfoId = ?' : '';
        $params     = $examId ? [$userId, $examId] : [$userId];
        $rows = Database::fetchAll(
            "SELECT se.*, $examCols FROM studentexam se $joins
              WHERE se.UserInfoId = ?" . $extraWhere . "
              ORDER BY se.StudentExamId DESC", $params
        );

        return array_map(function ($r) {
            $score = (int)($r['Score'] ?? 0);
            $outOf = (int)($r['MarksOutOf'] ?? ($r['NumOfQuestions'] ?? 0));
            $minPass = min(100, max(0, (int)($r['MinPassing'] ?? 0)));
            $desc = $r['Description'] ?? '';
            if ($desc !== '' && $minPass > 0 && $outOf > 0) {
                $desc = (round($score / $outOf * 100) >= $minPass) ? 'Pass' : 'Fail';
            }
            return [
                'studentExamId' => (int)$r['StudentExamId'],
                'examId'        => (int)$r['ExamInfoId'],
                'examName'      => $r['ExamName'] ?? '',
                'gradeName'     => $r['GradeName'] ?? '',
                'subjectName'   => $r['SubjectName'] ?? '',
                'score'         => $score,
                'marksOutOf'    => $outOf,
                'scorePercent'  => $outOf > 0 ? round($score / $outOf * 100, 1) : 0,
                'description'   => $desc,
                'correctCount'  => isset($r['CorrectCount']) ? (int)$r['CorrectCount'] : null,
                'wrongCount'    => isset($r['WrongCount'])   ? (int)$r['WrongCount']   : null,
                'skippedCount'  => isset($r['SkippedCount']) ? (int)$r['SkippedCount'] : null,
                'timeTakenSec'  => (int)($r['TimeTaken'] ?? 0),
                'examDate'      => $r['ExamDate'] ?? ($r['CreateDate'] ?? null),
            ];
        }, $rows);
    }

    /** Per-question breakdown for a past attempt (review screen). */
    public static function getResultDetail(int $studentExamId, int $userId): ?array
    {
        $header = Database::fetchOne(
            "SELECT se.*, e.ExamName, e.MinPassing, g.GradeName, s.SubjectName
               FROM studentexam se
          LEFT JOIN examinfo e ON e.ExamInfoId = se.ExamInfoId
          LEFT JOIN gradeinfo g ON g.GradeInfoId = e.GradeInfoId
          LEFT JOIN subjectinfo s ON s.SubjectInfoId = e.SubjectInfoId
              WHERE se.StudentExamId = ? AND se.UserInfoId = ? LIMIT 1",
            [$studentExamId, $userId]
        );
        if (!$header) return null;

        $details = [];
        try {
            $details = Database::fetchAll(
                "SELECT r.QuestionId, r.SelectedAnswer, r.CorrectAnswer, r.IsCorrect, r.EarnedMarks, r.MarksPerQ,
                        q.QuestionDesc, q.ImageInd, q.ImageLoc, COALESCE(q.QuestionType,'MCQ') AS QuestionType,
                        q.Explanation
                   FROM studentexamresults r
              LEFT JOIN questions q ON q.QuestionId = r.QuestionId
                  WHERE r.StudentExamId = ?", [$studentExamId]
            );
        } catch (Exception $e) {}

        $score = (int)($header['Score'] ?? 0);
        $outOf = (int)($header['MarksOutOf'] ?? 0);

        return [
            'studentExamId' => $studentExamId,
            'examName'      => $header['ExamName'] ?? '',
            'gradeName'     => $header['GradeName'] ?? '',
            'subjectName'   => $header['SubjectName'] ?? '',
            'score'         => $score,
            'marksOutOf'    => $outOf,
            'scorePercent'  => $outOf > 0 ? round($score / $outOf * 100, 1) : 0,
            'description'   => $header['Description'] ?? '',
            'timeTakenSec'  => (int)($header['TimeTaken'] ?? 0),
            'examDate'      => $header['ExamDate'] ?? null,
            'questions'     => array_map(fn($d) => [
                'questionId'    => (int)$d['QuestionId'],
                'questionDesc'  => $d['QuestionDesc'] ?? '',
                'imageUrl'      => (($d['ImageInd'] ?? 'N') === 'Y' && !empty($d['ImageLoc']))
                                    ? self::resolveMediaUrl($d['ImageLoc']) : '',
                'questionType'  => $d['QuestionType'] ?? 'MCQ',
                'yourAnswer'    => $d['SelectedAnswer'] ?? '',
                'correctAnswer' => $d['CorrectAnswer'] ?? '',
                'isCorrect'     => ($d['IsCorrect'] ?? 'N') === 'Y',
                'earnedMarks'   => isset($d['EarnedMarks']) ? (float)$d['EarnedMarks'] : null,
                'explanation'   => $d['Explanation'] ?? '',
            ], $details),
        ];
    }
}
