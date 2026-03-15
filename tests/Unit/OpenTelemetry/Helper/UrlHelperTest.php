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

    public static function provideSanitizeCases(): Generator
    {
        yield 'no credentials' => [
            'https://example.com/path',
            'https://example.com/path'
        ];
        yield 'user and password' => [
            'https://admin:secret@example.com/path',
            'https://xxx:xxx@example.com/path'
        ];
        yield 'user only' => [
            'https://admin@example.com',
            'https://xxx@example.com'
        ];
        yield 'user password with port query fragment' => [
            'https://user:pass@host.com:8080/api?foo=bar#section',
            'https://xxx:xxx@host.com:8080/api?foo=bar#section'
        ];
        yield 'user only with port' => [
            'redis://myuser@localhost:6379',
            'redis://xxx@localhost:6379'
        ];
        yield 'no at sign' => [
            'http://example.com/no-credentials',
            'http://example.com/no-credentials',
        ];
        yield 'plain string no at sign' => [
            'just-a-string',
            'just-a-string'
        ];
    }
}
