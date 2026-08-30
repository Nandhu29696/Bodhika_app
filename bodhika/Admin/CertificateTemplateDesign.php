<?php
/**
 * Admin/CertificateTemplateDesign.php — place placeholder fields + signature
 * blocks on top of an uploaded certificate background image
 * (TemplateType='image' rows created by Admin/CertificateTemplates.php).
 *
 * Positions are stored in LayoutJson / SignatoriesJson as coordinates on a
 * fixed 1122.52 x 793.70 reference canvas — the exact CSS-pixel size of the
 * 297mm x 210mm A4-landscape page exam/certificate-print.php prints onto
 * (1mm = 96/25.4 px, a fixed CSS unit conversion, not display-DPI
 * dependent). The on-screen canvas here is shown smaller and scaled, but
 * every position is converted back to that reference frame before saving,
 * so a field placed here lands in the exact same spot on the printed
 * certificate regardless of the uploaded image's native resolution.
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
require_once __DIR__ . '/../Lib/Certificate.php';
Auth::requireLogin('../auth/login.php');
if (!Auth::isAdmin()) { header('Location: ../exam/search.php'); exit; }

const REF_W = 1122.52; // 297mm @ 96dpi
const REF_H = 793.70;  // 210mm @ 96dpi

$id  = (int)($_GET['id'] ?? $_POST['TemplateId'] ?? 0);
$tpl = $id > 0 ? Certificate::getTemplate($id) : null;
if (!$tpl || ($tpl['TemplateType'] ?? 'coded') !== 'image') {
    header('Location: CertificateTemplates.php?flash=' . urlencode('error|Template not found, or it is not an image-based template.'));
    exit;
}

$flash = '';

/* ── Handle POST — save layout ───────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::validateCsrf();

    $bgPath = (string)$tpl['BackgroundImage'];
    if (!empty($_FILES['background_image']['tmp_name'])) {
        $file = $_FILES['background_image'];
        $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $flash = 'error|Background image must be JPG, PNG, or WEBP.';
        } elseif ($file['size'] > 6 * 1024 * 1024) {
            $flash = 'error|Background image must be 6MB or smaller.';
        } else {
            $dir = __DIR__ . '/images/certificate/templates/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $fname = 'bg_' . uniqid('', true) . '.' . $ext;
            if (move_uploaded_file($file['tmp_name'], $dir . $fname)) {
                if ($bgPath && str_starts_with($bgPath, 'images/certificate/')) @unlink(__DIR__ . '/' . $bgPath);
                $bgPath = 'images/certificate/templates/' . $fname;
            } else {
                $flash = 'error|Failed to save the uploaded background image.';
            }
        }
    }

    if ($flash === '') {
        /* Placeholder fields — keyed by Certificate::placeholderFields(), so
           every row lines up with $_POST['field_*'][$key] regardless of which
           checkboxes were ticked. */
        $catalog  = Certificate::placeholderFields();
        $enabled  = $_POST['field_enabled']  ?? [];
        $fx       = $_POST['field_x']        ?? [];
        $fy       = $_POST['field_y']        ?? [];
        $ffs      = $_POST['field_fontsize'] ?? [];
        $fcolor   = $_POST['field_color']    ?? [];
        $fbold    = $_POST['field_bold']     ?? [];
        $falign   = $_POST['field_align']    ?? [];

        $layout = [];
        foreach ($catalog as $key => $label) {
            if (empty($enabled[$key])) continue;
            $color = (string)($fcolor[$key] ?? '#1a202c');
            $layout[] = [
                'key'      => $key,
                'x'        => round((float)($fx[$key] ?? (REF_W / 2)), 1),
                'y'        => round((float)($fy[$key] ?? (REF_H / 2)), 1),
                'fontSize' => max(6, min(200, (int)($ffs[$key] ?? 28))),
                'color'    => preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : '#1a202c',
                'bold'     => !empty($fbold[$key]),
                'align'    => in_array($falign[$key] ?? '', ['left', 'center', 'right'], true) ? $falign[$key] : 'center',
            ];
        }

        /* Signatories — dynamic rows, correlated purely by array position
           (every sig_* / signatory_* array is appended-to and removed-from
           together client-side, so index i always refers to the same block). */
        $sigNames    = $_POST['sig_name']                 ?? [];
        $sigTitles   = $_POST['sig_title']                ?? [];
        $sigX        = $_POST['sig_x']                    ?? [];
        $sigY        = $_POST['sig_y']                    ?? [];
        $sigFs       = $_POST['sig_fontsize']              ?? [];
        $sigAlign    = $_POST['sig_align']                ?? [];
        $sigExisting = $_POST['signatory_existing_image']  ?? [];
        $sigRemove   = $_POST['sig_remove_image']          ?? [];
        $sigFiles    = $_FILES['signatory_image']          ?? null;

        $signatories = [];
        for ($i = 0, $n = count($sigNames); $i < $n; $i++) {
            $name  = trim((string)($sigNames[$i]  ?? ''));
            $title = trim((string)($sigTitles[$i] ?? ''));
            if ($name === '' && $title === '') continue; // skip a fully-blank row

            $imagePath = (string)($sigExisting[$i] ?? '');
            if (($sigRemove[$i] ?? '0') === '1' && $imagePath !== '') {
                if (str_starts_with($imagePath, 'images/certificate/')) @unlink(__DIR__ . '/' . $imagePath);
                $imagePath = '';
            }
            if (!empty($sigFiles['tmp_name'][$i])) {
                $ext = strtolower(pathinfo($sigFiles['name'][$i], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) && $sigFiles['size'][$i] <= 2 * 1024 * 1024) {
                    $dir = __DIR__ . '/images/certificate/templates/';
                    if (!is_dir($dir)) mkdir($dir, 0755, true);
                    $fn = 'sig_' . uniqid('', true) . '.' . $ext;
                    if (move_uploaded_file($sigFiles['tmp_name'][$i], $dir . $fn)) {
                        if ($imagePath !== '' && str_starts_with($imagePath, 'images/certificate/')) @unlink(__DIR__ . '/' . $imagePath);
                        $imagePath = 'images/certificate/templates/' . $fn;
                    }
                }
            }

            $signatories[] = [
                'name'     => $name,
                'title'    => $title,
                'x'        => round((float)($sigX[$i] ?? (REF_W / 2)), 1),
                'y'        => round((float)($sigY[$i] ?? (REF_H - 70)), 1),
                'fontSize' => max(8, min(60, (int)($sigFs[$i] ?? 15))),
                'align'    => in_array($sigAlign[$i] ?? '', ['left', 'center', 'right'], true) ? $sigAlign[$i] : 'center',
                'image'    => $imagePath,
            ];
        }

        try {
            Database::execute(
                "UPDATE certificate_templates SET BackgroundImage = ?, LayoutJson = ?, SignatoriesJson = ? WHERE TemplateId = ?",
                [$bgPath, json_encode($layout), json_encode($signatories), $id]
            );
            $flash = 'success|Layout saved.';
        } catch (Exception $e) {
            $flash = 'error|Could not save layout: ' . $e->getMessage();
        }
    }

    header('Location: CertificateTemplateDesign.php?id=' . $id . '&flash=' . urlencode($flash));
    exit;
}

if (isset($_GET['flash'])) $flash = urldecode($_GET['flash']);
[$flashType, $flashMsg] = $flash ? explode('|', $flash, 2) : ['', ''];

$catalog     = Certificate::placeholderFields();
$layoutByKey = [];
foreach (Certificate::decodeJsonArray($tpl['LayoutJson']) as $f) {
    if (!empty($f['key'])) $layoutByKey[$f['key']] = $f;
}
$signatories = Certificate::decodeJsonArray($tpl['SignatoriesJson']);

// Sample values shown on the designer chips — same dummy data
// exam/certificate-print.php's ?preview=1 mode uses, so what you see here
// matches the Preview button.
$sampleValues = [
    'StudentName'   => 'Jordan A. Smith',
    'CourseName'    => 'Full-Stack Web Development',
    'Duration'      => '12 Weeks',
    'IssueDate'     => date('d M Y'),
    'CertificateNo' => 'CERT-PREVIEW-0000',
    'Grade'         => 'Distinction',
    'Percentage'    => '92%',
];

$pageTitle = 'Design: ' . $tpl['Name'];
$pageHead  = '<style>
  #designerWrap { overflow-x:auto; padding-bottom:8px; }
  #canvasInner {
    position:relative; background:#e2e8f0; border:1px solid #cbd5e0;
    background-size:cover; background-position:center;
  }
  .design-chip {
    position:absolute; white-space:nowrap; cursor:move; user-select:none;
    padding:2px 4px; border:1px dashed transparent; border-radius:3px;
  }
  .design-chip:hover { border-color:#3182ce; background:rgba(49,130,206,.08); }
  .field-row, .sig-row {
    display:flex; align-items:center; gap:10px; flex-wrap:wrap;
    padding:8px 10px; border-bottom:1px solid #edf2f7; font-size:.83rem;
  }
  .field-row:last-child, .sig-row:last-child { border-bottom:none; }
  .field-row label, .sig-row label { display:flex; align-items:center; gap:4px; white-space:nowrap; }
  .field-row input[type=text], .sig-row input[type=text] { padding:4px 6px; border:1px solid #cbd5e0; border-radius:4px; }
  .sig-row input[type=file] { max-width:150px; font-size:.78rem; }
  .sig-thumb { height:24px; border-radius:3px; border:1px solid #cbd5e0; }
</style>';
include __DIR__ . '/../includes/header.php';
?>
<nav style="font-size:.85rem;color:#718096;margin-bottom:14px;">
  <a href="AppSettings.php?tab=certificate" style="color:#3182ce;text-decoration:none;">&#9881; Certificate Settings</a>
  <span style="margin:0 6px;">&rsaquo;</span>
  <a href="CertificateTemplates.php" style="color:#3182ce;text-decoration:none;">Templates</a>
  <span style="margin:0 6px;">&rsaquo;</span>
  <span>Design: <?= htmlspecialchars($tpl['Name']) ?></span>
</nav>

<?php if ($flashMsg): ?>
<div class="alert alert-<?= $flashType==='success' ? 'success' : 'danger' ?>" style="margin-bottom:14px;">
  <?= htmlspecialchars($flashMsg) ?>
</div>
<?php endif; ?>

<p style="color:#718096;font-size:.85rem;margin-bottom:14px;">
  Drag each field onto the artwork below. Checked fields only render if the certificate actually has that value
  (e.g. Grade/Percentage stay blank on a Course Completion certificate).
  <a href="../exam/certificate-print.php?preview=1&amp;templateId=<?= (int)$id ?>" target="_blank">&#128065; Open full-size preview</a>
</p>

<form method="post" enctype="multipart/form-data" id="designForm">
  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken()) ?>">
  <input type="hidden" name="TemplateId" value="<?= (int)$id ?>">

  <div class="card" style="margin-bottom:16px;">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
      <span>&#128444; Background Artwork</span>
      <label style="font-size:.82rem;font-weight:400;color:#718096;">
        Replace image: <input type="file" name="background_image" accept="image/jpeg,image/png,image/webp">
      </label>
    </div>
    <div class="card-body" style="padding:16px;">
      <div id="designerWrap">
        <div id="canvasInner"></div>
      </div>
    </div>
  </div>

  <div class="card" style="margin-bottom:16px;">
    <div class="card-header">&#127991; Placeholder Fields</div>
    <div id="fieldRows"></div>
  </div>

  <div class="card" style="margin-bottom:16px;">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
      <span>&#9997; Signature Blocks</span>
      <button type="button" class="btn btn-secondary btn-sm" onclick="addSignatory()">&#10010; Add Signature</button>
    </div>
    <div id="sigRows"></div>
  </div>

  <button type="submit" class="btn btn-primary" style="font-weight:700;">&#128190; Save Layout</button>
  <a href="CertificateTemplates.php" class="btn btn-secondary">&larr; Back to Templates</a>
</form>

<script>
const REF_W = <?= REF_W ?>, REF_H = <?= REF_H ?>;
const DISPLAY_W = Math.min(900, window.innerWidth - 80);
const DISPLAY_H = DISPLAY_W * REF_H / REF_W;
const scale = DISPLAY_W / REF_W;

const canvasInner = document.getElementById('canvasInner');
canvasInner.style.width  = DISPLAY_W + 'px';
canvasInner.style.height = DISPLAY_H + 'px';
canvasInner.style.backgroundImage = "url('<?= htmlspecialchars(addslashes($tpl['BackgroundImage'] ?? '')) ?>')";

const CATALOG = <?= json_encode($catalog, JSON_HEX_TAG) ?>;
const SAMPLE  = <?= json_encode($sampleValues, JSON_HEX_TAG) ?>;
const SAVED_FIELDS = <?= json_encode(array_values($layoutByKey), JSON_HEX_TAG) ?>;
const SAVED_SIGS   = <?= json_encode($signatories, JSON_HEX_TAG) ?>;

function esc(s) {
  return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

/* ── Placeholder fields ───────────────────────────────────────────────── */
const fieldRows = document.getElementById('fieldRows');
const savedByKey = {};
SAVED_FIELDS.forEach(f => savedByKey[f.key] = f);

let fi = 0;
for (const key in CATALOG) {
  const label  = CATALOG[key];
  const saved  = savedByKey[key];
  const enabled = !!saved;
  const x = saved ? saved.x : REF_W * 0.5;
  const y = saved ? saved.y : 160 + (fi * 55);
  const fontSize = saved ? saved.fontSize : 28;
  const color = saved ? saved.color : '#1a202c';
  const bold = saved ? !!saved.bold : (key === 'StudentName');
  const align = saved ? saved.align : 'center';
  fi++;

  const row = document.createElement('div');
  row.className = 'field-row';
  row.innerHTML = `
    <label style="min-width:170px;"><input type="checkbox" name="field_enabled[${key}]" value="1" ${enabled?'checked':''} onchange="toggleChip('${key}', this.checked)"> <strong>${esc(label)}</strong></label>
    <input type="hidden" id="fx_${key}" name="field_x[${key}]" value="${x}">
    <input type="hidden" id="fy_${key}" name="field_y[${key}]" value="${y}">
    <label>Size <input type="number" name="field_fontsize[${key}]" value="${fontSize}" min="6" max="200" style="width:58px;" oninput="updateChipStyle('${key}')"></label>
    <label>Color <input type="color" name="field_color[${key}]" value="${color}" oninput="updateChipStyle('${key}')"></label>
    <label><input type="checkbox" name="field_bold[${key}]" value="1" ${bold?'checked':''} onchange="updateChipStyle('${key}')"> Bold</label>
    <label>Align
      <select name="field_align[${key}]" onchange="updateChipStyle('${key}')">
        <option value="left" ${align==='left'?'selected':''}>Left</option>
        <option value="center" ${align==='center'?'selected':''}>Center</option>
        <option value="right" ${align==='right'?'selected':''}>Right</option>
      </select>
    </label>
  `;
  fieldRows.appendChild(row);

  const chip = document.createElement('div');
  chip.className = 'design-chip';
  chip.dataset.key = key;
  chip.textContent = SAMPLE[key] || label;
  chip.style.display = enabled ? '' : 'none';
  canvasInner.appendChild(chip);
  positionChip(chip, x, y);
  updateChipStyle(key);
}

function toggleChip(key, show) {
  const chip = canvasInner.querySelector(`.design-chip[data-key="${key}"]`);
  if (chip) chip.style.display = show ? '' : 'none';
}

function updateChipStyle(key) {
  const chip = canvasInner.querySelector(`.design-chip[data-key="${key}"]`);
  if (!chip) return;
  const fontSize = document.querySelector(`input[name="field_fontsize[${key}]"]`).value;
  const color    = document.querySelector(`input[name="field_color[${key}]"]`).value;
  const bold     = document.querySelector(`input[name="field_bold[${key}]"]`).checked;
  const align    = document.querySelector(`select[name="field_align[${key}]"]`).value;
  chip.style.fontSize   = (fontSize * scale) + 'px';
  chip.style.color      = color;
  chip.style.fontWeight = bold ? '700' : '400';
  chip.style.textAlign  = align;
  chip.style.fontFamily = 'Georgia, serif';
}

function positionChip(chip, xRef, yRef) {
  chip.style.left = (xRef * scale) + 'px';
  chip.style.top  = (yRef * scale) + 'px';
  chip.style.transform = 'translate(-50%, -50%)';
}

/* ── Signatories ──────────────────────────────────────────────────────── */
let sigCounter = 0;
function addSignatory(data) {
  data = data || { name: '', title: '', x: REF_W / 2, y: REF_H - 70, fontSize: 15, align: 'center', existingImage: '' };
  const cid = 'sig' + (sigCounter++);

  const row = document.createElement('div');
  row.className = 'sig-row';
  row.dataset.cid = cid;
  row.innerHTML = `
    <input type="text" name="sig_name[]" placeholder="Name" value="${esc(data.name)}" style="width:150px;">
    <input type="text" name="sig_title[]" placeholder="Title" value="${esc(data.title)}" style="width:150px;">
    <input type="hidden" class="sig-x" name="sig_x[]" value="${data.x}">
    <input type="hidden" class="sig-y" name="sig_y[]" value="${data.y}">
    <label>Size <input type="number" name="sig_fontsize[]" value="${data.fontSize}" min="8" max="60" style="width:52px;"></label>
    <label>Align
      <select name="sig_align[]">
        <option value="left" ${data.align==='left'?'selected':''}>Left</option>
        <option value="center" ${data.align==='center'?'selected':''}>Center</option>
        <option value="right" ${data.align==='right'?'selected':''}>Right</option>
      </select>
    </label>
    ${data.existingImage ? `<img class="sig-thumb" src="${esc(data.existingImage)}" alt="">` : ''}
    <label>Image <input type="file" name="signatory_image[]" accept="image/*"></label>
    <input type="hidden" class="sig-remove-flag" name="sig_remove_image[]" value="0">
    <input type="hidden" name="signatory_existing_image[]" value="${esc(data.existingImage||'')}">
    <label style="font-size:.75rem;"><input type="checkbox" onclick="this.parentElement.previousElementSibling.previousElementSibling.value=this.checked?'1':'0'"> remove image</label>
    <button type="button" class="btn btn-danger btn-sm" onclick="removeSignatory('${cid}')">&times; Remove</button>
  `;
  row.querySelectorAll('input[type=text], input[type=number], select').forEach(inp => {
    inp.addEventListener('input', () => updateSigChip(cid));
    inp.addEventListener('change', () => updateSigChip(cid));
  });
  document.getElementById('sigRows').appendChild(row);

  const chip = document.createElement('div');
  chip.className = 'design-chip sig-chip';
  chip.dataset.cid = cid;
  canvasInner.appendChild(chip);
  positionChip(chip, data.x, data.y);
  updateSigChip(cid);
}

function updateSigChip(cid) {
  const row  = document.querySelector(`.sig-row[data-cid="${cid}"]`);
  const chip = document.querySelector(`.design-chip.sig-chip[data-cid="${cid}"]`);
  if (!row || !chip) return;
  const name  = row.querySelector('input[name="sig_name[]"]').value || '(name)';
  const title = row.querySelector('input[name="sig_title[]"]').value;
  const fontSize = row.querySelector('input[name="sig_fontsize[]"]').value;
  const align = row.querySelector('select[name="sig_align[]"]').value;
  chip.innerHTML = `<div style="font-weight:700;">${esc(name)}</div>` + (title ? `<div style="font-size:.85em;">${esc(title)}</div>` : '');
  chip.style.fontSize = (fontSize * scale) + 'px';
  chip.style.textAlign = align;
  chip.style.fontFamily = 'Georgia, serif';
}

function removeSignatory(cid) {
  document.querySelector(`.sig-row[data-cid="${cid}"]`)?.remove();
  document.querySelector(`.design-chip.sig-chip[data-cid="${cid}"]`)?.remove();
}

SAVED_SIGS.forEach(s => addSignatory({
  name: s.name || '', title: s.title || '', x: s.x, y: s.y,
  fontSize: s.fontSize || 15, align: s.align || 'center', existingImage: s.image || '',
}));

/* ── Drag handling (shared by field chips + signature chips) ────────────── */
let dragEl = null, dragOffX = 0, dragOffY = 0;
canvasInner.addEventListener('mousedown', e => {
  const chip = e.target.closest('.design-chip');
  if (!chip || chip.style.display === 'none') return;
  dragEl = chip;
  const rect = chip.getBoundingClientRect();
  dragOffX = e.clientX - (rect.left + rect.width / 2);
  dragOffY = e.clientY - (rect.top + rect.height / 2);
  e.preventDefault();
});
document.addEventListener('mousemove', e => {
  if (!dragEl) return;
  const wrapRect = canvasInner.getBoundingClientRect();
  let left = e.clientX - wrapRect.left - dragOffX;
  let top  = e.clientY - wrapRect.top  - dragOffY;
  left = Math.max(0, Math.min(DISPLAY_W, left));
  top  = Math.max(0, Math.min(DISPLAY_H, top));
  dragEl.style.left = left + 'px';
  dragEl.style.top  = top + 'px';
  const xRef = (left / scale).toFixed(1);
  const yRef = (top  / scale).toFixed(1);
  if (dragEl.classList.contains('sig-chip')) {
    const row = document.querySelector(`.sig-row[data-cid="${dragEl.dataset.cid}"]`);
    if (row) { row.querySelector('.sig-x').value = xRef; row.querySelector('.sig-y').value = yRef; }
  } else {
    const key = dragEl.dataset.key;
    document.getElementById('fx_' + key).value = xRef;
    document.getElementById('fy_' + key).value = yRef;
  }
});
document.addEventListener('mouseup', () => { dragEl = null; });
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
