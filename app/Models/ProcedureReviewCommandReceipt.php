<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProcedureReviewCommandReceipt extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['confirmed_result' => 'array'];
}
