<?php

namespace Tests\Unit\SecurityDevices;

use App\Domain\SecurityDevices\Endpoints\Services\EndpointCommandSigner;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointScriptContract as Contract;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use stdClass;

final class EndpointScriptContractTest extends TestCase
{
    private function fixture(): array
    {
        $bytes = "# Synthetic contract fixture; never executed.\n";
        $runtime = (object) [
            'version' => 1, 'interpreter' => 'powershell7', 'interpreter_version' => '7.6.0', 'architecture' => 'amd64', 'entrypoint' => 'pwsh.exe',
            'files' => [
                (object) ['path' => 'obli-script-wrapper.ps1', 'size_bytes' => 10, 'sha256' => str_repeat('b', 64)],
                (object) ['path' => 'pwsh.exe', 'size_bytes' => 20, 'sha256' => str_repeat('c', 64)],
            ],
        ];
        $manifest = (object) [
            'script_uuid' => '11111111-1111-4111-8111-111111111111', 'script_version' => 1,
            'name' => 'Synthetic reviewed diagnostic', 'publisher_label' => 'Synthetic publisher',
            'sha256' => hash('sha256', $bytes), 'size_bytes' => strlen($bytes),
            'interpreter' => 'powershell7', 'interpreter_version' => '7.6.0',
            'runtime_manifest_sha256' => hash('sha256', Contract::encode($runtime)), 'wrapper_sha256' => str_repeat('b', 64), 'architecture' => 'amd64',
            'parameter_schema' => [
                (object) ['name' => 'Enabled', 'type' => 'boolean', 'required' => true, 'constraints' => (object) []],
                (object) ['name' => 'Count', 'type' => 'integer', 'required' => true, 'constraints' => (object) ['minimum' => -2, 'maximum' => 10]],
                (object) ['name' => 'Label', 'type' => 'string', 'required' => false, 'constraints' => (object) ['min_length' => 0, 'max_length' => 10]],
                (object) ['name' => 'Mode', 'type' => 'enum', 'required' => true, 'constraints' => (object) ['values' => ['Inspect', 'Report']]],
            ],
            'execution_policy' => (object) ['runtime_seconds' => 60, 'output_bytes' => 4096, 'output_retention_hours' => 24, 'network_mode' => 'reviewed_network', 'timeout_mode' => 'report_only', 'success_exit_codes' => [0], 'expected_observation_schema' => null],
        ];

        return [$manifest, $runtime, $bytes, (object) ['Enabled' => false, 'Count' => -2, 'Label' => '😀 Māori', 'Mode' => 'Inspect']];
    }

    private function copy(mixed $value): mixed
    {
        return json_decode(Contract::encode($value));
    }

    private function rejects(callable $operation): void
    {
        try {
            $operation();
            self::fail('Invalid contract accepted.');
        } catch (InvalidArgumentException $error) {
            self::assertSame('invalid_script_contract', $error->getMessage());
        }
    }

    public function test_original_artifact_runtime_and_typed_values_are_preserved(): void
    {
        [$m, $r, $bytes, $inputs] = $this->fixture();
        $before = Contract::encode([$m, $r, $inputs]);
        Contract::manifest($m);
        Contract::artifact($m, $bytes);
        Contract::inputs($m->parameter_schema, $inputs);
        self::assertEquals($r, Contract::runtime($m, Contract::encode($r)));
        unset($inputs->Label);
        Contract::inputs($m->parameter_schema, $inputs);
        $inputs->Label = '😀 Māori';
        self::assertEquals(json_decode($before), [$m, $r, $inputs]);
        Contract::inputs([], (object) []);
    }

    public function test_raw_json_rejects_ambiguous_duplicate_unicode_and_budget_cases(): void
    {
        $valid = '{"label":"quote: \" and slash: \\\\","nested":[{"a":1},{"a":2}],"empty":{}}';
        self::assertSame('quote: " and slash: \\', Contract::decode($valid)->label);
        foreach ([
            '{"a":1,"a":2}', '{"a":1,"\u0061":2}', '{"x":{"a":1,"a":2}}',
            '{"x":[{"a":1,"\u0061":2}]}', '{"x":1}{"y":2}', '[]', 'null',
            '{"a":"\ud800"}', "{\"a\":\"\xFF\"}", '{"x":NaN}', "\xEF\xBB\xBF{}",
        ] as $raw) {
            $this->rejects(fn () => Contract::decode($raw));
        }
        self::assertInstanceOf(stdClass::class, Contract::decode('{}', 2));
        $this->rejects(fn () => Contract::decode('{}', 1));
        $this->rejects(fn () => Contract::decode('{"x":'.str_repeat('[', 35).'0'.str_repeat(']', 35).'}'));
    }

    public function test_manifest_requires_closed_exact_immutable_metadata(): void
    {
        [$m] = $this->fixture();
        foreach ([
            ['script_version', 2], ['script_version', '1'], ['size_bytes', 0], ['size_bytes', 262145], ['sha256', str_repeat('A', 64)],
            ['script_uuid', '11111111-1111-1111-1111-111111111111'], ['name', "Name\n"], ['publisher_label', "\u{202E}publisher"],
            ['name', str_repeat('😀', 201)], ['interpreter', 'powershell.exe'], ['interpreter_version', 'latest'],
            ['interpreter_version', '7.06.0'], ['interpreter_version', '7.6.0-preview.1'], ['architecture', 'x86'],
            ['parameter_schema', (object) []], ['execution_policy', null],
        ] as [$key, $value]) {
            $changed = $this->copy($m);
            $changed->{$key} = $value;
            $this->rejects(fn () => Contract::manifest($changed));
        }
        foreach (Contract::MANIFEST_FIELDS as $key) {
            $changed = $this->copy($m);
            unset($changed->{$key});
            $this->rejects(fn () => Contract::manifest($changed));
        }
        $m->command = 'arbitrary text';
        $this->rejects(fn () => Contract::manifest($m));
    }

    public function test_parameters_do_not_coerce_or_accept_case_aliases_or_unknown_values(): void
    {
        [$m, , , $inputs] = $this->fixture();
        foreach ([
            ['Enabled', 0], ['Enabled', 'false'], ['Enabled', null], ['Count', '2'], ['Count', 2.5], ['Count', 11],
            ['Label', str_repeat('😀', 11)], ['Label', "a\nb"], ['Label', "\u{FFFD}"],
            ['Mode', 'inspect'], ['Mode', ['Inspect']], ['Other', true], ['enabled', true],
        ] as [$key, $value]) {
            $changed = $this->copy($inputs);
            $changed->{$key} = $value;
            $this->rejects(fn () => Contract::inputs($m->parameter_schema, $changed));
        }
        unset($inputs->Enabled);
        $this->rejects(fn () => Contract::inputs($m->parameter_schema, $inputs));
        $this->rejects(fn () => Contract::inputs([], []));
        $schema = [(object) ['name' => 'A', 'type' => 'string', 'required' => true, 'constraints' => (object) ['min_length' => 0, 'max_length' => 1024]]];
        $values = (object) [];
        for ($i = 0; $i < 9; $i++) {
            $schema[$i] = $this->copy($schema[0]);
            $schema[$i]->name = 'A'.$i;
            $values->{'A'.$i} = str_repeat('x', 1024);
        }
        $this->rejects(fn () => Contract::inputs($schema, $values));
    }

    public function test_schema_is_closed_bounded_and_case_insensitive_names_are_unique(): void
    {
        [$m] = $this->fixture();
        foreach ([
            ['name', 'Verbose'], ['name', 'A-B'], ['name', '1Name'], ['type', 'path'], ['required', 1], ['constraints', []],
        ] as [$key, $value]) {
            $schema = $this->copy($m->parameter_schema);
            $schema[0]->{$key} = $value;
            $this->rejects(fn () => Contract::schema($schema));
        }
        $schema = $this->copy($m->parameter_schema);
        $schema[1]->name = 'enabled';
        $this->rejects(fn () => Contract::schema($schema));
        $schema = $this->copy($m->parameter_schema);
        $schema[3]->constraints->values = ['Inspect', 'Inspect'];
        $this->rejects(fn () => Contract::schema($schema));
        $schema[3]->constraints->values = [];
        $this->rejects(fn () => Contract::schema($schema));
        $schema = $this->copy($m->parameter_schema);
        $schema[1]->constraints->minimum = -9007199254740992;
        $this->rejects(fn () => Contract::schema($schema));
        $schema[1]->constraints->minimum = 11;
        $this->rejects(fn () => Contract::schema($schema));
    }

    public function test_policy_never_implies_network_isolation_or_unbounded_execution(): void
    {
        [$m] = $this->fixture();
        foreach ([
            ['runtime_seconds', 0], ['runtime_seconds', 901], ['output_bytes', -1], ['output_bytes', 65537],
            ['output_retention_hours', 169], ['network_mode', 'deny'], ['timeout_mode', 'kill_pid'],
            ['success_exit_codes', [0, 0]], ['success_exit_codes', []], ['success_exit_codes', [4294967296]],
            ['success_exit_codes', ['0']], ['expected_observation_schema', 'exit_zero'],
        ] as [$key, $value]) {
            $p = $this->copy($m->execution_policy);
            $p->{$key} = $value;
            $this->rejects(fn () => Contract::policy($p));
        }
        $p = $m->execution_policy;
        $p->timeout_mode = 'terminate_owned_job';
        $p->success_exit_codes = [0, 4294967295];
        $p->output_bytes = 0;
        $p->expected_observation_schema = str_repeat('d', 64);
        Contract::policy($p);
        self::assertSame(0, $p->output_bytes);
    }

    public function test_artifact_rejects_substitution_bom_nul_and_invalid_utf8_even_with_matching_hash(): void
    {
        [$m, , $bytes] = $this->fixture();
        $this->rejects(fn () => Contract::artifact($m, $bytes.' '));
        foreach (["\xEF\xBB\xBF".$bytes, $bytes."\0", $bytes."\xFF", ''] as $bytes) {
            $m->size_bytes = strlen($bytes);
            $m->sha256 = hash('sha256', $bytes);
            $this->rejects(fn () => Contract::artifact($m, $bytes));
        }
    }

    public function test_runtime_rejects_aliases_traversal_missing_wrapper_and_bound_identity(): void
    {
        [$m, $runtime] = $this->fixture();
        foreach (['../escape', 'C:/pwsh.exe', 'a\\b', 'a//b', 'NUL.txt', 'sub/COM1.dll', 'x.', '/pwsh.exe', 'a:b'] as $path) {
            $r = $this->copy($runtime);
            $r->files[0]->path = $path;
            $raw = Contract::encode($r);
            $m->runtime_manifest_sha256 = hash('sha256', $raw);
            $this->rejects(fn () => Contract::runtime($m, $raw));
        }
        foreach (['entrypoint' => 'other.exe', 'architecture' => 'arm64', 'interpreter_version' => '7.6.1'] as $key => $value) {
            $r = $this->copy($runtime);
            $r->{$key} = $value;
            $raw = Contract::encode($r);
            $m->runtime_manifest_sha256 = hash('sha256', $raw);
            $this->rejects(fn () => Contract::runtime($m, $raw));
        }
        $r = $this->copy($runtime);
        $r->files[0]->sha256 = str_repeat('d', 64);
        $raw = Contract::encode($r);
        $m->runtime_manifest_sha256 = hash('sha256', $raw);
        $this->rejects(fn () => Contract::runtime($m, $raw));
        $r = $this->copy($runtime);
        $r->files[] = (object) ['path' => 'PWSH.EXE', 'size_bytes' => 10, 'sha256' => str_repeat('a', 64)];
        $raw = Contract::encode($r);
        $m->runtime_manifest_sha256 = hash('sha256', $raw);
        $this->rejects(fn () => Contract::runtime($m, $raw));
        $this->rejects(fn () => Contract::runtime($m, $raw.' '));
    }

    public function test_parameter_type_must_be_a_string_even_when_optional_input_is_omitted(): void
    {
        [$m] = $this->fixture();
        foreach ([true, false, 0, 1, null, [], (object) [], 1.5] as $type) {
            foreach ([true, false] as $required) {
                $m->parameter_schema = [(object) ['name' => 'Flag', 'type' => $type, 'required' => $required, 'constraints' => (object) []]];
                $this->rejects(fn () => Contract::manifest($m));
                $this->rejects(fn () => Contract::inputs($m->parameter_schema, (object) []));
                $this->rejects(fn () => Contract::inputs($m->parameter_schema, (object) ['Flag' => true]));
            }
        }
    }

    private function command(): stdClass
    {
        [$m, , , $inputs] = $this->fixture();

        return (object) [
            'version' => 1, 'type' => 'endpoint_script_run',
            'agent_uuid' => '22222222-2222-4222-8222-222222222222',
            'command_uuid' => '33333333-3333-4333-8333-333333333333',
            'attempt_uuid' => '44444444-4444-4444-8444-444444444444',
            'assignment_fingerprint' => str_repeat('e', 64), 'capability' => Contract::CAPABILITY,
            'parameters' => (object) ['script_uuid' => $m->script_uuid, 'script_version' => 1, 'manifest' => $m, 'inputs' => $inputs],
            'policy' => (object) ['version' => 1, 'enabled' => true, 'consent_mode' => 'attended'],
            'technician' => 'Synthetic technician 😀', 'purpose' => 'Inspect the approved synthetic fixture',
            'issued_at' => '2026-10-20T01:00:00Z', 'expires_at' => '2026-10-20T01:10:00Z',
        ];
    }

    public function test_final_claim_request_is_closed_typed_and_bound_to_reviewed_material(): void
    {
        $command = $this->command();
        $command->policy->consent_mode = 'attended';
        $input = (object) ['command_uuid' => $command->command_uuid, 'payload_hash' => str_repeat('e', 64),
            'artifact_sha256' => $command->parameters->manifest->sha256,
            'runtime_manifest_sha256' => $command->parameters->manifest->runtime_manifest_sha256,
            'boot_id' => '22222222-2222-4222-8222-222222222222',
            'consent' => (object) ['desktop_session' => '33333333-3333-4333-8333-333333333333', 'consent_nonce' => str_repeat('c', 64)]];
        Contract::claimRequest($input, $command);
        self::assertTrue(true);
        foreach (['command_uuid' => $input->boot_id, 'payload_hash' => null, 'artifact_sha256' => str_repeat('f', 64),
            'runtime_manifest_sha256' => str_repeat('f', 64), 'boot_id' => 123, 'consent' => null, 'extra' => true] as $key => $value) {
            $bad = $this->copy($input);
            $bad->{$key} = $value;
            $this->rejects(fn () => Contract::claimRequest($bad, $command));
        }
        foreach (['desktop_session' => '', 'consent_nonce' => str_repeat('C', 64), 'extra' => false] as $key => $value) {
            $bad = $this->copy($input);
            $bad->consent->{$key} = $value;
            $this->rejects(fn () => Contract::claimRequest($bad, $command));
        }
        $command->policy->consent_mode = 'unattended';
        $this->rejects(fn () => Contract::claimRequest($input, $command));
        unset($input->consent);
        Contract::claimRequest($input, $command);
        self::assertTrue(true);
        $command->policy->consent_mode = 'attended';
        $this->rejects(fn () => Contract::claimRequest($input, $command));
    }

    public function test_dispatch_binds_exact_manifest_identity_and_closed_time_policy(): void
    {
        $c = $this->command();
        Contract::dispatch($c);
        self::assertSame(Contract::CAPABILITY, $c->capability);
        foreach ([
            ['issued_at', '0000-01-01T00:00:00Z'], ['issued_at', '2026-02-30T01:00:00Z'],
            ['expires_at', '2026-10-20T01:00:00Z'], ['expires_at', '2026-10-20T02:00:01Z'],
            ['issued_at', '2026-10-20T01:00:00.000Z'], ['issued_at', '2026-10-20T01:00:00+00:00'],
            ['agent_uuid', 'foreign'], ['version', true], ['capability', 'endpoint.task.run'],
            ['purpose', "unsafe\npurpose"], ['technician', null], ['parameters', []],
        ] as [$key, $value]) {
            $c = $this->command();
            $c->{$key} = $value;
            $this->rejects(fn () => Contract::dispatch($c));
        }
        $c = $this->command();
        $c->parameters->script_uuid = $c->agent_uuid;
        $this->rejects(fn () => Contract::dispatch($c));
        $c = $this->command();
        $c->parameters->script_version = '1';
        $this->rejects(fn () => Contract::dispatch($c));
        foreach (['version' => 0, 'enabled' => false, 'consent_mode' => 'none'] as $key => $value) {
            $c = $this->command();
            $c->policy->{$key} = $value;
            $this->rejects(fn () => Contract::dispatch($c));
        }
        $c = $this->command();
        $c->parameters->manifest->parameter_schema[3]->constraints->values = array_map(fn ($i) => $i.str_repeat('😀', 1000), range(1, 32));
        $c->parameters->inputs->Mode = $c->parameters->manifest->parameter_schema[3]->constraints->values[0];
        $this->rejects(fn () => Contract::dispatch($c));
    }

    public function test_canonical_signer_exports_original_nested_json_for_native_receiver(): void
    {
        $previous = Container::getInstance();
        $app = new Application;
        $pair = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($pair);
        $app->instance('config', new Repository(['endpoint_management' => ['command_signing_secret' => base64_encode($secret)]]));
        try {
            $signer = new EndpointCommandSigner;
            $c = $this->command();
            Contract::dispatch($c);
            $signed = $signer->sign((array) $c);
            $public = $signer->publicKey();
            self::assertTrue(sodium_crypto_sign_verify_detached(base64_decode($signed['signature']), base64_decode($signed['payload']), base64_decode($public)));
            $cases = [['name' => 'populated_original_signer', 'accepted' => true, 'envelope' => $signed]];
            $optional = $this->command();
            $optional->parameters->manifest->parameter_schema = [];
            $optional->parameters->inputs = (object) [];
            Contract::dispatch($optional);
            $cases[] = ['name' => 'empty_objects_and_arrays', 'accepted' => true, 'envelope' => $signer->sign((array) $optional)];
            foreach ([true, false, 0, 1, null, [], (object) [], 1.5] as $index => $type) {
                foreach ([true, false] as $required) {
                    $bad = $this->command();
                    $bad->parameters->manifest->parameter_schema = [(object) ['name' => 'Flag', 'type' => $type, 'required' => $required, 'constraints' => (object) []]];
                    $bad->parameters->inputs = (object) [];
                    $raw = Contract::encode($bad);
                    $this->rejects(fn () => Contract::dispatch(Contract::decode($raw)));
                    $cases[] = ['name' => 'non_string_type_'.$index.'_'.($required ? 'required' : 'optional_omitted'), 'accepted' => false, 'envelope' => ['payload' => base64_encode($raw), 'signature' => base64_encode(sodium_crypto_sign_detached($raw, $secret))]];
                }
            }
            $valid = base64_decode($signed['payload']);
            $rawCases = [
                'nested_duplicate' => str_replace('"required":true', '"required":true,"required":false', $valid),
                'escaped_duplicate' => str_replace('"required":true', '"required":true,"\u0072equired":false', $valid),
                'trailing_document' => $valid.'{}',
                'invalid_unicode' => str_replace('Synthetic publisher', '\ud800', $valid),
                'unknown_nested_field' => str_replace('"runtime_seconds":60', '"runtime_seconds":60,"shell":"cmd"', $valid),
                'excessive_depth' => '{"x":'.str_repeat('[', 35).'0'.str_repeat(']', 35).'}',
            ];
            foreach ($rawCases as $name => $raw) {
                $this->rejects(fn () => Contract::dispatch(Contract::decode($raw)));
                $cases[] = ['name' => $name, 'accepted' => false, 'envelope' => ['payload' => base64_encode($raw), 'signature' => base64_encode(sodium_crypto_sign_detached($raw, $secret))]];
            }
            [$m, $runtime, $artifact] = $this->fixture();
            $export = ['public_key' => $public, 'agent_uuid' => $c->agent_uuid, 'now' => '2026-10-20T01:00:01Z',
                'cases' => $cases, 'receipts' => $this->receiptCorpus($signer), 'runtime_cases' => $this->runtimeCorpus(), 'runtime_raw' => base64_encode(Contract::encode($runtime)), 'artifact_raw' => base64_encode($artifact),
                'scope' => 'Contract validation and canonical signer only; no publication, current authority, transport, tray or execution acceptance.'];
            if ($destination = getenv('OBLI_SCRIPT_EXPORT')) {
                self::assertNotFalse(file_put_contents($destination, json_encode($export, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)));
            }
            self::assertCount(24, $cases);
        } finally {
            sodium_memzero($secret);
            sodium_memzero($pair);
            Container::setInstance($previous);
        }
    }

    private function runtimeCorpus(): array
    {
        $cases = [];
        foreach ([
            ['nested_files', true, ['modules/Core/main.dll', 'modules/Core/help.xml']],
            ['sibling_prefix', true, ['module', 'modules/main.dll']],
            ['file_is_directory', false, ['lib', 'lib/main.dll']],
            ['case_folded_directory', false, ['Modules', 'modules/main.dll']],
            ['non_adjacent_parent', false, ['lib', 'lib-other', 'lib/main.dll']],
            ['entry_is_directory', false, ['pwsh.exe/module.dll']],
            ['wrapper_is_directory', false, ['obli-script-wrapper.ps1/data']],
        ] as [$name, $accepted, $paths]) {
            [$m, $runtime] = $this->fixture();
            foreach ($paths as $path) {
                $runtime->files[] = (object) ['path' => $path, 'size_bytes' => 1, 'sha256' => str_repeat('d', 64)];
            }
            usort($runtime->files, fn ($a, $b) => strcmp(strtolower($a->path), strtolower($b->path)));
            $raw = Contract::encode($runtime);
            $m->runtime_manifest_sha256 = hash('sha256', $raw);
            if ($accepted) {
                self::assertEquals($runtime, Contract::runtime($m, $raw));
            } else {
                $this->rejects(fn () => Contract::runtime($m, $raw));
            }
            $cases[] = ['name' => $name, 'accepted' => $accepted, 'manifest' => $m, 'raw' => base64_encode($raw)];
        }

        return $cases;
    }

    public function test_native_output_bytes_match_server_receipt_without_reencoding(): void
    {
        $path = getenv('OBLI_SCRIPT_OUTPUT_EXPORT');
        if (! $path || ! is_file($path)) {
            self::markTestSkipped('Original native output export not supplied.');
        }
        $rows = json_decode(file_get_contents($path), false, 32, JSON_THROW_ON_ERROR);
        self::assertCount(10, $rows);
        foreach ($rows as $row) {
            $command = $this->command();
            $command->parameters->manifest->execution_policy->output_bytes = $row->limit;
            $hash = str_repeat('8', 64);
            $bytes = base64_decode($row->body_base64, true);
            self::assertIsString($bytes);
            $result = (object) ['command_uuid' => $command->command_uuid, 'payload_hash' => $hash,
                'status' => 'completed', 'mutation_started' => true, 'reason_code' => 'completed', 'exit_code' => 0,
                'boot_id' => '77777777-7777-4777-8777-777777777777', 'output_sha256' => $row->sha256,
                'output_bytes' => $row->size_bytes, 'output_truncated' => $row->truncated];
            $header = (object) ['command_uuid' => $command->command_uuid, 'payload_hash' => $hash,
                'sha256' => $row->sha256, 'size_bytes' => $row->size_bytes, 'truncated' => $row->truncated];
            Contract::output($header, $bytes, $result, $command, $hash);
            self::assertSame(strlen($bytes), $result->output_bytes);
            $this->rejects(fn () => Contract::output($header, $bytes.'x', $result, $command, $hash));
            foreach (['command_uuid' => $result->boot_id, 'payload_hash' => str_repeat('9', 64), 'sha256' => str_repeat('f', 64), 'size_bytes' => (string) $row->size_bytes, 'truncated' => ! $row->truncated, 'extra' => true] as $field => $value) {
                $bad = $this->copy($header);
                $bad->{$field} = $value;
                $this->rejects(fn () => Contract::output($bad, $bytes, $result, $command, $hash));
            }
        }
        // Matching size/digest is insufficient for invalid retained UTF-8.
        $bytes = "\xff";
        $result->output_bytes = $header->size_bytes = 1;
        $result->output_sha256 = $header->sha256 = hash('sha256', $bytes);
        $this->rejects(fn () => Contract::output($header, $bytes, $result, $command, $hash));
        $result->mutation_started = false;
        $result->status = 'failed';
        $result->reason_code = 'artifact_invalid';
        $result->exit_code = null;
        $result->output_bytes = $header->size_bytes = 0;
        $result->output_sha256 = $header->sha256 = null;
        $result->output_truncated = $header->truncated = false;
        $this->rejects(fn () => Contract::output($header, '', $result, $command, $hash));
    }

    private function receiptCorpus(EndpointCommandSigner $signer): array
    {
        $rows = [];
        $boot = '55555555-5555-4555-8555-555555555555';
        $desktop = '66666666-6666-4666-8666-666666666666';
        $nonce = str_repeat('a', 64);
        foreach (['attended', 'unattended'] as $mode) {
            $c = $this->command();
            $c->policy->consent_mode = $mode;
            Contract::dispatch($c);
            $dispatch = $signer->sign((array) $c);
            $hash = hash('sha256', base64_decode($dispatch['payload']));
            $base = (object) ['version' => 1, 'type' => 'endpoint_script_claim', 'agent_uuid' => $c->agent_uuid,
                'command_uuid' => $c->command_uuid, 'attempt_uuid' => $c->attempt_uuid, 'assignment_fingerprint' => $c->assignment_fingerprint,
                'payload_hash' => $hash, 'policy_version' => 1, 'consent_mode' => $mode,
                'artifact_sha256' => $c->parameters->manifest->sha256, 'runtime_manifest_sha256' => $c->parameters->manifest->runtime_manifest_sha256,
                'boot_id' => $boot, 'desktop_session' => $mode === 'attended' ? $desktop : null, 'consent_nonce' => $mode === 'attended' ? $nonce : null,
                'issued_at' => '2026-10-20T01:00:01Z', 'expires_at' => '2026-10-20T01:00:31Z'];
            $variants = [['valid', null, null]];
            foreach (['policy_version' => 2, 'boot_id' => $c->agent_uuid, 'payload_hash' => str_repeat('f', 64), 'runtime_manifest_sha256' => str_repeat('f', 64),
                'artifact_sha256' => str_repeat('f', 64), 'desktop_session' => $mode === 'attended' ? null : $desktop, 'consent_nonce' => $mode === 'attended' ? null : $nonce,
                'expires_at' => '2026-10-20T01:00:32Z', 'issued_at' => '2026-10-20T01:00:05Z', 'attempt_uuid' => $boot] as $key => $value) {
                $variants[] = [$key, $key, $value];
            }
            foreach ($variants as [$name, $key, $value]) {
                $x = $this->copy($base);
                if ($key !== null) {
                    $x->{$key} = $value;
                }
                $accepted = $key === null;
                $operation = fn () => Contract::claim($x, $c, $hash, $boot, $mode === 'attended' ? $desktop : null, $mode === 'attended' ? $nonce : null, '2026-10-20T01:00:01Z');
                if ($accepted) {
                    $operation();
                    self::assertTrue(true);
                } else {
                    $this->rejects($operation);
                }
                $rows[] = ['kind' => 'claim', 'name' => $mode.'_'.$name, 'accepted' => $accepted, 'command' => $dispatch, 'payload_hash' => $hash,
                    'envelope' => $signer->sign((array) $x), 'boot_id' => $boot, 'desktop_session' => $mode === 'attended' ? $desktop : null, 'consent_nonce' => $mode === 'attended' ? $nonce : null, 'now' => '2026-10-20T01:00:01Z'];
            }
        }
        $c = $this->command();
        $dispatch = $signer->sign((array) $c);
        $hash = hash('sha256', base64_decode($dispatch['payload']));
        $cancel = (object) ['version' => 1, 'type' => 'endpoint_script_cancel', 'agent_uuid' => $c->agent_uuid, 'command_uuid' => $c->command_uuid, 'attempt_uuid' => $c->attempt_uuid,
            'assignment_fingerprint' => $c->assignment_fingerprint, 'payload_hash' => $hash, 'issued_at' => '2026-10-20T02:00:00Z', 'expires_at' => '2026-10-20T02:01:00Z'];
        foreach ([['after_dispatch_expiry', null, null], ['foreign_attempt', 'attempt_uuid', $boot], ['expired', 'expires_at', '2026-10-20T02:00:01Z'], ['long_lease', 'expires_at', '2026-10-20T03:00:01Z']] as [$name, $key, $value]) {
            $x = $this->copy($cancel);
            if ($key !== null) {
                $x->{$key} = $value;
            }
            $accepted = $key === null;
            $operation = fn () => Contract::cancellation($x, $c, $hash, '2026-10-20T02:00:01Z');
            if ($accepted) {
                $operation();
                self::assertTrue(true);
            } else {
                $this->rejects($operation);
            }
            $rows[] = ['kind' => 'cancel', 'name' => $name, 'accepted' => $accepted, 'command' => $dispatch, 'payload_hash' => $hash, 'envelope' => $signer->sign((array) $x), 'now' => '2026-10-20T02:00:01Z'];
        }
        foreach ([false, true] as $observation) {
            $c = $this->command();
            $c->parameters->manifest->execution_policy->expected_observation_schema = $observation ? str_repeat('d', 64) : null;
            $dispatch = $signer->sign((array) $c);
            $hash = hash('sha256', base64_decode($dispatch['payload']));
            foreach ([
                ['completed', 'completed', true, 0, ! $observation],
                ['awaiting_observation', 'completed', true, 0, $observation],
                ['succeeded', 'completed', true, 0, false],
                ['failed', 'exit_failed', true, 1, true],
                ['failed', 'exit_failed', true, 0, false],
                ['uncertain', 'interrupted', false, null, true],
                ['uncertain', 'state_unavailable', false, null, true],
                ['uncertain', 'interrupted', true, null, true],
                ['uncertain', 'timed_out', true, null, true],
                ['uncertain', 'cancellation_requested', true, 1, true],
                ['uncertain', 'timed_out', false, null, false],
                ['cancelled', 'cancelled', true, 1, false],
                ['cancelled', 'cancelled', false, null, true],
                ['declined', 'declined', false, null, true],
                ['expired', 'expired', false, null, true],
                ['failed', 'artifact_invalid', false, null, true],
                ['completed', 'completed', false, 0, false],
            ] as $i => [$status, $reason, $started, $exit, $accepted]) {
                $x = (object) ['command_uuid' => $c->command_uuid, 'payload_hash' => $hash, 'status' => $status, 'mutation_started' => $started, 'reason_code' => $reason,
                    'exit_code' => $exit, 'boot_id' => $boot, 'output_sha256' => null, 'output_bytes' => 0, 'output_truncated' => false];
                $operation = fn () => Contract::result($x, $c, $hash);
                if ($accepted) {
                    $operation();
                    self::assertTrue(true);
                } else {
                    $this->rejects($operation);
                }
                $rows[] = ['kind' => 'result', 'name' => ($observation ? 'observation_' : 'execution_').$i, 'accepted' => $accepted, 'command' => $dispatch, 'payload_hash' => $hash, 'value' => $x];
            }
            foreach (['output_bytes' => 4097, 'output_sha256' => str_repeat('a', 64), 'output_truncated' => 'true', 'mutation_started' => 1, 'exit_code' => 4294967296, 'extra' => true] as $key => $value) {
                $x = (object) ['command_uuid' => $c->command_uuid, 'payload_hash' => $hash, 'status' => 'uncertain', 'mutation_started' => true, 'reason_code' => 'interrupted',
                    'exit_code' => null, 'boot_id' => $boot, 'output_sha256' => null, 'output_bytes' => 0, 'output_truncated' => false];
                $x->{$key} = $value;
                $this->rejects(fn () => Contract::result($x, $c, $hash));
                $rows[] = ['kind' => 'result', 'name' => ($observation ? 'observation_' : 'execution_').'invalid_'.$key, 'accepted' => false, 'command' => $dispatch, 'payload_hash' => $hash, 'value' => $x];
            }
        }

        foreach ([false, true] as $observation) {
            foreach ([[true, 0, 0, false, true], [true, 0, 0, true, true], [false, 0, 0, true, false], [true, 4096, 32, true, true]] as $i => [$started, $limit, $bytes, $truncated, $accepted]) {
                $c = $this->command();
                $c->parameters->manifest->execution_policy->output_bytes = $limit;
                $c->parameters->manifest->execution_policy->expected_observation_schema = $observation ? str_repeat('d', 64) : null;
                $dispatch = $signer->sign((array) $c);
                $hash = hash('sha256', base64_decode($dispatch['payload']));
                $x = (object) ['command_uuid' => $c->command_uuid, 'payload_hash' => $hash, 'status' => 'uncertain', 'mutation_started' => $started, 'reason_code' => 'interrupted',
                    'exit_code' => null, 'boot_id' => $boot, 'output_sha256' => $bytes > 0 ? str_repeat('a', 64) : null, 'output_bytes' => $bytes, 'output_truncated' => $truncated];
                if ($accepted) {
                    Contract::result($x, $c, $hash);
                    self::assertTrue(true);
                } else {
                    $this->rejects(fn () => Contract::result($x, $c, $hash));
                }
                $rows[] = ['kind' => 'result', 'name' => ($observation ? 'observation_' : 'execution_').'output_'.$i, 'accepted' => $accepted, 'command' => $dispatch, 'payload_hash' => $hash, 'value' => $x];
            }
        }

        return $rows;
    }
}
