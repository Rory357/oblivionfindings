<?php

namespace App\Domain\Clinical\Services;

use App\Domain\Clinical\Models\ClientMealRestriction;
use App\Domain\Clinical\Models\ClientMealRestrictionDiscrepancy;
use App\Models\Client;
use App\Models\MealDietaryTag;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClientMealRestrictionService
{
    public function __construct(
        private readonly ClientMealRestrictionProjection $projection,
    ) {}

    /** @param array<string, mixed> $data */
    public function propose(Client $client, User $proposer, array $data): ClientMealRestriction
    {
        return DB::transaction(function () use ($client, $proposer, $data): ClientMealRestriction {
            $lockedClient = Client::query()->whereKey($client->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $lockedClient->site_id === (int) $client->site_id, 409, 'The resident Site changed; reload before proposing restrictions.');

            $pendingExists = ClientMealRestriction::query()
                ->where('client_id', $client->id)
                ->where('status', ClientMealRestriction::STATUS_PENDING)
                ->exists();
            abort_if($pendingExists, 409, 'A restriction amendment is already awaiting independent approval.');

            $latestAuthorised = ClientMealRestriction::query()
                ->where('client_id', $client->id)
                ->where('status', ClientMealRestriction::STATUS_AUTHORISED)
                ->orderByDesc('version')
                ->first();
            $expectedCurrentId = isset($data['expected_current_id'])
                ? (int) $data['expected_current_id']
                : null;
            abort_if(
                ($latestAuthorised?->id ?? null) !== $expectedCurrentId,
                409,
                'The authorised restriction changed; reload before proposing an amendment.',
            );

            $allergenIds = ClientMealRestriction::normaliseIds($data['allergen_tag_ids'] ?? []);
            $dietaryIds = ClientMealRestriction::normaliseIds($data['dietary_tag_ids'] ?? []);
            $this->assertTagKinds($allergenIds, $dietaryIds);

            $version = ((int) ClientMealRestriction::query()
                ->where('client_id', $client->id)
                ->max('version')) + 1;

            $restriction = new ClientMealRestriction([
                'site_id' => $client->site_id,
                'client_id' => $client->id,
                'version' => $version,
                'status' => ClientMealRestriction::STATUS_PENDING,
                'replaces_id' => $latestAuthorised?->id,
                'proposed_by' => $proposer->id,
                'proposed_at' => now(),
                'effective_from' => $data['effective_from'],
                'effective_until' => $data['effective_until'] ?? null,
                'review_due_at' => $data['review_due_at'],
                'iddsi_food_level' => $data['iddsi_food_level'] ?? null,
                'iddsi_food_label' => isset($data['iddsi_food_level'])
                    ? ClientMealRestriction::FOOD_LEVELS[(int) $data['iddsi_food_level']]
                    : null,
                'fluid_iddsi_level' => $data['fluid_iddsi_level'] ?? null,
                'fluid_label' => isset($data['fluid_iddsi_level'])
                    ? ClientMealRestriction::FLUID_LEVELS[(int) $data['fluid_iddsi_level']]
                    : null,
                'allergen_tag_ids' => $allergenIds,
                'dietary_tag_ids' => $dietaryIds,
                'clinical_notes' => $this->nullableTrim($data['clinical_notes'] ?? null),
                'amendment_reason' => trim((string) $data['amendment_reason']),
            ]);
            $restriction->content_hash = $restriction->calculateContentHash();
            $restriction->save();

            return $restriction->fresh(['proposer:id,name']);
        }, 3);
    }

    public function approve(
        ClientMealRestriction $restriction,
        User $approver,
        string $replayKey,
    ): ClientMealRestriction {
        return DB::transaction(function () use ($restriction, $approver, $replayKey): ClientMealRestriction {
            $locked = ClientMealRestriction::query()->whereKey($restriction->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === ClientMealRestriction::STATUS_AUTHORISED) {
                abort_unless(
                    hash_equals((string) $locked->approval_replay_key, $replayKey)
                    && (int) $locked->approved_by === (int) $approver->id,
                    409,
                    'This restriction has already been approved.',
                );

                return $locked->load(['proposer:id,name', 'approver:id,name']);
            }

            abort_unless($locked->status === ClientMealRestriction::STATUS_PENDING, 409, 'Only a pending restriction can be approved.');
            abort_if((int) $locked->proposed_by === (int) $approver->id, 422, 'The author cannot approve their own restriction amendment.');
            abort_unless(
                hash_equals($locked->content_hash, $locked->calculateContentHash()),
                409,
                'Restriction provenance verification failed.',
            );

            $replayed = ClientMealRestriction::query()
                ->where('approval_replay_key', $replayKey)
                ->whereKeyNot($locked->id)
                ->exists();
            abort_if($replayed, 409, 'This approval request was already used for another restriction.');

            $locked->update([
                'status' => ClientMealRestriction::STATUS_AUTHORISED,
                'approved_by' => $approver->id,
                'approved_at' => now(),
                'approval_replay_key' => $replayKey,
            ]);

            return $locked->fresh(['proposer:id,name', 'approver:id,name']);
        }, 3);
    }

    public function reportDiscrepancy(
        Client $client,
        User $reporter,
        string $details,
        string $replayKey,
    ): ClientMealRestrictionDiscrepancy {
        return DB::transaction(function () use ($client, $reporter, $details, $replayKey): ClientMealRestrictionDiscrepancy {
            Client::query()->whereKey($client->id)->lockForUpdate()->firstOrFail();
            $details = trim($details);

            $existing = ClientMealRestrictionDiscrepancy::query()
                ->where('report_replay_key', $replayKey)
                ->first();
            if ($existing) {
                abort_unless(
                    (int) $existing->site_id === (int) $client->site_id
                    && (int) $existing->client_id === (int) $client->id
                    && (int) $existing->reported_by === (int) $reporter->id
                    && hash_equals($existing->details, $details),
                    409,
                    'This discrepancy request key was already used.',
                );

                return $existing;
            }

            $projection = $this->projection->forClient($client);

            return ClientMealRestrictionDiscrepancy::create([
                'site_id' => $client->site_id,
                'client_id' => $client->id,
                'restriction_id' => $projection['restriction_id'],
                'reported_by' => $reporter->id,
                'report_replay_key' => $replayKey,
                'status' => 'open',
                'details' => $details,
                'reported_at' => now(),
            ]);
        }, 3);
    }

    /** @param list<int> $allergenIds
     * @param  list<int>  $dietaryIds
     */
    private function assertTagKinds(array $allergenIds, array $dietaryIds): void
    {
        $ids = array_values(array_unique([...$allergenIds, ...$dietaryIds]));
        $tags = MealDietaryTag::query()->whereIn('id', $ids)->get(['id', 'kind'])->keyBy('id');
        $valid = $tags->count() === count($ids)
            && collect($allergenIds)->every(fn (int $id): bool => $tags->get($id)?->kind === 'allergen')
            && collect($dietaryIds)->every(fn (int $id): bool => $tags->get($id)?->kind === 'dietary');

        if (! $valid) {
            throw ValidationException::withMessages([
                'allergen_tag_ids' => ['Allergen and dietary tags must use the matching clinical category.'],
            ]);
        }
    }

    private function nullableTrim(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
