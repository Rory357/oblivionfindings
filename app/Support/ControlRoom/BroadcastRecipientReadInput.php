<?php

namespace App\Support\ControlRoom;

use InvalidArgumentException;
use JsonException;

/** Strict transport values only; expected actor is a correlation guard, never authority. */
final readonly class BroadcastRecipientReadInput
{
    private function __construct(public int $expectedActorId, public string $commandId) {}

    public static function parse(string $raw): self
    {
        self::require($raw !== '' && strlen($raw) <= 1024);
        try {
            $value = json_decode($raw, false, 5, JSON_THROW_ON_ERROR);
            $offset = 0;
            self::uniqueKeys($raw, $offset);
        } catch (JsonException) { throw new InvalidArgumentException('Invalid broadcast read request.'); }
        self::require($value instanceof \stdClass);
        $data = (array) $value;
        $keys = ['kind', 'schema_version', 'expected_actor_id', 'command_id'];
        self::require(count($data) === 4 && array_diff(array_keys($data), $keys) === []);
        self::require($data['kind'] === 'broadcast_recipient' && $data['schema_version'] === 1);
        self::require(is_int($data['expected_actor_id']) && $data['expected_actor_id'] > 0 && $data['expected_actor_id'] <= 9007199254740991);
        self::require(BroadcastCommandInput::canonicalUuid($data['command_id']));
        return new self($data['expected_actor_id'], $data['command_id']);
    }

    /** Consume raw query: PHP's parsed query loses duplicate keys and array syntax. */
    public static function reconcileActor(string $rawQuery): int
    {
        self::require(strlen($rawQuery) <= 80 && preg_match('/^expected_actor_id=([1-9][0-9]{0,15})$/D', $rawQuery, $matches) === 1);
        $id = (int) $matches[1];
        self::require($id <= 9007199254740991 && (string) $id === $matches[1]);
        return $id;
    }

    public function fingerprint(string $operation, ?string $notificationId): string
    {
        self::require(in_array($operation, ['single', 'bulk'], true));
        self::require($operation === 'single' ? BroadcastCommandInput::canonicalUuid($notificationId) : $notificationId === null);
        return hash('sha256', json_encode([1, $this->expectedActorId, $operation, $notificationId], JSON_THROW_ON_ERROR));
    }

    private static function require(bool $valid): void
    {
        if (! $valid) { throw new InvalidArgumentException('Invalid broadcast read request.'); }
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




