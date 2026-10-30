<?php

namespace App\Domain\SecurityDevices\Endpoints\Services;

use App\Domain\SecurityDevices\Endpoints\Models\EndpointAgent;
use App\Domain\SecurityDevices\Models\Device;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

final class EndpointHeartbeat
{
    public function __construct(private readonly EndpointAgentIdentity $identity) {}

    public function record(string $credential, array $payload): array
    {
        return $this->identity->withAuthenticated($credential, function (EndpointAgent $agent) use ($payload): array {
            $data = $this->validate($payload);
            $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            if ($data['sequence'] <= $agent->sequence) {
                // A lost acknowledgement can be retried, but cannot advance freshness.
                abort_unless($data['sequence'] === $agent->sequence && hash_equals($agent->heartbeat_hash ?? '', $hash), 409);

                return $this->acknowledgement($agent);
            }
            $observed = CarbonImmutable::parse($data['observed_at']);
            abort_unless($observed->betweenIncluded(now()->subSeconds(120), now()->addSeconds(30)), 422, 'Observation time is outside the accepted clock window.');
            abort_if($agent->observed_at && $observed->lessThan($agent->observed_at), 409);
            $agent->forceFill([
                'sequence' => $data['sequence'], 'heartbeat_hash' => $hash,
                'observed_at' => $observed, 'last_seen_at' => now(),
                'agent_version' => $data['agent_version'], 'boot_id' => $data['boot_id'],
                'inventory' => $data['inventory'],
                ...(Schema::hasColumn('endpoint_agents', 'script_actions') ? ['script_actions' => $data['script_actions'] ?? null] : []),
                ...(Schema::hasColumn('endpoint_agents', 'service_actions') ? ['service_actions' => $data['service_actions'] ?? null, 'service_action_observations' => app(EndpointServiceCommands::class)->observations($agent, $data['service_action_observations'] ?? [], $observed, $data['sequence'])] : []),
                ...(Schema::hasColumn('endpoint_agents', 'software_actions') ? ['software_actions' => $data['software_actions'] ?? null, 'software_observations' => app(EndpointSoftwareCommands::class)->observations($agent, $data['software_observations'] ?? [], $observed, $data['sequence'], $data['boot_id'])] : []),
                ...(Schema::hasColumn('endpoint_agents', 'restart_actions') ? ['restart_actions' => $data['restart_actions'] ?? null, 'restart_observations' => app(EndpointRestartCommands::class)->observations($agent, $data['restart_observations'] ?? [], $observed, $data['sequence'], $data['boot_id'])] : []),
            ])->save();
            // Source observations cannot change canonical ownership, identity or lifecycle.
            Device::query()->whereKey($agent->device_id)->update(['last_seen_at' => now()]);

            return $this->acknowledgement($agent);
        });
    }

    private function validate(array $payload): array
    {
        $text = ['string', 'max:300', 'not_regex:/[\\x00-\\x1F\\x7F]/'];
        $integer = ['integer', 'min:0', 'max:9007199254740991'];

        return Validator::make($payload, [
            'script_actions' => ['sometimes', 'required', 'array:version,installed_service,release_enabled,executor', 'size:4'],
            'script_actions.version' => ['required_with:script_actions', 'integer:strict', 'in:1'],
            'script_actions.installed_service' => ['sometimes', 'required_with:script_actions', 'accepted', 'boolean:strict'],
            'script_actions.release_enabled' => ['sometimes', 'required_with:script_actions', 'accepted', 'boolean:strict'],
            'script_actions.executor' => ['required_with:script_actions', 'string', 'in:reviewed_script'],
            'software_actions' => ['sometimes', 'required', 'array:version,installed_service,release_enabled,executor', 'size:4'],
            'software_actions.version' => ['required_with:software_actions', 'integer:strict', 'in:1'],
            'software_actions.installed_service' => ['sometimes', 'required_with:software_actions', 'accepted', 'boolean:strict'],
            'software_actions.release_enabled' => ['sometimes', 'required_with:software_actions', 'accepted', 'boolean:strict'],
            'software_actions.executor' => ['required_with:software_actions', 'in:windows_msi'],
            'software_observations' => ['sometimes', 'array', 'max:8'],
            'software_observations.*' => ['required', 'array:command_uuid,attempt_uuid,product_code,product_version,state,boot_id,transaction_quiescent,observed_at', 'size:8'],
            'software_observations.*.command_uuid' => ['required', 'uuid'],
            'software_observations.*.attempt_uuid' => ['required', 'uuid', 'distinct:strict'],
            'software_observations.*.product_code' => ['required', 'regex:/\A\{[A-F0-9]{8}-[A-F0-9]{4}-[A-F0-9]{4}-[A-F0-9]{4}-[A-F0-9]{12}\}\z/'],
            'software_observations.*.product_version' => ['present', 'nullable', 'regex:/\A(?:0|[1-9][0-9]{0,2})\.(?:0|[1-9][0-9]{0,2})\.(?:0|[1-9][0-9]{0,4})\z/'],
            'software_observations.*.state' => ['required', 'in:installed,advertised,absent,unknown'],
            'software_observations.*.boot_id' => ['required', 'uuid'],
            'software_observations.*.transaction_quiescent' => ['required', 'boolean:strict'],
            'software_observations.*.observed_at' => ['required', 'date_format:Y-m-d\TH:i:s\Z'],
            'restart_actions' => ['sometimes', 'required', 'array:version,installed_service,release_enabled,executor', 'size:4'],
            'restart_actions.version' => ['required_with:restart_actions', 'integer:strict', 'in:1'],
            'restart_actions.installed_service' => ['sometimes', 'required_with:restart_actions', 'accepted', 'boolean:strict'],
            'restart_actions.release_enabled' => ['sometimes', 'required_with:restart_actions', 'accepted', 'boolean:strict'],
            'restart_actions.executor' => ['required_with:restart_actions', 'in:windows_restart'],
            'restart_observations' => ['sometimes', 'array', 'max:8'],
            'restart_observations.*' => ['required', 'array:command_uuid,attempt_uuid,before_boot_id,boot_id,observed_at', 'size:5'],
            'restart_observations.*.command_uuid' => ['required', 'uuid'],
            'restart_observations.*.attempt_uuid' => ['required', 'uuid', 'distinct:strict'],
            'restart_observations.*.before_boot_id' => ['required', 'uuid'],
            'restart_observations.*.boot_id' => ['required', 'uuid'],
            'restart_observations.*.observed_at' => ['required', 'date_format:Y-m-d\TH:i:s\Z'],
            'service_action_observations' => ['sometimes', 'array', 'max:8'],
            'service_action_observations.*' => ['required', 'array:command_uuid,attempt_uuid,service_name,state,observed_at', 'size:5'],
            'service_action_observations.*.command_uuid' => ['required', 'uuid'],
            'service_action_observations.*.attempt_uuid' => ['required', 'uuid', 'distinct:strict'],
            'service_action_observations.*.service_name' => ['required', 'string', 'max:200'],
            'service_action_observations.*.state' => ['required', 'in:running,stopped,unknown'],
            'service_action_observations.*.observed_at' => ['required', 'date_format:Y-m-d\\TH:i:s\\Z'],
            'service_actions' => ['sometimes', 'required', 'array:version,installed_service,release_enabled,executor,operations', 'size:5'],
            'service_actions.version' => ['required_with:service_actions', 'integer:strict', 'in:1'],
            'service_actions.installed_service' => ['sometimes', 'required_with:service_actions', 'accepted', 'boolean:strict'],
            'service_actions.release_enabled' => ['sometimes', 'required_with:service_actions', 'accepted', 'boolean:strict'],
            'service_actions.executor' => ['required_with:service_actions', 'in:windows_scm'],
            'service_actions.operations' => ['required_with:service_actions', 'array', 'min:1', 'max:3'],
            'service_actions.operations.*' => ['required', 'in:start,stop,restart', 'distinct:strict'],
            'sequence' => ['required', 'integer:strict', 'min:1', 'max:9007199254740991'],
            'observed_at' => ['required', 'date_format:Y-m-d\\TH:i:s\\Z'],
            'agent_version' => ['required', 'string', 'max:40', 'regex:/^[0-9]+\\.[0-9]+\\.[0-9]+(?:-[a-zA-Z0-9.-]+)?$/D'],
            'boot_id' => ['required', 'uuid'],
            'inventory' => ['required', 'array:hostname,os_version,architecture,memory_total_bytes,memory_available_bytes,uptime_seconds,cpu_percent,disks,software,software_complete,services,services_complete,probe_errors'],
            'inventory.hostname' => ['required', ...$text],
            'inventory.os_version' => ['required', ...$text],
            'inventory.architecture' => ['required', 'in:amd64,arm64,386,arm'],
            'inventory.memory_total_bytes' => ['present', 'nullable', ...$integer],
            'inventory.memory_available_bytes' => ['present', 'nullable', ...$integer],
            'inventory.uptime_seconds' => ['present', 'nullable', ...$integer],
            'inventory.cpu_percent' => ['present', 'nullable', 'numeric', 'between:0,100'],
            'inventory.disks' => ['present', 'array', 'max:64'],
            'inventory.disks.*' => ['array:name,total_bytes,free_bytes'],
            'inventory.disks.*.name' => ['required', ...$text],
            'inventory.disks.*.total_bytes' => ['required', ...$integer],
            'inventory.disks.*.free_bytes' => ['required', ...$integer],
            'inventory.software' => ['present', 'array', 'max:5000'],
            'inventory.software.*' => ['array:name,version,publisher'],
            'inventory.software.*.name' => ['required', ...$text],
            'inventory.software.*.version' => ['present', 'nullable', ...$text],
            'inventory.software.*.publisher' => ['present', 'nullable', ...$text],
            'inventory.software_complete' => ['required', 'boolean:strict'],
            'inventory.services' => ['present', 'array', 'max:1000'],
            'inventory.services.*' => ['array:name,state'],
            'inventory.services.*.name' => ['required', ...$text],
            'inventory.services.*.state' => ['required', 'in:running,stopped,unknown'],
            'inventory.services_complete' => ['required', 'boolean:strict'],
            'inventory.probe_errors' => ['present', 'array', 'max:8'],
            'inventory.probe_errors.*' => ['in:os,memory,uptime,cpu,disks,software,services'],
        ])->validate();
    }

    private function acknowledgement(EndpointAgent $agent): array
    {
        return [
            ...($agent->software_observations ? ['software_observations_accepted' => app(EndpointSoftwareCommands::class)->acceptedObservations($agent)] : []),
            ...($agent->restart_observations ? ['restart_observations_accepted' => app(EndpointRestartCommands::class)->acceptedObservations($agent)] : []),
            'agent_uuid' => $agent->uuid, 'acknowledged_sequence' => $agent->sequence,
            'server_time' => now()->utc()->format('Y-m-d\\TH:i:s\\Z'),
            'next_heartbeat_seconds' => max(30, min(300, (int) config('endpoint_management.heartbeat_seconds', 60))),
            ...($agent->service_action_observations ? ['service_action_observations_accepted' => app(EndpointServiceCommands::class)->acceptedObservations($agent)] : []),
        ];
    }
}
