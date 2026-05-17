<?php

namespace App\Services\Catering\DeliveryProviders;

use App\Models\Site;
use App\Models\SiteMealShoppingList;
use Illuminate\Support\Collection;

class NullDeliveryProvider implements DeliveryProviderContract
{
    public function key(): string
    {
        return 'manual';
    }

    public function label(): string
    {
        return 'Manual (no external provider)';
    }

    public function searchProducts(string $query): Collection
    {
        return collect();
    }

    public function matchProduct(int $localProductId): ?string
    {
        return null;
    }

    public function priceQuote(SiteMealShoppingList $list): ?int
    {
        return null;
    }

    public function submitOrder(Site $site, SiteMealShoppingList $list): string
    {
        throw new UnsupportedOperationException(
            'The manual delivery provider cannot submit orders. Mark the list as ordered manually instead.'
        );
    }

    public function orderStatus(string $providerOrderRef): string
    {
        return 'pending';
    }
}
