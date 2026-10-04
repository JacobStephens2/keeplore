<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ValidationFunctionsTest extends TestCase
{
    // -----------------------------------------------------------------
    // is_blank()
    // -----------------------------------------------------------------

    public function test_is_blank_with_null(): void
    {
        $this->assertTrue(is_blank(null));
    }

    public function test_is_blank_with_empty_string(): void
    {
        $this->assertTrue(is_blank(''));
    }

    public function test_is_blank_with_spaces_only(): void
    {
        $this->assertTrue(is_blank('   '));
    }

    public function test_is_blank_with_valid_string(): void
    {
        $this->assertFalse(is_blank('hello'));
    }

    public function test_is_blank_with_zero_string(): void
    {
        // "0" is NOT blank (unlike PHP's empty())
        $this->assertFalse(is_blank('0'));
    }

    // -----------------------------------------------------------------
    // has_presence()
    // -----------------------------------------------------------------

    public function test_has_presence_with_valid_string(): void
    {
        $this->assertTrue(has_presence('hello'));
    }

    public function test_has_presence_with_null(): void
    {
        $this->assertFalse(has_presence(null));
    }

    public function test_has_presence_with_empty_string(): void
    {
        $this->assertFalse(has_presence(''));
    }

    public function test_has_presence_with_spaces_only(): void
    {
        $this->assertFalse(has_presence('   '));
    }

    public function test_has_presence_with_zero_string(): void
    {
        $this->assertTrue(has_presence('0'));
    }

    // -----------------------------------------------------------------
    // has_length_greater_than()
    // -----------------------------------------------------------------

    public function test_has_length_greater_than_true(): void
    {
        $this->assertTrue(has_length_greater_than('abcd', 3));
    }

    public function test_has_length_greater_than_equal(): void
    {
        // Equal length should return false (strictly greater than)
        $this->assertFalse(has_length_greater_than('abc', 3));
    }

    public function test_has_length_greater_than_false(): void
    {
        $this->assertFalse(has_length_greater_than('ab', 3));
    }

    // -----------------------------------------------------------------
    // has_length_less_than()
    // -----------------------------------------------------------------

    public function test_has_length_less_than_true(): void
    {
        $this->assertTrue(has_length_less_than('ab', 3));
    }

    public function test_has_length_less_than_equal(): void
    {
        $this->assertFalse(has_length_less_than('abc', 3));
    }

    public function test_has_length_less_than_false(): void
    {
        $this->assertFalse(has_length_less_than('abcd', 3));
    }

    // -----------------------------------------------------------------
    // has_length_exactly()
    // -----------------------------------------------------------------

    public function test_has_length_exactly_true(): void
    {
        $this->assertTrue(has_length_exactly('abcd', 4));
    }

    public function test_has_length_exactly_false(): void
    {
        $this->assertFalse(has_length_exactly('abc', 4));
    }

    // -----------------------------------------------------------------
    // has_length() (composite)
    // -----------------------------------------------------------------

    public function test_has_length_with_min_pass(): void
    {
        $this->assertTrue(has_length('abcde', ['min' => 3]));
    }

    public function test_has_length_with_min_exact(): void
    {
        $this->assertTrue(has_length('abc', ['min' => 3]));
    }

    public function test_has_length_with_min_fail(): void
    {
        $this->assertFalse(has_length('ab', ['min' => 3]));
    }

    public function test_has_length_with_max_pass(): void
    {
        $this->assertTrue(has_length('abc', ['max' => 5]));
    }

    public function test_has_length_with_max_exact(): void
    {
        $this->assertTrue(has_length('abcde', ['max' => 5]));
    }

    public function test_has_length_with_max_fail(): void
    {
        $this->assertFalse(has_length('abcdef', ['max' => 5]));
    }

    public function test_has_length_with_exact_pass(): void
    {
        $this->assertTrue(has_length('abcd', ['exact' => 4]));
    }

    public function test_has_length_with_exact_fail(): void
    {
        $this->assertFalse(has_length('abc', ['exact' => 4]));
    }

    public function test_has_length_with_min_and_max(): void
    {
        $this->assertTrue(has_length('abcd', ['min' => 3, 'max' => 5]));
        $this->assertFalse(has_length('ab', ['min' => 3, 'max' => 5]));
        $this->assertFalse(has_length('abcdef', ['min' => 3, 'max' => 5]));
    }

    // -----------------------------------------------------------------
    // has_inclusion_of()
    // -----------------------------------------------------------------

    public function test_has_inclusion_of_present(): void
    {
        $this->assertTrue(has_inclusion_of(5, [1, 3, 5, 7, 9]));
    }

    public function test_has_inclusion_of_absent(): void
    {
        $this->assertFalse(has_inclusion_of(4, [1, 3, 5, 7, 9]));
    }

    public function test_has_inclusion_of_strings(): void
    {
        $this->assertTrue(has_inclusion_of('red', ['red', 'green', 'blue']));
        $this->assertFalse(has_inclusion_of('yellow', ['red', 'green', 'blue']));
    }

    // -----------------------------------------------------------------
    // has_exclusion_of()
    // -----------------------------------------------------------------

    public function test_has_exclusion_of_absent(): void
    {
        $this->assertTrue(has_exclusion_of(4, [1, 3, 5, 7, 9]));
    }

    public function test_has_exclusion_of_present(): void
    {
        $this->assertFalse(has_exclusion_of(5, [1, 3, 5, 7, 9]));
    }

    // -----------------------------------------------------------------
    // has_string()
    // -----------------------------------------------------------------

    public function test_has_string_found(): void
    {
        $this->assertTrue(has_string('nobody@nowhere.com', '.com'));
    }

    public function test_has_string_not_found(): void
    {
        $this->assertFalse(has_string('nobody@nowhere.com', '.org'));
    }

    public function test_has_string_at_start(): void
    {
        $this->assertTrue(has_string('hello world', 'hello'));
    }

    public function test_has_string_empty_needle(): void
    {
        // strpos with empty needle: PHP 8+ returns 0 (beginning of string)
        $this->assertTrue(has_string('anything', ''));
    }
}
