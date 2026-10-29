<?php

namespace LiveTrafficMonitor\Tests;

use Modules_LiveTrafficMonitor_DockerClient as DockerClient;
use Modules_LiveTrafficMonitor_DockerRoutes as DockerRoutes;

final class DockerRoutesTest extends TestCase
{
    private const WEB = 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1';
    private const API = 'b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2';
    private const OLD = 'c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3';

    /** @var list<string> paths requested from the fake Docker */
    private array $requests = [];

    /** The Docker extension's database, with the columns Plesk's Docker extension 2.2 has. */
    private function rulesDb(array $rules, array $configuration = []): string
    {
        $file = $this->dir . '/db.sqlite3';
        $db = new \PDO('sqlite:' . $file, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $db->exec('CREATE TABLE Configurations (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, description TEXT DEFAULT NULL,
            host TEXT DEFAULT NULL, port TEXT DEFAULT NULL, active INTEGER NOT NULL DEFAULT 0, remote INTEGER NOT NULL DEFAULT 0,
            ssl INTEGER NOT NULL DEFAULT 1, sslCert TEXT DEFAULT NULL, sslKey TEXT DEFAULT NULL, sslCa TEXT DEFAULT NULL,
            dockerSocket TEXT DEFAULT NULL, hubUrl TEXT DEFAULT NULL, hubApiUrl TEXT DEFAULT NULL)');
        $db->exec('CREATE TABLE ProxyRules (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, domainId INTEGER NOT NULL,
            configurationId INTEGER NOT NULL, path TEXT DEFAULT NULL, port TEXT DEFAULT NULL,
            supportWSTraffic TINYINT DEFAULT 0 NOT NULL, host TEXT DEFAULT NULL)');
        $db->prepare('INSERT INTO Configurations (id, active, remote, sslKey, dockerSocket) VALUES (1, 1, ?, ?, ?)')
            ->execute([$configuration['remote'] ?? 0, 'PRIVATE KEY', $configuration['socket'] ?? null]);
        $insert = $db->prepare('INSERT INTO ProxyRules (domainId, configurationId, path, port, host) VALUES (?, 1, ?, ?, ?)');
        foreach ($rules as [$domainId, $path, $port, $host]) {
            $insert->execute([$domainId, $path, $port, $host]);
        }
        return $file;
    }

    /** @param array<string, mixed> $answers path => decoded JSON, or a RuntimeException to throw */
    private function routes(string $rulesDb, array $answers, ?array &$sockets = null, ?string $cacheFile = null): DockerRoutes
    {
        $sockets = [];
        $requests = &$this->requests;
        return new DockerRoutes($rulesDb, $cacheFile, static function (string $socket) use ($answers, &$requests, &$sockets): DockerClient {
            $sockets[] = $socket;
            return new class ($socket, $answers, $requests) extends DockerClient {
                public function __construct(string $socket, private array $answers, private array &$log)
                {
                    parent::__construct($socket);
                }

                public function get(string $path)
                {
                    $this->log[] = $path;
                    $answer = $this->answers[$path] ?? new \RuntimeException('Docker answered: No such container');
                    if ($answer instanceof \Throwable) {
                        throw $answer;
                    }
                    return $answer;
                }
            };
        });
    }

    private static function sites(): array
    {
        return [
            ['name' => 'shop.example', 'id' => 82, 'sslRedirect' => true],
            ['name' => 'blog.example', 'id' => 73, 'sslRedirect' => false],
        ];
    }

    private static function containers(): array
    {
        return [
            ['Id' => self::WEB, 'Names' => ['/web'], 'Image' => 'web:2026-10-28', 'State' => 'running', 'Status' => 'Up 12 hours (healthy)',
                'Ports' => [['IP' => '127.0.0.1', 'PrivatePort' => 8080, 'PublicPort' => 18080, 'Type' => 'tcp'], ['PrivatePort' => 443, 'Type' => 'tcp']]],
            ['Id' => self::API, 'Names' => ['/api'], 'Image' => 'api:1.4', 'State' => 'exited', 'Status' => 'Exited (1) 3 minutes ago', 'Ports' => []],
            ['Id' => self::OLD, 'Names' => ['/api-old'], 'Image' => 'api:1.3', 'State' => 'exited', 'Status' => 'Exited (0) 2 weeks ago', 'Ports' => []],
            ['Id' => 'not-an-id', 'Names' => ['/odd'], 'State' => 'running', 'Ports' => []],
        ];
    }

    public function testWithoutTheDockerExtensionThereIsNothingToShow(): void
    {
        self::assertNull($this->routes($this->dir . '/missing.sqlite3', [])->load(self::sites()));
        self::assertSame([], DockerRoutes::containerList(null));
    }

    public function testRulesOfOtherWebsitesAreIgnored(): void
    {
        $db = $this->rulesDb([[999, '', '18080', '127.0.0.1']]);

        self::assertNull($this->routes($db, [])->load(self::sites()));
        self::assertSame([], $this->requests);
    }

    public function testRulesAreJoinedToTheContainersPublishingTheirPorts(): void
    {
        $db = $this->rulesDb([
            [82, '', '18080', '127.0.0.1'],
            [73, '', '18080', '0.0.0.0'],
            [73, 'api/', '18081', '127.0.0.1'],
        ]);
        $answers = [
            '/containers/json?all=1' => self::containers(),
            '/containers/' . self::API . '/json' => ['HostConfig' => ['PortBindings' => ['8080/tcp' => [['HostIp' => '127.0.0.1', 'HostPort' => '18081']]]]],
            '/containers/' . self::OLD . '/json' => ['HostConfig' => ['PortBindings' => ['8080/tcp' => [['HostIp' => '127.0.0.1', 'HostPort' => '18081']]]]],
        ];

        $docker = $this->routes($db, $answers, $sockets)->load(self::sites());

        self::assertSame([DockerClient::DEFAULT_SOCKET], $sockets);
        self::assertTrue($docker['available']);
        self::assertNull($docker['error']);
        self::assertSame([
            'shop.example' => [['prefix' => '/', 'container' => 'web', 'schemes' => ['https']]],
            'blog.example' => [
                ['prefix' => '/api/', 'container' => 'api', 'schemes' => ['https', 'http']],
                ['prefix' => '/', 'container' => 'web', 'schemes' => ['https', 'http']],
            ],
        ], $docker['routes']);
        self::assertSame([
            'api' => ['container' => 'api', 'image' => 'api:1.4', 'state' => 'exited', 'health' => null,
                'status' => 'Exited (1) 3 minutes ago', 'domains' => ['blog.example']],
            'web' => ['container' => 'web', 'image' => 'web:2026-10-28', 'state' => 'running', 'health' => 'healthy',
                'status' => 'Up 12 hours (healthy)', 'domains' => ['blog.example', 'shop.example']],
        ], $docker['containers']);
        // The newest stopped container with the binding wins, so the older one is never inspected.
        self::assertSame(['/containers/json?all=1', '/containers/' . self::API . '/json'], $this->requests);
        self::assertSame(
            [['name' => 'api', 'domains' => ['blog.example']], ['name' => 'web', 'domains' => ['blog.example', 'shop.example']]],
            DockerRoutes::containerList($docker)
        );
    }

    public function testRuleWithoutContainerIsNamedByItsAddress(): void
    {
        $db = $this->rulesDb([[82, '', '18099', '127.0.0.1']]);

        $docker = $this->routes($db, ['/containers/json?all=1' => self::containers()])->load(self::sites());

        self::assertTrue($docker['available']);
        self::assertSame(['127.0.0.1:18099'], array_keys($docker['containers']));
        self::assertSame('missing', $docker['containers']['127.0.0.1:18099']['state']);
        self::assertSame('127.0.0.1:18099', $docker['routes']['shop.example'][0]['container']);
    }

    public function testDockerNotReachable(): void
    {
        $db = $this->rulesDb([[82, '', '18080', '127.0.0.1']], ['socket' => 'unix:///run/custom-docker.sock']);
        $error = new \RuntimeException('Cannot connect to Docker at /run/custom-docker.sock: Permission denied');

        $docker = $this->routes($db, ['/containers/json?all=1' => $error], $sockets)->load(self::sites());

        self::assertSame(['/run/custom-docker.sock'], $sockets);
        self::assertFalse($docker['available']);
        self::assertSame('Cannot connect to Docker at /run/custom-docker.sock: Permission denied', $docker['error']);
        self::assertSame('unknown', $docker['containers']['127.0.0.1:18080']['state']);
        self::assertSame('127.0.0.1:18080', $docker['routes']['shop.example'][0]['container']);
    }

    public function testWhileDockerIsDownTheLastKnownContainerKeepsItsName(): void
    {
        $db = $this->rulesDb([[82, '', '18080', '127.0.0.1'], [73, '', '18099', '127.0.0.1']]);
        $cache = $this->dir . '/docker.json';
        $this->routes($db, ['/containers/json?all=1' => self::containers()], $sockets, $cache)->load(self::sites());

        $down = ['/containers/json?all=1' => new \RuntimeException('Docker did not answer in time')];
        $docker = $this->routes($db, $down, $sockets, $cache)->load(self::sites());

        self::assertFalse($docker['available']);
        self::assertSame('web', $docker['routes']['shop.example'][0]['container']);
        self::assertSame(['container' => 'web', 'image' => 'web:2026-10-28', 'state' => 'unknown', 'health' => null,
            'status' => null, 'domains' => ['shop.example']], $docker['containers']['web']);
        // A rule whose container was never seen is still named by its address.
        self::assertSame('127.0.0.1:18099', $docker['routes']['blog.example'][0]['container']);
    }

    public function testRemoteDockerIsNotContacted(): void
    {
        $db = $this->rulesDb([[82, '', '18080', '192.0.2.10']], ['remote' => 1]);

        $docker = $this->routes($db, [], $sockets)->load(self::sites());

        self::assertSame([], $sockets);
        self::assertTrue($docker['available']);
        self::assertSame(['192.0.2.10:18080'], array_keys($docker['containers']));
        self::assertSame('unknown', $docker['containers']['192.0.2.10:18080']['state']);
    }

    public function testUnreadableRulesAreReported(): void
    {
        file_put_contents($this->dir . '/db.sqlite3', 'not a database');

        $docker = $this->routes($this->dir . '/db.sqlite3', [])->load(self::sites());

        self::assertSame(
            ['available' => false, 'error' => "Cannot read the Docker extension's proxy rules", 'routes' => [], 'containers' => []],
            $docker
        );
    }

    public function testTheDockerExtensionDatabaseIsNotWritten(): void
    {
        $db = $this->rulesDb([[82, '', '18080', '127.0.0.1']]);
        $before = [md5_file($db), filemtime($db)];

        $this->routes($db, ['/containers/json?all=1' => self::containers()])->load(self::sites());

        self::assertSame($before, [md5_file($db), filemtime($db)]);
        self::assertSame(['db.sqlite3'], array_values(array_diff(scandir($this->dir), ['.', '..'])));
    }

    public function testHealthFromOlderDockerStatusText(): void
    {
        $db = $this->rulesDb([[82, '', '18080', '127.0.0.1']]);
        $containers = self::containers();
        $containers[0]['Status'] = 'Up 5 seconds (health: starting)';

        $docker = $this->routes($db, ['/containers/json?all=1' => $containers])->load(self::sites());

        self::assertSame('starting', $docker['containers']['web']['health']);
    }

    public function testMatch(): void
    {
        $routes = [
            ['prefix' => '/api/', 'container' => 'api', 'schemes' => ['https']],
            ['prefix' => '/', 'container' => 'web', 'schemes' => ['https', 'http']],
        ];

        self::assertSame('api', DockerRoutes::match($routes, 'https', '/api/orders?id=1'));
        self::assertSame('web', DockerRoutes::match($routes, 'http', '/api/orders'));
        self::assertSame('web', DockerRoutes::match($routes, 'https', '/'));
        self::assertNull(DockerRoutes::match($routes, 'https', '/.well-known/acme-challenge/token'));
        self::assertNull(DockerRoutes::match($routes, 'https', '/.well-known/pki-validation/fileauth.txt'));
        self::assertNull(DockerRoutes::match($routes, 'https', 'http://example.com/'));
        self::assertNull(DockerRoutes::match($routes, 'https', ''));
        self::assertNull(DockerRoutes::match([], 'https', '/'));
    }

    public function testUnknownStatesAreNormalised(): void
    {
        self::assertSame('running', DockerRoutes::state('running'));
        self::assertSame('unknown', DockerRoutes::state('hibernating'));
        self::assertSame('unknown', DockerRoutes::state(null));
    }
}
