<?php

namespace App\Services\Chat;

use App\Models\ChatCall;
use App\Models\ChatCallParticipant;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class SelfHostedCalling
{
    public function ready(): bool
    {
        return (bool) (config('chat.calling.verified') && config('chat.calling.authorization_gateway_verified') && $this->configured());
    }

    public function configured(): bool
    {
        $url = (string) config('chat.calling.url');
        $apiUrl = (string) config('chat.calling.api_url');
        // Deployment-owned addresses only. Managed LiveKit endpoints are never accepted.
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $apiHost = strtolower((string) parse_url($apiUrl, PHP_URL_HOST));

        return $host !== '' && $apiHost !== '' && ! str_ends_with($host, '.livekit.cloud') && ! str_ends_with($apiHost, '.livekit.cloud')
            && parse_url($url, PHP_URL_SCHEME) === 'wss' && parse_url($apiUrl, PHP_URL_SCHEME) === 'https'
            && config('chat.calling.api_key') && strlen((string) config('chat.calling.api_secret')) >= 32;
    }

    public function connection(ChatCall $call, ChatCallParticipant $participant): array
    {
        abort_unless($this->ready(), 503, 'Voice calling is not available yet.');

        return ['url' => config('chat.calling.url'), 'token' => $this->token([
            'sub' => $participant->media_identity,
            'video' => ['roomJoin' => true, 'room' => $call->id, 'canPublish' => true, 'canSubscribe' => true,
                'canPublishSources' => ['microphone'], 'canPublishData' => false, 'canUpdateOwnMetadata' => false],
        ]), 'expires_in' => 30];
    }

    public function remove(ChatCall $call, ChatCallParticipant $participant): void
    {
        $this->request('RemoveParticipant', ['room' => $call->id, 'identity' => $participant->media_identity], ['roomAdmin' => true, 'room' => $call->id]);
    }

    public function end(ChatCall $call): void
    {
        $this->request('DeleteRoom', ['room' => $call->id], ['roomCreate' => true]);
    }

    public function health(): bool
    {
        if (! $this->configured()) {
            return false;
        }
        try {
            $response = $this->request('ListRooms', ['names' => []], ['roomList' => true]);
            $body = json_decode($response->body(), false, 32, JSON_THROW_ON_ERROR);

            return is_object($body) && (! property_exists($body, 'rooms') || is_array($body->rooms));
        } catch (\Throwable) {
            return false;
        }
    }

    private function request(string $method, array $payload, array $grant): Response
    {
        abort_unless($this->configured(), 503, 'Calling server is not configured.');
        $response = Http::withToken($this->token(['video' => $grant]))->asJson()->acceptJson()
            ->connectTimeout(3)->timeout(5)->withoutRedirecting()
            ->post(rtrim(config('chat.calling.api_url'), '/').'/twirp/livekit.RoomService/'.$method, $payload);
        // An already absent room/participant is a successful teardown.
        if ($response->status() !== 404 || ! in_array($method, ['RemoveParticipant', 'DeleteRoom'], true)) {
            $response->throw();
        }

        return $response;
    }

    private function token(array $claims): string
    {
        return JWT::encode([
            'iss' => config('chat.calling.api_key'), 'iat' => time(), 'nbf' => time() - 5, 'exp' => time() + 30, ...$claims,
        ], config('chat.calling.api_secret'), 'HS256');
    }
}
