<?php
/**
 * Lib/Certificate.php — Certificate generation (course completion + merit).
 *
 * Certificates are issued as immutable snapshots into the `certificates`
 * table (StudentName/CourseName/Score/Grade copied at issue time) — a
 * deliberate departure from this app's usual "always compute live" pattern,
 * because a certificate is a historical record that must not silently
 * change if the student's name or an exam's marks are edited afterwards.
 *
 * Output is print-ready HTML (exam/certificate-print.php), not a generated
 * PDF binary — there is no PDF library in this app, and the browser's
 * native Print → Save as PDF covers the same need without adding one.
 *
 * Requires: migrations/migration_v37.sql (certificate_templates, certificates).
 */
class Certificate
{
    /** Grade bands, highest first. Thresholds are configurable via AppSettings. */
    private const GRADE_BANDS = ['Distinction', 'A+', 'A', 'B+', 'B'];

    /** Hard fallback thresholds if AppSettings has no override yet. */
    private const DEFAULT_THRESHOLDS = [
        'Distinction' => 90,
        'A+'          => 80,
        'A'           => 70,
        'B+'          => 60,
        'B'           => 50,
    ];

    /**
     * Configurable grade-band percentage cutoffs, e.g.
     *   ['Distinction' => 90, 'A+' => 80, 'A' => 70, 'B+' => 60, 'B' => 50]
     * Stored as AppSettings keys cert_grade_<lowercased band, + -> plus>.
     */
    public static function getGradeThresholds(): array
    {
        $thresholds = [];
        foreach (self::DEFAULT_THRESHOLDS as $band => $default) {
            $key = 'cert_grade_' . strtolower(str_replace('+', 'plus', $band));
            $thresholds[$band] = class_exists('AppSettings')
                ? (int)AppSettings::get($key, (string)$default)
                : $default;
        }
        return $thresholds;
    }

    /**
     * Map a percentage score (0-100) to a grade band, e.g. 92.5 → 'Distinction'.
     * Returns '' if below the lowest configured band (no merit grade earned).
     */
    public static function gradeForPercent(float $percent): string
    {
        $thresholds = self::getGradeThresholds();
        foreach (self::GRADE_BANDS as $band) {
            if ($percent >= ($thresholds[$band] ?? self::DEFAULT_THRESHOLDS[$band])) {
                return $band;
            }
        }
        return '';
    }

    /**
     * Generate a unique, human-readable certificate number: CERT-2026-00001.
     * Sequential per calendar year, with a collision-safe random fallback —
     * issue() retries through this on a duplicate-key error.
     */
    public static function generateCertNo(): string
    {
        $year = date('Y');
        try {
            $row = Database::fetchOne(
                "SELECT COUNT(*) AS cnt FROM certificates WHERE CertificateNo LIKE ?",
                ["CERT-{$year}-%"]
            );
            $seq = (int)($row['cnt'] ?? 0) + 1;
        } catch (Exception $e) {
            $seq = random_int(1, 99999);
        }
        return sprintf('CERT-%s-%05d', $year, $seq);
    }

    /**
     * Coded CSS design themes available for templates. Keyed by ThemeKey,
     * rendered by exam/certificate-print.php — there is no free-form CSS
     * editor or artwork upload, by design (see migration_v37.sql header).
     */
    public static function availableThemes(): array
    {
        return [
            'navy_gold'   => 'Classic Navy & Gold',
            'teal_modern' => 'Elegant Teal',
            'gold_seal'   => 'Distinction Gold Seal',
            'navy_ribbon' => 'Scholar Navy Ribbon',
        ];
    }

    /**
     * Active templates, optionally filtered by CertType ('completion'|'merit')
     * and by owning Institute.
     *
     * $instituteId, when given (>0), scopes the result to templates usable
     * for that institute's students: the institute's own TemplateType='image'
     * rows (InstituteId = $instituteId) PLUS every global/built-in
     * TemplateType='coded' theme (InstituteId IS NULL — those remain usable
     * everywhere). Passing null/0 returns only the global templates, so an
     * institute-owned template never shows up until that institute is
     * actually selected (Admin/GenerateCertificates.php's Institution filter).
     *
     * $allInstitutes bypasses that scoping entirely and returns every
     * template regardless of owner — for the admin's master template list
     * (Admin/CertificateTemplates.php), which needs to manage every
     * institute's templates at once, not just one institute's usable set.
     */
    public static function listTemplates(
        ?string $certType = null,
        bool $activeOnly = true,
        ?int $instituteId = null,
        bool $allInstitutes = false
    ): array
    {
        $where  = [];
        $params = [];
        if ($activeOnly)      { $where[] = "Active = 'Y'"; }
        if ($certType !== null) { $where[] = 'CertType = ?'; $params[] = $certType; }
        if (!$allInstitutes) {
            if ($instituteId !== null && $instituteId > 0) {
                $where[]  = '(InstituteId IS NULL OR InstituteId = ?)';
                $params[] = $instituteId;
            } else {
                $where[] = 'InstituteId IS NULL';
            }
        }
        $sql = 'SELECT * FROM certificate_templates'
             . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
             . ' ORDER BY (InstituteId IS NULL), CertType, Name';
        try {
            return Database::fetchAll($sql, $params);
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Fixed catalogue of placeholder fields the image-template designer
     * (Admin/CertificateTemplateDesign.php) can place on a background, and
     * exam/certificate-print.php resolves against the issued certificate row.
     * Grade/Percentage only make sense for merit certificates, but are left
     * selectable regardless — the renderer simply omits them when blank.
     */
    public static function placeholderFields(): array
    {
        return [
            'StudentName'   => 'Student Name',
            'CourseName'    => 'Course / Program Name',
            'Duration'      => 'Duration',
            'IssueDate'     => 'Issue Date',
            'CertificateNo' => 'Certificate No.',
            'Grade'         => 'Grade (merit only)',
            'Percentage'    => 'Percentage (merit only)',
        ];
    }

    /** Decode a LayoutJson/SignatoriesJson/WordPlaceholders/WordFieldMap/ExtraFields column into an array; never throws. */
    public static function decodeJsonArray(?string $json): array
    {
        if ($json === null || $json === '') return [];
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    /** Trim a percentage to at most 1 decimal place, dropping a trailing ".0"/"0". */
    public static function formatPercent(float $p): string
    {
        $s = rtrim(rtrim(number_format($p, 1), '0'), '.');
        return $s === '' ? '0' : $s;
    }

    /**
     * The catalog values (Certificate::placeholderFields() keys) actually
     * resolved for one issued/preview certificate row, print-formatted the
     * same way regardless of caller — shared by exam/certificate-print.php's
     * image-template renderer and Admin/GenerateCertificates.php's word-
     * template filler so the two never drift (e.g. date formatting, the "%"
     * suffix on Percentage) despite rendering to completely different outputs
     * (positioned HTML text vs. a filled .docx).
     */
    public static function fieldValues(array $certRow): array
    {
        $issueDate = (string)($certRow['IssueDate'] ?? '');
        return [
            'StudentName'   => (string)($certRow['StudentName'] ?? ''),
            'CourseName'    => (string)($certRow['CourseName'] ?? ''),
            'Duration'      => (string)($certRow['Duration'] ?? ''),
            'IssueDate'     => $issueDate !== '' ? date('d M Y', strtotime($issueDate)) : '',
            'CertificateNo' => (string)($certRow['CertificateNo'] ?? ''),
            'Grade'         => (string)($certRow['Grade'] ?? ''),
            'Percentage'    => (($certRow['Percentage'] ?? null) !== null && $certRow['Percentage'] !== '')
                                ? self::formatPercent((float)$certRow['Percentage']) . '%' : '',
        ];
    }

    public static function getTemplate(int $templateId): ?array
    {
        try {
            $row = Database::fetchOne(
                "SELECT * FROM certificate_templates WHERE TemplateId = ? LIMIT 1",
                [$templateId]
            );
            return $row ?: null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Issue one certificate. Returns ['ok'=>bool, 'certificateId'=>int, 'certificateNo'=>string, 'error'=>string].
     *
     * Required keys in $data: TemplateId, UserInfoId, StudentName, CourseName,
     * IssueDate, CertType ('completion'|'merit'), IssuedBy.
     * Optional: SubjectInfoId, ExamInfoId, Duration, Score, MarksOutOf,
     * Percentage, Grade (auto-derived from Percentage for merit if omitted).
     */
    public static function issue(array $data): array
    {
        $certType = in_array($data['CertType'] ?? '', ['completion', 'merit'], true)
            ? $data['CertType'] : 'completion';

        $percentage = isset($data['Percentage']) && $data['Percentage'] !== ''
            ? round((float)$data['Percentage'], 2) : null;

        $grade = trim((string)($data['Grade'] ?? ''));
        if ($certType === 'merit' && $grade === '' && $percentage !== null) {
            $grade = self::gradeForPercent($percentage);
        }

        $row = [
            'TemplateId'    => (int)($data['TemplateId'] ?? 0),
            'UserInfoId'    => (int)($data['UserInfoId'] ?? 0),
            'SubjectInfoId' => !empty($data['SubjectInfoId']) ? (int)$data['SubjectInfoId'] : null,
            'ExamInfoId'    => !empty($data['ExamInfoId'])    ? (int)$data['ExamInfoId']    : null,
            'StudentName'   => trim((string)($data['StudentName'] ?? '')),
            'CourseName'    => trim((string)($data['CourseName']  ?? '')),
            'Duration'      => trim((string)($data['Duration']    ?? '')) ?: null,
            'IssueDate'     => $data['IssueDate'] ?? date('Y-m-d'),
            'Score'         => isset($data['Score'])      && $data['Score']      !== '' ? round((float)$data['Score'], 2)      : null,
            'MarksOutOf'    => isset($data['MarksOutOf'])  && $data['MarksOutOf']  !== '' ? round((float)$data['MarksOutOf'], 2) : null,
            'Percentage'    => $percentage,
            'Grade'         => $grade ?: null,
            'CertType'      => $certType,
            'IssuedBy'      => trim((string)($data['IssuedBy'] ?? '')),
        ];

        if ($row['TemplateId'] <= 0 || $row['UserInfoId'] <= 0 ||
            $row['StudentName'] === '' || $row['CourseName'] === '') {
            return ['ok' => false, 'certificateId' => 0, 'certificateNo' => '', 'error' => 'Missing required certificate fields.'];
        }

        /* Retry a couple of times on a CertificateNo collision (sequential
           number raced by a concurrent request) before giving up. */
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $certNo = self::generateCertNo();
            if ($attempt > 0) {
                $certNo .= '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
            }
            try {
                Database::execute(
                    "INSERT INTO certificates
                        (CertificateNo, TemplateId, UserInfoId, SubjectInfoId, ExamInfoId,
                         StudentName, CourseName, Duration, IssueDate,
                         Score, MarksOutOf, Percentage, Grade, CertType, Status, IssuedBy)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,'Issued',?)",
                    [
                        $certNo, $row['TemplateId'], $row['UserInfoId'], $row['SubjectInfoId'], $row['ExamInfoId'],
                        $row['StudentName'], $row['CourseName'], $row['Duration'], $row['IssueDate'],
                        $row['Score'], $row['MarksOutOf'], $row['Percentage'], $row['Grade'], $row['CertType'], $row['IssuedBy'],
                    ]
                );
                return [
                    'ok' => true,
                    'certificateId' => (int)Database::lastInsertId(),
                    'certificateNo' => $certNo,
                    'error' => '',
                ];
            } catch (Exception $e) {
                $isDuplicate = stripos($e->getMessage(), 'Duplicate') !== false;
                if (!$isDuplicate || $attempt === 2) {
                    return ['ok' => false, 'certificateId' => 0, 'certificateNo' => '', 'error' => $e->getMessage()];
                }
                // else: loop and retry with a fresh/disambiguated number
            }
        }

        return ['ok' => false, 'certificateId' => 0, 'certificateNo' => '', 'error' => 'Could not generate a unique certificate number.'];
    }

    /**
     * Record the filled .docx produced for a TemplateType='word' certificate
     * (Lib/WordTemplate.php::fillTemplate, called from
     * Admin/GenerateCertificates.php right after issue() above returns a
     * certificateId — CertificateNo isn't known until then, so the file can
     * only be filled/attached as a second step, not inside issue() itself).
     *
     * $relativePath is stored as-is (relative to Admin/, matching the
     * BackgroundImage/WordFile convention) so exam/certificate-download.php
     * can resolve it the same way cert_resolve_abs() does for images.
     */
    public static function attachGeneratedFile(int $certificateId, string $relativePath, array $extraFields = []): bool
    {
        try {
            return Database::execute(
                "UPDATE certificates SET GeneratedFile = ?, ExtraFields = ? WHERE CertificateId = ?",
                [$relativePath, $extraFields ? json_encode($extraFields) : null, $certificateId]
            ) > 0;
        } catch (Exception $e) {
            return false;
        }
    }

    /** Public lookup by certificate number (verify page) — Issued certs only by default. */
    public static function findByNo(string $certNo, bool $issuedOnly = true): ?array
    {
        $certNo = trim($certNo);
        if ($certNo === '') return null;
        $sql = "SELECT c.*, t.Name AS TemplateName, t.ThemeKey,
                       t.TemplateType, t.BackgroundImage, t.LayoutJson, t.SignatoriesJson
                  FROM certificates c
             LEFT JOIN certificate_templates t ON t.TemplateId = c.TemplateId
                 WHERE c.CertificateNo = ?" . ($issuedOnly ? " AND c.Status = 'Issued'" : '') . " LIMIT 1";
        try {
            $row = Database::fetchOne($sql, [$certNo]);
            return $row ?: null;
        } catch (Exception $e) {
            return null;
        }
    }

    public static function findById(int $certificateId): ?array
    {
        try {
            $row = Database::fetchOne(
                "SELECT c.*, t.Name AS TemplateName, t.ThemeKey,
                        t.TemplateType, t.BackgroundImage, t.LayoutJson, t.SignatoriesJson
                   FROM certificates c
              LEFT JOIN certificate_templates t ON t.TemplateId = c.TemplateId
                  WHERE c.CertificateId = ? LIMIT 1",
                [$certificateId]
            );
            return $row ?: null;
        } catch (Exception $e) {
            return null;
        }
    }

    /** Shared WHERE-clause builder for listIssued()/countIssued() — keeps the two in sync. */
    private static function issuedWhere(array $filters): array
    {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['userId'])) {
            $where[]  = 'c.UserInfoId = ?';
            $params[] = (int)$filters['userId'];
        }
        if (!empty($filters['search'])) {
            $where[]  = '(c.StudentName LIKE ? OR c.CertificateNo LIKE ? OR c.CourseName LIKE ?)';
            $like     = '%' . $filters['search'] . '%';
            $params[] = $like; $params[] = $like; $params[] = $like;
        }
        if (!empty($filters['certType']) && in_array($filters['certType'], ['completion', 'merit'], true)) {
            $where[]  = 'c.CertType = ?';
            $params[] = $filters['certType'];
        }
        if (!empty($filters['status']) && in_array($filters['status'], ['Issued', 'Revoked'], true)) {
            $where[]  = 'c.Status = ?';
            $params[] = $filters['status'];
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * Filterable list for the admin "Certificates Issued" page, and
     * (via the 'userId' filter) the student-facing "My Certificates" page.
     *
     * $limit/$offset default to the old fixed 500-row cap so every existing
     * caller (student "My Certificates", any other unqualified callers)
     * keeps working unchanged; the admin list page passes real pagination
     * values (see countIssued()/countIssuedByStatus() for the matching totals).
     */
    public static function listIssued(array $filters = [], int $limit = 500, int $offset = 0): array
    {
        [$whereSQL, $params] = self::issuedWhere($filters);

        // OwnerLogin/OwnerActive let the admin list show which account a
        // certificate is actually tied to (c.StudentName is just a
        // point-in-time snapshot, not a live link) — added so a "student
        // says they can't view their own certificate" report can be
        // diagnosed by eye: compare OwnerLogin against the student's real
        // login instead of needing a raw SQL query.
        $sql = "SELECT c.*, t.Name AS TemplateName,
                       u.LoginName AS OwnerLogin, l.Active AS OwnerActive
                  FROM certificates c
             LEFT JOIN certificate_templates t ON t.TemplateId = c.TemplateId
             LEFT JOIN userinfo u ON u.UserInfoId = c.UserInfoId
             LEFT JOIN logininfo l ON l.LoginName = u.LoginName
                 WHERE {$whereSQL}
                 ORDER BY c.IssuedAt DESC
                 LIMIT " . max(0, $offset) . ', ' . max(1, $limit);
        try {
            return Database::fetchAll($sql, $params);
        } catch (Exception $e) {
            return [];
        }
    }

    /** Total row count for the same filters listIssued() would use — drives pagination. */
    public static function countIssued(array $filters = []): int
    {
        [$whereSQL, $params] = self::issuedWhere($filters);
        try {
            $row = Database::fetchOne("SELECT COUNT(*) AS cnt FROM certificates c WHERE {$whereSQL}", $params);
            return (int)($row['cnt'] ?? 0);
        } catch (Exception $e) {
            return 0;
        }
    }

    /** Issued/Revoked breakdown for the same filters — avoids fetching every row just to tally statuses. */
    public static function countIssuedByStatus(array $filters): array
    {
        [$whereSQL, $params] = self::issuedWhere($filters);
        try {
            $rows = Database::fetchAll(
                "SELECT c.Status, COUNT(*) AS cnt FROM certificates c WHERE {$whereSQL} GROUP BY c.Status",
                $params
            );
        } catch (Exception $e) {
            return ['Issued' => 0, 'Revoked' => 0];
        }
        $out = ['Issued' => 0, 'Revoked' => 0];
        foreach ($rows as $r) {
            if (isset($out[$r['Status']])) $out[$r['Status']] = (int)$r['cnt'];
        }
        return $out;
    }

    /** Revoke / re-issue (toggle Status). Returns true on success. */
    public static function setStatus(int $certificateId, string $status): bool
    {
        if (!in_array($status, ['Issued', 'Revoked'], true)) return false;
        try {
            return Database::execute(
                "UPDATE certificates SET Status = ? WHERE CertificateId = ?",
                [$status, $certificateId]
            ) > 0;
        } catch (Exception $e) {
            return false;
        }
    }
}
