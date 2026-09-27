<?php

// Pure permission-contract probe; real source services, mocked actor, no database.
require getcwd().'/vendor/autoload.php';
$container = new Illuminate\Container\Container;
Illuminate\Container\Container::setInstance($container);
$container->instance('config', new Illuminate\Config\Repository(['operational-reports' => require getcwd().'/config/operational-reports.php']));
$results = [];
foreach (['dashboard_only' => ['controlRoom.viewAny'], 'alert_reader' => ['controlRoom.alerts.view']] as $case => $permissions) {
    $grants = [...$permissions, 'assets.telemetry.view', 'hazards.view'];
    $actor = Mockery::mock(App\Models\User::class)->makePartial();
    $actor->setRawAttributes(['id' => 123, 'approved_at' => '2026-09-01 00:00:00']);
    $actor->shouldReceive('fresh')->andReturn($actor);
    $actor->shouldReceive('loadMissing')->andReturn($actor);
    $actor->shouldReceive('canDo')->andReturnUsing(fn (string $permission): bool => in_array($permission, $grants, true));
    $canonical = (new App\Services\ControlRoom\ControlRoomAlertAccessService(new App\Services\UserSiteAccessService))->canRead($actor);
    $sources = (new App\Services\Reporting\ReportAccess)->sources($actor);
    $results[$case] = ['canonicalCanReadAlert' => $canonical, 'clientAlertReportOffered' => isset($sources['client_alerts']), 'staffAlertReportOffered' => isset($sources['staff_alerts'])];
}
Mockery::close();
echo json_encode($results, JSON_PRETTY_PRINT), PHP_EOL;
exit($results['dashboard_only']['clientAlertReportOffered'] || ! $results['alert_reader']['clientAlertReportOffered'] ? 1 : 0);
