<?php

namespace App\Services\Fleet;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Cache;

class FleetMapSettings
{
    public const KEY = 'fleet.maps.provider';

    public const CAPABILITIES = ['display' => 'Map display', 'places' => 'Address search', 'geocoding' => 'Reverse geocoding', 'routes' => 'Routing'];

    public function values(): array
    {
        return array_replace([
            'google' => false, 'project' => (string) config('fleet.maps.google_project_id', ''), 'display' => false, 'places' => false, 'geocoding' => false, 'routes' => false,
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
        return array_key_exists($capability, self::CAPABILITIES)
            && $values['google'] && $values[$capability]
            && $values['display'] && $credentials['browser']
            && $values['restrictions_reviewed'] && $values['terms_reviewed']
            && $credentials[$capability === 'display' ? 'browser' : 'server'];
    }

    /** Configuration validation only. Never contacts Google or infers API health. */
    public function validationErrors(array $values): array
    {
        if (! $values['google']) {
            return [];
        }
        $errors = [];
        $credentials = $this->credentials();
        if (trim($values['project'] ?? '') === '') {
            $errors['values.project'] = 'Enter the Google Cloud project reference.';
        }
        if (! $values['display']) {
            $errors['values.display'] = 'Enable Google map display for Google results shown on maps.';
        }
        if (! $credentials['browser']) {
            $errors['values.google'] = 'The restricted browser credential is missing. Ask the deployment owner to configure GOOGLE_MAPS_API_KEY, then reload saved settings.';
        }
        if (($values['places'] || $values['geocoding'] || $values['routes']) && ! $credentials['server']) {
            $errors['values.places'] = 'The selected address or routing services need GOOGLE_MAPS_SERVER_API_KEY. Ask the deployment owner to configure the separate server credential, or turn those services off.';
        }
        foreach (['restrictions_reviewed' => 'Confirm the key restrictions for the selected APIs.', 'terms_reviewed' => 'Confirm the provider terms, privacy, billing and quota review.'] as $key => $message) {
            if (! $values[$key]) {
                $errors['values.'.$key] = $message;
            }
        }

        return $errors;
    }

    private function reference(string $key, string $label): ?string
    {
        if (blank($key)) {
            return null;
        }

        // A keyed fingerprint identifies rotation without disclosing any key characters.
        return $label.' · '.substr(hash_hmac('sha256', $key, (string) config('app.key')), 0, 8);
    }

    public function observationKey(string $revision, string $capability): string
    {
        return 'fleet:google:'.$revision.':'.$capability.':observation';
    }

    public function snapshot(): array
    {
        $values = $this->values();
        $credentials = $this->credentials();
        $revision = hash('sha256', json_encode([$values, $credentials, hash_hmac('sha256', config('fleet.maps.api_key').'|'.config('fleet.maps.google_server_key'), (string) config('app.key'))], JSON_THROW_ON_ERROR));

        return ['values' => $values, 'credentials' => $credentials,
            'references' => ['browser' => $this->reference((string) config('fleet.maps.api_key'), 'Browser credential'), 'server' => $this->reference((string) config('fleet.maps.google_server_key'), 'Server credential')],
            'revision' => $revision,
            'capabilities' => collect(self::CAPABILITIES)->map(function ($title, $key) use ($values, $credentials, $revision) {
                $enabled = $this->evaluate($key, $values, $credentials);
                $status = match (true) {
                    ! $values['google'] => 'Google is off',
                    ! $values[$key] => 'Not selected',
                    ! $credentials[$key === 'display' ? 'browser' : 'server'] => 'Credential missing',
                    ! $values['display'] || ! $credentials['browser'] => 'Google display required',
                    ! $values['restrictions_reviewed'] || ! $values['terms_reviewed'] => 'Review required',
                    default => 'Configured · not verified',
                };

                return ['key' => $key, 'title' => $title, 'selected' => $values['google'] && $values[$key], 'enabled' => $enabled,
                    'status' => $status, 'observation' => $enabled ? Cache::get($this->observationKey($revision, $key)) : null];
            })->values()->all(),
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
