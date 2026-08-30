<?php
/**
 * Lib/Phone.php — Shared mobile-number / country-code helper.
 *
 * Single source of truth for the country-code list and per-country digit
 * rules used by every "country code + mobile number" field in the app
 * (auth/register.php, exam/settings.php, ...). Keeping this in one place
 * means every page validates and renders the same way instead of each
 * page carrying its own copy of the rules.
 *
 * The companion client-side rules live in assets/phone-input.js — keep
 * the two in sync if you add/change a country here.
 */
final class Phone
{
    /** [dialCode => [min digits, max digits, leading-digit regex|null, label]] */
    public const RULES = [
        '+91'  => [10, 10, '/^[6-9]/',    'India'],
        '+1'   => [10, 10, null,          'USA / Canada'],
        '+44'  => [10, 10, null,          'UK'],
        '+61'  => [9,  9,  null,          'Australia'],
        '+64'  => [8,  9,  null,          'New Zealand'],
        '+971' => [9,  9,  null,          'UAE'],
        '+966' => [9,  9,  '/^[5]/',      'Saudi Arabia'],
        '+65'  => [8,  8,  '/^[689]/',    'Singapore'],
        '+60'  => [9,  10, '/^[1]/',      'Malaysia'],
        '+94'  => [9,  9,  '/^[7]/',      'Sri Lanka'],
        '+92'  => [10, 10, '/^[3]/',      'Pakistan'],
        '+880' => [10, 10, '/^[1]/',      'Bangladesh'],
        '+977' => [10, 10, '/^[9]/',      'Nepal'],
        '+81'  => [10, 11, null,          'Japan'],
        '+82'  => [9,  10, null,          'South Korea'],
        '+86'  => [11, 11, null,          'China'],
        '+852' => [8,  8,  null,          'Hong Kong'],
        '+49'  => [10, 12, null,          'Germany'],
        '+33'  => [9,  9,  null,          'France'],
        '+39'  => [9,  10, null,          'Italy'],
        '+34'  => [9,  9,  null,          'Spain'],
        '+31'  => [9,  9,  null,          'Netherlands'],
        '+46'  => [9,  9,  null,          'Sweden'],
        '+47'  => [8,  8,  null,          'Norway'],
        '+45'  => [8,  8,  null,          'Denmark'],
        '+41'  => [9,  9,  null,          'Switzerland'],
        '+7'   => [10, 10, null,          'Russia'],
        '+55'  => [10, 11, null,          'Brazil'],
        '+52'  => [10, 10, null,          'Mexico'],
        '+54'  => [10, 10, null,          'Argentina'],
        '+27'  => [9,  9,  null,          'South Africa'],
        '+234' => [10, 10, '/^[07-9]/',   'Nigeria'],
        '+254' => [9,  9,  '/^[7]/',      'Kenya'],
        '+20'  => [10, 10, null,          'Egypt'],
        '+212' => [9,  9,  null,          'Morocco'],
    ];

    /** [dialCode => [isoCode, flagEmoji]] — display info, keyed the same as RULES */
    public const DISPLAY = [
        '+91'=>['IN','🇮🇳'], '+1'=>['US','🇺🇸'],  '+44'=>['GB','🇬🇧'], '+61'=>['AU','🇦🇺'],
        '+64'=>['NZ','🇳🇿'], '+971'=>['AE','🇦🇪'], '+966'=>['SA','🇸🇦'], '+65'=>['SG','🇸🇬'],
        '+60'=>['MY','🇲🇾'], '+94'=>['LK','🇱🇰'],  '+92'=>['PK','🇵🇰'],  '+880'=>['BD','🇧🇩'],
        '+977'=>['NP','🇳🇵'],'+81'=>['JP','🇯🇵'],  '+82'=>['KR','🇰🇷'],  '+86'=>['CN','🇨🇳'],
        '+852'=>['HK','🇭🇰'],'+49'=>['DE','🇩🇪'],  '+33'=>['FR','🇫🇷'],  '+39'=>['IT','🇮🇹'],
        '+34'=>['ES','🇪🇸'], '+31'=>['NL','🇳🇱'],  '+46'=>['SE','🇸🇪'],  '+47'=>['NO','🇳🇴'],
        '+45'=>['DK','🇩🇰'], '+41'=>['CH','🇨🇭'],  '+7'=>['RU','🇷🇺'],   '+55'=>['BR','🇧🇷'],
        '+52'=>['MX','🇲🇽'], '+54'=>['AR','🇦🇷'],  '+27'=>['ZA','🇿🇦'],  '+234'=>['NG','🇳🇬'],
        '+254'=>['KE','🇰🇪'],'+20'=>['EG','🇪🇬'],  '+212'=>['MA','🇲🇦'],
    ];

    public const DEFAULT_CC = '+91';

    /**
     * Ordered list for rendering a <select>: [code, iso, flag, label].
     * Default country first, then the rest in RULES' insertion order.
     */
    public static function ccList(): array
    {
        $out = [];
        foreach (self::RULES as $code => [$min, $max, $lead, $label]) {
            [$iso, $flag] = self::DISPLAY[$code] ?? ['', ''];
            $out[] = [$code, $iso, $flag, $label];
        }
        return $out;
    }

    /**
     * Split a stored number (e.g. "+919876543211" or a bare legacy
     * "9876543211") into [dialCode, localDigits]. Falls back to
     * DEFAULT_CC when there's no recognizable "+<code>" prefix.
     */
    public static function split(?string $stored): array
    {
        $stored = trim((string)$stored);
        if ($stored === '') {
            return [self::DEFAULT_CC, ''];
        }
        if ($stored[0] === '+') {
            // Try longest dial-code prefix first (+971 before +91, +1 etc.)
            $codes = array_keys(self::RULES);
            usort($codes, fn($a, $b) => strlen($b) - strlen($a));
            foreach ($codes as $code) {
                if (strpos($stored, $code) === 0) {
                    return [$code, preg_replace('/\D/', '', substr($stored, strlen($code)))];
                }
            }
            // Unknown "+something" prefix — strip leading + and digits, keep as-is under default cc
            return [self::DEFAULT_CC, preg_replace('/\D/', '', $stored)];
        }
        // No "+" prefix at all → legacy bare number. Usually this is just a
        // local number under the default country, but some legacy data
        // (e.g. admin free-text edits before this page had a country-code
        // selector) has the dial code baked into the digits without a "+",
        // like "919876543210". Left as-is, that shows up as an invalid
        // 12-digit "+91" number that can never be fixed by editing it,
        // since every save just re-derives the same bare string. Only
        // treat it as code+digits if the bare string DOESN'T already look
        // like a valid default-country number on its own — a real 10-digit
        // Indian number starting with "91" (e.g. "9198765432") must not be
        // mistaken for "+91" + a 8-digit remainder.
        $digits = preg_replace('/\D/', '', $stored);
        if (isset(self::RULES[self::DEFAULT_CC])) {
            [$defMin, $defMax] = self::RULES[self::DEFAULT_CC];
            if (strlen($digits) >= $defMin && strlen($digits) <= $defMax) {
                return [self::DEFAULT_CC, $digits]; // already a plausible bare number — leave it alone
            }
        }
        $codes = array_keys(self::RULES);
        usort($codes, fn($a, $b) => strlen($b) - strlen($a));
        foreach ($codes as $code) {
            $bareCode = ltrim($code, '+');
            if (strpos($digits, $bareCode) !== 0) continue;
            $remainder = substr($digits, strlen($bareCode));
            [$min, $max, $lead] = self::RULES[$code];
            if (strlen($remainder) < $min || strlen($remainder) > $max) continue;
            if ($lead && !preg_match($lead, $remainder)) continue;
            return [$code, $remainder];
        }
        return [self::DEFAULT_CC, $digits];
    }

    /** Recombine a dial code + local digits back into one storable string. */
    public static function combine(string $cc, string $digits): string
    {
        $digits = preg_replace('/\D/', '', $digits);
        return $digits === '' ? '' : $cc . $digits;
    }

    /**
     * Validate local digits against a dial code's rule.
     * Returns null when valid, or a human-readable error string.
     */
    public static function validate(string $cc, string $digits): ?string
    {
        $digits = preg_replace('/\D/', '', $digits);
        if ($digits === '') {
            return null; // empty mobile number is allowed (optional field)
        }
        if (!ctype_digit($digits)) {
            return 'Mobile number must contain digits only (no spaces or dashes).';
        }
        $len = strlen($digits);
        if (isset(self::RULES[$cc])) {
            [$min, $max, $lead] = self::RULES[$cc];
            if ($len < $min || $len > $max) {
                return "Mobile number for {$cc} must be "
                     . ($min === $max ? "{$min}" : "{$min}–{$max}")
                     . " digits (you entered {$len}).";
            }
            if ($lead && !preg_match($lead, $digits)) {
                return "Mobile number for {$cc} appears invalid (check starting digit).";
            }
            return null;
        }
        // Unknown dial code — basic sanity range
        if ($len < 6 || $len > 15) {
            return 'Mobile number must be between 6 and 15 digits.';
        }
        return null;
    }
}
