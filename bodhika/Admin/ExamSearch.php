<?php
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
require_once __DIR__ . '/../Lib/Institute.php';
require_once __DIR__ . '/../Lib/ExamType.php';

Auth::requireLogin('../index.php');
$userName = Auth::currentUser();

$grades     = Database::fetchAll("SELECT GradeInfoId, GradeName   FROM gradeinfo   ORDER BY GradeName");
$subjects   = Database::fetchAll("SELECT SubjectInfoId, SubjectName FROM subjectinfo ORDER BY SubjectName");
$institutes = Institute::listAll();   // for filter dropdown
$examTypes  = ExamType::allValues();  // for Type filter dropdown; [] if migration_v55 not run
$catColAvailable = Database::hasColumn('examinfo', 'ExamCategory');
/* Country filter (migration_v64) — examinfo.ExamCountry, once an admin sets
   it explicitly, always wins over the Type-derived guess; see
   ExamType::resolveCountry()'s docblock for the full precedence rule.
   countryFilterOptions() only offers a country that would actually return
   something, from either the explicit column or the Type fallback. */
$countryColAvailable = Database::hasColumn('examinfo', 'ExamCountry');
$countryOptions       = ExamType::countryFilterOptions();

// Quick-toggle IsActive via GET ?toggle=<examId>
$toggleMsg = '';
if (isset($_GET['toggle']) && ($togId = (int)$_GET['toggle']) > 0) {
    try {
        $cur = Database::fetchOne("SELECT IsActive FROM examinfo WHERE ExamInfoId = ? LIMIT 1", [$togId]);
        $newVal = (($cur['IsActive'] ?? 'Y') === 'Y') ? 'N' : 'Y';
        Database::execute("UPDATE examinfo SET IsActive = ? WHERE ExamInfoId = ?", [$newVal, $togId]);
        $toggleMsg = 'Exam ' . ($newVal === 'Y' ? 'activated' : 'deactivated') . '.';
    } catch (Exception $e) { $toggleMsg = 'Could not toggle status — run migration_v27.sql first.'; }
    // Redirect to avoid re-toggle on refresh — preserve current filters/page
    $redirectParams = $_GET;
    unset($redirectParams['toggle']);
    $redirectParams['togMsg'] = $toggleMsg;
    header('Location: ExamSearch.php?' . http_build_query($redirectParams));
    exit;
}
$toggleMsg = isset($_GET['togMsg']) ? htmlspecialchars($_GET['togMsg']) : '';

// Search filters — plain GET params so pagination links can carry them
// forward unchanged (a form using method="get" replaces the query string
// with just its own fields, so submitting a new search naturally resets
// back to page 1).
$where  = [];
$params = [];
$grade    = (int)($_GET['txtGrade']     ?? 0);
$subject  = (int)($_GET['txtSubject']   ?? 0);
$scope    = trim($_GET['txtScope']      ?? '');
$instId   = (int)($_GET['txtInstitute'] ?? 0);
$status   = trim($_GET['txtStatus']     ?? '');
$category = trim($_GET['txtCategory']   ?? '');
$country  = trim($_GET['txtCountry']    ?? '');
if ($grade > 0)   { $where[] = 'e.GradeInfoId = ?';   $params[] = $grade; }
if ($subject > 0) { $where[] = 'e.SubjectInfoId = ?'; $params[] = $subject; }
if (in_array($scope, ['All','Institute'])) {
    $where[] = 'e.ExamScope = ?'; $params[] = $scope;
}
if ($instId > 0)  { $where[] = 'e.ExamInstituteId = ?'; $params[] = $instId; }
if (in_array($status, ['Y','N'])) {
    $where[] = "COALESCE(e.IsActive,'Y') = ?"; $params[] = $status;
}
if ($catColAvailable && $category !== '') {
    $where[] = 'e.ExamCategory = ?'; $params[] = $category;
}
if ($country !== '') {
    // Explicit examinfo.ExamCountry wins when set; falls back to matching
    // any Type known to belong to this country only for rows where
    // ExamCountry is blank/unset — same precedence as
    // ExamType::resolveCountry() (row-aware) uses for display.
    $countryFallbackTypes = ExamType::typesForCountry($country);
    if ($countryColAvailable && $countryFallbackTypes) {
        $ph = implode(',', array_fill(0, count($countryFallbackTypes), '?'));
        $where[] = "(e.ExamCountry = ? OR ((e.ExamCountry IS NULL OR TRIM(e.ExamCountry) = '') AND e.ExamCategory IN ($ph)))";
        $params[] = $country;
        array_push($params, ...$countryFallbackTypes);
    } elseif ($countryColAvailable) {
        $where[] = 'e.ExamCountry = ?'; $params[] = $country;
    } elseif ($countryFallbackTypes) {
        $ph = implode(',', array_fill(0, count($countryFallbackTypes), '?'));
        $where[] = "e.ExamCategory IN ($ph)"; array_push($params, ...$countryFallbackTypes);
    } else {
        $where[] = '1=0'; // selected country matches nothing, explicit or Type-derived — no results, not an error
    }
}

// Pagination: 10 exams per page by default.
const EXAMS_PER_PAGE = 10;
$requestedPage = max(1, (int)($_GET['page'] ?? 1));

// Join with institutes for display; graceful fallback if migration_v24 not run
try {
    $baseSql = " FROM examinfo e
            LEFT JOIN institutes i ON i.InstituteId = e.ExamInstituteId"
             . ($where ? ' WHERE ' . implode(' AND ', $where) : '');

    $totalExams = (int)(Database::fetchOne("SELECT COUNT(*) AS cnt" . $baseSql, $params)['cnt'] ?? 0);
    $totalPages = max(1, (int)ceil($totalExams / EXAMS_PER_PAGE));
    $page       = min($requestedPage, $totalPages);
    $offset     = ($page - 1) * EXAMS_PER_PAGE;

    $sql = "SELECT e.*, i.InstituteName" . $baseSql
         . ' ORDER BY e.ExamInfoId DESC'
         . ' LIMIT ' . EXAMS_PER_PAGE . ' OFFSET ' . $offset;
    $exams = Database::fetchAll($sql, $params);
    $scopeColAvailable = true;
} catch (Exception $ex) {
    // migration_v24 not yet run — fall back to simple query
    $baseSql = ' FROM examinfo' . ($where ? ' WHERE ' . implode(' AND ', $where) : '');

    $totalExams = (int)(Database::fetchOne("SELECT COUNT(*) AS cnt" . $baseSql, $params)['cnt'] ?? 0);
    $totalPages = max(1, (int)ceil($totalExams / EXAMS_PER_PAGE));
    $page       = min($requestedPage, $totalPages);
    $offset     = ($page - 1) * EXAMS_PER_PAGE;

    $sql   = 'SELECT *' . $baseSql
           . ' ORDER BY ExamInfoId DESC'
           . ' LIMIT ' . EXAMS_PER_PAGE . ' OFFSET ' . $offset;
    $exams = Database::fetchAll($sql, $params);
    $scopeColAvailable = false;
}

// Builds a pagination link that preserves every current filter/query param
// except page, which is overridden to the target page.
function examListPageUrl(int $targetPage): string
{
    $qs = $_GET;
    $qs['page'] = $targetPage;
    return 'ExamSearch.php?' . http_build_query($qs);
}

$gradeMap   = array_column($grades,   'GradeName',   'GradeInfoId');
$subjectMap = array_column($subjects, 'SubjectName', 'SubjectInfoId');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Search Exam</title>
  <link href="style.css" rel="stylesheet" type="text/css">
  <style>
    th { background:#4a6fa5; color:#fff; padding:6px 8px; font-size:12px; }
    td { padding:4px 8px; font-size:12px; }
    tr.odd  { background:#f9f9f9; }
    tr.even { background:#eef2f8; }
    .scope-all  { background:#c6efce; color:#276749; padding:2px 8px; border-radius:10px; font-size:.75rem; font-weight:600; }
    .scope-inst { background:#dbeafe; color:#1e40af; padding:2px 8px; border-radius:10px; font-size:.75rem; font-weight:600; }
  </style>
</head>
<body>
<?php include_once('Includes/Top.php'); ?>

<table border="0" cellpadding="4" cellspacing="1" width="1024" align="center">
  <tr><td>
    <form name="frmExam" method="get" action="ExamSearch.php">
      <table border="0" cellpadding="4" cellspacing="1" width="100%" bgcolor="#EEEEEE">
        <tr><td class="tblhdr" colspan="8">Exam Search</td></tr>
        <tr>
          <td class="tbldt">Grade</td>
          <td class="tbldt">
            <select name="txtGrade">
              <option value="0">-- All --</option>
              <?php foreach ($grades as $g): ?>
                <option value="<?php echo (int)$g['GradeInfoId']; ?>"
                  <?php echo ($grade === (int)$g['GradeInfoId']) ? 'selected' : ''; ?>>
                  <?php echo htmlspecialchars($g['GradeName']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </td>
          <td class="tbldt">Subject</td>
          <td class="tbldt">
            <select name="txtSubject">
              <option value="0">-- All --</option>
              <?php foreach ($subjects as $s): ?>
                <option value="<?php echo (int)$s['SubjectInfoId']; ?>"
                  <?php echo ($subject === (int)$s['SubjectInfoId']) ? 'selected' : ''; ?>>
                  <?php echo htmlspecialchars($s['SubjectName']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </td>
          <?php if ($catColAvailable): ?>
          <td class="tbldt">Type</td>
          <td class="tbldt">
            <select name="txtCategory">
              <option value="">-- All --</option>
              <?php foreach ($examTypes as $t): ?>
                <option value="<?php echo htmlspecialchars($t); ?>"
                  <?php echo ($category === $t) ? 'selected' : ''; ?>>
                  <?php echo htmlspecialchars($t); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </td>
          <?php endif; ?>
          <?php if (!empty($countryOptions)): ?>
          <td class="tbldt">Country</td>
          <td class="tbldt">
            <select name="txtCountry">
              <option value="">-- All --</option>
              <?php foreach ($countryOptions as $c): ?>
                <option value="<?php echo htmlspecialchars($c); ?>"
                  <?php echo ($country === $c) ? 'selected' : ''; ?>>
                  <?php echo htmlspecialchars($c); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </td>
          <?php endif; ?>
          <?php if ($scopeColAvailable): ?>
          <td class="tbldt">Scope</td>
          <td class="tbldt">
            <select name="txtScope">
              <option value="">-- All --</option>
              <option value="All"       <?php echo ($scope==='All')       ?'selected':''; ?>>All Students</option>
              <option value="Institute" <?php echo ($scope==='Institute') ?'selected':''; ?>>Institute Only</option>
            </select>
          </td>
          <td class="tbldt">Institute</td>
          <td class="tbldt">
            <select name="txtInstitute">
              <option value="0">-- All --</option>
              <?php foreach ($institutes as $inst): ?>
                <option value="<?php echo (int)$inst['InstituteId']; ?>"
                  <?php echo ($instId===(int)$inst['InstituteId'])?'selected':''; ?>>
                  <?php echo htmlspecialchars($inst['InstituteName']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </td>
          <?php endif; ?>
        </tr>
        <tr>
          <td class="tbldt">Status</td>
          <td class="tbldt">
            <select name="txtStatus">
              <option value="">-- All --</option>
              <option value="Y" <?php echo ($status==='Y')?'selected':''; ?>>Active only</option>
              <option value="N" <?php echo ($status==='N')?'selected':''; ?>>Inactive only</option>
            </select>
          </td>
          <td colspan="6"></td>
        </tr>
        <tr>
          <td colspan="8" align="center">
            <input type="submit" name="Search" value="Search"
                   style="background-image:url('../Images/btnsmall2.gif');width:100px;height:26px;border:0" class="btnbg">
            <input type="button" value="Back" onclick="history.go(-1)"
                   style="background-image:url('../Images/btnsmall2.gif');width:100px;height:26px;border:0" class="btnbg">
          </td>
        </tr>
      </table>
    </form>
  </td></tr>

  <?php if ($toggleMsg !== ''): ?>
  <tr><td>
    <div style="padding:8px 12px;background:#d1fae5;border:1px solid #6ee7b7;border-radius:6px;color:#065f46;font-weight:600;margin-bottom:6px;">
      ✅ <?php echo $toggleMsg; ?>
    </div>
  </td></tr>
  <?php endif; ?>

  <?php if (!empty($exams)): ?>
  <tr><td>
    <table border="0" cellpadding="0" cellspacing="1" width="100%" bgcolor="#EEEEEE">
      <tr><td class="tblhdr" colspan="9">
        Examination List
        <?php if ($totalExams > 0): ?>
          <span style="font-weight:normal;font-size:.8rem;">
            (showing <?php echo $offset + 1; ?>–<?php echo min($offset + EXAMS_PER_PAGE, $totalExams); ?> of <?php echo $totalExams; ?>)
          </span>
        <?php endif; ?>
      </td></tr>
      <tr class="HeaderStyle">
        <th>Exam Name</th><th>Grade</th><th>Subject</th>
        <th>Questions</th><th>Min Passing</th><th>Time Allotted</th>
        <?php if ($scopeColAvailable): ?><th>Scope</th><?php endif; ?>
        <th>Status</th>
        <th>Actions</th>
      </tr>
      <?php foreach ($exams as $i => $exam): ?>
      <tr class="<?php echo ($i % 2 === 0) ? 'odd' : 'even'; ?>">
        <td>
          <?php echo htmlspecialchars($exam['ExamName']); ?>
          <?php $ff = $exam['ExamFreeFor'] ?? 'None';
                if ($ff === 'All'): ?>
            <span style="display:inline-block;margin-left:5px;padding:1px 7px;background:#d1fae5;color:#065f46;border-radius:10px;font-size:.7rem;font-weight:700;vertical-align:middle;">FREE ALL</span>
          <?php elseif ($ff === 'Institute'): ?>
            <span style="display:inline-block;margin-left:5px;padding:1px 7px;background:#dbeafe;color:#1e40af;border-radius:10px;font-size:.7rem;font-weight:700;vertical-align:middle;">FREE 🏫</span>
          <?php endif; ?>
          <?php if ($catColAvailable && !empty($exam['ExamCategory'])):
            $rowCountry = ExamType::resolveCountry($exam);
            $badgeTitle = $exam['ExamCategory'] . ($rowCountry !== '' ? ' — ' . $rowCountry : '');
            $badgeFlag  = ExamType::resolveFlagIconHtml($exam); // '' for India — see Lib/ExamType.php::SUPPRESS_FLAG_FOR
          ?>
            <span title="<?php echo htmlspecialchars($badgeTitle); ?>" style="margin-left:5px;">
              <?php echo $badgeFlag !== '' ? $badgeFlag . ' ' : ''; ?><?php echo htmlspecialchars($exam['ExamCategory']); ?>
            </span>
          <?php endif; ?>
        </td>
        <td><?php echo htmlspecialchars($gradeMap[$exam['GradeInfoId']] ?? ''); ?></td>
        <td><?php echo htmlspecialchars($subjectMap[$exam['SubjectInfoId']] ?? ''); ?></td>
        <td align="center"><?php echo (int)$exam['NumOfQuestions']; ?></td>
        <td align="center"><?php echo min(100, max(0, (int)$exam['MinPassing'])); ?>%</td>
        <td align="center"><?php echo htmlspecialchars($exam['TimeAlloted'] ?? ''); ?></td>
        <?php if ($scopeColAvailable):
          $scope    = $exam['ExamScope'] ?? 'All';
          $instName = $exam['InstituteName'] ?? null; ?>
        <td>
          <?php if ($scope === 'Institute'): ?>
            <span class="scope-inst">&#127982; <?php echo htmlspecialchars($instName ?: 'Institute'); ?></span>
          <?php else: ?>
            <span class="scope-all">&#127760; All</span>
          <?php endif; ?>
        </td>
        <?php endif; ?>
        <?php $isActive = ($exam['IsActive'] ?? 'Y'); ?>
        <td align="center">
          <?php if ($isActive === 'Y'): ?>
            <span style="color:#059669;font-weight:700;">✅ Active</span><br>
            <a href="ExamSearch.php?toggle=<?php echo (int)$exam['ExamInfoId']; ?>"
               onclick="return confirm('Deactivate this exam? Students will no longer see it.');"
               style="font-size:.75rem;color:#dc2626;">Deactivate</a>
          <?php else: ?>
            <span style="color:#dc2626;font-weight:700;">🚫 Inactive</span><br>
            <a href="ExamSearch.php?toggle=<?php echo (int)$exam['ExamInfoId']; ?>"
               onclick="return confirm('Activate this exam? It will become visible to students.');"
               style="font-size:.75rem;color:#059669;">Activate</a>
          <?php endif; ?>
        </td>
        <td align="center">
          <a href="../exam/write.php?InfoId=<?php echo (int)$exam['ExamInfoId']; ?>" class="bodynav">Write Exam</a>
          | <a href="../exam/manage.php?InfoId=<?php echo (int)$exam['ExamInfoId']; ?>" class="bodynav">Edit</a>
          | <a href="ExamHistoryList.php?InfoId=<?php echo (int)$exam['ExamInfoId']; ?>" class="bodynav">History</a>
          | <a href="BulkUploadQuestions.php?examId=<?php echo (int)$exam['ExamInfoId']; ?>" class="bodynav">Bulk Upload Qs</a>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if ($totalPages > 1): ?>
      <tr>
        <td colspan="9" align="center" style="padding:8px;background:#fff;">
          <?php if ($page > 1): ?>
            <a href="<?php echo htmlspecialchars(examListPageUrl(1)); ?>" class="bodynav">&laquo; First</a>
            &nbsp;
            <a href="<?php echo htmlspecialchars(examListPageUrl($page - 1)); ?>" class="bodynav">&lsaquo; Prev</a>
            &nbsp;
          <?php endif; ?>
          <?php
            // Windowed page numbers: first, last, and a few around current.
            $windowStart = max(1, $page - 2);
            $windowEnd   = min($totalPages, $page + 2);
            if ($windowStart > 1) { echo '<a href="' . htmlspecialchars(examListPageUrl(1)) . '" class="bodynav">1</a> '; if ($windowStart > 2) echo '... '; }
            for ($p = $windowStart; $p <= $windowEnd; $p++) {
                if ($p === $page) {
                    echo '<b style="padding:0 4px;">' . $p . '</b> ';
                } else {
                    echo '<a href="' . htmlspecialchars(examListPageUrl($p)) . '" class="bodynav">' . $p . '</a> ';
                }
            }
            if ($windowEnd < $totalPages) { if ($windowEnd < $totalPages - 1) echo '... '; echo '<a href="' . htmlspecialchars(examListPageUrl($totalPages)) . '" class="bodynav">' . $totalPages . '</a>'; }
          ?>
          <?php if ($page < $totalPages): ?>
            &nbsp;
            <a href="<?php echo htmlspecialchars(examListPageUrl($page + 1)); ?>" class="bodynav">Next &rsaquo;</a>
            &nbsp;
            <a href="<?php echo htmlspecialchars(examListPageUrl($totalPages)); ?>" class="bodynav">Last &raquo;</a>
          <?php endif; ?>
          <div style="font-size:.75rem;color:#666;margin-top:4px;">Page <?php echo $page; ?> of <?php echo $totalPages; ?></div>
        </td>
      </tr>
      <?php endif; ?>
      <tr>
        <td colspan="9" align="right" style="padding:6px;">
          <input type="button" value="Add New" onclick="location.href='../exam/manage.php?InfoId=0'"
                 style="background-image:url('../Images/btnsmall2.gif');width:100px;height:26px;border:0" class="btnbg">
        </td>
      </tr>
    </table>
  </td></tr>
  <?php else: ?>
  <tr><td align="center" style="padding:20px;color:#666;">No exams found.</td></tr>
  <?php endif; ?>
</table>

<?php include_once('Includes/Bottom.php'); ?>
</body>
</html>
