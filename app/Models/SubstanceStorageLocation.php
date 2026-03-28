<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SubstanceStorageLocation extends Model
{
    use HasFactory;
    use AuditableChanges;
    use SoftDeletes;

    protected $fillable = [
        'hazardous_substance_id',
        'site_id',
        'location_description',
        'current_quantity',
        'quantity_unit',
        'maximum_quantity',
        'container_type',
        'properly_labelled',
        'segregation_compliant',
        'last_audit_date',
        'storage_notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'current_quantity' => 'decimal:2',
        'maximum_quantity' => 'decimal:2',
        'properly_labelled' => 'boolean',
        'segregation_compliant' => 'boolean',
        'last_audit_date' => 'date',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function hazardousSubstance(): BelongsTo
    {
        return $this->belongsTo(HazardousSubstance::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes                                                             */
    /* ------------------------------------------------------------------ */

    public function scopeForSite($query, int $siteId)
    {
        return $query->where('site_id', $siteId);
    }

    public function scopeNonCompliant($query)
    {
        return $query->where('properly_labelled', false)
            ->orWhere('segregation_compliant', false);
    }
}
