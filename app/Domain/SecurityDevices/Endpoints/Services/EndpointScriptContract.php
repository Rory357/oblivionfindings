<?php

namespace App\Domain\SecurityDevices\Endpoints\Services;

use InvalidArgumentException;
use stdClass;

/**
 * Closed, side-effect-free catalogue contract. Validation is not publication,
 * current command authority, installed-runtime verification or execution.
 */
final class EndpointScriptContract
{
    public const CAPABILITY = 'endpoint.script.run';

    public const MAX_SCRIPT_BYTES = 262144;

    public const MAX_SAFE_INTEGER = 9007199254740991;

    public const MANIFEST_FIELDS = ['script_uuid', 'script_version', 'name', 'publisher_label', 'sha256', 'size_bytes', 'interpreter', 'interpreter_version', 'runtime_manifest_sha256', 'wrapper_sha256', 'architecture', 'parameter_schema', 'execution_policy'];

    public static function manifest(stdClass $m): void
    {
        self::keys($m, self::MANIFEST_FIELDS);
        self::require(self::uuid($m->script_uuid) && $m->script_version === 1);
        self::require(self::label($m->name, 1, 200) && self::label($m->publisher_label, 1, 200));
        self::require(self::digest($m->sha256) && self::integer($m->size_bytes, 1, self::MAX_SCRIPT_BYTES));
        self::require($m->interpreter === 'powershell7' && self::version($m->interpreter_version));
        self::require(self::digest($m->runtime_manifest_sha256) && self::digest($m->wrapper_sha256));
        self::require(in_array($m->architecture, ['amd64', 'arm64'], true));
        self::schema($m->parameter_schema);
        self::policy($m->execution_policy);
    }

    /** A valid dispatch still requires the canonical owner's current authority checks. */
    public static function dispatch(stdClass $c): void
    {
        self::keys($c, ['version', 'type', 'agent_uuid', 'command_uuid', 'attempt_uuid', 'assignment_fingerprint', 'capability', 'parameters', 'policy', 'technician', 'purpose', 'issued_at', 'expires_at']);
        self::require($c->version === 1 && $c->type === 'endpoint_script_run' && $c->capability === self::CAPABILITY);
        foreach (['agent_uuid', 'command_uuid', 'attempt_uuid'] as $key) {
            self::require(self::uuid($c->{$key}));
        }
        self::require(self::digest($c->assignment_fingerprint) && self::label($c->technician, 1, 200) && self::label($c->purpose, 1, 500));
        self::keys($c->parameters, ['script_uuid', 'script_version', 'manifest', 'inputs']);
        self::require($c->parameters->manifest instanceof stdClass);
        self::manifest($c->parameters->manifest);
        self::require($c->parameters->script_uuid === $c->parameters->manifest->script_uuid && $c->parameters->script_version === $c->parameters->manifest->script_version);
        self::inputs($c->parameters->manifest->parameter_schema, $c->parameters->inputs);
        self::keys($c->policy, ['version', 'enabled', 'consent_mode']);
        self::require(self::integer($c->policy->version, 1, self::MAX_SAFE_INTEGER) && $c->policy->enabled === true && in_array($c->policy->consent_mode, ['attended', 'unattended'], true));
        $issued = self::instant($c->issued_at);
        $expires = self::instant($c->expires_at);
        self::require($expires > $issued && $expires - $issued <= 3600);
        // Match the canonical signer's encoding, including escaped Unicode.
        self::require(strlen(json_encode($c, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)) <= 32768);
    }

    public static function claimRequest(stdClass $x, stdClass $c): void
    {
        self::dispatch($c);
        $attended = $c->policy->consent_mode === 'attended';
        self::keys($x, ['command_uuid', 'payload_hash', 'artifact_sha256', 'runtime_manifest_sha256', 'boot_id', ...($attended ? ['consent'] : [])]);
        self::require($x->command_uuid === $c->command_uuid && self::digest($x->payload_hash)
            && $x->artifact_sha256 === $c->parameters->manifest->sha256
            && $x->runtime_manifest_sha256 === $c->parameters->manifest->runtime_manifest_sha256 && self::uuid($x->boot_id));
        if ($attended) {
            self::keys($x->consent, ['desktop_session', 'consent_nonce']);
            self::require(self::uuid($x->consent->desktop_session) && self::digest($x->consent->consent_nonce));
        }
    }

    public static function claim(stdClass $x, stdClass $c, string $hash, string $boot, ?string $desktop, ?string $nonce, string $now): void
    {
        self::dispatch($c);
        self::keys($x, ['version', 'type', 'agent_uuid', 'command_uuid', 'attempt_uuid', 'assignment_fingerprint', 'payload_hash', 'policy_version', 'consent_mode', 'artifact_sha256', 'runtime_manifest_sha256', 'boot_id', 'desktop_session', 'consent_nonce', 'issued_at', 'expires_at']);
        self::binding($x, $c, $hash, 'endpoint_script_claim');
        $m = $c->parameters->manifest;
        self::require(self::uuid($boot) && $x->boot_id === $boot && $x->policy_version === $c->policy->version && $x->consent_mode === $c->policy->consent_mode);
        self::require($x->artifact_sha256 === $m->sha256 && $x->runtime_manifest_sha256 === $m->runtime_manifest_sha256);
        if ($c->policy->consent_mode === 'attended') {
            self::require(self::uuid($desktop) && self::digest($nonce) && $x->desktop_session === $desktop && $x->consent_nonce === $nonce);
        } else {
            self::require($desktop === null && $nonce === null && $x->desktop_session === null && $x->consent_nonce === null);
        }
        $issued = self::instant($x->issued_at);
        $expires = self::instant($x->expires_at);
        $current = self::instant($now);
        self::require($issued >= self::instant($c->issued_at) && $issued <= $current + 1 && $expires > $current && $expires > $issued && $expires - $issued <= 30 && $expires <= self::instant($c->expires_at));
    }

    public static function cancellation(stdClass $x, stdClass $c, string $hash, string $now): void
    {
        self::dispatch($c);
        self::keys($x, ['version', 'type', 'agent_uuid', 'command_uuid', 'attempt_uuid', 'assignment_fingerprint', 'payload_hash', 'issued_at', 'expires_at']);
        self::binding($x, $c, $hash, 'endpoint_script_cancel');
        $issued = self::instant($x->issued_at);
        $expires = self::instant($x->expires_at);
        $current = self::instant($now);
        // A still-running attempt can be cancelled after its original dispatch expires.
        self::require($issued >= self::instant($c->issued_at) && $issued <= $current + 1 && $expires > $current && $expires > $issued && $expires - $issued <= 3600);
    }

    public static function result(stdClass $x, stdClass $c, string $hash): void
    {
        self::dispatch($c);
        self::keys($x, ['command_uuid', 'payload_hash', 'status', 'mutation_started', 'reason_code', 'exit_code', 'boot_id', 'output_sha256', 'output_bytes', 'output_truncated']);
        self::require(self::digest($hash) && $x->command_uuid === $c->command_uuid && $x->payload_hash === $hash && self::uuid($x->boot_id));
        self::require(is_bool($x->mutation_started) && is_bool($x->output_truncated) && is_string($x->status) && is_string($x->reason_code));
        $policy = $c->parameters->manifest->execution_policy;
        self::require(self::integer($x->output_bytes, 0, $policy->output_bytes));
        self::require($x->output_bytes === 0 ? $x->output_sha256 === null : self::digest($x->output_sha256));
        self::require(! $x->output_truncated || $x->mutation_started);
        self::require($x->exit_code === null || ($x->mutation_started && self::integer($x->exit_code, 0, 4294967295)));
        if (! $x->mutation_started) {
            self::require($x->output_bytes === 0 && $x->output_sha256 === null && ! $x->output_truncated);
        }
        if (in_array($x->status, ['declined', 'cancelled', 'expired'], true)) {
            self::require(! $x->mutation_started && $x->exit_code === null && $x->reason_code === $x->status);

            return;
        }
        if ($x->status === 'uncertain') {
            self::require(in_array($x->reason_code, ['interrupted', 'state_unavailable', 'timed_out', 'cancellation_requested'], true));
            if (in_array($x->reason_code, ['interrupted', 'state_unavailable'], true)) {
                self::require($x->exit_code === null);
            } else {
                self::require($x->mutation_started);
            }

            return;
        }
        if (! $x->mutation_started) {
            self::require($x->status === 'failed' && $x->exit_code === null && in_array($x->reason_code, ['artifact_invalid', 'interpreter_unavailable', 'input_invalid', 'policy_unavailable', 'consent_lost', 'operation_failed'], true));

            return;
        }
        self::require($x->exit_code !== null);
        $success = in_array($x->exit_code, $policy->success_exit_codes, true);
        if (! $success) {
            self::require($x->status === 'failed' && $x->reason_code === 'exit_failed');

            return;
        }
        self::require($x->reason_code === 'completed' && $x->status === ($policy->expected_observation_schema === null ? 'completed' : 'awaiting_observation'));
    }

    private static function binding(stdClass $x, stdClass $c, string $hash, string $type): void
    {
        self::require($x->version === 1 && $x->type === $type && self::digest($hash) && $x->payload_hash === $hash);
        foreach (['agent_uuid', 'command_uuid', 'attempt_uuid', 'assignment_fingerprint'] as $field) {
            self::require($x->{$field} === $c->{$field});
        }
    }

    private static function instant(mixed $text): int
    {
        self::require(is_string($text) && preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/', $text) === 1 && ! str_starts_with($text, '0000'));
        $time = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $text, new \DateTimeZone('UTC'));
        self::require($time !== false && $time->format('Y-m-d\TH:i:s\Z') === $text);

        return $time->getTimestamp();
    }

    public static function schema(mixed $schema): void
    {
        self::require(is_array($schema) && array_is_list($schema) && count($schema) <= 16);
        $seen = [];
        foreach ($schema as $field) {
            self::keys($field, ['name', 'type', 'required', 'constraints']);
            self::require(is_string($field->name) && preg_match('/\A[A-Za-z][A-Za-z0-9_]{0,31}\z/', $field->name) === 1);
            $key = strtolower($field->name);
            // PowerShell parameter binding is case-insensitive. Common parameters
            // belong to the fixed wrapper and may not be catalogue inputs.
            self::require(! isset($seen[$key]) && ! in_array($key, ['verbose', 'debug', 'erroraction', 'warningaction', 'informationaction', 'progressaction', 'errorvariable', 'warningvariable', 'informationvariable', 'outvariable', 'outbuffer', 'pipelinevariable', 'whatif', 'confirm'], true));
            $seen[$key] = true;
            self::require(is_bool($field->required));
            $c = $field->constraints;
            self::require(is_string($field->type) && in_array($field->type, ['boolean', 'integer', 'string', 'enum'], true));
            switch ($field->type) {
                case 'boolean':
                    self::keys($c, []);
                    break;
                case 'integer':
                    self::keys($c, ['minimum', 'maximum']);
                    self::require(self::integer($c->minimum, -self::MAX_SAFE_INTEGER, self::MAX_SAFE_INTEGER) && self::integer($c->maximum, $c->minimum, self::MAX_SAFE_INTEGER));
                    break;
                case 'string':
                    self::keys($c, ['min_length', 'max_length']);
                    self::require(self::integer($c->min_length, 0, 1024) && self::integer($c->max_length, $c->min_length, 1024));
                    break;
                case 'enum':
                    self::keys($c, ['values']);
                    self::require(is_array($c->values) && array_is_list($c->values) && count($c->values) >= 1 && count($c->values) <= 32);
                    $values = [];
                    foreach ($c->values as $value) {
                        self::require(self::label($value, 1, 1024) && ! in_array($value, $values, true));
                        $values[] = $value;
                    }
                    break;
                default:
                    self::require(false);
            }
        }
    }

    public static function inputs(mixed $schema, mixed $inputs): void
    {
        self::schema($schema);
        self::require($inputs instanceof stdClass && strlen(self::encode($inputs)) <= 8192);
        $known = [];
        foreach ($schema as $field) {
            $name = $field->name;
            $known[] = $name;
            if (! property_exists($inputs, $name)) {
                self::require(! $field->required);

                continue;
            }
            $value = $inputs->{$name};
            $c = $field->constraints;
            self::require(match ($field->type) {
                'boolean' => is_bool($value),
                'integer' => self::integer($value, $c->minimum, $c->maximum),
                'string' => self::label($value, $c->min_length, $c->max_length),
                'enum' => is_string($value) && in_array($value, $c->values, true),
                default => false,
            });
        }
        self::require(array_diff(array_keys(get_object_vars($inputs)), $known) === []);
    }

    public static function policy(mixed $p): void
    {
        self::keys($p, ['runtime_seconds', 'output_bytes', 'output_retention_hours', 'network_mode', 'timeout_mode', 'success_exit_codes', 'expected_observation_schema']);
        self::require(self::integer($p->runtime_seconds, 1, 900) && self::integer($p->output_bytes, 0, 65536) && self::integer($p->output_retention_hours, 1, 168));
        self::require($p->network_mode === 'reviewed_network' && in_array($p->timeout_mode, ['report_only', 'terminate_owned_job'], true));
        self::require(is_array($p->success_exit_codes) && array_is_list($p->success_exit_codes) && count($p->success_exit_codes) >= 1 && count($p->success_exit_codes) <= 16);
        $seen = [];
        foreach ($p->success_exit_codes as $code) {
            self::require(self::integer($code, 0, 4294967295) && ! in_array($code, $seen, true));
            $seen[] = $code;
        }
        self::require($p->expected_observation_schema === null || self::digest($p->expected_observation_schema));
    }

    /** Exact retained UTF-8 bytes. Storage/ownership/retention are separate owners. */
    public static function output(stdClass $header, string $bytes, stdClass $result, stdClass $command, string $payloadHash): void
    {
        self::result($result, $command, $payloadHash);
        self::keys($header, ['command_uuid', 'payload_hash', 'sha256', 'size_bytes', 'truncated']);
        self::require($result->mutation_started && $header->command_uuid === $command->command_uuid && $header->payload_hash === $payloadHash);
        self::require($header->sha256 === $result->output_sha256 && $header->size_bytes === $result->output_bytes && $header->truncated === $result->output_truncated);
        self::require(strlen($bytes) === $header->size_bytes && mb_check_encoding($bytes, 'UTF-8'));
        self::require($bytes === '' ? $header->sha256 === null : hash_equals(hash('sha256', $bytes), $header->sha256));
    }

    /** Exact script bytes, not a normalized/re-encoded substitute. */
    public static function artifact(stdClass $manifest, string $bytes): void
    {
        self::manifest($manifest);
        self::require(strlen($bytes) === $manifest->size_bytes && mb_check_encoding($bytes, 'UTF-8') && ! str_starts_with($bytes, "\xEF\xBB\xBF") && ! str_contains($bytes, "\0"));
        self::require(hash_equals($manifest->sha256, hash('sha256', $bytes)));
    }

    /** Original-byte runtime manifest digest. Complete installed tree checks are separate. */
    public static function runtime(stdClass $manifest, string $raw): stdClass
    {
        self::manifest($manifest);
        self::require(hash_equals($manifest->runtime_manifest_sha256, hash('sha256', $raw)));
        $r = self::decode($raw, 1048576);
        self::keys($r, ['version', 'interpreter', 'interpreter_version', 'architecture', 'entrypoint', 'files']);
        self::require($r->version === 1 && $r->interpreter === $manifest->interpreter && $r->interpreter_version === $manifest->interpreter_version && $r->architecture === $manifest->architecture && $r->entrypoint === 'pwsh.exe');
        self::require(is_array($r->files) && array_is_list($r->files) && count($r->files) >= 2 && count($r->files) <= 4096);
        $prior = '';
        $paths = [];
        $total = 0;
        $entry = false;
        $wrapper = false;
        foreach ($r->files as $file) {
            self::keys($file, ['path', 'size_bytes', 'sha256']);
            self::require(self::path($file->path) && self::integer($file->size_bytes, 1, 536870912) && self::digest($file->sha256));
            $key = strtolower($file->path);
            self::require(strcmp($prior, $key) < 0);
            $parts = explode('/', $key);
            array_pop($parts);
            while ($parts !== []) {
                self::require(! isset($paths[implode('/', $parts)]));
                array_pop($parts);
            }
            $paths[$key] = true;
            $prior = $key;
            $total += $file->size_bytes;
            self::require($total <= 2147483648);
            $entry = $entry || $file->path === 'pwsh.exe';
            $wrapper = $wrapper || ($file->path === 'obli-script-wrapper.ps1' && hash_equals($file->sha256, $manifest->wrapper_sha256));
        }
        self::require($entry && $wrapper);

        return $r;
    }

    public static function decode(string $raw, int $maximum = 32768): stdClass
    {
        self::require(strlen($raw) > 0 && strlen($raw) <= $maximum && mb_check_encoding($raw, 'UTF-8'));
        try {
            $value = json_decode($raw, false, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidArgumentException('invalid_script_contract');
        }
        self::require($value instanceof stdClass);
        // Decode keys before comparison so escaped duplicate spellings also reject.
        preg_match_all('/"(?:\\\\.|[^"\\\\])*"|[{}\[\],:]|[^\s{}\[\],:]+/s', $raw, $tokens);
        $stack = [];
        foreach ($tokens[0] as $token) {
            if ($token === '{' || $token === '[') {
                $stack[] = ['object' => $token === '{', 'key' => $token === '{', 'seen' => []];

                continue;
            }
            if ($token === '}' || $token === ']') {
                array_pop($stack);

                continue;
            }
            $i = count($stack) - 1;
            if ($i < 0 || ! $stack[$i]['object']) {
                continue;
            }
            if ($token === ',') {
                $stack[$i]['key'] = true;
            } elseif ($stack[$i]['key'] && str_starts_with($token, '"')) {
                $key = ':'.json_decode($token, false, 32, JSON_THROW_ON_ERROR);
                self::require(! isset($stack[$i]['seen'][$key]));
                $stack[$i]['seen'][$key] = true;
                $stack[$i]['key'] = false;
            }
        }

        return $value;
    }

    public static function encode(mixed $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException) {
            throw new InvalidArgumentException('invalid_script_contract');
        }
    }

    private static function keys(mixed $value, array $keys): void
    {
        self::require($value instanceof stdClass);
        $actual = array_keys(get_object_vars($value));
        sort($actual);
        sort($keys);
        self::require($actual === $keys);
    }

    private static function path(mixed $path): bool
    {
        if (! is_string($path) || strlen($path) > 240 || preg_match('/\A[A-Za-z0-9_.-]+(?:\/[A-Za-z0-9_.-]+)*\z/', $path) !== 1) {
            return false;
        }
        foreach (explode('/', $path) as $part) {
            if ($part === '.' || $part === '..' || str_ends_with($part, '.') || preg_match('/\A(?:CON|PRN|AUX|NUL|COM[0-9]|LPT[0-9])(?:\.|\z)/i', $part)) {
                return false;
            }
        }

        return true;
    }

    private static function version(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A7\.(?:0|[1-9][0-9]{0,2})\.(?:0|[1-9][0-9]{0,3})\z/', $value) === 1;
    }

    private static function uuid(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $value) === 1;
    }

    private static function digest(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[0-9a-f]{64}\z/', $value) === 1;
    }

    private static function integer(mixed $value, int $min, int $max): bool
    {
        return is_int($value) && $value >= $min && $value <= $max;
    }

    private static function label(mixed $value, int $min, int $max): bool
    {
        return is_string($value) && mb_check_encoding($value, 'UTF-8') && mb_strlen($value, 'UTF-8') >= $min && mb_strlen($value, 'UTF-8') <= $max && preg_match('/[\p{Cc}\p{Cf}\x{FFFD}]/u', $value) === 0;
    }

    private static function require(bool $condition): void
    {
        if (! $condition) {
            throw new InvalidArgumentException('invalid_script_contract');
        }
    }
}
