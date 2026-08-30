<?php
/**
 * exam/certificates.php — "My Certificates" (student-facing).
 *
 * Lists every certificate issued to the logged-in user (Completion + Merit,
 * Issued + Revoked) with a link through to exam/certificate-print.php?id=N
 * to view/print. Certificate::findById() already enforces that a student
 * may only ever load their OWN certificate by id, so the link here is safe
 * even if guessed/shared.
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
require_once __DIR__ . '/../Lib/Certificate.php';
Auth::requireLogin('../auth/login.php');

$certs = Certificate::listIssued(['userId' => Auth::currentUserId()]);

$pageTitle = 'My Certificates';
include __DIR__ . '/../includes/header.php';
?>
<style>
  .mycert-grid { display:grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap:16px; }
  .mycert-card { background:var(--clr-surface); border-radius:var(--radius-lg); box-shadow:var(--shadow-sm);
                 padding:18px 20px; display:flex; flex-direction:column; gap:8px; }
  .mycert-type { font-size:.78rem; font-weight:700; letter-spacing:.5px; text-transform:uppercase; color:#718096; }
  .mycert-course { font-size:1.05rem; font-weight:700; color:#1e3a5f; }
  .mycert-meta { font-size:.82rem; color:#4a5568; }
  .mycert-grade { display:inline-block; background:#fef3c7; color:#92400e; padding:2px 10px;
                  border-radius:10px; font-size:.76rem; font-weight:700; }
  .mycert-footer { margin-top:auto; display:flex; justify-content:space-between; align-items:center; padding-top:8px; }
</style>

<h2 style="margin-bottom:4px;">&#127942; My Certificates</h2>
<p style="color:var(--clr-text-muted);margin-bottom:18px;">
  Certificates of completion and merit issued to you. Click View / Print to open a printable copy
  (Print &rarr; Save as PDF from your browser).
</p>

<?php if (!$certs): ?>
  <div class="card" style="padding:32px;text-align:center;color:var(--clr-text-muted);">
    No certificates have been issued to you yet.
  </div>
<?php else: ?>
  <div class="mycert-grid">
    <?php foreach ($certs as $c):
      $isMerit   = $c['CertType'] === 'merit';
      $isRevoked = $c['Status'] === 'Revoked';
    ?>
      <div class="mycert-card">
        <div class="mycert-type">
          <?= $isMerit ? '&#127942; Certificate of Merit' : '&#127891; Certificate of Completion' ?>
        </div>
        <div class="mycert-course"><?= htmlspecialchars($c['CourseName']) ?></div>
        <?php if ($c['Duration']): ?>
          <div class="mycert-meta"><?= htmlspecialchars($c['Duration']) ?></div>
        <?php endif; ?>
        <div class="mycert-meta">Issued <?= htmlspecialchars(date('d M Y', strtotime((string)$c['IssueDate']))) ?></div>
        <?php if ($isMerit && $c['Grade']): ?>
          <div><span class="mycert-grade">Grade: <?= htmlspecialchars($c['Grade']) ?></span></div>
        <?php endif; ?>
        <div class="mycert-meta" style="font-family:monospace;color:#9ca3af;font-size:.74rem;">
          <?= htmlspecialchars($c['CertificateNo']) ?>
        </div>

        <div class="mycert-footer">
          <span class="badge-<?= $isRevoked ? 'fail' : 'pass' ?>"><?= htmlspecialchars($c['Status']) ?></span>
          <a href="certificate-print.php?id=<?= (int)$c['CertificateId'] ?>" target="_blank"
             class="btn btn-secondary btn-sm">&#128424; View / Print</a>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
