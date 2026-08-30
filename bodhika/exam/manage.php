<?php
/**
 * exam/manage.php  — Add or edit an exam (admin only).
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');

// Full admins manage any exam. Institute admins may create/edit exams for
// their own institute only — enforced below via $lockToOwnInstitute (forces
// ExamScope=Institute + ExamInstituteId=own institute, hides the Question
// Bank checkbox, and blocks opening an exam owned by a different institute).
$isFullAdmin = Auth::isAdmin();
$isInstAdmin = Auth::isInstituteAdmin();
if (!$isFullAdmin && !$isInstAdmin) { header('Location: search.php'); exit; }
$lockToOwnInstitute = $isInstAdmin && !$isFullAdmin;
$myInstId = $lockToOwnInstitute ? (int)Auth::currentInstituteId() : null;
$backToExamsUrl = $lockToOwnInstitute ? '../Admin/InstituteAdminExams.php' : 'search.php';

require_once __DIR__ . '/../Lib/Institute.php';
require_once __DIR__ . '/../Lib/ExamType.php';

$examId  = filter_input(INPUT_GET,  'InfoId', FILTER_VALIDATE_INT)
        ?? filter_input(INPUT_POST, 'InfoId', FILTER_VALIDATE_INT);
$isNew   = (!$examId || $examId === 0);
$msg     = ''; $isErr = false;

$subjects   = Database::fetchAll("SELECT SubjectInfoId, SubjectName FROM subjectinfo ORDER BY SubjectName");
$institutes = Institute::listAll();   // for ExamScope = 'Institute' dropdown

/* Exam type suggestions (migration_v55.sql) — a few sensible starter values
   unioned with every distinct value already typed into an exam, so a custom
   type an admin added once (e.g. "CLAT") shows up as a one-click suggestion
   from then on instead of having to be retyped from memory. examCategory
   itself is a free-text field — this datalist is suggestions only, never a
   restriction (see the POST handler below). */
$examCategorySuggestions = ['NEET', 'JEE', 'GRE', 'GMAT', 'UPSC', 'Other'];
try {
    if (Database::hasColumn('examinfo', 'ExamCategory')) {
        $existingCats = Database::fetchAll(
            "SELECT DISTINCT ExamCategory FROM examinfo
              WHERE ExamCategory IS NOT NULL AND ExamCategory <> '' ORDER BY ExamCategory");
        foreach ($existingCats as $row) { $examCategorySuggestions[] = $row['ExamCategory']; }
        $examCategorySuggestions = array_values(array_unique($examCategorySuggestions));
        sort($examCategorySuggestions);
    }
} catch (Exception $e) { /* migration_v55 not yet run */ }

/* Country suggestions (migration_v64.sql) — same "curated + whatever's
   already in use" pattern as $examCategorySuggestions above, via
   Lib/ExamType.php (shared with the Country filter on exam/search.php).
   examCountry itself is a free-text field — suggestions only, never a
   restriction. [] (and the field hides) if migration_v64 hasn't run yet. */
$examCountrySuggestions = ExamType::allCountryValues();

/* Grades, with GroupId so the Group picker below can filter them client-side.
   Falls back gracefully if migration_v44 (gradeinfo.GroupId) hasn't run yet. */
try {
    $grades = Database::fetchAll("SELECT GradeInfoId, GradeName, GroupId FROM gradeinfo ORDER BY GradeName");
} catch (Exception $e) {
    $grades = Database::fetchAll("SELECT GradeInfoId, GradeName, NULL AS GroupId FROM gradeinfo ORDER BY GradeName");
}
try {
    $groups = Database::fetchAll("SELECT GroupId, GroupName FROM groupinfo WHERE Active = 'Y' ORDER BY SortOrder, GroupName");
} catch (Exception $e) {
    $groups = [];
}
$gradeGroupById = array_column($grades, 'GroupId', 'GradeInfoId');

// Load existing exam for editing
$exam = ['ExamName'=>'','ExamCategory'=>'','ExamCountry'=>'','GradeInfoId'=>0,'SubjectInfoId'=>0,'NumOfQuestions'=>10,'MinPassing'=>50,
         'TimeAlloted'=>30,'proctor_lock'=>0,'ExamScope'=>'All','ExamInstituteId'=>null,'ExamFreeFor'=>'None','IsActive'=>'Y','MaxAttempts'=>5,
         'MarkingScheme'=>'Dynamic','TotalMarks'=>100,'MarksPerQuestion'=>null,'NegativeMarks'=>0,'ExamFee'=>0,'ExamDiscountPct'=>0,
         'IsQuestionBank'=>'N'];
if (!$isNew) {
    $row = Database::fetchOne("SELECT * FROM examinfo WHERE ExamInfoId = ? LIMIT 1", [$examId]);
    if (!$row) { die('Exam not found.'); }
    if (($row['IsDeleted'] ?? 'N') === 'Y') {
        header('Location: trash.php?msg=' . urlencode('That exam has been deleted. Restore it from Trash before editing.'));
        exit;
    }
    $exam = $row;
    // Institute-admin ownership check — never let an institute admin open
    // (view or edit) an exam belonging to a different institute, or one with
    // no institute at all (ExamScope='All').
    if ($lockToOwnInstitute && (int)($exam['ExamInstituteId'] ?? 0) !== $myInstId) {
        header('Location: ' . $backToExamsUrl); exit;
    }
}
// A brand-new exam being created by an institute admin is always locked to
// their own institute — same reasoning as the ownership check just above.
if ($isNew && $lockToOwnInstitute) {
    $exam['ExamScope']       = 'Institute';
    $exam['ExamInstituteId'] = $myInstId;
}

/* ── Handle soft delete ─────────────────────────────────────────────────── */
if (!$isNew && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btnDelete'])) {
    Auth::validateCsrf();
    try {
        Database::execute(
            "UPDATE examinfo SET IsDeleted='Y', DeletedAt=NOW(), DeletedBy=? WHERE ExamInfoId=?",
            [Auth::currentUser() ?: 'admin', $examId]
        );
        try {
            Database::execute(
                "INSERT INTO exam_changelog (ExamInfoId,ExamName,Action,ActionBy,Details) VALUES (?,?,?,?,?)",
                [$examId, $exam['ExamName'] ?? '', 'DELETE', Auth::currentUser(), 'Soft-deleted from Edit Exam page']
            );
        } catch (Exception $eLog) {}
        header('Location: ' . $backToExamsUrl . '?deleted=1'); exit;
    } catch (Exception $e) {
        $msg = 'Could not delete this exam — the soft-delete feature needs migrations/migration_v43.sql run against the database.';
        $isErr = true;
    }
}

/** Format a marks value for an <input value=""> — trims trailing zeros, '' when unset. */
function fmtMarks($v): string {
    if ($v === null || $v === '') return '';
    return rtrim(rtrim(number_format((float)$v, 2), '0'), '.');
}

// Load per-exam autosave settings (falls back to defaults if table/row missing)
$examSettings = ['AutosaveIntervalSec' => 60, 'AutosaveDebounceMs' => 3000];
if (!$isNew && $examId) {
    try {
        $esRow = Database::fetchOne(
            "SELECT AutosaveIntervalSec, AutosaveDebounceMs FROM exam_settings WHERE ExamInfoId = ? LIMIT 1",
            [$examId]);
        if ($esRow) $examSettings = $esRow;
    } catch (Exception $e) { /* migration_v18.sql not yet run */ }
}

// Load exam pattern (migration_v54) — multi-subject sections, e.g. a NEET
// paper's Physics/Chemistry/Botany/Zoology split. $isMultiSubject / $examSections
// here are the GET/display defaults; the POST handler below overwrites both
// from the submitted form (so a validation-failure redisplay shows what the
// admin just typed, and a successful save reflects the new state, without a
// second DB round-trip — same pattern as $examSettings above).
$isMultiSubject = (($exam['IsMultiSubject'] ?? 'N') === 'Y');
$examSections   = [];
if (!$isNew && $examId) {
    try {
        $examSections = Database::fetchAll(
            "SELECT es.SubjectInfoId, es.NumOfQuestions, sub.SubjectName
               FROM exam_sections es
          LEFT JOIN subjectinfo sub ON sub.SubjectInfoId = es.SubjectInfoId
              WHERE es.ExamInfoId = ?
              ORDER BY es.SortOrder, es.ExamSectionId", [$examId]);
        $examSections = array_map(fn($s) => [
            'subjectId'    => (int)$s['SubjectInfoId'],
            'numQuestions' => (int)$s['NumOfQuestions'],
            'label'        => $s['SubjectName'] ?? '',
        ], $examSections);
    } catch (Exception $e) {
        $isMultiSubject = false; // migration_v54 not yet run
    }
}

// Handle save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btnSave'])) {
    Auth::validateCsrf();
    $name       = trim($_POST['txtExamName']    ?? '');
    // Free text, not a fixed list — admins can introduce new exam types
    // (SAT, CAT, CLAT, GATE, ...) just by typing one; matches
    // migrations/migration_v55.sql's design (examinfo.ExamCategory is a
    // plain VARCHAR, not an ENUM, precisely so no migration is ever needed
    // to add a type). Column is VARCHAR(50), so trim to fit.
    $examCategory = trim(substr(trim($_POST['examCategory'] ?? ''), 0, 50));
    // Same free-text convention as examCategory (migration_v64.sql —
    // examinfo.ExamCountry is a plain VARCHAR, not an ENUM/FK to a country
    // table, so any country name can be typed without another migration).
    $examCountry  = trim(substr(trim($_POST['examCountry']  ?? ''), 0, 50));
    $gradeId    = (int)($_POST['txtGrade']      ?? 0);
    $subjId     = (int)($_POST['txtSubject']    ?? 0);
    $numQ       = (int)($_POST['txtNumQ']       ?? 0);
    $passing    = (int)($_POST['txtPassing']    ?? 0);
    $time       = (int)($_POST['txtTime']       ?? 30);
    $markingScheme     = ($_POST['markingScheme'] ?? 'Dynamic') === 'Fixed' ? 'Fixed' : 'Dynamic';
    $totalMarks        = max(0, (float)($_POST['txtTotalMarks']    ?? 100));
    $marksPerQuestion  = max(0, (float)($_POST['txtMarksPerQ']     ?? 0));
    $negativeMarks     = max(0, (float)($_POST['txtNegativeMarks'] ?? 0));
    $proctorLock       = isset($_POST['chkProctorLock']) ? 1 : 0;
    $autosaveInterval  = max(0,    (int)($_POST['txtAutosaveInterval'] ?? 60));
    $autosaveDebounce  = max(500, (int)($_POST['txtAutosaveDebounce'] ?? 3000));
    $examScope         = in_array($_POST['examScope'] ?? '', ['All','Institute']) ? $_POST['examScope'] : 'All';
    $examInstituteId   = ($examScope === 'Institute') ? ((int)($_POST['examInstituteId'] ?? 0) ?: null) : null;
    $examFreeFor       = in_array($_POST['examFreeFor'] ?? '', ['None','All','Institute']) ? $_POST['examFreeFor'] : 'None';
    $examFee           = max(0.0, (float)str_replace(',', '', $_POST['txtExamFee'] ?? '0'));
    $examDiscountPct   = min(100.0, max(0.0, (float)($_POST['txtExamDiscountPct'] ?? '0')));
    $isActive          = ($_POST['isActive'] ?? 'Y') === 'N' ? 'N' : 'Y';
    $maxAttempts       = max(0, (int)($_POST['txtMaxAttempts'] ?? 5)); // 0 = unlimited
    // Question Bank flag (migration_v65) — Y = pure question pool, never
    // directly attemptable/assignable/self-enrollable, only a source for
    // exam/question-bank-builder.php. Saved as its own statement below,
    // same reasoning/pattern as IsMultiSubject just further down.
    $isQuestionBank    = isset($_POST['isQuestionBank']) ? 'Y' : 'N';
    // If FreeFor=Institute, reuse examInstituteId (must be set)
    if ($examFreeFor === 'Institute' && !$examInstituteId) {
        // Try to use explicitly chosen institute for free override
        $freeInstId = (int)($_POST['freeInstituteId'] ?? 0) ?: null;
        if ($freeInstId) $examInstituteId = $freeInstId;
    }
    // Institute admins can only ever create/edit exams scoped to their own
    // institute, and can never mark one as a Question Bank — enforced
    // server-side regardless of what a crafted POST might submit, not just
    // hidden in the UI below.
    if ($lockToOwnInstitute) {
        $examScope       = 'Institute';
        $examInstituteId = $myInstId;
        $isQuestionBank  = 'N';
    }

    /* ── Exam Pattern (migration_v54) — multi-subject sections ────────────
       e.g. a NEET paper: Physics/Chemistry/Botany/Zoology, 45 questions each.
       $examSections drives exam_sections after the exam itself is saved
       below; kept as its own statement (not threaded into the INSERT/UPDATE
       schema-fallback cascade above) so it doesn't need a copy in every one
       of that cascade's tiers. Duplicate subjects in the submitted rows
       collapse to one (last one submitted wins). */
    $isMultiSubject = isset($_POST['isMultiSubject']);
    $examSections   = [];
    if ($isMultiSubject) {
        $secSubjIds = $_POST['secSubjectId'] ?? [];
        $secNumQs   = $_POST['secNumQ']      ?? [];
        $bySubject  = [];
        foreach ($secSubjIds as $idx => $sid) {
            $sid = (int)$sid;
            $n   = (int)($secNumQs[$idx] ?? 0);
            if ($sid > 0 && $n > 0) { $bySubject[$sid] = ['subjectId' => $sid, 'numQuestions' => $n]; }
        }
        $examSections = array_values($bySubject);
        // The exam's total question count becomes the sum of its sections —
        // keeps NumOfQuestions (still read directly by exam listings/reports)
        // in sync with the pattern instead of going stale at whatever was
        // typed into the "Number of Questions" field above.
        if ($examSections) { $numQ = array_sum(array_column($examSections, 'numQuestions')); }
    }

    // Validation
    $errors = [];
    if ($name === '')      $errors[] = 'Exam name is required.';
    if ($gradeId <= 0)     $errors[] = 'Please select a grade.';
    if (!$isMultiSubject && $subjId <= 0) $errors[] = 'Please select a subject.';
    if ($numQ    <= 0)     $errors[] = 'Number of questions must be greater than 0.';
    if ($passing <= 0 || $passing > 100) $errors[] = 'Pass mark must be between 1 and 100 (%).';
    if ($time    < 5)      $errors[] = 'Time allotted must be at least 5 minutes.';
    if ($markingScheme === 'Dynamic' && $totalMarks <= 0) $errors[] = 'Total marks must be greater than 0.';
    if ($markingScheme === 'Fixed'   && $marksPerQuestion <= 0) $errors[] = 'Marks per correct answer must be greater than 0 for Fixed marking.';
    if ($negativeMarks < 0) $errors[] = 'Negative marks cannot be negative.';
    if ($examScope === 'Institute' && !$examInstituteId) $errors[] = 'Please select an institute for institute-scoped exams.';
    if ($examFreeFor === 'Institute' && !$examInstituteId) $errors[] = 'Please select an institute for institute-level free override.';
    if ($isMultiSubject && !$examSections) $errors[] = 'Add at least one subject section, or turn off multi-subject exam.';

    // A multi-subject exam has no single subject of its own — its sections
    // (saved to exam_sections below) carry that instead. $subjects (loaded
    // at the top of this file) still needs *a* valid SubjectInfoId to bind
    // when the DB column is NOT NULL on older schemas — the exam's first
    // section subject is a reasonable, harmless placeholder for that case.
    if ($isMultiSubject && $examSections) {
        $subjId = $examSections[0]['subjectId'];
    }

    if ($errors) {
        $msg = implode(' ', $errors); $isErr = true;
    } else {
        $action = $isNew ? 'CREATE' : 'EDIT';
        if ($isNew) {
            // Try with v51 (exam-level ExamFee/ExamDiscountPct) first, fall back to the
            // pre-existing cascade untouched if those columns don't exist yet on this environment.
            try {
                Database::execute(
                    "INSERT INTO examinfo (ExamName,GradeInfoId,SubjectInfoId,NumOfQuestions,MinPassing,TimeAlloted,proctor_lock,ExamScope,ExamInstituteId,ExamFreeFor,IsActive,MaxAttempts,MarkingScheme,TotalMarks,MarksPerQuestion,NegativeMarks,ExamFee,ExamDiscountPct)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                    [$name,$gradeId,$subjId,$numQ,$passing,$time,$proctorLock,$examScope,$examInstituteId,$examFreeFor,$isActive,$maxAttempts,$markingScheme,$totalMarks,$marksPerQuestion ?: null,$negativeMarks,$examFee,$examDiscountPct]);
            } catch (Exception $eFeeOverride) {
            // Try with v38 (marking scheme) next, fall back gracefully through older migrations
            try {
                Database::execute(
                    "INSERT INTO examinfo (ExamName,GradeInfoId,SubjectInfoId,NumOfQuestions,MinPassing,TimeAlloted,proctor_lock,ExamScope,ExamInstituteId,ExamFreeFor,IsActive,MaxAttempts,MarkingScheme,TotalMarks,MarksPerQuestion,NegativeMarks)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                    [$name,$gradeId,$subjId,$numQ,$passing,$time,$proctorLock,$examScope,$examInstituteId,$examFreeFor,$isActive,$maxAttempts,$markingScheme,$totalMarks,$marksPerQuestion ?: null,$negativeMarks]);
            } catch (Exception $eMarking) {
            try {
                Database::execute(
                    "INSERT INTO examinfo (ExamName,GradeInfoId,SubjectInfoId,NumOfQuestions,MinPassing,TimeAlloted,proctor_lock,ExamScope,ExamInstituteId,ExamFreeFor,IsActive,MaxAttempts)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
                    [$name,$gradeId,$subjId,$numQ,$passing,$time,$proctorLock,$examScope,$examInstituteId,$examFreeFor,$isActive,$maxAttempts]);
            } catch (Exception $e0) {
            try {
                Database::execute(
                    "INSERT INTO examinfo (ExamName,GradeInfoId,SubjectInfoId,NumOfQuestions,MinPassing,TimeAlloted,proctor_lock,ExamScope,ExamInstituteId,ExamFreeFor,IsActive)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?)",
                    [$name,$gradeId,$subjId,$numQ,$passing,$time,$proctorLock,$examScope,$examInstituteId,$examFreeFor,$isActive]);
            } catch (Exception $e) {
                try {
                    Database::execute(
                        "INSERT INTO examinfo (ExamName,GradeInfoId,SubjectInfoId,NumOfQuestions,MinPassing,TimeAlloted,proctor_lock,ExamScope,ExamInstituteId,ExamFreeFor)
                         VALUES (?,?,?,?,?,?,?,?,?,?)",
                        [$name,$gradeId,$subjId,$numQ,$passing,$time,$proctorLock,$examScope,$examInstituteId,$examFreeFor]);
                } catch (Exception $e2) {
                    try {
                        Database::execute(
                            "INSERT INTO examinfo (ExamName,GradeInfoId,SubjectInfoId,NumOfQuestions,MinPassing,TimeAlloted,proctor_lock,ExamScope,ExamInstituteId)
                             VALUES (?,?,?,?,?,?,?,?,?)",
                            [$name,$gradeId,$subjId,$numQ,$passing,$time,$proctorLock,$examScope,$examInstituteId]);
                    } catch (Exception $e3) {
                        Database::execute(
                            "INSERT INTO examinfo (ExamName,GradeInfoId,SubjectInfoId,NumOfQuestions,MinPassing,TimeAlloted,proctor_lock)
                             VALUES (?,?,?,?,?,?,?)",
                            [$name,$gradeId,$subjId,$numQ,$passing,$time,$proctorLock]);
                    }
                }
            }
            }
            }
            } // close outer ExamFee/ExamDiscountPct try/catch
            $examId = (int)Database::lastInsertId();
            $msg = "Exam created successfully.";
        } else {
            // Try with v51 (exam-level ExamFee/ExamDiscountPct) first, fall back to the
            // pre-existing cascade untouched if those columns don't exist yet on this environment.
            try {
                Database::execute(
                    "UPDATE examinfo SET ExamName=?,GradeInfoId=?,SubjectInfoId=?,NumOfQuestions=?,MinPassing=?,TimeAlloted=?,proctor_lock=?,ExamScope=?,ExamInstituteId=?,ExamFreeFor=?,IsActive=?,MaxAttempts=?,MarkingScheme=?,TotalMarks=?,MarksPerQuestion=?,NegativeMarks=?,ExamFee=?,ExamDiscountPct=?
                      WHERE ExamInfoId=?",
                    [$name,$gradeId,$subjId,$numQ,$passing,$time,$proctorLock,$examScope,$examInstituteId,$examFreeFor,$isActive,$maxAttempts,$markingScheme,$totalMarks,$marksPerQuestion ?: null,$negativeMarks,$examFee,$examDiscountPct,$examId]);
            } catch (Exception $eFeeOverride) {
            try {
                Database::execute(
                    "UPDATE examinfo SET ExamName=?,GradeInfoId=?,SubjectInfoId=?,NumOfQuestions=?,MinPassing=?,TimeAlloted=?,proctor_lock=?,ExamScope=?,ExamInstituteId=?,ExamFreeFor=?,IsActive=?,MaxAttempts=?,MarkingScheme=?,TotalMarks=?,MarksPerQuestion=?,NegativeMarks=?
                      WHERE ExamInfoId=?",
                    [$name,$gradeId,$subjId,$numQ,$passing,$time,$proctorLock,$examScope,$examInstituteId,$examFreeFor,$isActive,$maxAttempts,$markingScheme,$totalMarks,$marksPerQuestion ?: null,$negativeMarks,$examId]);
            } catch (Exception $eMarking) {
            try {
                Database::execute(
                    "UPDATE examinfo SET ExamName=?,GradeInfoId=?,SubjectInfoId=?,NumOfQuestions=?,MinPassing=?,TimeAlloted=?,proctor_lock=?,ExamScope=?,ExamInstituteId=?,ExamFreeFor=?,IsActive=?,MaxAttempts=?
                      WHERE ExamInfoId=?",
                    [$name,$gradeId,$subjId,$numQ,$passing,$time,$proctorLock,$examScope,$examInstituteId,$examFreeFor,$isActive,$maxAttempts,$examId]);
            } catch (Exception $e0) {
            try {
                Database::execute(
                    "UPDATE examinfo SET ExamName=?,GradeInfoId=?,SubjectInfoId=?,NumOfQuestions=?,MinPassing=?,TimeAlloted=?,proctor_lock=?,ExamScope=?,ExamInstituteId=?,ExamFreeFor=?,IsActive=?
                      WHERE ExamInfoId=?",
                    [$name,$gradeId,$subjId,$numQ,$passing,$time,$proctorLock,$examScope,$examInstituteId,$examFreeFor,$isActive,$examId]);
            } catch (Exception $e) {
                try {
                    Database::execute(
                        "UPDATE examinfo SET ExamName=?,GradeInfoId=?,SubjectInfoId=?,NumOfQuestions=?,MinPassing=?,TimeAlloted=?,proctor_lock=?,ExamScope=?,ExamInstituteId=?,ExamFreeFor=?
                          WHERE ExamInfoId=?",
                        [$name,$gradeId,$subjId,$numQ,$passing,$time,$proctorLock,$examScope,$examInstituteId,$examFreeFor,$examId]);
                } catch (Exception $e2) {
                    try {
                        Database::execute(
                            "UPDATE examinfo SET ExamName=?,GradeInfoId=?,SubjectInfoId=?,NumOfQuestions=?,MinPassing=?,TimeAlloted=?,proctor_lock=?,ExamScope=?,ExamInstituteId=?
                              WHERE ExamInfoId=?",
                            [$name,$gradeId,$subjId,$numQ,$passing,$time,$proctorLock,$examScope,$examInstituteId,$examId]);
                    } catch (Exception $e3) {
                        Database::execute(
                            "UPDATE examinfo SET ExamName=?,GradeInfoId=?,SubjectInfoId=?,NumOfQuestions=?,MinPassing=?,TimeAlloted=?,proctor_lock=?
                              WHERE ExamInfoId=?",
                            [$name,$gradeId,$subjId,$numQ,$passing,$time,$proctorLock,$examId]);
                    }
                }
            }
            }
            }
            } // close outer ExamFee/ExamDiscountPct try/catch
            $msg = "Exam updated successfully.";
        }
        // Upsert autosave settings
        try {
            Database::execute(
                "INSERT INTO exam_settings (ExamInfoId, AutosaveIntervalSec, AutosaveDebounceMs)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                     AutosaveIntervalSec = VALUES(AutosaveIntervalSec),
                     AutosaveDebounceMs  = VALUES(AutosaveDebounceMs)",
                [$examId, $autosaveInterval, $autosaveDebounce]);
            $examSettings = ['AutosaveIntervalSec' => $autosaveInterval, 'AutosaveDebounceMs' => $autosaveDebounce];
        } catch (Exception $e) { /* migration_v18.sql not yet run — silently skip */ }

        // Exam Pattern (migration_v54) — multi-subject sections. Its own
        // statement, deliberately separate from the examinfo INSERT/UPDATE
        // schema-fallback cascade above (so it doesn't need a copy in each
        // of that cascade's tiers). Sections are fully replaced on every
        // save — simpler and safer than diffing against what was there
        // before, and this form is the only place exam_sections is ever
        // written from.
        try {
            Database::execute("UPDATE examinfo SET IsMultiSubject=? WHERE ExamInfoId=?",
                [$isMultiSubject ? 'Y' : 'N', $examId]);
            Database::execute("DELETE FROM exam_sections WHERE ExamInfoId=?", [$examId]);
            if ($isMultiSubject) {
                $sortOrder = 0;
                foreach ($examSections as $sec) {
                    Database::execute(
                        "INSERT INTO exam_sections (ExamInfoId, SubjectInfoId, NumOfQuestions, SortOrder)
                         VALUES (?, ?, ?, ?)",
                        [$examId, $sec['subjectId'], $sec['numQuestions'], $sortOrder++]);
                }
            }
        } catch (Exception $e) { /* migration_v54 not yet run — silently skip, single-subject behaviour stands */ }

        // Question Bank flag (migration_v65) — own statement, same pattern as
        // IsMultiSubject/ExamCategory/ExamCountry above so it degrades
        // gracefully on a database that hasn't run migration_v65 yet.
        try {
            Database::execute("UPDATE examinfo SET IsQuestionBank=? WHERE ExamInfoId=?",
                [$isQuestionBank, $examId]);
        } catch (Exception $e) { /* migration_v65 not yet run — silently skip */ }

        // Exam type/category (migration_v55) — NEET/JEE/GRE/GMAT/UPSC/Other,
        // independent of Subject/Grade, used by browse-subjects.php's "By
        // Type" tab. Its own statement for the same reason exam_sections is:
        // one more optional column shouldn't need a copy in every tier of
        // the examinfo INSERT/UPDATE schema-fallback cascade above.
        try {
            Database::execute("UPDATE examinfo SET ExamCategory=? WHERE ExamInfoId=?",
                [$examCategory !== '' ? $examCategory : null, $examId]);
        } catch (Exception $e) { /* migration_v55 not yet run — silently skip */ }

        // Exam country (migration_v64) — independent of Type/ExamCategory,
        // same "own statement" reasoning as ExamCategory just above: one
        // more optional column that shouldn't need a copy in every tier of
        // the examinfo INSERT/UPDATE schema-fallback cascade further up.
        try {
            Database::execute("UPDATE examinfo SET ExamCountry=? WHERE ExamInfoId=?",
                [$examCountry !== '' ? $examCountry : null, $examId]);
        } catch (Exception $e) { /* migration_v64 not yet run — silently skip */ }

        // Log the change
        try {
            Database::execute(
                "INSERT INTO exam_changelog (ExamInfoId,ExamName,Action,ActionBy,Details)
                 VALUES (?,?,?,?,?)",
                [$examId, $name, $action, Auth::currentUser(),
                 "Category:" . ($examCategory !== '' ? $examCategory : 'none') . " Country:" . ($examCountry !== '' ? $examCountry : 'none') . " Grade:{$gradeId} Subject:{$subjId} Qs:{$numQ} Pass:{$passing} Time:{$time}min Lock:{$proctorLock} Scope:{$examScope} InstId:" . ($examInstituteId ?? 'null') . " AutosaveInterval:{$autosaveInterval}s Debounce:{$autosaveDebounce}ms MaxAttempts:{$maxAttempts} Marking:{$markingScheme} Total:{$totalMarks} PerQ:{$marksPerQuestion} Neg:{$negativeMarks} Fee:{$examFee} DiscountPct:{$examDiscountPct} QuestionBank:{$isQuestionBank}"]);
        } catch (Exception $e) {}
        // Reload exam data
        $exam = Database::fetchOne("SELECT * FROM examinfo WHERE ExamInfoId = ? LIMIT 1", [$examId]);
        $isNew = false; $isErr = false;
    }
}

$pageTitle = $isNew ? 'Add Exam' : 'Edit Exam';
include __DIR__ . '/../includes/header.php';
?>
<div class="card" style="max-width:640px;margin:0 auto;">
  <div class="card-header">
    <?php echo $isNew ? '&#10010; Add New Exam' : '&#9998; Edit Exam'; ?>
  </div>
  <div class="card-body">
    <?php if ($msg !== ''): ?>
      <div class="alert <?php echo $isErr ? 'alert-error' : 'alert-success'; ?>">
        <?php echo htmlspecialchars($msg); ?>
        <?php if (!$isErr && !$isNew): ?>
          &nbsp;<a href="<?php echo htmlspecialchars($backToExamsUrl); ?>">Back to Exam List</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <form method="post" action="manage.php?InfoId=<?php echo (int)$examId; ?>" onsubmit="return validateForm()">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken()); ?>">
      <input type="hidden" name="InfoId"     value="<?php echo (int)$examId; ?>">

      <div class="form-group">
        <label for="txtExamName">Exam Name <span style="color:#e53e3e">*</span></label>
        <input type="text" id="txtExamName" name="txtExamName" class="form-control" required maxlength="200"
               value="<?php echo htmlspecialchars($exam['ExamName']); ?>">
      </div>
      <div class="form-group" style="max-width:260px;">
        <label for="examCategory">
          Exam Type <span style="font-weight:400;font-size:.78rem;color:#718096;">(for Browse &amp; Enroll's "By Type" tab)</span>
        </label>
        <?php $curCategory = $exam['ExamCategory'] ?? ''; ?>
        <input type="text" id="examCategory" name="examCategory" class="form-control" maxlength="50"
               list="examCategoryList" placeholder="e.g. NEET, JEE, GATE, CLAT…"
               value="<?php echo htmlspecialchars($curCategory); ?>"
               onchange="applyMarkingSuggestionForCategory()" onblur="applyMarkingSuggestionForCategory()">
        <datalist id="examCategoryList">
          <?php foreach ($examCategorySuggestions as $cat): ?>
            <option value="<?php echo htmlspecialchars($cat); ?>">
          <?php endforeach; ?>
        </datalist>
        <p style="font-size:.75rem;color:#94a3b8;margin:4px 0 0;">
          Type any value — pick a suggestion or introduce a new type; leave blank for Uncategorized.
        </p>
      </div>
      <?php if (!empty($examCountrySuggestions)): ?>
      <div class="form-group" style="max-width:260px;">
        <label for="examCountry">
          Country <span style="font-weight:400;font-size:.78rem;color:#718096;">(flag &amp; Country filter on Search)</span>
        </label>
        <?php $curCountry = $exam['ExamCountry'] ?? ''; ?>
        <input type="text" id="examCountry" name="examCountry" class="form-control" maxlength="50"
               list="examCountryList" placeholder="e.g. India, USA…"
               value="<?php echo htmlspecialchars($curCountry); ?>">
        <datalist id="examCountryList">
          <?php foreach ($examCountrySuggestions as $ctry): ?>
            <option value="<?php echo htmlspecialchars($ctry); ?>">
          <?php endforeach; ?>
        </datalist>
        <p style="font-size:.75rem;color:#94a3b8;margin:4px 0 0;">
          Independent of Type above — leave blank to show a flag based on Type instead (NEET/JEE/UPSC = India, GRE/GMAT = USA), where applicable.
        </p>
      </div>
      <?php endif; ?>
      <div style="display:flex;gap:16px;flex-wrap:wrap;">
        <?php if (!empty($groups)): ?>
        <div class="form-group" style="flex:1;min-width:180px;">
          <label for="txtGroupFilter" style="display:flex;justify-content:space-between;align-items:center;">
            <span>Group <span style="font-weight:400;font-size:.78rem;color:#718096;">(narrows Grade below)</span></span>
            <a href="groups.php" style="font-size:.73rem;color:#4f46e5;font-weight:500;"
               title="Manage groups">&#127959; Manage</a>
          </label>
          <select id="txtGroupFilter" class="form-control" onchange="filterGradesByGroup()">
            <option value="0">— All Groups —</option>
            <?php $curGroupId = (int)($gradeGroupById[(int)$exam['GradeInfoId']] ?? 0); ?>
            <?php foreach ($groups as $gr): ?>
              <option value="<?php echo (int)$gr['GroupId']; ?>"
                <?php echo ($curGroupId === (int)$gr['GroupId']) ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($gr['GroupName']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
        <div class="form-group" style="flex:1;min-width:180px;">
          <label for="txtGrade" style="display:flex;justify-content:space-between;align-items:center;">
            <span>Grade <span style="color:#e53e3e">*</span></span>
            <a href="grades.php" style="font-size:.73rem;color:#4f46e5;font-weight:500;"
               title="Manage grades">&#127891; Manage</a>
          </label>
          <select id="txtGrade" name="txtGrade" class="form-control" required>
            <option value="0">-- Select Grade --</option>
            <?php foreach ($grades as $g): ?>
              <option value="<?php echo (int)$g['GradeInfoId']; ?>"
                data-group="<?php echo (int)($g['GroupId'] ?? 0); ?>"
                <?php echo ((int)$exam['GradeInfoId']===(int)$g['GradeInfoId'])?'selected':''; ?>>
                <?php echo htmlspecialchars($g['GradeName']); ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (empty($grades)): ?>
            <p style="font-size:.75rem;color:#e53e3e;margin:4px 0 0;">
              No grades found. <a href="grades.php">Add a grade</a> first.
            </p>
          <?php endif; ?>
        </div>
        <div class="form-group" style="flex:1;min-width:180px;">
          <label for="txtSubject" style="display:flex;justify-content:space-between;align-items:center;">
            <span>Subject <span style="color:#e53e3e">*</span></span>
            <a href="subjects.php" style="font-size:.73rem;color:#4f46e5;font-weight:500;"
               title="Manage subjects">&#128218; Manage</a>
          </label>
          <select id="txtSubject" name="txtSubject" class="form-control" required>
            <option value="0">-- Select Subject --</option>
            <?php foreach ($subjects as $s): ?>
              <option value="<?php echo (int)$s['SubjectInfoId']; ?>"
                <?php echo ((int)$exam['SubjectInfoId']===(int)$s['SubjectInfoId'])?'selected':''; ?>>
                <?php echo htmlspecialchars($s['SubjectName']); ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (empty($subjects)): ?>
            <p style="font-size:.75rem;color:#e53e3e;margin:4px 0 0;">
              No subjects found. <a href="subjects.php">Add a subject</a> first.
            </p>
          <?php endif; ?>
        </div>
      </div>
      <div style="display:flex;gap:16px;flex-wrap:wrap;">
        <div class="form-group" style="flex:1;min-width:130px;">
          <label for="txtNumQ">Number of Questions <span style="color:#e53e3e">*</span></label>
          <input type="number" id="txtNumQ" name="txtNumQ" class="form-control" required min="1" max="200"
                 value="<?php echo (int)$exam['NumOfQuestions']; ?>" oninput="updateMarkingPreview()">
        </div>
        <div class="form-group" style="flex:1;min-width:130px;">
          <label for="txtPassing">Passing Marks (%) <span style="color:#e53e3e">*</span></label>
          <input type="number" id="txtPassing" name="txtPassing" class="form-control" required min="1" max="100"
                 value="<?php echo (int)$exam['MinPassing']; ?>">
        </div>
        <div class="form-group" style="flex:1;min-width:130px;">
          <label for="txtTime">Time Allotted (min) <span style="color:#e53e3e">*</span></label>
          <input type="number" id="txtTime" name="txtTime" class="form-control" required min="5" max="300"
                 value="<?php echo (int)($exam['TimeAlloted'] ?? 30); ?>">
        </div>
      </div>

      <!-- ── Marking Scheme ───────────────────────────────────────────── -->
      <div class="form-group" style="background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;padding:14px 16px;margin-top:4px;">
        <div style="font-weight:700;color:#9a3412;margin-bottom:10px;">&#127919; Marking Scheme</div>
        <div style="font-size:.82rem;color:#b45309;margin-bottom:12px;">
          Choose how marks per correct answer are calculated, and optionally deduct marks for wrong answers.
        </div>
        <?php $curScheme = ($exam['MarkingScheme'] ?? 'Dynamic') === 'Fixed' ? 'Fixed' : 'Dynamic'; ?>
        <div style="display:flex;gap:24px;flex-wrap:wrap;margin-bottom:12px;">
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:600;">
            <input type="radio" name="markingScheme" value="Dynamic" id="markDynamic"
                   style="accent-color:#c2410c;"
                   <?php echo $curScheme === 'Dynamic' ? 'checked' : ''; ?>
                   onchange="toggleMarkingScheme()">
            <span>&#128202; Dynamic
              <span style="font-weight:400;font-size:.78rem;color:#92400e;">(Total marks &divide; Number of questions)</span></span>
          </label>
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:600;">
            <input type="radio" name="markingScheme" value="Fixed" id="markFixed"
                   style="accent-color:#c2410c;"
                   <?php echo $curScheme === 'Fixed' ? 'checked' : ''; ?>
                   onchange="toggleMarkingScheme()">
            <span>&#128274; Fixed
              <span style="font-weight:400;font-size:.78rem;color:#92400e;">(set marks per correct answer directly)</span></span>
          </label>
        </div>
        <div style="display:flex;gap:16px;flex-wrap:wrap;">
          <div class="form-group" id="totalMarksWrap" style="flex:1;min-width:160px;margin:0;
               display:<?php echo $curScheme === 'Fixed' ? 'none' : 'block'; ?>;">
            <label for="txtTotalMarks">Total Marks</label>
            <input type="number" id="txtTotalMarks" name="txtTotalMarks" class="form-control" min="1" max="100000" step="0.5"
                   value="<?php echo htmlspecialchars(fmtMarks($exam['TotalMarks'] ?? 100) ?: '100'); ?>"
                   oninput="updateMarkingPreview()">
          </div>
          <div class="form-group" id="marksPerQWrap" style="flex:1;min-width:160px;margin:0;
               display:<?php echo $curScheme === 'Fixed' ? 'block' : 'none'; ?>;">
            <label for="txtMarksPerQ">Marks per Correct Answer</label>
            <input type="number" id="txtMarksPerQ" name="txtMarksPerQ" class="form-control" min="0.5" max="1000" step="0.5"
                   value="<?php echo htmlspecialchars(fmtMarks($exam['MarksPerQuestion'] ?? null)); ?>"
                   oninput="updateMarkingPreview()">
          </div>
          <div class="form-group" style="flex:1;min-width:160px;margin:0;">
            <label for="txtNegativeMarks">Negative Marks (per wrong answer)</label>
            <input type="number" id="txtNegativeMarks" name="txtNegativeMarks" class="form-control" min="0" max="1000" step="0.5"
                   value="<?php echo htmlspecialchars(fmtMarks($exam['NegativeMarks'] ?? 0) ?: '0'); ?>"
                   oninput="updateMarkingPreview()">
            <small style="color:#6b7280;">0 = no negative marking</small>
          </div>
        </div>
        <div id="markingPreview"
             style="margin-top:12px;font-size:.85rem;font-weight:600;color:#9a3412;background:#ffedd5;padding:8px 12px;border-radius:6px;">
        </div>
      </div>

      <!-- ── Exam Pattern (migration_v54) — multi-subject sections ───────── -->
      <div class="form-group" style="background:#f0fdfa;border:1px solid #99f6e4;border-radius:8px;padding:14px 16px;margin-top:4px;">
        <div style="font-weight:700;color:#115e59;margin-bottom:10px;">
          &#129513; Exam Pattern
          <span style="font-size:.78rem;font-weight:400;color:#0f766e;">(multi-subject papers like NEET/JEE — Physics/Chemistry/Botany/Zoology, each with their own question count)</span>
        </div>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:600;margin-bottom:10px;">
          <input type="checkbox" name="isMultiSubject" id="chkMultiSubject" style="accent-color:#0d9488;"
                 <?php echo $isMultiSubject ? 'checked' : ''; ?> onchange="toggleMultiSubject()">
          <span>This exam has multiple subject sections</span>
        </label>

        <div id="sectionsWrap" style="display:<?php echo $isMultiSubject ? 'block' : 'none'; ?>;">
          <div id="sectionRows"></div>
          <button type="button" class="btn btn-secondary" style="margin-top:6px;" onclick="addSectionRow()">
            &#10010; Add Subject Section
          </button>
          <div style="margin-top:10px;font-size:.85rem;font-weight:600;color:#115e59;">
            Total questions across sections: <span id="sectionTotal">0</span>
            <span style="font-weight:400;color:#0f766e;"> — this replaces "Number of Questions" above for this exam.</span>
          </div>
        </div>
      </div>

      <!-- ── Exam Scope ─────────────────────────────────────────────────── -->
      <?php if ($lockToOwnInstitute): ?>
        <?php
          $myInstName = '';
          foreach ($institutes as $inst) { if ((int)$inst['InstituteId'] === $myInstId) { $myInstName = $inst['InstituteName']; break; } }
        ?>
        <div class="form-group" style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:14px 16px;margin-top:4px;">
          <div style="font-weight:700;color:#1e40af;margin-bottom:6px;">&#127982; Exam Scope</div>
          <div style="font-size:.85rem;color:#3b5fa0;">
            Institute-scoped exams you create are automatically restricted to
            <strong><?php echo htmlspecialchars($myInstName ?: 'your institute'); ?></strong> — only students
            registered there can see or be assigned this exam.
          </div>
          <input type="hidden" name="examScope" value="Institute">
          <input type="hidden" name="examInstituteId" value="<?php echo (int)$myInstId; ?>">
        </div>
      <?php else: ?>
      <div class="form-group" style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:14px 16px;margin-top:4px;">
        <div style="font-weight:700;color:#1e40af;margin-bottom:10px;">&#127982; Exam Scope</div>
        <div style="font-size:.82rem;color:#3b5fa0;margin-bottom:12px;">
          Controls who can see and take this exam.
          <strong>All Students</strong> makes it available to everyone.
          <strong>Institute Only</strong> restricts it to students registered under the selected institute.
        </div>
        <div style="display:flex;gap:24px;flex-wrap:wrap;margin-bottom:10px;">
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:600;">
            <input type="radio" name="examScope" value="All"
                   id="scopeAll" style="accent-color:#1e40af;"
                   <?php echo (($exam['ExamScope'] ?? 'All') === 'All') ? 'checked' : ''; ?>
                   onchange="toggleInstituteField()">
            <span>&#127760; All Students</span>
          </label>
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:600;">
            <input type="radio" name="examScope" value="Institute"
                   id="scopeInst" style="accent-color:#1e40af;"
                   <?php echo (($exam['ExamScope'] ?? 'All') === 'Institute') ? 'checked' : ''; ?>
                   onchange="toggleInstituteField()">
            <span>&#127982; Institute Only</span>
          </label>
        </div>
        <div id="instituteFieldWrap"
             style="display:<?php echo (($exam['ExamScope'] ?? 'All') === 'Institute') ? 'block' : 'none'; ?>;">
          <label for="examInstituteId" style="font-size:.85rem;font-weight:600;">
            Select Institute <span style="color:#e53e3e">*</span>
          </label>
          <?php if (empty($institutes)): ?>
            <p style="font-size:.82rem;color:#e53e3e;margin:4px 0 0;">
              No active institutes found.
              <a href="../Admin/ManageInstitutes.php?action=add">Add an institute</a> first.
            </p>
            <input type="hidden" name="examInstituteId" value="0">
          <?php else: ?>
          <select id="examInstituteId" name="examInstituteId" class="form-control" style="max-width:420px;">
            <option value="0">— Select Institute —</option>
            <?php foreach ($institutes as $inst):
              $sel = ((int)($exam['ExamInstituteId'] ?? 0) === (int)$inst['InstituteId']) ? 'selected' : ''; ?>
              <option value="<?php echo (int)$inst['InstituteId']; ?>" <?php echo $sel; ?>>
                <?php echo htmlspecialchars($inst['InstituteName']); ?>
                (<?php echo htmlspecialchars($inst['InstituteType']); ?>,
                 <?php echo htmlspecialchars($inst['State']); ?>)
              </option>
            <?php endforeach; ?>
          </select>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- ── Exam Status ──────────────────────────────────────────────── -->
      <div class="form-group" style="margin-top:4px;">
        <label style="font-weight:700;display:block;margin-bottom:8px;">📢 Exam Status</label>
        <div style="display:flex;gap:16px;flex-wrap:wrap;">
          <label style="display:flex;align-items:center;gap:7px;cursor:pointer;font-size:.92rem;">
            <input type="radio" name="isActive" value="Y" id="isActiveY"
                   <?php echo (($exam['IsActive'] ?? 'Y') === 'Y') ? 'checked' : ''; ?>>
            <span style="color:#059669;font-weight:600;">✅ Active</span>
            <span style="font-size:.78rem;color:#6b7280;">Visible to students</span>
          </label>
          <label style="display:flex;align-items:center;gap:7px;cursor:pointer;font-size:.92rem;">
            <input type="radio" name="isActive" value="N" id="isActiveN"
                   <?php echo (($exam['IsActive'] ?? 'Y') === 'N') ? 'checked' : ''; ?>>
            <span style="color:#dc2626;font-weight:600;">🚫 Inactive</span>
            <span style="font-size:.78rem;color:#6b7280;">Hidden from student exam list</span>
          </label>
        </div>
      </div>

      <!-- ── Question Bank (migration_v65) ───────────────────────────────
           A pool exams get BUILT from (see exam/question-bank-builder.php),
           not a live test itself — checking this hides it everywhere a
           student could otherwise reach it (write/assign/self-enroll),
           regardless of the Active/Inactive setting above. Full-admin only —
           institute admins create real, assignable exams, never banks; the
           server-side POST handler forces isQuestionBank='N' for them too. -->
      <?php if (!$lockToOwnInstitute): ?>
      <div class="form-group" style="background:#fef3c7;border:1px solid #fbbf24;border-radius:8px;padding:14px 16px;margin-top:4px;">
        <label style="display:flex;align-items:flex-start;gap:9px;cursor:pointer;">
          <input type="checkbox" id="isQuestionBank" name="isQuestionBank" style="margin-top:3px;transform:scale(1.15);"
                 <?php echo (($exam['IsQuestionBank'] ?? 'N') === 'Y') ? 'checked' : ''; ?>>
          <span>
            <span style="font-weight:700;color:#92400e;">&#128218; This is a Question Bank, not a live exam</span><br>
            <span style="font-size:.8rem;color:#92400e;">
              For a pool of questions (often hundreds/thousands) that other exams get built from via
              "Build from Question Bank" on the Questions page — never something a student writes, gets
              assigned, or self-enrolls into directly.
            </span>
          </span>
        </label>
      </div>
      <?php endif; ?>

      <!-- ── Attempt Limit ────────────────────────────────────────────── -->
      <div class="form-group" style="background:#eef2ff;border:1px solid #c7d2fe;border-radius:8px;padding:14px 16px;margin-top:4px;">
        <div style="font-weight:700;color:#3730a3;margin-bottom:10px;">&#128260; Attempt Limit</div>
        <div style="font-size:.82rem;color:#4338ca;margin-bottom:12px;">
          Max submissions a student may make for this exam. Default is <strong>5</strong>.
          Set to <strong>0</strong> for unlimited attempts.
          A per-student override (if set) always takes precedence over this value.
        </div>
        <div class="form-group" style="max-width:180px;margin:0;">
          <label for="txtMaxAttempts">Max Attempts</label>
          <input type="number" id="txtMaxAttempts" name="txtMaxAttempts" class="form-control" min="0" max="999"
                 value="<?php echo (int)($exam['MaxAttempts'] ?? 5); ?>">
        </div>
        <?php if (!$isNew): ?>
          <div style="margin-top:10px;">
            <a href="../Admin/ExamAttemptOverrides.php?examId=<?php echo (int)$examId; ?>"
               style="font-size:.8rem;color:#4338ca;font-weight:600;">
              &#128101; Manage per-student overrides &rarr;
            </a>
          </div>
        <?php endif; ?>
      </div>

      <!-- ── Pricing ───────────────────────────────────────────────────── -->
      <div class="form-group" style="background:#fefce8;border:1px solid #fde68a;border-radius:8px;padding:14px 16px;margin-top:4px;">
        <div style="font-weight:700;color:#92400e;margin-bottom:10px;">
          💰 Pricing <span style="font-size:.78rem;font-weight:400;color:#b45309;">(this exam's fee, discount, and coupon eligibility — subjects no longer carry a price)</span>
        </div>

        <div style="display:flex;gap:20px;flex-wrap:wrap;margin-bottom:10px;">
          <?php $curFreeFor = $exam['ExamFreeFor'] ?? 'None'; ?>

          <label style="display:flex;align-items:center;gap:7px;cursor:pointer;font-weight:600;font-size:.9rem;">
            <input type="radio" name="examFreeFor" value="None" id="freeForNone"
                   onchange="toggleFreeForField()"
                   <?php echo $curFreeFor === 'None' ? 'checked' : ''; ?>>
            <span>🔓 No override</span>
            <span style="font-size:.78rem;font-weight:400;color:#6b7280;">Normal fee logic applies</span>
          </label>

          <label style="display:flex;align-items:center;gap:7px;cursor:pointer;font-weight:600;font-size:.9rem;">
            <input type="radio" name="examFreeFor" value="All" id="freeForAll"
                   onchange="toggleFreeForField()"
                   <?php echo $curFreeFor === 'All' ? 'checked' : ''; ?>>
            <span style="color:#059669;">🌍 Free for ALL students</span>
          </label>

          <label style="display:flex;align-items:center;gap:7px;cursor:pointer;font-weight:600;font-size:.9rem;">
            <input type="radio" name="examFreeFor" value="Institute" id="freeForInst"
                   onchange="toggleFreeForField()"
                   <?php echo $curFreeFor === 'Institute' ? 'checked' : ''; ?>>
            <span style="color:#2563eb;">🏫 Free for specific Institute</span>
          </label>
        </div>

        <!-- Institute selector (shown only when FreeFor=Institute AND ExamScope != Institute) -->
        <div id="freeInstituteFieldWrap"
             style="display:<?php echo $curFreeFor === 'Institute' ? 'block' : 'none'; ?>;margin-top:6px;">
          <?php
            // If ExamScope=Institute, the institute is already set via examInstituteId — just show info
            $scopeIsInst = (($exam['ExamScope'] ?? 'All') === 'Institute');
          ?>
          <?php if ($scopeIsInst): ?>
            <div style="font-size:.84rem;color:#1d4ed8;background:#dbeafe;padding:8px 12px;border-radius:6px;">
              ℹ️ Uses the same institute selected in <strong>Exam Scope</strong> above.
              The exam is already restricted to that institute — making it free will remove
              the payment requirement for those students.
            </div>
          <?php elseif (empty($institutes)): ?>
            <p style="font-size:.82rem;color:#e53e3e;margin:4px 0 0;">
              No active institutes found.
              <a href="../Admin/ManageInstitutes.php?action=add">Add an institute</a> first.
            </p>
            <input type="hidden" name="freeInstituteId" value="0">
          <?php else: ?>
            <label for="freeInstituteId" style="font-size:.85rem;font-weight:600;">
              Select Institute to offer free access <span style="color:#e53e3e">*</span>
            </label>
            <select id="freeInstituteId" name="freeInstituteId" class="form-control" style="max-width:420px;margin-top:4px;">
              <option value="0">— Select Institute —</option>
              <?php foreach ($institutes as $inst):
                $curInstId = (int)($exam['ExamInstituteId'] ?? 0);
                $sel = ($curInstId === (int)$inst['InstituteId'] && $curFreeFor === 'Institute') ? 'selected' : ''; ?>
                <option value="<?php echo (int)$inst['InstituteId']; ?>" <?php echo $sel; ?>>
                  <?php echo htmlspecialchars($inst['InstituteName']); ?>
                  (<?php echo htmlspecialchars($inst['InstituteType']); ?>,
                   <?php echo htmlspecialchars($inst['State']); ?>)
                </option>
              <?php endforeach; ?>
            </select>
          <?php endif; ?>
        </div>

        <!-- Fee + default discount — only meaningful when No override (None) is
             selected above; a free-for-all/institute exam never charges anyone. -->
        <div class="form-group" style="margin-top:14px;padding-top:12px;border-top:1px dashed #fde68a;display:flex;gap:20px;flex-wrap:wrap;">
          <div>
            <label for="txtExamFee" style="font-size:.88rem;font-weight:700;color:#92400e;">
              Exam Fee (&#8377;)
            </label>
            <input type="number" id="txtExamFee" name="txtExamFee" class="form-control" min="0" step="0.01"
                   style="max-width:220px;"
                   value="<?php echo number_format((float)($exam['ExamFee'] ?? 0), 2, '.', ''); ?>"
                   placeholder="0.00">
            <small style="color:#92400e;display:block;margin-top:4px;max-width:320px;">
              Set to 0 for free access. Students must pay before attempting the exam.
            </small>
          </div>
          <div>
            <label for="txtExamDiscountPct" style="font-size:.88rem;font-weight:700;color:#92400e;">
              Default Discount (%)
            </label>
            <input type="number" id="txtExamDiscountPct" name="txtExamDiscountPct" class="form-control" min="0" max="100" step="0.01"
                   style="max-width:220px;"
                   value="<?php echo number_format((float)($exam['ExamDiscountPct'] ?? 0), 2, '.', ''); ?>"
                   placeholder="0.00">
            <small style="color:#92400e;display:block;margin-top:4px;max-width:320px;">
              Applied automatically before any coupon code. 0 = no discount.
            </small>
          </div>
        </div>

        <div style="margin-top:10px;font-size:.8rem;color:#92400e;background:#fef3c7;padding:8px 10px;border-radius:6px;">
          ⚠️ <strong>Priority order:</strong> Exam-level "free for" override → Assignment (admin-assigned students always get in) →
          Scholarship / institute-free waivers → institute discount (if any) → this exam's Discount % / coupon → Exam Fee above.
          Coupons for this exam are managed under <a href="../Admin/ManageCoupons.php" style="color:#1d4ed8;">Manage Coupons</a>.
        </div>
      </div>

      <!-- ── Autosave Settings ──────────────────────────────────────────── -->
      <div class="form-group" style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:14px 16px;margin-top:4px;">
        <div style="font-weight:700;color:#166534;margin-bottom:10px;">&#9889; Autosave Settings</div>
        <div style="font-size:.8rem;color:#4b7a5a;margin-bottom:12px;">
          Controls how often the student's in-progress answers are saved to the server.
          Lower values protect against data loss but increase DB load.
          Set interval to <strong>0</strong> to disable periodic saves entirely.
        </div>
        <div style="display:flex;gap:16px;flex-wrap:wrap;">
          <div class="form-group" style="flex:1;min-width:160px;margin:0;">
            <label for="txtAutosaveInterval" style="font-size:.85rem;">
              Periodic Save Interval (seconds)
              <span style="font-size:.75rem;color:#6b7280;font-weight:400;"> — 0 = off</span>
            </label>
            <input type="number" id="txtAutosaveInterval" name="txtAutosaveInterval"
                   class="form-control" min="0" max="600" step="10"
                   value="<?php echo (int)($examSettings['AutosaveIntervalSec'] ?? 60); ?>">
            <small style="color:#6b7280;">Recommended: 30–120 s for long exams, 0 for short quizzes.</small>
          </div>
          <div class="form-group" style="flex:1;min-width:160px;margin:0;">
            <label for="txtAutosaveDebounce" style="font-size:.85rem;">
              Answer-Change Debounce (ms)
            </label>
            <input type="number" id="txtAutosaveDebounce" name="txtAutosaveDebounce"
                   class="form-control" min="500" max="10000" step="500"
                   value="<?php echo (int)($examSettings['AutosaveDebounceMs'] ?? 3000); ?>">
            <small style="color:#6b7280;">Save fires this many ms after the student changes an answer. Min 500 ms.</small>
          </div>
        </div>
      </div>

      <!-- ── Lockdown / Proctoring ─────────────────────────────────────── -->
      <div class="form-group" style="background:#faf5ff;border:1px solid #e9d5ff;border-radius:8px;padding:14px 16px;margin-top:4px;">
        <label style="display:flex;align-items:flex-start;gap:12px;cursor:pointer;margin:0;">
          <input type="checkbox" id="chkProctorLock" name="chkProctorLock"
                 style="width:18px;height:18px;margin-top:2px;accent-color:#7c3aed;flex-shrink:0;"
                 <?php echo ($exam['proctor_lock'] ?? 0) ? 'checked' : ''; ?>>
          <span>
            <strong style="color:#5b21b6;">&#128274; Lockdown Mode</strong>
            <span style="display:block;font-size:.8rem;color:#6b7280;margin-top:2px;">
              Forces fullscreen when the exam starts. If the student switches away
              (Alt+Tab, another app, or another browser tab) a warning overlay appears.
              After <strong>3 violations</strong> the exam is automatically submitted.
              Each violation is logged for review.
            </span>
          </span>
        </label>
      </div>

      <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:12px;align-items:center;">
        <button type="submit" name="btnSave" class="btn btn-success">
          <?php echo $isNew ? '&#10010; Create Exam' : '&#10003; Save Changes'; ?>
        </button>
        <a href="<?php echo htmlspecialchars($backToExamsUrl); ?>" class="btn btn-secondary">Cancel</a>
        <?php if (!$isNew): ?>
          <a href="question-bank.php?examId=<?php echo (int)$examId; ?>"
             class="btn btn-sm" style="background:#2b6cb0;color:#fff;font-weight:700;"
             title="Pick existing questions by subject/chapter and link them here — nothing is copied">
            &#128279; Build from Question Bank
          </a>
          <a href="questions.php?examId=<?php echo (int)$examId; ?>" class="btn btn-secondary">
            &#10067; Manage Questions
          </a>
          <a href="../Admin/BulkUploadQuestions.php?examId=<?php echo (int)$examId; ?>"
             class="btn btn-sm" style="background:#7c3aed;color:#fff;font-weight:600;">
            &#11014; Bulk Upload Questions
          </a>
          <a href="history.php?InfoId=<?php echo (int)$examId; ?>" class="btn btn-secondary">
            &#128200; View History
          </a>
        <?php endif; ?>
      </div>
    </form>
    <?php if (!$isNew): ?>
    <form method="post" action="manage.php?InfoId=<?php echo (int)$examId; ?>" style="margin-top:16px;padding-top:14px;border-top:1px solid #fecaca;"
          onsubmit="return confirm('Delete &quot;<?php echo addslashes(htmlspecialchars($exam['ExamName'])); ?>&quot;?\n\nThe exam will be hidden from every list but can be restored from Trash at any time. Nothing is permanently erased.');">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken()); ?>">
      <input type="hidden" name="InfoId"     value="<?php echo (int)$examId; ?>">
      <button type="submit" name="btnDelete" class="btn btn-sm" style="background:#dc2626;color:#fff;font-weight:700;">
        &#128465; Delete Exam
      </button>
      <span style="font-size:.78rem;color:#6b7280;margin-left:8px;">Soft delete — restorable from <a href="trash.php" style="color:#4f46e5;">Trash</a>.</span>
    </form>
    <?php endif; ?>
  </div>
</div>
<script>
function filterGradesByGroup() {
  var groupSel = document.getElementById('txtGroupFilter');
  var gradeSel = document.getElementById('txtGrade');
  if (!groupSel || !gradeSel) return;
  var gid = groupSel.value;
  var selectedStillVisible = false;
  Array.prototype.forEach.call(gradeSel.options, function(opt) {
    if (!opt.value || opt.value === '0') { opt.hidden = false; return; }
    var matches = (gid === '0' || gid === '' || opt.getAttribute('data-group') === gid);
    opt.hidden = !matches;
    if (matches && opt.selected) selectedStillVisible = true;
  });
  // If the currently-selected grade got filtered out, don't silently keep an
  // invisible selection — drop back to the placeholder so the admin notices.
  if (gradeSel.value !== '0' && !selectedStillVisible) {
    gradeSel.value = '0';
  }
}

function toggleInstituteField() {
  var wrap  = document.getElementById('instituteFieldWrap');
  var isInst = document.getElementById('scopeInst').checked;
  wrap.style.display = isInst ? 'block' : 'none';
}

function toggleFreeForField() {
  var wrap   = document.getElementById('freeInstituteFieldWrap');
  var isFreeInst = document.getElementById('freeForInst') && document.getElementById('freeForInst').checked;
  if (wrap) wrap.style.display = isFreeInst ? 'block' : 'none';
}

/* ── Exam Pattern (migration_v54) — multi-subject sections ────────────── */
var EP_SUBJECTS = <?php echo json_encode(array_map(fn($s) => [
    'id' => (int)$s['SubjectInfoId'], 'name' => $s['SubjectName'],
], $subjects), JSON_HEX_TAG | JSON_HEX_APOS); ?>;
var EP_EXISTING_SECTIONS = <?php echo json_encode(array_map(fn($s) => [
    'subjectId' => $s['subjectId'], 'numQuestions' => $s['numQuestions'],
], $examSections), JSON_HEX_TAG | JSON_HEX_APOS); ?>;

function toggleMultiSubject() {
  var on = document.getElementById('chkMultiSubject').checked;
  document.getElementById('sectionsWrap').style.display = on ? 'block' : 'none';
  document.getElementById('txtSubject').required = !on;
  if (on && document.querySelectorAll('#sectionRows .section-row').length === 0) {
    addSectionRow(0, '');
  }
  updateSectionTotal();
}

function addSectionRow(subjectId, numQuestions) {
  var wrap = document.getElementById('sectionRows');
  var row = document.createElement('div');
  row.className = 'section-row';
  row.style.cssText = 'display:flex;gap:10px;align-items:center;margin-bottom:8px;';

  var opts = '<option value="0">-- Select Subject --</option>';
  for (var i = 0; i < EP_SUBJECTS.length; i++) {
    var s = EP_SUBJECTS[i];
    opts += '<option value="' + s.id + '"' + (s.id === subjectId ? ' selected' : '') + '>' + s.name + '</option>';
  }

  // sec-subject / sec-numq are named directly as the PHP array fields the
  // form handler reads ($_POST['secSubjectId'][], $_POST['secNumQ'][]) —
  // no separate hidden mirror needed, index N of one array pairs with
  // index N of the other.
  row.innerHTML =
    '<select class="form-control sec-subject" name="secSubjectId[]" style="flex:2;">' + opts + '</select>' +
    '<input type="number" class="form-control sec-numq" name="secNumQ[]" style="flex:1;max-width:120px;" min="1" max="200" ' +
      'placeholder="# Questions" value="' + (numQuestions || '') + '">' +
    '<button type="button" class="btn btn-secondary" onclick="this.parentNode.remove(); updateSectionTotal();">&#10005;</button>';

  wrap.appendChild(row);
  row.querySelector('.sec-numq').addEventListener('input', updateSectionTotal);
  row.querySelector('.sec-subject').addEventListener('change', updateSectionTotal);
}

function updateSectionTotal() {
  var nums = document.querySelectorAll('#sectionRows .sec-numq');
  var total = 0;
  for (var i = 0; i < nums.length; i++) { total += parseInt(nums[i].value) || 0; }
  var el = document.getElementById('sectionTotal');
  if (el) el.textContent = total;
}

(function initExamPattern() {
  for (var i = 0; i < EP_EXISTING_SECTIONS.length; i++) {
    addSectionRow(EP_EXISTING_SECTIONS[i].subjectId, EP_EXISTING_SECTIONS[i].numQuestions);
  }
  if (document.getElementById('chkMultiSubject').checked && EP_EXISTING_SECTIONS.length === 0) {
    addSectionRow(0, '');
  }
  updateSectionTotal();
})();

function toggleMarkingScheme() {
  var isFixed = document.getElementById('markFixed').checked;
  document.getElementById('totalMarksWrap').style.display = isFixed ? 'none' : 'block';
  document.getElementById('marksPerQWrap').style.display  = isFixed ? 'block' : 'none';
  updateMarkingPreview();
}

// Suggested marking scheme per well-known exam type (Lib/Marking.php still
// owns the actual scoring — this only pre-fills the form fields above with
// sensible values so an admin creating e.g. a NEET exam doesn't have to
// already know its +4/-1 convention). Add more entries here as needed —
// nothing about this is NEET-specific in the code, just the data.
var EXAM_IS_NEW = <?php echo $isNew ? 'true' : 'false'; ?>;
var EXAM_TYPE_MARKING_SUGGESTIONS = {
  // country here mirrors Lib/ExamType.php's fixed Type->country map — kept
  // in sync manually since this is a display-only prefill, not the source
  // of truth (examinfo.ExamCountry, once saved, always wins — see
  // ExamType::resolveCountry()).
  'NEET': { scheme: 'Fixed', marksPerQ: 4, negativeMarks: 1, country: 'India' },
  'JEE':  { country: 'India' },
  'UPSC': { country: 'India' },
  'GRE':  { country: 'USA' },
  'GMAT': { country: 'USA' }
};

function applyMarkingSuggestionForCategory() {
  // Only auto-apply on a brand-new exam — an exam being edited already has
  // explicit (possibly deliberately different) marking/country values that
  // this must never silently overwrite.
  if (!EXAM_IS_NEW) return;
  var cat = document.getElementById('examCategory').value.trim().toUpperCase();
  var suggestion = EXAM_TYPE_MARKING_SUGGESTIONS[cat];
  if (!suggestion) return;

  if (suggestion.scheme) {
    document.getElementById(suggestion.scheme === 'Fixed' ? 'markFixed' : 'markDynamic').checked = true;
    if (suggestion.scheme === 'Fixed') {
      document.getElementById('txtMarksPerQ').value = suggestion.marksPerQ;
    } else if (suggestion.totalMarks) {
      document.getElementById('txtTotalMarks').value = suggestion.totalMarks;
    }
    document.getElementById('txtNegativeMarks').value = suggestion.negativeMarks;
    toggleMarkingScheme();
  }
  var countryField = document.getElementById('examCountry');
  if (suggestion.country && countryField && !countryField.value.trim()) {
    countryField.value = suggestion.country;
  }
}

function round2(n) {
  return (Math.round(n * 100) / 100).toString();
}

function updateMarkingPreview() {
  var numQ    = parseFloat(document.getElementById('txtNumQ').value) || 0;
  var isFixed = document.getElementById('markFixed').checked;
  var perQ, total;
  if (isFixed) {
    perQ  = parseFloat(document.getElementById('txtMarksPerQ').value) || 0;
    total = perQ * numQ;
  } else {
    total = parseFloat(document.getElementById('txtTotalMarks').value) || 0;
    perQ  = numQ > 0 ? total / numQ : 0;
  }
  var neg = parseFloat(document.getElementById('txtNegativeMarks').value) || 0;
  var preview = document.getElementById('markingPreview');
  if (preview) {
    preview.innerHTML = '&#9989; Correct: <strong>+' + round2(perQ) + '</strong> marks'
      + (neg > 0 ? ' &nbsp;|&nbsp; &#10060; Incorrect: <strong>&minus;' + round2(neg) + '</strong> marks' : '')
      + ' &nbsp;|&nbsp; &#127919; Total: <strong>' + round2(total) + ' marks</strong>';
  }
}

function validateForm() {
  var name    = document.getElementById('txtExamName').value.trim();
  var grade   = parseInt(document.getElementById('txtGrade').value);
  var subject = parseInt(document.getElementById('txtSubject').value);
  var numQ    = parseInt(document.getElementById('txtNumQ').value);
  var passing = parseInt(document.getElementById('txtPassing').value);
  var time    = parseInt(document.getElementById('txtTime').value);
  var multiSubject = document.getElementById('chkMultiSubject').checked;
  if (!name)          { alert('Exam name is required.'); return false; }
  if (!grade)         { alert('Please select a grade.'); return false; }
  if (!multiSubject && !subject) { alert('Please select a subject.'); return false; }
  if (multiSubject) {
    var rows = document.querySelectorAll('#sectionRows .section-row');
    var seen = {}, valid = 0;
    for (var i = 0; i < rows.length; i++) {
      var sSel = rows[i].querySelector('.sec-subject');
      var sNum = rows[i].querySelector('.sec-numq');
      var sid  = parseInt(sSel.value);
      var n    = parseInt(sNum.value);
      if (!sid || !n) continue; // blank row — ignored, same as the server
      if (seen[sid]) { alert('Each subject can only appear once in the Exam Pattern.'); return false; }
      seen[sid] = true;
      valid++;
    }
    if (!valid) { alert('Add at least one subject section, or turn off multi-subject exam.'); return false; }
  }
  if (!multiSubject && numQ < 1) { alert('Number of questions must be at least 1.'); return false; }
  if (passing < 1 || passing > 100) { alert('Pass mark must be between 1 and 100 (%).'); return false; }
  if (time < 5)       { alert('Time must be at least 5 minutes.'); return false; }
  var markFixed = document.getElementById('markFixed').checked;
  if (markFixed) {
    var perQ = parseFloat(document.getElementById('txtMarksPerQ').value);
    if (!perQ || perQ <= 0) { alert('Marks per correct answer must be greater than 0.'); return false; }
  } else {
    var totalMarks = parseFloat(document.getElementById('txtTotalMarks').value);
    if (!totalMarks || totalMarks <= 0) { alert('Total marks must be greater than 0.'); return false; }
  }
  var negMarks = parseFloat(document.getElementById('txtNegativeMarks').value);
  if (isNaN(negMarks) || negMarks < 0) { alert('Negative marks cannot be negative.'); return false; }
  var scopeInst = document.getElementById('scopeInst');
  if (scopeInst && scopeInst.checked) {
    var instSel = document.getElementById('examInstituteId');
    if (instSel && parseInt(instSel.value) <= 0) {
      alert('Please select an institute for institute-scoped exams.');
      instSel.focus();
      return false;
    }
  }
  var freeForInst = document.getElementById('freeForInst');
  if (freeForInst && freeForInst.checked) {
    var freeInstSel = document.getElementById('freeInstituteId');
    if (freeInstSel && parseInt(freeInstSel.value) <= 0) {
      alert('Please select an institute for the fee override.');
      freeInstSel.focus();
      return false;
    }
  }
  return true;
}

updateMarkingPreview();
filterGradesByGroup();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
