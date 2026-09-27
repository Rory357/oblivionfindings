<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssetImportBatch extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['version' => 1, 'status' => 'mapping'];

    protected function casts(): array
    {
        return ['headers' => 'array', 'mapping' => 'array', 'rows' => 'array', 'version' => 'integer'];
    }
}
