<?php

function employeePeopleMethodSource(string $source, string $method): string
{
    preg_match(
        '/public function '.preg_quote($method, '/').'\b(?<body>.*?)(?=\n    (?:public|private|protected) function|\z)/s',
        $source,
        $matches,
    );

    return $matches['body'] ?? '';
}

/** @return array<string, string> */
function employeePeopleForbiddenSourceFragments(): array
{
    $partition = 'ten'.'ant';
    $partitionClass = 'Ten'.'ant';

    return [
        'team_scope' => 'canonicalTeamFor'.$partitionClass,
        'storage_scope' => 'hrApplicationStorage'.'ContextId',
        'scope_parameter' => $partition.'Id',
        'query_scope' => 'for'.$partitionClass.'(',
        'partition_write' => "'{$partition}_id'",
        'report_scope' => "HrReportExport::query()\n            ->for{$partitionClass}",
        'access_guard' => 'assertHr'.'OrganisationAccess',
    ];
}

test('People mutations lock and re-authorize canonical records inside transactions', function () {
    $root = dirname(__DIR__, 3);
    $controller = file_get_contents($root.'/app/Http/Controllers/Hr/EmployeeProfileController.php');

    foreach (['store', 'setActive', 'rehire', 'bulkAction', 'update'] as $method) {
        $source = employeePeopleMethodSource($controller, $method);
        expect($source)
            ->not->toBe('')
            ->toContain('DB::transaction')
            ->toContain('lockPeopleMutationGraph(');
    }

    $intake = file_get_contents($root.'/app/Domain/Hr/Services/EmployeeIntakeService.php');
    expect(employeePeopleMethodSource($intake, 'intake'))
        ->toContain('DB::transaction')
        ->toContain('mutationLocks->lock(');

    $setActive = employeePeopleMethodSource($controller, 'setActive');
    expect(strpos($setActive, 'assertProfileMutationAccess'))
        ->toBeLessThan(strpos($setActive, '$request->validate'));
});

test('People mutations use one documented lock order and retry deadlocks', function () {
    $root = dirname(__DIR__, 3);
    $controller = file_get_contents($root.'/app/Http/Controllers/Hr/EmployeeProfileController.php');

    expect($controller)->toContain('LOCK ORDER: all affected Users by ID, all affected Profiles by ID, then');
    foreach (['resendInvite', 'store', 'setActive', 'rehire', 'bulkAction', 'update'] as $method) {
        expect(employeePeopleMethodSource($controller, $method))->toContain('attempts: 3');
    }

    $intake = file_get_contents($root.'/app/Domain/Hr/Services/EmployeeIntakeService.php');
    foreach (['intake', 'rehire'] as $method) {
        expect(employeePeopleMethodSource($intake, $method))->toContain('attempts: 3');
    }
});

test('People mutations acquire one globally sorted user then profile lock graph', function () {
    $root = dirname(__DIR__, 3);
    $controller = file_get_contents($root.'/app/Http/Controllers/Hr/EmployeeProfileController.php');
    $lockServicePath = $root.'/app/Domain/Hr/Services/PeopleMutationLockService.php';

    expect(is_file($lockServicePath))->toBeTrue();
    $lockService = file_get_contents($lockServicePath);
    expect($lockService)
        ->toContain('User::query()')
        ->toContain('HrEmployeeProfile::withTrashed()')
        ->toContain("->orderBy('id')")
        ->and(strpos($lockService, 'User::query()'))->toBeLessThan(strpos($lockService, 'HrEmployeeProfile::withTrashed()'));

    foreach (['resendInvite', 'store', 'setActive', 'rehire', 'bulkAction', 'update'] as $method) {
        expect(employeePeopleMethodSource($controller, $method))->toContain('lockPeopleMutationGraph(');
    }

    $intake = file_get_contents($root.'/app/Domain/Hr/Services/EmployeeIntakeService.php');
    foreach (['intake', 'rehire'] as $method) {
        expect(employeePeopleMethodSource($intake, $method))->toContain('mutationLocks->lock(');
    }
});

test('People mutation paths do not query by legacy storage context', function () {
    $controller = file_get_contents(dirname(__DIR__, 3).'/app/Http/Controllers/Hr/EmployeeProfileController.php');
    $forbidden = employeePeopleForbiddenSourceFragments();

    expect($controller)->not->toContain('ResolvesHrOrganisationContext');

    foreach (['store', 'update'] as $method) {
        expect(employeePeopleMethodSource($controller, $method))
            ->not->toContain($forbidden['team_scope'])
            ->not->toContain($forbidden['storage_scope']);
    }

    $intake = file_get_contents(dirname(__DIR__, 3).'/app/Domain/Hr/Services/EmployeeIntakeService.php');
    expect($intake)
        ->not->toContain('LegacyStorageContext')
        ->not->toContain($forbidden['scope_parameter'])
        ->not->toContain('webhooks->publish(')
        ->toContain('publishApplicationEvent');
});

test('People side effects and application events are outermost commit safe and application global', function () {
    $root = dirname(__DIR__, 3);
    $intake = file_get_contents($root.'/app/Domain/Hr/Services/EmployeeIntakeService.php');
    $webhooks = file_get_contents($root.'/app/Domain/Hr/Services/HrWebhookService.php');
    $webhookController = file_get_contents($root.'/app/Http/Controllers/Hr/HrWebhookController.php');
    $automationController = file_get_contents($root.'/app/Http/Controllers/Hr/HrAutomationController.php');
    $forbidden = employeePeopleForbiddenSourceFragments();

    foreach (['intake', 'rehire'] as $method) {
        expect(employeePeopleMethodSource($intake, $method))->toContain('DB::afterCommit(');
    }
    expect($webhooks)
        ->toContain("'employee.rehired'")
        ->toContain('->afterCommit()');

    foreach (['HrWebhookEndpoint.php', 'HrWebhookDelivery.php', 'HrAutomationRule.php', 'HrAutomationRun.php'] as $model) {
        expect(file_get_contents($root.'/app/Domain/Hr/Models/'.$model))->toContain('WritesLegacyStorageContext');
    }

    expect($webhookController)
        ->not->toContain($forbidden['query_scope'])
        ->not->toContain($forbidden['storage_scope'])
        ->and($automationController)
        ->not->toContain($forbidden['query_scope'])
        ->not->toContain($forbidden['storage_scope']);

    $resendInvite = employeePeopleMethodSource(
        file_get_contents($root.'/app/Http/Controllers/Hr/EmployeeProfileController.php'),
        'resendInvite',
    );
    expect($resendInvite)
        ->toContain('DB::afterCommit(')
        ->and(strpos($resendInvite, 'DB::afterCommit('))->toBeLessThan(strpos($resendInvite, 'EmployeeInviteNotification'));
});

test('application automation outputs and report exports carry no partition identity', function () {
    $root = dirname(__DIR__, 3);
    $automation = file_get_contents($root.'/app/Domain/Hr/Services/HrAutomationService.php');
    $reportExport = file_get_contents($root.'/app/Domain/Hr/Models/HrReportExport.php');
    $reportController = file_get_contents($root.'/app/Http/Controllers/Hr/HrReportController.php');
    $forbidden = employeePeopleForbiddenSourceFragments();

    expect(employeePeopleMethodSource($automation, 'actionNotifyUsers'))
        ->not->toContain($forbidden['partition_write'])
        ->and(employeePeopleMethodSource($automation, 'actionNotifyTeamsWebhook'))
        ->not->toContain($forbidden['partition_write'])
        ->and($reportExport)
        ->toContain('WritesLegacyStorageContext')
        ->and(employeePeopleMethodSource($reportController, 'index'))
        ->not->toContain($forbidden['report_scope'])
        ->and(employeePeopleMethodSource($reportController, 'showExport'))
        ->not->toContain($forbidden['access_guard'])
        ->and(employeePeopleMethodSource($reportController, 'downloadExport'))
        ->not->toContain($forbidden['access_guard']);
});

test('People form contracts use canonical department pay and emergency fields', function () {
    $root = dirname(__DIR__, 3);
    $storeRequest = file_get_contents($root.'/app/Http/Requests/Hr/StoreEmployeeRequest.php');
    $updateRequest = file_get_contents($root.'/app/Http/Requests/Hr/UpdateEmployeeProfileRequest.php');
    $addDialog = file_get_contents($root.'/resources/js/components/hr/add-employee-dialog.tsx');
    $editPage = file_get_contents($root.'/resources/js/pages/hr/employees/edit.tsx');

    expect($storeRequest)
        ->toContain("'department_id'")
        ->not->toContain("'department'      =>")
        ->and($updateRequest)
        ->toContain("'hourly_rate'")
        ->toContain("'emergency_contacts.*.name'")
        ->and($addDialog)
        ->toContain('department_id')
        ->not->toContain("department: ''")
        ->and($editPage)
        ->toContain('hourly_rate')
        ->toContain('emergency_contacts')
        ->not->toContain('pay_rate');
});
