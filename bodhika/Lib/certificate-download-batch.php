<?php
/**
 * exam/certificate-download-batch.php — download every TemplateType='word'
 * certificate in a batch (Admin/GenerateCertificates.php's ?batch=1,2,3
 * redirect) as ONE combined file, instead of one .docx per student.
 *
 *   ?batch=1,2,3&format=docx  Single .docx, one certificate per page
 *                             (Lib/WordTemplate.php::mergeDocuments).
 *   ?batch=1,2,3&format=pdf   Same, converted to PDF via a LibreOffice/
 *                             OpenOffice binary if one is available on this
 *                             server (Lib/WordTemplate.php::tryConvertToPdf).
 *                             Most shared hosting has neither LibreOffice
 *                             nor shell_exec, so this fails soft with a
 *                             clear explanation rather than a broken file —
 *                             the .docx is always the reliable fallback
 *                             (every major OS/Office/Google Docs opens it
 *                             and can "Save/Print as PDF" from there).
 *
 * Admin-only, matching certificate-print.php's ?batch= mode (a batch spans
 * multiple students, so there's no single "owner" who could view it).
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
require_once __DIR__ . '/../Lib/Certificate.php';
require_once __DIR__ . '/../Lib/WordTemplate.php';
Auth::requireLogin('../auth/login.php');

function certbatch_fail(string $message, int $code = 404): void
{
    http_response_code($code);
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Certificates</title>'
       . '<style>body{font-family:Georgia,serif;background:#edf2f7;display:flex;'
       . 'align-items:center;justify-content:center;height:100vh;margin:0;}'
       . '.box{background:#fff;padding:32px 40px;border-radius:10px;'
       . 'box-shadow:0 2px 16px rgba(0,0,0,.1);text-align:center;max-width:460px;}'
       . '.box h2{margin:0 0 10px;color:#dc2626;font-size:1.2rem;}'
       . '.box a{color:#3182ce;text-decoration:none;font-size:.9rem;}</style></head><body>'
       . '<div class="box"><h2>&#9888; ' . htmlspecialchars($message) . '</h2>'
       . '<p><a href="javascript:history.back()">&larr; Go back</a></p></div>'
       . '</body></html>';
    exit;
}

function certbatch_safe_filename(string $s): string
{
    $s = preg_replace('/[\\\\\/:*?"<>|]+/', ' ', trim($s));
    $s = preg_replace('/\s+/', ' ', $s);
    return trim((string)$s);
}

if (!Auth::isAdmin()) certbatch_fail('You do not have permission to download this batch.', 403);

$format = ($_GET['format'] ?? 'docx') === 'pdf' ? 'pdf' : 'docx';

$ids = array_values(array_unique(array_filter(
    array_map('intval', explode(',', (string)($_GET['batch'] ?? ''))),
    fn($v) => $v > 0
)));
if (!$ids) certbatch_fail('No certificates specified.');
$ids = array_slice($ids, 0, 200);

$paths = [];
foreach ($ids as $cid) {
    $cert = Certificate::findById($cid);
    if (!$cert) continue;
    if (($cert['TemplateType'] ?? 'coded') !== 'word') continue; // coded/image certs use the browser Print instead
    if (empty($cert['GeneratedFile'])) continue;
    $abs = __DIR__ . '/../Admin/' . $cert['GeneratedFile'];
    if (is_file($abs)) $paths[] = $abs;
}
if (!$paths) {
    certbatch_fail('None of the certificates in this batch have a generated Word document to combine.');
}

$tmpDir = sys_get_temp_dir();
$mergedPath = $tmpDir . '/certbatch_' . uniqid('', true) . '.docx';

$mergeError = null;
if (!WordTemplate::mergeDocuments($paths, $mergedPath, $mergeError)) {
    certbatch_fail('Could not combine the certificates into one document.' . ($mergeError ? ' (' . $mergeError . ')' : ''));
}

$downloadName = certbatch_safe_filename('Certificates - Batch of ' . count($paths));

if ($format === 'pdf') {
    $pdfPath = WordTemplate::tryConvertToPdf($mergedPath, $tmpDir);
    if ($pdfPath !== null) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $downloadName . '.pdf"');
        header('Content-Length: ' . filesize($pdfPath));
        header('X-Content-Type-Options: nosniff');
        readfile($pdfPath);
        @unlink($pdfPath);
        @unlink($mergedPath);
        exit;
    }
    // No LibreOffice/soffice available on this server — fail soft with a
    // clear explanation instead of silently serving the wrong file type.
    @unlink($mergedPath);
    certbatch_fail(
        'PDF conversion is not available on this server (no LibreOffice/soffice found). '
        . 'Use "Download All (.docx)" instead, then use Word, Google Docs, or LibreOffice\'s '
        . '"Save as PDF" / "Print to PDF" to convert it.'
    );
}

header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="' . $downloadName . '.docx"');
header('Content-Length: ' . filesize($mergedPath));
header('X-Content-Type-Options: nosniff');
readfile($mergedPath);
@unlink($mergedPath);
