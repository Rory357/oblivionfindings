<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MedicationAdminRule extends Model
{
    use HasFactory;
    use AuditableChanges;

    protected $fillable = [
        'site_id',
        'match_type',
        'match_value',
        'requires_countersign',
        'required_observations',
        'active',
        'created_by',
    ];

    protected $casts = [
        'requires_countersign' => 'boolean',
        'required_observations' => 'array',
        'active' => 'boolean',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
