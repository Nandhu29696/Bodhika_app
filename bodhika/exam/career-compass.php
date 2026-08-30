<?php
/**
 * exam/career-compass.php — ExamPath Directory: "Career Compass"
 *
 * A short 5-question quiz that points a student toward a broad career
 * direction and the ExamPath Directory exams that lead there. Content
 * (questions + career-result mappings) is fixed reference/app content —
 * ported directly from the original static tool's QS/CMAP data — so it
 * lives here as PHP constants rather than new database tables, the same
 * way this app already keeps other fixed UI copy in code rather than
 * the database.
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');

$QS_DATA = [
    [
        'q' => 'When you read the news, which story makes you lean forward?',
        'opts' => [
            ['i' => '💻', 'l' => 'Tech breakthrough', 's' => 'AI, chips, space launch, new software', 't' => 'tech'],
            ['i' => '🏥', 'l' => 'Health discovery', 's' => 'Cancer cure, pandemic, mental health', 't' => 'health'],
            ['i' => '🌱', 'l' => 'Environment / Climate', 's' => 'Climate, pollution, clean energy, water', 't' => 'climate'],
            ['i' => '📚', 'l' => 'Education / Social impact', 's' => 'School access, inequality, community', 't' => 'impact'],
            ['i' => '🎨', 'l' => 'Design / Culture', 's' => 'New product, architecture, art, experience', 't' => 'design'],
            ['i' => '💰', 'l' => 'Finance / Business', 's' => 'Startup, market, economy, investment', 't' => 'finance'],
            ['i' => '🛡️', 'l' => 'Defence / Security', 's' => 'National security, military tech, cyber', 't' => 'defence'],
        ],
    ],
    [
        'q' => 'Which kind of homework do you NOT notice the time passing?',
        'opts' => [
            ['i' => '📐', 'l' => 'Mathematics', 's' => 'Solving problems, proofs, patterns', 't' => 'math'],
            ['i' => '💻', 'l' => 'Writing code', 's' => 'Building things, debugging, logic', 't' => 'code'],
            ['i' => '🔬', 'l' => 'Reading science', 's' => 'How the universe works, experiments', 't' => 'science'],
            ['i' => '✏️', 'l' => 'Drawing / designing', 's' => 'Sketching, making things look right', 't' => 'design'],
            ['i' => '📚', 'l' => 'Writing / reading', 's' => 'Essays, stories, arguments, debate', 't' => 'arts'],
            ['i' => '📊', 'l' => 'Organising / planning', 's' => 'Spreadsheets, presentations, systems', 't' => 'manage'],
        ],
    ],
    [
        'q' => 'What would you rather be doing at age 35?',
        'opts' => [
            ['i' => '🚀', 'l' => 'Launching products at a tech startup', 's' => 'Coding, building, shipping fast', 't' => 'tech'],
            ['i' => '🔬', 'l' => 'Presenting research that changes policy', 's' => 'Lab, papers, conferences', 't' => 'research'],
            ['i' => '🎨', 'l' => 'Designing a product millions use daily', 's' => 'UX, industrial design, brand', 't' => 'design'],
            ['i' => '🏫', 'l' => 'Teaching in a room that changes lives', 's' => 'Education, mentoring, social impact', 't' => 'impact'],
            ['i' => '🛡️', 'l' => 'Leading a unit protecting the country', 's' => 'Officer, mission, service', 't' => 'defence'],
            ['i' => '🏢', 'l' => 'Building a company from scratch', 's' => 'Entrepreneur, founder, scaling', 't' => 'biz'],
        ],
    ],
    [
        'q' => 'Which degree label feels most right for you?',
        'opts' => [
            ['i' => '⚙️', 'l' => 'BTech Engineering', 's' => 'Building systems, products, infrastructure', 't' => 'engineering'],
            ['i' => '🧪', 'l' => 'BSc Research', 's' => 'Pure science, deep understanding, discovery', 't' => 'research'],
            ['i' => '🎭', 'l' => 'BDes Design', 's' => 'Visual, spatial, human-centered creation', 't' => 'design'],
            ['i' => '📈', 'l' => 'BBA / Integrated MBA', 's' => 'Business, strategy, people, money', 't' => 'management'],
            ['i' => '📚', 'l' => 'BA Liberal Arts', 's' => 'Broad thinking, multiple disciplines', 't' => 'liberal'],
            ['i' => '🛡️', 'l' => 'Defence training + BTech', 's' => 'Service, leadership, engineering', 't' => 'defence'],
        ],
    ],
    [
        'q' => 'Which problem makes your heart beat faster?',
        'opts' => [
            ['i' => '🤖', 'l' => 'Making AI safe and beneficial', 's' => 'AI alignment, ethics, research', 'c' => 'AI / ML Research', 'ex' => ['IAT (IISER)', 'JEE Advanced', 'UGEE IIIT Hyderabad']],
            ['i' => '⚡', 'l' => 'Clean energy for everyone', 's' => 'Solar, batteries, green hydrogen', 'c' => 'Renewable Energy Engineer', 'ex' => ['JEE Main S1', 'KCET', 'BITSAT']],
            ['i' => '🔒', 'l' => 'Protecting people from cybercrime', 's' => 'Ethical hacking, security, privacy', 'c' => 'Cybersecurity Engineer', 'ex' => ['JEE Main S1', 'KCET', 'COMEDK']],
            ['i' => '🏥', 'l' => 'Making healthcare affordable', 's' => 'Biotech, genomics, health tech', 'c' => 'Biotech / Health Tech', 'ex' => ['IAT (IISER)', 'IISc BS Research', 'JEE Main S1']],
            ['i' => '🎨', 'l' => 'Making technology beautiful to use', 's' => 'UX, product design, interfaces', 'c' => 'UX / Product Designer', 'ex' => ['UCEED 2027', 'NID DAT', 'Srishti Manipal']],
            ['i' => '🛡️', 'l' => 'Defending the nation with technology', 's' => 'DRDO, ISRO, Naval tech, Army', 'c' => 'Defence Scientist / Officer', 'ex' => ['NDA I', 'Army TES', 'Navy BTech Cadet']],
        ],
    ],
];

$CMAP_DATA = [
    'tc_eng' => ['career' => 'Software / AI Engineer', 'tier' => '🔵 Tier 1', 'sal' => '₹10–18 LPA start → ₹50L–3 Cr peak', 'imp' => '⭐⭐⭐⭐⭐', 'ex' => ['JEE Main S1', 'JEE Main S2', 'JEE Advanced', 'KCET', 'COMEDK', 'BITSAT', 'UGEE IIIT Hyderabad']],
    'tc_res' => ['career' => 'AI Researcher / Scientist', 'tier' => '🔵 Tier 1', 'sal' => '₹8–25 LPA start → ₹50L–1 Cr peak', 'imp' => '⭐⭐⭐⭐⭐', 'ex' => ['IAT (IISER)', 'IISc BS Research', 'JEE Advanced', 'UGEE IIIT Hyderabad']],
    'health' => ['career' => 'Biotech / Health Tech Researcher', 'tier' => '🟢 Tier 2', 'sal' => '₹5–12 LPA start → ₹30–60 LPA peak', 'imp' => '⭐⭐⭐⭐⭐', 'ex' => ['IAT (IISER)', 'IISc BS Research', 'JEE Main S1', 'NEST', 'BITSAT']],
    'climate' => ['career' => 'Climate Tech / Renewable Energy Engineer', 'tier' => '🟢 Tier 2', 'sal' => '₹6–12 LPA start → ₹40–60 LPA peak', 'imp' => '⭐⭐⭐⭐⭐', 'ex' => ['JEE Main S1', 'JEE Advanced', 'KCET', 'BITSAT', 'IAT (IISER)']],
    'design' => ['career' => 'UX / Product Designer', 'tier' => '🟢 Tier 2', 'sal' => '₹6–12 LPA start → ₹35–60 LPA peak', 'imp' => '⭐⭐⭐⭐', 'ex' => ['UCEED 2027', 'NID DAT', 'NIFT Entrance', 'Srishti Manipal']],
    'defence' => ['career' => 'Defence Officer / DRDO / ISRO Scientist', 'tier' => '🛡️ Service', 'sal' => '₹1.2–1.5L/month equiv. + pension for life', 'imp' => '⭐⭐⭐⭐⭐', 'ex' => ['NDA I', 'NDA II', 'Army TES', 'Navy BTech Cadet']],
    'finance' => ['career' => 'FinTech / Quant Finance / Management', 'tier' => '🟡 Tier 3', 'sal' => '₹15–25 LPA start → ₹1–5 Cr peak', 'imp' => '⭐⭐⭐', 'ex' => ['IPMAT Indore', 'IPMAT Rohtak', 'JEE Main S1', 'ISI Admission']],
    'impact' => ['career' => 'EdTech / Education / Social Innovation', 'tier' => '💠 Impact', 'sal' => '₹6–15 LPA start → ₹25–50 LPA peak', 'imp' => '⭐⭐⭐⭐⭐', 'ex' => ['Ashoka AAT', 'APU Entrance Bangalore', 'KUCAT Krea', 'FLAME FEAT']],
    'def' => ['career' => 'Engineering + Research (Broad Path)', 'tier' => '🔵 Tier 1', 'sal' => '₹8–20 LPA start → ₹40–80 LPA peak', 'imp' => '⭐⭐⭐⭐', 'ex' => ['JEE Main S1', 'JEE Main S2', 'KCET', 'COMEDK', 'BITSAT', 'IAT (IISER)', 'IISc BS Research']],
];

/* ── Handle quiz submission ──────────────────────────────────────────────── */
$result = null;
$answers = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_quiz'])) {
    Auth::validateCsrf();
    $ok = true;
    foreach ($QS_DATA as $qi => $q) {
        $ai = isset($_POST['a' . $qi]) ? (int)$_POST['a' . $qi] : -1;
        if ($ai < 0 || $ai >= count($q['opts'])) { $ok = false; break; }
        $answers[$qi] = $ai;
    }
    if ($ok) {
        // Same priority-chain algorithm as the original tool's showResult().
        $tags = [];
        foreach ($QS_DATA as $qi => $q) { $tags[] = $q['opts'][$answers[$qi]]['t'] ?? ''; }
        $has = fn($t) => in_array($t, $tags, true);

        $key = 'def';
        if ($has('defence'))                                     $key = 'defence';
        elseif (count(array_filter($tags, fn($t) => $t === 'design')) >= 2) $key = 'design';
        elseif ($has('impact') || $has('arts') || $has('liberal'))         $key = 'impact';
        elseif ($has('finance') || $has('manage') || $has('management'))   $key = 'finance';
        elseif ($has('health') || $has('science'))                        $key = 'health';
        elseif ($has('climate'))                                          $key = 'climate';
        elseif ($has('math') && ($has('research') || $has('science')))    $key = 'tc_res';
        elseif ($has('tech') || $has('code') || $has('engineering'))      $key = 'tc_eng';

        $result = $CMAP_DATA[$key] ?? $CMAP_DATA['def'];
        $lastOpt = $QS_DATA[4]['opts'][$answers[4]] ?? null;
    }
}

$pageTitle = 'ExamPath Directory — Career Compass';
include __DIR__ . '/../includes/header.php';
?>
<style>
  .cc-wrap { max-width:720px; margin:0 auto; }
  .cc-q { margin-bottom:28px; }
  .cc-q-title { font-weight:800; font-size:1rem; color:#1e293b; margin-bottom:12px; }
  .cc-opts { display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:10px; }
  .cc-opt { display:flex; align-items:flex-start; gap:10px; border:2px solid #e2e8f0; border-radius:10px;
            padding:10px 12px; cursor:pointer; transition:.15s; }
  .cc-opt:hover { border-color:#a5b4fc; background:#f5f3ff; }
  .cc-opt input:checked ~ .cc-opt-text,
  .cc-opt.selected { border-color:#4f46e5; background:#eef2ff; }
  .cc-opt input { margin-top:3px; }
  .cc-opt-icon { font-size:1.3rem; }
  .cc-opt-text .l { font-weight:700; font-size:.88rem; color:#1e293b; }
  .cc-opt-text .s { font-size:.76rem; color:#6b7280; margin-top:1px; }

  .cc-result { text-align:center; }
  .cc-result .eyebrow { font-size:.78rem; font-weight:800; text-transform:uppercase; letter-spacing:.08em; color:#818cf8; }
  .cc-result .career { font-size:1.6rem; font-weight:800; color:#1e293b; margin:6px 0; }
  .cc-result .sub { font-size:.9rem; color:#6b7280; margin-bottom:20px; }
  .cc-sec { text-align:left; background:#f8fafc; border-radius:12px; padding:16px 18px; margin-bottom:14px; }
  .cc-sec-title { font-weight:800; font-size:.85rem; color:#334155; margin-bottom:8px; }
  .epill { display:inline-block; background:#eef2ff; color:#4338ca; border:1px solid #c7d2fe; border-radius:20px;
           padding:5px 12px; font-size:.8rem; font-weight:700; margin:3px 4px 0 0; text-decoration:none; }
</style>

<div class="card">
  <div class="card-header">&#129517; Career Compass</div>
  <div class="card-body cc-wrap">

    <?php if ($result): ?>
      <div class="cc-result">
        <div class="eyebrow">Your Career Match</div>
        <div class="career"><?php echo htmlspecialchars($result['career']); ?></div>
        <div class="sub"><?php echo htmlspecialchars($result['tier']); ?> &nbsp;&middot;&nbsp;
          <?php echo htmlspecialchars(trim(explode('→', $result['sal'])[0])); ?> &nbsp;&middot;&nbsp;
          <?php echo htmlspecialchars($result['imp']); ?></div>

        <?php if ($lastOpt && !empty($lastOpt['c'])): ?>
        <div class="cc-sec">
          <div class="cc-sec-title">Based on your final answer: "<?php echo htmlspecialchars($lastOpt['l']); ?>"</div>
          <p style="font-size:.85rem;color:#4A5568;margin-bottom:10px;">
            Specific direction: <strong><?php echo htmlspecialchars($lastOpt['c']); ?></strong>
          </p>
          <div>
            <?php foreach ($lastOpt['ex'] ?? [] as $ex): ?>
              <a class="epill" href="exam-directory.php?q=<?php echo urlencode($ex); ?>"><?php echo htmlspecialchars($ex); ?></a>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

        <div class="cc-sec">
          <div class="cc-sec-title">Priority Exams to Target</div>
          <div>
            <?php foreach ($result['ex'] as $ex): ?>
              <a class="epill" href="exam-directory.php?q=<?php echo urlencode($ex); ?>"><?php echo htmlspecialchars($ex); ?></a>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="cc-sec">
          <div class="cc-sec-title">Salary Trajectory &amp; Impact</div>
          <p style="font-size:.82rem;color:#4A5568;"><?php echo htmlspecialchars($result['sal']); ?></p>
          <p style="font-size:.9rem;color:#276749;font-weight:700;margin-top:6px;"><?php echo htmlspecialchars($result['imp']); ?></p>
        </div>

        <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;margin-top:10px;">
          <a href="exam-directory.php" class="btn btn-primary">Browse All Exams &#8594;</a>
          <a href="career-compass.php" class="btn btn-secondary">Retake Quiz</a>
        </div>
      </div>

    <?php else: ?>
      <p style="color:#6b7280;font-size:.88rem;margin-bottom:20px;">
        Answer these 5 quick questions to get a suggested career direction and the ExamPath Directory exams that lead there.
      </p>
      <form method="post" action="">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken()); ?>">
        <?php foreach ($QS_DATA as $qi => $q): ?>
          <div class="cc-q">
            <div class="cc-q-title"><?php echo ($qi + 1); ?>. <?php echo htmlspecialchars($q['q']); ?></div>
            <div class="cc-opts">
              <?php foreach ($q['opts'] as $oi => $o): ?>
                <label class="cc-opt">
                  <input type="radio" name="a<?php echo $qi; ?>" value="<?php echo $oi; ?>" required>
                  <span class="cc-opt-icon"><?php echo $o['i']; ?></span>
                  <span class="cc-opt-text">
                    <div class="l"><?php echo htmlspecialchars($o['l']); ?></div>
                    <div class="s"><?php echo htmlspecialchars($o['s']); ?></div>
                  </span>
                </label>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
        <button type="submit" name="submit_quiz" class="btn btn-primary" style="margin-top:6px;">See My Career Match &#8594;</button>
      </form>
    <?php endif; ?>

  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
