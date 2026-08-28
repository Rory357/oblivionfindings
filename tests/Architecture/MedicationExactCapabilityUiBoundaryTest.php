<?php

it('keeps medication UI flags and authoring routes on their exact capabilities', function (): void {
    $root = dirname(__DIR__, 2);
    $emarController = (string) file_get_contents($root.'/app/Http/Controllers/Emar/EmarController.php');
    $settingsController = (string) file_get_contents($root.'/app/Http/Controllers/Emar/MedicationSettingsController.php');
    $errorController = (string) file_get_contents($root.'/app/Http/Controllers/Emar/MedicationErrorController.php');
    $apiController = (string) file_get_contents($root.'/app/Http/Controllers/Api/MedicationsApiController.php');
    $emarRoutes = (string) file_get_contents($root.'/routes/emar.php');
    $apiRoutes = (string) file_get_contents($root.'/routes/api_medications.php');
    $sidebar = (string) file_get_contents($root.'/resources/js/components/app-sidebar.tsx');
    $errorPage = (string) file_get_contents($root.'/resources/js/pages/emar/MedicationErrors.tsx');
    $errorDialogs = (string) file_get_contents($root.'/resources/js/pages/emar/_error-dialogs.tsx');

    expect($emarController)
        ->toContain(
            "'correct' => (bool) \$user && \$user->canDo('medications.administer.correct')",
            "'manage_settings' => (bool) \$user && \$user->canDo('medications.settings.manage')",
            "'manage_inr' => (bool) \$user && \$user->canDo('medications.orders.manage')",
            "\$user->canDo('medications.orders.manage')\n                || \$user->canDo('medications.administer.record')",
            "'manage_allergies' => (bool) \$user && \$user->canDo('clients.update')",
            "'manage_interactions' => (bool) \$user && \$user->canDo('medications.administer.correct')",
            "\$user->canDo('medications.reports.export')\n                || \$user->canDo('reports.viewAny')",
            "'canManageSettings' => \$can['manage_settings']",
        )
        ->not->toContain(
            "'correct' => (bool) \$user && (\$user->canDo('medications.administer.correct') || \$user->canDo('clients.update'))",
            "&& \$user->canDo('medications.view')\n                && \$user->canDo('medications.reports.export')",
        )
        ->and($settingsController)
        ->toContain("return (bool) \$user && \$user->canDo('medications.settings.manage');")
        ->not->toContain("canDo('medications.orders.manage')", "canDo('clients.update')")
        ->and($errorController)
        ->toContain(
            "'record' => \$actor->canDo('medications.administer.record')",
            "'correct' => \$actor->canDo('medications.administer.correct')",
        )
        ->and($apiController)
        ->toContain("abort_unless(\$user?->canDo('medications.administer.correct'), 403);")
        ->and($emarRoutes)
        ->toContain(
            "Route::middleware('permission:medications.settings.manage')->group(function ()",
            "Route::post('/errors/{error}/link-incident', [MedicationErrorController::class, 'linkIncident'])\n        ->middleware('permission:medications.administer.correct')",
        )
        ->not->toContain('permission:medications.settings.manage|medications.orders.manage|clients.update')
        ->and($apiRoutes)
        ->toContain("->middleware('permission:medications.administer.correct')\n        ->name('api.medications.interactions.store')")
        ->not->toContain('permission:medications.administer.correct|clients.update')
        ->and($sidebar)
        ->toContain(
            'if (can?.reports?.viewAny || can?.medications?.reportsExport)',
            "(can?.medications?.view &&\n            can?.medications?.controlledView &&\n            can?.medications?.controlledRecord)",
            'if (can?.medications?.view && can?.medications?.controlledView)',
        )
        ->not->toContain(
            "can?.medications?.view &&\n        (can?.reports?.viewAny || can?.medications?.reportsExport)",
            '(can?.medications?.view && can?.medications?.controlledView) ||',
            '(can?.medications?.view && can?.medications?.controlledRecord) ||',
        )
        ->and($errorPage)
        ->toContain(
            'can.record ? (',
            'can.correct && err.status',
            'canCorrect={can.correct}',
        )
        ->and($errorDialogs)
        ->toContain(
            "canCorrect && error.status === 'reported'",
            "canCorrect && error.status === 'resolved'",
        );
});

it('keeps the worker witness picker exact, current, and bounded to canonical board Sites', function (): void {
    $root = dirname(__DIR__, 2);
    $payload = (string) file_get_contents($root.'/app/Services/Emar/MedsBoardPayloadService.php');
    $worker = (string) file_get_contents($root.'/app/Http/Controllers/Emar/WorkerMedsController.php');
    $governance = (string) file_get_contents($root.'/app/Services/Medication/MedicationGovernanceScopeService.php');
    $controlledWitnesses = (string) file_get_contents($root.'/app/Services/Medication/ControlledMedicationTransportWitnessService.php');

    expect($payload)
        ->toContain(
            "if (! \$user->canDo('medications.administer.record') || empty(\$clientIds))",
            'MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS',
            "->whereIn('id', \$clientIds)",
            "->whereIn('site_id', \$approvedSiteIds)",
            '->controlledWitnessPicker($boardSiteIds, $user->id)',
        )
        ->not->toContain('User::staff()')
        ->and($worker)
        ->toContain("'witnesses' => \$this->boardPayload->witnesses(\$user, \$assignedClientIds)")
        ->and($governance)
        ->toContain(
            'public function controlledWitnessPicker(array $siteIds, ?int $excludedUserId = null): Collection',
            '->eligibleWitnessesForSites($siteIds, now(), $excludedUserId)',
        )
        ->and($controlledWitnesses)
        ->toContain(
            'public function eligibleWitnessesForSites(',
            '$this->scope->medicationWitnessesForSite($siteId, $excludeUserId)',
            "canDo('medications.controlled.witness')",
        );
});
