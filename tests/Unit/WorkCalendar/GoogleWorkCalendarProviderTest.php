<?php

namespace Tests\Unit\WorkCalendar;

use App\Models\WorkCalendarEventLink;
use App\Services\WorkCalendar\WorkCalendarProvider;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\TestCase;

class GoogleWorkCalendarProviderTest extends TestCase
{
    public function test_google_delegates_to_the_work_mailbox_and_reuses_a_conflicting_operation(): void
    {
        // A public test-only fixture avoids machine-specific OpenSSL key-generation configuration.
        $pem = file_get_contents(__DIR__.'/fixtures/google-test-key.pem');
        $previousContainer = Container::getInstance();
        $previousFacades = Facade::getFacadeApplication();
        $container = new Container;
        $container->instance('config', new Repository(['work_calendar' => ['google' => [
            'service_account_email' => 'test@service.example', 'private_key' => $pem,
        ]]]));
        $container->instance(Factory::class, new Factory);
        Container::setInstance($container);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);

        try {
            Http::preventStrayRequests();
            $eventId = 'of11111111111141118111111111111111';
            Http::fake([
                'oauth2.googleapis.com/token' => Http::response(['access_token' => 'google-test-token']),
                'www.googleapis.com/calendar/v3/calendars/primary/events' => Http::response([], 409),
                "www.googleapis.com/calendar/v3/calendars/primary/events/$eventId" => Http::response(['id' => $eventId]),
            ]);
            $link = new WorkCalendarEventLink;
            $link->setRawAttributes([
                'provider' => 'google', 'mailbox' => 'worker@work.example',
                'operation_id' => '11111111-1111-4111-8111-111111111111',
            ]);
            self::assertSame($eventId, (new WorkCalendarProvider)->upsert($link, ['summary' => 'Work shift']));
            $publicKey = openssl_pkey_get_details(openssl_pkey_get_private($pem))['key'];
            Http::assertSent(function ($request) use ($publicKey): bool {
                if (! str_contains($request->url(), 'oauth2.googleapis.com/token')) {
                    return false;
                }
                $claims = JWT::decode($request['assertion'], new Key($publicKey, 'RS256'));

                return $claims->sub === 'worker@work.example'
                    && $claims->scope === WorkCalendarProvider::GOOGLE_SCOPE;
            });
            Http::assertSent(fn ($request) => $request->method() === 'PATCH' && str_ends_with($request->url(), $eventId));
        } finally {
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($previousFacades);
            Container::setInstance($previousContainer);
        }
    }
}
