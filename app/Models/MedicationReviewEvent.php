<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Append-only review evidence; projections never rewrite these events. */
class MedicationReviewEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['details' => 'array', 'created_at' => 'datetime'];

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function review()
    {
        return $this->belongsTo(MedicationReview::class, 'review_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Medication review evidence is immutable.'));
        static::deleting(fn () => throw new \LogicException('Medication review evidence is immutable.'));
    }
}
