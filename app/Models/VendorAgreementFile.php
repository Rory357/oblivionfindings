<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VendorAgreementFile extends Model
{
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $hidden = ['path', 'sha256', 'name'];
    protected $casts = ['created_at' => 'datetime'];
}
