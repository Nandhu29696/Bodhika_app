<?php
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
require_once __DIR__ . '/../Lib/Database.php';

Auth::requireLogin('../index.php');
if (!Auth::isAdmin()) {
    http_response_code(403);
    exit('Access denied');
}

// ── XLSX export helper (pure PHP, no composer needed) ─────────────────────────
function buildXlsx(array $sheets): string
{
    // $sheets = [ 'SheetName' => [ ['col1','col2',...], [...], ... ], ... ]

    $sheetXmls   = [];
    $sheetRels   = [];
    $sharedStrings = [];
    $ssIndex     = [];   // value => index in shared-string table

    $getSS = function(string $v) use (&$sharedStrings, &$ssIndex): int {
        if (!isset($ssIndex[$v])) {
            $ssIndex[$v] = count($sharedStrings);
            $sharedStrings[] = $v;
        }
        return $ssIndex[$v];
    };

    $sheetId = 1;
    foreach ($sheets as $name => $rows) {
        $xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        $xml .= '<sheetData>';

        $rowNum = 1;
        foreach ($rows as $row) {
            $xml .= '<row r="' . $rowNum . '">';
            $colNum = 0;
            foreach ($row as $cell) {
                $colLetter = colLetter($colNum);
                $cellRef   = $colLetter . $rowNum;
                $val       = (string)($cell ?? '');

                if (is_numeric($cell) && $cell !== '') {
                    $xml .= '<c r="' . $cellRef . '" t="n"><v>' . htmlspecialchars($val, ENT_XML1) . '</v></c>';
                } else {
                    $si  = $getSS($val);
                    $xml .= '<c r="' . $cellRef . '" t="s"><v>' . $si . '</v></c>';
                }
                $colNum++;
            }
            $xml .= '</row>';
            $rowNum++;
        }

        $xml .= '</sheetData></worksheet>';
        $sheetXmls['xl/worksheets/sheet' . $sheetId . '.xml'] = $xml;
        $sheetRels[] = [
            'id'   => 'rId' . $sheetId,
            'name' => htmlspecialchars($name, ENT_XML1),
            'idx'  => $sheetId,
        ];
        $sheetId++;
    }

    // Shared strings XML
    $ssCount  = count($sharedStrings);
    $ssXml    = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $ssXml   .= '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"';
    $ssXml   .= ' count="' . $ssCount . '" uniqueCount="' . $ssCount . '">';
    foreach ($sharedStrings as $s) {
        $ssXml .= '<si><t xml:space="preserve">' . htmlspecialchars($s, ENT_XML1) . '</t></si>';
    }
    $ssXml .= '</sst>';

    // workbook.xml
    $wbXml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $wbXml .= '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"';
    $wbXml .= ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
    $wbXml .= '<sheets>';
    foreach ($sheetRels as $sr) {
        $wbXml .= '<sheet name="' . $sr['name'] . '" sheetId="' . $sr['idx'] . '" r:id="' . $sr['id'] . '"/>';
    }
    $wbXml .= '</sheets></workbook>';

    // workbook.xml.rels
    $wbRel  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $wbRel .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    foreach ($sheetRels as $sr) {
        $wbRel .= '<Relationship Id="' . $sr['id'] . '"';
        $wbRel .= ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"';
        $wbRel .= ' Target="worksheets/sheet' . $sr['idx'] . '.xml"/>';
    }
    $wbRel .= '<Relationship Id="rIdSS"';
    $wbRel .= ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings"';
    $wbRel .= ' Target="sharedStrings.xml"/>';
    $wbRel .= '<Relationship Id="rIdSt"';
    $wbRel .= ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles"';
    $wbRel .= ' Target="styles.xml"/>';
    $wbRel .= '</Relationships>';

    // [Content_Types].xml
    $ctXml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $ctXml .= '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">';
    $ctXml .= '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>';
    $ctXml .= '<Default Extension="xml"  ContentType="application/xml"/>';
    $ctXml .= '<Override PartName="/xl/workbook.xml"';
    $ctXml .= ' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
    foreach ($sheetRels as $sr) {
        $ctXml .= '<Override PartName="/xl/worksheets/sheet' . $sr['idx'] . '.xml"';
        $ctXml .= ' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    }
    $ctXml .= '<Override PartName="/xl/sharedStrings.xml"';
    $ctXml .= ' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>';
    $ctXml .= '<Override PartName="/xl/styles.xml"';
    $ctXml .= ' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
    $ctXml .= '</Types>';

    // Minimal styles.xml (required by Excel)
    $stylesXml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $stylesXml .= '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
    $stylesXml .= '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>';
    $stylesXml .= '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>';
    $stylesXml .= '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>';
    $stylesXml .= '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>';
    $stylesXml .= '<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs>';
    $stylesXml .= '</styleSheet>';

    // Package into ZIP (in memory)
    $tmpFile = tempnam(sys_get_temp_dir(), 'xlsx_');
    $zip     = new ZipArchive();
    $zip->open($tmpFile, ZipArchive::OVERWRITE);

    $zip->addFromString('[Content_Types].xml',       $ctXml);
    $zip->addFromString('_rels/.rels',               rootRels());
    $zip->addFromString('xl/workbook.xml',           $wbXml);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRel);
    $zip->addFromString('xl/sharedStrings.xml',      $ssXml);
    $zip->addFromString('xl/styles.xml',             $stylesXml);

    foreach ($sheetXmls as $path => $content) {
        $zip->addFromString($path, $content);
    }

    $zip->close();
    $bytes = file_get_contents($tmpFile);
    unlink($tmpFile);
    return $bytes;
}

function rootRels(): string {
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
         . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
         . '<Relationship Id="rId1"'
         . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"'
         . ' Target="xl/workbook.xml"/>'
         . '</Relationships>';
}

function colLetter(int $n): string {
    $letter = '';
    do {
        $letter = chr(65 + ($n % 26)) . $letter;
        $n      = intdiv($n, 26) - 1;
    } while ($n >= 0);
    return $letter;
}

// ── Handle POST: generate and download xlsx ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::validateCsrf();

    $allTables   = Database::fetchAll('SHOW TABLES');
    $dbName      = DB_NAME;
    $tableColKey = 'Tables_in_' . $dbName;

    // Fall back to first column if key not found
    $allTableNames = array_map(function($r) use ($tableColKey) {
        return $r[$tableColKey] ?? reset($r);
    }, $allTables);

    $selected = $_POST['tables'] ?? [];

    if (!is_array($selected) || empty($selected)) {
        // Nothing selected — bounce back
        header('Location: db-export.php?err=noselect');
        exit;
    }

    // Validate: only export tables that actually exist
    $selected = array_intersect($selected, $allTableNames);

    if (empty($selected)) {
        header('Location: db-export.php?err=invalid');
        exit;
    }

    $sheets = [];
    foreach ($selected as $table) {
        $rows = Database::fetchAll('SELECT * FROM `' . $table . '`');
        if (empty($rows)) {
            $sheets[$table] = [['(no data)']];
            continue;
        }
        $header   = array_keys($rows[0]);
        $dataRows = [];
        foreach ($rows as $row) {
            $dataRows[] = array_values($row);
        }
        $sheets[$table] = array_merge([$header], $dataRows);
    }

    $filename = 'db-export-' . date('Ymd-His') . '.xlsx';
    $xlsx     = buildXlsx($sheets);

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($xlsx));
    header('Cache-Control: max-age=0');
    echo $xlsx;
    exit;
}

// ── GET: show table selection form ────────────────────────────────────────────
$allTables   = Database::fetchAll('SHOW TABLES');
$dbName      = DB_NAME;
$tableColKey = 'Tables_in_' . $dbName;
$tableNames  = array_map(function($r) use ($tableColKey) {
    return $r[$tableColKey] ?? reset($r);
}, $allTables);

$error = match($_GET['err'] ?? '') {
    'noselect' => 'Please select at least one table.',
    'invalid'  => 'Invalid table selection.',
    default    => '',
};

include_once 'Includes/Top.php';
?>
<style>
.dbexp-wrap { max-width:900px; margin:0 auto; padding:0 16px; font-family:Verdana,Arial,sans-serif; }
.dbexp-wrap h2 { font-size:18px; color:#762F00; margin-bottom:16px; }
.dbexp-card { background:#fff; border:1px solid #ddd; border-radius:6px; padding:20px; box-shadow:0 1px 4px rgba(0,0,0,.08); }
.dbexp-toolbar { display:flex; align-items:center; gap:12px; margin-bottom:14px; flex-wrap:wrap; }
.dbexp-toolbar input[type=text] {
    flex:1; min-width:160px; padding:6px 10px;
    border:1px solid #ccc; border-radius:4px; font-size:13px; }
.dbexp-toolbar label { font-size:13px; font-weight:bold; color:#555; cursor:pointer; }
.dbexp-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(210px,1fr)); gap:6px 14px; max-height:480px; overflow-y:auto; padding-right:4px; }
.dbexp-grid label { display:flex; align-items:center; gap:7px; font-size:13px; padding:4px 6px; border-radius:3px; cursor:pointer; }
.dbexp-grid label:hover { background:#fdf4ec; }
.dbexp-grid input[type=checkbox] { width:15px; height:15px; cursor:pointer; accent-color:#762F00; }
.dbexp-actions { margin-top:18px; display:flex; align-items:center; gap:14px; flex-wrap:wrap; }
.btn-export { background:#762F00; color:#fff; border:none; padding:9px 22px;
              font-size:14px; font-weight:bold; border-radius:4px; cursor:pointer; }
.btn-export:hover { background:#9a4000; }
.dbexp-count { font-size:13px; color:#777; }
.dbexp-error { background:#fde8e8; border:1px solid #f5c6cb; color:#721c24;
               padding:8px 14px; border-radius:4px; margin-bottom:14px; font-size:13px; }
</style>

<div class="dbexp-wrap">
  <h2>&#x1F4E5; Database Table Export</h2>

  <?php if ($error): ?>
    <div class="dbexp-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <div class="dbexp-card">
    <form method="post" id="exportForm">
      <?= Auth::csrfToken() ?>

      <div class="dbexp-toolbar">
        <input type="text" id="tableSearch" placeholder="Filter tables…" oninput="filterTables(this.value)" autocomplete="off">
        <label>
          <input type="checkbox" id="selectAll" onchange="toggleAll(this.checked)">
          Select All
        </label>
        <span class="dbexp-count" id="countLabel">0 of <?= count($tableNames) ?> selected</span>
      </div>

      <div class="dbexp-grid" id="tableGrid">
        <?php foreach ($tableNames as $tbl): ?>
          <label class="tbl-item">
            <input type="checkbox" name="tables[]" value="<?= htmlspecialchars($tbl) ?>"
                   onchange="updateCount()">
            <?= htmlspecialchars($tbl) ?>
          </label>
        <?php endforeach; ?>
      </div>

      <div class="dbexp-actions">
        <button type="submit" class="btn-export">&#x2B07; Download Excel (.xlsx)</button>
        <span class="dbexp-count">Each selected table becomes one sheet in the workbook.</span>
      </div>
    </form>
  </div>
</div>

<script>
function getChecks() {
    return document.querySelectorAll('#tableGrid input[type=checkbox]');
}

function updateCount() {
    var checks = getChecks();
    var total   = checks.length;
    var checked = 0;
    checks.forEach(function(c) { if (c.checked) checked++; });
    document.getElementById('countLabel').textContent = checked + ' of ' + total + ' selected';
    document.getElementById('selectAll').checked = (checked === total && total > 0);
    document.getElementById('selectAll').indeterminate = (checked > 0 && checked < total);
}

function toggleAll(state) {
    getChecks().forEach(function(c) {
        if (c.closest('.tbl-item').style.display !== 'none') {
            c.checked = state;
        }
    });
    updateCount();
}

function filterTables(q) {
    q = q.toLowerCase();
    var items = document.querySelectorAll('#tableGrid .tbl-item');
    items.forEach(function(item) {
        var name = item.querySelector('input').value.toLowerCase();
        item.style.display = name.includes(q) ? '' : 'none';
    });
    updateCount();
}

// Prevent submit if nothing selected
document.getElementById('exportForm').addEventListener('submit', function(e) {
    var any = false;
    getChecks().forEach(function(c) { if (c.checked) any = true; });
    if (!any) {
        e.preventDefault();
        alert('Please select at least one table to export.');
    }
});

updateCount();
</script>

<?php include_once 'Includes/Bottom.php'; ?>
