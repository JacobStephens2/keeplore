<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ThemeTest extends TestCase
{
    public function test_default_is_system(): void
    {
        $this->assertSame('system', theme_default());
    }

    public function test_options_cover_system_light_dark_in_order(): void
    {
        $this->assertSame(
            ['system' => 'System', 'light' => 'Light', 'dark' => 'Dark'],
            theme_options()
        );
    }

    public function test_valid_values_pass_through_sanitize(): void
    {
        $this->assertSame('system', theme_sanitize('system'));
        $this->assertSame('light', theme_sanitize('light'));
        $this->assertSame('dark', theme_sanitize('dark'));
    }

    public function test_unknown_values_fall_back_to_default(): void
    {
        $this->assertSame('system', theme_sanitize('midnight'));
        $this->assertSame('system', theme_sanitize(''));
        $this->assertSame('system', theme_sanitize(null));
        $this->assertSame('system', theme_sanitize('LIGHT'));
    }

    public function test_default_is_one_of_the_options(): void
    {
        $this->assertArrayHasKey(theme_default(), theme_options());
    }
}
