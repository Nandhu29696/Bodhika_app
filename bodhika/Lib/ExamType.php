<?php
/**
 * Lib/ExamType.php — shared helpers for examinfo.ExamCategory (migration_v55)
 * and examinfo.ExamCountry (migration_v64).
 *
 * ExamCategory and ExamCountry are both free-text fields (see exam/manage.php's
 * datalists), so any admin can type a custom exam type/country beyond the
 * well-known ones. Every helper here is written to degrade gracefully for
 * those custom/blank values instead of erroring, so it's safe to call from
 * any page regardless of what data exists.
 *
 * Country precedence: examinfo.ExamCountry, once an admin sets it explicitly,
 * always wins over the fixed Type->country guess below (NEET/JEE/UPSC =
 * India, GRE/GMAT = USA) — that guess only exists so exams nobody has tagged
 * yet (or predate migration_v64) still show a sensible flag. Use
 * resolveCountry()/resolveFlagIconHtml() (row-aware) rather than
 * countryName()/flagIconHtml() (Type-only) wherever an actual exam row is
 * available, so the explicit field is always preferred when set.
 *
 * Used by exam/browse-subjects.php (student-facing type badges/sections),
 * Admin/ExamSearch.php and exam/search.php (exam-type/country filter
 * dropdowns + result badges), exam/manage.php (Add/Edit Exam form).
 */
class ExamType
{
    /** Well-known exam types this app ships fixed styling for. */
    private const KNOWN = ['NEET', 'JEE', 'GRE', 'GMAT', 'UPSC'];

    /** Display order for known types; anything else sorts after, alphabetically. */
    private const ORDER = ['NEET' => 1, 'JEE' => 2, 'GRE' => 3, 'GMAT' => 4, 'UPSC' => 5];

    /** ISO 3166-1 alpha-2 country code for an exam type — the country whose
     *  competitive exam this is (NEET/JEE/UPSC = India, GRE/GMAT = USA).
     *  null for unknown/custom types — deliberately never guessed. */
    private const COUNTRY = [
        'NEET' => 'IN', 'JEE' => 'IN', 'UPSC' => 'IN',
        'GRE'  => 'US', 'GMAT' => 'US',
    ];

    /** Full country name for display (filter dropdowns etc.). */
    private const COUNTRY_NAME = ['IN' => 'India', 'US' => 'USA'];

    /**
     * Curated country-name -> ISO 3166-1 alpha-2 code lookup, for rendering
     * a real flag image next to whatever an admin typed into the new
     * examinfo.ExamCountry field (migration_v64) — that field is free text
     * like ExamCategory, so this has to cover common spellings/aliases
     * rather than assume a single canonical string. Case-insensitive lookup
     * via countryCodeForName() below. Unrecognised text (a typo, or a
     * country genuinely not in this starter list) degrades to the 🌐 globe,
     * same graceful-degradation approach as every other unknown value in
     * this class — never a hard error, never a guess.
     */
    private const COUNTRY_CODE_BY_NAME = [
        'india' => 'IN',
        'usa' => 'US', 'us' => 'US', 'united states' => 'US', 'united states of america' => 'US',
        'uk' => 'GB', 'united kingdom' => 'GB', 'britain' => 'GB', 'great britain' => 'GB',
        'australia' => 'AU',
        'canada' => 'CA',
        'singapore' => 'SG',
        'uae' => 'AE', 'united arab emirates' => 'AE',
        'new zealand' => 'NZ',
        'germany' => 'DE',
        'france' => 'FR',
        'china' => 'CN',
        'japan' => 'JP',
        'south africa' => 'ZA',
    ];

    /**
     * Countries whose flag icon is deliberately never rendered on list/search
     * pages (flagIconHtml()/resolveFlagIconHtml()/flagIconHtmlForCountry()
     * below) — the overwhelming majority of exams in this app are India-based
     * (NEET/JEE/UPSC plus most custom types), so showing the same flag image
     * on nearly every single row is pure visual clutter AND an extra external
     * image request (flagcdn.com) per row for zero information gained. Any
     * OTHER country still renders its flag normally — this is purely about
     * the single dominant/default case. The Type/Country TEXT label next to
     * it is unaffected; only the icon is suppressed.
     */
    private const SUPPRESS_FLAG_FOR = ['india'];

    /** Whether a country's flag icon should be suppressed per SUPPRESS_FLAG_FOR above. */
    private static function isFlagSuppressed(string $countryName): bool
    {
        return in_array(strtolower(trim($countryName)), self::SUPPRESS_FLAG_FOR, true);
    }

    /**
     * Country flag for an exam type — NEET/JEE/UPSC are Indian competitive
     * exams, GRE/GMAT are US-based standardized tests. Unknown/custom types
     * and blank/"Uncategorized" get a neutral globe rather than guessing a
     * country with nothing to base it on.
     *
     * Returns a literal Unicode flag emoji — fine for API/JSON consumers
     * (e.g. the mobile app) where the OS renders it as a real flag. For HTML
     * pages, prefer flagIconHtml() below: Windows has no color-emoji glyphs
     * for flag emoji (which are built from two "regional indicator" letter
     * characters) and falls back to rendering them as bare text like "IN".
     */
    public static function flag(string $key): string
    {
        static $flags = [
            'NEET' => '🇮🇳', 'JEE' => '🇮🇳', 'UPSC' => '🇮🇳',
            'GRE'  => '🇺🇸', 'GMAT' => '🇺🇸',
        ];
        return $flags[$key] ?? '🌐';
    }

    /**
     * HTML for a flag that actually renders as a flag on every OS/browser,
     * including Windows. Known types render a real flag image (flagcdn.com,
     * keyed by ISO country code — no bundled assets, no build step); unknown/
     * custom types fall back to the 🌐 globe emoji, which — unlike country
     * flags — is a single codepoint with normal cross-platform glyph support,
     * so it never suffers the same fallback-to-letters problem. Returns ''
     * (no icon at all — not even the globe) for India, see SUPPRESS_FLAG_FOR.
     */
    public static function flagIconHtml(string $key): string
    {
        $name = self::countryName($key);
        if ($name === null) return '🌐';
        if (self::isFlagSuppressed($name)) return '';
        return self::renderFlagImg((string)self::countryCode($key));
    }

    /** Shared <img> markup for both flagIconHtml() and flagIconHtmlForCountry(). */
    private static function renderFlagImg(string $code): string
    {
        $lc = strtolower($code);
        return '<img src="https://flagcdn.com/24x18/' . $lc . '.png" width="18" height="14"'
             . ' alt="' . htmlspecialchars($code) . '" style="vertical-align:middle;border-radius:2px;">';
    }

    /** ISO 3166-1 alpha-2 country code for an exam type, or null if unknown. */
    public static function countryCode(string $key): ?string
    {
        return self::COUNTRY[$key] ?? null;
    }

    /** Full country name for an exam type ("India"/"USA"), or null if unknown. */
    public static function countryName(string $key): ?string
    {
        $code = self::countryCode($key);
        return $code !== null ? (self::COUNTRY_NAME[$code] ?? $code) : null;
    }

    /**
     * Every distinct country name represented among currently-used exam
     * types (via allValues()) — for a "Country" filter dropdown. Only ever
     * offers countries that actually have a matching exam type in use, and
     * only the well-known types have a country at all (custom/unmapped
     * types simply don't contribute one — never guessed).
     */
    public static function countryOptions(): array
    {
        $names = [];
        foreach (self::allValues() as $v) {
            $n = self::countryName($v);
            if ($n !== null && !in_array($n, $names, true)) $names[] = $n;
        }
        sort($names);
        return $names;
    }

    /** Every exam-type value (from allValues()) that belongs to a given country name. */
    public static function typesForCountry(string $country): array
    {
        return array_values(array_filter(self::allValues(), fn($v) => self::countryName($v) === $country));
    }

    /** ISO 3166-1 alpha-2 code for a free-text country name (ExamCountry), or null if unrecognised. */
    public static function countryCodeForName(string $countryName): ?string
    {
        $key = strtolower(trim($countryName));
        return $key !== '' ? (self::COUNTRY_CODE_BY_NAME[$key] ?? null) : null;
    }

    /**
     * Flag image HTML for a free-text country name — 🌐 globe if
     * unrecognised, '' (no icon) for India (SUPPRESS_FLAG_FOR). Same
     * rendering as flagIconHtml(), for the row-aware ExamCountry field
     * instead of a bare Type key.
     */
    public static function flagIconHtmlForCountry(string $countryName): string
    {
        if (self::isFlagSuppressed($countryName)) return '';
        $code = self::countryCodeForName($countryName);
        return $code === null ? '🌐' : self::renderFlagImg($code);
    }

    /**
     * The effective country for an exam ROW: examinfo.ExamCountry if an
     * admin has explicitly set it (migration_v64), else fall back to the
     * fixed Type->country guess (countryName()) so exams that predate this
     * field, or were never explicitly tagged, still resolve sensibly.
     * Returns '' if neither is available — never guesses beyond that.
     */
    public static function resolveCountry(array $exam): string
    {
        $explicit = trim((string)($exam['ExamCountry'] ?? ''));
        if ($explicit !== '') return $explicit;
        return self::countryName((string)($exam['ExamCategory'] ?? '')) ?? '';
    }

    /** Flag HTML for an exam ROW — resolveCountry() first, Type-only flag as last resort. */
    public static function resolveFlagIconHtml(array $exam): string
    {
        $country = self::resolveCountry($exam);
        return $country !== '' ? self::flagIconHtmlForCountry($country) : '🌐';
    }

    /**
     * Every distinct, non-blank ExamCountry value currently in use, plus a
     * curated starter list — for the Add/Edit Exam form's Country datalist
     * (same "suggest known + surface custom" pattern as allValues()).
     * Returns [] if the ExamCountry column doesn't exist yet (migration_v64
     * not run).
     */
    public static function allCountryValues(): array
    {
        if (!Database::hasColumn('examinfo', 'ExamCountry')) return [];
        $values = array_values(array_unique(array_merge(
            array_values(self::COUNTRY_NAME), // India, USA — keeps Type-derived guesses representable too
            ['India', 'USA', 'UK', 'Australia', 'Canada', 'Singapore', 'UAE']
        )));
        try {
            $rows = Database::fetchAll(
                "SELECT DISTINCT ExamCountry FROM examinfo WHERE ExamCountry IS NOT NULL AND ExamCountry <> ''");
            foreach ($rows as $r) {
                $v = trim($r['ExamCountry'] ?? '');
                if ($v !== '' && !in_array($v, $values, true)) $values[] = $v;
            }
        } catch (Exception $e) {}
        sort($values);
        return $values;
    }

    /**
     * Distinct ExamCountry values actually present on an exam right now —
     * unlike allCountryValues(), NO curated suggestions mixed in. Used by
     * the "Country" filter dropdown (countryFilterOptions() below), which
     * should only ever offer a country that would actually return results,
     * not every country the Add/Edit form happens to suggest.
     */
    public static function usedCountryValues(): array
    {
        if (!Database::hasColumn('examinfo', 'ExamCountry')) return [];
        $values = [];
        try {
            $rows = Database::fetchAll(
                "SELECT DISTINCT ExamCountry FROM examinfo WHERE ExamCountry IS NOT NULL AND ExamCountry <> ''");
            foreach ($rows as $r) {
                $v = trim($r['ExamCountry'] ?? '');
                if ($v !== '' && !in_array($v, $values, true)) $values[] = $v;
            }
        } catch (Exception $e) {}
        return $values;
    }

    /**
     * Every country name a "Country" filter dropdown should offer: whatever
     * is actually set via usedCountryValues() (the explicit column, once
     * migration_v64 has run) UNIONed with countryOptions() (the Type-derived
     * fallback) — so filtering by country still works for exams that only
     * have a Type set and haven't been individually tagged with ExamCountry
     * yet. Deliberately NOT the same list as allCountryValues() (the
     * Add/Edit form's datalist), which also suggests unused countries —
     * a filter should never offer an option guaranteed to return nothing.
     */
    public static function countryFilterOptions(): array
    {
        $names = array_unique(array_merge(self::usedCountryValues(), self::countryOptions()));
        sort($names);
        return array_values($names);
    }

    /**
     * Background color for an exam-type section/badge. Known types get
     * fixed, deliberately-chosen colors; anything else still gets its own
     * consistent color instead of one generic shade every time — deterministic
     * per name (CRC32-keyed into a fixed palette), so the same custom type
     * always renders the same color.
     */
    public static function color(string $key): string
    {
        static $fixed = [
            'NEET'          => '#059669',
            'JEE'           => '#4338ca',
            'GRE'           => '#7c3aed',
            'GMAT'          => '#ea580c',
            'UPSC'          => '#b91c1c',
            'Uncategorized' => '#94a3b8',
        ];
        if (isset($fixed[$key])) return $fixed[$key];
        $palette = ['#0891b2', '#a16207', '#be185d', '#0f766e', '#7c2d12', '#6d28d9'];
        return $palette[crc32($key) % count($palette)];
    }

    /**
     * Sort key so known types show in a fixed, sensible order (NEET, JEE,
     * GRE, GMAT, UPSC) with any custom type — or "Uncategorized" — after.
     */
    public static function sortRank(string $key): int
    {
        return self::ORDER[$key] ?? ($key === 'Uncategorized' ? 99 : 90);
    }

    /**
     * Every distinct, non-blank ExamCategory value currently in use, plus
     * every well-known type — so the filter dropdown always offers NEET/JEE/
     * GRE/GMAT/UPSC even before any exam has been tagged with them, and also
     * surfaces whatever custom types admins have already typed in. Returns
     * [] if the ExamCategory column doesn't exist yet (migration_v55 not run).
     */
    public static function allValues(): array
    {
        if (!Database::hasColumn('examinfo', 'ExamCategory')) return [];
        $values = self::KNOWN;
        try {
            $rows = Database::fetchAll(
                "SELECT DISTINCT ExamCategory FROM examinfo WHERE ExamCategory IS NOT NULL AND ExamCategory <> ''");
            foreach ($rows as $r) {
                $v = trim($r['ExamCategory'] ?? '');
                if ($v !== '' && !in_array($v, $values, true)) $values[] = $v;
            }
        } catch (Exception $e) {}
        usort($values, fn($a, $b) => self::sortRank($a) <=> self::sortRank($b) ?: strcmp($a, $b));
        return $values;
    }
}
