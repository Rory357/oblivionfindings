<?php

namespace App\Domain\It\Services;

use App\Models\ItRecurrencePlan;
use App\Models\ItTicket;
use App\Models\ItTicketEvent;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Recurring maintenance tickets. Every due occurrence is claimed by a unique
 * (plan, occurrence) run row before a ticket exists, so a crashed or replayed
 * scheduler run can never create the same ticket twice; missed catch-up is
 * bounded to the single most recent occurrence, with older misses recorded
 * as skipped rather than silently discarded or exploded into a storm.
 */
final class ItRecurrenceService
{
    /** At most this many overdue occurrences are examined per plan per run. */
    private const CATCH_UP_WINDOW = 25;

    public function __construct(private readonly ItTicketRoutingService $routing) {}

    public function create(User $actor, array $data): ItRecurrencePlan
    {
        $this->assertValidCron($data['cron_expression']);

        return DB::transaction(function () use ($actor, $data): ItRecurrencePlan {
            $plan = ItRecurrencePlan::query()->create([
                ...$this->planFields($data),
                'owner_user_id' => $data['owner_user_id'] ?? $actor->id,
                'status' => 'active',
                'lock_version' => 1,
                'created_by_user_id' => $actor->id,
                'updated_by_user_id' => $actor->id,
            ]);
            $plan->forceFill(['next_due_at' => $this->nextDue($plan, CarbonImmutable::now())])->save();
            AuditLogger::logOrFail('it.recurrence.created', $plan, ['actor_id' => $actor->id]);

            return $plan;
        });
    }

    public function update(ItRecurrencePlan $plan, User $actor, array $data): ItRecurrencePlan
    {
        $this->assertValidCron($data['cron_expression']);

        return DB::transaction(function () use ($plan, $actor, $data): ItRecurrencePlan {
            $current = ItRecurrencePlan::query()->lockForUpdate()->findOrFail($plan->id);
            $this->assertCurrentVersion($current, (int) ($data['lock_version'] ?? 0));
            $current->fill([
                ...$this->planFields($data),
                'owner_user_id' => $data['owner_user_id'] ?? $current->owner_user_id,
                'lock_version' => $current->lock_version + 1,
                'updated_by_user_id' => $actor->id,
            ]);
            // Editing applies to future occurrences only: recompute from now,
            // never rewrite already-recorded runs or their tickets.
            $current->next_due_at = $current->status === 'active'
                ? $this->nextDue($current, CarbonImmutable::now())
                : null;
            $current->save();
            AuditLogger::logOrFail('it.recurrence.updated', $current, ['actor_id' => $actor->id]);

            return $current;
        });
    }

    public function setStatus(ItRecurrencePlan $plan, User $actor, string $status, int $expectedVersion): ItRecurrencePlan
    {
        if (! in_array($status, ItRecurrencePlan::STATUSES, true)) {
            throw ValidationException::withMessages(['status' => 'Unknown recurrence status.']);
        }

        return DB::transaction(function () use ($plan, $actor, $status, $expectedVersion): ItRecurrencePlan {
            $current = ItRecurrencePlan::query()->lockForUpdate()->findOrFail($plan->id);
            $this->assertCurrentVersion($current, $expectedVersion);
            if ($current->status === 'retired' && $status !== 'retired') {
                throw ValidationException::withMessages(['status' => 'A retired plan stays retired. Create a new plan instead.']);
            }
            $current->fill([
                'status' => $status,
                'lock_version' => $current->lock_version + 1,
                'updated_by_user_id' => $actor->id,
                'next_due_at' => $status === 'active' ? $this->nextDue($current, CarbonImmutable::now()) : null,
            ])->save();
            AuditLogger::logOrFail('it.recurrence.'.$status, $current, ['actor_id' => $actor->id]);

            return $current;
        });
    }

    /** @return array{created: int, skipped: int, failed: int} */
    public function runDue(?CarbonImmutable $now = null): array
    {
        $now = $now ?? CarbonImmutable::now();
        $summary = ['created' => 0, 'skipped' => 0, 'failed' => 0];
        $planIds = ItRecurrencePlan::query()->where('status', 'active')
            ->whereNotNull('next_due_at')->where('next_due_at', '<=', $now)->pluck('id');

        foreach ($planIds as $planId) {
            $occurrence = null;
            try {
                $occurrence = DB::transaction(function () use ($planId, $now, &$summary): ?array {
                    $plan = ItRecurrencePlan::query()->lockForUpdate()->find($planId);
                    if (! $plan || $plan->status !== 'active' || $plan->next_due_at === null || $plan->next_due_at->gt($now)) {
                        return null;
                    }

                    // Walk the overdue occurrences; everything before the most
                    // recent one is recorded as an auditable skip, never work.
                    $due = [];
                    $cursor = CarbonImmutable::parse($plan->next_due_at);
                    for ($step = 0; $step < self::CATCH_UP_WINDOW && $cursor !== null && $cursor->lte($now); $step++) {
                        $due[] = $cursor;
                        $cursor = $this->nextDue($plan, $cursor->addMinute());
                    }
                    $latest = array_pop($due);
                    foreach ($due as $missed) {
                        if ($plan->runs()->firstOrCreate(
                            ['occurrence_key' => $missed->toIso8601String()],
                            ['status' => 'skipped', 'detail' => 'Missed occurrence superseded by a later one.', 'created_at' => now()],
                        )->wasRecentlyCreated) {
                            $summary['skipped']++;
                        }
                    }

                    $plan->forceFill([
                        'next_due_at' => $cursor,
                        'status' => $cursor === null ? 'retired' : $plan->status,
                    ])->save();

                    if ($latest === null) {
                        return null;
                    }

                    // Claim the occurrence before any ticket exists.
                    $run = $plan->runs()->firstOrCreate(
                        ['occurrence_key' => $latest->toIso8601String()],
                        ['status' => 'created', 'detail' => null, 'created_at' => now()],
                    );
                    if (! $run->wasRecentlyCreated) {
                        return null;
                    }

                    return ['plan' => $plan, 'run_id' => (int) $run->id, 'due' => $latest];
                });

                if ($occurrence !== null) {
                    DB::transaction(function () use ($occurrence, &$summary): void {
                        $ticket = $this->createTicket($occurrence['plan'], $occurrence['due']);
                        $occurrence['plan']->runs()->whereKey($occurrence['run_id'])->update(['ticket_id' => $ticket->id]);
                        $summary['created']++;
                    });
                }
            } catch (Throwable $raised) {
                report($raised);
                $summary['failed']++;
                if ($occurrence !== null) {
                    ItRecurrencePlan::query()->find($planId)?->runs()
                        ->whereKey($occurrence['run_id'])
                        ->update(['status' => 'failed', 'detail' => mb_substr($raised->getMessage(), 0, 500)]);
                }
            }
        }

        return $summary;
    }

    public function nextDue(ItRecurrencePlan $plan, CarbonImmutable $from): ?CarbonImmutable
    {
        $timezone = $plan->timezone ?: 'Pacific/Auckland';
        $cron = new CronExpression($plan->cron_expression);
        $cursor = $from->setTimezone($timezone);
        $start = CarbonImmutable::parse($plan->starts_on->toDateString(), $timezone)->startOfDay();
        if ($cursor->lt($start)) {
            $cursor = $start;
        }
        $end = $plan->ends_on
            ? CarbonImmutable::parse($plan->ends_on->toDateString(), $timezone)->endOfDay()
            : null;
        $exceptions = array_map('strval', $plan->exception_dates ?? []);

        // The cron library truncates seconds, so "advance past X" must move
        // in whole minutes: subMinute + strict search makes `from` inclusive
        // at minute precision without ever re-yielding the same occurrence.
        for ($step = 0; $step < 400; $step++) {
            $candidate = CarbonImmutable::instance($cron->getNextRunDate($cursor->subMinute(), 0, false, $timezone));
            if ($end !== null && $candidate->gt($end)) {
                return null;
            }
            if (! in_array($candidate->toDateString(), $exceptions, true)) {
                return $candidate->utc();
            }
            // Exceptions are whole dates; resume from the following day.
            $cursor = $candidate->addDay()->startOfDay();
        }

        return null;
    }

    private function createTicket(ItRecurrencePlan $plan, CarbonImmutable $due): ItTicket
    {
        $template = $plan->ticket_template;
        $ticket = ItTicket::createWithReference([
            'site_id' => (int) $template['site_id'],
            'is_organisation_wide' => false,
            'title' => (string) $template['title'],
            'description' => trim(($template['description'] ?? '')."\n\nScheduled occurrence: ".$due->setTimezone($plan->timezone ?: 'Pacific/Auckland')->format('D j M Y, H:i')),
            'requester_user_id' => $plan->owner_user_id,
            'category' => $template['category'] ?? 'other',
            'it_service_id' => $template['it_service_id'] ?? null,
            'source' => 'system',
            'work_type' => $template['work_type'] ?? 'task',
            'priority' => $template['priority'] ?? 'normal',
            'impact' => 'individual',
            'urgency' => 'normal',
            'status' => 'open',
            'status_reason' => 'recurring_plan',
            'requires_approval' => false,
        ]);
        $ticket->stampSlaDueDates();
        $ticket->save();
        ItTicketEvent::record($ticket, 'created_from_recurrence', null, [
            'plan_id' => $plan->id, 'plan_name' => $plan->name, 'occurrence' => $due->toIso8601String(),
        ]);
        $this->routing->route($ticket);

        return $ticket;
    }

    private function planFields(array $data): array
    {
        return [
            'name' => $data['name'],
            'cron_expression' => $data['cron_expression'],
            'timezone' => $data['timezone'] ?? 'Pacific/Auckland',
            'starts_on' => $data['starts_on'],
            'ends_on' => $data['ends_on'] ?? null,
            'exception_dates' => array_values($data['exception_dates'] ?? []),
            'ticket_template' => $data['ticket_template'],
        ];
    }

    private function assertValidCron(string $expression): void
    {
        if (! CronExpression::isValidExpression($expression)) {
            throw ValidationException::withMessages(['cron_expression' => 'That schedule expression is not valid.']);
        }
    }

    private function assertCurrentVersion(ItRecurrencePlan $current, int $expectedVersion): void
    {
        if ($expectedVersion !== (int) $current->lock_version) {
            throw ValidationException::withMessages([
                'lock_version' => 'This plan changed while you were editing. Reload it and reapply your changes.',
            ]);
        }
    }
}
