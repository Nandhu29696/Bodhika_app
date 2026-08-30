<?php
/**
 * Admin/ManageCoupons.php — CRUD for discount_coupons table.
 *
 * Actions (via POST ?action=):
 *   save   — insert or update a coupon
 *   toggle — flip Active flag
 *   delete — hard-delete (only if UsedCount = 0)
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');
if (!Auth::isAdmin()) { header('Location: ../auth/login.php'); exit; }

$subjects = Database::fetchAll(
    "SELECT SubjectInfoId, SubjectName FROM subjectinfo WHERE Active='Y' ORDER BY SubjectName");

$action  = $_POST['action'] ?? '';
$msg     = '';
$msgType = 'success';

/* ── Handle actions ──────────────────────────────────────────────────────── */
if ($action === 'save') {
    $couponId     = (int)($_POST['CouponId'] ?? 0);
    $code         = strtoupper(trim($_POST['Code']          ?? ''));
    $discType     = in_array($_POST['DiscountType'] ?? '', ['PCT','AMT']) ? $_POST['DiscountType'] : 'PCT';
    $discValue    = max(0.0, (float)($_POST['DiscountValue'] ?? 0));
    $maxUses      = max(0,   (int)($_POST['MaxUses']        ?? 0));
    $validFrom    = trim($_POST['ValidFrom'] ?? '') ?: null;
    $validTo      = trim($_POST['ValidTo']   ?? '') ?: null;
    $subjectId    = (int)($_POST['SubjectInfoId'] ?? 0) ?: null;
    $active       = ($_POST['Active'] ?? 'Y') === 'Y' ? 'Y' : 'N';

    if ($code === '') {
        $msg = 'Coupon code is required.'; $msgType = 'danger';
    } elseif ($discValue <= 0) {
        $msg = 'Discount value must be > 0.'; $msgType = 'danger';
    } else {
        try {
            if ($couponId > 0) {
                Database::execute(
                    "UPDATE discount_coupons
                        SET Code=?, DiscountType=?, DiscountValue=?, MaxUses=?,
                            ValidFrom=?, ValidTo=?, SubjectInfoId=?, Active=?
                      WHERE CouponId=?",
                    [$code, $discType, $discValue, $maxUses, $validFrom, $validTo, $subjectId, $active, $couponId]);
                $msg = 'Coupon updated.';
            } else {
                Database::execute(
                    "INSERT INTO discount_coupons
                        (Code, DiscountType, DiscountValue, MaxUses, ValidFrom, ValidTo, SubjectInfoId, Active)
                     VALUES (?,?,?,?,?,?,?,?)",
                    [$code, $discType, $discValue, $maxUses, $validFrom, $validTo, $subjectId, $active]);
                $msg = 'Coupon created.';
            }
        } catch (Exception $e) {
            $msg = 'Error: ' . $e->getMessage(); $msgType = 'danger';
        }
    }
}

if ($action === 'toggle') {
    $couponId = (int)($_POST['CouponId'] ?? 0);
    Database::execute(
        "UPDATE discount_coupons SET Active = IF(Active='Y','N','Y') WHERE CouponId=?", [$couponId]);
    $msg = 'Coupon status toggled.';
}

if ($action === 'delete') {
    $couponId = (int)($_POST['CouponId'] ?? 0);
    $check = Database::fetchOne(
        "SELECT UsedCount FROM discount_coupons WHERE CouponId=? LIMIT 1", [$couponId]);
    if ($check && (int)$check['UsedCount'] > 0) {
        $msg = 'Cannot delete a coupon that has been used. Deactivate it instead.'; $msgType = 'danger';
    } else {
        Database::execute("DELETE FROM discount_coupons WHERE CouponId=?", [$couponId]);
        $msg = 'Coupon deleted.';
    }
}

/* ── Load coupon being edited (if any) ───────────────────────────────────── */
$editId  = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT) ?: 0;
$editRow = [];
if ($editId > 0) {
    $editRow = Database::fetchOne(
        "SELECT * FROM discount_coupons WHERE CouponId=? LIMIT 1", [$editId]) ?: [];
}

/* ── Load all coupons ────────────────────────────────────────────────────── */
try {
    $coupons = Database::fetchAll(
        "SELECT c.*, s.SubjectName
           FROM discount_coupons c
      LEFT JOIN subjectinfo s ON s.SubjectInfoId = c.SubjectInfoId
          ORDER BY c.CouponId DESC");
} catch (Exception $e) {
    $coupons = [];
    $msg = 'discount_coupons table not found — please run migration_v13.sql.'; $msgType = 'danger';
}

$pageTitle = 'Manage Coupons';
include __DIR__ . '/../includes/header.php';
?>
<style>
  .coupon-form { max-width:680px; }
  .form-grid   { display:grid; grid-template-columns:1fr 1fr; gap:0 16px; }
  .span2       { grid-column:1/-1; }
  .badge-act   { display:inline-block;padding:2px 10px;border-radius:10px;font-size:.75rem;font-weight:700; }
  .badge-y     { background:#dcfce7;color:#166534; }
  .badge-n     { background:#fee2e2;color:#991b1b; }
</style>

<?php if ($msg): ?>
  <div class="alert alert-<?php echo $msgType; ?>" style="margin-bottom:16px;">
    <?php echo htmlspecialchars($msg); ?>
  </div>
<?php endif; ?>

<!-- ── Add / Edit form ─────────────────────────────────────────────────── -->
<div class="card coupon-form" style="margin-bottom:24px;">
  <div class="card-header">
    <?php echo $editId > 0 ? '&#9998; Edit Coupon' : '&#10010; New Coupon'; ?>
  </div>
  <div class="card-body">
    <form method="post" action="ManageCoupons.php<?php echo $editId > 0 ? '?edit='.$editId : ''; ?>">
      <input type="hidden" name="action"   value="save">
      <input type="hidden" name="CouponId" value="<?php echo (int)($editRow['CouponId'] ?? 0); ?>">

      <div class="form-grid">
        <!-- Code -->
        <div class="form-group">
          <label>Coupon Code <span style="color:#dc2626;">*</span></label>
          <input type="text" name="Code" class="form-control" maxlength="40"
                 style="text-transform:uppercase;"
                 value="<?php echo htmlspecialchars($editRow['Code'] ?? ''); ?>"
                 placeholder="e.g. EARLY20" required>
        </div>

        <!-- Active -->
        <div class="form-group">
          <label>Active</label>
          <select name="Active" class="form-control">
            <option value="Y" <?php echo (($editRow['Active'] ?? 'Y') === 'Y') ? 'selected' : ''; ?>>Yes</option>
            <option value="N" <?php echo (($editRow['Active'] ?? '') === 'N') ? 'selected' : ''; ?>>No</option>
          </select>
        </div>

        <!-- Discount Type -->
        <div class="form-group">
          <label>Discount Type <span style="color:#dc2626;">*</span></label>
          <select name="DiscountType" class="form-control" id="discType" onchange="updateLabel()">
            <option value="PCT" <?php echo (($editRow['DiscountType'] ?? 'PCT') === 'PCT') ? 'selected' : ''; ?>>Percentage (%)</option>
            <option value="AMT" <?php echo (($editRow['DiscountType'] ?? '') === 'AMT') ? 'selected' : ''; ?>>Fixed Amount (₹)</option>
          </select>
        </div>

        <!-- Discount Value -->
        <div class="form-group">
          <label>Discount Value <span style="color:#dc2626;">*</span> <span id="discUnit" style="color:#6b7280;font-weight:400;font-size:.82rem;"></span></label>
          <input type="number" name="DiscountValue" class="form-control" min="0.01" step="0.01"
                 value="<?php echo (float)($editRow['DiscountValue'] ?? ''); ?>"
                 placeholder="e.g. 20" required>
        </div>

        <!-- Max Uses -->
        <div class="form-group">
          <label>Max Uses <span style="color:#6b7280;font-size:.78rem;">(0 = unlimited)</span></label>
          <input type="number" name="MaxUses" class="form-control" min="0"
                 value="<?php echo (int)($editRow['MaxUses'] ?? 0); ?>">
        </div>

        <!-- Subject restriction -->
        <div class="form-group">
          <label>Restrict to Subject <span style="color:#6b7280;font-size:.78rem;">(optional)</span></label>
          <select name="SubjectInfoId" class="form-control">
            <option value="">— All Subjects —</option>
            <?php foreach ($subjects as $s): ?>
              <option value="<?php echo (int)$s['SubjectInfoId']; ?>"
                <?php echo ((int)($editRow['SubjectInfoId'] ?? 0) === (int)$s['SubjectInfoId']) ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($s['SubjectName']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- Valid From -->
        <div class="form-group">
          <label>Valid From <span style="color:#6b7280;font-size:.78rem;">(optional)</span></label>
          <input type="date" name="ValidFrom" class="form-control"
                 value="<?php echo htmlspecialchars($editRow['ValidFrom'] ?? ''); ?>">
        </div>

        <!-- Valid To -->
        <div class="form-group">
          <label>Valid To <span style="color:#6b7280;font-size:.78rem;">(optional)</span></label>
          <input type="date" name="ValidTo" class="form-control"
                 value="<?php echo htmlspecialchars($editRow['ValidTo'] ?? ''); ?>">
        </div>
      </div>

      <div style="display:flex;gap:10px;margin-top:16px;">
        <button type="submit" class="btn btn-primary">&#128190; Save Coupon</button>
        <?php if ($editId > 0): ?>
          <a href="ManageCoupons.php" class="btn btn-secondary">&#10006; Cancel</a>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<!-- ── Coupon list ─────────────────────────────────────────────────────── -->
<div class="card">
  <div class="card-header">&#127381; All Coupons</div>
  <div class="card-body" style="padding:0;">
    <?php if (empty($coupons)): ?>
      <p style="padding:20px;color:#718096;text-align:center;">No coupons yet. Create one above.</p>
    <?php else: ?>
    <table class="tbl" style="font-size:.85rem;">
      <thead>
        <tr>
          <th>Code</th>
          <th>Type</th>
          <th>Value</th>
          <th>Subject</th>
          <th>Valid From</th>
          <th>Valid To</th>
          <th style="width:60px;">Uses</th>
          <th style="width:60px;">Max</th>
          <th style="width:60px;">Active</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($coupons as $i => $c): ?>
        <tr class="<?php echo ($i%2===0)?'odd':'even'; ?>">
          <td><strong style="font-family:monospace;"><?php echo htmlspecialchars($c['Code']); ?></strong></td>
          <td><?php echo $c['DiscountType'] === 'PCT' ? 'Percent' : 'Fixed ₹'; ?></td>
          <td>
            <?php if ($c['DiscountType'] === 'PCT'): ?>
              <?php echo number_format((float)$c['DiscountValue'], 1); ?>%
            <?php else: ?>
              ₹<?php echo number_format((float)$c['DiscountValue'], 2); ?>
            <?php endif; ?>
          </td>
          <td><?php echo $c['SubjectName'] ? htmlspecialchars($c['SubjectName']) : '<span style="color:#a0aec0;">All</span>'; ?></td>
          <td><?php echo $c['ValidFrom'] ? date('d M Y', strtotime($c['ValidFrom'])) : '—'; ?></td>
          <td><?php echo $c['ValidTo']   ? date('d M Y', strtotime($c['ValidTo']))   : '—'; ?></td>
          <td class="text-center"><?php echo (int)$c['UsedCount']; ?></td>
          <td class="text-center"><?php echo (int)$c['MaxUses'] > 0 ? (int)$c['MaxUses'] : '∞'; ?></td>
          <td class="text-center">
            <span class="badge-act <?php echo $c['Active'] === 'Y' ? 'badge-y' : 'badge-n'; ?>">
              <?php echo $c['Active'] === 'Y' ? 'Yes' : 'No'; ?>
            </span>
          </td>
          <td>
            <div class="flex gap-2" style="flex-wrap:wrap;">
              <a href="ManageCoupons.php?edit=<?php echo (int)$c['CouponId']; ?>"
                 class="btn btn-warning btn-sm">&#9998; Edit</a>
              <form method="post" action="ManageCoupons.php" style="display:inline;">
                <input type="hidden" name="action"   value="toggle">
                <input type="hidden" name="CouponId" value="<?php echo (int)$c['CouponId']; ?>">
                <button type="submit" class="btn btn-sm"
                        style="background:#6b7280;color:#fff;">
                  <?php echo $c['Active'] === 'Y' ? '&#9898; Deactivate' : '&#9899; Activate'; ?>
                </button>
              </form>
              <?php if ((int)$c['UsedCount'] === 0): ?>
              <form method="post" action="ManageCoupons.php" style="display:inline;"
                    onsubmit="return confirm('Delete coupon <?php echo addslashes($c['Code']); ?>?');">
                <input type="hidden" name="action"   value="delete">
                <input type="hidden" name="CouponId" value="<?php echo (int)$c['CouponId']; ?>">
                <button type="submit" class="btn btn-danger btn-sm">&#128465; Delete</button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<script>
function updateLabel() {
  var t = document.getElementById('discType').value;
  document.getElementById('discUnit').textContent = t === 'PCT' ? '(0–100)' : '(₹ amount)';
}
updateLabel();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
