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

    expect($controller)
        ->toContain(
            "withCount([\n            'devices' => fn (\$devices) => \$devices->whereIn('devices.id', clone \$visibleDeviceIds)",
            'deviceScope: $this->access->visibleDevices($user)',
            '$this->access->assertCanViewDevice($user, $device)',
        )
        ->not->toContain('$availableDevices = Device::query()');

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
