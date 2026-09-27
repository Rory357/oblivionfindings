<?php

namespace App\Domain\Finance\Models;

use Illuminate\Database\Eloquent\Model;

final class FinBillDocument extends Model
{
    protected $guarded = [];

    protected $hidden = ['path', 'request_key'];

    protected $casts = ['size' => 'integer', 'scanned_at' => 'datetime'];
}
