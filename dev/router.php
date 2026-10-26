<?php
/**
 * Dev server for the dashboard without Plesk. From the repository root:
 *
 *   php -S 127.0.0.1:8099 dev/router.php
 *
 *   /            the page, inside a minimal imitation of the Plesk admin layout
 *                ?theme=dark|light|contrast, ?fake=1 (synthetic data), ?poll=<ms>,
 *                ?rows=<row limit>, ?lagging=1, ?parseErrors=<n> (the last four only with fake data)
 *   /data        the JSON endpoint (window=60|120|300, domain=all|<name>)
 *   /js, /css    src/htdocs assets
 *
 * Create dev/var/fail to make /data answer 503 (to see the error state); remove it to recover.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$varDir = __DIR__ . '/var';
$vhostsDir = __DIR__ . '/vhosts/system';
$windows = [60, 120, 300];

// Plesk's autoloading convention: Modules_LiveTrafficMonitor_Foo => plib/library/Foo.php.
spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'Modules_LiveTrafficMonitor_';
    if (strpos($class, $prefix) === 0) {
        $file = $root . '/src/plib/library/' . str_replace('_', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
if (is_file($root . '/vendor/autoload.php')) {
    require $root . '/vendor/autoload.php';
}

/** @return list<array{name: string, displayName: string, logDir: string}> one per dev/vhosts/system/<domain>/logs */
function ltm_dev_sites(string $vhostsDir): array
{
    $displayNames = require __DIR__ . '/sites.php';
    foreach (array_keys($displayNames) as $name) {
        if (!is_dir("$vhostsDir/$name/logs")) {
            mkdir("$vhostsDir/$name/logs", 0775, true);
        }
    }

    $sites = [];
    foreach (glob("$vhostsDir/*/logs", GLOB_ONLYDIR) ?: [] as $logDir) {
        $name = basename(dirname($logDir));
        $sites[] = ['name' => $name, 'displayName' => $displayNames[$name] ?? $name, 'logDir' => $logDir];
    }
    usort($sites, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
    return $sites;
}

function ltm_dev_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function ltm_dev_asset(string $root, string $path): never
{
    $types = ['js' => 'text/javascript; charset=utf-8', 'css' => 'text/css; charset=utf-8'];
    $base = realpath($root . '/src/htdocs');
    $file = realpath($base . $path);
    $type = $types[pathinfo($path, PATHINFO_EXTENSION)] ?? null;
    if ($file === false || $type === null || strpos($file, $base . '/') !== 0 || !is_file($file)) {
        http_response_code(404);
        echo 'Not found';
        exit;
    }
    header('Content-Type: ' . $type);
    header('Cache-Control: no-store');
    readfile($file);
    exit;
}

/** The view object the real template expects: escape() and the config property. */
final class Ltm_Dev_View
{
    /** @param array<string, mixed> $config */
    public function __construct(public array $config)
    {
    }

    public function escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    public function render(string $template): string
    {
        ob_start();
        include $template;
        return (string) ob_get_clean();
    }
}

$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($path === '/favicon.ico') {
    http_response_code(204);
    exit;
}

if (strpos($path, '/js/') === 0 || strpos($path, '/css/') === 0) {
    ltm_dev_asset($root, $path);
}

if ($path === '/data') {
    $sites = ltm_dev_sites($vhostsDir);
    if (is_file($varDir . '/fail')) {
        ltm_dev_json(['ok' => false, 'error' => 'Dev harness: dev/var/fail exists, simulating a server failure.'], 503);
    }

    $window = filter_var($_GET['window'] ?? 60, FILTER_VALIDATE_INT);
    $domain = (string) ($_GET['domain'] ?? 'all');
    if (!in_array($window, $windows, true)) {
        ltm_dev_json(['ok' => false, 'error' => 'Invalid time window.'], 400);
    }
    if ($domain !== 'all' && !in_array($domain, array_column($sites, 'name'), true)) {
        ltm_dev_json(['ok' => false, 'error' => 'Unknown website.'], 400);
    }
    $filter = $domain === 'all' ? null : $domain;

    $fake = isset($_GET['fake']) || !class_exists('Modules_LiveTrafficMonitor_Monitor');
    header('X-Dev-Source: ' . ($fake ? 'fake' : 'monitor'));
    try {
        if ($fake) {
            require_once __DIR__ . '/fake-payload.php';
            $payload = ltm_fake_payload($sites, $window, $filter, [
                'rows' => $_GET['rows'] ?? 500,
                'lagging' => isset($_GET['lagging']),
                'parseErrors' => $_GET['parseErrors'] ?? 0,
            ]);
        } else {
            if (!is_dir($varDir)) {
                mkdir($varDir, 0775, true);
            }
            $monitor = new Modules_LiveTrafficMonitor_Monitor($varDir);
            $payload = $monitor->poll($sites, $window, $filter);
        }
    } catch (Throwable $e) {
        ltm_dev_json(['ok' => false, 'error' => $e->getMessage()], 500);
    }
    ltm_dev_json($payload);
}

if ($path === '/') {
    $sites = ltm_dev_sites($vhostsDir);
    $theme = in_array($_GET['theme'] ?? '', ['dark', 'light', 'contrast'], true) ? $_GET['theme'] : 'light';
    $passThrough = array_intersect_key($_GET, array_flip(['fake', 'rows', 'lagging', 'parseErrors']));
    $view = new Ltm_Dev_View([
        'dataUrl' => '/data' . ($passThrough ? '?' . http_build_query($passThrough) : ''),
        'domains' => array_map(
            static fn (array $site): array => ['name' => $site['name'], 'displayName' => $site['displayName']],
            $sites
        ),
        'windows' => $windows,
        'defaultWindow' => 60,
        'pollMs' => max(1000, (int) ($_GET['poll'] ?? 3000)),
        'rowLimit' => (int) ($_GET['rows'] ?? 500),
    ]);
    $body = $view->render($root . '/src/plib/views/scripts/index/index.phtml');
    $e = [$view, 'escape'];
    ?>
<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $e($theme); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="forgery_protection_token" content="dev-forgery-token">
    <title>Live Traffic Monitor (dev)</title>
    <link rel="stylesheet" href="/css/monitor.css">
    <style>
        /* Rough imitation of the Plesk Obsidian admin layout. */
        body { margin: 0; background: #fff; color: rgb(32, 32, 32); font: 14px/1.5 "Open Sans", system-ui, sans-serif; }
        html[data-theme="dark"] body { background: #191919; color: #e8e8e8; }
        .dev-header { padding: 24px 32px 8px; }
        .dev-header h1 { margin: 0; font-size: 24px; font-weight: 600; line-height: 1.3; }
        .dev-content { padding: 16px 32px 48px; }
        @media (max-width: 600px) { .dev-header { padding: 16px 16px 4px; } .dev-content { padding: 12px 16px 32px; } }
    </style>
    <script>
        // Stand-ins for what prototype.js does to a Plesk page: monitor.js must not depend on any of it.
        window.$ = function (id) { return typeof id === 'string' ? document.getElementById(id) : id; };
        Array.prototype.toJSON = function () { return 'broken'; };
        Array.prototype.entries = function () { return this.slice(); };
        Array.from = function (iterable) {
            var length = iterable.length || 0, results = new Array(length);
            while (length--) { results[length] = iterable[length]; }
            return results;
        };
    </script>
    <!-- Plesk adds headScript() files here, before the page body. -->
    <script src="/js/monitor.js"></script>
</head>
<body>
    <div class="dev-header"><h1>Live Traffic Monitor</h1></div>
    <div class="dev-content">
<?php echo $body; ?>
    </div>
</body>
</html>
<?php
    exit;
}

http_response_code(404);
echo 'Not found';
