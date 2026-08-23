<?php

use App\Http\Controllers\ControlRoom\ControlRoomAlertController;

test('the alert index delegates adjacent datasets to the canonical scoped worklist query', function () {
    $reflection = new ReflectionMethod(ControlRoomAlertController::class, 'index');
    $lines = file($reflection->getFileName());
    $source = implode('', array_slice(
        $lines,
        $reflection->getStartLine() - 1,
        $reflection->getEndLine() - $reflection->getStartLine() + 1,
    ));

    expect($source)
        ->toContain('$worklists->forUser(')
        ->toContain('$worklists->viewContextFor(')
        ->not->toContain('ControlRoomAlert::query(')
        ->not->toContain('Client::query(')
        ->not->toContain('Site::query(')
        ->not->toContain('TriageQueue::');
});

test('the canonical worklist query scopes rows aggregates queue counts and creation options', function () {
    $source = file_get_contents(dirname(__DIR__, 2).'/app/Services/ControlRoom/AlertWorklistQuery.php');

    expect($source)
        ->toContain('private function visibleAlerts(')
        ->toContain('applyVisibleAlertScope(')
        ->toContain('$this->alertAccess->applyVisibleScope(')
        ->toContain("if (! \$can['create'])")
        ->toContain('applyClientScope(')
        ->toContain('applySiteScope(');
});
