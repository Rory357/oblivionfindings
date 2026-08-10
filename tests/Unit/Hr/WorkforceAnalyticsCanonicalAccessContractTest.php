<?php

function workforceCanonicalSource(string $relativePath): string
{
    $source = file_get_contents(dirname(__DIR__, 3).'/'.$relativePath);
    expect($source)->not->toBeFalse();

    return (string) $source;
}

/** @return array<string, string> */
function workforceForbiddenLegacyAccessTerms(): array
{
    $partitionField = implode('', ['ten', 'ant_id']);

    return [
        'context_helper' => implode('', ['hrApplicationStorage', 'ContextId']),
        'context_parameter' => implode('', ['ten', 'antId']),
        'alternate_context_parameter' => implode('', ['organisa', 'tionId']),
        'scope' => implode('', ['forTen', 'ant(']),
        'query' => "where('{$partitionField}'",
    ];
}

test('workforce analytics and headcount use one application staff population', function () {
    $forbidden = workforceForbiddenLegacyAccessTerms();

    foreach ([
        'app/Http/Controllers/Hr/AnalyticsDashboardController.php',
        'app/Http/Controllers/Hr/HeadcountController.php',
        'app/Domain/Hr/Services/WorkforceAnalyticsService.php',
        'app/Domain/Hr/Services/HeadcountForecastService.php',
    ] as $path) {
        expect(workforceCanonicalSource($path))
            ->not->toContain('ResolvesHrOrganisationContext')
            ->not->toContain($forbidden['context_helper'])
            ->not->toContain($forbidden['context_parameter'])
            ->not->toContain($forbidden['scope'])
            ->not->toContain($forbidden['query']);
    }
});

test('calendar performance configuration and audit history are application wide', function () {
    $forbidden = workforceForbiddenLegacyAccessTerms();

    foreach ([
        'app/Http/Controllers/Hr/AuditController.php',
        'app/Http/Controllers/Hr/ICalController.php',
        'app/Http/Controllers/Hr/PerformanceHubController.php',
        'app/Models/AuditLog.php',
    ] as $path) {
        expect(workforceCanonicalSource($path))
            ->not->toContain('ResolvesHrOrganisationContext')
            ->not->toContain($forbidden['context_helper'])
            ->not->toContain($forbidden['alternate_context_parameter'])
            ->not->toContain($forbidden['scope'])
            ->not->toContain('forOrganization(')
            ->not->toContain($forbidden['query']);
    }
});
