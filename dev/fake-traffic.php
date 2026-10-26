<?php
/**
 * Appends realistic access-log lines (nginx/Apache "combined", timestamped now) to
 * dev/vhosts/system/<domain>/logs/{proxy_access_ssl_log,proxy_access_log,access_ssl_log,access_log}.
 *
 *   php dev/fake-traffic.php [rate=5] [duration=60] [--rotate] [--burst N]
 *
 *   rate      lines per second
 *   duration  seconds to keep writing (0 or omitted with --burst: only the burst)
 *   --rotate  truncate one log in place (ftruncate to 0) halfway through
 *   --burst N write N lines as fast as possible before the steady stream
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Run this from the command line.\n");
}

// --- Arguments -------------------------------------------------------------------------------

$positional = [];
$rotate = false;
$burst = 0;
for ($i = 1; $i < $argc; $i++) {
    if ($argv[$i] === '--rotate') {
        $rotate = true;
    } elseif ($argv[$i] === '--burst') {
        $burst = max(0, (int) ($argv[++$i] ?? 0));
    } elseif (is_numeric($argv[$i])) {
        $positional[] = (float) $argv[$i];
    } else {
        fwrite(STDERR, "Usage: php dev/fake-traffic.php [rate=5] [duration=60] [--rotate] [--burst N]\n");
        exit(2);
    }
}
$rate = max(0.1, $positional[0] ?? 5.0);
$duration = $positional[1] ?? ($burst > 0 ? 0.0 : 60.0);

// --- Log files -------------------------------------------------------------------------------

$domains = ['example.com', 'shop.example.net', 'blog.example.org', 'xn--80ak6aa92e.com'];
$files = [];
$allLogs = [];
foreach ($domains as $domain) {
    $dir = __DIR__ . "/vhosts/system/$domain/logs";
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    foreach (['nginx' => ['proxy_access_ssl_log', 'proxy_access_log'], 'apache' => ['access_ssl_log', 'access_log']] as $server => $names) {
        foreach ($names as $name) {
            $files[$domain][$server][$name] = $allLogs[] = $dir . '/' . $name;
        }
    }
}
$handles = [];
function ltm_handle(string $file): mixed
{
    global $handles;
    return $handles[$file] ??= fopen($file, 'ab');
}

// --- Line generation -------------------------------------------------------------------------

function pick(array $list): mixed
{
    return $list[array_rand($list)];
}

/** Picks a key of a [key => weight] map. */
function weighted(array $weights): int|string
{
    $roll = mt_rand(1, array_sum($weights));
    foreach ($weights as $key => $weight) {
        if (($roll -= $weight) <= 0) {
            return $key;
        }
    }
    return array_key_first($weights);
}

function random_ip(): string
{
    return match (mt_rand(0, 3)) {
        0 => '203.0.113.' . mt_rand(1, 254),
        1 => '198.51.100.' . mt_rand(1, 254),
        2 => '192.0.2.' . mt_rand(1, 254),
        default => '2001:db8:' . dechex(mt_rand(1, 65535)) . '::' . dechex(mt_rand(1, 65535)),
    };
}

const REFERERS = ['-', '-', '-', 'https://www.google.com/', 'https://example.com/shop', 'https://www.facebook.com/'];
const AGENTS = [
    'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/127.0.0.0 Safari/537.36',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15',
    'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148',
    'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
    'curl/8.5.0',
    '-',
];
const STATIC_FILES = ['/wp-content/a.css', '/wp-content/themes/main/app.js', '/wp-content/uploads/2026/10/photo.jpg', '/assets/logo.svg'];

/** A request path, with a nginx- or Apache-style escaping of quotes for the hostile ones. */
function random_path(string $server): string
{
    $slug = rawurlencode(pick(['новини', 'първи-стъпки', 'контакти', 'поръчка-на-хостинг', 'цени']));
    $quote = $server === 'nginx' ? '\x22' : '\"';
    return match (weighted([
        'home' => 14, 'page' => 10, 'bg' => 10, 'query' => 14, 'secret' => 10, 'api' => 8, 'xss' => 4, 'misc' => 12,
    ])) {
        'home' => '/',
        'page' => pick(['/index.php', '/about', '/shop', '/shop/product-' . mt_rand(1, 400), '/wp-login.php', '/wp-admin/admin-ajax.php']),
        'bg' => '/' . $slug . '/' . rawurlencode(pick(['статия', 'каталог', 'гореща-оферта'])) . '-' . mt_rand(1, 99),
        'query' => pick(['/search?q=hosting&page=' . mt_rand(1, 9), '/shop?page=2', '/category/news?page=' . mt_rand(1, 20) . '&sort=date']),
        'secret' => pick([
            '/login?token=abc123def456',
            '/oauth/callback?access_token=eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.sig',
            '/newsletter/confirm?email=a@b.com',
            '/wp-admin/post.php?_wpnonce=1a2b3c4d5e&action=edit',
            '/list?page=2',
        ]),
        'api' => pick(['/api/v1/products?limit=20', '/api/v1/cart', '/wp-json/wp/v2/posts?per_page=10']),
        'xss' => pick([
            '/<script>alert(1)</script>',
            '/' . $quote . 'onmouseover=' . $quote . 'x',
            '/?q=<img src=x onerror=alert(1)>',
        ]),
        'misc' => pick(['/robots.txt', '/favicon.ico', '/.env', '/phpmyadmin/index.php', '/xmlrpc.php', '/sitemap.xml']),
    };
}

function log_line(string $ip, string $request, int $status, int $bytes, string $referer, string $agent): string
{
    return sprintf('%s - - [%s] "%s" %d %d "%s" "%s"' . "\n", $ip, gmdate('d/M/Y:H:i:s O'), $request, $status, $bytes, $referer, $agent);
}

/** Appends one request (or the X-Accel pair) to the right log files. Returns the number of lines written. */
function write_request(array $files): int
{
    $domain = array_rand($files);
    $ssl = mt_rand(0, 9) > 0;
    $logs = $files[$domain];
    $nginxLog = $logs['nginx'][$ssl ? 'proxy_access_ssl_log' : 'proxy_access_log'];
    $apacheLog = $logs['apache'][$ssl ? 'access_ssl_log' : 'access_log'];
    $ip = random_ip();
    $referer = pick(REFERERS);
    $agent = pick(AGENTS);

    // Plesk's X-Accel pattern: Apache logs the static file it hands back, then nginx logs what the client got.
    if (mt_rand(1, 100) <= 15) {
        $file = pick(STATIC_FILES);
        $cached = mt_rand(0, 1) === 1;
        write_line($apacheLog, log_line($ip, "GET $file HTTP/1.1", 200, 5533, $referer, $agent));
        write_line($nginxLog, log_line($ip, "GET $file " . pick(['HTTP/2.0', 'HTTP/3.0']), $cached ? 304 : 200, $cached ? 0 : 5533, $referer, $agent));
        return 2;
    }

    // Malformed request: a TLS handshake sent to the plain-HTTP port.
    if (mt_rand(1, 100) <= 2) {
        write_line($nginxLog, sprintf('%s - - [%s] "\x16\x03\x01" 400 157 "-" "-"' . "\n", $ip, gmdate('d/M/Y:H:i:s O')));
        return 1;
    }

    $server = mt_rand(1, 100) <= 60 ? 'nginx' : 'apache';
    $status = weighted([200 => 62, 301 => 6, 302 => 3, 304 => 9, 206 => 1, 403 => 2, 404 => 9, 499 => 1, 500 => 3, 502 => 1, 503 => 1]);
    $protocol = $server === 'nginx' ? pick(['HTTP/1.1', 'HTTP/2.0', 'HTTP/2.0', 'HTTP/3.0']) : 'HTTP/1.1';
    $method = weighted(['GET' => 85, 'POST' => 10, 'HEAD' => 3, 'OPTIONS' => 2]);
    $bytes = in_array($status, [304, 499], true) ? 0 : match (true) {
        $status >= 500, $status === 403 => mt_rand(150, 600),
        $status >= 300 => mt_rand(150, 400),
        default => mt_rand(0, 6) === 0 ? mt_rand(200000, 12000000) : mt_rand(300, 90000),
    };
    write_line($server === 'nginx' ? $nginxLog : $apacheLog, log_line($ip, "$method " . random_path($server) . " $protocol", $status, $bytes, $referer, $agent));
    return 1;
}

function write_line(string $file, string $line): void
{
    fwrite(ltm_handle($file), $line);
}

// --- Run -------------------------------------------------------------------------------------

$written = 0;
if ($burst > 0) {
    for ($n = 0; $n < $burst; $n += write_request($files)) {
    }
    printf("Burst: wrote %d lines.\n", $n);
    $written += $n;
}

if ($duration > 0) {
    printf("Writing about %s lines/s for %s s to %d websites (Ctrl+C to stop)...\n", $rate, $duration, count($domains));
    $start = microtime(true);
    $emitted = 0;
    $rotated = !$rotate;
    while (($elapsed = microtime(true) - $start) < $duration) {
        // Emit whatever is due; each call may write 1-2 lines, so count requests, not lines.
        while ($emitted < floor($elapsed * $rate)) {
            $written += write_request($files);
            $emitted++;
        }
        if (!$rotated && $elapsed >= $duration / 2) {
            $log = pick(array_values(array_filter($allLogs, static fn (string $file): bool => is_file($file) && filesize($file) > 0)));
            $h = fopen($log, 'cb');
            ftruncate($h, 0);
            fclose($h);
            printf("Rotated (truncated in place): %s\n", substr($log, strlen(__DIR__) + 1));
            $rotated = true;
        }
        usleep(20000);
    }
}
printf("Done: %d lines written.\n", $written);
