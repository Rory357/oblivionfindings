<?php

namespace App\Domain\Referrals\Services;

use App\Domain\Referrals\Models\CareReferral;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class ReferralIntakeCorrection
{
    public function __construct(private readonly ReferralCommand $command, private readonly ReferralAssessment $assessment) {}

    public function save(User $actor, int $id, int $version, array $input): void
    {
        $data = Validator::make($input, [
            'person' => 'required|array:first_name,last_name,preferred_name,date_of_birth',
            'person.first_name' => 'present|nullable|string|max:120', 'person.last_name' => 'present|nullable|string|max:120',
            'person.preferred_name' => 'present|nullable|string|max:120', 'person.date_of_birth' => ['present', 'nullable', 'date_format:Y-m-d', 'before_or_equal:'.now('Pacific/Auckland')->toDateString()],
            'contact' => 'required|array:name,email,phone,channel,safe_time,leave_message,communication',
            'contact.name' => 'required|string|max:160', 'contact.email' => 'nullable|email:rfc|max:254|required_if:contact.channel,email',
            'contact.phone' => 'nullable|string|min:5|max:50|required_if:contact.channel,phone',
            'contact.channel' => ['required', Rule::in(['phone', 'email'])], 'contact.safe_time' => 'present|nullable|string|max:200',
            'contact.leave_message' => 'required|boolean', 'contact.communication' => 'present|nullable|string|max:500',
            'requested_service' => 'required|string|max:64', 'goals' => 'present|nullable|string|max:2000', 'region' => 'present|nullable|string|max:120',
            'convert_to_referral' => 'required|boolean', 'reason' => 'required|string|min:5|max:2000',
        ])->validate();
        $this->command->run($actor, $id, $version, 'edit', function (CareReferral $referral, User $actor) use ($data): void {
            abort_if($referral->stage === 'closed', 422, 'Reopen the referral before correcting intake.');
            if ($referral->general_enquiry && ! $data['convert_to_referral']) {
                abort_if(array_filter($data['person']) !== [] || ! empty($data['goals']), 422, 'Keep process enquiries minimal until collection authority is reviewed.');
            }
            if ($data['convert_to_referral']) {
                abort_unless($referral->general_enquiry && ReferralAssessment::scope($referral, 'collect'), 422, 'Only a process enquiry with current collection authority can become a full referral.');
            }
            if (! $referral->general_enquiry || $data['convert_to_referral']) {
                abort_if(empty($data['person']['first_name']), 422, 'Record the person’s given name.');
            }
            if ($data['requested_service'] !== $referral->requested_service || $data['convert_to_referral']) {
                $configuration = app(ReferralSettings::class)->effective()?->configuration;
                abort_unless(collect($configuration['services'] ?? [])->contains(fn ($service) => $service['enabled'] && $service['id'] === $data['requested_service']), 422, 'Choose a service enabled in the published settings.');
            }
            $previous = ['person' => Arr::only($referral->identity, array_keys($data['person'])), 'contact' => $referral->intake['submitted_contact'] ?? [],
                'goals' => $referral->intake['goals'] ?? null, 'region' => $referral->intake['region'] ?? null,
                'requested_service' => $referral->requested_service, 'general_enquiry' => $referral->general_enquiry];
            $normalize = fn (array $identity) => array_map(fn ($key) => trim((string) ($identity[$key] ?? '')), ['first_name', 'last_name', 'preferred_name', 'date_of_birth']);
            $identityChanged = $normalize($referral->identity) !== $normalize($data['person']);
            $referral->fill(['identity' => [...$referral->identity, ...$data['person']], 'requested_service' => $data['requested_service'],
                'intake' => [...$referral->intake, 'submitted_contact' => $data['contact'], 'goals' => $data['goals'], 'region' => $data['region']],
                'general_enquiry' => $referral->general_enquiry && ! $data['convert_to_referral']]);
            // Correcting source intake never silently overwrites a linked canonical person's record.
            if ($identityChanged || $previous['requested_service'] !== $data['requested_service']) {
                if (! $data['convert_to_referral'] && isset($referral->authority['review'])) {
                    // Existing permission must not silently attach to changed identity/service information.
                    $referral->authority = [...$referral->authority, 'review_required' => true];
                }
                $referral->assessment = [...$referral->assessment, 'invalidated_at' => now()->toISOString(), 'invalidation_reason' => 'Identity or requested service changed'];
                $this->assessment->invalidateReadiness($referral, $actor, 'intake');
            }
            $this->command->record($referral, $actor, 'intake.corrected', 'intake', ['previous' => $previous, 'current' => $data, 'reason' => $data['reason']]);
        });
    }
}
