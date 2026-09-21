<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Seam: Edit User loads the user-interactions include and the shared
 * record-use submit module. Behaviour of those modules is tested at
 * their own seams (RecordUseTest, RecordUseSubmitTest).
 */
class UserEditRecordUseTest extends TestCase
{
    public function test_edit_user_loads_the_interactions_include(): void
    {
        $page = (string) file_get_contents(PROJECT_PATH . '/ui/users/edit.php');
        $this->assertStringContainsString("SHARED_PATH . '/user_interactions.php'", $page);
        $this->assertStringNotContainsString('id="record-use-form"', $page);
    }

    public function test_interactions_include_records_a_use_through_the_shared_module(): void
    {
        $include = (string) file_get_contents(PROJECT_PATH . '/private/shared/user_interactions.php');
        $this->assertStringContainsString('id="record-use-form"', $include);
        $this->assertStringContainsString('/uses/record-new.php', $include);
        $this->assertStringContainsString('record_use_participants', $include);
        $this->assertStringContainsString('most_recent_use_setting', $include);
        $this->assertStringContainsString('record-use-submit.js', $include);
        $this->assertStringContainsString('RecordUseSubmit.bind', $include);
    }
}
