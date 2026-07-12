<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HsRecommendationDisposition extends Model
{
    use HasFactory;

    public const DISPOSITION_CORRECTIVE_ACTION = 'corrective_action';

    public const DISPOSITION_ACCEPTED_RISK = 'accepted_risk';

    public const DISPOSITION_DUPLICATE = 'duplicate';

    public const DISPOSITION_NO_ACTION = 'no_action';

    protected $fillable = [
        'hs_investigation_id',
        'recommendation_index',
        'disposition',
        'reason',
        'hs_corrective_action_id',
        'decided_by_user_id',
        'decided_at',
    ];

    protected $casts = [
        'recommendation_index' => 'integer',
        'decided_at' => 'datetime',
    ];

    public function investigation(): BelongsTo
    {
        return $this->belongsTo(HsInvestigation::class, 'hs_investigation_id');
    }

    public function correctiveAction(): BelongsTo
    {
        return $this->belongsTo(HsCorrectiveAction::class, 'hs_corrective_action_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }
}
