<?php

namespace App\Domain\Finance\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FinVendor extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    protected $table = 'fin_vendors';

    protected $fillable = [
        'organization_id',
        'name',
        'trading_name',
        'vendor_type',
        'gst_number',
        'bank_account_number',
        'email',
        'phone',
        'address_line_1',
        'address_line_2',
        'city',
        'region',
        'postal_code',
        'payment_terms_days',
        'default_expense_account_id',
        'is_active',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'bank_account_number' => 'encrypted',
        'payment_terms_days' => 'integer',
        'is_active' => 'boolean',
    ];

    public function defaultExpenseAccount(): BelongsTo
    {
        return $this->belongsTo(FinAccount::class, 'default_expense_account_id');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(FinVendorContact::class, 'vendor_id');
    }

    public function bills(): HasMany
    {
        return $this->hasMany(FinBill::class, 'vendor_id');
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(FinPurchaseOrder::class, 'vendor_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeForOrganization($query, ?int $orgId)
    {
        return $query->when($orgId, fn($q) => $q->where('organization_id', $orgId));
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
