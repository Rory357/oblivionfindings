<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssetKitItem extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['removed_at' => 'datetime'];
}
