<?php

it('keeps Device Groups and policy decisions behind the canonical visibility boundary', function () {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $controller = file_get_contents(
        $root.'/app/Domain/SecurityDevices/Http/Controllers/DeviceGroupController.php',
    );
    $autoRules = file_get_contents(
        $root.'/app/Domain/SecurityDevices/Services/DeviceGroupAutoRuleService.php',
    );
    $policy = file_get_contents(
        $root.'/app/Domain/SecurityDevices/Policies/DevicePolicy.php',
    );
    // S&D rebuild C6 (D8): the group pages are gone; a group is a Devices filter built only from the
    // devices this person can already see.
    $devicesFilter = file_get_contents(
        $root.'/app/Domain/SecurityDevices/Screens/Devices/DevicesIndexPresenter.php',
    );

    expect($controller)
        ->toContain(
            'deviceScope: $this->access->visibleDevices($user)',
            '$this->access->assertCanViewDevice($user, $device)',
        )
        ->not->toContain('$availableDevices = Device::query()')
        ->not->toContain('Inertia::render(');

    expect($devicesFilter)
        ->toContain(
            '$groups = $this->groupOptions($scoped);',
            "->whereIn('device_group_members.device_id', \$ids)",
        );

    expect($autoRules)
        ->toContain(
            'queryFromRules(array $rules, ?Builder $deviceScope = null)',
            'applyToGroup(DeviceGroup $group, ?Builder $deviceScope = null)',
            "\$existing->whereIn('devices.id', (clone \$deviceScope)->select('devices.id'))",
        );

    expect($policy)
        ->toContain('private readonly SecurityDevicesAccessService $access')
        ->and(substr_count($policy, '$this->access->visibleDevices($user)'))->toBe(4);
});
