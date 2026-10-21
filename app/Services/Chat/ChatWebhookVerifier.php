<?php

namespace App\Services\Chat;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use UnexpectedValueException;

final class ChatWebhookVerifier
{
    public function decode(string $body, string $authorization): array
    {
        if (strlen($body) > 65536 || strlen($authorization) > 4096) {
            throw new UnexpectedValueException('Webhook exceeds the size limit.');
        }
        $token = preg_replace('/^Bearer\s+/i', '', $authorization);
        $claims = JWT::decode($token, new Key(config('chat.calling.api_secret'), 'HS256'));
        if (($claims->iss ?? null) !== config('chat.calling.api_key')
            || ! isset($claims->sha256)
            || ! hash_equals(base64_encode(hash('sha256', $body, true)), (string) $claims->sha256)) {
            throw new UnexpectedValueException('Webhook signature does not match its body.');
        }
        $event = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($event)) {
            throw new UnexpectedValueException('Webhook event must be an object.');
        }

        return $event;
    }
}
