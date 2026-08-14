<?php

use App\Console\Commands\EscalateOverdueTasks;
use App\Http\Controllers\AllTasksController;
use App\Http\Controllers\MyTasksController;
use App\Http\Middleware\HandleInertiaRequests;
use App\Notifications\AppEventNotification;
use App\Services\NotificationService;
use App\Services\Tasks\Contracts\ExplicitlyGlobalTaskProvider;
use App\Services\Tasks\Contracts\SiteScopedTaskProvider;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\Providers\ActionItemProvider;
use App\Services\Tasks\Providers\CdLossReportProvider;
use App\Services\Tasks\Providers\ClientIncidentProvider;
use App\Services\Tasks\Providers\ControlRoomAlertProvider;
use App\Services\Tasks\Providers\DataBreachProvider;
use App\Services\Tasks\Providers\DataSubjectRequestProvider;
use App\Services\Tasks\Providers\FirstAidFollowupProvider;
use App\Services\Tasks\Providers\FleetIncidentProvider;
use App\Services\Tasks\Providers\FleetMaintenanceProvider;
use App\Services\Tasks\Providers\HrCaseProvider;
use App\Services\Tasks\Providers\HsCorrectiveActionProvider;
use App\Services\Tasks\Providers\HsEventProvider;
use App\Services\Tasks\Providers\HsInvestigationProvider;
use App\Services\Tasks\Providers\IncidentFollowupProvider;
use App\Services\Tasks\Providers\MedicationErrorProvider;
use App\Services\Tasks\Providers\RespiteTaskProvider;
use App\Services\Tasks\Providers\RestraintReviewProvider;
use App\Services\Tasks\Providers\SafeguardingActionPlanProvider;
use App\Services\Tasks\Providers\SafeguardingConcernProvider;
use App\Services\Tasks\Providers\ShiftTaskProvider;
use App\Services\Tasks\Providers\SiteChecklistRunProvider;
use App\Services\Tasks\Providers\SiteHazardProvider;
use App\Services\Tasks\Providers\WorkplaceInjuryProvider;
use App\Services\Tasks\TaskAggregator;
use App\Services\Tasks\TaskAssignmentNotifier;
use App\Services\Tasks\TaskProviderAuthorization;
use Illuminate\Support\Facades\Route;

it('registers every task source behind exactly one authorization boundary', function () {
    $providers = TaskAggregator::defaultProviders();

    expect($providers)->toHaveCount(23);
    expect(collect($providers)->mapWithKeys(fn (TaskProvider $provider): array => [
        $provider::class => $provider->sourceKey(),
    ])->all())->toBe([
        ClientIncidentProvider::class => 'incident',
        IncidentFollowupProvider::class => 'followup',
        HsEventProvider::class => 'hs_event',
        HsInvestigationProvider::class => 'hs_investigation',
        HsCorrectiveActionProvider::class => 'corrective_action',
        SiteHazardProvider::class => 'hazard',
        WorkplaceInjuryProvider::class => 'injury',
        SafeguardingConcernProvider::class => 'safeguarding',
        SafeguardingActionPlanProvider::class => 'safeguarding_action',
        ControlRoomAlertProvider::class => 'alert',
        FleetIncidentProvider::class => 'fleet_incident',
        FleetMaintenanceProvider::class => 'fleet_maintenance',
        MedicationErrorProvider::class => 'med_error',
        CdLossReportProvider::class => 'cd_loss',
        DataBreachProvider::class => 'breach',
        DataSubjectRequestProvider::class => 'dsr',
        ActionItemProvider::class => 'action_item',
        HrCaseProvider::class => 'hr_case',
        SiteChecklistRunProvider::class => 'checklist_run',
        ShiftTaskProvider::class => 'shift_task',
        RespiteTaskProvider::class => 'respite_task',
        FirstAidFollowupProvider::class => 'first_aid_followup',
        RestraintReviewProvider::class => 'restraint_review',
    ]);

    foreach ($providers as $provider) {
        $siteScoped = $provider instanceof SiteScopedTaskProvider;
        $explicitlyGlobal = $provider instanceof ExplicitlyGlobalTaskProvider;

        expect($provider)
            ->toBeInstanceOf(TaskProvider::class)
            ->and((int) $siteScoped + (int) $explicitlyGlobal)
            ->toBe(1, $provider::class.' must declare exactly one row-authorization mode.');

        $reflection = new ReflectionClass($provider);
        $path = $reflection->getFileName();
        $source = $path === false ? '' : file_get_contents($path);

        expect($source)
            ->toContain(TaskProviderAuthorization::class)
            ->not->toContain('public function tasks(')
            ->not->toContain('fn ($scoped) => $scoped')
            ->not->toMatch('/->(?:get|paginate|cursor|lazy|chunk)\s*\(/');

        if ($siteScoped) {
            expect($source)->toContain('->siteScoped(');
        } else {
            expect($source)->toContain('->explicitlyGlobal(');
        }
    }
});

it('keeps the intentionally global providers tied to their explicit product permissions', function () {
    $globalProviders = collect(TaskAggregator::defaultProviders())
        ->filter(fn (TaskProvider $provider): bool => $provider instanceof ExplicitlyGlobalTaskProvider)
        ->mapWithKeys(fn (ExplicitlyGlobalTaskProvider&TaskProvider $provider): array => [
            $provider->sourceKey() => $provider->globalViewPermissions(),
        ])
        ->all();

    expect($globalProviders)->toBe([
        'breach' => ['privacy.reportBreaches'],
        'dsr' => ['privacy.viewRequests'],
        'action_item' => ['governance.actions.view'],
    ]);
});

it('allows row loading only inside the shared provider authorization service', function () {
    $authorization = new ReflectionClass(TaskProviderAuthorization::class);
    $path = $authorization->getFileName();
    $source = $path === false ? '' : file_get_contents($path);

    expect($source)
        ->toContain('$applyCanonicalScope($query, $actor)')
        ->toContain('$scoped->get()->map($project)->all()')
        ->toContain('$query->get()->map($project)->all()');
});

it('inventories every all tasks route and aggregate consumer', function () {
    $routes = [
        'tasks.index' => ['GET', 'AllTasksController@index'],
        'tasks.detail' => ['GET', 'AllTasksController@detail'],
        'tasks.lookup' => ['GET', 'AllTasksController@lookup'],
        'tasks.reports' => ['GET', 'AllTasksController@reports'],
        'tasks.users' => ['GET', 'AllTasksController@users'],
        'tasks.default-view' => ['POST', 'AllTasksController@saveDefaultView'],
        'tasks.assign' => ['POST', 'AllTasksController@assign'],
        'tasks.watch' => ['POST', 'AllTasksController@watch'],
        'tasks.split' => ['POST', 'AllTasksController@split'],
    ];

    foreach ($routes as $name => [$method, $action]) {
        $route = Route::getRoutes()->getByName($name);

        expect($route)->not->toBeNull()
            ->and($route?->methods())->toContain($method)
            ->and($route?->getActionName())->toEndWith($action);
    }

    $consumers = [
        AllTasksController::class => [
            'itemsFor(', 'stats(', 'findItemFor(', 'visibleWatcherIdsFor(', 'exportCsv(',
        ],
        MyTasksController::class => ['itemsFor(', "['assigned' => 'me']"],
        HandleInertiaRequests::class => ['navigationBadgeFor('],
        EscalateOverdueTasks::class => [
            'itemsFor(', 'candidateWatcherIdsForDelivery(', 'authorizedWatcherItemForDelivery(',
        ],
        TaskAssignmentNotifier::class => [
            'findItemFor(', 'candidateWatcherIdsForDelivery(', 'authorizedWatcherItemForDelivery(',
        ],
    ];

    foreach ($consumers as $class => $requiredFragments) {
        $reflection = new ReflectionClass($class);
        $path = $reflection->getFileName();
        $source = $path === false ? '' : file_get_contents($path);

        foreach ($requiredFragments as $fragment) {
            expect($source)->toContain($fragment);
        }
    }
});

it('keeps atomic overdue delivery on the synchronous database notification channel', function () {
    $notification = new ReflectionClass(AppEventNotification::class);
    $notificationPath = $notification->getFileName();
    $notificationSource = $notificationPath === false ? '' : file_get_contents($notificationPath);

    $routing = new ReflectionClass(NotificationService::class);
    $routingPath = $routing->getFileName();
    $routingSource = $routingPath === false ? '' : file_get_contents($routingPath);

    $command = new ReflectionClass(EscalateOverdueTasks::class);
    $commandPath = $command->getFileName();
    $commandSource = $commandPath === false ? '' : file_get_contents($commandPath);

    expect($notificationSource)
        ->toContain("return ['database'];")
        ->not->toContain('ShouldQueue')
        ->and($routingSource)
        ->toContain('new AppEventNotification($payload)')
        ->toContain('$u->notify($notification)')
        ->and($commandSource)
        ->toContain("DB::transaction(function () use (")
        ->toContain("DB::table('task_escalations')->insertOrIgnore(");
});
