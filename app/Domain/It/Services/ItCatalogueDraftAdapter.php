<?php

namespace App\Domain\It\Services;

use App\Domain\It\Exceptions\ItTicketDraftException as DraftError;
use App\Models\ItCatalogItem;
use App\Models\ItCatalogVersion;
use App\Models\ItTicketDraft;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Catalogue context adapter for the canonical encrypted IT draft store. */
final class ItCatalogueDraftAdapter
{
    public function context(User $actor, int $itemId, int $schemaVersion): array
    {
        $scope = ['catalogue' => ['catalog_item_id' => $itemId, 'schema_version' => $schemaVersion, 'entities' => [], 'internal' => false]];
        $this->authorize($actor, $scope['catalogue']);

        return $scope;
    }

    public function contract(int $itemId, int $schemaVersion): ItCatalogItem
    {
        $item = ItCatalogItem::query()->find($itemId);
        $version = ItCatalogVersion::query()->where('catalog_item_id', $itemId)->where('version', $schemaVersion)->first();
        if (! $item || ! $version) {
            throw DraftError::unavailable();
        }

        return $item->forceFill(['site_scope' => null, 'provisioning_template_version_id' => null, ...$version->contract]);
    }

    public function validate(array $fields): array
    {
        $safe = Validator::make(['fields' => $fields], [
            'fields' => ['array:catalog_item_id,schema_version,catalogue_values,site_id,requested_for_user_id'],
            'fields.catalog_item_id' => ['required', 'integer', 'min:1'], 'fields.schema_version' => ['required', 'integer', 'min:1'],
            'fields.catalogue_values' => ['required', 'string', 'max:60000', 'json'],
            'fields.site_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'fields.requested_for_user_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ])->validate()['fields'];
        if (strlen(json_encode($safe, JSON_THROW_ON_ERROR)) > 65536) {
            throw ValidationException::withMessages(['fields' => 'Keep this draft below 64 KB of text and selected references.']);
        }
        $values = json_decode($safe['catalogue_values'], true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($values) || count($values) > 50) {
            throw ValidationException::withMessages(['fields.catalogue_values' => 'Use the original catalogue fields.']);
        }
        $contract = $this->contract((int) $safe['catalog_item_id'], (int) $safe['schema_version']);
        $definitions = collect($contract->form_schema['fields'] ?? [])->keyBy('key');
        if (array_diff(array_keys($values), $definitions->keys()->all()) !== []) {
            throw ValidationException::withMessages(['fields.catalogue_values' => 'Some entries do not belong to the original form.']);
        }
        $rules = [];
        foreach ($values as $key => $value) {
            $field = $definitions[$key];
            $type = $field['type'] ?? 'text';
            $rules[$key] = ['nullable', match ($type) {
                'integer', 'employee', 'user', 'asset' => 'integer', 'number' => 'numeric', 'boolean' => 'boolean',
                'multiselect', 'attachment' => 'array', default => 'string',
            }];
            if (in_array($type, ['text', 'textarea', 'email', 'date'], true)) {
                $rules[$key][] = 'max:'.min(5000, (int) ($field['max'] ?? 5000));
            }
            if ($type === 'select') {
                $rules[$key][] = Rule::in($field['options'] ?? []);
            }
            if ($type === 'multiselect') {
                $rules[$key][] = 'max:50';
                $rules[$key.'.*'] = [Rule::in($field['options'] ?? [])];
            }
            if ($type === 'attachment') {
                $rules[$key][] = 'max:0';
            } // File identity lives on the original ItAttachment rows.
        }
        Validator::make($values, $rules)->validate();

        return $safe;
    }

    public function bind(User $actor, array $fields, array $old): array
    {
        $bound = $old['catalogue'] ?? ['catalog_item_id' => (int) $fields['catalog_item_id'], 'schema_version' => (int) $fields['schema_version'], 'entities' => [], 'internal' => false];
        if ((int) $bound['catalog_item_id'] !== (int) $fields['catalog_item_id'] || (int) $bound['schema_version'] !== (int) $fields['schema_version']) {
            throw DraftError::unavailable();
        }
        $this->authorize($actor, $bound);
        $contract = $this->contract((int) $bound['catalog_item_id'], (int) $bound['schema_version']);
        if (! empty($fields['site_id']) && ! app(ItCatalogAccessService::class)->allowsSite($contract, (int) $fields['site_id'])) {
            throw DraftError::unavailable();
        }
        $values = json_decode($fields['catalogue_values'], true, 64, JSON_THROW_ON_ERROR);
        foreach ($contract->form_schema['fields'] ?? [] as $field) {
            $value = $values[$field['key']] ?? null;
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            if (($field['visibility'] ?? 'requester') !== 'requester') {
                $bound['internal'] = true;
            }
            $entityType = $field['key'] === 'employee_profile_id' && $contract->outcome_type === 'provisioning' ? 'employee' : $field['type'];
            if (in_array($entityType, ItCatalogFieldOptionService::TYPES, true)) {
                $bound['entities'][] = ['type' => $entityType, 'id' => (int) $value];
            }
        }
        $bound['entities'] = array_values(collect($bound['entities'])->unique(fn ($entry) => $entry['type'].':'.$entry['id'])->all());
        if (count($bound['entities']) > 100) {
            throw ValidationException::withMessages(['fields' => 'Save a new request after reviewing the earlier selected records.']);
        }
        if (! empty($fields['requested_for_user_id'])) {
            $bound['requested_for_user_id'] = (int) $fields['requested_for_user_id'];
        }
        $this->authorize($actor, $bound);

        return $bound;
    }

    public function authorize(User $actor, array $scope): void
    {
        $current = ItCatalogItem::query()->published()->find((int) ($scope['catalog_item_id'] ?? 0));
        $original = $this->contract((int) ($scope['catalog_item_id'] ?? 0), (int) ($scope['schema_version'] ?? 0));
        $access = app(ItCatalogAccessService::class);
        if (! $current || ! $access->canDiscover($actor, $current->publishedContract()) || ! $access->canDiscover($actor, $original)
            || (! empty($scope['internal']) && ! $actor->canDo('it.manage'))) {
            throw DraftError::unavailable();
        }
        foreach ($scope['entities'] ?? [] as $entity) {
            if (! app(ItCatalogFieldOptionService::class)->find($actor, (string) $entity['type'], (int) $entity['id'])) {
                throw DraftError::unavailable();
            }
        }
        if (! empty($scope['requested_for_user_id']) && (int) $scope['requested_for_user_id'] !== (int) $actor->id) {
            $person = User::query()->find((int) $scope['requested_for_user_id']);
            if (! $actor->canDo('it.manage') || ! $person || ! app(ItTicketIntakeService::class)->requesterOption($actor, $person)) {
                throw DraftError::unavailable();
            }
        }
    }

    public function field(User $actor, ItTicketDraft $draft, ?string $key): array
    {
        $scope = $draft->bound_scope['catalogue'] ?? [];
        $this->authorize($actor, $scope);
        $contract = $this->contract((int) $scope['catalog_item_id'], (int) $scope['schema_version']);
        $field = collect($contract->form_schema['fields'] ?? [])->firstWhere('key', $key);
        if (! $field || ($field['type'] ?? null) !== 'attachment' || (($field['visibility'] ?? 'requester') !== 'requester' && ! $actor->canDo('it.manage'))) {
            throw DraftError::unavailable();
        }

        return $field;
    }

    public function blocker(ItTicketDraft $draft): ?array
    {
        $scope = $draft->bound_scope['catalogue'] ?? [];
        $current = ItCatalogItem::query()->find($scope['catalog_item_id'] ?? 0)?->publishedVersion;

        return $current && (int) $current->version === (int) ($scope['schema_version'] ?? 0) ? null
            : ['code' => 'catalogue_changed', 'message' => 'The published form changed. Keep this original draft and review the current form before making a new request.'];
    }
}
