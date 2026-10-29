<?php
/**
 * Minimal read-only client for the Docker Engine API on its Unix socket. Plesk's panel user is in
 * the `docker` group (the Docker extension relies on that too), so no helper running as root is needed.
 *
 * Access to the socket means full control of Docker, so this class only ever sends GET requests,
 * to paths its callers build from fixed strings and validated container IDs.
 */
class Modules_LiveTrafficMonitor_DockerClient
{
    public const DEFAULT_SOCKET = '/var/run/docker.sock';
    private const MAX_RESPONSE = 8 << 20;

    private string $socket;
    private float $timeout;

    public function __construct(string $socket = self::DEFAULT_SOCKET, float $timeout = 1.0)
    {
        $this->socket = $socket;
        $this->timeout = $timeout;
    }

    public function socket(): string
    {
        return $this->socket;
    }

    /**
     * @return mixed the decoded JSON body
     * @throws RuntimeException when Docker cannot be reached or does not answer 200
     */
    public function get(string $path)
    {
        $fp = @stream_socket_client('unix://' . $this->socket, $errno, $error, $this->timeout);
        if ($fp === false) {
            throw new RuntimeException("Cannot connect to Docker at {$this->socket}: " . ($error ?: 'error ' . $errno));
        }
        try {
            stream_set_timeout($fp, (int) $this->timeout, (int) (fmod($this->timeout, 1.0) * 1e6));
            // HTTP/1.0 so that Docker closes the connection after the body and never sends it chunked.
            fwrite($fp, "GET $path HTTP/1.0\r\nHost: docker\r\nAccept: application/json\r\n\r\n");
            $raw = stream_get_contents($fp, self::MAX_RESPONSE + 1);
            $timedOut = stream_get_meta_data($fp)['timed_out'];
        } finally {
            fclose($fp);
        }
        if ($raw === false || $timedOut) {
            throw new RuntimeException('Docker did not answer in time');
        }
        if (strlen($raw) > self::MAX_RESPONSE) {
            throw new RuntimeException('Docker sent an unexpectedly large answer');
        }
        return self::decode($raw);
    }

    /**
     * Decodes a raw HTTP response with a JSON body.
     *
     * @return mixed
     * @throws RuntimeException
     */
    public static function decode(string $raw)
    {
        $split = strpos($raw, "\r\n\r\n");
        if ($split === false || !preg_match('#^HTTP/\d(?:\.\d)? (\d{3})#', $raw, $m)) {
            throw new RuntimeException('Unexpected answer from Docker');
        }
        $body = substr($raw, $split + 4);
        if (preg_match('/^Transfer-Encoding:[ \t]*chunked[ \t]*\r?$/mi', substr($raw, 0, $split))) {
            $body = self::unchunk($body);
        }

        $data = json_decode($body, true);
        if ((int) $m[1] !== 200) {
            $message = is_array($data) && is_string($data['message'] ?? null) ? $data['message'] : 'HTTP ' . $m[1];
            throw new RuntimeException('Docker answered: ' . $message);
        }
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Unexpected answer from Docker');
        }
        return $data;
    }

    private static function unchunk(string $body): string
    {
        $out = '';
        $pos = 0;
        while (preg_match('/\G([0-9A-Fa-f]+)[^\r\n]*\r\n/', $body, $m, 0, $pos)) {
            $size = hexdec($m[1]);
            if ($size === 0) {
                break;
            }
            $pos += strlen($m[0]);
            $out .= substr($body, $pos, $size);
            $pos += $size + 2;
        }
        return $out;
    }
}
