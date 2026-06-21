<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\SiteHazard;
use App\Models\SiteHazardAction;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Serialises a SiteHazard into the HazardDetail payload the detail modal
 * consumes. Shared by SiteHazardController (editable register) and
 * ClientController (read-only client-profile panel) so the modal is identical
 * everywhere and the serialiser lives in one place.
 *
 * Twin of HsEventController::buildEventDetail(). Expects the hazard pre-loaded
 * with site, reportedBy, assignedTo, statusChangedBy, closedBy and
 * actions.{assignedTo,completedBy}.
 */
class HazardDetailPresenter
{
    /**
     * @param  array{manage:bool, assign:bool, close:bool, create?:bool}  $can
     * @return array<string,mixed>
     */
    public static function make(SiteHazard $hazard, array $can): array
    {
        $openActions = $hazard->actions->where('status', '!=', 'completed')->count();

        return [
            'id' => $hazard->id,
            'reference_number' => $hazard->reference_number,
            'site' => $hazard->site ? [
                'id' => $hazard->site->id,
                'name' => $hazard->site->name,
                'type' => $hazard->site->type,
            ] : null,
            'hazard_type' => $hazard->hazard_type,
            'hazard_label' => self::hazardLabel($hazard),
            'custom_hazard_type' => $hazard->custom_hazard_type,
            // Approved safe work procedures that mitigate this hazard type (clean
            // taxonomy overlaps only — manual handling / fire / biological / equipment).
            // Gated on procedures.view to match every other procedure surface.
            'related_procedures' => auth()->user()?->canDo('procedures.view')
                ? \App\Models\SafeWorkProcedure::query()
                    ->mitigatingHazardType($hazard->hazard_type)
                    ->orderBy('title')->limit(8)
                    ->get(['id', 'reference_number', 'title', 'category', 'status', 'review_date'])
                    ->map(fn ($p) => [
                        'id' => $p->id,
                        'reference_number' => $p->reference_number,
                        'title' => $p->title,
                        'category' => $p->category,
                        'status' => $p->status,
                        'review_date' => $p->review_date?->toDateString(),
                    ])->values()
                : [],
            'severity' => $hazard->severity,
            'likelihood' => $hazard->likelihood,
            'risk_rating' => $hazard->risk_rating,
            'residual_severity' => $hazard->residual_severity,
            'residual_likelihood' => $hazard->residual_likelihood,
            'residual_risk_rating' => $hazard->residual_risk_rating,
            'control_hierarchy' => array_values($hazard->control_hierarchy ?? []),
            'description' => $hazard->description,
            'location' => $hazard->location,
            'witnesses' => $hazard->witnesses,
            'immediate_action_applied' => (bool) $hazard->immediate_action_applied,
            'immediate_action_taken' => $hazard->immediate_action_taken,
            'status' => $hazard->status,
            'reported_by' => $hazard->reportedBy ? ['id' => $hazard->reportedBy->id, 'name' => $hazard->reportedBy->name] : null,
            'assigned_to' => $hazard->assignedTo ? ['id' => $hazard->assignedTo->id, 'name' => $hazard->assignedTo->name] : null,
            'due_date' => $hazard->due_date?->toDateString(),
            'created_at' => $hazard->created_at?->toDateTimeString(),
            'closed_at' => $hazard->closed_at?->toDateTimeString(),
            'status_changed_at' => $hazard->status_changed_at?->toDateTimeString(),
            'status_changed_by' => $hazard->statusChangedBy ? ['id' => $hazard->statusChangedBy->id, 'name' => $hazard->statusChangedBy->name] : null,
            'worksafe_notifiable' => $hazard->isWorksafeNotifiable(),
            'resolution_summary' => $hazard->resolution_summary,
            // Evidence lives on the private disk — emit the authenticated serve URL
            // (sites.hazards.media.show) per item instead of a raw path. The frontend's
            // storageUrl() passes these absolute URLs through unchanged.
            'photo_paths' => collect(array_values($hazard->photo_paths ?? []))
                ->map(fn ($p, int $i) => route('sites.hazards.media.show', [$hazard->id, 'photo', $i]))
                ->all(),
            'document_paths' => self::fileServeUrls($hazard, 'document', self::normaliseFiles($hazard->document_paths ?? [])),
            'resolution_evidence' => self::fileServeUrls($hazard, 'resolution', self::normaliseFiles($hazard->resolution_evidence ?? [])),
            'actions' => $hazard->actions
                ->sortByDesc('created_at')
                ->values()
                ->map(fn (SiteHazardAction $a) => [
                    'id' => $a->id,
                    'reference_number' => $a->reference_number,
                    'title' => $a->action_description,
                    'action_type' => $a->action_type,
                    'status' => $a->status === 'pending' ? 'open' : $a->status,
                    'assigned_to' => $a->assignedTo ? ['id' => $a->assignedTo->id, 'name' => $a->assignedTo->name] : null,
                    'due_date' => $a->due_date?->toDateString(),
                    'completed_at' => $a->completed_at?->toDateTimeString(),
                    'completed_by' => $a->completedBy ? ['id' => $a->completedBy->id, 'name' => $a->completedBy->name] : null,
                    'completion_notes' => $a->completion_notes,
                ])->all(),
            'assignable_staff' => $can['manage'] || $can['assign']
                ? User::staff()->select(['id', 'name'])->orderBy('name')->get()->all()
                : [],
            'close_gate' => self::closeGate($openActions),
            'history' => self::buildHistory($hazard),
            'can' => $can,
        ];
    }

    public static function hazardLabel(SiteHazard $hazard): string
    {
        if ($hazard->custom_hazard_type) {
            return $hazard->custom_hazard_type;
        }

        return Str::title(str_replace('_', ' ', (string) $hazard->hazard_type));
    }

    /**
     * @param  array<int,mixed>  $items
     * @return array<int,array{name:string, path:string, size?:int|null}>
     */
    public static function normaliseFiles(array $items): array
    {
        return collect($items)->map(function ($item) {
            if (is_string($item)) {
                return ['name' => basename($item), 'path' => $item];
            }

            return [
                'name' => $item['name'] ?? basename((string) ($item['path'] ?? '')),
                'path' => $item['path'] ?? '',
                'size' => $item['size'] ?? null,
            ];
        })->filter(fn ($i) => $i['path'] !== '')->values()->all();
    }

    /**
     * Replace each normalised file's raw path with its authenticated serve URL so the
     * private-disk evidence is reachable only through the hazards.view-gated route. The
     * index must match SiteHazardController::showMedia (both run normaliseFiles).
     *
     * @param  array<int,array{name?:string,path?:string,size?:int|null}>  $files
     * @return array<int,array{name:string,path:string,size:int|null}>
     */
    private static function fileServeUrls(SiteHazard $hazard, string $kind, array $files): array
    {
        return collect($files)
            ->map(fn ($f, int $i) => [
                'name' => $f['name'] ?? basename((string) ($f['path'] ?? '')),
                'path' => route('sites.hazards.media.show', [$hazard->id, $kind, $i]),
                'size' => $f['size'] ?? null,
            ])
            ->all();
    }

    /**
     * @return array{actions_ok:bool, blockers:array<int,string>}
     */
    private static function closeGate(int $openActions): array
    {
        $blockers = [];
        if ($openActions > 0) {
            $blockers[] = $openActions . ' corrective action' . ($openActions === 1 ? '' : 's') . ' still open.';
        }

        return ['actions_ok' => $openActions === 0, 'blockers' => $blockers];
    }

    /**
     * Audit-trail timeline, newest first, from the semantic audit events the
     * observer + controller write plus the corrective-action milestones.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function buildHistory(SiteHazard $hazard): array
    {
        $audits = AuditLog::query()
            ->where('auditable_type', $hazard->getMorphClass())
            ->where('auditable_id', $hazard->id)
            ->whereIn('action', ['hazard.created', 'hazard.assigned', 'hazard.status_changed', 'hazard.reviewed', 'hazard.risk_changed'])
            ->orderBy('created_at')
            ->get(['id', 'user_id', 'action', 'meta', 'created_at']);

        $actorIds = $audits->pluck('user_id')->filter()->unique()->all();
        $actors = $actorIds ? User::whereIn('id', $actorIds)->pluck('name', 'id') : collect();

        $entries = [];

        foreach ($audits as $audit) {
            $entry = self::historyEntryFor($audit->action, $audit->meta ?? [], $hazard);
            if (! $entry) {
                continue;
            }
            $entries[] = [
                'type' => $entry['type'],
                'title' => $entry['title'],
                'note' => $entry['note'] ?? null,
                'actor' => $actors[$audit->user_id] ?? null,
                'at' => $audit->created_at?->toDateTimeString(),
            ];
        }

        foreach ($hazard->actions as $action) {
            $entries[] = [
                'type' => 'action_added',
                'title' => 'Corrective action added',
                'note' => trim(($action->reference_number ? $action->reference_number . ' · ' : '') . ($action->action_description ?? '')),
                'actor' => $action->assignedTo?->name,
                'at' => $action->created_at?->toDateTimeString(),
            ];
            if ($action->completed_at) {
                $entries[] = [
                    'type' => 'action_completed',
                    'title' => 'Corrective action completed',
                    'note' => $action->completion_notes,
                    'actor' => $action->completedBy?->name,
                    'at' => $action->completed_at?->toDateTimeString(),
                ];
            }
        }

        usort($entries, fn ($a, $b) => strcmp((string) $b['at'], (string) $a['at']));

        return array_map(fn ($e, $i) => ['id' => $i + 1, ...$e], $entries, array_keys($entries));
    }

    /**
     * @param  array<string,mixed>  $meta
     * @return array{type:string, title:string, note?:string|null}|null
     */
    private static function historyEntryFor(string $action, array $meta, SiteHazard $hazard): ?array
    {
        return match ($action) {
            'hazard.created' => ['type' => 'reported', 'title' => 'Hazard reported'],
            'hazard.assigned' => ['type' => 'assigned', 'title' => isset($meta['assignee_name']) ? 'Assigned to ' . $meta['assignee_name'] : 'Hazard assigned'],
            'hazard.reviewed' => ['type' => 'reviewed', 'title' => 'Review recorded', 'note' => $meta['note'] ?? null],
            'hazard.risk_changed' => ['type' => 'risk', 'title' => 'Risk re-rated' . (isset($meta['from'], $meta['to']) ? ' ' . Str::title($meta['from']) . ' → ' . Str::title($meta['to']) : '')],
            'hazard.status_changed' => self::statusHistoryEntry($meta['to'] ?? '', $hazard),
            default => null,
        };
    }

    /**
     * @return array{type:string, title:string, note?:string|null}
     */
    private static function statusHistoryEntry(string $to, SiteHazard $hazard): array
    {
        return match ($to) {
            'in_progress' => ['type' => 'in_progress', 'title' => 'Moved to In progress'],
            'mitigated' => ['type' => 'mitigated', 'title' => 'Marked Mitigated', 'note' => self::mitigationNote($hazard)],
            'closed' => ['type' => 'closed', 'title' => 'Hazard closed', 'note' => $hazard->resolution_summary],
            'reopened' => ['type' => 'reopened', 'title' => 'Hazard reopened'],
            'open' => ['type' => 'reopened', 'title' => 'Returned to Open'],
            default => ['type' => 'status', 'title' => 'Status updated'],
        };
    }

    private static function mitigationNote(SiteHazard $hazard): ?string
    {
        $parts = [];
        if ($hazard->control_hierarchy) {
            $parts[] = 'Controls: ' . collect($hazard->control_hierarchy)->map(fn ($c) => Str::title(str_replace('_', ' ', $c)))->implode(', ');
        }
        if ($hazard->residual_risk_rating) {
            $parts[] = 'Residual: ' . Str::title($hazard->residual_risk_rating);
        }

        return $parts ? implode(' · ', $parts) : null;
    }
}
