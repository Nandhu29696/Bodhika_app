<?php
/**
 * exam/certificate-download.php — stream a TemplateType='word' certificate's
 * filled .docx (certificates.GeneratedFile, produced by
 * Admin/GenerateCertificates.php via Lib/WordTemplate.php::fillTemplate).
 *
 * Split out from exam/certificate-print.php because that page renders HTML
 * (a "Download Certificate" card, for word-template records) — this is the
 * actual file byte-stream the card's button links to. Same admin-or-owner
 * authorization rule as certificate-print.php?id=N, deliberately duplicated
 * rather than shared, since it's three lines and pulling it into a helper
 * for one caller isn't worth the indirection.
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
require_once __DIR__ . '/../Lib/Certificate.php';
Auth::requireLogin('../auth/login.php');

function certdl_fail(string $message, int $code = 404): void
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $message;
    exit;
}

$certificateId = (int)($_GET['id'] ?? 0);
$cert = $certificateId > 0 ? Certificate::findById($certificateId) : null;
if (!$cert) certdl_fail('Certificate not found.');

$isOwner = (int)$cert['UserInfoId'] === Auth::currentUserId();
if (!Auth::isAdmin() && !$isOwner) certdl_fail('You do not have permission to download this certificate.', 403);

if (empty($cert['GeneratedFile'])) certdl_fail('No document has been generated for this certificate.');

$absPath = __DIR__ . '/../Admin/' . $cert['GeneratedFile'];
if (!is_file($absPath)) certdl_fail('The certificate document is missing on the server.');

function certdl_safe_filename(string $s): string
{
    $s = preg_replace('/[\\\\\/:*?"<>|]+/', ' ', trim($s));
    $s = preg_replace('/\s+/', ' ', $s);
    return trim((string)$s);
}

$downloadName = certdl_safe_filename((string)($cert['StudentName'] ?? 'Certificate'))
              . ' - Certificate' . ($cert['CertificateNo'] ? ' ' . $cert['CertificateNo'] : '') . '.docx';

header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Content-Length: ' . filesize($absPath));
header('X-Content-Type-Options: nosniff');
readfile($absPath);
