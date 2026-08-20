<?php

namespace Tests\Architecture;

use Tests\TestCase;

class SafeguardingTerminalTransitionOwnershipTest extends TestCase
{
    public function test_terminal_controller_paths_delegate_to_the_single_server_owner(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/SafeguardingConcernController.php'));
        $owner = file_get_contents(app_path('Services/Safeguarding/SafeguardingTerminalTransitionService.php'));

        $this->assertIsString($controller);
        $this->assertIsString($owner);
        $this->assertStringContainsString('SafeguardingTerminalTransitionService $terminalTransitions', $controller);
        $this->assertStringContainsString('$terminalTransitions->noAction(', $controller);
        $this->assertStringContainsString('$terminalTransitions->close(', $controller);
        $this->assertStringNotContainsString('private function syncTerminalState', $controller);
        $this->assertStringNotContainsString("'journey_attention'", $controller);
        $this->assertSame(1, substr_count($owner, "'journey_terminal'"));
        $this->assertStringContainsString('lockForUpdate()', $owner);
        $this->assertStringContainsString('AuditLogger::logOrFail(', $owner);
    }
}
