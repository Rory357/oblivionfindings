<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssetLabelBatch extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['status' => 'ready'];

    protected function casts(): array
    {
        return ['asset_ids' => 'array', 'layout' => 'array', 'downloads' => 'array', 'expires_at' => 'immutable_datetime'];
    }
}
