<?php

use App\Domain\It\Services\ItObliDiagnosticProjection;

it('projects only bounded numeric diagnostic allowlists and reports incomplete observations', function () {
    $projection = ItObliDiagnosticProjection::project(['hostname' => 'private-person-host', 'os_version' => 'Microsoft Windows 11 Pro 10.0.26100.1', 'architecture' => 'amd64', 'memory_total_bytes' => 8192, 'memory_available_bytes' => 9000, 'uptime_seconds' => 120, 'cpu_percent' => 12.36, 'disks' => [['name' => 'C:', 'total_bytes' => 1000, 'free_bytes' => 500], ['name' => 'private-path', 'total_bytes' => 1000, 'free_bytes' => 500]], 'software' => [['name' => 'Microsoft Edge', 'version' => '130.0.1', 'publisher' => 'private-publisher'], ['name' => 'Private payroll', 'version' => '1.0']], 'software_complete' => false, 'services' => [['name' => 'Spooler', 'state' => 'running'], ['name' => 'Private service', 'state' => 'running']], 'services_complete' => true, 'probe_errors' => []]);
    expect($projection['snapshot']['system']['memory_available_bytes'])->toBeNull()
        ->and($projection['snapshot']['system']['cpu_percent'])->toBe(12.4)
        ->and($projection['snapshot']['system']['windows_version'])->toBe('10.0.26100.1')
        ->and($projection['snapshot']['software'])->toBe([['name' => 'microsoft_edge', 'version' => '130.0.1']])
        ->and($projection['snapshot']['services'])->toBe([['name' => 'print_spooler', 'state' => 'running']])
        ->and($projection['completeness']['system']['missing_fields'])->toBe(['memory_available_bytes', 'disks'])
        ->and($projection['completeness']['software']['complete'])->toBeFalse()
        ->and(json_encode($projection))->not->toContain('private')->not->toContain('Private');
});

it('does not guess conflicting software versions or service state from partial inventory', function () {
    $projection = ItObliDiagnosticProjection::project(['software' => [['name' => 'Google Chrome', 'version' => '1.2'], ['name' => 'google chrome', 'version' => '2.3']], 'software_complete' => true, 'services' => [['name' => 'BITS', 'state' => 'running'], ['name' => 'bits', 'state' => 'stopped']], 'services_complete' => true]);
    expect($projection['snapshot']['software'])->toBe([])
        ->and($projection['snapshot']['services'])->toBe([['name' => 'background_transfer', 'state' => 'unknown']])
        ->and($projection['completeness']['software']['complete'])->toBeFalse()
        ->and($projection['completeness']['services']['complete'])->toBeFalse();
    $empty = ItObliDiagnosticProjection::project([]);
    expect($empty['snapshot']['software'])->toBe([])->and($empty['snapshot']['services'])->toBe([])
        ->and($empty['completeness']['system']['missing_fields'])->toBe(ItObliDiagnosticProjection::MISSING_FIELDS);
});
