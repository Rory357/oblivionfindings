<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SiteCredential extends Model
{
    use HasFactory;
    use AuditableChanges;

    protected $fillable = [
        'site_id',
        'tenant_id',
        'vendor_id',
        'label',
        'username',
        'url',
        'credential_type',
        'encrypted_value',
        'iv',
        'notes',
        'last_rotated_at',
        'last_rotated_by_user_id',
        'requires_reauth',
        'is_shareable',
        'password_strength',
        'totp_secret_encrypted',
        'totp_issuer',
        'totp_account',
    ];

    protected $casts = [
        'last_rotated_at' => 'datetime',
        'requires_reauth' => 'boolean',
        'is_shareable' => 'boolean',
        'password_strength' => 'integer',
    ];

    protected $hidden = [
        'encrypted_value',
        'iv',
        'totp_secret_encrypted',
    ];

    public function hasTotp(): bool
    {
        return !empty($this->totp_secret_encrypted);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(SiteVendor::class, 'vendor_id');
    }

    public function lastRotatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_rotated_by_user_id');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(SiteCredentialAuditLog::class, 'credential_id');
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('credential_type', $type);
    }

    public function scopeRequiringReauth($query)
    {
        return $query->where('requires_reauth', true);
    }
}
