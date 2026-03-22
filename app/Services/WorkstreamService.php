<?php

namespace App\Services;

use App\Models\IncidentFollowup;
use App\Models\Shift;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class WorkstreamService
{
    /**
     * Build a unified list ("My Day" / workstream) for a staff user.
     *
     * Items are normalized into a DTO for Inertia.
     *
     * @return Collection<int, array>
     */
    public function forStaff(User $user, Carbon $from, Carbon $to): Collection
    {
        $now = now();

        $shifts = Shift::query()
            ->where('user_id', $user->id)
            ->whereBetween('starts_at', [$from, $to])
            ->orderBy('starts_at')
            ->with('client:id,first_name,last_name')
            ->limit(config('dashboard.max_workstream_items', 300))
            ->get()
            ->map(function (Shift $s) {
                $clientName = $s->client ? trim($s->client->first_name . ' ' . $s->client->last_name) : '—';
                return [
                    'kind' => 'shift',
                    'id' => $s->id,
                    'at' => optional($s->starts_at)->toISOString(),
                    'end_at' => optional($s->ends_at)->toISOString(),
                    'title' => $clientName,
                    'subtitle' => $s->location ? (string) $s->location : null,
                    'status' => (string) ($s->status ?? 'scheduled'),
                    'url' => url("/shifts/{$s->id}"),
                    'client' => $s->client ? [
                        'id' => $s->client->id,
                        'first_name' => $s->client->first_name,
                        'last_name' => $s->client->last_name,
                    ] : null,
                ];
            });

        $followups = IncidentFollowup::query()
            ->where('assigned_to_user_id', $user->id)
            ->whereNull('completed_at')
            ->where(function ($q) use ($from, $to) {
                $q->whereBetween('due_at', [$from, $to])
                    ->orWhere('due_at', '<', $from);
            })
            ->orderBy('due_at')
            ->with(['incident:id,client_id,type,severity,occurred_at', 'incident.client:id,first_name,last_name'])
            ->limit(config('dashboard.max_workstream_items', 300))
            ->get()
            ->map(function (IncidentFollowup $f) use ($now) {
                $clientName = $f->incident?->client ? trim($f->incident->client->first_name . ' ' . $f->incident->client->last_name) : '—';
                $incidentLabel = $f->incident ? (string) ($f->incident->type ?? 'Incident') : 'Incident';

                $isOverdue = $f->due_at && $f->due_at->lt($now);

                return [
                    'kind' => 'followup',
                    'id' => $f->id,
                    'at' => optional($f->due_at)->toISOString(),
                    'end_at' => null,
                    'title' => $clientName,
                    'subtitle' => $incidentLabel,
                    'status' => $isOverdue ? 'overdue' : 'open',
                    'url' => $f->incident ? url("/incidents/{$f->incident->id}") : null,
                    'client' => $f->incident?->client ? [
                        'id' => $f->incident->client->id,
                        'first_name' => $f->incident->client->first_name,
                        'last_name' => $f->incident->client->last_name,
                    ] : null,
                    'meta' => [
                        'incident_id' => $f->incident?->id,
                        'severity' => $f->incident?->severity,
                    ],
                ];
            });

        // HR tasks
        $hrTasks = collect();

        // Pending signatures for this user
        $hrTasks = $hrTasks->concat(
            \App\Domain\Hr\Models\HrDocumentSignature::where('signer_user_id', $user->id)
                ->where('status', 'pending')
                ->with('document:id,title')
                ->limit(5)
                ->get()
                ->map(fn ($sig) => [
                    'kind' => 'hr_signature',
                    'id' => $sig->id,
                    'at' => optional($sig->requested_at)->toISOString(),
                    'end_at' => null,
                    'title' => 'Sign: ' . ($sig->document?->title ?? 'Document'),
                    'subtitle' => 'E-Signature required',
                    'status' => 'open',
                    'url' => '/hr/signatures/' . $sig->id,
                    'client' => null,
                    'meta' => ['type' => 'hr'],
                ])
        );

        // Due policy attestations
        $hrTasks = $hrTasks->concat(
            \App\Domain\Hr\Models\HrPolicy::where('is_active', true)
                ->where('requires_attestation', true)
                ->whereDoesntHave('attestations', fn ($q) => $q->where('user_id', $user->id))
                ->limit(5)
                ->get()
                ->map(fn ($p) => [
                    'kind' => 'hr_attestation',
                    'id' => $p->id,
                    'at' => now()->toISOString(),
                    'end_at' => null,
                    'title' => 'Attest: ' . $p->title,
                    'subtitle' => 'Policy acknowledgement due',
                    'status' => 'open',
                    'url' => '/hr/my/policies',
                    'client' => null,
                    'meta' => ['type' => 'hr'],
                ])
        );

        return $shifts
            ->concat($followups)
            ->concat($hrTasks)
            ->sortBy(fn ($i) => $i['at'] ?? '')
            ->values();
    }
}
