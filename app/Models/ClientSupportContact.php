<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An explicitly released staff-care contact, separate from emergency contacts and portal membership. */
class ClientSupportContact extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['release'];

    protected function casts(): array
    {
        return ['release' => 'encrypted:array', 'version' => 'integer'];
    }
}
