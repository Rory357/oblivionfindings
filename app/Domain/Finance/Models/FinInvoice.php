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
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

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
        'quote_source_id',
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
     * Establish and lock the durable per-storage-context number sequence.
     *
     * Callers that also lock a source aggregate must take this mutex first.
     */
    public static function lockNumberSequence(?int $orgId): void
    {
        $orgId = static::validatedStorageContextId($orgId);
        if (DB::connection()->transactionLevel() < 1) {
            throw new RuntimeException('Invoice number allocation requires an active transaction.');
        }

        $floor = static::ledgerNumberFloor($orgId);
        DB::table('fin_invoice_sequences')->insertOrIgnore([
            'organization_id' => $orgId,
            'next_number' => $floor,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sequence = DB::table('fin_invoice_sequences')
            ->where('organization_id', $orgId)
            ->lockForUpdate()
            ->first();

        if (! $sequence) {
            throw new RuntimeException('The invoice number sequence could not be established.');
        }
    }

    /**
     * Allocate the next sequential invoice number while holding the durable
     * per-storage-context sequence mutex (INV-00001 by default).
     */
    public static function nextNumber(?int $orgId, int $minimum = 1, int $padding = 5): string
    {
        $orgId = static::validatedStorageContextId($orgId);
        if ($minimum < 1 || $padding < 1) {
            throw new InvalidArgumentException('Invoice number bounds must be positive integers.');
        }

        static::lockNumberSequence($orgId);

        $sequence = DB::table('fin_invoice_sequences')
            ->where('organization_id', $orgId)
            ->lockForUpdate()
            ->first();
        if (! $sequence) {
            throw new RuntimeException('The invoice number sequence is missing.');
        }

        $next = max((int) $sequence->next_number, static::ledgerNumberFloor($orgId), $minimum);
        DB::table('fin_invoice_sequences')
            ->where('organization_id', $orgId)
            ->update([
                'next_number' => $next + 1,
                'updated_at' => now(),
            ]);

        return 'INV-'.str_pad((string) $next, $padding, '0', STR_PAD_LEFT);
    }

    private static function validatedStorageContextId(?int $orgId): int
    {
        if ($orgId === null || $orgId < 1) {
            throw new InvalidArgumentException('A valid invoice storage context is required.');
        }

        return $orgId;
    }

    private static function ledgerNumberFloor(int $orgId): int
    {
        $maximum = static::query()
            ->withTrashed()
            ->where('organization_id', $orgId)
            ->pluck('invoice_number')
            ->reduce(function (int $current, string $invoiceNumber): int {
                if (! preg_match('/^INV-(\d+)$/', $invoiceNumber, $matches)) {
                    return $current;
                }

                return max($current, (int) $matches[1]);
            }, 0);

        return $maximum + 1;
    }
}
