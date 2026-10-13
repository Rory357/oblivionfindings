<?php

namespace App\Domain\CompanyHub\Models;

use Illuminate\Database\Eloquent\Model;

class HubMedia extends Model
{
    protected $table = 'hub_media';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['crop' => 'array', 'version' => 'integer'];
    }
}
