<?php

namespace LiveTrafficMonitor\Tests;

use Modules_LiveTrafficMonitor_LogParser as LogParser;

final class LogParserTest extends TestCase
{
    public function testParsesNginxCombinedLine(): void
    {
        $entry = (new LogParser())->parse('200.92.175.25 - - [26/Oct/2026:11:17:44 +0000] "GET /produkt-etiket/pupil-books HTTP/1.1" 301 5 "https://mellyjolly.com/" "Mozilla/5.0"');

        self::assertSame([
            'ts' => gmmktime(11, 17, 44, 10, 26, 2026),
            'ip' => '200.92.175.25',
            'method' => 'GET',
            'target' => '/produkt-etiket/pupil-books',
            'status' => 301,
            'bytes' => 5,
            'rt' => null,
        ], $entry);
    }

    public function testAppliesTimezoneOffset(): void
    {
        $entry = (new LogParser())->parse('::1 - - [26/Oct/2026:13:17:44 +0200] "GET / HTTP/2.0" 200 1 "-" "-"');

        self::assertSame(gmmktime(11, 17, 44, 10, 26, 2026), $entry['ts']);
        self::assertSame('::1', $entry['ip']);
    }

    public function testDecodesNginxHexEscapesInRequest(): void
    {
        $entry = (new LogParser())->parse('1.2.3.4 - - [26/Oct/2026:11:17:44 +0000] "GET /\x22onmouseover=\x22x HTTP/1.1" 404 0 "-" "-"');

        self::assertSame('/"onmouseover="x', $entry['target']);
    }

    public function testDecodesApacheQuoteEscapes(): void
    {
        $entry = (new LogParser())->parse('1.2.3.4 - user [26/Oct/2026:11:17:44 +0000] "GET /a\"b HTTP/1.1" 200 - "-" "agent \"quoted\""');

        self::assertSame('/a"b', $entry['target']);
        self::assertSame(0, $entry['bytes']);
    }

    public function testInvalidRequestLineIsStillARequest(): void
    {
        $entry = (new LogParser())->parse('1.2.3.4 - - [26/Oct/2026:11:17:44 +0000] "\x16\x03\x01\x02\x00" 400 157 "-" "-"');

        self::assertSame('-', $entry['method']);
        self::assertSame(400, $entry['status']);
    }

    public function testReadsOptionalTrailingRequestTime(): void
    {
        $parser = new LogParser();

        self::assertSame(0.123, $parser->parse('1.2.3.4 - - [26/Oct/2026:11:17:44 +0000] "GET / HTTP/1.1" 200 1 "-" "Agent 1.0" 0.123')['rt']);
        self::assertSame(1.5, $parser->parse('1.2.3.4 - - [26/Oct/2026:11:17:44 +0000] "GET / HTTP/1.1" 200 1 "-" "-" rt=1.500')['rt']);
        self::assertNull($parser->parse('1.2.3.4 - - [26/Oct/2026:11:17:44 +0000] "GET / HTTP/1.1" 200 1 "-" "Agent 1.0"')['rt']);
    }

    public function testRejectsGarbage(): void
    {
        $parser = new LogParser();

        self::assertNull($parser->parse('garbage'));
        self::assertNull($parser->parse('1.2.3.4 - - [99/Foo/2026:11:17:44 +0000] "GET / HTTP/1.1" 200 1 "-" "-"'));
        self::assertNull($parser->parse(''));
    }

    public function testParseLineTime(): void
    {
        $parser = new LogParser();

        self::assertSame(gmmktime(0, 0, 1, 1, 2, 2026), $parser->parseLineTime(self::line(gmmktime(0, 0, 1, 1, 2, 2026))));
        self::assertNull($parser->parseLineTime('no time here'));
    }
}
