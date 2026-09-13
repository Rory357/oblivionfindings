<?php

namespace App\Domain\Governance\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GovernanceVotingProfile extends Model
{
    use AuditableChanges, HasFactory;

    protected $table = 'governance_voting_profiles';

    protected $fillable = [
        'governing_body',
        'board_committee_id',
        'legal_form',
        'governing_document_reference',
        'governing_document_version',
        'quorum_mode',
        'quorum_formula',
        'ordinary_threshold_formula',
        'unanimous_denominator_formula',
        'written_voting_permitted',
        'written_unanimity_required',
        'recusal_policy',
        'is_active',
        'approved_by_resolution_id',
        'approved_by_user_id',
        'approved_at',
        'effective_from',
        'effective_to',
        'metadata',
        'created_by',
    ];

    protected $casts = [
        'written_voting_permitted' => 'boolean',
        'written_unanimity_required' => 'boolean',
        'is_active' => 'boolean',
        'approved_at' => 'datetime',
        'effective_from' => 'datetime',
        'effective_to' => 'datetime',
        'metadata' => 'array',
    ];

    public function committee(): BelongsTo
    {
        return $this->belongsTo(BoardCommittee::class, 'board_committee_id');
    }

    public function approvedByResolution(): BelongsTo
    {
        return $this->belongsTo(Resolution::class, 'approved_by_resolution_id');
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('effective_from')
                    ->orWhere('effective_from', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', now());
            });
    }

    public function isConfirmed(): bool
    {
        return $this->is_active
            && !empty($this->governing_document_reference)
            && (!empty($this->approved_at) || !empty($this->approved_by_resolution_id));
    }

    public static function candidateDefaults(string $governingBody = 'board', ?int $committeeId = null): array
    {
        return [
            'governing_body' => $governingBody,
            'board_committee_id' => $committeeId,
            'legal_form' => 'charitable_trust',
            'governing_document_reference' => 'Candidate Governance Profile (Pending D1 Legal Authority)',
            'governing_document_version' => '1.0-candidate',
            'quorum_mode' => 'majority_floor_plus_one',
            'quorum_formula' => 'floor(N/2)+1',
            'ordinary_threshold_formula' => 'for > against of valid votes cast',
            'unanimous_denominator_formula' => 'assent from all entitled voters',
            'written_voting_permitted' => false,
            'written_unanimity_required' => true,
            'recusal_policy' => 'exclude_from_presence_and_tally_without_reducing_N',
            'is_active' => false,
            'metadata' => [
                'status_note' => 'Candidate D1 default profile. Live voting requires recording actual constitution/trust deed authority and approval.',
            ],
        ];
    }
}
