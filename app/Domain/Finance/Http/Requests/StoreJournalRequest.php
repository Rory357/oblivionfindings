<?php

namespace App\Domain\Finance\Http\Requests;

use App\Domain\Finance\Models\FinJournal;
use Illuminate\Foundation\Http\FormRequest;

class StoreJournalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', FinJournal::class);
    }

    public function rules(): array
    {
        return [
            'journal_date' => ['required', 'date'],
            'type' => ['required', 'in:standard,adjustment,opening'],
            'reference' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_id' => ['required', 'exists:fin_accounts,id'],
            'lines.*.description' => ['nullable', 'string', 'max:500'],
            'lines.*.debit' => ['required', 'numeric', 'min:0'],
            'lines.*.credit' => ['required', 'numeric', 'min:0'],
            'lines.*.cost_centre_id' => ['nullable', 'exists:fin_cost_centres,id'],
            'lines.*.funding_stream_id' => ['nullable', 'exists:fin_funding_streams,id'],
            'lines.*.tax_rate_id' => ['nullable', 'exists:fin_tax_rates,id'],
            'lines.*.tax_amount' => ['nullable', 'numeric', 'min:0'],
            'post_immediately' => ['nullable', 'boolean'],
        ];
    }
}
