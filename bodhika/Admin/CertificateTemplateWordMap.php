<?php
/**
 * Admin/CertificateTemplateWordMap.php — map a Word template's {{TOKEN}}
 * placeholders to certificate data (TemplateType='word' rows created by
 * Admin/CertificateTemplates.php).
 *
 * Every token detected in the uploaded .docx (Lib/WordTemplate.php::extractPlaceholders,
 * stored as WordPlaceholders at upload time) gets mapped to one of two things:
 *   - a system field from Certificate::placeholderFields() (StudentName,
 *     CourseName, Duration, IssueDate, CertificateNo, Grade, Percentage) —
 *     resolved per-student automatically at issue time, same catalog the
 *     image-template designer uses; or
 *   - a custom field — typed once per batch in Admin/GenerateCertificates.php
 *     (e.g. a training program's START_DATE/END_DATE, which aren't part of
 *     the fixed catalog and don't vary per student within one issue run) and
 *     snapshotted into certificates.ExtraFields at issue time.
 * The result is stored as WordFieldMap; Admin/GenerateCertificates.php reads
 * it to know which inputs to render, and Lib/WordTemplate.php::fillTemplate
 * receives the resolved token=>value pairs built from it.
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
require_once __DIR__ . '/../Lib/Certificate.php';
require_once __DIR__ . '/../Lib/WordTemplate.php';
Auth::requireLogin('../auth/login.php');
if (!Auth::isAdmin()) { header('Location: ../exam/search.php'); exit; }

$id  = (int)($_GET['id'] ?? $_POST['TemplateId'] ?? 0);
$tpl = $id > 0 ? Certificate::getTemplate($id) : null;
if (!$tpl || ($tpl['TemplateType'] ?? 'coded') !== 'word') {
    header('Location: CertificateTemplates.php?flash=' . urlencode('error|Template not found, or it is not a Word template.'));
    exit;
}

/** "START_DATE" -> "Start Date" — used as the default custom-field label. */
function wordmap_humanize(string $token): string
{
    $words = array_filter(explode('_', $token));
    return implode(' ', array_map(fn($w) => ucfirst(strtolower($w)), $words)) ?: $token;
}

$flash = '';

/* ── Handle POST — save mapping ──────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::validateCsrf();

    $tokens   = Certificate::decodeJsonArray($tpl['WordPlaceholders'] ?? null);
    $catalog  = Certificate::placeholderFields();
    $mapType  = $_POST['map_type']  ?? [];
    $mapField = $_POST['map_field'] ?? [];
    $mapLabel = $_POST['map_label'] ?? [];

    $fieldMap = [];
    $errors   = [];
    foreach ($tokens as $token) {
        $type = ($mapType[$token] ?? '') === 'system' ? 'system' : 'custom';
        if ($type === 'system') {
            $field = (string)($mapField[$token] ?? '');
            if (!array_key_exists($field, $catalog)) {
                $errors[] = "\"$token\" — please choose a valid system field.";
                continue;
            }
            $fieldMap[$token] = ['type' => 'system', 'field' => $field];
        } else {
            $label = trim((string)($mapLabel[$token] ?? ''));
            if ($label === '') $label = wordmap_humanize($token);
            $fieldMap[$token] = ['type' => 'custom', 'label' => $label];
        }
    }

    if ($errors) {
        $flash = 'error|' . implode(' ', $errors);
    } else {
        try {
            Database::execute(
                "UPDATE certificate_templates SET WordFieldMap = ? WHERE TemplateId = ?",
                [json_encode($fieldMap), $id]
            );
            $flash = 'success|Field mapping saved.';
        } catch (Exception $e) {
            $flash = 'error|Could not save mapping: ' . $e->getMessage();
        }
    }

    header('Location: CertificateTemplateWordMap.php?id=' . $id . '&flash=' . urlencode($flash));
    exit;
}

if (isset($_GET['flash'])) $flash = urldecode($_GET['flash']);
[$flashType, $flashMsg] = $flash ? explode('|', $flash, 2) : ['', ''];

$tokens      = Certificate::decodeJsonArray($tpl['WordPlaceholders'] ?? null);
$savedMap    = Certificate::decodeJsonArray($tpl['WordFieldMap'] ?? null);
$catalog     = Certificate::placeholderFields();

// Best-effort auto-suggestion for tokens with no saved mapping yet, so a
// freshly-uploaded template doesn't show every row defaulting to the same
// first catalog option — nudges the common cases (…NAME -> StudentName,
// …PROGRAM/COURSE… -> CourseName, …DATE… -> IssueDate) toward something
// plausible; the admin can always change it before saving.
function wordmap_autosuggest(string $token): array
{
    $t = strtoupper($token);
    if (str_contains($t, 'NAME'))                                    return ['type' => 'system', 'field' => 'StudentName'];
    if (str_contains($t, 'PROGRAM') || str_contains($t, 'COURSE'))   return ['type' => 'system', 'field' => 'CourseName'];
    if (str_contains($t, 'CERT') && str_contains($t, 'NO'))          return ['type' => 'system', 'field' => 'CertificateNo'];
    if ($t === 'ISSUE_DATE' || $t === 'DATE')                        return ['type' => 'system', 'field' => 'IssueDate'];
    if (str_contains($t, 'GRADE'))                                   return ['type' => 'system', 'field' => 'Grade'];
    if (str_contains($t, 'PERCENT') || str_contains($t, 'SCORE'))    return ['type' => 'system', 'field' => 'Percentage'];
    if (str_contains($t, 'DURATION'))                                return ['type' => 'system', 'field' => 'Duration'];
    return ['type' => 'custom', 'label' => wordmap_humanize($token)];
}

$pageTitle = 'Map Fields: ' . $tpl['Name'];
$pageHead  = '<style>
  .wm-row { display:flex; align-items:center; gap:12px; padding:10px 12px; border-bottom:1px solid #edf2f7; flex-wrap:wrap; }
  .wm-row:last-child { border-bottom:none; }
  .wm-token { font-family:monospace; font-size:.86rem; font-weight:700; color:#1e3a5f; background:#eef2f7; padding:3px 8px; border-radius:4px; min-width:170px; }
  .wm-row select, .wm-row input[type=text] { padding:5px 8px; border:1px solid #cbd5e0; border-radius:5px; font-size:.85rem; }
</style>';
include __DIR__ . '/../includes/header.php';
?>
<nav style="font-size:.85rem;color:#718096;margin-bottom:14px;">
  <a href="AppSettings.php?tab=certificate" style="color:#3182ce;text-decoration:none;">&#9881; Certificate Settings</a>
  <span style="margin:0 6px;">&rsaquo;</span>
  <a href="CertificateTemplates.php" style="color:#3182ce;text-decoration:none;">Templates</a>
  <span style="margin:0 6px;">&rsaquo;</span>
  <span>Map Fields: <?= htmlspecialchars($tpl['Name']) ?></span>
</nav>

<?php if ($flashMsg): ?>
<div class="alert alert-<?= $flashType==='success' ? 'success' : 'danger' ?>" style="margin-bottom:14px;">
  <?= htmlspecialchars($flashMsg) ?>
</div>
<?php endif; ?>

<?php if (!$tokens): ?>
  <div class="alert alert-warning">
    No <code>{{TOKEN}}</code> placeholders were detected in this document. Edit the .docx to add some
    (e.g. <code>{{RECIPIENT_NAME}}</code>) and create the template again — the uploaded file itself can't
    be replaced here.
  </div>
<?php else: ?>

<div class="card" style="margin-bottom:16px;">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
    <span>&#128279; <?= count($tokens) ?> Placeholder<?= count($tokens) === 1 ? '' : 's' ?> Found</span>
    <a href="../exam/certificate-print.php?preview=1&amp;templateId=<?= (int)$id ?>" class="btn btn-secondary btn-sm">
      &#11015; Download Sample .docx
    </a>
  </div>
  <div class="card-body" style="padding:0;">
    <form method="post" id="wordMapForm">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken()) ?>">
      <input type="hidden" name="TemplateId" value="<?= (int)$id ?>">

      <?php foreach ($tokens as $token):
          $saved = $savedMap[$token] ?? wordmap_autosuggest($token);
          $isSystem = ($saved['type'] ?? 'custom') === 'system';
          $sysField = $isSystem ? ($saved['field'] ?? '') : '';
          $label    = !$isSystem ? ($saved['label'] ?? wordmap_humanize($token)) : wordmap_humanize($token);
          $rid      = 'row_' . preg_replace('/[^A-Za-z0-9_]/', '_', $token);
      ?>
        <div class="wm-row">
          <span class="wm-token">{{<?= htmlspecialchars($token) ?>}}</span>
          <label style="display:flex;align-items:center;gap:4px;font-size:.85rem;">
            <input type="radio" name="map_type[<?= htmlspecialchars($token) ?>]" value="system" <?= $isSystem ? 'checked' : '' ?>
                   onchange="wmToggle('<?= $rid ?>', true)"> System field
          </label>
          <select name="map_field[<?= htmlspecialchars($token) ?>]" id="<?= $rid ?>_field" <?= $isSystem ? '' : 'disabled' ?>>
            <?php foreach ($catalog as $key => $flabel): ?>
              <option value="<?= htmlspecialchars($key) ?>" <?= $sysField === $key ? 'selected' : '' ?>><?= htmlspecialchars($flabel) ?></option>
            <?php endforeach; ?>
          </select>

          <label style="display:flex;align-items:center;gap:4px;font-size:.85rem;margin-left:14px;">
            <input type="radio" name="map_type[<?= htmlspecialchars($token) ?>]" value="custom" <?= $isSystem ? '' : 'checked' ?>
                   onchange="wmToggle('<?= $rid ?>', false)"> Custom field, typed once per batch —
          </label>
          <input type="text" name="map_label[<?= htmlspecialchars($token) ?>]" id="<?= $rid ?>_label"
                 value="<?= htmlspecialchars($label) ?>" placeholder="Label shown when issuing"
                 <?= $isSystem ? 'disabled' : '' ?> style="min-width:160px;">
        </div>
      <?php endforeach; ?>

      <div style="padding:14px 16px;border-top:1px solid #e2e8f0;background:#f7fafc;">
        <button type="submit" class="btn btn-primary" style="font-weight:700;">&#128190; Save Mapping</button>
        <a href="CertificateTemplates.php" class="btn btn-secondary">&larr; Back to Templates</a>
      </div>
    </form>
  </div>
</div>

<p style="font-size:.82rem;color:#718096;">
  System fields are resolved per student automatically when certificates are issued
  (Admin/GenerateCertificates.php). Custom fields are typed once for the whole batch —
  useful for things like a training program's start/end dates that don't vary per student.
</p>

<script>
function wmToggle(rid, isSystem) {
  document.getElementById(rid + '_field').disabled = !isSystem;
  document.getElementById(rid + '_label').disabled = isSystem;
}
</script>

<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
