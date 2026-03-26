<?php

namespace App\Services;

use App\Models\Identity;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleCalendarService
{
    protected Identity $identity;

    public function __construct(Identity $identity)
    {
        $this->identity = $identity;
    }

    protected function client()
    {
        if ($this->identity->needsRefresh()) {
            $this->refreshAccessToken();
        }

        return Http::withToken($this->identity->access_token)
            ->baseUrl('https://www.googleapis.com/calendar/v3');
    }

    protected function refreshAccessToken(): void
    {
        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'grant_type' => 'refresh_token',
            'refresh_token' => $this->identity->refresh_token,
        ]);

        if ($response->successful()) {
            $this->identity->update([
                'access_token' => $response->json('access_token'),
                'token_expires_at' => now()->addSeconds($response->json('expires_in', 3600)),
            ]);
        } else {
            Log::warning('Google token refresh failed', ['user_id' => $this->identity->user_id]);
        }
    }

    public function getCalendarEvents(string $from, string $to, string $calendarId = 'primary'): array
    {
        $response = $this->client()->get("/calendars/{$calendarId}/events", [
            'timeMin' => $from,
            'timeMax' => $to,
            'singleEvents' => 'true',
            'orderBy' => 'startTime',
            'maxResults' => 100,
        ]);

        return $response->successful() ? $response->json('items', []) : [];
    }

    public function createCalendarEvent(array $data, string $calendarId = 'primary'): ?array
    {
        $response = $this->client()->post("/calendars/{$calendarId}/events", $data);

        return $response->successful() ? $response->json() : null;
    }

    public function updateCalendarEvent(string $eventId, array $data, string $calendarId = 'primary'): bool
    {
        return $this->client()->put("/calendars/{$calendarId}/events/{$eventId}", $data)->successful();
    }

    public function deleteCalendarEvent(string $eventId, string $calendarId = 'primary'): bool
    {
        return $this->client()->delete("/calendars/{$calendarId}/events/{$eventId}")->successful();
    }
}
