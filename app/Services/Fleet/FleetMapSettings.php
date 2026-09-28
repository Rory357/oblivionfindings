<?php

namespace App\Services\Fleet;

use App\Models\AppSetting;

class FleetMapSettings
{
    public const KEY = 'fleet.maps.provider';

    public function values(): array
    {
        return array_replace([
            'google' => false, 'project' => '', 'display' => false, 'places' => false, 'geocoding' => false, 'routes' => false,
            'restrictions_reviewed' => false, 'terms_reviewed' => false,
        ], (array) AppSetting::where('key', self::KEY)->value('value'));
    }

    public function credentials(): array
    {
        // References only. The server key must never be sent to a browser or an audit log.
        return ['browser' => filled(config('fleet.maps.api_key')), 'server' => filled(config('fleet.maps.google_server_key'))];
    }

    public function enabled(string $capability): bool
    {
        return $this->evaluate($capability, $this->values(), $this->credentials());
    }

    private function evaluate(string $capability, array $values, array $credentials): bool
    {
        return in_array($capability, ['display', 'places', 'geocoding', 'routes'], true)
            && $values['google'] && $values[$capability]
            && $values['restrictions_reviewed'] && $values['terms_reviewed']
            && $credentials[$capability === 'display' ? 'browser' : 'server'];
    }

    public function snapshot(): array
    {
        $values = $this->values();
        $credentials = $this->credentials();

        return ['values' => $values, 'credentials' => $credentials,
            'revision' => hash('sha256', json_encode([$values, $credentials, hash_hmac('sha256', config('fleet.maps.api_key').'|'.config('fleet.maps.google_server_key'), (string) config('app.key'))], JSON_THROW_ON_ERROR)),
            'capabilities' => collect(['display' => 'Map display', 'places' => 'Address search', 'geocoding' => 'Reverse geocoding', 'routes' => 'Routing'])->map(fn ($title, $key) => [
                'key' => $key, 'title' => $title, 'enabled' => $this->evaluate($key, $values, $credentials),
                'status' => $this->evaluate($key, $values, $credentials) ? 'Configured · not verified' : 'Not enabled',
            ])->values()->all(),
        ];
    }

    public function browser(): array
    {
        $snapshot = $this->snapshot();
        $display = $this->evaluate('display', $snapshot['values'], $snapshot['credentials']);

        return ['provider' => $display ? 'google' : 'osm',
            'apiKey' => $display ? config('fleet.maps.api_key') : null,
            'revision' => $snapshot['revision']];
    }
}
