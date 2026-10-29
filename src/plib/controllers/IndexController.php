<?php
/**
 * The Live Traffic Monitor page and the JSON endpoint it polls. Administrators only.
 */
class IndexController extends pm_Controller_Action
{
    protected $_accessLevel = 'admin';

    public function init()
    {
        parent::init();
        // $_accessLevel already limits this controller to administrators. Check again here so the logs
        // stay out of reach of resellers, customers and administrators impersonating a customer.
        if (!pm_Session::getClient()->isAdmin()) {
            throw new pm_Exception('Permission denied');
        }
    }

    public function indexAction()
    {
        $this->view->pageTitle = 'Live Traffic Monitor';
        $this->view->headLink()->appendStylesheet(self::assetUrl('css/monitor.css'));
        $this->view->headScript()->appendFile(self::assetUrl('js/monitor.js'));

        $sites = self::sites();
        $this->view->config = [
            'dataUrl' => pm_Context::getActionUrl('index', 'data'),
            'domains' => array_map(
                static fn (array $site): array => ['name' => $site['name'], 'displayName' => $site['displayName']],
                $sites
            ),
            'containers' => Modules_LiveTrafficMonitor_DockerRoutes::containerList(self::docker($sites)),
            'windows' => Modules_LiveTrafficMonitor_Monitor::WINDOWS,
            'defaultWindow' => 60,
            'pollMs' => 3000,
            'rowLimit' => 500,
        ];
    }

    /** GET ?window=60|120|300&domain=all|<name>&container=all|<name>; see docs/data-contract.md. */
    public function dataAction()
    {
        $window = (int) $this->_getParam('window', 60);
        $domain = (string) $this->_getParam('domain', 'all');
        $container = (string) $this->_getParam('container', 'all');
        $sites = self::sites();
        $docker = self::docker($sites);

        if (!in_array($window, Modules_LiveTrafficMonitor_Monitor::WINDOWS, true)) {
            $this->sendJson(['ok' => false, 'error' => 'Unsupported time window.'], 400);
            return;
        }
        if ($domain !== 'all' && !in_array($domain, array_column($sites, 'name'), true)) {
            $this->sendJson(['ok' => false, 'error' => 'Unknown website.'], 400);
            return;
        }
        if ($container !== 'all' && !isset($docker['containers'][$container])) {
            $this->sendJson(['ok' => false, 'error' => 'Unknown container.'], 400);
            return;
        }

        try {
            $monitor = new Modules_LiveTrafficMonitor_Monitor(pm_Context::getVarDir() . 'state');
            $payload = $monitor->poll(
                $sites,
                $window,
                $domain === 'all' ? null : $domain,
                null,
                $docker,
                $container === 'all' ? null : $container
            );
        } catch (Throwable $e) {
            pm_Log::err('Live Traffic Monitor: ' . $e->getMessage());
            $this->sendJson(['ok' => false, 'error' => 'Could not read the access logs. The Plesk panel log has the details.'], 500);
            return;
        }
        $this->sendJson($payload);
    }

    private function sendJson(array $data, int $status = 200): void
    {
        $this->getResponse()
            ->setHttpResponseCode($status)
            ->setHeader('Cache-Control', 'no-store', true);
        $this->_helper->json($data);
    }

    /**
     * Websites with hosting, i.e. with their own web server logs. Includes subdomains and additional
     * domains; aliases share the logs of their main domain.
     *
     * @return list<array{name: string, displayName: string, logDir: string, id: int, sslRedirect: bool}>
     */
    private static function sites(): array
    {
        $sites = [];
        foreach (pm_Domain::getAllDomains() as $domain) {
            if (!$domain->hasHosting()) {
                continue;
            }
            try {
                $logDir = $domain->getVhostSystemPath() . '/logs';
            } catch (pm_Exception $e) {
                continue;
            }
            try {
                $sslRedirect = $domain->hasSslRedirect();
            } catch (pm_Exception $e) {
                $sslRedirect = false;
            }
            $sites[] = [
                'name' => $domain->getName(),
                'displayName' => $domain->getDisplayName(),
                'logDir' => $logDir,
                'id' => (int) $domain->getId(),
                'sslRedirect' => $sslRedirect,
            ];
        }
        usort($sites, static fn (array $a, array $b): int => strcmp($a['displayName'], $b['displayName']));
        return $sites;
    }

    /**
     * The Docker proxy rules of these websites and the containers behind them; null when there are
     * none. The rules are in the Docker extension's database, next to this extension's var directory.
     *
     * @param list<array{name: string, id: int, sslRedirect: bool}> $sites
     * @return array<string, mixed>|null
     */
    private static function docker(array $sites): ?array
    {
        $rulesDb = dirname(rtrim(pm_Context::getVarDir(), '/')) . '/docker/db.sqlite3';
        return (new Modules_LiveTrafficMonitor_DockerRoutes($rulesDb, pm_Context::getVarDir() . 'state/docker.json'))->load($sites);
    }

    private static function assetUrl(string $path): string
    {
        $mtime = @filemtime(pm_Context::getHtdocsDir() . $path);
        return pm_Context::getBaseUrl() . $path . ($mtime ? '?' . $mtime : '');
    }
}
