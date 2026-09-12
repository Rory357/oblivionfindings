<?php

namespace App\Services\WorkCalendar;

use App\Models\WorkCalendarEventLink;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/** App-only work calendar access; never falls back to an employee's login token. */
class WorkCalendarProvider
{
    public const GOOGLE_SCOPE = 'https://www.googleapis.com/auth/calendar.events';

    private const OPERATION_PROPERTY = 'String {629a60ea-03a2-4a7e-a55a-3bf7e118b41b} Name OblivionWorkCalendarOperation';

    private array $tokens = [];

    public function configured(string $provider): bool
    {
        $keys = match ($provider) {
            'google' => ['service_account_email', 'private_key'],
            'microsoft' => ['directory_id', 'client_id', 'client_secret'],
            default => [],
        };

        return $keys !== [] && collect($keys)->every(fn ($key) => filled(config("work_calendar.$provider.$key")));
    }

    public function fingerprint(string $provider, string $domain): string
    {
        return hash('sha256', $provider.'|'.$domain.'|'.json_encode(config("work_calendar.$provider")));
    }

    public function check(string $provider, string $mailbox): void
    {
        $response = $this->client($provider, $mailbox)->get($this->eventsPath($provider, $mailbox),
            $provider === 'google' ? ['maxResults' => 1, 'fields' => 'kind'] : ['$top' => 1, '$select' => 'id']);
        $this->assertSuccess($response);
    }

    public function upsert(WorkCalendarEventLink $link, array $event): string
    {
        $client = $this->client($link->provider, $link->mailbox);
        $path = $this->eventsPath($link->provider, $link->mailbox);
        if ($link->provider === 'microsoft' && ! $link->external_id) {
            $recovered = $this->recoverMicrosoftId($link);
            if ($recovered) {
                $link->update(['external_id' => $recovered]);
            }
        }
        if ($link->external_id) {
            $response = $client->patch($path.'/'.rawurlencode($link->external_id), $event);
            if (! in_array($response->status(), [404, 410], true)) {
                $this->assertSuccess($response);

                return $link->external_id;
            }
            // A user removed this copy. Start a new idempotent operation before retrying.
            $link->update(['external_id' => null, 'operation_id' => (string) Str::uuid()]);
        }
        if ($link->provider === 'google') {
            $event['id'] = 'of'.str_replace('-', '', $link->operation_id);
        } else {
            $event['transactionId'] = $link->operation_id;
            $event['singleValueExtendedProperties'] = [['id' => self::OPERATION_PROPERTY, 'value' => $link->operation_id]];
        }
        $response = $client->post($path, $event);
        if ($link->provider === 'google' && $response->status() === 409) {
            $response = $client->patch($path.'/'.$event['id'], array_diff_key($event, ['id' => true]));
        }
        $this->assertSuccess($response);
        $id = $response->json('id');
        if (! is_string($id) || $id === '') {
            throw new RuntimeException('The calendar provider returned an incomplete response.');
        }

        return $id;
    }

    public function remove(WorkCalendarEventLink $link): void
    {
        $id = $link->external_id;
        if (! $id && $link->provider === 'google') {
            $id = 'of'.str_replace('-', '', $link->operation_id);
        }
        if (! $id) {
            // An interrupted Graph create may have succeeded. Recover only our operation.
            $id = $this->recoverMicrosoftId($link);
        }
        if ($id) {
            $response = $this->client($link->provider, $link->mailbox)
                ->delete($this->eventsPath($link->provider, $link->mailbox).'/'.rawurlencode($id));
            if (! in_array($response->status(), [404, 410], true)) {
                $this->assertSuccess($response);
            }
        }
    }

    private function client(string $provider, string $mailbox): PendingRequest
    {
        if (! $this->configured($provider)) {
            throw new RuntimeException('Organisation calendar credentials are not configured.');
        }
        $tokenKey = $provider === 'google' ? $mailbox : 'microsoft';
        if (! isset($this->tokens[$tokenKey]) || $this->tokens[$tokenKey]['expires'] <= time() + 60) {
            if ($provider === 'google') {
                $assertion = JWT::encode([
                    'iss' => config('work_calendar.google.service_account_email'),
                    'sub' => $mailbox, 'scope' => self::GOOGLE_SCOPE,
                    'aud' => 'https://oauth2.googleapis.com/token', 'iat' => time(), 'exp' => time() + 3600,
                ], str_replace('\\n', "\n", config('work_calendar.google.private_key')), 'RS256');
                $response = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $assertion,
                ]);
            } else {
                $directory = config('work_calendar.microsoft.directory_id');
                if (! preg_match('/^[a-f0-9-]{36}$/i', $directory)) {
                    throw new RuntimeException('Set the Microsoft organisation directory ID.');
                }
                $response = Http::asForm()->timeout(20)->post("https://login.microsoftonline.com/$directory/oauth2/v2.0/token", [
                    'grant_type' => 'client_credentials', 'scope' => 'https://graph.microsoft.com/.default',
                    'client_id' => config('work_calendar.microsoft.client_id'),
                    'client_secret' => config('work_calendar.microsoft.client_secret'),
                ]);
            }
            $this->assertSuccess($response);
            if (! is_string($response->json('access_token')) || ! $response->json('access_token')) {
                throw new RuntimeException('The calendar provider did not return an access token.');
            }
            $this->tokens[$tokenKey] = ['token' => $response->json('access_token'), 'expires' => time() + (int) $response->json('expires_in', 3600)];
        }

        return Http::withToken($this->tokens[$tokenKey]['token'])->acceptJson()->timeout(20)
            ->baseUrl($provider === 'google' ? 'https://www.googleapis.com/calendar/v3' : 'https://graph.microsoft.com/v1.0');
    }

    private function eventsPath(string $provider, string $mailbox): string
    {
        return $provider === 'google' ? '/calendars/primary/events' : '/users/'.rawurlencode($mailbox).'/calendar/events';
    }

    private function recoverMicrosoftId(WorkCalendarEventLink $link): ?string
    {
        $property = self::OPERATION_PROPERTY;
        $response = $this->client('microsoft', $link->mailbox)->get($this->eventsPath('microsoft', $link->mailbox), [
            '$filter' => "singleValueExtendedProperties/Any(ep: ep/id eq '$property' and ep/value eq '{$link->operation_id}')",
            '$select' => 'id', '$top' => 1,
        ]);
        $this->assertSuccess($response);

        return $response->json('value.0.id');
    }

    private function assertSuccess(Response $response): void
    {
        if (! $response->successful()) {
            // Never disclose upstream bodies, mailbox contents, credentials, or token requests.
            throw new RuntimeException('Calendar access failed (HTTP '.$response->status().'). Ask your admin to check credentials and calendar permissions.');
        }
    }
}
