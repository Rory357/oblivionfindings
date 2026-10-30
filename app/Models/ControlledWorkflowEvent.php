<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class ControlledWorkflowEvent extends Model
{
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['payload' => 'array', 'created_at' => 'datetime'];
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Controlled medicine history cannot be edited.'));
        static::deleting(fn () => throw new LogicException('Controlled medicine history cannot be deleted.'));
    }
}
