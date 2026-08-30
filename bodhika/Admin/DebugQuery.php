<?php
/**
 * Admin/DebugQuery.php — Ad-hoc read-only SQL query tool for admins.
 *
 * Lets an admin paste a SELECT/SHOW/DESCRIBE/EXPLAIN query, preview the
 * results in-browser, and export the same result set to a CSV file that
 * opens directly in Excel (same convention as exam/export-excel.php: UTF-8
 * BOM + quoted CSV).
 *
 * SAFETY:
 *  - Admin-only (Auth::isAdmin()), CSRF-protected POST.
 *  - Only SELECT / WITH / SHOW / DESCRIBE / DESC / EXPLAIN statements are
 *    allowed — anything else (INSERT/UPDATE/DELETE/DROP/ALTER/TRUNCATE/
 *    CREATE/GRANT/REVOKE/CALL/LOAD_FILE/... or writing to a file via
 *    INTO OUTFILE/DUMPFILE) is rejected before it ever reaches the DB.
 *  - Only a single statement is allowed (no ';'-stacked queries).
 *  - A LIMIT is auto-appended to plain SELECT/WITH queries that don't
 *    already have one, capped at MAX_ROWS, so a runaway query can't hang
 *    the page or exhaust memory.
 *  - Every query that actually executes is written to the PHP error log
 *    with the admin's login name, for an audit trail.
 *
 * This is a power-user tool for data review, not a general admin feature —
 * intentionally not linked from includes/sidebar.php. Admins who need it
 * can bookmark Admin/DebugQuery.php directly.
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');
if (!Auth::isAdmin()) { header('Location: ../auth/login.php'); exit; }

const MAX_ROWS     = 5000; // hard cap on rows fetched/exported
const PREVIEW_ROWS = 500;  // rows actually rendered in the on-page HTML table

/* ── CSV export helpers (mirrors exam/export-excel.php's convention) ────── */
function dbgCsvRow(array $fields): string {
    return implode(',', array_map(function ($v) {
        $v = str_replace('"', '""', (string)$v);
        return '"' . $v . '"';
    }, $fields)) . "\r\n";
}

function dbgSendCsvHeaders(string $filename): void {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM so Excel auto-detects encoding
}

/**
 * Validates a user-supplied query is a single, read-only statement.
 * Returns the (possibly LIMIT-appended) query on success, or throws with a
 * human-readable reason on failure.
 */
function dbgSanitizeQuery(string $raw): string
{
    $sql = trim($raw);
    if ($sql === '') {
        throw new InvalidArgumentException('Enter a query first.');
    }

    // Strip a single trailing semicolon (and any trailing whitespace after it).
    $sql = rtrim($sql);
    $sql = rtrim($sql, ";");
    $sql = rtrim($sql);

    // Strip leading SQL comments (-- line, # line, /* block */) so a query
    // pasted straight from a .sql file — which usually opens with a header
    // comment — still has its real statement recognised as the first token.
    while (true) {
        $before = $sql;
        $sql = preg_replace('/^\s*--[^\n]*\n?/', '', $sql);
        $sql = preg_replace('/^\s*#[^\n]*\n?/', '', $sql);
        $sql = preg_replace('/^\s*\/\*.*?\*\//s', '', $sql);
        $sql = ltrim($sql);
        if ($sql === $before) break;
    }
    if ($sql === '') {
        throw new InvalidArgumentException('Query is empty after stripping comments.');
    }

    // Reject anything that still contains a semicolon — no stacked statements.
    if (strpos($sql, ';') !== false) {
        throw new InvalidArgumentException('Only a single statement is allowed (no semicolons inside the query).');
    }

    // Must start with a read-only verb.
    if (!preg_match('/^\s*(SELECT|WITH|SHOW|DESCRIBE|DESC|EXPLAIN)\b/i', $sql, $m)) {
        throw new InvalidArgumentException('Only SELECT, WITH, SHOW, DESCRIBE, and EXPLAIN statements are allowed.');
    }
    $verb = strtoupper($m[1]);

    // Block write/DDL/file-access keywords anywhere in the statement, even
    // inside a SELECT (e.g. "SELECT ... INTO OUTFILE '/tmp/x'").
    $forbidden = '/\b(INSERT|UPDATE|DELETE|REPLACE|DROP|ALTER|TRUNCATE|CREATE|GRANT|REVOKE|
                    CALL|EXEC|EXECUTE|LOAD_FILE|OUTFILE|DUMPFILE|SET\s+GLOBAL|SET\s+SESSION)\b/ix';
    if (preg_match($forbidden, $sql)) {
        throw new InvalidArgumentException('That query contains a write/DDL/file-access keyword, which is not allowed here.');
    }

    // Auto-cap row count for plain SELECT/WITH queries that don't specify
    // their own LIMIT. SHOW/DESCRIBE/EXPLAIN don't reliably support LIMIT,
    // so leave those alone (their result sets are inherently small anyway).
    if (in_array($verb, ['SELECT', 'WITH'], true) && !preg_match('/\bLIMIT\s+\d/i', $sql)) {
        $sql .= ' LIMIT ' . MAX_ROWS;
    }

    return $sql;
}

$query   = trim($_POST['sql_query'] ?? $_GET['sql_query'] ?? '');
$rows    = null;
$columns = [];
$error   = '';
$ranQuery = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::validateCsrf();
    $action = $_POST['action'] ?? 'run';

    try {
        $safeSql  = dbgSanitizeQuery($query);
        $ranQuery = $safeSql;
        $rows     = Database::fetchAll($safeSql);
        $columns  = $rows ? array_keys($rows[0]) : [];

        $dbgAdminLabel = Auth::currentUser() ?: ('user#' . Auth::currentUserId());
        error_log(sprintf(
            '[DebugQuery] admin=%s rows=%d sql=%s',
            $dbgAdminLabel,
            count($rows),
            $safeSql
        ));

        if ($action === 'export') {
            dbgSendCsvHeaders('debug_query_' . date('Y-m-d_His') . '.csv');
            if ($columns) {
                echo dbgCsvRow($columns);
                foreach ($rows as $r) {
                    echo dbgCsvRow(array_map(fn($v) => $v === null ? '' : $v, $r));
                }
            }
            exit;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $rows  = null;
    }
}

$pageTitle = 'Debug Query';
include __DIR__ . '/../includes/header.php';
?>
<style>
  .dq-textarea { width:100%; min-height:160px; font-family:'SFMono-Regular',Consolas,Menlo,monospace; font-size:.85rem; padding:12px; border:1px solid var(--clr-border); border-radius:8px; resize:vertical; }
  .dq-actions { display:flex; gap:10px; margin-top:10px; flex-wrap:wrap; align-items:center; }
  .dq-hint { font-size:.8rem; color:#6b7280; margin-top:8px; line-height:1.5; }
  .dq-hint code { background:#f3f4f6; padding:1px 5px; border-radius:4px; }
  .dq-meta { font-size:.82rem; color:#6b7280; margin:12px 0; }
  .dq-tbl-wrap { overflow:auto; max-height:60vh; border:1px solid var(--clr-border); border-radius:8px; }
  .dq-tbl { border-collapse:collapse; width:100%; font-size:.82rem; white-space:nowrap; }
  .dq-tbl th, .dq-tbl td { padding:6px 10px; border-bottom:1px solid var(--clr-border); text-align:left; }
  .dq-tbl th { position:sticky; top:0; background:var(--clr-surface); z-index:1; }
  .dq-tbl tr:nth-child(even) { background:rgba(0,0,0,.02); }
</style>

<div class="card">
  <div class="card-header">&#128269; Debug Query</div>
  <div class="card-body">

    <div class="alert alert-warning" style="font-size:.85rem;">
      Read-only tool — only <code>SELECT</code>/<code>WITH</code>/<code>SHOW</code>/<code>DESCRIBE</code>/<code>EXPLAIN</code>
      statements run here. Every executed query is written to the server error log for audit purposes.
    </div>

    <?php if ($error): ?>
      <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form method="post" action="">
      <input type="hidden" name="csrf_token" value="<?php echo Auth::csrfToken(); ?>">
      <textarea name="sql_query" class="dq-textarea" placeholder="SELECT * FROM questions WHERE SubjectInfoId = 3 LIMIT 100"><?php echo htmlspecialchars($query); ?></textarea>

      <div class="dq-actions">
        <button type="submit" name="action" value="run" class="btn btn-primary">&#9654; Run Query</button>
        <button type="submit" name="action" value="export" class="btn btn-secondary">&#11015; Export to Excel (CSV)</button>
      </div>

      <div class="dq-hint">
        A <code>LIMIT <?php echo MAX_ROWS; ?></code> is auto-added to <code>SELECT</code>/<code>WITH</code> queries
        that don't already specify one. Add your own <code>LIMIT</code> to preview a smaller slice first.
        "Export" re-runs the same query and streams the full (capped) result set as a CSV file instead of
        rendering it on the page.
      </div>
    </form>

    <?php if ($rows !== null): ?>
      <div class="dq-meta">
        <?php echo count($rows); ?> row<?php echo count($rows) !== 1 ? 's' : ''; ?> returned
        <?php if (count($rows) > PREVIEW_ROWS): ?>
          — showing first <?php echo PREVIEW_ROWS; ?> below (use Export for the full set)
        <?php endif; ?>
      </div>

      <?php if (empty($rows)): ?>
        <p style="text-align:center;color:#6b7280;padding:20px 0;">Query ran successfully but returned no rows.</p>
      <?php else: ?>
        <div class="dq-tbl-wrap">
          <table class="dq-tbl">
            <thead>
              <tr><?php foreach ($columns as $col): ?><th><?php echo htmlspecialchars($col); ?></th><?php endforeach; ?></tr>
            </thead>
            <tbody>
              <?php foreach (array_slice($rows, 0, PREVIEW_ROWS) as $r): ?>
                <tr>
                  <?php foreach ($columns as $col): ?>
                    <td><?php echo htmlspecialchars((string)($r[$col] ?? '')); ?></td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    <?php endif; ?>

  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
