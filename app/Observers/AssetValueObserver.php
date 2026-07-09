<?php

namespace App\Observers;

use App\Domain\Finance\Models\FinFixedAsset;
use App\Domain\Finance\Services\FixedAssetService;
use App\Models\AssetValue;
use Illuminate\Support\Facades\Log;

/**
 * Capture-at-source: when an operational asset is valued at or above the
 * capitalisation threshold (and its category is a capital category), register
 * it on the fixed-asset register.
 *
 * GL-safe by design: the FinFixedAsset is created WITHOUT GL accounts, so no
 * journal posts automatically. Finance assigns the GL asset account and posts
 * the acquisition explicitly (FixedAssetService::capitaliseAsset) — the
 * capitalisation policy (which account, cash vs on-account) stays a finance
 * decision. Idempotent: one register entry per operational asset.
 */
class AssetValueObserver
{
    public function created(AssetValue $value): void
    {
        $this->captureFixedAsset($value);
    }

    public function updated(AssetValue $value): void
    {
        $this->captureFixedAsset($value);
    }

    private function captureFixedAsset(AssetValue $value): void
    {
        $cost = (float) ($value->purchase_cost ?? 0);
        $threshold = (float) config('finance.capture.asset_capitalisation_threshold', 1000);

        if ($cost < $threshold || $cost <= 0) {
            return;
        }

        try {
            $asset = $value->asset;
            if (! $asset) {
                return;
            }

            $category = strtolower(trim((string) ($asset->categoryRef?->slug ?? $asset->category ?? '')));
            $capital = config('finance.capture.asset_capital_categories', ['vehicle', 'equipment', 'building', 'furniture', 'it_equipment', 'land']);
            if (! in_array($category, $capital, true)) {
                return;
            }

            $orgId = $asset->site?->tenant_id ?? $asset->homeSite?->tenant_id;
            if (! $orgId) {
                return;
            }

            if (FinFixedAsset::where('linked_asset_id', $asset->id)->exists()) {
                return;
            }

            app(FixedAssetService::class)->createAsset($orgId, [
                'asset_name' => $asset->name,
                'asset_tag' => $asset->asset_tag,
                'category' => $category,
                'purchase_date' => ($asset->purchase_date ?? now())->toDateString(),
                'purchase_cost' => $cost,
                'useful_life_months' => (int) config('finance.capture.asset_useful_life_months', 60),
                'depreciation_method' => 'straight_line',
                // No GL accounts on purpose — registration only, no auto-posted journal.
                'linked_asset_id' => $asset->id,
                'notes' => "Auto-captured from operational asset #{$asset->id} valuation.",
            ]);
        } catch (\Throwable $e) {
            Log::error("AssetValueObserver: Failed to capture fixed asset for value #{$value->id}: {$e->getMessage()}");
        }
    }
}
