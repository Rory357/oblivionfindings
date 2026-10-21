<?php

namespace App\Domain\Referrals\Services;

use App\Domain\Privacy\Retention\RetentionExecutionService;
use App\Domain\Privacy\Retention\RetentionOwnerAdapter;
use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralCareHandover;
use App\Domain\Referrals\Models\ReferralConversion;
use App\Domain\Referrals\Models\ReferralDocumentRequest;
use App\Domain\Referrals\Models\ReferralEvent;
use App\Domain\Referrals\Models\ReferralEvidence;
use App\Domain\Referrals\Models\ReferralFundingCase;
use App\Domain\Referrals\Models\ReferralFundingDecision;
use App\Domain\Referrals\Models\ReferralInternalArrangement;
use App\Domain\Referrals\Models\ReferralOutbox;
use App\Domain\Referrals\Models\ReferralProposal;
use App\Domain\Referrals\Models\ReferralProposalReview;
use App\Domain\Referrals\Models\ReferralServiceAgreement;
use App\Domain\Referrals\Models\ReferralServiceStart;
use App\Domain\Referrals\Models\ReferralStartDecision;
use App\Domain\Referrals\Models\ReferralWorkItem;
use App\Models\DataRetentionPolicy;
use App\Models\LegalHold;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Referral data under the governed retention framework (Privacy › Retention).
 *
 * Nothing here deletes a referral. An approved policy anonymises closed referrals that never became a
 * supported person: free text and identity are removed, while the reference, dates, stage, source,
 * site, owner, priority, closure reason and the history's structure stay so reports remain complete.
 * Uploaded files are the only thing removed outright, and only under an approved policy.
 *
 * Kept outside any policy: referrals linked to a person (they follow the person's record), settings
 * versions (configuration only), submission receipts (one-way codes) and intake drafts (private,
 * expiring seven days after their last save). The search index is derived and cleared with the referral.
 */
final class ReferralRetention
{
    /** Bump when the redaction or related clean-up changes: every referral approval must then be renewed. */
    public const CONTRACT_VERSION = 'referrals-retention-2026-10-21';

    public const REMOVED_TITLE = 'Removed under the retention policy';

    /**
     * Keys whose string values are stable references or categories, never free text about a person.
     * Every other string in a redacted payload becomes null; numbers, true/false and the shape stay.
     */
    private const STRUCTURAL_KEYS = [
        'previous_stage', 'stage', 'previous_stage_label', 'stage_label', 'previous_hold_reason', 'hold_reason',
        'closure_reason', 'previous_priority', 'priority', 'previous_priority_label', 'priority_label',
        'outcome', 'result', 'status', 'state', 'kind', 'channel', 'audience', 'pathway', 'payer_kind',
        'category', 'mime', 'currency', 'period', 'unit', 'template_key',
        // Staff messages (Lane M): the configured template, never its rendered text.
        'template', 'template_id', 'template_label',
        // Lane M: the recipient's role (family, professional…) and kind (enquiry contact or People involved).
        'recipient_role', 'recipient_kind',
    ];

    /** Who a staff message or document request went to, as a role. Any other recipient value is an address. */
    private const RECIPIENT_ROLES = ['person', 'referrer', 'people_contact'];

    public const REMOVED_MESSAGE = 'Message text removed under the retention policy';

    private const OPEN_FUNDING = ['withdrawn', 'closed'];

    /** The anonymised value of each referral payload: a marker for identity, nothing for the rest. */
    private static function redactReferralField(string $field): mixed
    {
        return match ($field) {
            'identity' => ['retention_redacted_at' => now('UTC')->toISOString()],
            'next_action' => '',
            default => [],
        };
    }

    /**
     * The referral retention periods shown as settings on Privacy › Retention (Stephan, 21 Oct 2026:
     * "can you make it a setting"). Each row is one governed policy, created or changed from that page;
     * a changed period needs a fresh preview and a second person's approval before it runs. Periods are
     * whole years of the policy's "Anonymise after"; the service agreement link has no period.
     *
     * @return array<string, array{owner: class-string, label: string, explanation: string, default_years: int, conditions: array|null}>
     */
    public static function periodSettings(): array
    {
        $junk = ['spam', 'test', 'duplicate'];

        return [
            'referral_closed_junk' => [
                'owner' => CareReferral::class, 'label' => 'Spam, test and duplicate closures', 'default_years' => 1,
                'conditions' => ['closure_reason' => $junk],
                'explanation' => 'Closed as spam, a test entry or a duplicate of another referral. Personal details are removed this long after closing.',
            ],
            'referral_closed_other' => [
                'owner' => CareReferral::class, 'label' => 'Other closed referrals', 'default_years' => 2,
                'conditions' => ['closure_reason' => ['not_in' => $junk]],
                'explanation' => 'Closed without becoming a supported person, for any other reason. Personal details are removed this long after closing; the outline stays for reports.',
            ],
            'referral_documents' => [
                'owner' => ReferralEvidence::class, 'label' => 'Documents on closed referrals', 'default_years' => 1,
                'conditions' => null,
                'explanation' => 'Uploaded files are deleted this long after upload, once the referral has been closed at least as long.',
            ],
            'referral_messages' => [
                'owner' => ReferralOutbox::class, 'label' => 'Message copies on closed referrals', 'default_years' => 1,
                'conditions' => null,
                'explanation' => 'Addresses and wording of emails, texts and acknowledgements are cleared this long after queueing, once the referral has been closed at least as long.',
            ],
        ];
    }

    /**
     * Each referral period setting with its policy's current state, for the settings rows.
     *
     * @return list<array<string, mixed>>
     */
    public function periodRows(RetentionExecutionService $service): array
    {
        $policies = DataRetentionPolicy::query()->whereIn('managed_key', array_keys(self::periodSettings()))->get()->keyBy('managed_key');
        $rows = [];
        foreach (self::periodSettings() as $key => $setting) {
            $policy = $policies->get($key);
            $fingerprint = $policy ? $service->fingerprint($policy) : null;
            $rows[] = [
                'key' => $key, 'label' => $setting['label'], 'explanation' => $setting['explanation'], 'unit' => 'years',
                'default_years' => $setting['default_years'], 'years' => $policy?->hard_delete_after_years ?? $setting['default_years'],
                'policy' => $policy ? [
                    'id' => $policy->id, 'name' => $policy->policy_name, 'active' => (bool) $policy->active, 'execution_state' => $policy->execution_state,
                    'preview_current' => is_string($policy->preview_fingerprint) && hash_equals($fingerprint, $policy->preview_fingerprint),
                    'approval_current' => is_string($policy->approved_fingerprint) && hash_equals($fingerprint, $policy->approved_fingerprint),
                ] : null,
            ];
        }

        return $rows;
    }

    /**
     * Create or change the governed policy behind each referral period. A new or changed period is a
     * draft: it never runs until it is previewed and a different person approves it. Unchanged periods
     * keep their approval.
     *
     * @param  array<string, mixed>  $input
     * @return list<string> the setting keys that changed
     */
    public function savePeriods(User $actor, array $input, RetentionExecutionService $service): array
    {
        $settings = self::periodSettings();
        $data = Validator::make($input, [
            'periods' => 'required|array',
            ...collect(array_keys($settings))->mapWithKeys(fn (string $key) => ['periods.'.$key => 'required|integer|min:1|max:50'])->all(),
        ], [], collect($settings)->mapWithKeys(fn (array $setting, string $key) => ['periods.'.$key => $setting['label']])->all())->validate();

        return DB::transaction(function () use ($actor, $data, $settings, $service): array {
            $changed = [];
            foreach ($settings as $key => $setting) {
                $years = (int) $data['periods'][$key];
                $policy = DataRetentionPolicy::query()->where('managed_key', $key)->lockForUpdate()->first();
                if ($policy && (int) $policy->hard_delete_after_years === $years) {
                    continue;
                }
                $previous = $policy?->hard_delete_after_years;
                $policy ??= new DataRetentionPolicy([
                    'model_type' => $setting['owner'], 'policy_name' => 'Referral retention · '.$setting['label'],
                    'description' => $setting['explanation'], 'retention_conditions' => $setting['conditions'],
                    'retention_period_years' => null, 'archive_after_years' => null,
                    'applies_to_soft_deleted' => true, 'legal_hold_exemption' => true, 'active_case_exemption' => false, 'active' => true,
                    'legal_basis' => 'Privacy Act 2020, information privacy principle 9: keep personal information no longer than it is needed.',
                    'business_justification' => 'Referral retention period set on Privacy › Retention.',
                    'created_by' => $actor->id,
                ]);
                $policy->forceFill(['managed_key' => $key, 'hard_delete_after_years' => $years]);
                $service->invalidateApproval($policy, $actor->id);
                $policy->save();
                AuditLogger::logOrFail('data_retention.referral_period_changed', $policy, [
                    'actor_id' => $actor->id, 'setting' => $key, 'previous_years' => $previous, 'years' => $years,
                ]);
                $changed[] = $key;
            }

            return $changed;
        });
    }

    /**
     * A person's record is being anonymised (Client retention): the referrals that led to their support
     * follow, the same way closed referrals are anonymised, so nothing identifying is left behind. The
     * conversion receipt, handover, start and agreement-link records keep their non-identifying facts
     * (ids, outcomes, dates, numbers); funding outcomes stay. A legal hold on a referral keeps it as it is.
     *
     * @return array<string, int>
     */
    public function anonymiseForPerson(Model $client): array
    {
        $clientId = (int) $client->getKey();
        $referrals = CareReferral::query()
            ->where(fn (Builder $query) => $query->where('client_id', $clientId)
                ->orWhereIn('id', ReferralConversion::query()->where('client_id', $clientId)->select('referral_id')))
            ->orderBy('id')->lockForUpdate()->get();
        $counts = ['referrals_anonymised' => 0, 'referrals_held' => 0];
        foreach ($referrals as $referral) {
            if (self::anonymised($referral)) {
                continue;
            }
            if (LegalHold::query()->active()->where('holdable_type', CareReferral::class)->where('holdable_id', $referral->id)->exists()) {
                $counts['referrals_held']++;

                continue;
            }
            $referral->forceFill(collect(['identity', 'intake', 'authority', 'assessment', 'next_action'])
                ->mapWithKeys(fn (string $field) => [$field => self::redactReferralField($field)])->all())->saveQuietly();
            $related = [...$this->anonymiseRelated($referral), ...$this->anonymisePersonRecords($referral)];
            foreach ($related as $key => $count) {
                $counts[$key] = ($counts[$key] ?? 0) + $count;
            }
            $counts['referrals_anonymised']++;
            AuditLogger::logOrFail('data_retention.referral_anonymised_with_person', $referral, [
                'client_id' => $clientId, 'related' => $related,
            ]);
        }

        return array_filter($counts);
    }

    /** Records that exist only once a referral is linked to a person: free text goes, facts stay. */
    private function anonymisePersonRecords(CareReferral $referral): array
    {
        $id = $referral->id;
        $conversions = ReferralConversion::query()->where('referral_id', $id)->select('id');
        $scrub = function (Builder $query, string $column): int {
            $count = 0;
            foreach ($query->lockForUpdate()->get() as $row) {
                $row->forceFill([$column => self::scrub($row->getAttribute($column) ?? [])])->saveQuietly();
                $count++;
            }

            return $count;
        };
        $counts = [
            'conversion_receipts' => $scrub(ReferralConversion::query()->where('referral_id', $id), 'receipt'),
            'care_handovers' => $scrub(ReferralCareHandover::query()->whereIn('conversion_id', $conversions), 'snapshot'),
            'service_starts' => $scrub(ReferralServiceStart::query()->where('referral_id', $id), 'handover'),
            'start_decisions' => $scrub(ReferralStartDecision::query()->where('referral_id', $id), 'decision'),
            'internal_arrangements' => $scrub(ReferralInternalArrangement::query()->where('referral_id', $id), 'terms'),
        ];
        if (Schema::hasTable('care_referral_service_agreements')) {
            $counts['agreement_links'] = $scrub(ReferralServiceAgreement::query()->where('referral_id', $id), 'receipt');
        }

        return array_filter($counts);
    }

    /** @return list<RetentionOwnerAdapter> */
    public function owners(): array
    {
        return [$this->referralOwner(), $this->evidenceOwner(), $this->messageOwner(), $this->agreementLinkOwner()];
    }

    /** True once a governed run has removed this referral's personal details. */
    public static function anonymised(CareReferral $referral): bool
    {
        return isset($referral->identity['retention_redacted_at']);
    }

    public static function anonymisedAt(CareReferral $referral): ?string
    {
        return $referral->identity['retention_redacted_at'] ?? null;
    }

    /** Remove free text while keeping the payload's shape, so older readers never meet a missing key. */
    public static function scrub(mixed $value, ?string $key = null): mixed
    {
        if (is_array($value)) {
            $result = [];
            foreach ($value as $childKey => $child) {
                $result[$childKey] = self::scrub($child, is_string($childKey) ? $childKey : $key);
            }

            return $result;
        }

        if (is_string($value)) {
            if ($key === 'recipient') {
                return in_array($value, self::RECIPIENT_ROLES, true) ? $value : null;
            }

            return $key !== null && in_array($key, self::STRUCTURAL_KEYS, true) ? $value : null;
        }

        return $value;
    }

    /**
     * A queued message copy (acknowledgements, notices and staff messages): the address, name, subject and
     * text go; the channel, template, recipient role, sender and edit flag stay with the delivery status.
     */
    public static function redactMessage(mixed $message): array
    {
        $redacted = self::scrub(is_array($message) ? $message : []);
        if (array_key_exists('body', $redacted)) {
            $redacted['body'] = self::REMOVED_MESSAGE;
        }

        return $redacted;
    }

    private function referralOwner(): RetentionOwnerAdapter
    {
        return new RetentionOwnerAdapter(
            key: CareReferral::class,
            label: 'Closed referrals (not a supported person)',
            modelClass: CareReferral::class,
            conditionOperators: [
                'closure_reason' => ['equals', 'in', 'not_in'],
                'site_id' => ['equals', 'in', 'null', 'not_null'],
                'source' => ['equals', 'in', 'not_in'],
                'entry_kind' => ['equals', 'in', 'not_in'],
                'requested_service' => ['equals', 'in', 'not_in'],
                'general_enquiry' => ['equals'],
            ],
            anonymizationFields: [
                'identity' => 'structured',
                'intake' => 'structured',
                'authority' => 'structured',
                'assessment' => 'structured',
                'next_action' => 'structured',
            ],
            cutoffColumn: 'closed_at',
            protections: [
                'closed' => [
                    'label' => 'The referral is open again',
                    'scope' => fn (Builder $query) => $query->where('care_referrals.stage', 'closed'),
                ],
                'not_linked_to_person' => [
                    'label' => 'Linked to a supported person, so it stays with their record',
                    'scope' => fn (Builder $query) => $this->unlinked($query->getQuery(), 'care_referrals.id', 'care_referrals'),
                ],
                'no_open_work' => [
                    'label' => 'Follow-ups, funding help, messages or document requests are still open',
                    'scope' => fn (Builder $query) => $this->withoutOpenWork($query->getQuery(), 'care_referrals.id'),
                ],
                'not_already_anonymised' => [
                    'label' => 'Already anonymised by an earlier run',
                    'scope' => fn (Builder $query) => $this->notProcessed($query->getQuery(), CareReferral::class, 'care_referrals.id'),
                ],
            ],
            redactor: fn (string $field): mixed => self::redactReferralField($field),
            afterAnonymize: fn (Model $referral): array => $this->anonymiseRelated($referral),
            guidance: [
                'summary' => 'Closed referrals and enquiries that never became a supported person. The record is kept but becomes anonymous.',
                'removes' => [
                    'Names, date of birth, contact details and everything the person or referrer told us',
                    'Assessment, authority and next-step notes',
                    'Notes in the history, follow-ups, funding help, decisions, proposals and document requests',
                    'Uploaded documents: the files themselves are deleted',
                    'Addresses and text in message copies, including emails and texts staff sent, and the name search entries',
                ],
                'keeps' => [
                    'The reference, dates, stage, source, site, owner, priority and closure reason, so reports stay complete',
                    'Funding pathways, statuses and decision outcomes, without their details',
                    'Who did what and when in the history, without the notes',
                ],
                'blocked_by' => [
                    'A legal hold on the referral',
                    'A referral that is open again, or is linked to a supported person',
                    'Open follow-ups, funding help, unsent messages or document requests still waiting',
                ],
                'period' => 'Only “Anonymise after” applies, counted from the date the referral was closed. Referrals are never deleted, so the retention and archive periods are not used.',
                'period_basis' => 'after the referral was closed',
                'field_labels' => [
                    'identity' => 'Name, date of birth and identity details',
                    'intake' => 'What the person or referrer told us',
                    'authority' => 'Authority and sharing permissions',
                    'assessment' => 'Assessment notes',
                    'next_action' => 'Next step',
                ],
                'elsewhere' => [
                    'Referrals that became a supported person stay with that person’s record and its retention. Their funding decisions, handover receipts and service agreement links are kept as evidence while the record exists.',
                    'Unfinished intake drafts are private to their author and are deleted 7 days after they were last saved.',
                    'Referral settings versions hold configuration, not personal details, and are kept as history.',
                    'Submission receipts hold only one-way codes that stop duplicate submissions.',
                ],
            ],
            contractVersion: self::CONTRACT_VERSION,
        );
    }

    private function evidenceOwner(): RetentionOwnerAdapter
    {
        return new RetentionOwnerAdapter(
            key: ReferralEvidence::class,
            label: 'Referral documents (closed referrals)',
            modelClass: ReferralEvidence::class,
            conditionOperators: [
                'state' => ['equals', 'in', 'not_in'],
                'audience' => ['equals', 'in'],
            ],
            anonymizationFields: ['metadata' => 'structured', 'sha256' => 'structured', 'state' => 'structured'],
            protections: [
                'referral_closed' => [
                    'label' => 'The referral is open, or closed more recently than the period',
                    'scope' => fn (Builder $query, CarbonInterface $cutoff) => $query->whereExists(fn (QueryBuilder $referral) => $referral->selectRaw('1')
                        ->from('care_referrals')->whereColumn('care_referrals.id', 'care_referral_evidence.referral_id')
                        ->where('care_referrals.stage', 'closed')->where('care_referrals.closed_at', '<', $cutoff)),
                ],
                'not_linked_to_person' => [
                    'label' => 'Linked to a supported person, so it stays with their record',
                    'scope' => function (Builder $query): void {
                        $this->unlinked($query->getQuery(), 'care_referral_evidence.referral_id');
                        $query->whereNotExists(fn (QueryBuilder $document) => $document->selectRaw('1')->from('client_documents')
                            ->whereColumn('client_documents.source_referral_evidence_id', 'care_referral_evidence.id'));
                    },
                ],
                'no_referral_hold' => [
                    'label' => 'A legal hold on the referral',
                    'scope' => fn (Builder $query) => $this->withoutReferralHold($query->getQuery(), 'care_referral_evidence.referral_id'),
                ],
                'not_already_removed' => [
                    'label' => 'Already removed by an earlier run',
                    'scope' => fn (Builder $query) => $query->where('care_referral_evidence.state', '!=', 'disposed'),
                ],
            ],
            redactor: fn (string $field, mixed $value): mixed => match ($field) {
                'metadata' => self::removedDocumentMetadata(is_array($value) ? $value : []),
                'sha256' => str_repeat('0', 64),
                default => 'disposed',
            },
            afterAnonymize: fn (Model $evidence): array => ['files_removed' => $this->removeFileAfterCommit($evidence)],
            guidance: [
                'summary' => 'Files uploaded to referrals that closed without becoming a supported person. Use this to remove documents sooner than the rest of the referral.',
                'removes' => ['The uploaded file', 'Its title, source and expiry details'],
                'keeps' => ['A placeholder that says the document was removed, with its category, size and upload date'],
                'blocked_by' => [
                    'A legal hold on the document or its referral',
                    'A referral that is still open, or closed more recently than the period',
                    'A referral linked to a supported person, or a document copied into their record',
                ],
                'period' => 'Only “Anonymise after” applies, counted from the upload date, and only once the referral has been closed for at least as long. Retention and archive periods are not used.',
                'period_basis' => 'after upload, once the referral has been closed at least as long',
                'field_labels' => [
                    'metadata' => 'Document title, source and expiry',
                    'sha256' => 'File check code',
                    'state' => 'Document status (marked removed)',
                ],
            ],
            contractVersion: self::CONTRACT_VERSION,
        );
    }

    private function messageOwner(): RetentionOwnerAdapter
    {
        return new RetentionOwnerAdapter(
            key: ReferralOutbox::class,
            label: 'Referral message copies (closed referrals)',
            modelClass: ReferralOutbox::class,
            conditionOperators: [
                'kind' => ['equals', 'in', 'not_in'],
                'state' => ['equals', 'in', 'not_in'],
            ],
            anonymizationFields: ['message' => 'structured'],
            protections: [
                'finished' => [
                    'label' => 'The message is still waiting to send or sending',
                    'scope' => fn (Builder $query) => $query->whereNotIn('care_referral_outbox.state', ['pending', 'sending']),
                ],
                'referral_closed' => [
                    'label' => 'The referral is open, or closed more recently than the period',
                    'scope' => fn (Builder $query, CarbonInterface $cutoff) => $query->whereExists(fn (QueryBuilder $referral) => $referral->selectRaw('1')
                        ->from('care_referrals')->whereColumn('care_referrals.id', 'care_referral_outbox.referral_id')
                        ->where('care_referrals.stage', 'closed')->where('care_referrals.closed_at', '<', $cutoff)),
                ],
                'not_linked_to_person' => [
                    'label' => 'Linked to a supported person, so it stays with their record',
                    'scope' => fn (Builder $query) => $this->unlinked($query->getQuery(), 'care_referral_outbox.referral_id'),
                ],
                'no_referral_hold' => [
                    'label' => 'A legal hold on the referral',
                    'scope' => fn (Builder $query) => $this->withoutReferralHold($query->getQuery(), 'care_referral_outbox.referral_id'),
                ],
                'not_already_cleared' => [
                    'label' => 'Already cleared by an earlier run',
                    'scope' => fn (Builder $query) => $this->notProcessed($query->getQuery(), ReferralOutbox::class, 'care_referral_outbox.id'),
                ],
            ],
            redactor: fn (string $field, mixed $value): mixed => self::redactMessage($value),
            guidance: [
                'summary' => 'Copies of messages queued for referrals that closed without becoming a supported person: emails and texts staff sent from the referral, acknowledgements to the person who referred, and internal notices.',
                'removes' => [
                    'The email address or phone number and the recipient’s name',
                    'The subject and message text, which show as “'.self::REMOVED_MESSAGE.'”',
                ],
                'keeps' => [
                    'Email or text, the template used, whether it went to the person or the referrer, and who sent it',
                    'Whether delivery worked, and when it was queued, sent and delivered',
                    'The history entry for each message, which never held the address or text',
                ],
                'blocked_by' => [
                    'A legal hold on the referral',
                    'A message still waiting to send',
                    'A referral that is still open, closed more recently than the period, or linked to a supported person',
                ],
                'period' => 'Only “Anonymise after” applies, counted from when the message was queued, and only once the referral has been closed for at least as long. Retention and archive periods are not used.',
                'period_basis' => 'after the message was queued, once the referral has been closed at least as long',
                'field_labels' => ['message' => 'Message wording and recipient'],
            ],
            contractVersion: self::CONTRACT_VERSION,
        );
    }

    /**
     * Lane G's link from an approved proposal version to the service agreement it created. It is contract
     * evidence: kept while the person's record or the agreement exists. Both are protected from deletion
     * (soft-deleted and anonymised rows still exist), so today a policy for it never finds anything to do.
     */
    private function agreementLinkOwner(): RetentionOwnerAdapter
    {
        return new RetentionOwnerAdapter(
            key: ReferralServiceAgreement::class,
            label: 'Referral service agreement links (contract evidence)',
            modelClass: ReferralServiceAgreement::class,
            conditionOperators: [],
            anonymizationFields: ['receipt' => 'structured'],
            protections: [
                'person_record_gone' => [
                    'label' => 'The person’s record still exists',
                    'scope' => fn (Builder $query) => $query->whereNotExists(fn (QueryBuilder $client) => $client->selectRaw('1')->from('clients')
                        ->whereColumn('clients.id', 'care_referral_service_agreements.client_id')),
                ],
                'agreement_gone' => [
                    'label' => 'The service agreement still exists',
                    'scope' => fn (Builder $query) => $query->whereNotExists(fn (QueryBuilder $agreement) => $agreement->selectRaw('1')->from('service_agreements')
                        ->whereColumn('service_agreements.id', 'care_referral_service_agreements.service_agreement_id')),
                ],
                'no_referral_hold' => [
                    'label' => 'A legal hold on the referral',
                    'scope' => fn (Builder $query) => $this->withoutReferralHold($query->getQuery(), 'care_referral_service_agreements.referral_id'),
                ],
            ],
            redactor: fn (string $field, mixed $value): mixed => self::scrub(is_array($value) ? $value : []),
            guidance: [
                'summary' => 'The record linking an approved support proposal to the service agreement it created. It is contract evidence for the person’s support.',
                'removes' => ['Nothing while the person’s record or the agreement exists. After both are gone, only the wording in the link’s receipt would be cleared.'],
                'keeps' => ['Which proposal version created which agreement, for whom, by whom and when'],
                'blocked_by' => [
                    'The person’s record still existing, even archived or anonymised',
                    'The service agreement still existing',
                    'A legal hold on the referral',
                ],
                'period' => 'Both the person’s record and the agreement are protected from deletion, so a policy for this record type previews nothing to change. It stays with the person’s record and the agreement.',
                'field_labels' => ['receipt' => 'Receipt wording (field mapping notes)'],
            ],
            contractVersion: self::CONTRACT_VERSION,
        );
    }

    /**
     * Everything that belongs to the referral follows its anonymisation in the same transaction:
     * history notes, follow-ups, funding help and decisions, proposals and their reviews, document
     * requests, message copies, uploaded files and the name search entries. Rows are kept.
     *
     * @return array<string, int>
     */
    private function anonymiseRelated(Model $referral): array
    {
        $id = (int) $referral->getKey();
        $scrub = function (Builder $query, string $column, array $extra = [], ?callable $redact = null): int {
            $count = 0;
            foreach ($query->lockForUpdate()->get() as $row) {
                $value = $row->getAttribute($column) ?? [];
                // Quietly: append-only history and decision records accept only this governed change.
                $row->forceFill([$column => $redact ? $redact($value) : self::scrub($value), ...$extra])->saveQuietly();
                $count++;
            }

            return $count;
        };
        $proposals = ReferralProposal::query()->where('referral_id', $id)->select('id');
        $cases = ReferralFundingCase::query()->where('referral_id', $id)->select('id');
        $counts = [
            'history_entries' => $scrub(ReferralEvent::query()->where('referral_id', $id), 'payload'),
            'follow_ups' => $scrub(ReferralWorkItem::query()->where('referral_id', $id), 'details'),
            'funding_cases' => $scrub(ReferralFundingCase::query()->where('referral_id', $id), 'details'),
            'funding_decisions' => $scrub(ReferralFundingDecision::query()->whereIn('funding_case_id', $cases), 'decision'),
            'proposals' => $scrub(ReferralProposal::query()->where('referral_id', $id), 'proposal'),
            'proposal_reviews' => $scrub(ReferralProposalReview::query()->whereIn('proposal_id', $proposals), 'details'),
            'document_requests' => $scrub(ReferralDocumentRequest::query()->where('referral_id', $id), 'details', ['credentials' => null]),
            'message_copies' => $scrub(ReferralOutbox::query()->where('referral_id', $id), 'message', [], self::redactMessage(...)),
            'documents_removed' => 0,
        ];
        foreach (ReferralEvidence::query()->where('referral_id', $id)->where('state', '!=', 'disposed')->lockForUpdate()->get() as $evidence) {
            $evidence->forceFill([
                'metadata' => self::removedDocumentMetadata($evidence->metadata ?? []),
                'sha256' => str_repeat('0', 64),
                'state' => 'disposed',
            ])->saveQuietly();
            $counts['documents_removed'] += $this->removeFileAfterCommit($evidence);
        }
        $counts['search_entries'] = DB::table('care_referral_search_tokens')->where('referral_id', $id)->delete();

        return array_filter($counts);
    }

    /** @param  array<string, mixed>  $metadata */
    private static function removedDocumentMetadata(array $metadata): array
    {
        return [...self::scrub($metadata), 'title' => self::REMOVED_TITLE, 'retention_removed_at' => now('UTC')->toISOString()];
    }

    /**
     * The file goes only once the database change commits, so a rolled-back run never loses a file
     * its records still describe. A failure is logged by record id only, never by name or path.
     */
    private function removeFileAfterCommit(Model $evidence): int
    {
        $disk = $evidence->getAttribute('disk');
        $path = $evidence->getAttribute('storage_path');
        if ($disk !== 'private' || ! is_string($path) || ! preg_match('/^referral-evidence\/[0-9a-f-]{36}\.bin$/D', $path)) {
            return 0;
        }
        $id = $evidence->getKey();
        DB::afterCommit(function () use ($path, $id): void {
            try {
                Storage::disk('private')->delete($path);
            } catch (Throwable) {
                Log::warning('referrals.retention.file_not_removed', ['evidence_id' => $id]);
            }
        });

        return 1;
    }

    /** Not linked to a supported person in any way: no client, conversion, start, agreement or arrangement. */
    private function unlinked(QueryBuilder $query, string $referralColumn, ?string $referralTable = null): void
    {
        if ($referralTable === null) {
            $query->whereExists(fn (QueryBuilder $referral) => $referral->selectRaw('1')->from('care_referrals')
                ->whereColumn('care_referrals.id', $referralColumn)->whereNull('care_referrals.client_id')->whereNull('care_referrals.converted_at'));
        } else {
            $query->whereNull($referralTable.'.client_id')->whereNull($referralTable.'.converted_at');
        }
        $tables = ['care_referral_conversions', 'care_referral_service_starts', 'care_referral_start_decisions', 'care_referral_internal_arrangements', 'care_referral_service_agreements'];
        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                $query->whereNotExists(fn (QueryBuilder $linked) => $linked->selectRaw('1')->from($table)->whereColumn($table.'.referral_id', $referralColumn));
            }
        }
    }

    private function withoutOpenWork(QueryBuilder $query, string $referralColumn): void
    {
        $query->whereNotExists(fn (QueryBuilder $work) => $work->selectRaw('1')->from('care_referral_work_items')
            ->whereColumn('care_referral_work_items.referral_id', $referralColumn)->where('care_referral_work_items.status', 'open'))
            ->whereNotExists(fn (QueryBuilder $funding) => $funding->selectRaw('1')->from('care_referral_funding_cases')
                ->whereColumn('care_referral_funding_cases.referral_id', $referralColumn)->whereNotIn('care_referral_funding_cases.status', self::OPEN_FUNDING))
            ->whereNotExists(fn (QueryBuilder $message) => $message->selectRaw('1')->from('care_referral_outbox')
                ->whereColumn('care_referral_outbox.referral_id', $referralColumn)->whereIn('care_referral_outbox.state', ['pending', 'sending']))
            ->whereNotExists(fn (QueryBuilder $request) => $request->selectRaw('1')->from('care_referral_document_requests')
                ->whereColumn('care_referral_document_requests.referral_id', $referralColumn)
                ->where('care_referral_document_requests.state', 'pending')->where('care_referral_document_requests.expires_at', '>', now()));
    }

    private function withoutReferralHold(QueryBuilder $query, string $referralColumn): void
    {
        $query->whereNotExists(fn (QueryBuilder $hold) => $hold->selectRaw('1')->from('legal_holds')
            ->where('legal_holds.holdable_type', CareReferral::class)->whereColumn('legal_holds.holdable_id', $referralColumn)
            ->where('legal_holds.status', 'active'));
    }

    private function notProcessed(QueryBuilder $query, string $ownerKey, string $recordColumn): void
    {
        $query->whereNotExists(fn (QueryBuilder $item) => $item->selectRaw('1')->from('data_retention_execution_items')
            ->where('data_retention_execution_items.owner_key', $ownerKey)->whereColumn('data_retention_execution_items.record_id', $recordColumn)
            ->where('data_retention_execution_items.action', 'anonymize'));
    }
}
