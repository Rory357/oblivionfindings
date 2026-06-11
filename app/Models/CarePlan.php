<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CarePlan extends Model
{
    use HasFactory;
    use AuditableChanges;
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'client_id',
        'title',
        'status',
        'plan_type',
        'starts_at',
        'ends_at',
        'next_review_at',
        'reviewed_at',
        'reviewed_by',
        'created_by',
        'content',
        'version',
        'parent_id',
    ];

    protected $casts = [
        'starts_at' => 'date',
        'ends_at' => 'date',
        'next_review_at' => 'date',
        'reviewed_at' => 'datetime',
        'content' => 'array',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function goals()
    {
        return $this->hasMany(CarePlanGoal::class);
    }

    public function parent()
    {
        return $this->belongsTo(CarePlan::class, 'parent_id');
    }

    public function versions()
    {
        return $this->hasMany(CarePlan::class, 'parent_id');
    }

    /**
     * Recorded agreements to this plan version (client, whānau, EOR/guardian, etc.).
     * Sign-offs are version-specific and are intentionally NOT copied when a review
     * clones a new version — the reviewed plan must be agreed afresh.
     */
    public function signOffs()
    {
        return $this->hasMany(CarePlanSignOff::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeReviewDue($query)
    {
        return $query->where('next_review_at', '<=', now());
    }
}
