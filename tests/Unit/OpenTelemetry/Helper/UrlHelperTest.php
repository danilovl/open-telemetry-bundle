<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Unit\OpenTelemetry\Helper;

use Danilovl\OpenTelemetryBundle\OpenTelemetry\Helper\UrlHelper;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UrlHelperTest extends TestCase
{
    #[DataProvider('provideSanitizeCases')]
    public function testSanitize(string $url, string $expected): void
    {
        $this->assertSame($expected, UrlHelper::sanitize($url));
    }

    public function testSanitizeWithUnparsableUrlReturnsOriginal(): void
    {
        $url = 'not_a_url_with_no_user@somewhere';
        $result = UrlHelper::sanitize($url);
        $this->assertSame($url, $result);
    }

    public function testCustomSensitiveParameters(): void
    {
        $this->assertSame(
            'https://h/p?token=abc&order_id=REDACTED',
            UrlHelper::sanitize('https://h/p?token=abc&order_id=42', ['order_id'])
        );
    }

    public function testEmptySensitiveParameters(): void
    {
        $this->assertSame(
            'https://REDACTED:REDACTED@h/p?token=abc',
            UrlHelper::sanitize('https://u:p@h/p?token=abc', [])
        );
    }

    public function testSensitiveParameterCaseInsensitive(): void
    {
        $this->assertSame('/p?ACCESS_TOKEN=REDACTED', UrlHelper::sanitize('/p?ACCESS_TOKEN=x'));
    }

    public function testRedactQueryKeepsEncoding(): void
    {
        $this->assertSame(
            'q=hello%20world&api_key=REDACTED&empty=&flag',
            UrlHelper::redactQuery('q=hello%20world&api_key=s3cr3t&empty=&flag')
        );
    }

    public static function provideSanitizeCases(): Generator
    {
        yield 'no credentials' => [
            'https://example.com/path',
            'https://example.com/path'
        ];
        yield 'user and password' => [
            'https://admin:secret@example.com/path',
            'https://REDACTED:REDACTED@example.com/path'
        ];
        yield 'user only' => [
            'https://admin@example.com',
            'https://REDACTED@example.com'
        ];
        yield 'user password with port query fragment' => [
            'https://user:pass@host.com:8080/api?foo=bar#section',
            'https://REDACTED:REDACTED@host.com:8080/api?foo=bar#section'
        ];
        yield 'user only with port' => [
            'redis://myuser@localhost:6379',
            'redis://REDACTED@localhost:6379'
        ];
        yield 'no at sign' => [
            'http://example.com/no-credentials',
            'http://example.com/no-credentials',
        ];
        yield 'plain string no at sign' => [
            'just-a-string',
            'just-a-string'
        ];
        yield 'at sign in query is not user info' => [
            'https://h/p?email=a@b.c',
            'https://h/p?email=a@b.c'
        ];
        yield 'at sign in path is not user info' => [
            'https://h/users/a@b.c',
            'https://h/users/a@b.c'
        ];
        yield 'token in query' => [
            'https://h/reset?token=abc123&lang=en',
            'https://h/reset?token=REDACTED&lang=en'
        ];
        yield 'semconv aws presigned url' => [
            'https://bucket.s3.amazonaws.com/f.txt?AWSAccessKeyId=AKIA&Signature=xyz&Expires=1',
            'https://bucket.s3.amazonaws.com/f.txt?AWSAccessKeyId=REDACTED&Signature=REDACTED&Expires=1'
        ];
        yield 'semconv azure sig and google signature' => [
            'https://h/f?sig=1&X-Goog-Signature=2&other=3',
            'https://h/f?sig=REDACTED&X-Goog-Signature=REDACTED&other=3'
        ];
        yield 'aws v4 signature' => [
            'https://h/f?X-Amz-Credential=c&X-Amz-Signature=s&X-Amz-Date=d',
            'https://h/f?X-Amz-Credential=REDACTED&X-Amz-Signature=REDACTED&X-Amz-Date=d'
        ];
        yield 'oauth code and api key' => [
            '/callback?code=xyz&state=abc&api_key=k',
            '/callback?code=REDACTED&state=abc&api_key=REDACTED'
        ];
        yield 'array parameters' => [
            '/p?token[]=a&auth[token]=b&tokens=c',
            '/p?token[]=REDACTED&auth[token]=REDACTED&tokens=c'
        ];
        yield 'url encoded parameter name' => [
            '/p?access%5Ftoken=a',
            '/p?access%5Ftoken=REDACTED'
        ];
        yield 'credentials and query and fragment together' => [
            'https://u:p@h:8443/p?password=x&a=1#frag?token=y',
            'https://REDACTED:REDACTED@h:8443/p?password=REDACTED&a=1#frag?token=y'
        ];
        yield 'unencoded at sign in password' => [
            'redis://user:p@ss@host:6379/0',
            'redis://REDACTED:REDACTED@host:6379/0'
        ];
        yield 'protocol relative url' => [
            '//u:p@h/p',
            '//REDACTED:REDACTED@h/p'
        ];
        yield 'empty query' => [
            'https://h/p?',
            'https://h/p?'
        ];
        yield 'parameter without value' => [
            'https://h/p?token',
            'https://h/p?token'
        ];
    }
}
