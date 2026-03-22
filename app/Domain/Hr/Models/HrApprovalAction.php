<?php

namespace App\Domain\Hr\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrApprovalAction extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'approval_instance_id',
        'step_order',
        'action',
        'actioned_by',
        'notes',
        'actioned_at',
    ];

    protected $casts = [
        'step_order' => 'integer',
        'actioned_at' => 'datetime',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function instance(): BelongsTo
    {
        return $this->belongsTo(HrApprovalInstance::class, 'approval_instance_id');
    }

    public function actionedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actioned_by');
    }
}
