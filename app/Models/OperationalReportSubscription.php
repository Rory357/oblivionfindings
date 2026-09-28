<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OperationalReportSubscription extends Model
{
    protected $table = 'operational_report_subscriptions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'next_run_at' => 'datetime'];
    }
}
