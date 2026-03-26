<?php

namespace App\Services;

use App\Models\Identity;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MicrosoftGraphService
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
            ->baseUrl('https://graph.microsoft.com/v1.0');
    }

    protected function refreshAccessToken(): void
    {
        $response = Http::asForm()->post(
            'https://login.microsoftonline.com/' . config('services.microsoft.tenant') . '/oauth2/v2.0/token',
            [
                'client_id' => config('services.microsoft.client_id'),
                'client_secret' => config('services.microsoft.client_secret'),
                'grant_type' => 'refresh_token',
                'refresh_token' => $this->identity->refresh_token,
                'scope' => 'https://graph.microsoft.com/.default',
            ]
        );

        if ($response->successful()) {
            $this->identity->update([
                'access_token' => $response->json('access_token'),
                'refresh_token' => $response->json('refresh_token', $this->identity->refresh_token),
                'token_expires_at' => now()->addSeconds($response->json('expires_in', 3600)),
            ]);
        } else {
            Log::warning('Microsoft token refresh failed', ['user_id' => $this->identity->user_id]);
        }
    }

    // Calendar methods

    public function getCalendarEvents(string $from, string $to): array
    {
        $response = $this->client()->get('/me/calendarview', [
            'startDateTime' => $from,
            'endDateTime' => $to,
            '$select' => 'id,subject,start,end,location,body,isAllDay',
            '$top' => 100,
            '$orderby' => 'start/dateTime',
        ]);

        return $response->successful() ? $response->json('value', []) : [];
    }

    public function createCalendarEvent(array $data): ?array
    {
        $response = $this->client()->post('/me/events', $data);

        return $response->successful() ? $response->json() : null;
    }

    public function updateCalendarEvent(string $eventId, array $data): bool
    {
        return $this->client()->patch("/me/events/{$eventId}", $data)->successful();
    }

    public function deleteCalendarEvent(string $eventId): bool
    {
        return $this->client()->delete("/me/events/{$eventId}")->successful();
    }

    // Mail methods

    public function sendMail(string $to, string $subject, string $body, array $attachments = []): bool
    {
        $message = [
            'message' => [
                'subject' => $subject,
                'body' => ['contentType' => 'HTML', 'content' => $body],
                'toRecipients' => [['emailAddress' => ['address' => $to]]],
            ],
        ];

        if (!empty($attachments)) {
            $message['message']['attachments'] = $attachments;
        }

        return $this->client()->post('/me/sendMail', $message)->successful();
    }

    // User info

    public function getProfile(): ?array
    {
        $response = $this->client()->get('/me', [
            '$select' => 'id,displayName,mail,jobTitle,department',
        ]);

        return $response->successful() ? $response->json() : null;
    }
}
