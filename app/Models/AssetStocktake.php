<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssetStocktake extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['version' => 1, 'status' => 'draft'];

    protected function casts(): array
    {
        return ['scope' => 'array', 'entries' => 'array', 'activity' => 'array', 'counted_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime', 'version' => 'integer'];
    }
}
