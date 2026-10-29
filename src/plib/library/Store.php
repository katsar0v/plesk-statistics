<?php
/**
 * Short-lived SQLite store of parsed requests (a few minutes) and of the read position of each log.
 */
class Modules_LiveTrafficMonitor_Store
{
    private const SCHEMA_VERSION = 2;

    /**
     * An Apache line is a copy of an nginx line when Plesk's nginx served a static file on Apache's
     * behalf (X-Accel-Redirect): both servers log the request. nginx logs when the response ends, so
     * its line can be up to this many seconds later than Apache's, which records the request start.
     */
    private const DUPLICATE_BEFORE = 2;
    private const DUPLICATE_AFTER = 60;

    private PDO $db;

    public function __construct(string $file)
    {
        $this->db = new PDO('sqlite:' . $file, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        $this->db->exec('PRAGMA journal_mode = WAL');
        $this->db->exec('PRAGMA synchronous = NORMAL');
        $this->migrate();
    }

    private function migrate(): void
    {
        if ($this->schemaVersion() === self::SCHEMA_VERSION) {
            return;
        }
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $this->createSchema();
            $this->db->exec('COMMIT');
        } catch (Throwable $e) {
            $this->rollBack();
            throw $e;
        }
    }

    /** Runs inside the migration transaction; another request may have created the schema meanwhile. */
    private function createSchema(): void
    {
        if ($this->schemaVersion() === self::SCHEMA_VERSION) {
            return;
        }
        // The store only holds minutes of data, so an old schema is simply replaced.
        $this->db->exec('DROP TABLE IF EXISTS requests');
        $this->db->exec('DROP TABLE IF EXISTS files');
        $this->db->exec('DROP TABLE IF EXISTS meta');
        $this->db->exec('CREATE TABLE files (
            path TEXT PRIMARY KEY, dev INTEGER NOT NULL, ino INTEGER NOT NULL, pos INTEGER NOT NULL,
            head BLOB NOT NULL, seen INTEGER NOT NULL)');
        $this->db->exec('CREATE TABLE requests (
            id INTEGER PRIMARY KEY, ts INTEGER NOT NULL, domain TEXT NOT NULL, scheme TEXT NOT NULL,
            src TEXT NOT NULL, method TEXT NOT NULL, path TEXT NOT NULL, status INTEGER NOT NULL,
            bytes INTEGER NOT NULL, ip TEXT NOT NULL, rt REAL, container TEXT, k INTEGER NOT NULL,
            dup INTEGER NOT NULL DEFAULT 0)');
        $this->db->exec('CREATE INDEX requests_ts ON requests (ts)');
        $this->db->exec('CREATE INDEX requests_domain_ts ON requests (domain, ts)');
        $this->db->exec('CREATE INDEX requests_container_ts ON requests (container, ts)');
        $this->db->exec('CREATE INDEX requests_k_ts ON requests (k, ts)');
        $this->db->exec('CREATE TABLE meta (name TEXT PRIMARY KEY, value TEXT NOT NULL)');
        $this->db->exec('PRAGMA user_version = ' . self::SCHEMA_VERSION);
    }

    private function schemaVersion(): int
    {
        return (int) $this->db->query('PRAGMA user_version')->fetchColumn();
    }

    public function begin(): void
    {
        $this->db->exec('BEGIN IMMEDIATE');
    }

    public function commit(): void
    {
        $this->db->exec('COMMIT');
    }

    public function rollBack(): void
    {
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }
    }

    /** @return array<string, array{dev: int, ino: int, pos: int, head: string, seen: int}> */
    public function fileStates(): array
    {
        $states = [];
        foreach ($this->db->query('SELECT path, dev, ino, pos, head, seen FROM files') as $row) {
            $states[$row['path']] = [
                'dev' => (int) $row['dev'],
                'ino' => (int) $row['ino'],
                'pos' => (int) $row['pos'],
                'head' => (string) $row['head'],
                'seen' => (int) $row['seen'],
            ];
        }
        return $states;
    }

    /** @param array{dev: int, ino: int, pos: int, head: string, seen: int} $state */
    public function saveFileState(string $path, array $state): void
    {
        $stmt = $this->db->prepare('INSERT OR REPLACE INTO files (path, dev, ino, pos, head, seen) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->bindValue(1, $path);
        $stmt->bindValue(2, $state['dev'], PDO::PARAM_INT);
        $stmt->bindValue(3, $state['ino'], PDO::PARAM_INT);
        $stmt->bindValue(4, $state['pos'], PDO::PARAM_INT);
        $stmt->bindValue(5, $state['head'], PDO::PARAM_LOB);
        $stmt->bindValue(6, $state['seen'], PDO::PARAM_INT);
        $stmt->execute();
    }

    /** @param list<array<string, int|float|string|null>> $rows named parameters `:ts`, `:domain`, `:scheme`, `:src`, `:method`, `:path`, `:status`, `:bytes`, `:ip`, `:rt`, `:container`, `:k` */
    public function insertRequests(array $rows): void
    {
        if (!$rows) {
            return;
        }
        $stmt = $this->db->prepare('INSERT INTO requests (ts, domain, scheme, src, method, path, status, bytes, ip, rt, container, k)
            VALUES (:ts, :domain, :scheme, :src, :method, :path, :status, :bytes, :ip, :rt, :container, :k)');
        foreach ($rows as $row) {
            $stmt->execute($row);
        }
    }

    /**
     * Flags Apache lines that nginx also logged; see DUPLICATE_BEFORE. `$insertedSince` is the
     * oldest timestamp inserted by this poll: older Apache lines may pair with a new nginx line.
     */
    public function markDuplicates(int $insertedSince): void
    {
        $since = $insertedSince - self::DUPLICATE_AFTER;
        $stmt = $this->db->prepare("UPDATE requests SET dup = 1
            WHERE src = 'apache' AND dup = 0 AND ts >= :since AND EXISTS (
                SELECT 1 FROM requests n
                WHERE n.k = requests.k AND n.src = 'nginx'
                  AND n.ts BETWEEN requests.ts - :before AND requests.ts + :after)");
        $stmt->execute([':since' => $since, ':before' => self::DUPLICATE_BEFORE, ':after' => self::DUPLICATE_AFTER]);
    }

    public function prune(int $requestsBefore, int $filesBefore): void
    {
        $this->db->prepare('DELETE FROM requests WHERE ts < ?')->execute([$requestsBefore]);
        $this->db->prepare('DELETE FROM files WHERE seen < ?')->execute([$filesBefore]);
    }

    public function meta(string $name): ?string
    {
        $stmt = $this->db->prepare('SELECT value FROM meta WHERE name = ?');
        $stmt->execute([$name]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (string) $value;
    }

    public function setMeta(string $name, string $value): void
    {
        $this->db->prepare('INSERT OR REPLACE INTO meta (name, value) VALUES (?, ?)')->execute([$name, $value]);
    }

    /**
     * Requests with `$now - $window < ts <= $now`, Apache duplicates excluded.
     *
     * @return array{total: int, byStatusClass: array<string, int>, byStatus: list<array{status: int, count: int}>,
     *               byDomain: list<array{domain: string, count: int}>,
     *               byContainer: list<array{container: string, count: int, errors: int}>, rows: list<array<string, mixed>>}
     */
    public function query(int $window, ?string $domain, int $now, int $limit, ?string $container = null): array
    {
        $all = 'dup = 0 AND ts > :from AND ts <= :to';
        $params = [':from' => $now - $window, ':to' => $now];
        $where = $all;
        $filtered = $params;
        if ($domain !== null) {
            $where .= ' AND domain = :domain';
            $filtered[':domain'] = $domain;
        }
        if ($container !== null) {
            $where .= ' AND container = :container';
            $filtered[':container'] = $container;
        }

        $classes = ['2xx' => 0, '3xx' => 0, '4xx' => 0, '5xx' => 0, 'other' => 0];
        $total = 0;
        foreach ($this->fetchAll("SELECT status / 100 AS c, COUNT(*) AS n FROM requests WHERE $where GROUP BY c", $filtered) as $row) {
            $class = in_array((int) $row['c'], [2, 3, 4, 5], true) ? $row['c'] . 'xx' : 'other';
            $classes[$class] += (int) $row['n'];
            $total += (int) $row['n'];
        }

        $byStatus = array_map(
            static fn (array $r): array => ['status' => (int) $r['status'], 'count' => (int) $r['n']],
            $this->fetchAll("SELECT status, COUNT(*) AS n FROM requests WHERE $where GROUP BY status ORDER BY n DESC, status LIMIT 10", $filtered)
        );
        $byDomain = array_map(
            static fn (array $r): array => ['domain' => (string) $r['domain'], 'count' => (int) $r['n']],
            $this->fetchAll("SELECT domain, COUNT(*) AS n FROM requests WHERE $all GROUP BY domain ORDER BY n DESC, domain", $params)
        );
        $byContainer = array_map(
            static fn (array $r): array => ['container' => (string) $r['container'], 'count' => (int) $r['n'], 'errors' => (int) $r['e']],
            $this->fetchAll(
                "SELECT container, COUNT(*) AS n, SUM(status BETWEEN 500 AND 599) AS e FROM requests
                 WHERE $all AND container IS NOT NULL GROUP BY container ORDER BY n DESC, container",
                $params
            )
        );
        $rows = array_map(static fn (array $r): array => [
            'ts' => (int) $r['ts'],
            'domain' => (string) $r['domain'],
            'scheme' => (string) $r['scheme'],
            'method' => (string) $r['method'],
            'path' => (string) $r['path'],
            'status' => (int) $r['status'],
            'bytes' => (int) $r['bytes'],
            'ip' => (string) $r['ip'],
            'rt' => $r['rt'] === null ? null : (float) $r['rt'],
            'src' => (string) $r['src'],
            'container' => $r['container'] === null ? null : (string) $r['container'],
        ], $this->fetchAll(
            "SELECT ts, domain, scheme, method, path, status, bytes, ip, rt, src, container FROM requests WHERE $where
             ORDER BY ts DESC, id DESC LIMIT " . max(1, $limit),
            $filtered
        ));

        return [
            'total' => $total,
            'byStatusClass' => $classes,
            'byStatus' => $byStatus,
            'byDomain' => $byDomain,
            'byContainer' => $byContainer,
            'rows' => $rows,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function fetchAll(string $sql, array $params): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
