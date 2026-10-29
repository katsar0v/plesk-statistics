<?php
/**
 * Synthesises a docs/data-contract.md payload for developing the UI without log files.
 * Rows are derived from the clock (one seed per second), so they stay stable between polls.
 *
 * @param list<array{name: string, displayName: string, logDir: string}> $sites
 * @param array<string, mixed> $options rows (row limit), lagging, parseErrors,
 *     container (name or null), docker (`ok`, `down` or `none`)
 * @return array<string, mixed>
 */
function ltm_fake_payload(array $sites, int $window, ?string $domain, array $options = []): array
{
    $now = time();
    $rowLimit = max(1, (int) ($options['rows'] ?? 500));
    $container = isset($options['container']) ? (string) $options['container'] : null;
    $dockerMode = in_array($options['docker'] ?? 'ok', ['down', 'none'], true) ? $options['docker'] : 'ok';
    $containers = $dockerMode === 'none' ? [] : ltm_fake_container_specs($sites);
    $paths = [
        '/', '/index.php', '/shop?page=2&token=[redacted]', '/wp-login.php', '/wp-admin/admin-ajax.php',
        '/wp-content/uploads/2026/10/photo.jpg', '/wp-content/themes/main/style.css', '/api/v1/products?page=2',
        '/api/v1/orders', '/api/v1/orders/1042', '/api/v1/users/me',
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
            $row = [
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
                'container' => null,
            ];

            // Only HTTPS requests that nginx proxied under a Docker proxy rule are tagged (Apache's lines never are).
            foreach ($containers as $spec) {
                if ($row['scheme'] === 'https' && $row['domain'] === $spec['domain'] && strpos($row['path'], $spec['pathPrefix']) === 0) {
                    $row['container'] = $spec['name'];
                    $row['src'] = 'nginx';
                    if ($spec['state'] === 'exited') {
                        $row['status'] = 502; // Nothing is listening behind nginx.
                        $row['bytes'] = 157;
                    }
                    break;
                }
            }
            $all[] = $row;
        }
    }

    $byDomain = [];
    foreach ($sites as $site) {
        $byDomain[$site['name']] = ['domain' => $site['name'], 'displayName' => $site['displayName'], 'count' => 0];
    }
    $byContainer = [];
    foreach ($containers as $spec) {
        $byContainer[$spec['name']] = [
            'container' => $spec['name'],
            'image' => $spec['image'],
            'state' => $spec['state'],
            'health' => $spec['health'],
            'status' => $spec['status'],
            'domains' => [$spec['domain']],
            'count' => 0,
            'errors' => 0,
        ];
        if ($dockerMode === 'down') {
            $byContainer[$spec['name']]['image'] = null;
            $byContainer[$spec['name']]['state'] = 'unknown';
            $byContainer[$spec['name']]['health'] = null;
            $byContainer[$spec['name']]['status'] = null;
        }
    }
    foreach ($all as $row) {
        $byDomain[$row['domain']]['count']++;
        if ($row['container'] !== null) {
            $byContainer[$row['container']]['count']++;
            if ($row['status'] >= 500 && $row['status'] <= 599) {
                $byContainer[$row['container']]['errors']++;
            }
        }
    }
    $byDomain = array_values($byDomain);
    usort($byDomain, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);
    $byContainer = array_values($byContainer);
    usort($byContainer, static fn (array $a, array $b): int => $b['count'] <=> $a['count'] ?: strcmp($a['container'], $b['container']));

    $matching = array_values(array_filter(
        $all,
        static fn (array $r): bool => ($domain === null || $r['domain'] === $domain)
            && ($container === null || $r['container'] === $container)
    ));
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

    $docker = null;
    if ($dockerMode === 'ok') {
        $docker = ['available' => true, 'error' => null];
    } elseif ($dockerMode === 'down') {
        $docker = ['available' => false, 'error' => 'Cannot connect to Docker at /var/run/docker.sock: Permission denied'];
    }

    return [
        'ok' => true,
        'generatedAt' => $now,
        'window' => $window,
        'domain' => $domain ?? 'all',
        'container' => $container ?? 'all',
        'total' => count($matching),
        'rps' => round(count($matching) / $window, 2),
        'byStatusClass' => $byStatusClass,
        'byStatus' => $top,
        'byDomain' => $byDomain,
        'byContainer' => $byContainer,
        'docker' => $docker,
        'rows' => array_slice($matching, 0, $rowLimit),
        'rowLimit' => $rowLimit,
        'truncated' => count($matching) > $rowLimit,
        'lagging' => !empty($options['lagging']),
        'parseErrors' => (int) ($options['parseErrors'] ?? 0),
        'files' => ['read' => count($sites) * 4, 'unreadable' => 0],
    ];
}

/**
 * The page config's `containers` for the dev harness: the Docker containers behind the fake proxy rules.
 *
 * @param list<array{name: string, displayName: string, logDir: string}> $sites
 * @param string $dockerMode `ok`, `down` (Docker not reachable) or `none` (no Docker proxy rules)
 * @return list<array{name: string, domains: list<string>}> sorted by name
 */
function ltm_fake_containers(array $sites, string $dockerMode): array
{
    if ($dockerMode === 'none') {
        return [];
    }
    $containers = [];
    foreach (ltm_fake_container_specs($sites) as $spec) {
        $containers[] = ['name' => $spec['name'], 'domains' => [$spec['domain']]];
    }
    usort($containers, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
    return $containers;
}

/**
 * The fake containers, limited to those whose website exists in $sites. `pathPrefix` is the part of the
 * website that the proxy rule sends to the container; the rest is served by Apache or nginx itself.
 * `state`, `health`, `status` and `image` are what Docker would report when it answers.
 *
 * @param list<array{name: string, displayName: string, logDir: string}> $sites
 * @return list<array{name: string, domain: string, pathPrefix: string, image: string, state: string, health: ?string, status: string}>
 */
function ltm_fake_container_specs(array $sites): array
{
    $specs = [
        [
            'name' => 'shop', 'domain' => 'shop.example.net', 'pathPrefix' => '/',
            'image' => 'shop:2026-10-28', 'state' => 'running', 'health' => 'healthy', 'status' => 'Up 12 hours (healthy)',
        ],
        [
            'name' => 'blog-api', 'domain' => 'blog.example.org', 'pathPrefix' => '/api',
            'image' => 'blog-api:1.4.0', 'state' => 'exited', 'health' => null, 'status' => 'Exited (1) 3 minutes ago',
        ],
    ];
    $known = array_column($sites, 'name');
    return array_values(array_filter($specs, static fn (array $spec): bool => in_array($spec['domain'], $known, true)));
}
