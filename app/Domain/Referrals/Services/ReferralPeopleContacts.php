<?php

namespace App\Domain\Referrals\Services;

use App\Domain\Referrals\Models\CareReferral;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Reviewed third-party contacts. Roles here never create portal membership or legal powers. */
final class ReferralPeopleContacts
{
    public const ROLES = ['family', 'supporter', 'advocate', 'representative', 'referrer', 'professional', 'other'];

    public function __construct(private readonly ReferralCommand $command, private readonly ReferralAccess $access) {}

    public function present(User $actor, CareReferral $referral): array
    {
        if (! $this->access->audience($actor, 'assessment')) {
            return [];
        }
        abort_unless($this->access->query($actor)->whereKey($referral->id)->exists(), 404);

        return array_values(array_map(fn ($contact) => [
            ...Arr::only($contact, ['id', 'version', 'details', 'reviewed_at', 'updated_at']),
            'review_current' => $this->current($referral, $contact),
        ], $referral->intake['people_contacts'] ?? []));
    }

    public function current(CareReferral $referral, array $contact): bool
    {
        $details = $contact['details'];

        return $referral->stage !== 'closed' && $details['state'] === 'current' && ! empty($details['confirmed'])
            && ! empty($details['review_due_at']) && CarbonImmutable::parse($details['review_due_at'])->isFuture()
            && ($contact['authority_version'] ?? null) === ($referral->authority['review']['version'] ?? null)
            && ReferralAssessment::scope($referral, 'collect')
            && (! $details['may_contact'] || ReferralAssessment::scope($referral, 'contact'));
    }

    public function save(User $actor, int $referralId, int $version, array $input): array
    {
        $data = Validator::make($input, [
            'submission_key' => 'required|uuid', 'contact_id' => 'required|uuid', 'contact_version' => 'required|integer|min:0',
            'name' => 'required|string|min:2|max:200', 'role' => ['required', Rule::in(self::ROLES)],
            'relationship' => 'required|string|min:2|max:160', 'organisation' => 'present|nullable|string|max:200',
            'phone' => 'present|nullable|string|max:80', 'email' => 'present|nullable|email:rfc|max:254',
            'channel' => ['required', Rule::in(['phone', 'email', 'none'])], 'safe_time' => 'present|nullable|string|max:1000',
            'leave_message' => 'required|boolean', 'communication' => 'present|nullable|string|max:1000',
            'state' => ['required', Rule::in(['pending', 'current', 'withdrawn', 'archived'])],
            'may_contact' => 'required|boolean', 'purpose' => 'required|string|min:10|max:2000',
            'authority_note' => 'present|nullable|string|max:2000', 'review_note' => 'required|string|min:10|max:2000',
            'review_due_at' => 'present|nullable|date', 'confirmed' => 'required|boolean',
        ])->validate();
        foreach (['name', 'relationship', 'organisation', 'phone', 'email', 'safe_time', 'communication', 'purpose', 'authority_note', 'review_note'] as $field) {
            if (is_string($data[$field])) {
                $data[$field] = trim($data[$field]);
            }
        }
        abort_unless(mb_strlen($data['name']) >= 2 && mb_strlen($data['relationship']) >= 2
            && mb_strlen($data['purpose']) >= 10 && mb_strlen($data['review_note']) >= 10, 422, 'Record meaningful contact details, purpose and review notes.');
        $intent = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));

        return $this->command->run($actor, $referralId, $version, 'assessment.manage', function (CareReferral $referral, User $actor) use ($data, $intent): array {
            abort_unless($this->access->audience($actor, 'assessment'), 403);
            abort_if($referral->stage === 'closed' && ! in_array($data['state'], ['withdrawn', 'archived'], true), 422, 'A closed referral only permits withdrawal or archival. Reopen it before adding or renewing contacts.');
            abort_if($referral->general_enquiry, 422, 'Complete the person’s intake and collection review before recording their contacts.');
            // Revocation and archival remain possible after authority expires or is withdrawn.
            if (! in_array($data['state'], ['withdrawn', 'archived'], true)) {
                abort_unless(ReferralAssessment::scope($referral, 'collect'), 422, 'Review current collection authority before recording another person’s details.');
            }
            $contacts = $referral->intake['people_contacts'] ?? [];
            $previous = $contacts[$data['contact_id']] ?? null;
            abort_unless(($previous['version'] ?? 0) === $data['contact_version'], 409, 'This contact changed. Reload and review the latest details.');
            abort_if(! $previous && count($contacts) >= 50, 422, 'This referral already has 50 contact records. Review its contact history before adding more.');
            abort_if(! $previous && in_array($data['state'], ['withdrawn', 'archived'], true), 422, 'Only an existing contact can be withdrawn or archived.');
            if (in_array($data['state'], ['withdrawn', 'archived'], true)) {
                foreach (['name', 'role', 'relationship', 'organisation', 'phone', 'email', 'channel', 'safe_time', 'communication', 'purpose', 'authority_note'] as $field) {
                    abort_unless(($data[$field] ?: null) === ($previous['details'][$field] ?: null), 422, 'Withdraw or archive the existing details unchanged. Record your reason in the review note.');
                }
            }
            $current = $data['state'] === 'current';
            abort_if(! $current && ($data['may_contact'] || $data['leave_message'] || $data['confirmed']), 422, 'Pending, withdrawn or archived contacts cannot have current contact permission.');
            abort_if($data['channel'] === 'none' && ($data['may_contact'] || $data['leave_message']), 422, 'No direct contact cannot permit calls, messages or email.');
            abort_if($data['leave_message'] && (! $data['may_contact'] || $data['channel'] !== 'phone'), 422, 'Message permission needs an agreed phone contact channel.');
            $due = $data['review_due_at'] ? ReferralDates::parse($data['review_due_at'], 'review_due_at') : null;
            if ($current) {
                abort_unless($data['confirmed'] && $due?->isFuture(), 422, 'Confirm the reviewed details and choose a future review date.');
                abort_if($due->gt(CarbonImmutable::parse($referral->authority['review']['review_due_at'])), 422, 'The contact review cannot run beyond the current authority review.');
                abort_if($data['role'] === 'representative' && mb_strlen($data['authority_note'] ?? '') < 10, 422, 'Describe the verified representative role and its limits. This record does not grant decision-making powers.');
                abort_if($data['may_contact'] && (! ReferralAssessment::scope($referral, 'contact') || ! filled($data[$data['channel']] ?? null)), 422, 'Current contact authority and details for the agreed channel are required.');
            }
            $details = Arr::except($data, ['submission_key', 'contact_id', 'contact_version']);
            $details['review_due_at'] = $due?->toISOString();
            $record = ['id' => $data['contact_id'], 'version' => ($previous['version'] ?? 0) + 1, 'details' => $details,
                'authority_version' => $referral->authority['review']['version'] ?? null,
                'reviewed_at' => $current ? now()->toISOString() : null, 'reviewed_by' => $current ? $actor->id : null,
                'updated_at' => now()->toISOString(), 'updated_by' => $actor->id,
                'submission_key' => $data['submission_key'], 'intent_hash' => $intent];
            $contacts[$record['id']] = $record;
            $referral->intake = [...$referral->intake, 'people_contacts' => $contacts];
            $this->command->record($referral, $actor, 'person_contact.reviewed', 'assessment', ['previous' => $previous ? Arr::except($previous, ['submission_key', 'intent_hash']) : null, 'current' => Arr::except($record, ['submission_key', 'intent_hash'])]);

            return ['saved' => true, 'contact_id' => $record['id'], 'contact_version' => $record['version']];
        }, [], function (CareReferral $referral, User $actor) use ($data, $intent): ?array {
            abort_unless($this->access->audience($actor, 'assessment'), 403);
            $record = $referral->intake['people_contacts'][$data['contact_id']] ?? null;
            if (($record['submission_key'] ?? null) !== $data['submission_key']) {
                return null;
            }
            abort_unless(hash_equals($record['intent_hash'], $intent), 409, 'This contact submission was already used with different details. Reload before another review.');

            return ['saved' => true, 'contact_id' => $record['id'], 'contact_version' => $record['version']];
        });
    }
}
