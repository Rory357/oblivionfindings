<?php

it('keeps every medication administration controller behind the authoritative scope decision', function () {
    $controllers = [
        'app/Http/Controllers/Api/MedicationsApiController.php',
        'app/Http/Controllers/ClientMedicalController.php',
        'app/Http/Controllers/Emar/GuidedRoundController.php',
        'app/Http/Controllers/Emar/WorkerMedsController.php',
        'app/Http/Controllers/MyDayMedicationsController.php',
    ];

    foreach ($controllers as $controller) {
        $source = (string) file_get_contents(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.$controller);

        expect($source)
            ->toContain('MedicationScopeDecisionService')
            ->toContain('->forAdministration(')
            ->toContain('->recordAdministration(');
    }
});

it('keeps crossed My Day actor witness pairs on one canonical administration lock path', function () {
    $source = (string) file_get_contents(
        dirname(__DIR__, 2).'/app/Http/Controllers/MyDayMedicationsController.php',
    );
    $administer = substr(
        $source,
        (int) strpos($source, 'public function administer('),
        (int) strpos($source, 'public function refuse(')
            - (int) strpos($source, 'public function administer('),
    );
    $refuse = substr(
        $source,
        (int) strpos($source, 'public function refuse('),
        (int) strpos($source, 'public function snooze(')
            - (int) strpos($source, 'public function refuse('),
    );

    expect(substr_count($administer, '->forAdministration('))->toBe(1)
        ->and(substr_count($refuse, '->forAdministration('))->toBe(1)
        ->and($administer)->not->toContain('->forMedication(')
        ->and($refuse)->not->toContain('->forMedication(')
        ->and($administer)->toContain(
            'scopedInputResolver:',
            "'payload' => \$data",
            "'witnessed_by'",
            'prelockedPresenceShifts:',
            'prelockedPresenceEffectiveAt:',
        )
        ->and($refuse)->toContain(
            'scopedInputResolver:',
            "'payload' => \$data",
            'prelockedPresenceShifts:',
            'prelockedPresenceEffectiveAt:',
        );
});

it('keeps prescription medication and prn effectiveness mutations on the same scope boundary', function () {
    $source = (string) file_get_contents(
        dirname(__DIR__, 2).'/app/Http/Controllers/Emar/EmarController.php',
    );

    expect($source)
        ->toContain('MedicationScopeDecisionService')
        ->toContain('->forClient(')
        ->toContain('->forPrescription(')
        ->toContain('->forMedication(')
        ->toContain('->forPrnEffectiveness(');
});

it('does not reintroduce nullable best effort shift lookup in medication write controllers', function () {
    $sources = collect([
        'app/Http/Controllers/Api/MedicationsApiController.php',
        'app/Http/Controllers/ClientMedicalController.php',
        'app/Http/Controllers/Emar/GuidedRoundController.php',
        'app/Http/Controllers/Emar/WorkerMedsController.php',
        'app/Http/Controllers/MyDayMedicationsController.php',
    ])->map(
        fn (string $path): string => (string) file_get_contents(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.$path),
    )->implode("\n");

    expect($sources)->not->toContain('activeShiftIdFor(');

    $worker = (string) file_get_contents(
        dirname(__DIR__, 2).'/app/Http/Controllers/Emar/WorkerMedsController.php',
    );
    expect($worker)
        ->not->toContain('exists:client_medication_administrations,id')
        ->not->toContain('exists:client_medications,id');
});

it('locks round context current participants and site before the constrained round mutation', function () {
    $root = dirname(__DIR__, 2);
    $scopeSource = (string) file_get_contents($root.'/app/Services/Medication/MedicationScopeDecisionService.php');
    $controller = (string) file_get_contents($root.'/app/Http/Controllers/Emar/EmarController.php');
    $roundScope = substr(
        $scopeSource,
        (int) strpos($scopeSource, 'public function forRound('),
        (int) strpos($scopeSource, 'public function recordBreakGlassUse(')
            - (int) strpos($scopeSource, 'public function forRound('),
    );

    $context = strpos($roundScope, 'ServiceContext::query()');
    $users = strpos($roundScope, '->lockControlledWitnessUsers($userIds)');
    $profiles = strpos($roundScope, '->lockCurrentStaffProfiles($lockedUsers, $userIds)');
    $site = strpos($roundScope, '->lockCurrentMedicationSite($siteId)');
    $round = strrpos($roundScope, '$roundQuery->lockForUpdate()');
    expect($context)->not->toBeFalse()
        ->and($users)->not->toBeFalse()
        ->and($profiles)->not->toBeFalse()
        ->and($site)->not->toBeFalse()
        ->and($round)->not->toBeFalse()
        ->and($context)->toBeLessThan($users)
        ->and($users)->toBeLessThan($profiles)
        ->and($profiles)->toBeLessThan($site)
        ->and($site)->toBeLessThan($round);

    $assign = substr(
        $controller,
        (int) strpos($controller, 'public function assignRound('),
        (int) strpos($controller, 'public function storeSelfAdmin(')
            - (int) strpos($controller, 'public function assignRound('),
    );
    expect($assign)
        ->toContain(
            '$assigneeId = (int) $validated[\'assigned_to\'];',
            'Collection $lockedUsers',
            'authorizationUserIds: [$assigneeId]',
            '$assignee?->hrEmployeeProfile',
        )
        ->not->toContain('->staffPicker(');
});

it('keeps shift creation on the same service-context before client prefix as round work', function () {
    $source = (string) file_get_contents(
        dirname(__DIR__, 2).'/app/Domain/Shifts/Lifecycle/ShiftLifecycleService.php',
    );
    $helper = substr(
        $source,
        (int) strpos($source, 'private function lockCanonicalCreateContext('),
        (int) strpos($source, 'private function lockCurrentCreateAuthority(')
            - (int) strpos($source, 'private function lockCanonicalCreateContext('),
    );
    $context = strpos($helper, '$serviceContext = ServiceContext::query()');
    $client = strpos($helper, '$client = Client::query()');

    expect($context)->not->toBeFalse()
        ->and($client)->not->toBeFalse()
        ->and($context)->toBeLessThan($client)
        ->and($helper)->toContain(
            "->where('is_active', true)",
            "->where('site_id', \$siteId)",
            'abort_unless($client instanceof Client, 404)',
            'abort_unless($serviceContext instanceof ServiceContext, 404)',
        );
});

it('locks medication alert transitions client medication current actor Site and constrained alert', function () {
    $source = (string) file_get_contents(
        dirname(__DIR__, 2).'/app/Services/MedicationAlertService.php',
    );
    $method = substr(
        $source,
        (int) strpos($source, 'private function lockCanonicalAlert('),
        (int) strpos($source, 'public function clearStaleAlerts(')
            - (int) strpos($source, 'private function lockCanonicalAlert('),
    );

    $identity = strpos($method, '$identity = MedicationDashboardAlert::query()');
    $client = strpos($method, '$client = Client::query()', (int) $identity);
    $medication = strpos($method, 'ClientMedication::query()', (int) $client);
    $actor = strpos($method, '->lockCurrentAlertActor(', (int) $medication);
    $site = strpos($method, 'Site::query()', (int) $actor);
    $alert = strpos($method, '$lockedAlertQuery = MedicationDashboardAlert::query()', (int) $site);

    expect($identity)->not->toBeFalse()
        ->and($client)->not->toBeFalse()
        ->and($medication)->not->toBeFalse()
        ->and($actor)->not->toBeFalse()
        ->and($site)->not->toBeFalse()
        ->and($alert)->not->toBeFalse()
        ->and($identity)->toBeLessThan($client)
        ->and($client)->toBeLessThan($medication)
        ->and($medication)->toBeLessThan($actor)
        ->and($actor)->toBeLessThan($site)
        ->and($site)->toBeLessThan($alert)
        ->and($method)->toContain(
            "->where('is_active', true)",
            "->where('archived', false)",
            "->where('client_id', \$client->id)",
            "->where('alert_type', \$identity->alert_type)",
            "->whereNull('client_medication_id')",
        );
});

it('locks administration rounds only after current rule User Profile and Site evidence', function () {
    $root = dirname(__DIR__, 2);
    $scopeSource = (string) file_get_contents($root.'/app/Services/Medication/MedicationScopeDecisionService.php');
    $enhancedSource = (string) file_get_contents($root.'/app/Services/EnhancedMarService.php');
    $scope = substr(
        $scopeSource,
        (int) strpos($scopeSource, 'public function forAdministration('),
        (int) strpos($scopeSource, 'public function forPrnEffectiveness(')
            - (int) strpos($scopeSource, 'public function forAdministration('),
    );
    $authority = substr(
        $scopeSource,
        (int) strpos($scopeSource, 'private function lockCurrentAdministrationAuthority('),
        (int) strpos($scopeSource, 'private function isCanonicalBreakGlass(')
            - (int) strpos($scopeSource, 'private function lockCurrentAdministrationAuthority('),
    );
    $enhanced = substr(
        $enhancedSource,
        (int) strpos($enhancedSource, 'public function recordAdministration('),
        (int) strpos($enhancedSource, 'private function validateWitness(')
            - (int) strpos($enhancedSource, 'public function recordAdministration('),
    );

    $scopeProjection = strpos($scope, '$this->clientIdsWithCurrentAuthority(');
    $scopeSnapshot = strpos($scope, '$roundSnapshot = MedicationRound::query()', (int) $scopeProjection);
    $scopeResolver = strpos($scope, '$scopedInputResolver($client, $medication)', (int) $scopeSnapshot);
    $scopePresence = strpos($scope, '->lockControlledWitnessPresenceShifts(', (int) $scopeResolver);
    $scopeRule = strpos($scope, '->requirementsFor($medication, true)', (int) $scopePresence);
    $scopeAuthority = strpos($scope, '->lockCurrentAdministrationAuthority(', (int) $scopeRule);
    $scopeRound = strpos($scope, '$roundQuery->lockForUpdate()->first()', (int) $scopeAuthority);
    expect($scopeProjection)->not->toBeFalse()
        ->and($scopeSnapshot)->not->toBeFalse()
        ->and($scopeResolver)->not->toBeFalse()
        ->and($scopePresence)->not->toBeFalse()
        ->and($scopeRule)->not->toBeFalse()
        ->and($scopeAuthority)->not->toBeFalse()
        ->and($scopeRound)->not->toBeFalse()
        ->and($scopeProjection)->toBeLessThan($scopeSnapshot)
        ->and($scopeSnapshot)->toBeLessThan($scopeResolver)
        ->and($scopeResolver)->toBeLessThan($scopePresence)
        ->and($scopePresence)->toBeLessThan($scopeRule)
        ->and($scopeRule)->toBeLessThan($scopeAuthority)
        ->and($scopeAuthority)->toBeLessThan($scopeRound)
        ->and($scope)->toContain('service_context_id', 'assigned_to', 'started_by', 'status')
        ->and($authority)->toContain('->lockCurrentMedicationSite($siteId)');

    $projection = substr(
        $scopeSource,
        (int) strpos($scopeSource, 'public function clientIdsWithCurrentAuthority('),
        (int) strpos($scopeSource, 'public function forRound(')
            - (int) strpos($scopeSource, 'public function clientIdsWithCurrentAuthority('),
    );
    expect($projection)
        ->toContain('whereExists(function ($shift)')
        ->not->toContain('lockForUpdate');

    $enhancedSnapshot = strpos($enhanced, '$roundSnapshot = MedicationRound::query()');
    $enhancedRule = strpos($enhanced, '->requirementsFor($medication, true)', (int) $enhancedSnapshot);
    $enhancedUsers = strpos($enhanced, '->lockControlledWitnessUsers($authorizationUserIds)', (int) $enhancedRule);
    $enhancedProfiles = strpos($enhanced, '->lockCurrentStaffProfilesAtSite(', (int) $enhancedUsers);
    $enhancedSite = strpos($enhanced, '->lockCurrentMedicationSite(', (int) $enhancedProfiles);
    $enhancedRound = strpos($enhanced, '$roundQuery', (int) $enhancedSite);
    $enhancedRoundLock = strpos($enhanced, '->lockForUpdate()', (int) $enhancedRound);
    expect($enhancedSnapshot)->not->toBeFalse()
        ->and($enhancedRule)->not->toBeFalse()
        ->and($enhancedUsers)->not->toBeFalse()
        ->and($enhancedProfiles)->not->toBeFalse()
        ->and($enhancedSite)->not->toBeFalse()
        ->and($enhancedRound)->not->toBeFalse()
        ->and($enhancedRoundLock)->not->toBeFalse()
        ->and($enhancedSnapshot)->toBeLessThan($enhancedRule)
        ->and($enhancedRule)->toBeLessThan($enhancedUsers)
        ->and($enhancedUsers)->toBeLessThan($enhancedProfiles)
        ->and($enhancedProfiles)->toBeLessThan($enhancedSite)
        ->and($enhancedSite)->toBeLessThan($enhancedRound)
        ->and($enhancedRound)->toBeLessThan($enhancedRoundLock);
});
