<?php

namespace App\Services\HealthSafety;

use App\Models\AuditLog;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\HsEventFile;
use App\Models\HsEventOwnerChange;
use App\Models\HsInvestigation;
use App\Models\HsInvestigationCorrection;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * G3 History tab (boards approved by Stephan, 1 Nov 2026): everything done on a safety review, newest first, in plain
 * words, each entry tagged with its step (assigned, investigated, implemented, check, closed) and marked when it is a
 * correction or a check that didn't work. Built only from what is already recorded (the audit log, owner changes and
 * corrections), so nothing here can be edited. Raw before/after field values are never exposed; corrections show the
 * findings text, which only people who can read the whole review see (the controller reads the event first).
 */
final class HsEventActivityService
{
    public const LIMIT = 300;

    /**
     * Story view (G3 board M5b): only what happened to the viewer's own actions on the review — never anyone else's
     * actions, the investigation or the review itself.
     *
     * @return list<array{at: string, who: ?string, you: bool, what: string}>
     */
    public function forOwnActions(HsEvent $event, User $viewer): array
    {
        $actions = HsCorrectiveAction::query()->where('hs_event_id', $event->id)->where('assigned_to_user_id', $viewer->id)
            ->get(['id', 'title', 'reference_number']);
        if ($actions->isEmpty()) {
            return [];
        }
        $titles = $actions->mapWithKeys(fn (HsCorrectiveAction $a): array => [$a->id => $a->title ?: $a->reference_number])->all();
        $rows = AuditLog::query()
            ->whereIn('auditable_type', [(new HsCorrectiveAction)->getMorphClass(), HsCorrectiveAction::class])
            ->whereIn('auditable_id', $actions->pluck('id'))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit(50)
            ->get(['id', 'user_id', 'action', 'auditable_type', 'auditable_id', 'meta', 'created_at']);
        $names = User::query()->whereIn('id', $rows->pluck('user_id')->filter()->unique())->pluck('name', 'id');

        $entries = [];
        foreach ($rows as $row) {
            $entry = $this->describe($row, $titles, [], true);
            if ($entry === null) {
                continue;
            }
            $entries[] = [
                'at' => Carbon::parse($row->created_at)->toIso8601String(),
                'who' => $row->user_id ? ($names[$row->user_id] ?? null) : 'The app',
                'you' => (int) $row->user_id === (int) $viewer->id,
                'what' => $entry['what'],
            ];
        }

        return $entries;
    }

    /** @return list<array{at: string, who: ?string, you: bool, what: string, step: string, kind: string, detail: ?array}> */
    public function forEvent(HsEvent $event, ?User $viewer = null): array
    {
        $actions = HsCorrectiveAction::query()->where('hs_event_id', $event->id)->get(['id', 'title', 'reference_number']);
        $investigationIds = HsInvestigation::query()->where('hs_event_id', $event->id)->pluck('id')->all();
        $titles = $actions->mapWithKeys(fn (HsCorrectiveAction $a): array => [$a->id => $a->title ?: $a->reference_number]);

        $types = [
            'event' => [(new HsEvent)->getMorphClass(), HsEvent::class],
            'action' => [(new HsCorrectiveAction)->getMorphClass(), HsCorrectiveAction::class],
            'investigation' => [(new HsInvestigation)->getMorphClass(), HsInvestigation::class],
        ];
        $rows = AuditLog::query()
            ->where(function ($q) use ($event, $types, $actions, $investigationIds): void {
                $q->where(fn ($e) => $e->whereIn('auditable_type', $types['event'])->where('auditable_id', $event->id));
                if ($actions->isNotEmpty()) {
                    $q->orWhere(fn ($a) => $a->whereIn('auditable_type', $types['action'])->whereIn('auditable_id', $actions->pluck('id')));
                }
                if ($investigationIds !== []) {
                    $q->orWhere(fn ($i) => $i->whereIn('auditable_type', $types['investigation'])->whereIn('auditable_id', $investigationIds));
                }
            })
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get(['id', 'user_id', 'action', 'auditable_type', 'auditable_id', 'meta', 'created_at']);

        $names = User::query()->whereIn('id', $rows->pluck('user_id')->filter()->unique())->pluck('name', 'id');
        $corrections = HsInvestigationCorrection::query()->where('hs_event_id', $event->id)->get()->keyBy('id');

        $entries = new Collection;
        foreach ($rows as $row) {
            $entry = $this->describe($row, $titles->all(), $corrections->all(), in_array($row->auditable_type, $types['action'], true));
            if ($entry === null) {
                continue;
            }
            $entries->push([
                'at' => Carbon::parse($row->created_at)->toIso8601String(),
                'who' => $row->user_id ? ($names[$row->user_id] ?? null) : 'The app',
                'you' => $viewer !== null && (int) $row->user_id === (int) $viewer->id,
                ...$entry,
            ]);
        }

        // Owner changes keep their reason in their own table.
        foreach (HsEventOwnerChange::query()->where('hs_event_id', $event->id)->get() as $change) {
            $entries = $entries->reject(fn (array $e): bool => ($e['change_id'] ?? null) === $change->id)->values();
            $entries->push([
                'at' => $change->created_at->toIso8601String(), 'who' => $change->actor_name,
                'you' => $viewer !== null && (int) $change->actor_user_id === (int) $viewer->id,
                'what' => $change->previous_owner_user_id === null && $change->previous_owner_name === null
                    ? 'chose '.$change->owner_name.' as the owner'
                    : 'changed the owner from '.($change->previous_owner_name ?: 'someone no longer listed').' to '.$change->owner_name,
                'step' => 'assigned', 'kind' => 'owner', 'detail' => $change->reason !== null ? ['why' => $change->reason] : null,
            ]);
        }

        return $entries->sortByDesc('at')->values()
            ->map(fn (array $e): array => array_diff_key($e, ['change_id' => true]))
            ->take(self::LIMIT)->all();
    }

    /**
     * @param  array<int, string>  $titles
     * @param  array<int, HsInvestigationCorrection>  $corrections
     * @return array{what: string, step: string, kind: string, detail: ?array, change_id?: int}|null
     */
    private function describe(AuditLog $row, array $titles, array $corrections, bool $isAction): ?array
    {
        $meta = is_array($row->meta) ? $row->meta : [];
        $title = $isAction ? '“'.($titles[(int) $row->auditable_id] ?? 'an action').'”' : null;
        $plain = fn (string $what, string $step, string $kind = 'done', ?array $detail = null): array => compact('what', 'step', 'kind', 'detail');

        switch ($row->action) {
            case 'healthSafety.correctiveAction.created':
                return $plain('added '.$title.(! empty($meta['replaces_action_id']) ? ' as a revised action' : ''), 'implemented');
            case 'healthSafety.correctiveAction.handedOver':
                return $plain('changed the owner of '.$title, 'implemented', 'owner');
            case 'healthSafety.event.ownerChanged':
                return [...$plain('changed the owner', 'assigned', 'owner'), 'change_id' => (int) ($meta['change_id'] ?? 0)];
            case 'healthSafety.event.worksafeDecisionRecorded':
                return $plain('recorded the WorkSafe decision', 'investigated');
            case 'healthSafety.event.sitePreservationDecisionRecorded':
                return $plain('recorded whether the site had to be kept as it was', 'investigated');
            case 'healthSafety.event.sitePreservationReleased':
                return $plain('recorded that WorkSafe released the site', 'investigated');
            case 'healthSafety.investigation.submittedForReview':
                return $plain('sent the findings for review', 'investigated');
            case 'healthSafety.investigation.reviewed':
                return $plain('reviewed the findings', 'investigated');
            case 'healthSafety.investigation.approved':
                return $plain('approved the findings', 'investigated');
            case 'healthSafety.investigation.returnedForRework':
                return $plain('sent the findings back for more work', 'investigated');
            case 'healthSafety.investigation.recommendationDispositioned':
                return $plain('decided what to do about a recommendation', 'investigated');
            case 'healthSafety.investigation.correctionProposed':
            case 'healthSafety.investigation.correctionReviewed':
            case 'healthSafety.investigation.correctionApproved':
            case 'healthSafety.investigation.correctionRejected':
                $c = $corrections[(int) ($meta['correction_id'] ?? 0)] ?? null;
                $verb = match ($row->action) {
                    'healthSafety.investigation.correctionProposed' => 'proposed a correction to the findings',
                    'healthSafety.investigation.correctionReviewed' => 'reviewed the correction to the findings',
                    'healthSafety.investigation.correctionApproved' => 'approved the correction to the findings',
                    default => 'didn\'t accept the correction to the findings',
                };
                $detail = $c && $row->action === 'healthSafety.investigation.correctionProposed'
                    ? ['why' => $c->reason, 'before' => $c->before_value, 'after' => $c->after_value, 'status' => $c->status]
                    : ($row->action === 'healthSafety.investigation.correctionRejected' && $c ? ['why' => $c->rejection_reason] : null);

                return $plain($verb, 'investigated', 'edit', $detail);
            case 'healthSafety.event.closureExceptionRequested':
                return $plain('asked to close anyway', 'closed');
            case 'healthSafety.event.closureExceptionApproved':
                return $plain('approved closing anyway', 'closed');
            case 'healthSafety.event.closureExceptionRejected':
                return $plain('didn\'t approve closing anyway', 'closed');
            case 'healthSafety.event.closureExceptionRevoked':
                return $plain('withdrew the approval to close anyway', 'closed');
            case 'healthSafety.event.closed':
            case 'healthSafety.event.closedWithException':
                return $plain('closed the safety review', 'closed');
            // G3 board M5d: each upload, open and hide of a step's file is part of the record.
            case 'healthSafety.event.fileAdded':
            case 'healthSafety.event.fileOpened':
            case 'healthSafety.event.fileHidden':
                $file = isset($meta['file_id']) ? HsEventFile::query()->find((int) $meta['file_id'], ['id', 'original_name', 'kind', 'step', 'hidden_reason']) : null;
                $name = $file ? '“'.$file->original_name.'”' : 'a file';
                $step = in_array($meta['step'] ?? $file?->step, HsEventFile::STEPS, true) ? (string) ($meta['step'] ?? $file?->step) : 'investigated';

                return match ($row->action) {
                    'healthSafety.event.fileAdded' => $plain('added '.match ($file?->kind) {
                        HsEventFile::KIND_PHOTO => 'a photo ', HsEventFile::KIND_VIDEO => 'a video ', default => 'a document ',
                    }.$name, $step),
                    'healthSafety.event.fileOpened' => $plain(($file?->kind === HsEventFile::KIND_VIDEO ? 'played ' : 'opened ').$name, $step),
                    default => $plain('hid '.$name, $step, 'edit', ['why' => $meta['reason'] ?? $file?->hidden_reason]),
                };
            case 'hscorrectiveaction.update':
                return $this->actionStatus($meta, $title);
            case 'hsevent.update':
                return $this->eventChange($meta);
        }

        return null;
    }

    /** @return array{what: string, step: string, kind: string, detail: ?array}|null */
    private function actionStatus(array $meta, ?string $title): ?array
    {
        $after = is_array($meta['after'] ?? null) ? $meta['after'] : [];
        $status = $after['status'] ?? null;

        return match ($status) {
            HsCorrectiveAction::STATUS_IN_PROGRESS => ['what' => 'started '.$title, 'step' => 'implemented', 'kind' => 'done', 'detail' => null],
            HsCorrectiveAction::STATUS_COMPLETED => ['what' => 'marked '.$title.' done', 'step' => 'implemented', 'kind' => 'done', 'detail' => null],
            HsCorrectiveAction::STATUS_VERIFIED => (in_array($after['effectiveness_confirmed'] ?? null, [false, 0, '0'], true)
                ? ['what' => 'checked '.$title.': didn\'t work', 'step' => 'check', 'kind' => 'failed', 'detail' => null]
                : ['what' => 'checked '.$title.': worked', 'step' => 'check', 'kind' => 'done', 'detail' => null]),
            HsCorrectiveAction::STATUS_CLOSED => ['what' => 'closed '.$title, 'step' => 'check', 'kind' => 'done', 'detail' => null],
            default => null,
        };
    }

    /** @return array{what: string, step: string, kind: string, detail: ?array}|null */
    private function eventChange(array $meta): ?array
    {
        $after = is_array($meta['after'] ?? null) ? $meta['after'] : [];
        if (($after['handover_status'] ?? null) === HsEvent::HANDOVER_ACCEPTED) {
            return ['what' => 'took the safety review on', 'step' => 'assigned', 'kind' => 'owner', 'detail' => null];
        }

        return null;
    }
}
