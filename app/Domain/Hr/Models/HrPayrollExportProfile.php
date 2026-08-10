<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyStorageContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrPayrollExportProfile extends Model
{
    use AuditableChanges, HasFactory, WritesLegacyStorageContext;

    protected $fillable = [
        'tenant_id',
        'name',
        'provider_key',
        'description',
        'delimiter',
        'enclosure',
        'line_ending',
        'include_headers',
        'is_default',
        'mappings',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'include_headers' => 'boolean',
        'is_default' => 'boolean',
        'mappings' => 'array',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
