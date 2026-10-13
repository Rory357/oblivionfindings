<?php

namespace App\Http\Controllers\It;

use App\Domain\It\Services\ItObliDiagnosticSource;
use App\Domain\Obli\ObliAiSettings;
use App\Domain\Obli\ObliChatGateway;
use App\Domain\Obli\ObliChatRejected;
use App\Domain\Obli\ObliTechnicianChatScope;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class ItObliInvestigationController
{
    public function options(Request $request, int $device, ItObliDiagnosticSource $source, ObliAiSettings $settings, ObliChatGateway $chat)
    {
        return $this->errors(function () use ($request, $device, $source, $settings, $chat) {
            $data = $this->query($request);
            $actor = $request->user();
            $this->expected($actor, $data);

            return $source->withAuthorized($actor, $device, $data['ticket_id'] ?? null, function (User $current) use ($device, $data, $settings, $chat) {
                $policy = $settings->current(true);
                $enabled = (bool) ($policy['enabled'] && $policy['technician_enabled'] && $chat->investigationReady());

                return $this->json(['actor_id' => (int) $current->id, 'device_id' => $device, 'ticket_id' => $data['ticket_id'] ?? null, 'version' => $policy['version'], 'enabled' => $enabled, 'provider' => $policy['provider'], 'model' => $policy['model'], 'chat_retention_days' => $policy['chat_retention_days'], 'diagnostic_categories' => $policy['diagnostic_categories'], 'mode' => 'read_suggest', 'can_preview' => $current->canDo('securityDevices.endpoints.diagnostics'), 'can_assist' => $enabled && $current->canDo('securityDevices.obli.assist')]);
            });
        });
    }

    public function diagnostics(Request $request, int $device, ItObliDiagnosticSource $source)
    {
        return $this->errors(function () use ($request, $device, $source) {
            $data = $this->query($request, true);
            $actor = $request->user();
            $this->expected($actor, $data);

            return $this->json(['actor_id' => (int) $actor->id, 'ticket_id' => $data['ticket_id'] ?? null] + $source->preview($actor, $device, $data['ticket_id'] ?? null, $data['groups'] ?? ['system', 'software', 'services']));
        });
    }

    public function store(Request $request, int $device, ItObliDiagnosticSource $source, ObliAiSettings $settings, ObliChatGateway $chat)
    {
        return $this->errors(function () use ($request, $device, $source, $settings, $chat) {
            $input = $request->json()->all();
            if (array_diff(array_keys($input), ['request_uuid', 'expected_actor_id', 'configuration_version', 'ticket_id', 'messages']) !== []) {
                throw new ObliChatRejected('invalid_request', 422);
            }
            $data = validator($input, ['expected_actor_id' => ['required', 'integer', 'min:1'], 'ticket_id' => ['nullable', 'integer', 'min:1']])->validate();
            $actor = $request->user();
            $this->expected($actor, $data);
            $scope = new ObliTechnicianChatScope($source, $settings, $actor, $device, isset($data['ticket_id']) ? (int) $data['ticket_id'] : null, (int) $data['expected_actor_id']);
            unset($input['expected_actor_id'], $input['ticket_id']);

            return $this->json($chat->sendInvestigation($scope, $input));
        });
    }

    public function show(Request $request, int $device, string $requestUuid, ItObliDiagnosticSource $source, ObliAiSettings $settings, ObliChatGateway $chat)
    {
        return $this->errors(function () use ($request, $device, $requestUuid, $source, $settings, $chat) {
            $data = $this->query($request);
            $actor = $request->user();
            $this->expected($actor, $data);
            $scope = new ObliTechnicianChatScope($source, $settings, $actor, $device, $data['ticket_id'] ?? null, (int) $data['expected_actor_id']);

            return $this->json($chat->outcomeInvestigation($scope, $requestUuid));
        });
    }

    private function query(Request $request, bool $groups = false): array
    {
        $input = $request->query();
        if (array_diff(array_keys($input), $groups ? ['expected_actor_id', 'ticket_id', 'groups'] : ['expected_actor_id', 'ticket_id']) !== []) {
            throw new ObliChatRejected('invalid_request', 422);
        }
        $data = validator($input, ['expected_actor_id' => ['required', 'integer', 'min:1'], 'ticket_id' => ['nullable', 'integer', 'min:1'], 'groups' => ['sometimes', 'required', 'array', 'min:1', 'max:3'], 'groups.*' => ['required', 'string', 'distinct', 'in:system,software,services']])->validate();
        if (isset($data['groups']) && ! array_is_list($data['groups'])) {
            throw new ObliChatRejected('invalid_request', 422);
        }
        if (isset($data['ticket_id'])) {
            $data['ticket_id'] = (int) $data['ticket_id'];
        }

        return $data;
    }

    private function expected(?User $actor, array $data): void
    {
        if (! $actor || (int) $actor->id !== (int) $data['expected_actor_id']) {
            throw new ObliChatRejected('source_unavailable', 404);
        }
    }

    private function errors(\Closure $operation)
    {
        try {
            return $operation();
        } catch (ValidationException) {
            throw new ObliChatRejected('invalid_request', 422);
        } catch (HttpExceptionInterface $error) {
            throw new ObliChatRejected(match ($error->getStatusCode()) {
                422 => 'invalid_request',409 => 'idempotency_conflict',429 => 'budget_exceeded',503 => 'chat_unavailable',410 => 'outcome_expired',default => 'source_unavailable'
            }, $error->getStatusCode());
        }
    }

    private function json(array $data)
    {
        return response()->json($data, options: JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
