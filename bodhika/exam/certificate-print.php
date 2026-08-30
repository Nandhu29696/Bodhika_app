<?php
/**
 * exam/certificate-print.php — print-ready certificate renderer.
 *
 * Lives outside Admin/ on purpose: both admins (issuing/reprinting any
 * certificate) and students (viewing their own) land here under one rule.
 *
 * Modes:
 *   ?id=N                  Single certificate. Admin, or the owning student.
 *   ?batch=1,2,3            Multiple certificates, one per printed page
 *                            (GenerateCertificates.php's POST redirect). Admin only.
 *   ?preview=1&templateId=N Sample/placeholder render of a not-yet-issued
 *                            template (CertificateTemplates.php's Preview link). Admin only.
 *
 * Output is plain HTML + print CSS (A4 landscape, page-break-after between
 * certificates) — no PDF library involved. The browser's native
 * Print → Save as PDF covers that need.
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
require_once __DIR__ . '/../Lib/Certificate.php';
require_once __DIR__ . '/../Lib/AppSettings.php';
require_once __DIR__ . '/../Lib/WordTemplate.php';
Auth::requireLogin('../auth/login.php');

function cert_fail(string $message, int $code = 404): void
{
    http_response_code($code);
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Certificate</title>'
       . '<style>body{font-family:Georgia,serif;background:#edf2f7;display:flex;'
       . 'align-items:center;justify-content:center;height:100vh;margin:0;}'
       . '.box{background:#fff;padding:32px 40px;border-radius:10px;'
       . 'box-shadow:0 2px 16px rgba(0,0,0,.1);text-align:center;max-width:420px;}'
       . '.box h2{margin:0 0 10px;color:#dc2626;font-size:1.2rem;}'
       . '.box a{color:#3182ce;text-decoration:none;font-size:.9rem;}</style></head><body>'
       . '<div class="box"><h2>&#9888; ' . htmlspecialchars($message) . '</h2>'
       . '<p><a href="javascript:history.back()">&larr; Go back</a></p></div>'
       . '</body></html>';
    exit;
}

/**
 * Strip characters that are illegal (or awkward) in a downloaded filename,
 * collapsing whitespace along the way. Browsers derive their "Save as PDF"
 * suggested filename from <title>, so this keeps that name clean without
 * needing any client-side JS.
 */
function cert_safe_filename(string $s): string
{
    $s = preg_replace('/[\\\\\/:*?"<>|]+/', ' ', trim($s));
    $s = preg_replace('/\s+/', ' ', $s);
    return trim((string)$s);
}

/* ── Resolve mode + records to render ───────────────────────────────────── */
$mode    = '';
$records = []; // each: ['data' => array, 'isPreview' => bool]

if (isset($_GET['preview'])) {
    if (!Auth::isAdmin()) cert_fail('You do not have permission to preview templates.', 403);

    $templateId = (int)($_GET['templateId'] ?? 0);
    $template   = Certificate::getTemplate($templateId);
    if (!$template) cert_fail('Template not found.');

    // Word templates have no HTML/CSS to preview inline — instead fill the
    // .docx with the same dummy data used below and hand it straight back as
    // a download, so an admin can open it in Word and see exactly what an
    // issued certificate will look like before mapping is even finished
    // (unmapped tokens are simply left as literal {{TOKEN}} text).
    if (($template['TemplateType'] ?? 'coded') === 'word') {
        if (empty($template['WordFile']) || !is_file(__DIR__ . '/../Admin/' . $template['WordFile'])) {
            cert_fail('This Word template\'s file is missing on the server.');
        }
        $isMerit = $template['CertType'] === 'merit';
        $sample  = Certificate::fieldValues([
            'StudentName'   => 'Jordan A. Smith',
            'CourseName'    => 'Full-Stack Web Development',
            'Duration'      => '12 Weeks',
            'IssueDate'     => date('Y-m-d'),
            'CertificateNo' => 'CERT-PREVIEW-0000',
            'Grade'         => $isMerit ? Certificate::gradeForPercent(92.0) : '',
            'Percentage'    => $isMerit ? 92.0 : null,
        ]);
        $tokenValues = [];
        foreach (Certificate::decodeJsonArray($template['WordFieldMap'] ?? null) as $token => $map) {
            $tokenValues[$token] = ($map['type'] ?? '') === 'system'
                ? ($sample[$map['field']] ?? '')
                : 'Sample ' . ($map['label'] ?? $token);
        }

        // Built directly (not via tempnam()) so the file WordTemplate::fillTemplate
        // creates has the .docx extension from the start — tempnam() always
        // creates its reserved file with no extension, which would leave that
        // stray empty file behind once we write the real output elsewhere.
        $tmpFile = sys_get_temp_dir() . '/certpreview_' . uniqid('', true) . '.docx';
        $fillError = null;
        $ok = WordTemplate::fillTemplate(__DIR__ . '/../Admin/' . $template['WordFile'], $tmpFile, $tokenValues, $fillError);
        if (!$ok || !is_file($tmpFile)) {
            cert_fail('Could not generate a sample from this template.' . ($fillError ? ' (' . $fillError . ')' : ''));
        }

        $downloadName = cert_safe_filename($template['Name']) . ' - Sample.docx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('Content-Length: ' . filesize($tmpFile));
        readfile($tmpFile);
        @unlink($tmpFile);
        exit;
    }

    $isMerit    = $template['CertType'] === 'merit';
    $records[]  = [
        'data' => [
            'CertificateNo'   => 'CERT-PREVIEW-0000',
            'StudentName'     => 'Jordan A. Smith',
            'CourseName'      => 'Full-Stack Web Development',
            'Duration'        => '12 Weeks',
            'IssueDate'       => date('Y-m-d'),
            'CertType'        => $template['CertType'],
            'Percentage'      => $isMerit ? 92.0 : null,
            'Grade'           => $isMerit ? Certificate::gradeForPercent(92.0) : null,
            'Status'          => 'Issued',
            'ThemeKey'        => $template['ThemeKey'],
            'UserInfoId'      => 0,
            'TemplateType'    => $template['TemplateType']    ?? 'coded',
            'BackgroundImage' => $template['BackgroundImage'] ?? null,
            'LayoutJson'      => $template['LayoutJson']      ?? null,
            'SignatoriesJson' => $template['SignatoriesJson'] ?? null,
        ],
        'isPreview' => true,
    ];
    $mode = 'preview';

} elseif (isset($_GET['batch'])) {
    if (!Auth::isAdmin()) cert_fail('You do not have permission to view this batch.', 403);

    $ids = array_values(array_unique(array_filter(
        array_map('intval', explode(',', (string)$_GET['batch'])),
        fn($v) => $v > 0
    )));
    if (!$ids) cert_fail('No certificates specified.');
    $ids = array_slice($ids, 0, 200); // sane upper bound

    foreach ($ids as $cid) {
        $cert = Certificate::findById($cid);
        if ($cert) $records[] = ['data' => $cert, 'isPreview' => false];
    }
    if (!$records) cert_fail('None of the requested certificates could be found.');
    $mode = 'batch';

} elseif (isset($_GET['id'])) {
    $cert = Certificate::findById((int)$_GET['id']);
    if (!$cert) cert_fail('Certificate not found.');

    $isOwner = (int)$cert['UserInfoId'] === Auth::currentUserId();
    if (!Auth::isAdmin() && !$isOwner) cert_fail('You do not have permission to view this certificate.', 403);

    $records[] = ['data' => $cert, 'isPreview' => false];
    $mode = 'single';

} else {
    cert_fail('No certificate requested.');
}

/**
 * Word-template certificates in this batch that actually have a generated
 * file to combine — drives the "Download All" toolbar buttons below
 * (exam/certificate-download-batch.php). Coded/image-theme certificates
 * already have a combined download path (the browser's own Print > Save as
 * PDF, which naturally spans every .cert-page on this view), so this is
 * word-template-only and only worth showing once there's more than one —
 * a single word certificate already has its own download button on its card.
 */
$wordBatchIds = [];
if ($mode === 'batch') {
    foreach ($records as $rec) {
        if (($rec['data']['TemplateType'] ?? 'coded') === 'word' && !empty($rec['data']['GeneratedFile'])) {
            $wordBatchIds[] = (int)($rec['data']['CertificateId'] ?? 0);
        }
    }
}

/**
 * Document <title> — also doubles as the browser's suggested filename for
 * "Print > Save as PDF", so a single certificate should be saved under the
 * recipient's name rather than a generic "Certificate.pdf" every admin/
 * student would otherwise end up with.
 */
if (($mode === 'single' || $mode === 'preview') && !empty($records[0]['data']['StudentName'])) {
    $certNo   = (string)($records[0]['data']['CertificateNo'] ?? '');
    $docTitle = cert_safe_filename((string)$records[0]['data']['StudentName'])
              . ' - Certificate' . ($certNo !== '' && $mode === 'single' ? ' ' . $certNo : '');
} elseif ($mode === 'batch') {
    $docTitle = 'Certificates - Batch of ' . count($records);
} else {
    $docTitle = 'Certificate';
}

$instituteName    = AppSettings::get('cert_institute_name', APP_NAME);
$instituteTagline = AppSettings::get('cert_institute_tagline', 'Learn • Practice • Succeed');
$signatoryName    = AppSettings::get('cert_signatory_name', '');
$signatoryTitle   = AppSettings::get('cert_signatory_title', 'Director');
if ($signatoryTitle === '') $signatoryTitle = 'Director';
$certLogo      = AppSettings::get('cert_logo', '../assets/riyatrix_cert_header.png');
$certSignature = AppSettings::get('cert_signature', '');
$themes        = Certificate::availableThemes();

// The bundled default logo is a full lockup (icon + "RIYATRIX SYSTEMS"
// wordmark already baked into the image), unlike a typical icon-only custom
// upload — so the separate institute-name/tagline text lines next to it
// would just repeat what's already in the picture. Only show that text
// when a different (presumably icon-only) logo is in use, or no logo at all.
// (An older bundled option, cert-logo.png, is still available in assets/ for
// admins who want to switch back — it gets the same treatment.)
$logoIsBundledLockup = in_array($certLogo, ['../assets/riyatrix_cert_header.png', '../assets/cert-logo.png'], true);

/**
 * Resolve a stored cert_logo/cert_signature value to an absolute filesystem
 * path. Two conventions in play: the bundled defaults live under assets/
 * (relative to this file, exam/), while admin-uploaded files are saved by
 * Admin/AppSettings.php under Admin/images/certificate/ — so a value like
 * 'images/certificate/logo_xxx.jpg' must resolve against Admin/, not exam/.
 */
function cert_resolve_abs(string $stored): ?string
{
    if ($stored === '') return null;
    if (str_starts_with($stored, 'images/certificate/')) {
        return __DIR__ . '/../Admin/' . $stored;
    }
    return __DIR__ . '/' . ltrim($stored, '/');
}

/**
 * Inline an image as a base64 data: URI so it always renders in the printed
 * HTML regardless of web-server path/virtual-host quirks (the same reason
 * the old code inlined the default logo's raw SVG — this generalises that
 * fix to every logo/signature image, uploaded or bundled, of any format).
 */
function cert_data_uri(string $absPath): ?string
{
    if (!is_file($absPath)) return null;
    $raw = @file_get_contents($absPath);
    if ($raw === false) return null;
    $mime = match (strtolower(pathinfo($absPath, PATHINFO_EXTENSION))) {
        'svg'          => 'image/svg+xml',
        'png'          => 'image/png',
        'gif'          => 'image/gif',
        'webp'         => 'image/webp',
        default        => 'image/jpeg',
    };
    return 'data:' . $mime . ';base64,' . base64_encode($raw);
}

/** Build an <img class="..."> tag from a stored cert_logo/cert_signature value. */
function cert_image_html(string $stored, string $class, string $alt): string
{
    $abs = cert_resolve_abs($stored);
    $uri = $abs ? cert_data_uri($abs) : null;
    $src = $uri ?? $stored; // fall back to the raw path if the file couldn't be read
    return '<img class="' . $class . '" src="' . htmlspecialchars($src) . '" alt="' . htmlspecialchars($alt) . '">';
}

$certLogoHtml      = $certLogo      !== '' ? cert_image_html($certLogo, 'cert-logo', $instituteName . ' logo') : '';
$certSignatureHtml = $certSignature !== '' ? cert_image_html($certSignature, 'cert-signature', 'Signature') : '';

// Decorative award-ribbon seal (bundled asset, not admin-configurable).
// Themes that already draw their own CSS medallion/ribbon (gold_seal,
// navy_ribbon) keep that bespoke treatment instead — see the per-record
// $showBadgeSeal check below.
$certBadgeUri = cert_data_uri(__DIR__ . '/../assets/cert-badge.png');

$flash = isset($_GET['flash']) ? urldecode((string)$_GET['flash']) : '';
[$flashType, $flashMsg] = $flash ? explode('|', $flash, 2) : ['', ''];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($docTitle) ?> &mdash; <?= htmlspecialchars($instituteName) ?></title>
<style>
  @import url('https://fonts.googleapis.com/css2?family=Cinzel:wght@700&family=Dancing+Script:wght@700&display=swap');
  @page { size: A4 landscape; margin: 0; }
  * { box-sizing: border-box; }
  html, body {
    margin: 0; padding: 0; background: #e2e8f0;
    font-family: Georgia, 'Times New Roman', serif;
  }

  /* ── Toolbar (screen only) ─────────────────────────────────────────── */
  .toolbar {
    position: sticky; top: 0; z-index: 100;
    display: flex; justify-content: space-between; align-items: center;
    background: #1a202c; color: #e2e8f0; padding: 10px 22px;
    font-family: Arial, Helvetica, sans-serif;
  }
  .toolbar a.btn-link { color: #cbd5e0; text-decoration: none; font-size: .85rem; margin-right: 16px; }
  .toolbar a.btn-download-all {
    color: #e2e8f0; background: #2d3748; border: 1px solid #4a5568;
    padding: 8px 14px; border-radius: 6px; font-size: .85rem; font-weight: 600;
    margin-right: 10px; white-space: nowrap;
  }
  .toolbar a.btn-download-all:hover { background: #3a4658; }
  .toolbar .toolbar-flash { color: #9ae6b4; font-size: .85rem; }
  .toolbar .toolbar-flash-warn { color: #fbd38d; font-weight: 700; }
  .toolbar .toolbar-count { color: #a0aec0; font-size: .85rem; margin-right: 16px; }
  .toolbar button.btn-print {
    background: #3182ce; color: #fff; border: none; padding: 9px 20px;
    border-radius: 6px; font-size: .88rem; font-weight: 700; cursor: pointer;
  }
  .toolbar button.btn-print:hover { background: #2b6cb0; }

  /* ── Certificate page ──────────────────────────────────────────────── */
  .cert-page {
    width: 297mm; height: 210mm; margin: 26px auto;
    background: #fff; position: relative; overflow: hidden;
    box-shadow: 0 6px 24px rgba(0,0,0,.2);
    page-break-after: always;
  }
  .cert-page:last-child { page-break-after: auto; }

  .cert-watermark {
    position: absolute; top: 50%; left: 50%; z-index: 5;
    transform: translate(-50%, -50%) rotate(-28deg);
    font-family: Arial, Helvetica, sans-serif;
    font-size: 64px; font-weight: 800; letter-spacing: 8px;
    color: #1a202c; opacity: .1; white-space: nowrap; pointer-events: none;
  }
  .cert-watermark-revoked { color: #dc2626; opacity: .16; }

  .cert-border { position: absolute; inset: 13mm; border: 2px solid #1a365d; }
  .cert-corner {
    position: absolute; width: 28px; height: 28px; border: 3px solid #d4af37;
  }
  .cert-corner-tl { top: -3px;  left: -3px;  border-right: none; border-bottom: none; }
  .cert-corner-tr { top: -3px;  right: -3px; border-left: none;  border-bottom: none; }
  .cert-corner-bl { bottom: -3px; left: -3px;  border-right: none; border-top: none; }
  .cert-corner-br { bottom: -3px; right: -3px; border-left: none;  border-top: none; }

  .cert-inner {
    position: relative; height: 100%; padding: 12mm 16mm; text-align: center;
    display: flex; flex-direction: column; align-items: center; justify-content: space-between;
  }

  .cert-seal { display: none; position: absolute; }

  /* ── Header band: logo + institute/tagline | gold divider | "Certificate of X" ─
     Redesigned as a horizontal row (logo and wordmark side-by-side, like a
     letterhead) instead of a stacked centered block. */
  .cert-header { display: flex; align-items: center; justify-content: center; gap: 20px; }
  .cert-header-brand { display: flex; align-items: center; gap: 10px; text-align: left; }
  .cert-header-logo { display: flex; align-items: center; }
  /* max-height raised from 64->80px for the bundled Riyatrix Systems lockup,
     which is a squarer icon-over-wordmark layout (~1.26:1) rather than the
     older wide horizontal lockup this box was originally sized for — at
     64px tall it rendered as a small ~80px-wide icon, undersized next to
     the kicker heading. max-width stays generous so a future wide custom
     upload still has room without being cropped by the height cap alone. */
  .cert-logo, img.cert-logo { display: block; max-height: 80px; max-width: 220px; height: auto; width: auto; }
  .cert-header-divider { width: 1px; align-self: stretch; min-height: 40px; background: linear-gradient(180deg, rgba(212,175,55,0) 0%, #d4af37 50%, rgba(212,175,55,0) 100%); }

  /* Decorative ribbon-medal seal (assets/cert-badge.png) — top-right corner.
     Sized up from 78px so the laurel wreath and star engraved on the medal
     stay legible instead of shrinking into an indistinct blob; shadow eased
     off so it doesn't further soften those fine edges. */
  .cert-badge-corner { position: absolute; top: 13mm; right: 15mm; width: 104px; height: auto; z-index: 6; filter: drop-shadow(0 2px 4px rgba(0,0,0,.22)); }

  .cert-institute { font-family: 'Cinzel', 'Trajan Pro', Georgia, serif; font-size: 15px; font-weight: 700; letter-spacing: 2.5px; text-transform: uppercase; color: #1a365d; line-height: 1.2; }
  .cert-tagline   { font-size: 8.5px; font-weight: 600; letter-spacing: 2px; text-transform: uppercase; color: #b7791f; margin-top: 3px; }
  .cert-kicker    { font-family: 'Cinzel', 'Trajan Pro', Georgia, serif; font-size: 30px; font-weight: 700; letter-spacing: 2px; text-transform: uppercase; color: #1a365d; text-align: left; }
  .cert-presented { font-size: 12px; color: #4a5568; text-transform: uppercase; letter-spacing: 2px; margin: 8px 0 2px; }
  .cert-name      { font-family: 'Dancing Script', 'Segoe Script', cursive; font-size: 58px; font-weight: 700; color: #b7791f; margin: 0 0 8px; line-height: 1.2; }
  .cert-illustration { display: block; margin: 8px auto 0; }
  .cert-text { font-size: 15px; color: #2d3748; max-width: 580px; line-height: 1.65; margin: 0 auto; }
  .cert-duration { font-size: 13px; color: #718096; }

  .cert-grade-row { margin-top: 16px; display: flex; gap: 16px; justify-content: center; align-items: center; }
  .cert-grade-badge { padding: 6px 20px; border-radius: 20px; font-weight: 700; font-size: 14px; letter-spacing: 1px; background: #fef3c7; color: #92400e; }
  .cert-score { font-size: 14px; color: #4a5568; font-weight: 600; }

  .cert-footer { display: flex; width: 100%; justify-content: space-between; align-items: flex-end; }
  .cert-footer-col { flex: 1; font-size: 11px; color: #4a5568; }
  .cert-line { width: 140px; border-top: 1px solid #a0aec0; margin: 0 auto 6px; }
  .cert-label { line-height: 1.4; }

  /* Digital signature — sits just above its line, like ink on paper. */
  .cert-signature-wrap { height: 36px; display: flex; align-items: flex-end; justify-content: center; margin-bottom: -6px; }
  .cert-signature { max-height: 42px; max-width: 140px; object-fit: contain; }
  .cert-sig-title { color: #4a5568; }
  .cert-no { font-weight: 700; font-size: 12.5px; letter-spacing: 1px; color: #1a365d; }
  .cert-verify { font-size: 11px; color: #718096; margin-top: 3px; }

  /* ── Theme: Classic Navy & Gold (completion) ───────────────────────── */
  .theme-navy_gold .cert-border { border-color: #1a365d; }
  .theme-navy_gold .cert-corner { border-color: #d4af37; }

  /* ── Theme: Elegant Teal (completion) — cleaner, sans-serif, no corners ─ */
  .theme-teal_modern { font-family: Arial, Helvetica, sans-serif; }
  .theme-teal_modern .cert-border { border-color: #0d9488; border-width: 1px; }
  .theme-teal_modern .cert-corner { display: none; }
  .theme-teal_modern .cert-institute,
  .theme-teal_modern .cert-kicker { color: #0f766e; }
  .theme-teal_modern .cert-kicker { letter-spacing: 6px; font-weight: 600; }
  .theme-teal_modern .cert-name { font-family: 'Dancing Script', Georgia, serif; color: #0f766e; }
  .theme-teal_modern .cert-grade-badge { background: #ccfbf1; color: #115e59; }
  .theme-teal_modern .cert-no { color: #0f766e; }

  /* ── Theme: Distinction Gold Seal (merit) — CSS medallion top-right ──── */
  .theme-gold_seal .cert-border { border-color: #92400e; }
  .theme-gold_seal .cert-corner { border-color: #b7791f; }
  .theme-gold_seal .cert-name { color: #92400e; }
  .theme-gold_seal .cert-grade-badge { background: #92400e; color: #fffbeb; }
  .theme-gold_seal .cert-seal {
    display: block; top: 16mm; right: 18mm; width: 92px; height: 92px; border-radius: 50%;
    background: radial-gradient(circle at 35% 30%, #fdf3c7 0%, #d4af37 55%, #92400e 100%);
    box-shadow: 0 3px 10px rgba(0,0,0,.3);
  }
  .theme-gold_seal .cert-seal::after {
    content: '\2605'; position: absolute; inset: 0; display: flex; align-items: center; justify-content: center;
    color: #fffbeb; font-size: 28px;
  }

  /* ── Theme: Scholar Navy Ribbon (merit) — CSS ribbon banner top-left ──── */
  .theme-navy_ribbon .cert-border { border-color: #1e3a5f; }
  .theme-navy_ribbon .cert-corner { border-color: #1e3a5f; }
  .theme-navy_ribbon .cert-name { color: #1e3a5f; }
  .theme-navy_ribbon .cert-grade-badge { background: #1e3a5f; color: #d4af37; }
  .theme-navy_ribbon .cert-seal {
    display: block; top: 10mm; left: -2mm; width: 130px; height: 34px;
    background: #1e3a5f; color: #d4af37; transform: rotate(-45deg) translateX(-22px);
    transform-origin: left top; text-align: center; line-height: 34px;
    font-family: Arial, Helvetica, sans-serif; font-size: 11px; font-weight: 700; letter-spacing: 1px;
  }
  .theme-navy_ribbon .cert-seal::before { content: 'MERIT'; }

  /* ── Word-template download card (no HTML/CSS artwork to render — the
     actual certificate is the filled .docx generated at issue time) ────── */
  .cert-download-card {
    height: 100%; display: flex; flex-direction: column; align-items: center;
    justify-content: center; gap: 14px; text-align: center; padding: 0 40px;
  }
  .cert-download-icon { font-size: 64px; line-height: 1; }
  .cert-download-title { font-family: 'Cinzel', 'Trajan Pro', Georgia, serif; font-size: 22px; font-weight: 700; color: #1a365d; }
  .cert-download-name { font-size: 17px; color: #2d3748; }
  .cert-download-course { font-size: 14px; color: #4a5568; }
  .cert-download-btn {
    display: inline-block; margin-top: 6px; padding: 11px 28px; border-radius: 8px;
    background: #1e3a5f; color: #fff; text-decoration: none; font-weight: 700; font-size: 14px;
    font-family: Arial, Helvetica, sans-serif;
  }
  .cert-download-btn:hover { background: #16283f; }
  .cert-download-missing { color: #dc2626; font-size: 13px; }

  /* ── Print ──────────────────────────────────────────────────────────── */
  @media print {
    .toolbar { display: none !important; }
    html, body { background: #fff; }
    .cert-page { margin: 0; box-shadow: none; }
  }
</style>
</head>
<body>

<div class="toolbar no-print">
  <div>
    <a href="javascript:history.back()" class="btn-link">&larr; Back</a>
    <?php if ($flashMsg):
      // A "Skipped: ..." suffix (Admin/GenerateCertificates.php) means at
      // least one certificate did NOT come out clean (e.g. a Word template
      // failed to fill) even though the overall flash is styled "success" —
      // split it out and flag it so that half of the message isn't lost in
      // pale green text on a dark toolbar.
      $skipPos = strpos($flashMsg, ' Skipped:');
      $flashHead = $skipPos !== false ? substr($flashMsg, 0, $skipPos) : $flashMsg;
      $flashTail = $skipPos !== false ? substr($flashMsg, $skipPos + 1) : '';
    ?>
      <span class="toolbar-flash"><?= htmlspecialchars($flashHead) ?></span>
      <?php if ($flashTail !== ''): ?>
        <span class="toolbar-flash toolbar-flash-warn">&#9888; <?= htmlspecialchars($flashTail) ?></span>
      <?php endif; ?>
    <?php endif; ?>
  </div>
  <div style="display:flex;align-items:center;">
    <span class="toolbar-count"><?= count($records) ?> certificate<?= count($records) === 1 ? '' : 's' ?></span>
    <?php if (count($wordBatchIds) > 1):
      $batchParam = implode(',', $wordBatchIds);
    ?>
      <a class="btn-link btn-download-all" href="certificate-download-batch.php?batch=<?= urlencode($batchParam) ?>&amp;format=docx">
        &#11015; All as Word (<?= count($wordBatchIds) ?>)
      </a>
      <a class="btn-link btn-download-all" href="certificate-download-batch.php?batch=<?= urlencode($batchParam) ?>&amp;format=pdf">
        &#11015; All as PDF
      </a>
    <?php endif; ?>
    <button type="button" class="btn-print" onclick="certPrint()">&#128424; Print / Save as PDF</button>
  </div>
</div>

<script>
  // The Cinzel/Dancing Script webfonts load asynchronously via the @import
  // above. If Print/Save-as-PDF fires before they're ready, the browser
  // silently substitutes a fallback serif for the certificate title and
  // signature-style name — document.fonts.ready lets us wait for the real
  // fonts first so what prints matches what's on screen.
  function certPrint() {
    if (document.fonts && document.fonts.ready) {
      document.fonts.ready.then(() => window.print());
    } else {
      window.print();
    }
  }
</script>

<?php foreach ($records as $rec):
    $d            = $rec['data'];
    $templateType = $d['TemplateType'] ?? 'coded';
    $isRevoked    = ($d['Status'] ?? 'Issued') === 'Revoked';
    $issueDateFmt = date('d M Y', strtotime((string)$d['IssueDate']));
?>
<?php if ($templateType === 'word'):
    // The actual certificate is the filled .docx produced at issue time
    // (Admin/GenerateCertificates.php) — nothing to render as HTML/CSS here,
    // just a card linking to the download.
?>
<div class="cert-page">
  <?php if ($rec['isPreview']): ?>
    <div class="cert-watermark">SAMPLE PREVIEW</div>
  <?php elseif ($isRevoked): ?>
    <div class="cert-watermark cert-watermark-revoked">REVOKED</div>
  <?php endif; ?>
  <div class="cert-border">
    <div class="cert-corner cert-corner-tl"></div>
    <div class="cert-corner cert-corner-tr"></div>
    <div class="cert-corner cert-corner-bl"></div>
    <div class="cert-corner cert-corner-br"></div>
    <div class="cert-download-card">
      <div class="cert-download-icon">&#128196;</div>
      <div class="cert-download-title">Certificate of <?= ($d['CertType'] ?? 'completion') === 'merit' ? 'Merit' : 'Completion' ?></div>
      <div class="cert-download-name"><?= htmlspecialchars((string)($d['StudentName'] ?? '')) ?></div>
      <div class="cert-download-course"><?= htmlspecialchars((string)($d['CourseName'] ?? '')) ?></div>
      <?php if (!empty($d['GeneratedFile'])): ?>
        <a class="cert-download-btn no-print" href="certificate-download.php?id=<?= (int)($d['CertificateId'] ?? 0) ?>">
          &#11015; Download Certificate (.docx)
        </a>
      <?php else: ?>
        <div class="cert-download-missing">The Word document for this certificate could not be found.</div>
      <?php endif; ?>
      <div class="cert-no"><?= htmlspecialchars((string)($d['CertificateNo'] ?? '')) ?></div>
    </div>
  </div>
</div>
<?php elseif ($templateType === 'image' && !empty($d['BackgroundImage'])):
    // ── Institute-uploaded background artwork + positioned placeholders ──
    // (Admin/CertificateTemplateDesign.php). Coordinates are stored on a
    // fixed 1122.52x793.70 reference canvas that is pixel-identical to this
    // .cert-page's 297mm x 210mm print size (mm↔px is a fixed CSS ratio), so
    // they can be used directly as left/top px with no conversion.
    $bgAbs = cert_resolve_abs((string)$d['BackgroundImage']);
    $bgUri = $bgAbs ? cert_data_uri($bgAbs) : null;
    $layout      = Certificate::decodeJsonArray($d['LayoutJson'] ?? null);
    $signatories = Certificate::decodeJsonArray($d['SignatoriesJson'] ?? null);
    // Same formatting Lib/WordTemplate.php's caller (Admin/GenerateCertificates.php)
    // uses to fill a Word-template certificate — kept in one place
    // (Certificate::fieldValues) so date/percentage formatting can't drift
    // between the two renderers.
    $fieldValues = Certificate::fieldValues($d);
?>
<div class="cert-page">
  <?php if ($rec['isPreview']): ?>
    <div class="cert-watermark">SAMPLE PREVIEW</div>
  <?php elseif ($isRevoked): ?>
    <div class="cert-watermark cert-watermark-revoked">REVOKED</div>
  <?php endif; ?>

  <?php if ($bgUri): ?>
    <img src="<?= htmlspecialchars($bgUri) ?>" alt=""
         style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;z-index:1;">
  <?php endif; ?>

  <?php foreach ($layout as $f):
      $key = $f['key'] ?? '';
      $val = $fieldValues[$key] ?? '';
      if ($val === '') continue;
      $align = in_array($f['align'] ?? '', ['left', 'center', 'right'], true) ? $f['align'] : 'center';
      $tx    = $align === 'center' ? '-50%' : ($align === 'right' ? '-100%' : '0');
  ?>
    <div style="position:absolute; left:<?= (float)($f['x'] ?? 0) ?>px; top:<?= (float)($f['y'] ?? 0) ?>px;
                transform:translate(<?= $tx ?>,-50%); text-align:<?= htmlspecialchars($align) ?>;
                font-size:<?= (float)($f['fontSize'] ?? 24) ?>px; color:<?= htmlspecialchars($f['color'] ?? '#1a202c') ?>;
                font-weight:<?= !empty($f['bold']) ? '700' : '400' ?>; white-space:nowrap; z-index:2;
                font-family:Georgia,'Times New Roman',serif;">
      <?= htmlspecialchars($val) ?>
    </div>
  <?php endforeach; ?>

  <?php foreach ($signatories as $s):
      $sAlign = in_array($s['align'] ?? '', ['left', 'center', 'right'], true) ? $s['align'] : 'center';
      $sTx    = $sAlign === 'center' ? '-50%' : ($sAlign === 'right' ? '-100%' : '0');
      $sigAbs = !empty($s['image']) ? cert_resolve_abs((string)$s['image']) : null;
      $sigUri = $sigAbs ? cert_data_uri($sigAbs) : null;
      $sigFs  = (float)($s['fontSize'] ?? 15);
  ?>
    <div style="position:absolute; left:<?= (float)($s['x'] ?? 0) ?>px; top:<?= (float)($s['y'] ?? 0) ?>px;
                transform:translate(<?= $sTx ?>,-50%); text-align:<?= htmlspecialchars($sAlign) ?>; width:220px; z-index:2;
                font-family:Georgia,'Times New Roman',serif;">
      <?php if ($sigUri): ?>
        <img src="<?= htmlspecialchars($sigUri) ?>" alt="Signature"
             style="max-height:46px;max-width:170px;display:block;margin:0 auto 4px;<?= $sAlign !== 'center' ? 'margin-left:0;margin-right:0;' : '' ?>">
      <?php endif; ?>
      <?php if (!empty($s['name'])): ?><div style="font-weight:700;font-size:<?= $sigFs ?>px;color:#1a202c;"><?= htmlspecialchars((string)$s['name']) ?></div><?php endif; ?>
      <?php if (!empty($s['title'])): ?><div style="font-size:<?= max(9, $sigFs - 3) ?>px;color:#4a5568;"><?= htmlspecialchars((string)$s['title']) ?></div><?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>
<?php else:
    $themeKey = $d['ThemeKey'] ?? 'navy_gold';
    if (!isset($themes[$themeKey])) $themeKey = 'navy_gold';
    $isMerit   = ($d['CertType'] ?? 'completion') === 'merit';
    // gold_seal / navy_ribbon already draw their own bespoke CSS medallion
    // in this same top-right corner — don't stack the image badge on top.
    $showBadgeSeal = ($certBadgeUri !== null) && !in_array($themeKey, ['gold_seal', 'navy_ribbon'], true);
?>
<div class="cert-page theme-<?= htmlspecialchars($themeKey) ?>">
  <?php if ($rec['isPreview']): ?>
    <div class="cert-watermark">SAMPLE PREVIEW</div>
  <?php elseif ($isRevoked): ?>
    <div class="cert-watermark cert-watermark-revoked">REVOKED</div>
  <?php endif; ?>

  <div class="cert-border">
    <div class="cert-corner cert-corner-tl"></div>
    <div class="cert-corner cert-corner-tr"></div>
    <div class="cert-corner cert-corner-bl"></div>
    <div class="cert-corner cert-corner-br"></div>
    <div class="cert-seal"></div>
    <?php if ($showBadgeSeal): ?>
      <img class="cert-badge-corner" src="<?= htmlspecialchars($certBadgeUri) ?>" alt="Award seal">
    <?php endif; ?>

    <div class="cert-inner">
      <div class="cert-header">
        <div class="cert-header-brand">
          <?php if ($certLogoHtml !== ''): ?><div class="cert-header-logo"><?= $certLogoHtml ?></div><?php endif; ?>
          <?php if (!$logoIsBundledLockup): ?>
          <div>
            <div class="cert-institute"><?= htmlspecialchars($instituteName) ?></div>
            <?php if ($instituteTagline !== ''): ?><div class="cert-tagline"><?= htmlspecialchars($instituteTagline) ?></div><?php endif; ?>
          </div>
          <?php endif; ?>
        </div>
        <div class="cert-header-divider"></div>
        <div class="cert-kicker">Certificate of <?= $isMerit ? 'Merit' : 'Completion' ?></div>
      </div>

      <div>
        <!-- Course-completion illustration: graduation cap with laurel branches -->
        <svg class="cert-illustration" viewBox="0 0 300 92" xmlns="http://www.w3.org/2000/svg" width="160" height="49" aria-hidden="true">
          <!-- Laurel left -->
          <path d="M32,46 C22,31 26,14 34,11 C32,26 31,40 32,46Z" fill="#d4af37" opacity="0.55"/>
          <path d="M27,46 C14,37 18,18 26,14 C26,29 26,42 27,46Z" fill="#d4af37" opacity="0.42"/>
          <path d="M22,46 C9,43 12,27 20,21 C22,34 22,42 22,46Z" fill="#d4af37" opacity="0.30"/>
          <path d="M32,52 C18,53 16,38 24,32 C28,42 30,48 32,52Z" fill="#d4af37" opacity="0.30"/>
          <path d="M34,60 C20,63 16,48 24,41 C28,52 32,57 34,60Z" fill="#d4af37" opacity="0.22"/>
          <!-- Laurel right (mirrored) -->
          <path d="M268,46 C278,31 274,14 266,11 C268,26 269,40 268,46Z" fill="#d4af37" opacity="0.55"/>
          <path d="M273,46 C286,37 282,18 274,14 C274,29 274,42 273,46Z" fill="#d4af37" opacity="0.42"/>
          <path d="M278,46 C291,43 288,27 280,21 C278,34 278,42 278,46Z" fill="#d4af37" opacity="0.30"/>
          <path d="M268,52 C282,53 284,38 276,32 C272,42 270,48 268,52Z" fill="#d4af37" opacity="0.30"/>
          <path d="M266,60 C280,63 284,48 276,41 C272,52 268,57 266,60Z" fill="#d4af37" opacity="0.22"/>
          <!-- Graduation cap board -->
          <polygon points="150,10 200,34 150,58 100,34" fill="#1a365d"/>
          <polygon points="150,10 200,34 150,46 100,34" fill="#243d7a" opacity="0.45"/>
          <!-- Cap brim/body -->
          <rect x="128" y="56" width="44" height="20" rx="3" fill="#1a365d"/>
          <rect x="131" y="59" width="38" height="14" rx="2" fill="#1e3a70" opacity="0.6"/>
          <!-- Tassel rope -->
          <line x1="200" y1="34" x2="200" y2="56" stroke="#d4af37" stroke-width="2.5" stroke-linecap="round"/>
          <!-- Tassel knot -->
          <circle cx="200" cy="57" r="3" fill="#d4af37"/>
          <!-- Tassel ends -->
          <line x1="200" y1="60" x2="193" y2="78" stroke="#d4af37" stroke-width="1.8" stroke-linecap="round"/>
          <line x1="200" y1="60" x2="200" y2="80" stroke="#d4af37" stroke-width="1.8" stroke-linecap="round"/>
          <line x1="200" y1="60" x2="207" y2="78" stroke="#d4af37" stroke-width="1.8" stroke-linecap="round"/>
          <!-- Gold button on top -->
          <circle cx="150" cy="10" r="5" fill="#d4af37"/>
          <!-- Sparkle stars -->
          <text x="50"  y="30" fill="#d4af37" font-size="13" opacity="0.60">★</text>
          <text x="225" y="28" fill="#d4af37" font-size="11" opacity="0.50">★</text>
          <text x="58"  y="62" fill="#d4af37" font-size="8"  opacity="0.35">★</text>
          <text x="220" y="60" fill="#d4af37" font-size="8"  opacity="0.35">★</text>
        </svg>
        <p class="cert-presented">This certificate is proudly presented to</p>
        <p class="cert-name"><?= htmlspecialchars($d['StudentName']) ?></p>
        <p class="cert-text">
          for successfully <?= $isMerit ? 'demonstrating excellence in' : 'completing the course' ?>
          <strong><?= htmlspecialchars($d['CourseName']) ?></strong>
          <?php if (!empty($d['Duration'])): ?><br><span class="cert-duration"><?= htmlspecialchars($d['Duration']) ?></span><?php endif; ?>
        </p>
        <?php if ($isMerit && !empty($d['Grade'])): ?>
        <div class="cert-grade-row">
          <span class="cert-grade-badge">Grade: <?= htmlspecialchars($d['Grade']) ?></span>
          <?php if ($d['Percentage'] !== null && $d['Percentage'] !== ''): ?>
            <span class="cert-score">Score: <?= Certificate::formatPercent((float)$d['Percentage']) ?>%</span>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>

      <div class="cert-footer">
        <div class="cert-footer-col">
          <div class="cert-label">Date<br><?= htmlspecialchars($issueDateFmt) ?></div>
        </div>
        <div class="cert-footer-col cert-footer-center">
          <div class="cert-no"><?= htmlspecialchars($d['CertificateNo']) ?></div>
          <div class="cert-verify">Verify at /exam/verify-certificate.php</div>
        </div>
        <div class="cert-footer-col">
          <?php if ($certSignatureHtml !== ''): ?>
            <div class="cert-signature-wrap"><?= $certSignatureHtml ?></div>
          <?php endif; ?>
          <div class="cert-line"></div>
          <div class="cert-label"><?= $signatoryName !== '' ? htmlspecialchars($signatoryName) : '&nbsp;' ?><br><span class="cert-sig-title"><?= htmlspecialchars($signatoryTitle) ?></span></div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>
<?php endforeach; ?>

</body>
</html>
