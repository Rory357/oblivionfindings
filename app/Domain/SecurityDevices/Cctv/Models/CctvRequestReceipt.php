<?php

namespace App\Domain\SecurityDevices\Cctv\Models;

use Illuminate\Database\Eloquent\Model;

final class CctvRequestReceipt extends Model
{
    protected $table = 'cctv_request_receipts';

    protected $guarded = [];

    protected $casts = ['response' => 'encrypted:array', 'expires_at' => 'immutable_datetime'];

    protected $hidden = ['response', 'input_hash'];
}
