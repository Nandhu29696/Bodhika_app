<?php
/**
 * Admin/ImportExamTemplate.php
 *
 * Multi-step wizard: upload a raw exam-paper .docx (subject section headers,
 * numbered questions, a)/b)/c)/d) options, no answer key, optional inline
 * diagram/equation images — e.g. a school's own combined PCMB test paper)
 * and turn it into ONE new exam containing every question, each individually
 * tagged to its own subject (matched from the section heading it appeared
 * under), with the exam itself filed under a "NEET" subject so it shows up
 * as a single combined test rather than 4 separate ones.
 *
 * This is deliberately a SEPARATE tool from Admin/BulkUploadQuestions.php:
 * that one expects a single-subject exam and its own fixed Q1./A)/Answer:/
 * Complexity:/Explanation: template (with an answer key already in the
 * file). This tool is for a raw, single-subject-per-section exam PAPER with
 * no answer key at all and multiple subjects in one document.
 *
 * Flow (state kept in $_SESSION['examImport'] across steps):
 *   1. upload      — pick .docx, parse into sections/questions/images.
 *   2. mapSubjects — only shown if a heading doesn't match an existing
 *                    subject (e.g. "Mathematics"), OR a batch of questions
 *                    was found before any heading was recognised at all (see
 *                    parsing note below) — admin maps each to an existing
 *                    subject or creates a new one.
 *   3. review      — one subject section at a time: admin edits question
 *                    text/options if the parse got anything wrong, picks the
 *                    correct answer (the source document has no answer key),
 *                    and can skip a garbled row or detach an auto-attached
 *                    image.
 *   4. confirm     — exam name / grade / timing, then commit: creates the
 *                    exam and inserts every reviewed question in one
 *                    transaction.
 *
 * Parsing note — floating text-box headings: some Word documents (this one
 * included) put the FIRST section's subject name in a floating text box /
 * shape rather than a plain paragraph (later sections in the same document
 * may still use plain paragraphs). A text box's position in document.xml
 * does not reliably correspond to its visual reading-order position, so it
 * cannot be safely detected the same way as a plain heading paragraph.
 * Rather than guess, any numbered questions found BEFORE a heading is
 * recognised are bucketed into a placeholder section ("Section 1 — before
 * first subject heading") which always flows into step 2 (mapSubjects) so
 * the admin just picks the right subject for it — no content is silently
 * dropped.
 *
 * Images: staged into Admin/images/exam/_staging_<token>/ during parsing (the
 * same publicly-reachable images/exam/ tree exam/question-edit.php already
 * uses, so <img> thumbnails work immediately with no extra serving script).
 * At final commit, kept images are renamed into the flat images/exam/ folder
 * matching the app's normal ImageLoc convention; the staging folder is then
 * removed. Stale staging folders older than 24h are swept on every visit to
 * step 1 in case an upload is abandoned partway through.
 */

require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');
if (!Auth::isAdmin()) { header('Location: ../auth/login.php'); exit; }

const EIT_PLACEHOLDER_HEADING = 'Section 1 (before first subject heading)';

$IMAGES_DIR = __DIR__ . '/images/exam/'; // Admin/images/exam/
if (!is_dir($IMAGES_DIR)) { @mkdir($IMAGES_DIR, 0755, true); }

/** Remove abandoned upload staging folders older than 24h. */
function eitSweepStaleStaging(string $imagesDir): void
{
    foreach (glob($imagesDir . '_staging_*', GLOB_ONLYDIR) ?: [] as $dir) {
        if (@filemtime($dir) !== false && filemtime($dir) < time() - 86400) {
            foreach (glob($dir . '/*') ?: [] as $f) { @unlink($f); }
            @rmdir($dir);
        }
    }
}

/** Delete a specific staging folder (used on commit and on explicit reset). */
function eitCleanupStaging(string $imagesDir, string $token): void
{
    $dir = $imagesDir . '_staging_' . $token;
    foreach (glob($dir . '/*') ?: [] as $f) { @unlink($f); }
    @rmdir($dir);
}

/* ─────────────────────────────────────────────────────────────────────────
   DOCX PARSING
   ───────────────────────────────────────────────────────────────────────── */

/** Lowercase-name => canonical display name, seeded from existing subjects
 *  plus a handful of common school-subject names so a heading is recognised
 *  even before it exists as a subjectinfo row. */
function eitKnownHeadingNames(array $dbSubjectNames): array
{
    $extra = ['Mathematics', 'Maths', 'Biology', 'Botany', 'Zoology', 'Physics', 'Chemistry', 'English'];
    $all = array_merge($dbSubjectNames, $extra);
    $out = [];
    foreach ($all as $n) { $out[strtolower(trim($n))] = trim($n); }
    return $out;
}

/** [ ['text'=>string, 'images'=>[rId,...]], ... ] per <w:p>, in document order. */
function eitDocxParagraphs(string $documentXml): array
{
    $paragraphs = [];
    if (!preg_match_all('/<w:p\b[^>]*>(.*?)<\/w:p>/s', $documentXml, $pMatches)) return [];
    foreach ($pMatches[1] as $pInner) {
        $text = '';
        if (preg_match_all('/<w:t\b[^>]*>(.*?)<\/w:t>/s', $pInner, $tMatches)) {
            foreach ($tMatches[1] as $t) { $text .= html_entity_decode($t, ENT_QUOTES | ENT_XML1, 'UTF-8'); }
        }
        $relIds = [];
        if (preg_match_all('/<a:blip[^>]*r:embed="(rId\d+)"/', $pInner, $bMatches)) { $relIds = $bMatches[1]; }
        $paragraphs[] = ['text' => trim($text), 'images' => $relIds];
    }
    return $paragraphs;
}

/** rId => zip-relative target path, from word/_rels/document.xml.rels */
function eitDocxRelMap(string $relsXml): array
{
    $map = [];
    if (preg_match_all('/<Relationship[^>]*Id="(rId\d+)"[^>]*Target="([^"]+)"/', $relsXml, $m)) {
        foreach ($m[1] as $i => $rid) { $map[$rid] = $m[2][$i]; }
    }
    return $map;
}

/**
 * Extract the .docx's paragraphs + only the media files actually referenced
 * by an image-bearing paragraph, staged into $stagingDir.
 * Returns ['paragraphs'=>[...], 'ridToStagedPath'=>[rId => 'images/exam/_staging_<t>/file.png']]
 * or false if the file can't be read as a .docx at all.
 */
function eitReadDocx(string $path, string $stagingDir): array|false
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return false;
    $documentXml = $zip->getFromName('word/document.xml');
    if ($documentXml === false) { $zip->close(); return false; }
    $relsXml = $zip->getFromName('word/_rels/document.xml.rels');

    $paragraphs = eitDocxParagraphs($documentXml);
    $relMap     = $relsXml !== false ? eitDocxRelMap($relsXml) : [];

    $usedRids = [];
    foreach ($paragraphs as $p) { foreach ($p['images'] as $rid) { $usedRids[$rid] = true; } }

    $ridToStagedPath = [];
    if ($usedRids) {
        if (!is_dir($stagingDir)) { mkdir($stagingDir, 0755, true); }
        $urlPrefix = 'images/exam/' . basename($stagingDir) . '/';
        foreach (array_keys($usedRids) as $rid) {
            if (!isset($relMap[$rid])) continue;
            $target  = ltrim($relMap[$rid], '/');
            $zipPath = 'word/' . $target;
            $bytes   = $zip->getFromName($zipPath);
            if ($bytes === false) continue;
            $fname = basename($target);
            file_put_contents($stagingDir . '/' . $fname, $bytes);
            $ridToStagedPath[$rid] = $urlPrefix . $fname;
        }
    }
    $zip->close();

    return ['paragraphs' => $paragraphs, 'ridToStagedPath' => $ridToStagedPath];
}

/**
 * Parse the paragraph list into sections. Any numbered question found before
 * a heading is ever recognised is bucketed under EIT_PLACEHOLDER_HEADING so
 * nothing is silently lost (see the file-level parsing note above).
 *
 * Returns: [ ['heading'=>str, 'placeholder'=>bool,
 *             'questions'=>[ ['num'=>str,'text'=>str,
 *                             'opts'=>['a'=>,'b'=>,'c'=>,'d'=>],
 *                             'image'=>str|null] ] ] ]
 */
function eitParseSections(array $paragraphs, array $headingLookup, array $ridToStagedPath): array
{
    $sections = [];
    $curSectionIdx = null;
    $curQ = null;
    $mode = null; // 'question' | 'option'
    $lastLetter = null;

    $flushQ = function () use (&$curQ, &$sections, &$curSectionIdx) {
        if ($curQ !== null && trim($curQ['text']) !== '' && $curSectionIdx !== null) {
            $sections[$curSectionIdx]['questions'][] = $curQ;
        }
        $curQ = null;
    };
    $findOrCreateSection = function (string $heading, bool $placeholder) use (&$sections) {
        foreach ($sections as $i => $s) { if ($s['heading'] === $heading) return $i; }
        $sections[] = ['heading' => $heading, 'placeholder' => $placeholder, 'questions' => []];
        return count($sections) - 1;
    };

    foreach ($paragraphs as $p) {
        $line = $p['text'];
        $lower = strtolower($line);

        // Plain-paragraph subject heading (see file-level note re: text-box headings).
        if ($line !== '' && isset($headingLookup[$lower]) && mb_strlen($line) <= 30
            && !preg_match('/^\(?\d/', $line) && !preg_match('/^[a-dA-D]\s*[.):]/', $line)) {
            $flushQ();
            $curSectionIdx = $findOrCreateSection($headingLookup[$lower], false);
            $mode = null;
            continue;
        }

        $isQuestionStart = $line !== '' && preg_match('/^\(?\s*(\d{1,3})\s*[\.\)]\s*(.*)$/', $line, $qm);

        if ($curSectionIdx === null) {
            if (!$isQuestionStart) continue; // preamble before anything recognisable
            $curSectionIdx = $findOrCreateSection(EIT_PLACEHOLDER_HEADING, true);
        }

        if ($isQuestionStart) {
            $flushQ();
            $curQ = ['num' => $qm[1], 'text' => $qm[2], 'opts' => ['a' => '', 'b' => '', 'c' => '', 'd' => ''], 'image' => null];
            $mode = 'question';
            foreach ($p['images'] as $rid) {
                if (isset($ridToStagedPath[$rid])) { $curQ['image'] = $ridToStagedPath[$rid]; break; }
            }
            continue;
        }

        // Any image on this paragraph (blank diagram/equation line, or an
        // option line that IS an image) attaches to the current question —
        // first one wins; schema supports one image per question.
        if ($curQ !== null && $curQ['image'] === null) {
            foreach ($p['images'] as $rid) {
                if (isset($ridToStagedPath[$rid])) { $curQ['image'] = $ridToStagedPath[$rid]; break; }
            }
        }

        if ($line === '') continue;
        if ($curQ === null) continue;

        if (preg_match_all('/([a-dA-D])\s*[.):]/', $line, $markers, PREG_OFFSET_CAPTURE) && $markers[0]) {
            $n = count($markers[0]);
            for ($i = 0; $i < $n; $i++) {
                $letter = strtolower($markers[1][$i][0]);
                $start  = $markers[0][$i][1] + strlen($markers[0][$i][0]);
                $end    = ($i + 1 < $n) ? $markers[0][$i + 1][1] : strlen($line);
                $curQ['opts'][$letter] = trim(substr($line, $start, $end - $start));
                $lastLetter = $letter;
            }
            $mode = 'option';
            continue;
        }

        if ($mode === 'question') {
            $curQ['text'] .= ' ' . $line;
        } elseif ($mode === 'option' && $lastLetter) {
            $curQ['opts'][$lastLetter] .= ' ' . $line;
        }
    }
    $flushQ();

    return $sections;
}

/* ─────────────────────────────────────────────────────────────────────────
   CONTROLLER
   ───────────────────────────────────────────────────────────────────────── */

$flash = null;

/* ── Reset / start over ─────────────────────────────────────────────────── */
if (($_GET['action'] ?? '') === 'reset') {
    if (isset($_SESSION['examImport']['token'])) {
        eitCleanupStaging($IMAGES_DIR, $_SESSION['examImport']['token']);
    }
    unset($_SESSION['examImport']);
    header('Location: ImportExamTemplate.php'); exit;
}

/* ── Step 1: upload + parse ─────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'doUpload' && isset($_FILES['examFile'])) {
    Auth::validateCsrf();
    $file = $_FILES['examFile'];
    $ext  = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    $uploadErr = '';

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $uploadErr = 'File upload failed (error code ' . $file['error'] . ').';
    } elseif ($ext !== 'docx') {
        $uploadErr = 'Only .docx files are accepted here — use Bulk Upload for .xlsx/.csv/single-subject .docx.';
    } elseif ($file['size'] > 10 * 1024 * 1024) {
        $uploadErr = 'File must be 10 MB or smaller.';
    }

    if ($uploadErr === '') {
        $token = bin2hex(random_bytes(8));
        $stagingDir = $IMAGES_DIR . '_staging_' . $token;
        $parsed = eitReadDocx($file['tmp_name'], $stagingDir);

        if ($parsed === false) {
            $uploadErr = 'Could not read this file — make sure it is a valid, non-password-protected .docx.';
        } else {
            $dbSubjects = Database::fetchAll("SELECT SubjectInfoId, SubjectName FROM subjectinfo ORDER BY SubjectName");
            $dbNameToId = [];
            $dbNames    = [];
            foreach ($dbSubjects as $s) {
                $dbNameToId[strtolower($s['SubjectName'])] = (int)$s['SubjectInfoId'];
                $dbNames[] = $s['SubjectName'];
            }
            $headingLookup = eitKnownHeadingNames($dbNames);
            $sections = eitParseSections($parsed['paragraphs'], $headingLookup, $parsed['ridToStagedPath']);

            foreach ($sections as $i => $s) {
                $sections[$i]['subjectId'] = $s['placeholder'] ? null : ($dbNameToId[strtolower($s['heading'])] ?? null);
            }

            $totalQ = array_sum(array_map(fn($s) => count($s['questions']), $sections));
            if (empty($sections) || $totalQ === 0) {
                $uploadErr = 'No questions were found. Each question must start with "1.", "2." etc. at the '
                           . 'start of its own line/paragraph, with a) b) c) d) options following.';
                eitCleanupStaging($IMAGES_DIR, $token);
            } elseif ($totalQ > 500) {
                $uploadErr = "File contains $totalQ questions — maximum is 500 per upload.";
                eitCleanupStaging($IMAGES_DIR, $token);
            } else {
                $_SESSION['examImport'] = [
                    'token'           => $token,
                    'examNameDefault' => pathinfo($file['name'], PATHINFO_FILENAME),
                    'sections'        => $sections,
                    'finalized'       => [],
                    'skippedCounts'   => [],
                ];
                $hasUnmatched = false;
                foreach ($sections as $s) { if ($s['subjectId'] === null) { $hasUnmatched = true; break; } }
                header('Location: ImportExamTemplate.php?step=' . ($hasUnmatched ? 'mapSubjects' : 'review&section=0'));
                exit;
            }
        }
    }

    if ($uploadErr !== '') { $flash = ['type' => 'error', 'msg' => $uploadErr]; }
}

/* ── Step 2: subject mapping ────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'doMapSubjects') {
    Auth::validateCsrf();
    $imp = $_SESSION['examImport'] ?? null;
    if ($imp) {
        $allResolved = true;
        foreach ($imp['sections'] as $i => $s) {
            if ($s['subjectId'] !== null) continue;
            $choice = $_POST['mapSubject_' . $i] ?? '';
            if ($choice === '__new__') {
                $newName = trim($_POST['newSubjectName_' . $i] ?? '');
                if ($newName !== '') {
                    Database::execute(
                        "INSERT INTO subjectinfo (SubjectName, Active, ExamFee, DiscountPct) VALUES (?, 'Y', 0, 0)",
                        [$newName]);
                    $imp['sections'][$i]['subjectId'] = (int)Database::lastInsertId();
                } else {
                    $allResolved = false;
                }
            } elseif ((int)$choice > 0) {
                $imp['sections'][$i]['subjectId'] = (int)$choice;
            } else {
                $allResolved = false;
            }
        }
        $_SESSION['examImport'] = $imp;
        if ($allResolved) {
            header('Location: ImportExamTemplate.php?step=review&section=0'); exit;
        } else {
            $flash = ['type' => 'error', 'msg' => 'Please choose (or create) a subject for every heading below.'];
        }
    }
}

/* ── Step 3: save one section's review edits ────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'doSaveSection') {
    Auth::validateCsrf();
    $imp = $_SESSION['examImport'] ?? null;
    $sectionIdx = (int)($_POST['section'] ?? -1);
    if ($imp && isset($imp['sections'][$sectionIdx])) {
        $origQuestions = $imp['sections'][$sectionIdx]['questions'];
        $rows = [];
        $skipped = 0;
        $qTexts = $_POST['qText'] ?? [];
        foreach ($qTexts as $j => $rawText) {
            if (isset($_POST['skip'][$j])) { $skipped++; continue; }
            $qText = trim($rawText);
            $a1 = trim($_POST['a1'][$j] ?? '');
            $a2 = trim($_POST['a2'][$j] ?? '');
            $a3 = trim($_POST['a3'][$j] ?? '');
            $a4 = trim($_POST['a4'][$j] ?? '');
            $correct = (int)($_POST['correct'][$j] ?? 0);
            if ($qText === '' || $a1 === '' || $a2 === '' || $correct < 1 || $correct > 4) { $skipped++; continue; }
            $answerMap = [1 => $a1, 2 => $a2, 3 => $a3, 4 => $a4];
            if (trim($answerMap[$correct]) === '') { $skipped++; continue; }

            $image = null;
            if (!empty($origQuestions[$j]['image']) && empty($_POST['removeImage'][$j])) {
                $image = $origQuestions[$j]['image'];
            }
            $rows[] = ['qText' => $qText, 'a1' => $a1, 'a2' => $a2, 'a3' => $a3, 'a4' => $a4, 'correct' => $correct, 'image' => $image];
        }
        $imp['finalized'][$sectionIdx]     = $rows;
        $imp['skippedCounts'][$sectionIdx] = $skipped;
        $_SESSION['examImport'] = $imp;

        $nextIdx = $sectionIdx + 1;
        if ($nextIdx < count($imp['sections'])) {
            header('Location: ImportExamTemplate.php?step=review&section=' . $nextIdx); exit;
        } else {
            header('Location: ImportExamTemplate.php?step=confirm'); exit;
        }
    }
}

/* ── Step 4: commit — create exam + insert every finalized question ────── */
$commitError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'doCommit') {
    Auth::validateCsrf();
    $imp = $_SESSION['examImport'] ?? null;
    if (!$imp) {
        header('Location: ImportExamTemplate.php'); exit;
    }

    $examName    = trim($_POST['examName'] ?? '') ?: $imp['examNameDefault'];
    $gradeId     = (int)($_POST['gradeId'] ?? 0);
    $timeAlloted = max(5, (int)($_POST['timeAlloted'] ?? 180));
    $minPassing  = max(1, min(100, (int)($_POST['minPassing'] ?? 40)));
    $marksPerQ   = max(0.5, (float)($_POST['marksPerQ'] ?? 4));
    $negMarks    = max(0, (float)($_POST['negMarks'] ?? 1));

    $errors = [];
    if ($examName === '') $errors[] = 'Exam name is required.';
    if ($gradeId <= 0)    $errors[] = 'Please select a grade.';

    $totalQ = 0;
    foreach ($imp['sections'] as $i => $s) { $totalQ += count($imp['finalized'][$i] ?? []); }
    if ($totalQ === 0) $errors[] = 'There are no reviewed questions to import — go back and review at least one subject section.';

    if ($errors) {
        $commitError = implode(' ', $errors);
    } else {
        Database::beginTransaction();
        try {
            $neet = Database::fetchOne("SELECT SubjectInfoId FROM subjectinfo WHERE LOWER(SubjectName) = LOWER(?) LIMIT 1", ['NEET']);
            if ($neet) {
                $neetSubjectId = (int)$neet['SubjectInfoId'];
            } else {
                Database::execute("INSERT INTO subjectinfo (SubjectName, Active, ExamFee, DiscountPct) VALUES ('NEET', 'Y', 0, 0)");
                $neetSubjectId = (int)Database::lastInsertId();
            }

            try {
                Database::execute(
                    "INSERT INTO examinfo
                        (ExamName,GradeInfoId,SubjectInfoId,NumOfQuestions,MinPassing,TimeAlloted,proctor_lock,
                         ExamScope,ExamInstituteId,ExamFreeFor,IsActive,MaxAttempts,MarkingScheme,TotalMarks,MarksPerQuestion,NegativeMarks)
                     VALUES (?,?,?,?,?,?,0,'All',NULL,'None','Y',5,'Fixed',?,?,?)",
                    [$examName, $gradeId, $neetSubjectId, $totalQ, $minPassing, $timeAlloted,
                     $marksPerQ * $totalQ, $marksPerQ, $negMarks]);
            } catch (Exception $eNew) {
                Database::execute(
                    "INSERT INTO examinfo (ExamName,GradeInfoId,SubjectInfoId,NumOfQuestions,MinPassing,TimeAlloted,proctor_lock)
                     VALUES (?,?,?,?,?,?,0)",
                    [$examName, $gradeId, $neetSubjectId, $totalQ, $minPassing, $timeAlloted]);
            }
            $examId = (int)Database::lastInsertId();

            foreach ($imp['sections'] as $i => $sec) {
                $subjId = (int)$sec['subjectId'];
                foreach (($imp['finalized'][$i] ?? []) as $r) {
                    $imageInd = 'N'; $imageLoc = null; $numImages = 0;
                    if (!empty($r['image'])) {
                        $srcAbs = __DIR__ . '/' . $r['image'];
                        if (is_file($srcAbs)) {
                            $ext = pathinfo($srcAbs, PATHINFO_EXTENSION) ?: 'png';
                            $finalName = 'examimport_' . bin2hex(random_bytes(6)) . '.' . $ext;
                            $destAbs = $IMAGES_DIR . $finalName;
                            if (@rename($srcAbs, $destAbs) || @copy($srcAbs, $destAbs)) {
                                $imageInd = 'Y'; $imageLoc = 'images/exam/' . $finalName; $numImages = 1;
                            }
                        }
                    }

                    Database::execute(
                        "INSERT INTO questions
                            (SubjectInfoId, QuestionDesc, CorrectAnswer, OperatorInd, IsActive,
                             QuestionType, Complexity, Explanation, ImageInd, ImageLoc, NumofImages)
                         VALUES (?, ?, ?, 'N', 'Y', 'MCQ', 'Medium', '', ?, ?, ?)",
                        [$subjId, $r['qText'], (string)$r['correct'], $imageInd, $imageLoc, $numImages]);
                    $qid = (int)Database::lastInsertId();

                    try {
                        Database::execute(
                            "INSERT IGNORE INTO exam_questions (ExamInfoId, QuestionId, IsActive) VALUES (?, ?, 'Y')",
                            [$examId, $qid]);
                    } catch (Exception $eq) {
                        Database::execute("UPDATE questions SET ExamInfoId=? WHERE QuestionId=?", [$examId, $qid]);
                    }

                    Database::execute(
                        "INSERT INTO answers (QuestionId, Answer1, Answer2, Answer3, Answer4, AnsImageInd, MultiImageInd)
                         VALUES (?, ?, ?, ?, ?, 'N', 'N')",
                        [$qid, $r['a1'], $r['a2'], $r['a3'] !== '' ? $r['a3'] : null, $r['a4'] !== '' ? $r['a4'] : null]);
                }
            }

            Database::commit();
            eitCleanupStaging($IMAGES_DIR, $imp['token']);
            unset($_SESSION['examImport']);

            header('Location: ../exam/questions.php?examId=' . $examId . '&imported=' . $totalQ);
            exit;
        } catch (Exception $ex) {
            Database::rollBack();
            $commitError = 'Could not create the exam — database error: ' . $ex->getMessage();
        }
    }
}

/* ─────────────────────────────────────────────────────────────────────────
   RENDER
   ───────────────────────────────────────────────────────────────────────── */

$imp  = $_SESSION['examImport'] ?? null;
$step = $_GET['step'] ?? 'upload';
if (!$imp && $step !== 'upload') { $step = 'upload'; }

if ($step === 'upload') { eitSweepStaleStaging($IMAGES_DIR); }

$subjectsById = [];
foreach (Database::fetchAll("SELECT SubjectInfoId, SubjectName FROM subjectinfo ORDER BY SubjectName") as $s) {
    $subjectsById[(int)$s['SubjectInfoId']] = $s['SubjectName'];
}

$pageTitle = 'Import Exam Template (.docx)';
include __DIR__ . '/../includes/header.php';
?>
<style>
.eit-card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:22px 26px;margin-bottom:18px;}
.eit-steps{display:flex;gap:6px;margin-bottom:18px;flex-wrap:wrap;}
.eit-step{padding:6px 14px;border-radius:16px;font-size:.8rem;font-weight:600;background:#f1f5f9;color:#64748b;}
.eit-step.active{background:#1e3a5f;color:#fff;}
.eit-step.done{background:#d1fae5;color:#065f46;}
.eit-qrow{border:1px solid #e2e8f0;border-radius:8px;padding:14px 16px;margin-bottom:12px;background:#fafbfc;}
.eit-qrow.skipme{opacity:.5;}
.eit-opt-line{display:flex;gap:8px;align-items:center;margin-bottom:6px;}
.eit-opt-line input[type=text]{flex:1;}
.eit-thumb{max-width:260px;max-height:160px;border:1px solid #d1d5db;border-radius:6px;margin:8px 0;display:block;}
.eit-upload-zone{border:2px dashed #93c5fd;border-radius:8px;padding:32px;text-align:center;background:#eff6ff;cursor:pointer;}
.eit-upload-zone input[type=file]{display:none;}
</style>

<div style="margin-bottom:14px;font-size:.87rem;color:#6b7280;">
  <a href="../exam/search.php">Exam List</a> &rsaquo; Import Exam Template
  <?php if ($imp): ?>
    &nbsp;|&nbsp; <a href="ImportExamTemplate.php?action=reset" onclick="return confirm('Start over? Any unsaved review edits will be lost.');" style="color:#dc2626;">&#10006; Start Over</a>
  <?php endif; ?>
</div>

<h2 style="margin-top:0;">&#128196; Import Exam Template (.docx)</h2>
<p style="color:#6b7280;font-size:.9rem;max-width:800px;">
  Upload a combined multi-subject test paper (subject headings, numbered questions, a)/b)/c)/d) options).
  Every question is tagged to its own subject and all of them are added to ONE new exam filed under <strong>NEET</strong>.
</p>

<div class="eit-steps">
  <span class="eit-step <?php echo $step==='upload' ? 'active' : 'done'; ?>">1. Upload</span>
  <span class="eit-step <?php echo $step==='mapSubjects' ? 'active' : ($step==='review'||$step==='confirm' ? 'done' : ''); ?>">2. Map Subjects</span>
  <span class="eit-step <?php echo $step==='review' ? 'active' : ($step==='confirm' ? 'done' : ''); ?>">3. Review Questions</span>
  <span class="eit-step <?php echo $step==='confirm' ? 'active' : ''; ?>">4. Create Exam</span>
</div>

<?php if ($flash): ?>
  <div class="alert <?php echo $flash['type']==='error' ? 'alert-danger' : 'alert-success'; ?>">
    <?php echo htmlspecialchars($flash['msg']); ?>
  </div>
<?php endif; ?>

<?php if ($step === 'upload'): ?>

  <div class="eit-card">
    <h3 style="margin-top:0;color:#1e3a5f;">Upload File</h3>
    <form method="post" enctype="multipart/form-data" id="uploadForm">
      <input type="hidden" name="csrf_token" value="<?php echo Auth::csrfToken(); ?>">
      <input type="hidden" name="action" value="doUpload">
      <div class="eit-upload-zone" onclick="document.getElementById('examFileInput').click()">
        <div style="font-size:2.2rem;">&#128193;</div>
        <div style="font-weight:600;color:#1e40af;">Click to choose your .docx test paper</div>
        <div style="font-size:.82rem;color:#6b7280;margin-top:6px;">Max 10 MB &nbsp;|&nbsp; Up to 500 questions</div>
        <div id="examFileName" style="margin-top:10px;font-weight:600;color:#1e40af;"></div>
        <input type="file" id="examFileInput" name="examFile" accept=".docx" onchange="eitShowFile(this)">
      </div>
      <div style="margin-top:16px;">
        <button type="submit" id="eitSubmitBtn" class="btn btn-primary" disabled>&#11014; Upload &amp; Parse</button>
      </div>
    </form>
  </div>

  <script>
  function eitShowFile(input) {
    if (input.files && input.files[0]) {
      document.getElementById('examFileName').textContent = '📄 ' + input.files[0].name;
      document.getElementById('eitSubmitBtn').disabled = false;
    }
  }
  var f = document.getElementById('uploadForm');
  if (f) f.addEventListener('submit', function() {
    document.getElementById('eitSubmitBtn').disabled = true;
    document.getElementById('eitSubmitBtn').textContent = '⏳ Parsing…';
  });
  </script>

<?php elseif ($step === 'mapSubjects'): ?>

  <div class="eit-card">
    <h3 style="margin-top:0;color:#1e3a5f;">Map Unrecognised Headings to a Subject</h3>
    <p style="color:#6b7280;font-size:.87rem;">
      These sections didn't match an existing subject name. Pick an existing subject or create a new one for each.
      (A "Section 1 (before first subject heading)" entry means questions appeared before any subject heading could
      be recognised in the document — pick the subject those questions actually belong to.)
    </p>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?php echo Auth::csrfToken(); ?>">
      <input type="hidden" name="action" value="doMapSubjects">
      <?php foreach ($imp['sections'] as $i => $s): if ($s['subjectId'] !== null) continue; ?>
        <div class="form-group" style="border:1px solid #e2e8f0;border-radius:8px;padding:12px 14px;margin-bottom:12px;">
          <label style="font-weight:700;">
            "<?php echo htmlspecialchars($s['heading']); ?>"
            <span style="font-weight:400;color:#6b7280;">(<?php echo count($s['questions']); ?> questions,
              e.g. "<?php echo htmlspecialchars(mb_substr($s['questions'][0]['text'] ?? '', 0, 60)); ?>…")</span>
          </label>
          <select name="mapSubject_<?php echo $i; ?>" class="form-control" onchange="eitToggleNew(<?php echo $i; ?>, this.value)">
            <option value="">— Choose —</option>
            <?php foreach ($subjectsById as $sid => $sname): ?>
              <option value="<?php echo $sid; ?>"><?php echo htmlspecialchars($sname); ?></option>
            <?php endforeach; ?>
            <option value="__new__">+ Create new subject…</option>
          </select>
          <input type="text" name="newSubjectName_<?php echo $i; ?>" id="newSubjectName_<?php echo $i; ?>"
                 class="form-control" placeholder="New subject name" style="display:none;margin-top:6px;">
        </div>
      <?php endforeach; ?>
      <button type="submit" class="btn btn-primary">Continue to Review &rarr;</button>
    </form>
  </div>
  <script>
  function eitToggleNew(i, val) {
    var el = document.getElementById('newSubjectName_' + i);
    if (el) el.style.display = (val === '__new__') ? 'block' : 'none';
  }
  </script>

<?php elseif ($step === 'review'):
  $sectionIdx = (int)($_GET['section'] ?? 0);
  $section = $imp['sections'][$sectionIdx] ?? null;
  if (!$section) { header('Location: ImportExamTemplate.php?step=confirm'); exit; }
  $subjectName = $subjectsById[(int)$section['subjectId']] ?? $section['heading'];

  $existingFinal = $imp['finalized'][$sectionIdx] ?? null;
  $displayRows = [];
  if ($existingFinal !== null) {
      foreach ($existingFinal as $r) {
          $displayRows[] = ['text' => $r['qText'], 'opts' => ['a' => $r['a1'], 'b' => $r['a2'], 'c' => $r['a3'], 'd' => $r['a4']],
                             'correct' => $r['correct'], 'image' => $r['image']];
      }
  } else {
      foreach ($section['questions'] as $q) {
          $displayRows[] = ['text' => $q['text'], 'opts' => $q['opts'], 'correct' => 0, 'image' => $q['image']];
      }
  }
  $totalSections = count($imp['sections']);
?>

  <div class="eit-card">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:6px;">
      <h3 style="margin:0;color:#1e3a5f;">
        Reviewing: <?php echo htmlspecialchars($subjectName); ?>
        <span style="font-weight:400;color:#6b7280;font-size:.85rem;">(section <?php echo $sectionIdx+1; ?> of <?php echo $totalSections; ?>, <?php echo count($displayRows); ?> questions)</span>
      </h3>
      <?php if ($sectionIdx > 0): ?>
        <a href="ImportExamTemplate.php?step=review&section=<?php echo $sectionIdx-1; ?>" class="btn btn-secondary">&larr; Previous Subject</a>
      <?php endif; ?>
    </div>
    <p style="color:#6b7280;font-size:.85rem;">
      The source document has no answer key — pick the correct option for each question below. Fix any
      garbled text/options and check the "skip" box to leave out a row entirely (e.g. a match-the-following
      item that isn't a real MCQ).
    </p>

    <form method="post">
      <input type="hidden" name="csrf_token" value="<?php echo Auth::csrfToken(); ?>">
      <input type="hidden" name="action" value="doSaveSection">
      <input type="hidden" name="section" value="<?php echo $sectionIdx; ?>">

      <?php foreach ($displayRows as $j => $row): ?>
        <div class="eit-qrow" id="qrow_<?php echo $j; ?>">
          <div style="display:flex;justify-content:space-between;gap:10px;">
            <strong style="color:#1e3a5f;">Q<?php echo $j+1; ?></strong>
            <label style="font-size:.82rem;color:#dc2626;font-weight:600;">
              <input type="checkbox" name="skip[<?php echo $j; ?>]" onchange="document.getElementById('qrow_<?php echo $j; ?>').classList.toggle('skipme', this.checked)">
              Skip this question
            </label>
          </div>
          <textarea name="qText[<?php echo $j; ?>]" class="form-control" rows="2" style="margin:6px 0;"><?php echo htmlspecialchars($row['text']); ?></textarea>

          <?php if (!empty($row['image'])): ?>
            <img src="<?php echo htmlspecialchars($row['image']); ?>" class="eit-thumb" alt="Question diagram">
            <label style="font-size:.8rem;color:#6b7280;">
              <input type="checkbox" name="removeImage[<?php echo $j; ?>]"> Remove this auto-attached image
            </label>
          <?php endif; ?>

          <?php foreach (['a'=>1,'b'=>2,'c'=>3,'d'=>4] as $letter => $num): ?>
            <div class="eit-opt-line">
              <input type="radio" name="correct[<?php echo $j; ?>]" value="<?php echo $num; ?>"
                     <?php echo ((int)$row['correct'] === $num) ? 'checked' : ''; ?> title="Mark as correct answer">
              <span style="width:18px;font-weight:700;color:#6b7280;"><?php echo strtoupper($letter); ?></span>
              <input type="text" name="a<?php echo $num; ?>[<?php echo $j; ?>]" class="form-control"
                     value="<?php echo htmlspecialchars($row['opts'][$letter] ?? ''); ?>"
                     placeholder="Option <?php echo strtoupper($letter); ?><?php echo $num >= 3 ? ' (optional)' : ''; ?>">
            </div>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>

      <button type="submit" class="btn btn-primary">
        <?php echo ($sectionIdx + 1 < $totalSections) ? 'Save & Continue to Next Subject &rarr;' : 'Save & Continue to Create Exam &rarr;'; ?>
      </button>
    </form>
  </div>

<?php elseif ($step === 'confirm'):
  $bySubject = [];
  $totalQ = 0;
  foreach ($imp['sections'] as $i => $s) {
      $count = count($imp['finalized'][$i] ?? []);
      $skipped = $imp['skippedCounts'][$i] ?? 0;
      $bySubject[] = ['name' => $subjectsById[(int)$s['subjectId']] ?? $s['heading'], 'count' => $count, 'skipped' => $skipped];
      $totalQ += $count;
  }
  $grades = Database::fetchAll("SELECT GradeInfoId, GradeName FROM gradeinfo ORDER BY GradeName");
?>

  <div class="eit-card">
    <h3 style="margin-top:0;color:#1e3a5f;">Review Summary</h3>
    <table style="width:100%;border-collapse:collapse;margin-bottom:14px;">
      <thead><tr style="background:#1e3a5f;color:#fff;">
        <th style="padding:8px 12px;text-align:left;">Subject</th>
        <th style="padding:8px 12px;text-align:left;">Questions Ready</th>
        <th style="padding:8px 12px;text-align:left;">Skipped</th>
      </tr></thead>
      <tbody>
      <?php foreach ($bySubject as $bs): ?>
        <tr style="border-bottom:1px solid #f1f5f9;">
          <td style="padding:7px 12px;"><?php echo htmlspecialchars($bs['name']); ?></td>
          <td style="padding:7px 12px;font-weight:700;color:#059669;"><?php echo $bs['count']; ?></td>
          <td style="padding:7px 12px;color:#6b7280;"><?php echo $bs['skipped']; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div style="font-weight:700;margin-bottom:16px;">Total questions to import: <?php echo $totalQ; ?></div>

    <?php if ($commitError): ?>
      <div class="alert alert-danger"><?php echo htmlspecialchars($commitError); ?></div>
    <?php endif; ?>

    <?php if ($totalQ === 0): ?>
      <div class="alert alert-danger">No reviewed questions yet — go back and review at least one subject section.</div>
      <a href="ImportExamTemplate.php?step=review&section=0" class="btn btn-secondary">&larr; Back to Review</a>
    <?php else: ?>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo Auth::csrfToken(); ?>">
        <input type="hidden" name="action" value="doCommit">

        <div class="form-group">
          <label>Exam Name <span style="color:#dc2626;">*</span></label>
          <input type="text" name="examName" class="form-control" required maxlength="200"
                 value="<?php echo htmlspecialchars($imp['examNameDefault']); ?>">
        </div>
        <div style="display:flex;gap:16px;flex-wrap:wrap;">
          <div class="form-group" style="flex:1;min-width:180px;">
            <label>Grade <span style="color:#dc2626;">*</span></label>
            <select name="gradeId" class="form-control" required>
              <option value="0">-- Select Grade --</option>
              <?php foreach ($grades as $g): ?>
                <option value="<?php echo (int)$g['GradeInfoId']; ?>"><?php echo htmlspecialchars($g['GradeName']); ?></option>
              <?php endforeach; ?>
            </select>
            <?php if (empty($grades)): ?>
              <p style="font-size:.75rem;color:#dc2626;">No grades found — <a href="../Admin/AddEditGradeInfo.php?InfoId=0">add one</a> first.</p>
            <?php endif; ?>
          </div>
          <div class="form-group" style="flex:1;min-width:140px;">
            <label>Time Allotted (min)</label>
            <input type="number" name="timeAlloted" class="form-control" min="5" max="300" value="180">
          </div>
          <div class="form-group" style="flex:1;min-width:140px;">
            <label>Passing (%)</label>
            <input type="number" name="minPassing" class="form-control" min="1" max="100" value="40">
          </div>
        </div>
        <div style="display:flex;gap:16px;flex-wrap:wrap;">
          <div class="form-group" style="flex:1;min-width:140px;">
            <label>Marks per Correct Answer</label>
            <input type="number" name="marksPerQ" class="form-control" min="0.5" step="0.5" value="4">
          </div>
          <div class="form-group" style="flex:1;min-width:140px;">
            <label>Negative Marks</label>
            <input type="number" name="negMarks" class="form-control" min="0" step="0.5" value="1">
          </div>
        </div>
        <div style="font-size:.8rem;color:#6b7280;margin-bottom:12px;">
          This exam will be filed under the <strong>NEET</strong> subject (created automatically if it doesn't exist yet);
          each question keeps its own real subject tag shown in the table above.
        </div>
        <button type="submit" class="btn btn-success">&#10003; Create Exam &amp; Import <?php echo $totalQ; ?> Questions</button>
      </form>
    <?php endif; ?>
  </div>

<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
