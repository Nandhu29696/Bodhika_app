<?php
/**
 * Admin/BulkUploadQuestions.php
 *
 * Bulk upload questions for a specific exam from an Excel (.xlsx) or CSV file.
 *
 * Supported file formats:
 *   .xlsx  — read via PhpSpreadsheet (if installed via Composer) OR
 *            via the bundled SimpleXlsxReader fallback (no Composer needed).
 *   .csv   — read with PHP built-in fgetcsv(); UTF-8 with BOM is handled.
 *   .docx  — read via PHP's built-in ZipArchive (word/document.xml), matching
 *            the downloadable QuestionUpload_Template.docx: each question is
 *            a "Q1." block with A)/B)/C)/D) options and Answer:/Complexity:/
 *            Explanation: labels — see docxParseQuestions() below.
 *
 * Expected columns (matching the downloadable template):
 *   A  QuestionText   (required)
 *   B  Answer1        (required)
 *   C  Answer2        (required)
 *   D  Answer3        (optional)
 *   E  Answer4        (optional)
 *   F  CorrectAnswer  (required, integer 1–4)
 *   G  Complexity     (optional: Easy / Medium / Hard, defaults Medium)
 *   H  Explanation    (optional)
 *   I  Subject        (migration_v54 — required ONLY for a multi-subject exam,
 *                       e.g. NEET/JEE-pattern; must name one of that exam's
 *                       configured sections, e.g. "Physics". Ignored for a
 *                       normal single-subject exam — leave blank or omit.)
 *
 * Validations per row:
 *   - QuestionText     not blank, ≤ 2000 chars
 *   - Answer1 & 2      not blank
 *   - CorrectAnswer    integer 1–4, must point to a non-blank answer
 *   - Complexity       one of Easy / Medium / Hard (or blank → Medium)
 *   - Subject          for a multi-subject exam: required, must match one of
 *                       the exam's configured sections (case-insensitive)
 *   - Duplicate text   not already present in this exam (DB check per row)
 *   - Max 500 rows     per upload
 *
 * Sheet selection: if the uploaded .xlsx has more than one sheet/tab (e.g. a
 * template with an "Instructions" tab alongside "Questions"), the sheet named
 * "Questions" is read regardless of which tab is active or comes first in the
 * workbook. Single-sheet files just use the one sheet present.
 */

require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');
if (!Auth::isAdmin()) { header('Location: ../auth/login.php'); exit; }

/* ── Exam context ─────────────────────────────────────────────────────────── */
$examId = filter_input(INPUT_GET,  'examId', FILTER_VALIDATE_INT)
       ?: filter_input(INPUT_POST, 'examId', FILTER_VALIDATE_INT);
if (!$examId) { header('Location: ExamList.php'); exit; }

$exam = Database::fetchOne(
    "SELECT e.*, g.GradeName, s.SubjectName
       FROM examinfo e
  LEFT JOIN gradeinfo   g ON g.GradeInfoId   = e.GradeInfoId
  LEFT JOIN subjectinfo s ON s.SubjectInfoId  = e.SubjectInfoId
      WHERE e.ExamInfoId = ? LIMIT 1", [$examId]);
if (!$exam) { header('Location: ExamList.php'); exit; }

/* ── This exam's subject(s) (migration_v54) ──────────────────────────────
   Multi-subject exam (e.g. NEET): every row's Subject column (I) must name
   one of these configured sections — $examSubjectChoices maps a lowercased
   name to its SubjectInfoId for validateRow()'s lookup.
   Single-subject exam (the default): [] — column I is ignored entirely and
   every question keeps inheriting $exam['SubjectInfoId'], exactly as before. */
$isMultiSubjectExam = (($exam['IsMultiSubject'] ?? 'N') === 'Y');
$examSubjectChoices = [];   // lowercase label => SubjectInfoId
if ($isMultiSubjectExam) {
    try {
        $secRows = Database::fetchAll(
            "SELECT es.SubjectInfoId, COALESCE(es.SectionLabel, sub.SubjectName) AS Label
               FROM exam_sections es
          LEFT JOIN subjectinfo sub ON sub.SubjectInfoId = es.SubjectInfoId
              WHERE es.ExamInfoId = ?", [$examId]);
        foreach ($secRows as $sr) {
            $examSubjectChoices[mb_strtolower(trim($sr['Label']))] = (int)$sr['SubjectInfoId'];
        }
    } catch (Exception $e) {
        $isMultiSubjectExam = false; // migration_v54 not run — behave as single-subject
    }
}

/* ── Sheet selection helpers ──────────────────────────────────────────────
   Multi-sheet uploads (template/generated files ship an "Instructions" tab
   alongside "Questions") must not be read via "active sheet" or "first
   sheet" — that picks Instructions and silently yields zero data rows.
   Both readers below resolve the sheet literally named "Questions" first,
   falling back to a name containing "question", then to the only/first
   sheet so single-sheet files keep working unchanged. ─────────────────── */

/** Pick the right worksheet object out of a loaded PhpSpreadsheet workbook. */
function pickQuestionsSheetPhp(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet)
{
    $names = $spreadsheet->getSheetNames();
    if (count($names) === 1) return $spreadsheet->getSheet(0);

    foreach ($names as $i => $name) {
        if (strcasecmp(trim($name), 'Questions') === 0) return $spreadsheet->getSheet($i);
    }
    foreach ($names as $i => $name) {
        if (stripos($name, 'question') !== false) return $spreadsheet->getSheet($i);
    }
    return $spreadsheet->getActiveSheet(); // last resort — old behaviour
}

/** Resolve the on-disk sheetN.xml path for the "Questions" sheet inside an
 *  extracted .xlsx, using workbook.xml + workbook.xml.rels (rIds are NOT
 *  guaranteed to map 1:1 to sheet numbers, so sheet1.xml is never assumed). */
function locateQuestionsSheetXml(string $tmpDir): string
{
    $fallback = $tmpDir . '/xl/worksheets/sheet1.xml';
    $wbPath   = $tmpDir . '/xl/workbook.xml';
    $relsPath = $tmpDir . '/xl/_rels/workbook.xml.rels';
    if (!file_exists($wbPath) || !file_exists($relsPath)) return $fallback;

    try {
        $wbXml = simplexml_load_file($wbPath);
        $ns    = $wbXml->getNamespaces(true);
        $rNs   = $ns['r'] ?? 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

        $sheets = [];
        foreach ($wbXml->sheets->sheet as $sheetEl) {
            $rid = (string)($sheetEl->attributes($rNs)['id'] ?? '');
            if ($rid !== '') $sheets[] = ['name' => (string)$sheetEl['name'], 'rid' => $rid];
        }
        if (!$sheets) return $fallback;

        $targets = [];
        foreach (simplexml_load_file($relsPath)->Relationship as $rel) {
            $targets[(string)$rel['Id']] = (string)$rel['Target'];
        }

        $resolve = function (string $rid) use ($targets, $tmpDir): ?string {
            if (!isset($targets[$rid])) return null;
            $rel = preg_replace('#^/?xl/#', '', $targets[$rid]);
            $p   = $tmpDir . '/xl/' . $rel;
            return file_exists($p) ? $p : null;
        };

        if (count($sheets) === 1) {
            return $resolve($sheets[0]['rid']) ?? $fallback;
        }
        foreach ($sheets as $s) {
            if (strcasecmp(trim($s['name']), 'Questions') === 0) {
                $p = $resolve($s['rid']);
                if ($p) return $p;
            }
        }
        foreach ($sheets as $s) {
            if (stripos($s['name'], 'question') !== false) {
                $p = $resolve($s['rid']);
                if ($p) return $p;
            }
        }
        return $resolve($sheets[0]['rid']) ?? $fallback;
    } catch (Exception $e) {
        return $fallback;
    }
}

/* ── XLSX reader — tries PhpSpreadsheet then simple fallback ──────────────── */
function readXlsx(string $path): array|false
{
    /* Try PhpSpreadsheet (Composer) */
    if (class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $sheet = pickQuestionsSheetPhp($spreadsheet);
        $rows  = [];
        foreach ($sheet->getRowIterator(3) as $row) { // data starts row 3 (banner=1, header=2)
            $cells = [];
            foreach ($row->getCellIterator('A', 'I') as $cell) { // I = Subject (migration_v54)
                $cells[] = (string)($cell->getValue() ?? '');
            }
            if (implode('', $cells) === '') continue; // skip blank rows
            $rows[] = $cells;
        }
        return $rows;
    }

    /* Fallback: unzip xlsx and parse sharedStrings + the Questions sheet
       ourselves. Handles simple string/number cells — adequate for this
       template. */
    $tmpDir = sys_get_temp_dir() . '/xlsxread_' . uniqid();
    mkdir($tmpDir, 0700, true);
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) { rmdir($tmpDir); return false; }
    $zip->extractTo($tmpDir);
    $zip->close();

    /* Shared strings */
    $sharedStrings = [];
    $ssPath = $tmpDir . '/xl/sharedStrings.xml';
    if (file_exists($ssPath)) {
        $xml = simplexml_load_file($ssPath);
        foreach ($xml->si as $si) {
            $sharedStrings[] = (string)($si->t ?? implode('', array_map('strval', (array)($si->r ?? []))));
        }
    }

    /* Questions worksheet — located by sheet name, not assumed to be sheet1.xml */
    $sheetPath = locateQuestionsSheetXml($tmpDir);
    if (!file_exists($sheetPath)) {
        array_map('unlink', glob("$tmpDir/*/*") ?: []);
        array_map('unlink', glob("$tmpDir/*")   ?: []);
        rmdir($tmpDir);
        return false;
    }

    $xml  = simplexml_load_file($sheetPath);
    $rows = [];
    foreach ($xml->sheetData->row as $row) {
        $rowIdx = (int)$row['r'];
        if ($rowIdx <= 2) continue; // skip banner + header rows
        $cells = array_fill(0, 9, ''); // 9th = Subject (migration_v54)
        foreach ($row->c as $cell) {
            preg_match('/^([A-Z]+)/', (string)$cell['r'], $m);
            $colIdx = 0;
            foreach (str_split($m[1]) as $ch) $colIdx = $colIdx * 26 + (ord($ch) - 64);
            $colIdx--; // 0-indexed
            if ($colIdx >= 9) continue;
            $t = (string)($cell['t'] ?? '');
            if ($t === 's') {
                // Shared string — index into sharedStrings.xml
                $cells[$colIdx] = $sharedStrings[(int)($cell->v ?? '')] ?? '';
            } elseif ($t === 'inlineStr') {
                // Inline string — <is><t>text</t></is>, or multiple <r><t>..</t></r> runs.
                // Common from openpyxl/non-Excel xlsx writers; previously fell through
                // to $cell->v (which inline-string cells don't have) and read as blank.
                $is = $cell->is ?? null;
                $cells[$colIdx] = $is === null
                    ? ''
                    : (string)($is->t ?? implode('', array_map('strval', (array)($is->r ?? []))));
            } else {
                // Numeric / boolean / formula-result-string — plain <v> value
                $cells[$colIdx] = (string)($cell->v ?? '');
            }
        }
        if (implode('', $cells) === '') continue;
        $rows[] = $cells;
    }

    // Cleanup temp dir
    foreach (glob("$tmpDir/xl/worksheets/*") ?: [] as $f) { @unlink($f); }
    foreach (glob("$tmpDir/xl/_rels/*")      ?: [] as $f) { @unlink($f); }
    foreach (glob("$tmpDir/xl/*")            ?: [] as $f) { if (is_file($f)) @unlink($f); }
    @rmdir($tmpDir . '/xl/worksheets');
    @rmdir($tmpDir . '/xl/_rels');
    @rmdir($tmpDir . '/xl');
    foreach (glob("$tmpDir/_rels/*") ?: [] as $f) { @unlink($f); }
    @rmdir($tmpDir . '/_rels');
    foreach (glob("$tmpDir/*")               ?: [] as $f) { if (is_file($f)) @unlink($f); }
    @rmdir($tmpDir);

    return $rows;
}

function readCsv(string $path): array
{
    $rows = [];
    if (($h = fopen($path, 'r')) === false) return [];
    // Strip UTF-8 BOM
    $bom = fread($h, 3);
    if ($bom !== "\xEF\xBB\xBF") rewind($h);
    $line = 0;
    while (($cells = fgetcsv($h, 4096)) !== false) {
        $line++;
        if ($line <= 2) continue; // skip banner + header
        if (implode('', $cells) === '') continue;
        // Pad to 9 columns (9th = Subject, migration_v54)
        while (count($cells) < 9) $cells[] = '';
        $rows[] = array_slice($cells, 0, 9);
    }
    fclose($h);
    return $rows;
}

/* ── Word (.docx) reader ──────────────────────────────────────────────────
   No PhpWord/Composer library here (see the ZipArchive pattern already used
   by Admin/db-export.php and exam/export-word.php) — read word/document.xml
   directly out of the .docx zip and pull out each paragraph's plain text
   with a regex over <w:t> runs (avoids namespace-registration edge cases
   with SimpleXML for a file format this doesn't need full XML parsing for).
   Then a small line-based state machine matches the exact Q1./A)/B)/C)/D)/
   Answer:/Complexity:/Explanation: labels used by QuestionUpload_Template.docx,
   producing the SAME 8-column row shape validateRow() already expects — so
   every existing validation and insert rule below applies unchanged. */

/** Pull plain text out of each <w:p>...</w:p> paragraph, in document order,
 *  concatenating every <w:t> run inside it (a single typed line is often
 *  split across several runs by Word's spell-checker). */
function docxExtractParagraphs(string $documentXml): array
{
    $paragraphs = [];
    if (!preg_match_all('/<w:p\b[^>]*>(.*?)<\/w:p>/s', $documentXml, $pMatches)) {
        return [];
    }
    foreach ($pMatches[1] as $pInner) {
        $text = '';
        if (preg_match_all('/<w:t\b[^>]*>(.*?)<\/w:t>/s', $pInner, $tMatches)) {
            foreach ($tMatches[1] as $t) {
                $text .= html_entity_decode($t, ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
        }
        $paragraphs[] = trim($text);
    }
    return $paragraphs;
}

/** State machine over the paragraph list, matching the template's fixed
 *  labels and returning rows shaped like [qText,a1,a2,a3,a4,correct,
 *  complexity,explanation] — identical to readXlsx()/readCsv(). */
function docxParseQuestions(array $paragraphs): array
{
    $rows = [];
    $cur  = null;
    $mode = null; // 'question' | 'option:A'..'option:D' | 'explanation' | null

    $flush = function () use (&$cur, &$rows) {
        if ($cur !== null && trim($cur['qText']) !== '') {
            $letterToNum = ['A' => '1', 'B' => '2', 'C' => '3', 'D' => '4'];
            $rows[] = [
                trim($cur['qText']),
                trim($cur['options']['A']),
                trim($cur['options']['B']),
                trim($cur['options']['C']),
                trim($cur['options']['D']),
                $letterToNum[$cur['correct']] ?? $cur['correct'],
                $cur['complexity'],
                trim($cur['explanation']),
                trim($cur['subject'] ?? ''), // migration_v54 — multi-subject exams only
            ];
        }
        $cur = null;
    };

    foreach ($paragraphs as $line) {
        if ($line === '') { continue; } // blank paragraphs just separate questions

        if (preg_match('/^Q(?:uestion)?\s*\d*\s*[.\):]\s*(.*)$/i', $line, $m)) {
            $flush();
            $cur = ['qText' => $m[1], 'options' => ['A' => '', 'B' => '', 'C' => '', 'D' => ''],
                    'correct' => '', 'complexity' => '', 'explanation' => '', 'subject' => ''];
            $mode = 'question';
            continue;
        }
        if ($cur === null) { continue; } // ignore any instructions text before the first "Q1."

        if (preg_match('/^([A-Da-d])\s*[.\):]\s*(.*)$/', $line, $m)) {
            $letter = strtoupper($m[1]);
            $cur['options'][$letter] = $m[2];
            $mode = 'option:' . $letter;
            continue;
        }
        // These three intentionally match on the label ALONE (value optional)
        // so a still-blank template line like "Answer:" doesn't fall through
        // to the "continuation of the previous field" branch below and get
        // wrongly appended to option D's text.
        if (preg_match('/^(?:Correct\s*)?Answer\s*:?\s*([A-Da-d])?\s*$/i', $line, $m)) {
            $cur['correct'] = strtoupper($m[1] ?? '');
            $mode = null;
            continue;
        }
        if (preg_match('/^(?:Complexity|Difficulty)\s*:?\s*(Easy|Medium|Hard)?\s*$/i', $line, $m)) {
            // Normalise case (validateRow()'s allowed-values check is case-sensitive:
            // ['Easy','Medium','Hard',''] — a user typing "easy" must still match).
            $cur['complexity'] = (!empty($m[1])) ? ucfirst(strtolower($m[1])) : '';
            $mode = null;
            continue;
        }
        if (preg_match('/^Explanation\s*:?\s*(.*)$/i', $line, $m)) {
            $cur['explanation'] = $m[1];
            $mode = 'explanation';
            continue;
        }
        // Subject (migration_v54) — only meaningful for a multi-subject exam;
        // matches on the label alone too, same reasoning as Answer:/Complexity:
        // above, so a blank "Subject:" line doesn't get appended to Explanation.
        if (preg_match('/^Subject\s*:?\s*(.*)$/i', $line, $m)) {
            $cur['subject'] = $m[1];
            $mode = null;
            continue;
        }

        // Any other line is a wrapped continuation of whichever field was
        // last being filled (long question text or a long option, typed
        // across more than one line/paragraph in Word).
        if ($mode === 'question') {
            $cur['qText'] .= ' ' . $line;
        } elseif (is_string($mode) && str_starts_with($mode, 'option:')) {
            $letter = substr($mode, 7);
            $cur['options'][$letter] .= ' ' . $line;
        } elseif ($mode === 'explanation') {
            $cur['explanation'] .= ' ' . $line;
        }
        // else: stray line with nothing active to append to — ignore it.
    }
    $flush();

    return $rows;
}

function readDocx(string $path): array|false
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return false;
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    if ($xml === false) return false;

    return docxParseQuestions(docxExtractParagraphs($xml));
}

/* ── Validation ───────────────────────────────────────────────────────────── */
/**
 * @param array $examSubjectChoices lowercase subject label => SubjectInfoId.
 *        [] for a single-subject exam (column I is then ignored entirely).
 */
function validateRow(array $cells, int $rowNum, int $examId, bool $isMultiSubjectExam, array $examSubjectChoices): array
{
    [$qText, $a1, $a2, $a3, $a4, $correct, $complexity, $explanation, $subjectName] = array_pad($cells, 9, '');

    $qText      = trim($qText);
    $a1         = trim($a1);
    $a2         = trim($a2);
    $a3         = trim($a3);
    $a4         = trim($a4);
    $correct    = trim($correct);
    $complexity = trim($complexity);
    $explanation= trim($explanation);
    $subjectName= trim($subjectName);

    $errors = [];
    $subjectId = null;

    // Subject (migration_v54) — only checked for a multi-subject exam; a
    // normal single-subject exam ignores column I entirely (question keeps
    // inheriting the exam's own subject, same as before this feature).
    if ($isMultiSubjectExam) {
        $key = mb_strtolower($subjectName);
        if ($subjectName === '' || !isset($examSubjectChoices[$key])) {
            $valid = implode(', ', array_map('ucfirst', array_keys($examSubjectChoices)));
            $errors[] = "Subject must be one of this exam's sections ($valid) — got: '$subjectName'.";
        } else {
            $subjectId = $examSubjectChoices[$key];
        }
    }

    if ($qText === '') {
        $errors[] = 'Question text is required.';
    } elseif (mb_strlen($qText) > 2000) {
        $errors[] = 'Question text exceeds 2000 characters (' . mb_strlen($qText) . ').';
    }

    if ($a1 === '') $errors[] = 'Answer 1 (Option A) is required.';
    if ($a2 === '') $errors[] = 'Answer 2 (Option B) is required.';

    $correctInt = is_numeric($correct) ? (int)$correct : 0;
    if ($correct === '' || $correctInt < 1 || $correctInt > 4) {
        $errors[] = 'Correct Answer must be 1, 2, 3, or 4.';
    } else {
        $answerMap = [1 => $a1, 2 => $a2, 3 => $a3, 4 => $a4];
        if (($answerMap[$correctInt] ?? '') === '') {
            $errors[] = "Correct Answer is $correctInt but Answer $correctInt is blank.";
        }
    }

    $allowedComplexity = ['Easy', 'Medium', 'Hard', ''];
    if ($complexity !== '' && !in_array($complexity, $allowedComplexity, true)) {
        $errors[] = "Complexity must be Easy, Medium, or Hard (got: '$complexity').";
    }

    // Duplicate check — exam_questions (migration_v22+) is the authoritative
    // exam/question mapping (questions.php and the "Total in Exam Now" KPI
    // both read through it), so check there first; a question added via the
    // modern question-edit.php flow has no questions.ExamInfoId set at all
    // and would be missed by a legacy-only check. Fall back to the legacy
    // direct FK only if exam_questions doesn't exist on this DB yet.
    if ($qText !== '' && empty($errors)) {
        try {
            try {
                $dup = Database::fetchOne(
                    "SELECT q.QuestionId
                       FROM exam_questions eq
                       JOIN questions q ON q.QuestionId = eq.QuestionId
                      WHERE eq.ExamInfoId = ? AND TRIM(q.QuestionDesc) = ?
                        AND COALESCE(q.IsDeleted,'N') = 'N'
                      LIMIT 1",
                    [$examId, $qText]);
            } catch (Exception $eDel) {
                // migration_v43 not yet run — IsDeleted column missing, check without it.
                $dup = Database::fetchOne(
                    "SELECT q.QuestionId
                       FROM exam_questions eq
                       JOIN questions q ON q.QuestionId = eq.QuestionId
                      WHERE eq.ExamInfoId = ? AND TRIM(q.QuestionDesc) = ?
                      LIMIT 1",
                    [$examId, $qText]);
            }
            if (!$dup) {
                try {
                    $dup = Database::fetchOne(
                        "SELECT QuestionId FROM questions
                          WHERE ExamInfoId = ? AND TRIM(QuestionDesc) = ? LIMIT 1",
                        [$examId, $qText]);
                } catch (Exception $eLegacy) { /* legacy ExamInfoId column gone — fine, exam_questions already covered it */ }
            }
            if ($dup) {
                $errors[] = 'A question with this exact text already exists in this exam.';
            }
        } catch (Exception $e) {
            // Never let one row's DB error (e.g. a charset/collation mismatch —
            // see migrations/migration_v41.sql) abort the whole batch and lose
            // every other row's validation results. Flag this row and move on.
            error_log("BulkUploadQuestions: duplicate check failed for row $rowNum: " . $e->getMessage());
            $errors[] = 'Could not check for duplicate questions (database error) — see server error log.';
        }
    }

    return [
        'errors'      => $errors,
        'qText'       => $qText,
        'a1'          => $a1,
        'a2'          => $a2,
        'a3'          => $a3,
        'a4'          => $a4,
        'correct'     => $correctInt,
        'complexity'  => $complexity === '' ? 'Medium' : $complexity,
        'explanation' => $explanation,
        'subjectId'   => $subjectId, // migration_v54 — null for a single-subject exam
    ];
}

/* ── Handle upload POST ───────────────────────────────────────────────────── */
$results    = null;   // null = not yet uploaded
$inserted   = 0;
$errorRows  = [];
$totalRows  = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['bulkFile'])) {
    Auth::validateCsrf();

    $file    = $_FILES['bulkFile'];
    $tmpPath = $file['tmp_name'];
    $origName= strtolower($file['name'] ?? '');
    $ext     = pathinfo($origName, PATHINFO_EXTENSION);
    $uploadErr = '';

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $uploadErr = 'File upload failed (error code ' . $file['error'] . ').';
    } elseif (!in_array($ext, ['xlsx', 'csv', 'docx'], true)) {
        $uploadErr = 'Only .xlsx, .csv, and .docx files are accepted.';
    } elseif ($file['size'] > 5 * 1024 * 1024) {
        $uploadErr = 'File size must not exceed 5 MB.';
    }

    if ($uploadErr) {
        $errorRows[] = ['row' => '—', 'errors' => [$uploadErr]];
        $results = true;
    } else {
        $rows = match ($ext) {
            'xlsx'  => readXlsx($tmpPath),
            'docx'  => readDocx($tmpPath),
            default => readCsv($tmpPath),
        };

        if ($rows === false) {
            $errorRows[] = ['row' => '—', 'errors' => [
                $ext === 'docx'
                    ? 'Could not read the file. Ensure it is a valid .docx file (not .doc, and not password-protected).'
                    : 'Could not read the file. Ensure it is a valid .xlsx file.',
            ]];
            $results = true;
        } else {
            $totalRows = count($rows);
            if ($totalRows === 0) {
                $errorRows[] = ['row' => '—', 'errors' => [
                    $ext === 'docx'
                        ? 'No questions were found. Each question must start with "Q1.", "Q2.", etc. ' .
                          'exactly as shown in the downloadable Word template — check your file follows ' .
                          'that pattern (Q#., A) B) C) D), Answer:, Complexity:, Explanation:).'
                        : 'No question rows were found. Data must start on row 3 of the "Questions" ' .
                          'sheet (rows 1–2 are reserved for the banner and column headers) — check ' .
                          'that your file follows the downloadable template exactly.',
                ]];
                $results = true;
            } elseif ($totalRows > 500) {
                $errorRows[] = ['row' => '—', 'errors' => ["File contains $totalRows rows — maximum is 500 per upload."]];
                $results = true;
            } else {
                $validRows = [];
                foreach ($rows as $i => $cells) {
                    // xlsx/csv: 1-based spreadsheet row, accounting for 2 header rows.
                    // docx: there are no header rows — label by question number instead
                    // (Q1, Q2, ...) since that's what the admin actually typed and can find.
                    $rowNum = $ext === 'docx' ? ($i + 1) : ($i + 3);
                    $v = validateRow($cells, $rowNum, $examId, $isMultiSubjectExam, $examSubjectChoices);
                    if ($v['errors']) {
                        $errorRows[] = [
                            'row'    => $rowNum,
                            'text'   => mb_substr($cells[0], 0, 60) . (mb_strlen($cells[0]) > 60 ? '…' : ''),
                            'errors' => $v['errors'],
                        ];
                    } else {
                        $validRows[] = $v;
                    }
                }

                // Insert valid rows in a transaction.
                // Schema note (migration_v22+): questions is a pure question
                // bank keyed by SubjectInfoId — it has no GradeInfoId column
                // at all (that caused the original crash here) and exam
                // assignment happens exclusively via the exam_questions join
                // table, not a direct FK. $exam already carries SubjectInfoId
                // (selected via `e.*` at the top of this file), so it's
                // resolved once up front instead of re-querying examinfo on
                // every row.
                // Multi-subject exam (migration_v54): each row instead carries
                // its own $v['subjectId'], validated above against this exam's
                // configured sections — $examSubjectFallback is only used as
                // the (rare) single-subject fallback below.
                $examSubjectFallback = (int)($exam['SubjectInfoId'] ?? 0) ?: null;

                if ($validRows) {
                    Database::beginTransaction();
                    try {
                        foreach ($validRows as $v) {
                            $subjectId = $isMultiSubjectExam ? $v['subjectId'] : $examSubjectFallback;

                            // Insert into questions
                            Database::execute(
                                "INSERT INTO questions
                                    (SubjectInfoId, QuestionDesc,
                                     CorrectAnswer, OperatorInd, IsActive,
                                     QuestionType, Complexity, Explanation,
                                     ImageInd, NumofImages)
                                 VALUES (?, ?, ?, 'N', 'Y', 'MCQ', ?, ?, 'N', 0)",
                                [$subjectId, $v['qText'], (string)$v['correct'],
                                 $v['complexity'], $v['explanation']]);

                            $qid = (int)Database::lastInsertId();

                            // Link to this exam via exam_questions (migration_v22+).
                            // This is the authoritative exam/question mapping — without
                            // it the question is invisible on questions.php, which lists
                            // by joining exam_questions, not a legacy FK on questions.
                            try {
                                Database::execute(
                                    "INSERT IGNORE INTO exam_questions (ExamInfoId, QuestionId, IsActive)
                                     VALUES (?, ?, 'Y')",
                                    [$examId, $qid]);
                            } catch (Exception $eq) {
                                // Pre-migration schema (no exam_questions table yet) —
                                // fall back to the legacy direct FK so the question is
                                // still findable, mirroring exam/question-edit.php.
                                error_log("BulkUploadQuestions: exam_questions insert failed for QuestionId=$qid: " . $eq->getMessage());
                                try {
                                    Database::execute("UPDATE questions SET ExamInfoId=? WHERE QuestionId=?", [$examId, $qid]);
                                } catch (Exception $eq2) {}
                            }

                            // Insert into answers
                            Database::execute(
                                "INSERT INTO answers
                                    (QuestionId, Answer1, Answer2, Answer3, Answer4,
                                     AnsImageInd, MultiImageInd)
                                 VALUES (?, ?, ?, ?, ?, 'N', 'N')",
                                [$qid,
                                 $v['a1'], $v['a2'],
                                 $v['a3'] !== '' ? $v['a3'] : null,
                                 $v['a4'] !== '' ? $v['a4'] : null]);

                            $inserted++;
                        }
                        Database::commit();
                    } catch (Exception $ex) {
                        Database::rollBack();
                        $errorRows[] = ['row' => '—', 'errors' => ['Database error: ' . $ex->getMessage()]];
                        $inserted = 0;
                    }
                }
                $results = true;
            }
        }
    }
}

/* ── Current total questions in this exam (for the results screen) ──────────
   Counts via exam_questions (migration_v22+, matches questions.php's own
   listing query) so the number shown matches what the exam actually displays;
   falls back to legacy ExamInfoId on questions for pre-migration databases. */
$totalQuestionsInExam = 0;
if ($results !== null) {
    try {
        $totalQuestionsInExam = (int)(Database::fetchOne(
            "SELECT COUNT(*) AS c FROM exam_questions eq
               JOIN questions q ON q.QuestionId = eq.QuestionId
              WHERE eq.ExamInfoId = ? AND COALESCE(q.IsDeleted,'N') = 'N'", [$examId])['c'] ?? 0);
    } catch (Exception $e) {
        try {
            $totalQuestionsInExam = (int)(Database::fetchOne(
                "SELECT COUNT(*) AS c FROM exam_questions WHERE ExamInfoId = ?", [$examId])['c'] ?? 0);
        } catch (Exception $e2) {
            $totalQuestionsInExam = (int)(Database::fetchOne(
                "SELECT COUNT(*) AS c FROM questions WHERE ExamInfoId = ?", [$examId])['c'] ?? 0);
        }
    }
}

$pageTitle = 'Bulk Upload Questions';
include __DIR__ . '/../includes/header.php';
?>
<style>
.upload-card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:24px 28px;margin-bottom:20px;}
.upload-zone{border:2px dashed #93c5fd;border-radius:8px;padding:32px;text-align:center;
             background:#eff6ff;cursor:pointer;transition:background .2s;}
.upload-zone:hover,.upload-zone.drag-over{background:#dbeafe;border-color:#3b82f6;}
.upload-zone input[type=file]{display:none;}
.upload-zone-icon{font-size:2.5rem;margin-bottom:10px;}
.result-tbl th{background:#1e3a5f;color:#fff;padding:8px 12px;font-size:.82rem;text-align:left;}
.result-tbl td{padding:7px 12px;border-bottom:1px solid #f1f5f9;font-size:.83rem;vertical-align:top;}
.badge-ok {background:#d1fae5;color:#065f46;padding:2px 8px;border-radius:10px;font-size:.75rem;font-weight:700;}
.badge-err{background:#fee2e2;color:#991b1b;padding:2px 8px;border-radius:10px;font-size:.75rem;font-weight:700;}
.kpi-row{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px;}
.kpi{padding:14px 20px;border-radius:8px;text-align:center;min-width:120px;}
.kpi-val{font-size:1.8rem;font-weight:800;}
.kpi-lbl{font-size:.78rem;margin-top:2px;}
.step-num{display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;
          border-radius:50%;background:#1e3a5f;color:#fff;font-weight:700;font-size:.85rem;flex-shrink:0;}
</style>

<!-- Breadcrumb -->
<div style="margin-bottom:16px;font-size:.87rem;color:#6b7280;">
  <a href="ExamList.php">Exam List</a> &rsaquo;
  <a href="../exam/questions.php?examId=<?php echo $examId; ?>"><?php echo htmlspecialchars($exam['ExamName']); ?></a> &rsaquo;
  Bulk Upload Questions
</div>

<div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;margin-bottom:20px;">
  <div>
    <h2 style="margin:0;">Bulk Upload Questions</h2>
    <div style="color:#6b7280;font-size:.88rem;margin-top:4px;">
      <?php echo htmlspecialchars($exam['ExamName']); ?>
      &bull; <?php echo htmlspecialchars($exam['GradeName'] ?? ''); ?>
      &bull; <?php echo htmlspecialchars($exam['SubjectName'] ?? ''); ?>
    </div>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;">
    <a href="QuestionUpload_Template.xlsx" download class="btn btn-primary">
      &#11123; Excel Template (.xlsx)
    </a>
    <a href="QuestionUpload_Template.docx" download class="btn btn-primary" style="background:#2563eb;">
      &#11123; Word Template (.docx)
    </a>
    <a href="../exam/questions.php?examId=<?php echo $examId; ?>" class="btn btn-secondary">
      ← Back to Questions
    </a>
  </div>
</div>

<?php if ($isMultiSubjectExam): ?>
<div class="upload-card" style="border-color:#fbbf24;background:#fffbeb;">
  <strong style="color:#92400e;">&#9888; This is a multi-subject exam.</strong>
  <span style="color:#92400e;font-size:.88rem;">
    Every row needs a 9th column, <strong>Subject</strong>, naming one of this exam's
    configured sections exactly:
    <?php echo htmlspecialchars(implode(', ', array_map('ucfirst', array_keys($examSubjectChoices)))); ?>.
    Rows with a missing or unrecognised Subject will be rejected — see
    <a href="../exam/manage.php?InfoId=<?php echo $examId; ?>">Exam Pattern</a> to check the exact section names.
  </span>
</div>
<?php endif; ?>

<?php if ($results === null): /* ── Upload form ── */ ?>
<!-- How-to steps -->
<div class="upload-card">
  <h3 style="margin-top:0;color:#1e3a5f;">How to bulk upload</h3>
  <div style="display:flex;flex-direction:column;gap:10px;">
    <?php $steps = [
      "Download either template above — Excel (.xlsx) for a spreadsheet, or Word (.docx) if you'd rather type questions out like a document (handy for question text with lots of formulas or symbols).",
      "Excel: fill in your questions from row 3 onwards; don't edit or delete rows 1–2. Word: fill in the Q1./A)/B)/C)/D)/Answer:/Complexity:/Explanation: blocks exactly as shown, copying a block to add more questions.",
      "Each question needs: Question Text, at least Answer 1–2 (3–4 optional), Correct Answer, and optionally Complexity and Explanation.",
      "Save the file as .xlsx, .csv, or .docx.",
      "Upload the file below. Valid questions are inserted immediately; invalid ones are listed with reasons so you can fix and re-upload just those.",
    ];
    foreach ($steps as $i => $step): ?>
    <div style="display:flex;align-items:flex-start;gap:10px;">
      <span class="step-num"><?php echo $i+1; ?></span>
      <span style="font-size:.9rem;padding-top:3px;"><?php echo htmlspecialchars($step); ?></span>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- Upload form -->
<div class="upload-card">
  <h3 style="margin-top:0;color:#1e3a5f;">Upload File</h3>
  <form method="post" enctype="multipart/form-data" id="uploadForm">
    <input type="hidden" name="csrf_token" value="<?php echo Auth::csrfToken(); ?>">
    <input type="hidden" name="examId"     value="<?php echo $examId; ?>">

    <div class="upload-zone" id="dropZone" onclick="document.getElementById('fileInput').click()">
      <div class="upload-zone-icon">📂</div>
      <div style="font-weight:600;font-size:1rem;color:#1e40af;">Click to choose or drag & drop your file here</div>
      <div style="font-size:.83rem;color:#6b7280;margin-top:6px;">Accepts .xlsx, .csv, or .docx &nbsp;|&nbsp; Max 5 MB &nbsp;|&nbsp; Up to 500 questions</div>
      <div id="fileName" style="margin-top:10px;font-size:.88rem;font-weight:600;color:#1e40af;"></div>
      <input type="file" id="fileInput" name="bulkFile" accept=".xlsx,.csv,.docx" onchange="showFile(this)">
    </div>

    <div style="margin-top:16px;display:flex;gap:10px;align-items:center;">
      <button type="submit" id="submitBtn" class="btn btn-primary" disabled style="min-width:160px;">
        ⬆ Upload & Process
      </button>
      <span id="uploadStatus" style="font-size:.85rem;color:#6b7280;"></span>
    </div>
  </form>
</div>

<?php else: /* ── Results ── */ ?>
<!-- Summary KPIs -->
<div class="kpi-row">
  <div class="kpi" style="background:#dbeafe;color:#1e40af;">
    <div class="kpi-val"><?php echo $totalRows; ?></div>
    <div class="kpi-lbl">Rows in File</div>
  </div>
  <div class="kpi" style="background:#d1fae5;color:#065f46;">
    <div class="kpi-val"><?php echo $inserted; ?></div>
    <div class="kpi-lbl">Imported ✓</div>
  </div>
  <div class="kpi" style="background:<?php echo $errorRows ? '#fee2e2' : '#f1f5f9'; ?>;
                           color:<?php echo $errorRows ? '#991b1b' : '#475569'; ?>;">
    <div class="kpi-val"><?php echo count($errorRows); ?></div>
    <div class="kpi-lbl">Errors ✗</div>
  </div>
  <div class="kpi" style="background:#ede9fe;color:#5b21b6;">
    <div class="kpi-val"><?php echo $totalQuestionsInExam; ?></div>
    <div class="kpi-lbl">Total in Exam Now</div>
  </div>
</div>

<?php if ($inserted > 0): ?>
<div style="background:#d1fae5;border:1px solid #6ee7b7;border-radius:8px;
            padding:12px 16px;margin-bottom:16px;color:#065f46;font-weight:600;">
  ✓ <?php echo $inserted; ?> question<?php echo $inserted !== 1 ? 's' : ''; ?> added to "<?php echo htmlspecialchars($exam['ExamName']); ?>"
  — this exam now has <?php echo $totalQuestionsInExam; ?> question<?php echo $totalQuestionsInExam !== 1 ? 's' : ''; ?> total.
</div>
<?php endif; ?>

<?php if ($errorRows): ?>
<div class="upload-card" style="border-color:#fca5a5;">
  <h3 style="margin-top:0;color:#991b1b;">&#10006; Rows with Errors (not imported)</h3>
  <p style="font-size:.87rem;color:#6b7280;margin-bottom:12px;">
    Fix these rows in your file and re-upload. Rows that were successfully imported above do not need to be re-uploaded.
  </p>
  <table class="result-tbl" style="width:100%;border-collapse:collapse;">
    <thead>
      <tr>
        <th style="width:60px;">Row #</th>
        <th style="width:35%;">Question Text (preview)</th>
        <th>Validation Errors</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($errorRows as $er): ?>
    <tr>
      <td style="text-align:center;"><span class="badge-err"><?php echo htmlspecialchars((string)$er['row']); ?></span></td>
      <td style="color:#6b7280;font-style:italic;font-size:.82rem;"><?php echo htmlspecialchars($er['text'] ?? ''); ?></td>
      <td>
        <?php foreach ($er['errors'] as $msg): ?>
        <div style="display:flex;align-items:flex-start;gap:6px;margin-bottom:3px;">
          <span style="color:#ef4444;flex-shrink:0;">•</span>
          <span style="font-size:.83rem;"><?php echo htmlspecialchars($msg); ?></span>
        </div>
        <?php endforeach; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<div style="display:flex;gap:10px;margin-top:8px;">
  <a href="BulkUploadQuestions.php?examId=<?php echo $examId; ?>" class="btn btn-primary">
    ⬆ Upload Another File
  </a>
  <a href="../exam/questions.php?examId=<?php echo $examId; ?>" class="btn btn-secondary">
    ← View All Questions
  </a>
  <a href="QuestionUpload_Template.xlsx" download class="btn btn-secondary">
    &#11123; Excel Template
  </a>
  <a href="QuestionUpload_Template.docx" download class="btn btn-secondary">
    &#11123; Word Template
  </a>
</div>
<?php endif; ?>

<script>
var dropZone  = document.getElementById('dropZone');
var fileInput = document.getElementById('fileInput');
var submitBtn = document.getElementById('submitBtn');

function showFile(input) {
  if (input.files && input.files[0]) {
    document.getElementById('fileName').textContent = '📄 ' + input.files[0].name;
    submitBtn.disabled = false;
  }
}

/* Drag-and-drop */
if (dropZone) {
  dropZone.addEventListener('dragover',  function(e){ e.preventDefault(); dropZone.classList.add('drag-over'); });
  dropZone.addEventListener('dragleave', function()  { dropZone.classList.remove('drag-over'); });
  dropZone.addEventListener('drop', function(e) {
    e.preventDefault();
    dropZone.classList.remove('drag-over');
    if (e.dataTransfer.files.length) {
      fileInput.files = e.dataTransfer.files;
      showFile(fileInput);
    }
  });
}

/* Show processing state on submit */
var form = document.getElementById('uploadForm');
if (form) {
  form.addEventListener('submit', function() {
    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.textContent = '⏳ Processing…';
    }
    document.getElementById('uploadStatus').textContent = 'Validating and importing — please wait…';
  });
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
