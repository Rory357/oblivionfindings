<?php

namespace App\Domain\Referrals\Models;

use App\Domain\Referrals\Models\Concerns\HasReferralReference;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CareReferral extends Model
{
    use HasReferralReference;

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    protected $table = 'care_referrals';

    protected $fillable = [
        'reference',
        'site_id',
        'owner_id',
        'settings_version_id',
        'client_id',
        'stage',
        'source',
        'entry_kind',
        'requested_service',
        'team',
        'priority',
        'restricted',
        'general_enquiry',
        'identity',
        'intake',
        'authority',
        'assessment',
        'next_action',
        'next_due_at',
        'hold_reason',
        'closure_reason',
        'stage_changed_at',
        'first_contact_attempt_at',
        'first_human_contact_at',
        'primary_work_item_id',
        'converted_at',
        'closed_at',
        'lock_version',
    ];

    protected $hidden = ['identity', 'intake', 'authority', 'assessment', 'next_action'];

    protected $casts = [
        'identity' => 'encrypted:array',
        'intake' => 'encrypted:array',
        'authority' => 'encrypted:array',
        'assessment' => 'encrypted:array',
        'next_action' => 'encrypted',
        'restricted' => 'boolean',
        'general_enquiry' => 'boolean',
        'next_due_at' => 'immutable_datetime',
        'stage_changed_at' => 'immutable_datetime',
        'first_contact_attempt_at' => 'immutable_datetime',
        'first_human_contact_at' => 'immutable_datetime',
        'primary_work_item_id' => 'integer',
        'converted_at' => 'immutable_datetime',
        'closed_at' => 'immutable_datetime',
        'lock_version' => 'integer',
    ];
}
