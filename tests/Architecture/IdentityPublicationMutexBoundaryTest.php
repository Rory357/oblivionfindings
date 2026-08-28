<?php

test('email-first identity publication rechecks the locked current email before binding or login', function (): void {
    $root = dirname(__DIR__, 2);
    $microsoft = (string) file_get_contents($root.'/app/Http/Controllers/Auth/MicrosoftController.php');
    $portalOauth = (string) file_get_contents($root.'/app/Http/Controllers/Auth/PortalOAuthController.php');
    $portalLink = (string) file_get_contents($root.'/app/Http/Controllers/ClientPortalUserController.php');
    $clientCreate = (string) file_get_contents($root.'/app/Http/Controllers/ClientController.php');

    foreach ([$microsoft, $portalOauth, $portalLink, $clientCreate] as $source) {
        $intake = strpos($source, "acquireIntakeLock('email:'.\$email)");
        $lookup = strpos($source, "where('email', \$email)->value('id')", (int) $intake);
        $userLock = strpos($source, 'lockForUsers(', (int) $lookup);
        if ($source === $portalLink) {
            $userLock = strpos($source, 'PeopleMutationLockService::class', (int) $lookup);
        }
        $emailReplay = strpos($source, 'strtolower(trim((string) $user->email)) !== $email', (int) $userLock);
        if ($emailReplay === false) {
            $emailReplay = strpos($source, 'strtolower(trim((string) $user->email)) === $email', (int) $userLock);
        }

        expect($intake)->not->toBeFalse()
            ->and($lookup)->not->toBeFalse()
            ->and($userLock)->not->toBeFalse()
            ->and($emailReplay)->not->toBeFalse()
            ->and($intake)->toBeLessThan($lookup)
            ->and($lookup)->toBeLessThan($userLock)
            ->and($userLock)->toBeLessThan($emailReplay);
    }

    $linkBranch = substr(
        $microsoft,
        (int) strpos($microsoft, 'if ($linkUserId)'),
        (int) strpos($microsoft, '// Org-only rule') - (int) strpos($microsoft, 'if ($linkUserId)'),
    );
    expect($linkBranch)->toContain('DB::transaction(', 'lockForUsers(', 'approved_at !== null')
        ->and(strrpos($microsoft, 'Auth::login('))->toBeGreaterThan(strrpos($microsoft, '}, 3);'))
        ->and(strrpos($portalOauth, 'Auth::login('))->toBeGreaterThan(strrpos($portalOauth, '}, 3);'));
});

test('identity publication rechecks the requested role name after the shared role lock', function (): void {
    $root = dirname(__DIR__, 2);
    $cases = [
        [
            (string) file_get_contents($root.'/app/Http/Controllers/Auth/MicrosoftController.php'),
            '$lockedDefaultRole = Role::query()->whereKey($defaultRoleId)->lockForUpdate()->first()',
            "(string) \$lockedDefaultRole->name === 'support_worker'",
            'syncWithoutDetaching([$defaultRoleId])',
        ],
        [
            (string) file_get_contents($root.'/app/Http/Controllers/Auth/PortalOAuthController.php'),
            '$lockedPortalRole = Role::query()->whereKey($portalRoleId)->lockForUpdate()->first()',
            "(string) \$lockedPortalRole->name === 'next_of_kin'",
            'syncWithoutDetaching([$portalRoleId])',
        ],
        [
            (string) file_get_contents($root.'/app/Http/Controllers/ClientPortalUserController.php'),
            '$lockedPortalRole = Role::query()->whereKey($roleId)->lockForUpdate()->first()',
            "(string) \$lockedPortalRole->name === (string) \$data['portal_role']",
            'syncWithoutDetaching([$roleId])',
        ],
        [
            (string) file_get_contents($root.'/app/Http/Controllers/ClientController.php'),
            '$lockedRole = Role::query()->whereKey($roleId)->lockForUpdate()->first()',
            '(string) $lockedRole->name === $roleName',
            'syncWithoutDetaching([$roleId])',
        ],
    ];

    foreach ($cases as [$source, $roleLock, $nameReplay, $attach]) {
        $userGraph = strpos($source, 'lockForUsers(');
        if (str_contains($source, 'ClientPortalUserController')) {
            $userGraph = strpos($source, 'PeopleMutationLockService::class');
        }
        $roleReplay = strpos($source, $roleLock, (int) $userGraph);
        $nameCheck = strpos($source, $nameReplay, (int) $roleReplay);
        $assignment = strpos($source, $attach, (int) $nameCheck);

        expect($userGraph)->not->toBeFalse()
            ->and($roleReplay)->not->toBeFalse()
            ->and($nameCheck)->not->toBeFalse()
            ->and($assignment)->not->toBeFalse()
            ->and($userGraph)->toBeLessThan($roleReplay)
            ->and($roleReplay)->toBeLessThan($nameCheck)
            ->and($nameCheck)->toBeLessThan($assignment);
    }
});

test('board membership mutates only the semantic roles in the complete locked role graph', function (): void {
    $source = (string) file_get_contents(
        dirname(__DIR__, 2).'/app/Http/Controllers/Settings/AccessController.php',
    );
    $store = substr(
        $source,
        (int) strpos($source, 'public function storeBoardMember('),
        (int) strpos($source, 'public function destroyBoardMember(')
            - (int) strpos($source, 'public function storeBoardMember('),
    );
    $destroy = substr(
        $source,
        (int) strpos($source, 'public function destroyBoardMember('),
        (int) strpos($source, 'private function boardRoleMap(')
            - (int) strpos($source, 'public function destroyBoardMember('),
    );

    $selectedSnapshot = strpos($store, "Role::query()->where('name', \$selectedRoleName)->value('id')");
    $completeGraph = strpos($store, 'lockAccessMutationUsers($actorId, $targetId, [$selectedRoleId])');
    $roleReplay = strpos($store, '->whereKey($selectedRoleId)', (int) $completeGraph);
    $nameReplay = strpos($store, '(string) $lockedSelectedRole->name === $selectedRoleName', (int) $roleReplay);
    $lockedDetach = strpos($store, '$targetUser->roles', (int) $nameReplay);
    $attach = strpos($store, 'syncWithoutDetaching([$selectedRoleId])', (int) $lockedDetach);

    expect($selectedSnapshot)->not->toBeFalse()
        ->and($completeGraph)->not->toBeFalse()
        ->and($roleReplay)->not->toBeFalse()
        ->and($nameReplay)->not->toBeFalse()
        ->and($lockedDetach)->not->toBeFalse()
        ->and($attach)->not->toBeFalse()
        ->and($selectedSnapshot)->toBeLessThan($completeGraph)
        ->and($completeGraph)->toBeLessThan($roleReplay)
        ->and($roleReplay)->toBeLessThan($nameReplay)
        ->and($nameReplay)->toBeLessThan($lockedDetach)
        ->and($lockedDetach)->toBeLessThan($attach)
        ->and($store)->not->toContain("->whereIn('name'");

    expect($destroy)->toContain(
        'lockAccessMutationUsers($actorId, $targetId, [])',
        '$targetUser->roles',
        'in_array((string) $role->name, $boardRoleNames, true)',
        'detach($currentBoardRoleIds)',
    )->not->toContain('Role::query()');
});

test('system portal and employee intake replay semantic role names on exact prelocked ids', function (): void {
    $root = dirname(__DIR__, 2);
    $systemUsers = (string) file_get_contents($root.'/app/Http/Controllers/System/UsersController.php');
    $intake = (string) file_get_contents($root.'/app/Domain/Hr/Services/EmployeeIntakeService.php');
    $assignments = (string) file_get_contents($root.'/app/Domain/Hr/Services/EmployeeRoleAssignmentService.php');

    $portalStore = substr(
        $systemUsers,
        (int) strpos($systemUsers, 'public function store('),
        (int) strpos($systemUsers, 'public function show(')
            - (int) strpos($systemUsers, 'public function store('),
    );
    $portalGraph = strpos($portalStore, 'lockForUsers(');
    $portalRole = strpos($portalStore, 'lockRoleMutex($portalRoleId)', (int) $portalGraph);
    $portalName = strpos($portalStore, '(string) $portalRole->name === $portalRoleName', (int) $portalRole);
    $portalCreate = strpos($portalStore, '$newUser = User::create(', (int) $portalName);
    expect($portalGraph)->not->toBeFalse()
        ->and($portalRole)->not->toBeFalse()
        ->and($portalName)->not->toBeFalse()
        ->and($portalCreate)->not->toBeFalse()
        ->and($portalGraph)->toBeLessThan($portalRole)
        ->and($portalRole)->toBeLessThan($portalName)
        ->and($portalName)->toBeLessThan($portalCreate);

    expect($intake)->toContain(
        'assertAssignable($requestedRoleId, $roleName, $actor)',
        '$requestedRoleId,',
        '(string) $roleName,',
    )->not->toContain("Role::query()->where('name', \$roleName)->lockForUpdate()");
    expect($assignments)->toContain(
        'assertAssignable(int $roleId, string $roleName, User $actor)',
        'Role::query()->whereKey($roleId)->lockForUpdate()->first()',
        '(string) $role->name !== $roleName',
    )->not->toContain("->where('name', \$roleName)");
});
