<?php

namespace App\Domain\CompanyHub\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HubEditorialPlan extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['audience' => 'array', 'version' => 'integer', 'owner_id' => 'integer', 'target_date' => 'date'];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(HubPage::class, 'hub_page_id');
    }
}
