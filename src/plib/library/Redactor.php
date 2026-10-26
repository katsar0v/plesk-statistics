<?php
/**
 * Turns a raw request target into a display string that is safe to show to administrators:
 * credentials, tokens and personal data in the query string are replaced with `[redacted]`,
 * token-like path segments are hidden, and the result is valid, printable UTF-8.
 */
class Modules_LiveTrafficMonitor_Redactor
{
    public const MASK = '[redacted]';
    public const MAX_LENGTH = 512;

    /** C1 controls, zero-width characters and bidi overrides, which could disguise a URL. */
    private const INVISIBLE = '[\x{80}-\x{9F}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]';

    /** Query keys containing one of these anywhere are always redacted (`access_token`, `X-Amz-Signature`, `_wpnonce`). */
    private const KEY_FRAGMENTS = [
        'token', 'secret', 'passw', 'pwd', 'nonce', 'signature', 'hmac', 'jwt', 'apikey', 'api_key', 'api-key',
        'sessid', 'sessionid', 'session_id', 'credential', 'authorization', 'bearer', 'saml', 'cookie', 'privatekey',
    ];

    /** Query keys whose words (split on `_ - . [ ]` and camelCase) include one of these are redacted (`key`, `reset_code`). */
    private const KEY_WORDS = [
        'key', 'sig', 'sid', 'code', 'state', 'hash', 'session', 'ticket', 'salt', 'reset', 'verify', 'verification',
        'activation', 'confirm', 'email', 'mail', 'login', 'user', 'username', 'phone', 'tel', 'pin', 'cvv', 'cvc',
        'iban', 'card', 'pass', 'password', 'private', 'auth', 'oauth', 'otp', 'assertion',
    ];

    public function redact(string $target): string
    {
        $target = self::toPrintable($target);
        $query = null;
        if (($q = strpos($target, '?')) !== false) {
            $query = substr($target, $q + 1);
            $target = substr($target, 0, $q);
        }
        if (($hash = strpos($target, '#')) !== false) {
            $target = substr($target, 0, $hash);
        }

        $out = $this->redactPath($target);
        if ($query !== null) {
            $out .= '?' . $this->redactQuery($query);
        }
        return self::truncate(self::decodeUtf8($out));
    }

    private function redactPath(string $path): string
    {
        // Session ids in path parameters: /cart;jsessionid=ABC
        $path = preg_replace('/;(jsessionid|phpsessid|sid|sessionid|token)=[^\/;]*/i', ';$1=' . self::MASK, $path);

        $segments = explode('/', $path);
        foreach ($segments as $i => $segment) {
            if ($segment !== '' && self::looksLikePathSecret(rawurldecode($segment))) {
                $segments[$i] = self::MASK;
            }
        }
        return implode('/', $segments);
    }

    private function redactQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }
        $pairs = explode('&', $query);
        foreach ($pairs as $i => $pair) {
            $eq = strpos($pair, '=');
            $key = $eq === false ? $pair : substr($pair, 0, $eq);
            $value = $eq === false ? null : substr($pair, $eq + 1);
            $decodedKey = urldecode($key);

            if ($value === null) {
                // `?<token>` without a key.
                if (self::looksLikeValueSecret($decodedKey)) {
                    $pairs[$i] = self::MASK;
                }
                continue;
            }
            if (self::looksLikeValueSecret($decodedKey)) {
                $pairs[$i] = self::MASK . '=' . self::MASK;
            } elseif ($value !== '' && (self::isSensitiveKey($decodedKey) || self::looksLikeValueSecret(urldecode($value))
                || self::containsSensitiveParam(urldecode($value)))) {
                $pairs[$i] = $key . '=' . self::MASK;
            }
        }
        return implode('&', $pairs);
    }

    public static function isSensitiveKey(string $key): bool
    {
        $lower = strtolower($key);
        foreach (self::KEY_FRAGMENTS as $fragment) {
            if (strpos($lower, $fragment) !== false) {
                return true;
            }
        }
        $words = preg_split('/[^a-z0-9]+/', strtolower(preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $key)), -1, PREG_SPLIT_NO_EMPTY);
        return (bool) array_intersect($words, self::KEY_WORDS);
    }

    /** A value that is itself a URL or query string carrying a sensitive parameter (`?next=/a?token=x`). */
    private static function containsSensitiveParam(string $value): bool
    {
        if (!preg_match_all('/(?:^|[?&;])([^=&?;#\/]{1,64})=[^&;#]/', $value, $m)) {
            return false;
        }
        foreach ($m[1] as $key) {
            if (self::isSensitiveKey($key)) {
                return true;
            }
        }
        return false;
    }

    /** Query values: JWTs, e-mail addresses, long hex strings and long random-looking strings. */
    public static function looksLikeValueSecret(string $value): bool
    {
        if (strlen($value) < 16) {
            return false;
        }
        return self::isJwt($value)
            || preg_match('/[^@\s\/]+@[^@\s\/]+\.[a-z]{2,}/i', $value)
            || preg_match('/(?<![0-9a-f])[0-9a-f]{32,}(?![0-9a-f])/i', $value)
            || self::hasRandomRun($value, 20);
    }

    /**
     * Path segments are usually readable slugs, ids or cache-busting hashes, so only JWTs and long
     * mixed-case random strings (password-reset and download tokens) count as secrets here.
     */
    public static function looksLikePathSecret(string $segment): bool
    {
        return strlen($segment) >= 24 && (self::isJwt($segment) || self::hasRandomRun($segment, 32));
    }

    private static function isJwt(string $s): bool
    {
        return (bool) preg_match('/eyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}(\.[A-Za-z0-9_-]*)?/', $s);
    }

    /**
     * A run of base64/base64url characters containing a 16+ character stretch without separators that
     * mixes upper case, lower case and at least two digits. Hyphenated slugs such as
     * `Summer-Sale-2024-Edition-X1` have no such stretch.
     */
    private static function hasRandomRun(string $s, int $minLength): bool
    {
        if (!preg_match_all('/[A-Za-z0-9+\/=_-]{' . $minLength . ',}/', $s, $runs)) {
            return false;
        }
        foreach ($runs[0] as $run) {
            foreach (preg_split('/[-_\/]+/', $run) as $piece) {
                if (strlen($piece) >= 16 && preg_match('/[a-z]/', $piece) && preg_match('/[A-Z]/', $piece)
                    && preg_match_all('/\d/', $piece) >= 2) {
                    return true;
                }
            }
        }
        return false;
    }

    /** Percent-encodes control characters, spaces, bidi overrides and bytes that are not valid UTF-8. */
    private static function toPrintable(string $s): string
    {
        $encode = static fn (array $m): string => strtoupper(rawurlencode($m[0]));
        if (mb_check_encoding($s, 'UTF-8')) {
            $s = preg_replace_callback('/' . self::INVISIBLE . '/u', $encode, $s);
        } else {
            $s = preg_replace_callback('/[\x80-\xFF]/', $encode, $s);
        }
        return preg_replace_callback('/[\x00-\x20\x7F]/', $encode, $s);
    }

    /**
     * Makes percent-encoded UTF-8 readable (`/%D0%BA%D0%BE` → `/ко`). ASCII escapes such as `%2F`,
     * `%3F` or `%20` stay encoded so the decoded string stays unambiguous.
     */
    private static function decodeUtf8(string $s): string
    {
        return preg_replace_callback('/(?:%[C-Fc-f][0-9A-Fa-f](?:%[89ABab][0-9A-Fa-f]){1,3})+/', static function (array $m): string {
            $decoded = rawurldecode($m[0]);
            return mb_check_encoding($decoded, 'UTF-8') && !preg_match('/' . self::INVISIBLE . '/u', $decoded)
                ? $decoded
                : $m[0];
        }, $s);
    }

    private static function truncate(string $s): string
    {
        return mb_strlen($s, 'UTF-8') > self::MAX_LENGTH ? mb_substr($s, 0, self::MAX_LENGTH - 1, 'UTF-8') . '…' : $s;
    }
}
