<?php

namespace App\Domain\Finance\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FinInvoice extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    protected static function newFactory()
    {
        return \Database\Factories\Finance\FinInvoiceFactory::new();
    }

    protected $table = 'fin_invoices';

    protected $fillable = [
        'organization_id',
        'invoice_number',
        'invoice_date',
        'due_date',
        'client_name',
        'client_email',
        'client_address',
        'bill_id',
        'subtotal',
        'tax_amount',
        'total_amount',
        'currency_code',
        'status',
        'sent_at',
        'viewed_at',
        'paid_at',
        'notes',
        'terms',
        'pdf_path',
        'email_subject',
        'email_body',
        'created_by',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'due_date' => 'date',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'sent_at' => 'datetime',
        'viewed_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(FinInvoiceLine::class, 'invoice_id')->orderBy('sort_order');
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(FinBill::class, 'bill_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeForOrganization($query, ?int $orgId)
    {
        return $query->when($orgId, fn($q) => $q->where('organization_id', $orgId));
    }

    public function scopeOfStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeOverdue($query)
    {
        return $query->where('due_date', '<', now())
            ->whereNotIn('status', ['paid', 'cancelled']);
    }
}
