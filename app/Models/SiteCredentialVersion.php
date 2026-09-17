<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SiteCredentialVersion extends Model
{
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $hidden = ['encrypted_snapshot'];
    protected $casts = ['created_at' => 'datetime'];
}
