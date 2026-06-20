<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcedureAcknowledgement extends Model
{
    protected $fillable = [
        'safe_work_procedure_id',
        'user_id',
        'version_acknowledged',
        'acknowledged_at',
    ];

    protected $casts = [
        'version_acknowledged' => 'integer',
        'acknowledged_at' => 'datetime',
    ];

    public function procedure(): BelongsTo
    {
        return $this->belongsTo(SafeWorkProcedure::class, 'safe_work_procedure_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
