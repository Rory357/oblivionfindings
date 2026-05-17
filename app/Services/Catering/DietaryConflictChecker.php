<?php

namespace App\Services\Catering;

use App\Models\Client;
use App\Models\ClientMealDislike;
use App\Models\MealDietaryTag;
use App\Models\MealRecipe;

class DietaryConflictChecker
{
    /**
     * Inspect a recipe against the food allergies, dietary preferences
     * and dislikes of a set of clients.
     *
     * Returns a structured report:
     *
     *   [
     *     'has_hard_blocks' => bool,       // any critical allergen match → save must be gated
     *     'has_soft_warnings' => bool,     // warn-severity tag OR a dislike match → soft warning
     *     'hard_blocks' => [               // one entry per resident with at least one critical match
     *       [
     *         'client_id' => int,
     *         'client_name' => string,
     *         'matches' => [
     *           ['label' => string, 'severity' => 'critical', 'kind' => 'allergen',
     *            'source' => 'recipe_tag'|'product_tag'],
     *         ],
     *       ],
     *     ],
     *     'soft_warnings' => [             // dislikes + warn-severity tags
     *       [
     *         'client_id' => int,
     *         'client_name' => string,
     *         'matches' => [
     *           ['label' => string, 'severity' => 'warn'|'dislike', 'kind' => 'dietary'|'dislike',
     *            'source' => 'recipe_tag'|'product_tag'|'ingredient_name'|'recipe_name'],
     *         ],
     *       ],
     *     ],
     *     'recipe_tag_ids' => array<int>,
     *   ]
     *
     * @param  array<int>  $clientIds
     */
    public function checkRecipeAgainstClients(MealRecipe $recipe, array $clientIds): array
    {
        $clientIds = array_values(array_unique(array_filter($clientIds)));
        $recipe->loadMissing(['tags', 'ingredients.product.tags']);

        $recipeTagIds = $this->collectRecipeAllergenTagIds($recipe);
        $recipeTagsById = $this->tagLookup($recipeTagIds);
        $haystack = $this->buildNameHaystack($recipe);

        $hardBlocks = [];
        $softWarnings = [];

        if (empty($clientIds)) {
            return $this->emptyReport($recipeTagIds);
        }

        $clients = Client::with([
            'mealDietaryTags' => function ($q) use ($recipeTagIds) {
                if ($recipeTagIds) {
                    $q->whereIn('meal_dietary_tags.id', $recipeTagIds);
                }
            },
            'mealDislikes.product',
        ])->whereIn('id', $clientIds)->get();

        foreach ($clients as $client) {
            $hard = [];
            $soft = [];

            // 1. Tag matches (allergens + dietary tags) — driven by recipe tag intersection.
            foreach ($client->mealDietaryTags as $tag) {
                $entry = [
                    'label' => $tag->label,
                    'severity' => $tag->severity,
                    'kind' => $tag->kind,
                    'source' => $this->locateRecipeTagSource($tag->id, $recipeTagsById, $recipe),
                ];
                if ($tag->kind === 'allergen' || $tag->severity === 'critical') {
                    $hard[] = $entry + ['severity' => 'critical'];
                } else {
                    $soft[] = $entry;
                }
            }

            // 2. Dislike matches — name-based substring against haystack.
            foreach ($client->mealDislikes as $dislike) {
                $needle = trim(strtolower($dislike->matchTerm()));
                if ($needle === '') {
                    continue;
                }
                $matched = $this->matchDislike($needle, $haystack, $dislike);
                if ($matched !== null) {
                    $soft[] = [
                        'label' => $dislike->displayName(),
                        'severity' => 'dislike',
                        'kind' => 'dislike',
                        'source' => $matched,
                    ];
                }
            }

            $clientName = trim($client->first_name . ' ' . $client->last_name);
            if ($hard) {
                $hardBlocks[] = ['client_id' => $client->id, 'client_name' => $clientName, 'matches' => $hard];
            }
            if ($soft) {
                $softWarnings[] = ['client_id' => $client->id, 'client_name' => $clientName, 'matches' => $soft];
            }
        }

        return [
            'has_hard_blocks' => !empty($hardBlocks),
            'has_soft_warnings' => !empty($softWarnings),
            'hard_blocks' => $hardBlocks,
            'soft_warnings' => $softWarnings,
            'recipe_tag_ids' => $recipeTagIds,
        ];
    }

    /**
     * @return array<int>
     */
    public function collectRecipeAllergenTagIds(MealRecipe $recipe): array
    {
        $recipe->loadMissing(['tags', 'ingredients.product.tags']);

        $direct = $recipe->tags->pluck('id');
        $fromProducts = $recipe->ingredients
            ->filter(fn ($i) => $i->product)
            ->flatMap(fn ($i) => $i->product->tags->pluck('id'));

        return $direct->merge($fromProducts)->unique()->values()->all();
    }

    /**
     * Build the lowercase haystack of all names a dislike substring
     * can match against. Order matters for source attribution.
     *
     * @return array<int, array{text:string, source:string}>
     */
    private function buildNameHaystack(MealRecipe $recipe): array
    {
        $haystack = [];
        $haystack[] = ['text' => mb_strtolower($recipe->name), 'source' => 'recipe_name'];
        foreach ($recipe->ingredients as $ingredient) {
            if ($ingredient->product) {
                $haystack[] = ['text' => mb_strtolower($ingredient->product->name), 'source' => 'ingredient_name'];
            }
            if ($ingredient->free_text_name) {
                $haystack[] = ['text' => mb_strtolower($ingredient->free_text_name), 'source' => 'ingredient_name'];
            }
        }
        return $haystack;
    }

    /**
     * Returns the source label if the dislike matches anything in the
     * haystack, OR if the dislike is product-linked and the recipe uses
     * that exact product. Returns null if no match.
     */
    private function matchDislike(string $needle, array $haystack, ClientMealDislike $dislike): ?string
    {
        // 1. Direct product FK match: precise, no substring.
        if ($dislike->product_id !== null) {
            // (the haystack will already include this product's name; this
            // path also catches cases where the substring would miss e.g.
            // exotic punctuation.)
            // Re-fetch ingredient list cheaply by iterating the haystack.
            foreach ($haystack as $row) {
                if ($row['source'] !== 'ingredient_name') continue;
                if ($row['text'] === mb_strtolower($dislike->product?->name ?? '')) {
                    return 'product_match';
                }
            }
        }

        // 2. Substring match across name haystack.
        foreach ($haystack as $row) {
            if ($row['text'] !== '' && mb_strpos($row['text'], $needle) !== false) {
                return $row['source'];
            }
        }

        return null;
    }

    /**
     * @param  array<int>  $tagIds
     * @return array<int, MealDietaryTag>
     */
    private function tagLookup(array $tagIds): array
    {
        if (empty($tagIds)) return [];
        return MealDietaryTag::whereIn('id', $tagIds)->get()->keyBy('id')->all();
    }

    private function locateRecipeTagSource(int $tagId, array $recipeTagsById, MealRecipe $recipe): string
    {
        // direct on recipe?
        if ($recipe->tags->contains('id', $tagId)) {
            return 'recipe_tag';
        }
        return 'product_tag';
    }

    private function emptyReport(array $recipeTagIds): array
    {
        return [
            'has_hard_blocks' => false,
            'has_soft_warnings' => false,
            'hard_blocks' => [],
            'soft_warnings' => [],
            'recipe_tag_ids' => $recipeTagIds,
        ];
    }
}
