<?php

namespace App\Notifications\Channels;

use App\Services\Notifications\ExpoPushProvider;
use App\Services\Notifications\WebPushProvider;
use Illuminate\Notifications\Notification;

class PushChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toPush')) {
            return;
        }

        $payload = $notification->toPush($notifiable);
        $title = (string) ($payload['title'] ?? config('app.name', 'Oblivion Findings'));
        $body = (string) ($payload['body'] ?? $payload['message'] ?? '');
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        if ($body === '' || ! method_exists($notifiable, 'pushSubscriptions')) {
            return;
        }

        $subscriptions = $notifiable->pushSubscriptions()
            ->where('enabled', true)
            ->get();

        $expoTokens = $subscriptions
            ->where('provider', 'expo')
            ->pluck('token')
            ->filter()
            ->values()
            ->all();

        if ($expoTokens !== []) {
            app(ExpoPushProvider::class)->send($expoTokens, $body, $title, $data);
        }

        $webSubscriptions = $subscriptions
            ->where('provider', 'webpush')
            ->values();

        if ($webSubscriptions->isNotEmpty()) {
            app(WebPushProvider::class)->sendToSubscriptions($webSubscriptions, [
                'title' => $title,
                'body' => $body,
                'data' => $data,
            ]);
        }
    }
}
