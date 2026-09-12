<?php

namespace App\Models;

use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ItCatalogItem extends Model
{
    use HasFactory, SoftDeletes, WritesLegacyStorageContext;

    public const OUTCOME_TYPES = ['service_request', 'security_request', 'provisioning'];

    public const CONTRACT_FIELDS = ['it_service_id', 'name', 'slug', 'description', 'outcome_type', 'category', 'provisioning_type', 'default_priority', 'requires_approval', 'internal_only', 'site_scope', 'form_schema_version', 'form_schema', 'search_terms', 'sort_order'];

    protected $fillable = [
        'it_service_id',
        'name',
        'slug',
        'description',
        'outcome_type',
        'category',
        'provisioning_type',
        'default_priority',
        'requires_approval',
        'is_published',
        'internal_only',
        'site_scope',
        'form_schema_version',
        'form_schema',
        'search_terms',
        'sort_order',
        'created_by',
        'updated_by',
        'lock_version',
        'published_version_id',
    ];

    protected $casts = [
        'requires_approval' => 'boolean',
        'is_published' => 'boolean',
        'internal_only' => 'boolean',
        'site_scope' => 'array',
        'form_schema_version' => 'integer',
        'form_schema' => 'array',
        'search_terms' => 'array',
        'sort_order' => 'integer',
        'lock_version' => 'integer',
    ];

    protected static function booted(): void
    {
        static::created(function (self $item): void {
            $item->refresh();
            // Existing seed/import callers may create published records. Capture
            // their actual contract without claiming a human publication review.
            if ($item->is_published) {
                $version = ItCatalogVersion::query()->create([
                    'catalog_item_id' => $item->id,
                    'version' => $item->form_schema_version,
                    'contract' => $item->only(self::CONTRACT_FIELDS),
                    'provenance' => 'imported_current',
                ]);
                $item->forceFill(['published_version_id' => $version->id])->saveQuietly();
            }
        });
    }

    protected function performInsert(Builder $query)
    {
        // Seed/import callers also need the item and its initial publication
        // to commit together; a failed snapshot must not leave a partial item.
        return $this->getConnection()->transaction(fn () => parent::performInsert($query));
    }

    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(ItCatalogVersion::class, 'published_version_id');
    }

    public function publishedContract(): self
    {
        $version = $this->publishedVersion;
        abort_unless($this->is_published && $version && (int) $version->catalog_item_id === (int) $this->id, 404);
        $copy = clone $this;
        // Older immutable versions predate Site restrictions. Never inherit
        // a newer draft's restrictions while reading that original contract.
        $copy->forceFill(['site_scope' => null, ...$version->contract]);

        return $copy;
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(ItService::class, 'it_service_id');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(ItCatalogSubmission::class, 'catalog_item_id');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true)->whereHas('publishedVersion', fn (Builder $versions) => $versions->whereColumn('catalog_item_id', 'it_catalog_items.id'));
    }

    /**
     * Permission-safe discovery projection. Requesters never receive the
     * existence, label, or defaults of technician-only form fields.
     *
     * @return array<string, mixed>
     */
    public function discoveryPayload(bool $includeInternalFields = false): array
    {
        $schema = $this->form_schema ?? ['fields' => []];
        $fields = collect($schema['fields'] ?? [])
            ->filter(fn (mixed $field) => is_array($field))
            ->when(! $includeInternalFields, fn ($items) => $items->reject(
                fn (array $field) => in_array($field['visibility'] ?? 'requester', ['internal', 'restricted'], true),
            ))
            ->values()
            ->all();
        $schema['fields'] = $fields;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'outcome_type' => $this->outcome_type,
            'category' => $this->category,
            'default_priority' => $this->default_priority,
            'requires_approval' => $this->requires_approval,
            'form_schema_version' => $this->form_schema_version,
            'form_schema' => $schema,
        ];
    }
}
