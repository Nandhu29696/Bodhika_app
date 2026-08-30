<?php
/**
 * Lib/Pii.php — On-screen PII masking + the admin "Show/Hide PII" toggle.
 *
 * Scope: this masks values in the HTML the admin looks at. It does NOT
 * change what's stored in the database (still plain text) or what's sent
 * to SQL for search/duplicate-checks (Enrollment, register.php, and
 * BulkUploadStudents.php all keep querying real Email/Mobile values —
 * masking those in SQL would break every uniqueness check already built).
 * See docs/PII_RECOMMENDATIONS.md for the at-rest encryption discussion.
 *
 * Toggle state:
 *   - One global switch per admin session ($_SESSION['pii_unmasked']),
 *     flipped via Admin/TogglePiiMask.php and rendered by toggleButton()
 *     in includes/header.php — visible admin-wide, not per-page.
 *   - Auto re-masks after UNMASK_MINUTES of being left on, so a forgotten
 *     "Show" toggle doesn't leave PII exposed for an entire long session.
 *   - Every flip to "unmasked" is written to pii_access_log (migration_v62)
 *     for audit purposes — who looked at real PII, and when.
 *
 * Usage in a view:
 *   echo htmlspecialchars(Pii::email($row['EMail']));
 *   echo htmlspecialchars(Pii::mobile($row['Mobile']));
 *   echo htmlspecialchars(Pii::name($row['FstName'] . ' ' . $row['LstName']));
 *   echo htmlspecialchars(Pii::address($row['Address']));
 */
class Pii
{
    /** Minutes the toggle stays "unmasked" before auto re-masking. */
    const UNMASK_MINUTES = 30;

    /* ── Toggle state ──────────────────────────────────────────────────── */

    /** Whether PII should currently be shown in full for this session. */
    public static function isUnmasked(): bool
    {
        if (empty($_SESSION['pii_unmasked']) || empty($_SESSION['pii_unmasked_at'])) {
            return false;
        }
        if (time() - (int)$_SESSION['pii_unmasked_at'] > self::UNMASK_MINUTES * 60) {
            self::setUnmasked(false); // expired — re-mask silently
            return false;
        }
        return true;
    }

    /** Flip the toggle. Does NOT log — callers (TogglePiiMask.php) log explicitly. */
    public static function setUnmasked(bool $on): void
    {
        $_SESSION['pii_unmasked']    = $on;
        $_SESSION['pii_unmasked_at'] = $on ? time() : null;
    }

    /** Minutes remaining before an active "unmasked" toggle auto-expires (0 if masked). */
    public static function minutesRemaining(): int
    {
        if (!self::isUnmasked()) return 0;
        $elapsed = time() - (int)$_SESSION['pii_unmasked_at'];
        return max(0, (int)ceil((self::UNMASK_MINUTES * 60 - $elapsed) / 60));
    }

    /* ── Field-aware helpers — mask or pass through based on the toggle ──── */

    public static function email(?string $v): string
    {
        return self::isUnmasked() ? (string)$v : self::maskEmail($v);
    }

    public static function mobile(?string $v): string
    {
        return self::isUnmasked() ? (string)$v : self::maskMobile($v);
    }

    public static function address(?string $v): string
    {
        return self::isUnmasked() ? (string)$v : self::maskAddress($v);
    }

    /** Full display name — pass FstName/LstName pre-joined, or call maskName() directly per-part. */
    public static function name(?string $v): string
    {
        return self::isUnmasked() ? (string)$v : self::maskName($v);
    }

    /* ── Raw masking functions (usable directly, independent of the toggle —
       e.g. the mask/unmask CLI scripts reuse these for consistent output) ── */

    /** john.doe@example.com → j******e@e*****.com */
    public static function maskEmail(?string $email): string
    {
        $email = trim((string)$email);
        if ($email === '' || !str_contains($email, '@')) {
            return $email === '' ? '' : '••••••';
        }
        [$local, $domain] = explode('@', $email, 2);
        $maskedLocal  = self::maskMiddle($local, 1, 1);
        $dotPos       = strrpos($domain, '.');
        if ($dotPos === false) {
            $maskedDomain = self::maskMiddle($domain, 1, 0);
        } else {
            $domainName = substr($domain, 0, $dotPos);
            $tld        = substr($domain, $dotPos); // includes leading "."
            $maskedDomain = self::maskMiddle($domainName, 1, 0) . $tld;
        }
        return $maskedLocal . '@' . $maskedDomain;
    }

    /** +919876543210 → +•••••••3210  |  9876543210 → •••••••3210
     *  No attempt is made to detect the exact country-code boundary (that
     *  needs a dial-code table, see BulkUploadStudents.php's MOBILE_RULES,
     *  and isn't worth the complexity purely for cosmetic masking) — a
     *  leading "+" is just preserved as-is, everything else is digits. */
    public static function maskMobile(?string $mobile): string
    {
        $mobile = trim((string)$mobile);
        if ($mobile === '') return '';

        $hasPlus = str_starts_with($mobile, '+');
        $digits  = preg_replace('/\D/', '', $mobile) ?? '';
        $len     = strlen($digits);
        if ($len === 0) return $mobile; // nothing digit-like to mask — show as-is

        if ($len <= 4) {
            return ($hasPlus ? '+' : '') . str_repeat('•', max(4, $len));
        }
        $visible = min(4, max(2, (int)floor($len * 0.3)));
        return ($hasPlus ? '+' : '') . str_repeat('•', $len - $visible) . substr($digits, -$visible);
    }

    /** "221B Baker Street, London" → "221B ••••••••••••••••••" */
    public static function maskAddress(?string $address): string
    {
        $address = trim((string)$address);
        if ($address === '') return '';
        if (mb_strlen($address) <= 6) return str_repeat('•', 8);
        $visible = mb_substr($address, 0, 4);
        return $visible . str_repeat('•', 12);
    }

    /** "John Michael Doe" → "J*** M****** D**" — first letter of each word kept. */
    public static function maskName(?string $name): string
    {
        $name = trim((string)$name);
        if ($name === '') return '';
        $words = preg_split('/\s+/', $name);
        $out   = array_map(function (string $w): string {
            if (mb_strlen($w) <= 1) return $w;
            return mb_substr($w, 0, 1) . str_repeat('•', min(mb_strlen($w) - 1, 6));
        }, $words);
        return implode(' ', $out);
    }

    /** Generic helper: keep $showStart chars from the front and $showEnd from
     *  the back, mask everything between with a length-capped run of bullets
     *  (capped so the mask never leaks the original length for short strings
     *  vs long ones at a glance). */
    private static function maskMiddle(string $s, int $showStart, int $showEnd, int $maxMask = 6): string
    {
        $len = mb_strlen($s);
        if ($len === 0) return '';
        if ($len <= $showStart + $showEnd) {
            return mb_substr($s, 0, 1) . str_repeat('•', max(1, $len - 1));
        }
        $start   = mb_substr($s, 0, $showStart);
        $end     = $showEnd > 0 ? mb_substr($s, -$showEnd) : '';
        $maskLen = min($maxMask, $len - $showStart - $showEnd);
        return $start . str_repeat('•', $maskLen) . $end;
    }

    /* ── UI ────────────────────────────────────────────────────────────── */

    /** Renders the global Show/Hide PII toggle button (admin-only pages). */
    public static function toggleButton(string $returnTo): string
    {
        $unmasked = self::isUnmasked();
        $label    = $unmasked ? '&#128065; PII Visible' : '&#128274; PII Masked';
        $bg       = $unmasked ? '#dc2626' : '#059669';
        $title    = $unmasked
            ? 'Full email/mobile/address/name is visible. Click to re-mask. Auto re-masks in ' . self::minutesRemaining() . ' min.'
            : 'Personal details are masked. Click to reveal for this session (logged for audit).';

        $csrf = htmlspecialchars(Auth::csrfToken());
        $ret  = htmlspecialchars($returnTo);

        return '<form method="post" action="' . self::_root() . 'Admin/TogglePiiMask.php" '
             . 'style="display:inline;margin:0;" title="' . htmlspecialchars($title) . '">'
             . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
             . '<input type="hidden" name="return" value="' . $ret . '">'
             . '<button type="submit" style="background:' . $bg . ';color:#fff;border:none;'
             . 'padding:6px 12px;border-radius:6px;font-size:.78rem;font-weight:700;cursor:pointer;'
             . 'white-space:nowrap;">' . $label . '</button>'
             . '</form>';
    }

    /** Best-effort relative path back to the app root, for the toggle form's action. */
    private static function _root(): string
    {
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        // Anything under /Admin/, /exam/, /auth/, /api/... is one directory deep.
        return preg_match('#/(Admin|exam|auth|api)/#', $script) ? '../' : '';
    }
}
