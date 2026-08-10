<?php

use App\Domain\SecurityDevices\Management\Enums\CommandConfirmationMode;
use App\Domain\SecurityDevices\Management\Enums\CommandRisk;
use App\Domain\SecurityDevices\Management\Enums\ManagementLevel;
use App\Domain\SecurityDevices\Management\Services\CommandCapabilityRegistry;
use App\Domain\SecurityDevices\Management\Services\CommandParameterValidator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class);

it('defines the complete provider-neutral management catalogue with safe policy defaults', function () {
    $capabilities = app(CommandCapabilityRegistry::class)->all();

    expect($capabilities)->toHaveCount(35)
        ->and($capabilities->keys()->all())->toContain(
            'diagnostics.ping',
            'tracking.location_refresh',
            'configuration.refresh',
            'device.reboot',
            'device.remote_session',
            'device.remote_shell',
            'device.wipe',
            'configuration.apply',
            'firmware.update',
            'network.firewall_policy.apply',
            'access.door.unlock_timed',
            'access.door.lockdown',
            'access.credential.grant',
            'alarm.disarm',
            'alarm.emergency_mode.set',
            'camera.privacy.disable',
            'camera.recording.stop',
            'camera.stream.share',
            'camera.evidence.export',
            'monitoring.suppression.create',
            'monitoring.suppression.site.create',
            'healthcare.calibration_override',
            'facilities.actuator.set_state',
        );

    foreach ($capabilities as $key => $capability) {
        expect($capability->key)->toBe($key)
            ->and($capability->expiresAfterSeconds)->toBeGreaterThanOrEqual(30)
            ->and($capability->expiresAfterSeconds)->toBeLessThanOrEqual(3600)
            ->and($capability->deviceDomains)->not->toBeEmpty()
            ->and($capability->sensitivity)->not->toBeEmpty()
            ->and($capability->impact)->not->toBeEmpty()
            ->and($capability->expectedResult)->not->toBeEmpty()
            ->and(array_diff($capability->safeSummaryFields, array_keys($capability->parameters)))->toBe([]);

        if ($capability->isHighRisk()) {
            expect($capability->requiresStepUp)->toBeTrue()
                ->and($capability->requiresFreshObservation)->toBeTrue()
                ->and($capability->requiresApproval || $capability->requiresChange)->toBeTrue()
                ->and($capability->retryPolicy)->toBe('reconcile_before_retry')
                ->and($capability->confirmationMode)->not->toBe(CommandConfirmationMode::None)
                ->and($capability->level)->toBe(ManagementLevel::Control);
        }
        if ($capability->risk === CommandRisk::Critical) {
            expect($capability->requiresMfa)->toBeTrue()
                ->and($capability->expiresAfterSeconds)->toBeLessThanOrEqual(120)
                ->and($capability->confirmationMode)->toBe(CommandConfirmationMode::TypeDeviceName);
        }
    }

    expect($capabilities->get('diagnostics.ping')->requiresFreshObservation)->toBeFalse()
        ->and($capabilities->get('diagnostics.trace_route')->requiresFreshObservation)->toBeFalse()
        ->and($capabilities->get('tracking.location_refresh')->requiresFreshObservation)->toBeFalse();
});

it('declares workspace Device-class source-permission and sensitivity boundaries', function () {
    $capabilities = app(CommandCapabilityRegistry::class);

    $door = $capabilities->definition('access.door.unlock_timed');
    expect($door->deviceDomains)->toBe(['security'])
        ->and($door->deviceCategories)->toBe(['access_control'])
        ->and($door->requiredPermissions)->toBe([])
        ->and($door->sensitivity)->toBe('security_control');

    $camera = $capabilities->definition('camera.evidence.export');
    expect($camera->deviceDomains)->toBe(['security'])
        ->and($camera->deviceCategories)->toBe(['cctv'])
        ->and($camera->requiredPermissions)->toBe(['securityDevices.cctv.media.view'])
        ->and($camera->sensitivity)->toBe('cctv_media');

    $tracking = $capabilities->definition('tracking.location_refresh');
    expect($tracking->deviceDomains)->toBe(['tracking'])
        ->and($tracking->requiredPermissions)->toBe(['assets.telemetry.view'])
        ->and($tracking->sensitivity)->toBe('personal_location');

    $configuration = $capabilities->definition('configuration.refresh');
    expect($configuration->deviceDomains)->toBe(['tracking'])
        ->and($configuration->requiredPermissions)->toBe(['securityDevices.integrations.view'])
        ->and($configuration->sensitivity)->toBe('standard');

    $healthcare = $capabilities->definition('healthcare.calibration_override');
    expect($healthcare->deviceDomains)->toBe(['iot_healthcare'])
        ->and($healthcare->requiredPermissions)->toBe(['securityDevices.maintenance.view'])
        ->and($healthcare->sensitivity)->toBe('healthcare_technical');
});

it('locks the timed door unlock to control-level high-risk safeguards', function () {
    $capability = app(CommandCapabilityRegistry::class)->definition('access.door.unlock_timed');

    expect($capability->label)->toBe('Unlock door temporarily')
        ->and($capability->domain)->toBe('security')
        ->and($capability->level)->toBe(ManagementLevel::Control)
        ->and($capability->risk)->toBe(CommandRisk::High)
        ->and($capability->requiresStepUp)->toBeTrue()
        ->and($capability->requiresFreshObservation)->toBeTrue()
        ->and($capability->requiresMfa)->toBeFalse()
        ->and($capability->requiresApproval)->toBeTrue()
        ->and($capability->confirmationMode)->toBe(CommandConfirmationMode::AcknowledgeImpact)
        ->and($capability->requiresChange)->toBeFalse()
        ->and($capability->allowsBreakGlass)->toBeTrue()
        ->and($capability->retryPolicy)->toBe('reconcile_before_retry')
        ->and($capability->safeSummaryFields)->toBe(['duration_seconds'])
        ->and($capability->parameters['duration_seconds'])->toBe([
            'type' => 'integer',
            'min' => 5,
            'max' => 60,
        ]);
});

it('applies the required risk controls to every sensitive command family', function () {
    $capabilities = app(CommandCapabilityRegistry::class);
    $critical = [
        'device.remote_shell',
        'device.wipe',
        'network.firewall_policy.apply',
        'access.door.lockdown',
        'alarm.disarm',
        'alarm.bypass',
        'alarm.emergency_mode.set',
        'camera.privacy.disable',
        'camera.recording.stop',
        'camera.stream.share',
        'monitoring.suppression.site.create',
        'healthcare.calibration_override',
        'healthcare.data_flow_reroute',
    ];
    $high = [
        'device.reboot',
        'device.remote_session',
        'configuration.apply',
        'firmware.update',
        'access.door.unlock_timed',
        'access.door.lock',
        'access.schedule.update',
        'access.credential.grant',
        'access.credential.revoke',
        'alarm.arm',
        'alarm.reset',
        'camera.privacy.enable',
        'camera.recording.update',
        'camera.evidence.export',
        'monitoring.suppression.create',
    ];

    foreach ($critical as $key) {
        $definition = $capabilities->definition($key);
        expect($definition->risk)->toBe(CommandRisk::Critical)
            ->and($definition->requiresMfa)->toBeTrue()
            ->and($definition->requiresStepUp)->toBeTrue()
            ->and($definition->requiresFreshObservation)->toBeTrue()
            ->and($definition->requiresApproval)->toBeTrue()
            ->and($definition->confirmationMode)->toBe(CommandConfirmationMode::TypeDeviceName)
            ->and($definition->retryPolicy)->toBe('reconcile_before_retry');
    }
    foreach ($high as $key) {
        $definition = $capabilities->definition($key);
        expect($definition->risk)->toBe(CommandRisk::High)
            ->and($definition->requiresStepUp)->toBeTrue()
            ->and($definition->requiresFreshObservation)->toBeTrue()
            ->and($definition->requiresApproval)->toBeTrue()
            ->and($definition->confirmationMode)->toBe(CommandConfirmationMode::AcknowledgeImpact)
            ->and($definition->retryPolicy)->toBe('reconcile_before_retry');
    }

    expect($capabilities->definition('device.reboot')->requiresChange)->toBeTrue()
        ->and($capabilities->definition('device.remote_session')->requiresMfa)->toBeTrue()
        ->and($capabilities->definition('camera.evidence.export')->requiresMfa)->toBeTrue()
        ->and($capabilities->definition('device.wipe')->allowsBreakGlass)->toBeFalse()
        ->and($capabilities->definition('camera.recording.stop')->requiresChange)->toBeTrue()
        ->and($capabilities->definition('monitoring.suppression.site.create')->requiresChange)->toBeTrue()
        ->and($capabilities->definition('monitoring.suppression.site.create')->allowsBreakGlass)->toBeFalse()
        ->and($capabilities->definition('healthcare.calibration_override')->allowsBreakGlass)->toBeFalse()
        ->and($capabilities->definition('healthcare.data_flow_reroute')->allowsBreakGlass)->toBeFalse()
        ->and($capabilities->definition('monitoring.suppression.create')->parameters['scope']['enum'])
        ->toBe(['device', 'service']);
});

it('fails closed for an unknown capability', function () {
    expect(fn () => app(CommandCapabilityRegistry::class)->definition('provider.raw_command'))
        ->toThrow(DomainException::class, 'The requested device capability is not recognised.');
});

it('rejects a site-wide scope through the ordinary suppression capability', function () {
    $capability = app(CommandCapabilityRegistry::class)->definition('monitoring.suppression.create');

    expect(fn () => app(CommandParameterValidator::class)->validate($capability, [
        'duration_minutes' => 30,
        'scope' => 'site',
    ]))->toThrow(ValidationException::class);
});

it('defines an ordered management permission lattice', function () {
    expect(array_map(fn (ManagementLevel $level): string => $level->permissionKey(), ManagementLevel::cases()))
        ->toBe([
            'securityDevices.commands.observe',
            'securityDevices.commands.operate',
            'securityDevices.commands.manage',
            'securityDevices.commands.control',
            'securityDevices.commands.admin',
        ])
        ->and(array_map(fn (ManagementLevel $level): int => $level->rank(), ManagementLevel::cases()))
        ->toBe([10, 20, 30, 40, 50]);
});
