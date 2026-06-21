<?php

namespace App\Domain\Rostering;

use App\Events\RosterPeriodPublished;
use App\Models\RosterPeriod;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RosterPublishingService
{
    private const DIRTY_FIELDS = [
        'client_id',
        'site_id',
        'service_context_id',
        'user_id',
        'starts_at',
        'ends_at',
        'status',
        'shift_type',
        'is_sleepover',
        'is_on_call',
        'is_lone_worker',
        'expected_break_minutes',
        'coverage_roles',
        'location',
        'notes',
    ];

    public function __construct(
        private readonly RosterPeriodService $periods,
        private readonly RosterPublishValidator $validator,
        private readonly PeriodSnapshotter $snapshotter,
    ) {
    }

    public function review(RosterPeriod $period, User $actor): array
    {
        return DB::transaction(function () use ($period) {
            $locked = RosterPeriod::query()->lockForUpdate()->findOrFail($period->id);

            if ($locked->status === RosterPeriod::STATUS_ARCHIVED) {
                throw ValidationException::withMessages([
                    'roster_period' => 'Archived roster periods cannot be reviewed for publish.',
                ]);
            }

            $locked->forceFill([
                'status' => RosterPeriod::STATUS_VALIDATING,
                'validating_at' => now(),
            ])->save();

            $summary = $this->validator->validate($locked);

            $locked->forceFill([
                'status' => $summary['can_publish']
                    ? RosterPeriod::STATUS_READY
                    : ($locked->published_at
                        ? RosterPeriod::STATUS_CHANGED_AFTER_PUBLISH
                        : RosterPeriod::STATUS_DRAFT),
                'ready_at' => $summary['can_publish'] ? now() : null,
                'shift_count' => $summary['shift_count'],
                'last_validated_at' => now(),
                'validation_summary' => $summary,
            ])->save();

            return $summary;
        });
    }

    public function publish(RosterPeriod $period, User $actor): RosterPeriod
    {
        return DB::transaction(function () use ($period, $actor) {
            $locked = RosterPeriod::query()->lockForUpdate()->findOrFail($period->id);

            if ($locked->status === RosterPeriod::STATUS_ARCHIVED) {
                throw ValidationException::withMessages([
                    'roster_period' => 'Archived roster periods cannot be published.',
                ]);
            }

            if ($locked->published_at && $locked->status !== RosterPeriod::STATUS_PUBLISHED) {
                return $this->republishLocked($locked, $actor);
            }

            $summary = $this->validator->validate($locked);

            if (! $summary['can_publish']) {
                $locked->forceFill([
                    'status' => $locked->published_at
                        ? RosterPeriod::STATUS_CHANGED_AFTER_PUBLISH
                        : RosterPeriod::STATUS_DRAFT,
                    'last_validated_at' => now(),
                    'validation_summary' => $summary,
                ])->save();

                throw ValidationException::withMessages([
                    'roster_period' => 'This roster has publish blockers. Review the validation list before publishing.',
                ]);
            }

            $publishedAt = now();
            $shifts = $this->periods->shiftsQuery($locked)
                ->with(['client:id,first_name,last_name', 'site:id,name', 'serviceContext:id,name', 'staff:id,name'])
                ->lockForUpdate()
                ->orderBy('starts_at')
                ->get();

            foreach ($shifts as $shift) {
                $shift->forceFill([
                    'roster_period_id' => $locked->id,
                    'published_at' => $publishedAt,
                    'publish_dirty_at' => null,
                ])->save();
            }

            $locked->forceFill([
                'status' => RosterPeriod::STATUS_PUBLISHED,
                'week_end' => $locked->week_end ?? $locked->week_start->copy()->addDays(7),
                'shift_count' => $shifts->count(),
                'published_at' => $publishedAt,
                'published_by' => $actor->id,
                'locked_at' => null,
                'last_validated_at' => $publishedAt,
                'validation_summary' => $summary,
                'snapshot' => $this->snapshotter->snapshot($this->freshShifts($shifts)),
                'publish_meta' => [
                    'actor_id' => $actor->id,
                    'validator_warnings_count' => count($summary['warnings']),
                    'shift_count' => $shifts->count(),
                ],
            ])->save();

            $published = $locked->fresh() ?? $locked;
            event(new RosterPeriodPublished($published, $actor, false));

            return $published;
        });
    }

    public function republish(RosterPeriod $period, User $actor): RosterPeriod
    {
        return DB::transaction(function () use ($period, $actor) {
            $locked = RosterPeriod::query()->lockForUpdate()->findOrFail($period->id);

            return $this->republishLocked($locked, $actor);
        });
    }

    public function unpublish(RosterPeriod $period, User $actor): RosterPeriod
    {
        return DB::transaction(function () use ($period) {
            $locked = RosterPeriod::query()->lockForUpdate()->findOrFail($period->id);

            if ($locked->status === RosterPeriod::STATUS_ARCHIVED) {
                throw ValidationException::withMessages([
                    'roster_period' => 'Archived roster periods cannot be unpublished.',
                ]);
            }

            $shifts = $this->periods->shiftsQuery($locked)
                ->with('timesheets:id,shift_id,status')
                ->lockForUpdate()
                ->get();

            if ($shifts->contains(fn (Shift $shift) => $shift->timesheets->contains(
                fn ($timesheet) => $timesheet->status === 'approved',
            ))) {
                throw ValidationException::withMessages([
                    'roster_period' => 'Roster periods with approved timesheets cannot be unpublished.',
                ]);
            }

            $shifts->each(function (Shift $shift): void {
                $shift->forceFill([
                    'published_at' => null,
                    'publish_dirty_at' => null,
                ])->save();
            });

            $locked->forceFill([
                'status' => RosterPeriod::STATUS_DRAFT,
                'published_at' => null,
                'published_by' => null,
                'locked_at' => null,
            ])->save();

            return $locked->fresh() ?? $locked;
        });
    }

    public function diff(RosterPeriod $period): array
    {
        $shifts = $this->periods->shiftsQuery($period)
            ->with(['client:id,first_name,last_name', 'site:id,name', 'serviceContext:id,name', 'staff:id,name'])
            ->orderBy('starts_at')
            ->get();

        return $this->snapshotter->diff($period, $shifts);
    }

    public function markDirtyFromShiftUpdate(Shift $shift): void
    {
        if (! $shift->exists || ! $shift->published_at) {
            return;
        }

        if (! $shift->isDirty(self::DIRTY_FIELDS)) {
            return;
        }

        if (! $shift->publish_dirty_at) {
            $shift->forceFill(['publish_dirty_at' => now()]);
        }

        $this->markPeriodChanged($shift->rosterPeriod);
    }

    public function markDirtyFromShiftCreate(Shift $shift): void
    {
        if ($shift->published_at) {
            return;
        }

        $period = $this->activePublishedPeriodForShift($shift);

        $this->markPeriodChanged($period);
    }

    public function markDirtyFromShiftDelete(Shift $shift): void
    {
        $period = $shift->rosterPeriod ?: $this->activePublishedPeriodForShift($shift);

        $this->markPeriodChanged($period);
    }

    private function republishLocked(RosterPeriod $locked, User $actor): RosterPeriod
    {
        if (! $locked->published_at) {
            throw ValidationException::withMessages([
                'roster_period' => 'Only previously published roster periods can be republished.',
            ]);
        }

        if ($locked->status === RosterPeriod::STATUS_ARCHIVED) {
            throw ValidationException::withMessages([
                'roster_period' => 'Archived roster periods cannot be republished.',
            ]);
        }

        $summary = $this->validator->validate($locked);
        if (! $summary['can_publish']) {
            $locked->forceFill([
                'status' => RosterPeriod::STATUS_CHANGED_AFTER_PUBLISH,
                'last_validated_at' => now(),
                'validation_summary' => $summary,
            ])->save();

            throw ValidationException::withMessages([
                'roster_period' => 'This roster has publish blockers. Review the validation list before republishing.',
            ]);
        }

        $publishedAt = now();
        $shifts = $this->periods->shiftsQuery($locked)
            ->with(['client:id,first_name,last_name', 'site:id,name', 'serviceContext:id,name', 'staff:id,name'])
            ->lockForUpdate()
            ->orderBy('starts_at')
            ->get();

        $nextVersion = ((int) RosterPeriod::query()
            ->where('organization_id', $locked->organization_id)
            ->where('site_id', $locked->site_id)
            ->whereDate('week_start', $locked->week_start)
            ->max('version')) + 1;

        $newPeriod = RosterPeriod::query()->create([
            'organization_id' => $locked->organization_id,
            'site_id' => $locked->site_id,
            'week_start' => $locked->week_start,
            'week_end' => $locked->week_end ?? $locked->week_start->copy()->addDays(7),
            'version' => $nextVersion,
            'status' => RosterPeriod::STATUS_PUBLISHED,
            'shift_count' => $shifts->count(),
            'published_at' => $publishedAt,
            'published_by' => $actor->id,
            'created_by' => $actor->id,
            'last_validated_at' => $publishedAt,
            'validation_summary' => $summary,
            'publish_meta' => [
                'actor_id' => $actor->id,
                'replaces_period_id' => $locked->id,
                'validator_warnings_count' => count($summary['warnings']),
                'shift_count' => $shifts->count(),
            ],
        ]);

        foreach ($shifts as $shift) {
            $shift->forceFill([
                'roster_period_id' => $newPeriod->id,
                'published_at' => $publishedAt,
                'publish_dirty_at' => null,
            ])->save();
        }

        $newPeriod->forceFill([
            'snapshot' => $this->snapshotter->snapshot($this->freshShifts($shifts)),
        ])->save();

        $locked->forceFill([
            'status' => RosterPeriod::STATUS_ARCHIVED,
            'archived_at' => $publishedAt,
            'archive_reason' => 'republished',
            'locked_at' => $publishedAt,
        ])->save();

        $published = $newPeriod->fresh() ?? $newPeriod;
        event(new RosterPeriodPublished($published, $actor, true));

        return $published;
    }

    private function markPeriodChanged(?RosterPeriod $period): void
    {
        if ($period && $period->status === RosterPeriod::STATUS_PUBLISHED) {
            $period->forceFill([
                'status' => RosterPeriod::STATUS_CHANGED_AFTER_PUBLISH,
            ])->save();
        }
    }

    private function activePublishedPeriodForShift(Shift $shift): ?RosterPeriod
    {
        if (! $shift->site_id || ! $shift->starts_at) {
            return null;
        }

        $period = $this->periods->activeFor(
            $shift->organization_id,
            (int) $shift->site_id,
            $shift->starts_at,
        );

        return $period?->status === RosterPeriod::STATUS_PUBLISHED ? $period : null;
    }

    private function freshShifts($shifts)
    {
        return Shift::query()
            ->with(['client:id,first_name,last_name', 'site:id,name', 'serviceContext:id,name', 'staff:id,name'])
            ->whereKey($shifts->modelKeys())
            ->orderBy('starts_at')
            ->get();
    }
}
