<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientMealDislike;
use App\Models\MealDietaryTag;
use App\Models\MealProduct;
use Illuminate\Http\Request;

class ClientMealPreferencesController extends Controller
{
    /**
     * Returns the resident's food & meal preferences for the
     * "Food & Meal Preferences" card on the client profile page.
     */
    public function show(Client $client)
    {
        $client->load(['mealDietaryTags', 'mealDislikes.product:id,name,default_unit']);

        return response()->json([
            'client_id' => $client->id,
            'allergens' => $client->mealDietaryTags->where('kind', 'allergen')->values()->map(fn (MealDietaryTag $t) => $this->tagPayload($t)),
            'preferences' => $client->mealDietaryTags->where('kind', 'dietary')->values()->map(fn (MealDietaryTag $t) => $this->tagPayload($t)),
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
        ]);
    }

    public function syncTags(Request $request, Client $client)
    {
        $data = $request->validate([
            'tag_ids' => 'nullable|array',
            'tag_ids.*' => 'integer|exists:meal_dietary_tags,id',
        ]);
        $client->mealDietaryTags()->sync($data['tag_ids'] ?? []);
        return back()->with('status', 'Dietary tags updated');
    }

    public function storeDislike(Request $request, Client $client)
    {
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
