<?php

namespace App\Domain\It\Services;

use App\Http\Requests\It\StoreItServiceIdentityRequest;
use App\Models\ItServiceIdentity;
use App\Models\ItServiceIdentityCommandReceipt;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\AuthorizationEvidenceLockService;
use Closure;
use DomainException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Browser management transport: one durable intent, no recoverable secret. */
final class ItServiceIdentityCommandService
{
    public function __construct(private readonly ItServiceIdentityCredentialService $credentials) {}

    public function execute(User $viewer, string $operation, array $input, ?ItServiceIdentity $identity = null): array
    {
        abort_unless(in_array($operation, ['issue', 'update', 'rotate', 'revoke'], true), 404);
        $this->validateEnvelope($viewer, $input);
        $payload = Arr::except($input, ['request_uuid', 'viewer_user_id']);
        $hash = hash('sha256', json_encode(Arr::sortRecursive([
            'operation' => $operation, 'identity_id' => $identity?->id, 'payload' => $payload,
        ]), JSON_THROW_ON_ERROR));

        return $this->context($viewer, $input, $identity?->id, function (
            User $manager, Collection $users, ?ItServiceIdentity $target, ?ItServiceIdentityCommandReceipt $receipt,
        ) use ($operation, $input, $payload, $hash): array {
            if ($receipt) {
                if ($receipt->state === 'cancelled') {
                    return $this->result($manager, $receipt, true);
                }
                $this->conflictUnless(hash_equals((string) $receipt->payload_hash, $hash), 'command_conflict',
                    'This request has already been used for another action. Recover its outcome before starting again.');

                return $this->result($manager, $receipt, true);
            }
            if ($operation !== 'issue') {
                abort_unless($target, 404);
                Validator::make($payload, ['expected_version' => ['required', 'integer', 'min:1']])->validate();
                $this->conflictUnless((int) $payload['expected_version'] === (int) $target->configuration_version,
                    'identity_changed', 'This identity changed. Refresh and review its current settings.');
                $this->conflictUnless($target->revoked_at === null && ($operation !== 'rotate' || $target->isActive()),
                    'identity_inactive', 'This identity is no longer eligible for this action.');
            }
            $data = $this->validatedPayload($operation, $payload);
            $credential = null;
            if (in_array($operation, ['issue', 'update'], true)) {
                $this->credentials->validateDelegatedInput($manager,
                    $users->get($operation === 'issue' ? (int) $data['actor_user_id'] : $target->actor_user_id), $data);
            }
            if ($operation === 'issue') {
                $issued = $this->credentials->create($manager, $data);
                $target = $issued['identity'];
                $credential = ['identity_id' => $target->id, 'name' => $target->name, 'token' => $issued['token']];
            } elseif ($operation === 'update') {
                $target = $this->credentials->updateGrants($target, $manager, $users->get($target->actor_user_id), $data);
            } elseif ($operation === 'rotate') {
                $credential = $this->credentials->rotate($target, $manager, $users->get($target->actor_user_id));
            } else {
                $target = $this->credentials->revoke($target, $manager);
            }
            $receipt = ItServiceIdentityCommandReceipt::query()->create([
                'actor_user_id' => $manager->id, 'request_uuid' => strtolower($input['request_uuid']),
                'operation' => $operation, 'service_identity_id' => $target->id,
                'payload_hash' => $hash, 'state' => 'confirmed', 'result_version' => $target->configuration_version,
            ]);

            return [...$this->result($manager, $receipt, false), 'credential' => $credential, 'credential_unavailable' => false];
        });
    }

    public function recover(User $viewer, array $input, bool $cancel = false): array
    {
        $this->validateEnvelope($viewer, $input);
        Validator::make($input, ['request_uuid' => ['required', 'uuid'], 'viewer_user_id' => ['required', 'integer']])->validate();
        if (array_diff(array_keys($input), ['request_uuid', 'viewer_user_id']) !== []) {
            throw ValidationException::withMessages(['request_uuid' => 'Only the original request and viewer are accepted.']);
        }

        return $this->context($viewer, $input, null, function (User $manager, Collection $users, ?ItServiceIdentity $target, ?ItServiceIdentityCommandReceipt $receipt) use ($input, $cancel): array {
            if ($receipt) {
                return $this->result($manager, $receipt, true);
            }
            if (! $cancel) {
                return ['viewer_user_id' => $manager->id, 'request_uuid' => strtolower($input['request_uuid']), 'state' => 'not_found'];
            }
            $receipt = ItServiceIdentityCommandReceipt::query()->create([
                'actor_user_id' => $manager->id, 'request_uuid' => strtolower($input['request_uuid']), 'state' => 'cancelled',
            ]);
            AuditLogger::logOrFail('it.api.identity.command_cancelled', $receipt, [
                'application_scope' => 'single_installation', 'actor_id' => $manager->id,
            ]);

            return $this->result($manager, $receipt, false);
        });
    }

    private function validateEnvelope(User $viewer, array $input): void
    {
        Validator::make($input, [
            'request_uuid' => ['required', 'uuid'], 'viewer_user_id' => ['required', 'integer', 'min:1'],
        ])->validate();
        abort_unless((int) $input['viewer_user_id'] === (int) $viewer->id, 403, 'The signed-in account changed.');
    }

    /** Locks match API publication: ordered Users -> ordered identities -> current evidence. */
    private function context(User $viewer, array $input, ?int $targetId, Closure $action): array
    {
        $uuid = strtolower($input['request_uuid']);
        $discoveredIds = [];
        // A same-actor command may publish after our initial routing snapshot.
        // Retry only lock planning, never an action or an uncertain commit.
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $snapshot = ItServiceIdentityCommandReceipt::query()->where('actor_user_id', $viewer->id)
                ->where('request_uuid', $uuid)->first();
            $identityIds = array_values(array_unique(array_filter([$targetId, $snapshot?->service_identity_id, ...$discoveredIds])));
            $identities = ItServiceIdentity::query()->whereKey($identityIds)->get()->keyBy('id');
            $userIds = collect([$viewer->id, $input['actor_user_id'] ?? null])
                ->merge($identities->pluck('actor_user_id'))->merge($identities->pluck('created_by_user_id'))
                ->filter(fn ($id): bool => is_numeric($id) && (int) $id > 0)->map(fn ($id): int => (int) $id)->unique()->sort()->values();
            $retryIdentity = null;
            $result = DB::transaction(function () use ($viewer, $input, $targetId, $uuid, $identities, $identityIds, $userIds, $action, &$retryIdentity): ?array {
                $users = User::query()->whereKey($userIds->all())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                abort_unless($users->has($viewer->id), 404);
                $locked = ItServiceIdentity::query()->whereKey($identityIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                foreach ($locked as $id => $record) {
                    abort_unless($identities->has($id)
                        && (int) $record->actor_user_id === (int) $identities[$id]->actor_user_id
                        && (int) $record->created_by_user_id === (int) $identities[$id]->created_by_user_id, 409);
                }
                // This is the command transaction's first consistent read, after
                // the actor mutex. A waiting command therefore sees its owner's
                // committed receipt. Never lock a missing compound key: unrelated
                // actors can share its gap and deadlock on later receipt inserts.
                $receipt = ItServiceIdentityCommandReceipt::query()->where('actor_user_id', $viewer->id)
                    ->where('request_uuid', $uuid)->first();
                if ($receipt) {
                    $receipt = ItServiceIdentityCommandReceipt::query()->whereKey($receipt->id)
                        ->lockForUpdate()->firstOrFail();
                    abort_unless((int) $receipt->actor_user_id === (int) $viewer->id
                        && $receipt->request_uuid === $uuid, 409);
                }
                if ($receipt?->service_identity_id && ! $locked->has($receipt->service_identity_id)) {
                    $retryIdentity = (int) $receipt->service_identity_id;

                    return null;
                }
                $users = app(AuthorizationEvidenceLockService::class)->lockForUsers($users, ['*']);
                app(ItWorkAccessService::class)->lockApprovedSiteEvidenceForUsers($users);
                $manager = $users->get($viewer->id);
                try {
                    $this->credentials->guardManager($manager, true);
                } catch (DomainException) {
                    abort(403, 'Current IT management access is required.');
                }
                if ($targetId) {
                    abort_unless($locked->has($targetId), 404);
                }
                foreach ($locked as $record) {
                    abort_unless($this->credentials->canManageLoadedIdentity($manager, $record, true), 404);
                }
                if (isset($input['actor_user_id']) && ! $users->has((int) $input['actor_user_id'])) {
                    throw ValidationException::withMessages(['actor_user_id' => 'Choose a currently approved IT execution account.']);
                }

                return $action($manager, $users, $targetId ? $locked->get($targetId) : null, $receipt);
            });
            if ($retryIdentity === null) {
                return $result;
            }
            $discoveredIds[] = $retryIdentity;
        }
        $this->conflictUnless(false, 'identity_changed', 'The identity changed while checking this request. Recover its current outcome.');
    }

    private function validatedPayload(string $operation, array $payload): array
    {
        $rules = in_array($operation, ['issue', 'update'], true)
            ? StoreItServiceIdentityRequest::configurationRules($operation === 'issue') : [];
        if ($operation !== 'issue') {
            $rules['expected_version'] = ['required', 'integer', 'min:1'];
        }
        $allowed = array_filter(array_keys($rules), fn (string $key): bool => ! str_contains($key, '.'));
        if (array_diff(array_keys($payload), $allowed) !== []) {
            throw ValidationException::withMessages(['identity' => 'This command contains unsupported fields.']);
        }
        $data = Validator::make($payload, $rules)->validate();
        if (in_array($operation, ['issue', 'update'], true)) {
            if (array_diff(ItServiceIdentity::REQUIRED_CREATE_FIELDS, $data['create_fields']) !== []) {
                throw ValidationException::withMessages(['create_fields' => 'Title, category, priority and work type must remain enabled for intake.']);
            }
            if (($data['update_fields'] ?? []) !== [] && ! in_array('work:update', $data['abilities'], true)) {
                throw ValidationException::withMessages(['update_fields' => 'Enable the update operation before granting update fields.']);
            }
            $data['allowed_fields'] = [
                'create' => array_values(array_unique($data['create_fields'])),
                'read' => array_values(array_unique($data['read_fields'])),
                'update' => array_values(array_unique($data['update_fields'] ?? [])),
            ];
        }

        return $data;
    }

    private function result(User $viewer, ItServiceIdentityCommandReceipt $receipt, bool $replayed): array
    {
        return [
            'viewer_user_id' => $viewer->id, 'request_uuid' => $receipt->request_uuid,
            'operation' => $receipt->operation, 'state' => $receipt->state,
            'identity_id' => $receipt->service_identity_id, 'configuration_version' => $receipt->result_version,
            'replayed' => $replayed, 'credential' => null,
            'credential_unavailable' => $receipt->state === 'confirmed' && in_array($receipt->operation, ['issue', 'rotate'], true),
        ];
    }

    private function conflictUnless(bool $condition, string $code, string $message): void
    {
        if (! $condition) {
            throw new HttpResponseException(response()->json([
                'code' => $code, 'message' => $message,
            ], 409)->header('Cache-Control', 'no-store, private'));
        }
    }
}
