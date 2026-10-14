<?php

namespace App\Domain\SecurityDevices\Http\Controllers;

use App\Domain\Monitoring\Services\NativeMonitorCommandService;
use App\Domain\Monitoring\Services\NativeMonitoringDefinitionService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class NativeMonitorLifecycleController extends Controller
{
    public function __construct(
        private readonly NativeMonitorCommandService $commands,
    ) {}

    public function store(Request $request): JsonResponse
    {
        return $this->command($request, 'create', null, $this->rules(false), 201);
    }

    public function update(Request $request, int $monitor): JsonResponse
    {
        return $this->command($request, 'update', $monitor, $this->rules(true));
    }

    public function deactivate(Request $request, int $monitor): JsonResponse
    {
        return $this->command($request, 'deactivate', $monitor, [
            ...$this->identityRules(),
            'review_token' => ['required_with:request_uuid', 'string', 'regex:/^[0-9a-f]{64}$/D'],
            'reason_code' => ['required', 'string', Rule::in(['replaced', 'obsolete', 'coverage_removed', 'device_retired'])],
        ]);
    }

    public function requests(Request $request): JsonResponse
    {
        $viewer = $this->manager($request);
        if (array_diff(array_keys($request->query()), ['expected_actor_id', 'before_id']) !== []) {
            abort(400, 'invalid_request');
        }
        $values = $request->validate(['expected_actor_id' => ['required', 'integer', 'min:1'], 'before_id' => ['sometimes', 'integer', 'min:1']]);

        return response()->json($this->commands->savedRequests($viewer, (int) $values['expected_actor_id'], isset($values['before_id']) ? (int) $values['before_id'] : null))->header('Cache-Control', 'private, no-store');
    }

    public function recover(Request $request, string $requestUuid): JsonResponse
    {
        $this->queryFree($request);
        $viewer = $this->manager($request);
        $values = $request->validate($this->identityRules(true));

        return response()->json($this->commands->recover($viewer, $requestUuid, $values))->header('Cache-Control', 'private, no-store');
    }

    private function command(Request $request, string $operation, ?int $target, array $rules, int $status = 200): JsonResponse
    {
        $this->queryFree($request);
        $viewer = $this->manager($request);
        if ($request->filled('request_uuid') && array_diff(array_keys($request->except('_token')), array_keys($rules)) !== []) {
            throw ValidationException::withMessages(['form' => 'The monitor request contains unsupported fields. Reopen and review the change.']);
        }
        $values = $request->validate($rules);

        return response()->json($this->commands->execute($viewer, $operation, $target, $values), $status)->header('Cache-Control', 'private, no-store');
    }

    private function queryFree(Request $request): void
    {
        abort_if($request->query() !== [], 400, 'invalid_request');
    }

    private function manager(Request $request): User
    {
        $viewer = $request->user();
        abort_unless($viewer instanceof User && $viewer->canDo('securityDevices.monitoring.manage'), 403);

        return $viewer;
    }

    /** @return array<string, mixed> */
    private function rules(bool $updating): array
    {
        $sometimes = $updating ? ['sometimes'] : ['required'];

        return [
            ...$this->identityRules(),
            'review_token' => $updating ? ['required_with:request_uuid', 'string', 'regex:/^[0-9a-f]{64}$/D'] : ['prohibited'],
            'device_id' => $updating ? ['prohibited'] : ['required', 'integer', 'min:1'],
            'profile_id' => [...$sometimes, 'integer', 'min:1'],
            'kind' => $updating
                ? ['prohibited']
                : ['required', 'string', Rule::in(collect(NativeMonitoringDefinitionService::directKindOptions())->pluck('value')->all())],
            'name' => [...$sometimes, 'string', 'min:3', 'max:128', 'not_regex:/[\x00-\x1F\x7F]/'],
            'target' => $updating
                ? ['sometimes', 'nullable', 'string', 'max:8192', 'not_regex:/[\x00-\x1F\x7F]/']
                : ['required', 'string', 'max:8192', 'not_regex:/[\x00-\x1F\x7F]/'],
            'port' => ['sometimes', 'nullable', 'integer', 'between:1,65535'],
            'dns_name' => ['sometimes', 'nullable', 'string', 'max:253'],
            'dns_type' => ['sometimes', 'nullable', 'string', Rule::in(['A', 'AAAA', 'CNAME', 'MX', 'TXT'])],
            'expected_answers' => ['sometimes', 'array', 'max:64'],
            'expected_answers.*' => ['string', 'max:1024', 'not_regex:/[\x00-\x1F\x7F]/'],
            'expected_status' => ['sometimes', 'array', 'min:1', 'max:20'],
            'expected_status.*' => ['integer', 'between:100,599'],
            'warn_days' => ['sometimes', 'nullable', 'integer', 'between:1,365'],
            'credential_reference' => ['sometimes', 'nullable', 'string', 'max:191', 'regex:/^[a-z][a-z0-9._-]{1,31}:[A-Za-z0-9._\/:@-]{1,158}$/'],
            'inventory_profile' => ['sometimes', 'nullable', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_.-]{1,63}$/'],
            'host_key_fingerprint' => ['sometimes', 'nullable', 'string', 'max:51', 'regex:/^SHA256:[A-Za-z0-9+\/]{43}$/'],
            'affects_availability' => ['sometimes', 'boolean'],
        ];
    }

    private function identityRules(bool $recovering = false): array
    {
        return [
            'request_uuid' => $recovering ? ['prohibited'] : ['sometimes', 'required', 'uuid', 'regex:/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD'],
            'expected_actor_id' => [$recovering ? 'required' : 'required_with:request_uuid', 'integer', 'min:1'],
            'expected_site_id' => [$recovering ? 'required' : 'required_with:request_uuid', 'integer', 'min:1'],
            'expected_device_id' => [$recovering ? 'required' : 'required_with:request_uuid', 'integer', 'min:1'],
        ];
    }
}
