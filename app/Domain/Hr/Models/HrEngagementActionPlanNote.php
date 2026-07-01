<?php

namespace App\Domain\Hr\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrEngagementActionPlanNote extends Model
{
    use HasFactory;

    protected $fillable = [
        'plan_id',
        'author_user_id',
        'kind',
        'body',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(HrEngagementActionPlan::class, 'plan_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }
}
