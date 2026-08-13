<?php

namespace App\Services\Catering;

use App\Domain\Clinical\Services\ClientMealRestrictionProjection;
use App\Models\Client;
use App\Models\ClientMealDislike;
use App\Models\MealDietaryTag;
use App\Models\MealRecipe;
use Carbon\CarbonInterface;

class DietaryConflictChecker
{
    public function __construct(
        private readonly ClientMealRestrictionProjection $restrictions,
    ) {}

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
    public function checkRecipeAgainstClients(
        MealRecipe $recipe,
        array $clientIds,
        CarbonInterface|string|null $onDate = null,
    ): array {
        return $this->checkMealAgainstClients($recipe, $clientIds, $onDate);
    }

    /**
     * Safety gate for recipe, ad-hoc and takeaway meals. The authorised
     * clinical projection is the only restriction source; missing, stale,
     * expired or unverifiable authority is itself a hard block.
     *
     * @param  array<int>  $clientIds
     * @return array<string, mixed>
     */
    public function checkMealAgainstClients(
        ?MealRecipe $recipe,
        array $clientIds,
        CarbonInterface|string|null $onDate = null,
    ): array {
        $clientIds = array_values(array_unique(array_filter($clientIds)));
        $recipe?->loadMissing(['tags', 'ingredients.product.tags']);

        $recipeTagIds = $recipe ? $this->collectRecipeAllergenTagIds($recipe) : [];
        $recipeTagsById = $this->tagLookup($recipeTagIds);
        $haystack = $recipe ? $this->buildNameHaystack($recipe) : [];

        $hardBlocks = [];
        $softWarnings = [];

        if (empty($clientIds)) {
            return $this->emptyReport($recipeTagIds);
        }

        $clients = Client::with('mealDislikes.product')->whereIn('id', $clientIds)->get();

        foreach ($clients as $client) {
            $hard = [];
            $soft = [];
            $restriction = $this->restrictions->forClient($client, $onDate);

            if ($restriction['authority_status'] !== 'authorised') {
                $hard[] = [
                    'label' => 'Clinical meal restrictions '.str_replace('_', ' ', $restriction['authority_status']),
                    'severity' => 'critical',
                    'kind' => 'authority',
                    'source' => 'clinical_restriction',
                ];
            } else {
                $allergenIds = $restriction['allergen_tag_ids'];
                $dietaryIds = $restriction['dietary_tag_ids'];

                if (! $recipe && ($allergenIds !== [] || $dietaryIds !== [] || $restriction['texture'] !== null)) {
                    $hard[] = [
                        'label' => 'Ad-hoc or takeaway suitability is not clinically verified',
                        'severity' => 'critical',
                        'kind' => 'authority',
                        'source' => 'unclassified_meal',
                    ];
                }

                if ($recipe) {
                    foreach (array_values(array_intersect($allergenIds, $recipeTagIds)) as $tagId) {
                        $tag = $recipeTagsById[$tagId] ?? null;
                        $hard[] = [
                            'label' => $tag?->label ?? 'Recorded allergen',
                            'severity' => 'critical',
                            'kind' => 'allergen',
                            'source' => $this->locateRecipeTagSource($tagId, $recipeTagsById, $recipe),
                        ];
                    }

                    foreach (array_values(array_diff($dietaryIds, $recipeTagIds)) as $tagId) {
                        $tag = MealDietaryTag::query()->find($tagId);
                        $hard[] = [
                            'label' => ($tag?->label ?? 'Dietary restriction').' is not confirmed by this recipe',
                            'severity' => 'critical',
                            'kind' => 'dietary',
                            'source' => 'clinical_restriction',
                        ];
                    }

                    $requiredFoodLevel = $restriction['texture']['level'] ?? null;
                    if ($requiredFoodLevel !== null && (int) $recipe->iddsi_food_level !== (int) $requiredFoodLevel) {
                        $hard[] = [
                            'label' => $recipe->iddsi_food_level === null
                                ? "IDDSI {$requiredFoodLevel} suitability is not verified for this recipe"
                                : "Recipe IDDSI {$recipe->iddsi_food_level} conflicts with required IDDSI {$requiredFoodLevel}",
                            'severity' => 'critical',
                            'kind' => 'iddsi',
                            'source' => 'clinical_restriction',
                        ];
                    }
                }
            }

            // Dislikes remain operational preferences, not clinical authority.
            foreach ($recipe ? $client->mealDislikes : [] as $dislike) {
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

            $clientName = trim($client->first_name.' '.$client->last_name);
            if ($hard) {
                $hardBlocks[] = ['client_id' => $client->id, 'client_name' => $clientName, 'matches' => $hard];
            }
            if ($soft) {
                $softWarnings[] = ['client_id' => $client->id, 'client_name' => $clientName, 'matches' => $soft];
            }
        }

        return [
            'has_hard_blocks' => ! empty($hardBlocks),
            'has_soft_warnings' => ! empty($softWarnings),
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
                if ($row['source'] !== 'ingredient_name') {
                    continue;
                }
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
        if (empty($tagIds)) {
            return [];
        }

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
