<?php

namespace App\Domain\Finance\Models;

use App\Models\Client;
use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Database\Factories\Finance\FinInvoiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FinInvoice extends Model
{
    use AuditableChanges, HasFactory, SoftDeletes;

    protected static function newFactory()
    {
        return FinInvoiceFactory::new();
    }

    protected $table = 'fin_invoices';

    protected $fillable = [
        'organization_id',
        'client_id',
        'invoice_number',
        'invoice_date',
        'due_date',
        'client_name',
        'client_email',
        'client_address',
        'funding_body',
        'bill_id',
        'source',
        'source_type',
        'source_id',
        'subtotal',
        'tax_amount',
        'total_amount',
        'currency_code',
        'status',
        'journal_id',
        'gl_posted_at',
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
        'gl_posted_at' => 'datetime',
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

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(FinJournal::class, 'journal_id');
    }

    public function scopeForOrganization($query, ?int $orgId)
    {
        return $query->when($orgId, fn ($q) => $q->where($query->qualifyColumn('organization_id'), $orgId));
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

    /**
     * Next sequential invoice number for an organisation (INV-00001).
     */
    public static function nextNumber(?int $orgId): string
    {
        $latest = static::forOrganization($orgId)
            ->withTrashed()
            ->orderBy('id', 'desc')
            ->value('invoice_number');

        $next = ($latest && preg_match('/INV-(\d+)$/', $latest, $m)) ? ((int) $m[1] + 1) : 1;

        return 'INV-'.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }
}
