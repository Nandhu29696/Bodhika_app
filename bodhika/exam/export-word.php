<?php
/**
 * exam/export-word.php — generate a printable, fully-editable Word (.docx)
 * question paper from an exam's question bank.
 *
 * No Composer/PhpWord is available in this project (see Admin/db-export.php,
 * which hand-rolls .xlsx the same way) — so this hand-rolls the minimal valid
 * OOXML for a .docx via PHP's built-in ZipArchive, following that same file's
 * pattern (tempnam -> ZipArchive::addFromString for each part -> read bytes
 * -> unlink -> stream with the right headers).
 *
 * Question/option text in this question bank is plain UTF-8 (precomposed
 * Unicode superscripts/subscripts and math symbols — ², √, ×, π, etc. — see
 * neet_physics.sql / jee_mathematics.sql), not LaTeX or MathML, so no real
 * OOXML math (<m:oMath>) objects are needed: correctly-escaped UTF-8 text
 * runs render those symbols natively in Word.
 *
 * Flow:
 *   GET  ?examId=N            Step 1: small options form (Duration, Max
 *                             Marks, Answer Key toggle) — same two-step
 *                             GET-setup/POST-action shape as
 *                             Admin/GenerateCertificates.php.
 *   POST ?examId=N            Step 2: streams the generated .docx and exits.
 *
 * Admin or Institute Admin (own institute's exams only), matching
 * exam/questions.php (the page this is linked from).
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
require_once __DIR__ . '/../Lib/AppSettings.php';
Auth::requireLogin('../auth/login.php');
$isFullAdmin = Auth::isAdmin();
$isInstAdmin = Auth::isInstituteAdmin();
if (!$isFullAdmin && !$isInstAdmin) { header('Location: search.php'); exit; }

$examId = filter_input(INPUT_GET, 'examId', FILTER_VALIDATE_INT)
        ?: filter_input(INPUT_POST, 'examId', FILTER_VALIDATE_INT);
if (!$examId) { header('Location: search.php'); exit; }

/* ── Load exam info (same shape as exam/questions.php) ───────────────────── */
$exam = Database::fetchOne(
    "SELECT e.*, g.GradeName, s.SubjectName, i.InstituteName AS ExamInstituteName
       FROM examinfo e
  LEFT JOIN gradeinfo   g ON g.GradeInfoId   = e.GradeInfoId
  LEFT JOIN subjectinfo s ON s.SubjectInfoId = e.SubjectInfoId
  LEFT JOIN institutes  i ON i.InstituteId   = e.ExamInstituteId
      WHERE e.ExamInfoId = ? LIMIT 1", [$examId]);
if (!$exam) { header('Location: search.php'); exit; }

// Institute admins may only export exams that belong to their own institute.
if ($isInstAdmin && !$isFullAdmin && (int)($exam['ExamInstituteId'] ?? 0) !== (int)Auth::currentInstituteId()) {
    header('Location: search.php'); exit;
}

/* ── Load active questions — identical fallback cascade to questions.php,
   so this export always matches what the admin sees on that page. ───────── */
function ewp_load_questions(int $examId): array
{
    try {
        return Database::fetchAll(
            "SELECT q.QuestionId, q.QuestionDesc, q.ImageInd, q.ImageLoc,
                    q.CorrectAnswer,
                    COALESCE(q.Complexity,   'Medium') AS Complexity,
                    COALESCE(eq.IsActive,    'Y')      AS IsActive,
                    COALESCE(q.QuestionType, 'MCQ')    AS QuestionType,
                    q.Explanation,
                    a.Answer1, a.Answer2, a.Answer3, a.Answer4,
                    a.YesNo1,  a.YesNo2,  a.YesNo3,  a.YesNo4,
                    COALESCE(a.NumStatements, 4)        AS NumStatements,
                    ai.AnswerImage1Loc, ai.AnswerImage2Loc,
                    ai.AnswerImage3Loc, ai.AnswerImage4Loc
               FROM exam_questions eq
               JOIN questions q  ON q.QuestionId = eq.QuestionId
          LEFT JOIN answers   a  ON a.QuestionId = q.QuestionId
          LEFT JOIN answerimages ai ON ai.QuestionId = q.QuestionId
              WHERE eq.ExamInfoId = ? AND COALESCE(q.IsDeleted,'N') = 'N'
                AND COALESCE(eq.IsActive,'Y') = 'Y'
              ORDER BY q.QuestionId",
            [$examId]);
    } catch (Exception $e) {
        try {
            return Database::fetchAll(
                "SELECT q.QuestionId,
                        COALESCE(sq.QuestionDesc,  q.QuestionDesc)          AS QuestionDesc,
                        COALESCE(sq.ImageInd,      q.ImageInd)              AS ImageInd,
                        COALESCE(sq.ImageLoc,      q.ImageLoc)              AS ImageLoc,
                        COALESCE(sq.CorrectAnswer, q.CorrectAnswer)         AS CorrectAnswer,
                        COALESCE(sq.Complexity,    q.Complexity,  'Medium') AS Complexity,
                        COALESCE(sq.IsActive,      q.IsActive,    'Y')      AS IsActive,
                        COALESCE(sq.QuestionType,  q.QuestionType,'MCQ')    AS QuestionType,
                        COALESCE(sq.Explanation,   q.Explanation)           AS Explanation,
                        a.Answer1, a.Answer2, a.Answer3, a.Answer4,
                        a.YesNo1,  a.YesNo2,  a.YesNo3,  a.YesNo4,
                        COALESCE(a.NumStatements, 4) AS NumStatements,
                        NULL AS AnswerImage1Loc, NULL AS AnswerImage2Loc,
                        NULL AS AnswerImage3Loc, NULL AS AnswerImage4Loc
                   FROM questions q
              LEFT JOIN questions sq ON sq.QuestionId = q.LinkedFromQuestionId
              LEFT JOIN answers   a  ON a.QuestionId  = COALESCE(q.LinkedFromQuestionId, q.QuestionId)
                  WHERE q.ExamInfoId = ? AND COALESCE(q.IsDeleted,'N') = 'N'
                    AND COALESCE(q.IsActive,'Y') = 'Y'
                  ORDER BY q.QuestionId",
                [$examId]);
        } catch (Exception $e2) {
            return Database::fetchAll(
                "SELECT q.QuestionId, q.QuestionDesc, q.ImageInd, q.ImageLoc, q.CorrectAnswer,
                        COALESCE(q.Complexity,'Medium') AS Complexity,
                        COALESCE(q.IsActive,'Y') AS IsActive, 'MCQ' AS QuestionType,
                        q.Explanation,
                        a.Answer1, a.Answer2, a.Answer3, a.Answer4,
                        NULL AS YesNo1, NULL AS YesNo2, NULL AS YesNo3, NULL AS YesNo4, 4 AS NumStatements,
                        NULL AS AnswerImage1Loc, NULL AS AnswerImage2Loc,
                        NULL AS AnswerImage3Loc, NULL AS AnswerImage4Loc
                   FROM questions q
              LEFT JOIN answers a ON a.QuestionId = q.QuestionId
                  WHERE q.ExamInfoId = ? AND COALESCE(q.IsActive,'Y') = 'Y'
                  ORDER BY q.QuestionId",
                [$examId]);
        }
    }
}

/* ═══════════════════════════════════════════════════════════════════════
   Minimal OOXML (.docx) writer — no external library available (see the
   file header comment). Accumulates body XML + embedded images, then packs
   everything into a real .docx zip, matching Admin/db-export.php's pattern
   for .xlsx.
   ═══════════════════════════════════════════════════════════════════════ */
final class SimpleDocx
{
    private string $body = '';
    /** @var array<string,array{name:string,data:string}> rId => media file */
    private array $media = [];
    /** @var array<string,bool> file extension => registered in [Content_Types].xml */
    private array $mediaExts = [];
    private int $imgSeq = 0;

    /** Escape text for use inside a w:t element; strips XML-illegal control chars. */
    private static function esc(string $s): string
    {
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s) ?? $s;
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /** Append a plain paragraph. $opts: bold, italic, size (pt), align (left/center/right),
     *  indent (twentieths of a point), after (spacing after, twentieths of a point), border (bool). */
    public function addParagraph(string $text, array $opts = []): void
    {
        $rPr = '';
        if (!empty($opts['bold']))   $rPr .= '<w:b/>';
        if (!empty($opts['italic'])) $rPr .= '<w:i/>';
        if (!empty($opts['size']))   $rPr .= '<w:sz w:val="' . ((int)$opts['size'] * 2) . '"/><w:szCs w:val="' . ((int)$opts['size'] * 2) . '"/>';
        $rPr = $rPr !== '' ? '<w:rPr>' . $rPr . '</w:rPr>' : '';

        $pPr = '<w:spacing w:after="' . (int)($opts['after'] ?? 120) . '"/>';
        if (!empty($opts['align']))  $pPr .= '<w:jc w:val="' . htmlspecialchars($opts['align'], ENT_QUOTES) . '"/>';
        if (!empty($opts['indent'])) $pPr .= '<w:ind w:left="' . (int)$opts['indent'] . '"/>';
        if (!empty($opts['border'])) $pPr .= '<w:pBdr><w:bottom w:val="single" w:sz="6" w:space="4" w:color="999999"/></w:pBdr>';

        $lines = explode("\n", $text);
        $runs  = '';
        foreach ($lines as $i => $line) {
            if ($i > 0) $runs .= '<w:r>' . $rPr . '<w:br/></w:r>';
            $runs .= '<w:r>' . $rPr . '<w:t xml:space="preserve">' . self::esc($line) . '</w:t></w:r>';
        }
        if ($runs === '') $runs = '<w:r>' . $rPr . '<w:t></w:t></w:r>'; // keep blank paragraphs valid

        $this->body .= '<w:p><w:pPr>' . $pPr . '</w:pPr>' . $runs . '</w:p>';
    }

    public function addPageBreak(): void
    {
        $this->body .= '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
    }

    /**
     * Embed an image (by absolute filesystem path) as its own centered
     * paragraph, scaled to fit within $maxWidthIn (inches), preserving
     * aspect ratio. Returns true on success; false (no-op) if the file is
     * missing or unreadable, so a bad ImageLoc never breaks the export.
     */
    public function addImage(string $absPath, float $maxWidthIn = 4.0): bool
    {
        if (!is_file($absPath)) return false;
        $info = @getimagesize($absPath);
        if (!$info) return false;
        $data = @file_get_contents($absPath);
        if ($data === false || $data === '') return false;

        [$pxW, $pxH] = $info;
        if ($pxW <= 0 || $pxH <= 0) return false;

        $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
        if (!in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'bmp'], true)) $ext = 'png';

        // Assume a 96dpi source (typical for uploaded question-diagram screenshots);
        // scale down to fit the print column width, never scale up past the original.
        $dpi      = 96.0;
        $widthIn  = $pxW / $dpi;
        $heightIn = $pxH / $dpi;
        if ($widthIn > $maxWidthIn) {
            $ratio     = $maxWidthIn / $widthIn;
            $widthIn  *= $ratio;
            $heightIn *= $ratio;
        }
        $cx = (int)round($widthIn  * 914400); // EMU per inch
        $cy = (int)round($heightIn * 914400);
        if ($cx <= 0 || $cy <= 0) return false;

        $this->imgSeq++;
        $rId   = 'rIdImg' . $this->imgSeq;
        $fname = 'image' . $this->imgSeq . '.' . $ext;
        $this->media[$rId] = ['name' => $fname, 'data' => $data];
        $this->mediaExts[$ext] = true;
        $docPrId = 1000 + $this->imgSeq;

        $this->body .= '<w:p><w:pPr><w:jc w:val="center"/><w:spacing w:after="160"/></w:pPr><w:r><w:drawing>'
            . '<wp:inline distT="0" distB="0" distL="0" distR="0">'
            . '<wp:extent cx="' . $cx . '" cy="' . $cy . '"/>'
            . '<wp:effectExtent l="0" t="0" r="0" b="0"/>'
            . '<wp:docPr id="' . $docPrId . '" name="Image' . $docPrId . '"/>'
            . '<wp:cNvGraphicFramePr><a:graphicFrameLocks noChangeAspect="1"/></wp:cNvGraphicFramePr>'
            . '<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            . '<pic:pic><pic:nvPicPr><pic:cNvPr id="' . $docPrId . '" name="Image' . $docPrId . '"/><pic:cNvPicPr/></pic:nvPicPr>'
            . '<pic:blipFill><a:blip r:embed="' . $rId . '"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
            . '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm>'
            . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr>'
            . '</pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>';

        return true;
    }

    /** Two-column table: header row + data rows (array of [col1, col2]). */
    public function addTwoColTable(string $h1, string $h2, array $rows): void
    {
        $cellXml = function (string $text, bool $bold = false): string {
            $rPr = $bold ? '<w:rPr><w:b/></w:rPr>' : '';
            return '<w:tc><w:tcPr><w:tcW w:w="0" w:type="auto"/></w:tcPr>'
                 . '<w:p><w:r>' . $rPr . '<w:t xml:space="preserve">' . self::esc($text) . '</w:t></w:r></w:p></w:tc>';
        };
        $tbl = '<w:tbl><w:tblPr><w:tblStyle w:val="TableGrid"/><w:tblW w:w="0" w:type="auto"/>'
             . '<w:tblBorders>'
             . '<w:top w:val="single" w:sz="4" w:color="999999"/><w:left w:val="single" w:sz="4" w:color="999999"/>'
             . '<w:bottom w:val="single" w:sz="4" w:color="999999"/><w:right w:val="single" w:sz="4" w:color="999999"/>'
             . '<w:insideH w:val="single" w:sz="4" w:color="999999"/><w:insideV w:val="single" w:sz="4" w:color="999999"/>'
             . '</w:tblBorders></w:tblPr>';
        $tbl .= '<w:tr>' . $cellXml($h1, true) . $cellXml($h2, true) . '</w:tr>';
        foreach ($rows as $r) {
            $tbl .= '<w:tr>' . $cellXml((string)$r[0]) . $cellXml((string)$r[1]) . '</w:tr>';
        }
        $tbl .= '</w:tbl><w:p/>'; // trailing empty paragraph — required after a table at body end
        $this->body .= $tbl;
    }

    /** Assemble every OOXML part and return the finished .docx as raw bytes. */
    public function build(): string
    {
        $docXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document '
            . 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
            . 'xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" '
            . 'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
            . 'xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            . '<w:body>' . $this->body
            . '<w:sectPr><w:pgSz w:w="12240" w:h="15840"/>'
            . '<w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440" w:header="720" w:footer="720" w:gutter="0"/>'
            . '</w:sectPr></w:body></w:document>';

        $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:cs="Calibri"/>'
            . '<w:sz w:val="22"/><w:szCs w:val="22"/></w:rPr></w:rPrDefault></w:docDefaults>'
            . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/></w:style>'
            . '<w:style w:type="table" w:styleId="TableGrid"><w:name w:val="Table Grid"/>'
            . '<w:tblPr><w:tblBorders>'
            . '<w:top w:val="single" w:sz="4" w:color="999999"/><w:left w:val="single" w:sz="4" w:color="999999"/>'
            . '<w:bottom w:val="single" w:sz="4" w:color="999999"/><w:right w:val="single" w:sz="4" w:color="999999"/>'
            . '<w:insideH w:val="single" w:sz="4" w:color="999999"/><w:insideV w:val="single" w:sz="4" w:color="999999"/>'
            . '</w:tblBorders></w:tblPr></w:style>'
            . '</w:styles>';

        $ctXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>';
        foreach (array_keys($this->mediaExts) as $ext) {
            $mime = match ($ext) {
                'jpg', 'jpeg' => 'image/jpeg',
                'gif'         => 'image/gif',
                'bmp'         => 'image/bmp',
                default       => 'image/png',
            };
            $ctXml .= '<Default Extension="' . $ext . '" ContentType="' . $mime . '"/>';
        }
        $ctXml .= '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
            . '</Types>';

        $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '</Relationships>';

        $docRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        foreach ($this->media as $rId => $m) {
            $docRels .= '<Relationship Id="' . $rId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/' . $m['name'] . '"/>';
        }
        $docRels .= '</Relationships>';

        $tmpFile = tempnam(sys_get_temp_dir(), 'docx_');
        $zip = new ZipArchive();
        $zip->open($tmpFile, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', $ctXml);
        $zip->addFromString('_rels/.rels', $rootRels);
        $zip->addFromString('word/document.xml', $docXml);
        $zip->addFromString('word/_rels/document.xml.rels', $docRels);
        $zip->addFromString('word/styles.xml', $stylesXml);
        foreach ($this->media as $m) {
            $zip->addFromString('word/media/' . $m['name'], $m['data']);
        }
        $zip->close();

        $bytes = file_get_contents($tmpFile);
        unlink($tmpFile);
        return $bytes;
    }
}

/** Resolve a stored ImageLoc/AnswerImageNLoc value to an absolute path.
 *  Question/answer images are uploaded under Admin/ (same convention as
 *  question-bank.php's `<img src="../Admin/<?= ImageLoc ?>">`). */
function ewp_resolve_image(?string $loc): ?string
{
    $loc = trim((string)$loc);
    if ($loc === '') return null;
    return __DIR__ . '/../Admin/' . ltrim($loc, '/');
}

/* ═══════════════════════════════════════════════════════════════════════
   POST — build and stream the .docx
   ═══════════════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::validateCsrf();

    $includeAnswerKey = !empty($_POST['includeAnswerKey']);
    $duration  = trim($_POST['Duration']  ?? '');
    $maxMarks  = trim($_POST['MaxMarks']  ?? '');
    $paperTitle = trim($_POST['PaperTitle'] ?? '') ?: ($exam['ExamName'] ?? 'Question Paper');

    // Prefer the exam's OWN institute (examinfo.ExamInstituteId ->
    // institutes.InstituteName), same as exam/export-pdf.php, so the header
    // reflects who the exam actually belongs to rather than the platform-wide
    // default. Falls back to the cert_institute_name app setting, then
    // APP_NAME, only for exams with no owning institute.
    $instituteName = trim((string)($exam['ExamInstituteName'] ?? ''));
    if ($instituteName === '') {
        $instituteName = AppSettings::get('cert_institute_name', defined('APP_NAME') ? APP_NAME : 'Riyatrix Systems');
    }
    $questions = ewp_load_questions($examId);

    $doc = new SimpleDocx();

    /* ── Paper header ─────────────────────────────────────────────────── */
    $doc->addParagraph($instituteName, ['bold' => true, 'size' => 16, 'align' => 'center', 'after' => 60]);
    $doc->addParagraph($paperTitle,    ['bold' => true, 'size' => 20, 'align' => 'center', 'after' => 40]);

    $metaBits = [];
    if (!empty($exam['SubjectName'])) $metaBits[] = 'Subject: ' . $exam['SubjectName'];
    if (!empty($exam['GradeName']))   $metaBits[] = 'Grade: '   . $exam['GradeName'];
    if ($metaBits) {
        $doc->addParagraph(implode('   |   ', $metaBits), ['align' => 'center', 'size' => 11, 'after' => 120]);
    }

    $infoLine = 'Name: ______________________________        Date: ____________';
    $doc->addParagraph($infoLine, ['size' => 11, 'after' => 60]);
    $bits2 = [];
    if ($duration !== '') $bits2[] = 'Duration: ' . $duration;
    if ($maxMarks !== '') $bits2[] = 'Maximum Marks: ' . $maxMarks;
    $bits2[] = 'Total Questions: ' . count($questions);
    $doc->addParagraph(implode('        ', $bits2), ['size' => 11, 'after' => 120, 'border' => true]);

    $doc->addParagraph(
        'Instructions: Answer all questions. Each question carries equal marks unless stated otherwise.',
        ['italic' => true, 'size' => 10, 'after' => 240]
    );

    /* ── Questions ────────────────────────────────────────────────────── */
    $optionLabels = ['1' => 'A', '2' => 'B', '3' => 'C', '4' => 'D'];
    $answerKeyRows = [];
    $qNum = 0;

    foreach ($questions as $q) {
        $qNum++;
        $qType = $q['QuestionType'] ?? 'MCQ';

        $doc->addParagraph($qNum . '. ' . ($q['QuestionDesc'] ?? ''), ['bold' => true, 'size' => 12, 'after' => 80]);

        if (($q['ImageInd'] ?? 'N') === 'Y') {
            $imgPath = ewp_resolve_image($q['ImageLoc'] ?? null);
            if (!$imgPath || !$doc->addImage($imgPath, 4.5)) {
                $doc->addParagraph('[Diagram could not be embedded — see the original question in the system.]',
                    ['italic' => true, 'size' => 9, 'indent' => 360, 'after' => 80]);
            }
        }

        if ($qType === 'YESNO') {
            $numStatements = max(1, min(4, (int)($q['NumStatements'] ?? 4)));
            for ($s = 1; $s <= $numStatements; $s++) {
                $stmt = $q['YesNo' . $s] ?? '';
                if ($stmt === '' || $stmt === null) continue;
                $doc->addParagraph('(' . $s . ') ' . $stmt . '   —   Yes  /  No', ['indent' => 360, 'size' => 11, 'after' => 60]);
            }
        } elseif ($qType === 'MCQ' || $qType === 'DROPDOWN') {
            foreach ($optionLabels as $optNum => $letter) {
                $optText = $q['Answer' . $optNum] ?? '';
                $optImgLoc = $q['AnswerImage' . $optNum . 'Loc'] ?? null;
                if (($optText === '' || $optText === null) && !$optImgLoc) continue;

                $label = $letter . '.' . ($optText !== '' && $optText !== null ? '  ' . $optText : '');
                $doc->addParagraph($label, ['indent' => 360, 'size' => 11, 'after' => 40]);

                if ($optImgLoc) {
                    $optImgPath = ewp_resolve_image($optImgLoc);
                    if ($optImgPath) $doc->addImage($optImgPath, 2.5);
                }
            }
        }

        $doc->addParagraph('', ['after' => 100]); // breathing room between questions

        if ($includeAnswerKey) {
            $correctRaw = ltrim(str_ireplace('Answer', '', $q['CorrectAnswer'] ?? ''));
            $correctLetter = $optionLabels[$correctRaw] ?? '';
            if ($qType === 'YESNO') {
                $numStatements = max(1, min(4, (int)($q['NumStatements'] ?? 4)));
                $pattern = [];
                for ($s = 1; $s <= $numStatements; $s++) $pattern[] = $q['YesNo' . $s] ?? '?';
                $answerKeyRows[] = [$qNum, implode(' / ', $pattern)];
            } else {
                $answerKeyRows[] = [$qNum, $correctLetter !== '' ? $correctLetter : '—'];
            }
        }
    }

    if (!$questions) {
        $doc->addParagraph('No active questions found for this exam.', ['italic' => true]);
    }

    /* ── Answer key appendix ──────────────────────────────────────────── */
    if ($includeAnswerKey && $answerKeyRows) {
        $doc->addPageBreak();
        $doc->addParagraph('Answer Key', ['bold' => true, 'size' => 16, 'after' => 160]);
        $doc->addTwoColTable('Q#', 'Correct Answer', $answerKeyRows);
    }

    $bytes = $doc->build();

    $safeName = preg_replace('/[^A-Za-z0-9_-]+/', '_', $exam['ExamName'] ?? 'question_paper');
    $filename = $safeName . '_' . ($includeAnswerKey ? 'with_key' : 'paper') . '.docx';

    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: max-age=0');
    echo $bytes;
    exit;
}

/* ═══════════════════════════════════════════════════════════════════════
   GET — options form (Step 1)
   ═══════════════════════════════════════════════════════════════════════ */
/* Reuses ewp_load_questions()'s own fallback cascade rather than a second,
   separately-maintained query — so a pre-migration_v22 database (no
   exam_questions table yet) can't hit an uncaught exception here. */
$questionCount = count(ewp_load_questions($examId));

$pageTitle = 'Export Question Paper — Word';
include __DIR__ . '/../includes/header.php';
?>

<nav style="font-size:.85rem;color:#718096;margin-bottom:14px;">
  <a href="questions.php?examId=<?= (int)$examId ?>" style="color:#3182ce;text-decoration:none;">&#10067; <?= htmlspecialchars($exam['ExamName'] ?? '') ?></a>
  <span style="margin:0 6px;">&rsaquo;</span>
  <span>Export as Word</span>
</nav>

<div class="card" style="max-width:560px;">
  <div class="card-header">&#128196; Export Question Paper (.docx)</div>
  <div class="card-body">
    <p style="font-size:.88rem;color:#4a5568;margin-top:0;">
      Generates a fully editable Word document from
      <strong><?= htmlspecialchars($exam['ExamName'] ?? '') ?></strong>
      (<?= $questionCount ?> active question<?= $questionCount === 1 ? '' : 's' ?>) —
      ready to print or edit further.
    </p>

    <?php if ($questionCount === 0): ?>
      <div class="alert alert-warning">This exam has no active questions yet.</div>
    <?php else: ?>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken()) ?>">
      <input type="hidden" name="examId" value="<?= (int)$examId ?>">

      <div class="form-group">
        <label class="form-label">Paper Title <small style="font-weight:400;color:#718096;">(optional — defaults to the exam name)</small></label>
        <input type="text" class="form-control" name="PaperTitle" maxlength="200" value="<?= htmlspecialchars($exam['ExamName'] ?? '') ?>">
      </div>
      <div class="form-row cols-2">
        <div class="form-group">
          <label class="form-label">Duration <small style="font-weight:400;color:#718096;">(optional)</small></label>
          <input type="text" class="form-control" name="Duration" maxlength="50" placeholder="e.g. 90 minutes">
        </div>
        <div class="form-group">
          <label class="form-label">Maximum Marks <small style="font-weight:400;color:#718096;">(optional)</small></label>
          <input type="text" class="form-control" name="MaxMarks" maxlength="20" placeholder="e.g. 100">
        </div>
      </div>
      <div class="form-group" style="margin-top:6px;">
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:.9rem;">
          <input type="checkbox" name="includeAnswerKey" value="1" style="transform:scale(1.2);">
          Include an Answer Key appendix (correct options revealed on a separate page)
        </label>
      </div>

      <button type="submit" class="btn btn-success" style="font-weight:700;margin-top:10px;">
        &#128190; Generate Word Document
      </button>
    </form>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
