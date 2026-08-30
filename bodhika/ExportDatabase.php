<?php
/**
 * Admin/ExportDatabase.php — One-click full-database export.
 *
 * Lists every base table in the current database (with row counts) and
 * lets an admin download:
 *   - a real .xlsx workbook, one tab per table   (?export=xlsx) — best for
 *     review: every table is its own sheet, named after the table
 *   - every table stacked into ONE CSV           (?export=all)  — a single
 *     flat file, one table's data after another, if you just want to grep/
 *     diff it or open it somewhere that doesn't do multi-sheet files
 *   - a single table as CSV                       (?export=csv&table=xxx)
 *   - every table as a ZIP of separate CSVs        (?export=zip) — for when
 *     you want to re-import individual tables elsewhere
 *
 * This project has no PhpSpreadsheet (or similar) library installed, so the
 * .xlsx export below is a minimal hand-written OOXML workbook: it builds the
 * handful of required XML parts (workbook.xml, one worksheet XML per table,
 * the content-types/relationship manifests) and zips them up with
 * ZipArchive, which is already used elsewhere on this page. All cell values
 * are written as inline strings (t="inlineStr") rather than typed
 * numbers/dates — simplest and safest for a raw data dump (no risk of e.g.
 * a zero-padded ID losing its leading zero), at the cost of numbers not
 * being right-aligned/sortable-as-numbers out of the box.
 *
 * This is a data export, not a schema backup: it does not include
 * CREATE TABLE statements, indexes, or constraints. For a restorable
 * backup, use mysqldump via phpMyAdmin or the command line.
 *
 * Admin-only. Exports are plain SELECTs with no side effects, so (like
 * exam/export-excel.php) the download links are simple GETs — no CSRF
 * token needed.
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');
if (!Auth::isAdmin()) { header('Location: ../auth/login.php'); exit; }

const EXPDB_MAX_ROWS = 200000; // per-table safety cap

/* ── CSV helpers (mirrors Admin/DebugQuery.php's convention) ─────────────── */
function expdbCsvRow(array $fields): string
{
    return implode(',', array_map(function ($v) {
        $v = str_replace('"', '""', (string)$v);
        return '"' . $v . '"';
    }, $fields)) . "\r\n";
}

function expdbSendCsvHeaders(string $filename): void
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM so Excel auto-detects encoding
}

/** All base tables in the current database, sorted by name. */
function expdbListTables(): array
{
    return array_column(
        Database::fetchAll(
            "SELECT TABLE_NAME AS t FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'
              ORDER BY TABLE_NAME"
        ),
        't'
    );
}

/**
 * Write one table's full contents as CSV to $out (defaults to the response
 * body). Streams row-by-row rather than building the whole CSV in memory.
 * Returns the number of data rows written.
 */
function expdbWriteTableCsv(string $table, $out): int
{
    $pdo  = Database::getInstance()->getConnection();
    $stmt = $pdo->query('SELECT * FROM `' . $table . '` LIMIT ' . EXPDB_MAX_ROWS);

    $rowCount = 0;
    $header   = false;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!$header) {
            fwrite($out, expdbCsvRow(array_keys($row)));
            $header = true;
        }
        fwrite($out, expdbCsvRow(array_map(fn($v) => $v === null ? '' : $v, $row)));
        $rowCount++;
    }
    if (!$header) {
        // Zero rows — still emit a header line from the column list.
        $cols = array_column(
            Database::fetchAll(
                "SELECT COLUMN_NAME AS c FROM information_schema.columns
                  WHERE table_schema = DATABASE() AND table_name = ?
                  ORDER BY ORDINAL_POSITION",
                [$table]
            ),
            'c'
        );
        fwrite($out, expdbCsvRow($cols));
    }
    return $rowCount;
}

/* ── Minimal raw-OOXML .xlsx writer (no external library) ────────────────
   Just enough of the spreadsheetml format for a multi-sheet data dump:
   [Content_Types].xml, _rels/.rels, xl/workbook.xml, xl/_rels/workbook.xml.rels,
   and one xl/worksheets/sheetN.xml per table. ───────────────────────────── */

/** Convert a 1-based column number to its spreadsheet letter (1->A, 27->AA). */
function expdbColLetter(int $n): string
{
    $letter = '';
    while ($n > 0) {
        $n--;
        $letter = chr(65 + ($n % 26)) . $letter;
        $n = intdiv($n, 26);
    }
    return $letter;
}

/** Escape a value for use as XML text content, stripping bytes XML 1.0 disallows. */
function expdbXmlEscape(string $s): string
{
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);
    return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/**
 * Sanitize a table name into a legal, unique Excel sheet name: <=31 chars,
 * none of \ / ? * [ ] :, not blank, no leading/trailing apostrophe.
 * $used is keyed by lowercase name and mutated in place to track collisions.
 */
function expdbSanitizeSheetName(string $name, array &$used): string
{
    $clean = preg_replace('/[\\\\\/\?\*\[\]:]/', '_', $name);
    $clean = trim($clean, "'");
    if ($clean === '') $clean = 'Sheet';
    $clean = substr($clean, 0, 31);

    $base = $clean;
    $n = 2;
    while (isset($used[strtolower($clean)])) {
        $suffix = '_' . $n;
        $clean  = substr($base, 0, 31 - strlen($suffix)) . $suffix;
        $n++;
    }
    $used[strtolower($clean)] = true;
    return $clean;
}

/** Write one <row> element (1-based $rowNum) with inline-string cells to $fh. */
function expdbWriteXlsxRow($fh, int $rowNum, array $values): void
{
    $xml = '<row r="' . $rowNum . '">';
    $col = 1;
    foreach ($values as $v) {
        $ref  = expdbColLetter($col) . $rowNum;
        $text = expdbXmlEscape((string)$v);
        $xml .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . $text . '</t></is></c>';
        $col++;
    }
    $xml .= '</row>';
    fwrite($fh, $xml);
}

/** Stream one table's full contents (header + data rows) as sheet <row> XML to $fh. */
function expdbWriteTableSheetRows(string $table, $fh): void
{
    $pdo  = Database::getInstance()->getConnection();
    $stmt = $pdo->query('SELECT * FROM `' . $table . '` LIMIT ' . EXPDB_MAX_ROWS);

    $rowNum = 1;
    $header = false;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!$header) {
            expdbWriteXlsxRow($fh, $rowNum++, array_keys($row));
            $header = true;
        }
        expdbWriteXlsxRow($fh, $rowNum++, array_map(fn($v) => $v === null ? '' : $v, $row));
    }
    if (!$header) {
        $cols = array_column(
            Database::fetchAll(
                "SELECT COLUMN_NAME AS c FROM information_schema.columns
                  WHERE table_schema = DATABASE() AND table_name = ?
                  ORDER BY ORDINAL_POSITION",
                [$table]
            ),
            'c'
        );
        expdbWriteXlsxRow($fh, $rowNum++, $cols);
    }
}

$export = $_GET['export'] ?? '';

/* ══════════════════════════════════════════════════════════════════════
   Export: single table → CSV
   ══════════════════════════════════════════════════════════════════════ */
if ($export === 'csv') {
    $table = trim($_GET['table'] ?? '');
    // Validate against the real table list (also doubles as SQL-injection
    // protection for the identifier, since it's interpolated below).
    if ($table === '' || !in_array($table, expdbListTables(), true)) {
        http_response_code(400);
        exit('Unknown table.');
    }
    expdbSendCsvHeaders($table . '_' . date('Y-m-d') . '.csv');
    expdbWriteTableCsv($table, fopen('php://output', 'w'));
    exit;
}

/* ══════════════════════════════════════════════════════════════════════
   Export: every table → one .xlsx workbook, one tab per table
   ══════════════════════════════════════════════════════════════════════ */
if ($export === 'xlsx') {
    if (!class_exists('ZipArchive')) {
        http_response_code(500);
        exit('The PHP zip extension is not enabled on this server, so an Excel export isn\'t available. Use the CSV options instead.');
    }

    $tables  = expdbListTables();
    $tmpXlsx = tempnam(sys_get_temp_dir(), 'dbexport_');
    $zip     = new ZipArchive();
    $zip->open($tmpXlsx, ZipArchive::OVERWRITE);

    $usedNames    = [];
    $sheets       = []; // ['idx' => n, 'name' => sanitized sheet name]
    $sheetTmpFiles = [];

    $idx = 0;
    foreach ($tables as $table) {
        $idx++;
        $sheetFile = tempnam(sys_get_temp_dir(), 'sheet_');
        $sheetTmpFiles[] = $sheetFile;

        $fh = fopen($sheetFile, 'w');
        fwrite($fh, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>');
        expdbWriteTableSheetRows($table, $fh);
        fwrite($fh, '</sheetData></worksheet>');
        fclose($fh);

        $zip->addFile($sheetFile, "xl/worksheets/sheet{$idx}.xml");
        $sheets[] = ['idx' => $idx, 'name' => expdbSanitizeSheetName($table, $usedNames)];
    }

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
    foreach ($sheets as $s) {
        $contentTypes .= '<Override PartName="/xl/worksheets/sheet' . $s['idx'] . '.xml"'
            . ' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    }
    $contentTypes .= '</Types>';
    $zip->addFromString('[Content_Types].xml', $contentTypes);

    $zip->addFromString('_rels/.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>');

    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
    foreach ($sheets as $s) {
        $workbook .= '<sheet name="' . expdbXmlEscape($s['name']) . '" sheetId="' . $s['idx'] . '" r:id="rId' . $s['idx'] . '"/>';
    }
    $workbook .= '</sheets></workbook>';
    $zip->addFromString('xl/workbook.xml', $workbook);

    $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    foreach ($sheets as $s) {
        $workbookRels .= '<Relationship Id="rId' . $s['idx'] . '"'
            . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"'
            . ' Target="worksheets/sheet' . $s['idx'] . '.xml"/>';
    }
    $workbookRels .= '</Relationships>';
    $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);

    $zip->close();
    foreach ($sheetTmpFiles as $f) { @unlink($f); }

    $xlsxName = 'database_export_' . date('Y-m-d_His') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $xlsxName . '"');
    header('Content-Length: ' . filesize($tmpXlsx));
    readfile($tmpXlsx);
    unlink($tmpXlsx);
    exit;
}

/* ══════════════════════════════════════════════════════════════════════
   Export: every table stacked into ONE CSV (single flat file)
   ══════════════════════════════════════════════════════════════════════ */
if ($export === 'all') {
    $tables = expdbListTables();
    expdbSendCsvHeaders('database_export_' . date('Y-m-d_His') . '.csv');
    $out = fopen('php://output', 'w');
    foreach ($tables as $table) {
        // Section marker row so each table's block is easy to spot while
        // scrolling — stands out because it's a single non-quoted-looking cell.
        fwrite($out, expdbCsvRow(["===== TABLE: {$table} ====="]));
        expdbWriteTableCsv($table, $out);
        fwrite($out, "\r\n"); // blank row between tables
    }
    exit;
}

/* ══════════════════════════════════════════════════════════════════════
   Export: every table → one ZIP of CSVs
   ══════════════════════════════════════════════════════════════════════ */
if ($export === 'zip') {
    if (!class_exists('ZipArchive')) {
        http_response_code(500);
        exit('The PHP zip extension is not enabled on this server, so a combined ZIP export isn\'t available. Use the per-table CSV links instead.');
    }

    $tables  = expdbListTables();
    $tmpFile = tempnam(sys_get_temp_dir(), 'dbexport_');
    $zip     = new ZipArchive();
    $zip->open($tmpFile, ZipArchive::OVERWRITE);

    foreach ($tables as $table) {
        $fh = fopen('php://temp/maxmemory:5242880', 'r+'); // spill to disk past 5MB
        fwrite($fh, "\xEF\xBB\xBF");
        expdbWriteTableCsv($table, $fh);
        rewind($fh);
        $zip->addFromString($table . '.csv', stream_get_contents($fh));
        fclose($fh);
    }
    $zip->close();

    $zipName = 'database_export_' . date('Y-m-d_His') . '.zip';
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zipName . '"');
    header('Content-Length: ' . filesize($tmpFile));
    readfile($tmpFile);
    unlink($tmpFile);
    exit;
}

/* ══════════════════════════════════════════════════════════════════════
   Page: table listing
   ══════════════════════════════════════════════════════════════════════ */
$tables = [];
foreach (expdbListTables() as $t) {
    try {
        $cnt = (int)(Database::fetchOne('SELECT COUNT(*) AS c FROM `' . $t . '`')['c'] ?? 0);
    } catch (Throwable $e) {
        $cnt = -1; // couldn't count (permissions, view, etc.)
    }
    $tables[] = ['name' => $t, 'count' => $cnt];
}
$totalRows = array_sum(array_map(fn($t) => max($t['count'], 0), $tables));

$pageTitle = 'Export Database';
include __DIR__ . '/../includes/header.php';
?>
<style>
  .expdb-tbl-wrap { overflow:auto; max-height:65vh; border:1px solid var(--clr-border); border-radius:8px; }
  .expdb-tbl { border-collapse:collapse; width:100%; font-size:.85rem; }
  .expdb-tbl th, .expdb-tbl td { padding:8px 12px; border-bottom:1px solid var(--clr-border); text-align:left; }
  .expdb-tbl th { position:sticky; top:0; background:var(--clr-surface); z-index:1; }
  .expdb-tbl tr:nth-child(even) { background:rgba(0,0,0,.02); }
  .expdb-count { font-variant-numeric: tabular-nums; text-align:right; }
  .expdb-meta { font-size:.85rem; color:#6b7280; margin:10px 0 16px; }
</style>

<div class="card">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
    <span>&#128190; Export Database</span>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <a href="ExportDatabase.php?export=xlsx" class="btn btn-primary">&#11015; Download All Tables (Excel, tab per table)</a>
      <a href="ExportDatabase.php?export=all" class="btn btn-secondary">&#11015; Single CSV (all stacked)</a>
      <a href="ExportDatabase.php?export=zip" class="btn btn-secondary">&#11015; ZIP (one file per table)</a>
    </div>
  </div>
  <div class="card-body">

    <div class="alert alert-warning" style="font-size:.85rem;">
      Exports are read-only data snapshots. This is data only, not a schema backup (no <code>CREATE TABLE</code>,
      indexes, or constraints). For a fully restorable backup, use <code>mysqldump</code> via phpMyAdmin or the
      command line. Each table is capped at <?php echo number_format(EXPDB_MAX_ROWS); ?> rows.
      <br><br>
      <strong>Excel</strong> gives you one real <code>.xlsx</code> workbook with a separate tab named after each
      table — best for browsing. <strong>Single CSV</strong> stacks every table into one flat file (with a
      <code>===== TABLE: name =====</code> marker row between them). <strong>ZIP</strong> gives one clean CSV
      file per table, useful if you want to re-import a table elsewhere. CSV files use a UTF-8 BOM so they
      open with correct encoding.
    </div>

    <div class="expdb-meta">
      <?php echo count($tables); ?> tables, <?php echo number_format($totalRows); ?> rows total.
    </div>

    <div class="expdb-tbl-wrap">
      <table class="expdb-tbl">
        <thead>
          <tr><th>Table</th><th style="text-align:right;">Rows</th><th style="width:140px;">Export</th></tr>
        </thead>
        <tbody>
          <?php foreach ($tables as $t): ?>
          <tr>
            <td><code><?php echo htmlspecialchars($t['name']); ?></code></td>
            <td class="expdb-count"><?php echo $t['count'] >= 0 ? number_format($t['count']) : '—'; ?></td>
            <td>
              <a href="ExportDatabase.php?export=csv&amp;table=<?php echo urlencode($t['name']); ?>"
                 class="btn btn-secondary" style="font-size:.8rem;padding:3px 10px;">&#11015; CSV</a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
