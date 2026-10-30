<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MedicationFollowupEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['data' => 'array', 'created_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Medication follow-up history is append-only.'));
        static::deleting(fn () => throw new \LogicException('Medication follow-up history is append-only.'));
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
