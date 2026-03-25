<?php

namespace App\Domain\Roadmap\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InitiativeDependency extends Model
{
    use AuditableChanges;
    use HasFactory;

    protected $table = 'roadmap_initiative_dependencies';

    protected $fillable = [
        'tenant_id',
        'initiative_id',
        'depends_on_initiative_id',
        'external_ref',
        'dependency_type',
        'risk_level',
        'status',
        'due_date',
        'notes',
    ];

    protected $casts = [
        'due_date' => 'date',
    ];

    public function initiative(): BelongsTo
    {
        return $this->belongsTo(Initiative::class, 'initiative_id');
    }

    public function dependsOn(): BelongsTo
    {
        return $this->belongsTo(Initiative::class, 'depends_on_initiative_id');
    }
}
