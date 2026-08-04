<?php

namespace App\Domain\Roadmap\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyStorageContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssuranceEvidencePlan extends Model
{
    use AuditableChanges;
    use HasFactory;
    use WritesLegacyStorageContext;

    protected $table = 'roadmap_assurance_evidence_plans';

    protected $fillable = [
        'initiative_id',
        'control_name',
        'evidence_type',
        'evidence_source_type',
        'evidence_source_id',
        'verifier_user_id',
        'verify_due_date',
        'verification_frequency',
        'verified_at',
        'verification_result',
        'document_reference',
        'notes',
    ];

    protected $casts = [
        'verify_due_date' => 'date',
        'verified_at' => 'datetime',
    ];

    public function initiative(): BelongsTo
    {
        return $this->belongsTo(Initiative::class, 'initiative_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verifier_user_id');
    }
}
