<?php

namespace App\Services\Tasks;

use Illuminate\Database\Eloquent\Builder;

final class TaskSearch
{
    /**
     * Providers that make up the audited Control Room → incident → H&S journey.
     *
     * @var string[]
     */
    public const INCIDENT_JOURNEY_SOURCES = [
        'alert',
        'incident',
        'followup',
        'hs_event',
        'hs_investigation',
        'corrective_action',
    ];

    public static function hasQuery(array $filters): bool
    {
        return trim((string) ($filters['q'] ?? '')) !== '';
    }

    /**
     * Narrow candidates by the record's own searchable fields even when it has
     * no incident link. Providers still apply source authority, then the
     * aggregator matches only their permission-shaped search material.
     *
     * @param  list<string>  $columns
     * @param  array<string, list<string>>  $relations
     * @param  array<string, string>  $displayStates
     */
    public static function applyRecordPredicate(Builder $query, array $filters, array $columns, array $relations = [], array $displayStates = []): Builder
    {
        $needle = trim((string) ($filters['q'] ?? ''));
        if ($needle === '') {
            return $query;
        }
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $needle).'%';
        $statuses = self::matchingStatuses($needle, $displayStates);

        return $query->where(function (Builder $match) use ($columns, $relations, $like, $statuses): void {
            foreach ($columns as $column) {
                $match->orWhereLike($column, $like);
            }
            if ($statuses !== []) {
                $match->orWhereIn('status', $statuses);
            }
            foreach ($relations as $relation => $fields) {
                $match->orWhereHas($relation, function (Builder $related) use ($fields, $like): void {
                    $related->where(function (Builder $values) use ($fields, $like): void {
                        foreach ($fields as $field) {
                            $values->orWhereLike($field, $like);
                        }
                        if (in_array('first_name', $fields, true) && in_array('last_name', $fields, true)) {
                            $values->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", [$like]);
                        }
                    });
                });
            }
        });
    }

    /**
     * @return string[]
     */
    public static function incidentJourneySources(?array $selectedSources): array
    {
        if ($selectedSources === null || $selectedSources === []) {
            return self::INCIDENT_JOURNEY_SOURCES;
        }

        return array_values(array_intersect(self::INCIDENT_JOURNEY_SOURCES, $selectedSources));
    }

    public static function applyIncidentJourneyPredicate(Builder $query, array $filters): Builder
    {
        $needle = trim((string) ($filters['q'] ?? ''));
        if ($needle === '') {
            return $query;
        }

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $needle).'%';
        $words = array_values(array_filter(
            preg_split('/\s+/', $needle) ?: [],
            fn (string $word) => $word !== '',
        ));
        $correctiveActionStatuses = self::matchingStatuses($needle, [
            'open' => 'Not started',
            'in_progress' => 'In progress',
            'completed' => 'Awaiting independent verification',
            'verified' => 'Verified — ready to close',
            'closed' => 'Closed',
        ]);
        $alertStatuses = self::matchingStatuses($needle, [
            'open' => 'Awaiting response',
            'ack' => 'Acknowledged',
            'triaging' => 'Triage in progress',
            'confirmed' => 'Response confirmed',
            'resolved' => 'Resolved',
            'closed' => 'Closed',
            'dismissed' => 'Dismissed',
        ]);

        return $query->where(function (Builder $match) use (
            $alertStatuses,
            $correctiveActionStatuses,
            $like,
            $words,
        ): void {
            $match
                ->whereLike('reference_number', $like)
                ->orWhereLike('title', $like)
                ->orWhereLike('description', $like)
                ->orWhereLike('immediate_action_taken', $like)
                ->orWhereLike('immediate_action', $like)
                ->orWhereLike('witnesses', $like)
                ->orWhereLike('potential_consequence', $like)
                ->orWhereLike('source', $like)
                ->orWhereLike('status', $like)
                ->orWhereHas('client', function (Builder $client) use ($like, $words): void {
                    $client->whereLike('first_name', $like)
                        ->orWhereLike('last_name', $like);

                    if (count($words) > 1) {
                        $client->orWhere(function (Builder $fullName) use ($words): void {
                            foreach ($words as $word) {
                                $wordLike = '%'.str_replace(
                                    ['\\', '%', '_'],
                                    ['\\\\', '\\%', '\\_'],
                                    $word,
                                ).'%';
                                $fullName->where(function (Builder $part) use ($wordLike): void {
                                    $part->whereLike('first_name', $wordLike)
                                        ->orWhereLike('last_name', $wordLike);
                                });
                            }
                        });
                    }
                })
                ->orWhereHas('site', fn (Builder $site) => $site->whereLike('name', $like))
                ->orWhereHas('investigator', fn (Builder $owner) => $owner->whereLike('name', $like))
                ->orWhereHas('followups', function (Builder $followup) use ($like): void {
                    $followup->whereLike('notes', $like)
                        ->orWhereHas('assignedTo', fn (Builder $owner) => $owner->whereLike('name', $like));
                })
                ->orWhereHas('controlRoomAlert', function (Builder $alert) use ($alertStatuses, $like): void {
                    $alert
                        ->whereLike('reference_number', $like)
                        ->orWhereLike('alert_type', $like)
                        ->orWhereLike('category', $like)
                        ->orWhereLike('source', $like)
                        ->orWhereLike('notes', $like)
                        ->orWhereLike('status', $like)
                        ->when(
                            $alertStatuses !== [],
                            fn (Builder $status) => $status->orWhereIn('status', $alertStatuses),
                        )
                        ->orWhereHas('assignedTo', fn (Builder $owner) => $owner->whereLike('name', $like))
                        ->orWhereHas('tasks', function (Builder $task) use ($like): void {
                            $task->whereLike('title', $like)
                                ->orWhereLike('description', $like)
                                ->orWhereHas('assignedTo', fn (Builder $owner) => $owner->whereLike('name', $like));
                        });
                })
                ->orWhereHas('hsEvent', function (Builder $event) use (
                    $correctiveActionStatuses,
                    $like,
                ): void {
                    $event
                        ->whereLike('reference_number', $like)
                        ->orWhereLike('event_category', $like)
                        ->orWhereLike('status', $like)
                        ->orWhereLike('severity', $like)
                        ->orWhereHas('owner', fn (Builder $owner) => $owner->whereLike('name', $like))
                        ->orWhereHas('investigations', function (Builder $investigation) use ($like): void {
                            $investigation
                                ->whereLike('reference_number', $like)
                                ->orWhereLike('investigation_type', $like)
                                ->orWhereLike('status', $like)
                                ->orWhereLike('findings_summary', $like)
                                ->orWhereHas(
                                    'leadInvestigator',
                                    fn (Builder $owner) => $owner->whereLike('name', $like),
                                );
                        })
                        ->orWhereHas('correctiveActions', function (Builder $action) use (
                            $correctiveActionStatuses,
                            $like,
                        ): void {
                            $action
                                ->whereLike('reference_number', $like)
                                ->orWhereLike('title', $like)
                                ->orWhereLike('description', $like)
                                ->orWhereLike('status', $like)
                                ->orWhereLike('priority', $like)
                                ->when(
                                    $correctiveActionStatuses !== [],
                                    fn (Builder $status) => $status->orWhereIn(
                                        'status',
                                        $correctiveActionStatuses,
                                    ),
                                )
                                ->orWhereHas(
                                    'assignedTo',
                                    fn (Builder $owner) => $owner->whereLike('name', $like),
                                )
                                ->orWhereHas('sourceControlRoomTask', function (Builder $task) use ($like): void {
                                    $task->whereLike('title', $like)
                                        ->orWhereLike('description', $like);
                                })
                                ->orWhereHas(
                                    'hsInvestigation',
                                    fn (Builder $investigation) => $investigation
                                        ->whereLike('reference_number', $like),
                                );
                        });
                });
        });
    }

    /**
     * @param  array<string, string>  $labels
     * @return string[]
     */
    private static function matchingStatuses(string $needle, array $labels): array
    {
        $needle = strtolower($needle);

        return array_keys(array_filter(
            $labels,
            fn (string $label) => str_contains(strtolower($label), $needle),
        ));
    }
}
