<?php
/**
 * Lib/Countries.php — Shared country/flag helpers.
 *
 * Single source of truth for turning a countryinfo row (or bare ISO 3166-1
 * alpha-2 code) into the small flag icon shown next to an exam, used both
 * on the admin exam list (exam/search.php) and anywhere else a country
 * needs to be displayed. Flags are rendered via flagcdn.com (free, no key,
 * already the kind of external asset this app pulls in elsewhere — see
 * MathJax from jsdelivr in includes/header.php) rather than emoji, since
 * flag emoji render inconsistently on Windows/older browsers.
 *
 * flagcdn.com only serves a fixed set of bucket sizes — for fixed-HEIGHT
 * icons (what we want here, so a row of flags lines up regardless of each
 * flag's aspect ratio) the only valid buckets are h20/h24/h40/h60/h80/h120/
 * h240. Requesting any other height 404s, so flagHeight() below always
 * snaps to the nearest valid bucket rather than trusting the caller.
 *
 * "Global" (no specific country) is represented by CountryId = NULL on
 * examinfo — there is no countryinfo row for it, it's just the absence
 * of one. Callers should treat a missing/0 CountryId as Global.
 */
final class Countries
{
    /** @var int[] The only height buckets flagcdn.com actually serves. */
    private const VALID_HEIGHTS = [20, 24, 40, 60, 80, 120, 240];

    /** Snap an arbitrary requested height to the nearest bucket flagcdn.com serves. */
    private static function snapHeight(int $height): int
    {
        $best = self::VALID_HEIGHTS[0];
        $bestDiff = PHP_INT_MAX;
        foreach (self::VALID_HEIGHTS as $h) {
            $diff = abs($h - $height);
            if ($diff < $bestDiff) { $best = $h; $bestDiff = $diff; }
        }
        return $best;
    }

    /** Small flag <img> for a given ISO 3166-1 alpha-2 code (e.g. 'IN'). */
    public static function flagImg(?string $code, ?string $name = null, int $height = 20): string
    {
        $code = strtolower(trim((string)$code));
        if ($code === '' || strlen($code) !== 2) {
            return '';
        }
        $h    = self::snapHeight($height);
        $idx  = array_search($h, self::VALID_HEIGHTS, true);
        $h2x  = self::VALID_HEIGHTS[$idx + 1] ?? $h; // next bucket up, best-effort retina
        $label = $name ?: strtoupper($code);
        $url   = "https://flagcdn.com/h{$h}/{$code}.png";
        $url2x = "https://flagcdn.com/h{$h2x}/{$code}.png";
        return '<img src="' . htmlspecialchars($url) . '" srcset="' . htmlspecialchars($url2x) . ' 2x" '
             . 'height="' . (int)$h . '" alt="' . htmlspecialchars($label) . '" '
             . 'title="' . htmlspecialchars($label) . '" loading="lazy" '
             . 'style="vertical-align:middle;border-radius:2px;box-shadow:0 0 0 1px rgba(0,0,0,.12);">';
    }

    /** "Global" badge shown for exams with no specific country (CountryId NULL). */
    public static function globalBadge(): string
    {
        return '<span title="Applicable to all countries" style="vertical-align:middle;">&#127760;</span>';
    }

    /**
     * Render whichever is appropriate for an exam row: a flag (specific
     * country) or the globe badge (Global). $countryMap is CountryId =>
     * ['CountryCode'=>..,'CountryName'=>..].
     */
    public static function examBadge($countryId, array $countryMap, int $height = 20): string
    {
        $countryId = (int)($countryId ?? 0);
        if ($countryId > 0 && isset($countryMap[$countryId])) {
            $c = $countryMap[$countryId];
            return self::flagImg($c['CountryCode'] ?? '', $c['CountryName'] ?? '', $height);
        }
        return self::globalBadge();
    }
}
