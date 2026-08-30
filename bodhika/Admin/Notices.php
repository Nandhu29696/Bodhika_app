<?php
/**
 * Admin/Notices.php — Send notice to students / teachers
 *
 * Refactored from legacy mysql_* + raw-table layout.
 * Form posts to NoticeActionAttach.php (unchanged backend).
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');
if (!Auth::isAdmin()) { header('Location: ../exam/search.php'); exit; }

/* ── Data for dropdowns ──────────────────────────────────────────────── */
$templates = Database::fetchAll(
    "SELECT TemplateName, Message FROM msgtemplate ORDER BY TemplateName",
    []
);

$grades = Database::fetchAll(
    "SELECT GradeInfoId, GradeName FROM gradeinfo ORDER BY GradeName",
    []
);

$emailGroups = Database::fetchAll(
    "SELECT EmailGrpId, EmailGrpName FROM emailgrpinfo WHERE Active='Y' ORDER BY EmailGrpName",
    []
);

/* Templates as JSON for JS population */
$templateMap = [];
foreach ($templates as $t) {
    $templateMap[htmlspecialchars($t['TemplateName'], ENT_QUOTES)] = $t['Message'];
}
$templateJson = json_encode($templateMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);

$pageTitle = 'Send Notice';
include __DIR__ . '/../includes/header.php';
?>
<style>
  .notice-grid { display:grid;grid-template-columns:1fr 1fr;gap:1rem; }
  @media(max-width:700px){ .notice-grid { grid-template-columns:1fr; } }

  /* Recipient option cards */
  .recip-options { display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:.6rem;margin-top:.4rem; }
  .recip-card { border:2px solid var(--clr-border);border-radius:8px;padding:10px 12px;cursor:pointer;
                transition:.15s;background:#fff;display:flex;align-items:flex-start;gap:8px; }
  .recip-card:hover  { border-color:var(--clr-primary);background:#f8f5ff; }
  .recip-card.active { border-color:var(--clr-primary);background:#eef2ff; }
  .recip-card input[type=radio] { margin-top:2px;accent-color:var(--clr-primary); }
  .recip-card .rc-title { font-weight:700;font-size:.85rem; }
  .recip-card .rc-hint  { font-size:.75rem;color:var(--tx-muted);margin-top:2px; }

  /* Channel toggle buttons */
  .channel-group { display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.4rem; }
  .ch-btn { padding:7px 18px;border:2px solid var(--clr-border);border-radius:6px;
             background:#fff;cursor:pointer;font-size:.85rem;font-weight:600;transition:.15s; }
  .ch-btn:hover  { border-color:var(--clr-primary); }
  .ch-btn.active { border-color:var(--clr-primary);background:var(--clr-primary);color:#fff; }
  .ch-btn input  { display:none; }

  /* Char counter */
  #charCount { font-size:.78rem;color:var(--tx-muted);text-align:right;margin-top:4px; }
  #charCount.warn  { color:#d97706;font-weight:700; }
  #charCount.over  { color:#dc2626;font-weight:700; }

  /* Conditional sub-fields */
  .recip-subfield { display:none;margin-top:.6rem;padding:.8rem;background:#f8fafc;
                    border:1px solid var(--clr-border);border-radius:6px; }
  .recip-subfield.visible { display:block; }
</style>

<div class="card">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;">
    <h2 style="margin:0;font-size:1.05rem;">📢 Send Notice</h2>
    <a href="NoticesSent.php" class="btn btn-secondary btn-sm">📋 Sent Notices</a>
  </div>

  <div class="card-body">
    <form name="frmNotices" id="frmNotices"
          action="NoticeActionAttach.php" method="post"
          enctype="multipart/form-data"
          novalidate>
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken()) ?>">

      <!-- ── Row 1: Template + Subject ───────────────────────────────── -->
      <div class="notice-grid" style="margin-bottom:.8rem;">
        <div class="form-group">
          <label class="form-label">Message Template <span style="color:var(--tx-muted);font-weight:400;">(optional)</span></label>
          <select id="txtTemplateName" name="txtTemplateName" class="form-control"
                  onchange="applyTemplate(this.value)">
            <option value="">— Select a template —</option>
            <?php foreach ($templates as $t): ?>
              <option value="<?= htmlspecialchars($t['TemplateName'], ENT_QUOTES) ?>">
                <?= htmlspecialchars($t['TemplateName']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label">Subject <span style="color:#dc2626;">*</span></label>
          <input type="text" id="txtSubject" name="txtSubject" class="form-control"
                 placeholder="Enter notice subject" maxlength="200">
        </div>
      </div>

      <!-- ── Message ─────────────────────────────────────────────────── -->
      <div class="form-group">
        <label class="form-label">Message <span style="color:#dc2626;">*</span></label>
        <textarea name="txtMessage" id="txtMessage" class="form-control" rows="6"
                  placeholder="Type your notice here…"
                  oninput="updateCharCount()"><?= isset($t) ? htmlspecialchars($t['Message']) : '' ?></textarea>
        <div id="charCount">0 characters</div>
        <div id="smsWarning" style="display:none;margin-top:4px;font-size:.78rem;color:#d97706;">
          ⚠ SMS limit: subject + message must not exceed 160 characters.
        </div>
      </div>

      <!-- ── Send to ─────────────────────────────────────────────────── -->
      <div class="form-group">
        <label class="form-label">Send Notice To <span style="color:#dc2626;">*</span></label>

        <div class="recip-options">
          <!-- Individual student/teacher -->
          <label class="recip-card" id="rc-Student" onclick="selectRecip('Student')">
            <input type="radio" name="cbxNoticeTo" value="Student">
            <div>
              <div class="rc-title">👤 Specific Student / Teacher</div>
              <div class="rc-hint">Enter email or search by name</div>
            </div>
          </label>

          <!-- All Students -->
          <label class="recip-card" id="rc-All" onclick="selectRecip('All')">
            <input type="radio" name="cbxNoticeTo" value="All">
            <div>
              <div class="rc-title">👥 All Students</div>
              <div class="rc-hint">Broadcast to every enrolled student</div>
            </div>
          </label>

          <!-- All Teachers -->
          <label class="recip-card" id="rc-Teachers" onclick="selectRecip('Teachers')">
            <input type="radio" name="cbxNoticeTo" value="Teachers">
            <div>
              <div class="rc-title">🎓 All Teachers</div>
              <div class="rc-hint">Send to all teaching staff</div>
            </div>
          </label>

          <!-- Grade wise -->
          <label class="recip-card" id="rc-Grade" onclick="selectRecip('Grade')">
            <input type="radio" name="cbxNoticeTo" value="Grade">
            <div>
              <div class="rc-title">📚 Grade / Batch</div>
              <div class="rc-hint">Select one or more grades</div>
            </div>
          </label>

          <!-- Specific Group -->
          <label class="recip-card" id="rc-SpecificGrp" onclick="selectRecip('SpecificGrp')">
            <input type="radio" name="cbxNoticeTo" value="SpecificGrp">
            <div>
              <div class="rc-title">📋 Email Group</div>
              <div class="rc-hint">Pre-defined mailing list</div>
            </div>
          </label>
        </div>

        <!-- Sub-fields for each option -->
        <div class="recip-subfield" id="sub-Student">
          <label class="form-label" style="font-size:.82rem;">Student / Teacher Email</label>
          <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
            <input type="email" name="txtEMail" id="txtEMail" class="form-control"
                   placeholder="student@example.com" style="max-width:300px;">
            <a href="#" class="btn btn-secondary btn-sm"
               onclick="window.open('SearchUser.php','','width=820,height=520,scrollbars=1,left=80');return false;">
              🔍 Search User
            </a>
          </div>
        </div>

        <div class="recip-subfield" id="sub-Grade">
          <label class="form-label" style="font-size:.82rem;">Select Grade(s) <span style="color:var(--tx-muted);font-weight:400;">(Ctrl/Cmd + click for multiple)</span></label>
          <select name="grade[]" size="5" multiple class="form-control" style="max-width:280px;">
            <?php foreach ($grades as $g): ?>
              <option value="<?= (int)$g['GradeInfoId'] ?>"><?= htmlspecialchars($g['GradeName']) ?></option>
            <?php endforeach; ?>
            <?php if (!$grades): ?>
              <option disabled>No grades configured</option>
            <?php endif; ?>
          </select>
        </div>

        <div class="recip-subfield" id="sub-SpecificGrp">
          <label class="form-label" style="font-size:.82rem;">Email Group</label>
          <select name="txtEmailGrp" id="txtEmailGrp" class="form-control" style="max-width:280px;">
            <option value="0">— Select group —</option>
            <?php foreach ($emailGroups as $eg): ?>
              <option value="<?= (int)$eg['EmailGrpId'] ?>"><?= htmlspecialchars($eg['EmailGrpName']) ?></option>
            <?php endforeach; ?>
            <?php if (!$emailGroups): ?>
              <option disabled>No active email groups</option>
            <?php endif; ?>
          </select>
        </div>
      </div>

      <!-- ── Channel ─────────────────────────────────────────────────── -->
      <div class="form-group">
        <label class="form-label">Send Via <span style="color:#dc2626;">*</span></label>
        <div class="channel-group">
          <label class="ch-btn active" id="chEmail" onclick="selectChannel('EMail',this)">
            <input type="radio" name="cbxNoticeThr" value="EMail" checked>
            ✉️ Email
          </label>
          <label class="ch-btn" id="chSMS" onclick="selectChannel('SMS',this)">
            <input type="radio" name="cbxNoticeThr" value="SMS">
            📱 SMS
          </label>
          <label class="ch-btn" id="chBOTH" onclick="selectChannel('BOTH',this)">
            <input type="radio" name="cbxNoticeThr" value="BOTH">
            📧📱 Both
          </label>
        </div>
      </div>

      <!-- ── File attachment ─────────────────────────────────────────── -->
      <div class="form-group">
        <label class="form-label">Attachment <span style="color:var(--tx-muted);font-weight:400;">(optional — PDF, image, doc)</span></label>
        <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;">
          <input type="file" name="fileatt" id="fileatt" accept=".pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg"
                 onchange="showFileName(this)" style="display:none;">
          <button type="button" class="btn btn-secondary btn-sm"
                  onclick="document.getElementById('fileatt').click()">📎 Choose File</button>
          <span id="fileLabel" style="font-size:.83rem;color:var(--tx-muted);">No file chosen</span>
        </div>
      </div>

      <!-- ── Submit row ──────────────────────────────────────────────── -->
      <div style="display:flex;gap:.6rem;align-items:center;margin-top:1.2rem;flex-wrap:wrap;">
        <button type="submit" class="btn btn-primary" id="sendBtn">📤 Send Notice</button>
        <a href="Index.php" class="btn btn-secondary">Cancel</a>
        <div id="formError" style="display:none;font-size:.82rem;color:#dc2626;font-weight:600;"></div>
      </div>
    </form>
  </div>
</div>

<script>
/* ── Template map ──────────────────────────────────────────────────── */
var TEMPLATES = <?= $templateJson ?>;

function applyTemplate(name) {
  if (!name || !TEMPLATES[name]) return;
  document.getElementById('txtMessage').value = TEMPLATES[name];
  updateCharCount();
}

/* ── Recipient selection ──────────────────────────────────────────── */
var currentRecip = null;
function selectRecip(val) {
  currentRecip = val;
  /* Card highlight */
  document.querySelectorAll('.recip-card').forEach(function(c){ c.classList.remove('active'); });
  var card = document.getElementById('rc-' + val);
  if (card) card.classList.add('active');
  /* Mark radio checked */
  var radios = document.querySelectorAll('[name=cbxNoticeTo]');
  radios.forEach(function(r){ r.checked = (r.value === val); });
  /* Show/hide sub-fields */
  document.querySelectorAll('.recip-subfield').forEach(function(el){ el.classList.remove('visible'); });
  var sub = document.getElementById('sub-' + val);
  if (sub) sub.classList.add('visible');
}

/* ── Channel selection ────────────────────────────────────────────── */
var currentChannel = 'EMail';
function selectChannel(val, el) {
  currentChannel = val;
  document.querySelectorAll('.ch-btn').forEach(function(b){ b.classList.remove('active'); });
  el.classList.add('active');
  /* update radio */
  document.querySelectorAll('[name=cbxNoticeThr]').forEach(function(r){ r.checked=(r.value===val); });
  /* Show/hide SMS warning */
  var showSms = (val === 'SMS' || val === 'BOTH');
  document.getElementById('smsWarning').style.display = showSms ? '' : 'none';
  updateCharCount();
}

/* ── Character counter ────────────────────────────────────────────── */
function updateCharCount() {
  var msg  = (document.getElementById('txtMessage').value  || '').length;
  var subj = (document.getElementById('txtSubject').value  || '').length;
  var total = msg;
  var el = document.getElementById('charCount');
  var isSms = currentChannel === 'SMS' || currentChannel === 'BOTH';

  if (isSms) {
    el.textContent = (subj + msg) + ' / 160 characters (subject + message)';
    el.className   = (subj + msg) > 160 ? 'over' : ((subj + msg) > 130 ? 'warn' : '');
  } else {
    el.textContent = total + ' characters';
    el.className   = '';
  }
}
document.getElementById('txtSubject').addEventListener('input', updateCharCount);

/* ── File label ───────────────────────────────────────────────────── */
function showFileName(input) {
  var lbl = document.getElementById('fileLabel');
  lbl.textContent = input.files.length ? input.files[0].name : 'No file chosen';
  lbl.style.color = input.files.length ? 'inherit' : '';
}

/* ── Form validation ──────────────────────────────────────────────── */
document.getElementById('frmNotices').addEventListener('submit', function(e) {
  var errEl = document.getElementById('formError');
  errEl.style.display = 'none';

  var subject = document.getElementById('txtSubject').value.trim();
  var message = document.getElementById('txtMessage').value.trim();

  if (!subject) {
    show('Please enter a subject.'); e.preventDefault(); return;
  }
  if (!message) {
    show('Please enter a message.'); e.preventDefault(); return;
  }
  if (!currentRecip) {
    show('Please select who to send the notice to.'); e.preventDefault(); return;
  }
  if (currentRecip === 'Student') {
    var email = document.getElementById('txtEMail').value.trim();
    if (!email || email.indexOf('@') === -1 || email.indexOf('.') === -1) {
      show('Please enter a valid email address for the recipient.'); e.preventDefault(); return;
    }
  }
  if (currentRecip === 'SpecificGrp') {
    if (!document.getElementById('txtEmailGrp').value || document.getElementById('txtEmailGrp').value === '0') {
      show('Please select an email group.'); e.preventDefault(); return;
    }
  }
  if (currentChannel === 'SMS' || currentChannel === 'BOTH') {
    var total = subject.length + message.length;
    if (total > 160) {
      show('SMS limit exceeded: subject + message must be 160 characters or less. Currently ' + total + ' characters.'); e.preventDefault(); return;
    }
  }

  /* Visual feedback */
  document.getElementById('sendBtn').textContent = '⏳ Sending…';
  document.getElementById('sendBtn').disabled = true;

  function show(msg) { errEl.textContent = msg; errEl.style.display=''; document.querySelector('[name=cbxNoticeTo]')?.closest('.form-group')?.scrollIntoView({behavior:'smooth',block:'center'}); }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
