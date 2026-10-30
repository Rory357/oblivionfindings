<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatUpload extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['scan_started_at' => 'datetime', 'scanned_at' => 'datetime', 'size_bytes' => 'integer', 'duration_ms' => 'integer'];
}
