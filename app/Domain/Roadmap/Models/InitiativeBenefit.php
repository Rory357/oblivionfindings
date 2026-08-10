<?php

namespace App\Domain\Roadmap\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InitiativeBenefit extends Model
{
    use AuditableChanges;
    use HasFactory;
    use WritesLegacyStorageContext;

    protected $table = 'roadmap_initiative_benefits';

    protected $fillable = [
        'initiative_id',
        'benefit_type',
        'baseline_value',
        'target_value',
        'estimated_value_low',
        'estimated_value_high',
        'measurement_method',
        'realisation_fiscal_year',
        'realisation_quarter',
        'status',
        'notes',
    ];

    protected $casts = [
        'baseline_value' => 'decimal:2',
        'target_value' => 'decimal:2',
        'estimated_value_low' => 'decimal:2',
        'estimated_value_high' => 'decimal:2',
    ];

    public function initiative(): BelongsTo
    {
        return $this->belongsTo(Initiative::class, 'initiative_id');
    }
}
