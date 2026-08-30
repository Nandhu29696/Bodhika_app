<?php
/**
 * Lib/AdminApi.php — "Core admin" read/approve surface for the mobile API.
 *
 * Scope deliberately limited to what was agreed for the mobile admin
 * experience: dashboard stats, student/teacher directories with last-seen +
 * exam history drill-in, login activity, a read-only exam/question viewer,
 * and approving pending profile-change requests. Heavy content authoring
 * (bulk question upload, the full exam builder, payments/coupons, teacher
 * onboarding) stays desktop-only — those flows involve multi-step forms and
 * file uploads that don't belong on a phone.
 *
 * The students/teachers/login-activity queries are a clean-room port of
 * Admin/AdminUsers.php's three tabs (same JOINs, same LastSeenAt subquery);
 * the change-request approve/reject pair mirrors Admin/UserChangeRequests.php
 * exactly, since that one already enforces an allow-list of editable columns
 * and must stay byte-for-byte compatible with what the desktop queue expects.
 *
 * Requires Config.php, Database.php, ExamEngine.php to already be loaded.
 */
final class AdminApi
{
    private const PAGE_SIZE = 20;

    // ── Dashboard ─────────────────────────────────────────────────────────

    public static function dashboardStats(): array
    {
        $stat = function (string $sql, array $params = []) {
            try { return (int)(Database::fetchOne($sql, $params)['cnt'] ?? 0); }
            catch (Exception $e) { return 0; }
        };

        return [
            'totalStudents'   => $stat("SELECT COUNT(*) AS cnt FROM userinfo u JOIN logininfo l ON l.LoginName = u.LoginName WHERE l.Role = 'STDNT'"),
            'totalTeachers'   => $stat("SELECT COUNT(*) AS cnt FROM userinfo u JOIN logininfo l ON l.LoginName = u.LoginName WHERE l.Role = 'TEACH'"),
            'activeTeachers'  => $stat("SELECT COUNT(*) AS cnt FROM userinfo u JOIN logininfo l ON l.LoginName = u.LoginName WHERE l.Role = 'TEACH' AND l.Active = 'Y'"),
            'totalExams'      => $stat("SELECT COUNT(*) AS cnt FROM examinfo WHERE COALESCE(IsActive,'Y') = 'Y' AND COALESCE(IsDeleted,'N') = 'N'"),
            'attemptsToday'   => $stat("SELECT COUNT(*) AS cnt FROM studentexam WHERE DATE(COALESCE(ExamDate, CreateDate)) = CURDATE()"),
            'attemptsThisWeek'=> $stat("SELECT COUNT(*) AS cnt FROM studentexam WHERE COALESCE(ExamDate, CreateDate) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)"),
            'loginsToday'     => $stat("SELECT COUNT(*) AS cnt FROM logintrackinfo WHERE DATE(CreateDtm) = CURDATE()"),
            'pendingChangeRequests' => $stat("SELECT COUNT(*) AS cnt FROM user_change_requests WHERE Status = 'pending'"),
            'totalInstitutes' => $stat("SELECT COUNT(*) AS cnt FROM institutes"),
        ];
    }

    // ── Students directory (mirrors AdminUsers.php tab 1) ────────────────

    public static function listStudents(string $nameFilter, int $instituteId, int $page, int $pageSize = self::PAGE_SIZE): array
    {
        $where  = ['1=1'];
        $params = [];
        if ($nameFilter !== '') {
            $where[]  = "(u.FstName LIKE ? OR u.LstName LIKE ? OR u.LoginName LIKE ?)";
            $like     = "%{$nameFilter}%";
            array_push($params, $like, $like, $like);
        }
        if ($instituteId > 0) {
            $where[]  = "u.InstituteId = ?";
            $params[] = $instituteId;
        }
        $whereSQL = implode(' AND ', $where);
        $baseSQL  = "FROM userinfo u
                     LEFT JOIN logininfo l ON l.LoginName = u.LoginName
                     LEFT JOIN enrollment_payments ep ON ep.UserInfoId = u.UserInfoId
                     LEFT JOIN subjectinfo s ON s.SubjectInfoId = ep.SubjectInfoId
                     WHERE l.Role = 'STDNT' AND {$whereSQL}";

        $total = (int)(Database::fetchOne("SELECT COUNT(DISTINCT u.UserInfoId) AS cnt {$baseSQL}", $params)['cnt'] ?? 0);

        $page   = max(1, $page);
        $offset = ($page - 1) * $pageSize;
        $rows = Database::fetchAll(
            "SELECT DISTINCT u.UserInfoId, u.FstName, u.LstName, u.LoginName, u.Mobile, u.EMail, l.Active,
                    COALESCE(inst.InstituteName, '—') AS InstituteName,
                    GROUP_CONCAT(DISTINCT s.SubjectName ORDER BY s.SubjectName SEPARATOR ', ') AS Subjects,
                    (SELECT MAX(lt2.CreateDtm) FROM logintrackinfo lt2 WHERE lt2.UserId = u.UserInfoId) AS LastSeenAt
               FROM userinfo u
               LEFT JOIN logininfo l ON l.LoginName = u.LoginName
               LEFT JOIN enrollment_payments ep ON ep.UserInfoId = u.UserInfoId
               LEFT JOIN subjectinfo s ON s.SubjectInfoId = ep.SubjectInfoId
               LEFT JOIN institutes inst ON inst.InstituteId = u.InstituteId
              WHERE l.Role = 'STDNT' AND {$whereSQL}
              GROUP BY u.UserInfoId
              ORDER BY LastSeenAt DESC, u.UserInfoId DESC
              LIMIT {$offset}, {$pageSize}",
            $params
        );

        return [
            'total'   => $total,
            'page'    => $page,
            'pageSize'=> $pageSize,
            'items'   => array_map(fn($r) => self::shapePersonRow($r), $rows),
        ];
    }

    // ── Teachers directory (mirrors AdminUsers.php tab 2) ─────────────────

    public static function listTeachers(string $nameFilter, int $instituteId, int $page, int $pageSize = self::PAGE_SIZE): array
    {
        $where  = ['1=1'];
        $params = [];
        if ($nameFilter !== '') {
            $where[]  = "(u.FstName LIKE ? OR u.LstName LIKE ? OR u.LoginName LIKE ?)";
            $like     = "%{$nameFilter}%";
            array_push($params, $like, $like, $like);
        }
        if ($instituteId > 0) {
            $where[]  = "u.InstituteId = ?";
            $params[] = $instituteId;
        }
        $whereSQL = implode(' AND ', $where);
        $baseSQL  = "FROM userinfo u
                     LEFT JOIN logininfo l ON l.LoginName = u.LoginName
                     LEFT JOIN teacher_profiles tp ON tp.UserInfoId = u.UserInfoId
                     LEFT JOIN teacher_subjects ts ON ts.TeacherId = tp.TeacherId AND ts.Active = 'Y'
                     WHERE l.Role = 'TEACH' AND {$whereSQL}";

        $total = (int)(Database::fetchOne("SELECT COUNT(DISTINCT u.UserInfoId) AS cnt {$baseSQL}", $params)['cnt'] ?? 0);

        $page   = max(1, $page);
        $offset = ($page - 1) * $pageSize;
        $rows = Database::fetchAll(
            "SELECT DISTINCT u.UserInfoId, u.FstName, u.LstName, u.LoginName, u.Mobile, u.EMail, l.Active, tp.TeacherId,
                    COALESCE(inst.InstituteName, '—') AS InstituteName,
                    GROUP_CONCAT(DISTINCT COALESCE(ts.CourseName, s.SubjectName) ORDER BY s.SubjectName SEPARATOR ', ') AS Subjects,
                    (SELECT MAX(lt2.CreateDtm) FROM logintrackinfo lt2 WHERE lt2.UserId = u.UserInfoId) AS LastSeenAt
               FROM userinfo u
               LEFT JOIN logininfo l ON l.LoginName = u.LoginName
               LEFT JOIN teacher_profiles tp ON tp.UserInfoId = u.UserInfoId
               LEFT JOIN teacher_subjects ts ON ts.TeacherId = tp.TeacherId AND ts.Active = 'Y'
               LEFT JOIN subjectinfo s ON s.SubjectInfoId = ts.SubjectInfoId
               LEFT JOIN institutes inst ON inst.InstituteId = u.InstituteId
              WHERE l.Role = 'TEACH' AND {$whereSQL}
              GROUP BY u.UserInfoId
              ORDER BY LastSeenAt DESC, u.UserInfoId DESC
              LIMIT {$offset}, {$pageSize}",
            $params
        );

        return [
            'total'   => $total,
            'page'    => $page,
            'pageSize'=> $pageSize,
            'items'   => array_map(fn($r) => self::shapePersonRow($r, true), $rows),
        ];
    }

    private static function shapePersonRow(array $r, bool $isTeacher = false): array
    {
        $out = [
            'userId'       => (int)$r['UserInfoId'],
            'fullName'     => trim(($r['FstName'] ?? '') . ' ' . ($r['LstName'] ?? '')),
            'loginName'    => $r['LoginName'] ?? '',
            'mobile'       => $r['Mobile'] ?? '',
            'email'        => $r['EMail'] ?? '',
            'active'       => ($r['Active'] ?? 'Y') === 'Y',
            'instituteName'=> $r['InstituteName'] ?? '—',
            'subjects'     => $r['Subjects'] ?? '',
            'lastSeenAt'   => $r['LastSeenAt'] ?? null,
        ];
        if ($isTeacher) $out['teacherId'] = isset($r['TeacherId']) ? (int)$r['TeacherId'] : null;
        return $out;
    }

    /** Drill-in from the student/teacher directory — reuses the same logic students see for their own history. */
    public static function studentExamHistory(int $userId): array
    {
        return ExamEngine::getHistory($userId);
    }

    // ── Login activity (mirrors AdminUsers.php tab 3) ─────────────────────

    public static function listLoginActivity(string $nameFilter, string $roleFilter, ?string $fromDate, ?string $toDate, int $page, int $pageSize = self::PAGE_SIZE): array
    {
        $fromDate = $fromDate ?: date('Y-m-d', strtotime('-30 days'));

        $where  = ['1=1'];
        $params = [];
        if ($nameFilter !== '') {
            $where[]  = "(u.FstName LIKE ? OR u.LstName LIKE ? OR u.LoginName LIKE ?)";
            $like     = "%{$nameFilter}%";
            array_push($params, $like, $like, $like);
        }
        if ($roleFilter !== '') {
            $where[]  = "li.Role LIKE ?";
            $params[] = "%{$roleFilter}%";
        }
        if ($fromDate) {
            $where[]  = "DATE(lt.CreateDtm) >= ?";
            $params[] = $fromDate;
        }
        if ($toDate) {
            $where[]  = "DATE(lt.CreateDtm) <= ?";
            $params[] = $toDate;
        }
        $whereSQL = implode(' AND ', $where);

        // lt.UserId > 0 = userinfo.UserInfoId; lt.UserId < 0 = -logininfo.LoginInfoId (no userinfo row).
        $baseSQL = "FROM logintrackinfo lt
                     LEFT JOIN userinfo  u  ON u.UserInfoId = lt.UserId AND lt.UserId > 0
                     LEFT JOIN logininfo li ON (
                         (lt.UserId > 0 AND u.LoginName IS NOT NULL AND li.LoginName = u.LoginName)
                         OR (lt.UserId < 0 AND li.LoginInfoId = -lt.UserId)
                     )
                     LEFT JOIN institutes inst ON inst.InstituteId = u.InstituteId
                     WHERE {$whereSQL}";

        $total = (int)(Database::fetchOne("SELECT COUNT(*) AS cnt {$baseSQL}", $params)['cnt'] ?? 0);

        $page   = max(1, $page);
        $offset = ($page - 1) * $pageSize;
        $rows = Database::fetchAll(
            "SELECT lt.UserId,
                    COALESCE(u.LoginName, li.LoginName, '') AS TrackLogin,
                    COALESCE(u.FstName, '') AS FstName, COALESCE(u.LstName, '') AS LstName,
                    COALESCE(u.EMail, '') AS EMail,
                    COALESCE(li.Role, '—') AS RoleDesc,
                    COALESCE(inst.InstituteName, '—') AS InstituteName,
                    lt.CreateDtm AS LoginAt
               {$baseSQL}
              ORDER BY lt.CreateDtm DESC
              LIMIT {$offset}, {$pageSize}",
            $params
        );

        return [
            'total'   => $total,
            'page'    => $page,
            'pageSize'=> $pageSize,
            'items'   => array_map(fn($r) => [
                'loginName' => $r['TrackLogin'] ?? '',
                'fullName'  => trim(($r['FstName'] ?? '') . ' ' . ($r['LstName'] ?? '')),
                'email'     => $r['EMail'] ?? '',
                'role'      => $r['RoleDesc'] ?? '—',
                'instituteName' => $r['InstituteName'] ?? '—',
                'loginAt'   => $r['LoginAt'] ?? null,
            ], $rows),
        ];
    }

    // ── Exam list with question counts ─────────────────────────────────────

    public static function listExams(string $nameFilter, int $page, int $pageSize = self::PAGE_SIZE): array
    {
        $where  = ["COALESCE(e.IsDeleted,'N') = 'N'"];
        $params = [];
        if ($nameFilter !== '') {
            $where[]  = 'e.ExamName LIKE ?';
            $params[] = "%{$nameFilter}%";
        }
        $whereSQL = implode(' AND ', $where);

        try {
            $total = (int)(Database::fetchOne("SELECT COUNT(*) AS cnt FROM examinfo e WHERE {$whereSQL}", $params)['cnt'] ?? 0);
        } catch (Exception $e) {
            // migration_v43 not yet run — IsDeleted column missing, count everything.
            $legacyWhere = $nameFilter !== '' ? 'e.ExamName LIKE ?' : '1=1';
            $total = (int)(Database::fetchOne("SELECT COUNT(*) AS cnt FROM examinfo e WHERE {$legacyWhere}", $params)['cnt'] ?? 0);
        }

        $page   = max(1, $page);
        $offset = ($page - 1) * $pageSize;
        try {
            $rows = Database::fetchAll(
                "SELECT e.ExamInfoId, e.ExamName, e.TimeAlloted, e.MinPassing, e.NumOfQuestions,
                        COALESCE(e.IsActive,'Y') AS IsActive, g.GradeName, s.SubjectName,
                        (SELECT COUNT(*) FROM exam_questions eq
                           JOIN questions q ON q.QuestionId = eq.QuestionId
                          WHERE eq.ExamInfoId = e.ExamInfoId AND COALESCE(eq.IsActive,'Y') = 'Y'
                            AND COALESCE(q.IsDeleted,'N') = 'N') AS QuestionCount
                   FROM examinfo e
              LEFT JOIN gradeinfo g ON g.GradeInfoId = e.GradeInfoId
              LEFT JOIN subjectinfo s ON s.SubjectInfoId = e.SubjectInfoId
                  WHERE {$whereSQL}
                  ORDER BY e.ExamInfoId DESC LIMIT {$offset}, {$pageSize}",
                $params
            );
        } catch (Exception $e) {
            // Legacy schema: questions link directly via questions.ExamInfoId, no exam_questions table,
            // and/or migration_v43 (IsDeleted) hasn't been run yet.
            $legacyWhereSQL = $nameFilter !== '' ? 'e.ExamName LIKE ?' : '1=1';
            $rows = Database::fetchAll(
                "SELECT e.ExamInfoId, e.ExamName, e.TimeAlloted, e.MinPassing, e.NumOfQuestions,
                        COALESCE(e.IsActive,'Y') AS IsActive, g.GradeName, s.SubjectName,
                        (SELECT COUNT(*) FROM questions q WHERE q.ExamInfoId = e.ExamInfoId) AS QuestionCount
                   FROM examinfo e
              LEFT JOIN gradeinfo g ON g.GradeInfoId = e.GradeInfoId
              LEFT JOIN subjectinfo s ON s.SubjectInfoId = e.SubjectInfoId
                  WHERE {$legacyWhereSQL}
                  ORDER BY e.ExamInfoId DESC LIMIT {$offset}, {$pageSize}",
                $params
            );
        }

        return [
            'total'   => $total,
            'page'    => $page,
            'pageSize'=> $pageSize,
            'items'   => array_map(fn($r) => [
                'examId'        => (int)$r['ExamInfoId'],
                'examName'      => $r['ExamName'] ?? '',
                'gradeName'     => $r['GradeName'] ?? '',
                'subjectName'   => $r['SubjectName'] ?? '',
                'timeAllotedMin'=> (int)($r['TimeAlloted'] ?? 0),
                'minPassingPct' => min(100, max(0, (int)($r['MinPassing'] ?? 0))),
                'configuredQuestions' => (int)($r['NumOfQuestions'] ?? 0),
                'questionCount' => (int)($r['QuestionCount'] ?? 0),
                'isActive'      => ($r['IsActive'] ?? 'Y') === 'Y',
            ], $rows),
        ];
    }

    /** Read-only question viewer — UNLIKE ExamEngine::pickQuestions(), this is allowed to include correct answers (admin-only). */
    public static function getExamQuestions(int $examId): array
    {
        try {
            $rows = Database::fetchAll(
                "SELECT q.QuestionId, q.QuestionDesc, q.CorrectAnswer,
                        COALESCE(q.QuestionType,'MCQ') AS QuestionType,
                        a.Answer1, a.Answer2, a.Answer3, a.Answer4,
                        a.YesNo1, a.YesNo2, a.YesNo3, a.YesNo4,
                        a.MatchStatement1, a.MatchStatement2, a.MatchStatement3, a.MatchStatement4,
                        a.MatchCorrect1, a.MatchCorrect2, a.MatchCorrect3, a.MatchCorrect4
                   FROM exam_questions eq
                   JOIN questions q ON q.QuestionId = eq.QuestionId
              LEFT JOIN answers   a ON a.QuestionId = q.QuestionId
                  WHERE eq.ExamInfoId = ? AND COALESCE(eq.IsActive,'Y') = 'Y'
                    AND COALESCE(q.IsDeleted,'N') = 'N'
                  ORDER BY q.QuestionId", [$examId]
            );
        } catch (Exception $e) {
            // Either exam_questions doesn't exist yet (pre-migration_v22), or
            // IsDeleted doesn't exist yet (pre-migration_v43) — try the legacy
            // ExamInfoId join first, then drop the IsDeleted filter entirely
            // if that column is still missing on this DB.
            try {
                $rows = Database::fetchAll(
                    "SELECT q.QuestionId, q.QuestionDesc, q.CorrectAnswer,
                            COALESCE(q.QuestionType,'MCQ') AS QuestionType,
                            a.Answer1, a.Answer2, a.Answer3, a.Answer4,
                            a.YesNo1, a.YesNo2, a.YesNo3, a.YesNo4,
                            a.MatchStatement1, a.MatchStatement2, a.MatchStatement3, a.MatchStatement4,
                            a.MatchCorrect1, a.MatchCorrect2, a.MatchCorrect3, a.MatchCorrect4
                       FROM questions q
                  LEFT JOIN answers a ON a.QuestionId = q.QuestionId
                      WHERE q.ExamInfoId = ? AND COALESCE(q.IsDeleted,'N') = 'N'
                      ORDER BY q.QuestionId", [$examId]
                );
            } catch (Exception $e2) {
                $rows = Database::fetchAll(
                    "SELECT q.QuestionId, q.QuestionDesc, q.CorrectAnswer,
                            COALESCE(q.QuestionType,'MCQ') AS QuestionType,
                            a.Answer1, a.Answer2, a.Answer3, a.Answer4,
                            a.YesNo1, a.YesNo2, a.YesNo3, a.YesNo4,
                            a.MatchStatement1, a.MatchStatement2, a.MatchStatement3, a.MatchStatement4,
                            a.MatchCorrect1, a.MatchCorrect2, a.MatchCorrect3, a.MatchCorrect4
                       FROM questions q
                  LEFT JOIN answers a ON a.QuestionId = q.QuestionId
                      WHERE q.ExamInfoId = ?
                      ORDER BY q.QuestionId", [$examId]
                );
            }
        }

        return array_map(function ($q) {
            $qType = $q['QuestionType'] ?? 'MCQ';
            $out = [
                'questionId'   => (int)$q['QuestionId'],
                'questionDesc' => $q['QuestionDesc'] ?? '',
                'questionType' => $qType,
            ];
            if ($qType === 'YESNO') {
                $out['statements'] = [];
                for ($i = 1; $i <= 4; $i++) {
                    $t = trim($q['Answer' . $i] ?? '');
                    if ($t === '') continue;
                    $out['statements'][] = ['num' => $i, 'text' => $t, 'correct' => $q['YesNo' . $i] ?? 'Y'];
                }
            } elseif ($qType === 'MATCH') {
                $pool = []; $targets = [];
                for ($i = 1; $i <= 4; $i++) {
                    $t = trim($q['Answer' . $i] ?? '');
                    if ($t !== '') $pool[] = ['num' => $i, 'text' => $t];
                    $s = trim($q['MatchStatement' . $i] ?? '');
                    if ($s !== '') $targets[] = ['num' => $i, 'text' => $s, 'correctOption' => (int)($q['MatchCorrect' . $i] ?? 0)];
                }
                $out['poolOptions'] = $pool;
                $out['targets'] = $targets;
            } else {
                $opts = [];
                for ($i = 1; $i <= 4; $i++) {
                    $t = trim($q['Answer' . $i] ?? '');
                    if ($t !== '') $opts[] = ['num' => $i, 'text' => $t];
                }
                $out['options'] = $opts;
                $out['correctAnswer'] = ltrim(str_ireplace('Answer', '', trim($q['CorrectAnswer'] ?? '')));
            }
            return $out;
        }, $rows);
    }

    // ── Pending profile-change requests (mirrors UserChangeRequests.php) ──

    public static function listChangeRequests(string $status = 'pending'): array
    {
        $status = in_array($status, ['pending', 'approved', 'rejected'], true) ? $status : 'pending';
        $rows = Database::fetchAll(
            "SELECT ucr.*, u.FstName, u.LstName, u.LoginName,
                    a.FstName AS AdminFst, a.LstName AS AdminLst
               FROM user_change_requests ucr
          LEFT JOIN userinfo u ON u.UserInfoId = ucr.UserId
          LEFT JOIN userinfo a ON a.UserInfoId = ucr.ReviewedBy
              WHERE ucr.Status = ?
              ORDER BY ucr.RequestedAt DESC LIMIT 200",
            [$status]
        );

        return array_map(fn($r) => [
            'requestId'   => (int)$r['RequestId'],
            'userId'      => (int)$r['UserId'],
            'userName'    => trim(($r['FstName'] ?? '') . ' ' . ($r['LstName'] ?? '')) ?: ($r['LoginName'] ?? '—'),
            'loginName'   => $r['LoginName'] ?? '',
            'fieldName'   => $r['FieldName'] ?? '',
            'oldValue'    => $r['OldLabel'] ?: ($r['OldValue'] ?: null),
            'newValue'    => $r['NewLabel'] ?: ($r['NewValue'] ?: null),
            'status'      => $r['Status'] ?? 'pending',
            'requestedAt' => $r['RequestedAt'] ?? null,
            'reviewedAt'  => $r['ReviewedAt'] ?? null,
            'reviewedBy'  => trim(($r['AdminFst'] ?? '') . ' ' . ($r['AdminLst'] ?? '')) ?: null,
            'adminNote'   => $r['AdminNote'] ?? null,
        ], $rows);
    }

    public static function reviewChangeRequest(int $requestId, int $adminUserId, string $action, string $note = ''): array
    {
        $req = Database::fetchOne("SELECT * FROM user_change_requests WHERE RequestId = ? AND Status = 'pending' LIMIT 1", [$requestId]);
        if (!$req) {
            return ['ok' => false, 'error' => 'Request not found or already reviewed.'];
        }

        if ($action === 'approve') {
            $col = $req['FieldName'];
            $allowed = ['InstituteId', 'EMail', 'Mobile', 'FstName', 'LstName'];
            if (in_array($col, $allowed, true)) {
                Database::execute("UPDATE userinfo SET `$col` = ? WHERE UserInfoId = ?", [$req['NewValue'] ?: null, (int)$req['UserId']]);
            }
            Database::execute(
                "UPDATE user_change_requests SET Status='approved', ReviewedBy=?, ReviewedAt=NOW(), AdminNote=? WHERE RequestId=?",
                [$adminUserId, $note ?: 'Approved.', $requestId]
            );
            return ['ok' => true, 'message' => 'Change approved and applied to user profile.'];
        }

        if ($action === 'reject') {
            Database::execute(
                "UPDATE user_change_requests SET Status='rejected', ReviewedBy=?, ReviewedAt=NOW(), AdminNote=? WHERE RequestId=?",
                [$adminUserId, $note ?: 'Rejected by admin.', $requestId]
            );
            return ['ok' => true, 'message' => 'Change request rejected.'];
        }

        return ['ok' => false, 'error' => 'Unknown action.'];
    }
}
