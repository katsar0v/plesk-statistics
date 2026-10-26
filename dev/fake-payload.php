<?php
/**
 * Synthesises a docs/data-contract.md payload for developing the UI without log files.
 * Rows are derived from the clock (one seed per second), so they stay stable between polls.
 *
 * @param list<array{name: string, displayName: string, logDir: string}> $sites
 * @param array<string, mixed> $options rows (row limit), lagging, parseErrors
 * @return array<string, mixed>
 */
function ltm_fake_payload(array $sites, int $window, ?string $domain, array $options = []): array
{
    $now = time();
    $rowLimit = max(1, (int) ($options['rows'] ?? 500));
    $paths = [
        '/', '/index.php', '/shop?page=2&token=[redacted]', '/wp-login.php', '/wp-admin/admin-ajax.php',
        '/wp-content/uploads/2026/10/photo.jpg', '/wp-content/themes/main/style.css', '/api/v1/products?page=2',
        '/блог/първи-стъпки', '/новини/18-октомври?utm_source=newsletter', '/search?email=[redacted]',
        '/<script>alert(1)</script>', '/"onmouseover="x', '/?q=<img src=x onerror=alert(1)>',
        '/robots.txt', '/favicon.ico', '/.env', '/phpmyadmin/index.php',
        '/very/long/path/' . str_repeat('segment/', 14) . 'file.html?ref=' . str_repeat('abcdef', 20),
    ];
    $statuses = [200, 200, 200, 200, 200, 200, 200, 301, 302, 304, 304, 404, 404, 403, 500, 502, 206, 499, 100];
    $methods = ['GET', 'GET', 'GET', 'GET', 'POST', 'HEAD', 'PUT'];

    $all = [];
    for ($ts = $now; $ts > $now - $window; $ts--) {
        mt_srand($ts);
        for ($i = mt_rand(0, 4); $i > 0; $i--) {
            $site = $sites[mt_rand(0, count($sites) - 1)];
            $status = $statuses[mt_rand(0, count($statuses) - 1)];
            $all[] = [
                'ts' => $ts,
                'domain' => $site['name'],
                'scheme' => mt_rand(0, 9) ? 'https' : 'http',
                'method' => $methods[mt_rand(0, count($methods) - 1)],
                'path' => $paths[mt_rand(0, count($paths) - 1)],
                'status' => $status,
                'bytes' => $status === 304 ? 0 : (mt_rand(0, 3) ? mt_rand(200, 80000) : mt_rand(1, 30) * 1048576),
                'ip' => mt_rand(0, 4) ? '203.0.113.' . mt_rand(1, 254) : '2001:db8:85a3::8a2e:370:' . dechex(mt_rand(1, 65535)),
                'rt' => mt_rand(0, 2) ? null : round(mt_rand(1, 2500) / (mt_rand(0, 9) ? 1000 : 100), 3),
                'src' => mt_rand(0, 2) ? 'nginx' : 'apache',
            ];
        }
    }

    $byDomain = [];
    foreach ($sites as $site) {
        $byDomain[$site['name']] = ['domain' => $site['name'], 'displayName' => $site['displayName'], 'count' => 0];
    }
    foreach ($all as $row) {
        $byDomain[$row['domain']]['count']++;
    }
    $byDomain = array_values($byDomain);
    usort($byDomain, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

    $matching = $domain === null ? $all : array_values(array_filter($all, static fn (array $r): bool => $r['domain'] === $domain));
    $byStatusClass = ['2xx' => 0, '3xx' => 0, '4xx' => 0, '5xx' => 0, 'other' => 0];
    $byStatus = [];
    foreach ($matching as $row) {
        $family = intdiv($row['status'], 100);
        $byStatusClass[$family >= 2 && $family <= 5 ? $family . 'xx' : 'other']++;
        $byStatus[$row['status']] = ($byStatus[$row['status']] ?? 0) + 1;
    }
    arsort($byStatus);
    $top = [];
    foreach (array_slice($byStatus, 0, 10, true) as $status => $count) {
        $top[] = ['status' => $status, 'count' => $count];
    }

    return [
        'ok' => true,
        'generatedAt' => $now,
        'window' => $window,
        'domain' => $domain ?? 'all',
        'total' => count($matching),
        'rps' => round(count($matching) / $window, 2),
        'byStatusClass' => $byStatusClass,
        'byStatus' => $top,
        'byDomain' => $byDomain,
        'rows' => array_slice($matching, 0, $rowLimit),
        'rowLimit' => $rowLimit,
        'truncated' => count($matching) > $rowLimit,
        'lagging' => !empty($options['lagging']),
        'parseErrors' => (int) ($options['parseErrors'] ?? 0),
        'files' => ['read' => count($sites) * 4, 'unreadable' => 0],
    ];
}
