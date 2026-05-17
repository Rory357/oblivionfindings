<?php

namespace App\Services\Catering\DeliveryProviders;

use App\Models\Site;
use App\Models\SiteMealShoppingList;
use Illuminate\Support\Collection;

interface DeliveryProviderContract
{
    /** Short stable key, e.g. "manual" or "countdown". */
    public function key(): string;

    /** Human label for UI dropdowns. */
    public function label(): string;

    /**
     * Search the provider's catalogue for products matching $query.
     * @return Collection<int, array{external_id:string, name:string, price_cents:?int, unit:?string}>
     */
    public function searchProducts(string $query): Collection;

    /**
     * Resolve a local meal_products row to the provider's catalogue.
     * Returns the provider's external ID if matched, null otherwise.
     */
    public function matchProduct(int $localProductId): ?string;

    /**
     * Quote the cost in cents for a shopping list (without ordering).
     * Returns null if quoting isn't supported.
     */
    public function priceQuote(SiteMealShoppingList $list): ?int;

    /**
     * Submit an order. Returns the provider's order reference on success;
     * throws on failure. Implementations may throw
     * UnsupportedOperationException for read-only providers.
     */
    public function submitOrder(Site $site, SiteMealShoppingList $list): string;

    /**
     * Lookup the current status for a previously-submitted order.
     * Returns one of: pending, confirmed, dispatched, delivered, cancelled.
     */
    public function orderStatus(string $providerOrderRef): string;
}
