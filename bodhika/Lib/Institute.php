<?php
/**
 * Lib/Institute.php — Institute-level discount resolution.
 *
 * Priority order (highest to lowest):
 *   1. Institute IsFree = 1  → completely free, skip payment
 *   2. Institute discount (PCT or AMT) for the specific subject
 *   3. Institute discount (PCT or AMT) with SubjectInfoId = NULL (wildcard)
 *   4. Coupon code discount (handled in Enrollment::applyCoupon)
 *   5. Subject-level default discount (DiscountPct on subjectinfo)
 *
 * If an institute discount resolves, coupon is NOT applied on top of it.
 */
class Institute
{
    // ── Public API ─────────────────────────────────────────────────────────────

    /**
     * Get the active institute discount rule for a student + subject.
     * Returns the matching institute_subject_discounts row or null.
     *
     * Precedence: subject-specific rule beats wildcard (NULL SubjectInfoId).
     */
    public static function getDiscount(int $userId, int $subjectId): ?array
    {
        $instId = self::getInstituteId($userId);
        if (!$instId) return null;

        try {
            // Subject-specific rule first
            $row = Database::fetchOne(
                "SELECT isd.*, i.InstituteName
                   FROM institute_subject_discounts isd
                   JOIN institutes i ON i.InstituteId = isd.InstituteId
                  WHERE isd.InstituteId  = ?
                    AND isd.SubjectInfoId = ?
                    AND isd.Active        = 'Y'
                    AND i.Active          = 'Y'
                    AND (isd.ValidFrom IS NULL OR isd.ValidFrom <= CURDATE())
                    AND (isd.ValidTo   IS NULL OR isd.ValidTo   >= CURDATE())
                  LIMIT 1",
                [$instId, $subjectId]);

            if ($row) return $row;

            // Wildcard rule (applies to all subjects for this institute)
            return Database::fetchOne(
                "SELECT isd.*, i.InstituteName
                   FROM institute_subject_discounts isd
                   JOIN institutes i ON i.InstituteId = isd.InstituteId
                  WHERE isd.InstituteId   = ?
                    AND isd.SubjectInfoId IS NULL
                    AND isd.Active         = 'Y'
                    AND i.Active           = 'Y'
                    AND (isd.ValidFrom IS NULL OR isd.ValidFrom <= CURDATE())
                    AND (isd.ValidTo   IS NULL OR isd.ValidTo   >= CURDATE())
                  LIMIT 1",
                [$instId]) ?: null;
        } catch (Exception $e) {
            error_log('Institute::getDiscount error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Apply institute discount to a fee.
     * Returns [
     *   'final'      => float,   // amount student pays
     *   'discount'   => float,   // amount saved
     *   'is_free'    => bool,
     *   'rule'       => array|null,  // the matched isd row
     *   'applied'    => bool,    // false = no institute rule found
     * ]
     */
    public static function applyDiscount(int $userId, int $subjectId, float $fee): array
    {
        $rule = self::getDiscount($userId, $subjectId);

        if (!$rule) {
            return ['final' => $fee, 'discount' => 0.0, 'is_free' => false,
                    'rule' => null, 'applied' => false];
        }

        if ($rule['IsFree']) {
            return ['final' => 0.0, 'discount' => $fee, 'is_free' => true,
                    'rule' => $rule, 'applied' => true];
        }

        $discount = ($rule['DiscountType'] === 'PCT')
            ? round($fee * (float)$rule['DiscountValue'] / 100, 2)
            : min((float)$rule['DiscountValue'], $fee);

        $final = max(0.0, $fee - $discount);
        return ['final' => $final, 'discount' => $discount, 'is_free' => ($final <= 0),
                'rule' => $rule, 'applied' => true];
    }

    /**
     * Check whether a student's institute grants free access to a subject.
     * Convenience wrapper for Enrollment::canAccess.
     */
    public static function isFreeForStudent(int $userId, int $subjectId): bool
    {
        $result = self::applyDiscount($userId, $subjectId, 1.0);
        return $result['is_free'];
    }

    /**
     * Get InstituteId for a user (cached per request via static array).
     */
    public static function getInstituteId(int $userId): ?int
    {
        static $cache = [];
        if (isset($cache[$userId])) return $cache[$userId];

        try {
            $row = Database::fetchOne(
                "SELECT InstituteId FROM userinfo WHERE UserInfoId = ? LIMIT 1", [$userId]);
            $cache[$userId] = $row ? ((int)$row['InstituteId'] ?: null) : null;
        } catch (Exception $e) {
            $cache[$userId] = null;
        }
        return $cache[$userId];
    }

    /**
     * Get institute record for a user (for display on enrollment page).
     */
    public static function getForUser(int $userId): ?array
    {
        $instId = self::getInstituteId($userId);
        if (!$instId) return null;
        try {
            return Database::fetchOne(
                "SELECT * FROM institutes WHERE InstituteId = ? AND Active = 'Y' LIMIT 1",
                [$instId]) ?: null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * All active institutes for dropdowns.
     */
    public static function listAll(): array
    {
        try {
            return Database::fetchAll(
                "SELECT InstituteId, InstituteName, InstituteType, State, CityVillage
                   FROM institutes WHERE Active = 'Y'
                   ORDER BY State, InstituteName");
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Primary contact for an institute.
     */
    public static function primaryContact(int $instituteId): ?array
    {
        try {
            return Database::fetchOne(
                "SELECT * FROM institute_contacts
                  WHERE InstituteId = ? AND IsPrimary = 1 AND Active = 'Y'
                  LIMIT 1",
                [$instituteId]) ?: null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * All contacts for an institute.
     */
    public static function contacts(int $instituteId): array
    {
        try {
            return Database::fetchAll(
                "SELECT * FROM institute_contacts
                  WHERE InstituteId = ? AND Active = 'Y'
                  ORDER BY IsPrimary DESC, ContactId",
                [$instituteId]);
        } catch (Exception $e) {
            return [];
        }
    }
}
