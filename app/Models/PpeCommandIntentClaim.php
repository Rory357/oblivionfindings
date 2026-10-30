<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** A reviewed UUID intent is unique across all PPE roots and actors, including rejected file attempts. */
final class PpeCommandIntentClaim extends Model
{
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $dateFormat = 'Y-m-d H:i:s.u';
    protected $casts = ['user_id' => 'integer', 'root_id' => 'integer', 'site_id' => 'integer', 'source_id' => 'integer',
        'attachment_id' => 'integer', 'expected_version' => 'integer', 'created_at' => 'immutable_datetime'];
    public function identity(): array
    {
        return array_diff_key($this->attributesToArray(), array_flip(['id', 'identity_digest', 'created_at']));
    }
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('PPE reviewed command intent is immutable.'));
        static::deleting(fn () => throw new LogicException('PPE reviewed command intent must be retained.'));
    }
}