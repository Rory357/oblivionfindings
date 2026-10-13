<?php

namespace App\Domain\SecurityDevices\Cctv\Services;

use Carbon\CarbonImmutable;
use Illuminate\Pagination\Cursor;
use InvalidArgumentException;

final class CctvRecordingReferenceInput
{
    public static function create(array $input, CarbonImmutable $now, int $maximum): array
    {
        CctvContract::exact($input, ['expected_actor_id', 'request_key', 'expected_source_revision', 'requested_start_at', 'requested_end_at', 'purpose']);
        CctvContract::positive($input['expected_actor_id']);
        CctvContract::positive($input['expected_source_revision']);
        CctvContract::uuid($input['request_key']);
        CctvContract::text($input['purpose'], 10, 500);
        $start = self::instant($input['requested_start_at']);
        $end = self::instant($input['requested_end_at']);
        if ($start->greaterThan($now) || ! $end->greaterThan($start) || $end->timestamp - $start->timestamp > $maximum) {
            throw new InvalidArgumentException('invalid_request');
        }

        return $input;
    }

    public static function instant(mixed $value): CarbonImmutable
    {
        if (! is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $value) !== 1) {
            throw new InvalidArgumentException('invalid_request');
        }
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, 'UTC');
        } catch (\Throwable) {
            throw new InvalidArgumentException('invalid_request');
        }
        if (! $date || $date->format('Y-m-d\TH:i:s\Z') !== $value) {
            throw new InvalidArgumentException('invalid_request');
        }

        return $date;
    }

    public static function cursor(array $input, string $scope): ?Cursor
    {
        if ($input === []) {
            return null;
        }
        CctvContract::exact($input, ['cursor']);
        $encoded = $input['cursor'];
        if (! is_string($encoded) || $encoded === '' || strlen($encoded) > 256 || preg_match('/\A[A-Za-z0-9_-]+\z/', $encoded) !== 1) {
            throw new InvalidArgumentException('invalid_request');
        }
        try {
            $payload = json_decode(base64_decode(strtr($encoded, '-_', '+/'), true), true, 3, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new InvalidArgumentException('invalid_request');
        }
        if (! is_array($payload) || array_keys($payload) !== ['id', 'scope_sha256', '_pointsToNextItems'] || ! is_string($payload['scope_sha256']) || ! hash_equals($scope, $payload['scope_sha256']) || ! is_int($payload['id']) || $payload['id'] < 1 || ! is_bool($payload['_pointsToNextItems'])) {
            throw new InvalidArgumentException('invalid_request');
        }
        $cursor = new Cursor(['id' => $payload['id']], $payload['_pointsToNextItems']);
        if ((new Cursor(['id' => $payload['id'], 'scope_sha256' => $scope], $payload['_pointsToNextItems']))->encode() !== $encoded) {
            throw new InvalidArgumentException('invalid_request');
        }

        return $cursor;
    }
}
