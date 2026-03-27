<?php

namespace App\Domain\Finance\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinCashFlowScenario extends Model
{
    use HasFactory;

    protected $table = 'fin_cash_flow_scenarios';

    protected $fillable = [
        'forecast_id',
        'name',
        'adjustments',
        'forecast_data',
    ];

    protected $casts = [
        'adjustments' => 'array',
        'forecast_data' => 'array',
    ];

    public function forecast(): BelongsTo
    {
        return $this->belongsTo(FinCashFlowForecast::class, 'forecast_id');
    }
}
