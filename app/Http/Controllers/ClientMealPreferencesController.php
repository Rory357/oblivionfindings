<?php

namespace App\Http\Controllers;

use App\Domain\Clinical\Services\ClientMealRestrictionProjection;
use App\Models\Client;
use App\Models\ClientMealDislike;
use App\Models\MealDietaryTag;
use App\Models\MealProduct;
use Illuminate\Http\Request;

class ClientMealPreferencesController extends Controller
{
    public function __construct(
        private readonly ClientMealRestrictionProjection $restrictionProjection,
    ) {}

    /**
     * Returns the resident's food & meal preferences for the
     * "Food & Meal Preferences" card on the client profile page.
     */
    public function show(Client $client)
    {
        $this->authorize('manageMeals', $client);
        $client->load(['mealDislikes.product:id,name,default_unit']);
        $restriction = $this->restrictionProjection->forClient($client);
        $selectedTags = MealDietaryTag::query()
            ->whereIn('id', array_values(array_unique([
                ...$restriction['allergen_tag_ids'],
                ...$restriction['dietary_tag_ids'],
            ])))
            ->get()
            ->keyBy('id');

        return response()->json([
            'client_id' => $client->id,
            'allergens' => collect($restriction['allergen_tag_ids'])->map(fn (int $id) => $this->tagPayload($selectedTags[$id]))->values(),
            'preferences' => collect($restriction['dietary_tag_ids'])->map(fn (int $id) => $this->tagPayload($selectedTags[$id]))->values(),
            'dislikes' => $client->mealDislikes->map(fn (ClientMealDislike $d) => [
                'id' => $d->id,
                'product_id' => $d->product_id,
                'product_name' => $d->product?->name,
                'free_text_name' => $d->free_text_name,
                'notes' => $d->notes,
            ]),
            'tag_catalogue' => [
                'allergens' => MealDietaryTag::where('kind', 'allergen')->orderBy('label')->get()->map(fn ($t) => $this->tagPayload($t)),
                'preferences' => MealDietaryTag::where('kind', 'dietary')->orderBy('label')->get()->map(fn ($t) => $this->tagPayload($t)),
            ],
            'products' => MealProduct::active()->orderBy('name')->get(['id', 'name', 'default_unit']),
            'restrictions_read_only' => true,
            'restriction_authority' => [
                'status' => $restriction['authority_status'],
                'version' => $restriction['version'],
                'effective_from' => $restriction['effective_from'],
                'effective_until' => $restriction['effective_until'],
                'review_due_at' => $restriction['review_due_at'],
                'approved_by' => $restriction['approved_by'],
            ],
        ]);
    }

    public function syncTags(Request $request, Client $client)
    {
        $this->authorize('manageMeals', $client);
        abort(409, 'Clinical meal restrictions are read-only here; use the independent clinical approval workflow.');
    }

    public function storeDislike(Request $request, Client $client)
    {
        $this->authorize('manageMeals', $client);
        $data = $request->validate([
            'product_id' => 'nullable|integer|exists:meal_products,id',
            'free_text_name' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:500',
        ]);

        if (empty($data['product_id']) && empty($data['free_text_name'])) {
            return back()->withErrors(['free_text_name' => 'Pick a product or type a name.']);
        }

        ClientMealDislike::create([
            'client_id' => $client->id,
            'product_id' => $data['product_id'] ?? null,
            'free_text_name' => $data['free_text_name'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => auth()->id(),
        ]);

        return back()->with('status', 'Dislike added');
    }

    public function destroyDislike(Client $client, ClientMealDislike $dislike)
    {
        $this->authorize('manageMeals', $client);
        abort_unless($dislike->client_id === $client->id, 404);
        $dislike->delete();

        return back()->with('status', 'Dislike removed');
    }

    private function tagPayload(MealDietaryTag $tag): array
    {
        return [
            'id' => $tag->id,
            'key' => $tag->key,
            'label' => $tag->label,
            'kind' => $tag->kind,
            'severity' => $tag->severity,
            'color' => $tag->color,
        ];
    }
}
