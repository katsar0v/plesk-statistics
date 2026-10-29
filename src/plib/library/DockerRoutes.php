<?php
/**
 * Which Docker container answers which requests. Plesk's Docker extension keeps one proxy rule per
 * website and URL path: Plesk's nginx proxies matching requests to a port on the host, and Docker
 * knows which container publishes that port. Container logs are never read; the requests themselves
 * come from nginx's access log like any other website's.
 *
 * The rules come from the Docker extension's own database, opened read-only. Only the rule columns
 * and each Docker connection's `remote` and `dockerSocket` settings are read: the connections table
 * also holds TLS keys.
 *
 * The container behind each rule is remembered in a small cache file, so that while Docker is not
 * reachable (for example while it restarts) requests are still credited to the right container.
 */
class Modules_LiveTrafficMonitor_DockerRoutes
{
    /** Paths that Plesk's nginx answers itself (`location ^~` blocks) before any Docker proxy rule. */
    private const NGINX_PATHS = ['/.well-known/acme-challenge/', '/.well-known/pki-validation/'];
    /** Stopped containers don't list their ports; at most this many are inspected per load. */
    private const MAX_INSPECT = 20;
    private const STATES = ['running', 'restarting', 'paused', 'created', 'exited', 'dead', 'removing'];

    private string $rulesDb;
    private ?string $cacheFile;
    /** @var callable(string): Modules_LiveTrafficMonitor_DockerClient */
    private $clientFactory;

    /**
     * @param string $rulesDb the Docker extension's SQLite database
     * @param string|null $cacheFile where to remember the last container seen behind each rule
     * @param (callable(string): Modules_LiveTrafficMonitor_DockerClient)|null $clientFactory socket path => client
     */
    public function __construct(string $rulesDb, ?string $cacheFile = null, ?callable $clientFactory = null)
    {
        $this->rulesDb = $rulesDb;
        $this->cacheFile = $cacheFile;
        $this->clientFactory = $clientFactory
            ?? static fn (string $socket): Modules_LiveTrafficMonitor_DockerClient => new Modules_LiveTrafficMonitor_DockerClient($socket);
    }

    /**
     * @param list<array{name: string, id?: int, sslRedirect?: bool}> $sites
     * @return array{available: bool, error: ?string,
     *               routes: array<string, list<array{prefix: string, container: string, schemes: list<string>}>>,
     *               containers: array<string, array{container: string, image: ?string, state: string, health: ?string, status: ?string, domains: list<string>}>}|null
     *         null when no website has a Docker proxy rule. `routes` are longest prefix first.
     */
    public function load(array $sites): ?array
    {
        if (!is_file($this->rulesDb)) {
            return null;
        }
        try {
            $rules = $this->readRules();
        } catch (Throwable $e) {
            return ['available' => false, 'error' => "Cannot read the Docker extension's proxy rules", 'routes' => [], 'containers' => []];
        }

        $sitesById = [];
        foreach ($sites as $site) {
            if (isset($site['id'])) {
                $sitesById[$site['id']] = $site;
            }
        }
        $rules = array_values(array_filter($rules, static fn (array $rule): bool => isset($sitesById[$rule['domainId']])));
        if (!$rules) {
            return null;
        }

        $error = null;
        $hosts = [];
        foreach (array_unique(array_column(array_filter($rules, static fn (array $r): bool => !$r['remote']), 'socket')) as $socket) {
            try {
                $hosts[$socket] = new Modules_LiveTrafficMonitor_DockerHost(($this->clientFactory)($socket), self::MAX_INSPECT);
            } catch (Throwable $e) {
                $error ??= $e->getMessage();
            }
        }

        $known = $this->readCache();
        $seen = [];
        $routes = [];
        $containers = [];
        foreach ($rules as $rule) {
            $site = $sitesById[$rule['domainId']];
            $host = $rule['remote'] ? null : ($hosts[$rule['socket']] ?? null);
            $key = $rule['socket'] . '#' . $rule['port'];
            $found = $host ? $host->publishing($rule['port']) : null;
            if ($found) {
                $seen[$key] = ['container' => $found['container'], 'image' => $found['image']];
            } elseif (!$host && !$rule['remote'] && isset($known[$key])) {
                $found = $known[$key] + ['state' => 'unknown', 'health' => null, 'status' => null, 'domains' => []];
            }
            $label = $found['container'] ?? self::address($rule['host'], $rule['port']);

            $containers[$label] ??= $found ?? [
                'container' => $label,
                'image' => null,
                'state' => $host ? 'missing' : 'unknown',
                'health' => null,
                'status' => null,
                'domains' => [],
            ];
            if (!in_array($site['name'], $containers[$label]['domains'], true)) {
                $containers[$label]['domains'][] = $site['name'];
                sort($containers[$label]['domains']);
            }
            $routes[$site['name']][] = [
                'prefix' => '/' . ltrim($rule['path'], '/'),
                'container' => $label,
                // With "redirect from HTTP to HTTPS", nginx answers every HTTP request with a redirect.
                'schemes' => empty($site['sslRedirect']) ? ['https', 'http'] : ['https'],
            ];
        }
        foreach ($routes as &$list) {
            usort($list, static fn (array $a, array $b): int => strlen($b['prefix']) <=> strlen($a['prefix']));
        }
        unset($list);
        ksort($containers);
        if ($hosts && $seen != $known) {
            $this->writeCache($seen + $known);
        }

        return ['available' => $error === null, 'error' => $error, 'routes' => $routes, 'containers' => $containers];
    }

    /**
     * The container that answered a request to a website, or null when nginx answered it itself
     * or passed it to Apache.
     *
     * @param list<array{prefix: string, container: string, schemes: list<string>}> $routes the website's routes
     * @param string $target the raw request target (path and query)
     */
    public static function match(array $routes, string $scheme, string $target): ?string
    {
        if ($target === '' || $target[0] !== '/') {
            return null;
        }
        foreach (self::NGINX_PATHS as $path) {
            if (strncmp($target, $path, strlen($path)) === 0) {
                return null;
            }
        }
        foreach ($routes as $route) {
            if (in_array($scheme, $route['schemes'], true) && strncmp($target, $route['prefix'], strlen($route['prefix'])) === 0) {
                return $route['container'];
            }
        }
        return null;
    }

    /**
     * The page config's `containers` (docs/data-contract.md).
     *
     * @param array{containers: array<string, array{container: string, domains: list<string>}>}|null $docker a load() result
     * @return list<array{name: string, domains: list<string>}>
     */
    public static function containerList(?array $docker): array
    {
        return array_values(array_map(
            static fn (array $c): array => ['name' => $c['container'], 'domains' => $c['domains']],
            $docker['containers'] ?? []
        ));
    }

    /** Docker's `State`, or `unknown` for values this extension doesn't know. */
    public static function state(mixed $state): string
    {
        return in_array($state, self::STATES, true) ? $state : 'unknown';
    }

    /** @return array<string, array{container: string, image: ?string}> "socket#port" => container */
    private function readCache(): array
    {
        $data = $this->cacheFile !== null && is_file($this->cacheFile) ? json_decode((string) @file_get_contents($this->cacheFile), true) : null;
        $known = [];
        foreach (is_array($data) ? $data : [] as $key => $entry) {
            if (is_string($entry['container'] ?? null)) {
                $known[(string) $key] = ['container' => $entry['container'], 'image' => is_string($entry['image'] ?? null) ? $entry['image'] : null];
            }
        }
        return $known;
    }

    /** @param array<string, array{container: string, image: ?string}> $known */
    private function writeCache(array $known): void
    {
        if ($this->cacheFile === null || !is_dir(dirname($this->cacheFile))) {
            return;
        }
        $tmp = $this->cacheFile . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, json_encode($known)) !== false) {
            @rename($tmp, $this->cacheFile);
        }
    }

    private static function address(string $host, int $port): string
    {
        return ($host === '' ? '127.0.0.1' : $host) . ':' . $port;
    }

    /** @return list<array{domainId: int, path: string, port: int, host: string, remote: bool, socket: string}> */
    private function readRules(): array
    {
        $flags = defined('Pdo\Sqlite::ATTR_OPEN_FLAGS') ? constant('Pdo\Sqlite::ATTR_OPEN_FLAGS') : PDO::SQLITE_ATTR_OPEN_FLAGS;
        $readOnly = defined('Pdo\Sqlite::OPEN_READONLY') ? constant('Pdo\Sqlite::OPEN_READONLY') : PDO::SQLITE_OPEN_READONLY;
        $db = new PDO('sqlite:' . $this->rulesDb, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 1,
            $flags => $readOnly,
        ]);
        $rows = $db->query('SELECT r.domainId, r.path, r.port, r.host, c.remote, c.dockerSocket
            FROM ProxyRules r LEFT JOIN Configurations c ON c.id = r.configurationId')->fetchAll();

        $rules = [];
        foreach ($rows as $row) {
            $port = (int) $row['port'];
            if ($port < 1 || $port > 65535) {
                continue;
            }
            $socket = preg_replace('#^unix://#', '', (string) $row['dockerSocket']);
            $rules[] = [
                'domainId' => (int) $row['domainId'],
                'path' => (string) $row['path'],
                'port' => $port,
                'host' => (string) $row['host'],
                'remote' => (bool) $row['remote'],
                'socket' => $socket !== '' && $socket[0] === '/' ? $socket : Modules_LiveTrafficMonitor_DockerClient::DEFAULT_SOCKET,
            ];
        }
        return $rules;
    }
}
