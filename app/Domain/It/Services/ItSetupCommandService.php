<?php

namespace App\Domain\It\Services;

use App\Models\ItCatalogItem;
use App\Models\ItProvisioningTemplate;
use App\Models\ItQueue;
use App\Models\ItService;
use App\Models\ItSetupCommandReceipt;
use App\Models\ItTeam;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ItSetupCommandService
{
    public function __construct(
        private readonly ItServiceManagementSetupService $setup,
        private readonly ItCatalogManagementService $catalogue,
        private readonly ItProvisioningTemplateService $templates,
        private readonly ItWorkAccessService $workAccess,
    ) {}

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function create(User $actor, string $resource, array $data): array
    {
        $uuid = strtolower((string) ($data['request_uuid'] ?? ''));
        abort_unless(Str::isUuid($uuid), 422, 'A valid create command identity is required.');
        $payload = Arr::except($data, ['actor_user_id', 'request_uuid', 'configuration_version']);
        $hash = hash('sha256', json_encode(
            in_array($resource, ['catalogue-items', 'provisioning-templates'], true)
                ? $this->orderedContract($payload) : Arr::sortRecursive($payload),
            JSON_THROW_ON_ERROR,
        ));

        return DB::transaction(function () use ($actor, $resource, $data, $uuid, $payload, $hash): array {
            $actor = $this->lockedActor($actor, (int) ($data['actor_user_id'] ?? 0));
            $receipt = ItSetupCommandReceipt::query()->where('actor_user_id', $actor->id)
                ->where('request_uuid', $uuid)->lockForUpdate()->first();
            if ($receipt) {
                abort_if($receipt->resource !== $resource, 409, 'This command belongs to another setup resource.');
                if ($receipt->cancelled_at) {
                    return $this->cancelledResult($actor, $receipt);
                }
                if (! hash_equals((string) $receipt->request_hash, $hash)) {
                    throw new HttpException(409, 'This command identity already belongs to a different create request. Recover its original outcome before starting another.');
                }

                return $this->committedResult($actor, $receipt, true);
            }
            [$table, $uniqueField] = match ($resource) {
                'teams' => ['it_teams', 'name'],
                'queues' => ['it_queues', 'key'],
                'services' => ['it_services', 'key'],
                'catalogue-items', 'provisioning-templates' => [null, null],
            };
            // Replay must be checked before the original name/key becomes a
            // duplicate. A new command still receives normal validation.
            if ($uniqueField !== null) {
                Validator::make($payload, [$uniqueField => ['required', Rule::unique($table, $uniqueField)]])->validate();
            }
            $receipt = ItSetupCommandReceipt::query()->create([
                'actor_user_id' => $actor->id, 'resource' => $resource,
                'request_uuid' => $uuid, 'request_hash' => $hash,
            ]);
            $record = match ($resource) {
                'teams' => $this->setup->createTeam($actor, $payload),
                'queues' => $this->setup->createQueue($actor, $payload),
                'services' => $this->setup->createService($actor, $payload),
                'catalogue-items' => $this->catalogue->create($actor, $payload),
                'provisioning-templates' => $this->templates->create($actor, $payload),
            };
            $receipt->forceFill([
                $this->recordColumn($resource) => $record->id,
                'committed_configuration_version' => $this->version($record), 'committed_at' => now(),
            ])->save();

            return $this->committedResult($actor, $receipt, false);
        });
    }

    /** @return array<string, mixed> */
    public function recover(User $actor, string $resource, string $uuid, int $originalActorId): array
    {
        return DB::transaction(function () use ($actor, $resource, $uuid, $originalActorId): array {
            $actor = $this->lockedActor($actor, $originalActorId);
            $receipt = ItSetupCommandReceipt::query()->where('actor_user_id', $actor->id)
                ->where('request_uuid', strtolower($uuid))->lockForUpdate()->first();
            if (! $receipt) {
                return ['status' => 'not_found', 'data' => [
                    'viewer_user_id' => $actor->id, 'resource' => $resource,
                    'request_uuid' => strtolower($uuid), 'retry_same_command' => true,
                ]];
            }
            abort_if($receipt->resource !== $resource, 409, 'This command belongs to another setup resource.');

            if ($receipt->cancelled_at) {
                return $this->cancelledResult($actor, $receipt);
            }

            return $this->committedResult($actor, $receipt, true);
        });
    }

    private function lockedActor(User $actor, int $originalActorId): User
    {
        User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();

        return $this->setup->reviewActor($actor, $originalActorId);
    }

    /** Cancel an uncommitted identity; a committed result is never undone. */
    public function cancel(User $actor, string $resource, string $uuid, int $originalActorId): array
    {
        return DB::transaction(function () use ($actor, $resource, $uuid, $originalActorId): array {
            $actor = $this->lockedActor($actor, $originalActorId);
            $receipt = ItSetupCommandReceipt::query()->where('actor_user_id', $actor->id)
                ->where('request_uuid', strtolower($uuid))->lockForUpdate()->first();
            if ($receipt) {
                abort_if($receipt->resource !== $resource, 409, 'This command belongs to another setup resource.');

                return $receipt->cancelled_at ? $this->cancelledResult($actor, $receipt) : $this->committedResult($actor, $receipt, true);
            }
            $receipt = ItSetupCommandReceipt::query()->create([
                'actor_user_id' => $actor->id, 'resource' => $resource, 'request_uuid' => strtolower($uuid), 'cancelled_at' => now(),
            ]);
            AuditLogger::logOrFail('it.setup.create.cancelled', $receipt, ['actor_id' => $actor->id, 'resource' => $resource]);

            return $this->cancelledResult($actor, $receipt);
        });
    }

    private function cancelledResult(User $actor, ItSetupCommandReceipt $receipt): array
    {
        return ['status' => 'cancelled', 'data' => [
            'viewer_user_id' => $actor->id, 'resource' => $receipt->resource,
            'request_uuid' => $receipt->request_uuid, 'cancelled' => true,
        ]];
    }

    private function recordColumn(string $resource): string
    {
        return match ($resource) {
            'teams' => 'it_team_id', 'queues' => 'it_queue_id', 'services' => 'it_service_id',
            'catalogue-items' => 'it_catalog_item_id',
            'provisioning-templates' => 'it_provisioning_template_id',
        };
    }

    private function version(Model $record): string
    {
        return match (true) {
            $record instanceof ItTeam => $this->setup->teamVersion($record),
            $record instanceof ItQueue => $this->setup->queueVersion($record),
            $record instanceof ItService => $this->setup->serviceVersion($record),
            $record instanceof ItCatalogItem => hash('sha256', 'catalogue:'.$record->id.':'.$record->lock_version),
            $record instanceof ItProvisioningTemplate => hash('sha256', 'provisioning-template:'.$record->id.':'.$record->lock_version),
        };
    }

    /** @return array<string, mixed> */
    private function committedResult(User $actor, ItSetupCommandReceipt $receipt, bool $replayed): array
    {
        abort_unless($receipt->committed_at && $receipt->{$this->recordColumn($receipt->resource)}, 503, 'The create result cannot yet be confirmed. Check its outcome again.');
        $model = match ($receipt->resource) {
            'teams' => ItTeam::class, 'queues' => ItQueue::class, 'services' => ItService::class,
            'catalogue-items' => ItCatalogItem::class,
            'provisioning-templates' => ItProvisioningTemplate::class,
        };
        $record = $model::query()->findOrFail($receipt->{$this->recordColumn($receipt->resource)});
        if ($record instanceof ItProvisioningTemplate) {
            // Receipt ownership never bypasses the current canonical Site boundary.
            abort_unless($record->site_id === null || $actor->canDo('it.organisationWide')
                || in_array((int) $record->site_id, $this->workAccess->approvedSiteIds($actor), true), 404);

            return $this->result($actor, $receipt, $record, $replayed);
        }
        // Catalogue authoring uses the current approved IT manager boundary.
        // lockedActor revalidates it for every create, recovery and cancellation;
        // receipt ownership alone never grants access to an archived record.
        if ($record instanceof ItCatalogItem) {
            return $this->result($actor, $receipt, $record, $replayed);
        }
        $fields = match (true) {
            $record instanceof ItTeam => [...$record->only(['manager_user_id']), 'members' => $record->members->map(fn (User $member) => ['user_id' => $member->id, 'role' => $member->pivot->role])->all()],
            $record instanceof ItQueue => [...($record->filter_rules ?? []), 'team_id' => $record->team_id],
            $record instanceof ItService => $record->only(['owner_user_id']),
        };
        // The receipt is not a permanent authorization grant to its result.
        $this->setup->authorizeCandidate($actor, [
            'actor_user_id' => $actor->id, 'resource' => $receipt->resource, 'record_id' => $record->id,
            'configuration_version' => $receipt->committed_configuration_version,
            'context_uuid' => $receipt->request_uuid, 'candidate_uuid' => $receipt->request_uuid,
            'base_fields' => $fields, 'fields' => [], 'bound_scopes' => [],
        ]);

        return $this->result($actor, $receipt, $record, $replayed);
    }

    private function result(User $actor, ItSetupCommandReceipt $receipt, Model $record, bool $replayed): array
    {
        return ['status' => 'committed', 'data' => [
            'viewer_user_id' => $actor->id, 'resource' => $receipt->resource, 'request_uuid' => $receipt->request_uuid,
            'id' => $record->id, 'committed_configuration_version' => $receipt->committed_configuration_version,
            'configuration_version' => $this->version($record), 'replayed' => $replayed,
        ]];
    }

    /** Sort object keys for stable retries, retaining authored field/choice order. */
    private function orderedContract(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $child): mixed => $this->orderedContract($child), $value);
    }
}
