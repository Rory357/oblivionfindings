<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MedicineCatalogueSource extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['licence_attested_at' => 'immutable_datetime', 'reviewed_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime', 'version' => 'integer'];
    }

    public function products(): HasMany
    {
        return $this->hasMany(MedicineCatalogueProduct::class, 'source_id');
    }
}
