<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetProcedureRun extends Model
{
    protected $fillable = [
        'asset_id',
        'procedure_run_id',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function procedureRun(): BelongsTo
    {
        return $this->belongsTo(ProcedureRun::class, 'procedure_run_id');
    }
}
