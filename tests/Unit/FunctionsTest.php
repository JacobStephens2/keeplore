<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class FunctionsTest extends TestCase
{
    // -----------------------------------------------------------------
    // url_for()
    // -----------------------------------------------------------------

    public function test_url_for_with_leading_slash(): void
    {
        $this->assertSame('/page/show.php', url_for('/page/show.php'));
    }

    public function test_url_for_without_leading_slash(): void
    {
        $this->assertSame('/page/show.php', url_for('page/show.php'));
    }

    public function test_url_for_root(): void
    {
        $this->assertSame('/', url_for('/'));
    }

    // -----------------------------------------------------------------
    // h() - HTML escaping
    // -----------------------------------------------------------------

    public function test_h_escapes_html_entities(): void
    {
        $this->assertSame('&lt;script&gt;alert(1)&lt;/script&gt;', h('<script>alert(1)</script>'));
    }

    public function test_h_escapes_ampersand(): void
    {
        $this->assertSame('a &amp; b', h('a & b'));
    }

    public function test_h_escapes_quotes(): void
    {
        $this->assertSame('&quot;hello&quot;', h('"hello"'));
    }

    public function test_h_with_empty_string(): void
    {
        $this->assertSame('', h(''));
    }

    public function test_h_with_no_argument(): void
    {
        $this->assertSame('', h());
    }

    public function test_h_with_null_returns_empty_string(): void
    {
        set_error_handler(static function (int $severity, string $message): bool {
            if ($severity === E_DEPRECATED) {
                throw new \RuntimeException($message);
            }
            return false;
        });
        try {
            $this->assertSame('', h(null));
        } finally {
            restore_error_handler();
        }
    }

    public function test_h_with_safe_string(): void
    {
        $this->assertSame('hello world', h('hello world'));
    }

    public function test_normalize_item_image_url_keeps_https(): void
    {
        $url = 'https://cf.geekdo-images.com/uXMeQzNenHb3zK7Hoa6b2w__itemrep/img/oaw-LYEIaB20e79Y568JgyHZ5NQ=/fit-in/246x300/filters:strip_icc()/pic7398904.jpg';
        $this->assertSame($url, normalize_item_image_url('  ' . $url . '  '));
    }

    public function test_normalize_item_image_url_rejects_non_https(): void
    {
        $this->assertSame('', normalize_item_image_url('http://cf.geekdo-images.com/x.jpg'));
        $this->assertSame('', normalize_item_image_url('javascript:alert(1)'));
        $this->assertSame('', normalize_item_image_url(''));
    }

    public function test_normalize_item_bgg_url_keeps_geek_site_links(): void
    {
        $url = 'https://boardgamegeek.com/boardgame/621/25-words-or-less';
        $this->assertSame($url, normalize_item_bgg_url('  ' . $url . '  '));
        $this->assertSame('https://www.rpggeek.com/rpgitem/1', normalize_item_bgg_url('https://www.rpggeek.com/rpgitem/1'));
        $this->assertSame('https://videogamegeek.com/videogame/2', normalize_item_bgg_url('https://videogamegeek.com/videogame/2'));
    }

    public function test_normalize_item_bgg_url_upgrades_a_typed_link_to_https(): void
    {
        $this->assertSame('https://boardgamegeek.com/boardgame/621', normalize_item_bgg_url('http://boardgamegeek.com/boardgame/621'));
        $this->assertSame('https://boardgamegeek.com/boardgame/621', normalize_item_bgg_url('boardgamegeek.com/boardgame/621'));
        $this->assertSame('https://www.boardgamegeek.com/boardgame/621', normalize_item_bgg_url('www.boardgamegeek.com/boardgame/621'));
    }

    public function test_item_bgg_link_html_names_the_site(): void
    {
        $this->assertSame('', item_bgg_link_html(''));
        $this->assertSame('', item_bgg_link_html('https://example.com/'));
        $html = item_bgg_link_html('https://boardgamegeek.com/boardgame/621/25-words-or-less');
        $this->assertStringContainsString('href="https://boardgamegeek.com/boardgame/621/25-words-or-less"', $html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
        $this->assertStringContainsString('>View on BoardGameGeek<', $html);
        $this->assertStringContainsString('>View on RPGGeek<', item_bgg_link_html('https://rpggeek.com/rpgitem/1'));
        $this->assertStringContainsString('>View on VideoGameGeek<', item_bgg_link_html('https://www.videogamegeek.com/videogame/2'));
    }

    public function test_normalize_item_bgg_url_rejects_other_links(): void
    {
        $this->assertSame('', normalize_item_bgg_url('https://boardgamegeek.com.evil.test/boardgame/621'));
        $this->assertSame('', normalize_item_bgg_url('https://example.com/'));
        $this->assertSame('', normalize_item_bgg_url('javascript:alert(1)'));
        $this->assertSame('', normalize_item_bgg_url(''));
    }

    // -----------------------------------------------------------------
    // u() - URL encoding
    // -----------------------------------------------------------------

    public function test_u_encodes_spaces(): void
    {
        $this->assertSame('hello+world', u('hello world'));
    }

    public function test_u_encodes_special_characters(): void
    {
        $this->assertSame('a%26b%3Dc', u('a&b=c'));
    }

    public function test_u_with_empty_string(): void
    {
        $this->assertSame('', u(''));
    }

    public function test_u_with_no_argument(): void
    {
        $this->assertSame('', u());
    }

    // -----------------------------------------------------------------
    // raw_u() - raw URL encoding
    // -----------------------------------------------------------------

    public function test_raw_u_encodes_spaces_as_percent20(): void
    {
        $this->assertSame('hello%20world', raw_u('hello world'));
    }

    public function test_raw_u_with_empty_string(): void
    {
        $this->assertSame('', raw_u(''));
    }

    // -----------------------------------------------------------------
    // is_post_request() / is_get_request()
    // -----------------------------------------------------------------

    public function test_is_get_request_returns_true_by_default(): void
    {
        // bootstrap sets REQUEST_METHOD to GET
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $this->assertTrue(is_get_request());
        $this->assertFalse(is_post_request());
    }

    public function test_is_post_request_returns_true_when_post(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->assertTrue(is_post_request());
        $this->assertFalse(is_get_request());
    }

    protected function tearDown(): void
    {
        // Reset to GET so other tests are not affected
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    // -----------------------------------------------------------------
    // display_errors()
    // -----------------------------------------------------------------

    public function test_display_errors_with_no_errors(): void
    {
        $this->assertSame('', display_errors([]));
    }

    public function test_display_errors_with_empty_default(): void
    {
        $this->assertSame('', display_errors());
    }

    public function test_display_errors_with_single_error(): void
    {
        $output = display_errors(['Name is required.']);
        $this->assertStringContainsString('<div class="errors">', $output);
        $this->assertStringContainsString('<li>Name is required.</li>', $output);
        $this->assertStringContainsString('</ul>', $output);
        $this->assertStringContainsString('</div>', $output);
    }

    public function test_display_errors_with_multiple_errors(): void
    {
        $errors = ['Name is required.', 'Email is invalid.'];
        $output = display_errors($errors);
        $this->assertStringContainsString('<li>Name is required.</li>', $output);
        $this->assertStringContainsString('<li>Email is invalid.</li>', $output);
    }

    public function test_display_errors_escapes_html_in_messages(): void
    {
        $output = display_errors(['<script>alert(1)</script>']);
        $this->assertStringContainsString('&lt;script&gt;', $output);
        $this->assertStringNotContainsString('<script>', $output);
    }
}
