<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OperationalReportRun extends Model
{
    protected $table = 'operational_report_runs';

    protected $guarded = ['id'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['definition' => 'array', 'payload' => 'encrypted:array', 'expires_at' => 'datetime'];
    }
}
