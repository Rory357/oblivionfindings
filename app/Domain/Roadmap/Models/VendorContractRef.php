<?php

namespace App\Domain\Roadmap\Models;

use App\Models\SiteVendor;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorContractRef extends Model
{
    use HasFactory;

    protected $table = 'roadmap_vendor_contract_refs';

    protected $fillable = [
        'tenant_id',
        'initiative_id',
        'site_vendor_id',
        'vendor_name',
        'contract_ref',
        'renewal_date',
        'notice_days',
        'annual_cost',
        'status',
        'source_module',
        'meta',
    ];

    protected $casts = [
        'renewal_date' => 'date',
        'annual_cost' => 'decimal:2',
        'meta' => 'array',
    ];

    public function initiative(): BelongsTo
    {
        return $this->belongsTo(Initiative::class, 'initiative_id');
    }

    public function siteVendor(): BelongsTo
    {
        return $this->belongsTo(SiteVendor::class, 'site_vendor_id');
    }
}
