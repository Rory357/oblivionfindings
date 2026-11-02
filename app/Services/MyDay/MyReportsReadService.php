<?php

namespace App\Services\MyDay;

use App\Models\ClientIncident;
use App\Models\User;
use App\Models\WorkplaceInjury;
use App\Services\HealthSafety\OwnInjuryReadService;
use App\Support\WorkerClock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * My Day's "What you reported" and "Your workplace injury" (board G4-1, approved by Stephan 1 Nov 2026; Q3, D-5).
 *
 * What you reported: only the person's own reports — incidents they reported and workplace injuries they recorded —
 * as status only, in the four words Draft · Sent · Being followed up · Closed. Their own reports about no particular
 * person (a hot tap, a wardrobe door) show here only; the Incidents list can't open them. Never listed: safeguarding,
 * restraint, HR-confidential or independently handled reports — those go straight to the people who follow them up.
 * Nothing here widens a read: it is the reporter's own rows, by the reporter's own id, with no other people's content.
 *
 * Your workplace injury: the person's own open injury, from the own-injury read (being the injured worker is the whole
 * read rule): modified duties for now, the next review date (not when the duties end) and who coordinates.
 */
final class MyReportsReadService
{
    public const WORDS = ['draft', 'sent', 'followed_up', 'closed'];

    public function __construct(private readonly OwnInjuryReadService $ownInjuries) {}

    /** @return list<array<string, mixed>> */
    public function recent(User $user, int $limit = 5): array
    {
        $gate = Gate::forUser($user);
        $incidents = $this->ownIncidents($user)
            ->with(['client', 'site:id,name'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(function (ClientIncident $incident) use ($gate): array {
                // Privacy (Stephan, 1 Nov 2026): a person's name shows only while the reporter may still open that
                // person today (the client view rule). Someone they no longer support is "a person you no longer
                // support", and a draft about them has no Finish link.
                $current = $incident->client !== null && $gate->allows('view', $incident->client);

                return [
                    'kind' => 'incident',
                    'id' => (int) $incident->id,
                    'type' => $incident->type,
                    'client_name' => $current
                        ? trim($incident->client->first_name.' '.$incident->client->last_name)
                        : null,
                    'former_client' => $incident->client !== null && ! $current,
                    // A report about no particular person names its place instead.
                    'site_name' => $incident->client ? null : $incident->site?->name,
                    'at' => $incident->occurred_at?->utc()->toIso8601String(),
                    'status' => self::incidentWord($incident->status, $incident->incident_owner_user_id !== null),
                    'finish_url' => $incident->status === 'draft' && $current
                        ? '/incidents/'.$incident->id.'?return_to=%2Fmy-day'
                        : null,
                ];
            });

        $injuries = WorkplaceInjury::query()
            ->where('created_by', $user->id)
            ->orderByDesc('injury_date')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'user_id', 'injury_type', 'body_part_affected', 'injury_date', 'status', 'coordinator_user_id'])
            ->map(fn (WorkplaceInjury $injury): array => [
                'kind' => 'injury',
                'id' => (int) $injury->id,
                'injury_type' => $injury->injury_type,
                'body_part' => $injury->body_part_affected,
                // Someone else's injury is said only as "a workplace injury": never their name here.
                'own' => (int) $injury->user_id === (int) $user->id,
                'at' => $injury->injury_date?->utc()->toIso8601String(),
                'status' => self::injuryWord($injury->status, $injury->coordinator_user_id !== null),
                'finish_url' => null,
            ]);

        return $incidents->concat($injuries)
            ->sortByDesc(fn (array $row) => $row['at'] ?? '')
            ->take($limit)
            ->values()
            ->all();
    }

    /** The person's own open workplace injury (the newest), or null. */
    public function openInjury(User $user): ?array
    {
        $injury = collect($this->ownInjuries->forWorker($user))
            ->first(fn (array $row) => ! in_array($row['status'] ?? null, ['recovered', 'closed'], true));
        if (! $injury) {
            return null;
        }

        $today = WorkerClock::today()->toDateString();
        $plan = collect($injury['plans'] ?? [])->first(fn (array $plan) => ($plan['status'] ?? null) === 'active');
        $onModifiedDuties = collect($injury['plans'] ?? [])
            ->flatMap(fn (array $plan) => $plan['duties'] ?? [])
            ->contains(fn (array $duty) => ($duty['status'] ?? null) === 'active'
                && (! $duty['start_date'] || $duty['start_date'] <= $today)
                && (! $duty['end_date'] || $duty['end_date'] >= $today));

        return [
            'id' => $injury['id'],
            'injury_type' => $injury['injury_type'],
            'body_part' => $injury['body_part'],
            'injury_date' => $injury['injury_date'],
            'on_modified_duties' => $onModifiedDuties,
            'next_review_date' => $injury['next_review_date'] ?? $plan['next_review_date'] ?? null,
            'coordinator' => $injury['coordinator'],
        ];
    }

    /** The reporter's own incidents, without the kinds that go privately to the people who follow them up. */
    private function ownIncidents(User $user): Builder
    {
        return ClientIncident::query()
            ->where('reported_by', $user->id)
            ->where(fn (Builder $query) => $query->where('is_hr_confidential', false)->orWhereNull('is_hr_confidential'))
            ->where(fn (Builder $query) => $query->where('independent_handling_requested', false)->orWhereNull('independent_handling_requested'))
            ->where(function (Builder $query): void {
                foreach (['%safeguard%', '%abuse%', '%neglect%', '%restrain%'] as $private) {
                    $query->where('type', 'not like', $private);
                }
            });
    }

    /** Draft · Sent (nobody has taken it on) · Being followed up (someone has it) · Closed. */
    public static function incidentWord(?string $status, bool $hasOwner): string
    {
        return match (true) {
            in_array($status, [null, '', 'draft'], true) => 'draft',
            $status === 'closed' => 'closed',
            $status === 'reviewed', $hasOwner => 'followed_up',
            default => 'sent',
        };
    }

    public static function injuryWord(?string $status, bool $hasCoordinator): string
    {
        return match (true) {
            in_array($status, ['recovered', 'closed'], true) => 'closed',
            in_array($status, ['under_treatment', 'return_to_work'], true), $hasCoordinator => 'followed_up',
            default => 'sent',
        };
    }
}
