<?php

use App\Domain\SecurityDevices\Endpoints\Services\EndpointServiceCatalogue;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointServiceCommands;
use App\Domain\SecurityDevices\Management\Services\CommandCapabilityRegistry;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

pest()->extend(TestCase::class);

it('keeps the reviewed service catalogue configurable bounded and canonical while denying protected names', function () {
    config()->set('endpoint_management.services', [['name' => 'Spooler', 'label' => 'Print service', 'operations' => ['restart', 'start']], ['name' => 'Example Service_2', 'label' => 'Example service', 'operations' => ['stop']]]);
    $catalogue = app(EndpointServiceCatalogue::class);
    expect($catalogue->all())->toBe([['name' => 'Example Service_2', 'label' => 'Example service', 'operations' => ['stop']], ['name' => 'Spooler', 'label' => 'Print service', 'operations' => ['start', 'restart']]])
        ->and($catalogue->digest())->toMatch('/^[a-f0-9]{64}$/')->and($catalogue->allows('Spooler', 'stop'))->toBeFalse();
    $original = $catalogue->digest();
    config()->set('endpoint_management.services', array_reverse(config('endpoint_management.services')));
    expect($catalogue->digest())->toBe($original);
    foreach (['OblivionAgent', 'RpcSs', 'WinDefend', 'Appinfo', ' Spooler', 'Spooler ', 'a/b', "x\n", '🛠service'] as $name) {
        expect(EndpointServiceCatalogue::safeName($name))->toBeFalse();
    }
    config()->set('endpoint_management.services', [['name' => 'Spooler', 'label' => 'Print', 'operations' => ['start']], ['name' => 'spooler', 'label' => 'Duplicate', 'operations' => ['stop']]]);
    expect($catalogue->all())->toBe([]);
    config()->set('endpoint_management.services', [['name' => 'Spooler', 'label' => "private\u{0085}control", 'operations' => ['start']]]);
    expect($catalogue->all())->toBe([]);
});

it('requires canonical high risk safeguards and exposes catalogue options with safe labels', function () {
    config()->set('endpoint_management.services', [['name' => 'Spooler', 'label' => 'Print service', 'operations' => ['start']]]);
    $definition = app(CommandCapabilityRegistry::class)->definition('endpoint.service.manage');
    expect($definition->risk->value)->toBe('high')->and($definition->level->value)->toBe('control')
        ->and($definition->requiresMfa)->toBeTrue()->and($definition->requiresApproval)->toBeTrue()
        ->and($definition->requiresStepUp)->toBeTrue()->and($definition->requiresChange)->toBeFalse()
        ->and($definition->allowsBreakGlass)->toBeFalse()->and($definition->retryPolicy)->toBe('reconcile_before_retry')
        ->and($definition->parameters['service_name']['enum'])->toBe(['Spooler'])
        ->and($definition->parameters['service_name']['option_labels'])->toBe(['Spooler' => 'Print service']);
});

it('rejects results that claim clean failure after mutation or invented restart success', function () {
    $validate = new ReflectionMethod(EndpointServiceCommands::class, 'validateResult');
    $commands = app(EndpointServiceCommands::class);
    $base = ['status' => 'succeeded', 'operation' => 'restart', 'before_state' => 'running', 'after_state' => 'running', 'mutation_started' => false, 'reason_code' => 'already_in_state'];
    expect(fn () => $validate->invoke($commands, $base))->toThrow(HttpException::class);
    expect(fn () => $validate->invoke($commands, [...$base, 'status' => 'failed', 'reason_code' => 'operation_failed', 'mutation_started' => true]))->toThrow(HttpException::class);
    $validate->invoke($commands, [...$base, 'status' => 'uncertain', 'reason_code' => 'operation_timeout', 'mutation_started' => true]);
    $validate->invoke($commands, [...$base, 'reason_code' => 'confirmed', 'mutation_started' => true]);
    $validate->invoke($commands, [...$base, 'status' => 'declined', 'reason_code' => 'declined']);
    expect(true)->toBeTrue();
});
