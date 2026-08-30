<?php
/**
 * Admin/AddEditStudyReference.php — Add / Edit a study reference
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
require_once __DIR__ . '/../Lib/Institute.php';
Auth::requireLogin('../auth/login.php');
if (!Auth::isAdmin()) { header('Location: ../auth/login.php'); exit; }

/* Visibility (institute-scoping) is an optional feature — production may
   not have run migration_v56.sql yet, so gate everything on hasColumn()
   the same way browse-subjects.php/manage.php do for their own optional
   columns. */
$hasScopeCols = Database::hasColumn('study_references', 'ScopeType');
$institutes   = $hasScopeCols ? Institute::listAll() : [];

$refId = filter_input(INPUT_GET, 'RefId', FILTER_VALIDATE_INT) ?: 0;

/* ── Load existing row ────────────────────────────────────────────────────── */
$row = [];
if ($refId > 0) {
    $row = Database::fetchOne(
        "SELECT * FROM study_references WHERE RefId = ? LIMIT 1", [$refId]) ?: [];
    if (empty($row)) { header('Location: StudyReferences.php'); exit; }
}

$subjects = Database::fetchAll(
    "SELECT SubjectInfoId, SubjectName FROM subjectinfo WHERE Active='Y' ORDER BY SubjectName");

$refTypes = ['Book', 'Video', 'Website', 'PDF', 'Article', 'Other'];

/* Distinct Category / SubCategory values already in use, for datalist
   suggestions — lets an admin re-use "Interview Questions" / "MCQ" /
   "Technical" (or invent a brand new category) without hunting through
   existing rows first. */
$existingCategories = array_values(array_filter(array_column(
    Database::fetchAll(
        "SELECT DISTINCT Category FROM study_references
          WHERE Category IS NOT NULL AND Category <> '' ORDER BY Category"),
    'Category')));
$existingSubCategories = array_values(array_filter(array_column(
    Database::fetchAll(
        "SELECT DISTINCT SubCategory FROM study_references
          WHERE SubCategory IS NOT NULL AND SubCategory <> '' ORDER BY SubCategory"),
    'SubCategory')));

/* Upload target for locally-hosted documents (PDFs, etc.), served from the
   top-level assets/ folder so both Admin/ and exam/ pages can link to it
   via the usual $_root-relative convention. */
define('STUDY_REF_UPLOAD_DIR', __DIR__ . '/../assets/study-references/');
define('STUDY_REF_UPLOAD_URL_PREFIX', 'assets/study-references/');
$allowedUploadExt = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'zip'];
$maxUploadBytes    = 25 * 1024 * 1024; // 25 MB

/* ── Handle save ──────────────────────────────────────────────────────────── */
$errors  = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    $title       = trim($_POST['Title']         ?? '');
    $desc        = trim($_POST['Description']   ?? '');
    $url         = trim($_POST['URL']           ?? '');
    $refType     = trim($_POST['RefType']       ?? 'Website');
    $category    = trim($_POST['Category']      ?? '');
    $subCategory = trim($_POST['SubCategory']   ?? '');
    $subjectId   = (int)($_POST['SubjectInfoId'] ?? 0) ?: null;
    $author      = trim($_POST['Author']        ?? '');
    $sortOrder   = (int)($_POST['SortOrder']    ?? 0);
    $active      = trim($_POST['Active']        ?? 'Y');
    $showContent = trim($_POST['ShowContent']   ?? 'Y');
    $scopeType   = ($_POST['ScopeType'] ?? 'All') === 'Institute' ? 'Institute' : 'All';
    $scopeInstituteId = (int)($_POST['ScopeInstituteId'] ?? 0) ?: null;

    /* File upload takes priority over a manually typed URL: if the admin
       attaches a document, it becomes this reference's link. */
    $uploadedUrl = null;
    if (!empty($_FILES['DocumentFile']['tmp_name']) && $_FILES['DocumentFile']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['DocumentFile'];
        $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedUploadExt, true)) {
            $errors[] = 'Uploaded file must be one of: ' . implode(', ', $allowedUploadExt) . '.';
        } elseif ($file['size'] > $maxUploadBytes) {
            $errors[] = 'Uploaded file must be ' . round($maxUploadBytes / 1024 / 1024) . 'MB or smaller.';
        } else {
            if (!is_dir(STUDY_REF_UPLOAD_DIR)) mkdir(STUDY_REF_UPLOAD_DIR, 0755, true);
            $slug = preg_replace('/[^a-z0-9]+/i', '_', pathinfo($file['name'], PATHINFO_FILENAME));
            $name = trim($slug, '_') . '_' . uniqid('', false) . '.' . $ext;
            if (move_uploaded_file($file['tmp_name'], STUDY_REF_UPLOAD_DIR . $name)) {
                $uploadedUrl = STUDY_REF_UPLOAD_URL_PREFIX . $name;
            } else {
                $errors[] = 'Failed to save the uploaded file.';
            }
        }
    }
    if ($uploadedUrl !== null) {
        // Replace the old uploaded file (if this reference already pointed at
        // one under our own upload dir) so edits don't leave orphan files behind.
        if ($refId > 0 && !empty($row['URL']) && str_starts_with($row['URL'], STUDY_REF_UPLOAD_URL_PREFIX)) {
            @unlink(__DIR__ . '/../' . $row['URL']);
        }
        $url = $uploadedUrl;
    }

    /* Validation */
    if ($title === '') $errors[] = 'Title is required.';
    if (!in_array($refType, $refTypes)) $errors[] = 'Invalid reference type.';
    if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
        // Not an absolute URL — accept it as a relative in-app path (e.g. an
        // uploaded document, or a hand-typed "assets/…" path), as long as it
        // looks like a sane relative path rather than garbage input.
        if (!preg_match('~^[A-Za-z0-9._\-/]+$~', $url) || str_contains($url, '..')) {
            $errors[] = 'URL is not valid. Include https:// for an external link, or upload a document instead.';
        }
    }
    if ($url === '' && $desc === '') {
        $errors[] = 'Please provide at least a URL, an uploaded document, or a description.';
    }
    if ($scopeType === 'Institute' && !$scopeInstituteId) {
        $errors[] = 'Please select an institute, or switch Visibility to All Students.';
    }

    if (empty($errors)) {
        $data = [$title, $desc ?: null, $url ?: null, $refType, $category ?: null, $subCategory ?: null,
                 $subjectId, $author ?: null, $sortOrder, $active, $showContent];

        $savedRefId = $refId;

        if ($refId > 0) {
            Database::execute(
                "UPDATE study_references
                    SET Title=?, Description=?, URL=?, RefType=?, Category=?, SubCategory=?,
                        SubjectInfoId=?, Author=?, SortOrder=?, Active=?, ShowContent=?
                  WHERE RefId=?",
                array_merge($data, [$refId]));
            $success = 'Reference updated.';
        } else {
            Database::execute(
                "INSERT INTO study_references
                    (Title, Description, URL, RefType, Category, SubCategory,
                     SubjectInfoId, Author, SortOrder, Active, ShowContent)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                $data);
            $savedRefId = (int)Database::lastInsertId();
        }

        /* Visibility (ScopeType/InstituteId) is saved via its own guarded
           UPDATE, separate from the INSERT/UPDATE above, so a production
           database that hasn't run migration_v56.sql yet just skips this
           silently instead of failing the whole save — same resilience
           pattern used for examinfo.ExamCategory in exam/manage.php. */
        if ($hasScopeCols && $savedRefId > 0) {
            try {
                Database::execute(
                    "UPDATE study_references SET ScopeType=?, InstituteId=? WHERE RefId=?",
                    [$scopeType, $scopeType === 'Institute' ? $scopeInstituteId : null, $savedRefId]);
            } catch (Exception $e) { /* optional column not present yet — ignore */ }
        }

        if ($refId > 0) {
            $row = Database::fetchOne(
                "SELECT * FROM study_references WHERE RefId=? LIMIT 1", [$refId]) ?: [];
        } else {
            header('Location: StudyReferences.php?msg=added');
            exit;
        }
    }
}

$pageTitle = ($refId > 0 ? 'Edit' : 'Add') . ' Study Reference';
include __DIR__ . '/../includes/header.php';
?>
<style>
  .form-wrap { max-width:640px; margin:0 auto; }
  .field-hint { font-size:.78rem; color:#6b7280; margin-top:3px; }
  .type-grid  { display:grid; grid-template-columns:repeat(3,1fr); gap:8px; }
  .type-card  {
    border:2px solid #e5e7eb; border-radius:6px; padding:8px 10px;
    cursor:pointer; text-align:center; font-size:.82rem; font-weight:600;
    color:#374151; transition:border-color .15s, background .15s;
  }
  .type-card:hover   { border-color:#6366f1; background:#f5f3ff; }
  .type-card.selected{ border-color:#4f46e5; background:#eef2ff; color:#4f46e5; }
  .type-card input   { display:none; }
</style>

<div class="card form-wrap">
  <div class="card-header">&#128218; <?php echo htmlspecialchars($pageTitle); ?></div>
  <div class="card-body">

    <?php foreach ($errors as $e): ?>
      <div class="alert alert-danger"><?php echo htmlspecialchars($e); ?></div>
    <?php endforeach; ?>
    <?php if ($success): ?>
      <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <form method="post" action="" id="refForm" enctype="multipart/form-data">

      <!-- Title -->
      <div class="form-group">
        <label>Title <span style="color:#dc2626;">*</span></label>
        <input type="text" name="Title" class="form-control" maxlength="255" required
               value="<?php echo htmlspecialchars($row['Title'] ?? ''); ?>"
               placeholder="e.g. Introduction to Databases – 3rd Edition, or a company name like TCS">
      </div>

      <!-- Category / SubCategory -->
      <div class="form-group" style="display:flex;gap:12px;">
        <div style="flex:1;">
          <label>Category</label>
          <input type="text" name="Category" class="form-control" list="categoryList" maxlength="100"
                 value="<?php echo htmlspecialchars($row['Category'] ?? ''); ?>"
                 placeholder="e.g. Interview Questions">
          <datalist id="categoryList">
            <?php foreach ($existingCategories as $c): ?>
              <option value="<?php echo htmlspecialchars($c); ?>">
            <?php endforeach; ?>
          </datalist>
          <div class="field-hint">Optional grouping shown in the sidebar/menu (e.g. "Interview Questions"). Leave blank for a plain, ungrouped reference.</div>
        </div>
        <div style="flex:1;">
          <label>Sub-Category</label>
          <input type="text" name="SubCategory" class="form-control" list="subCategoryList" maxlength="100"
                 value="<?php echo htmlspecialchars($row['SubCategory'] ?? ''); ?>"
                 placeholder="e.g. MCQ or Technical">
          <datalist id="subCategoryList">
            <?php foreach ($existingSubCategories as $c): ?>
              <option value="<?php echo htmlspecialchars($c); ?>">
            <?php endforeach; ?>
          </datalist>
          <div class="field-hint">Second-level grouping within the Category above.</div>
        </div>
      </div>

      <!-- Author -->
      <div class="form-group">
        <label>Author / Source</label>
        <input type="text" name="Author" class="form-control" maxlength="255"
               value="<?php echo htmlspecialchars($row['Author'] ?? ''); ?>"
               placeholder="e.g. Abraham Silberschatz">
        <div class="field-hint">Optional. Book author, channel name, publisher, etc.</div>
      </div>

      <!-- Reference Type -->
      <div class="form-group">
        <label>Reference Type <span style="color:#dc2626;">*</span></label>
        <div class="type-grid" id="typeGrid">
          <?php
          $icons   = ['Book'=>'📖','Video'=>'🎬','Website'=>'🌐','PDF'=>'📄','Article'=>'📰','Other'=>'🔗'];
          $current = $row['RefType'] ?? 'Website';
          foreach ($refTypes as $t): ?>
          <label class="type-card <?php echo $current===$t?'selected':''; ?>"
                 data-type="<?php echo $t; ?>">
            <input type="radio" name="RefType" value="<?php echo $t; ?>"
                   <?php echo $current===$t?'checked':''; ?>>
            <?php echo $icons[$t]; ?> <?php echo $t; ?>
          </label>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- URL -->
      <div class="form-group">
        <label>URL / Link</label>
        <input type="text" name="URL" class="form-control" maxlength="2048"
               value="<?php echo htmlspecialchars($row['URL'] ?? ''); ?>"
               placeholder="https://… (or leave blank and upload a document below)">
        <div class="field-hint">Leave blank for offline resources (books, etc.) or if you're uploading a document below.</div>
      </div>

      <!-- Document upload -->
      <div class="form-group">
        <label>Upload Document</label>
        <?php if (!empty($row['URL']) && str_starts_with($row['URL'], 'assets/study-references/')): ?>
          <div class="field-hint" style="margin-bottom:6px;">
            Currently attached: <a href="../<?php echo htmlspecialchars($row['URL']); ?>" target="_blank" rel="noopener">
              &#128279; <?php echo htmlspecialchars(basename($row['URL'])); ?></a>
            — uploading a new file below will replace it.
          </div>
        <?php endif; ?>
        <input type="file" name="DocumentFile" class="form-control"
               accept=".pdf,.doc,.docx,.ppt,.pptx,.zip">
        <div class="field-hint">
          PDF, Word, PowerPoint, or ZIP, up to 25MB. If provided, this replaces the URL above with a link
          to the uploaded file — this is how you add/update documents like the Interview Questions PDFs.
        </div>
      </div>

      <!-- Description -->
      <div class="form-group">
        <label>Description / Notes</label>
        <textarea name="Description" class="form-control" rows="4"
                  placeholder="What this resource covers, which chapters to read, etc."><?php
          echo htmlspecialchars($row['Description'] ?? '');
        ?></textarea>
        <div class="field-hint">Shown to students as guidance. A URL or description (or both) is required.</div>
      </div>

      <!-- Subject -->
      <div class="form-group">
        <label>Subject (optional)</label>
        <select name="SubjectInfoId" class="form-control">
          <option value="0">— All / General —</option>
          <?php foreach ($subjects as $s): ?>
            <option value="<?php echo $s['SubjectInfoId']; ?>"
              <?php echo (($row['SubjectInfoId'] ?? 0) == $s['SubjectInfoId']) ? 'selected' : ''; ?>>
              <?php echo htmlspecialchars($s['SubjectName']); ?>
            </option>
          <?php endforeach; ?>
        </select>
        <div class="field-hint">Link this reference to a specific subject so students can filter by it.</div>
      </div>

      <?php if ($hasScopeCols): ?>
      <!-- ── Visibility ─────────────────────────────────────────────────── -->
      <div class="form-group" style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:14px 16px;margin-top:4px;">
        <div style="font-weight:700;color:#1e40af;margin-bottom:10px;">&#127982; Visibility</div>
        <div style="font-size:.82rem;color:#3b5fa0;margin-bottom:12px;">
          Controls which students can see this reference.
          <strong>All Students</strong> makes it available to everyone.
          <strong>Institute Only</strong> restricts it to students registered under the selected institute.
        </div>
        <div style="display:flex;gap:24px;flex-wrap:wrap;margin-bottom:10px;">
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:600;">
            <input type="radio" name="ScopeType" value="All"
                   id="scopeAll" style="accent-color:#1e40af;"
                   <?php echo (($row['ScopeType'] ?? 'All') === 'All') ? 'checked' : ''; ?>
                   onchange="toggleRefInstituteField()">
            <span>&#127760; All Students</span>
          </label>
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:600;">
            <input type="radio" name="ScopeType" value="Institute"
                   id="scopeInst" style="accent-color:#1e40af;"
                   <?php echo (($row['ScopeType'] ?? 'All') === 'Institute') ? 'checked' : ''; ?>
                   onchange="toggleRefInstituteField()">
            <span>&#127982; Institute Only</span>
          </label>
        </div>
        <div id="refInstituteFieldWrap"
             style="display:<?php echo (($row['ScopeType'] ?? 'All') === 'Institute') ? 'block' : 'none'; ?>;">
          <label for="ScopeInstituteId" style="font-size:.85rem;font-weight:600;">
            Select Institute <span style="color:#e53e3e">*</span>
          </label>
          <?php if (empty($institutes)): ?>
            <p style="font-size:.82rem;color:#e53e3e;margin:4px 0 0;">
              No active institutes found.
              <a href="ManageInstitutes.php?action=add">Add an institute</a> first.
            </p>
            <input type="hidden" name="ScopeInstituteId" value="0">
          <?php else: ?>
          <select id="ScopeInstituteId" name="ScopeInstituteId" class="form-control" style="max-width:420px;">
            <option value="0">— Select Institute —</option>
            <?php foreach ($institutes as $inst):
              $sel = ((int)($row['InstituteId'] ?? 0) === (int)$inst['InstituteId']) ? 'selected' : ''; ?>
              <option value="<?php echo (int)$inst['InstituteId']; ?>" <?php echo $sel; ?>>
                <?php echo htmlspecialchars($inst['InstituteName']); ?>
                (<?php echo htmlspecialchars($inst['InstituteType']); ?>,
                 <?php echo htmlspecialchars($inst['State']); ?>)
              </option>
            <?php endforeach; ?>
          </select>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- Sort Order -->
      <div class="form-group">
        <label>Sort Order</label>
        <input type="number" name="SortOrder" class="form-control" min="0" max="9999"
               value="<?php echo (int)($row['SortOrder'] ?? 0); ?>" style="max-width:120px;">
        <div class="field-hint">Lower number = appears first. Items with the same order sort by date.</div>
      </div>

      <!-- Active -->
      <div class="form-group">
        <label>Visible to Students</label>
        <select name="Active" class="form-control" style="max-width:160px;">
          <option value="Y" <?php echo (($row['Active'] ?? 'Y')==='Y')?'selected':''; ?>>Yes (Active)</option>
          <option value="N" <?php echo (($row['Active'] ?? '')==='N')?'selected':''; ?>>No (Hidden)</option>
        </select>
        <div class="field-hint">When set to No the entire reference is hidden from students.</div>
      </div>

      <!-- Show Content -->
      <div class="form-group">
        <label>Show Description / Notes to Students</label>
        <select name="ShowContent" class="form-control" style="max-width:160px;">
          <option value="Y" <?php echo (($row['ShowContent'] ?? 'Y')==='Y')?'selected':''; ?>>Yes – show description</option>
          <option value="N" <?php echo (($row['ShowContent'] ?? '')==='N')?'selected':''; ?>>No – title &amp; link only</option>
        </select>
        <div class="field-hint">
          When set to <strong>No</strong>, students see the title, type, subject and URL but <em>not</em>
          the description/notes. Useful when notes contain admin-only guidance.
        </div>
      </div>

      <div style="display:flex;gap:10px;margin-top:24px;">
        <button type="submit" name="save" class="btn btn-primary">&#128190; Save</button>
        <a href="StudyReferences.php" class="btn btn-secondary">&#8592; Back</a>
      </div>

    </form>
  </div>
</div>

<script>
/* Type card toggle */
document.querySelectorAll('#typeGrid .type-card').forEach(function(card) {
  card.addEventListener('click', function() {
    document.querySelectorAll('#typeGrid .type-card').forEach(function(c){ c.classList.remove('selected'); });
    card.classList.add('selected');
    card.querySelector('input').checked = true;
  });
});

/* Visibility scope toggle (All Students / Institute Only) */
function toggleRefInstituteField() {
  var inst = document.getElementById('scopeInst');
  var wrap = document.getElementById('refInstituteFieldWrap');
  if (inst && wrap) wrap.style.display = inst.checked ? 'block' : 'none';
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
