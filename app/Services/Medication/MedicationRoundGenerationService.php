<?php

namespace App\Services\Medication;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\PeopleMutationLockService;
use App\Models\MedicationRound;
use App\Models\MedicationRoundTemplate;
use App\Models\ServiceContext;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class MedicationRoundGenerationService
{
    public const STATUS_CREATED = 'created';

    public const STATUS_ALREADY_EXISTS = 'already_exists';

    public const STATUS_SKIPPED = 'skipped';

    public const REASON_CREATED = 'created';

    public const REASON_ALREADY_EXISTS = 'already_exists';

    public const REASON_EXISTING_ROUND_SCOPE_MISMATCH = 'existing_round_scope_mismatch';

    public const REASON_TEMPLATE_MISSING = 'template_missing';

    public const REASON_TEMPLATE_INACTIVE = 'template_inactive';

    public const REASON_TEMPLATE_RETIRED = 'template_retired';

    public const REASON_TEMPLATE_CHANGED = 'template_changed';

    public const REASON_NOT_SCHEDULED = 'not_scheduled';

    public const REASON_LEGACY_NULL_SITE = 'legacy_null_site';

    public const REASON_SITE_OUT_OF_SCOPE = 'site_out_of_scope';

    public const REASON_SITE_INVALID = 'site_invalid';

    public const REASON_SITE_INACTIVE = 'site_inactive';

    public const REASON_CONTEXT_INVALID = 'context_invalid';

    public const REASON_CONTEXT_INACTIVE = 'context_inactive';

    public const REASON_CONTEXT_SITE_MISMATCH = 'context_site_mismatch';

    public const REASON_ASSIGNEE_INVALID = 'assignee_invalid';

    public const REASON_ASSIGNEE_NOT_CURRENT = 'assignee_not_current';

    public const REASON_ASSIGNEE_SITE_MISMATCH = 'assignee_site_mismatch';

    public function __construct(
        private readonly PeopleMutationLockService $peopleLocks,
    ) {}

    /**
     * Materialize at most one round for a template and date.
     *
     * The submitted identifier is deliberately re-resolved inside the
     * transaction. Callers may discover candidates before a concurrent
     * template edit, Site retirement, context change, or staff departure.
     *
     * @param  array<int, int>|null  $allowedSiteIds  Null grants the CLI its
     *                                                application-wide scope; an array
     *                                                keeps a web request inside its
     *                                                already-authorized Site set.
     * @return array{status: string, reason: string, template_id: int, round_id: int|null}
     */
    public function generate(
        int $templateId,
        CarbonInterface|string $date,
        bool $generateAll = false,
        ?array $allowedSiteIds = null,
        ?User $actor = null,
    ): array {
        $roundDate = $date instanceof CarbonInterface
            ? $date->toDateString()
            : CarbonImmutable::parse($date)->toDateString();
        $dayOfWeek = CarbonImmutable::parse($roundDate)->dayOfWeekIso;
        $normalizedAllowedSiteIds = $allowedSiteIds === null
            ? null
            : collect($allowedSiteIds)
                ->filter(fn (mixed $siteId): bool => is_numeric($siteId) && (int) $siteId > 0)
                ->map(fn (mixed $siteId): int => (int) $siteId)
                ->unique()
                ->values()
                ->all();
        // Resolve the parent identity before opening the transaction. A plain
        // SELECT inside a MySQL REPEATABLE READ transaction would establish a
        // stale snapshot before the canonical Site lock and could hide a round
        // committed by the worker that held that lock first.
        $snapshot = MedicationRoundTemplate::query()
            ->whereKey($templateId)
            ->first(['id', 'site_id', 'service_context_id', 'default_assigned_to']);
        if ($snapshot === null) {
            return $this->skipped($templateId, self::REASON_TEMPLATE_MISSING);
        }
        if ($snapshot->site_id === null) {
            return $this->skipped($templateId, self::REASON_LEGACY_NULL_SITE);
        }
        $siteId = $this->positiveId($snapshot->site_id);
        if ($siteId === null) {
            return $this->skipped($templateId, self::REASON_SITE_INVALID);
        }

        return DB::transaction(function () use (
            $templateId,
            $roundDate,
            $dayOfWeek,
            $generateAll,
            $normalizedAllowedSiteIds,
            $snapshot,
            $siteId,
            $actor,
        ): array {
            if ($normalizedAllowedSiteIds !== null
                && ! in_array($siteId, $normalizedAllowedSiteIds, true)) {
                return $this->skipped($templateId, self::REASON_SITE_OUT_OF_SCOPE);
            }

            // Canonical shared prefix: ServiceContext -> complete
            // Users/RBAC/Profiles -> Site -> template. The unlocked identity
            // snapshot identifies the full graph; the final constrained
            // template lock rejects a concurrent retarget.
            $contextId = null;
            if ($snapshot->service_context_id !== null) {
                $contextId = $this->positiveId($snapshot->service_context_id);
                if ($contextId === null) {
                    return $this->skipped($templateId, self::REASON_CONTEXT_INVALID);
                }

                $context = ServiceContext::query()
                    ->whereKey($contextId)
                    ->lockForUpdate()
                    ->first();
                if ($context === null) {
                    return $this->skipped($templateId, self::REASON_CONTEXT_INVALID);
                }
                if (! $context->is_active) {
                    return $this->skipped($templateId, self::REASON_CONTEXT_INACTIVE);
                }
                $contextSiteId = $this->positiveId($context->site_id);
                if ($contextSiteId !== null && $contextSiteId !== $siteId) {
                    return $this->skipped($templateId, self::REASON_CONTEXT_SITE_MISMATCH);
                }
            }

            $assigneeId = $snapshot->default_assigned_to !== null
                ? $this->positiveId($snapshot->default_assigned_to)
                : null;
            if ($snapshot->default_assigned_to !== null && $assigneeId === null) {
                return $this->skipped($templateId, self::REASON_ASSIGNEE_INVALID);
            }

            $people = null;
            $peopleUserIds = array_filter([
                $actor?->id ? (int) $actor->id : null,
                $assigneeId,
            ]);
            if ($peopleUserIds !== []) {
                $people = $this->peopleLocks->lock($peopleUserIds);
            }

            if ($actor !== null) {
                /** @var User|null $lockedActor */
                $lockedActor = $people['users']->get((int) $actor->id);
                abort_unless(
                    $lockedActor instanceof User
                        && $lockedActor->approved_at !== null
                        && $lockedActor->canDo('medications.orders.manage'),
                    403,
                );
                /** @var HrEmployeeProfile|null $actorProfile */
                $actorProfile = $lockedActor->hrEmployeeProfile;
                $currentClinicalDate = CarbonImmutable::now(
                    config('app.worker_timezone', 'Pacific/Auckland'),
                )->toDateString();
                abort_unless($this->profileIsCurrent($actorProfile, $currentClinicalDate), 404);
                abort_unless(
                    $lockedActor->canDo('clinical.accessAllSites')
                        || $lockedActor->canDo('sites.viewAll')
                        || $this->profileIncludesSite($actorProfile, $siteId),
                    404,
                );
            }

            if ($snapshot->default_assigned_to !== null) {
                /** @var User|null $assignee */
                $assignee = $people['users']->get($assigneeId);
                if ($assignee === null || $assignee->approved_at === null) {
                    return $this->skipped($templateId, self::REASON_ASSIGNEE_NOT_CURRENT);
                }

                /** @var HrEmployeeProfile|null $profile */
                $profile = $assignee->hrEmployeeProfile;
                if (! $this->profileIsCurrent($profile, $roundDate)) {
                    return $this->skipped($templateId, self::REASON_ASSIGNEE_NOT_CURRENT);
                }
                if (! $this->profileIncludesSite($profile, $siteId)) {
                    return $this->skipped($templateId, self::REASON_ASSIGNEE_SITE_MISMATCH);
                }
            }

            $site = Site::withTrashed()
                ->whereKey($siteId)
                ->lockForUpdate()
                ->first();
            if ($site === null) {
                return $this->skipped($templateId, self::REASON_SITE_INVALID);
            }
            if ($site->trashed() || ! $site->is_active || $site->archived || $site->archived_at !== null) {
                return $this->skipped($templateId, self::REASON_SITE_INACTIVE);
            }

            $template = MedicationRoundTemplate::query()
                ->whereKey($templateId)
                ->lockForUpdate()
                ->first();
            if ($template === null) {
                return $this->skipped($templateId, self::REASON_TEMPLATE_MISSING);
            }
            if ($this->templateIdentityChanged($template, $siteId, $contextId, $assigneeId)) {
                return $this->skipped($templateId, self::REASON_TEMPLATE_CHANGED);
            }
            if ($template->isRetired()) {
                return $this->skipped($templateId, self::REASON_TEMPLATE_RETIRED);
            }
            if (! $template->active) {
                return $this->skipped($templateId, self::REASON_TEMPLATE_INACTIVE);
            }
            if (! $generateAll && ! $template->appliesToDay($dayOfWeek)) {
                return $this->skipped($templateId, self::REASON_NOT_SCHEDULED);
            }

            $existingRound = MedicationRound::query()
                ->where('round_template_id', $template->id)
                ->where('round_date', $roundDate)
                ->lockForUpdate()
                ->first();
            if ($existingRound !== null) {
                if ((int) $existingRound->site_id !== $siteId
                    || $this->positiveId($existingRound->service_context_id) !== $contextId) {
                    return $this->skipped($templateId, self::REASON_EXISTING_ROUND_SCOPE_MISMATCH);
                }

                return [
                    'status' => self::STATUS_ALREADY_EXISTS,
                    'reason' => self::REASON_ALREADY_EXISTS,
                    'template_id' => (int) $template->id,
                    'round_id' => (int) $existingRound->id,
                ];
            }

            $round = MedicationRound::query()->create([
                'round_template_id' => $template->id,
                'round_date' => $roundDate,
                'name' => $template->name,
                'round_type' => 'scheduled',
                'scheduled_time' => $template->scheduled_time,
                'window_minutes' => $template->window_minutes ?? 60,
                'status' => 'pending',
                'assigned_to' => $assigneeId,
                'total_medications' => $template->applicableMedicationCountForDate(
                    Carbon::parse($roundDate),
                ),
                'site_id' => $siteId,
                'service_context_id' => $contextId,
            ]);

            return [
                'status' => self::STATUS_CREATED,
                'reason' => self::REASON_CREATED,
                'template_id' => (int) $template->id,
                'round_id' => (int) $round->id,
            ];
        }, 3);
    }

    /** @return array{status: string, reason: string, template_id: int, round_id: null} */
    private function skipped(int $templateId, string $reason): array
    {
        return [
            'status' => self::STATUS_SKIPPED,
            'reason' => $reason,
            'template_id' => $templateId,
            'round_id' => null,
        ];
    }

    private function positiveId(mixed $value): ?int
    {
        if (! is_numeric($value) || (int) $value <= 0) {
            return null;
        }

        return (int) $value;
    }

    private function profileIsCurrent(?HrEmployeeProfile $profile, string $roundDate): bool
    {
        if ($profile === null || $profile->trashed() || ! $profile->is_active) {
            return false;
        }

        $calendarDate = CarbonImmutable::parse($roundDate)->toDateString();

        return ($profile->start_date === null || $profile->start_date->toDateString() <= $calendarDate)
            && ($profile->end_date === null || $profile->end_date->toDateString() >= $calendarDate);
    }

    private function profileIncludesSite(HrEmployeeProfile $profile, int $siteId): bool
    {
        return collect([
            $profile->primary_site_id,
            ...(is_array($profile->secondary_site_ids) ? $profile->secondary_site_ids : []),
        ])->contains(fn (mixed $assignedSiteId): bool => $this->positiveId($assignedSiteId) === $siteId);
    }

    private function templateIdentityChanged(
        MedicationRoundTemplate $template,
        int $siteId,
        ?int $contextId,
        ?int $assigneeId,
    ): bool {
        return $this->positiveId($template->site_id) !== $siteId
            || $this->positiveId($template->service_context_id) !== $contextId
            || $this->positiveId($template->default_assigned_to) !== $assigneeId;
    }
}
