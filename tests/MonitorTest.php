<?php

namespace LiveTrafficMonitor\Tests;

use Modules_LiveTrafficMonitor_Monitor as Monitor;

final class MonitorTest extends TestCase
{
    private const NOW = 1793000000;

    private function site(string $name, ?string $displayName = null): array
    {
        $logDir = $this->dir . '/vhosts/system/' . $name . '/logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0700, true);
        }
        return ['name' => $name, 'displayName' => $displayName ?? $name, 'logDir' => $logDir];
    }

    private function append(array $site, string $file, string ...$lines): void
    {
        file_put_contents($site['logDir'] . '/' . $file, implode('', $lines), FILE_APPEND);
    }

    private function monitor(array $options = []): Monitor
    {
        return new Monitor($this->dir . '/state', $options);
    }

    public function testRollingWindowsCountRequestsInsideEachWindow(): void
    {
        $site = $this->site('a.com');
        $this->append($site, 'proxy_access_ssl_log',
            self::line(self::NOW - 400),
            self::line(self::NOW - 300), // exactly 300 s old: outside the 5-minute window
            self::line(self::NOW - 299),
            self::line(self::NOW - 120),
            self::line(self::NOW - 119),
            self::line(self::NOW - 60),
            self::line(self::NOW - 59),
            self::line(self::NOW),
            self::line(self::NOW + 5) // clock skew: not yet in any window
        );
        $monitor = $this->monitor();

        self::assertSame(2, $monitor->poll([$site], 60, null, self::NOW)['total']);
        self::assertSame(4, $monitor->poll([$site], 120, null, self::NOW)['total']);
        self::assertSame(6, $monitor->poll([$site], 300, null, self::NOW)['total']);

        // Rolling: 30 s later the oldest of the minute has aged out, and the skewed line has arrived.
        self::assertSame(2, $monitor->poll([$site], 60, null, self::NOW + 30)['total']);
    }

    public function testResponseShape(): void
    {
        $site = $this->site('xn--80ak6aa92e.com', 'пример.com');
        $this->append($site, 'proxy_access_ssl_log',
            self::line(self::NOW - 3, 'GET /a?token=secret123 HTTP/2.0', 200, '198.51.100.1', 1234),
            self::line(self::NOW - 2, 'GET /missing HTTP/2.0', 404),
            self::line(self::NOW - 1, 'POST /api HTTP/2.0', 502)
        );
        $this->append($site, 'proxy_access_log', self::line(self::NOW, 'GET / HTTP/1.1', 301));

        $r = $this->monitor()->poll([$site], 60, null, self::NOW);

        self::assertTrue($r['ok']);
        self::assertSame(self::NOW, $r['generatedAt']);
        self::assertSame('all', $r['domain']);
        self::assertSame('all', $r['container']);
        self::assertSame(4, $r['total']);
        self::assertSame(round(4 / 60, 2), $r['rps']);
        self::assertSame(['2xx' => 1, '3xx' => 1, '4xx' => 1, '5xx' => 1, 'other' => 0], $r['byStatusClass']);
        self::assertSame([['domain' => 'xn--80ak6aa92e.com', 'count' => 4, 'displayName' => 'пример.com']], $r['byDomain']);
        self::assertSame(['/', '/api', '/missing', '/a?token=[redacted]'], array_column($r['rows'], 'path'));
        self::assertSame(['http', 'https', 'https', 'https'], array_column($r['rows'], 'scheme'));
        self::assertSame([
            'ts' => self::NOW - 3,
            'domain' => 'xn--80ak6aa92e.com',
            'scheme' => 'https',
            'method' => 'GET',
            'path' => '/a?token=[redacted]',
            'status' => 200,
            'bytes' => 1234,
            'ip' => '198.51.100.1',
            'rt' => null,
            'src' => 'nginx',
            'container' => null,
        ], $r['rows'][3]);
        self::assertSame([], $r['byContainer']);
        self::assertNull($r['docker']);
        self::assertFalse($r['truncated']);
        self::assertFalse($r['lagging']);
        self::assertSame(['read' => 2, 'unreadable' => 0], $r['files']);
        self::assertStringNotContainsString('secret123', json_encode($r));
    }

    public function testDomainFilterKeepsFullDomainBreakdown(): void
    {
        $a = $this->site('a.com');
        $b = $this->site('b.com');
        $this->append($a, 'proxy_access_ssl_log', self::line(self::NOW - 5), self::line(self::NOW - 4));
        $this->append($b, 'access_ssl_log', self::line(self::NOW - 3, 'GET /b HTTP/1.1', 500));

        $r = $this->monitor()->poll([$a, $b], 60, 'b.com', self::NOW);

        self::assertSame('b.com', $r['domain']);
        self::assertSame(1, $r['total']);
        self::assertSame(['b.com'], array_unique(array_column($r['rows'], 'domain')));
        self::assertSame([['status' => 500, 'count' => 1]], $r['byStatus']);
        self::assertSame(['a.com' => 2, 'b.com' => 1], array_column($r['byDomain'], 'count', 'domain'));
    }

    public function testXAccelDuplicateCountsOnceWithNginxStatus(): void
    {
        $site = $this->site('crm.example.com');
        $this->append($site, 'access_ssl_log',
            self::line(self::NOW - 10, 'GET /wp-content/font.woff2 HTTP/1.1', 200, '85.118.76.145', 5533),
            self::line(self::NOW - 9, 'GET /wp-json/api HTTP/1.1', 200, '85.118.76.145')
        );
        $monitor = $this->monitor();

        // Apache's line can be read before nginx has logged the response.
        self::assertSame(2, $monitor->poll([$site], 60, null, self::NOW)['total']);

        $this->append($site, 'proxy_access_ssl_log', self::line(self::NOW - 9, 'GET /wp-content/font.woff2 HTTP/3.0', 304, '85.118.76.145', 0));
        $r = $monitor->poll([$site], 60, null, self::NOW + 1);

        self::assertSame(2, $r['total']);
        $font = array_values(array_filter($r['rows'], static fn (array $row): bool => $row['path'] === '/wp-content/font.woff2'));
        self::assertCount(1, $font);
        self::assertSame(['status' => 304, 'src' => 'nginx'], ['status' => $font[0]['status'], 'src' => $font[0]['src']]);
    }

    public function testSameRequestFromAnotherClientOrSchemeIsNotADuplicate(): void
    {
        $site = $this->site('a.com');
        $this->append($site, 'access_ssl_log',
            self::line(self::NOW - 10, 'GET /x HTTP/1.1', 200, '192.0.2.1'),
            self::line(self::NOW - 10, 'GET /x HTTP/1.1', 200, '192.0.2.2')
        );
        $this->append($site, 'proxy_access_ssl_log', self::line(self::NOW - 10, 'GET /x HTTP/2.0', 200, '192.0.2.1'));
        $this->append($site, 'proxy_access_log', self::line(self::NOW - 10, 'GET /x HTTP/1.1', 301, '192.0.2.2'));

        // nginx+Apache pair for .1 counts once; .2's http redirect and its https request are two requests.
        self::assertSame(3, $this->monitor()->poll([$site], 60, null, self::NOW)['total']);
    }

    public function testDuplicateDetectedWhenNginxLogsLateLongDownload(): void
    {
        $site = $this->site('a.com');
        $this->append($site, 'access_ssl_log', self::line(self::NOW - 50, 'GET /big.zip HTTP/1.1'));
        $this->append($site, 'proxy_access_ssl_log', self::line(self::NOW - 5, 'GET /big.zip HTTP/2.0'));

        self::assertSame(1, $this->monitor()->poll([$site], 60, null, self::NOW)['total']);
    }

    public function testRowLimitTruncates(): void
    {
        $site = $this->site('a.com');
        $this->append($site, 'proxy_access_ssl_log', ...array_map(static fn (int $i): string => self::line(self::NOW - 50 + $i, "GET /$i HTTP/1.1"), range(0, 9)));

        $r = $this->monitor(['rowLimit' => 3])->poll([$site], 60, null, self::NOW);

        self::assertSame(10, $r['total']);
        self::assertTrue($r['truncated']);
        self::assertSame(['/9', '/8', '/7'], array_column($r['rows'], 'path'));
    }

    public function testByteBudgetCatchesUpOverPollsWithoutStarvingLaterLogs(): void
    {
        $sites = [$this->site('a.com'), $this->site('b.com')];
        $this->append($sites[0], 'proxy_access_ssl_log', ...array_fill(0, 200, self::line(self::NOW - 20)));
        $this->append($sites[1], 'proxy_access_ssl_log', ...array_fill(0, 200, self::line(self::NOW - 20)));
        $monitor = $this->monitor(['maxBytesPerFile' => 4096, 'maxBytesPerPoll' => 6000]);

        $first = $monitor->poll($sites, 60, null, self::NOW);
        self::assertTrue($first['lagging']);
        self::assertSame(['a.com', 'b.com'], array_column($first['byDomain'], 'domain'));

        for ($i = 0; $i < 20 && ($r = $monitor->poll($sites, 60, null, self::NOW))['lagging']; $i++) {
        }
        self::assertFalse($r['lagging']);
        self::assertSame(400, $r['total']);
    }

    public function testParseErrorsAndUnreadableFilesAreReported(): void
    {
        $site = $this->site('a.com');
        $this->append($site, 'proxy_access_ssl_log', self::line(self::NOW - 2));
        file_put_contents($this->dir . '/elsewhere', self::line(self::NOW - 1));
        symlink($this->dir . '/elsewhere', $site['logDir'] . '/access_ssl_log');
        $monitor = $this->monitor();
        $monitor->poll([$site], 60, null, self::NOW);
        $this->append($site, 'proxy_access_ssl_log', "garbage\n", self::line(self::NOW - 1));

        $r = $monitor->poll([$site], 60, null, self::NOW);

        self::assertSame(2, $r['total']);
        self::assertSame(1, $r['parseErrors']);
        self::assertSame(['read' => 1, 'unreadable' => 1], $r['files']);
    }

    public function testOldRequestsArePruned(): void
    {
        $site = $this->site('a.com');
        $this->append($site, 'proxy_access_ssl_log', self::line(self::NOW - 10));
        $monitor = $this->monitor();
        $monitor->poll([$site], 300, null, self::NOW);

        $db = new \PDO('sqlite:' . $this->dir . '/state/traffic.sqlite');
        self::assertSame(1, (int) $db->query('SELECT COUNT(*) FROM requests')->fetchColumn());

        $monitor->poll([$site], 300, null, self::NOW + Monitor::RETENTION);
        self::assertSame(0, (int) $db->query('SELECT COUNT(*) FROM requests')->fetchColumn());
    }

    public function testBusyLockAnswersFromStore(): void
    {
        $site = $this->site('a.com');
        $this->append($site, 'proxy_access_ssl_log', self::line(self::NOW - 1));
        $monitor = $this->monitor(['lockWait' => 0.1]);
        $monitor->poll([$site], 60, null, self::NOW);
        $this->append($site, 'proxy_access_ssl_log', self::line(self::NOW));

        $lock = fopen($this->dir . '/state/ingest.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            $r = $monitor->poll([$site], 60, null, self::NOW);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        self::assertTrue($r['lagging']);
        self::assertSame(1, $r['total']);
        self::assertSame(2, $monitor->poll([$site], 60, null, self::NOW)['total']);
    }

    public function testRejectsUnsupportedWindow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->monitor()->poll([], 30, null, self::NOW);
    }

    /** A Modules_LiveTrafficMonitor_DockerRoutes::load() result for a.com. */
    private static function docker(array $routes, array $containers, bool $available = true): array
    {
        $info = [];
        foreach ($containers as $name => $state) {
            $info[$name] = ['container' => $name, 'image' => $name . ':1', 'state' => $state, 'health' => null,
                'status' => null, 'domains' => ['a.com']];
        }
        return [
            'available' => $available,
            'error' => $available ? null : 'Docker is down',
            'routes' => ['a.com' => $routes],
            'containers' => $info,
        ];
    }

    public function testDockerProxiedRequestsAreTaggedWithTheirContainer(): void
    {
        $site = $this->site('a.com');
        $this->append($site, 'proxy_access_ssl_log',
            self::line(self::NOW - 6, 'GET /api/orders?id=1 HTTP/2.0', 502),
            self::line(self::NOW - 5, 'GET /shop HTTP/2.0'),
            self::line(self::NOW - 4, 'GET /.well-known/acme-challenge/abc HTTP/1.1'),
            self::line(self::NOW - 3, "\x16\x03\x01", 400)
        );
        $this->append($site, 'access_ssl_log', self::line(self::NOW - 2, 'GET /legacy.php HTTP/1.0'));
        $docker = self::docker([
            ['prefix' => '/api', 'container' => 'api', 'schemes' => ['https', 'http']],
            ['prefix' => '/', 'container' => 'web', 'schemes' => ['https', 'http']],
        ], ['web' => 'running', 'api' => 'exited']);

        $r = $this->monitor()->poll([$site], 60, null, self::NOW, $docker);

        self::assertSame(
            ['/legacy.php' => null, '(invalid request)' => null, '/.well-known/acme-challenge/abc' => null, '/shop' => 'web', '/api/orders?id=1' => 'api'],
            array_column($r['rows'], 'container', 'path')
        );
        self::assertSame(['available' => true, 'error' => null], $r['docker']);
        self::assertSame([
            ['container' => 'api', 'image' => 'api:1', 'state' => 'exited', 'health' => null, 'status' => null,
                'domains' => ['a.com'], 'count' => 1, 'errors' => 1],
            ['container' => 'web', 'image' => 'web:1', 'state' => 'running', 'health' => null, 'status' => null,
                'domains' => ['a.com'], 'count' => 1, 'errors' => 0],
        ], $r['byContainer']);
    }

    public function testContainerFilterKeepsFullContainerBreakdown(): void
    {
        $site = $this->site('a.com');
        $this->append($site, 'proxy_access_ssl_log',
            self::line(self::NOW - 3, 'GET /api/a HTTP/2.0'),
            self::line(self::NOW - 2, 'GET /api/b HTTP/2.0', 503),
            self::line(self::NOW - 1, 'GET / HTTP/2.0')
        );
        $docker = self::docker([
            ['prefix' => '/api', 'container' => 'api', 'schemes' => ['https']],
            ['prefix' => '/', 'container' => 'web', 'schemes' => ['https']],
        ], ['web' => 'running', 'api' => 'running']);

        $r = $this->monitor()->poll([$site], 60, null, self::NOW, $docker, 'api');

        self::assertSame('api', $r['container']);
        self::assertSame(2, $r['total']);
        self::assertSame(['/api/b', '/api/a'], array_column($r['rows'], 'path'));
        self::assertSame([['status' => 200, 'count' => 1], ['status' => 503, 'count' => 1]], $r['byStatus']);
        self::assertSame(['api' => 2, 'web' => 1], array_column($r['byContainer'], 'count', 'container'));
        self::assertSame(['a.com' => 3], array_column($r['byDomain'], 'count', 'domain'));
    }

    public function testHttpRequestsOfSitesThatRedirectToHttpsAreNotTagged(): void
    {
        $site = $this->site('a.com');
        $this->append($site, 'proxy_access_log', self::line(self::NOW - 2, 'GET / HTTP/1.1', 301));
        $this->append($site, 'proxy_access_ssl_log', self::line(self::NOW - 1, 'GET / HTTP/2.0'));
        $docker = self::docker([['prefix' => '/', 'container' => 'web', 'schemes' => ['https']]], ['web' => 'running']);

        $r = $this->monitor()->poll([$site], 60, null, self::NOW, $docker);

        self::assertSame(['https' => 'web', 'http' => null], array_column($r['rows'], 'container', 'scheme'));
    }

    public function testByContainerListsIdleContainersAndOnesThatAreGone(): void
    {
        $site = $this->site('a.com');
        $this->append($site, 'proxy_access_ssl_log', self::line(self::NOW - 2));
        $monitor = $this->monitor();
        $monitor->poll([$site], 60, null, self::NOW,
            self::docker([['prefix' => '/', 'container' => 'old', 'schemes' => ['https']]], ['old' => 'running']));

        // The rule now points at a new, idle container; the old one's request is still in the window.
        $r = $monitor->poll([$site], 60, null, self::NOW,
            self::docker([['prefix' => '/', 'container' => 'new', 'schemes' => ['https']]], ['new' => 'created'], false));

        self::assertSame(['available' => false, 'error' => 'Docker is down'], $r['docker']);
        self::assertSame([
            ['container' => 'old', 'image' => null, 'state' => 'unknown', 'health' => null, 'status' => null,
                'domains' => [], 'count' => 1, 'errors' => 0],
            ['container' => 'new', 'image' => 'new:1', 'state' => 'created', 'health' => null, 'status' => null,
                'domains' => ['a.com'], 'count' => 0, 'errors' => 0],
        ], $r['byContainer']);
    }

    public function testStateDirectoryIsPrivate(): void
    {
        $this->monitor()->poll([], 60, null, self::NOW);

        self::assertSame('0700', substr(sprintf('%o', fileperms($this->dir . '/state')), -4));
    }
}
