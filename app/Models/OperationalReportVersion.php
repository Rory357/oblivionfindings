<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OperationalReportVersion extends Model
{
    protected $table = 'operational_report_versions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['definition' => 'array'];
    }
}
