<?php

test('administration rule readers and writers share a stable rule-set mutex before people and site evidence', function (): void {
    $root = dirname(__DIR__, 2);
    $service = (string) file_get_contents($root.'/app/Services/MedicationRuleService.php');
    $settings = (string) file_get_contents($root.'/app/Http/Controllers/Emar/MedicationSettingsController.php');
    $enhanced = (string) file_get_contents($root.'/app/Services/EnhancedMarService.php');
    $fleet = (string) file_get_contents($root.'/app/Services/Fleet/ResidentTransportJourneyService.php');
    $scope = (string) file_get_contents($root.'/app/Services/Medication/MedicationScopeDecisionService.php');

    expect($service)
        ->toContain(
            '? $this->lockRuleSet()',
            "MedicationAdminRule::query()\n            ->orderBy('id')\n            ->lockForUpdate()",
        )
        ->and($enhanced)->toContain('requirementsFor($medication, true)')
        ->and($fleet)->toContain('requirementsFor($medication, true)');

    $administration = substr(
        $scope,
        (int) strpos($scope, 'public function forAdministration('),
        (int) strpos($scope, 'public function forPrnEffectiveness(')
            - (int) strpos($scope, 'public function forAdministration('),
    );
    $rules = strpos($administration, '$this->medicationRules->requirementsFor($medication, true)');
    $users = strpos($administration, '$this->lockCurrentAdministrationAuthority(');
    expect($rules)->not->toBeFalse()
        ->and($users)->not->toBeFalse()
        ->and($rules)->toBeLessThan($users);

    foreach (['public function store(', 'public function update(', 'public function destroy('] as $method) {
        $start = strpos($settings, $method);
        $end = strpos($settings, "\n    public function ", (int) $start + strlen($method));
        $slice = substr($settings, (int) $start, $end === false ? null : $end - (int) $start);
        $rules = strpos($slice, '$this->ruleService->lockRuleSet()');
        $actor = strpos($slice, '$this->lockCurrentRuleActor(');
        $sites = strpos($slice, '$this->lockCurrentRuleSites(');

        expect($rules)->not->toBeFalse()
            ->and($actor)->not->toBeFalse()
            ->and($sites)->not->toBeFalse()
            ->and($rules)->toBeLessThan($actor)
            ->and($actor)->toBeLessThan($sites);
    }

    expect($settings)->toContain(
        "canDo('medications.settings.manage')",
        '$profile instanceof HrEmployeeProfile',
        "config('app.worker_timezone', 'Pacific/Auckland')",
        "->where('is_active', true)",
        "->where('archived', false)",
    );
});
