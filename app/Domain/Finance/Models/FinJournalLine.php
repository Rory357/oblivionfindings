<?php

namespace App\Domain\Finance\Models;

use App\Models\Client;
use App\Models\ClientFund;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinJournalLine extends Model
{
    use HasFactory;

    protected $table = 'fin_journal_lines';

    protected $fillable = [
        'journal_id',
        'account_id',
        'cost_centre_id',
        'funding_stream_id',
        'client_id',
        'client_fund_id',
        'site_id',
        'description',
        'debit',
        'credit',
        'tax_rate_id',
        'tax_amount',
    ];

    protected $casts = [
        'debit' => 'decimal:2',
        'credit' => 'decimal:2',
        'tax_amount' => 'decimal:2',
    ];

    public function journal(): BelongsTo
    {
        return $this->belongsTo(FinJournal::class, 'journal_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinAccount::class, 'account_id');
    }

    public function costCentre(): BelongsTo
    {
        return $this->belongsTo(FinCostCentre::class, 'cost_centre_id');
    }

    public function fundingStream(): BelongsTo
    {
        return $this->belongsTo(FinFundingStream::class, 'funding_stream_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function clientFund(): BelongsTo
    {
        return $this->belongsTo(ClientFund::class, 'client_fund_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id');
    }

    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(FinTaxRate::class, 'tax_rate_id');
    }
}
