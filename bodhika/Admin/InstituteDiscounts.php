<?php
/**
 * Admin/InstituteDiscounts.php
 * Configure discount / free-exam rules per institute per subject.
 * Institute discount takes PRIORITY over coupon codes.
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
require_once __DIR__ . '/../Lib/Institute.php';
Auth::requireLogin('../auth/login.php');
if (!Auth::isAdmin()) { header('Location: ../auth/login.php'); exit; }

$instId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
if (!$instId) { header('Location: ManageInstitutes.php'); exit; }

$institute = Database::fetchOne(
    "SELECT * FROM institutes WHERE InstituteId = ? LIMIT 1", [$instId]);
if (!$institute) { header('Location: ManageInstitutes.php'); exit; }

$subjects = Database::fetchAll(
    "SELECT SubjectInfoId, SubjectName, COALESCE(ExamFee,0) AS ExamFee
       FROM subjectinfo WHERE Active='Y' ORDER BY SubjectName");

$msg = ''; $msgType = 'success';

/* ── POST: save / delete a rule ─────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::validateCsrf();
    $postAction = $_POST['post_action'] ?? '';

    if ($postAction === 'save') {
        $discountId  = (int)($_POST['DiscountId']   ?? 0);
        $subjectId   = (int)($_POST['SubjectInfoId'] ?? 0) ?: null; // null = all subjects
        $isFree      = isset($_POST['IsFree']) ? 1 : 0;
        $discType    = in_array($_POST['DiscountType'] ?? '', ['PCT','AMT']) ? $_POST['DiscountType'] : 'PCT';
        $discValue   = max(0.0, (float)($_POST['DiscountValue'] ?? 0));
        $validFrom   = trim($_POST['ValidFrom'] ?? '') ?: null;
        $validTo     = trim($_POST['ValidTo']   ?? '') ?: null;
        $active      = ($_POST['Active'] ?? 'Y') === 'Y' ? 'Y' : 'N';

        if (!$isFree && $discValue <= 0) {
            $msg = 'Discount value must be greater than 0, or enable Free Exam.'; $msgType = 'danger';
        } elseif ($discType === 'PCT' && !$isFree && $discValue > 100) {
            $msg = 'Percentage discount cannot exceed 100%.'; $msgType = 'danger';
        } else {
            try {
                if ($discountId > 0) {
                    Database::execute(
                        "UPDATE institute_subject_discounts
                            SET SubjectInfoId=?,IsFree=?,DiscountType=?,DiscountValue=?,
                                ValidFrom=?,ValidTo=?,Active=?,CreatedBy=?
                          WHERE DiscountId=? AND InstituteId=?",
                        [$subjectId,$isFree,$discType,$discValue,
                         $validFrom,$validTo,$active,Auth::currentUser(),
                         $discountId,$instId]);
                    $msg = 'Discount rule updated.';
                } else {
                    Database::execute(
                        "INSERT INTO institute_subject_discounts
                           (InstituteId,SubjectInfoId,IsFree,DiscountType,DiscountValue,
                            ValidFrom,ValidTo,Active,CreatedBy)
                         VALUES (?,?,?,?,?,?,?,?,?)
                         ON DUPLICATE KEY UPDATE
                           IsFree=VALUES(IsFree),DiscountType=VALUES(DiscountType),
                           DiscountValue=VALUES(DiscountValue),ValidFrom=VALUES(ValidFrom),
                           ValidTo=VALUES(ValidTo),Active=VALUES(Active),
                           CreatedBy=VALUES(CreatedBy)",
                        [$instId,$subjectId,$isFree,$discType,$discValue,
                         $validFrom,$validTo,$active,Auth::currentUser()]);
                    $msg = 'Discount rule saved.';
                }
            } catch (Exception $e) {
                $msg = 'Error: ' . $e->getMessage(); $msgType = 'danger';
            }
        }
    }

    if ($postAction === 'delete') {
        $discountId = (int)($_POST['DiscountId'] ?? 0);
        Database::execute(
            "DELETE FROM institute_subject_discounts WHERE DiscountId=? AND InstituteId=?",
            [$discountId, $instId]);
        $msg = 'Discount rule removed.';
    }

    if ($postAction === 'toggle') {
        $discountId = (int)($_POST['DiscountId'] ?? 0);
        $cur = Database::fetchOne(
            "SELECT Active FROM institute_subject_discounts WHERE DiscountId=? LIMIT 1",
            [$discountId]);
        if ($cur) {
            $new = $cur['Active'] === 'Y' ? 'N' : 'Y';
            Database::execute(
                "UPDATE institute_subject_discounts SET Active=? WHERE DiscountId=?",
                [$new, $discountId]);
            $msg = 'Rule ' . ($new === 'Y' ? 'activated.' : 'deactivated.');
        }
    }
}

/* ── Load existing rules ─────────────────────────────────────────────────── */
$rules = Database::fetchAll(
    "SELECT isd.*,
            COALESCE(s.SubjectName, '— All Subjects —') AS SubjectName,
            COALESCE(s.ExamFee, 0) AS ExamFee
       FROM institute_subject_discounts isd
  LEFT JOIN subjectinfo s ON s.SubjectInfoId = isd.SubjectInfoId
      WHERE isd.InstituteId = ?
      ORDER BY isd.SubjectInfoId IS NULL DESC, s.SubjectName",
    [$instId]);

/* Subject map for effective-fee preview */
$subjectFeeMap = [];
foreach ($subjects as $s) {
    $subjectFeeMap[(int)$s['SubjectInfoId']] = (float)$s['ExamFee'];
}

$pageTitle = 'Institute Discounts — ' . ($institute['InstituteName'] ?? '');
include __DIR__ . '/../includes/header.php';
?>
<style>
.card{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:20px 24px;margin-bottom:20px;}
.rule-row{background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:14px 18px;margin-bottom:10px;display:flex;align-items:center;gap:16px;flex-wrap:wrap;}
.priority-badge{background:#7c3aed;color:#fff;padding:3px 10px;border-radius:10px;font-size:.72rem;font-weight:700;}
.free-badge{background:#065f46;color:#fff;padding:3px 10px;border-radius:10px;font-size:.72rem;font-weight:700;}
.disc-value{font-size:1.2rem;font-weight:700;color:#1e40af;}
</style>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
  <div>
    <h2 style="margin:0;">Discount Rules</h2>
    <div style="color:#6b7280;font-size:.9rem;">
      <?php echo htmlspecialchars($institute['InstituteName']); ?> &bull;
      <?php echo htmlspecialchars($institute['InstituteType']); ?> &bull;
      <?php echo htmlspecialchars($institute['State']); ?>
    </div>
  </div>
  <a href="ManageInstitutes.php?action=view&id=<?php echo $instId; ?>" class="btn btn-secondary">Back to Institute</a>
</div>

<div style="background:#fffbeb;border:1px solid #fcd34d;border-radius:6px;padding:10px 16px;margin-bottom:16px;font-size:.875rem;">
  <strong>Priority note:</strong> Institute discounts override coupon codes. If a student's institute has a rule for a subject, their coupon is ignored. A wildcard rule (All Subjects) applies to every subject that has no specific rule.
</div>

<?php if ($msg !== ''): ?>
<div style="background:<?php echo $msgType==='success'?'#d1fae5;color:#065f46':'#fee2e2;color:#991b1b'; ?>;padding:10px 16px;border-radius:6px;margin-bottom:16px;">
  <?php echo htmlspecialchars($msg); ?>
</div>
<?php endif; ?>

<!-- Existing rules -->
<div class="card">
  <h3 style="margin-top:0;">Active Rules <span class="priority-badge">INSTITUTE PRIORITY</span></h3>
  <?php if (empty($rules)): ?>
    <p style="color:#888;">No discount rules configured yet. Add one below.</p>
  <?php endif; ?>
  <?php foreach ($rules as $rule):
    $effectiveFee = $rule['SubjectInfoId']
        ? ($subjectFeeMap[(int)$rule['SubjectInfoId']] ?? 0)
        : null;
    $discStr = $rule['IsFree'] ? 'FREE' :
        ($rule['DiscountType']==='PCT'
            ? $rule['DiscountValue'].'%'
            : '₹'.number_format($rule['DiscountValue'],2));
    $today = date('Y-m-d');
    $expired = ($rule['ValidTo'] && $rule['ValidTo'] < $today);
    $notStarted = ($rule['ValidFrom'] && $rule['ValidFrom'] > $today);
  ?>
  <div class="rule-row" style="opacity:<?php echo $rule['Active']==='Y'&&!$expired&&!$notStarted?1:0.55; ?>">
    <div style="flex:2;min-width:160px;">
      <div style="font-weight:600;"><?php echo htmlspecialchars($rule['SubjectName']); ?></div>
      <?php if ($rule['SubjectInfoId'] === null): ?>
        <div style="font-size:.75rem;color:#7c3aed;">Wildcard — covers all subjects</div>
      <?php endif; ?>
    </div>
    <div style="flex:1;text-align:center;">
      <?php if ($rule['IsFree']): ?>
        <span class="free-badge">FREE EXAM</span>
      <?php else: ?>
        <span class="disc-value"><?php echo $discStr; ?></span>
        <div style="font-size:.75rem;color:#6b7280;">
          <?php echo $rule['DiscountType']==='PCT'?'Percentage off':'Fixed amount off'; ?>
        </div>
      <?php endif; ?>
    </div>
    <?php if ($effectiveFee !== null && !$rule['IsFree']): ?>
    <div style="flex:1;text-align:center;font-size:.82rem;color:#6b7280;">
      ₹<?php echo number_format($effectiveFee,2); ?> →
      <?php
        $disc = $rule['DiscountType']==='PCT'
          ? round($effectiveFee * $rule['DiscountValue'] / 100, 2)
          : min($rule['DiscountValue'], $effectiveFee);
        $final = max(0, $effectiveFee - $disc);
        echo $final > 0 ? '₹'.number_format($final,2) : '<strong style="color:#065f46">FREE</strong>';
      ?>
    </div>
    <?php endif; ?>
    <div style="flex:1;font-size:.8rem;color:#6b7280;">
      <?php if ($rule['ValidFrom']): echo 'From: '.$rule['ValidFrom'].'<br>'; endif; ?>
      <?php if ($rule['ValidTo']):   echo 'To: '.$rule['ValidTo']; endif; ?>
      <?php if ($expired): echo '<span style="color:#dc2626;"> (Expired)</span>'; endif; ?>
      <?php if ($notStarted): echo '<span style="color:#d97706;"> (Not started)</span>'; endif; ?>
    </div>
    <div style="display:flex;gap:6px;flex-shrink:0;">
      <!-- Toggle active -->
      <form method="post" style="display:inline;">
        <input type="hidden" name="csrf_token" value="<?php echo Auth::csrfToken(); ?>">
        <input type="hidden" name="post_action" value="toggle">
        <input type="hidden" name="DiscountId" value="<?php echo $rule['DiscountId']; ?>">
        <button type="submit" class="btn btn-sm"
                style="background:<?php echo $rule['Active']==='Y'?'#d1fae5;color:#065f46':'#fee2e2;color:#991b1b'; ?>">
          <?php echo $rule['Active']==='Y'?'On':'Off'; ?>
        </button>
      </form>
      <!-- Edit: open form below populated -->
      <a href="?id=<?php echo $instId; ?>&edit=<?php echo $rule['DiscountId']; ?>"
         class="btn btn-sm btn-primary">Edit</a>
      <!-- Delete -->
      <form method="post" style="display:inline;"
            onsubmit="return confirm('Delete this rule?')">
        <input type="hidden" name="csrf_token" value="<?php echo Auth::csrfToken(); ?>">
        <input type="hidden" name="post_action" value="delete">
        <input type="hidden" name="DiscountId" value="<?php echo $rule['DiscountId']; ?>">
        <button type="submit" class="btn btn-sm" style="background:#fee2e2;color:#991b1b;">Delete</button>
      </form>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Add / Edit form -->
<?php
$editRule = null;
$editId   = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT) ?: 0;
if ($editId) {
    $editRule = Database::fetchOne(
        "SELECT * FROM institute_subject_discounts WHERE DiscountId=? AND InstituteId=? LIMIT 1",
        [$editId, $instId]);
}
?>
<div class="card">
  <h3 style="margin-top:0;"><?php echo $editRule ? 'Edit Rule' : 'Add New Rule'; ?></h3>
  <form method="post" action="?id=<?php echo $instId; ?>">
    <input type="hidden" name="csrf_token" value="<?php echo Auth::csrfToken(); ?>">
    <input type="hidden" name="post_action" value="save">
    <input type="hidden" name="DiscountId" value="<?php echo $editRule ? $editRule['DiscountId'] : 0; ?>">

    <div style="display:grid;grid-template-columns:2fr 1fr 1fr 1fr 1fr;gap:12px;align-items:end;flex-wrap:wrap;">
      <div class="form-group" style="margin:0;">
        <label>Subject <small style="color:#6b7280;">(leave blank = all subjects)</small></label>
        <select name="SubjectInfoId" class="form-control">
          <option value="">— All Subjects (Wildcard) —</option>
          <?php $curSubj = $editRule ? $editRule['SubjectInfoId'] : ($_POST['SubjectInfoId'] ?? '');
          foreach ($subjects as $s): ?>
            <option value="<?php echo $s['SubjectInfoId']; ?>"
              <?php echo $curSubj == $s['SubjectInfoId'] ? 'selected' : ''; ?>>
              <?php echo htmlspecialchars($s['SubjectName']); ?>
              (₹<?php echo number_format($s['ExamFee'],2); ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="margin:0;">
        <label>Discount Type</label>
        <select name="DiscountType" class="form-control" id="discType"
                onchange="toggleFreeCheck()">
          <option value="PCT" <?php echo (!$editRule||$editRule['DiscountType']==='PCT')?'selected':''; ?>>Percentage (%)</option>
          <option value="AMT" <?php echo ($editRule&&$editRule['DiscountType']==='AMT')?'selected':''; ?>>Fixed Amount (₹)</option>
        </select>
      </div>
      <div class="form-group" style="margin:0;">
        <label>Discount Value</label>
        <input type="number" name="DiscountValue" class="form-control" id="discValue"
               min="0" step="0.01"
               value="<?php echo $editRule ? $editRule['DiscountValue'] : ''; ?>">
      </div>
      <div class="form-group" style="margin:0;">
        <label>Valid From</label>
        <input type="date" name="ValidFrom" class="form-control"
               value="<?php echo $editRule ? ($editRule['ValidFrom']??'') : ''; ?>">
      </div>
      <div class="form-group" style="margin:0;">
        <label>Valid To</label>
        <input type="date" name="ValidTo" class="form-control"
               value="<?php echo $editRule ? ($editRule['ValidTo']??'') : ''; ?>">
      </div>
    </div>

    <div style="display:flex;gap:24px;align-items:center;margin-top:14px;">
      <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-weight:600;">
        <input type="checkbox" name="IsFree" id="chkFree" value="1"
               onchange="toggleFreeCheck()"
               <?php echo ($editRule && $editRule['IsFree']) ? 'checked' : ''; ?>>
        <span style="color:#065f46;">Free Exam (100% waived — ignores discount value)</span>
      </label>
      <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
        Active:
        <select name="Active" class="form-control" style="width:80px;">
          <option value="Y" <?php echo (!$editRule||$editRule['Active']==='Y')?'selected':''; ?>>Yes</option>
          <option value="N" <?php echo ($editRule&&$editRule['Active']==='N')?'selected':''; ?>>No</option>
        </select>
      </label>
    </div>

    <div style="margin-top:16px;display:flex;gap:10px;">
      <button type="submit" class="btn btn-primary"><?php echo $editRule ? 'Update Rule' : 'Add Rule'; ?></button>
      <?php if ($editRule): ?>
        <a href="?id=<?php echo $instId; ?>" class="btn btn-secondary">Cancel Edit</a>
      <?php endif; ?>
    </div>
  </form>
</div>
<script>
function toggleFreeCheck() {
  var free = document.getElementById('chkFree').checked;
  document.getElementById('discValue').disabled = free;
  document.getElementById('discType').disabled  = free;
  if (free) { document.getElementById('discValue').value = 100; }
}
toggleFreeCheck();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
