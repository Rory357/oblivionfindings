<?php

namespace App\Domain\Roadmap\Models;

use App\Domain\Governance\Models\RiskRegisterEntry;
use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InitiativeRiskLink extends Model
{
    use AuditableChanges;
    use HasFactory;
    use WritesLegacyStorageContext;

    protected $table = 'roadmap_initiative_risk_links';

    protected $fillable = [
        'initiative_id',
        'risk_register_entry_id',
        'link_type',
        'risk_delta_expected',
        'within_appetite_expected',
        'notes',
    ];

    protected $casts = [
        'risk_delta_expected' => 'decimal:2',
        'within_appetite_expected' => 'boolean',
    ];

    public function initiative(): BelongsTo
    {
        return $this->belongsTo(Initiative::class, 'initiative_id');
    }

    public function risk(): BelongsTo
    {
        return $this->belongsTo(RiskRegisterEntry::class, 'risk_register_entry_id');
    }
}
