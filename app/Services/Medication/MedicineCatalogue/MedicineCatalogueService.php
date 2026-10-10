<?php

namespace App\Services\Medication\MedicineCatalogue;

use App\Models\MedicineCatalogueProduct;
use App\Models\MedicineCatalogueSource;
use App\Models\User;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\Medication\Connected\ConnectedCareSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class MedicineCatalogueService
{
    public function __construct(private readonly CatalogueDataset $dataset) {}

    public function reader(User $actor): void
    {
        abort_unless($actor->isApproved() && $actor->canDo('medications.view') && ! $actor->hasRole('client', 'next_of_kin'), 403);
    }

    public function page(User $actor, int $page = 1): array
    {
        $this->reader($actor);
        // EA-186: draft, expired and revoked sources and licence references
        // are for catalogue managers; readers only match reviewed pictures.
        abort_unless($actor->canDo('medications.catalogue.manage'), 403);
        $sources = MedicineCatalogueSource::query()->with('products')->orderByDesc('id')->paginate(10, ['*'], 'source_page', max(1, $page));

        return ['source_meta' => ['current_page' => $sources->currentPage(), 'last_page' => $sources->lastPage(), 'total' => $sources->total()], 'sources' => $sources->getCollection()->map(fn ($source) => $this->sourceDto($source))->all(), 'can_manage' => $actor->canDo('medications.catalogue.manage'), 'notice' => 'No supplier feed is installed. Only owner-licensed, reviewed datasets are available. Photos help identify a product; always check the current chart and packaging.'];
    }

    public function create(User $actor, array $data): MedicineCatalogueSource
    {
        return DB::transaction(function () use ($actor, $data) {
            $current = $this->manager($actor);
            abort_unless(($data['licence_attested'] ?? false) === true, 422);

            return MedicineCatalogueSource::query()->create(array_intersect_key($data, array_flip(['supplier', 'source_name', 'source_version', 'attribution', 'licence_reference'])) + ['created_by' => $current->id, 'licence_attested_at' => now(), 'status' => 'draft', 'version' => 1]);
        }, 5);
    }

    public function import(User $actor, int $id, int $version, string $json): MedicineCatalogueSource
    {
        $rows = $this->dataset->parse($json);

        return DB::transaction(function () use ($actor, $id, $version, $rows, $json) {
            $this->manager($actor);
            $source = $this->locked($id, $version);
            $this->draft($source);
            if ($source->dataset_sha256 !== null) {
                // A published source version never changes under an old attribution/review.
                throw ValidationException::withMessages(['dataset' => 'This source already has a dataset. Create a new source version to replace it.']);
            }
            foreach ($rows as $row) {
                $source->products()->create($row);
            }
            $source->forceFill(['dataset_sha256' => hash('sha256', $json), 'version' => $source->version + 1])->saveOrFail();

            return $source;
        }, 5);
    }

    public function photo(User $actor, int $id, int $productId, int $version, string $uuid, UploadedFile $file): MedicineCatalogueProduct
    {
        $facts = $this->dataset->image($file);
        $owned = null;
        try {
            $result = DB::transaction(function () use ($actor, $id, $productId, $version, $uuid, $file, $facts, &$owned) {
                $this->manager($actor);
                $source = MedicineCatalogueSource::query()->lockForUpdate()->findOrFail($id);
                $product = $source->products()->lockForUpdate()->findOrFail($productId);
                if ($product->photo_request_uuid === $uuid) {
                    abort_unless(hash_equals((string) $product->photo_sha256, $facts['sha256']), 409);

                    return $product;
                }
                abort_unless($source->version === $version, 409, 'The source changed. Reload before editing.');
                $this->draft($source);
                abort_if($product->photo_path !== null, 409, 'Create a new source version to replace a product photograph.');
                $owned ??= 'medicine-catalogue/'.$source->id.'/'.Str::uuid().'.'.$facts['extension'];
                if (! Storage::disk('private')->exists($owned)) {
                    abort_unless(Storage::disk('private')->put($owned, file_get_contents($file->getRealPath())), 503);
                }
                $product->forceFill(['photo_path' => $owned, 'photo_mime' => $facts['mime'], 'photo_sha256' => $facts['sha256'], 'photo_request_uuid' => $uuid])->saveOrFail();
                $source->increment('version');

                return $product;
            }, 5);
        } catch (Throwable $exception) {
            $this->deleteUnused($owned);
            throw $exception;
        }
        $this->deleteUnused($owned);

        return $result;
    }

    public function review(User $actor, int $id, int $version, string $expiry): MedicineCatalogueSource
    {
        return DB::transaction(function () use ($actor, $id, $version, $expiry) {
            $current = $this->manager($actor);
            $source = $this->locked($id, $version);
            $this->draft($source);
            // EA-104: a second person reviews what someone else added (a
            // Settings › Connected services switch, on by default).
            if (app(ConnectedCareSettings::class)->twoPerson(ConnectedCareSettings::TWO_PERSON_CATALOGUE)
                && (int) $source->created_by === (int) $current->id) {
                throw ValidationException::withMessages(['source' => 'Another catalogue manager reviews this source. You added it, so you can’t also review it.']);
            }
            if (! preg_match('/^\\d{4}-\\d{2}-\\d{2}T.*(?:Z|[+-]\\d{2}:\\d{2})$/D', $expiry)) {
                throw ValidationException::withMessages(['expires_at' => 'Provide the expiry with an explicit UTC offset.']);
            }
            $expires = CarbonImmutable::parse($expiry)->utc();
            if ($expires->lessThanOrEqualTo(now()) || $expires->greaterThan(now()->addYear()) || $source->dataset_sha256 === null || ! $source->products()->exists()) {
                throw ValidationException::withMessages(['expires_at' => 'Review an imported dataset with an expiry within the next year.']);
            }
            $source->forceFill(['status' => 'reviewed', 'reviewed_by' => $current->id, 'reviewed_at' => now(), 'expires_at' => $expires, 'version' => $source->version + 1])->saveOrFail();

            return $source;
        }, 5);
    }

    public function revoke(User $actor, int $id, int $version): MedicineCatalogueSource
    {
        return DB::transaction(function () use ($actor, $id, $version) {
            $this->manager($actor);
            $source = $this->locked($id, $version);
            $source->forceFill(['status' => 'revoked', 'revoked_at' => now(), 'version' => $source->version + 1])->saveOrFail();

            return $source;
        }, 5);
    }

    public function match(User $actor, array $identity): array
    {
        $hash = $this->dataset->identity($identity);

        return DB::transaction(function () use ($actor, $hash): array {
            $currentActor = app(AuthorizationEvidenceLockService::class)->lockForUserWithoutWaiting($actor, ['*']);
            $this->reader($currentActor);
            $products = MedicineCatalogueProduct::query()->where('identity_sha256', $hash)->orderBy('source_id')->orderBy('id')->lockForUpdate()->get();
            foreach ($products as $product) {
                $product->setRelation('source', MedicineCatalogueSource::query()->lockForUpdate()->findOrFail($product->source_id));
            }
            $current = $products->filter(fn ($product) => $this->status($product->source) === 'reviewed');
            if ($current->count() === 1) {
                $product = $current->first();

                return ['status' => 'matched', 'product' => $this->productDto($product), 'source' => $this->provenance($product->source)];
            }
            if ($current->count() > 1) {
                return ['status' => 'review_required', 'message' => 'More than one reviewed source matches. Ask the catalogue owner to resolve the source versions.'];
            }
            $status = $products->contains(fn ($product) => $this->status($product->source) === 'expired') ? 'expired' : ($products->isNotEmpty() ? 'review_required' : (MedicineCatalogueSource::query()->lockForUpdate()->first() !== null ? 'no_exact_match' : 'unavailable'));

            return ['status' => $status, 'message' => 'No current reviewed exact product is available. Do not identify a medicine from a near match.'];
        }, 1);
    }

    public function readablePhoto(User $actor, int $productId): MedicineCatalogueProduct
    {
        $this->reader($actor);
        $product = MedicineCatalogueProduct::query()->with('source')->findOrFail($productId);
        // Draft review access is explicit; general readers never see expired/revoked imagery.
        abort_unless($this->status($product->source) === 'reviewed' || ($product->source->status === 'draft' && $actor->canDo('medications.catalogue.manage')), 404);
        abort_unless($product->photo_path && Storage::disk('private')->exists($product->photo_path), 404);
        abort_unless(hash_equals($product->photo_sha256, hash('sha256', Storage::disk('private')->get($product->photo_path))), 404);

        return $product;
    }

    public function sourceDto(MedicineCatalogueSource $source): array
    {
        return $this->provenance($source) + ['id' => (int) $source->id, 'supplier' => $source->supplier, 'licence_reference' => $source->licence_reference, 'status' => $this->status($source), 'version' => $source->version, 'product_count' => $source->products->count(), 'products' => $source->products->map(fn ($product) => $this->productDto($product))->all()];
    }

    private function productDto(MedicineCatalogueProduct $product): array
    {
        return array_intersect_key($product->getAttributes(), array_flip(['code_system', 'code', 'name', 'strength', 'form'])) + ['id' => (int) $product->id, 'has_photo' => $product->photo_path !== null, 'photo_url' => $product->photo_path ? '/emar/catalogue/products/'.$product->id.'/photo' : null];
    }

    private function provenance(MedicineCatalogueSource $source): array
    {
        return ['source_name' => $source->source_name, 'source_version' => $source->source_version, 'attribution' => $source->attribution, 'reviewed_at' => $source->reviewed_at?->toIso8601String(), 'expires_at' => $source->expires_at?->toIso8601String()];
    }

    private function status(MedicineCatalogueSource $source): string
    {
        if ($source->status === 'reviewed' && (! $source->reviewed_at || $source->reviewed_at->isFuture() || ! $source->expires_at || ! $source->licence_attested_at)) {
            return 'review_required';
        }

        return $source->status === 'reviewed' && $source->expires_at->lessThanOrEqualTo(now()) ? 'expired' : $source->status;
    }

    private function manager(User $actor): User
    {
        $current = app(AuthorizationEvidenceLockService::class)->lockForUserWithoutWaiting($actor, ['*']);
        $this->reader($current);
        abort_unless($current->canDo('medications.catalogue.manage'), 403);

        return $current;
    }

    private function locked(int $id, int $version): MedicineCatalogueSource
    {
        $source = MedicineCatalogueSource::query()->lockForUpdate()->findOrFail($id);
        abort_unless($source->version === $version, 409, 'The source changed. Reload before editing.');

        return $source;
    }

    private function draft(MedicineCatalogueSource $source): void
    {
        abort_unless($source->status === 'draft', 409, 'Create a new source version to change reviewed catalogue evidence.');
    }

    private function deleteUnused(?string $path): void
    {
        if ($path === null) {
            return;
        }
        try {
            if (MedicineCatalogueProduct::query()->where('photo_path', $path)->exists()) {
                return;
            }
        } catch (Throwable) {
            return; // Fail closed after an ambiguous commit/reference lookup.
        }
        Storage::disk('private')->delete($path);
    }
}
