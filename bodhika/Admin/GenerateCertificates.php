<?php
/**
 * Admin/GenerateCertificates.php — issue Completion / Merit certificates
 * to one or more students in a single batch.
 *
 * Flow:
 *   1. Pick Certificate Type, Template, Subject, (Merit: an Exam — required,
 *      so each student's Grade can be derived from an actual marks record).
 *      Changing these re-submits as GET so the student list below reflects
 *      the choice (same pattern as exam/assign.php).
 *   2. Pick students (search/filter), Course Name, Duration, Issue Date.
 *   3. POST issues one certificate per selected student via Certificate::issue()
 *      and redirects to a combined print view.
 *
 * Score/Grade are ALWAYS re-derived server-side from `studentexam` at POST
 * time (latest attempt for that exam) — never trusted from client input —
 * so a certificate's marks can't be forged via a tampered form field.
 *
 * TemplateType='word' templates additionally need any "custom" fields from
 * their WordFieldMap (Admin/CertificateTemplateWordMap.php) typed once for
 * the whole batch (e.g. a training program's start/end dates). templateId is
 * therefore threaded through as a GET param too, like subjectId/examId/
 * instituteId, so choosing a template reloads the page and reveals the right
 * inputs before the student picker — see reloadWithTemplate() below.
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
require_once __DIR__ . '/../Lib/Certificate.php';
require_once __DIR__ . '/../Lib/WordTemplate.php';
Auth::requireLogin('../auth/login.php');
if (!Auth::isAdmin()) { header('Location: ../exam/search.php'); exit; }

$errors = [];

/* ── Setup filters (GET — re-render the page as choices change) ──────────── */
$certType    = in_array($_REQUEST['certType'] ?? '', ['completion', 'merit'], true) ? $_REQUEST['certType'] : 'completion';
$subjectId   = filter_input(INPUT_GET, 'subjectId',   FILTER_VALIDATE_INT) ?: (int)($_POST['subjectId'] ?? 0);
$examId      = filter_input(INPUT_GET, 'examId',      FILTER_VALIDATE_INT) ?: (int)($_POST['examId'] ?? 0);
$instituteId = filter_input(INPUT_GET, 'instituteId', FILTER_VALIDATE_INT) ?: (int)($_POST['instituteId'] ?? 0);
$templateId  = filter_input(INPUT_GET, 'templateId',  FILTER_VALIDATE_INT) ?: (int)($_POST['TemplateId'] ?? 0);

/* ── Pagination helpers (mirrors AdminUsers.php / StudentGroupMembers.php) ──
   Uses $_REQUEST (not just $_GET) for search/page because — unlike the other
   admin pages here — a failed POST (validation errors) re-renders this same
   page with the student picker still showing, so the search/page the admin
   had open needs to survive a POST just like subjectId/examId/etc. already do. */
const PAGE_SIZE = 25;
function currentPage(string $key): int {
    return max(1, (int)($_REQUEST[$key] ?? 1));
}
function paginator(int $total, int $current, int $pageSize, array $qs, string $pageKey): string {
    if ($total <= $pageSize) return '';
    $pages = (int)ceil($total / $pageSize);
    $html  = '<div class="pager">';
    for ($i = 1; $i <= $pages; $i++) {
        $q    = array_merge($qs, [$pageKey => $i]);
        $url  = '?' . http_build_query($q);
        $cls  = $i === $current ? 'pager-active' : 'pager-link';
        $html .= "<a href=\"{$url}\" class=\"{$cls}\">{$i}</a> ";
    }
    return $html . '</div>';
}
$studentSearch = trim($_REQUEST['sq'] ?? '');
$studentPage   = currentPage('sp');

/* ── Handle POST — issue certificates ─────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::validateCsrf();

    $courseName = trim($_POST['CourseName'] ?? '');
    $duration   = trim($_POST['Duration']   ?? '');
    $issueDate  = trim($_POST['IssueDate']  ?? '') ?: date('Y-m-d');
    $userIds    = array_values(array_unique(array_filter(
        array_map('intval', $_POST['user_ids'] ?? []),
        fn($id) => $id > 0
    )));

    if ($templateId <= 0)            $errors[] = 'Please choose a template.';
    if ($courseName === '')          $errors[] = 'Please enter the course / subject name.';
    if (!$userIds)                   $errors[] = 'Please select at least one student.';
    if ($certType === 'merit' && $examId <= 0) {
        $errors[] = 'Merit certificates require an exam to be selected (grades are derived from marks).';
    }
    if (!DateTime::createFromFormat('Y-m-d', $issueDate)) $errors[] = 'Invalid issue date.';

    // Word-template batches: resolve the template file + field map up front,
    // and collect the "typed once per batch" custom field values (e.g.
    // START_DATE/END_DATE) — every selected student's filled .docx reuses
    // these same values, only the system fields (StudentName, ...) vary.
    $wordTpl        = null;
    $wordFieldMap   = [];
    $wordCustomVals = [];
    if (!$errors && $templateId > 0) {
        $tplRow = Certificate::getTemplate($templateId);
        if ($tplRow && ($tplRow['TemplateType'] ?? 'coded') === 'word') {
            $wordTpl      = $tplRow;
            $wordFieldMap = Certificate::decodeJsonArray($tplRow['WordFieldMap'] ?? null);
            if (empty($tplRow['WordFile']) || !is_file(__DIR__ . '/' . $tplRow['WordFile'])) {
                $errors[] = 'This Word template\'s file is missing on the server — re-upload it.';
            }
            foreach ($wordFieldMap as $token => $map) {
                if (($map['type'] ?? '') !== 'custom') continue;
                $val = trim((string)($_POST['custom_field'][$token] ?? ''));
                if ($val === '') {
                    $errors[] = 'Please fill in "' . ($map['label'] ?? $token) . '" for the Word template.';
                    continue;
                }
                $wordCustomVals[$token] = $val;
            }
        }
    }

    if (!$errors) {
        $issuedIds = [];
        $skipped   = [];

        $students = Database::fetchAll(
            "SELECT UserInfoId, FstName, LstName FROM userinfo WHERE UserInfoId IN ("
            . implode(',', array_fill(0, count($userIds), '?')) . ')',
            $userIds
        );
        $studentMap = [];
        foreach ($students as $st) $studentMap[(int)$st['UserInfoId']] = $st;

        foreach ($userIds as $uid) {
            $student = $studentMap[$uid] ?? null;
            if (!$student) { $skipped[] = "User #$uid (not found)"; continue; }
            $name = trim($student['FstName'] . ' ' . $student['LstName']) ?: "User #$uid";

            $score = $marksOutOf = $percentage = null;
            if ($examId > 0) {
                try {
                    $attempt = Database::fetchOne(
                        "SELECT Score, MarksOutOf FROM studentexam
                          WHERE ExamInfoId = ? AND UserInfoId = ? AND Score IS NOT NULL
                          ORDER BY COALESCE(ExamDate, CreateDate) DESC, StudentExamId DESC LIMIT 1",
                        [$examId, $uid]
                    );
                } catch (Exception $e) { $attempt = false; }

                if ($attempt) {
                    $score      = (float)$attempt['Score'];
                    $marksOutOf = (float)($attempt['MarksOutOf'] ?? 0);
                    $percentage = $marksOutOf > 0 ? round($score / $marksOutOf * 100, 2) : null;
                } elseif ($certType === 'merit') {
                    $skipped[] = "$name (no exam attempt found — cannot assign a grade)";
                    continue;
                }
            }

            $result = Certificate::issue([
                'TemplateId'    => $templateId,
                'UserInfoId'    => $uid,
                'SubjectInfoId' => $subjectId ?: null,
                'ExamInfoId'    => $examId ?: null,
                'StudentName'   => $name,
                'CourseName'    => $courseName,
                'Duration'      => $duration,
                'IssueDate'     => $issueDate,
                'Score'         => $score,
                'MarksOutOf'    => $marksOutOf,
                'Percentage'    => $percentage,
                'CertType'      => $certType,
                'IssuedBy'      => Auth::currentUser(),
            ]);

            if (!$result['ok']) { $skipped[] = "$name ({$result['error']})"; continue; }

            $issuedIds[] = $result['certificateId'];

            if ($wordTpl) {
                // Re-fetch the just-inserted row rather than recomputing
                // locally, so the filled document matches exactly what was
                // persisted (CertificateNo/Grade are only known now that
                // issue() has run — Certificate::issue() derives Grade from
                // Percentage internally for merit certs).
                $savedCert = Certificate::findById($result['certificateId']);
                $fieldValues = $savedCert ? Certificate::fieldValues($savedCert) : [];

                $tokenValues = [];
                foreach ($wordFieldMap as $token => $map) {
                    $tokenValues[$token] = ($map['type'] ?? '') === 'system'
                        ? ($fieldValues[$map['field']] ?? '')
                        : ($wordCustomVals[$token] ?? '');
                }

                $genDir = __DIR__ . '/certificates/generated/';
                $genRel = 'certificates/generated/cert_' . $result['certificateId'] . '_' . uniqid('', true) . '.docx';

                // fillTemplate() already reports failures via its return value +
                // $fillError, but ZipArchive can throw a raw PHP Error (not an
                // Exception) on some builds — wrapped here too so one student's
                // Word-doc failure can never abort the rest of the batch or the
                // redirect below (which is what previously made this look like
                // "nothing happened" with no certificate visible at all).
                $fillError = null;
                try {
                    $filled = WordTemplate::fillTemplate(__DIR__ . '/' . $wordTpl['WordFile'], __DIR__ . '/' . $genRel, $tokenValues, $fillError);
                } catch (\Throwable $e) {
                    $filled = false;
                    $fillError = $e->getMessage();
                }

                if ($filled) {
                    Certificate::attachGeneratedFile($result['certificateId'], $genRel, $wordCustomVals);
                } else {
                    $skipped[] = "$name (certificate issued, but the Word document could not be generated" . ($fillError ? ': ' . $fillError : '') . ')';
                }
            }
        }

        if ($issuedIds) {
            $flash = 'success|Issued ' . count($issuedIds) . ' certificate(s).'
                   . ($skipped ? ' Skipped: ' . implode('; ', $skipped) : '');
            header('Location: ../exam/certificate-print.php?batch=' . implode(',', $issuedIds)
                 . '&flash=' . urlencode($flash));
            exit;
        }
        $errors[] = $skipped ? ('No certificates issued. ' . implode('; ', $skipped)) : 'No certificates were issued.';
    }
}

/* ── Dropdown data ───────────────────────────────────────────────────────── */
$institutes = Database::fetchAll("SELECT InstituteId, InstituteName FROM institutes ORDER BY InstituteName");
$instituteNames = [];
foreach ($institutes as $inst) $instituteNames[(int)$inst['InstituteId']] = $inst['InstituteName'];

// Every active template of the chosen CertType, from every institute — not
// scoped to the currently-selected Institution filter. Previously this list
// only included an institute's own templates once that institute was picked
// in Step 1, which made a freshly-created institute template (e.g. a Word
// template) silently vanish from the picker until an admin discovered the
// undocumented prerequisite. The Institution filter still narrows the
// *student list* below; the Template list is shown in full, with each
// institute-owned template's owning institute named in its label so it's
// still clear (and an admin can still choose not to use it for a different
// institute's students).
$templates = Certificate::listTemplates($certType, true, null, true);
$subjects  = Database::fetchAll("SELECT SubjectInfoId, SubjectName FROM subjectinfo ORDER BY SubjectName");

// Word-template custom fields (Admin/CertificateTemplateWordMap.php) to
// render as extra batch-level inputs below, if the currently-selected
// template is TemplateType='word'.
$selectedWordFields = [];
if ($templateId > 0) {
    $selectedTplRow = Certificate::getTemplate($templateId);
    if ($selectedTplRow && ($selectedTplRow['TemplateType'] ?? 'coded') === 'word') {
        foreach (Certificate::decodeJsonArray($selectedTplRow['WordFieldMap'] ?? null) as $token => $map) {
            if (($map['type'] ?? '') === 'custom') {
                $selectedWordFields[$token] = $map['label'] ?? $token;
            }
        }
    }
}

$examWhere  = [];
$examParams = [];
if ($subjectId > 0) { $examWhere[] = 'e.SubjectInfoId = ?'; $examParams[] = $subjectId; }
$exams = Database::fetchAll(
    "SELECT e.ExamInfoId, e.ExamName, s.SubjectName
       FROM examinfo e
  LEFT JOIN subjectinfo s ON s.SubjectInfoId = e.SubjectInfoId"
    . ($examWhere ? ' WHERE ' . implode(' AND ', $examWhere) : '')
    . " ORDER BY e.ExamName",
    $examParams
);

/* ── Student list ────────────────────────────────────────────────────────────
   Completion: every student account (mirrors exam/assign.php's user list).
   Merit:      only students who actually have a scored attempt for the
               chosen exam — and we show their live score/grade preview.
   Both branches are now search-filtered + server-side paginated (PAGE_SIZE)
   instead of fetching every match and letting JS hide rows — this list was
   the entire student body for Completion certs, unbounded. */
$students     = [];
$studentTotal = 0;
$searchLike   = $studentSearch !== '' ? "%{$studentSearch}%" : null;

$nameCounts = [];   // FullName => how many students in the whole candidate pool share it (see note below, populated per-branch)

if ($certType === 'merit') {
    if ($examId > 0) {
        $where  = ['se.ExamInfoId = ?', 'se.Score IS NOT NULL'];
        $params = [$examId];
        if ($instituteId > 0) { $where[] = 'u.InstituteId = ?'; $params[] = $instituteId; }
        $where[] = "se.StudentExamId = (
                    SELECT se2.StudentExamId FROM studentexam se2
                     WHERE se2.ExamInfoId = se.ExamInfoId AND se2.UserInfoId = se.UserInfoId
                       AND se2.Score IS NOT NULL
                     ORDER BY COALESCE(se2.ExamDate, se2.CreateDate) DESC, se2.StudentExamId DESC
                     LIMIT 1
                )";
        $baseSQLNoSearch = "FROM studentexam se
                     JOIN userinfo  u ON u.UserInfoId = se.UserInfoId
                LEFT JOIN logininfo l ON l.LoginName  = u.LoginName
                    WHERE " . implode(' AND ', $where);

        // Duplicate-name protection must look across the WHOLE candidate pool
        // (every exam-scored student, not just this page/search), otherwise
        // two same-named students landing on different pages would both show
        // up unflagged — defeating the point of this safety check. GROUP BY
        // + HAVING COUNT(*) > 1 keeps this cheap: only actual duplicate name
        // groups come back, not all 1000+ candidate rows.
        foreach (Database::fetchAll(
            "SELECT u.FstName, u.LstName, COUNT(*) AS cnt {$baseSQLNoSearch}
             GROUP BY u.FstName, u.LstName HAVING COUNT(*) > 1", $params
        ) as $row) {
            $nameCounts[trim($row['FstName'] . ' ' . $row['LstName'])] = (int)$row['cnt'];
        }

        if ($searchLike !== null) {
            $where[] = '(u.FstName LIKE ? OR u.LstName LIKE ? OR l.LoginName LIKE ?)';
            array_push($params, $searchLike, $searchLike, $searchLike);
        }
        $whereSQL = implode(' AND ', $where);
        $baseSQL  = "FROM studentexam se
                     JOIN userinfo  u ON u.UserInfoId = se.UserInfoId
                LEFT JOIN logininfo l ON l.LoginName  = u.LoginName
                    WHERE {$whereSQL}";

        $studentTotal = (int)(Database::fetchOne("SELECT COUNT(*) AS cnt {$baseSQL}", $params)['cnt'] ?? 0);

        /* One row per student: their LATEST scored attempt for this exam,
           picked via a correlated subquery (avoids ONLY_FULL_GROUP_BY issues
           and matches the same "latest attempt wins" rule used at POST time). */
        $offset   = ($studentPage - 1) * PAGE_SIZE;
        $students = Database::fetchAll(
            "SELECT u.UserInfoId, u.FstName, u.LstName, l.LoginName,
                    se.Score, se.MarksOutOf
             {$baseSQL}
             ORDER BY u.FstName, u.LstName
             LIMIT {$offset}, " . PAGE_SIZE,
            $params
        );
        foreach ($students as &$st) {
            $sc = (float)($st['Score'] ?? 0);
            $mo = (float)($st['MarksOutOf'] ?? 0);
            $st['Percentage'] = $mo > 0 ? round($sc / $mo * 100, 1) : null;
            $st['Grade']      = $st['Percentage'] !== null ? Certificate::gradeForPercent($st['Percentage']) : '';
        }
        unset($st);
    }
} else {
    $where  = ['l.LoginInfoId IS NOT NULL'];
    $params = [];
    if ($instituteId > 0) { $where[] = 'u.InstituteId = ?'; $params[] = $instituteId; }

    $baseSQLNoSearch = "FROM userinfo  u
            LEFT JOIN logininfo l ON l.LoginName = u.LoginName
                 WHERE " . implode(' AND ', $where);
    // Same reasoning as the merit branch above: only fetch the duplicate
    // name groups themselves, not all 1600+ candidate rows.
    foreach (Database::fetchAll(
        "SELECT u.FstName, u.LstName, COUNT(*) AS cnt {$baseSQLNoSearch}
         GROUP BY u.FstName, u.LstName HAVING COUNT(*) > 1", $params
    ) as $row) {
        $nameCounts[trim($row['FstName'] . ' ' . $row['LstName'])] = (int)$row['cnt'];
    }

    if ($searchLike !== null) {
        $where[] = '(u.FstName LIKE ? OR u.LstName LIKE ? OR l.LoginName LIKE ?)';
        array_push($params, $searchLike, $searchLike, $searchLike);
    }
    $whereSQL = implode(' AND ', $where);
    $baseSQL  = "FROM userinfo  u
            LEFT JOIN logininfo l ON l.LoginName = u.LoginName
                 WHERE {$whereSQL}";

    $studentTotal = (int)(Database::fetchOne("SELECT COUNT(*) AS cnt {$baseSQL}", $params)['cnt'] ?? 0);

    $offset   = ($studentPage - 1) * PAGE_SIZE;
    $students = Database::fetchAll(
        "SELECT u.UserInfoId, u.FstName, u.LstName, l.LoginName
         {$baseSQL}
         ORDER BY u.FstName, u.LstName
         LIMIT {$offset}, " . PAGE_SIZE,
        $params
    );
}

$qsStudents = array_filter([
    'certType'    => $certType,
    'subjectId'   => $subjectId ?: null,
    'examId'      => $examId ?: null,
    'instituteId' => $instituteId ?: null,
    'templateId'  => $templateId ?: null,
    'sq'          => $studentSearch,
], fn($v) => $v !== null && $v !== '');

$defaultCourseName = '';
foreach ($subjects as $sub) {
    if ((int)$sub['SubjectInfoId'] === $subjectId) { $defaultCourseName = $sub['SubjectName']; break; }
}

/**
 * Certificates are issued by clicking a checkbox next to a name — there is
 * no confirmation step and CourseName/StudentName are snapshotted verbatim
 * at POST time (see Certificate::issue). If two students share the same
 * full name, nothing in the UI lets an admin tell their rows apart, so a
 * misclick silently issues the certificate to the wrong account (only
 * discoverable later, when it's printed). $nameCounts (built above, across
 * the whole candidate pool, not just this page) drives a disambiguator
 * (login/UserInfoId) shown beside every duplicate instead of two
 * identical-looking rows.
 */

$pageTitle = 'Generate Certificates';
$pageHead  = '<style>
  .cert-student-row{display:flex;align-items:center;gap:10px;padding:8px 12px;border-bottom:1px solid #e2e8f0;}
  .cert-student-row:hover{background:#f7fafc;}
  .cert-student-row:last-child{border-bottom:none;}
  .cert-grade-chip{padding:2px 10px;border-radius:10px;font-size:.75rem;font-weight:700;background:#fef3c7;color:#92400e;}
  .pager        { margin:10px 16px; font-size:12px; display:flex; flex-wrap:wrap; gap:4px; }
  .pager-link   { display:inline-block; padding:3px 10px; border:1px solid #cbd5e1; border-radius:4px;
                   text-decoration:none; color:#475569; }
  .pager-link:hover { border-color:var(--clr-primary); color:var(--clr-primary); }
  .pager-active { display:inline-block; padding:3px 10px; border-radius:4px;
                   background:var(--clr-primary); color:#fff; border:1px solid var(--clr-primary); }
</style>';
include __DIR__ . '/../includes/header.php';
?>

<nav style="font-size:.85rem;color:#718096;margin-bottom:14px;">
  <a href="AppSettings.php?tab=certificate" style="color:#3182ce;text-decoration:none;">&#9881; Certificates</a>
  <span style="margin:0 6px;">&rsaquo;</span>
  <span>Generate</span>
</nav>

<?php if ($errors): ?>
<div class="alert alert-danger" style="margin-bottom:14px;">
  <?php foreach ($errors as $e) echo '<div>' . htmlspecialchars($e) . '</div>'; ?>
</div>
<?php endif; ?>

<!-- ── Step 1: type / template / subject / exam (GET, auto-reloads) ───────── -->
<div class="card" style="margin-bottom:16px;">
  <div class="card-header">&#127942; 1. Certificate Setup</div>
  <div class="card-body">
    <form method="get" id="setupForm" class="form-row cols-3">
      <div class="form-group">
        <label class="form-label">Certificate Type</label>
        <select class="form-control" name="certType" onchange="this.form.submit()">
          <option value="completion" <?= $certType==='completion'?'selected':'' ?>>&#127891; Course Completion</option>
          <option value="merit"      <?= $certType==='merit'?'selected':''      ?>>&#127942; Merit (grade-based)</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Subject</label>
        <select class="form-control" name="subjectId" onchange="this.form.submit()">
          <option value="0">— Any / Not exam-linked —</option>
          <?php foreach ($subjects as $sub): ?>
            <option value="<?= (int)$sub['SubjectInfoId'] ?>" <?= $subjectId===(int)$sub['SubjectInfoId']?'selected':'' ?>>
              <?= htmlspecialchars($sub['SubjectName']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">
          Exam <?= $certType==='merit' ? '<span style="color:#dc2626;">*</span> (required for grading)' : '(optional)' ?>
        </label>
        <select class="form-control" name="examId" onchange="this.form.submit()">
          <option value="0">— None —</option>
          <?php foreach ($exams as $ex): ?>
            <option value="<?= (int)$ex['ExamInfoId'] ?>" <?= $examId===(int)$ex['ExamInfoId']?'selected':'' ?>>
              <?= htmlspecialchars($ex['ExamName']) ?><?= $ex['SubjectName'] ? ' — ' . htmlspecialchars($ex['SubjectName']) : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Institution <small style="font-weight:400;color:var(--clr-text-muted);">(narrows the student list below)</small></label>
        <select class="form-control" name="instituteId" onchange="this.form.submit()">
          <option value="0">— All Institutions —</option>
          <?php foreach ($institutes as $inst): ?>
            <option value="<?= (int)$inst['InstituteId'] ?>" <?= $instituteId===(int)$inst['InstituteId']?'selected':'' ?>>
              <?= htmlspecialchars($inst['InstituteName']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </form>
    <?php if ($certType === 'merit' && $examId <= 0): ?>
      <div class="alert alert-warning" style="margin-top:12px;margin-bottom:0;">
        &#9888; Select an exam above to load students with scored attempts and preview their auto-assigned grade.
      </div>
    <?php endif; ?>
  </div>
</div>

<?php if (!$templates): ?>
  <div class="alert alert-warning">
    No active <?= $certType==='merit'?'merit':'completion' ?> templates yet.
    <a href="CertificateTemplates.php">Create one first &rarr;</a>
  </div>
<?php elseif ($certType === 'merit' && $examId <= 0): ?>
  <!-- waiting for exam selection -->
<?php else: ?>

<!-- ── Step 2: students + course details (POST) ───────────────────────────── -->
<form method="post" id="issueForm">
  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken()) ?>">
  <input type="hidden" name="certType"    value="<?= htmlspecialchars($certType) ?>">
  <input type="hidden" name="subjectId"   value="<?= $subjectId ?>">
  <input type="hidden" name="examId"      value="<?= $examId ?>">
  <input type="hidden" name="instituteId" value="<?= $instituteId ?>">
  <input type="hidden" name="sq" value="<?= htmlspecialchars($studentSearch) ?>">
  <input type="hidden" name="sp" value="<?= $studentPage ?>">

  <div class="card" style="margin-bottom:16px;">
    <div class="card-header">&#128221; 2. Certificate Details</div>
    <div class="card-body">
      <div class="form-row cols-2">
        <div class="form-group">
          <label class="form-label">Template</label>
          <select class="form-control" name="TemplateId" required onchange="reloadWithTemplate(this)">
            <?php foreach ($templates as $t):
              $tOwnerName = !empty($t['InstituteId']) ? ($instituteNames[(int)$t['InstituteId']] ?? null) : null;
              $tTypeLabel = ($t['TemplateType'] ?? 'coded') === 'word' ? 'Word template' : (($t['TemplateType'] ?? 'coded') === 'image' ? 'Image template' : 'global theme');
            ?>
              <option value="<?= (int)$t['TemplateId'] ?>" <?= $templateId===(int)$t['TemplateId'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($t['Name']) ?>
                (<?= htmlspecialchars($tTypeLabel) ?><?= $tOwnerName ? ' — ' . htmlspecialchars($tOwnerName) : '' ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Course / Subject Name</label>
          <input type="text" class="form-control" name="CourseName" required maxlength="200"
                 value="<?= htmlspecialchars($defaultCourseName) ?>" placeholder="e.g. Full-Stack Web Development">
        </div>
        <div class="form-group">
          <label class="form-label">Duration <small style="font-weight:400;color:var(--clr-text-muted);">(optional)</small></label>
          <input type="text" class="form-control" name="Duration" maxlength="100" placeholder="e.g. 6 weeks / 40 hours">
        </div>
        <div class="form-group">
          <label class="form-label">Issue Date</label>
          <input type="date" class="form-control" name="IssueDate" required value="<?= date('Y-m-d') ?>">
        </div>
      </div>

      <?php if ($selectedWordFields): ?>
        <div style="margin-top:6px;padding-top:14px;border-top:1px solid #e2e8f0;">
          <div style="font-size:.85rem;font-weight:700;color:#1e3a5f;margin-bottom:10px;">
            &#128196; Word Template Fields <small style="font-weight:400;color:var(--clr-text-muted);">(typed once, used on every certificate in this batch)</small>
          </div>
          <div class="form-row cols-2">
            <?php foreach ($selectedWordFields as $token => $label): ?>
              <div class="form-group">
                <label class="form-label"><?= htmlspecialchars($label) ?></label>
                <input type="text" class="form-control" name="custom_field[<?= htmlspecialchars($token) ?>]" required maxlength="200"
                       placeholder="{{<?= htmlspecialchars($token) ?>}}">
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
      <span>&#128101; 3. Select Students</span>
      <span style="font-size:.85rem;color:#718096;"><span id="selCount">0</span> selected</span>
    </div>

    <!-- Not a nested <form> (this whole section lives inside #issueForm, and
         forms can't nest) — searchStudents() below reloads the page via GET
         with the current setup filters preserved, same effect as a real form. -->
    <div style="padding:10px 16px;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;gap:10px;flex-wrap:wrap;background:#f7fafc;">
      <input type="text" id="studentSearch" placeholder="&#128269; Search by name or login…"
             value="<?= htmlspecialchars($studentSearch) ?>"
             onkeydown="if(event.key==='Enter'){event.preventDefault();searchStudents();}"
             style="flex:1;min-width:220px;max-width:360px;padding:7px 12px;border:1px solid #cbd5e0;border-radius:6px;font-size:.88rem;">
      <button type="button" onclick="searchStudents()" class="btn btn-secondary btn-sm">Search</button>
      <?php if ($studentSearch !== ''): ?>
        <a href="?<?= http_build_query(array_diff_key($qsStudents, ['sq' => 1])) ?>" class="btn btn-secondary btn-sm">Clear search</a>
      <?php endif; ?>
      <button type="button" onclick="selectAll(true)"  class="btn btn-secondary btn-sm">Select All (this page)</button>
      <button type="button" onclick="clearAllSelections()" class="btn btn-secondary btn-sm">Clear Selection</button>
    </div>

    <?php if ($studentSearch !== ''): ?>
      <div style="padding:8px 16px 0;font-size:.8rem;color:#718096;">Found <?= $studentTotal ?> student(s) matching “<?= htmlspecialchars($studentSearch) ?>”.</div>
    <?php endif; ?>

    <?php if (array_filter($nameCounts, fn($n) => $n > 1)): ?>
      <div class="alert alert-warning" style="margin:12px 16px 0;">
        &#9888; Two or more students below share the exact same name — rows flagged in red. Double-check the login shown under the name before selecting, so the certificate isn't issued to the wrong account.
      </div>
    <?php endif; ?>

    <?php if (!$students): ?>
      <div style="padding:30px;text-align:center;color:#718096;">
        <?= $certType==='merit' ? 'No students have a scored attempt for this exam yet.' : 'No students found.' ?>
      </div>
    <?php else: ?>
    <div id="studentList">
      <?php foreach ($students as $st):
        $fullName  = trim($st['FstName'] . ' ' . $st['LstName']);
        $uid       = (int)$st['UserInfoId'];
        $isDupName = ($nameCounts[$fullName] ?? 0) > 1;
      ?>
      <div class="cert-student-row student-item">
        <input type="checkbox" name="user_ids[]" value="<?= $uid ?>" id="uid<?= $uid ?>" onchange="toggleSelected(this.value, this.checked)"
               style="transform:scale(1.3);accent-color:#3182ce;flex-shrink:0;">
        <label for="uid<?= $uid ?>" style="cursor:pointer;flex:1;display:flex;align-items:center;justify-content:space-between;gap:12px;">
          <div>
            <div class="user-name">
              <?= htmlspecialchars($fullName) ?>
              <?php if ($isDupName): ?>
                <span title="Another student shares this exact name — check the login below before selecting."
                      style="display:inline-block;margin-left:6px;padding:1px 7px;border-radius:9px;font-size:.7rem;font-weight:700;background:#fee2e2;color:#b91c1c;">&#9888; #<?= $uid ?></span>
              <?php endif; ?>
            </div>
            <div class="user-meta"<?= $isDupName ? ' style="font-weight:700;color:#b91c1c;"' : '' ?>><?= htmlspecialchars($st['LoginName'] ?? '(no login)') ?></div>
          </div>
          <?php if ($certType === 'merit'): ?>
            <div style="text-align:right;white-space:nowrap;">
              <strong><?= (float)$st['Score'] ?> / <?= (float)$st['MarksOutOf'] ?></strong>
              (<?= $st['Percentage'] ?? '—' ?>%)
              <?php if ($st['Grade']): ?>
                <span class="cert-grade-chip"><?= htmlspecialchars($st['Grade']) ?></span>
              <?php else: ?>
                <span style="color:#9ca3af;font-size:.78rem;">below grade cutoff</span>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </label>
      </div>
      <?php endforeach; ?>
    </div>
    <?= paginator($studentTotal, $studentPage, PAGE_SIZE, $qsStudents, 'sp') ?>
    <div style="padding:14px 16px;border-top:1px solid #e2e8f0;background:#f7fafc;">
      <button type="submit" class="btn btn-success" style="font-weight:700;">
        &#127942; Issue Certificates
      </button>
    </div>
    <?php endif; ?>
  </div>
</form>

<script>
// Changing the Template select needs a full page reload (not just a form
// re-render) so the server can decide whether the chosen template is a Word
// template and, if so, render its custom-field inputs — mirrors the
// certType/subjectId/examId/instituteId onchange=submit fields in Step 1,
// which live in a separate GET form the Template select isn't part of.
function reloadWithTemplate(select) {
  const url = new URL(window.location.href);
  url.searchParams.set('templateId', select.value);
  window.location.href = url.toString();
}
// Student search reloads via GET (see comment above the search box) —
// preserves every Step-1 filter, resets to page 1 (sp intentionally omitted).
function searchStudents() {
  const url = new URL(window.location.href);
  const q = document.getElementById('studentSearch').value;
  if (q) url.searchParams.set('sq', q); else url.searchParams.delete('sq');
  url.searchParams.delete('sp');
  window.location.href = url.toString();
}

// Selections persist across search/pagination (sessionStorage), scoped to
// this exact filter context (certType/subject/exam/institute) so switching
// Step 1 filters naturally starts a fresh selection instead of carrying over
// picks that no longer make sense for a different exam/cert type.
var GC_KEY = 'gencert_selected_<?= htmlspecialchars(implode('_', [$certType, $subjectId, $examId, $instituteId]), ENT_QUOTES) ?>';

function loadSelected() {
  try { return JSON.parse(sessionStorage.getItem(GC_KEY) || '[]'); } catch (e) { return []; }
}
function saveSelected(ids) { sessionStorage.setItem(GC_KEY, JSON.stringify(ids)); }

function toggleSelected(uid, checked) {
  uid = String(uid);
  var ids = loadSelected();
  if (checked) {
    if (ids.indexOf(uid) === -1) ids.push(uid);
  } else {
    ids = ids.filter(function (id) { return id !== uid; });
  }
  saveSelected(ids);
  updateCount();
}
function updateCount() {
  var el = document.getElementById('selCount');
  if (el) el.textContent = loadSelected().length;
}
function selectAll(checked) {
  document.querySelectorAll('#studentList input[type=checkbox]').forEach(function (cb) {
    cb.checked = checked;
    toggleSelected(cb.value, checked);
  });
}
function clearAllSelections() {
  saveSelected([]);
  document.querySelectorAll('#studentList input[type=checkbox]').forEach(function (cb) { cb.checked = false; });
  updateCount();
}
function restoreSelections() {
  var ids = loadSelected();
  document.querySelectorAll('#studentList input[type=checkbox]').forEach(function (cb) {
    if (ids.indexOf(cb.value) !== -1) cb.checked = true;
  });
  updateCount();
}
document.addEventListener('DOMContentLoaded', restoreSelections);

var issueForm = document.getElementById('issueForm');
if (issueForm) {
  issueForm.addEventListener('submit', function () {
    var ids = loadSelected();
    var visible = new Set(Array.prototype.map.call(
      document.querySelectorAll('#studentList input[type=checkbox]'), function (cb) { return cb.value; }));
    var form = this;
    ids.forEach(function (id) {
      if (!visible.has(id)) {
        var inp = document.createElement('input');
        inp.type = 'hidden';
        inp.name = 'user_ids[]';
        inp.value = id;
        form.appendChild(inp);
      }
    });
    // A batch was just issued to whatever was selected — don't let a stale
    // selection carry over to the next visit to this same filter context.
    sessionStorage.removeItem(GC_KEY);
  });
}
</script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
