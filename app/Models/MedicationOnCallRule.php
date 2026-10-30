<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A house's on-call contact rule (eMAR P11 B2 chunk 4); no row = not configured. */
class MedicationOnCallRule extends Model
{
    public const ROSTER = 'roster';

    public const FIXED = 'fixed';

    protected $table = 'medication_oncall_rules';

    protected $fillable = ['site_id', 'mode', 'team_lead', 'backup_user_id', 'updated_by'];

    protected $casts = [
        'team_lead' => 'boolean',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function backup(): BelongsTo
    {
        return $this->belongsTo(User::class, 'backup_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
