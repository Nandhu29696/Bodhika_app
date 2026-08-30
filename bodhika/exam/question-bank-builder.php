<?php
/**
 * exam/question-bank-builder.php — Build an exam FROM question banks.
 *
 * Different from question-bank.php (which lets an admin hand-pick individual
 * questions from other exams sharing the target's own subject): this page
 * draws from every exam flagged IsQuestionBank='Y' (migration_v65) — the
 * pools that can hold hundreds/thousands of questions — and lets an admin
 * compose a new exam by choosing, per row: an optional Subject, an optional
 * Chapter (within that subject), and how many questions to pull. Multiple
 * rows can reuse the same Subject with a different Chapter (e.g. 10 from
 * "Mechanics" + 10 from "Thermodynamics", both Physics) — exactly the
 * NEET/JEE-style composition this app's chapter-wise banks
 * (neet_physics_chapterwise*.sql etc.) exist for.
 *
 * Selection is random (LIMIT ... ORDER BY RAND()) and never duplicates a
 * question already linked to the target exam. It also *prefers* — without
 * ever hard-blocking on — questions not yet used in any other real (non-
 * bank) exam, so repeatedly building exams from the same bank doesn't just
 * keep handing back the same well-worn set: see pickForRow()'s ORDER BY.
 *
 * Only ever LINKS existing questions (INSERT INTO exam_questions), never
 * copies a row — same "no data duplicated" convention as question-bank.php
 * and dp900_exam_ycis_institute.sql.
 *
 * Access: full Admin, or an Institute-Admin building/extending an exam that
 * belongs to their own institute (Auth::currentInstituteId()) — a bank
 * itself is always global/unscoped, only the *target* exam is checked.
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');

$isFullAdmin = Auth::isAdmin();
$isInstAdmin = Auth::isInstituteAdmin();
if (!$isFullAdmin && !$isInstAdmin) { header('Location: search.php'); exit; }

$examId = filter_input(INPUT_GET,  'examId', FILTER_VALIDATE_INT)
       ?: filter_input(INPUT_POST, 'examId', FILTER_VALIDATE_INT);
if (!$examId) { header('Location: search.php'); exit; }

/* ── Load target exam ─────────────────────────────────────────────────── */
$exam = Database::fetchOne(
    "SELECT e.*, g.GradeName, s.SubjectName
       FROM examinfo e
  LEFT JOIN gradeinfo   g ON g.GradeInfoId   = e.GradeInfoId
  LEFT JOIN subjectinfo s ON s.SubjectInfoId = e.SubjectInfoId
      WHERE e.ExamInfoId = ? LIMIT 1", [$examId]);
if (!$exam) { header('Location: search.php'); exit; }

/* Institute-Admin may only build into an exam that belongs to their own
   institute — never a global exam or another institute's. Full Admin has
   no such restriction. */
if ($isInstAdmin && !$isFullAdmin) {
    $myInstId = Auth::currentInstituteId();
    if (!$myInstId || (int)($exam['ExamInstituteId'] ?? 0) !== (int)$myInstId) {
        header('Location: search.php');
        exit;
    }
}

/* A bank exam is a pool to build FROM, not something to build questions
   INTO — building "a bank into a bank" would just be relinking a pool to
   itself. Send them to pick a real exam instead. */
if (($exam['IsQuestionBank'] ?? 'N') === 'Y') {
    header('Location: questions.php?examId=' . $examId . '&bankBuildErr=1');
    exit;
}

$hasQuestionBankCol = Database::hasColumn('examinfo', 'IsQuestionBank');
$hasChapterCol      = Database::hasColumn('questions', 'ChapterInfoId');

if (!$hasQuestionBankCol) {
    $pageTitle = 'Build from Question Bank';
    include __DIR__ . '/../includes/header.php';
    ?>
    <div class="card" style="max-width:560px;margin:40px auto;">
      <div class="card-header">&#9888; Not Available Yet</div>
      <div style="padding:24px;">
        <p>This feature needs <code>migrations/migration_v65.sql</code> to be run against this
           database first (adds <code>examinfo.IsQuestionBank</code>).</p>
        <a class="btn btn-primary" href="questions.php?examId=<?php echo $examId; ?>">&#8592; Back</a>
      </div>
    </div>
    <?php
    include __DIR__ . '/../includes/footer.php';
    exit;
}

/* ── Subjects / chapters for the row pickers ──────────────────────────── */
$subjects = Database::fetchAll("SELECT SubjectInfoId, SubjectName FROM subjectinfo WHERE Active = 'Y' ORDER BY SubjectName");
$chapters = [];
if ($hasChapterCol) {
    try {
        $chapters = Database::fetchAll(
            "SELECT ChapterInfoId, SubjectInfoId, ChapterName
               FROM chapterinfo WHERE Active = 'Y'
              ORDER BY SubjectInfoId, ChapterOrder, ChapterName");
    } catch (Exception $e) { $chapters = []; /* migration_v49 not yet run */ }
}
$chaptersBySubject = [];
foreach ($chapters as $c) { $chaptersBySubject[(int)$c['SubjectInfoId']][] = $c; }

/* ── How many bank questions exist per subject (for the "available" hint
   shown in the UI) — counts distinct questions linked to ANY IsQuestionBank
   exam, regardless of chapter. ─────────────────────────────────────────── */
$bankCountsBySubject = [];
try {
    $rows = Database::fetchAll(
        "SELECT q.SubjectInfoId, COUNT(DISTINCT q.QuestionId) AS c
           FROM questions q
           JOIN exam_questions eq ON eq.QuestionId = q.QuestionId
           JOIN examinfo be       ON be.ExamInfoId = eq.ExamInfoId AND be.IsQuestionBank = 'Y'
          WHERE COALESCE(q.IsDeleted,'N') = 'N' AND COALESCE(eq.IsActive,'Y') = 'Y'
          GROUP BY q.SubjectInfoId");
    foreach ($rows as $r) { $bankCountsBySubject[(int)$r['SubjectInfoId']] = (int)$r['c']; }
} catch (Exception $e) { $bankCountsBySubject = []; }
$totalBankQuestions = array_sum($bankCountsBySubject);

/**
 * Randomly select up to $count fresh QuestionIds from the question banks,
 * optionally scoped to a Subject and/or Chapter, excluding anything already
 * linked to $examId. Prefers (but never requires) questions not yet reused
 * in any other non-bank exam — see the ORDER BY.
 *
 * @return array{picked:int[], available:int} available = pool size BEFORE
 *         the LIMIT, so the caller can report a shortfall honestly.
 */
function qbb_pickForRow(int $examId, int $subjectId, int $chapterId, int $count, bool $hasChapterCol): array
{
    if ($count <= 0) return ['picked' => [], 'available' => 0];

    $where  = ["COALESCE(q.IsDeleted,'N') = 'N'", "COALESCE(eq.IsActive,'Y') = 'Y'", "be.IsQuestionBank = 'Y'"];
    $params = [];
    if ($subjectId > 0) { $where[] = 'q.SubjectInfoId = ?'; $params[] = $subjectId; }
    if ($hasChapterCol && $chapterId > 0) { $where[] = 'q.ChapterInfoId = ?'; $params[] = $chapterId; }
    $where[] = 'NOT EXISTS (SELECT 1 FROM exam_questions tex WHERE tex.ExamInfoId = ? AND tex.QuestionId = q.QuestionId)';
    $params[] = $examId;

    $sql = "SELECT DISTINCT q.QuestionId,
                   COALESCE(u.UsageCount, 0) AS UsageCount
              FROM questions q
              JOIN exam_questions eq ON eq.QuestionId = q.QuestionId
              JOIN examinfo be       ON be.ExamInfoId = eq.ExamInfoId
         LEFT JOIN (
                SELECT eq2.QuestionId, COUNT(*) AS UsageCount
                  FROM exam_questions eq2
                  JOIN examinfo re ON re.ExamInfoId = eq2.ExamInfoId
                 WHERE COALESCE(re.IsQuestionBank,'N') = 'N'
                 GROUP BY eq2.QuestionId
              ) u ON u.QuestionId = q.QuestionId
             WHERE " . implode(' AND ', $where) . "
          ORDER BY UsageCount ASC, RAND()";

    $pool = Database::fetchAll($sql, $params);
    $available = count($pool);
    $picked = array_map(fn($r) => (int)$r['QuestionId'], array_slice($pool, 0, $count));
    return ['picked' => $picked, 'available' => $available];
}

/* ── Handle POST — build the exam ─────────────────────────────────────── */
$results = null; // null = form not yet submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rows'])) {
    Auth::validateCsrf();

    $subjIds  = $_POST['rowSubject'] ?? [];
    $chapIds  = $_POST['rowChapter'] ?? [];
    $counts   = $_POST['rowCount']   ?? [];

    $results       = [];
    $totalAdded    = 0;
    $allPickedIds  = []; // de-dupe across rows within this one submission

    $rowN = max(count($subjIds), count($chapIds), count($counts));
    for ($i = 0; $i < $rowN; $i++) {
        $subjectId = (int)($subjIds[$i] ?? 0);
        $chapterId = (int)($chapIds[$i] ?? 0);
        $count     = max(0, min(500, (int)($counts[$i] ?? 0))); // 500/row sanity cap
        if ($count <= 0) continue; // blank row — skip silently

        $pick = qbb_pickForRow($examId, $subjectId, $chapterId, $count, $hasChapterCol);
        $candidates = array_values(array_diff($pick['picked'], $allPickedIds));
        // If de-duping against earlier rows in this submission ate into the
        // count, top up from the same pool (re-query without the ones we
        // already used) rather than silently under-delivering this row.
        if (count($candidates) < $count) {
            $topUp = qbb_pickForRow($examId, $subjectId, $chapterId, $count * 2, $hasChapterCol);
            $extra = array_values(array_diff($topUp['picked'], $allPickedIds, $candidates));
            $candidates = array_slice(array_merge($candidates, $extra), 0, $count);
        }

        $subjectName = $subjectId ? (array_column($subjects, 'SubjectName', 'SubjectInfoId')[$subjectId] ?? "Subject #$subjectId") : 'Any subject';
        $chapterName = $chapterId ? (array_column($chapters, 'ChapterName', 'ChapterInfoId')[$chapterId] ?? "Chapter #$chapterId") : null;

        $added = 0;
        foreach ($candidates as $qid) {
            try {
                $affected = Database::execute(
                    "INSERT IGNORE INTO exam_questions (ExamInfoId, QuestionId, IsActive) VALUES (?, ?, 'Y')",
                    [$examId, $qid]);
                if ($affected > 0) { $added++; $allPickedIds[] = $qid; $totalAdded++; }
            } catch (Exception $e) { /* skip this one, keep going */ }
        }

        $results[] = [
            'subject'    => $subjectName,
            'chapter'    => $chapterName,
            'requested'  => $count,
            'added'      => $added,
            'shortfall'  => max(0, $count - $added),
        ];
    }

    /* Keep NumOfQuestions / TotalMarks roughly in sync with what's actually
       linked now — same spirit as manage.php's multi-subject section sum,
       but additive since this tool ADDS to an existing exam rather than
       replacing its pattern outright. Best-effort; never blocks the result
       page on failure. */
    if ($totalAdded > 0) {
        try {
            $newCount = (int)(Database::fetchOne(
                "SELECT COUNT(*) AS c FROM exam_questions WHERE ExamInfoId = ? AND COALESCE(IsActive,'Y')='Y'",
                [$examId])['c'] ?? 0);
            if ($newCount > 0) {
                if (($exam['MarkingScheme'] ?? 'Dynamic') === 'Fixed' && (float)($exam['MarksPerQuestion'] ?? 0) > 0) {
                    Database::execute(
                        "UPDATE examinfo SET NumOfQuestions = ?, TotalMarks = ? WHERE ExamInfoId = ?",
                        [$newCount, $newCount * (float)$exam['MarksPerQuestion'], $examId]);
                } else {
                    Database::execute("UPDATE examinfo SET NumOfQuestions = ? WHERE ExamInfoId = ?", [$newCount, $examId]);
                }
            }
        } catch (Exception $e) { /* best-effort only */ }
    }
}

$currentQuestionCount = (int)(Database::fetchOne(
    "SELECT COUNT(*) AS c FROM exam_questions WHERE ExamInfoId = ? AND COALESCE(IsActive,'Y')='Y'", [$examId]
)['c'] ?? 0);

$pageTitle = 'Build from Question Bank';
include __DIR__ . '/../includes/header.php';
?>

<nav style="font-size:.85rem;color:#718096;margin-bottom:10px;">
  <a href="search.php" style="color:#3182ce;text-decoration:none;">&#128196; Exams</a>
  <span style="margin:0 6px;">&rsaquo;</span>
  <a href="questions.php?examId=<?php echo $examId; ?>" style="color:#3182ce;text-decoration:none;">
    <?php echo htmlspecialchars($exam['ExamName'] ?? ''); ?>
  </a>
  <span style="margin:0 6px;">&rsaquo;</span>
  <span>&#128218; Build from Question Bank</span>
</nav>

<div class="card" style="margin-bottom:16px;">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
    <span>&#128218; Build from Question Bank</span>
    <a href="questions.php?examId=<?php echo $examId; ?>" class="btn btn-secondary btn-sm">&#8592; Back to Questions</a>
  </div>
  <div class="card-body" style="padding:16px 20px;display:flex;gap:16px;flex-wrap:wrap;align-items:center;">
    <span style="padding:4px 12px;border-radius:16px;background:#ebf8ff;color:#2b6cb0;font-weight:700;font-size:.85rem;">
      Target: <?php echo htmlspecialchars($exam['ExamName'] ?? ''); ?>
    </span>
    <span style="padding:4px 12px;border-radius:16px;background:#f0fff4;color:#276749;font-weight:700;font-size:.85rem;">
      Currently has <?php echo $currentQuestionCount; ?> question<?php echo $currentQuestionCount !== 1 ? 's' : ''; ?>
    </span>
    <span style="padding:4px 12px;border-radius:16px;background:#faf5ff;color:#6b46c1;font-weight:700;font-size:.85rem;">
      <?php echo $totalBankQuestions; ?> question<?php echo $totalBankQuestions !== 1 ? 's' : ''; ?> available across all banks
    </span>
  </div>
</div>

<?php if ($results !== null): ?>
<div class="card" style="margin-bottom:16px;">
  <div class="card-header">&#9989; Result</div>
  <div style="padding:16px 20px;">
    <?php if (!$results): ?>
      <p style="color:#718096;">No rows were filled in — nothing was added.</p>
    <?php else: ?>
      <table class="tbl" style="width:100%;">
        <thead><tr><th>Subject</th><th>Chapter</th><th style="text-align:center;">Requested</th><th style="text-align:center;">Added</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($results as $r): ?>
          <tr>
            <td><?php echo htmlspecialchars($r['subject']); ?></td>
            <td><?php echo $r['chapter'] ? htmlspecialchars($r['chapter']) : '<span style="color:#94a3b8;">— any —</span>'; ?></td>
            <td style="text-align:center;"><?php echo $r['requested']; ?></td>
            <td style="text-align:center;font-weight:700;color:#276749;"><?php echo $r['added']; ?></td>
            <td>
              <?php if ($r['shortfall'] > 0): ?>
                <span style="color:#b7791f;font-size:.82rem;">&#9888; <?php echo $r['shortfall']; ?> short — the bank didn't have enough fresh questions matching this row.</span>
              <?php else: ?>
                <span style="color:#276749;font-size:.82rem;">&#10003; Filled</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
    <div style="margin-top:16px;display:flex;gap:10px;">
      <a href="questions.php?examId=<?php echo $examId; ?>" class="btn btn-primary">&#10067; View Questions</a>
      <a href="question-bank-builder.php?examId=<?php echo $examId; ?>" class="btn btn-secondary">&#8635; Add More</a>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-header">&#128203; Choose Subjects, Chapters &amp; Counts</div>
  <div style="padding:16px 20px;">
    <p style="font-size:.87rem;color:#4a5568;margin-top:0;">
      Add one row per subject/chapter combination you want questions from — e.g. for a NEET-style paper,
      add a row each for Physics, Chemistry, Botany, Zoology with a count of 45. Subject and Chapter are
      both optional (leave "Any" to sample from the whole bank); add several rows for the same subject
      with different chapters (e.g. 10 from "Mechanics" + 10 from "Thermodynamics") to control the mix
      precisely. Questions are picked at random and never duplicate what's already in this exam.
    </p>

    <?php if ($totalBankQuestions === 0): ?>
      <div class="alert alert-warning">
        No exams are flagged as a Question Bank yet (or none of them have any active questions linked).
        Mark an exam as a Question Bank from its <a href="manage.php">Edit Exam</a> page first.
      </div>
    <?php else: ?>

    <form method="post" id="qbbForm">
      <input type="hidden" name="csrf_token" value="<?php echo Auth::csrfToken(); ?>">
      <input type="hidden" name="examId"     value="<?php echo $examId; ?>">
      <input type="hidden" name="rows"       value="1">

      <div id="rowsWrap">
        <!-- rows injected by JS from #rowTemplate, starting with one -->
      </div>

      <button type="button" class="btn btn-secondary btn-sm" onclick="addRow()" style="margin-top:6px;">
        &#10010; Add Row
      </button>

      <div style="margin-top:20px;padding-top:16px;border-top:1px solid #e2e8f0;">
        <button type="submit" class="btn btn-success" style="font-weight:700;">
          &#128218; Build / Add to Exam
        </button>
        <span style="font-size:.82rem;color:#718096;margin-left:10px;">
          Links questions into this exam — no data is duplicated. Safe to run more than once.
        </span>
      </div>
    </form>

    <?php endif; ?>
  </div>
</div>

<!-- Row template (cloned by JS) -->
<template id="rowTemplate">
  <div class="qbb-row" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;padding:10px 0;border-bottom:1px solid #edf2f7;">
    <div class="form-group" style="min-width:200px;">
      <label style="font-size:.78rem;color:#6b7280;">Subject</label>
      <select name="rowSubject[]" class="form-control qbb-subject" onchange="qbbFilterChapters(this)">
        <option value="0">— Any Subject —</option>
        <?php foreach ($subjects as $s): ?>
          <option value="<?php echo (int)$s['SubjectInfoId']; ?>">
            <?php echo htmlspecialchars($s['SubjectName']); ?>
            (<?php echo $bankCountsBySubject[(int)$s['SubjectInfoId']] ?? 0; ?> in bank)
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php if (!empty($chapters)): ?>
    <div class="form-group" style="min-width:200px;">
      <label style="font-size:.78rem;color:#6b7280;">Chapter <span style="font-weight:400;">(optional)</span></label>
      <select name="rowChapter[]" class="form-control qbb-chapter">
        <option value="0">— Any Chapter —</option>
        <?php foreach ($chapters as $c): ?>
          <option value="<?php echo (int)$c['ChapterInfoId']; ?>" data-subject="<?php echo (int)$c['SubjectInfoId']; ?>" hidden>
            <?php echo htmlspecialchars($c['ChapterName']); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
    <div class="form-group" style="width:120px;">
      <label style="font-size:.78rem;color:#6b7280;">Count</label>
      <input type="number" name="rowCount[]" class="form-control" min="0" max="500" value="10">
    </div>
    <button type="button" class="btn btn-secondary btn-sm" onclick="this.closest('.qbb-row').remove()" title="Remove row">&#10006;</button>
  </div>
</template>

<script>
function qbbFilterChapters(subjSelect) {
  var row = subjSelect.closest('.qbb-row');
  var chapSelect = row.querySelector('.qbb-chapter');
  if (!chapSelect) return;
  var subjId = subjSelect.value;
  var resetNeeded = false;
  chapSelect.querySelectorAll('option[data-subject]').forEach(function (opt) {
    var match = (subjId === '0' || opt.dataset.subject === subjId);
    opt.hidden = !match;
    if (!match && opt.selected) resetNeeded = true;
  });
  if (resetNeeded) chapSelect.value = '0';
}

function addRow() {
  var tpl = document.getElementById('rowTemplate');
  var clone = tpl.content.cloneNode(true);
  document.getElementById('rowsWrap').appendChild(clone);
}

// Start with one row already visible.
addRow();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
