<?php

namespace App\Services;

use App\Contracts\CalendarOAuthToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleCalendarService
{
    public function __construct(protected CalendarOAuthToken $token) {}

    protected function client()
    {
        if ($this->token->needsRefresh()) {
            $this->refreshAccessToken();
        }

        return Http::withToken((string) $this->token->getAccessToken())
            ->baseUrl('https://www.googleapis.com/calendar/v3');
    }

    protected function refreshAccessToken(): void
    {
        $refreshToken = $this->token->getRefreshToken();
        if (! $refreshToken) {
            return;
        }

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);

        if ($response->successful()) {
            $this->token->storeRefreshedToken(
                (string) $response->json('access_token'),
                $response->json('refresh_token'),
                (int) $response->json('expires_in', 3600),
            );
        } else {
            Log::warning('Google token refresh failed', ['status' => $response->status()]);
        }
    }

    /**
     * List the calendars the connected account can access — including Workspace
     * resource calendars (rooms/houses) the admin has been granted on.
     *
     * @return array<int, array{id:string,name:string,primary:bool,accessRole:?string}>
     */
    public function listCalendars(): array
    {
        $response = $this->client()->get('/users/me/calendarList', [
            'maxResults' => 250,
            'showHidden' => 'true',
            'minAccessRole' => 'writer',
        ]);

        if (! $response->successful()) {
            return [];
        }

        return collect($response->json('items', []))
            ->map(fn (array $cal) => [
                'id' => (string) ($cal['id'] ?? ''),
                'name' => (string) ($cal['summaryOverride'] ?? $cal['summary'] ?? $cal['id'] ?? ''),
                'primary' => (bool) ($cal['primary'] ?? false),
                'accessRole' => $cal['accessRole'] ?? null,
            ])
            ->filter(fn ($cal) => $cal['id'] !== '')
            ->values()
            ->all();
    }

    public function getCalendarEvents(string $from, string $to, string $calendarId = 'primary'): array
    {
        $response = $this->client()->get('/calendars/'.rawurlencode($calendarId).'/events', [
            'timeMin' => $from,
            'timeMax' => $to,
            'singleEvents' => 'true',
            'orderBy' => 'startTime',
            'maxResults' => 250,
        ]);

        return $response->successful() ? $response->json('items', []) : [];
    }

    public function createCalendarEvent(array $data, string $calendarId = 'primary'): ?array
    {
        $response = $this->client()->post('/calendars/'.rawurlencode($calendarId).'/events', $data);

        return $response->successful() ? $response->json() : null;
    }

    public function updateCalendarEvent(string $eventId, array $data, string $calendarId = 'primary'): bool
    {
        return $this->client()
            ->put('/calendars/'.rawurlencode($calendarId).'/events/'.rawurlencode($eventId), $data)
            ->successful();
    }

    public function deleteCalendarEvent(string $eventId, string $calendarId = 'primary'): bool
    {
        return $this->client()
            ->delete('/calendars/'.rawurlencode($calendarId).'/events/'.rawurlencode($eventId))
            ->successful();
    }
}
