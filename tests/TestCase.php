<?php

namespace LiveTrafficMonitor\Tests;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ltm-test-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->dir);
    }

    /** A combined-format line as Plesk's nginx and Apache write it. */
    protected static function line(int $ts, string $request = 'GET / HTTP/1.1', int $status = 200, string $ip = '203.0.113.7', int $bytes = 100): string
    {
        return sprintf("%s - - [%s] \"%s\" %d %d \"-\" \"Mozilla/5.0\"\n", $ip, gmdate('d/M/Y:H:i:s O', $ts), $request, $status, $bytes);
    }
}
