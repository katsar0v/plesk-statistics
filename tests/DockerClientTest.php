<?php

namespace LiveTrafficMonitor\Tests;

use Modules_LiveTrafficMonitor_DockerClient as DockerClient;

final class DockerClientTest extends TestCase
{
    public function testDecodesJsonBody(): void
    {
        $raw = "HTTP/1.1 200 OK\r\nApi-Version: 1.55\r\nContent-Type: application/json\r\n\r\n[{\"Id\":\"abc\"}]\n";

        self::assertSame([['Id' => 'abc']], DockerClient::decode($raw));
    }

    public function testDecodesChunkedBody(): void
    {
        $raw = "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n5\r\n[{\"a\"\r\n5;ext=1\r\n:1}]\n\r\n0\r\n\r\n";

        self::assertSame([['a' => 1]], DockerClient::decode($raw));
    }

    public function testErrorStatusCarriesDockersMessage(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Docker answered: No such container: abc');

        DockerClient::decode("HTTP/1.1 404 Not Found\r\n\r\n{\"message\":\"No such container: abc\"}");
    }

    public function testErrorStatusWithoutMessage(): void
    {
        $this->expectExceptionMessage('Docker answered: HTTP 500');

        DockerClient::decode("HTTP/1.0 500 Internal Server Error\r\n\r\noops");
    }

    public function testRejectsGarbage(): void
    {
        $this->expectExceptionMessage('Unexpected answer from Docker');

        DockerClient::decode("HTTP/1.1 200 OK\r\n\r\n{not json");
    }

    public function testMissingSocket(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('#^Cannot connect to Docker at .*/missing\.sock: #');

        (new DockerClient($this->dir . '/missing.sock', 0.2))->get('/containers/json');
    }

    public function testSendsAPlainGetOverTheSocket(): void
    {
        $socket = $this->dir . '/docker.sock';
        $requestFile = $this->dir . '/request.txt';
        // A one-shot server in a child process: records the request and answers like Docker does.
        $server = proc_open([PHP_BINARY, '-r', '
            $server = stream_socket_server("unix://" . $argv[1], $errno, $error);
            $client = stream_socket_accept($server, 5);
            $request = "";
            while (strpos($request, "\r\n\r\n") === false && !feof($client)) {
                $request .= fread($client, 1024);
            }
            file_put_contents($argv[2], $request);
            fwrite($client, "HTTP/1.0 200 OK\r\nContent-Type: application/json\r\n\r\n{\"Version\":\"29.6.1\"}");
            fclose($client);
        ', $socket, $requestFile], [], $pipes);
        for ($i = 0; $i < 100 && !file_exists($socket); $i++) {
            usleep(20000);
        }

        try {
            $answer = (new DockerClient($socket, 2.0))->get('/version');
        } finally {
            proc_close($server);
        }

        self::assertSame(['Version' => '29.6.1'], $answer);
        self::assertSame("GET /version HTTP/1.0\r\nHost: docker\r\nAccept: application/json\r\n\r\n", file_get_contents($requestFile));
    }
}
