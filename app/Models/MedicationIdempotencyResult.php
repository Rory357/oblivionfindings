<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

final class MedicationIdempotencyResult extends Model
{
    use Prunable;

    protected $fillable = [
        'scope',
        'request_uuid',
        'response_payload',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'response_payload' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<=', now());
    }
}
