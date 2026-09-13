<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VendorAgreement extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['creation_key', 'creation_digest', 'terms', 'amount', 'reference', 'evidence'];
    protected $casts = ['starts_on' => 'date', 'renews_on' => 'date', 'amount' => 'decimal:2', 'lock_version' => 'integer'];
    public function vendor() { return $this->belongsTo(SiteVendor::class, 'vendor_id'); }
    public function site() { return $this->belongsTo(Site::class); }
    public function owner() { return $this->belongsTo(User::class, 'owner_user_id'); }
    public function files() { return $this->hasMany(VendorAgreementFile::class, 'agreement_id'); }
}
