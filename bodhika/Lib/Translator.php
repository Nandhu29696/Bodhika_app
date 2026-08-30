<?php
/**
 * Translator.php — thin client for machine-translating exam content.
 *
 * Used by Admin/TranslateExam.php when an admin "Save As"-es an exam into a
 * different language. Speaks the LibreTranslate REST API shape
 * (POST {q, source, target, format, api_key} -> {translatedText}), which a
 * self-hosted LibreTranslate instance (see Docker command above
 * TRANSLATE_API_URL in Lib/Config.php) or any compatible hosted instance
 * implements as-is.
 *
 * If TRANSLATE_API_URL is empty (nothing configured), every call degrades
 * gracefully to a no-op that tags the original text so the admin can see at
 * a glance which fields still need a manual translation pass — the
 * translated exam/questions still get created either way.
 */
class Translator
{
    /** Per-request cache so re-translating the same string (e.g. a repeated
     *  option like "None of the above") doesn't re-hit the API. */
    private static array $cache = [];

    public static function isConfigured(): bool
    {
        return trim(TRANSLATE_API_URL) !== '';
    }

    /**
     * Translate $text from $sourceLang to $targetLang.
     * Empty/NULL input passes through unchanged (nothing to translate).
     */
    public static function translate(?string $text, string $targetLang, string $sourceLang = 'en'): ?string
    {
        if ($text === null || trim($text) === '') {
            return $text;
        }
        if ($targetLang === $sourceLang) {
            return $text;
        }

        $cacheKey = $sourceLang . '|' . $targetLang . '|' . $text;
        if (array_key_exists($cacheKey, self::$cache)) {
            return self::$cache[$cacheKey];
        }

        $result = self::isConfigured()
            ? self::callApi($text, $sourceLang, $targetLang)
            : null;

        // Fallback (unconfigured, or the API call failed): tag the original
        // text so it's obvious in the admin review UI which fields are still
        // untranslated, rather than silently shipping English text.
        if ($result === null) {
            $result = '[TRANSLATE:' . strtoupper($targetLang) . '] ' . $text;
        }

        self::$cache[$cacheKey] = $result;
        return $result;
    }

    /**
     * Translate several fields at once, skipping NULLs. Convenience wrapper
     * so callers don't repeat the same 4-line loop for every question's
     * Answer1..Answer4 / MatchStatement1..4 etc.
     *
     * @param array<string,string|null> $fields
     * @return array<string,string|null>
     */
    public static function translateMany(array $fields, string $targetLang, string $sourceLang = 'en'): array
    {
        $out = [];
        foreach ($fields as $key => $val) {
            $out[$key] = self::translate($val, $targetLang, $sourceLang);
        }
        return $out;
    }

    /**
     * Calls a LibreTranslate-compatible /translate endpoint:
     *   POST {TRANSLATE_API_URL}
     *   {"q": "...", "source": "en", "target": "hi", "format": "text", "api_key": "..."}
     * -> {"translatedText": "..."}
     * TRANSLATE_API_URL should point at the full /translate path, e.g.
     * http://localhost:5000/translate for the default self-hosted Docker setup.
     */
    private static function callApi(string $text, string $sourceLang, string $targetLang): ?string
    {
        $payload = [
            'q'      => $text,
            'source' => $sourceLang,
            'target' => $targetLang,
            'format' => 'text',
        ];
        if (trim(TRANSLATE_API_KEY) !== '') {
            $payload['api_key'] = TRANSLATE_API_KEY;
        }

        $ch = curl_init(TRANSLATE_API_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $err !== '' || $code < 200 || $code >= 300) {
            error_log("Translator: LibreTranslate API call failed (HTTP $code) $err: " . substr((string)$body, 0, 300));
            return null;
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !isset($decoded['translatedText']) || !is_string($decoded['translatedText'])) {
            error_log('Translator: unexpected LibreTranslate API response shape: ' . substr($body, 0, 300));
            return null;
        }

        return $decoded['translatedText'];
    }
}
