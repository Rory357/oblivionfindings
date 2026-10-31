<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcedureAcknowledgementReceipt extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['acknowledged_at' => 'datetime'];

    public function procedure(): BelongsTo
    {
        return $this->belongsTo(SafeWorkProcedure::class, 'safe_work_procedure_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(SafeWorkProcedureVersion::class, 'version_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
