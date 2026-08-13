<?php

namespace App\Domain\Clinical\Services;

use App\Domain\Clinical\Models\ClientMealRestriction;
use App\Domain\Clinical\Models\ClientMealRestrictionDiscrepancy;
use App\Models\Client;
use App\Models\MealDietaryTag;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class ClientMealRestrictionProjection
{
    /**
     * Return the single clinically authorised restriction that governs a meal
     * on the requested date. Any provenance or lifecycle defect is exposed as
     * an unsafe authority status instead of falling back to mutable legacy data.
     *
     * @return array<string, mixed>
     */
    public function forClient(Client $client, CarbonInterface|string|null $onDate = null): array
    {
        $date = $onDate instanceof CarbonInterface
            ? CarbonImmutable::instance($onDate)->startOfDay()
            : CarbonImmutable::parse($onDate ?? now())->startOfDay();
        $dateString = $date->toDateString();

        $restriction = ClientMealRestriction::query()
            ->where('client_id', $client->id)
            ->where('site_id', $client->site_id)
            ->where('status', ClientMealRestriction::STATUS_AUTHORISED)
            ->whereDate('effective_from', '<=', $dateString)
            ->with(['proposer:id,name', 'approver:id,name'])
            ->orderByDesc('version')
            ->first();

        if (! $restriction) {
            return $this->unavailableProjection($client, $dateString);
        }

        // Once an amendment has become effective it supersedes older versions.
        // An expired latest version must therefore fail closed, never fall back
        // to a less recent restriction that happens to have a wider date range.
        if ($restriction->effective_until?->lt($date)) {
            return $this->baseProjection($client, 'expired', $dateString, $restriction);
        }

        if (! $this->hasValidAuthority($restriction, $client)) {
            return $this->baseProjection($client, 'invalid', $dateString);
        }

        if ($restriction->review_due_at->lt($date)) {
            return $this->baseProjection($client, 'stale', $dateString, $restriction);
        }

        $allergenIds = ClientMealRestriction::normaliseIds($restriction->allergen_tag_ids ?? []);
        $dietaryIds = ClientMealRestriction::normaliseIds($restriction->dietary_tag_ids ?? []);
        $tagIds = array_values(array_unique([...$allergenIds, ...$dietaryIds]));
        $tags = MealDietaryTag::query()
            ->whereIn('id', $tagIds)
            ->get(['id', 'label', 'kind'])
            ->keyBy('id');

        $tagKindsValid = collect($allergenIds)->every(fn (int $id): bool => $tags->get($id)?->kind === 'allergen')
            && collect($dietaryIds)->every(fn (int $id): bool => $tags->get($id)?->kind === 'dietary');
        if ($tags->count() !== count($tagIds) || ! $tagKindsValid) {
            return $this->baseProjection($client, 'invalid', $dateString, $restriction);
        }

        return [
            ...$this->baseProjection($client, 'authorised', $dateString, $restriction),
            'allergen_tag_ids' => $allergenIds,
            'allergens' => collect($allergenIds)->map(fn (int $id) => $tags[$id]->label)->values()->all(),
            'dietary_tag_ids' => $dietaryIds,
            'dietary' => collect($dietaryIds)->map(fn (int $id) => $tags[$id]->label)->values()->all(),
            'texture' => $restriction->iddsi_food_level !== null ? [
                'level' => $restriction->iddsi_food_level,
                'label' => $restriction->iddsi_food_label,
            ] : null,
            'fluid_level' => $restriction->fluid_iddsi_level,
            'fluids' => $restriction->fluid_label,
        ];
    }

    private function hasValidAuthority(ClientMealRestriction $restriction, Client $client): bool
    {
        return (int) $restriction->site_id === (int) $client->site_id
            && (int) $restriction->client_id === (int) $client->id
            && $restriction->approved_by !== null
            && $restriction->approved_at !== null
            && (int) $restriction->approved_by !== (int) $restriction->proposed_by
            && hash_equals($restriction->content_hash, $restriction->calculateContentHash());
    }

    /** @return array<string, mixed> */
    private function unavailableProjection(Client $client, string $date): array
    {
        $authorised = ClientMealRestriction::query()
            ->where('client_id', $client->id)
            ->where('site_id', $client->site_id)
            ->where('status', ClientMealRestriction::STATUS_AUTHORISED);

        $status = 'missing';
        if ((clone $authorised)->whereDate('effective_from', '>', $date)->exists()) {
            $status = 'not_effective';
        } elseif ((clone $authorised)->whereNotNull('effective_until')->whereDate('effective_until', '<', $date)->exists()) {
            $status = 'expired';
        } elseif (ClientMealRestriction::query()
            ->where('client_id', $client->id)
            ->where('site_id', $client->site_id)
            ->where('status', ClientMealRestriction::STATUS_PENDING)
            ->exists()) {
            $status = 'pending_approval';
        }

        return $this->baseProjection($client, $status, $date);
    }

    /** @return array<string, mixed> */
    private function baseProjection(
        Client $client,
        string $status,
        string $date,
        ?ClientMealRestriction $restriction = null,
    ): array {
        return [
            'authority_status' => $status,
            'authority_date' => $date,
            'restriction_id' => $restriction?->id,
            'version' => $restriction?->version,
            'effective_from' => $restriction?->effective_from?->toDateString(),
            'effective_until' => $restriction?->effective_until?->toDateString(),
            'review_due_at' => $restriction?->review_due_at?->toDateString(),
            'approved_at' => $restriction?->approved_at?->toIso8601String(),
            'approved_by' => $restriction?->approver ? [
                'id' => $restriction->approver->id,
                'name' => $restriction->approver->name,
            ] : null,
            'allergen_tag_ids' => [],
            'allergens' => [],
            'dietary_tag_ids' => [],
            'dietary' => [],
            'texture' => null,
            'fluid_level' => null,
            'fluids' => null,
            'open_discrepancies' => ClientMealRestrictionDiscrepancy::query()
                ->where('client_id', $client->id)
                ->where('site_id', $client->site_id)
                ->where('status', 'open')
                ->count(),
        ];
    }
}
