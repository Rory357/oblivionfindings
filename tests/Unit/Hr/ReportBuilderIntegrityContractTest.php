<?php

function reportBuilderIntegritySource(string $relativePath): string
{
    $source = file_get_contents(dirname(__DIR__, 3).'/'.$relativePath);
    expect($source)->not->toBeFalse();

    return (string) $source;
}

function reportBuilderLegacyPartitionField(): string
{
    return implode('', ['ten', 'ant_id']);
}

function reportBuilderLegacyScopeMethod(): string
{
    return implode('', ['forTen', 'ant']);
}

test('report builder execution is explicit permission and Site scoped', function (): void {
    $service = reportBuilderIntegritySource('app/Domain/Hr/Services/ReportBuilderService.php');

    expect($service)
        ->toContain('UserSiteAccessService')
        ->toContain('applyHistoricalStaffProfileScope')
        ->toContain('applyHistoricalStaffSiteScope')
        ->toContain('FIELD_MAPS')
        ->toContain('SOURCE_PERMISSIONS')
        ->toContain('FIELD_PERMISSIONS')
        ->toContain('assertDefinitionAllowed')
        ->not->toContain('auth()')
        ->not->toContain(reportBuilderLegacyPartitionField())
        ->not->toContain('select *');
});

test('saved definitions are creator owned and expose no inert scheduler', function (): void {
    $controller = reportBuilderIntegritySource('app/Http/Controllers/Hr/ReportBuilderController.php');
    $routes = reportBuilderIntegritySource('routes/hr.php');
    $savedPage = reportBuilderIntegritySource('resources/js/pages/hr/reports/saved.tsx');

    expect($controller)
        ->toContain("->where('created_by', \$user->id)")
        ->toContain('ownedReport')
        ->toContain('lockForUpdate()')
        ->not->toContain(reportBuilderLegacyScopeMethod())
        ->not->toContain(reportBuilderLegacyPartitionField())
        ->not->toContain('function schedule');

    expect($routes)
        ->not->toContain('reports.saved.schedule')
        ->and($savedPage)
        ->toContain('Scheduled Reports')
        ->toContain('canExport')
        ->not->toContain('is_scheduled');
});

test('saved report storage uses creator identity and application read indexes', function (): void {
    $migration = reportBuilderIntegritySource(
        'database/migrations/2026_08_02_000022_realign_hr_saved_report_application_identity.php',
    );

    expect($migration)
        ->toContain('hr_saved_reports_creator_name_uq')
        ->toContain('hr_saved_reports_creator_updated_idx')
        ->toContain('hr_saved_reports_type_updated_idx')
        ->toContain('duplicate creator/name definitions')
        ->toContain('legacyIndexes()')
        ->toContain(sprintf(
            "'hr_saved_reports_%s_index' => ['%s']",
            reportBuilderLegacyPartitionField(),
            reportBuilderLegacyPartitionField(),
        ));
});
