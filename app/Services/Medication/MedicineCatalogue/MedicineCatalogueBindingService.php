<?php

namespace App\Services\Medication\MedicineCatalogue;

use App\Models\ClientMedication;
use App\Models\MedicineCatalogueBinding;
use App\Models\MedicineCatalogueProduct;
use App\Models\MedicineCatalogueSource;
use App\Models\User;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\Medication\MedicationRecordAccess;
use Illuminate\Support\Facades\Storage;

class MedicineCatalogueBindingService
{
    public function __construct(private readonly MedicineCatalogueService $catalogue) {}

    public function bind(User $actor, int $medicationId, array $input): array
    {
        return app(MedicationGovernanceScopeService::class)->forMedication($actor, $medicationId, 'medications.stock.update', function ($client, $medicine, $current) use ($input): array {
            $current = app(AuthorizationEvidenceLockService::class)->lockForUserWithoutWaiting($current, ['*']);
            $this->readable($current, $medicine);
            abort_unless($current->canDo('medications.catalogue.manage'), 403);
            abort_unless((int) $medicine->version === (int) $input['expected_medication_version'] && ($input['product_label_confirmed'] ?? false) === true, 409, 'Compare the current medicine and actual package label before confirming.');
            $snapshot = MedicineCatalogueProduct::query()->findOrFail($input['product_id']);
            $source = MedicineCatalogueSource::query()->lockForUpdate()->findOrFail($snapshot->source_id);
            $product = $source->products()->lockForUpdate()->findOrFail($snapshot->id);
            abort_unless($source->status === 'reviewed' && $source->reviewed_at && $source->expires_at?->isFuture(), 422, 'Choose a current reviewed source.');
            $normalize = fn ($value) => mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $value)));
            abort_unless($normalize($medicine->name) === $normalize($product->name) && $normalize($medicine->form) === $normalize($product->form), 422, 'Medicine name and form must match the reviewed product exactly.');
            if (filled($medicine->nzulm_code)) {
                abort_unless($normalize($product->code_system) === 'nzulm' && $normalize($medicine->nzulm_code) === $normalize($product->code), 422, 'The recorded NZULM code must match exactly.');
            }
            // Strength is the reviewed package product strength, never the prescribed dose.
            MedicineCatalogueBinding::query()->create(['client_medication_id' => $medicine->id, 'medication_version' => $medicine->version, 'product_id' => $product->id, 'medicine_identity_sha256' => $this->identity($medicine), 'reference' => $input['reference'], 'verified_by' => $current->id, 'verified_at' => now()]);

            return $this->dto($current, $medicine);
        });
    }

    public function read(User $actor, int $medicationId): array
    {
        return app(MedicationGovernanceScopeService::class)->forMedication($actor, $medicationId, 'medications.view', function ($client, $medicine, $current): array {
            $current = app(AuthorizationEvidenceLockService::class)->lockForUserWithoutWaiting($current, ['*']);
            $this->readable($current, $medicine);

            return $this->dto($current, $medicine);
        });
    }

    public function products(User $actor, int $medicationId, string $search, int $page): array
    {
        return app(MedicationGovernanceScopeService::class)->forMedication($actor, $medicationId, 'medications.stock.update', function ($client, $medicine, $current) use ($search, $page): array {
            $current = app(AuthorizationEvidenceLockService::class)->lockForUserWithoutWaiting($current, ['*']);
            $this->readable($current, $medicine);
            abort_unless($current->canDo('medications.catalogue.manage'), 403);
            $normalize = fn ($value) => mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $value)));
            $query = MedicineCatalogueProduct::query()->with('source')->whereNotNull('photo_path')
                ->whereRaw('BINARY LOWER(name) = BINARY ?', [$normalize($medicine->name)])
                ->whereRaw('BINARY LOWER(form) = BINARY ?', [$normalize($medicine->form)])
                ->whereHas('source', fn ($q) => $q->where('status', 'reviewed')->where('reviewed_at', '<=', now())->where('expires_at', '>', now()));
            if (filled($medicine->nzulm_code)) {
                $query->whereRaw('BINARY LOWER(code_system) = BINARY ?', ['nzulm'])->whereRaw('BINARY LOWER(code) = BINARY ?', [$normalize($medicine->nzulm_code)]);
            }
            if (trim($search) !== '') {
                $pattern = '%'.addcslashes(trim($search), '%_\\').'%';
                $query->where(fn ($q) => $q->where('strength', 'like', $pattern)->orWhere('code', 'like', $pattern)->orWhereHas('source', fn ($s) => $s->where('source_name', 'like', $pattern)));
            }
            $rows = $query->orderBy('id')->paginate(25, ['*'], 'page', max(1, $page));

            return ['products' => $rows->getCollection()->map(fn ($p) => array_intersect_key($p->getAttributes(), array_flip(CatalogueDataset::FIELDS)) + ['id' => (int) $p->id, 'has_photo' => true, 'photo_url' => '/emar/catalogue/products/'.$p->id.'/photo', 'source_name' => $p->source->source_name, 'source_version' => $p->source->source_version])->all(), 'pagination' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()]];
        });
    }

    public function identity(ClientMedication $medicine): string
    {
        return hash('sha256', json_encode([$medicine->id, $medicine->client_id, $medicine->version, $medicine->name, $medicine->form, $medicine->nzulm_code, $medicine->dosage, $medicine->dose_amount, $medicine->dose_unit, $medicine->route, (bool) $medicine->controlled_drug], JSON_THROW_ON_ERROR));
    }

    private function readable(User $actor, ClientMedication $medicine): void
    {
        app(MedicationRecordAccess::class)->assertReadable($actor, $medicine->client);
        abort_if($medicine->controlled_drug && ! $actor->canDo('medications.controlled.view'), 404);
    }

    private function dto(User $actor, ClientMedication $medicine): array
    {
        $base = ['medicine_version' => (int) $medicine->version, 'can_manage' => $actor->canDo('medications.catalogue.manage') && $actor->canDo('medications.stock.update')];
        $binding = MedicineCatalogueBinding::query()->where('client_medication_id', $medicine->id)->orderByDesc('id')->lockForUpdate()->first();
        if (! $binding) {
            return $base + ['status' => 'unavailable', 'message' => 'No package-label product has been confirmed for this medicine.'];
        }
        $product = MedicineCatalogueProduct::query()->lockForUpdate()->find($binding->product_id);
        if ($product) {
            $product->setRelation('source', MedicineCatalogueSource::query()->lockForUpdate()->findOrFail($product->source_id));
        }
        if ($binding->medication_version !== (int) $medicine->version || ! hash_equals($binding->medicine_identity_sha256, $this->identity($medicine)) || ! $product
            || $product->source->status !== 'reviewed' || ! $product->source->reviewed_at || ! $product->source->expires_at?->isFuture()) {
            return $base + ['status' => 'review_required', 'message' => 'The medicine or licensed source changed. Compare the current package before using a photo.'];
        }
        if ($product->photo_path && (! Storage::disk('private')->exists($product->photo_path) || ! hash_equals($product->photo_sha256, hash('sha256', Storage::disk('private')->get($product->photo_path))))) {
            return $base + ['status' => 'review_required', 'message' => 'The product photo cannot be verified. Ask the catalogue owner to review it.'];
        }
        $identity = array_intersect_key($product->getAttributes(), array_flip(CatalogueDataset::FIELDS));
        $match = $this->catalogue->match($actor, $identity);
        if ($match['status'] !== 'matched' || (int) $match['product']['id'] !== (int) $product->id) {
            return $base + ['status' => 'review_required', 'message' => 'The reviewed product source needs a new check.'];
        }

        return $base + $match + ['binding' => ['id' => (int) $binding->id, 'verified_by' => (int) $binding->verified_by, 'verified_at' => $binding->verified_at->toIso8601String(), 'reference' => $binding->reference]];
    }
}
