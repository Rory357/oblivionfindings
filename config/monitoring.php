<?php

$signingKeys = [];
$encodedSigningKeys = env('MONITORING_SIGNING_KEYS');

if (is_string($encodedSigningKeys) && $encodedSigningKeys !== '') {
    try {
        $decodedSigningKeys = json_decode($encodedSigningKeys, false, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        $decodedSigningKeys = null;
    }

    if ($decodedSigningKeys instanceof stdClass) {
        $candidateSigningKeys = [];

        foreach (get_object_vars($decodedSigningKeys) as $keyId => $encodedKey) {
            $decodedKey = is_string($encodedKey) ? base64_decode($encodedKey, true) : false;

            if ($keyId === '' || $decodedKey === false || strlen($decodedKey) !== SODIUM_CRYPTO_AUTH_KEYBYTES) {
                $candidateSigningKeys = null;

                break;
            }

            $candidateSigningKeys[$keyId] = $encodedKey;
        }

        if (is_array($candidateSigningKeys)) {
            $signingKeys = $candidateSigningKeys;
        }
    }
}

return [
    'queues' => [
        'events' => 'monitoring-events',
        'checks' => 'monitoring-checks',
        'discovery' => 'monitoring-discovery',
        'provider' => 'monitoring-provider',
        'topology' => 'monitoring-topology',
        'maintenance' => 'monitoring-maintenance',
        'orchestration' => 'monitoring',
    ],
    'contracts' => ['current' => 1, 'accepted' => [1]],
    'signing' => ['active_key_id' => env('MONITORING_SIGNING_KEY_ID'), 'keys' => $signingKeys],
    'egress' => [
        'connect_timeout_seconds' => 5,
        'response_timeout_seconds' => 15,
        'max_response_bytes' => 1048576,
        'deny_cidrs' => ['0.0.0.0/8', '127.0.0.0/8', '169.254.0.0/16', '224.0.0.0/4', '::/128', '::1/128', 'fe80::/10', 'ff00::/8'],
    ],
    'retention' => ['raw_days' => 14, 'hourly_days' => 180, 'daily_days' => 1825],
];
