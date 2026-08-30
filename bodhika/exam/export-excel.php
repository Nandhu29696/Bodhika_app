<?php
/**
 * exam/export-excel.php — Centralized Excel/CSV export handler.
 *
 * Admin-only. Accepts:
 *   ?type=history     — Exam attempt history (with rankings)
 *   ?type=results     — Admin exam results dashboard
 *   ?type=enrollments — Enrollment payment records
 *
 * All active filter params from the source page are forwarded as-is.
 * Output: UTF-8 BOM CSV — opens directly in Excel with correct encoding.
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');
if (!Auth::isAdmin()) { http_response_code(403); exit('Access denied.'); }

$type = trim($_GET['type'] ?? '');

/* ── Helpers ──────────────────────────────────────────────────────────── */
function csvRow(array $fields): string {
    return implode(',', array_map(function($v) {
        $v = str_replace('"', '""', (string)$v);
        return '"' . $v . '"';
    }, $fields)) . "\r\n";
}

function sendCsvHeaders(string $filename): void {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    // UTF-8 BOM — makes Excel auto-detect encoding correctly
    echo "\xEF\xBB\xBF";
}

function fmtTime(int $secs): string {
    return $secs > 0 ? floor($secs / 60) . 'm ' . ($secs % 60) . 's' : '';
}

/* ══════════════════════════════════════════════════════════════════════
   TYPE: history
   ══════════════════════════════════════════════════════════════════════ */
if ($type === 'history') {
    $filterExam = filter_input(INPUT_GET, 'InfoId', FILTER_VALIDATE_INT) ?: 0;

    $examCols = "e.ExamName, e.NumOfQuestions, e.MinPassing, g.GradeName, s.SubjectName";
    $joins    = "LEFT JOIN examinfo    e ON e.ExamInfoId   = se.ExamInfoId
                 LEFT JOIN gradeinfo   g ON g.GradeInfoId  = e.GradeInfoId
                 LEFT JOIN subjectinfo s ON s.SubjectInfoId = e.SubjectInfoId
                 LEFT JOIN userinfo    u ON u.UserInfoId   = se.UserInfoId";

    if ($filterExam > 0) {
        $rows = Database::fetchAll(
            "SELECT se.*, $examCols,
                    u.FstName, u.LstName, u.LoginName AS StudentLogin
               FROM studentexam se $joins
              WHERE se.ExamInfoId = ?
              ORDER BY (se.Score / NULLIF(se.MarksOutOf,0)) DESC, se.TimeTaken ASC",
            [$filterExam]);
    } else {
        $rows = Database::fetchAll(
            "SELECT se.*, $examCols,
                    u.FstName, u.LstName, u.LoginName AS StudentLogin
               FROM studentexam se $joins
              ORDER BY se.ExamInfoId, (se.Score / NULLIF(se.MarksOutOf,0)) DESC, se.TimeTaken ASC",
            []);
    }

    // Normalise + compute rank
    $prevExam  = null;
    $rankPos   = 0;
    $processed = [];
    foreach ($rows as $r) {
        $score      = (float)($r['Score']      ?? 0);
        $marksOutOf = (float)($r['MarksOutOf'] ?? $r['NumOfQuestions'] ?? 0);
        $pct        = $marksOutOf > 0 ? round($score / $marksOutOf * 100, 1) : 0;
        $minPass    = min(100, max(0, (int)($r['MinPassing'] ?? 0)));
        $result     = $r['Description'] ?? '';
        if ($minPass > 0 && $marksOutOf > 0 && $result !== '') {
            $result = ($pct >= $minPass) ? 'Pass' : 'Fail';
        }
        // Rank resets per exam
        $examId = (int)$r['ExamInfoId'];
        if ($examId !== $prevExam) { $rankPos = 0; $prevExam = $examId; }
        $rankPos++;
        $r['_pct']    = $pct;
        $r['_result'] = $result;
        $r['_rank']   = $rankPos;
        $processed[]  = $r;
    }

    $examName  = ($filterExam > 0 && !empty($processed)) ? ($processed[0]['ExamName'] ?? 'Exam') : 'All';
    $dateStr   = date('Y-m-d');
    $filename  = 'history_' . preg_replace('/[^a-z0-9]+/i', '_', $examName) . '_' . $dateStr . '.csv';
    sendCsvHeaders($filename);

    echo csvRow(['Rank', 'Student', 'Username', 'Exam', 'Grade', 'Subject',
                 'Score', 'Out Of', 'Score %', 'Correct', 'Wrong', 'Skipped',
                 'Result', 'Duration (s)', 'Duration', 'Date']);

    foreach ($processed as $r) {
        $name = trim(($r['FstName'] ?? '') . ' ' . ($r['LstName'] ?? ''));
        $dt   = $r['ExamDate'] ?? $r['CreateDate'] ?? '';
        echo csvRow([
            $r['_rank'],
            $name ?: ($r['StudentLogin'] ?? ''),
            $r['StudentLogin'] ?? '',
            $r['ExamName']    ?? '',
            $r['GradeName']   ?? '',
            $r['SubjectName'] ?? '',
            (int)($r['Score']      ?? 0),
            (int)($r['MarksOutOf'] ?? 0),
            $r['_pct'],
            $r['CorrectCount']  ?? '',
            $r['WrongCount']    ?? '',
            $r['SkippedCount']  ?? '',
            $r['_result'],
            (int)($r['TimeTaken'] ?? 0),
            fmtTime((int)($r['TimeTaken'] ?? 0)),
            $dt ? date('d M Y H:i', strtotime($dt)) : '',
        ]);
    }
    exit;
}

/* ══════════════════════════════════════════════════════════════════════
   TYPE: results  (mirrors ExamResults.php filter logic)
   ══════════════════════════════════════════════════════════════════════ */
if ($type === 'results') {
    $filterExam    = filter_input(INPUT_GET, 'exam',    FILTER_VALIDATE_INT) ?: 0;
    $filterSubject = filter_input(INPUT_GET, 'subject', FILTER_VALIDATE_INT) ?: 0;
    $filterGrade   = filter_input(INPUT_GET, 'grade',   FILTER_VALIDATE_INT) ?: 0;
    $filterStatus  = in_array($_GET['status'] ?? '', ['Pass','Fail',''], true) ? ($_GET['status'] ?? '') : '';
    $filterDateFrom = trim($_GET['date_from'] ?? '');
    $filterDateTo   = trim($_GET['date_to']   ?? '');
    $filterStudent  = trim($_GET['student']   ?? '');

    $where  = ["(se.Description IS NOT NULL AND se.Description != '')"];
    $params = [];
    if ($filterExam    > 0) { $where[] = 'se.ExamInfoId = ?';   $params[] = $filterExam; }
    if ($filterSubject > 0) { $where[] = 'e.SubjectInfoId = ?'; $params[] = $filterSubject; }
    if ($filterGrade   > 0) { $where[] = 'e.GradeInfoId = ?';   $params[] = $filterGrade; }
    if ($filterStatus !== '') { $where[] = 'se.Description = ?'; $params[] = $filterStatus; }
    if ($filterStudent !== '') {
        $where[]  = '(u.FstName LIKE ? OR u.LstName LIKE ? OR u.LoginName LIKE ?)';
        $like = '%' . $filterStudent . '%';
        $params[] = $like; $params[] = $like; $params[] = $like;
    }
    if ($filterDateFrom !== '') {
        $where[] = 'COALESCE(se.ExamDate, se.CreateDate) >= ?';
        $params[] = $filterDateFrom . ' 00:00:00';
    }
    if ($filterDateTo !== '') {
        $where[] = 'COALESCE(se.ExamDate, se.CreateDate) <= ?';
        $params[] = $filterDateTo . ' 23:59:59';
    }

    $whereSQL = 'WHERE ' . implode(' AND ', $where);
    $joinSQL  = "FROM studentexam se
                 LEFT JOIN examinfo    e ON e.ExamInfoId   = se.ExamInfoId
                 LEFT JOIN gradeinfo   g ON g.GradeInfoId  = e.GradeInfoId
                 LEFT JOIN subjectinfo s ON s.SubjectInfoId = e.SubjectInfoId
                 LEFT JOIN userinfo    u ON u.UserInfoId   = se.UserInfoId";

    $rows = Database::fetchAll(
        "SELECT se.StudentExamId,
                COALESCE(se.Score, 0)       AS Score,
                COALESCE(se.MarksOutOf, e.NumOfQuestions, 0) AS MarksOutOf,
                COALESCE(se.Description,'') AS Description,
                COALESCE(se.TimeTaken, 0)   AS TimeTaken,
                se.CorrectCount, se.WrongCount, se.SkippedCount,
                COALESCE(se.ExamDate, se.CreateDate) AS AttemptDate,
                e.ExamName, COALESCE(e.MinPassing, 0) AS MinPassing,
                g.GradeName, s.SubjectName,
                u.FstName, u.LstName, u.LoginName AS StudentLogin
         $joinSQL $whereSQL
         ORDER BY (se.Score / NULLIF(se.MarksOutOf, 0)) DESC, AttemptDate DESC",
        $params);

    // Compute rank per exam, recalculate Pass/Fail
    $prevExam = null; $rank = 0; $processed = [];
    foreach ($rows as $r) {
        $score = (float)$r['Score']; $mo = (float)$r['MarksOutOf'];
        $pct   = $mo > 0 ? round($score / $mo * 100, 1) : 0;
        $minP  = (int)$r['MinPassing'];
        $res   = $r['Description'];
        if ($minP > 0 && $mo > 0) $res = ($pct >= $minP) ? 'Pass' : 'Fail';
        // Simple global rank when export covers multiple exams
        $rank++;
        $r['_pct'] = $pct; $r['_result'] = $res; $r['_rank'] = $rank;
        $processed[] = $r;
    }

    sendCsvHeaders('exam_results_' . date('Y-m-d') . '.csv');
    echo csvRow(['Rank', 'Student', 'Username', 'Exam', 'Grade', 'Subject',
                 'Score', 'Out Of', 'Score %', 'Correct', 'Wrong', 'Skipped',
                 'Result', 'Time', 'Date']);
    foreach ($processed as $r) {
        $name = trim(($r['FstName'] ?? '') . ' ' . ($r['LstName'] ?? ''));
        echo csvRow([
            $r['_rank'],
            $name ?: ($r['StudentLogin'] ?? ''),
            $r['StudentLogin'] ?? '',
            $r['ExamName']    ?? '',
            $r['GradeName']   ?? '',
            $r['SubjectName'] ?? '',
            (int)$r['Score'],
            (int)$r['MarksOutOf'],
            $r['_pct'],
            $r['CorrectCount']  ?? '',
            $r['WrongCount']    ?? '',
            $r['SkippedCount']  ?? '',
            $r['_result'],
            fmtTime((int)($r['TimeTaken'] ?? 0)),
            $r['AttemptDate'] ? date('d M Y H:i', strtotime($r['AttemptDate'])) : '',
        ]);
    }
    exit;
}

/* ══════════════════════════════════════════════════════════════════════
   TYPE: enrollments
   ══════════════════════════════════════════════════════════════════════ */
if ($type === 'enrollments') {
    $filterSubject = filter_input(INPUT_GET, 'subject', FILTER_VALIDATE_INT) ?: 0;
    $filterStatus  = trim($_GET['status'] ?? '');
    $filterName    = trim($_GET['name']   ?? '');
    $filterExpiry  = trim($_GET['expiry'] ?? '');
    $filterMethod  = trim($_GET['method'] ?? '');

    $where  = ['1=1'];
    $params = [];
    if ($filterSubject > 0) { $where[] = 'ep.SubjectInfoId = ?'; $params[] = $filterSubject; }
    if ($filterStatus !== '') { $where[] = 'ep.PaymentStatus = ?'; $params[] = $filterStatus; }
    if ($filterMethod !== '') { $where[] = 'ep.PaymentMethod = ?'; $params[] = $filterMethod; }
    if ($filterName   !== '') {
        $where[] = '(u.FstName LIKE ? OR u.LstName LIKE ? OR u.LoginName LIKE ?)';
        $like = '%' . $filterName . '%';
        $params[] = $like; $params[] = $like; $params[] = $like;
    }
    if ($filterExpiry === 'expired') {
        $where[] = 'ep.EndDate IS NOT NULL AND ep.EndDate < CURDATE()';
    } elseif ($filterExpiry === 'active') {
        $where[] = '(ep.EndDate IS NULL OR ep.EndDate >= CURDATE())';
    }

    $rows = Database::fetchAll(
        "SELECT ep.*,
                u.FstName, u.LstName, u.LoginName, u.EMail, u.ScholarshipFlag,
                s.SubjectName
           FROM enrollment_payments ep
           JOIN userinfo    u ON u.UserInfoId   = ep.UserInfoId
           JOIN subjectinfo s ON s.SubjectInfoId = ep.SubjectInfoId
          WHERE " . implode(' AND ', $where) . "
          ORDER BY ep.CreatedAt DESC",
        $params);

    sendCsvHeaders('enrollments_' . date('Y-m-d') . '.csv');
    echo csvRow(['Student', 'Username', 'Email', 'Subject', 'Status',
                 'Fee at Time', 'Coupon', 'Discount', 'Final Amount',
                 'Payment Method', 'Transaction ID', 'Submitted At',
                 'Start Date', 'End Date', 'Paid At', 'Scholarship',
                 'Override Note', 'Created At']);
    $today = date('Y-m-d');
    foreach ($rows as $r) {
        $name    = trim(($r['FstName'] ?? '') . ' ' . ($r['LstName'] ?? ''));
        $expired = (!empty($r['EndDate']) && $r['EndDate'] < $today) ? 'Expired' : '';
        echo csvRow([
            $name,
            $r['LoginName']     ?? '',
            $r['EMail']         ?? '',
            $r['SubjectName']   ?? '',
            ($r['PaymentStatus'] ?? '') . ($expired ? ' (Expired)' : ''),
            number_format((float)($r['ExamFeeAtTime']   ?? 0), 2),
            $r['CouponCode']    ?? '',
            number_format((float)($r['DiscountApplied'] ?? 0), 2),
            number_format((float)($r['FinalAmount']     ?? 0), 2),
            $r['PaymentMethod'] ?? 'Razorpay',
            $r['TransactionId'] ?? '',
            $r['SubmittedAt']   ? date('d M Y H:i', strtotime($r['SubmittedAt'])) : '',
            $r['StartDate']     ?? '',
            $r['EndDate']       ?? '',
            $r['PaidAt']        ? date('d M Y H:i', strtotime($r['PaidAt'])) : '',
            ($r['ScholarshipFlag'] ?? 'N') === 'Y' ? 'Yes' : 'No',
            $r['OverrideNote']  ?? '',
            $r['CreatedAt']     ? date('d M Y H:i', strtotime($r['CreatedAt'])) : '',
        ]);
    }
    exit;
}

/* ══════════════════════════════════════════════════════════════════════
   TYPE: students  (Registered Students tab)
   ══════════════════════════════════════════════════════════════════════ */
if ($type === 'students') {
    $sf_name    = trim($_GET['sf_name']    ?? '');
    $sf_from    = trim($_GET['sf_from']    ?? '');
    $sf_to      = trim($_GET['sf_to']      ?? '');
    $sf_subject = filter_input(INPUT_GET, 'sf_subject', FILTER_VALIDATE_INT) ?: 0;

    $where  = ["l.Role = 'STDNT'"];
    $params = [];
    if ($sf_name !== '') {
        $like = "%{$sf_name}%";
        $where[] = "(u.FstName LIKE ? OR u.LstName LIKE ? OR u.LoginName LIKE ?)";
        $params[] = $like; $params[] = $like; $params[] = $like;
    }
    if ($sf_from !== '') {
        $where[] = "EXISTS (SELECT 1 FROM logintrackinfo _lt WHERE _lt.UserId = u.UserInfoId AND DATE(_lt.CreateDtm) >= ?)";
        $params[] = $sf_from;
    }
    if ($sf_to !== '') {
        $where[] = "EXISTS (SELECT 1 FROM logintrackinfo _lt WHERE _lt.UserId = u.UserInfoId AND DATE(_lt.CreateDtm) <= ?)";
        $params[] = $sf_to;
    }
    if ($sf_subject > 0) {
        $where[] = "ep.SubjectInfoId = ?";
        $params[] = $sf_subject;
    }

    // InstituteStudentId (migration_v61) is optional and may not exist on
    // every install yet — select it conditionally so this report never 500s
    // on a database that hasn't run that migration.
    $hasInstStudentIdCol = Database::hasColumn('userinfo', 'InstituteStudentId');
    $instStudentIdSelect = $hasInstStudentIdCol ? 'u.InstituteStudentId,' : '';

    $rows = Database::fetchAll(
        "SELECT DISTINCT u.UserInfoId, u.FstName, u.LstName, u.LoginName, u.Mobile, u.EMail,
                $instStudentIdSelect
                l.Active,
                GROUP_CONCAT(DISTINCT s.SubjectName ORDER BY s.SubjectName SEPARATOR ', ') AS Subjects,
                (SELECT MAX(lt2.CreateDtm) FROM logintrackinfo lt2 WHERE lt2.UserId = u.UserInfoId) AS LastSeenAt
         FROM userinfo u
         LEFT JOIN logininfo l ON l.LoginName = u.LoginName
         LEFT JOIN enrollment_payments ep ON ep.UserInfoId = u.UserInfoId
         LEFT JOIN subjectinfo s ON s.SubjectInfoId = ep.SubjectInfoId
         WHERE " . implode(' AND ', $where) . "
         GROUP BY u.UserInfoId
         ORDER BY LastSeenAt DESC, u.UserInfoId DESC",
        $params
    );

    sendCsvHeaders('students_' . date('Y-m-d') . '.csv');
    echo csvRow(['#', 'Name', 'Username', 'Email', 'Mobile', 'Institute Student ID', 'Subjects Enrolled', 'Last Seen', 'Active']);
    $i = 1;
    foreach ($rows as $r) {
        $name     = trim(($r['FstName'] ?? '') . ' ' . ($r['LstName'] ?? ''));
        $lastSeen = !empty($r['LastSeenAt']) ? date('d M Y H:i', strtotime($r['LastSeenAt'])) : '';
        echo csvRow([$i++, $name, $r['LoginName'] ?? '', $r['EMail'] ?? '',
                     $r['Mobile'] ?? '', $r['InstituteStudentId'] ?? '', $r['Subjects'] ?? '', $lastSeen,
                     ($r['Active'] ?? 'N') === 'Y' ? 'Yes' : 'No']);
    }
    exit;
}

/* ══════════════════════════════════════════════════════════════════════
   TYPE: teachers  (Teachers tab)
   ══════════════════════════════════════════════════════════════════════ */
if ($type === 'teachers') {
    $tf_name    = trim($_GET['tf_name']    ?? '');
    $tf_from    = trim($_GET['tf_from']    ?? '');
    $tf_to      = trim($_GET['tf_to']      ?? '');
    $tf_subject = filter_input(INPUT_GET, 'tf_subject', FILTER_VALIDATE_INT) ?: 0;

    $where  = ["l.Role = 'TEACH'"];
    $params = [];
    if ($tf_name !== '') {
        $like = "%{$tf_name}%";
        $where[] = "(u.FstName LIKE ? OR u.LstName LIKE ? OR u.LoginName LIKE ?)";
        $params[] = $like; $params[] = $like; $params[] = $like;
    }
    if ($tf_from !== '') {
        $where[] = "EXISTS (SELECT 1 FROM logintrackinfo _lt WHERE _lt.UserId = u.UserInfoId AND DATE(_lt.CreateDtm) >= ?)";
        $params[] = $tf_from;
    }
    if ($tf_to !== '') {
        $where[] = "EXISTS (SELECT 1 FROM logintrackinfo _lt WHERE _lt.UserId = u.UserInfoId AND DATE(_lt.CreateDtm) <= ?)";
        $params[] = $tf_to;
    }
    if ($tf_subject > 0) {
        $where[] = "ts.SubjectInfoId = ?";
        $params[] = $tf_subject;
    }

    $rows = Database::fetchAll(
        "SELECT DISTINCT u.UserInfoId, u.FstName, u.LstName, u.LoginName, u.Mobile, u.EMail,
                l.Active,
                GROUP_CONCAT(DISTINCT COALESCE(ts.CourseName, s.SubjectName) ORDER BY s.SubjectName SEPARATOR ', ') AS Subjects,
                (SELECT MAX(lt2.CreateDtm) FROM logintrackinfo lt2 WHERE lt2.UserId = u.UserInfoId) AS LastSeenAt
           FROM userinfo u
           LEFT JOIN logininfo l ON l.LoginName = u.LoginName
           LEFT JOIN teacher_profiles tp ON tp.UserInfoId = u.UserInfoId
           LEFT JOIN teacher_subjects ts ON ts.TeacherId = tp.TeacherId AND ts.Active='Y'
           LEFT JOIN subjectinfo s ON s.SubjectInfoId = ts.SubjectInfoId
          WHERE " . implode(' AND ', $where) . "
          GROUP BY u.UserInfoId
          ORDER BY LastSeenAt DESC, u.UserInfoId DESC",
        $params
    );

    sendCsvHeaders('teachers_' . date('Y-m-d') . '.csv');
    echo csvRow(['#', 'Name', 'Username', 'Email', 'Mobile', 'Subjects Taught', 'Last Seen', 'Active']);
    $i = 1;
    foreach ($rows as $r) {
        $name     = trim(($r['FstName'] ?? '') . ' ' . ($r['LstName'] ?? ''));
        $lastSeen = !empty($r['LastSeenAt']) ? date('d M Y H:i', strtotime($r['LastSeenAt'])) : '';
        echo csvRow([$i++, $name, $r['LoginName'] ?? '', $r['EMail'] ?? '',
                     $r['Mobile'] ?? '', $r['Subjects'] ?? '', $lastSeen,
                     ($r['Active'] ?? 'N') === 'Y' ? 'Yes' : 'No']);
    }
    exit;
}

/* ══════════════════════════════════════════════════════════════════════
   TYPE: logins  (Login Activity tab)
   ══════════════════════════════════════════════════════════════════════ */
if ($type === 'logins') {
    $lf_name = trim($_GET['lf_name'] ?? '');
    $lf_role = trim($_GET['lf_role'] ?? '');
    $lf_from = trim($_GET['lf_from'] ?? '');
    $lf_to   = trim($_GET['lf_to']   ?? '');

    $where  = ["u.UserInfoId != 1"];
    $params = [];
    if ($lf_name !== '') {
        $like = "%{$lf_name}%";
        $where[] = "(u.FstName LIKE ? OR u.LstName LIKE ?)";
        $params[] = $like; $params[] = $like;
    }
    if ($lf_role !== '') {
        $where[] = "li.Role LIKE ?";
        $params[] = "%{$lf_role}%";
    }
    if ($lf_from !== '') { $where[] = "DATE(lt.CreateDtm) >= ?"; $params[] = $lf_from; }
    if ($lf_to   !== '') { $where[] = "DATE(lt.CreateDtm) <= ?"; $params[] = $lf_to;   }

    $rows = Database::fetchAll(
        "SELECT u.LoginName, u.FstName, u.LstName, u.EMail,
                COALESCE(li.Role, '') AS RoleDesc,
                lt.CreateDtm AS LoginAt
         FROM logintrackinfo lt
         JOIN userinfo u ON u.UserInfoId = lt.UserId
         LEFT JOIN logininfo li ON li.LoginName = u.LoginName
         WHERE " . implode(' AND ', $where) . "
         ORDER BY lt.CreateDtm DESC",
        $params
    );

    sendCsvHeaders('login_activity_' . date('Y-m-d') . '.csv');
    echo csvRow(['#', 'Name', 'Username', 'Email', 'Role', 'Login Date & Time']);
    $i = 1;
    foreach ($rows as $r) {
        $name    = trim(($r['FstName'] ?? '') . ' ' . ($r['LstName'] ?? ''));
        $loginAt = !empty($r['LoginAt']) ? date('d M Y H:i', strtotime($r['LoginAt'])) : '';
        echo csvRow([$i++, $name, $r['LoginName'] ?? '', $r['EMail'] ?? '',
                     $r['RoleDesc'] ?? '', $loginAt]);
    }
    exit;
}

/* ══════════════════════════════════════════════════════════════════════
   TYPE: login_history  (Login History admin page)
   Columns: #, Name, Login, Role, Institute, Login Time, Last Login,
            IP Address, Device
   ══════════════════════════════════════════════════════════════════════ */
if ($type === 'login_history') {

    /* ── Re-use the same UA → device parser ─────────────────────────── */
    function parseDeviceCsv(?string $ua): string {
        if (!$ua) return '';
        $ua = (string)$ua;
        if (preg_match('/\b(iPad)\b/i', $ua))                                   $device = 'Tablet (iPad)';
        elseif (preg_match('/\b(Android)\b/i', $ua) && preg_match('/\b(Mobile)\b/i', $ua)) $device = 'Mobile (Android)';
        elseif (preg_match('/\b(iPhone|iPod)\b/i', $ua))                        $device = 'Mobile (iOS)';
        elseif (preg_match('/\b(Android)\b/i', $ua))                            $device = 'Tablet (Android)';
        elseif (preg_match('/\b(Windows Phone)\b/i', $ua))                      $device = 'Mobile (Windows)';
        elseif (preg_match('/\b(Windows)\b/i', $ua))                            $device = 'Desktop (Windows)';
        elseif (preg_match('/\b(Macintosh|Mac OS X)\b/i', $ua))                 $device = 'Desktop (Mac)';
        elseif (preg_match('/\b(Linux)\b/i', $ua))                              $device = 'Desktop (Linux)';
        elseif (preg_match('/\b(CrOS)\b/i', $ua))                               $device = 'Chromebook';
        else                                                                      $device = 'Unknown';

        if     (preg_match('/\bEdg(?:e|\/)\b/i', $ua))         $browser = 'Edge';
        elseif (preg_match('/\bOPR\/|Opera\b/i', $ua))          $browser = 'Opera';
        elseif (preg_match('/\bChrome\/(\d+)/i', $ua, $m))      $browser = 'Chrome '.$m[1];
        elseif (preg_match('/\bFirefox\/(\d+)/i', $ua, $m))     $browser = 'Firefox '.$m[1];
        elseif (preg_match('/\bSafari\/\d+/i', $ua) && !preg_match('/\bChrome\b/i', $ua)) $browser = 'Safari';
        elseif (preg_match('/\bMSIE (\d+)|Trident\//i', $ua))   $browser = 'IE';
        else                                                      $browser = '';

        return $browser ? "$device · $browser" : $device;
    }

    /* ── Filters (mirrors LoginTrack.php) ───────────────────────────── */
    $fSearch    = trim($_GET['q']           ?? '');
    $fRole      = trim($_GET['role']        ?? '');
    $fInstitute = filter_input(INPUT_GET, 'institute', FILTER_VALIDATE_INT) ?: 0;
    $fDateFrom  = trim($_GET['date_from']   ?? '');
    $fDateTo    = trim($_GET['date_to']     ?? '');

    $where  = ['1=1'];
    $params = [];

    if ($fSearch !== '') {
        $like = '%' . $fSearch . '%';
        $where[]  = '(u.FstName LIKE ? OR u.LstName LIKE ? OR u.LoginName LIKE ? OR li_neg.LoginName LIKE ?)';
        $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
    }
    if ($fRole !== '') {
        $where[]  = 'COALESCE(li_pos.Role, li_neg.Role) LIKE ?';
        $params[] = '%' . $fRole . '%';
    }
    if ($fInstitute > 0) {
        $where[]  = 'u.InstituteId = ?';
        $params[] = $fInstitute;
    }
    if ($fDateFrom !== '') { $where[] = 'DATE(lt.CreateDtm) >= ?'; $params[] = $fDateFrom; }
    if ($fDateTo   !== '') { $where[] = 'DATE(lt.CreateDtm) <= ?'; $params[] = $fDateTo;   }

    $rows = Database::fetchAll(
        "SELECT
             lt.CreateDtm,
             lt.IpAddress,
             lt.UserAgent,
             COALESCE(u.FstName, '')              AS FstName,
             COALESCE(u.LstName, '')              AS LstName,
             COALESCE(u.LoginName, li_neg.LoginName, '') AS LoginName,
             COALESCE(li_pos.Role, li_neg.Role, '')      AS Role,
             COALESCE(li_pos.last_login, li_neg.last_login) AS LastLogin,
             COALESCE(inst.InstituteName, '')             AS InstituteName
         FROM logintrackinfo lt
         LEFT JOIN userinfo  u       ON u.UserInfoId      = lt.UserId AND lt.UserId > 0
         LEFT JOIN logininfo li_pos  ON li_pos.LoginName  = u.LoginName AND lt.UserId > 0
         LEFT JOIN logininfo li_neg  ON li_neg.LoginInfoId = -lt.UserId AND lt.UserId < 0
         LEFT JOIN institutes inst   ON inst.InstituteId   = u.InstituteId
         WHERE " . implode(' AND ', $where) . "
         ORDER BY lt.CreateDtm DESC",
        $params
    );

    sendCsvHeaders('login_history_' . date('Y-m-d') . '.csv');
    echo csvRow(['#', 'Name', 'Login', 'Role', 'Institute',
                 'Login Time', 'Last Login', 'IP Address', 'Device']);
    $i = 1;
    foreach ($rows as $r) {
        $name    = trim(($r['FstName'] ?? '') . ' ' . ($r['LstName'] ?? ''));
        $loginAt = !empty($r['CreateDtm']) ? date('d M Y H:i', strtotime($r['CreateDtm'])) : '';
        $lastLog = !empty($r['LastLogin']) ? date('d M Y H:i', strtotime($r['LastLogin'])) : '';
        echo csvRow([
            $i++,
            $name ?: ($r['LoginName'] ?? ''),
            $r['LoginName']     ?? '',
            $r['Role']          ?? '',
            $r['InstituteName'] ?? '',
            $loginAt,
            $lastLog,
            $r['IpAddress']     ?? '',
            parseDeviceCsv($r['UserAgent'] ?? null),
        ]);
    }
    exit;
}

/* ── TYPE: cheating  (Cheating Detection Dashboard) ────────────────────────
   Columns: #, Student, Login, Institute, Exam, Tab Switches, Copy Events,
            Paste Events, Refreshes, Total Events, First Seen, Last Seen,
            Multiple Logins, Severity
   ─────────────────────────────────────────────────────────────────────── */
if ($type === 'cheating') {

    $examId    = isset($_GET['examId'])    ? (int)$_GET['examId']    : 0;
    $dateFrom  = $_GET['dateFrom']  ?? '';
    $dateTo    = $_GET['dateTo']    ?? '';
    $minEvents = isset($_GET['minEvents']) ? (int)$_GET['minEvents'] : 1;
    $eventType = $_GET['eventType'] ?? '';

    $where  = ['1=1'];
    $params = [];

    if ($examId > 0) {
        $where[]  = 'ee.ExamInfoId = ?';
        $params[] = $examId;
    }
    if ($dateFrom !== '') {
        $where[]  = 'DATE(ee.LastEventAt) >= ?';
        $params[] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where[]  = 'DATE(ee.LastEventAt) <= ?';
        $params[] = $dateTo;
    }

    $having = "HAVING TotalEvents >= $minEvents";

    if ($eventType !== '') {
        $allowed = ['tab_switch','copy','paste','browser_refresh'];
        if (in_array($eventType, $allowed)) {
            $col = match($eventType) {
                'tab_switch'      => 'TabSwitches',
                'copy'            => 'CopyEvents',
                'paste'           => 'PasteEvents',
                'browser_refresh' => 'Refreshes',
                default           => null,
            };
            if ($col) $having .= " AND $col > 0";
        }
    }

    $rows = Database::fetchAll(
        "SELECT
            ee.UserId, ee.ExamInfoId,
            TRIM(CONCAT(COALESCE(u.FstName,''),' ',COALESCE(u.LstName,''))) AS FullName,
            COALESCE(li.LoginName, CONCAT('ID:',ABS(ee.UserId))) AS LoginName,
            COALESCE(inst.InstituteName,'—') AS InstituteName,
            COALESCE(ei.ExamName, CONCAT('Exam #', ee.ExamInfoId)) AS ExamName,
            SUM(CASE WHEN ee.EventType='tab_switch'      THEN ee.EventCount ELSE 0 END) AS TabSwitches,
            SUM(CASE WHEN ee.EventType='copy'            THEN ee.EventCount ELSE 0 END) AS CopyEvents,
            SUM(CASE WHEN ee.EventType='paste'           THEN ee.EventCount ELSE 0 END) AS PasteEvents,
            SUM(CASE WHEN ee.EventType='browser_refresh' THEN ee.EventCount ELSE 0 END) AS Refreshes,
            SUM(ee.EventCount) AS TotalEvents,
            MIN(ee.CreatedAt)  AS FirstSeen,
            MAX(ee.LastEventAt) AS LastSeen
         FROM exam_events ee
         LEFT JOIN userinfo   u    ON u.UserInfoId     = ee.UserId
         LEFT JOIN logininfo  li   ON li.LoginName    = u.LoginName
         LEFT JOIN institutes inst ON inst.InstituteId = u.InstituteId
         LEFT JOIN examinfo   ei   ON ei.ExamInfoId   = ee.ExamInfoId
         WHERE " . implode(' AND ', $where) . "
         GROUP BY ee.UserId, ee.ExamInfoId
         $having
         ORDER BY TotalEvents DESC",
        $params
    );

    /* Multiple-login detection */
    $multiLoginIds = [];
    try {
        $mlRows = Database::fetchAll(
            "SELECT UserId
               FROM logintrackinfo
              WHERE UserId > 0
                " . ($dateFrom !== '' ? "AND DATE(CreateDtm) >= '$dateFrom'" : '') . "
                " . ($dateTo   !== '' ? "AND DATE(CreateDtm) <= '$dateTo'"   : '') . "
              GROUP BY UserId, DATE(CreateDtm)
             HAVING COUNT(*) >= 2",
            []
        );
        foreach ($mlRows as $ml) {
            $multiLoginIds[(int)$ml['UserId']] = true;
        }
    } catch (Exception $e) { /* table may not exist yet */ }

    sendCsvHeaders('cheating_report_' . date('Y-m-d') . '.csv');
    echo csvRow([
        '#', 'Student', 'Login', 'Institute', 'Exam',
        'Tab Switches', 'Copy Events', 'Paste Events', 'Refreshes', 'Total Events',
        'First Seen', 'Last Seen', 'Multiple Logins', 'Severity'
    ]);

    $i = 1;
    foreach ($rows as $r) {
        $total    = (int)$r['TotalEvents'];
        $severity = $total >= 20 ? 'HIGH' : ($total >= 10 ? 'MEDIUM' : 'LOW');
        $multiLog = isset($multiLoginIds[(int)$r['UserId']]) ? 'Yes' : 'No';

        $firstSeen = !empty($r['FirstSeen'])  ? date('d M Y H:i', strtotime($r['FirstSeen']))  : '';
        $lastSeen  = !empty($r['LastSeen'])   ? date('d M Y H:i', strtotime($r['LastSeen']))   : '';

        echo csvRow([
            $i++,
            trim($r['FullName'])  ?: $r['LoginName'],
            $r['LoginName']       ?? '',
            $r['InstituteName']   ?? '',
            $r['ExamName']        ?? '',
            (int)$r['TabSwitches'],
            (int)$r['CopyEvents'],
            (int)$r['PasteEvents'],
            (int)$r['Refreshes'],
            $total,
            $firstSeen,
            $lastSeen,
            $multiLog,
            $severity,
        ]);
    }
    exit;
}

/* Fallback */
http_response_code(400);
exit('Invalid export type.');
