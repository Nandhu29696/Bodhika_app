<?php
/**
 * Admin/InstituteAdminExams.php
 * Institute-Admin: exams that belong to their own institute
 * (examinfo.ExamInstituteId = Auth::currentInstituteId()). Lists them with
 * quick links to manage/create (exam/manage.php), add questions
 * (exam/questions.php), assign to students/groups (exam/assign.php), and
 * export a printable paper / answer key (exam/export-pdf.php).
 *
 * A full Admin may also open this page for support, via ?instId=.
 * Question-bank exams never belong to an institute (see migration_v65.sql —
 * ExamInstituteId is only ever set on real, assignable exams built via
 * exam/manage.php), so none are excluded here on purpose.
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';

Auth::requireLogin('../auth/login.php');
if (!Auth::isInstituteAdmin() && !Auth::isAdmin()) {
    header('Location: ../exam/search.php');
    exit;
}

$isFullAdmin = Auth::isAdmin() && !Auth::isInstituteAdmin();
$instId = $isFullAdmin
    ? (filter_input(INPUT_GET, 'instId', FILTER_VALIDATE_INT) ?: 0)
    : (Auth::currentInstituteId() ?? 0);

if ($instId <= 0) {
    header('Location: InstituteAdminHome.php');
    exit;
}

$institute = Database::fetchOne(
    "SELECT InstituteId, InstituteName, InstituteType, State, CityVillage
       FROM institutes WHERE InstituteId = ? LIMIT 1", [$instId]);

if (!$institute) {
    header('Location: InstituteAdminHome.php');
    exit;
}

function safeStr(?string $v): string { return trim($v ?? ''); }
$search = safeStr($_GET['q'] ?? '');

$where  = ["e.ExamInstituteId = ?"];
$params = [$instId];
try {
    if (Database::hasColumn('examinfo', 'IsDeleted')) {
        $where[] = "COALESCE(e.IsDeleted,'N') = 'N'";
    }
} catch (Exception $e) {}
if ($search !== '') {
    $where[]  = "e.ExamName LIKE ?";
    $params[] = "%{$search}%";
}
$whereSQL = implode(' AND ', $where);

$exams = [];
try {
    $exams = Database::fetchAll(
        "SELECT e.ExamInfoId, e.ExamName, e.NumOfQuestions, e.IsActive, e.TimeAlloted,
                e.MinPassing, g.GradeName, s.SubjectName,
                COALESCE(e.IsMultiSubject,'N')  AS IsMultiSubject,
                (SELECT COUNT(*) FROM exam_assignments ea WHERE ea.ExamInfoId = e.ExamInfoId) AS AssignedCount,
                (SELECT COUNT(*) FROM exam_questions eq WHERE eq.ExamInfoId = e.ExamInfoId AND COALESCE(eq.IsActive,'Y')='Y') AS QuestionCount
           FROM examinfo e
      LEFT JOIN gradeinfo   g ON g.GradeInfoId   = e.GradeInfoId
      LEFT JOIN subjectinfo s ON s.SubjectInfoId = e.SubjectInfoId
          WHERE {$whereSQL}
          ORDER BY e.ExamInfoId DESC", $params);
} catch (Exception $e) {
    // exam_assignments / exam_questions may not exist on very old schemas —
    // fall back to a plain exam list without the counts.
    $exams = Database::fetchAll(
        "SELECT e.ExamInfoId, e.ExamName, e.NumOfQuestions, e.IsActive, e.TimeAlloted,
                e.MinPassing, g.GradeName, s.SubjectName,
                'N' AS IsMultiSubject, 0 AS AssignedCount, 0 AS QuestionCount
           FROM examinfo e
      LEFT JOIN gradeinfo   g ON g.GradeInfoId   = e.GradeInfoId
      LEFT JOIN subjectinfo s ON s.SubjectInfoId = e.SubjectInfoId
          WHERE {$whereSQL}
          ORDER BY e.ExamInfoId DESC", $params);
}

$createUrl = '../exam/manage.php';
$qsInst    = $isFullAdmin ? ['instId' => $instId] : [];

$pageTitle = 'My Exams — ' . $institute['InstituteName'];
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.ie-wrap{max-width:1100px;margin:0 auto;padding:0 16px;}
.ie-title{font-size:1.3rem;font-weight:700;color:var(--clr-primary);margin:0 0 4px;display:flex;align-items:center;gap:8px;}
.ie-back{font-size:12px;color:#64748b;text-decoration:none;display:inline-block;margin-bottom:14px;}
.ie-back:hover{color:var(--clr-primary);}
.ie-subhead{font-size:12px;color:#64748b;margin-bottom:18px;}
.ie-toolbar{display:flex;justify-content:space-between;align-items:flex-end;flex-wrap:wrap;gap:14px;margin-bottom:14px;}
.ie-search{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:14px 16px;display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap;}
.ie-search input[type=text]{height:34px;border:1px solid #cbd5e1;border-radius:5px;font-size:13px;padding:0 10px;width:220px;}
.ie-search button{background:var(--clr-gold);color:#fff;border:none;padding:0 20px;height:34px;border-radius:5px;cursor:pointer;font-size:13px;font-weight:600;}
.ie-table{width:100%;border-collapse:collapse;margin-top:4px;font-size:13px;border-radius:8px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.08);}
.ie-table th{background:var(--clr-primary);color:#fff;padding:9px 12px;font-size:12px;text-align:left;white-space:nowrap;}
.ie-table td{padding:8px 12px;border-bottom:1px solid #f1f5f9;color:#1e293b;}
.ie-table tr.odd td{background:#fff;} .ie-table tr.even td{background:#f8fafc;} .ie-table tr:hover td{background:#eff6ff;}
.badge{display:inline-block;padding:2px 9px;border-radius:12px;font-size:11px;font-weight:700;}
.badge-y{background:#dcfce7;color:#15803d;} .badge-n{background:#fee2e2;color:#b91c1c;}
.badge-as{background:#e0e7ff;color:#3730a3;}
.result-count{font-size:12px;color:#64748b;margin-bottom:8px;}
.btn-xs{display:inline-block;padding:3px 9px;border-radius:5px;font-size:11.5px;font-weight:600;text-decoration:none;margin-right:3px;margin-bottom:3px;}
.btn-xs-primary{background:var(--clr-primary);color:#fff;}
.btn-xs-sec{background:#e2e8f0;color:#334155;}
.btn-xs-amber{background:#f59e0b;color:#fff;}
</style>

<div class="ie-wrap">
  <a href="InstituteAdminHome.php<?= $isFullAdmin ? '?instId='.$instId : '' ?>" class="ie-back">&larr; Back to Dashboard</a>
  <div class="ie-title">&#128220; <?= htmlspecialchars($institute['InstituteName']) ?> — My Exams</div>
  <div class="ie-subhead"><?= htmlspecialchars($institute['CityVillage'] ?: '—') ?>, <?= htmlspecialchars($institute['State'] ?: '—') ?></div>

  <div class="ie-toolbar">
    <form method="get" class="ie-search">
      <?php if ($isFullAdmin): ?><input type="hidden" name="instId" value="<?= (int)$instId ?>"><?php endif; ?>
      <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search by exam name…">
      <button type="submit">Search</button>
      <?php if ($search !== ''): ?>
        <a href="?<?= $isFullAdmin ? 'instId='.$instId : '' ?>" style="font-size:12px;color:#64748b;align-self:center;">Reset</a>
      <?php endif; ?>
    </form>
    <a href="<?= htmlspecialchars($createUrl) ?>" class="btn btn-success" style="height:34px;display:flex;align-items:center;padding:0 18px;">
      &#10010; Create Exam
    </a>
  </div>

  <div class="result-count"><?= number_format(count($exams)) ?> exam<?= count($exams)===1?'':'s' ?> found</div>

  <?php if ($exams): ?>
  <table class="ie-table">
    <thead><tr>
      <th>#</th><th>Exam Name</th><th>Grade / Subject</th><th>Questions</th>
      <th>Time</th><th>Pass %</th><th>Assigned</th><th>Status</th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($exams as $i => $e):
        $rowClass = $i % 2 === 0 ? 'odd' : 'even';
        $active   = ($e['IsActive'] ?? 'Y') === 'Y';
        $subjLabel = ($e['IsMultiSubject'] ?? 'N') === 'Y' ? 'Multi-subject' : ($e['SubjectName'] ?? '—');
    ?>
      <tr class="<?= $rowClass ?>">
        <td><?= $i + 1 ?></td>
        <td><strong><?= htmlspecialchars($e['ExamName']) ?></strong></td>
        <td><?= htmlspecialchars($e['GradeName'] ?? '—') ?> / <?= htmlspecialchars($subjLabel) ?></td>
        <td><?= (int)$e['QuestionCount'] ?> / <?= (int)$e['NumOfQuestions'] ?></td>
        <td><?= (int)$e['TimeAlloted'] ?> min</td>
        <td><?= (int)$e['MinPassing'] ?>%</td>
        <td><?php if ((int)$e['AssignedCount'] > 0): ?><span class="badge badge-as"><?= (int)$e['AssignedCount'] ?></span><?php else: ?>—<?php endif; ?></td>
        <td><span class="badge <?= $active?'badge-y':'badge-n' ?>"><?= $active?'Active':'Inactive' ?></span></td>
        <td style="white-space:nowrap;">
          <a href="../exam/manage.php?InfoId=<?= (int)$e['ExamInfoId'] ?>" class="btn-xs btn-xs-sec">Edit</a>
          <a href="../exam/questions.php?examId=<?= (int)$e['ExamInfoId'] ?>" class="btn-xs btn-xs-sec">Questions</a>
          <a href="../exam/assign.php?examId=<?= (int)$e['ExamInfoId'] ?>" class="btn-xs btn-xs-primary">Assign</a>
          <a href="../exam/export-pdf.php?examId=<?= (int)$e['ExamInfoId'] ?>" class="btn-xs btn-xs-amber">PDF</a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
    <p style="color:#888;font-size:13px;">
      No exams found<?= $search!==''?' matching “'.htmlspecialchars($search).'”':' for this institute yet' ?>.
      <?= $search==='' ? '<a href="'.htmlspecialchars($createUrl).'">Create your first exam</a>.' : '' ?>
    </p>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
