<?php

test('sso mapping readers and writers share one mapping set mutex before user and role publication', function (): void {
    $root = dirname(__DIR__, 2);
    $mutex = (string) file_get_contents($root.'/app/Services/SsoGroupMappingLockService.php');
    $azure = (string) file_get_contents($root.'/app/Services/AzureAdGroupService.php');
    $controller = (string) file_get_contents($root.'/app/Http/Controllers/Settings/SsoGroupController.php');

    expect($mutex)->toContain(
        'DB::transactionLevel() < 1',
        "SsoGroupMapping::query()\n            ->orderBy('id')\n            ->lockForUpdate()",
    );

    $mappingSet = strpos($azure, 'app(SsoGroupMappingLockService::class)');
    $roleUnion = strpos($azure, '$roleIds = $lockedMappings->pluck(\'role_id\')', (int) $mappingSet);
    $userGraph = strpos($azure, 'lockForUsers(', (int) $roleUnion);
    $publication = strpos($azure, 'foreach ($lockedMappings as $mapping)', (int) $userGraph);

    expect($mappingSet)->not->toBeFalse()
        ->and($roleUnion)->not->toBeFalse()
        ->and($userGraph)->not->toBeFalse()
        ->and($publication)->not->toBeFalse()
        ->and($mappingSet)->toBeLessThan($roleUnion)
        ->and($roleUnion)->toBeLessThan($userGraph)
        ->and($userGraph)->toBeLessThan($publication)
        ->and($azure)->not->toContain('SsoGroupMapping::where(');

    $methodSlice = static function (string $source, string $method, string $nextMethod): string {
        $start = (int) strpos($source, $method);
        $end = (int) strpos($source, $nextMethod, $start + strlen($method));

        return substr($source, $start, $end - $start);
    };
    $store = $methodSlice($controller, 'public function store(', 'public function update(');
    $update = $methodSlice($controller, 'public function update(', 'public function destroy(');
    $destroy = $methodSlice($controller, 'public function destroy(', 'private function authorizeAccess(');

    foreach ([
        [$store, 'SsoGroupMapping::create($data)'],
        [$update, '$lockedMapping->update($data)'],
        [$destroy, '$lockedMapping->delete()'],
    ] as [$source, $write]) {
        $transaction = strpos($source, 'DB::transaction(');
        $mappingLock = strpos($source, 'lockMappingSet()', (int) $transaction);
        $actorGraph = strpos($source, 'lockMappingMutationActor(', (int) $mappingLock);
        $mutation = strpos($source, $write, (int) $actorGraph);

        expect($transaction)->not->toBeFalse()
            ->and($mappingLock)->not->toBeFalse()
            ->and($actorGraph)->not->toBeFalse()
            ->and($mutation)->not->toBeFalse()
            ->and($transaction)->toBeLessThan($mappingLock)
            ->and($mappingLock)->toBeLessThan($actorGraph)
            ->and($actorGraph)->toBeLessThan($mutation);
    }

    expect($update)->toContain(
        '$lockedMapping = $lockedMappings->get($mappingId)',
        '(int) $lockedMapping->role_id,',
        "(int) \$data['role_id'],",
    )->not->toContain('$mapping->update(');
    expect($destroy)->toContain(
        '$lockedMapping = $lockedMappings->get($mappingId)',
        '[(int) $lockedMapping->role_id]',
    )->not->toContain('$mapping->delete(');
    expect($controller)->toContain(
        "['settings.access.manage']",
        "abort_unless(\$actor?->canDo('settings.access.manage'), 403)",
    );
});
