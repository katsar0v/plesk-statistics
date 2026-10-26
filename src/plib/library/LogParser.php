<?php
/**
 * Parses one access-log line in the combined format that Plesk configures for both nginx
 * (`proxy_access*_log`) and Apache (`access*_log`):
 *
 *     203.0.113.7 - - [26/Oct/2026:11:17:44 +0000] "GET /path?x=1 HTTP/1.1" 200 1234 "referer" "agent"
 *
 * nginx escapes `"` and non-printable bytes in the request line as `\xHH`; Apache uses `\"` and `\xhh`.
 * A trailing request-time field (`0.123` or `rt=0.123`), present only in customised log formats, is
 * picked up when it is there.
 */
class Modules_LiveTrafficMonitor_LogParser
{
    private const LINE = '/^(\S+) \S+ \S+ \[([^\]]+)\] "((?:[^"\\\\]|\\\\.)*)" (\d{3}) (\d+|-)(.*)$/s';
    private const REQUEST = '/^([A-Za-z]{1,16}) (\S+)(?: HTTP\/[0-9.]+)?$/';
    private const REQUEST_TIME = '/(?:^|\s)(?:rt=|request_time=)?(\d{1,6}\.\d{1,6})(?=\s|$)/';
    private const MONTHS = [
        'Jan' => 1, 'Feb' => 2, 'Mar' => 3, 'Apr' => 4, 'May' => 5, 'Jun' => 6,
        'Jul' => 7, 'Aug' => 8, 'Sep' => 9, 'Oct' => 10, 'Nov' => 11, 'Dec' => 12,
    ];

    /** @var array<string, int> Timestamps of recently seen `[time]` fields; log lines arrive in bursts per second. */
    private array $timeCache = [];

    /**
     * @return array{ts: int, ip: string, method: string, target: string, status: int, bytes: int, rt: ?float}|null
     *         `target` is the raw request target (path and query) with log escapes decoded to bytes.
     */
    public function parse(string $line): ?array
    {
        if (!preg_match(self::LINE, rtrim($line, "\r\n"), $m)) {
            return null;
        }
        $ts = $this->parseTime($m[2]);
        if ($ts === null) {
            return null;
        }

        $request = self::unescape($m[3]);
        if (preg_match(self::REQUEST, $request, $r)) {
            $method = strtoupper($r[1]);
            $target = $r[2];
        } else {
            // TLS handshakes on the HTTP port, garbage and "-": still real requests, usually 400.
            $method = '-';
            $target = '';
        }

        $rt = null;
        $rest = self::stripQuoted($m[6]);
        if ($rest !== '' && preg_match(self::REQUEST_TIME, $rest, $t)) {
            $rt = (float) $t[1];
        }

        return [
            'ts' => $ts,
            'ip' => $m[1],
            'method' => $method,
            'target' => $target,
            'status' => (int) $m[4],
            'bytes' => $m[5] === '-' ? 0 : (int) $m[5],
            'rt' => $rt,
        ];
    }

    /** Timestamp of a line, for binary search in a log file; null if the line has no parsable time. */
    public function parseLineTime(string $line): ?int
    {
        $open = strpos($line, '[');
        $close = $open === false ? false : strpos($line, ']', $open);
        if ($open === false || $close === false || $close - $open > 40) {
            return null;
        }
        return $this->parseTime(substr($line, $open + 1, $close - $open - 1));
    }

    /** `26/Oct/2026:11:17:44 +0000` → Unix time. */
    public function parseTime(string $s): ?int
    {
        if (isset($this->timeCache[$s])) {
            return $this->timeCache[$s];
        }
        if (!preg_match('/^(\d{2})\/([A-Z][a-z]{2})\/(\d{4}):(\d{2}):(\d{2}):(\d{2}) ([+-])(\d{2})(\d{2})$/', $s, $m)
            || !isset(self::MONTHS[$m[2]])) {
            return null;
        }
        $offset = ((int) $m[8] * 3600 + (int) $m[9] * 60) * ($m[7] === '-' ? -1 : 1);
        $ts = gmmktime((int) $m[4], (int) $m[5], (int) $m[6], self::MONTHS[$m[2]], (int) $m[1], (int) $m[3]) - $offset;

        if (count($this->timeCache) > 512) {
            $this->timeCache = [];
        }
        return $this->timeCache[$s] = $ts;
    }

    /** Decodes `\xHH`, `\"` and `\\` escapes back to the bytes the client sent. */
    private static function unescape(string $s): string
    {
        if (strpos($s, '\\') === false) {
            return $s;
        }
        return preg_replace_callback(
            '/\\\\(x[0-9A-Fa-f]{2}|.)/s',
            static fn (array $m): string => $m[1][0] === 'x' && strlen($m[1]) === 3 ? chr(hexdec(substr($m[1], 1))) : $m[1],
            $s
        );
    }

    /** Removes the quoted referer and user-agent fields, leaving any unquoted extra fields. */
    private static function stripQuoted(string $s): string
    {
        return trim(preg_replace('/"(?:[^"\\\\]|\\\\.)*"/s', ' ', $s));
    }
}
