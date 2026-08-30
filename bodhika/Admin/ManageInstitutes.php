<?php
/**
 * Admin/ManageInstitutes.php — Full CRUD for institutes with contacts.
 *
 * Actions (GET ?action=):
 *   list    — paginated institute list (default)
 *   add     — blank add form
 *   edit    — edit form for ?id=N
 *   view    — read-only detail + contacts
 *
 * Actions (POST ?action=):
 *   save    — insert or update institute + contacts
 *   toggle  — flip Active flag
 *   delete_contact — remove a single contact (only if not primary or another primary exists)
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
require_once __DIR__ . '/../Lib/Institute.php';
Auth::requireLogin('../auth/login.php');
if (!Auth::isAdmin()) { header('Location: ../auth/login.php'); exit; }

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
$id     = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$flash  = '';
$flashType = 'success';

$TYPES  = ['Private','Govt','Semi-Govt','Autonomous','Trust','Other'];
$STATES = [
    'Andhra Pradesh','Arunachal Pradesh','Assam','Bihar','Chhattisgarh',
    'Goa','Gujarat','Haryana','Himachal Pradesh','Jharkhand','Karnataka',
    'Kerala','Madhya Pradesh','Maharashtra','Manipur','Meghalaya','Mizoram',
    'Nagaland','Odisha','Punjab','Rajasthan','Sikkim','Tamil Nadu','Telangana',
    'Tripura','Uttar Pradesh','Uttarakhand','West Bengal',
    'Andaman & Nicobar Islands','Chandigarh','Dadra & Nagar Haveli & Daman & Diu',
    'Delhi','Jammu & Kashmir','Ladakh','Lakshadweep','Puducherry','Other'
];

/* ── POST handlers ────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::validateCsrf();

    if ($action === 'toggle') {
        $tid = (int)($_POST['id'] ?? 0);
        $cur = Database::fetchOne("SELECT Active FROM institutes WHERE InstituteId=? LIMIT 1", [$tid]);
        if ($cur) {
            $new = $cur['Active'] === 'Y' ? 'N' : 'Y';
            Database::execute("UPDATE institutes SET Active=? WHERE InstituteId=?", [$new, $tid]);
        }
        header('Location: ManageInstitutes.php?flash=toggled'); exit;
    }

    if ($action === 'delete_contact') {
        $cid   = (int)($_POST['contact_id'] ?? 0);
        $instId = (int)($_POST['inst_id'] ?? 0);
        // Never delete the only primary contact
        $isPrimary = Database::fetchOne(
            "SELECT IsPrimary FROM institute_contacts WHERE ContactId=? LIMIT 1", [$cid]);
        $primaryCount = Database::fetchOne(
            "SELECT COUNT(*) AS cnt FROM institute_contacts
              WHERE InstituteId=? AND IsPrimary=1 AND Active='Y'", [$instId]);
        if ($isPrimary && $isPrimary['IsPrimary'] && (int)$primaryCount['cnt'] <= 1) {
            header("Location: ManageInstitutes.php?action=edit&id={$instId}&flash=cannot_delete_primary");
            exit;
        }
        Database::execute("UPDATE institute_contacts SET Active='N' WHERE ContactId=?", [$cid]);
        header("Location: ManageInstitutes.php?action=edit&id={$instId}&flash=contact_removed"); exit;
    }

    if ($action === 'save') {
        $instId      = (int)($_POST['InstituteId'] ?? 0);
        $name        = trim($_POST['InstituteName']  ?? '');
        $type        = in_array($_POST['InstituteType'] ?? '', $TYPES) ? $_POST['InstituteType'] : 'Private';
        $state       = trim($_POST['State']          ?? '');
        $city        = trim($_POST['CityVillage']    ?? '');
        $pin         = trim($_POST['PinCode']        ?? '');
        $address     = trim($_POST['Address']        ?? '');
        $email       = trim($_POST['Email']          ?? '');
        $phone       = trim($_POST['Phone']          ?? '');
        $website     = trim($_POST['Website']        ?? '');
        $notes       = trim($_POST['Notes']          ?? '');

        // Contacts from repeating fields
        $cIds    = $_POST['contact_id']    ?? [];
        $cNames  = $_POST['contact_name']  ?? [];
        $cDesig  = $_POST['contact_desig'] ?? [];
        $cEmails = $_POST['contact_email'] ?? [];
        $cPhones = $_POST['contact_phone'] ?? [];
        $cPrim   = (int)($_POST['primary_index'] ?? 0); // index of primary contact

        $errors = [];
        if ($name === '')  $errors[] = 'Institute name is required.';
        if ($state === '') $errors[] = 'State is required.';
        if ($city === '')  $errors[] = 'City / Village is required.';
        if (empty($cNames) || trim($cNames[0]) === '')
            $errors[] = 'At least one contact is required.';
        if (!empty($cPhones[0]) === false || trim($cPhones[0] ?? '') === '')
            $errors[] = 'Primary contact phone is required.';
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL))
            $errors[] = 'Institute email format is invalid.';

        if ($errors) {
            $flash = implode(' ', $errors); $flashType = 'danger';
            // Fall through to re-render form with data
        } else {
            if ($instId > 0) {
                Database::execute(
                    "UPDATE institutes SET InstituteName=?,InstituteType=?,State=?,CityVillage=?,
                      PinCode=?,Address=?,Email=?,Phone=?,Website=?,Notes=?
                     WHERE InstituteId=?",
                    [$name,$type,$state,$city,$pin,$address,$email,$phone,$website,$notes,$instId]);
            } else {
                Database::execute(
                    "INSERT INTO institutes
                      (InstituteName,InstituteType,State,CityVillage,PinCode,Address,Email,Phone,Website,Notes)
                     VALUES (?,?,?,?,?,?,?,?,?,?)",
                    [$name,$type,$state,$city,$pin,$address,$email,$phone,$website,$notes]);
                $instId = (int)Database::lastInsertId();
            }

            // Save contacts
            foreach ($cNames as $i => $cname) {
                $cname  = trim($cname);
                $cphone = trim($cPhones[$i] ?? '');
                if ($cname === '' && $cphone === '') continue;
                $isPrim = ($i === $cPrim) ? 1 : 0;
                $cid    = (int)($cIds[$i] ?? 0);
                if ($cid > 0) {
                    Database::execute(
                        "UPDATE institute_contacts
                            SET ContactName=?,Designation=?,Email=?,Phone=?,IsPrimary=?,Active='Y'
                          WHERE ContactId=? AND InstituteId=?",
                        [trim($cname), trim($cDesig[$i]??''), trim($cEmails[$i]??''),
                         $cphone, $isPrim, $cid, $instId]);
                } else {
                    if ($cname === '') continue;
                    Database::execute(
                        "INSERT INTO institute_contacts
                          (InstituteId,ContactName,Designation,Email,Phone,IsPrimary)
                         VALUES (?,?,?,?,?,?)",
                        [$instId, $cname, trim($cDesig[$i]??''),
                         trim($cEmails[$i]??''), $cphone, $isPrim]);
                }
            }
            // Ensure exactly one primary: clear duplicates
            Database::execute(
                "UPDATE institute_contacts SET IsPrimary=0 WHERE InstituteId=?", [$instId]);
            // Find first active contact to be primary if none explicitly set
            $firstContact = Database::fetchOne(
                "SELECT ContactId FROM institute_contacts
                  WHERE InstituteId=? AND Active='Y' ORDER BY IsPrimary DESC, ContactId LIMIT 1",
                [$instId]);
            if ($firstContact) {
                Database::execute(
                    "UPDATE institute_contacts SET IsPrimary=1 WHERE ContactId=?",
                    [(int)$firstContact['ContactId']]);
            }

            header("Location: ManageInstitutes.php?action=view&id={$instId}&flash=saved"); exit;
        }
    }
}

/* ── Load data for forms / views ──────────────────────────────────────────── */
$institute = null;
$contacts  = [];
if ($id > 0) {
    $institute = Database::fetchOne(
        "SELECT i.*,
                COUNT(DISTINCT u.UserInfoId) AS StudentCount
           FROM institutes i
      LEFT JOIN userinfo u ON u.InstituteId = i.InstituteId
          WHERE i.InstituteId = ?
          GROUP BY i.InstituteId LIMIT 1", [$id]);
    $contacts = Institute::contacts($id);
}

/* ── Paginated list ───────────────────────────────────────────────────────── */
$institutes = [];
$total = 0;
if ($action === 'list') {
    $search    = trim($_GET['q'] ?? '');
    $filterState = trim($_GET['state'] ?? '');
    $filterType  = trim($_GET['type']  ?? '');
    $page      = max(1, (int)($_GET['page'] ?? 1));
    $perPage   = 20;
    $offset    = ($page - 1) * $perPage;

    $where = []; $params = [];
    if ($search) {
        $where[] = '(i.InstituteName LIKE ? OR i.CityVillage LIKE ? OR i.Phone LIKE ?)';
        $like = '%'.$search.'%';
        $params = array_merge($params, [$like, $like, $like]);
    }
    if ($filterState) { $where[] = 'i.State = ?';         $params[] = $filterState; }
    if ($filterType)  { $where[] = 'i.InstituteType = ?'; $params[] = $filterType; }

    $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $countRow = Database::fetchOne(
        "SELECT COUNT(*) AS cnt FROM institutes i $whereSQL", $params);
    $total = (int)($countRow['cnt'] ?? 0);

    $institutes = Database::fetchAll(
        "SELECT i.*, COUNT(DISTINCT u.UserInfoId) AS StudentCount,
                (SELECT ContactName FROM institute_contacts
                  WHERE InstituteId=i.InstituteId AND IsPrimary=1 AND Active='Y' LIMIT 1) AS PrimaryContact
           FROM institutes i
      LEFT JOIN userinfo u ON u.InstituteId = i.InstituteId
         $whereSQL
          GROUP BY i.InstituteId
          ORDER BY i.State, i.InstituteName
          LIMIT ? OFFSET ?",
        array_merge($params, [$perPage, $offset]));
}

$titleMap  = ['add' => 'Add Institute', 'edit' => 'Edit Institute', 'view' => 'Institute Details'];
$pageTitle = isset($titleMap[$action]) ? $titleMap[$action] : 'Manage Institutes';
include __DIR__ . '/../includes/header.php';
?>
<style>
.inst-card{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:20px 24px;margin-bottom:16px;}
.contact-row{background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:12px;margin-bottom:10px;position:relative;}
.badge-type{display:inline-block;padding:2px 10px;border-radius:12px;font-size:.75rem;font-weight:600;}
.badge-Govt{background:#dcfce7;color:#166534;}
.badge-Private{background:#dbeafe;color:#1e40af;}
.badge-Semi-Govt{background:#fef9c3;color:#854d0e;}
.badge-Autonomous{background:#f3e8ff;color:#6b21a8;}
.badge-Trust{background:#ffedd5;color:#9a3412;}
.badge-Other{background:#f1f5f9;color:#475569;}
.flash-success{background:#d1fae5;color:#065f46;padding:10px 16px;border-radius:6px;margin-bottom:16px;}
.flash-danger {background:#fee2e2;color:#991b1b;padding:10px 16px;border-radius:6px;margin-bottom:16px;}
.tbl th{background:#1e40af;color:#fff;padding:8px 10px;font-size:.82rem;}
.tbl td{padding:7px 10px;border-bottom:1px solid #f1f5f9;font-size:.85rem;vertical-align:middle;}
.tbl tr:hover td{background:#f0f7ff;}
</style>

<?php
$urlFlash = $_GET['flash'] ?? '';
$flashMessages = [
    'saved'                  => ['Institute saved successfully.', 'success'],
    'toggled'                => ['Institute status updated.', 'success'],
    'contact_removed'        => ['Contact removed.', 'success'],
    'cannot_delete_primary'  => ['Cannot remove the only primary contact.', 'danger'],
];
if (isset($flashMessages[$urlFlash])): [$fm, $ft] = $flashMessages[$urlFlash]; ?>
  <div class="flash-<?php echo $ft; ?>"><?php echo htmlspecialchars($fm); ?></div>
<?php endif;
if ($flash !== ''): ?>
  <div class="flash-<?php echo $flashType; ?>"><?php echo htmlspecialchars($flash); ?></div>
<?php endif; ?>

<?php /* ═══════════════════════ LIST ═══════════════════════════ */ ?>
<?php if ($action === 'list'): ?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
  <h2 style="margin:0;">Institutes / Schools / Colleges</h2>
  <a href="?action=add" class="btn btn-primary">+ Add Institute</a>
</div>

<form method="get" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;">
  <input type="hidden" name="action" value="list">
  <input type="text" name="q" value="<?php echo htmlspecialchars($_GET['q']??''); ?>"
         placeholder="Search name, city, phone…" class="form-control" style="flex:2;min-width:180px;">
  <select name="state" class="form-control" style="flex:1;min-width:140px;">
    <option value="">All States</option>
    <?php foreach ($STATES as $s): ?>
      <option value="<?php echo $s; ?>" <?php echo (($_GET['state']??'')===$s)?'selected':''; ?>><?php echo $s; ?></option>
    <?php endforeach; ?>
  </select>
  <select name="type" class="form-control" style="flex:1;min-width:120px;">
    <option value="">All Types</option>
    <?php foreach ($TYPES as $t): ?>
      <option value="<?php echo $t; ?>" <?php echo (($_GET['type']??'')===$t)?'selected':''; ?>><?php echo $t; ?></option>
    <?php endforeach; ?>
  </select>
  <button type="submit" class="btn btn-secondary">Filter</button>
  <a href="?action=list" class="btn btn-secondary">Reset</a>
</form>

<table class="tbl" style="width:100%;border-collapse:collapse;">
  <thead><tr>
    <th>Institute Name</th><th>Type</th><th>State</th><th>City / Village</th>
    <th>Primary Contact</th><th>Students</th><th>Status</th><th>Actions</th>
  </tr></thead>
  <tbody>
  <?php if (empty($institutes)): ?>
    <tr><td colspan="8" style="text-align:center;color:#888;padding:24px;">No institutes found.</td></tr>
  <?php endif; ?>
  <?php foreach ($institutes as $inst): ?>
  <tr>
    <td><strong><?php echo htmlspecialchars($inst['InstituteName']); ?></strong></td>
    <td><span class="badge-type badge-<?php echo $inst['InstituteType']; ?>"><?php echo $inst['InstituteType']; ?></span></td>
    <td><?php echo htmlspecialchars($inst['State']); ?></td>
    <td><?php echo htmlspecialchars($inst['CityVillage']); ?></td>
    <td><?php echo htmlspecialchars($inst['PrimaryContact'] ?? '—'); ?></td>
    <td style="text-align:center;"><a href="InstituteStudents.php?id=<?php echo (int)$inst['InstituteId']; ?>"><?php echo (int)$inst['StudentCount']; ?></a></td>
    <td>
      <form method="post" style="display:inline;">
        <input type="hidden" name="csrf_token" value="<?php echo Auth::csrfToken(); ?>">
        <input type="hidden" name="action" value="toggle">
        <input type="hidden" name="id" value="<?php echo $inst['InstituteId']; ?>">
        <button type="submit" class="btn btn-sm"
                style="background:<?php echo $inst['Active']==='Y'?'#d1fae5;color:#065f46':'#fee2e2;color:#991b1b'; ?>">
          <?php echo $inst['Active']==='Y' ? 'Active' : 'Inactive'; ?>
        </button>
      </form>
    </td>
    <td>
      <a href="?action=view&id=<?php echo $inst['InstituteId']; ?>" class="btn btn-sm btn-secondary">View</a>
      <a href="?action=edit&id=<?php echo $inst['InstituteId']; ?>" class="btn btn-sm btn-primary">Edit</a>
      <a href="InstituteDiscounts.php?id=<?php echo $inst['InstituteId']; ?>" class="btn btn-sm" style="background:#7c3aed;color:#fff;">Discounts</a>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<?php /* Pagination */ ?>
<?php if ($total > 20):
  $pages = ceil($total / 20);
  $q = http_build_query(array_filter(['q'=>$_GET['q']??'','state'=>$_GET['state']??'','type'=>$_GET['type']??'','action'=>'list']));
?>
<div style="margin-top:16px;display:flex;gap:6px;">
  <?php for ($p = 1; $p <= $pages; $p++): ?>
    <a href="?<?php echo $q; ?>&page=<?php echo $p; ?>"
       style="padding:4px 10px;border-radius:4px;border:1px solid #e2e8f0;
              background:<?php echo $p===$page?'#1e40af;color:#fff':'#fff'; ?>;text-decoration:none;">
      <?php echo $p; ?>
    </a>
  <?php endfor; ?>
</div>
<?php endif; ?>

<?php /* ═══════════════════════ VIEW ═══════════════════════════ */ ?>
<?php elseif ($action === 'view' && $institute): ?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
  <h2 style="margin:0;"><?php echo htmlspecialchars($institute['InstituteName']); ?></h2>
  <div style="display:flex;gap:8px;">
    <a href="?action=edit&id=<?php echo $id; ?>" class="btn btn-primary">Edit</a>
    <a href="InstituteDiscounts.php?id=<?php echo $id; ?>" class="btn" style="background:#7c3aed;color:#fff;">Discounts</a>
    <a href="?action=list" class="btn btn-secondary">Back</a>
  </div>
</div>
<div class="inst-card">
  <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;">
    <div><label style="color:#6b7280;font-size:.8rem;">Type</label>
      <div><span class="badge-type badge-<?php echo $institute['InstituteType']; ?>"><?php echo $institute['InstituteType']; ?></span></div></div>
    <div><label style="color:#6b7280;font-size:.8rem;">State</label>
      <div><?php echo htmlspecialchars($institute['State']); ?></div></div>
    <div><label style="color:#6b7280;font-size:.8rem;">City / Village</label>
      <div><?php echo htmlspecialchars($institute['CityVillage']); ?></div></div>
    <div><label style="color:#6b7280;font-size:.8rem;">Pin Code</label>
      <div><?php echo htmlspecialchars($institute['PinCode'] ?: '—'); ?></div></div>
    <div><label style="color:#6b7280;font-size:.8rem;">Email</label>
      <div><?php echo htmlspecialchars($institute['Email'] ?: '—'); ?></div></div>
    <div><label style="color:#6b7280;font-size:.8rem;">Phone</label>
      <div><?php echo htmlspecialchars($institute['Phone'] ?: '—'); ?></div></div>
    <div><label style="color:#6b7280;font-size:.8rem;">Website</label>
      <div><?php echo $institute['Website'] ? '<a href="'.htmlspecialchars($institute['Website']).'" target="_blank">'.htmlspecialchars($institute['Website']).'</a>' : '—'; ?></div></div>
    <div><label style="color:#6b7280;font-size:.8rem;">Students</label>
      <div><strong><?php echo (int)$institute['StudentCount']; ?></strong>
        <a href="InstituteStudents.php?id=<?php echo $id; ?>" style="font-size:.8rem;margin-left:6px;">View all</a></div></div>
    <div><label style="color:#6b7280;font-size:.8rem;">Status</label>
      <div><?php echo $institute['Active']==='Y'?'<span style="color:#065f46">Active</span>':'<span style="color:#991b1b">Inactive</span>'; ?></div></div>
  </div>
  <?php if ($institute['Address']): ?>
    <div style="margin-top:12px;"><label style="color:#6b7280;font-size:.8rem;">Address</label>
      <div><?php echo nl2br(htmlspecialchars($institute['Address'])); ?></div></div>
  <?php endif; ?>
  <?php if ($institute['Notes']): ?>
    <div style="margin-top:12px;"><label style="color:#6b7280;font-size:.8rem;">Notes</label>
      <div><?php echo nl2br(htmlspecialchars($institute['Notes'])); ?></div></div>
  <?php endif; ?>
</div>

<h3>Contacts</h3>
<?php foreach ($contacts as $c): ?>
<div class="inst-card" style="display:flex;gap:24px;align-items:flex-start;padding:14px 20px;">
  <div style="flex:1;">
    <strong><?php echo htmlspecialchars($c['ContactName']); ?></strong>
    <?php if ($c['IsPrimary']): ?><span style="background:#dbeafe;color:#1e40af;font-size:.72rem;padding:1px 8px;border-radius:10px;margin-left:8px;">PRIMARY</span><?php endif; ?>
    <?php if ($c['Designation']): ?><div style="color:#6b7280;font-size:.85rem;"><?php echo htmlspecialchars($c['Designation']); ?></div><?php endif; ?>
  </div>
  <div style="min-width:160px;">📞 <?php echo htmlspecialchars($c['Phone']); ?></div>
  <div style="min-width:200px;"><?php echo $c['Email'] ? '✉ '.htmlspecialchars($c['Email']) : ''; ?></div>
</div>
<?php endforeach; ?>

<?php /* ═══════════════════════ ADD / EDIT FORM ════════════════ */ ?>
<?php elseif (in_array($action, ['add','edit'])): ?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
  <h2 style="margin:0;"><?php echo $pageTitle; ?></h2>
  <a href="?action=list" class="btn btn-secondary">Back to List</a>
</div>

<form method="post" action="?action=save">
  <input type="hidden" name="csrf_token" value="<?php echo Auth::csrfToken(); ?>">
  <input type="hidden" name="action" value="save">
  <input type="hidden" name="InstituteId" value="<?php echo $id; ?>">

  <div class="inst-card">
    <h3 style="margin-top:0;">Institute Details</h3>
    <div style="display:grid;grid-template-columns:2fr 1fr;gap:12px;">
      <div class="form-group">
        <label>Institute Name <span style="color:red">*</span></label>
        <input type="text" name="InstituteName" class="form-control" required
               value="<?php echo htmlspecialchars($institute['InstituteName'] ?? $_POST['InstituteName'] ?? ''); ?>">
      </div>
      <div class="form-group">
        <label>Type <span style="color:red">*</span></label>
        <select name="InstituteType" class="form-control">
          <?php $curType = $institute['InstituteType'] ?? $_POST['InstituteType'] ?? 'Private';
          foreach ($TYPES as $t): ?>
            <option value="<?php echo $t; ?>" <?php echo $t===$curType?'selected':''; ?>><?php echo $t; ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;">
      <div class="form-group">
        <label>State <span style="color:red">*</span></label>
        <select name="State" class="form-control" required>
          <option value="">— Select State —</option>
          <?php $curState = $institute['State'] ?? $_POST['State'] ?? '';
          foreach ($STATES as $s): ?>
            <option value="<?php echo $s; ?>" <?php echo $s===$curState?'selected':''; ?>><?php echo $s; ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>City / Village <span style="color:red">*</span></label>
        <input type="text" name="CityVillage" class="form-control" required
               value="<?php echo htmlspecialchars($institute['CityVillage'] ?? $_POST['CityVillage'] ?? ''); ?>">
      </div>
      <div class="form-group">
        <label>Pin Code</label>
        <input type="text" name="PinCode" class="form-control" maxlength="10"
               value="<?php echo htmlspecialchars($institute['PinCode'] ?? $_POST['PinCode'] ?? ''); ?>">
      </div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;">
      <div class="form-group">
        <label>Institute Email</label>
        <input type="email" name="Email" class="form-control"
               value="<?php echo htmlspecialchars($institute['Email'] ?? $_POST['Email'] ?? ''); ?>">
      </div>
      <div class="form-group">
        <label>Institute Phone</label>
        <input type="tel" name="Phone" class="form-control"
               value="<?php echo htmlspecialchars($institute['Phone'] ?? $_POST['Phone'] ?? ''); ?>">
      </div>
      <div class="form-group">
        <label>Website</label>
        <input type="url" name="Website" class="form-control" placeholder="https://"
               value="<?php echo htmlspecialchars($institute['Website'] ?? $_POST['Website'] ?? ''); ?>">
      </div>
    </div>
    <div class="form-group">
      <label>Address</label>
      <textarea name="Address" class="form-control" rows="2"><?php echo htmlspecialchars($institute['Address'] ?? $_POST['Address'] ?? ''); ?></textarea>
    </div>
    <div class="form-group">
      <label>Notes</label>
      <textarea name="Notes" class="form-control" rows="2"><?php echo htmlspecialchars($institute['Notes'] ?? $_POST['Notes'] ?? ''); ?></textarea>
    </div>
  </div>

  <!-- Contacts -->
  <div class="inst-card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
      <h3 style="margin:0;">Contacts <small style="color:#6b7280;font-weight:400;">(minimum 1 required)</small></h3>
      <button type="button" onclick="addContact()" class="btn btn-secondary btn-sm">+ Add Contact</button>
    </div>
    <div id="contacts-wrap">
    <?php
    $displayContacts = !empty($contacts) ? $contacts :
        [['ContactId'=>0,'ContactName'=>'','Designation'=>'','Email'=>'','Phone'=>'','IsPrimary'=>1]];
    foreach ($displayContacts as $ci => $c):
    ?>
    <div class="contact-row" id="cr-<?php echo $ci; ?>">
      <input type="hidden" name="contact_id[]" value="<?php echo (int)$c['ContactId']; ?>">
      <div style="display:grid;grid-template-columns:2fr 1fr 1fr 1fr auto;gap:10px;align-items:end;">
        <div class="form-group" style="margin:0;">
          <label style="font-size:.8rem;">Name <?php echo $ci===0?'<span style="color:red">*</span>':''; ?></label>
          <input type="text" name="contact_name[]" class="form-control"
                 <?php echo $ci===0?'required':''; ?>
                 value="<?php echo htmlspecialchars($c['ContactName']); ?>">
        </div>
        <div class="form-group" style="margin:0;">
          <label style="font-size:.8rem;">Designation</label>
          <input type="text" name="contact_desig[]" class="form-control"
                 value="<?php echo htmlspecialchars($c['Designation']??''); ?>">
        </div>
        <div class="form-group" style="margin:0;">
          <label style="font-size:.8rem;">Email</label>
          <input type="email" name="contact_email[]" class="form-control"
                 value="<?php echo htmlspecialchars($c['Email']??''); ?>">
        </div>
        <div class="form-group" style="margin:0;">
          <label style="font-size:.8rem;">Phone <?php echo $ci===0?'<span style="color:red">*</span>':''; ?></label>
          <input type="tel" name="contact_phone[]" class="form-control"
                 <?php echo $ci===0?'required':''; ?>
                 value="<?php echo htmlspecialchars($c['Phone']); ?>">
        </div>
        <div style="display:flex;flex-direction:column;gap:4px;align-items:center;">
          <label style="font-size:.72rem;color:#6b7280;">Primary</label>
          <input type="radio" name="primary_index" value="<?php echo $ci; ?>"
                 <?php echo $c['IsPrimary']?'checked':''; ?>>
        </div>
      </div>
      <?php if ($ci > 0 && (int)$c['ContactId'] > 0): ?>
      <form method="post" style="margin-top:6px;text-align:right;"
            onsubmit="return confirm('Remove this contact?')">
        <input type="hidden" name="csrf_token" value="<?php echo Auth::csrfToken(); ?>">
        <input type="hidden" name="action" value="delete_contact">
        <input type="hidden" name="contact_id" value="<?php echo (int)$c['ContactId']; ?>">
        <input type="hidden" name="inst_id" value="<?php echo $id; ?>">
        <button type="submit" class="btn btn-sm" style="background:#fee2e2;color:#991b1b;">Remove</button>
      </form>
      <?php elseif ($ci > 0): ?>
      <div style="text-align:right;margin-top:4px;">
        <button type="button" onclick="removeContact(<?php echo $ci; ?>)" class="btn btn-sm" style="background:#fee2e2;color:#991b1b;">Remove</button>
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
    </div>
  </div>

  <div style="display:flex;gap:10px;">
    <button type="submit" class="btn btn-primary">Save Institute</button>
    <a href="?action=list" class="btn btn-secondary">Cancel</a>
  </div>
</form>

<script>
var contactIdx = <?php echo count($displayContacts); ?>;
function addContact() {
  var i = contactIdx++;
  var html = `<div class="contact-row" id="cr-${i}">
    <input type="hidden" name="contact_id[]" value="0">
    <div style="display:grid;grid-template-columns:2fr 1fr 1fr 1fr auto;gap:10px;align-items:end;">
      <div class="form-group" style="margin:0;"><label style="font-size:.8rem;">Name</label>
        <input type="text" name="contact_name[]" class="form-control"></div>
      <div class="form-group" style="margin:0;"><label style="font-size:.8rem;">Designation</label>
        <input type="text" name="contact_desig[]" class="form-control"></div>
      <div class="form-group" style="margin:0;"><label style="font-size:.8rem;">Email</label>
        <input type="email" name="contact_email[]" class="form-control"></div>
      <div class="form-group" style="margin:0;"><label style="font-size:.8rem;">Phone</label>
        <input type="tel" name="contact_phone[]" class="form-control"></div>
      <div style="display:flex;flex-direction:column;gap:4px;align-items:center;">
        <label style="font-size:.72rem;color:#6b7280;">Primary</label>
        <input type="radio" name="primary_index" value="${i}">
      </div>
    </div>
    <div style="text-align:right;margin-top:4px;">
      <button type="button" onclick="removeContact(${i})" class="btn btn-sm" style="background:#fee2e2;color:#991b1b;">Remove</button>
    </div>
  </div>`;
  document.getElementById('contacts-wrap').insertAdjacentHTML('beforeend', html);
}
function removeContact(i) {
  var el = document.getElementById('cr-' + i);
  if (el) el.remove();
}
</script>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
