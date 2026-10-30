<?php

namespace App\Domain\SecurityDevices\Cctv\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;

final class CctvRecordingReference extends Model
{
    protected $table = 'cctv_recording_references';

    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['metadata', 'input_hash', 'request_key'];

    protected $casts = ['metadata' => 'encrypted:array', 'created_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        $immutable = static function (): never {
            throw new DomainException('Recording references are immutable.');
        };
        self::updating($immutable);
        self::deleting($immutable);
    }
}
