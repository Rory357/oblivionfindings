<?php

namespace App\Domain\Governance\Http\Requests;

use App\Domain\Governance\Models\Resolution;
use Illuminate\Foundation\Http\FormRequest;

class StoreResolutionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Resolution::class);
    }

    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'exact_motion' => 'nullable|string',
            'purpose' => 'nullable|string|in:decision,discussion,information',
            'decision_type' => 'nullable|string|max:100',
            'type' => 'nullable|string|in:ordinary,special,unanimous',
            'description' => 'nullable|string',
            'context' => 'nullable|string',
            'options' => 'nullable|array',
            'options.*.label' => 'nullable|string|max:255',
            'options.*.description' => 'nullable|string',
            'options.*.benefits' => 'nullable|string',
            'options.*.drawbacks' => 'nullable|string',
            'single_option_reason' => 'nullable|string',
            'recommendation' => 'nullable|string',
            'cost_impact' => 'nullable|array',
            'risk_impact' => 'nullable|array',
            'service_user_implications' => 'nullable|string',
            'risk_equity_implications' => 'nullable|string',
            'attachments' => 'nullable|array',
            'follow_up_actions' => 'nullable|array',
            'meeting_id' => 'nullable|exists:governance_meetings,id',
            'governance_meeting_id' => 'nullable|exists:governance_meetings,id',
            'board_committee_id' => 'nullable|exists:board_committees,id',
            'voting_deadline' => 'nullable|date|after:now',
            'voting_threshold' => 'nullable|string',
            'quorum_required' => 'nullable|boolean',
            'publish_now' => 'nullable|boolean',
        ];
    }
}
