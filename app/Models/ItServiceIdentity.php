<?php

namespace App\Models;

use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ItServiceIdentity extends Model
{
    use WritesLegacyStorageContext;

    public const ABILITIES = [
        'work:create',
        'work:read',
        'work:comment',
        'work:transition',
        'work:sensitive',
        'work:organisation-wide',
    ];

    public const CREATE_FIELDS = [
        'title', 'description', 'category', 'subcategory', 'priority', 'impact',
        'urgency', 'work_type', 'site_id', 'is_organisation_wide', 'it_service_id', 'asset_id',
    ];

    public const REQUIRED_CREATE_FIELDS = ['title', 'category', 'priority', 'work_type'];

    public const READ_FIELDS = [
        'description', 'category', 'subcategory', 'impact', 'urgency', 'site', 'service', 'asset',
        'queue', 'team', 'owner', 'assignee', 'sla', 'resolution',
    ];

    protected $fillable = [
        'actor_user_id',
        'created_by_user_id',
        'revoked_by_user_id',
        'public_id',
        'name',
        'description',
        'token_hash',
        'abilities',
        'allowed_work_types',
        'allowed_site_ids',
        'allowed_fields',
        'require_signature',
        'rate_limit_per_minute',
        'expires_at',
        'revoked_at',
        'last_used_at',
    ];

    protected $hidden = ['token_hash'];

    protected $casts = [
        'abilities' => 'array',
        'allowed_work_types' => 'array',
        'allowed_site_ids' => 'array',
        'allowed_fields' => 'array',
        'require_signature' => 'boolean',
        'rate_limit_per_minute' => 'integer',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'last_used_at' => 'datetime',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by_user_id');
    }

    public function requests(): HasMany
    {
        return $this->hasMany(ItApiRequest::class, 'service_identity_id');
    }

    public function hasAbility(string $ability): bool
    {
        return in_array($ability, $this->abilities ?? [], true);
    }

    public function allowsWorkType(string $workType): bool
    {
        return in_array($workType, $this->allowed_work_types ?? [], true);
    }

    public function allowsSite(?int $siteId): bool
    {
        return $siteId !== null
            && in_array($siteId, array_map('intval', $this->allowed_site_ids ?? []), true);
    }

    public function allowsSensitiveWork(): bool
    {
        return $this->hasAbility('work:sensitive');
    }

    public function allowsOrganisationWideWork(): bool
    {
        return $this->hasAbility('work:organisation-wide');
    }

    public function allowsField(string $operation, string $field): bool
    {
        return in_array($field, (array) ($this->allowed_fields[$operation] ?? []), true);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
