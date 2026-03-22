<?php

namespace App\Domain\Hr\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrApprovalChainStep extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'approval_chain_id',
        'step_order',
        'approver_type',
        'approver_role_id',
        'approver_user_id',
        'auto_approve_after_days',
        'created_at',
    ];

    protected $casts = [
        'step_order' => 'integer',
        'auto_approve_after_days' => 'integer',
        'created_at' => 'datetime',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function chain(): BelongsTo
    {
        return $this->belongsTo(HrApprovalChain::class, 'approval_chain_id');
    }

    public function approverRole(): BelongsTo
    {
        return $this->belongsTo(\Spatie\Permission\Models\Role::class, 'approver_role_id');
    }

    public function approverUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }
}
