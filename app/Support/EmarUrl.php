<?php

namespace App\Support;

use App\Models\Client;

class EmarUrl
{
    public static function mar(int|Client|null $client = null, ?string $date = null, array $params = []): string
    {
        return route('emar.mar', self::query(array_merge($params, [
            'client_id' => self::clientId($client),
            'date' => $date,
        ])));
    }

    public static function medications(int|Client|null $client = null, array $params = []): string
    {
        return route('emar.medications', self::query(array_merge($params, [
            'client_id' => self::clientId($client),
        ])));
    }

    public static function daily(array $params = []): string
    {
        return route('emar.daily', self::query($params));
    }

    public static function dashboard(array $params = []): string
    {
        return route('emar.index', self::query($params));
    }

    private static function clientId(int|Client|null $client): ?int
    {
        if ($client instanceof Client) {
            return $client->id;
        }

        return $client;
    }

    private static function query(array $params): array
    {
        return array_filter(
            $params,
            static fn ($value) => ! ($value === null || $value === ''),
        );
    }
}
