<?php
/**
 * exam/verify-certificate.php — public certificate verification.
 *
 * Deliberately NOT behind Auth::requireLogin() — this is the page printed
 * on every certificate footer ("Verify at /exam/verify-certificate.php"),
 * meant to be opened by a third party (employer, institution) who has no
 * account at all. Looks up by CertificateNo only; never lists certificates
 * or exposes anything beyond what is already printed on the certificate
 * itself.
 *
 * Looks up Issued AND Revoked certificates on purpose — a verifier needs
 * to be told "this certificate was revoked", not just "not found".
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
require_once __DIR__ . '/../Lib/Certificate.php';
if (file_exists(__DIR__ . '/../Lib/AppSettings.php')) require_once __DIR__ . '/../Lib/AppSettings.php';

$instituteName = class_exists('AppSettings') ? AppSettings::get('cert_institute_name', APP_NAME) : APP_NAME;

$certNo = trim($_GET['certNo'] ?? '');
$cert   = null;
$searched = $certNo !== '';

if ($searched) {
    $cert = Certificate::findByNo($certNo, false); // false = include Revoked too
}

/* Admins (or the certificate's own owner, if logged in) get a link through
   to the full printable certificate; anonymous/third-party verifiers don't
   need it — the summary below is the whole point of this page. */
$canViewFull = false;
if ($cert && Auth::isLoggedIn()) {
    $canViewFull = Auth::isAdmin() || (int)$cert['UserInfoId'] === Auth::currentUserId();
}

function vc_pct(float $p): string
{
    $s = rtrim(rtrim(number_format($p, 1), '0'), '.');
    return $s === '' ? '0' : $s;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#312e81">
  <title>Verify Certificate &mdash; <?= htmlspecialchars(APP_NAME) ?></title>
  <link rel="stylesheet" href="../<?= asset_version('assets/style.css') ?>">
</head>
<body>
<div class="auth-wrap" style="max-width:560px;">
  <div class="auth-header">
    <div class="auth-logo">
      <img src="../assets/logo.png" alt="Logo"
           onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
      <div class="auth-logo-placeholder" style="display:none;">YOUR<br>LOGO</div>
    </div>
    <h1>&#10003; Certificate Verification</h1>
    <p>Confirm the authenticity of a <?= htmlspecialchars($instituteName) ?> certificate</p>
  </div>

  <div class="auth-body">
    <form method="get" action="verify-certificate.php" autocomplete="off">
      <div class="form-group">
        <label for="certNo">Certificate Number</label>
        <input type="text" id="certNo" name="certNo" class="form-control"
               placeholder="e.g. CERT-2026-00001" required autofocus
               value="<?= htmlspecialchars($certNo) ?>">
      </div>
      <button type="submit" class="btn btn-primary btn-block">&#128269; Verify</button>
    </form>

    <?php if ($searched): ?>
      <div style="margin-top:22px;">
        <?php if (!$cert): ?>
          <div class="alert alert-danger">
            &#10006; No certificate found with number <strong><?= htmlspecialchars($certNo) ?></strong>.
            Please check the number and try again.
          </div>
        <?php else:
          $isRevoked = $cert['Status'] === 'Revoked';
          $isMerit   = $cert['CertType'] === 'merit';
          $issueDateFmt = date('d M Y', strtotime((string)$cert['IssueDate']));
        ?>
          <?php if ($isRevoked): ?>
            <div class="alert alert-danger">
              &#9888; <strong>This certificate has been revoked.</strong>
              It is no longer valid, even though the details below were originally issued.
            </div>
          <?php else: ?>
            <div class="alert alert-success">
              &#10003; <strong>This is a valid, currently issued certificate.</strong>
            </div>
          <?php endif; ?>

          <div style="border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;margin-top:4px;">
            <div style="background:#f7fafc;padding:10px 16px;font-weight:700;color:#1e3a5f;
                        display:flex;justify-content:space-between;align-items:center;">
              <span><?= $isMerit ? '&#127942; Certificate of Merit' : '&#127891; Certificate of Completion' ?></span>
              <span class="badge-<?= $isRevoked ? 'fail' : 'pass' ?>"><?= htmlspecialchars($cert['Status']) ?></span>
            </div>
            <div style="padding:16px;font-size:.92rem;">
              <table style="width:100%;border-collapse:collapse;">
                <tr><td style="padding:6px 0;color:#718096;width:40%;">Certificate No.</td>
                    <td style="padding:6px 0;font-family:monospace;font-weight:700;"><?= htmlspecialchars($cert['CertificateNo']) ?></td></tr>
                <tr><td style="padding:6px 0;color:#718096;">Issued To</td>
                    <td style="padding:6px 0;font-weight:700;"><?= htmlspecialchars($cert['StudentName']) ?></td></tr>
                <tr><td style="padding:6px 0;color:#718096;">Course / Subject</td>
                    <td style="padding:6px 0;"><?= htmlspecialchars($cert['CourseName']) ?></td></tr>
                <?php if (!empty($cert['Duration'])): ?>
                <tr><td style="padding:6px 0;color:#718096;">Duration</td>
                    <td style="padding:6px 0;"><?= htmlspecialchars($cert['Duration']) ?></td></tr>
                <?php endif; ?>
                <?php if ($isMerit && !empty($cert['Grade'])): ?>
                <tr><td style="padding:6px 0;color:#718096;">Grade</td>
                    <td style="padding:6px 0;">
                      <span style="background:#fef3c7;color:#92400e;padding:2px 10px;border-radius:10px;font-weight:700;font-size:.82rem;">
                        <?= htmlspecialchars($cert['Grade']) ?>
                      </span>
                      <?php if ($cert['Percentage'] !== null && $cert['Percentage'] !== ''): ?>
                        <span style="color:#4a5568;margin-left:6px;">(<?= vc_pct((float)$cert['Percentage']) ?>%)</span>
                      <?php endif; ?>
                    </td></tr>
                <?php endif; ?>
                <tr><td style="padding:6px 0;color:#718096;">Issue Date</td>
                    <td style="padding:6px 0;"><?= htmlspecialchars($issueDateFmt) ?></td></tr>
                <tr><td style="padding:6px 0;color:#718096;">Issuing Institute</td>
                    <td style="padding:6px 0;"><?= htmlspecialchars($instituteName) ?></td></tr>
              </table>

              <?php if ($canViewFull): ?>
                <div style="margin-top:14px;text-align:center;">
                  <a href="certificate-print.php?id=<?= (int)$cert['CertificateId'] ?>" target="_blank"
                     class="btn btn-secondary btn-sm">&#128424; View / Print Full Certificate</a>
                </div>
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <div style="text-align:center;margin-top:18px;font-size:.82rem;color:var(--clr-text-muted);">
      <a href="../auth/login.php">&larr; Back to <?= htmlspecialchars(APP_NAME) ?></a>
    </div>
  </div>
</div>
</body>
</html>
