<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Seam: login_return_path(). After an Account logs in, ui/login.php follows
 * the posted return path only when that posted value is a same-origin path:
 * one leading slash, no scheme, and not protocol-relative. Any other posted
 * value is /index.php. The posted bytes are not decoded into the Location,
 * so a percent-encoded slash cannot become a second slash. A Quick item
 * action return_to is a different seam and is not this function.
 */
class LoginReturnPathTest extends TestCase
{
    public static function postedReturnPaths(): array
    {
        return [
            'app path' => ['/artifacts/show.php?id=12', '/artifacts/show.php?id=12'],
            'index' => ['/index.php', '/index.php'],
            'root' => ['/', '/'],
            'hash on an app path' => ['/artifacts/show.php#kept', '/artifacts/show.php#kept'],
            'encoded character inside an app path' => [
                '/artifacts/show.php?name=caf%C3%A9',
                '/artifacts/show.php?name=caf%C3%A9',
            ],
            'plus stays a plus' => ['/search.php?q=a+b', '/search.php?q=a+b'],
            'percent-encoded slash stays in the path' => ['/%2Fevil.example', '/%2Fevil.example'],
            'encoded app path has no leading slash' => ['%2Fartifacts%2Fshow.php%3Fid%3D12', '/index.php'],
            'https scheme' => ['https://evil.example/phish', '/index.php'],
            'http scheme' => ['http://evil.example', '/index.php'],
            'scheme without slashes' => ['javascript:alert(1)', '/index.php'],
            'protocol-relative' => ['//evil.example/phish', '/index.php'],
            'three leading slashes' => ['///evil.example', '/index.php'],
            'backslash a browser reads as protocol-relative' => ['/\\evil.example', '/index.php'],
            'backslash in the query is still the posted path' => [
                "/artifacts/show.php?q=a\\b",
                "/artifacts/show.php?q=a\\b",
            ],
            'backslash after a segment stays on this host' => [
                '/foo/\\evil.example',
                '/foo/\\evil.example',
            ],
            'trailing tab is still the posted path' => [
                "/artifacts/show.php?id=12\t",
                "/artifacts/show.php?id=12\t",
            ],
            'tab a browser drops before the second slash' => ["/\t/evil.example", '/index.php'],
            'header split' => ["/index.php\r\nLocation: https://evil.example", '/index.php'],
            'no leading slash' => ['index.php', '/index.php'],
            'leading space' => [' /index.php', '/index.php'],
            'empty' => ['', '/index.php'],
            'encoded protocol-relative' => ['%2F%2Fevil.example', '/index.php'],
            'encoded scheme' => ['https:%2F%2Fevil.example', '/index.php'],
        ];
    }

    #[DataProvider('postedReturnPaths')]
    public function test_login_follows_a_posted_return_path_only_when_it_is_same_origin(
        string $posted,
        string $expected
    ): void {
        $this->assertSame($expected, login_return_path($posted));
    }

    public function test_a_posted_return_path_that_is_not_a_string_goes_to_the_dashboard(): void
    {
        $this->assertSame('/index.php', login_return_path(['//evil.example']));
    }
}
