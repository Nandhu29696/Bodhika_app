<?php
/**
 * Admin/InstituteAdminHome.php — Institute-Admin dashboard.
 *
 * Landing page for the INSTADMIN role (Auth::isInstituteAdmin()) — a scoped
 * admin who only sees students belonging to their own institute
 * (userinfo.InstituteId, resolved via Auth::currentInstituteId()).
 *
 * A full Admin can also open this page for support purposes, picking any
 * institute via ?instId= (no institute is implied for a full Admin — they
 * aren't linked to one the way an Institute-Admin's own account is).
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';

Auth::requireLogin('../auth/login.php');
if (!Auth::isInstituteAdmin() && !Auth::isAdmin()) {
    header('Location: ../exam/search.php');
    exit;
}

$isFullAdmin = Auth::isAdmin() && !Auth::isInstituteAdmin();

if ($isFullAdmin) {
    $instId = filter_input(INPUT_GET, 'instId', FILTER_VALIDATE_INT) ?: 0;
} else {
    $instId = Auth::currentInstituteId() ?? 0;
}

$institute = null;
if ($instId > 0) {
    $institute = Database::fetchOne(
        "SELECT InstituteId, InstituteName, InstituteType, State, CityVillage
           FROM institutes WHERE InstituteId = ? LIMIT 1", [$instId]);
}

/* ── Stats (only once we have a real institute) ─────────────────────────── */
$stats = ['students' => 0, 'assignedTotal' => 0, 'forthcoming' => 0, 'attempts' => 0, 'avgPct' => null, 'passRate' => null];

if ($institute) {
    try {
        $stats['students'] = (int)(Database::fetchOne(
            "SELECT COUNT(*) AS c FROM userinfo u
               JOIN logininfo l ON l.LoginName = u.LoginName
              WHERE u.InstituteId = ? AND l.Role = 'STDNT'",
            [$instId])['c'] ?? 0);
    } catch (\Throwable $e) {}

    try {
        $row = Database::fetchOne(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN ea.Status = 'Assigned' THEN 1 ELSE 0 END) AS forthcoming
               FROM exam_assignments ea
               JOIN userinfo  u ON u.UserInfoId = ea.UserInfoId
               JOIN logininfo l ON l.LoginName  = u.LoginName
              WHERE u.InstituteId = ? AND l.Role = 'STDNT'",
            [$instId]);
        $stats['assignedTotal'] = (int)($row['total'] ?? 0);
        $stats['forthcoming']   = (int)($row['forthcoming'] ?? 0);
    } catch (\Throwable $e) {}

    try {
        $row = Database::fetchOne(
            "SELECT COUNT(*) AS attempts,
                    AVG(CASE WHEN se.MarksOutOf > 0 THEN se.Score / se.MarksOutOf * 100 END) AS avgPct,
                    SUM(CASE WHEN se.Description = 'Pass' THEN 1 ELSE 0 END) AS passes
               FROM studentexam se
               JOIN userinfo  u ON u.UserInfoId = se.UserInfoId
               JOIN logininfo l ON l.LoginName  = u.LoginName
              WHERE u.InstituteId = ? AND l.Role = 'STDNT'",
            [$instId]);
        $stats['attempts'] = (int)($row['attempts'] ?? 0);
        $stats['avgPct']   = $row['avgPct'] !== null ? round((float)$row['avgPct'], 1) : null;
        $stats['passRate'] = $stats['attempts'] > 0 ? round(((int)($row['passes'] ?? 0)) / $stats['attempts'] * 100, 1) : null;
    } catch (\Throwable $e) {}
}

/* Institute picker options for full Admin support access */
require_once __DIR__ . '/../Lib/Institute.php';
$institutesForPicker = $isFullAdmin ? Institute::listAll() : [];

$pageTitle = 'Institute Dashboard';
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.ia-wrap { max-width: 1100px; margin: 0 auto; padding: 0 16px; }
.ia-title { font-size: 1.35rem; font-weight: 800; color: var(--clr-primary); margin: 0 0 4px; display:flex; align-items:center; gap:8px; }
.ia-subhead { font-size: .85rem; color: #64748b; margin-bottom: 20px; }
.ia-stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; margin-bottom: 24px; }
.ia-stat { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px 18px; box-shadow: 0 1px 4px rgba(0,0,0,.06); }
.ia-stat-num { font-size: 1.7rem; font-weight: 800; color: var(--clr-primary); line-height: 1.1; }
.ia-stat-label { font-size: .78rem; color: #64748b; margin-top: 4px; font-weight: 600; text-transform: uppercase; letter-spacing: .03em; }
.ia-actions { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 24px; }
.ia-action-card { flex: 1 1 220px; background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 18px; text-decoration: none; color: inherit; transition: box-shadow .15s, border-color .15s; }
.ia-action-card:hover { box-shadow: 0 4px 14px rgba(0,0,0,.1); border-color: var(--clr-primary); }
.ia-action-icon { font-size: 1.6rem; margin-bottom: 8px; }
.ia-action-title { font-weight: 700; color: #1e293b; font-size: .95rem; }
.ia-action-desc { font-size: .8rem; color: #64748b; margin-top: 4px; }
.ia-picker { background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:14px 16px;margin-bottom:20px;display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap; }
.ia-picker select { height:34px;border:1px solid #cbd5e1;border-radius:5px;font-size:13px;padding:0 10px;min-width:260px; }
.ia-picker button { background:var(--clr-gold);color:#fff;border:none;padding:0 20px;height:34px;border-radius:5px;cursor:pointer;font-size:13px;font-weight:600; }
</style>

<div class="ia-wrap">

  <?php if ($isFullAdmin): ?>
  <form method="get" class="ia-picker">
    <div>
      <label style="display:block;font-size:11px;color:#64748b;margin-bottom:4px;">Viewing as Institute Admin for:</label>
      <select name="instId">
        <option value="">— Select an institute —</option>
        <?php foreach ($institutesForPicker as $inst): ?>
          <option value="<?= (int)$inst['InstituteId'] ?>" <?= $instId === (int)$inst['InstituteId'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($inst['InstituteName']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="submit">View</button>
  </form>
  <?php endif; ?>

  <?php if (!$institute): ?>
    <div class="card">
      <div class="card-body" style="text-align:center;padding:40px;">
        <?php if ($isFullAdmin): ?>
          <p style="color:#64748b;">Select an institute above to view its Institute-Admin dashboard.</p>
        <?php else: ?>
          <p style="color:#64748b;">Your account isn't linked to an institute yet. Please contact your Admin to have
          an institute assigned to your account — you won't see any students until then.</p>
        <?php endif; ?>
      </div>
    </div>
  <?php else: ?>

  <div class="ia-title">&#127979; <?= htmlspecialchars($institute['InstituteName']) ?></div>
  <div class="ia-subhead">
    <?= htmlspecialchars($institute['InstituteType'] ?: '—') ?> &middot;
    <?= htmlspecialchars($institute['CityVillage'] ?: '—') ?>, <?= htmlspecialchars($institute['State'] ?: '—') ?>
  </div>

  <div class="ia-stat-grid">
    <div class="ia-stat">
      <div class="ia-stat-num"><?= number_format($stats['students']) ?></div>
      <div class="ia-stat-label">Students</div>
    </div>
    <div class="ia-stat">
      <div class="ia-stat-num"><?= number_format($stats['forthcoming']) ?></div>
      <div class="ia-stat-label">Forthcoming Exams</div>
    </div>
    <div class="ia-stat">
      <div class="ia-stat-num"><?= number_format($stats['assignedTotal']) ?></div>
      <div class="ia-stat-label">Total Assignments</div>
    </div>
    <div class="ia-stat">
      <div class="ia-stat-num"><?= $stats['avgPct'] !== null ? $stats['avgPct'] . '%' : '—' ?></div>
      <div class="ia-stat-label">Average Score</div>
    </div>
    <div class="ia-stat">
      <div class="ia-stat-num"><?= $stats['passRate'] !== null ? $stats['passRate'] . '%' : '—' ?></div>
      <div class="ia-stat-label">Pass Rate (<?= number_format($stats['attempts']) ?> attempts)</div>
    </div>
  </div>

  <div class="ia-actions">
    <a class="ia-action-card" href="InstituteAdminStudents.php<?= $isFullAdmin ? '?instId='.$instId : '' ?>">
      <div class="ia-action-icon">&#128101;</div>
      <div class="ia-action-title">Students</div>
      <div class="ia-action-desc">Roster, performance history, assigned &amp; forthcoming exams per student.</div>
    </a>
    <a class="ia-action-card" href="ResetStudentPassword.php<?= $isFullAdmin ? '?instId='.$instId : '' ?>">
      <div class="ia-action-icon">&#128274;</div>
      <div class="ia-action-title">Reset Student Password</div>
      <div class="ia-action-desc">Reset a student's password to the default; they'll be prompted to set a new one at next login.</div>
    </a>
  </div>

  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
