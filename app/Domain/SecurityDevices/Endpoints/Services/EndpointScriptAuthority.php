<?php

namespace App\Domain\SecurityDevices\Endpoints\Services;

use App\Domain\SecurityDevices\Endpoints\Models\EndpointAgent;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Domain\SecurityDevices\Management\Services\CommandCapabilityRegistry;
use App\Domain\SecurityDevices\Management\Services\DeviceManagementAuthorizationService;
use App\Domain\SecurityDevices\Models\Device;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/** Current command binding, never a second queue or a launch grant. */
final class EndpointScriptAuthority
{
    public function supports(Device $device): bool
    {
        if (! app(EndpointScriptPolicy::class)->available()) {
            return false;
        }
        try {
            return DB::transaction(function () use ($device): bool {
                $agent = app(EndpointScriptReadiness::class)->current($device);
                $effective = app(EndpointScriptPolicy::class)->current($agent->device);

                return $effective['assignment_matches'] && $effective['policy']['enabled'];
            });
        } catch (Throwable) {
            return false;
        }
    }

    public function withBinding(Device $device, User $actor, array $parameters, Closure $callback, bool $includeArtifact = false): mixed
    {
        try {
            $inputs = EndpointScriptRequestParameters::inputs($parameters);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['parameters' => 'Invalid reviewed script request.']);
        }

        return app(EndpointScripts::class)->withExecutionCandidate(
            $actor, $device, (int) $actor->id, $parameters['script_uuid'], $parameters['content_hash'],
            $parameters['policy_version'], $inputs,
            function (User $current, Device $locked, array $candidate) use ($parameters, $callback): mixed {
                CurrentAuthorizationReads::within(function (CurrentAuthorizationReads $reads) use ($current, $locked): void {
                    $decision = app(DeviceManagementAuthorizationService::class)->forCurrentEvidence($reads, (int) $locked->id)
                        ->evaluate($current, $locked, app(CommandCapabilityRegistry::class)->definition(EndpointScriptContract::CAPABILITY), fresh: true);
                    abort_unless($decision->allowed, $decision->concealed ? 404 : 403);
                });
                $agent = app(EndpointScriptReadiness::class)->current($locked);
                $manifest = $candidate['parameters']->manifest;
                abort_unless($manifest->script_version === $parameters['script_version']
                    && ($agent->inventory['architecture'] ?? null) === $manifest->architecture
                    && hash_equals($agent->assignment_fingerprint, $candidate['assignment_fingerprint']), 409);
                $binding = [
                    'script_execution_completed' => true, 'script_uuid' => $manifest->script_uuid,
                    'script_version' => $manifest->script_version, 'content_hash' => $candidate['content_hash'],
                    'artifact_sha256' => $manifest->sha256, 'runtime_manifest_sha256' => $manifest->runtime_manifest_sha256,
                    'wrapper_sha256' => $manifest->wrapper_sha256, 'architecture' => $manifest->architecture,
                    'agent_uuid' => $agent->uuid, 'assignment_fingerprint' => $agent->assignment_fingerprint,
                    'policy_version' => $candidate['policy']['version'], 'consent_mode' => $candidate['policy']['consent_mode'],
                ];

                return $callback($current, $locked, $binding, $agent, $candidate);
            }, $includeArtifact,
        );
    }

    public function assertRequest(DeviceCommandRequest $request, User $actor): EndpointAgent
    {
        abort_unless($request->capability === EndpointScriptContract::CAPABILITY
            && (int) $request->requested_by_user_id === (int) $actor->id, 409);

        return $this->withBinding($request->device, $actor, $request->encrypted_parameters,
            function (User $current, Device $device, array $binding, EndpointAgent $agent) use ($request): EndpointAgent {
                $this->assertBinding($request, $binding);

                return $agent;
            });
    }

    public function assertBinding(DeviceCommandRequest $request, array $binding): void
    {
        abort_unless($request->capability === EndpointScriptContract::CAPABILITY, 409);
        $expected = $request->expected_state;
        ksort($expected);
        ksort($binding);
        abort_unless($expected === $binding, 409);
    }
}
