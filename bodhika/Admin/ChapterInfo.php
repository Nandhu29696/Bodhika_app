<?php
/**
 * Admin/ChapterInfo.php — Manage NEET/JEE syllabus chapters
 *
 * Chapters are the unit under which chapter-wise question banks are
 * organised (questions.ChapterInfoId, see migrations/migration_v49.sql).
 * A question also carrying a Chapter lets students filter/practice by
 * syllabus chapter instead of only by subject.
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');
if (!Auth::isAdmin()) { header('Location: ../auth/login.php'); exit; }

/* ── Handle quick actions ─────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::validateCsrf();
    $action = $_POST['action'] ?? '';
    $chId   = (int)($_POST['ChapterInfoId'] ?? 0);

    if ($action === 'delete' && $chId > 0) {
        // Guard: don't delete a chapter that already has questions tagged to
        // it — unlink first (set ChapterInfoId back to NULL) or refuse.
        $inUse = (int)(Database::fetchOne(
            "SELECT COUNT(*) AS c FROM questions WHERE ChapterInfoId = ?", [$chId])['c'] ?? 0);
        if ($inUse > 0) {
            header('Location: ChapterInfo.php?msg=inuse&count=' . $inUse);
            exit;
        }
        Database::execute("DELETE FROM chapterinfo WHERE ChapterInfoId = ?", [$chId]);
        header('Location: ChapterInfo.php?msg=deleted');
        exit;
    }
    if ($action === 'toggle' && $chId > 0) {
        Database::execute(
            "UPDATE chapterinfo SET Active = IF(Active='Y','N','Y') WHERE ChapterInfoId = ?",
            [$chId]);
        header('Location: ChapterInfo.php');
        exit;
    }
}

/* ── Filters ──────────────────────────────────────────────────────────────── */
$filterSubject = (int)($_GET['subject'] ?? 0);
$filterActive  = trim($_GET['active']  ?? '');
$search        = trim($_GET['q']       ?? '');

$where  = ['1=1'];
$params = [];
if ($filterSubject > 0) { $where[] = 'c.SubjectInfoId = ?'; $params[] = $filterSubject; }
if ($filterActive !== '') { $where[] = 'c.Active = ?'; $params[] = $filterActive; }
if ($search !== '') { $where[] = 'c.ChapterName LIKE ?'; $params[] = '%' . $search . '%'; }

$sql = "SELECT c.*, s.SubjectName,
               (SELECT COUNT(*) FROM questions q WHERE q.ChapterInfoId = c.ChapterInfoId) AS QuestionCount
          FROM chapterinfo c
     LEFT JOIN subjectinfo s ON s.SubjectInfoId = c.SubjectInfoId
         WHERE " . implode(' AND ', $where) . "
      ORDER BY s.SubjectName ASC, c.ChapterOrder ASC, c.ChapterName ASC";
$chapters = Database::fetchAll($sql, $params);

$subjects = Database::fetchAll("SELECT SubjectInfoId, SubjectName FROM subjectinfo WHERE Active='Y' ORDER BY SubjectName");

$pageTitle = 'Chapters';
include __DIR__ . '/../includes/header.php';
?>
<style>
  .ref-filters { display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end; margin-bottom:18px; }
  .ref-filters .form-group { margin:0; }
  .ref-filters label { font-size:.72rem; font-weight:800; color:var(--clr-primary); text-transform:uppercase; letter-spacing:.06em; display:block; margin-bottom:3px; white-space:nowrap; }
  .ref-filters select,
  .ref-filters input[type=text] { font-size:.85rem; padding:5px 8px; height:32px; }
  .ref-filters .btn { height:32px; padding:0 14px; font-size:.85rem; }
  .act-btn { font-size:.8rem; padding:3px 10px; }
  .tbl th, .tbl td { vertical-align:middle; }
  .inactive-row { opacity:.55; }
  .qcount-badge { display:inline-block; padding:2px 9px; border-radius:20px; font-size:.75rem; font-weight:700; }
  .qcount-ok  { background:#d1fae5; color:#065f46; }
  .qcount-low { background:#fee2e2; color:#991b1b; }
</style>

<div class="card">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
    <span>&#128218; Chapters</span>
    <a href="AddEditChapterInfo.php?ChapterInfoId=0" class="btn btn-primary" style="font-size:.85rem;padding:5px 14px;">
      &#43; Add Chapter
    </a>
  </div>
  <div class="card-body">

    <?php if (isset($_GET['msg']) && $_GET['msg'] === 'deleted'): ?>
      <div class="alert alert-success">Chapter deleted.</div>
    <?php elseif (isset($_GET['msg']) && $_GET['msg'] === 'inuse'): ?>
      <div class="alert alert-danger">
        Can't delete — <?php echo (int)($_GET['count'] ?? 0); ?> question(s) are still tagged with this chapter.
        Re-tag or remove those questions first, or just mark the chapter Inactive instead.
      </div>
    <?php endif; ?>

    <form method="get" action="">
      <div class="ref-filters">
        <div class="form-group">
          <label>Search</label>
          <input type="text" name="q" class="form-control" placeholder="Chapter name…"
                 value="<?php echo htmlspecialchars($search); ?>" style="min-width:200px;">
        </div>
        <div class="form-group">
          <label>Subject</label>
          <select name="subject" class="form-control">
            <option value="0">All Subjects</option>
            <?php foreach ($subjects as $s): ?>
              <option value="<?php echo $s['SubjectInfoId']; ?>"
                <?php echo $filterSubject==(int)$s['SubjectInfoId']?'selected':''; ?>>
                <?php echo htmlspecialchars($s['SubjectName']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Status</label>
          <select name="active" class="form-control">
            <option value="">All</option>
            <option value="Y" <?php echo $filterActive==='Y'?'selected':''; ?>>Active</option>
            <option value="N" <?php echo $filterActive==='N'?'selected':''; ?>>Inactive</option>
          </select>
        </div>
        <div class="form-group">
          <label>&nbsp;</label>
          <button type="submit" class="btn btn-secondary">&#128269; Filter</button>
          <a href="ChapterInfo.php" class="btn btn-secondary">&times; Clear</a>
        </div>
      </div>
    </form>

    <?php if (empty($chapters)): ?>
      <p style="text-align:center;color:#6b7280;padding:30px 0;">
        No chapters found. <a href="AddEditChapterInfo.php?ChapterInfoId=0">Add the first one</a>.
      </p>
    <?php else: ?>
    <div class="tbl-wrap">
      <table class="tbl">
        <thead>
          <tr>
            <th style="width:8%">Order</th>
            <th style="width:32%">Chapter</th>
            <th style="width:16%">Subject</th>
            <th style="width:14%">Questions</th>
            <th style="width:10%">Status</th>
            <th style="width:14%">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($chapters as $c):
            $inactive = ($c['Active'] === 'N');
            $qc = (int)$c['QuestionCount'];
          ?>
          <tr class="<?php echo $inactive ? 'inactive-row' : ''; ?>">
            <td><?php echo (int)$c['ChapterOrder']; ?></td>
            <td><strong><?php echo htmlspecialchars($c['ChapterName']); ?></strong></td>
            <td style="font-size:.85rem;">
              <?php echo $c['SubjectName'] ? htmlspecialchars($c['SubjectName']) : '<em style="color:#9ca3af;">—</em>'; ?>
            </td>
            <td>
              <span class="qcount-badge <?php echo $qc >= 50 ? 'qcount-ok' : 'qcount-low'; ?>">
                <?php echo $qc; ?> question<?php echo $qc !== 1 ? 's' : ''; ?>
              </span>
            </td>
            <td>
              <form method="post" style="display:inline;">
                <input type="hidden" name="csrf_token" value="<?php echo Auth::csrfToken(); ?>">
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="ChapterInfoId" value="<?php echo $c['ChapterInfoId']; ?>">
                <button type="submit" class="btn act-btn <?php echo $inactive ? 'btn-secondary' : 'btn-success'; ?>">
                  <?php echo $inactive ? 'Inactive' : 'Active'; ?>
                </button>
              </form>
            </td>
            <td style="white-space:nowrap;">
              <a href="AddEditChapterInfo.php?ChapterInfoId=<?php echo $c['ChapterInfoId']; ?>"
                 class="btn btn-primary act-btn">&#9998; Edit</a>
              <form method="post" style="display:inline;"
                    onsubmit="return confirm('Delete this chapter?');">
                <input type="hidden" name="csrf_token" value="<?php echo Auth::csrfToken(); ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="ChapterInfoId" value="<?php echo $c['ChapterInfoId']; ?>">
                <button type="submit" class="btn btn-danger act-btn">&#128465;</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div style="margin-top:10px;font-size:.82rem;color:#6b7280;">
      <?php echo count($chapters); ?> chapter<?php echo count($chapters) !== 1 ? 's' : ''; ?> found.
      Chapters with fewer than 50 questions are flagged in red.
    </div>
    <?php endif; ?>

  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
