<?php
/**
 * Lib/AppSettings.php — Key-value application settings backed by app_settings table.
 *
 * Usage:
 *   AppSettings::get('otp_email_enabled')          // '0' | '1'
 *   AppSettings::set('otp_email_enabled', '1')
 *   AppSettings::getMany(['otp_email_enabled', 'otp_sms_enabled'])
 *
 * Results are request-scoped cached so repeated calls cost zero DB round-trips.
 * Requires: migration_v30.sql (app_settings table).
 */
class AppSettings
{
    /** @var array<string,string> In-process cache */
    private static array $_cache = [];
    /** @var bool True once we've loaded all settings in bulk */
    private static bool  $_allLoaded = false;

    /**
     * Fetch a single setting. Returns $default if key is not in the table
     * or if the table does not yet exist (pre-migration).
     */
    public static function get(string $key, string $default = ''): string
    {
        if (array_key_exists($key, self::$_cache)) {
            return self::$_cache[$key];
        }

        /* If the full table was already bulk-loaded, key simply doesn't exist */
        if (self::$_allLoaded) {
            return self::$_cache[$key] = $default;
        }

        try {
            $row = Database::fetchOne(
                "SELECT SettingValue FROM app_settings WHERE SettingKey = ? LIMIT 1",
                [$key]
            );
            self::$_cache[$key] = ($row !== false) ? (string)($row['SettingValue'] ?? '') : $default;
        } catch (Exception $e) {
            self::$_cache[$key] = $default; // table not yet created
        }

        return self::$_cache[$key];
    }

    /** Fetch several keys at once (single query). Returns [key => value]. */
    public static function getMany(array $keys): array
    {
        $missing = array_diff($keys, array_keys(self::$_cache));

        if ($missing) {
            $ph = implode(',', array_fill(0, count($missing), '?'));
            try {
                $rows = Database::fetchAll(
                    "SELECT SettingKey, SettingValue FROM app_settings WHERE SettingKey IN ($ph)",
                    array_values($missing)
                );
                foreach ($rows as $row) {
                    self::$_cache[$row['SettingKey']] = (string)($row['SettingValue'] ?? '');
                }
                /* Any key not returned by the query → store default '' */
                foreach ($missing as $k) {
                    if (!array_key_exists($k, self::$_cache)) self::$_cache[$k] = '';
                }
            } catch (Exception $e) {
                foreach ($missing as $k) self::$_cache[$k] = '';
            }
        }

        return array_intersect_key(self::$_cache, array_flip($keys));
    }

    /**
     * Load ALL settings from the table into the cache in one query.
     * Call this at the top of any page that reads many settings.
     */
    public static function loadAll(): void
    {
        if (self::$_allLoaded) return;
        try {
            $rows = Database::fetchAll("SELECT SettingKey, SettingValue FROM app_settings", []);
            foreach ($rows as $row) {
                self::$_cache[$row['SettingKey']] = (string)($row['SettingValue'] ?? '');
            }
            self::$_allLoaded = true;
        } catch (Exception $e) { /* pre-migration — silent */ }
    }

    /**
     * Persist a single setting.
     * Uses UPSERT so it works whether the row already exists or not.
     */
    public static function set(string $key, string $value): void
    {
        Database::execute(
            "INSERT INTO app_settings (SettingKey, SettingValue)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE SettingValue = VALUES(SettingValue)",
            [$key, $value]
        );
        self::$_cache[$key] = $value;
    }

    /**
     * Persist an associative array of settings (batched UPSERTs).
     * @param array<string,string> $map
     */
    public static function setMany(array $map): void
    {
        foreach ($map as $k => $v) {
            self::set((string)$k, (string)$v);
        }
    }

    /** Check a boolean-style setting ('1' | 'true' | 'yes' → true). */
    public static function isEnabled(string $key): bool
    {
        $v = strtolower(trim(self::get($key, '0')));
        return in_array($v, ['1', 'true', 'yes', 'on'], true);
    }

    /** Flush the in-process cache (useful in tests). */
    public static function flushCache(): void
    {
        self::$_cache    = [];
        self::$_allLoaded = false;
    }
}
