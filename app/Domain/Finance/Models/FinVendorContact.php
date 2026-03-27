<?php

namespace App\Domain\Finance\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinVendorContact extends Model
{
    use HasFactory;

    protected $table = 'fin_vendor_contacts';

    protected $fillable = [
        'vendor_id',
        'name',
        'role',
        'email',
        'phone',
        'is_primary',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(FinVendor::class, 'vendor_id');
    }
}
