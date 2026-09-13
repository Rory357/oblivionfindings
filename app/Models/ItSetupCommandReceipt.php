<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Exact browser command binding; no configuration text is retained here. */
final class ItSetupCommandReceipt extends Model
{
    protected $fillable = [
        'actor_user_id', 'resource', 'request_uuid', 'request_hash',
        'it_team_id', 'it_queue_id', 'it_service_id', 'it_catalog_item_id', 'it_provisioning_template_id',
        'committed_configuration_version', 'committed_at', 'cancelled_at',
    ];

    protected $hidden = ['request_hash'];

    protected function casts(): array
    {
        return ['committed_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }
}
