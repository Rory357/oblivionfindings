<?php

namespace App\Domain\Referrals\Services;

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralEvent;
use App\Domain\Referrals\Models\ReferralOutbox;
use App\Domain\Referrals\Models\ReferralSubmission;
use App\Services\CurrentAuthorizationReads;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class PublicReferralIntake
{
    public function __construct(private readonly ReferralSettings $settings) {}

    /** The returned reference is a receipt, never a credential or a public read endpoint. */
    public function submit(array $input): array
    {
        $data = $this->validate($input);
        $keyHash = $this->hash($data['submission_token']);
        unset($data['submission_token']);
        $payloadHash = $this->hash(json_encode($this->canonical($data), JSON_THROW_ON_ERROR));

        try {
            return DB::transaction(function () use ($data, $keyHash, $payloadHash): array {
                // Serialize automatic workload allocation with publication and other
                // website submissions. Manual assignments retain their own review.
                DB::table('care_referral_configuration')->insertOrIgnore(['id' => 1, 'lock_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
                DB::table('care_referral_configuration')->where('id', 1)->lockForUpdate()->first();
                if ($previous = ReferralSubmission::query()->where('idempotency_hash', $keyHash)->first()) {
                    return $this->replay($previous, $payloadHash);
                }
                $version = CurrentAuthorizationReads::within(fn ($reads) => $this->settings->effective($reads));
                abort_unless($version && $version->configuration['public']['enabled'], 503, 'Online referrals are currently unavailable. Please use the contact details on our website.');
                $config = $version->configuration;
                if ((int) $version->id !== (int) $data['settings_version_id'] || $data['notice_version'] !== $config['public']['notice_version']) {
                    throw ValidationException::withMessages(['notice_version' => 'Our referral information has changed. Refresh the form and review the current privacy notice before submitting.']);
                }
                $services = array_column(array_filter($config['services'], fn ($service) => $service['enabled']), 'id');
                Validator::make($data, ['requested_service' => [Rule::in([...$services, 'unsure'])]])->validate();
                $general = $data['authority_status'] === 'unsure' || $data['purpose'] === 'business';
                $reportedPathway = ReferralIntakeFunding::snapshot($config, $data);
                $answers = app(ReferralForms::class)->intakeAnswers($config, $data, $version->id);
                $routing = CurrentAuthorizationReads::within(fn ($reads) => app(ReferralRouting::class)->choose($config, $data['requested_service'], $data['region'], $reads, $general ? null : $data['funding_situation'], $reportedPathway['id'] ?? null));
                $referral = CareReferral::query()->create([
                    'settings_version_id' => $version->id,
                    'source' => 'website', 'entry_kind' => $data['purpose'], 'requested_service' => $data['requested_service'],
                    // Only published server rules establish ownership. A submitted
                    // region or service never directly supplies a site or permission.
                    'site_id' => $routing['site_id'], 'owner_id' => $routing['owner_id'], 'team' => $routing['team'],
                    'stage' => 'new', 'general_enquiry' => $general,
                    'identity' => $general ? [] : $data['person'],
                    'intake' => [
                        'submitted_contact' => $data['contact'], 'relationship' => $data['relationship'],
                        'organisation' => $data['organisation'], 'region' => $data['region'],
                        'goals' => $data['goals'], 'timeframe' => $data['timeframe'],
                        'funding_situation' => $data['funding_situation'], 'funding_agency' => $data['funding_agency'],
                        'reported_pathway' => $reportedPathway,
                        'provenance' => 'Unreviewed website submission',
                        'form_answers' => $answers,
                    ],
                    'authority' => [
                        'declaration' => $data['authority_status'], 'contact_permission' => true,
                        'notice_version' => $data['notice_version'], 'notice_method' => 'website',
                        'notice_presented_at' => now()->toISOString(), 'staff_review' => 'pending',
                        'third_party_notice' => $data['relationship'] === 'self' ? 'not_applicable' : 'pending_review',
                    ],
                    'assessment' => [], 'next_action' => 'Review enquiry and confirm safe contact',
                    'next_due_at' => $this->dueAt($config), 'stage_changed_at' => now(),
                ]);
                ReferralSubmission::query()->create([
                    'idempotency_hash' => $keyHash, 'payload_hash' => $payloadHash,
                    'receipt_hash' => $this->hash($referral->reference), 'referral_id' => $referral->id,
                    'expires_at' => now()->addDays(30),
                ]);
                ReferralEvent::query()->create([
                    'referral_id' => $referral->id, 'kind' => 'referral.received', 'audience' => 'intake',
                    'payload' => ['source' => 'website', 'settings_version_id' => $version->id, 'routing' => array_intersect_key($routing, array_flip(['team', 'site_id', 'owner_id']))], 'occurred_at' => now(),
                ]);
                // A rule label or skipped-rule explanation can reveal a funding condition.
                ReferralEvent::query()->create([
                    'referral_id' => $referral->id, 'kind' => 'referral.routing_decided', 'audience' => 'funding',
                    'payload' => ['routing' => $routing, 'settings_version_id' => $version->id], 'occurred_at' => now(),
                ]);
                ReferralOutbox::query()->create([
                    'referral_id' => $referral->id, 'deduplication_key' => 'intake:'.$referral->reference,
                    'kind' => 'intake_received', 'message' => ['reference' => $referral->reference], 'available_at' => now(),
                ]);
                if ($config['public']['acknowledgement_enabled'] && $data['contact']['channel'] === 'email') {
                    ReferralOutbox::query()->create([
                        'referral_id' => $referral->id, 'deduplication_key' => 'acknowledgement:'.$referral->reference,
                        'kind' => 'public_acknowledgement',
                        'message' => ['recipient' => $data['contact']['email'], 'reference' => $referral->reference],
                        'available_at' => now(),
                    ]);
                }

                return $this->receipt($referral);
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            // A concurrent retry may win the unique submission key. Its entire transaction is already durable.
            $previous = ReferralSubmission::query()->where('idempotency_hash', $keyHash)->first();
            if (! $previous) {
                throw $exception;
            }

            return $this->replay($previous, $payloadHash);
        }
    }

    public function validate(array $input, bool $staffRecorded = false): array
    {
        $data = Validator::make(['form' => $input], [
            'form' => 'required|array:submission_token,settings_version_id,notice_version,purpose,relationship,authority_status,contact,person,organisation,region,requested_service,goals,timeframe,funding_situation,funding_agency,funding_pathway_id,contact_permission,privacy_acknowledged,website,answers',
            'form.funding_pathway_id' => 'sometimes|nullable|string|max:64',
            'form.answers' => 'sometimes|array|max:40',
            'form.submission_token' => 'required|uuid',
            'form.settings_version_id' => 'required|integer|min:1',
            'form.notice_version' => 'required|string|max:80',
            'form.purpose' => ['required', Rule::in(['support', 'referral', 'funding', 'callback', 'business'])],
            'form.relationship' => ['required', Rule::in(['self', 'family', 'supporter', 'professional', 'general'])],
            'form.authority_status' => ['required', Rule::in(['self', 'confirmed', 'unsure'])],
            'form.contact' => 'required|array:name,email,phone,channel,safe_time,leave_message,communication',
            'form.contact.name' => 'required|string|max:160',
            'form.contact.email' => 'nullable|email:rfc|max:254|required_if:form.contact.channel,email',
            'form.contact.phone' => 'nullable|string|min:5|max:50|required_if:form.contact.channel,phone',
            'form.contact.channel' => ['required', Rule::in(['email', 'phone'])],
            'form.contact.safe_time' => 'nullable|string|max:200',
            'form.contact.leave_message' => 'required|boolean',
            'form.contact.communication' => 'nullable|string|max:1000',
            'form.person' => 'present|array:first_name,last_name,preferred_name',
            'form.person.first_name' => 'nullable|string|max:120',
            'form.person.last_name' => 'nullable|string|max:120',
            'form.person.preferred_name' => 'nullable|string|max:120',
            'form.organisation' => 'nullable|string|max:200',
            'form.region' => 'nullable|string|max:120',
            'form.requested_service' => 'required|string|max:80',
            'form.goals' => 'nullable|string|max:3000',
            'form.timeframe' => 'nullable|string|max:200',
            'form.funding_situation' => ['required', Rule::in(['not_explored', 'need_help', 'underway', 'arranged', 'private', 'unsure'])],
            'form.funding_agency' => 'nullable|string|max:200',
            'form.contact_permission' => $staffRecorded ? 'required|boolean' : 'required|accepted',
            'form.privacy_acknowledged' => $staffRecorded ? 'required|boolean' : 'required|accepted',
            'form.website' => 'nullable|string|max:0',
        ])->validate()['form'];
        if ($data['authority_status'] === 'self' && $data['relationship'] !== 'self') {
            throw ValidationException::withMessages(['authority_status' => 'Choose the authority that applies to the person you are contacting us for.']);
        }
        if (($data['authority_status'] === 'unsure' || $data['purpose'] === 'business') && array_filter($data['person']) !== []) {
            throw ValidationException::withMessages(['person' => 'Please leave the other person’s details blank. We can first discuss the process with you.']);
        }
        foreach (['organisation', 'region', 'goals', 'timeframe', 'funding_agency'] as $key) {
            $data[$key] = $data[$key] ?? null;
        }
        // Preserve replay hashes for legacy submissions with no pathway answer.
        if (in_array($data['funding_pathway_id'] ?? null, [null, ''], true)) {
            unset($data['funding_pathway_id']);
        }

        return $data;
    }

    private function replay(ReferralSubmission $submission, string $payloadHash): array
    {
        abort_unless(hash_equals($submission->payload_hash, $payloadHash), 409, 'This submission reference has already been used. Start a new enquiry for different information.');

        return $this->receipt(CareReferral::query()->findOrFail($submission->referral_id));
    }

    private function receipt(CareReferral $referral): array
    {
        return ['reference' => $referral->reference, 'status' => 'received'];
    }

    private function dueAt(array $config): CarbonImmutable
    {
        $calendar = $config['business_calendar'];
        $weekdays = array_map('intval', $calendar['weekdays']);
        $date = CarbonImmutable::now($calendar['timezone']);
        $remaining = $config['intake']['first_contact_business_days'];
        while ($remaining > 0) {
            $date = $date->addDay();
            if (in_array($date->isoWeekday(), $weekdays, true) && ! in_array($date->toDateString(), $calendar['holidays'], true)) {
                $remaining--;
            }
        }

        return $date->setTimeFromTimeString($calendar['closing_time'])->utc();
    }

    private function hash(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }

    private function canonical(array $value): array
    {
        ksort($value);
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $this->canonical($item);
            }
        }

        return $value;
    }
}
