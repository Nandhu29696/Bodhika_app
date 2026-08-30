<?php
/**
 * exam/log.php  — Exam audit / change log (admin only).
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');

if (!Auth::isAdmin()) { header('Location: search.php'); exit; }
$isAdmin = true;

$pageTitle = 'Exam Log';

// Filter params
$filterAction = trim($_GET['action'] ?? '');
$filterExam   = filter_input(INPUT_GET, 'exam', FILTER_VALIDATE_INT);
$filterUser   = trim($_GET['user'] ?? '');

$where  = []; $params = [];
if ($filterAction !== '') { $where[] = 'Action = ?';      $params[] = $filterAction; }
if ($filterExam   > 0)   { $where[] = 'ExamInfoId = ?';  $params[] = $filterExam; }
if ($filterUser   !== '') { $where[] = 'ActionBy LIKE ?'; $params[] = '%'.$filterUser.'%'; }

$logs = [];
try {
    $logs = Database::fetchAll(
        "SELECT * FROM exam_changelog" .
        ($where ? ' WHERE ' . implode(' AND ', $where) : '') .
        " ORDER BY ActionAt DESC LIMIT 500",
        $params);
} catch (Exception $e) {
    $logs = [];  // table may not exist yet
}

$exams = Database::fetchAll("SELECT ExamInfoId, ExamName FROM examinfo ORDER BY ExamName");

$actionColors = [
    'CREATE' => 'tag-create', 'EDIT' => 'tag-edit',
    'DELETE' => 'tag-delete', 'TAKEN' => 'tag-taken',
];

include __DIR__ . '/../includes/header.php';
?>
<div class="card">
  <div class="card-header">&#128203; Exam Change Log</div>
  <div class="card-body">
    <form method="get" action="" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
      <div class="form-group" style="margin-bottom:0;min-width:140px;">
        <label>Action</label>
        <select name="action" class="form-control">
          <option value="">-- All --</option>
          <?php foreach (['CREATE','EDIT','DELETE','TAKEN'] as $a): ?>
            <option value="<?php echo $a; ?>" <?php echo $filterAction===$a?'selected':''; ?>><?php echo $a; ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="margin-bottom:0;min-width:180px;">
        <label>Exam</label>
        <select name="exam" class="form-control">
          <option value="0">-- All Exams --</option>
          <?php foreach ($exams as $e): ?>
            <option value="<?php echo (int)$e['ExamInfoId']; ?>" <?php echo $filterExam===(int)$e['ExamInfoId']?'selected':''; ?>>
              <?php echo htmlspecialchars($e['ExamName']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="margin-bottom:0;min-width:150px;">
        <label>User</label>
        <input type="text" name="user" class="form-control" placeholder="username..."
               value="<?php echo htmlspecialchars($filterUser); ?>">
      </div>
      <div style="display:flex;gap:8px;margin-top:18px;">
        <button type="submit" class="btn btn-primary">&#128269; Filter</button>
        <a href="log.php" class="btn btn-secondary">Clear</a>
      </div>
    </form>
  </div>
</div>
<div class="card">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
    <span>Log Entries</span>
    <span style="font-size:.8rem;font-weight:normal;"><?php echo count($logs); ?> record(s)</span>
  </div>
  <div class="card-body" style="padding:0;overflow-x:auto;">
    <?php if (empty($logs)): ?>
      <p style="padding:20px;color:#718096;text-align:center;">
        No log entries found.
        <?php if ($logs === [] && !$filterAction && !$filterExam && !$filterUser): ?>
          <br><small>Run <code>migration_v2.sql</code> to create the exam_changelog table.</small>
        <?php endif; ?>
      </p>
    <?php else: ?>
    <table class="tbl">
      <thead>
        <tr><th>Action</th><th>Exam</th><th>Done By</th><th>Score</th><th>Details</th><th>Date &amp; Time</th></tr>
      </thead>
      <tbody>
        <?php foreach ($logs as $i => $log): ?>
        <tr class="<?php echo ($i%2===0)?'odd':'even'; ?>">
          <td><span class="tag <?php echo $actionColors[$log['Action']] ?? ''; ?>"><?php echo htmlspecialchars($log['Action']); ?></span></td>
          <td><?php echo htmlspecialchars($log['ExamName'] ?? '—'); ?></td>
          <td><?php echo htmlspecialchars($log['ActionBy']); ?></td>
          <td class="text-center">
            <?php if ($log['Score'] !== null): ?>
              <?php echo (int)$log['Score']; ?> / <?php echo (int)$log['MarksOutOf']; ?>
            <?php else: echo '—'; endif; ?>
          </td>
          <td style="font-size:.8rem;color:#4a5568;"><?php echo htmlspecialchars($log['Details'] ?? ''); ?></td>
          <td style="white-space:nowrap;"><?php echo htmlspecialchars(date('d M Y H:i', strtotime($log['ActionAt']))); ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
