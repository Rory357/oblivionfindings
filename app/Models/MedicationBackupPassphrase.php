<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * EA-141: what's needed to encrypt a recipient's backups with their own
 * passphrase — never the passphrase. `template` is the base64 of an encrypted
 * one-page PDF; `owner_secret` is its random owner password.
 */
class MedicationBackupPassphrase extends Model
{
    protected $guarded = [];

    protected $hidden = ['template', 'owner_secret'];

    protected function casts(): array
    {
        return ['template' => 'encrypted', 'owner_secret' => 'encrypted', 'set_at' => 'datetime'];
    }
}
