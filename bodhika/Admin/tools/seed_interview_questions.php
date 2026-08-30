<?php
/**
 * Admin/tools/seed_interview_questions.php
 *
 * One-time (re-runnable) seeder that registers the 24 Interview Questions
 * PDFs — 12 companies × {MCQ, Technical} — as study_references rows under
 * Category = "Interview Questions", SubCategory = "MCQ" | "Technical".
 *
 * The PDF files themselves must already sit in assets/study-references/
 * (this repo ships them there already). This script only creates/updates
 * the database rows that make them show up under
 * References → Interview Questions → MCQ / Technical.
 *
 * Run once after applying study_references_categories_migration.sql:
 *   CLI  :  php Admin/tools/seed_interview_questions.php
 *   Browser (admin session required): Admin/tools/seed_interview_questions.php
 *
 * Safe to re-run: uses INSERT ... ON DUPLICATE KEY UPDATE keyed on the
 * (Category, SubCategory, Title) unique index added by that migration, so
 * running it again just refreshes URL/Description instead of duplicating rows.
 */

require_once __DIR__ . '/../../Lib/Config.php';
require_once __DIR__ . '/../../Lib/Auth.php';

$isCli = (php_sapi_name() === 'cli');
if (!$isCli) {
    Auth::requireLogin('../../auth/login.php');
    if (!Auth::isAdmin()) { header('Location: ../../auth/login.php'); exit; }
}

const CATEGORY = 'Interview Questions';

/** Company => short blurb used for both MCQ and Technical descriptions. */
$companies = [
    'TCS'           => 'Tata Consultancy Services — TCS NQT (Ninja / Digital / Prime) prep set.',
    'Infosys'       => 'Infosys — System Engineer / Specialist Programmer / Power Programmer prep set.',
    'Wipro'         => 'Wipro — Elite NTH campus hiring prep set.',
    'Accenture'     => 'Accenture — Associate Software Engineer / Digital track prep set.',
    'Cognizant'     => 'Cognizant — GenC / GenC Next / GenC Elevate prep set.',
    'Capgemini'     => 'Capgemini — Analyst / Software Engineer track prep set.',
    'HCLTech'       => 'HCL Technologies — Graduate Engineer Trainee (GET) prep set.',
    'Tech Mahindra' => 'Tech Mahindra — Associate Software Engineer prep set.',
    'Google'        => 'Google — Software Engineer, New Grad prep set (DSA-heavy).',
    'Microsoft'     => 'Microsoft — Software Engineer, New Grad prep set.',
    'Amazon'        => 'Amazon — SDE-1, New Grad prep set (incl. Leadership Principles).',
    'Adobe'         => 'Adobe — Software Engineer, New Grad prep set.',
];

function slugify(string $name): string {
    return preg_replace('/[^A-Za-z0-9]+/', '_', $name);
}

$uploadUrlPrefix = 'assets/study-references/';
$uploadDir       = __DIR__ . '/../../assets/study-references/';

$results = [];
$sortOrder = 0;

foreach ($companies as $company => $blurb) {
    $slug = slugify($company);

    $subs = [
        'MCQ'       => [
            'file' => $slug . '_MCQ_Questions.pdf',
            'desc' => "100 technical multiple-choice interview questions for $company campus recruitment, "
                    . "covering OOP, DBMS/SQL, OS, Computer Networks, and DSA, with a full answer key. $blurb",
        ],
        'Technical' => [
            'file' => $slug . '_Technical_Questions.pdf',
            'desc' => "100 technical descriptive interview questions for $company with full answers, "
                    . "plus supporting diagrams for key concepts (data structures, DBMS, OS, networking, "
                    . "system design). $blurb",
        ],
    ];

    foreach ($subs as $subCategory => $info) {
        $sortOrder += 10;
        $filePath = $uploadDir . $info['file'];

        if (!file_exists($filePath)) {
            $results[] = "SKIPPED  $company / $subCategory — file not found: assets/study-references/{$info['file']}";
            continue;
        }

        $url = $uploadUrlPrefix . $info['file'];

        // INSERT ... ON DUPLICATE KEY UPDATE relies on the unique index
        // uq_ref_cat_sub_title (Category, SubCategory, Title) added by
        // study_references_categories_migration.sql.
        Database::execute(
            "INSERT INTO study_references
                (Title, Description, URL, RefType, Category, SubCategory,
                 SubjectInfoId, Author, SortOrder, Active, ShowContent)
             VALUES (?, ?, ?, 'PDF', ?, ?, NULL, NULL, ?, 'Y', 'Y')
             ON DUPLICATE KEY UPDATE
                Description = VALUES(Description),
                URL         = VALUES(URL),
                RefType     = VALUES(RefType),
                SortOrder   = VALUES(SortOrder),
                Active      = 'Y'",
            [$company, $info['desc'], $url, CATEGORY, $subCategory, $sortOrder]
        );

        $results[] = "OK       $company / $subCategory -> $url";
    }
}

if ($isCli) {
    echo implode(PHP_EOL, $results) . PHP_EOL;
    echo PHP_EOL . count($results) . " rows processed." . PHP_EOL;
    exit(0);
}
?>
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>Seed Interview Questions</title></head>
<body style="font-family:monospace;background:#111;color:#0f0;padding:20px;">
  <h2 style="color:#fff;">Interview Questions — Seed Result</h2>
  <pre><?php echo htmlspecialchars(implode(PHP_EOL, $results)); ?></pre>
  <p style="color:#fff;"><?php echo count($results); ?> rows processed.</p>
  <p><a href="../StudyReferences.php?category=Interview+Questions" style="color:#7dd3fc;">
    &#8594; View in Admin / All References</a></p>
</body>
</html>
