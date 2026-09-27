<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OperationalReport extends Model
{
    protected $table = 'operational_reports';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['definition' => 'array', 'shared_with' => 'array', 'favourite' => 'boolean', 'archived_at' => 'datetime'];
    }
}
