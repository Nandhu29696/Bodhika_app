<?php
/**
 * Config.php — Central application configuration & security bootstrap.
 *
 * Loaded first by every page. Responsibilities:
 *  1. Send HTTP security headers
 *  2. Harden the PHP session cookie
 *  3. Start the session
 *  4. Define constants (DB, app, payments, session timeout)
 *  5. Autoload the Database class
 */

// ── 1. HTTP Security Headers ─────────────────────────────────────────────────
// Send before any output. Safe to call even when headers already sent
// (PHP will ignore them in that case, but Config is always first).
if (!headers_sent()) {
    // Prevent page from being embedded in iframes (clickjacking)
    header('X-Frame-Options: SAMEORIGIN');

    // Prevent MIME-type sniffing
    header('X-Content-Type-Options: nosniff');

    // Limit referrer info sent to external sites
    header('Referrer-Policy: strict-origin-when-cross-origin');

    // Disable browser features we don't need
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()');

    // Basic Content-Security-Policy:
    //  - default: same origin only
    //  - scripts: same origin + cdnjs (Chart.js, Razorpay)
    //  - styles: same origin + inline (needed for dynamic colour chips)
    //  - frames: none (no iframes)
    header(
        "Content-Security-Policy: " .
        "default-src 'self'; " .
        "script-src 'self' https://cdnjs.cloudflare.com https://checkout.razorpay.com 'unsafe-inline'; " .
        "style-src 'self' 'unsafe-inline'; " .
        "img-src 'self' data:; " .
        "connect-src 'self'; " .
        "frame-src https://api.razorpay.com https://checkout.razorpay.com; " .
        "frame-ancestors 'self';"
    );

    // Force HTTPS in production (comment out if running on plain HTTP locally)
    // header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

// ── 2. Session hardening ──────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {

    $isHttps = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
        (int)($_SERVER['SERVER_PORT'] ?? 80) === 443
    );

    session_set_cookie_params([
        'lifetime' => 0,            // Cookie expires when browser closes
        'path'     => '/',
        'domain'   => '',           // Current domain only
        'secure'   => $isHttps,     // HTTPS-only when on HTTPS
        'httponly' => true,         // Not accessible by JavaScript (XSS protection)
        'samesite' => 'Lax',        // CSRF protection; Strict breaks Razorpay redirects
    ]);

    // PHP-level session GC: expire session data after 15 minutes of inactivity.
    // Auth::requireLogin() enforces this in application logic too.
    ini_set('session.gc_maxlifetime', 900);
    ini_set('session.gc_probability', 1);
    ini_set('session.gc_divisor',     100);

    // Prevent session ID in URL (session hijacking vector)
    ini_set('session.use_only_cookies', 1);
    ini_set('session.use_trans_sid',    0);

    // Stronger session ID (PHP 7.1+)
    ini_set('session.sid_length',        48);
    ini_set('session.sid_bits_per_character', 6);

    session_start();
}

// ── 3. Error reporting ────────────────────────────────────────────────────────
// DEVELOPMENT: display errors on screen so you see them immediately.
// PRODUCTION:  set display_errors to 0 and rely on log_errors only.
error_reporting(E_ALL);
ini_set('display_errors', 1);   // ← change to 0 in production
ini_set('log_errors',     1);

// ── 4. Environment bootstrap ───────────────────────────────────────────────
// Load .env from the project root if present so local config can be kept out of
// source control while still working with standard DB_* variable names.
if (!function_exists('load_env_file')) {
    function load_env_file(): void
    {
        $candidates = [
            dirname(__DIR__) . '/.env',
            __DIR__ . '/.env',
            dirname(__DIR__, 2) . '/.env',
        ];

        foreach ($candidates as $file) {
            if (is_file($file)) {
                $values = parse_ini_file($file, true, INI_SCANNER_TYPED);
                if (is_array($values)) {
                    foreach ($values as $key => $value) {
                        if (is_array($value)) {
                            continue;
                        }
                        $_ENV[$key] = (string) $value;
                        putenv($key . '=' . (string) $value);
                    }
                }
                break;
            }
        }
    }
}
load_env_file();

// Database
define('DB_HOST', getenv('DB_HOST') ?: getenv('DB_HOSTNAME') ?: 'localhost');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_DATABASE') ?: getenv('DB_NAME') ?: 'bodhika_mcqdb');
define('DB_USER', getenv('DB_USERNAME') ?: getenv('DB_USER') ?: 'myapp_user');
define('DB_PASS', getenv('DB_PASSWORD') ?: getenv('DB_PASS') ?: 'Dapoli@19750');
define('DB_CHARSET', getenv('DB_CHARSET') ?: 'utf8mb4');

// Application
define('APP_NAME',          'Bodhika');
define('DEFAULT_SENDER',    'webmaster@localhost');
define('SESSION_TOKEN_KEY', 'sess_token');

// Fixed password used by Auth::resetPasswordToDefault() (Admin/Institute-Admin
// "reset student password" feature, migration_v59). The user is forced to
// change it on next login (MustChangePassword flag), so a shared known value
// here is safe — it only ever grants one single login before it must change.
define('DEFAULT_RESET_PASSWORD', APP_NAME . '@123');

// Session security
define('SESSION_TIMEOUT',        900);   // 15 minutes inactivity → auto-logout
define('SESSION_REGEN_INTERVAL', 300);   // Regenerate session ID every 5 minutes
define('LOGIN_MAX_ATTEMPTS',       5);   // Failed logins before lockout
define('LOGIN_LOCKOUT_MINUTES',   15);   // Minutes to lock account after too many failures

// Mobile API (token-based auth — see Lib/ApiAuth.php, migrations/migration_v40.sql)
// Used to HMAC-sign short-lived "OTP pending" hand-off tokens between
// /api/auth/login.php and /api/auth/otp_verify.php so the API can stay fully
// stateless (no PHP session continuity assumed between two mobile requests).
// CHANGE THIS in production via the API_TOKEN_SECRET environment variable.
define('API_TOKEN_SECRET',      getenv('API_TOKEN_SECRET') ?: 'CHANGE_ME_IN_PRODUCTION_' . DB_NAME);
define('API_OTP_PENDING_TTL',   600);     // seconds an OTP hand-off token stays valid (10 min, matches Otp default)
define('API_TOKEN_DEFAULT_TTL', 60 * 60 * 24 * 30); // 30 days — bearer token lifetime for mobile sessions
define('API_ATTEMPT_GRACE_SECONDS', 1800); // extra time past TimeAlloted before Lib/ExamEngine.php rejects a late submit (slow uploads etc.)

// Machine translation (Admin/TranslateExam.php, Lib/Translator.php) — used for
// the "Save As different language" exam feature. Uses a self-hosted
// LibreTranslate instance (open source, free, unlimited, private —
// https://github.com/LibreTranslate/LibreTranslate). No API key needed for a
// self-hosted instance unless you've turned on --api-keys yourself.
//   1. Install Docker if you don't have it, then run:
//        docker run -d --name libretranslate -p 5000:5000 libretranslate/libretranslate
//      (first run downloads the language models — takes a few minutes; add
//      --load-only en,hi,mr etc. to skip languages you don't need)
//   2. Confirm it's up: http://localhost:5000 should show the LibreTranslate UI.
//   3. Leave TRANSLATE_API_URL pointed at http://localhost:5000/translate below
//      (default). If you host it elsewhere, set TRANSLATE_API_URL via env var.
// Leave TRANSLATE_API_URL empty to disable auto-translation: TranslateExam.php
// will still create the translated exam/question rows, just with each text
// field tagged "[TRANSLATE:xx] <original text>" so the admin knows exactly
// what still needs manual translation before publishing.
define('TRANSLATE_API_URL', getenv('TRANSLATE_API_URL') ?: 'http://localhost:5000/translate');
define('TRANSLATE_API_KEY', getenv('TRANSLATE_API_KEY') ?: '');

// Public base URL the Flutter app prepends to relative image paths (question
// images, answer images, profile photos) returned by the API.
// LOCAL TESTING default: 10.0.2.2 is how the Android emulator reaches the
// host PC's "localhost". Testing on a physical device instead? Swap this for
// your PC's LAN IP (run `ipconfig`), e.g. 'http://192.168.1.50/Exam'.
// Swap for the real domain again at production deploy time.
define('API_PUBLIC_BASE_URL', getenv('API_PUBLIC_BASE_URL') ?: 'http://10.0.2.2/Exam');

// Razorpay payment gateway (set via environment variables in production)
define('RZP_KEY_ID',     getenv('RZP_KEY_ID')     ?: '');
define('RZP_KEY_SECRET', getenv('RZP_KEY_SECRET') ?: '');

// ── 5. Static asset cache-busting ───────────────────────────────────────────
// assets/*.css and assets/*.js are served with a 1-year "immutable"
// Cache-Control header (see .htaccess) so browsers never re-check them once
// cached. That's only safe if every reference appends a version query
// string that changes when the file changes — otherwise a returning user
// can be stuck on a stale copy for up to a year after a deploy, even after
// a hard refresh in some browsers.
//
// This replaces the `@filemtime(__DIR__ . '/../assets/x.css') ?: time()`
// pattern that used to be duplicated inline in ~13 files (easy to forget,
// which is exactly how assets/password-toggle.js and assets/phone-input.js
// ended up shipping with no cache-busting at all). Every page should call
// this instead of touching filemtime() directly.
if (!function_exists('asset_version')) {
    /**
     * Return $relPath (relative to the Exam app root, e.g. 'assets/style.css')
     * with a `?v=<mtime>` query string appended, so browsers fetch a fresh
     * copy the moment the file's contents change on disk. Falls back to the
     * current timestamp (always busts cache, never crashes) if the file
     * can't be stat'd — e.g. wrong path, permissions.
     */
    function asset_version(string $relPath): string
    {
        static $appRoot = null;
        if ($appRoot === null) {
            $appRoot = dirname(__DIR__); // Lib/.. => Exam app root
        }
        $relPath = ltrim($relPath, '/');
        $mtime   = @filemtime($appRoot . '/' . $relPath);

        return $relPath . '?v=' . ($mtime ?: time());
    }
}

// ── 6. Autoload Database class ────────────────────────────────────────────────
require_once __DIR__ . '/Database.php';
