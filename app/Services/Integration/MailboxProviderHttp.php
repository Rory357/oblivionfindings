<?php

namespace App\Services\Integration;

use App\Contracts\CalendarOAuthToken;
use App\Services\Integration\Exceptions\MailboxProviderFailure;
use App\Services\Integration\Exceptions\ProviderRateLimited;
use Closure;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** Bounded HTTP boundary shared by the existing inbound mailbox adapters. */
final class MailboxProviderHttp
{
    private const MAX_INTERVAL_SECONDS = 31_536_000;

    public static function client(CalendarOAuthToken $token, string $provider, int $maxResponseBytes = MailboxResponseBody::DEFAULT_LIMIT): PendingRequest
    {
        $base = match ($provider) {
            'google' => 'https://gmail.googleapis.com/gmail/v1',
            'microsoft' => 'https://graph.microsoft.com/v1.0',
            default => throw new MailboxProviderFailure('rejected'),
        };
        if ($token->needsRefresh()) {
            self::refresh($token, $provider);
        }
        $access = $token->getAccessToken();
        if (! is_string($access) || trim($access) === '') {
            throw new MailboxProviderFailure('authentication');
        }

        return self::bounded($maxResponseBytes)->withToken($access)->baseUrl($base);
    }

    /** @param Closure(): Response $request */
    public static function send(Closure $request, bool $refresh = false): Response
    {
        try {
            $response = $request();
        } catch (ConnectionException|RequestException|GuzzleException) {
            // Deliberately omit the original exception: its URL can contain private identifiers.
            throw new MailboxProviderFailure('unavailable');
        }
        if ($response->successful()) {
            return $response;
        }
        if ($response->status() === 429) {
            $header = trim($response->header('Retry-After'));
            $seconds = ctype_digit($header) ? (int) $header : false;
            if ($seconds === false && $header !== '') {
                $date = strtotime($header);
                $seconds = $date === false ? false : max(1, $date - now()->timestamp);
            }
            if ($seconds !== false && $seconds > self::MAX_INTERVAL_SECONDS) {
                throw new MailboxProviderFailure('invalid_response');
            }
            throw new ProviderRateLimited($seconds === false ? 60 : max(1, $seconds));
        }
        $reason = match (true) {
            $response->status() === 401 => 'authentication',
            $refresh && $response->status() === 400 && $response->json('error') === 'invalid_grant' => 'authentication',
            $response->status() === 403 => 'permission',
            $response->status() === 408 || $response->status() >= 500 => 'unavailable',
            default => 'rejected',
        };
        throw new MailboxProviderFailure($reason);
    }

    /** A JSON object is required even when the provider omits an empty collection. */
    public static function object(Response $response): array
    {
        $body = $response->json();
        if (! is_array($body) || ! str_starts_with(ltrim($response->body()), '{')) {
            throw new MailboxProviderFailure('invalid_response');
        }

        return $body;
    }

    private static function bounded(int $maxResponseBytes = MailboxResponseBody::DEFAULT_LIMIT): PendingRequest
    {
        // Never follow a redirect with mailbox credentials or retry an ambiguous acknowledgement here.
        return Http::connectTimeout(5)->timeout(20)->withoutRedirecting()->acceptJson()
            ->withMiddleware(MailboxResponseBody::middleware($maxResponseBytes));
    }

    private static function refresh(CalendarOAuthToken $token, string $provider): void
    {
        $refresh = $token->getRefreshToken();
        if (! is_string($refresh) || trim($refresh) === '') {
            throw new MailboxProviderFailure('authentication');
        }
        if (! is_string(config("services.{$provider}.client_id")) || trim(config("services.{$provider}.client_id")) === ''
            || ! is_string(config("services.{$provider}.client_secret")) || trim(config("services.{$provider}.client_secret")) === '') {
            throw new MailboxProviderFailure('configuration');
        }
        $url = $provider === 'google'
            ? 'https://oauth2.googleapis.com/token'
            : 'https://login.microsoftonline.com/'.rawurlencode((string) config('services.microsoft.tenant')).'/oauth2/v2.0/token';
        $data = [
            'client_id' => config("services.{$provider}.client_id"),
            'client_secret' => config("services.{$provider}.client_secret"),
            'grant_type' => 'refresh_token',
            'refresh_token' => $refresh,
        ];
        if ($provider === 'microsoft') {
            $data['scope'] = 'https://graph.microsoft.com/.default offline_access';
        }
        $response = self::send(fn () => self::bounded()->asForm()->post($url, $data), refresh: true);
        $body = self::object($response);
        if (! is_string($body['access_token'] ?? null) || trim($body['access_token']) === ''
            || ! is_int($body['expires_in'] ?? null) || $body['expires_in'] < 1
            || $body['expires_in'] > self::MAX_INTERVAL_SECONDS
            || (isset($body['refresh_token']) && (! is_string($body['refresh_token']) || trim($body['refresh_token']) === ''))) {
            throw new MailboxProviderFailure('invalid_response');
        }
        $token->storeRefreshedToken($body['access_token'], $body['refresh_token'] ?? null, $body['expires_in']);
    }
}
