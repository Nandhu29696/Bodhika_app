<?php
/**
 * Admin/CertificateTemplates.php — manage certificate design templates.
 *
 * Three kinds of template, chosen at creation time:
 *   - Coded:  Name + CertType + a coded CSS ThemeKey
 *             (Lib/Certificate.php::availableThemes()) — global, usable by
 *             every institute. This is the original behaviour.
 *   - Image:  Name + CertType + an owning Institute + an uploaded background
 *             image (e.g. the institute's own letterhead/artwork). Only that
 *             institute's students can be issued this template
 *             (Admin/GenerateCertificates.php filters by Institution).
 *             Placeholder positions (student name, course, date, signatures)
 *             are configured afterwards in Admin/CertificateTemplateDesign.php.
 *   - Word:   Name + CertType + an owning Institute + an uploaded .docx with
 *             literal {{TOKEN}} placeholders in the running text. The file is
 *             scanned for tokens on upload (Lib/WordTemplate.php); the admin
 *             then maps each token to a system field or a custom
 *             batch-entered value in Admin/CertificateTemplateWordMap.php.
 *             Issuing produces one filled .docx per student — no HTML/CSS
 *             rendering involved (see exam/certificate-print.php's word branch).
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
require_once __DIR__ . '/../Lib/Certificate.php';
require_once __DIR__ . '/../Lib/WordTemplate.php';
Auth::requireLogin('../auth/login.php');
if (!Auth::isAdmin()) { header('Location: ../exam/search.php'); exit; }

$flash = '';

/* ── Handle POST ──────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::validateCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_template') {
        $name        = trim($_POST['Name'] ?? '');
        $certType    = in_array($_POST['CertType'] ?? '', ['completion', 'merit'], true) ? $_POST['CertType'] : '';
        $designType  = in_array($_POST['DesignType'] ?? '', ['coded', 'image', 'word'], true) ? $_POST['DesignType'] : 'coded';
        $themeKey    = $_POST['ThemeKey'] ?? '';
        $instituteId = (int)($_POST['InstituteId'] ?? 0);

        $errors = [];
        if ($name === '' || $certType === '') $errors[] = 'Please fill in the name and certificate type.';

        if ($designType === 'coded') {
            if (!array_key_exists($themeKey, Certificate::availableThemes())) {
                $errors[] = 'Please choose a valid design theme.';
            }
        } elseif ($designType === 'image') {
            if ($instituteId <= 0) $errors[] = 'Please choose the institute this template belongs to.';
            if (empty($_FILES['background_image']['tmp_name'])) {
                $errors[] = 'Please upload a background image for the certificate artwork.';
            }
        } else { // word
            if (!WordTemplate::zipSupported()) {
                $errors[] = "PHP's zip extension (ext-zip) is not available on this server, so Word templates can't be read or filled — ask your host to enable it, or use a Coded/Image template instead.";
            }
            if ($instituteId <= 0) $errors[] = 'Please choose the institute this template belongs to.';
            if (empty($_FILES['word_file']['tmp_name'])) {
                $errors[] = 'Please upload a .docx template.';
            }
        }

        $bgPath   = '';
        if (!$errors && $designType === 'image') {
            $file = $_FILES['background_image'];
            $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                $errors[] = 'Background image must be JPG, PNG, or WEBP.';
            } elseif ($file['size'] > 6 * 1024 * 1024) {
                $errors[] = 'Background image must be 6MB or smaller.';
            } else {
                $dir = __DIR__ . '/images/certificate/templates/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $fname = 'bg_' . uniqid('', true) . '.' . $ext;
                if (move_uploaded_file($file['tmp_name'], $dir . $fname)) {
                    $bgPath = 'images/certificate/templates/' . $fname;
                } else {
                    $errors[] = 'Failed to save the uploaded background image.';
                }
            }
        }

        $wordPath   = '';
        $wordTokens = [];
        if (!$errors && $designType === 'word') {
            $file = $_FILES['word_file'];
            $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if ($ext !== 'docx') {
                $errors[] = 'Word template must be a .docx file.';
            } elseif ($file['size'] > 4 * 1024 * 1024) {
                $errors[] = 'Word template must be 4MB or smaller.';
            } else {
                $dir = __DIR__ . '/certificates/word_templates/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $fname = 'tpl_' . uniqid('', true) . '.docx';
                if (move_uploaded_file($file['tmp_name'], $dir . $fname)) {
                    $wordPath   = 'certificates/word_templates/' . $fname;
                    $wordTokens = WordTemplate::extractPlaceholders($dir . $fname);
                } else {
                    $errors[] = 'Failed to save the uploaded Word template.';
                }
            }
        }

        if ($errors) {
            $flash = 'error|' . implode(' ', $errors);
        } else {
            try {
                if ($designType === 'coded') {
                    Database::execute(
                        "INSERT INTO certificate_templates (Name, CertType, ThemeKey, TemplateType, Active)
                         VALUES (?,?,?,'coded','Y')",
                        [$name, $certType, $themeKey]
                    );
                    $flash = 'success|Template "' . $name . '" added.';
                } elseif ($designType === 'image') {
                    Database::execute(
                        "INSERT INTO certificate_templates
                            (Name, CertType, InstituteId, TemplateType, BackgroundImage, LayoutJson, SignatoriesJson, Active)
                         VALUES (?,?,?,'image',?,'[]','[]','Y')",
                        [$name, $certType, $instituteId, $bgPath]
                    );
                    $newId = (int)Database::lastInsertId();
                    // Straight into the designer — an image template with no
                    // placeholder positions yet is useless, so skip the extra click.
                    header('Location: CertificateTemplateDesign.php?id=' . $newId
                         . '&flash=' . urlencode('success|Template "' . $name . '" created. Now place the fields below.'));
                    exit;
                } else { // word
                    Database::execute(
                        "INSERT INTO certificate_templates
                            (Name, CertType, InstituteId, TemplateType, WordFile, WordPlaceholders, Active)
                         VALUES (?,?,?,'word',?,?,'Y')",
                        [$name, $certType, $instituteId, $wordPath, json_encode($wordTokens)]
                    );
                    $newId = (int)Database::lastInsertId();
                    $mapFlash = $wordTokens
                        ? 'success|Template "' . $name . '" created — found ' . count($wordTokens) . ' placeholder(s). Now map them below.'
                        : 'error|Template "' . $name . '" created, but no {{TOKEN}} placeholders were found in the document — add some and re-upload, or map fields manually.';
                    header('Location: CertificateTemplateWordMap.php?id=' . $newId . '&flash=' . urlencode($mapFlash));
                    exit;
                }
            } catch (Exception $e) {
                $flash = 'error|Could not add template: ' . htmlspecialchars($e->getMessage());
            }
        }
    }

    if ($action === 'toggle_active') {
        $id = (int)($_POST['TemplateId'] ?? 0);
        try {
            Database::execute(
                "UPDATE certificate_templates SET Active = IF(Active='Y','N','Y') WHERE TemplateId = ?",
                [$id]
            );
            $flash = 'success|Template updated.';
        } catch (Exception $e) {
            $flash = 'error|Could not update template.';
        }
    }

    header('Location: CertificateTemplates.php?flash=' . urlencode($flash));
    exit;
}

if (isset($_GET['flash'])) $flash = urldecode($_GET['flash']);
[$flashType, $flashMsg] = $flash ? explode('|', $flash, 2) : ['', ''];

$templates  = Certificate::listTemplates(null, false, null, true); // every template, every institute, incl. inactive
$themes     = Certificate::availableThemes();
$institutes = Database::fetchAll("SELECT InstituteId, InstituteName FROM institutes ORDER BY InstituteName");
$instituteNames = [];
foreach ($institutes as $inst) $instituteNames[(int)$inst['InstituteId']] = $inst['InstituteName'];

$pageTitle = 'Certificate Templates';
$pageHead  = '<style>
  .dt-toggle { display:flex; gap:0; border:1px solid #cbd5e0; border-radius:6px; overflow:hidden; width:max-content; }
  .dt-toggle label { padding:7px 16px; font-size:.85rem; cursor:pointer; background:#fff; }
  .dt-toggle input { display:none; }
  .dt-toggle input:checked + span { font-weight:700; }
  .dt-toggle label:has(input:checked) { background:#1e3a5f; color:#fff; }
  .dt-pane { display:none; }
  .dt-pane.active { display:block; }
</style>';
include __DIR__ . '/../includes/header.php';
?>
<nav style="font-size:.85rem;color:#718096;margin-bottom:14px;">
  <a href="AppSettings.php?tab=certificate" style="color:#3182ce;text-decoration:none;">&#9881; Certificate Settings</a>
  <span style="margin:0 6px;">&rsaquo;</span>
  <span>Templates</span>
</nav>

<?php if ($flashMsg): ?>
<div class="alert alert-<?= $flashType==='success' ? 'success' : 'danger' ?>" style="margin-bottom:14px;">
  <?= htmlspecialchars($flashMsg) ?>
</div>
<?php endif; ?>

<div class="card" style="margin-bottom:16px;">
  <div class="card-header">&#127912; Add Template</div>
  <div class="card-body">
    <form method="post" enctype="multipart/form-data" id="addTemplateForm">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken()) ?>">
      <input type="hidden" name="action" value="add_template">

      <div class="form-row cols-3" style="margin-bottom:14px;">
        <div class="form-group">
          <label class="form-label">Template Name</label>
          <input type="text" class="form-control" name="Name" required placeholder="e.g. AI-IoT Trainer Certificate" maxlength="100">
        </div>
        <div class="form-group">
          <label class="form-label">Certificate Type</label>
          <select class="form-control" name="CertType" required>
            <option value="completion">Course Completion</option>
            <option value="merit">Merit (grade-based)</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Design Type</label>
          <div class="dt-toggle">
            <label><input type="radio" name="DesignType" value="coded" checked onchange="dtSwitch('coded')"><span>&#127912; Coded Theme</span></label>
            <label><input type="radio" name="DesignType" value="image" onchange="dtSwitch('image')"><span>&#128444; Custom Image</span></label>
            <label><input type="radio" name="DesignType" value="word" onchange="dtSwitch('word')"><span>&#128196; Word Template</span></label>
          </div>
        </div>
      </div>

      <div class="dt-pane active" id="dtPaneCoded">
        <div class="form-group" style="max-width:360px;">
          <label class="form-label">Design Theme</label>
          <select class="form-control" name="ThemeKey">
            <?php foreach ($themes as $key => $label): ?>
              <option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="dt-pane" id="dtPaneImage">
        <div class="form-row cols-2">
          <div class="form-group">
            <label class="form-label">Institute <small style="font-weight:400;color:var(--clr-text-muted);">(only this institute's students can use it)</small></label>
            <select class="form-control" name="InstituteId">
              <option value="0">— Choose institute —</option>
              <?php foreach ($institutes as $inst): ?>
                <option value="<?= (int)$inst['InstituteId'] ?>"><?= htmlspecialchars($inst['InstituteName']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Background Image <small style="font-weight:400;color:var(--clr-text-muted);">(JPG/PNG/WEBP, landscape ~297:210, up to 6MB)</small></label>
            <input type="file" class="form-control" name="background_image" accept="image/jpeg,image/png,image/webp">
          </div>
        </div>
        <p style="font-size:.8rem;color:#718096;margin:2px 0 0;">
          After saving, you'll place Student Name / Course / Date / signatures on top of this image.
        </p>
      </div>

      <div class="dt-pane" id="dtPaneWord">
        <div class="form-row cols-2">
          <div class="form-group">
            <label class="form-label">Institute <small style="font-weight:400;color:var(--clr-text-muted);">(only this institute's students can use it)</small></label>
            <select class="form-control" name="InstituteId">
              <option value="0">— Choose institute —</option>
              <?php foreach ($institutes as $inst): ?>
                <option value="<?= (int)$inst['InstituteId'] ?>"><?= htmlspecialchars($inst['InstituteName']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Word Template <small style="font-weight:400;color:var(--clr-text-muted);">(.docx, up to 4MB)</small></label>
            <input type="file" class="form-control" name="word_file" accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document">
          </div>
        </div>
        <p style="font-size:.8rem;color:#718096;margin:2px 0 0;">
          Write the document in Word (or any editor that saves .docx) with literal <code>{{TOKEN}}</code> placeholders
          anywhere in the text — e.g. <code>{{RECIPIENT_NAME}}</code>, <code>{{PROGRAM_NAME}}</code>, <code>{{START_DATE}}</code>.
          After saving, you'll map each detected token to a field below.
        </p>
      </div>

      <div class="form-group" style="margin-top:14px;">
        <button type="submit" class="btn btn-primary">&#10010; Add Template</button>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header">&#127942; All Templates (<?= count($templates) ?>)</div>
  <div class="tbl-wrap">
    <table class="tbl">
      <thead>
        <tr>
          <th>Name</th>
          <th>Type</th>
          <th>Theme / Institute</th>
          <th class="text-center">Status</th>
          <th class="text-center">Preview</th>
          <th class="text-center">Action</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$templates): ?>
        <tr><td colspan="6" style="text-align:center;color:var(--clr-text-muted);padding:24px;">No templates yet — add one above.</td></tr>
      <?php endif; ?>
      <?php foreach ($templates as $t):
        $tType   = $t['TemplateType'] ?? 'coded';
        $isImage = $tType === 'image';
        $isWord  = $tType === 'word';
      ?>
        <tr>
          <td><strong><?= htmlspecialchars($t['Name']) ?></strong></td>
          <td><?= $t['CertType'] === 'merit' ? '&#127942; Merit' : '&#127891; Completion' ?></td>
          <td>
            <?php if ($isImage): ?>
              &#127970; <?= htmlspecialchars($instituteNames[(int)$t['InstituteId']] ?? ('Institute #' . (int)$t['InstituteId'])) ?>
              <span style="color:#9ca3af;font-size:.78rem;">(image)</span>
            <?php elseif ($isWord): ?>
              &#128196; <?= htmlspecialchars($instituteNames[(int)$t['InstituteId']] ?? ('Institute #' . (int)$t['InstituteId'])) ?>
              <span style="color:#9ca3af;font-size:.78rem;">(<?= count(Certificate::decodeJsonArray($t['WordPlaceholders'] ?? null)) ?> field<?= count(Certificate::decodeJsonArray($t['WordPlaceholders'] ?? null)) === 1 ? '' : 's' ?>)</span>
            <?php else: ?>
              <?= htmlspecialchars($themes[$t['ThemeKey']] ?? $t['ThemeKey']) ?>
              <span style="color:#9ca3af;font-size:.78rem;">(global)</span>
            <?php endif; ?>
          </td>
          <td class="text-center">
            <span class="badge-<?= $t['Active']==='Y' ? 'pass' : 'fail' ?>">
              <?= $t['Active']==='Y' ? 'Active' : 'Inactive' ?>
            </span>
          </td>
          <td class="text-center">
            <?php if ($isWord): ?>
              <a href="../exam/certificate-print.php?preview=1&amp;templateId=<?= (int)$t['TemplateId'] ?>"
                 class="btn btn-secondary btn-sm">&#11015; Sample .docx</a>
            <?php else: ?>
              <a href="../exam/certificate-print.php?preview=1&amp;templateId=<?= (int)$t['TemplateId'] ?>"
                 target="_blank" class="btn btn-secondary btn-sm">&#128065; Preview</a>
            <?php endif; ?>
          </td>
          <td class="text-center" style="white-space:nowrap;">
            <?php if ($isImage): ?>
              <a href="CertificateTemplateDesign.php?id=<?= (int)$t['TemplateId'] ?>" class="btn btn-secondary btn-sm">&#127912; Design</a>
            <?php elseif ($isWord): ?>
              <a href="CertificateTemplateWordMap.php?id=<?= (int)$t['TemplateId'] ?>" class="btn btn-secondary btn-sm">&#128279; Map Fields</a>
            <?php endif; ?>
            <form method="post" style="display:inline;">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken()) ?>">
              <input type="hidden" name="action" value="toggle_active">
              <input type="hidden" name="TemplateId" value="<?= (int)$t['TemplateId'] ?>">
              <button type="submit" class="btn btn-<?= $t['Active']==='Y' ? 'danger' : 'success' ?> btn-sm">
                <?= $t['Active']==='Y' ? 'Deactivate' : 'Activate' ?>
              </button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
function dtSwitch(kind) {
  document.getElementById('dtPaneCoded').classList.toggle('active', kind === 'coded');
  document.getElementById('dtPaneImage').classList.toggle('active', kind === 'image');
  document.getElementById('dtPaneWord').classList.toggle('active', kind === 'word');
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
