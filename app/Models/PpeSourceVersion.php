<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class PpeSourceVersion extends Model
{
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $dateFormat = 'Y-m-d H:i:s.u';
    protected $casts = ['root_id' => 'integer', 'source_id' => 'integer', 'site_id' => 'integer',
        'person_id' => 'integer', 'aggregate_revision' => 'integer', 'source_schema_version' => 'integer',
        'user_id' => 'integer', 'actor_snapshot' => 'array', 'content' => 'array', 'factual_content' => 'array', 'own_content' => 'array', 'recorded_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('PPE source history is immutable.'));
        static::deleting(fn () => throw new LogicException('PPE source history must be retained.'));
    }
}
