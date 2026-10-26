<?php
/**
 * Reads only the new, complete lines of an access log since the previous poll.
 *
 * The position is remembered per file as (device, inode, offset, first bytes). The reader starts
 * over from a point in time, found by binary search on the line timestamps, when:
 * - the file is new to the reader;
 * - it was replaced (new inode);
 * - it was truncated in place (Plesk's daily statistics run empties the logs this way);
 * - it was rewritten (different first bytes);
 * - the last poll is older than the retention period.
 * A large log is therefore never scanned from the start, and a partial last line is left for the
 * next poll.
 */
class Modules_LiveTrafficMonitor_LogReader
{
    private const HEAD_BYTES = 64;
    private const SEARCH_GRANULARITY = 8192;
    private const PROBE_LINES = 16;

    private Modules_LiveTrafficMonitor_LogParser $parser;

    public function __construct(Modules_LiveTrafficMonitor_LogParser $parser)
    {
        $this->parser = $parser;
    }

    /**
     * @param array{dev: int, ino: int, pos: int, head: string, seen: int}|null $state from the previous poll
     * @param int $startFrom Unix time to start at when the position is unknown or stale
     * @param int $maxBytes  read at most this many bytes
     * @param int $maxAge    seconds after which a remembered position is stale
     * @return array{lines: list<string>, bytes: int, pending: bool, state: array{dev: int, ino: int, pos: int, head: string, seen: int}}|null
     *         null if the file cannot be opened; `pending` means complete lines remain unread.
     */
    public function read(string $path, ?array $state, int $startFrom, int $maxBytes, int $now, int $maxAge): ?array
    {
        if (is_link($path) || !is_file($path)) {
            return null;
        }
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return null;
        }
        try {
            $stat = fstat($fh);
            $size = (int) $stat['size'];
            $head = $size > 0 ? (string) stream_get_contents($fh, self::HEAD_BYTES, 0) : '';

            $known = $state !== null
                && $state['dev'] === (int) $stat['dev']
                && $state['ino'] === (int) $stat['ino']
                && $state['pos'] <= $size
                && $now - $state['seen'] <= $maxAge
                && strncmp($head, $state['head'], strlen($state['head'])) === 0;

            if (!$known) {
                $pos = $this->seekToTime($fh, $size, $startFrom);
            } else {
                $pos = $state['pos'];
                if ($size - $pos > $maxBytes) {
                    // A backlog bigger than one poll: skip the part that is too old to be shown anyway.
                    $probe = $this->firstTimedLineAfter($fh, $pos, $pos === 0);
                    if ($probe !== null && $probe[1] < $startFrom) {
                        $pos = max($pos, $this->seekToTime($fh, $size, $startFrom));
                    }
                }
            }

            $lines = [];
            $length = min($maxBytes, $size - $pos);
            $data = $length > 0 ? (string) stream_get_contents($fh, $length, $pos) : '';
            $end = strrpos($data, "\n");
            if ($end !== false) {
                $lines = explode("\n", substr($data, 0, $end));
                $newPos = $pos + $end + 1;
            } elseif (strlen($data) === $maxBytes) {
                // A single line longer than the whole budget: skip it rather than stall on it.
                $newPos = $this->nextLineStart($fh, $pos + strlen($data), $size);
            } else {
                $newPos = $pos;
            }

            return [
                'lines' => $lines,
                'bytes' => strlen($data),
                'pending' => $pos + strlen($data) < $size,
                'state' => [
                    'dev' => (int) $stat['dev'],
                    'ino' => (int) $stat['ino'],
                    'pos' => $newPos,
                    'head' => $head,
                    'seen' => $now,
                ],
            ];
        } finally {
            fclose($fh);
        }
    }

    /**
     * Offset of the first line whose timestamp is at or after `$target`, or of the end of the last
     * complete line if there is none. Assumes timestamps are roughly ascending, which holds for
     * access logs; callers subtract a safety margin for the little disorder there is.
     *
     * @param resource $fh
     */
    public function seekToTime($fh, int $size, int $target): int
    {
        $lo = 0;
        $hi = $size;
        while ($hi - $lo > self::SEARCH_GRANULARITY) {
            $mid = intdiv($lo + $hi, 2);
            $probe = $this->firstTimedLineAfter($fh, $mid, false);
            if ($probe === null || $probe[0] >= $hi || $probe[1] >= $target) {
                $hi = $mid;
            } else {
                $lo = $probe[0];
            }
        }

        // $lo is 0 or the start of a line older than $target.
        fseek($fh, $lo);
        $pos = $lo;
        while (($line = fgets($fh)) !== false && substr($line, -1) === "\n") {
            $ts = $this->parser->parseLineTime($line);
            if ($ts !== null && $ts >= $target) {
                return $pos;
            }
            $pos += strlen($line);
        }
        return $pos;
    }

    /**
     * Start offset and timestamp of the first complete, timestamped line that starts after `$pos`
     * (at `$pos` itself when `$atLineStart`).
     *
     * @param resource $fh
     * @return array{0: int, 1: int}|null
     */
    private function firstTimedLineAfter($fh, int $pos, bool $atLineStart): ?array
    {
        fseek($fh, $pos);
        if (!$atLineStart && $pos > 0) {
            $partial = fgets($fh);
            if ($partial === false) {
                return null;
            }
            $pos += strlen($partial);
        }
        for ($i = 0; $i < self::PROBE_LINES; $i++) {
            $line = fgets($fh);
            if ($line === false || substr($line, -1) !== "\n") {
                return null;
            }
            $ts = $this->parser->parseLineTime($line);
            if ($ts !== null) {
                return [$pos, $ts];
            }
            $pos += strlen($line);
        }
        return null;
    }

    /** @param resource $fh */
    private function nextLineStart($fh, int $pos, int $size): int
    {
        fseek($fh, $pos);
        $rest = fgets($fh);
        if ($rest === false || substr($rest, -1) !== "\n") {
            return $size;
        }
        return $pos + strlen($rest);
    }
}
