<?php
/**
 * The containers of one Docker daemon, and which of them publishes a given host port.
 */
class Modules_LiveTrafficMonitor_DockerHost
{
    private Modules_LiveTrafficMonitor_DockerClient $client;
    /** @var list<array{id: string, ports: list<int>, inspected: bool, info: array{container: string, image: ?string, state: string, health: ?string, status: ?string, domains: list<string>}}> */
    private array $containers = [];
    private int $inspectsLeft;

    /** @throws RuntimeException when Docker cannot be reached */
    public function __construct(Modules_LiveTrafficMonitor_DockerClient $client, int $maxInspect)
    {
        $this->client = $client;
        $this->inspectsLeft = $maxInspect;

        $list = $client->get('/containers/json?all=1');
        if (!is_array($list)) {
            throw new RuntimeException('Unexpected answer from Docker');
        }
        foreach ($list as $c) {
            $id = is_array($c) ? (string) ($c['Id'] ?? '') : '';
            $name = is_array($c) ? ltrim((string) ($c['Names'][0] ?? ''), '/') : '';
            if ($name === '' || !preg_match('/^[0-9a-f]{12,64}$/', $id)) {
                continue;
            }
            $ports = [];
            foreach ((array) ($c['Ports'] ?? []) as $port) {
                if (isset($port['PublicPort'])) {
                    $ports[] = (int) $port['PublicPort'];
                }
            }
            $state = Modules_LiveTrafficMonitor_DockerRoutes::state($c['State'] ?? null);
            $status = is_string($c['Status'] ?? null) ? $c['Status'] : null;
            $this->containers[] = [
                'id' => $id,
                'ports' => $ports,
                'inspected' => $state === 'running',
                'info' => [
                    'container' => $name,
                    'image' => is_string($c['Image'] ?? null) ? $c['Image'] : null,
                    'state' => $state,
                    'health' => $state === 'running' ? self::health($c, $status) : null,
                    'status' => $status,
                    'domains' => [],
                ],
            ];
        }
    }

    /** @return array{container: string, image: ?string, state: string, health: ?string, status: ?string, domains: list<string>}|null */
    public function publishing(int $port): ?array
    {
        foreach ($this->containers as $c) {
            if (in_array($port, $c['ports'], true)) {
                return $c['info'];
            }
        }
        // A stopped container lists no ports, but its configuration still has the port bindings.
        // Docker lists the newest containers first, so an older container with the same binding loses.
        foreach ($this->containers as $i => $c) {
            if ($c['inspected'] || $this->inspectsLeft <= 0) {
                continue;
            }
            $this->inspectsLeft--;
            $this->containers[$i]['inspected'] = true;
            try {
                $detail = $this->client->get('/containers/' . $c['id'] . '/json');
            } catch (RuntimeException $e) {
                continue;
            }
            $ports = [];
            foreach ((array) ($detail['HostConfig']['PortBindings'] ?? []) as $bindings) {
                foreach ((array) $bindings as $binding) {
                    if (ctype_digit((string) ($binding['HostPort'] ?? ''))) {
                        $ports[] = (int) $binding['HostPort'];
                    }
                }
            }
            $this->containers[$i]['ports'] = $ports;
            if (in_array($port, $ports, true)) {
                return $c['info'];
            }
        }
        return null;
    }

    /** `healthy`, `unhealthy` or `starting` for a container with a health check, otherwise null. */
    private static function health(array $container, ?string $status): ?string
    {
        $health = $container['Health']['Status'] ?? null;
        if (in_array($health, ['healthy', 'unhealthy', 'starting'], true)) {
            return $health;
        }
        // Older Docker versions only mention it in the status text: "Up 2 hours (health: starting)".
        if ($status !== null && preg_match('/\((healthy|unhealthy|health: starting)\)/', $status, $m)) {
            return $m[1] === 'health: starting' ? 'starting' : $m[1];
        }
        return null;
    }
}
