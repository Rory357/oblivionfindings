<?php

namespace App\Http\Controllers\Sites;

use App\Domain\Clinical\Models\ClientMealRestriction;
use App\Domain\Clinical\Services\ClientMealRestrictionService;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SiteMealRestrictionController extends Controller
{
    public function __construct(
        private readonly ClientMealRestrictionService $restrictions,
    ) {}

    public function propose(Request $request, Site $site, Client $client)
    {
        $this->authorize('view', $site);
        abort_unless($request->user()?->canDo('clinical.mealRestrictions.author'), 403);
        abort_unless((int) $client->site_id === (int) $site->id, 404);

        $data = $request->validate([
            'expected_current_id' => 'nullable|integer',
            'effective_from' => 'required|date',
            'effective_until' => 'nullable|date|after_or_equal:effective_from',
            'review_due_at' => 'required|date|after_or_equal:effective_from',
            'iddsi_food_level' => ['nullable', 'integer', Rule::in(array_keys(ClientMealRestriction::FOOD_LEVELS))],
            'fluid_iddsi_level' => ['nullable', 'integer', Rule::in(array_keys(ClientMealRestriction::FLUID_LEVELS))],
            'allergen_tag_ids' => 'present|array',
            'allergen_tag_ids.*' => 'integer|distinct|exists:meal_dietary_tags,id',
            'dietary_tag_ids' => 'present|array',
            'dietary_tag_ids.*' => 'integer|distinct|exists:meal_dietary_tags,id',
            'clinical_notes' => 'nullable|string|max:4000',
            'amendment_reason' => 'required|string|min:10|max:2000',
        ]);

        if (! empty($data['effective_until'])
            && CarbonImmutable::parse($data['review_due_at'])->gt(CarbonImmutable::parse($data['effective_until']))) {
            throw ValidationException::withMessages([
                'review_due_at' => ['The review date must not be after the restriction expires.'],
            ]);
        }

        $restriction = $this->restrictions->propose($client, $request->user(), $data);

        return response()->json(['restriction' => $this->restrictionPayload($restriction)], 201);
    }

    public function approve(
        Request $request,
        Site $site,
        Client $client,
        ClientMealRestriction $restriction,
    ) {
        $this->authorize('view', $site);
        abort_unless($request->user()?->canDo('clinical.mealRestrictions.approve'), 403);
        abort_unless((int) $client->site_id === (int) $site->id, 404);
        abort_unless(
            (int) $restriction->site_id === (int) $site->id
            && (int) $restriction->client_id === (int) $client->id,
            404,
        );

        $data = $request->validate([
            'idempotency_key' => 'required|uuid',
        ]);

        $restriction = $this->restrictions->approve(
            $restriction,
            $request->user(),
            $data['idempotency_key'],
        );

        return response()->json(['restriction' => $this->restrictionPayload($restriction)]);
    }

    public function reportDiscrepancy(Request $request, Site $site, Client $client)
    {
        $this->authorize('view', $site);
        abort_unless($request->user()?->canDo('sites.meals.view'), 403);
        abort_unless((int) $client->site_id === (int) $site->id, 404);

        $data = $request->validate([
            'details' => 'required|string|min:10|max:2000',
            'idempotency_key' => 'required|uuid',
        ]);

        $discrepancy = $this->restrictions->reportDiscrepancy(
            $client,
            $request->user(),
            $data['details'],
            $data['idempotency_key'],
        );

        return response()->json([
            'discrepancy' => [
                'id' => $discrepancy->id,
                'status' => $discrepancy->status,
                'reported_at' => $discrepancy->reported_at?->toIso8601String(),
            ],
        ], $discrepancy->wasRecentlyCreated ? 201 : 200);
    }

    /** @return array<string, mixed> */
    private function restrictionPayload(ClientMealRestriction $restriction): array
    {
        return [
            'id' => $restriction->id,
            'site_id' => $restriction->site_id,
            'client_id' => $restriction->client_id,
            'version' => $restriction->version,
            'status' => $restriction->status,
            'replaces_id' => $restriction->replaces_id,
            'effective_from' => $restriction->effective_from?->toDateString(),
            'effective_until' => $restriction->effective_until?->toDateString(),
            'review_due_at' => $restriction->review_due_at?->toDateString(),
            'iddsi_food_level' => $restriction->iddsi_food_level,
            'iddsi_food_label' => $restriction->iddsi_food_label,
            'fluid_iddsi_level' => $restriction->fluid_iddsi_level,
            'fluid_label' => $restriction->fluid_label,
            'allergen_tag_ids' => $restriction->allergen_tag_ids,
            'dietary_tag_ids' => $restriction->dietary_tag_ids,
            'clinical_notes' => $restriction->clinical_notes,
            'amendment_reason' => $restriction->amendment_reason,
            'content_hash' => $restriction->content_hash,
            'proposed_by' => $restriction->proposer ? [
                'id' => $restriction->proposer->id,
                'name' => $restriction->proposer->name,
            ] : null,
            'proposed_at' => $restriction->proposed_at?->toIso8601String(),
            'approved_by' => $restriction->approver ? [
                'id' => $restriction->approver->id,
                'name' => $restriction->approver->name,
            ] : null,
            'approved_at' => $restriction->approved_at?->toIso8601String(),
        ];
    }
}
