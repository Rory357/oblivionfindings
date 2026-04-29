<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RosterSuggestion extends Model
{
    use HasFactory;

    public const STATUS_SUGGESTED = 'suggested';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_DISMISSED = 'dismissed';
    public const STATUS_STALE = 'stale';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_CONFLICTED = 'conflicted';

    protected $fillable = [
        'roster_suggestion_run_id',
        'shift_id',
        'candidate_user_id',
        'rank',
        'score',
        'reasons',
        'eligibility_snapshot',
        'status',
        'accepted_by',
        'accepted_at',
        'dismissed_by',
        'dismissed_at',
        'applied_by',
        'applied_at',
    ];

    protected $casts = [
        'rank' => 'integer',
        'score' => 'decimal:2',
        'reasons' => 'array',
        'eligibility_snapshot' => 'array',
        'accepted_at' => 'datetime',
        'dismissed_at' => 'datetime',
        'applied_at' => 'datetime',
    ];

    public function run()
    {
        return $this->belongsTo(RosterSuggestionRun::class, 'roster_suggestion_run_id');
    }

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    public function candidate()
    {
        return $this->belongsTo(User::class, 'candidate_user_id');
    }

    public function acceptedBy()
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    public function dismissedBy()
    {
        return $this->belongsTo(User::class, 'dismissed_by');
    }

    public function appliedBy()
    {
        return $this->belongsTo(User::class, 'applied_by');
    }
}
