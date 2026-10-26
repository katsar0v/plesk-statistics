<?php
/**
 * One dashboard poll: read what is new in every website's access logs into the store (bounded in
 * bytes and time, one poll at a time), then answer the windowed query.
 *
 * Plesk logs each request once per web server that handled it, so every website's four logs are
 * read: nginx's `proxy_access_log` / `proxy_access_ssl_log` and Apache's `access_log` /
 * `access_ssl_log`. Requests nginx serves itself appear only in nginx's logs, and requests proxied
 * to Apache only in Apache's (Plesk turns nginx logging off for proxied locations). Static files
 * that Apache hands back to nginx (X-Accel-Redirect) appear in both, and the nginx line wins
 * because it has the status the client actually received. So the result is the same whether a
 * site runs on nginx only, nginx + Apache, or Apache only.
 */
class Modules_LiveTrafficMonitor_Monitor
{
    public const WINDOWS = [60, 120, 300];
    /** Seconds of requests kept in the store. */
    public const RETENTION = 600;
    /** Seconds before the largest window to start from on a fresh read; log lines are not strictly ordered. */
    private const START_MARGIN = 30;

    /** @var array<string, array{0: string, 1: string}> file name => [source, scheme] */
    private const LOG_FILES = [
        'proxy_access_ssl_log' => ['nginx', 'https'],
        'proxy_access_log' => ['nginx', 'http'],
        'access_ssl_log' => ['apache', 'https'],
        'access_log' => ['apache', 'http'],
    ];

    private const DEFAULTS = [
        'maxBytesPerFile' => 4 << 20,
        'maxBytesPerPoll' => 16 << 20,
        'timeBudget' => 1.0,
        'lockWait' => 2.0,
        'rowLimit' => 500,
    ];

    private string $stateDir;
    /** @var array{maxBytesPerFile: int, maxBytesPerPoll: int, timeBudget: float, lockWait: float, rowLimit: int} */
    private array $options;
    private Modules_LiveTrafficMonitor_LogParser $parser;
    private Modules_LiveTrafficMonitor_LogReader $reader;
    private Modules_LiveTrafficMonitor_Redactor $redactor;

    /** @param array<string, int|float> $options overrides for DEFAULTS */
    public function __construct(string $stateDir, array $options = [])
    {
        $this->stateDir = rtrim($stateDir, '/');
        $this->options = array_merge(self::DEFAULTS, array_intersect_key($options, self::DEFAULTS));
        $this->parser = new Modules_LiveTrafficMonitor_LogParser();
        $this->reader = new Modules_LiveTrafficMonitor_LogReader($this->parser);
        $this->redactor = new Modules_LiveTrafficMonitor_Redactor();
    }

    /**
     * @param list<array{name: string, displayName: string, logDir: string}> $sites
     * @param int $window one of WINDOWS
     * @param string|null $domain a site name, or null for all sites
     * @return array<string, mixed> the response described in docs/data-contract.md
     */
    public function poll(array $sites, int $window, ?string $domain, ?int $now = null): array
    {
        if (!in_array($window, self::WINDOWS, true)) {
            throw new InvalidArgumentException('Unsupported window');
        }
        $now ??= time();
        $store = $this->openStore();
        $ingest = $this->ingest($store, $sites, $now);
        $result = $store->query($window, $domain, $now, $this->options['rowLimit']);

        $displayNames = array_column($sites, 'displayName', 'name');
        foreach ($result['byDomain'] as &$entry) {
            $entry['displayName'] = $displayNames[$entry['domain']] ?? $entry['domain'];
        }
        unset($entry);

        return [
            'ok' => true,
            'generatedAt' => $now,
            'window' => $window,
            'domain' => $domain ?? 'all',
            'total' => $result['total'],
            'rps' => round($result['total'] / $window, 2),
            'byStatusClass' => $result['byStatusClass'],
            'byStatus' => $result['byStatus'],
            'byDomain' => $result['byDomain'],
            'rows' => $result['rows'],
            'rowLimit' => $this->options['rowLimit'],
            'truncated' => $result['total'] > count($result['rows']),
            'lagging' => $ingest['lagging'],
            'parseErrors' => $ingest['parseErrors'],
            'files' => ['read' => $ingest['read'], 'unreadable' => $ingest['unreadable']],
        ];
    }

    private function openStore(): Modules_LiveTrafficMonitor_Store
    {
        if (!is_dir($this->stateDir) && !@mkdir($this->stateDir, 0700, true) && !is_dir($this->stateDir)) {
            throw new RuntimeException('Cannot create the state directory');
        }
        return new Modules_LiveTrafficMonitor_Store($this->stateDir . '/traffic.sqlite');
    }

    /**
     * @param list<array{name: string, displayName: string, logDir: string}> $sites
     * @return array{lagging: bool, parseErrors: int, read: int, unreadable: int}
     */
    private function ingest(Modules_LiveTrafficMonitor_Store $store, array $sites, int $now): array
    {
        $summary = ['lagging' => false, 'parseErrors' => 0, 'read' => 0, 'unreadable' => 0];

        $lock = fopen($this->stateDir . '/ingest.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Cannot open the lock file');
        }
        $deadline = microtime(true) + $this->options['lockWait'];
        while (!flock($lock, LOCK_EX | LOCK_NB)) {
            if (microtime(true) >= $deadline) {
                // Another poll is still reading; answer from what is already stored.
                fclose($lock);
                $summary['lagging'] = true;
                return $summary;
            }
            usleep(50000);
        }

        try {
            $files = [];
            foreach ($sites as $site) {
                foreach (self::LOG_FILES as $file => [$src, $scheme]) {
                    $path = rtrim($site['logDir'], '/') . '/' . $file;
                    if (file_exists($path)) {
                        $files[] = ['path' => $path, 'domain' => $site['name'], 'src' => $src, 'scheme' => $scheme];
                    }
                }
            }

            $store->begin();
            try {
                $count = count($files);
                // Continue where the previous poll ran out of budget so no log is starved.
                $cursor = $count ? (int) $store->meta('cursor') % $count : 0;
                $states = $store->fileStates();
                $budget = $this->options['maxBytesPerPoll'];
                $timeLimit = microtime(true) + $this->options['timeBudget'];
                $startFrom = $now - max(self::WINDOWS) - self::START_MARGIN;
                $oldestInserted = null;

                for ($i = 0; $i < $count; $i++) {
                    $index = ($cursor + $i) % $count;
                    if ($budget <= 0 || microtime(true) >= $timeLimit) {
                        $summary['lagging'] = true;
                        $store->setMeta('cursor', (string) $index);
                        break;
                    }
                    $file = $files[$index];
                    $result = $this->reader->read(
                        $file['path'],
                        $states[$file['path']] ?? null,
                        $startFrom,
                        min($this->options['maxBytesPerFile'], $budget),
                        $now,
                        self::RETENTION
                    );
                    if ($result === null) {
                        $summary['unreadable']++;
                        continue;
                    }
                    $summary['read']++;
                    $budget -= $result['bytes'];
                    $summary['lagging'] = $summary['lagging'] || $result['pending'];

                    $rows = $this->toRows($result['lines'], $file, $now, $summary['parseErrors']);
                    if ($rows) {
                        $store->insertRequests($rows);
                        $oldest = min(array_column($rows, ':ts'));
                        $oldestInserted = $oldestInserted === null ? $oldest : min($oldestInserted, $oldest);
                    }
                    $store->saveFileState($file['path'], $result['state']);
                }

                if ($oldestInserted !== null) {
                    $store->markDuplicates($oldestInserted);
                }
                $store->prune($now - self::RETENTION, $now - 86400);
                $store->commit();
            } catch (Throwable $e) {
                $store->rollBack();
                throw $e;
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return $summary;
    }

    /**
     * @param list<string> $lines
     * @param array{path: string, domain: string, src: string, scheme: string} $file
     * @return list<array<string, mixed>>
     */
    private function toRows(array $lines, array $file, int $now, int &$parseErrors): array
    {
        $rows = [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            $entry = $this->parser->parse($line);
            if ($entry === null) {
                $parseErrors++;
                continue;
            }
            if ($entry['ts'] <= $now - self::RETENTION) {
                continue;
            }
            $rows[] = [
                ':ts' => $entry['ts'],
                ':domain' => $file['domain'],
                ':scheme' => $file['scheme'],
                ':src' => $file['src'],
                ':method' => $entry['method'],
                ':path' => $entry['method'] === '-' ? '(invalid request)' : $this->redactor->redact($entry['target']),
                ':status' => $entry['status'],
                ':bytes' => $entry['bytes'],
                ':ip' => $entry['ip'],
                ':rt' => $entry['rt'],
                ':k' => self::requestKey($file, $entry),
            ];
        }
        return $rows;
    }

    /** Identifies "the same request" across the nginx and Apache logs of one site. */
    private static function requestKey(array $file, array $entry): int
    {
        $hash = hash('sha256', implode("\0", [$file['domain'], $file['scheme'], $entry['ip'], $entry['method'], $entry['target']]), true);
        return unpack('q', substr($hash, 0, 8))[1];
    }
}
