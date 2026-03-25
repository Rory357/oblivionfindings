<?php

namespace App\Domain\Roadmap\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InitiativeQualityLink extends Model
{
    use AuditableChanges;
    use HasFactory;

    protected $table = 'roadmap_initiative_quality_links';

    protected $fillable = [
        'tenant_id',
        'initiative_id',
        'source_type',
        'source_id',
        'external_reference',
        'status',
        'notes',
    ];

    public function initiative(): BelongsTo
    {
        return $this->belongsTo(Initiative::class, 'initiative_id');
    }
}
