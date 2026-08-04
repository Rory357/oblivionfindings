<?php

namespace App\Domain\It\Services;

use App\Models\ItCatalogItem;
use App\Models\ItProvisioningRequest;
use App\Models\User;
use App\Services\AuditLogger;
use DomainException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ItCatalogManagementService
{
    private const EDITABLE = [
        'it_service_id',
        'name',
        'description',
        'outcome_type',
        'category',
        'provisioning_type',
        'default_priority',
        'requires_approval',
        'internal_only',
        'form_schema',
        'search_terms',
        'sort_order',
    ];

    /** @param array<string, mixed> $data */
    public function create(User $actor, array $data): ItCatalogItem
    {
        return DB::transaction(function () use ($actor, $data): ItCatalogItem {
            $this->guardActor($actor);
            $item = ItCatalogItem::query()->create([
                ...Arr::only($this->normalise($data), self::EDITABLE),
                'slug' => $this->uniqueSlug((string) $data['name']),
                'is_published' => false,
                'form_schema_version' => 1,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
            AuditLogger::logOrFail('it.catalogue.item.created', $item, [
                'application_scope' => 'single_application',
                'actor_id' => $actor->id,
                'outcome_type' => $item->outcome_type,
                'field_count' => count($item->form_schema['fields'] ?? []),
            ]);

            return $item->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function update(ItCatalogItem $item, User $actor, array $data): ItCatalogItem
    {
        return DB::transaction(function () use ($item, $actor, $data): ItCatalogItem {
            $item = $this->lock($item, $actor);
            $before = $item->only(self::EDITABLE);
            $wasSchema = $item->form_schema;
            $item->fill(Arr::only($this->normalise($data), self::EDITABLE));
            if ($item->form_schema !== $wasSchema) {
                $item->form_schema_version = (int) $item->form_schema_version + 1;
            }
            $item->updated_by = $actor->id;
            $changedFields = array_keys($item->getDirty());
            if ($changedFields === []) {
                return $item;
            }
            $item->save();
            AuditLogger::logOrFail('it.catalogue.item.updated', $item, [
                'application_scope' => 'single_application',
                'actor_id' => $actor->id,
                'before' => $before,
                'changed_fields' => $changedFields,
                'form_schema_version' => $item->form_schema_version,
            ]);

            return $item->refresh();
        });
    }

    public function publish(ItCatalogItem $item, User $actor): ItCatalogItem
    {
        return DB::transaction(function () use ($item, $actor): ItCatalogItem {
            $item = $this->lock($item, $actor);
            if ($item->is_published) {
                return $item;
            }
            if ($item->it_service_id !== null && ! $item->service()->where('is_active', true)->exists()) {
                throw new DomainException('Choose an active service before publishing this request.');
            }
            if ($item->outcome_type === 'provisioning' && ! in_array($item->provisioning_type, ItProvisioningRequest::TYPES, true)) {
                throw new DomainException('Choose a supported provisioning type before publishing this request.');
            }

            $item->forceFill([
                'is_published' => true,
                'updated_by' => $actor->id,
            ])->save();
            AuditLogger::logOrFail('it.catalogue.item.published', $item, [
                'application_scope' => 'single_application',
                'actor_id' => $actor->id,
                'form_schema_version' => $item->form_schema_version,
            ]);

            return $item->refresh();
        });
    }

    public function unpublish(ItCatalogItem $item, User $actor, string $reason): ItCatalogItem
    {
        return DB::transaction(function () use ($item, $actor, $reason): ItCatalogItem {
            $item = $this->lock($item, $actor);
            $reason = trim($reason);
            if ($reason === '') {
                throw new DomainException('Record why this request is being unpublished.');
            }
            if (! $item->is_published) {
                throw new DomainException('This request is already a draft.');
            }

            $item->forceFill([
                'is_published' => false,
                'updated_by' => $actor->id,
            ])->save();
            AuditLogger::logOrFail('it.catalogue.item.unpublished', $item, [
                'application_scope' => 'single_application',
                'actor_id' => $actor->id,
                'reason' => $reason,
                'form_schema_version' => $item->form_schema_version,
            ]);

            return $item->refresh();
        });
    }

    private function lock(ItCatalogItem $item, User $actor): ItCatalogItem
    {
        $this->guardActor($actor);

        return ItCatalogItem::query()->lockForUpdate()->findOrFail($item->getKey());
    }

    private function guardActor(User $actor): void
    {
        if ($actor->approved_at === null || ! $actor->canDo('it.manage')) {
            throw new DomainException('You are not allowed to manage the service catalogue.');
        }
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function normalise(array $data): array
    {
        $data['provisioning_type'] = ($data['outcome_type'] ?? null) === 'provisioning'
            ? ($data['provisioning_type'] ?? null)
            : null;
        $data['search_terms'] = array_values(array_unique(array_filter(array_map(
            fn (mixed $term): string => trim((string) $term),
            (array) ($data['search_terms'] ?? []),
        ))));
        $fields = collect($data['form_schema']['fields'] ?? [])->map(function (array $field): array {
            $normalised = [
                'key' => trim((string) $field['key']),
                'label' => trim((string) $field['label']),
                'type' => (string) $field['type'],
                'required' => (bool) $field['required'],
                'visibility' => (string) $field['visibility'],
            ];
            foreach (['help', 'min', 'max'] as $optional) {
                if (array_key_exists($optional, $field) && $field[$optional] !== null && $field[$optional] !== '') {
                    $normalised[$optional] = $field[$optional];
                }
            }
            if (in_array($field['type'], ['select', 'multiselect'], true)) {
                $normalised['options'] = array_values(array_unique(array_map(
                    fn (mixed $option): string => trim((string) $option),
                    (array) ($field['options'] ?? []),
                )));
            }

            return $normalised;
        })->values()->all();
        $data['form_schema'] = ['fields' => $fields];

        return $data;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'request';
        $slug = $base;
        $suffix = 1;
        while (ItCatalogItem::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }
}
