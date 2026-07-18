<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ItCatalogItem extends Model
{
    use HasFactory, SoftDeletes;

    public const OUTCOME_TYPES = ['service_request', 'security_request', 'provisioning'];

    protected $fillable = [
        'tenant_id',
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
        'form_schema_version',
        'form_schema',
        'search_terms',
        'sort_order',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'requires_approval' => 'boolean',
        'is_published' => 'boolean',
        'internal_only' => 'boolean',
        'form_schema_version' => 'integer',
        'form_schema' => 'array',
        'search_terms' => 'array',
        'sort_order' => 'integer',
    ];

    public function service(): BelongsTo
    {
        return $this->belongsTo(ItService::class, 'it_service_id');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(ItCatalogSubmission::class, 'catalog_item_id');
    }

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
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
