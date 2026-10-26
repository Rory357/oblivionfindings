<?php

namespace Tests\Unit\SecurityDevices;

use App\Domain\SecurityDevices\Endpoints\Services\EndpointScriptRequestParameters;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class EndpointScriptRequestParametersTest extends TestCase
{
    private function parameters(): array
    {
        return ['script_uuid' => '11111111-1111-4111-8111-111111111111', 'script_version' => 1,
            'content_hash' => str_repeat('a', 64), 'policy_version' => 1,
            'inputs_json' => ' { "IncludeDetails": false, "Label": "  Māori 😀  " } '];
    }

    public function test_preserves_original_private_input_bytes_and_types(): void
    {
        $parameters = $this->parameters();
        $original = $parameters;
        $inputs = EndpointScriptRequestParameters::inputs($parameters);
        self::assertSame($original, $parameters);
        self::assertFalse($inputs->IncludeDetails);
        self::assertSame('  Māori 😀  ', $inputs->Label);
        self::assertSame([], get_object_vars(EndpointScriptRequestParameters::inputs([...$parameters, 'inputs_json' => '{}'])));
    }

    public function test_rejects_unreviewed_identity_shapes_and_ambiguous_input_documents(): void
    {
        $valid = $this->parameters();
        $invalid = [
            [...$valid, 'script_version' => '1'], [...$valid, 'script_version' => 2],
            [...$valid, 'policy_version' => 0], [...$valid, 'policy_version' => '1'],
            [...$valid, 'policy_version' => 4294967296], [...$valid, 'content_hash' => str_repeat('A', 64)],
            [...$valid, 'script_uuid' => '11111111-1111-0111-8111-111111111111'],
            [...$valid, 'manifest' => []], array_diff_key($valid, ['inputs_json' => true]),
        ];
        foreach (['[]', 'null', '{"a":1,"a":2}', '{"a":1,"\\u0061":2}', '{}{}', '{"a":"\\ud800"}', '{"a":"'.str_repeat('x', 8192).'"}'] as $json) {
            $invalid[] = [...$valid, 'inputs_json' => $json];
        }
        foreach ($invalid as $parameters) {
            try {
                EndpointScriptRequestParameters::inputs($parameters);
                self::fail('Invalid script request accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_registered_capability_requires_independent_review_and_keeps_inputs_private(): void
    {
        $config = require __DIR__.'/../../../config/security_devices.php';
        $definition = $config['command_capabilities']['endpoint.script.run'];
        self::assertSame('control', $definition['level']);
        self::assertSame('high', $definition['risk']);
        foreach (['requires_mfa', 'requires_step_up', 'requires_approval', 'requires_fresh_observation'] as $key) {
            self::assertTrue($definition[$key]);
        }
        self::assertFalse($definition['allows_break_glass']);
        self::assertSame(900, $definition['expires_after_seconds']);
        self::assertSame('reconcile_before_retry', $definition['retry_policy']);
        self::assertSame(['script_uuid', 'script_version'], $definition['safe_summary_fields']);
        self::assertSame(array_keys($this->parameters()), array_keys($definition['parameters']));
    }
}
