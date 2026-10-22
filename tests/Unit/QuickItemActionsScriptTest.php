<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class QuickItemActionsScriptTest extends TestCase
{
    public function test_quick_item_action_behaviors(): void
    {
        $script = PROJECT_PATH . '/tests/Unit/quick-item-actions.test.js';
        exec('node --test ' . escapeshellarg($script) . ' 2>&1', $output, $code);
        $this->assertSame(0, $code, implode("\n", $output));
    }

    public function test_pages_load_the_shared_module_before_their_callers(): void
    {
        foreach ([
            'useby.php' => '/artifacts/useby-table.js',
            'index.php' => '/shared/js/items-list.js',
            'new.php' => '/shared/js/create-item-lookup.js',
            'to-get-rid-of.php' => 'QuickItemActions.bindKept',
        ] as $page => $caller) {
            $source = file_get_contents(PROJECT_PATH . '/ui/artifacts/' . $page);
            $dependencyPosition = strpos($source, '/shared/js/quick-item-actions.js');
            $callerPosition = strpos($source, $caller);
            $this->assertNotFalse($dependencyPosition, $page);
            $this->assertNotFalse($callerPosition, $page);
            $this->assertLessThan($callerPosition, $dependencyPosition, $page);
        }
    }
}
