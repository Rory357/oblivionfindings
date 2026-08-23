<?php

namespace App\Services\Safeguarding;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\SafeguardingConcern;
use App\Models\SafeguardingDeclassificationReview;
use App\Models\User;
use App\Policies\SafeguardingConcernPolicy;
use App\Services\AuditLogger;
use App\Services\UserSiteAccessService;
use Illuminate\Support\Facades\DB;

class SafeguardingSensitivityService
{
    private const SITE_BYPASS_PERMISSIONS = ['reports.viewAny'];

    public function __construct(
        private readonly SafeguardingConcernPolicy $policy,
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    /** @return array{version: int, site_id: ?int, scope_label: string, newly_visible_staff_count: int, audience_rule: string, hash: string} */
    public function audiencePreview(SafeguardingConcern $concern): array
    {
        $snapshot = $this->audienceSnapshot($concern);
        $hash = $this->audienceHash($snapshot);
        unset($snapshot['audience_fingerprint']);

        return [...$snapshot, 'hash' => $hash];
    }

    public function markSensitive(SafeguardingConcern $concern, User $actor): SafeguardingConcern
    {
        return DB::transaction(function () use ($concern, $actor): SafeguardingConcern {
            $locked = $this->lockedConcern($concern, $actor, 'update');
            if ($locked->is_sensitive) {
                return $locked;
            }

            $locked->forceFill([
                'is_sensitive' => true,
                'sensitivity_version' => ((int) $locked->sensitivity_version) + 1,
                'updated_by' => $actor->id,
            ])->save();

            AuditLogger::logOrFail('safeguarding.sensitivity.restricted', $locked, [
                'actor_id' => $actor->id,
                'site_id' => $locked->site_id,
                'sensitivity_version' => $locked->sensitivity_version,
            ]);

            return $locked->fresh();
        }, 3);
    }

    public function requestDeclassification(
        SafeguardingConcern $concern,
        User $actor,
        string $reason,
        string $audiencePreviewHash,
        int $expectedSensitivityVersion,
        string $replayKey,
    ): SafeguardingDeclassificationReview {
        return DB::transaction(function () use (
            $concern,
            $actor,
            $reason,
            $audiencePreviewHash,
            $expectedSensitivityVersion,
            $replayKey,
        ): SafeguardingDeclassificationReview {
            $locked = $this->lockedConcern($concern, $actor, 'requestDeclassification');
            abort_unless($locked->is_sensitive, 409, 'This concern is no longer restricted to need-to-know.');
            abort_unless(
                (int) $locked->sensitivity_version === $expectedSensitivityVersion,
                409,
                'The sensitivity state changed; reload before requesting declassification.',
            );
            $this->assertCanonicalPrivacyContext($locked);

            $reason = trim($reason);
            $snapshot = $this->audienceSnapshot($locked);
            $audienceHash = $this->audienceHash($snapshot);
            abort_unless(
                hash_equals($audienceHash, $audiencePreviewHash),
                409,
                'The expanded audience changed; review the current preview before continuing.',
            );

            $existingReplay = SafeguardingDeclassificationReview::query()
                ->where('request_replay_key', $replayKey)
                ->lockForUpdate()
                ->first();
            if ($existingReplay) {
                abort_unless(
                    $existingReplay->status === SafeguardingDeclassificationReview::STATUS_PENDING
                    && (int) $existingReplay->safeguarding_concern_id === (int) $locked->id
                    && (int) $existingReplay->requested_by_user_id === (int) $actor->id
                    && (int) $existingReplay->concern_sensitivity_version === $expectedSensitivityVersion
                    && $existingReplay->concern_updated_at?->format('Y-m-d H:i:s')
                        === $locked->updated_at?->format('Y-m-d H:i:s')
                    && hash_equals($existingReplay->audience_hash, $audienceHash)
                    && hash_equals($existingReplay->reason, $reason),
                    409,
                    'This declassification request key was already used.',
                );

                return $existingReplay;
            }

            abort_if(
                SafeguardingDeclassificationReview::query()
                    ->where('safeguarding_concern_id', $locked->id)
                    ->where('status', SafeguardingDeclassificationReview::STATUS_PENDING)
                    ->exists(),
                409,
                'A declassification request is already awaiting independent review.',
            );

            $review = new SafeguardingDeclassificationReview([
                'safeguarding_concern_id' => $locked->id,
                'site_id' => $locked->site_id,
                'active_concern_id' => $locked->id,
                'concern_sensitivity_version' => $expectedSensitivityVersion,
                'concern_updated_at' => $locked->updated_at,
                'status' => SafeguardingDeclassificationReview::STATUS_PENDING,
                'requested_by_user_id' => $actor->id,
                'requested_at' => now()->startOfSecond(),
                'reason' => $reason,
                'audience_snapshot' => $snapshot,
                'audience_hash' => $audienceHash,
                'request_replay_key' => $replayKey,
            ]);
            $review->content_hash = $review->calculateContentHash();
            $review->save();

            AuditLogger::logOrFail('safeguarding.declassification.requested', $locked, [
                'actor_id' => $actor->id,
                'review_id' => $review->id,
                'site_id' => $locked->site_id,
                'sensitivity_version' => $expectedSensitivityVersion,
                'reason_hash' => hash('sha256', $reason),
                'audience_hash' => $audienceHash,
                'newly_visible_staff_count' => $snapshot['newly_visible_staff_count'],
            ]);

            return $review->fresh(['requester:id,name']);
        }, 3);
    }

    public function approve(
        SafeguardingConcern $concern,
        SafeguardingDeclassificationReview $review,
        User $actor,
        string $decisionReason,
        string $replayKey,
    ): SafeguardingDeclassificationReview {
        return $this->decide(
            $concern,
            $review,
            $actor,
            SafeguardingDeclassificationReview::STATUS_APPROVED,
            $decisionReason,
            $replayKey,
        );
    }

    public function reject(
        SafeguardingConcern $concern,
        SafeguardingDeclassificationReview $review,
        User $actor,
        string $decisionReason,
        string $replayKey,
    ): SafeguardingDeclassificationReview {
        return $this->decide(
            $concern,
            $review,
            $actor,
            SafeguardingDeclassificationReview::STATUS_REJECTED,
            $decisionReason,
            $replayKey,
        );
    }

    private function decide(
        SafeguardingConcern $concern,
        SafeguardingDeclassificationReview $review,
        User $actor,
        string $decision,
        string $decisionReason,
        string $replayKey,
    ): SafeguardingDeclassificationReview {
        return DB::transaction(function () use (
            $concern,
            $review,
            $actor,
            $decision,
            $decisionReason,
            $replayKey,
        ): SafeguardingDeclassificationReview {
            $lockedConcern = $this->lockedConcern($concern, $actor, 'approveDeclassification');
            $lockedReview = SafeguardingDeclassificationReview::query()
                ->whereKey($review->id)
                ->where('safeguarding_concern_id', $lockedConcern->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedReview->status === $decision) {
                abort_unless(
                    (int) $lockedReview->reviewed_by_user_id === (int) $actor->id
                    && hash_equals((string) $lockedReview->decision_replay_key, $replayKey),
                    409,
                    'This declassification review already has a terminal decision.',
                );

                return $lockedReview->load(['requester:id,name', 'reviewer:id,name']);
            }

            abort_unless(
                $lockedReview->status === SafeguardingDeclassificationReview::STATUS_PENDING,
                409,
                'This declassification review already has a terminal decision.',
            );
            $existingDecisionReplay = SafeguardingDeclassificationReview::query()
                ->where('decision_replay_key', $replayKey)
                ->lockForUpdate()
                ->first();

            abort_if(
                $existingDecisionReplay !== null,
                409,
                'This declassification decision key was already used.',
            );
            abort_if(
                $decision === SafeguardingDeclassificationReview::STATUS_APPROVED
                && (int) $lockedReview->requested_by_user_id === (int) $actor->id,
                422,
                'The requester cannot approve their own declassification request.',
            );
            abort_unless(
                hash_equals($lockedReview->content_hash, $lockedReview->calculateContentHash()),
                409,
                'Declassification request provenance verification failed.',
            );

            $decisionReason = trim($decisionReason);
            if ($decision === SafeguardingDeclassificationReview::STATUS_APPROVED) {
                abort_unless($lockedConcern->is_sensitive, 409, 'This concern is no longer restricted to need-to-know.');
                $this->assertCanonicalPrivacyContext($lockedConcern);
                abort_unless(
                    (int) $lockedReview->site_id === (int) $lockedConcern->site_id
                    && (int) $lockedReview->concern_sensitivity_version === (int) $lockedConcern->sensitivity_version
                    && $lockedReview->concern_updated_at?->format('Y-m-d H:i:s')
                        === $lockedConcern->updated_at?->format('Y-m-d H:i:s'),
                    409,
                    'The concern content, Site or sensitivity state changed; this request cannot be approved.',
                );

                $currentSnapshot = $this->audienceSnapshot($lockedConcern);
                abort_unless(
                    hash_equals($lockedReview->audience_hash, $this->audienceHash($currentSnapshot)),
                    409,
                    'The expanded audience changed; this request needs a fresh review.',
                );
            }

            $lockedReview->update([
                'status' => $decision,
                'active_concern_id' => null,
                'reviewed_by_user_id' => $actor->id,
                'reviewed_at' => now(),
                'decision_reason' => $decisionReason,
                'decision_replay_key' => $replayKey,
            ]);

            if ($decision === SafeguardingDeclassificationReview::STATUS_APPROVED) {
                $lockedConcern->forceFill([
                    'is_sensitive' => false,
                    'sensitivity_version' => ((int) $lockedConcern->sensitivity_version) + 1,
                    'updated_by' => $actor->id,
                ])->save();
            }

            AuditLogger::logOrFail("safeguarding.declassification.{$decision}", $lockedConcern, [
                'actor_id' => $actor->id,
                'review_id' => $lockedReview->id,
                'requester_id' => $lockedReview->requested_by_user_id,
                'site_id' => $lockedConcern->site_id,
                'requested_sensitivity_version' => $lockedReview->concern_sensitivity_version,
                'resulting_sensitivity_version' => $lockedConcern->sensitivity_version,
                'decision_reason_hash' => hash('sha256', $decisionReason),
                'audience_hash' => $lockedReview->audience_hash,
            ]);

            return $lockedReview->fresh(['requester:id,name', 'reviewer:id,name']);
        }, 3);
    }

    private function lockedConcern(
        SafeguardingConcern $concern,
        User $actor,
        string $ability,
    ): SafeguardingConcern {
        $locked = $this->policy->applyVisibleScope(
            SafeguardingConcern::query()->whereKey($concern->id),
            $actor,
        )->lockForUpdate()->firstOrFail();
        abort_unless($actor->can($ability, $locked), 403, UserSiteAccessService::DEFAULT_MESSAGE);

        return $locked;
    }

    private function assertCanonicalPrivacyContext(SafeguardingConcern $concern): void
    {
        abort_unless(
            $concern->updated_at,
            409,
            'The concern provenance must be reconciled before declassification.',
        );

        $siteId = is_numeric($concern->site_id) && (int) $concern->site_id > 0
            ? (int) $concern->site_id
            : null;
        $linkedPeople = [
            [$concern->subject_type, $concern->subject_id],
            [$concern->alleged_perpetrator_type, $concern->alleged_perpetrator_id],
        ];

        if ($siteId === null) {
            abort_if(
                collect($linkedPeople)->contains(
                    fn (array $person): bool => filled($person[0]) || filled($person[1]),
                ) || filled($concern->related_incident_id),
                409,
                'The concern Site and linked-person provenance must be reconciled before declassification.',
            );

            return;
        }

        foreach ($linkedPeople as [$type, $id]) {
            if (! filled($id)) {
                abort_if(
                    filled($type),
                    409,
                    'The concern Site and linked-person provenance must be reconciled before declassification.',
                );
                continue;
            }

            $matchesSite = match ($type) {
                Client::class => Client::withTrashed()
                    ->whereKey($id)
                    ->where('site_id', $siteId)
                    ->exists(),
                User::class => HrEmployeeProfile::withTrashed()
                    ->where('user_id', $id)
                    ->where(function ($profiles) use ($siteId): void {
                        $profiles->where('primary_site_id', $siteId)
                            ->orWhereJsonContains('secondary_site_ids', $siteId);
                    })
                    ->exists(),
                default => false,
            };
            abort_unless(
                $matchesSite,
                409,
                'The concern Site and linked-person provenance must be reconciled before declassification.',
            );
        }

        if (filled($concern->related_incident_id)) {
            $incident = ClientIncident::query()->find($concern->related_incident_id);
            try {
                $incidentSiteId = $incident
                    ? $this->siteAccess->effectiveClientIncidentSiteId($incident)
                    : null;
            } catch (\LogicException) {
                $incidentSiteId = null;
            }
            abort_unless(
                $incidentSiteId === $siteId,
                409,
                'The concern Site and linked-record provenance must be reconciled before declassification.',
            );
        }
    }

    /** @return array{version: int, site_id: ?int, scope_label: string, newly_visible_staff_count: int, audience_fingerprint: string, audience_rule: string} */
    private function audienceSnapshot(SafeguardingConcern $concern): array
    {
        $siteId = is_numeric($concern->site_id) && (int) $concern->site_id > 0
            ? (int) $concern->site_id
            : null;

        $candidates = User::query()
            ->staff()
            ->whereNotNull('approved_at')
            ->where(function ($query): void {
                $query->whereHas('roles.permissions', fn ($permissions) => $permissions
                    ->where('permissions.key', 'safeguarding.viewAny'))
                    ->orWhereHas('permissionOverrides', fn ($permissions) => $permissions
                        ->where('permissions.key', 'safeguarding.viewAny'));
            })
            ->with(['roles.permissions', 'permissionOverrides', 'hrEmployeeProfile'])
            ->get();

        $newlyVisibleIds = $candidates->filter(function (User $candidate) use ($concern, $siteId): bool {
            if (! $candidate->canDo('safeguarding.viewAny')
                || $candidate->can('viewSensitive', SafeguardingConcern::class)
                || $candidate->can('approveDeclassification', $concern)
                || (int) $concern->assigned_to_user_id === (int) $candidate->id
                || (int) $concern->reported_by_user_id === (int) $candidate->id) {
                return false;
            }

            if ($siteId === null || $this->siteAccess->canBypass($candidate, self::SITE_BYPASS_PERMISSIONS)) {
                return true;
            }

            return in_array($siteId, $this->siteAccess->accessibleSiteIds($candidate), true);
        })->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all();

        $scopeLabel = $siteId === null
            ? 'organisation-wide safeguarding records'
            : (string) ($concern->site()->value('name') ?: "Site #{$siteId}");
        $audienceRule = $siteId === null
            ? 'Staff with organisation-wide safeguarding concern access will be able to view the full allegation.'
            : 'Staff with safeguarding concern access for this Site will be able to view the full allegation.';

        return [
            'version' => 1,
            'site_id' => $siteId,
            'scope_label' => $scopeLabel,
            'newly_visible_staff_count' => count($newlyVisibleIds),
            'audience_fingerprint' => hash(
                'sha256',
                json_encode($newlyVisibleIds, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ),
            'audience_rule' => $audienceRule,
        ];
    }

    /** @param array<string, mixed> $snapshot */
    private function audienceHash(array $snapshot): string
    {
        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
