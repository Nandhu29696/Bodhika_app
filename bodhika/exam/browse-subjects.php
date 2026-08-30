<?php
/**
 * exam/browse-subjects.php — Exam catalogue for self-enrollment.
 *
 * migration_v51: pricing is exam-level only — a "subject" is now purely an
 * organisational grouping (no fee of its own), so this page lists exams,
 * grouped under their subject as a heading, each with its own fee, discount,
 * and enrollment status. No admin assignment required — students enroll in
 * (and pay for, if priced) individual exams themselves.
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
require_once __DIR__ . '/../Lib/Enrollment.php';
require_once __DIR__ . '/../Lib/ExamType.php';
Auth::requireLogin('../auth/login.php');

$myUid   = (int)Auth::currentUserId();
$isAdmin = Auth::isAdmin();

/* ── Load all active exams (under active subjects) ─────────────────────────── *
 * examinfo.ExamFee / ExamDiscountPct are per-exam pricing columns added by
 * migrations/migration_v50.sql + migration_v51.sql. If those haven't been run
 * yet on this database, fall back to the pre-v51 model (one fee/discount per
 * subject, inherited by every exam under it) instead of hard-failing the page —
 * same defensive pattern already used elsewhere in this codebase (e.g.
 * exam/search.php's $hasLanguageCol) for columns a newer release depends on. */
/* IsQuestionBank (migration_v65) — a bank exam (potentially hundreds/
   thousands of questions) is a pool other exams get built from, never
   something a student should be able to self-enroll into. COALESCE keeps
   this catalogue working unchanged on a database that hasn't run
   migration_v65 yet (column simply doesn't exist -> COALESCE never fires,
   nothing is filtered out). */
$questionBankFilter = Database::hasColumn('examinfo', 'IsQuestionBank')
    ? "AND COALESCE(e.IsQuestionBank,'N') = 'N'" : '';

if (Database::hasColumn('examinfo', 'ExamFee')) {
    $exams = Database::fetchAll(
        "SELECT e.ExamInfoId, e.ExamName, e.SubjectInfoId,
                COALESCE(e.ExamFee, 0)         AS ExamFee,
                COALESCE(e.ExamDiscountPct, 0) AS ExamDiscountPct,
                s.SubjectName
           FROM examinfo e
           JOIN subjectinfo s ON s.SubjectInfoId = e.SubjectInfoId
          WHERE s.Active = 'Y'
            AND COALESCE(e.IsActive,'Y') = 'Y'
            AND COALESCE(e.IsDeleted,'N') = 'N'
            $questionBankFilter
          ORDER BY s.SubjectName, e.ExamName");
} else {
    // Legacy (pre migration_v50/v51): pricing lived on subjectinfo only, so
    // every exam under a subject shared that one fee/discount.
    $exams = Database::fetchAll(
        "SELECT e.ExamInfoId, e.ExamName, e.SubjectInfoId,
                COALESCE(s.ExamFee, 0)     AS ExamFee,
                COALESCE(s.DiscountPct, 0) AS ExamDiscountPct,
                s.SubjectName
           FROM examinfo e
           JOIN subjectinfo s ON s.SubjectInfoId = e.SubjectInfoId
          WHERE s.Active = 'Y'
            AND COALESCE(e.IsActive,'Y') = 'Y'
            AND COALESCE(e.IsDeleted,'N') = 'N'
            $questionBankFilter
          ORDER BY s.SubjectName, e.ExamName");
}

/* ── Load this user's payment records for all these exams ──────────────────── */
$payMap = [];
if ($myUid && !empty($exams)) {
    $examIds = array_column($exams, 'ExamInfoId');
    $ph = implode(',', array_fill(0, count($examIds), '?'));
    try {
        $efRows = Database::fetchAll(
            "SELECT ExamInfoId, PaymentStatus, StartDate, EndDate, FinalAmount
               FROM exam_fee_payments
              WHERE UserInfoId = ? AND ExamInfoId IN ($ph)",
            array_merge([$myUid], $examIds));
        foreach ($efRows as $er) {
            $payMap[(int)$er['ExamInfoId']] = $er;
        }
    } catch (Exception $e) { /* migration_v50/v51 not yet run */ }
}

/* Which exams the student is already explicitly assigned to (admin-driven —
   those don't need a self-enroll button at all, they show up in My Exams). */
$assignedIds = [];
if ($myUid && !empty($exams)) {
    try {
        $examIds = array_column($exams, 'ExamInfoId');
        $ph = implode(',', array_fill(0, count($examIds), '?'));
        $assignedIds = array_column(Database::fetchAll(
            "SELECT ExamInfoId FROM exam_assignments WHERE UserInfoId = ? AND ExamInfoId IN ($ph)",
            array_merge([$myUid], $examIds)
        ), 'ExamInfoId');
        $assignedIds = array_map('intval', $assignedIds);
    } catch (Exception $e) {}
}

/* Admin-assigned exams belong exclusively on exam/search.php ("Upcoming
   Exams") — this catalogue is for self-service discovery/enrollment, and
   an exam a student is already assigned needs neither. It used to still
   render here as a non-actionable "Assigned to you" card, which meant the
   same exam showed up in two places at once; now it's simply not part of
   this list, same as it's never been part of it for an admin browsing
   their own view. */
if (!empty($assignedIds)) {
    $exams = array_values(array_filter($exams,
        fn($ex) => !in_array((int)$ex['ExamInfoId'], $assignedIds, true)));
}

/* Scholarship check */
$isScholarship = false;
try {
    $uRow = Database::fetchOne(
        "SELECT ScholarshipFlag FROM userinfo WHERE UserInfoId = ? LIMIT 1", [$myUid]);
    $isScholarship = (($uRow['ScholarshipFlag'] ?? 'N') === 'Y');
} catch (Exception $e) {}

/* Student-group "Recommended for you" badges (migration_v53) — purely a
   display hint, does not affect access or which exams show up here. */
$recommendedIds = [];
if (file_exists(__DIR__ . '/../Lib/StudentGroup.php')) {
    require_once __DIR__ . '/../Lib/StudentGroup.php';
    $recommendedIds = $myUid ? StudentGroup::getRecommendedExamIds($myUid) : [];
}

$today = date('Y-m-d');

/* Exam type (NEET/JEE/GRE/GMAT/UPSC/Other) — migrations/migration_v55.sql.
   Falls back to every exam showing as "Uncategorized" if that migration
   hasn't run yet, or for any exam an admin hasn't classified, rather than
   failing the page — same resilience pattern as the ExamFee fallback above. */
$categoryMap = []; // ExamInfoId => ExamCategory
/* Country (migrations/migration_v64.sql) — examinfo.ExamCountry, when an
   admin has explicitly set it, always wins over the Type-derived guess
   (ExamType::resolveCountry()'s precedence rule); falls back to [] (every
   exam resolves purely via Type) if that migration hasn't run yet, same
   resilience pattern as ExamCategory just above. */
$countryMap = []; // ExamInfoId => ExamCountry
if (!empty($exams) && Database::hasColumn('examinfo', 'ExamCategory')) {
    try {
        $examIds = array_column($exams, 'ExamInfoId');
        $ph = implode(',', array_fill(0, count($examIds), '?'));
        $hasCountryCol = Database::hasColumn('examinfo', 'ExamCountry');
        $countryCol = $hasCountryCol ? ', ExamCountry' : '';
        $catRows = Database::fetchAll(
            "SELECT ExamInfoId, ExamCategory$countryCol FROM examinfo WHERE ExamInfoId IN ($ph)", $examIds);
        foreach ($catRows as $cr) {
            $catId = (int)$cr['ExamInfoId'];
            $categoryMap[$catId] = trim($cr['ExamCategory'] ?? '');
            if ($hasCountryCol) $countryMap[$catId] = trim($cr['ExamCountry'] ?? '');
        }
    } catch (Exception $e) {}
}

/* Group exams by subject (existing view) and by type (new view) */
$bySubject = [];
$byType    = [];
$typeOrder = ['NEET' => 1, 'JEE' => 2, 'GRE' => 3, 'GMAT' => 4, 'UPSC' => 5, 'Other' => 6, 'Uncategorized' => 7];
foreach ($exams as $ex) {
    $bySubject[(int)$ex['SubjectInfoId']]['name'] = $ex['SubjectName'];
    $bySubject[(int)$ex['SubjectInfoId']]['exams'][] = $ex;

    $cat    = $categoryMap[(int)$ex['ExamInfoId']] ?? '';
    $catKey = $cat !== '' ? $cat : 'Uncategorized';
    $byType[$catKey]['name'] = $catKey === 'Uncategorized' ? 'Other / Uncategorized' : $catKey;
    $byType[$catKey]['exams'][] = $ex;
}
uksort($byType, fn($a, $b) => ($typeOrder[$a] ?? 99) <=> ($typeOrder[$b] ?? 99));

/* Distinct background color per exam-type section, so it's visually obvious
   where one type's group of exams ends and the next begins when scrolling
   the "By Type" tab. Known types get fixed, deliberately-chosen colors;
   anything an admin has typed in beyond those (see exam/manage.php's free-
   text Exam Type field) still gets its own consistent color instead of
   falling back to a single generic shade every time — deterministic per
   name, so the same custom type always renders the same color. */
/* examTypeColor()/examTypeFlag() now live in Lib/ExamType.php (shared with
   Admin/ExamSearch.php and exam/search.php's type filters) — thin wrappers
   kept here so the many call sites below don't all need renaming. */
function examTypeColor(string $key): string { return ExamType::color($key); }
/* flagIconHtml() (real flag image), not flag() (Unicode emoji) — this page
   only calls examTypeFlag() from span/div contexts that can render HTML, so
   an actual flag image shows on every OS instead of Windows falling back to
   bare letters like "IN" for the flag emoji's regional-indicator codepoints. */
function examTypeFlag(string $key): string { return ExamType::flagIconHtml($key); }

$pageTitle = 'Browse Exams';
include __DIR__ . '/../includes/header.php';
?>
<style>
  .subject-section  { margin-bottom:28px; }
  .subject-heading   { font-size:1.05rem;font-weight:700;color:#1e3a5f;margin:0 0 12px;
                       padding-bottom:6px;border-bottom:2px solid #e2e8f0; }
  .exam-card-grid    { display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px; }
  .exam-card         { border:1px solid #e2e8f0;border-radius:10px;padding:16px;background:#fff;
                       display:flex;flex-direction:column;justify-content:space-between; }
  .card-exam-name    { font-size:.95rem;font-weight:700;color:#1e3a5f;margin:0 0 8px; }
  .card-recommended  { display:inline-block;background:#ede9fe;color:#5b21b6;font-size:.7rem;font-weight:700;
                       padding:2px 8px;border-radius:8px;margin-bottom:6px; }
  .card-fee          { font-size:1.3rem;font-weight:800;color:#1e40af;margin-bottom:2px; }
  .card-fee-orig     { font-size:.78rem;color:#94a3b8;text-decoration:line-through; }
  .card-discount     { font-size:.76rem;color:#059669;font-weight:600; }
  .enroll-status     { margin-top:8px;padding:5px 10px;border-radius:8px;font-size:.78rem;font-weight:600;display:inline-block; }
  .status-paid       { background:#dcfce7;color:#166534; }
  .status-pending    { background:#fef9c3;color:#92400e; }
  .status-expired    { background:#fee2e2;color:#991b1b; }
  .status-free       { background:#e0f2fe;color:#0369a1; }
  .status-assigned   { background:#ede9fe;color:#6d28d9; }
  .btn-enroll        { width:100%;margin-top:12px;padding:9px;font-size:.85rem;font-weight:700;
                       background:#3b82f6;color:#fff;border:none;border-radius:6px;
                       cursor:pointer;text-decoration:none;display:block;text-align:center; }
  .btn-enroll:hover  { background:#2563eb;text-decoration:none; }
  .btn-enroll.locked { background:#dc2626; }
  .expiry-note       { font-size:.72rem;color:#6b7280;margin-top:4px; }
  .search-bar        { max-width:340px; }
  .tabs-bar          { display:flex;gap:8px;flex-wrap:wrap;margin:14px 0 18px; }
  .tab-btn           { padding:8px 20px;border-radius:20px;font-size:.85rem;font-weight:700;
                       color:#4a5568;background:#f1f5f9;border:1.5px solid transparent;
                       cursor:pointer;transition:.15s; }
  .tab-btn:hover     { background:#e2e8f0; }
  .tab-btn.active    { background:#1e3a5f;border-color:#1e3a5f;color:#fff; }
  .quick-filter      { max-width:200px;padding:7px 10px;font-size:.82rem; }
  /* Colored banner heading — only used in the "By Type" panel, so it's
     visually obvious where one exam type's section ends and the next
     begins; background color is assigned per type in PHP (examTypeColor()). */
  .type-heading      { font-size:1.05rem;font-weight:700;color:#fff;margin:0 0 12px;
                       padding:9px 16px;border-radius:8px;border-bottom:none; }
</style>

<?php
/* One exam card, used by both the "By Subject" and "By Type" panels below so
   the two views can never drift out of sync with each other. $searchLabel is
   folded into data-name so the search box matches on subject name in one
   panel and exam type in the other. */
$renderExamCard = function (array $ex, string $searchLabel) use ($isScholarship, $assignedIds, $payMap, $recommendedIds, $today, $categoryMap, $countryMap) {
    $eid        = (int)$ex['ExamInfoId'];
    /* Country flag next to this exam's type (NEET=India, GRE=USA, etc., or
       whatever an admin explicitly set via examinfo.ExamCountry — that
       always wins, see ExamType::resolveCountry()) — looked up per-exam via
       $categoryMap/$countryMap so it shows correctly in both the "By
       Subject" and "By Type" panels, not just the type-grouped one. Left
       blank for exams with no ExamCategory/ExamCountry set at all, rather
       than showing a generic globe on every uncategorized card. */
    $examCat     = $categoryMap[$eid] ?? '';
    $resolvedRow = ['ExamCategory' => $examCat, 'ExamCountry' => $countryMap[$eid] ?? ''];
    $examCountry = ExamType::resolveCountry($resolvedRow);
    // Direct call, not the examTypeFlag() wrapper below — that one only
    // takes a bare Type key (used for the group heading, which represents
    // many exams and has no single row to resolve), whereas this card is
    // for one specific exam and should prefer its own explicit ExamCountry.
    $typeFlag    = $examCat !== '' ? ExamType::resolveFlagIconHtml($resolvedRow) : '';
    $fee        = (float)$ex['ExamFee'];
    $disc       = (float)$ex['ExamDiscountPct'];
    $discFee    = $disc > 0 ? max(0, $fee - round($fee * $disc / 100, 2)) : $fee;
    $isFree     = ($fee <= 0 || $isScholarship);
    $isAssigned = in_array($eid, $assignedIds, true);

    $ep       = $payMap[$eid] ?? null;
    $epStatus = $ep['PaymentStatus'] ?? '';
    $epEnd    = $ep['EndDate']   ?? null;
    $epStart  = $ep['StartDate'] ?? null;
    /* Scholarship / assignment already grant access with no payment row
       required — a merely zero-fee exam still needs an actual explicit
       enrollment record, same as a paid one. */
    $isEnrolled = $isAssigned || $isScholarship || in_array($epStatus, ['Paid','Waived','Free'], true);
    $isExpired  = ($isEnrolled && !$isAssigned && $epEnd && $epEnd < $today);
    $isPending  = (!$isEnrolled && $epStatus === 'Pending');
    ?>
    <div class="exam-card" data-name="<?php echo strtolower(htmlspecialchars($ex['ExamName'] . ' ' . $searchLabel)); ?>">
      <div>
        <?php if (in_array($eid, $recommendedIds, true)): ?>
          <div class="card-recommended">&#11088; Recommended for you</div>
        <?php endif; ?>
        <div class="card-exam-name">
          <?php if ($typeFlag !== ''): $flagTitle = $examCat . ($examCountry !== '' ? ' — ' . $examCountry : ''); ?><span title="<?php echo htmlspecialchars($flagTitle); ?>"><?php echo $typeFlag; ?></span> <?php endif; ?><?php echo htmlspecialchars($ex['ExamName']); ?>
        </div>

        <?php if ($isAssigned): ?>
          <div class="enroll-status status-assigned">&#128101; Assigned to you</div>
        <?php else: ?>
          <!-- Fee display -->
          <?php if ($isFree): ?>
            <div class="card-fee" style="color:#059669;">Free</div>
            <?php if ($isScholarship && $fee > 0): ?>
              <div class="card-fee-orig">&#8377;<?php echo number_format($fee, 2); ?></div>
              <div class="card-discount">&#127891; Scholarship applied</div>
            <?php endif; ?>
          <?php else: ?>
            <div class="card-fee">&#8377;<?php echo number_format($discFee, 2); ?></div>
            <?php if ($disc > 0): ?>
              <div class="card-fee-orig">&#8377;<?php echo number_format($fee, 2); ?></div>
              <div class="card-discount">&#128722; <?php echo number_format($disc, 0); ?>% off</div>
            <?php endif; ?>
          <?php endif; ?>

          <!-- Enrollment status -->
          <?php if ($isExpired): ?>
            <div class="enroll-status status-expired">&#9888; Enrollment Expired</div>
            <?php if ($epEnd): ?>
              <div class="expiry-note">Expired on <?php echo date('d M Y', strtotime($epEnd)); ?></div>
            <?php endif; ?>
          <?php elseif ($isEnrolled): ?>
            <div class="enroll-status <?php echo $isFree ? 'status-free' : 'status-paid'; ?>">
              &#10004; <?php echo $isFree ? 'Free Access' : 'Enrolled'; ?>
            </div>
            <?php if ($epStart): ?>
              <div class="expiry-note">
                Since <?php echo date('d M Y', strtotime($epStart)); ?>
                <?php if ($epEnd): ?>
                  &bull; Expires <?php echo date('d M Y', strtotime($epEnd)); ?>
                <?php else: ?>
                  &bull; <span style="color:#059669;">No expiry</span>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          <?php elseif ($isPending): ?>
            <div class="enroll-status status-pending">&#9203; Payment Pending</div>
          <?php endif; ?>
        <?php endif; ?>
      </div>

      <!-- Action button -->
      <div>
        <?php if ($isAssigned): ?>
          <a href="search.php" class="btn-enroll" style="background:#0f766e;">&#10004; Go to My Exams</a>
        <?php elseif ($isExpired): ?>
          <a href="enroll-exam.php?examId=<?php echo $eid; ?>&from=browse" class="btn-enroll locked">&#128274; Re-enroll</a>
        <?php elseif ($isEnrolled): ?>
          <a href="search.php" class="btn-enroll" style="background:#0f766e;">&#10004; Access via My Exams</a>
        <?php elseif ($isPending): ?>
          <a href="enroll-exam.php?examId=<?php echo $eid; ?>&from=browse"
             class="btn-enroll" style="background:#d97706;">&#9203; Complete Payment</a>
        <?php elseif ($isScholarship): ?>
          <a href="search.php" class="btn-enroll" style="background:#059669;">&#9998; Start Exam (Free)</a>
        <?php elseif ($isFree): ?>
          <a href="enroll-exam.php?examId=<?php echo $eid; ?>&from=browse" class="btn-enroll" style="background:#059669;">
            &#9998; Enroll (Free)
          </a>
        <?php else: ?>
          <a href="enroll-exam.php?examId=<?php echo $eid; ?>&from=browse"
             class="btn-enroll locked">&#128274; Enroll &mdash; &#8377;<?php echo number_format($discFee, 2); ?></a>
        <?php endif; ?>
      </div>
    </div>
    <?php
};
?>

<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:8px;">
  <div>
    <h2 style="margin:0;font-size:1.2rem;color:#1e3a5f;">&#128218; Browse &amp; Enroll in Exams</h2>
    <p style="margin:4px 0 0;color:#6b7280;font-size:.85rem;">
      <?php if ($isScholarship): ?>
        &#11088; You have a scholarship — every exam is free for you.
      <?php else: ?>
        Each exam has its own price. Enroll in one to access it — payment is processed securely via Razorpay.
      <?php endif; ?>
    </p>
  </div>
  <a href="search.php" class="btn btn-secondary btn-sm">&#8592; Back to My Exams</a>
</div>

<?php if (isset($_GET['enrolled'])): ?>
  <div class="alert alert-success">&#10004; Enrollment confirmed! You can now access this exam.</div>
<?php endif; ?>
<?php if (!empty($_GET['msg'])): ?>
  <div class="alert alert-info" style="background:#eff6ff;border:1px solid #93c5fd;color:#1e40af;">
    &#8505; <?php echo htmlspecialchars($_GET['msg']); ?>
  </div>
<?php endif; ?>

<!-- Search -->
<div class="search-bar" style="margin:12px 0 20px;">
  <input type="text" id="searchInput" class="form-control" placeholder="&#128269; Search exams or subjects…"
         oninput="filterCards(this.value)">
</div>

<!-- View tabs -->
<div class="tabs-bar">
  <button type="button" class="tab-btn" data-tab="subject" onclick="switchTab('subject')">&#128218; By Subject</button>
  <select id="subjectQuickFilter" class="form-control quick-filter" onchange="quickFilterPanel('panelSubject', this.value)">
    <option value="">All Subjects</option>
    <?php foreach ($bySubject as $group): ?>
      <option value="<?php echo strtolower(htmlspecialchars($group['name'])); ?>"><?php echo htmlspecialchars($group['name']); ?></option>
    <?php endforeach; ?>
  </select>

  <button type="button" class="tab-btn active" data-tab="type" onclick="switchTab('type')">&#127919; By Type <span style="font-weight:400;opacity:.85;">(&#127470;&#127475; NEET / JEE / UPSC &bull; &#127482;&#127480; GRE / GMAT)</span></button>
  <select id="typeQuickFilter" class="form-control quick-filter" onchange="quickFilterPanel('panelType', this.value)">
    <option value="">All Types</option>
    <?php foreach ($byType as $typeKey => $group): ?>
      <?php /* Plain text here — <option> elements can't render the HTML <img>
               that examTypeFlag() now returns; the colored section heading
               below (which supports HTML) still shows the real flag icon. */ ?>
      <option value="<?php echo strtolower(htmlspecialchars($group['name'])); ?>"><?php echo htmlspecialchars($group['name']); ?></option>
    <?php endforeach; ?>
  </select>
</div>

<div id="examCatalog">

  <div id="panelSubject" class="tab-panel" style="display:none;">
  <?php if (empty($bySubject)): ?>
    <p style="color:#718096;">No exams available yet.</p>
  <?php endif; ?>
  <?php foreach ($bySubject as $subjectId => $group): ?>
  <div class="subject-section" data-subject="<?php echo strtolower(htmlspecialchars($group['name'])); ?>">
    <div class="subject-heading"><?php echo htmlspecialchars($group['name']); ?></div>
    <div class="exam-card-grid">
      <?php foreach ($group['exams'] as $ex) { $renderExamCard($ex, $group['name']); } ?>
    </div>
  </div>
  <?php endforeach; ?>
  </div>

  <div id="panelType" class="tab-panel">
  <?php if (empty($byType)): ?>
    <p style="color:#718096;">No exams available yet.</p>
  <?php endif; ?>
  <?php foreach ($byType as $typeKey => $group):
    $bandColor  = examTypeColor($typeKey);
    $headingFlag = examTypeFlag($typeKey); // '' for India — see Lib/ExamType.php::SUPPRESS_FLAG_FOR
  ?>
  <div class="subject-section" data-subject="<?php echo strtolower(htmlspecialchars($group['name'])); ?>">
    <div class="subject-heading type-heading" style="background:<?php echo $bandColor; ?>;"><?php echo $headingFlag !== '' ? $headingFlag . ' ' : ''; ?><?php echo htmlspecialchars($group['name']); ?></div>
    <div class="exam-card-grid">
      <?php foreach ($group['exams'] as $ex) { $renderExamCard($ex, $group['name']); } ?>
    </div>
  </div>
  <?php endforeach; ?>
  </div>

</div>

<script>
function switchTab(which) {
  document.getElementById('panelSubject').style.display = which === 'subject' ? '' : 'none';
  document.getElementById('panelType').style.display     = which === 'type'    ? '' : 'none';
  document.querySelectorAll('.tab-btn').forEach(function(b) {
    b.classList.toggle('active', b.dataset.tab === which);
  });
}

// Quick-filter dropdown next to each tab button — jumps straight to one
// subject/type's section instead of scrolling, by hiding every other
// .subject-section in that panel. Independent of the free-text search box
// below (both just toggle the same elements' display, so using them
// together works, it just means "match both").
function quickFilterPanel(panelId, value) {
  // The dropdown only touches its own panel's sections — but "By Type" is the
  // default active tab, so picking a subject while that tab is showing was
  // filtering an already-hidden panel and appeared to do nothing. Jump to the
  // matching tab first so the filtered result is actually visible.
  switchTab(panelId === 'panelSubject' ? 'subject' : 'type');
  value = value.toLowerCase();
  document.querySelectorAll('#' + panelId + ' .subject-section').forEach(function(section) {
    section.style.display = (!value || section.dataset.subject === value) ? '' : 'none';
  });
}

function filterCards(q) {
  q = q.toLowerCase().trim();
  document.querySelectorAll('#examCatalog .exam-card').forEach(function(card) {
    card.style.display = (!q || card.dataset.name.includes(q)) ? '' : 'none';
  });
  /* Hide a subject section entirely if every card in it is filtered out */
  document.querySelectorAll('#examCatalog .subject-section').forEach(function(section) {
    var anyVisible = Array.prototype.some.call(
      section.querySelectorAll('.exam-card'), function(c) { return c.style.display !== 'none'; }
    );
    section.style.display = anyVisible ? '' : 'none';
  });
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
