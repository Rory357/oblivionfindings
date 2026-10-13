<?php

namespace App\Domain\SecurityDevices\Cctv\Models;

use Illuminate\Database\Eloquent\Model;

final class CctvCollectorPresence extends Model
{
    protected $table = 'cctv_collector_presence';

    protected $guarded = [];

    protected $casts = ['available' => 'boolean', 'observed_at' => 'immutable_datetime'];

    protected $hidden = [];

    public $timestamps = false;

    protected $primaryKey = 'collector_id';

    public $incrementing = false;
}
