<?php

namespace App\Domain\SecurityDevices\Endpoints\Services;

use InvalidArgumentException;
use stdClass;

/** Canonical command intent. The browser never supplies a manifest or runtime path. */
final class EndpointScriptRequestParameters
{
    public static function inputs(array $parameters): stdClass
    {
        $keys = ['script_uuid', 'script_version', 'content_hash', 'policy_version', 'inputs_json'];
        if (count($parameters) !== count($keys) || array_diff(array_keys($parameters), $keys) !== []
            || ! is_string($parameters['script_uuid'] ?? null)
            || ! preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $parameters['script_uuid'])
            || ($parameters['script_version'] ?? null) !== 1
            || ! is_string($parameters['content_hash'] ?? null) || ! preg_match('/\A[a-f0-9]{64}\z/', $parameters['content_hash'])
            || ! is_int($parameters['policy_version'] ?? null) || $parameters['policy_version'] < 1 || $parameters['policy_version'] > 4294967295
            || ! is_string($parameters['inputs_json'] ?? null)) {
            throw new InvalidArgumentException('Invalid reviewed script request.');
        }

        // Keep original JSON bytes in encrypted_parameters/idempotency hashing.
        // Decode only for typed schema validation and signed native transport.
        return EndpointScriptContract::decode($parameters['inputs_json'], 8192);
    }
}
