<?php

namespace App\Services\Notifications;

use Illuminate\Support\Facades\Http;
use Throwable;

final readonly class ExpoPushProvider implements PushProvider
{
    public function __construct(
        private string $endpoint,
        private ?string $accessToken = null,
    ) {
    }

    public function send(array $tokens, string $message, ?string $title = null, array $data = []): PushSendResult
    {
        $tokens = array_values(array_filter(array_unique($tokens), fn (string $token) => filled($token)));

        if ($tokens === []) {
            return PushSendResult::failed('No push token on record for the recipient.');
        }

        $payload = array_map(fn (string $token) => [
            'to' => $token,
            'title' => $title ?? 'Broadcast alert',
            'body' => $message,
            'data' => $data,
            'sound' => 'default',
        ], $tokens);

        try {
            $request = Http::acceptJson()
                ->asJson()
                ->timeout(10);

            if (filled($this->accessToken)) {
                $request = $request->withToken($this->accessToken);
            }

            $response = $request->post($this->endpoint, $payload);
        } catch (Throwable $e) {
            return PushSendResult::failed(substr($e->getMessage(), 0, 240));
        }

        if (! $response->successful()) {
            return PushSendResult::failed('Expo push request failed with HTTP '.$response->status().'.');
        }

        $items = $response->json('data') ?? [];
        $errors = [];
        $ids = [];

        foreach ($items as $item) {
            if (($item['status'] ?? null) === 'ok') {
                if (isset($item['id'])) {
                    $ids[] = (string) $item['id'];
                }

                continue;
            }

            $errors[] = $item['message'] ?? ($item['details']['error'] ?? 'Expo push provider rejected the message.');
        }

        if ($errors !== []) {
            return PushSendResult::failed(substr(implode('; ', array_unique($errors)), 0, 240));
        }

        return PushSendResult::sent($ids);
    }
}
