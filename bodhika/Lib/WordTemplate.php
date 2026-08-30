<?php
/**
 * Lib/WordTemplate.php — read/fill {{TOKEN}} placeholders inside a .docx.
 *
 * Used by the TemplateType='word' certificate flow: an admin uploads a .docx
 * authored in Word (or generated some other way, e.g. the docx skill) with
 * literal `{{TOKEN}}` placeholders in the running text — the same convention
 * used by the certificate template built for this app (RECIPIENT_NAME,
 * PROGRAM_NAME, START_DATE, END_DATE, ...). extractPlaceholders() finds every
 * token so the admin can map it to a system field (Admin/CertificateTemplateWordMap.php);
 * fillTemplate() substitutes real values back in at issue time
 * (Admin/GenerateCertificates.php), producing one filled .docx per student.
 *
 * A .docx is a zip archive; the visible text lives in word/document.xml as a
 * sequence of <w:t>...</w:t> runs. Word frequently splits what looks like one
 * word into several runs (spell-check/grammar/revision boundaries), so a
 * `{{TOKEN}}` typed by hand isn't guaranteed to sit inside a single <w:t>
 * node. Both methods below work over the *concatenated* plain text of every
 * <w:t> node (so a split token is still found/replaced correctly) while
 * still surgically editing only the affected node(s) in the original XML —
 * no full XML parse/re-serialize, so unrelated formatting is left untouched.
 */
class WordTemplate
{
    private const TOKEN_PATTERN = '/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/';

    /**
     * Every {{TOKEN}} name found in $absDocxPath's document.xml, in first-seen
     * order, de-duplicated. Empty array if the file can't be read/opened —
     * callers treat that as "no placeholders detected" rather than a hard error,
     * since an admin should still be able to attach the file and fix it later.
     */
    public static function extractPlaceholders(string $absDocxPath): array
    {
        $xml = self::readDocumentXml($absDocxPath);
        if ($xml === null) return [];

        [$plainTexts, ] = self::parseTextNodes($xml);
        $concat = implode('', $plainTexts);

        preg_match_all(self::TOKEN_PATTERN, $concat, $m);
        $tokens = [];
        foreach ($m[1] as $name) {
            if (!in_array($name, $tokens, true)) $tokens[] = $name;
        }
        return $tokens;
    }

    /**
     * True if PHP's zip extension (ext-zip / the ZipArchive class) is loaded.
     * A .docx IS a zip archive, so every method below needs it — on some
     * shared-hosting PHP builds ext-zip isn't compiled in, and instantiating
     * ZipArchive without this guard throws an uncatchable-by-Exception
     * PHP Error that kills the whole request (e.g. mid-batch in
     * Admin/GenerateCertificates.php, after certificates were already
     * inserted but before the redirect that shows them — which looks to an
     * admin like "nothing happened, no download option" with no error on
     * screen). Callers check this up front instead and fail softly.
     */
    public static function zipSupported(): bool
    {
        return class_exists('ZipArchive');
    }

    /**
     * Fill $srcAbsPath's {{TOKEN}} placeholders with $tokenValues (token name
     * => replacement string, values are inserted as plain text — no HTML/rich
     * formatting) and write the result to $destAbsPath. Returns true on
     * success. A token with no entry in $tokenValues is left as-is (so a
     * partially-configured template fails visibly instead of silently
     * dropping text).
     *
     * $error, if given, is set to a short human-readable reason on failure —
     * surfaced to the admin (Admin/GenerateCertificates.php) instead of a
     * silent/blank failure, since a filled-.docx failure has no other visible
     * symptom besides a missing download button.
     */
    public static function fillTemplate(string $srcAbsPath, string $destAbsPath, array $tokenValues, ?string &$error = null): bool
    {
        $error = null;

        if (!self::zipSupported()) {
            $error = "PHP's zip extension (ext-zip) is not available on this server — Word-template certificates can't be generated. Ask your host to enable ext-zip, or use an Image/Coded template instead.";
            return false;
        }
        if (!is_file($srcAbsPath)) { $error = 'The uploaded .docx template file is missing on the server — re-upload it.'; return false; }

        $destDir = dirname($destAbsPath);
        if (!is_dir($destDir) && !@mkdir($destDir, 0755, true) && !is_dir($destDir)) {
            $error = "Could not create the output folder ($destDir) — check filesystem permissions.";
            return false;
        }
        if (!is_writable($destDir)) {
            $error = "The output folder ($destDir) is not writable — check filesystem permissions.";
            return false;
        }
        if (!@copy($srcAbsPath, $destAbsPath)) { $error = 'Could not copy the template file — check filesystem permissions.'; return false; }

        $zip = new ZipArchive();
        $openResult = $zip->open($destAbsPath);
        if ($openResult !== true) {
            $error = 'Could not open the generated file as a .docx (zip error code ' . $openResult . ').';
            @unlink($destAbsPath);
            return false;
        }

        $xml = $zip->getFromName('word/document.xml');
        if ($xml === false) {
            $error = 'The template .docx has no word/document.xml — it may be corrupted or not a real Word document.';
            $zip->close();
            @unlink($destAbsPath);
            return false;
        }

        [$plainTexts, $rawNodes] = self::parseTextNodes($xml);
        $concat = implode('', $plainTexts);

        // Byte-length of each node's plain (decoded) text, and each node's
        // starting offset within the concatenated string — needed to map a
        // token match's [start,end) in $concat back to the node(s) it spans.
        $nodeLens = array_map('strlen', $plainTexts);
        $cumStart = [];
        $running = 0;
        foreach ($nodeLens as $i => $len) { $cumStart[$i] = $running; $running += $len; }

        preg_match_all(self::TOKEN_PATTERN, $concat, $m, PREG_OFFSET_CAPTURE);
        $matches = $m[0]; // [ [fullMatchText, byteOffsetInConcat], ... ]
        $names   = $m[1];

        // Process matches from the END backwards so mutating earlier text
        // never invalidates an offset we haven't handled yet.
        for ($i = count($matches) - 1; $i >= 0; $i--) {
            $tokenName = $names[$i][0];
            if (!array_key_exists($tokenName, $tokenValues)) continue; // unmapped -> leave literal {{TOKEN}} in place

            $start = $matches[$i][1];
            $len   = strlen($matches[$i][0]);
            $end   = $start + $len;
            $value = (string)$tokenValues[$tokenName];

            $startNode = self::nodeIndexForOffset($cumStart, $nodeLens, $start);
            $endNode   = self::nodeIndexForOffset($cumStart, $nodeLens, $end - 1);
            if ($startNode === null || $endNode === null) continue; // shouldn't happen; be defensive

            $localStart = $start - $cumStart[$startNode];

            if ($startNode === $endNode) {
                $localEnd = $end - $cumStart[$startNode];
                $plainTexts[$startNode] = substr($plainTexts[$startNode], 0, $localStart)
                                        . $value
                                        . substr($plainTexts[$startNode], $localEnd);
            } else {
                // Token fragmented across runs: the tail of the first node and
                // the head of the last node belong to the token; anything in
                // between is entirely consumed by it.
                $localEnd = $end - $cumStart[$endNode];
                $plainTexts[$startNode] = substr($plainTexts[$startNode], 0, $localStart) . $value;
                for ($k = $startNode + 1; $k < $endNode; $k++) $plainTexts[$k] = '';
                $plainTexts[$endNode] = substr($plainTexts[$endNode], $localEnd);
            }
        }

        // Splice the (re-encoded) new text of every changed node back into
        // the original XML, using the RAW byte offsets captured up front —
        // again processing from the end backwards to keep earlier offsets valid.
        for ($i = count($rawNodes) - 1; $i >= 0; $i--) {
            $encoded = self::xmlEncode($plainTexts[$i]);
            if ($encoded === $rawNodes[$i]['raw']) continue; // unchanged, skip the splice
            $xml = substr_replace($xml, $encoded, $rawNodes[$i]['start'], $rawNodes[$i]['len']);
        }

        $zip->addFromString('word/document.xml', $xml);
        $zip->close();
        return true;
    }

    /**
     * Concatenate several filled certificate .docx files (as produced by
     * fillTemplate()) into a single .docx, one page per source document —
     * used by exam/certificate-download-batch.php so an admin issuing
     * certificates to a class doesn't have to download N separate files.
     *
     * Every source here is a byte-for-byte copy of the SAME template (each
     * student's file is `copy()`-then-fill()'d from one WordFile in
     * Admin/GenerateCertificates.php), so every copy has identical
     * relationship IDs, styles and media — that's what makes a plain text-
     * level splice safe here without touching word/_rels or media/*: we
     * only ever borrow body content that references relationships already
     * present (identically) in the base document.
     *
     * $srcAbsPaths[0] becomes the base document (its media/styles/rels are
     * kept as-is); each subsequent document's <w:body> content — minus its
     * own trailing <w:sectPr> (that's per-document page/margin/section
     * settings, not real content) — is inserted before the base document's
     * own trailing <w:sectPr>, preceded by an explicit page break so each
     * student's certificate still starts on its own page.
     */
    public static function mergeDocuments(array $srcAbsPaths, string $destAbsPath, ?string &$error = null): bool
    {
        $error = null;
        $srcAbsPaths = array_values(array_filter($srcAbsPaths, 'is_file'));
        if (!$srcAbsPaths) { $error = 'No certificate documents to merge.'; return false; }

        if (!self::zipSupported()) {
            $error = "PHP's zip extension (ext-zip) is not available on this server.";
            return false;
        }

        $destDir = dirname($destAbsPath);
        if (!is_dir($destDir) && !@mkdir($destDir, 0755, true) && !is_dir($destDir)) {
            $error = "Could not create the output folder ($destDir) — check filesystem permissions.";
            return false;
        }
        if (!@copy($srcAbsPaths[0], $destAbsPath)) { $error = 'Could not copy the first certificate file.'; return false; }
        if (count($srcAbsPaths) === 1) return true; // nothing to merge — single file is already the result

        $zip = new ZipArchive();
        if ($zip->open($destAbsPath) !== true) { $error = 'Could not open the merged file.'; return false; }
        $baseXml = $zip->getFromName('word/document.xml');
        if ($baseXml === false) { $zip->close(); $error = 'The first certificate file has no word/document.xml.'; return false; }

        $bodyOpen = strpos($baseXml, '<w:body>');
        $bodyClose = strrpos($baseXml, '</w:body>');
        if ($bodyOpen === false || $bodyClose === false) {
            $zip->close();
            $error = 'The first certificate file has no <w:body>.';
            return false;
        }
        // Everything from the LAST <w:sectPr before </w:body> onward is the
        // base document's own section/page settings — new pages are spliced
        // in just before it so those settings stay governing the whole file.
        $lastSectPr = strrpos(substr($baseXml, 0, $bodyClose), '<w:sectPr');
        $insertPos  = $lastSectPr !== false ? $lastSectPr : $bodyClose;

        $appended = '';
        for ($i = 1; $i < count($srcAbsPaths); $i++) {
            $otherZip = new ZipArchive();
            if ($otherZip->open($srcAbsPaths[$i]) !== true) continue; // skip an unreadable file rather than fail the whole batch
            $otherXml = $otherZip->getFromName('word/document.xml');
            $otherZip->close();
            if ($otherXml === false) continue;

            $oBodyOpen  = strpos($otherXml, '<w:body>');
            $oBodyClose = strrpos($otherXml, '</w:body>');
            if ($oBodyOpen === false || $oBodyClose === false) continue;
            $oBodyOpen += strlen('<w:body>');
            $oContent = substr($otherXml, $oBodyOpen, $oBodyClose - $oBodyOpen);

            $oLastSectPr = strrpos($oContent, '<w:sectPr');
            if ($oLastSectPr !== false) $oContent = substr($oContent, 0, $oLastSectPr);

            $appended .= '<w:p><w:r><w:br w:type="page"/></w:r></w:p>' . $oContent;
        }

        if ($appended !== '') {
            $baseXml = substr_replace($baseXml, $appended, $insertPos, 0);
            $zip->addFromString('word/document.xml', $baseXml);
        }
        $zip->close();
        return true;
    }

    /**
     * Best-effort .docx -> .pdf conversion by shelling out to a LibreOffice/
     * OpenOffice binary, if one is installed and shell_exec isn't disabled.
     * Returns the produced PDF's absolute path, or null if conversion isn't
     * possible on this server — callers should offer the .docx as a fallback
     * rather than failing outright, since most shared hosting has neither
     * LibreOffice nor shell_exec available.
     */
    public static function tryConvertToPdf(string $docxAbsPath, string $outDir): ?string
    {
        if (!is_file($docxAbsPath)) return null;
        if (!function_exists('shell_exec')) return null;

        $binary = trim((string)@shell_exec('command -v soffice 2>/dev/null || command -v libreoffice 2>/dev/null'));
        if ($binary === '') return null;

        if (!is_dir($outDir)) @mkdir($outDir, 0755, true);
        if (!is_dir($outDir) || !is_writable($outDir)) return null;

        $cmd = escapeshellcmd($binary)
             . ' --headless --norestore --convert-to pdf --outdir ' . escapeshellarg($outDir)
             . ' ' . escapeshellarg($docxAbsPath) . ' 2>&1';
        @shell_exec($cmd);

        $expected = rtrim($outDir, '/') . '/' . pathinfo($docxAbsPath, PATHINFO_FILENAME) . '.pdf';
        return is_file($expected) ? $expected : null;
    }

    /**
     * Parse every <w:t>...</w:t> node in $xml. Returns [$plainTexts, $rawNodes]:
     *   $plainTexts[i] = XML-entity-decoded text of node i (what the algorithm
     *                    above searches/edits)
     *   $rawNodes[i]   = ['raw' => original encoded text, 'start' => byte
     *                    offset, 'len' => byte length] of that node's inner
     *                    text within $xml, for splicing edits back in.
     * Both arrays are in document order and index-aligned with each other.
     */
    private static function parseTextNodes(string $xml): array
    {
        preg_match_all('/<w:t(?:\s[^>]*)?>(.*?)<\/w:t>/s', $xml, $m, PREG_OFFSET_CAPTURE);
        $plainTexts = [];
        $rawNodes   = [];
        foreach ($m[1] as $match) {
            [$raw, $offset] = $match;
            $plainTexts[] = self::xmlDecode($raw);
            $rawNodes[]   = ['raw' => $raw, 'start' => $offset, 'len' => strlen($raw)];
        }
        return [$plainTexts, $rawNodes];
    }

    /** Which node index does byte offset $pos (into the concatenated plain text) fall inside? */
    private static function nodeIndexForOffset(array $cumStart, array $nodeLens, int $pos): ?int
    {
        foreach ($cumStart as $i => $start) {
            if ($pos >= $start && $pos < $start + $nodeLens[$i]) return $i;
        }
        // Position exactly at end-of-text (possible for a token ending at the
        // very last byte of the last node) — fall back to the last non-empty node.
        for ($i = count($nodeLens) - 1; $i >= 0; $i--) {
            if ($nodeLens[$i] > 0) return $i;
        }
        return null;
    }

    /** Only the 5 predefined XML entities are ever used inside <w:t> text — no numeric-entity handling needed. */
    private static function xmlDecode(string $s): string
    {
        return str_replace(['&lt;', '&gt;', '&quot;', '&apos;', '&amp;'], ['<', '>', '"', "'", '&'], $s);
    }

    private static function xmlEncode(string $s): string
    {
        return str_replace(['&', '<', '>', '"', "'"], ['&amp;', '&lt;', '&gt;', '&quot;', '&apos;'], $s);
    }

    /** word/document.xml's contents, or null if the file isn't a readable .docx/zip. */
    private static function readDocumentXml(string $absPath): ?string
    {
        if (!self::zipSupported()) return null;
        if (!is_file($absPath)) return null;
        $zip = new ZipArchive();
        if ($zip->open($absPath) !== true) return null;
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        return $xml !== false ? $xml : null;
    }
}
