<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HrOnboardingTemplate extends Model
{
    use HasFactory, AuditableChanges;

    protected $fillable = [
        'tenant_id',
        'role',
        'site_type',
        'tasks',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'tasks' => 'array',
        'is_active' => 'boolean',
    ];

    /* ------------------------------------------------------------------ */
    /*  Scopes                                                             */
    /* ------------------------------------------------------------------ */

    public function scopeForTenant($query, ?int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForRole($query, string $role)
    {
        return $query->where('role', $role);
    }
}
