<?php

namespace LiveTrafficMonitor\Tests;

use Modules_LiveTrafficMonitor_LogParser as LogParser;
use Modules_LiveTrafficMonitor_LogReader as LogReader;

final class LogReaderTest extends TestCase
{
    private const NOW = 1793000000;
    private const MAX_AGE = 600;

    private LogReader $reader;
    private string $log;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reader = new LogReader(new LogParser());
        $this->log = $this->dir . '/proxy_access_ssl_log';
    }

    private function read(?array $state, int $maxBytes = 1 << 20, int $now = self::NOW, int $startFrom = self::NOW - 330): array
    {
        $result = $this->reader->read($this->log, $state, $startFrom, $maxBytes, $now, self::MAX_AGE);
        self::assertNotNull($result);
        return $result;
    }

    private static function paths(array $result): array
    {
        return array_map(static fn (string $l): string => explode(' ', explode('"', $l)[1])[1], $result['lines']);
    }

    public function testFirstReadStartsAtStartTimeAndThenReadsOnlyNewLines(): void
    {
        file_put_contents($this->log, self::line(self::NOW - 1000, 'GET /old HTTP/1.1') . self::line(self::NOW - 10, 'GET /a HTTP/1.1'));

        $first = $this->read(null);
        self::assertSame(['/a'], self::paths($first));
        self::assertFalse($first['pending']);

        file_put_contents($this->log, self::line(self::NOW - 5, 'GET /b HTTP/1.1'), FILE_APPEND);
        $second = $this->read($first['state']);
        self::assertSame(['/b'], self::paths($second));

        $third = $this->read($second['state']);
        self::assertSame([], $third['lines']);
    }

    public function testPartialLastLineWaitsForNextPoll(): void
    {
        file_put_contents($this->log, self::line(self::NOW - 10, 'GET /a HTTP/1.1') . substr(self::line(self::NOW - 9, 'GET /b HTTP/1.1'), 0, 30));

        $first = $this->read(null);
        self::assertSame(['/a'], self::paths($first));

        file_put_contents($this->log, substr(self::line(self::NOW - 9, 'GET /b HTTP/1.1'), 30), FILE_APPEND);
        self::assertSame(['/b'], self::paths($this->read($first['state'])));
    }

    public function testTruncationInPlaceStartsOver(): void
    {
        file_put_contents($this->log, self::line(self::NOW - 20, 'GET /a HTTP/1.1') . self::line(self::NOW - 19, 'GET /b HTTP/1.1'));
        $first = $this->read(null);

        // Plesk's statistics run empties the log in place (same inode).
        $fh = fopen($this->log, 'r+');
        ftruncate($fh, 0);
        fclose($fh);
        file_put_contents($this->log, self::line(self::NOW - 1, 'GET /c HTTP/1.1'), FILE_APPEND);

        self::assertSame(['/c'], self::paths($this->read($first['state'])));
    }

    public function testTruncatedAndRegrownPastOldOffsetIsDetectedByHead(): void
    {
        file_put_contents($this->log, self::line(self::NOW - 20, 'GET /a HTTP/1.1'));
        $first = $this->read(null);

        file_put_contents($this->log, self::line(self::NOW - 3, 'GET /new1 HTTP/1.1') . self::line(self::NOW - 2, 'GET /new2 HTTP/1.1'));

        self::assertSame(['/new1', '/new2'], self::paths($this->read($first['state'])));
    }

    public function testRotationToNewInodeStartsOver(): void
    {
        file_put_contents($this->log, self::line(self::NOW - 20, 'GET /a HTTP/1.1'));
        $first = $this->read(null);

        rename($this->log, $this->log . '.1');
        file_put_contents($this->log, self::line(self::NOW - 1, 'GET /b HTTP/1.1') . str_repeat(self::line(self::NOW - 1, 'GET /pad HTTP/1.1'), 3));

        $second = $this->read($first['state']);
        self::assertSame('/b', self::paths($second)[0]);
        self::assertNotSame($first['state']['ino'], $second['state']['ino']);
    }

    public function testStaleStateStartsOverAtStartTime(): void
    {
        file_put_contents($this->log, self::line(self::NOW - 2000, 'GET /a HTTP/1.1'));
        $first = $this->read(null, 1 << 20, self::NOW - 1900, self::NOW - 2230);
        self::assertSame(['/a'], self::paths($first));

        file_put_contents($this->log, self::line(self::NOW - 900, 'GET /old HTTP/1.1') . self::line(self::NOW - 10, 'GET /recent HTTP/1.1'), FILE_APPEND);

        self::assertSame(['/recent'], self::paths($this->read($first['state'])));
    }

    public function testBinarySearchFindsStartInLargeFile(): void
    {
        $fh = fopen($this->log, 'w');
        for ($ts = self::NOW - 7200; $ts <= self::NOW; $ts++) {
            fwrite($fh, str_repeat(self::line($ts, 'GET /p' . $ts . ' HTTP/1.1'), 3));
        }
        fclose($fh);

        $fh = fopen($this->log, 'rb');
        $pos = $this->reader->seekToTime($fh, filesize($this->log), self::NOW - 60);
        fseek($fh, $pos);
        $line = fgets($fh);
        fclose($fh);

        self::assertStringContainsString('/p' . (self::NOW - 60) . ' ', $line);
        if ($pos > 0) {
            self::assertSame("\n", file_get_contents($this->log, false, null, $pos - 1, 1));
            self::assertStringContainsString('/p' . (self::NOW - 61) . ' ', $this->lineBefore($pos));
        }
    }

    private function lineBefore(int $pos): string
    {
        $chunk = file_get_contents($this->log, false, null, max(0, $pos - 400), min(400, $pos));
        $lines = explode("\n", rtrim($chunk, "\n"));
        return end($lines);
    }

    public function testSeekPastEndReturnsEndOfLastCompleteLine(): void
    {
        $content = self::line(self::NOW - 100) . self::line(self::NOW - 90);
        file_put_contents($this->log, $content . 'partial');

        $fh = fopen($this->log, 'rb');
        self::assertSame(strlen($content), $this->reader->seekToTime($fh, filesize($this->log), self::NOW));
        fclose($fh);
    }

    public function testByteBudgetLeavesRestPending(): void
    {
        file_put_contents($this->log, str_repeat(self::line(self::NOW - 10), 100));
        $lineLength = strlen(self::line(self::NOW - 10));

        $first = $this->read(null, $lineLength * 10 + 5);
        self::assertCount(10, $first['lines']);
        self::assertTrue($first['pending']);

        $total = count($first['lines']);
        $state = $first['state'];
        while (true) {
            $next = $this->read($state, $lineLength * 10 + 5);
            $total += count($next['lines']);
            $state = $next['state'];
            if (!$next['pending']) {
                break;
            }
        }
        self::assertSame(100, $total);
    }

    public function testBacklogOlderThanStartTimeIsSkipped(): void
    {
        file_put_contents($this->log, self::line(self::NOW - 1000, 'GET /a HTTP/1.1'));
        // Remembered position is fresh (200 s old), but the backlog behind it is bigger than one read.
        $first = $this->read(null, 1 << 20, self::NOW - 500, self::NOW - 1330);
        self::assertSame(['/a'], self::paths($first));

        $fh = fopen($this->log, 'a');
        for ($ts = self::NOW - 990; $ts < self::NOW - 400; $ts++) {
            fwrite($fh, self::line($ts, 'GET /backlog HTTP/1.1'));
        }
        fwrite($fh, self::line(self::NOW - 5, 'GET /recent HTTP/1.1'));
        fclose($fh);

        $second = $this->read($first['state'], 4096, self::NOW - 300);
        self::assertSame(['/recent'], self::paths($second));
    }

    public function testOverlongLineIsSkipped(): void
    {
        file_put_contents($this->log, self::line(self::NOW - 10, 'GET /' . str_repeat('x', 5000) . ' HTTP/1.1') . self::line(self::NOW - 9, 'GET /after HTTP/1.1'));

        $first = $this->read(['dev' => fstat(fopen($this->log, 'r'))['dev'], 'ino' => fileinode($this->log), 'pos' => 0, 'head' => '', 'seen' => self::NOW], 1024);
        self::assertSame([], $first['lines']);

        self::assertSame(['/after'], self::paths($this->read($first['state'], 1024)));
    }

    public function testSymlinksAndMissingFilesAreNotRead(): void
    {
        file_put_contents($this->dir . '/target', self::line(self::NOW));
        symlink($this->dir . '/target', $this->log);

        self::assertNull($this->reader->read($this->log, null, 0, 1024, self::NOW, self::MAX_AGE));
        self::assertNull($this->reader->read($this->dir . '/missing', null, 0, 1024, self::NOW, self::MAX_AGE));
    }
}
