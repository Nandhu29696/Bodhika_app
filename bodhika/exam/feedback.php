<?php
/**
 * exam/feedback.php
 * User-friendly multi-step feedback & suggestions form.
 * Available to all logged-in users (students, teachers, admins).
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');

$pageTitle   = 'Share Your Feedback';
$currentUser = Auth::currentUser();
$uid         = (int)($_SESSION['UserInfoId'] ?? 0);
$role        = $_SESSION['Role'] ?? 'Student';

$success = false;
$error   = '';

/* ── Handle POST ─────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $overall   = max(1, min(5, (int)($_POST['overall_rating']    ?? 0)));
    $examExp   = (int)($_POST['exam_exp_rating']   ?? 0) ?: null;
    $ui        = (int)($_POST['ui_rating']         ?? 0) ?: null;
    $perf      = (int)($_POST['perf_rating']       ?? 0) ?: null;
    $quality   = (int)($_POST['quality_rating']    ?? 0) ?: null;
    $support   = (int)($_POST['support_rating']    ?? 0) ?: null;
    $cats      = implode(',', array_map('trim', (array)($_POST['categories'] ?? [])));
    $liked     = trim($_POST['liked_most']         ?? '');
    $improve   = trim($_POST['improvements']       ?? '');
    $features  = trim($_POST['feature_requests']   ?? '');
    $recommend = in_array($_POST['recommend'] ?? '', ['Yes','No','Maybe'])
                 ? $_POST['recommend'] : null;
    $ip        = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;

    // Get institute from userinfo
    $instRow = null;
    try {
        $instRow = Database::fetchOne("SELECT InstituteId FROM userinfo WHERE UserInfoId=? LIMIT 1", [$uid]);
    } catch (Exception $e) {}
    $instId = $instRow ? ($instRow['InstituteId'] ?? null) : null;

    if ($overall < 1) {
        $error = 'Please give an overall rating before submitting.';
    } else {
        try {
            Database::execute(
                "INSERT INTO app_feedback
                    (UserInfoId,OverallRating,ExamExpRating,UIRating,PerfRating,
                     QualityRating,SupportRating,Categories,LikedMost,Improvements,
                     FeatureRequests,WouldRecommend,UserRole,InstituteId,IpAddress)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                [$uid,$overall,$examExp,$ui,$perf,$quality,$support,
                 $cats,$liked,$improve,$features,$recommend,$role,$instId,$ip]
            );
            $success = true;
        } catch (Exception $e) {
            $error = 'Could not save feedback. Please try again. (' . $e->getMessage() . ')';
        }
    }
}
include __DIR__ . '/../includes/header.php';
?>

<style>
/* ── Feedback page styles ───────────────────────────────── */
.fb-wrap{max-width:760px;margin:0 auto;padding:0 16px;}
.fb-header{text-align:center;margin-bottom:32px;}
.fb-header h1{font-size:1.8rem;color:#312e81;margin:0 0 8px;}
.fb-header p{color:#6b7280;font-size:1rem;}

/* Step indicator */
.fb-steps{display:flex;justify-content:center;gap:0;margin-bottom:36px;}
.fb-step{display:flex;flex-direction:column;align-items:center;flex:1;max-width:160px;position:relative;}
.fb-step:not(:last-child)::after{content:'';position:absolute;top:18px;left:60%;width:80%;height:2px;background:#e5e7eb;z-index:0;}
.fb-step.active::after,.fb-step.done::after{background:#7c3aed;}
.fb-step-circle{width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.85rem;z-index:1;transition:all .2s;}
.fb-step.pending .fb-step-circle{background:#e5e7eb;color:#9ca3af;border:2px solid #e5e7eb;}
.fb-step.active  .fb-step-circle{background:#7c3aed;color:#fff;border:2px solid #7c3aed;box-shadow:0 0 0 4px #ede9fe;}
.fb-step.done    .fb-step-circle{background:#059669;color:#fff;border:2px solid #059669;}
.fb-step-label{font-size:.72rem;color:#6b7280;margin-top:6px;text-align:center;font-weight:600;}
.fb-step.active .fb-step-label{color:#7c3aed;}
.fb-step.done   .fb-step-label{color:#059669;}

/* Cards */
.fb-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:28px 32px;margin-bottom:20px;box-shadow:0 2px 8px rgba(0,0,0,.04);}
.fb-card h2{font-size:1.1rem;color:#1e1b4b;margin:0 0 20px;display:flex;align-items:center;gap:10px;}

/* Star rating */
.star-group{display:flex;gap:6px;flex-wrap:wrap;}
.star-group input[type=radio]{display:none;}
.star-group label{font-size:2rem;cursor:pointer;color:#d1d5db;transition:color .15s,transform .1s;line-height:1;}
.star-group label:hover,.star-group label:hover ~ label,
.star-group input:checked ~ label{color:#d1d5db;}
.star-group label:hover,.star-group input:checked + label,
.star-group label.active{color:#f59e0b;}

/* Reverse trick for star hover */
.star-row{display:flex;flex-direction:row-reverse;gap:4px;}
.star-row input[type=radio]{display:none;}
.star-row label{font-size:2rem;cursor:pointer;color:#d1d5db;transition:color .15s;}
.star-row label:hover,.star-row label:hover ~ label,
.star-row input[type=radio]:checked ~ label{color:#f59e0b;}

/* Mini star row */
.star-row-sm label{font-size:1.5rem;}

/* Category chips */
.chip-grid{display:flex;flex-wrap:wrap;gap:10px;}
.chip-grid input[type=checkbox]{display:none;}
.chip-grid label{padding:8px 16px;border:2px solid #e5e7eb;border-radius:24px;cursor:pointer;
                  font-size:.88rem;font-weight:600;color:#374151;background:#f9fafb;
                  transition:all .15s;user-select:none;}
.chip-grid label:hover{border-color:#7c3aed;color:#7c3aed;background:#f5f3ff;}
.chip-grid input:checked + label{border-color:#7c3aed;background:#7c3aed;color:#fff;}

/* Sub-ratings grid */
.sub-ratings{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
@media(max-width:560px){.sub-ratings{grid-template-columns:1fr;}}
.sub-rating-item{background:#f9fafb;border-radius:8px;padding:14px 16px;}
.sub-rating-item label{font-size:.85rem;font-weight:700;color:#374151;display:block;margin-bottom:8px;}

/* Recommend pills */
.recommend-pills{display:flex;gap:12px;flex-wrap:wrap;}
.recommend-pills input{display:none;}
.recommend-pills label{padding:10px 28px;border:2px solid #e5e7eb;border-radius:30px;
                        cursor:pointer;font-size:.95rem;font-weight:700;color:#374151;
                        transition:all .15s;}
.rec-yes  label:hover,.recommend-pills input#rec_yes:checked  + label{border-color:#059669;background:#ecfdf5;color:#059669;}
.rec-no   label:hover,.recommend-pills input#rec_no:checked   + label{border-color:#dc2626;background:#fef2f2;color:#dc2626;}
.rec-maybe label:hover,.recommend-pills input#rec_maybe:checked + label{border-color:#d97706;background:#fffbeb;color:#d97706;}

/* Textarea */
.fb-textarea{width:100%;padding:12px;border:1px solid #d1d5db;border-radius:8px;
              font-size:.9rem;resize:vertical;font-family:inherit;
              transition:border-color .15s;box-sizing:border-box;}
.fb-textarea:focus{outline:none;border-color:#7c3aed;box-shadow:0 0 0 3px #ede9fe;}

/* Nav buttons */
.fb-nav{display:flex;justify-content:space-between;align-items:center;margin-top:28px;gap:12px;}
.fb-btn{padding:12px 28px;border-radius:8px;font-size:.95rem;font-weight:700;cursor:pointer;border:none;transition:all .15s;}
.fb-btn-primary{background:#7c3aed;color:#fff;}
.fb-btn-primary:hover{background:#6d28d9;}
.fb-btn-secondary{background:#f3f4f6;color:#374151;border:1px solid #d1d5db;}
.fb-btn-secondary:hover{background:#e5e7eb;}
.fb-btn-submit{background:#059669;color:#fff;padding:14px 36px;font-size:1rem;}
.fb-btn-submit:hover{background:#047857;}

/* Section heading */
.sect-title{font-size:.75rem;font-weight:800;text-transform:uppercase;letter-spacing:.8px;
             color:#6b7280;margin:20px 0 10px;}

/* Success */
.fb-success{text-align:center;padding:48px 24px;}
.fb-success .check{font-size:4rem;margin-bottom:16px;}
.fb-success h2{color:#059669;font-size:1.5rem;margin:0 0 10px;}
.fb-success p{color:#6b7280;margin:0 0 24px;}

.hidden{display:none!important;}
.progress-bar{height:4px;background:#ede9fe;border-radius:2px;margin-bottom:28px;}
.progress-fill{height:100%;background:#7c3aed;border-radius:2px;transition:width .3s;}
</style>

<div class="fb-wrap">

<?php if ($success): ?>
<!-- ── Success state ──────────────────────────────────────── -->
<div class="card">
  <div class="card-body fb-success">
    <div class="check">🎉</div>
    <h2>Thank you for your feedback!</h2>
    <p>Your response has been recorded. We truly appreciate you taking the time to help us improve Bodhika.</p>
    <a href="search.php" class="fb-btn fb-btn-primary">Go to Exams</a>
    &nbsp;
    <a href="history.php" class="fb-btn fb-btn-secondary">My History</a>
  </div>
</div>

<?php else: ?>

<div class="fb-header">
  <h1>💬 Share Your Feedback</h1>
  <p>Help us make Bodhika better — it only takes 2 minutes.</p>
</div>

<?php if ($error): ?>
<div class="card" style="border-color:#fca5a5;background:#fef2f2;margin-bottom:16px;">
  <div class="card-body" style="color:#b91c1c;padding:14px 20px;">⚠️ <?php echo htmlspecialchars($error); ?></div>
</div>
<?php endif; ?>

<!-- Step indicator -->
<div class="fb-steps" id="stepIndicator">
  <div class="fb-step active" id="si1">
    <div class="fb-step-circle">1</div>
    <div class="fb-step-label">Overall</div>
  </div>
  <div class="fb-step pending" id="si2">
    <div class="fb-step-circle">2</div>
    <div class="fb-step-label">Areas</div>
  </div>
  <div class="fb-step pending" id="si3">
    <div class="fb-step-circle">3</div>
    <div class="fb-step-label">Details</div>
  </div>
  <div class="fb-step pending" id="si4">
    <div class="fb-step-circle">4</div>
    <div class="fb-step-label">Suggest</div>
  </div>
</div>

<!-- Progress bar -->
<div class="progress-bar"><div class="progress-fill" id="progressFill" style="width:25%"></div></div>

<form method="post" action="feedback.php" id="fbForm" novalidate>

<!-- ════════════════════════════════
     STEP 1 — Overall Experience
════════════════════════════════ -->
<div class="fb-step-panel" id="panel1">
  <div class="fb-card">
    <h2>⭐ How would you rate your overall experience?</h2>

    <div style="text-align:center;padding:10px 0 24px;">
      <div class="star-row" id="overallStars" style="justify-content:center;">
        <input type="radio" name="overall_rating" id="s5" value="5" <?php echo (($_POST['overall_rating']??0)==5)?'checked':''; ?>>
        <label for="s5" title="Excellent">★</label>
        <input type="radio" name="overall_rating" id="s4" value="4" <?php echo (($_POST['overall_rating']??0)==4)?'checked':''; ?>>
        <label for="s4" title="Good">★</label>
        <input type="radio" name="overall_rating" id="s3" value="3" <?php echo (($_POST['overall_rating']??0)==3)?'checked':''; ?>>
        <label for="s3" title="Average">★</label>
        <input type="radio" name="overall_rating" id="s2" value="2" <?php echo (($_POST['overall_rating']??0)==2)?'checked':''; ?>>
        <label for="s2" title="Poor">★</label>
        <input type="radio" name="overall_rating" id="s1" value="1" <?php echo (($_POST['overall_rating']??0)==1)?'checked':''; ?>>
        <label for="s1" title="Terrible">★</label>
      </div>
      <div id="ratingLabel" style="margin-top:14px;font-size:1.1rem;font-weight:700;color:#7c3aed;min-height:28px;"></div>
    </div>

    <div class="sect-title">How did you use Bodhika?</div>
    <div class="chip-grid">
      <?php
        $cats_post = isset($_POST['categories']) ? (array)$_POST['categories'] : [];
        $catList = [
          'Took Exams'      => '📝 Took Exams',
          'Practiced Tests' => '📚 Practice Tests',
          'Checked Results' => '📊 Checked Results',
          'Used Admin'      => '⚙️ Admin Features',
          'Enrolled Course' => '🎓 Course Enrollment',
          'Used Mobile'     => '📱 Mobile Device',
        ];
        foreach ($catList as $val => $label):
          $chk = in_array($val, $cats_post) ? 'checked' : '';
          $id  = 'cat_' . preg_replace('/\W+/', '_', $val);
      ?>
      <span><input type="checkbox" name="categories[]" id="<?php echo $id; ?>" value="<?php echo htmlspecialchars($val); ?>" <?php echo $chk; ?>>
      <label for="<?php echo $id; ?>"><?php echo $label; ?></label></span>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="fb-nav">
    <span></span>
    <button type="button" class="fb-btn fb-btn-primary" onclick="goStep(2)">Next: Rate Specific Areas →</button>
  </div>
</div>

<!-- ════════════════════════════════
     STEP 2 — Area Ratings
════════════════════════════════ -->
<div class="fb-step-panel hidden" id="panel2">
  <div class="fb-card">
    <h2>🎯 Rate specific areas <span style="font-size:.8rem;color:#9ca3af;font-weight:400">(optional)</span></h2>
    <p style="color:#6b7280;font-size:.88rem;margin:-8px 0 20px;">Tap the stars for each area that applies to you.</p>

    <div class="sub-ratings">
      <?php
        $areas = [
          ['exam_exp_rating', '📝 Exam Experience',   'Ease of taking exams, timer, navigation'],
          ['ui_rating',       '🎨 Interface Design',  'Look, layout, and ease of use'],
          ['perf_rating',     '⚡ Speed & Performance','Page load, responsiveness'],
          ['quality_rating',  '❓ Question Quality',   'Clarity and relevance of questions'],
          ['support_rating',  '🤝 Support / Help',    'Help docs, error messages'],
        ];
        foreach ($areas as [$name, $label, $hint]):
          $prefix = str_replace('_rating','', $name);
          $curVal = (int)($_POST[$name] ?? 0);
      ?>
      <div class="sub-rating-item">
        <label><?php echo $label; ?></label>
        <div style="font-size:.75rem;color:#9ca3af;margin-bottom:8px;"><?php echo $hint; ?></div>
        <div class="star-row star-row-sm">
          <?php for ($v=5;$v>=1;$v--):
            $id2 = $prefix.'_'.$v;
            $chk2 = ($curVal===$v) ? 'checked' : '';
          ?>
          <input type="radio" name="<?php echo $name; ?>" id="<?php echo $id2; ?>" value="<?php echo $v; ?>" <?php echo $chk2; ?>>
          <label for="<?php echo $id2; ?>" title="<?php echo $v; ?> star">★</label>
          <?php endfor; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="fb-nav">
    <button type="button" class="fb-btn fb-btn-secondary" onclick="goStep(1)">← Back</button>
    <button type="button" class="fb-btn fb-btn-primary" onclick="goStep(3)">Next: Your Thoughts →</button>
  </div>
</div>

<!-- ════════════════════════════════
     STEP 3 — Open-ended Feedback
════════════════════════════════ -->
<div class="fb-step-panel hidden" id="panel3">
  <div class="fb-card">
    <h2>💡 Tell us more</h2>

    <div class="sect-title">What did you like most?</div>
    <textarea name="liked_most" class="fb-textarea" rows="3"
              placeholder="e.g. The timer works smoothly, results are instant, interface is clean…"><?php echo htmlspecialchars($_POST['liked_most'] ?? ''); ?></textarea>

    <div class="sect-title" style="margin-top:18px;">What could be improved?</div>
    <textarea name="improvements" class="fb-textarea" rows="3"
              placeholder="e.g. Would be great to have a dark mode, review answers after exam…"><?php echo htmlspecialchars($_POST['improvements'] ?? ''); ?></textarea>

    <div class="sect-title" style="margin-top:18px;">Any issues you faced?</div>
    <div class="chip-grid" style="margin-bottom:16px;">
      <?php
        $issues = [
          'Slow loading'     => '🐢 Slow loading',
          'Login problems'   => '🔐 Login problems',
          'Timer issues'     => '⏱ Timer issues',
          'Wrong answers'    => '❌ Wrong answers shown',
          'Mobile unfriendly'=> '📱 Mobile unfriendly',
          'Confusing UI'     => '😕 Confusing UI',
          'Payment issues'   => '💳 Payment issues',
          'No issues'        => '✅ No issues',
        ];
        $issuePost = isset($_POST['categories']) ? (array)$_POST['categories'] : [];
        foreach ($issues as $val => $label):
          $id3 = 'iss_' . preg_replace('/\W+/', '_', $val);
          $chk3 = in_array($val, $issuePost) ? 'checked' : '';
      ?>
      <span><input type="checkbox" name="categories[]" id="<?php echo $id3; ?>" value="<?php echo htmlspecialchars($val); ?>" <?php echo $chk3; ?>>
      <label for="<?php echo $id3; ?>"><?php echo $label; ?></label></span>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="fb-nav">
    <button type="button" class="fb-btn fb-btn-secondary" onclick="goStep(2)">← Back</button>
    <button type="button" class="fb-btn fb-btn-primary" onclick="goStep(4)">Next: Final Step →</button>
  </div>
</div>

<!-- ════════════════════════════════
     STEP 4 — Recommendations & Submit
════════════════════════════════ -->
<div class="fb-step-panel hidden" id="panel4">
  <div class="fb-card">
    <h2>🚀 Almost done!</h2>

    <div class="sect-title">Would you recommend Bodhika to others?</div>
    <div class="recommend-pills">
      <span class="rec-yes">
        <input type="radio" name="recommend" id="rec_yes" value="Yes"
               <?php echo (($_POST['recommend']??'')==='Yes')?'checked':''; ?>>
        <label for="rec_yes">👍 Yes, definitely!</label>
      </span>
      <span class="rec-maybe">
        <input type="radio" name="recommend" id="rec_maybe" value="Maybe"
               <?php echo (($_POST['recommend']??'')==='Maybe')?'checked':''; ?>>
        <label for="rec_maybe">🤔 Maybe</label>
      </span>
      <span class="rec-no">
        <input type="radio" name="recommend" id="rec_no" value="No"
               <?php echo (($_POST['recommend']??'')==='No')?'checked':''; ?>>
        <label for="rec_no">👎 Not right now</label>
      </span>
    </div>

    <div class="sect-title" style="margin-top:24px;">Feature requests / suggestions</div>
    <textarea name="feature_requests" class="fb-textarea" rows="3"
              placeholder="e.g. PDF export of results, offline exam mode, group study rooms…"><?php echo htmlspecialchars($_POST['feature_requests'] ?? ''); ?></textarea>

    <div style="margin-top:20px;padding:16px;background:#f5f3ff;border-radius:8px;font-size:.85rem;color:#6b7280;">
      💜 Your feedback is linked to your account so we can follow up if needed. We never share personal data.
    </div>
  </div>

  <div class="fb-nav">
    <button type="button" class="fb-btn fb-btn-secondary" onclick="goStep(3)">← Back</button>
    <button type="submit" class="fb-btn fb-btn-submit">🚀 Submit Feedback</button>
  </div>
</div>

</form>
<?php endif; ?>
</div><!-- .fb-wrap -->

<script>
/* ── Step navigation ─────────────────────────────── */
var currentStep = 1;
var ratingLabels = {1:'😞 Terrible',2:'😕 Poor',3:'😐 Average',4:'😊 Good',5:'😄 Excellent!'};

function goStep(n) {
  if (n === 2 && !validateStep1()) return;
  document.getElementById('panel' + currentStep).classList.add('hidden');
  document.getElementById('panel' + n).classList.remove('hidden');
  // Update step indicators
  for (var i = 1; i <= 4; i++) {
    var si = document.getElementById('si' + i);
    si.className = 'fb-step ' + (i < n ? 'done' : i === n ? 'active' : 'pending');
    if (i < n) si.querySelector('.fb-step-circle').textContent = '✓';
    else si.querySelector('.fb-step-circle').textContent = i;
  }
  document.getElementById('progressFill').style.width = (n * 25) + '%';
  currentStep = n;
  window.scrollTo({top: 0, behavior: 'smooth'});
}

function validateStep1() {
  var rated = document.querySelector('input[name="overall_rating"]:checked');
  if (!rated) {
    alert('Please give an overall star rating before continuing.');
    return false;
  }
  return true;
}

/* ── Overall star label ──────────────────────────── */
document.querySelectorAll('input[name="overall_rating"]').forEach(function(r) {
  r.addEventListener('change', function() {
    document.getElementById('ratingLabel').textContent = ratingLabels[this.value] || '';
  });
});
// Set on load if already selected (re-submit case)
var preRated = document.querySelector('input[name="overall_rating"]:checked');
if (preRated) document.getElementById('ratingLabel').textContent = ratingLabels[preRated.value] || '';
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
