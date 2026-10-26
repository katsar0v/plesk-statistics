<?php

namespace LiveTrafficMonitor\Tests;

use Modules_LiveTrafficMonitor_Redactor as Redactor;
use PHPUnit\Framework\Attributes\DataProvider;

final class RedactorTest extends TestCase
{
    #[DataProvider('targets')]
    public function testRedact(string $target, string $expected): void
    {
        self::assertSame($expected, (new Redactor())->redact($target));
    }

    public static function targets(): array
    {
        $jwt = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U';
        return [
            'plain path' => ['/shop/category/books', '/shop/category/books'],
            'harmless query' => ['/?page=2&sort=price&s=book', '/?page=2&sort=price&s=book'],
            'token' => ['/api?token=abc123&page=2', '/api?token=[redacted]&page=2'],
            'access_token' => ['/cb?access_token=x&state=y&code=z', '/cb?access_token=[redacted]&state=[redacted]&code=[redacted]'],
            'wp nonce' => ['/wp-admin/post.php?action=trash&_wpnonce=1a2b3c4d5e', '/wp-admin/post.php?action=trash&_wpnonce=[redacted]'],
            'wp password reset' => ['/wp-login.php?action=rp&key=AbCdEf&login=admin', '/wp-login.php?action=rp&key=[redacted]&login=[redacted]'],
            'camelCase api key' => ['/x?apiKey=1&ClientSecret=2', '/x?apiKey=[redacted]&ClientSecret=[redacted]'],
            'aws presigned' => ['/f?X-Amz-Credential=a&X-Amz-Signature=b&X-Amz-Date=20261026', '/f?X-Amz-Credential=[redacted]&X-Amz-Signature=[redacted]&X-Amz-Date=20261026'],
            'email value' => ['/unsubscribe?u=someone%40example.com', '/unsubscribe?u=[redacted]'],
            'jwt value under innocent key' => ['/x?q=' . $jwt, '/x?q=[redacted]'],
            'long hex value' => ['/dl?id=' . str_repeat('a1', 20), '/dl?id=[redacted]'],
            'random value' => ['/x?v=Zx8KpQ2mN7rT4wYb9LcV', '/x?v=[redacted]'],
            'bare token query' => ['/x?Zx8KpQ2mN7rT4wYb9LcV3', '/x?[redacted]'],
            'nested redirect with token' => ['/login?next=%2Fapp%3Ftoken%3Dabc', '/login?next=[redacted]'],
            'nested redirect harmless' => ['/login?next=%2Fapp%3Fpage%3D2', '/login?next=%2Fapp%3Fpage%3D2'],
            'author is not auth' => ['/?author=5', '/?author=5'],
            'jwt in path' => ['/verify/' . $jwt, '/verify/[redacted]'],
            'reset token in path' => ['/reset-password/Zx8KpQ2mN7rT4wYb9LcV3nH6sD1fG5jA', '/reset-password/[redacted]'],
            'jsessionid' => ['/cart;jsessionid=ABC123?x=1', '/cart;jsessionid=[redacted]?x=1'],
            'hashed asset kept' => ['/assets/inter-v20-cyrillic_latin-500-BOsU2yrk.woff2', '/assets/inter-v20-cyrillic_latin-500-BOsU2yrk.woff2'],
            'hex cache-busting path kept' => ['/build/' . str_repeat('ab12', 10) . '/app.js', '/build/' . str_repeat('ab12', 10) . '/app.js'],
            'slug kept' => ['/Summer-Sale-2024-Edition-Version-X1-Final', '/Summer-Sale-2024-Edition-Version-X1-Final'],
            'utf-8 decoded' => ['/produkt/%D0%BA%D0%BE%D0%BC%D0%BF%D0%BB%D0%B5%D0%BA%D1%82', '/produkt/комплект'],
            'ascii escapes kept' => ['/a%20b%2Fc%3Fd', '/a%20b%2Fc%3Fd'],
            'invalid utf-8 encoded' => ["/a\xff\xfe", '/a%FF%FE'],
            'raw utf-8 kept' => ['/комплект', '/комплект'],
            'control characters encoded' => ["/a\x01b c", '/a%01b%20c'],
            'bidi override encoded' => ["/a\u{202E}gpj.exe", '/a%E2%80%AEgpj.exe'],
            'percent-encoded bidi stays encoded' => ['/a%E2%80%AEgpj.exe', '/a%E2%80%AEgpj.exe'],
            'html stays plain text' => ['/<script>alert(1)</script>', '/<script>alert(1)</script>'],
            'fragment dropped' => ['/a#frag', '/a'],
        ];
    }

    public function testTruncatesLongTargets(): void
    {
        $redacted = (new Redactor())->redact('/' . str_repeat('я', 600));

        self::assertSame(Redactor::MAX_LENGTH, mb_strlen($redacted, 'UTF-8'));
        self::assertStringEndsWith('…', $redacted);
    }
}
