<?php

namespace App\Services\Tasks\Providers;

use App\Models\MedicationRound;
use App\Models\Shift;
use App\Models\User;
use App\Services\Emar\RosteredMedicationRounds;
use App\Services\Tasks\Contracts\SiteScopedTaskProvider;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\MedicationRoundTaskItem;
use App\Services\Tasks\TaskItem;
use App\Services\Tasks\TaskProviderAuthorization;
use Carbon\Carbon;

/** Read-only rostered work. Task visibility never grants round administration. */
final class MedicationRoundProvider implements SiteScopedTaskProvider, TaskProvider
{
    public function sourceKey(): string
    {
        return 'medication-round';
    }

    public function label(): string
    {
        return 'Medicines rounds';
    }

    public function canView(User $user): bool
    {
        return $user->canDo('medications.view') || $user->canDo('medications.administer.record');
    }

    public function authorizedTasks(User $user, array $filters = []): array
    {
        if (! $this->canView($user)) {
            return [];
        }
        $timezone = (string) config('app.worker_timezone', 'Pacific/Auckland');
        $from = isset($filters['from']) ? Carbon::parse($filters['from'])->timezone($timezone) : now($timezone)->startOfDay();
        $to = isset($filters['to']) ? Carbon::parse($filters['to'])->timezone($timezone) : $from->copy()->addDays(2)->endOfDay();
        // The calendar already supplies a bounded viewport. Tasks use today's
        // generated horizon. No synthetic task, clinical record or assignment.
        abort_if($from->diffInDays($to, true) > 62, 422);
        $context = app(RosteredMedicationRounds::class)->context($user, $from, $to);
        $siteIds = $context['site_ids'];
        $people = $context['people'];
        $shifts = $context['shifts'];
        if ($people === []) {
            return [];
        }

        $query = MedicationRound::query()->with(['site:id,name', 'assignedTo:id,name'])
            ->whereBetween('round_date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('status', ['pending', 'partial', 'in_progress', 'completed'])
            ->when(isset($filters['id']), fn ($q) => $q->whereKey((int) $filters['id']))
            ->orderBy('round_date')->orderBy('scheduled_time');

        return app(TaskProviderAuthorization::class)->siteScoped($user, $this->canView($user), $query,
            fn ($q, User $actor) => $q->whereIn('site_id', $siteIds)
                ->whereHas('site.clients', fn ($clients) => $clients->whereIn('id', $people)),
            function (MedicationRound $round) use ($user, $shifts, $people, $filters): ?TaskItem {
                $at = $round->scheduledAt();
                $cover = $at ? $shifts->first(fn (Shift $shift): bool => (int) $shift->site_id === (int) $round->site_id
                    && $at->betweenIncluded(Carbon::parse($shift->getRawOriginal('starts_at'), 'UTC'), Carbon::parse($shift->getRawOriginal('ends_at'), 'UTC'))
                ) : null;
                if (! $cover) {
                    return null;
                }
                $progress = app(RosteredMedicationRounds::class)->progress($round, $people);
                if ($progress['total'] === 0 && ($progress['waiting'] ?? 0) === 0) {
                    return null;
                }
                $done = $progress['pending'] === 0 && ($progress['waiting'] ?? 0) === 0;
                if ($done && empty($filters['include_done'])) {
                    return null;
                }
                $waiting = ($progress['waiting'] ?? 0) > 0;
                $owned = (int) $round->assigned_to === (int) $user->id
                    || ($round->assigned_to === null && (int) $round->started_by === (int) $user->id);
                $label = 'Medicines due '.$at->format('g:i a').' — '.($round->site?->name ?? 'House');
                $task = new MedicationRoundTaskItem(
                    id: 'medication-round-'.$round->id, source: $this->sourceKey(), sourceLabel: $this->label(),
                    ref: null, title: $label, status: $done ? 'completed' : ($waiting ? 'waiting_for_check' : $round->status),
                    bucket: $done ? TaskItem::BUCKET_DONE : TaskItem::BUCKET_OPEN,
                    severity: ! $done && $round->isOverdue() ? 'high' : 'info',
                    assignee: $round->assignedTo ? ['id' => $round->assignedTo->id, 'name' => $round->assignedTo->name] : null,
                    site: ['id' => (int) $round->site_id, 'name' => $round->site?->name ?? 'House'],
                    dueAt: $done || $waiting ? null : $at->copy()->addMinutes($round->windowMinutes())->toIso8601String(),
                    createdAt: $round->created_at?->toIso8601String(),
                    link: '/meds/today?'.http_build_query(['date' => $at->toDateString(), 'view' => $owned ? 'rounds' : 'schedule', ...($owned ? ['round' => $round->id] : [])]),
                    type: 'Medicines round', description: $progress['completed'].'/'.$progress['total'].' staff doses recorded'.($waiting ? ' · Waiting for the order check' : ''),
                    sourceContext: 'On your roster at this house; only people whose medicines you may open.',
                    actionLabel: $owned ? 'Open round' : 'Open Meds today',
                    actionHelp: $owned ? 'Recording permission is checked again for each dose.' : 'A lead can assign the round through Medicines rounds. The walk-through requires its assignee.',
                );
                $task->scheduledAt = $at->toIso8601String();

                return $task;
            });
    }

    public function calendar(User $user, Carbon $from, Carbon $to): array
    {
        return array_map(static fn (MedicationRoundTaskItem $task): array => [
            'id' => $task->id, 'title' => $task->title, 'start' => $task->scheduledAt,
            'allDay' => false,
            'extendedProps' => ['type' => 'medication_round', 'link' => $task->link,
                'status' => $task->status, 'description' => $task->description.' · '.$task->actionHelp],
        ], $this->authorizedTasks($user, ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String(), 'include_done' => true]));
    }
}
