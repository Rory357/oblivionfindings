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
    $navigation = (string) file_get_contents($root.'/resources/js/lib/emar-navigation.ts');
    $errorPage = (string) file_get_contents($root.'/resources/js/pages/emar/MedicationErrors.tsx');
    $errorDetail = (string) file_get_contents($root.'/resources/js/pages/emar/errors/_detail.tsx');

    expect($emarController)
        ->toContain(
            "'correct' => (bool) \$user && \$user->canDo('medications.administer.correct')",
            "'manage_settings' => (bool) \$user && \$user->canDo('medications.settings.manage')",
            "'manage_inr' => (bool) \$user && \$user->canDo('medications.orders.manage')",
            "\$user->canDo('medications.orders.manage')\n                || \$user->canDo('medications.administer.record')",
            "'manage_allergies' => (bool) \$user && \$user->canDo('clients.update')",
            "'manage_interactions' => (bool) \$user && \$user->canDo('medications.administer.correct')",
            "'view_reports' => (bool) \$user && \$user->canDo('medications.reports.view')",
            "'view_audit' => (bool) \$user && \$user->canDo('medications.reports.view') && \$user->canDo('medications.audit.view')",
            "'export_reports' => (bool) \$user && (\n                \$user->canDo('medications.reports.view')\n                && \$user->canDo('medications.reports.export')",
            "'canManageSettings' => \$can['manage_settings']",
        )
        ->not->toContain(
            "canDo('reports.viewAny')",
            "'correct' => (bool) \$user && (\$user->canDo('medications.administer.correct') || \$user->canDo('clients.update'))",
            "&& \$user->canDo('medications.view')\n                && \$user->canDo('medications.reports.export')",
        )
        ->and($settingsController)
        ->toContain("return (bool) \$user && \$user->canDo('medications.settings.manage');")
        ->not->toContain("canDo('medications.orders.manage')", "canDo('clients.update')")
        ->and($errorController)
        ->toContain(
            "'record' => \$actor->canDo('medications.administer.record')",
            "'manage' => \$actor->canDo('medications.errors.manage')",
            "abort_unless(\$user?->canDo('medications.errors.manage'), 403);",
        )
        ->and($apiController)
        // NF-09: interaction rules are governance, not a dose-correction right.
        ->toContain("abort_unless(\$user?->canDo('medications.settings.manage'), 403);")
        ->not->toContain("abort_unless(\$user?->canDo('medications.administer.correct'), 403);")
        ->and($emarRoutes)
        ->toContain(
            "Route::middleware('permission:medications.settings.manage')->group(function ()",
            "Route::middleware(['permission:medications.reports.view', 'permission:medications.reports.export'])->group(function ()",
            "Route::post('/errors/{error}/link-incident', [MedicationErrorController::class, 'linkIncident'])\n        ->middleware('permission:medications.errors.manage')",
        )
        ->not->toContain('permission:medications.settings.manage|medications.orders.manage|clients.update')
        ->and($apiRoutes)
        ->toContain("->middleware('permission:medications.settings.manage')\n        ->name('api.medications.interactions.store')")
        ->not->toContain("->middleware('permission:medications.administer.correct')\n        ->name('api.medications.interactions.store')")
        ->not->toContain('permission:medications.administer.correct|clients.update')
        // NAV: the sidebar delegates to lib/emar-navigation.ts, whose view
        // gates mirror the server's exact reader capabilities.
        ->and($sidebar)
        ->toContain('const medication = emarSidebar(can);')
        ->not->toContain('const canAdminEmar')
        ->and($navigation)
        ->toContain(
            "label: 'Standard reports',\n                href: '/emar/reports',\n                icon: BarChart3,\n                visible: reportsView,",
            'export const canOpenEmarReports = reportsView;',
            'export const canOpenEmarAudit = all(reportsView, auditView);',
            "label: 'Controlled register',\n                href: '/emar/controlled',\n                icon: Shield,\n                visible: all(view, controlledView),",
            "label: 'Destructions & returns',\n                href: '/emar/destructions',\n                icon: Ban,\n                visible: all(view, any(controlledView, stockUpdate)),",
            '(controlledView(can) && hasManagerCapability(can))',
        )
        ->not->toContain('controlledRecord(can)', 'controlledWitness(can)', 'reportsViewAny')
        ->and($errorPage)
        ->toContain(
            'can.record && (',
            "import { ErrorDetail } from './errors/_detail';",
            'canManage={can.manage}',
            'canRecord={can.record}',
        )
        ->not->toContain('canCorrect={can.correct}', "from './_error-dialogs'")
        ->and($errorDetail)
        ->toContain(
            'canManage && opened',
            'opened && canRecord',
            "start('link-incident')",
        )
        ->not->toContain('canCorrect');
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
