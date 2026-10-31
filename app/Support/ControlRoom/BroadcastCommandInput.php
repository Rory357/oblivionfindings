<?php

namespace App\Support\ControlRoom;

use JsonException;

/** Strict captured JSON intent. No authorization, persistence or side effects. */
final readonly class BroadcastCommandInput
{
    public const CHANNELS = ['in_app', 'push', 'sms', 'email'];
    public const ROLES = ['admin', 'coordinator', 'support_worker', 'shift_lead', 'nurse'];

    private function __construct(public int $actorId, public string $key, public array $intent) {}

    public static function canonicalUuid(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value) === 1;
    }

    public static function parse(string $raw): self
    {
        self::require(strlen($raw) <= 16384 && $raw !== '');
        try {
            $decoded = json_decode($raw, false, 9, JSON_THROW_ON_ERROR);
            $offset = 0;
            self::uniqueKeys($raw, $offset);
        } catch (JsonException) {
            self::require(false);
        }
        self::require($decoded instanceof \stdClass);
        $data = (array) $decoded;
        $keys = ['schema_version', 'expected_actor_id', 'idempotency_key', 'content', 'channels', 'target_roles', 'send_to_all', 'template', 'force_delivery'];
        self::require(count($data) === count($keys) && array_diff(array_keys($data), $keys) === []);
        self::require($data['schema_version'] === 1 && is_int($data['expected_actor_id']) && $data['expected_actor_id'] > 0 && $data['expected_actor_id'] <= 9007199254740991);
        self::require(self::canonicalUuid($data['idempotency_key']));
        self::require(is_string($data['content']) && is_bool($data['send_to_all']) && is_bool($data['force_delivery']));
        $content = trim($data['content'], " \t\n\r\v\f");
        self::require(mb_strlen($content, 'UTF-8') >= 1 && mb_strlen($content, 'UTF-8') <= 2000);
        self::require($data['template'] === null || is_string($data['template']));
        $template = $data['template'] === null ? null : trim($data['template'], " \t\n\r\v\f");
        self::require($template === null || mb_strlen($template, 'UTF-8') <= 255);
        $channels = self::selection($data['channels'], self::CHANNELS, 1);
        $roles = self::selection($data['target_roles'], self::ROLES, 0);
        self::require($data['send_to_all'] ? $roles === [] : $roles !== []);

        return new self($data['expected_actor_id'], $data['idempotency_key'], [
            'content' => $content, 'channels' => $channels, 'target_roles' => $roles,
            'send_to_all' => $data['send_to_all'], 'template' => $template, 'force_delivery' => $data['force_delivery'],
        ]);
    }

    public function digest(): string
    {
        return self::digestIntent($this->intent);
    }

    public static function digestIntent(array $intent): string
    {
        $ordered = [];
        foreach (['content', 'channels', 'target_roles', 'send_to_all', 'template', 'force_delivery'] as $key) {
            if (! array_key_exists($key, $intent)) { throw new \LogicException('Incomplete captured broadcast intent.'); }
            $ordered[$key] = $intent[$key];
        }
        return hash('sha256', json_encode($ordered, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
    private static function selection(mixed $value, array $allowed, int $minimum): array
    {
        self::require(is_array($value) && array_is_list($value) && count($value) >= $minimum && count($value) <= count($allowed));
        foreach ($value as $item) {
            self::require(is_string($item) && in_array($item, $allowed, true));
        }
        return array_values(array_filter($allowed, fn (string $item): bool => in_array($item, $value, true)));
    }

    private static function require(bool $valid): void
    {
        if (! $valid) {
            throw new BroadcastInputRejected('Invalid broadcast request.');
        }
    }

    /** json_decode validates grammar first; this pass rejects decoded duplicate keys at every depth. */
    private static function uniqueKeys(string $raw, int &$offset): void
    {
        self::whitespace($raw, $offset);
        $first = $raw[$offset];
        if ($first === '{') {
            $offset++;
            self::whitespace($raw, $offset);
            $seen = [];
            if ($raw[$offset] === '}') { $offset++; return; }
            do {
                self::whitespace($raw, $offset);
                $key = self::string($raw, $offset);
                self::require(! array_key_exists($key, $seen));
                $seen[$key] = true;
                self::whitespace($raw, $offset);
                $offset++; // colon, already grammar-validated
                self::uniqueKeys($raw, $offset);
                self::whitespace($raw, $offset);
                $separator = $raw[$offset++];
            } while ($separator === ',');
            return;
        }
        if ($first === '[') {
            $offset++;
            self::whitespace($raw, $offset);
            if ($raw[$offset] === ']') { $offset++; return; }
            do {
                self::uniqueKeys($raw, $offset);
                self::whitespace($raw, $offset);
                $separator = $raw[$offset++];
            } while ($separator === ',');
            return;
        }
        if ($first === '"') { self::string($raw, $offset); return; }
        while ($offset < strlen($raw) && ! str_contains(",]} \t\r\n", $raw[$offset])) { $offset++; }
    }

    private static function string(string $raw, int &$offset): string
    {
        $start = $offset++;
        while ($offset < strlen($raw)) {
            $character = $raw[$offset++];
            if ($character === '\\') { $offset++; continue; }
            if ($character === '"') { break; }
        }
        return json_decode(substr($raw, $start, $offset - $start), true, 2, JSON_THROW_ON_ERROR);
    }

    private static function whitespace(string $raw, int &$offset): void
    {
        while ($offset < strlen($raw) && str_contains(" \t\r\n", $raw[$offset])) { $offset++; }
    }
}




