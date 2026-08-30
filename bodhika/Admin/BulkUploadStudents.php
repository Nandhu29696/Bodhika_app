<?php
/**
 * Admin/BulkUploadStudents.php
 *
 * Bulk-create student accounts from an Excel (.xlsx) or CSV file built on
 * the downloadable StudentUpload_Template.xlsx (see the "Students" sheet —
 * "Instructions" is ignored on read, same two-sheet pattern as
 * QuestionUpload_Template.xlsx / BulkUploadQuestions.php).
 *
 * Expected columns (Students sheet, data starts row 3 — rows 1-2 are the
 * banner + header):
 *   A  Username        (required — 3-50 chars, letters/digits/_.- only)
 *   B  Temp Password   (required — min 8 characters)
 *   C  First Name      (required)
 *   D  Last Name       (required)
 *   E  Email           (required — valid format)
 *   F  Gender          (optional — M or F)
 *   G  Country Code    (optional — defaults to +91)
 *   H  Mobile Number   (required — digits only; the primary duplicate-
 *                        registration key, see below)
 *   I  Blood Group     (optional — A+/A-/B+/B-/AB+/AB-/O+/O-)
 *   J  Donate Blood?   (optional — Y/N, defaults N)
 *   K  Institute ID    (optional — must match an existing institutes.InstituteId)
 *   L  Institute Name  (optional — reference only; if Institute ID is blank,
 *                        this name is resolved to an ID for you; if both are
 *                        given they must agree)
 *   M  Institute Student ID (optional — free-text roll/admission/ERP number
 *                        the institute uses in its own records; stored as-is,
 *                        never validated or checked for uniqueness)
 *   N  Student Group   (optional — cohort/batch name. Matched to an existing
 *                        student_groups row case-insensitively; if no match
 *                        is found a new group is created on the fly with a
 *                        0% discount, then the student is added as a member.
 *                        Multiple rows may safely share the same group name
 *                        — it's created once and everyone joins it.)
 *
 * Validation per row:
 *   - Format checks for every column above (see validateRow()).
 *   - Duplicate checks on Username, Email, and Mobile Number — first within
 *     the uploaded file itself (row-to-row), then against every account
 *     already in the system. Mobile Number is the field the admin asked
 *     this importer to treat as the authoritative "is this student already
 *     registered?" check, so it is mandatory and must be unique both ways.
 *   - Only rows that pass every check are inserted; everything else is
 *     listed with a reason so the admin can fix and re-upload just those
 *     rows (same UX as BulkUploadQuestions.php).
 *   - Max 2000 rows per upload.
 *
 * Accounts created here are admin-curated (not self-registration), so they
 * are inserted Active='Y' (and RegistrationStatus='Approved' where that
 * column exists) — usable immediately, no separate approval step.
 */

require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');
if (!Auth::isAdmin()) { header('Location: ../auth/login.php'); exit; }

/* ── Institutes lookup (for Institute ID / Institute Name validation) ────── */
$institutesById   = [];   // InstituteId => InstituteName
$institutesByName = [];   // lowercase InstituteName => InstituteId
try {
    foreach (Database::fetchAll("SELECT InstituteId, InstituteName FROM institutes") as $inst) {
        $id = (int)$inst['InstituteId'];
        $institutesById[$id] = $inst['InstituteName'];
        $institutesByName[mb_strtolower(trim($inst['InstituteName']))] = $id;
    }
} catch (Exception $e) { /* institutes table not yet created (migration_v16 not run) */ }

/* ── Student groups support (migration_v53) — optional column M. If the
   tables don't exist yet on this install, group names in the file are
   simply ignored (students are still created) rather than failing the
   whole upload. ────────────────────────────────────────────────────────── */
$groupsSupported = Database::tableExists('student_groups') && Database::tableExists('student_group_members');
$groupNameToId    = [];   // lowercase GroupName => StudentGroupId (existing + newly created this run)
if ($groupsSupported) {
    try {
        foreach (Database::fetchAll("SELECT StudentGroupId, GroupName FROM student_groups") as $g) {
            $groupNameToId[mb_strtolower(trim($g['GroupName']))] = (int)$g['StudentGroupId'];
        }
    } catch (Exception $e) { $groupsSupported = false; }
}

/* ── Sheet selection helpers — mirrors BulkUploadQuestions.php: the
   template ships an "Instructions" tab alongside "Students", so the sheet
   must be picked by name, never by "active"/"first" position. ──────────── */
function pickStudentsSheetPhp(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet)
{
    $names = $spreadsheet->getSheetNames();
    if (count($names) === 1) return $spreadsheet->getSheet(0);
    foreach ($names as $i => $name) {
        if (strcasecmp(trim($name), 'Students') === 0) return $spreadsheet->getSheet($i);
    }
    foreach ($names as $i => $name) {
        if (stripos($name, 'student') !== false) return $spreadsheet->getSheet($i);
    }
    return $spreadsheet->getActiveSheet();
}

function locateStudentsSheetXml(string $tmpDir): string
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

        if (count($sheets) === 1) return $resolve($sheets[0]['rid']) ?? $fallback;
        foreach ($sheets as $s) {
            if (strcasecmp(trim($s['name']), 'Students') === 0) {
                $p = $resolve($s['rid']); if ($p) return $p;
            }
        }
        foreach ($sheets as $s) {
            if (stripos($s['name'], 'student') !== false) {
                $p = $resolve($s['rid']); if ($p) return $p;
            }
        }
        return $resolve($sheets[0]['rid']) ?? $fallback;
    } catch (Exception $e) {
        return $fallback;
    }
}

const STUDENT_COLS = 14; // A..N

/* ── XLSX reader — PhpSpreadsheet if available, else a self-contained
   unzip + XML parse, same fallback pattern as BulkUploadQuestions.php. ──── */
function readXlsx(string $path): array|false
{
    if (class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $sheet = pickStudentsSheetPhp($spreadsheet);
        $rows  = [];
        foreach ($sheet->getRowIterator(3) as $row) { // data starts row 3
            $cells = [];
            foreach ($row->getCellIterator('A', 'N') as $cell) {
                $cells[] = (string)($cell->getValue() ?? '');
            }
            if (implode('', $cells) === '') continue;
            $rows[] = $cells;
        }
        return $rows;
    }

    $tmpDir = sys_get_temp_dir() . '/xlsxread_' . uniqid();
    mkdir($tmpDir, 0700, true);
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) { rmdir($tmpDir); return false; }
    $zip->extractTo($tmpDir);
    $zip->close();

    $sharedStrings = [];
    $ssPath = $tmpDir . '/xl/sharedStrings.xml';
    if (file_exists($ssPath)) {
        $xml = simplexml_load_file($ssPath);
        foreach ($xml->si as $si) {
            $sharedStrings[] = (string)($si->t ?? implode('', array_map('strval', (array)($si->r ?? []))));
        }
    }

    $sheetPath = locateStudentsSheetXml($tmpDir);
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
        $cells = array_fill(0, STUDENT_COLS, '');
        foreach ($row->c as $cell) {
            preg_match('/^([A-Z]+)/', (string)$cell['r'], $m);
            $colIdx = 0;
            foreach (str_split($m[1]) as $ch) $colIdx = $colIdx * 26 + (ord($ch) - 64);
            $colIdx--;
            if ($colIdx >= STUDENT_COLS) continue;
            $t = (string)($cell['t'] ?? '');
            if ($t === 's') {
                $cells[$colIdx] = $sharedStrings[(int)($cell->v ?? '')] ?? '';
            } elseif ($t === 'inlineStr') {
                $is = $cell->is ?? null;
                $cells[$colIdx] = $is === null
                    ? ''
                    : (string)($is->t ?? implode('', array_map('strval', (array)($is->r ?? []))));
            } else {
                $cells[$colIdx] = (string)($cell->v ?? '');
            }
        }
        if (implode('', $cells) === '') continue;
        $rows[] = $cells;
    }

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
    $bom = fread($h, 3);
    if ($bom !== "\xEF\xBB\xBF") rewind($h);
    $line = 0;
    while (($cells = fgetcsv($h, 4096)) !== false) {
        $line++;
        if ($line <= 2) continue; // skip banner + header
        if (implode('', $cells) === '') continue;
        while (count($cells) < STUDENT_COLS) $cells[] = '';
        $rows[] = array_slice($cells, 0, STUDENT_COLS);
    }
    fclose($h);
    return $rows;
}

/* ── Mobile number rules (same table as auth/register.php, so a bulk-
   uploaded account is held to the same standard as a self-registered one) */
const MOBILE_RULES = [
    '+91'  => [10, 10, '/^[6-9]/'],   '+1'   => [10, 10, null],
    '+44'  => [10, 10, null],         '+61'  => [9,  9,  null],
    '+64'  => [8,  9,  null],         '+971' => [9,  9,  null],
    '+966' => [9,  9,  '/^[5]/'],     '+65'  => [8,  8,  '/^[689]/'],
    '+60'  => [9,  10, '/^[1]/'],     '+94'  => [9,  9,  '/^[7]/'],
    '+92'  => [10, 10, '/^[3]/'],     '+880' => [10, 10, '/^[1]/'],
    '+977' => [10, 10, '/^[9]/'],     '+81'  => [10, 11, null],
    '+82'  => [9,  10, null],         '+86'  => [11, 11, null],
    '+852' => [8,  8,  null],         '+49'  => [10, 12, null],
    '+33'  => [9,  9,  null],         '+39'  => [9,  10, null],
    '+34'  => [9,  9,  null],         '+31'  => [9,  9,  null],
    '+46'  => [9,  9,  null],         '+47'  => [8,  8,  null],
    '+45'  => [8,  8,  null],         '+41'  => [9,  9,  null],
    '+7'   => [10, 10, null],         '+55'  => [10, 11, null],
    '+52'  => [10, 10, null],         '+54'  => [10, 10, null],
    '+27'  => [9,  9,  null],         '+234' => [10, 10, '/^[07-9]/'],
    '+254' => [9,  9,  '/^[7]/'],     '+20'  => [10, 10, null],
    '+212' => [9,  9,  null],
];

const ALLOWED_BLOOD_GROUPS = ['A+','A-','B+','B-','AB+','AB-','O+','O-'];

/* ── Per-row structural/format validation (no DB duplicate checks here —
   those run afterwards, batched, once every row has been parsed). ──────── */
function validateRow(array $cells, int $rowNum, array $institutesById, array $institutesByName): array
{
    [$username, $password, $fstName, $lstName, $email, $gender,
     $ccode, $mobileRaw, $bloodGroup, $donate, $instIdRaw, $instNameRaw, $instStudentIdRaw, $groupNameRaw]
        = array_pad($cells, STUDENT_COLS, '');

    $username   = trim($username);
    $password   = (string)$password;
    $fstName    = trim($fstName);
    $lstName    = trim($lstName);
    $email      = trim($email);
    $gender     = strtoupper(trim($gender));
    $ccode      = trim($ccode) !== '' ? trim($ccode) : '+91';
    $mobileRaw  = preg_replace('/\D/', '', trim((string)$mobileRaw));
    $bloodGroup = strtoupper(trim($bloodGroup));
    $donate     = strtoupper(trim($donate));
    $instIdRaw  = trim((string)$instIdRaw);
    $instNameRaw= trim($instNameRaw);
    $instStudentId = trim((string)$instStudentIdRaw);
    $groupName  = trim((string)$groupNameRaw);

    $errors = [];

    if ($username === '') {
        $errors[] = 'Username is required.';
    } elseif (!preg_match('/^[A-Za-z0-9_.\-]{3,50}$/', $username)) {
        $errors[] = 'Username must be 3-50 characters: letters, digits, underscore, period, hyphen only.';
    }

    if ($password === '') {
        $errors[] = 'Temp Password is required.';
    } elseif (strlen($password) < 8) {
        $errors[] = 'Temp Password must be at least 8 characters.';
    }

    if ($fstName === '') $errors[] = 'First Name is required.';
    if ($lstName === '') $errors[] = 'Last Name is required.';

    if ($email === '') {
        $errors[] = 'Email is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Email '$email' is not a valid email address.";
    }

    if ($gender !== '' && !in_array($gender, ['M', 'F'], true)) {
        $errors[] = "Gender must be M or F (got: '$gender').";
    }

    /* Mobile Number — required (primary duplicate-registration key) */
    $mobileKey = '';
    if ($mobileRaw === '') {
        $errors[] = 'Mobile Number is required.';
    } elseif (!ctype_digit($mobileRaw)) {
        $errors[] = 'Mobile Number must contain digits only (no spaces or dashes).';
    } else {
        $digits = strlen($mobileRaw);
        if (isset(MOBILE_RULES[$ccode])) {
            [$min, $max, $leadPat] = MOBILE_RULES[$ccode];
            if ($digits < $min || $digits > $max) {
                $errors[] = "Mobile Number for $ccode must be "
                          . ($min === $max ? "$min" : "$min–$max")
                          . " digits (got $digits).";
            } elseif ($leadPat && !preg_match($leadPat, $mobileRaw)) {
                $errors[] = "Mobile Number for $ccode appears invalid (check starting digit).";
            }
        } elseif ($digits < 6 || $digits > 15) {
            $errors[] = 'Mobile Number must be between 6 and 15 digits.';
        }
        $mobileKey = $ccode . $mobileRaw;
    }

    if ($bloodGroup !== '' && !in_array($bloodGroup, ALLOWED_BLOOD_GROUPS, true)) {
        $errors[] = "Blood Group must be one of: " . implode(', ', ALLOWED_BLOOD_GROUPS) . " (got: '$bloodGroup').";
    }

    if ($donate !== '' && !in_array($donate, ['Y', 'N'], true)) {
        $errors[] = "Donate Blood? must be Y or N (got: '$donate').";
    }

    /* Institute ID / Institute Name — resolve to a single InstituteId */
    $instituteId = null;
    $instIdNum   = ($instIdRaw !== '' && is_numeric($instIdRaw)) ? (int)$instIdRaw : null;
    if ($instIdRaw !== '' && $instIdNum === null) {
        $errors[] = "Institute ID must be numeric (got: '$instIdRaw').";
    } elseif ($instIdNum !== null) {
        if (!isset($institutesById[$instIdNum])) {
            $errors[] = "Institute ID $instIdNum does not match any existing institute.";
        } else {
            $instituteId = $instIdNum;
            if ($instNameRaw !== '' && mb_strtolower($instNameRaw) !== mb_strtolower($institutesById[$instIdNum])) {
                $errors[] = "Institute Name '$instNameRaw' does not match Institute ID $instIdNum "
                          . "(which is '" . $institutesById[$instIdNum] . "'). Fix one of the two, or leave Institute Name blank.";
            }
        }
    } elseif ($instNameRaw !== '') {
        $key = mb_strtolower($instNameRaw);
        if (isset($institutesByName[$key])) {
            $instituteId = $institutesByName[$key];
        } else {
            $errors[] = "Institute Name '$instNameRaw' was not found — check spelling, use Institute ID instead, or leave both blank for an independent student.";
        }
    }

    if (mb_strlen($instStudentId) > 50) {
        $errors[] = 'Institute Student ID exceeds 50 characters (' . mb_strlen($instStudentId) . ').';
    }

    /* Student Group — no existence check: an unrecognised name is a new
       group to be created at insert time, not an error. Only the format
       (length) is validated here. */
    if (mb_strlen($groupName) > 150) {
        $errors[] = 'Student Group name exceeds 150 characters (' . mb_strlen($groupName) . ').';
    }

    return [
        'errors'      => $errors,
        'username'    => $username,
        'usernameKey' => mb_strtolower($username),
        'password'    => $password,
        'fstName'     => $fstName,
        'lstName'     => $lstName,
        'email'       => $email,
        'emailKey'    => mb_strtolower($email),
        'gender'      => $gender,
        'mobile'      => $mobileKey,     // '' only possible if validation already failed above
        'mobileKey'   => $mobileKey,
        'bloodGroup'  => $bloodGroup !== '' ? $bloodGroup : null,
        'donate'      => $donate === 'Y' ? 'Y' : 'N',
        'instituteId'     => $instituteId,
        'instStudentId'   => $instStudentId !== '' ? $instStudentId : null,
        'groupName'       => $groupName,
        'rowNum'          => $rowNum,
    ];
}

/* ── Handle upload POST ───────────────────────────────────────────────────── */
$results   = null;   // null = not yet uploaded
$inserted  = 0;
$errorRows = [];
$totalRows = 0;
$groupsCreated     = 0;
$groupMembersAdded = 0;
$groupsIgnoredNote = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['bulkFile'])) {
    Auth::validateCsrf();

    $file     = $_FILES['bulkFile'];
    $tmpPath  = $file['tmp_name'];
    $origName = strtolower($file['name'] ?? '');
    $ext      = pathinfo($origName, PATHINFO_EXTENSION);
    $uploadErr = '';

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $uploadErr = 'File upload failed (error code ' . $file['error'] . ').';
    } elseif (!in_array($ext, ['xlsx', 'csv'], true)) {
        $uploadErr = 'Only .xlsx and .csv files are accepted.';
    } elseif ($file['size'] > 15 * 1024 * 1024) {
        $uploadErr = 'File size must not exceed 15 MB.';
    }

    if ($uploadErr) {
        $errorRows[] = ['row' => '—', 'preview' => '', 'errors' => [$uploadErr]];
        $results = true;
    } else {
        $rows = $ext === 'xlsx' ? readXlsx($tmpPath) : readCsv($tmpPath);

        if ($rows === false) {
            $errorRows[] = ['row' => '—', 'preview' => '', 'errors' => [
                'Could not read the file. Ensure it is a valid, non-corrupted .xlsx or .csv file.',
            ]];
            $results = true;
        } else {
            $totalRows = count($rows);
            if ($totalRows === 0) {
                $errorRows[] = ['row' => '—', 'preview' => '', 'errors' => [
                    'No student rows were found. Data must start on row 3 of the "Students" sheet ' .
                    '(rows 1–2 are reserved for the banner and column headers) — check that your file ' .
                    'follows the downloadable template exactly.',
                ]];
                $results = true;
            } elseif ($totalRows > 2000) {
                $errorRows[] = ['row' => '—', 'preview' => '', 'errors' => ["File contains $totalRows rows — maximum is 2000 per upload."]];
                $results = true;
            } else {
                /* ── Pass 1: structural/format validation ── */
                $candidates = []; // rowNum => validated row data (still needs dup checks)
                foreach ($rows as $i => $cells) {
                    $rowNum = $i + 3; // account for the 2 header rows
                    $v = validateRow($cells, $rowNum, $institutesById, $institutesByName);
                    if ($v['errors']) {
                        $errorRows[] = [
                            'row'     => $rowNum,
                            'preview' => $v['username'] !== '' ? $v['username'] : mb_substr((string)$cells[0], 0, 40),
                            'errors'  => $v['errors'],
                        ];
                    } else {
                        $candidates[] = $v;
                    }
                }

                /* ── Pass 2: in-file duplicate detection (username/email/mobile) ── */
                $seenUsername = []; $seenEmail = []; $seenMobile = [];
                $afterInFileCheck = [];
                foreach ($candidates as $v) {
                    $dupErrors = [];
                    if (isset($seenUsername[$v['usernameKey']])) {
                        $dupErrors[] = "Duplicate Username — already used in row {$seenUsername[$v['usernameKey']]} of this file.";
                    } else {
                        $seenUsername[$v['usernameKey']] = $v['rowNum'];
                    }
                    if (isset($seenEmail[$v['emailKey']])) {
                        $dupErrors[] = "Duplicate Email — already used in row {$seenEmail[$v['emailKey']]} of this file.";
                    } else {
                        $seenEmail[$v['emailKey']] = $v['rowNum'];
                    }
                    if (isset($seenMobile[$v['mobileKey']])) {
                        $dupErrors[] = "Duplicate Mobile Number — already used in row {$seenMobile[$v['mobileKey']]} of this file.";
                    } else {
                        $seenMobile[$v['mobileKey']] = $v['rowNum'];
                    }

                    if ($dupErrors) {
                        $errorRows[] = ['row' => $v['rowNum'], 'preview' => $v['username'], 'errors' => $dupErrors];
                    } else {
                        $afterInFileCheck[] = $v;
                    }
                }

                /* ── Pass 3: duplicate-registration check against the database.
                   Batched (one IN() query per field) instead of one query per
                   row, so a 2000-row upload costs 3 queries here, not 6000. ── */
                $validRows = [];
                if ($afterInFileCheck) {
                    $usernames = array_column($afterInFileCheck, 'username');
                    $emails    = array_column($afterInFileCheck, 'email');
                    $mobiles   = array_column($afterInFileCheck, 'mobile');

                    $existingUsernames = []; $existingEmails = []; $existingMobiles = [];
                    try {
                        $ph = implode(',', array_fill(0, count($usernames), '?'));
                        foreach (Database::fetchAll(
                            "SELECT LoginName FROM logininfo WHERE LoginName IN ($ph)", $usernames) as $r) {
                            $existingUsernames[mb_strtolower($r['LoginName'])] = true;
                        }
                    } catch (Exception $e) {}
                    try {
                        $ph = implode(',', array_fill(0, count($emails), '?'));
                        foreach (Database::fetchAll(
                            "SELECT Email FROM logininfo WHERE Email IN ($ph)", $emails) as $r) {
                            $existingEmails[mb_strtolower($r['Email'])] = true;
                        }
                        foreach (Database::fetchAll(
                            "SELECT EMail FROM userinfo WHERE EMail IN ($ph)", $emails) as $r) {
                            $existingEmails[mb_strtolower($r['EMail'])] = true;
                        }
                    } catch (Exception $e) {}
                    try {
                        $ph = implode(',', array_fill(0, count($mobiles), '?'));
                        foreach (Database::fetchAll(
                            "SELECT Mobile FROM userinfo WHERE Mobile IN ($ph) AND Mobile <> ''", $mobiles) as $r) {
                            $existingMobiles[$r['Mobile']] = true;
                        }
                    } catch (Exception $e) {}

                    foreach ($afterInFileCheck as $v) {
                        $dbErrors = [];
                        if (isset($existingUsernames[$v['usernameKey']])) {
                            $dbErrors[] = 'Username is already registered.';
                        }
                        if (isset($existingEmails[$v['emailKey']])) {
                            $dbErrors[] = 'Email is already registered.';
                        }
                        if (isset($existingMobiles[$v['mobile']])) {
                            $dbErrors[] = 'Mobile Number is already registered — duplicate registration.';
                        }
                        if ($dbErrors) {
                            $errorRows[] = ['row' => $v['rowNum'], 'preview' => $v['username'], 'errors' => $dbErrors];
                        } else {
                            $validRows[] = $v;
                        }
                    }
                }

                /* ── Insert valid rows in a transaction ── */
                $groupsCreated     = 0;   // new student_groups rows created this run
                $groupMembersAdded = 0;   // student_group_members rows added this run
                $groupsIgnoredNote = false; // true if column M had data but migration_v53 hasn't run

                if ($validRows) {
                    $hasInstituteCol    = Database::hasColumn('userinfo', 'InstituteId');
                    $hasInstStudentCol  = Database::hasColumn('userinfo', 'InstituteStudentId');
                    $hasLoginInstCol    = Database::hasColumn('logininfo', 'InstituteId');
                    $hasBloodCols       = Database::hasColumn('userinfo', 'BloodGroup')
                                       && Database::hasColumn('userinfo', 'WillingToDonateBlood');
                    $adminUser          = Auth::currentUser() ?: 'bulk-upload';

                    Database::beginTransaction();
                    try {
                        foreach ($validRows as $v) {
                            $hash = password_hash($v['password'], PASSWORD_DEFAULT);

                            if ($hasLoginInstCol) {
                                Database::execute(
                                    "INSERT INTO logininfo (LoginName, Password, Role, Email, Active, InstituteId)
                                     VALUES (?, ?, 'STDNT', ?, 'Y', ?)",
                                    [$v['username'], $hash, $v['email'], $v['instituteId']]);
                            } else {
                                Database::execute(
                                    "INSERT INTO logininfo (LoginName, Password, Role, Email, Active)
                                     VALUES (?, ?, 'STDNT', ?, 'Y')",
                                    [$v['username'], $hash, $v['email']]);
                            }

                            $cols = "LoginName, FstName, MiddleName, LstName, Gender, EMail, Mobile, Address, ImageLoc, Note";
                            $phs  = "?, ?, '', ?, ?, ?, ?, '', '', ''";
                            $vals = [$v['username'], $v['fstName'], $v['lstName'], $v['gender'], $v['email'], $v['mobile']];
                            if ($hasInstituteCol)   { $cols .= ", InstituteId"; $phs .= ", ?"; $vals[] = $v['instituteId']; }
                            if ($hasInstStudentCol) { $cols .= ", InstituteStudentId"; $phs .= ", ?"; $vals[] = $v['instStudentId']; }
                            if ($hasBloodCols)      { $cols .= ", BloodGroup, WillingToDonateBlood"; $phs .= ", ?, ?"; $vals[] = $v['bloodGroup']; $vals[] = $v['donate']; }

                            Database::execute("INSERT INTO userinfo ($cols) VALUES ($phs)", $vals);
                            $newUserId = (int)Database::lastInsertId();

                            /* Student Group (column M) — create-if-missing, then add as member.
                               Silently skipped (not an error) when the account itself has no
                               group name, or when migration_v53 hasn't been run yet. */
                            if ($v['groupName'] !== '') {
                                if ($groupsSupported) {
                                    $gKey = mb_strtolower($v['groupName']);
                                    if (isset($groupNameToId[$gKey])) {
                                        $gid = $groupNameToId[$gKey];
                                    } else {
                                        Database::execute(
                                            "INSERT INTO student_groups (GroupName, Description, DiscountPct, IsActive, CreatedBy)
                                             VALUES (?, '', 0, 'Y', ?)",
                                            [$v['groupName'], $adminUser]);
                                        $gid = (int)Database::lastInsertId();
                                        $groupNameToId[$gKey] = $gid;
                                        $groupsCreated++;
                                    }
                                    Database::execute(
                                        "INSERT IGNORE INTO student_group_members (StudentGroupId, UserInfoId, AddedBy) VALUES (?, ?, ?)",
                                        [$gid, $newUserId, $adminUser]);
                                    $groupMembersAdded++;
                                } else {
                                    $groupsIgnoredNote = true;
                                }
                            }

                            $inserted++;
                        }
                        Database::commit();
                    } catch (Exception $ex) {
                        Database::rollBack();
                        $errorRows[] = ['row' => '—', 'preview' => '', 'errors' => ['Database error: ' . $ex->getMessage()]];
                        $inserted = 0; $groupsCreated = 0; $groupMembersAdded = 0;
                    }
                }
                $results = true;
            }
        }
    }
}

/* ── Current total registered students (for the results screen) ────────── */
$totalStudentsNow = 0;
if ($results !== null) {
    try {
        $totalStudentsNow = (int)(Database::fetchOne(
            "SELECT COUNT(*) AS c FROM logininfo WHERE Role = 'STDNT'")['c'] ?? 0);
    } catch (Exception $e) {}
}

$pageTitle = 'Bulk Upload Students';
include __DIR__ . '/../includes/header.php';
?>
<style>
.upload-card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:24px 28px;margin-bottom:20px;}
.upload-grid{display:grid;grid-template-columns:minmax(320px,1.3fr) minmax(280px,1fr);gap:20px;align-items:start;}
.upload-grid .upload-card{margin-bottom:0;}
@media (max-width:900px){.upload-grid{grid-template-columns:1fr;}}
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
  <a href="AdminUsers.php?tab=students">Registered Students</a> &rsaquo;
  Bulk Upload Students
</div>

<div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;margin-bottom:20px;">
  <div>
    <h2 style="margin:0;">Bulk Upload Students</h2>
    <div style="color:#6b7280;font-size:.88rem;margin-top:4px;">
      Create multiple student accounts at once from a spreadsheet.
    </div>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;">
    <a href="StudentUpload_Template.xlsx" download class="btn btn-primary">
      &#11123; Excel Template (.xlsx)
    </a>
    <a href="AdminUsers.php?tab=students" class="btn btn-secondary">
      ← Back to Students
    </a>
  </div>
</div>

<?php if ($results === null): /* ── Upload form ── */ ?>
<div class="upload-grid">
<!-- How-to steps -->
<div class="upload-card">
  <h3 style="margin-top:0;color:#1e3a5f;">How to bulk upload</h3>
  <div style="display:flex;flex-direction:column;gap:10px;">
    <?php $steps = [
      "Download the Excel template above.",
      "Fill in one student per row from row 3 onwards on the \"Students\" sheet; don't edit or delete rows 1–2.",
      "Username, Temp Password, First Name, Last Name, Email and Mobile Number are mandatory for every row.",
      "Mobile Number must be unique — it's used to catch duplicate registrations, both within your file and against existing accounts.",
      "Institute ID (or Institute Name) is optional — leave both blank for an independent student. Institute Student ID (their own roll/admission number) is also optional and just stored for reference.",
      "Student Group is optional — name an existing group to add the student to it, or a new name to create that group automatically and add them as its first member.",
      "Save the file as .xlsx or .csv and upload it below. Valid students are created immediately and can log in right away; invalid rows are listed with reasons so you can fix and re-upload just those.",
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

    <div class="upload-zone" id="dropZone" onclick="document.getElementById('fileInput').click()">
      <div class="upload-zone-icon">📂</div>
      <div style="font-weight:600;font-size:1rem;color:#1e40af;">Click to choose or drag & drop your file here</div>
      <div style="font-size:.83rem;color:#6b7280;margin-top:6px;">Accepts .xlsx or .csv &nbsp;|&nbsp; Max 15 MB &nbsp;|&nbsp; Up to 2000 students</div>
      <div id="fileName" style="margin-top:10px;font-size:.88rem;font-weight:600;color:#1e40af;"></div>
      <input type="file" id="fileInput" name="bulkFile" accept=".xlsx,.csv" onchange="showFile(this)">
    </div>

    <div style="margin-top:16px;display:flex;gap:10px;align-items:center;">
      <button type="submit" id="submitBtn" class="btn btn-primary" disabled style="min-width:160px;">
        ⬆ Upload & Process
      </button>
      <span id="uploadStatus" style="font-size:.85rem;color:#6b7280;"></span>
    </div>
  </form>
</div>
</div><!-- /upload-grid -->

<?php else: /* ── Results ── */ ?>
<!-- Summary KPIs -->
<div class="kpi-row">
  <div class="kpi" style="background:#dbeafe;color:#1e40af;">
    <div class="kpi-val"><?php echo $totalRows; ?></div>
    <div class="kpi-lbl">Rows in File</div>
  </div>
  <div class="kpi" style="background:#d1fae5;color:#065f46;">
    <div class="kpi-val"><?php echo $inserted; ?></div>
    <div class="kpi-lbl">Created ✓</div>
  </div>
  <div class="kpi" style="background:<?php echo $errorRows ? '#fee2e2' : '#f1f5f9'; ?>;
                           color:<?php echo $errorRows ? '#991b1b' : '#475569'; ?>;">
    <div class="kpi-val"><?php echo count($errorRows); ?></div>
    <div class="kpi-lbl">Errors ✗</div>
  </div>
  <div class="kpi" style="background:#ede9fe;color:#5b21b6;">
    <div class="kpi-val"><?php echo $totalStudentsNow; ?></div>
    <div class="kpi-lbl">Total Students Now</div>
  </div>
</div>

<?php if ($inserted > 0): ?>
<div style="background:#d1fae5;border:1px solid #6ee7b7;border-radius:8px;
            padding:12px 16px;margin-bottom:16px;color:#065f46;font-weight:600;">
  ✓ <?php echo $inserted; ?> student account<?php echo $inserted !== 1 ? 's' : ''; ?> created and active
  — the system now has <?php echo $totalStudentsNow; ?> student<?php echo $totalStudentsNow !== 1 ? 's' : ''; ?> total.
</div>
<?php endif; ?>

<?php if ($groupMembersAdded > 0): ?>
<div style="background:#ede9fe;border:1px solid #c4b5fd;border-radius:8px;
            padding:12px 16px;margin-bottom:16px;color:#5b21b6;font-weight:600;">
  &#128101; <?php echo $groupMembersAdded; ?> student<?php echo $groupMembersAdded !== 1 ? 's' : ''; ?> added to a Student Group
  <?php if ($groupsCreated > 0): ?>
    — <?php echo $groupsCreated; ?> new group<?php echo $groupsCreated !== 1 ? 's were' : ' was'; ?> created automatically
    (0% discount by default — <a href="StudentGroups.php" style="color:#5b21b6;">edit in Student Groups</a> if needed).
  <?php else: ?>
    (all matched existing groups).
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($groupsIgnoredNote): ?>
<div style="background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;
            padding:12px 16px;margin-bottom:16px;color:#92400e;font-weight:600;">
  &#9888; Student Group values in the file were ignored — this installation hasn't run migration_v53 yet, so student groups aren't available.
</div>
<?php endif; ?>

<?php if ($errorRows): ?>
<div class="upload-card" style="border-color:#fca5a5;">
  <h3 style="margin-top:0;color:#991b1b;">&#10006; Rows with Errors (not created)</h3>
  <p style="font-size:.87rem;color:#6b7280;margin-bottom:12px;">
    Fix these rows in your file and re-upload. Rows that were successfully created above do not need to be re-uploaded.
  </p>
  <table class="result-tbl" style="width:100%;border-collapse:collapse;">
    <thead>
      <tr>
        <th style="width:60px;">Row #</th>
        <th style="width:25%;">Username</th>
        <th>Validation Errors</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($errorRows as $er): ?>
    <tr>
      <td style="text-align:center;"><span class="badge-err"><?php echo htmlspecialchars((string)$er['row']); ?></span></td>
      <td style="color:#6b7280;font-style:italic;font-size:.82rem;"><?php echo htmlspecialchars($er['preview'] ?? ''); ?></td>
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
  <a href="BulkUploadStudents.php" class="btn btn-primary">
    ⬆ Upload Another File
  </a>
  <a href="AdminUsers.php?tab=students" class="btn btn-secondary">
    ← View All Students
  </a>
  <a href="StudentUpload_Template.xlsx" download class="btn btn-secondary">
    &#11123; Excel Template
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

var form = document.getElementById('uploadForm');
if (form) {
  form.addEventListener('submit', function() {
    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.textContent = '⏳ Processing…';
    }
    document.getElementById('uploadStatus').textContent = 'Validating and creating accounts — please wait…';
  });
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
