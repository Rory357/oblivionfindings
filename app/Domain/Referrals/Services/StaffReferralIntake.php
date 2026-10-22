<?php

namespace App\Domain\Referrals\Services;

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralEvent;
use App\Domain\Referrals\Models\ReferralOutbox;
use App\Domain\Referrals\Models\ReferralSubmission;
use App\Domain\Referrals\ReferralPermissions;
use App\Models\User;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\CurrentAuthorizationReads;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class StaffReferralIntake
{
    public function __construct(private readonly ReferralAccess $access, private readonly ReferralSettings $settings, private readonly PublicReferralIntake $validation) {}

    public function create(User $actor, array $input): CareReferral
    {
        $data = Validator::make($input, [
            'form' => 'required|array', 'source' => ['required', Rule::in(['phone', 'email', 'in_person', 'agency'])],
            'site_id' => 'present|nullable|integer|min:1', 'successful_contact' => 'required|boolean',
            'notice_method' => ['required', Rule::in(['verbal', 'written', 'pending'])],
            'notice_reason' => 'nullable|string|max:2000|required_if:notice_method,pending',
            'next_action' => 'required|string|max:500', 'next_due_at' => 'required|date',
        ])->validate();
        $data['form'] = $this->validation->validate($data['form'], staffRecorded: true);
        $data['site_id'] = $data['site_id'] === null ? null : (int) $data['site_id'];
        $keyHash = $this->hash('staff:'.$actor->id.':'.$data['form']['submission_token']);
        $payloadHash = $this->hash(json_encode($this->canonical($data), JSON_THROW_ON_ERROR));
        try {
            return DB::transaction(function () use ($actor, $data, $keyHash, $payloadHash): CareReferral {
                $actor = app(AuthorizationEvidenceLockService::class)->lockForUserWithoutWaiting($actor, array_column(ReferralPermissions::definitions(), 'key'));
                abort_unless($actor->approved_at && $this->access->can($actor, 'create'), 403);
                abort_if($data['successful_contact'] && ! $this->access->can($actor, 'contact'), 403);

                return CurrentAuthorizationReads::within(function (CurrentAuthorizationReads $reads) use ($actor, $data, $keyHash, $payloadHash): CareReferral {
                    if ($previous = ReferralSubmission::query()->where('idempotency_hash', $keyHash)->first()) {
                        return $this->replay($actor, $previous, $payloadHash, $reads);
                    }
                    abort_unless($this->access->eligibleForSite($actor, $data['site_id'], $reads), 403);
                    $version = $this->settings->effective();
                    abort_unless($version && $version->id === (int) $data['form']['settings_version_id'], 422, 'Load the current published referral configuration before recording intake.');
                    $config = $version->configuration;
                    $form = $data['form'];
                    abort_unless($form['notice_version'] === $config['public']['notice_version'], 422, 'Review the current collection notice.');
                    $services = array_column(array_filter($config['services'], fn ($service) => $service['enabled']), 'id');
                    abort_unless(in_array($form['requested_service'], [...$services, 'unsure'], true), 422, 'Select a currently offered service or not sure yet.');
                    abort_unless(($data['notice_method'] === 'pending') === ! (bool) $form['privacy_acknowledged'], 422, 'The notice delivery record must match whether it was actually provided.');
                    abort_if($data['notice_method'] !== 'pending' && trim((string) $config['public']['notice']) === '', 422, 'Publish the collection notice before recording it as provided.');
                    $due = ReferralDates::parse($data['next_due_at'], 'next_due_at');
                    abort_if($due->isPast(), 422, 'Choose a future next-action date.');
                    $general = $form['authority_status'] === 'unsure' || $form['purpose'] === 'business';
                    $reportedPathway = ReferralIntakeFunding::snapshot($config, $form);
                    $answers = app(ReferralForms::class)->intakeAnswers($config, $form, $version->id, $actor);
                    $record = CareReferral::query()->create([
                        'settings_version_id' => $version->id, 'source' => $data['source'], 'entry_kind' => $form['purpose'],
                        'site_id' => $data['site_id'], 'owner_id' => $actor->id, 'team' => $config['intake']['team'],
                        'requested_service' => $form['requested_service'], 'general_enquiry' => $general, 'identity' => $general ? [] : $form['person'],
                        'intake' => [
                            'submitted_contact' => $form['contact'], 'relationship' => $form['relationship'],
                            'organisation' => $form['organisation'], 'region' => $form['region'], 'goals' => $form['goals'], 'timeframe' => $form['timeframe'],
                            'funding_situation' => $form['funding_situation'], 'funding_agency' => $form['funding_agency'],
                            'reported_pathway' => $reportedPathway,
                            'provenance' => 'Recorded by staff; assessment and authority review pending',
                            'form_answers' => $answers,
                        ],
                        'authority' => [
                            'declaration' => $form['authority_status'], 'contact_permission' => (bool) $form['contact_permission'],
                            'notice_version' => $form['notice_version'], 'notice_method' => $data['notice_method'],
                            'notice_presented_at' => $data['notice_method'] === 'pending' ? null : now()->toISOString(),
                            'notice_pending_reason' => $data['notice_reason'] ?? null, 'staff_review' => 'pending',
                            'third_party_notice' => $form['relationship'] === 'self' ? 'not_applicable' : 'pending_review',
                        ],
                        'assessment' => [], 'next_action' => $data['next_action'], 'next_due_at' => $due, 'stage_changed_at' => now(),
                        'first_contact_attempt_at' => $data['successful_contact'] ? now() : null,
                        'first_human_contact_at' => $data['successful_contact'] ? now() : null,
                    ]);
                    ReferralSubmission::query()->create([
                        'idempotency_hash' => $keyHash, 'payload_hash' => $payloadHash, 'receipt_hash' => $this->hash($record->reference),
                        'referral_id' => $record->id, 'expires_at' => now()->addDays(30),
                    ]);
                    ReferralEvent::query()->create([
                        'referral_id' => $record->id, 'actor_id' => $actor->id, 'kind' => 'referral.received', 'audience' => 'intake',
                        'payload' => ['source' => $data['source'], 'successful_human_contact' => $data['successful_contact'], 'settings_version_id' => $version->id],
                        'occurred_at' => now(),
                    ]);
                    ReferralOutbox::query()->create([
                        'referral_id' => $record->id, 'deduplication_key' => 'intake:'.$record->reference, 'kind' => 'intake_received',
                        'message' => ['reference' => $record->reference], 'available_at' => now(),
                    ]);

                    return $record;
                });
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            $previous = ReferralSubmission::query()->where('idempotency_hash', $keyHash)->first();
            if (! $previous) {
                throw $exception;
            }

            $currentActor = $actor->fresh();
            abort_unless($currentActor && $currentActor->approved_at && $this->access->can($currentActor, 'create'), 403);

            return $this->replay($currentActor, $previous, $payloadHash);
        }
    }

    private function replay(User $actor, ReferralSubmission $submission, string $hash, ?CurrentAuthorizationReads $reads = null): CareReferral
    {
        abort_unless(hash_equals($submission->payload_hash, $hash), 409, 'This intake reference has already been used with different information.');

        return $this->access->query($actor, $reads)->whereKey($submission->referral_id)->firstOrFail();
    }

    private function hash(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }

    private function canonical(array $data): array
    {
        ksort($data);
        foreach ($data as &$value) {
            if (is_array($value)) {
                $value = $this->canonical($value);
            }
        }

        return $data;
    }
}
