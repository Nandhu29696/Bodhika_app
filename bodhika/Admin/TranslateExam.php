<?php
/**
 * Admin/TranslateExam.php — "Save As different language".
 *
 * Duplicates an existing exam (examinfo row + every question/answer/
 * answer-image reachable via exam_questions) into a brand-new ExamInfoId
 * tagged with a target Language, machine-translating every text field via
 * Lib/Translator. The original exam is never modified except to receive a
 * TranslationGroupId the first time it's translated (so every language
 * variant of the same source can be found via
 * `WHERE TranslationGroupId = ?`).
 *
 * The new exam is created inactive (IsActive='N') so it never appears to
 * students until the admin has reviewed the (possibly rough) machine
 * translation on exam/questions.php and flips it live from exam/manage.php.
 *
 * GET  ?examId=N        — form, source exam preselected
 * GET  (no examId)       — form, admin picks a source exam from a dropdown
 * POST action=translate — performs the duplication, redirects to review
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
require_once __DIR__ . '/../Lib/Translator.php';
Auth::requireLogin('../auth/login.php');
if (!Auth::isAdmin()) { header('Location: ../auth/login.php'); exit; }

/** Columns copied as-is from the source examinfo row (whitelist — never
 *  includes PK/name/language/status columns, which are set explicitly). */
const COPY_EXAMINFO_BLACKLIST = [
    'ExamInfoId', 'ExamName', 'Language', 'TranslationGroupId',
    'IsActive', 'IsDeleted', 'DeletedAt', 'DeletedBy',
    'CreateDate', 'CreatedAt', 'UpdateDate', 'UpdatedAt', 'ModifiedDate',
];

$errors  = [];
$flash   = '';
$flashType = 'success';

/* ── POST: perform the translation ───────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'translate') {
    Auth::validateCsrf();

    $srcExamId  = (int)($_POST['SourceExamId'] ?? 0);
    $targetLang = trim($_POST['TargetLanguage'] ?? '');
    $newName    = trim($_POST['NewExamName'] ?? '');

    $source = $srcExamId > 0
        ? Database::fetchOne("SELECT * FROM examinfo WHERE ExamInfoId = ? LIMIT 1", [$srcExamId])
        : null;
    $targetLangRow = $targetLang !== ''
        ? Database::fetchOne("SELECT * FROM languages WHERE LanguageCode = ? AND IsActive = 'Y' LIMIT 1", [$targetLang])
        : null;

    if (!$source)        $errors[] = 'Source exam not found.';
    if (!$targetLangRow) $errors[] = 'Choose a valid target language.';
    if ($newName === '') $errors[] = 'New exam name is required.';
    if ($source && $targetLangRow && ($source['Language'] ?? 'en') === $targetLang) {
        $errors[] = 'Target language must be different from the source exam\'s current language.';
    }
    if ($newName !== '') {
        $dupe = Database::fetchOne("SELECT 1 FROM examinfo WHERE ExamName = ? LIMIT 1", [$newName]);
        if ($dupe) $errors[] = "An exam named \"$newName\" already exists — choose a unique name.";
    }

    // Prevent translating into a language this exam's group already has.
    if (!$errors) {
        $groupId = (int)($source['TranslationGroupId'] ?? 0) ?: (int)$source['ExamInfoId'];
        $sibling = Database::fetchOne(
            "SELECT ExamInfoId, ExamName FROM examinfo
              WHERE TranslationGroupId = ? AND Language = ? LIMIT 1",
            [$groupId, $targetLang]);
        if ($sibling) {
            $errors[] = "This exam already has a {$targetLangRow['LanguageName']} version: \"{$sibling['ExamName']}\" (#{$sibling['ExamInfoId']}). Edit that one directly instead.";
        }
    }

    if (!$errors) {
        $sourceLang = $source['Language'] ?? 'en';
        try {
            Database::beginTransaction();

            // 1. Make sure the source has a TranslationGroupId (first-ever
            //    translation of this exam sets it to the source's own id).
            if (empty($source['TranslationGroupId'])) {
                Database::execute(
                    "UPDATE examinfo SET TranslationGroupId = ExamInfoId WHERE ExamInfoId = ?",
                    [$source['ExamInfoId']]);
                $groupId = (int)$source['ExamInfoId'];
            } else {
                $groupId = (int)$source['TranslationGroupId'];
            }

            // 2. New examinfo row — copy every whitelisted column, override name/language/status.
            $copyCols = array_values(array_diff(array_keys($source), COPY_EXAMINFO_BLACKLIST));
            $insCols  = array_merge(['ExamName', 'Language', 'TranslationGroupId', 'IsActive', 'IsDeleted'], $copyCols);
            $insVals  = array_merge([$newName, $targetLang, $groupId, 'N', 'N'],
                                     array_map(fn($c) => $source[$c], $copyCols));
            $placeholders = implode(',', array_fill(0, count($insCols), '?'));
            Database::execute(
                "INSERT INTO examinfo (" . implode(',', $insCols) . ") VALUES ($placeholders)",
                $insVals);
            $newExamId = (int)Database::lastInsertId();

            // 3. Walk every active, non-deleted question on the source exam
            //    (same join shape as exam/write.php's primary query).
            $srcQuestions = Database::fetchAll(
                "SELECT q.*, eq.SortOrder AS SrcSortOrder
                   FROM exam_questions eq
                   JOIN questions q ON q.QuestionId = eq.QuestionId
                  WHERE eq.ExamInfoId = ? AND COALESCE(eq.IsActive,'Y') = 'Y'
                    AND COALESCE(q.IsDeleted,'N') = 'N'
                  ORDER BY eq.SortOrder, q.QuestionId",
                [$srcExamId]);

            $hasQuestionHtml = array_key_exists('QuestionHtml', $srcQuestions[0] ?? []);

            foreach ($srcQuestions as $q) {
                // -- translate the question stem + explanation --
                $tDesc = Translator::translate($q['QuestionDesc'] ?? null, $targetLang, $sourceLang);
                $tExpl = Translator::translate($q['Explanation'] ?? null, $targetLang, $sourceLang);
                $tHtml = $hasQuestionHtml
                    ? Translator::translate($q['QuestionHtml'] ?? null, $targetLang, $sourceLang)
                    : null;

                $qCols = ['ExamInfoId', 'SubjectInfoId', 'QuestionDesc', 'ImageInd', 'ImageLoc',
                          'NumofImages', 'OperatorInd', 'CorrectAnswer', 'Complexity', 'IsActive',
                          'Explanation', 'QuestionType', 'ExpectedAnswerCount', 'TranslatedFromQuestionId'];
                $qVals = [$newExamId, $q['SubjectInfoId'] ?? null, $tDesc, $q['ImageInd'] ?? 'N',
                          $q['ImageLoc'] ?? null, $q['NumofImages'] ?? 1, $q['OperatorInd'] ?? 'N',
                          $q['CorrectAnswer'] ?? null, $q['Complexity'] ?? 'Medium', 'Y',
                          $tExpl, $q['QuestionType'] ?? 'MCQ', $q['ExpectedAnswerCount'] ?? null,
                          $q['QuestionId']];
                if ($hasQuestionHtml) { $qCols[] = 'QuestionHtml'; $qVals[] = $tHtml; }

                $ph = implode(',', array_fill(0, count($qCols), '?'));
                Database::execute(
                    "INSERT INTO questions (" . implode(',', $qCols) . ") VALUES ($ph)", $qVals);
                $newQuestionId = (int)Database::lastInsertId();

                // -- answers --
                $srcAnswer = Database::fetchOne(
                    "SELECT * FROM answers WHERE QuestionId = ? LIMIT 1", [$q['QuestionId']]);
                $newAnswerId = null;
                if ($srcAnswer) {
                    $hasAnsHtml = array_key_exists('AnsHtml1', $srcAnswer);
                    $hasMatch   = array_key_exists('MatchStatement1', $srcAnswer);

                    $aCols = ['QuestionId', 'Answer1', 'Answer2', 'Answer3', 'Answer4',
                              'AnsImageInd', 'MultiImageInd', 'YesNo1', 'YesNo2', 'YesNo3', 'YesNo4',
                              'NumStatements'];
                    $aVals = [
                        $newQuestionId,
                        Translator::translate($srcAnswer['Answer1'] ?? null, $targetLang, $sourceLang),
                        Translator::translate($srcAnswer['Answer2'] ?? null, $targetLang, $sourceLang),
                        Translator::translate($srcAnswer['Answer3'] ?? null, $targetLang, $sourceLang),
                        Translator::translate($srcAnswer['Answer4'] ?? null, $targetLang, $sourceLang),
                        $srcAnswer['AnsImageInd'] ?? 'N', $srcAnswer['MultiImageInd'] ?? 'N',
                        $srcAnswer['YesNo1'] ?? null, $srcAnswer['YesNo2'] ?? null,
                        $srcAnswer['YesNo3'] ?? null, $srcAnswer['YesNo4'] ?? null,
                        $srcAnswer['NumStatements'] ?? 4,
                    ];
                    if ($hasAnsHtml) {
                        foreach ([1,2,3,4] as $n) {
                            $aCols[] = "AnsHtml$n";
                            $aVals[] = Translator::translate($srcAnswer["AnsHtml$n"] ?? null, $targetLang, $sourceLang);
                        }
                    }
                    if ($hasMatch) {
                        foreach ([1,2,3,4] as $n) {
                            $aCols[] = "MatchStatement$n";
                            $aVals[] = Translator::translate($srcAnswer["MatchStatement$n"] ?? null, $targetLang, $sourceLang);
                        }
                        foreach ([1,2,3,4] as $n) {
                            $aCols[] = "MatchCorrect$n";
                            $aVals[] = $srcAnswer["MatchCorrect$n"] ?? null;
                        }
                    }
                    $aph = implode(',', array_fill(0, count($aCols), '?'));
                    Database::execute(
                        "INSERT INTO answers (" . implode(',', $aCols) . ") VALUES ($aph)", $aVals);
                    $newAnswerId = (int)Database::lastInsertId();
                }

                // -- answer images (paths are language-independent, copied as-is) --
                if ($newAnswerId && $srcAnswer) {
                    $srcImg = Database::fetchOne(
                        "SELECT * FROM answerimages WHERE AnswerId = ? LIMIT 1", [$srcAnswer['AnswerId']]);
                    if ($srcImg) {
                        Database::execute(
                            "INSERT INTO answerimages (AnswerId, AnswerImage1Loc, AnswerImage2Loc, AnswerImage3Loc, AnswerImage4Loc)
                             VALUES (?,?,?,?,?)",
                            [$newAnswerId, $srcImg['AnswerImage1Loc'] ?? null, $srcImg['AnswerImage2Loc'] ?? null,
                             $srcImg['AnswerImage3Loc'] ?? null, $srcImg['AnswerImage4Loc'] ?? null]);
                    }
                }

                // -- link into the new exam (preserve original ordering) --
                Database::execute(
                    "INSERT INTO exam_questions (ExamInfoId, QuestionId, IsActive, SortOrder) VALUES (?,?,'Y',?)",
                    [$newExamId, $newQuestionId, $q['SrcSortOrder'] ?? 0]);
            }

            Database::commit();

            $needsReview = !Translator::isConfigured();
            header('Location: ../exam/questions.php?examId=' . $newExamId
                 . '&flash=' . ($needsReview ? 'translated_unconfigured' : 'translated')); exit;

        } catch (Exception $e) {
            Database::rollBack();
            $errors[] = 'Translation failed: ' . $e->getMessage();
        }
    }
}

/* ── GET: render the form ────────────────────────────────────────────────── */
$preselectId = (int)($_GET['examId'] ?? 0);

$exams = Database::fetchAll(
    "SELECT ExamInfoId, ExamName, Language FROM examinfo
      WHERE COALESCE(IsDeleted,'N') = 'N' ORDER BY ExamName");

$languages = [];
try {
    $languages = Database::fetchAll(
        "SELECT LanguageCode, LanguageName, NativeName FROM languages WHERE IsActive = 'Y' ORDER BY SortOrder, LanguageName");
} catch (Exception $e) { /* migration_v47 not yet run */ }

$preselected = null;
if ($preselectId > 0) {
    foreach ($exams as $e) { if ((int)$e['ExamInfoId'] === $preselectId) { $preselected = $e; break; } }
}

$pageTitle = 'Translate Exam';
include __DIR__ . '/../includes/header.php';
?>
<div class="card" style="max-width:640px;margin:0 auto;">
  <div class="card-header">&#127760; Save Exam As Different Language</div>
  <div class="card-body">

    <?php if (!Translator::isConfigured()): ?>
      <div class="alert alert-warning" style="margin-bottom:16px;">
        &#9888; No translation service is configured (<code>TRANSLATE_API_URL</code> is empty in
        <code>Lib/Config.php</code>). The translated exam will still be created, but every
        question/option will be tagged <code>[TRANSLATE:xx]</code> instead of actually being
        translated — you'll need to edit each one by hand afterwards.
      </div>
    <?php endif; ?>

    <?php foreach ($errors as $err): ?>
      <div class="alert alert-danger"><?php echo htmlspecialchars($err); ?></div>
    <?php endforeach; ?>

    <?php if (empty($languages)): ?>
      <div class="alert alert-danger">
        No languages configured yet. <a href="Languages.php">Add languages</a> first.
      </div>
    <?php elseif (empty($exams)): ?>
      <div class="alert alert-danger">No exams found to translate.</div>
    <?php else: ?>
    <form method="post" action="TranslateExam.php" id="translateForm">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken()); ?>">
      <input type="hidden" name="action" value="translate">

      <div class="form-group">
        <label for="SourceExamId">Source Exam</label>
        <select id="SourceExamId" name="SourceExamId" class="form-control" required
                onchange="suggestName()">
          <option value="0">— Choose an exam —</option>
          <?php foreach ($exams as $e): ?>
            <option value="<?php echo (int)$e['ExamInfoId']; ?>"
                    data-name="<?php echo htmlspecialchars($e['ExamName']); ?>"
              <?php echo ($preselected && (int)$preselected['ExamInfoId'] === (int)$e['ExamInfoId']) ? 'selected' : ''; ?>>
              <?php echo htmlspecialchars($e['ExamName']); ?>
              (<?php echo htmlspecialchars(strtoupper($e['Language'] ?? 'EN')); ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label for="TargetLanguage">Translate Into</label>
        <select id="TargetLanguage" name="TargetLanguage" class="form-control" required
                onchange="suggestName()">
          <option value="">— Choose a language —</option>
          <?php foreach ($languages as $l): ?>
            <option value="<?php echo htmlspecialchars($l['LanguageCode']); ?>"
                    data-name="<?php echo htmlspecialchars($l['LanguageName']); ?>">
              <?php echo htmlspecialchars($l['LanguageName']); ?><?php echo $l['NativeName'] ? ' (' . htmlspecialchars($l['NativeName']) . ')' : ''; ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label for="NewExamName">New Exam Name <span style="color:#a0aec0;">(must be unique)</span></label>
        <input type="text" id="NewExamName" name="NewExamName" class="form-control" required
               placeholder="e.g. Aldehydes, Ketones and Carboxylic Acids (Hindi)">
      </div>

      <div class="alert alert-info" style="font-size:.85rem;">
        &#128161; The new exam is created <strong>inactive</strong> so students won't see it until
        you review the translated questions (redirects there next) and activate it from
        <em>Edit Exam</em>.
      </div>

      <button type="submit" class="btn btn-primary">&#127760; Translate &amp; Create</button>
      <a href="../exam/search.php" class="btn btn-secondary">Cancel</a>
    </form>
    <?php endif; ?>
  </div>
</div>

<script>
function suggestName() {
  const examSel = document.getElementById('SourceExamId');
  const langSel = document.getElementById('TargetLanguage');
  const nameInput = document.getElementById('NewExamName');
  const examOpt = examSel.options[examSel.selectedIndex];
  const langOpt = langSel.options[langSel.selectedIndex];
  if (!examOpt || !examOpt.dataset.name || !langOpt || !langOpt.dataset.name) return;
  // Only auto-fill if the admin hasn't typed a custom name yet.
  if (nameInput.value.trim() === '' || nameInput.dataset.auto === '1') {
    nameInput.value = examOpt.dataset.name + ' (' + langOpt.dataset.name + ')';
    nameInput.dataset.auto = '1';
  }
}
document.getElementById('NewExamName')?.addEventListener('input', function() {
  this.dataset.auto = '0';
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
