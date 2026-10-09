<?php

namespace App\Services\Medication\Stock;

use App\Models\ClientMedication;
use App\Models\MedicationStockLot;
use App\Models\MedicationStockPhoto;
use App\Models\MedicineCatalogueBinding;
use App\Models\MedicineCatalogueProduct;
use App\Models\MedicineCatalogueSource;
use App\Models\User;
use App\Services\Medication\MedicationConcealment;
use App\Services\Medication\MedicineCatalogue\MedicineCatalogueBindingService;
use Illuminate\Support\Collection;

/**
 * Staff pack photos (approved P06 Q6, P02 Medicines › Photos; Lane C11) are
 * the primary picture of a medicine everywhere. A catalogue picture is used
 * only when no staff photo exists and a reviewed, unexpired catalogue source
 * is bound to this exact medicine version; it is always labelled a
 * reference image.
 *
 * Callers have already applied the per-person record gate. This applies
 * controlled-medicine concealment: a controlled medicine's photo is never
 * returned to someone without controlled view.
 *
 * Every photo is a guide only: screens that show one say "Check the label —
 * the picture is a guide only".
 */
final class PackPhotoReader
{
    public const STATE_CURRENT = 'current';

    public const STATE_CHANGED = 'changed';

    public const STATE_REPLACED = 'replaced';

    /**
     * The picture shown for each order: the latest staff photo, else a bound
     * reference image, else null. Concealed orders are null.
     *
     * @param  iterable<int, ClientMedication>  $orders
     * @return array<int, array<string, mixed>|null> keyed by order id
     */
    public function forOrders(User $viewer, iterable $orders): array
    {
        $orders = collect($orders)->keyBy(fn (ClientMedication $order): int => (int) $order->id);
        if ($orders->isEmpty()) {
            return [];
        }
        $concealment = MedicationConcealment::for($viewer);
        $visible = $orders->reject(fn (ClientMedication $order): bool => $concealment->hides((bool) $order->controlled_drug));
        $latest = $this->latestStaffPhotos($visible->keys()->all());
        $references = $this->referenceImages($visible->except($latest->keys()->all()));

        return $orders->map(function (ClientMedication $order, int $id) use ($visible, $latest, $references): ?array {
            if (! $visible->has($id)) {
                return null;
            }
            $photo = $latest->get($id);

            return $photo !== null ? $this->staffPayload($photo, $order) : ($references[$id] ?? null);
        })->all();
    }

    public function forOrder(User $viewer, ClientMedication $order): ?array
    {
        return $this->forOrders($viewer, [$order])[(int) $order->id] ?? null;
    }

    /**
     * Every staff photo for these orders, newest first, with its state:
     * the newest per medicine is "current" (or "changed" when it was taken
     * because the pack or brand looked different); older ones are
     * "replaced". Concealed medicines are left out and counted.
     *
     * @param  Collection<int, ClientMedication>  $orders
     * @return array{photos: list<array<string, mixed>>, concealed: int}
     */
    public function history(User $viewer, Collection $orders): array
    {
        $concealment = MedicationConcealment::for($viewer);
        $orders = $orders->keyBy(fn (ClientMedication $order): int => (int) $order->id);
        $hidden = $orders->filter(fn (ClientMedication $order): bool => $concealment->hides((bool) $order->controlled_drug));
        $visible = $orders->except($hidden->keys()->all());
        $photos = $this->photoQuery($visible->keys()->all())
            ->orderByDesc('medication_stock_photos.taken_at')->orderByDesc('medication_stock_photos.id')
            ->limit(500)->get();
        $concealed = $hidden->isEmpty() ? 0 : $this->photoQuery($hidden->keys()->all())->count();
        $newest = [];
        $rows = [];
        foreach ($photos as $photo) {
            $orderId = (int) $photo->lot->stock->client_medication_id;
            $first = ! isset($newest[$orderId]);
            $newest[$orderId] = true;
            $order = $visible->get($orderId);
            $rows[] = [
                ...$this->staffPayload($photo, $order),
                'id' => (int) $photo->id,
                'medication_id' => $orderId,
                'medication_name' => (string) $order->name,
                'medication_strength' => $order->dosage,
                'controlled' => (bool) $order->controlled_drug,
                'state' => ! $first ? self::STATE_REPLACED : ((bool) $photo->pack_changed ? self::STATE_CHANGED : self::STATE_CURRENT),
                'original_name' => $photo->original_name,
                'mime' => $photo->mime,
                'bytes' => (int) $photo->bytes,
                'description' => $photo->description,
                'download_url' => route('emar.stock.photos.download', $photo->id),
            ];
        }

        return ['photos' => $rows, 'concealed' => $concealed];
    }

    /** @return array<string, mixed> */
    public function staffPayload(MedicationStockPhoto $photo, ?ClientMedication $order = null): array
    {
        $lot = $photo->lot;
        $nowPack = $order !== null ? $this->nowPack($order, $lot) : null;

        return [
            'kind' => 'staff',
            'url' => route('emar.stock.photos.view', $photo->id),
            'taken_at' => $photo->taken_at?->toIso8601String(),
            'taken_by' => $photo->takenBy?->name,
            'pack' => self::packLabel($lot),
            'pharmacy' => self::supplierLabel($lot),
            'pack_changed' => (bool) $photo->pack_changed,
            'now_pack' => $nowPack,
            'source' => null,
        ];
    }

    /** "Batch AB12 · Expires 05/2027" — the printed label facts of a pack. */
    public static function packLabel(?MedicationStockLot $lot): ?string
    {
        if ($lot === null) {
            return null;
        }
        $batch = $lot->batch_number ? 'Batch '.$lot->batch_number : ($lot->batch_not_printed ? 'Batch not printed' : 'Batch unknown');
        $expiry = $lot->expiry_date ? 'Expires '.$lot->expiry_date->format('m/Y') : ($lot->expiry_not_printed ? 'Expiry not printed' : 'Expiry unknown');

        return $batch.' · '.$expiry;
    }

    /** Who supplied the pack: the pharmacy on its supply record, else its source. */
    public static function supplierLabel(?MedicationStockLot $lot): ?string
    {
        if ($lot === null) {
            return null;
        }
        $pharmacy = $lot->relationLoaded('pharmacyOrder') ? $lot->pharmacyOrder?->pharmacy_name : $lot->pharmacyOrder()->value('pharmacy_name');
        if (filled($pharmacy)) {
            return (string) $pharmacy;
        }
        $source = match ($lot->source) {
            'pharmacy' => 'Pharmacy', 'family' => 'Family', 'hospital' => 'Hospital', 'respite' => 'Respite',
            'recorded_balance' => 'Carried-forward balance', 'pharmacy_order' => 'Pharmacy', default => 'Other source',
        };

        return filled($lot->source_reference) && $lot->source !== 'pharmacy'
            ? $source.' · '.$lot->source_reference
            : (filled($lot->source_reference) ? (string) $lot->source_reference : $source);
    }

    /** The pack doses come from now, when it isn't the photographed one. */
    private function nowPack(ClientMedication $order, ?MedicationStockLot $photographed): ?string
    {
        $stock = $order->relationLoaded('stock') ? $order->stock : $order->stock()->first();
        if ($stock === null || $stock->lots_started_at === null) {
            return null;
        }
        $next = app(StockAvailability::class)->nextUsablePack($stock);
        if ($next === null || ($photographed !== null && (int) $next->id === (int) $photographed->id)) {
            return null;
        }
        if ($photographed !== null && $next->batch_number !== null && $next->batch_number === $photographed->batch_number) {
            return null;
        }

        return self::packLabel($next);
    }

    /**
     * @param  list<int>  $orderIds
     * @return Collection<int, MedicationStockPhoto> latest photo keyed by order id
     */
    private function latestStaffPhotos(array $orderIds): Collection
    {
        if ($orderIds === []) {
            return collect();
        }
        // Aggregate only: only_full_group_by refuses photos.* beside MAX(id).
        $latestIds = $this->photoJoins($orderIds)
            ->groupBy('client_medication_stocks.client_medication_id')
            ->selectRaw('MAX(medication_stock_photos.id) as latest_id')
            ->toBase()->pluck('latest_id');

        return MedicationStockPhoto::query()->whereIn('id', $latestIds)
            ->with(['lot.stock', 'lot.pharmacyOrder', 'takenBy:id,name'])->get()
            ->keyBy(fn (MedicationStockPhoto $photo): int => (int) $photo->lot->stock->client_medication_id);
    }

    /** @param list<int> $orderIds */
    private function photoQuery(array $orderIds)
    {
        return $this->photoJoins($orderIds)
            ->select('medication_stock_photos.*')
            ->with(['lot.stock', 'lot.pharmacyOrder', 'takenBy:id,name']);
    }

    /** @param list<int> $orderIds */
    private function photoJoins(array $orderIds)
    {
        return MedicationStockPhoto::query()
            ->join('medication_stock_lots', 'medication_stock_lots.id', '=', 'medication_stock_photos.medication_stock_lot_id')
            ->join('client_medication_stocks', 'client_medication_stocks.id', '=', 'medication_stock_lots.client_medication_stock_id')
            ->whereIn('client_medication_stocks.client_medication_id', $orderIds === [] ? [0] : $orderIds);
    }

    /**
     * A reference image only where a reviewed, unexpired catalogue source is
     * bound to the order's current version and identity, with a picture.
     *
     * @param  Collection<int, ClientMedication>  $orders
     * @return array<int, array<string, mixed>>
     */
    private function referenceImages(Collection $orders): array
    {
        if ($orders->isEmpty() || ! self::catalogueConfigured()) {
            return [];
        }
        $identity = app(MedicineCatalogueBindingService::class);
        $bindings = MedicineCatalogueBinding::query()->whereIn('client_medication_id', $orders->keys()->all())
            ->orderByDesc('id')->get()->unique('client_medication_id');
        $products = MedicineCatalogueProduct::query()->with('source')
            ->whereIn('id', $bindings->pluck('product_id')->all())->get()->keyBy('id');
        $images = [];
        foreach ($bindings as $binding) {
            $order = $orders->get((int) $binding->client_medication_id);
            $product = $products->get((int) $binding->product_id);
            $source = $product?->source;
            if ($order === null || $product === null || $source === null || ! $product->photo_path
                || (int) $binding->medication_version !== (int) $order->version
                || ! hash_equals((string) $binding->medicine_identity_sha256, $identity->identity($order))
                || ! self::sourceCurrent($source)) {
                continue;
            }
            $images[(int) $order->id] = [
                'kind' => 'reference',
                'url' => '/emar/catalogue/products/'.$product->id.'/photo',
                'taken_at' => null,
                'taken_by' => null,
                'pack' => trim($product->name.' · '.$product->strength.' · '.$product->form, ' ·'),
                'pharmacy' => null,
                'pack_changed' => false,
                'now_pack' => null,
                'source' => trim($source->source_name.' '.$source->source_version),
            ];
        }

        return $images;
    }

    /** Whether any reviewed, unexpired catalogue source exists at all. */
    public static function catalogueConfigured(): bool
    {
        return MedicineCatalogueSource::query()->where('status', 'reviewed')
            ->whereNotNull('reviewed_at')->where('reviewed_at', '<=', now())
            ->where('expires_at', '>', now())->exists();
    }

    private static function sourceCurrent(MedicineCatalogueSource $source): bool
    {
        return $source->status === 'reviewed' && $source->reviewed_at !== null && $source->expires_at?->isFuture() === true;
    }
}
