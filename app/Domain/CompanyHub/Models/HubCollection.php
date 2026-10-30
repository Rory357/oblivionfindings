<?php

namespace App\Domain\CompanyHub\Models;

use Illuminate\Database\Eloquent\Model;

/** Shared curation metadata; membership belongs to published page snapshots. */
class HubCollection extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['version' => 'integer'];
}
