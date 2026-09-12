<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Immutable instructions belonging to the existing provisioning template. */
final class ItProvisioningTemplateVersion extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['provisioning_template_id', 'version', 'contract', 'provenance', 'recorded_by_user_id'];

    protected $casts = ['version' => 'integer', 'contract' => 'array'];

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Provisioning template versions are immutable.'));
        self::deleting(fn () => throw new LogicException('Provisioning template versions are immutable.'));
    }
}
