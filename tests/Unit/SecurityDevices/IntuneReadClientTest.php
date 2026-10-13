<?php

use App\Domain\SecurityDevices\Endpoints\Services\IntuneReadClient;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->oldContainer = Container::getInstance();
    $this->oldFacades = Facade::getFacadeApplication();
    $container = new Container;
    $container->instance('config', new Repository(['endpoint_management' => ['intune' => [
        'enabled' => true, 'directory_id' => '11111111-1111-4111-8111-111111111111',
        'client_id' => '22222222-2222-4222-8222-222222222222', 'client_secret' => 'test-only-secret',
        'maximum_pages' => 3, 'maximum_devices' => 10,
    ]]]));
    $container->instance(Factory::class, new Factory);
    Container::setInstance($container);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($container);
    Http::preventStrayRequests();
});

afterEach(function () {
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($this->oldFacades);
    Container::setInstance($this->oldContainer);
});

function fakeIntunePages(array $pages): void
{
    $sequence = Http::sequence();
    foreach ($pages as $page) {
        $sequence->push($page, 200);
    }
    Http::fake([
        'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response(['access_token' => 'test-only-token']),
        'graph.microsoft.com/v1.0/deviceManagement/managedDevices*' => $sequence,
    ]);
}

it('reads multiple device pages without performing Graph write actions or exposing extra person data', function () {
    fakeIntunePages([
        ['value' => [['id' => 'AAAAAAAA-AAAA-4AAA-8AAA-AAAAAAAAAAAA', 'deviceName' => 'Care laptop', 'complianceState' => 'compliant', 'isEncrypted' => true, 'userPrincipalName' => 'private@example.test']], '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/deviceManagement/managedDevices?$skiptoken=page2'],
        ['value' => [['id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'deviceName' => 'Shared tablet']]],
    ]);
    $records = (new IntuneReadClient)->devices();
    expect($records)->toHaveCount(2)
        ->and($records[0]['id'])->toBe('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa')
        ->and($records[0]['isEncrypted'])->toBeTrue()
        ->and($records[0])->not->toHaveKey('userPrincipalName');
    Http::assertSentCount(3);
    Http::assertSent(fn ($request) => $request->method() === 'POST' && $request['scope'] === 'https://graph.microsoft.com/.default');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'graph.microsoft.com') && $request->method() !== 'GET');
});

it('rejects pagination that could disclose the Graph credential or change resource scope', function (string $next) {
    fakeIntunePages([['value' => [], '@odata.nextLink' => $next]]);
    expect(fn () => (new IntuneReadClient)->devices())->toThrow(RuntimeException::class);
    Http::assertSentCount(2);
})->with([
    'foreign host' => 'https://example.test/deviceManagement/managedDevices',
    'userinfo' => 'https://graph.microsoft.com@example.test/v1.0/deviceManagement/managedDevices',
    'http' => 'http://graph.microsoft.com/v1.0/deviceManagement/managedDevices',
    'port' => 'https://graph.microsoft.com:444/v1.0/deviceManagement/managedDevices',
    'write-action path' => 'https://graph.microsoft.com/v1.0/deviceManagement/managedDevices/aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa/wipe',
    'different records' => 'https://graph.microsoft.com/v1.0/users',
    'fragment' => 'https://graph.microsoft.com/v1.0/deviceManagement/managedDevices#invalid',
]);

it('fails the complete read when a later page fails instead of returning a partial estate', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'test-only-token']),
        'graph.microsoft.com/*' => Http::sequence()
            ->push(['value' => [['id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa']], '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/deviceManagement/managedDevices?$skiptoken=next'])
            ->push(['error' => ['message' => 'PRIVATE-UPSTREAM-DATA']], 403),
    ]);
    try {
        (new IntuneReadClient)->devices();
        $this->fail('A partial estate must not be returned.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->not->toContain('PRIVATE-UPSTREAM-DATA', 'test-only-token', 'test-only-secret');
    }
});

it('detects repeated pagination and bounds page counts', function () {
    $next = 'https://graph.microsoft.com/v1.0/deviceManagement/managedDevices?$skiptoken=repeat';
    fakeIntunePages([['value' => [], '@odata.nextLink' => $next], ['value' => [], '@odata.nextLink' => $next]]);
    expect(fn () => (new IntuneReadClient)->devices())->toThrow(RuntimeException::class);
    Http::assertSentCount(3);
});

it('rejects duplicate identities even if their UUID casing differs', function () {
    fakeIntunePages([['value' => [
        ['id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'],
        ['id' => 'AAAAAAAA-AAAA-4AAA-8AAA-AAAAAAAAAAAA'],
    ]]]);
    expect(fn () => (new IntuneReadClient)->devices())->toThrow(RuntimeException::class);
});

it('does not guess that malformed encryption values mean encrypted', function () {
    fakeIntunePages([['value' => [['id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'isEncrypted' => 'false']]]]);
    expect(fn () => (new IntuneReadClient)->devices())->toThrow(RuntimeException::class);
});

it('rejects nested device properties rather than retaining arbitrary data', function () {
    fakeIntunePages([['value' => [['id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'deviceName' => ['unsafe']]]]]);
    expect(fn () => (new IntuneReadClient)->devices())->toThrow(RuntimeException::class);
});

it('makes no request while visibility is disabled or connection identity is invalid', function () {
    config()->set('endpoint_management.intune.enabled', false);
    expect(fn () => (new IntuneReadClient)->devices())->toThrow(RuntimeException::class);
    config()->set('endpoint_management.intune.enabled', true);
    config()->set('endpoint_management.intune.directory_id', '../../foreign');
    expect(fn () => (new IntuneReadClient)->devices())->toThrow(RuntimeException::class);
    Http::assertNothingSent();
});

it('enforces the configured estate bound without returning incomplete success', function () {
    config()->set('endpoint_management.intune.maximum_devices', 1);
    fakeIntunePages([['value' => [
        ['id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'],
        ['id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'],
    ]]]);
    expect(fn () => (new IntuneReadClient)->devices())->toThrow(RuntimeException::class);
});
