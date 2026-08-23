<?php

namespace App\Services\Incidents;

use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\IncidentReportDraft;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class IncidentReportDraftService
{
    private const RETENTION_DAYS = 14;

    public function __construct(private readonly UserSiteAccessService $siteAccess) {}

    /** @param array<string, mixed> $form */
    public function assertWritableScope(
        User $actor,
        string $requestUuid,
        array $form,
    ): void {
        abort_unless($actor->canDo('incidents.create'), 403);

        $incident = ClientIncident::query()
            ->where('report_request_uuid', $requestUuid)
            ->first(['reported_by', 'status']);
        if ($incident && (int) $incident->reported_by !== (int) $actor->id) {
            abort(404);
        }
        if ($incident && $incident->status !== 'draft') {
            abort(409, 'This incident report has already been saved or submitted.');
        }

        $draft = IncidentReportDraft::query()
            ->where('request_uuid', $requestUuid)
            ->first(['user_id', 'site_id', 'client_id', 'expires_at', 'consumed_at']);

        if ($draft && (int) $draft->user_id !== (int) $actor->id) {
            abort(404);
        }

        if ($draft?->consumed_at) {
            abort(409, 'This incident report has already been saved or submitted.');
        }

        if ($draft && ! $draft->expires_at?->isPast()) {
            $this->assertCanAccessDraft($actor, $draft);
        }

        $this->resolveScope($actor, $form);
    }

    /**
     * @param  array<string, mixed>  $form
     */
    public function save(
        User $actor,
        string $requestUuid,
        string $mode,
        string $entryContext,
        int $stepIndex,
        array $form,
        int $expectedRevision,
    ): IncidentReportDraft {
        abort_unless($actor->canDo('incidents.create'), 403);

        $payload = [
            'mode' => $mode,
            'entry_context' => $entryContext,
            'step_index' => $stepIndex,
            'form' => $form,
        ];
        $payloadHash = $this->payloadHash($payload);

        return DB::transaction(function () use (
            $actor,
            $requestUuid,
            $mode,
            $entryContext,
            $payload,
            $payloadHash,
            $expectedRevision,
            $form,
        ): IncidentReportDraft {
            // Canonical lock order shared with incident submission: incident
            // identity first, then recovery draft. This prevents a delayed
            // autosave from racing a terminal submit and resurrecting its UUID.
            $incident = ClientIncident::query()
                ->where('report_request_uuid', $requestUuid)
                ->lockForUpdate()
                ->first();
            if ($incident && (int) $incident->reported_by !== (int) $actor->id) {
                abort(404);
            }
            if ($incident && $incident->status !== 'draft') {
                abort(409, 'This incident report has already been saved or submitted.');
            }

            $draft = IncidentReportDraft::query()
                ->where('request_uuid', $requestUuid)
                ->lockForUpdate()
                ->first();

            if ($draft && (int) $draft->user_id !== (int) $actor->id) {
                abort(404);
            }

            // Re-resolve scope inside the write transaction so an early
            // privacy preflight is never the authority for the mutation.
            [$clientId, $siteId] = $this->resolveScope($actor, $form);
            if ($incident) {
                $this->assertCanonicalIncidentBinding(
                    $incident,
                    $clientId,
                    $siteId,
                    filled($form['shift_id'] ?? null) ? (int) $form['shift_id'] : null,
                );
            }

            if (! $draft) {
                if ($expectedRevision !== 0) {
                    abort(409, 'The saved incident draft changed. Reload it and retry.');
                }

                $draft = IncidentReportDraft::query()->createOrFirst([
                    'request_uuid' => $requestUuid,
                ], [
                    'user_id' => $actor->id,
                    'site_id' => $siteId,
                    'client_id' => $clientId,
                    'mode' => $mode,
                    'entry_context' => $entryContext,
                    'encrypted_payload' => $payload,
                    'payload_hash' => $payloadHash,
                    'revision' => 1,
                    'saved_at' => now(),
                    'expires_at' => now()->addDays(self::RETENTION_DAYS),
                ]);

                if ($draft->wasRecentlyCreated) {
                    return $draft;
                }

                $draft = IncidentReportDraft::query()
                    ->where('request_uuid', $requestUuid)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            if ((int) $draft->user_id !== (int) $actor->id) {
                abort(404);
            }

            if ($draft->consumed_at) {
                abort(409, 'This incident report has already been saved or submitted.');
            }

            if ($draft->expires_at?->isPast()) {
                if (! in_array($expectedRevision, [0, (int) $draft->revision], true)) {
                    abort(409, 'The saved incident draft changed. Reload it and retry.');
                }

                $draft->forceFill([
                    'site_id' => $siteId,
                    'client_id' => $clientId,
                    'mode' => $mode,
                    'entry_context' => $entryContext,
                    'encrypted_payload' => $payload,
                    'payload_hash' => $payloadHash,
                    'revision' => (int) $draft->revision + 1,
                    'saved_at' => now(),
                    'expires_at' => now()->addDays(self::RETENTION_DAYS),
                ])->save();

                return $draft->fresh();
            }

            $this->assertCanAccessDraft($actor, $draft);

            if ($expectedRevision !== (int) $draft->revision) {
                if (hash_equals((string) $draft->payload_hash, $payloadHash)) {
                    return $draft;
                }

                abort(409, 'The saved incident draft changed. Reload it and retry.');
            }

            if (hash_equals((string) $draft->payload_hash, $payloadHash)) {
                return $draft;
            }

            $draft->forceFill([
                'site_id' => $siteId,
                'client_id' => $clientId,
                'mode' => $mode,
                'entry_context' => $entryContext,
                'encrypted_payload' => $payload,
                'payload_hash' => $payloadHash,
                'revision' => (int) $draft->revision + 1,
                'saved_at' => now(),
                'expires_at' => now()->addDays(self::RETENTION_DAYS),
            ])->save();

            return $draft->fresh();
        }, 3);
    }

    public function findOwned(User $actor, string $requestUuid): ?IncidentReportDraft
    {
        abort_unless($actor->canDo('incidents.create'), 403);

        $draft = IncidentReportDraft::query()
            ->where('request_uuid', $requestUuid)
            ->where('user_id', $actor->id)
            ->first();

        if (! $draft || $draft->consumed_at) {
            return null;
        }

        if ($draft->expires_at?->isPast()) {
            IncidentReportDraft::query()
                ->whereKey($draft->id)
                ->where('expires_at', '<=', now())
                ->delete();

            return null;
        }

        $this->assertCanAccessDraft($actor, $draft);

        return $draft;
    }

    public function discardOwned(User $actor, string $requestUuid): void
    {
        abort_unless($actor->canDo('incidents.create'), 403);

        DB::transaction(function () use ($actor, $requestUuid): void {
            $draft = IncidentReportDraft::query()
                ->where('request_uuid', $requestUuid)
                ->where('user_id', $actor->id)
                ->lockForUpdate()
                ->first();

            // Missing and foreign UUIDs deliberately have the same response.
            if ($draft && ! $draft->consumed_at) {
                $draft->delete();
            }
        }, 3);
    }

    public function consumeOwned(
        User $actor,
        ?string $requestUuid,
        int $clientId,
        int $siteId,
    ): void {
        if (! $requestUuid) {
            return;
        }

        abort_unless($actor->canDo('incidents.create'), 403);

        DB::transaction(function () use ($actor, $requestUuid, $clientId, $siteId): void {
            $draft = IncidentReportDraft::query()
                ->where('request_uuid', $requestUuid)
                ->lockForUpdate()
                ->first();

            if (! $draft) {
                $draft = IncidentReportDraft::query()->createOrFirst([
                    'request_uuid' => $requestUuid,
                ], [
                    'user_id' => $actor->id,
                    'site_id' => $siteId,
                    'client_id' => $clientId,
                    'mode' => 'incident',
                    'entry_context' => 'incidents',
                    'encrypted_payload' => ['consumed' => true],
                    'payload_hash' => $this->payloadHash(['consumed' => true]),
                    'revision' => 1,
                    'saved_at' => now(),
                    'expires_at' => now()->addDays(self::RETENTION_DAYS),
                    'consumed_at' => now(),
                ]);

                if ($draft->wasRecentlyCreated) {
                    return;
                }

                $draft = IncidentReportDraft::query()
                    ->where('request_uuid', $requestUuid)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            abort_unless((int) $draft->user_id === (int) $actor->id, 404);
            $this->assertConsumeBinding($draft, $clientId, $siteId);

            if ($draft->consumed_at) {
                return;
            }

            $draft->forceFill([
                'site_id' => $siteId,
                'client_id' => $clientId,
                'encrypted_payload' => ['consumed' => true],
                'payload_hash' => $this->payloadHash(['consumed' => true]),
                'revision' => (int) $draft->revision + 1,
                'saved_at' => now(),
                'expires_at' => now()->addDays(self::RETENTION_DAYS),
                'consumed_at' => $draft->consumed_at ?? now(),
            ])->save();
        }, 3);
    }

    private function assertConsumeBinding(
        IncidentReportDraft $draft,
        int $clientId,
        int $siteId,
    ): void {
        if ($draft->client_id !== null && (int) $draft->client_id !== $clientId) {
            throw ValidationException::withMessages([
                'report_request_uuid' => 'The saved recovery draft belongs to a different person.',
            ]);
        }

        if ($draft->site_id !== null && (int) $draft->site_id !== $siteId) {
            throw ValidationException::withMessages([
                'report_request_uuid' => 'The saved recovery draft belongs to a different Site.',
            ]);
        }
    }

    private function assertCanonicalIncidentBinding(
        ClientIncident $incident,
        ?int $clientId,
        ?int $siteId,
        ?int $shiftId,
    ): void {
        if ($clientId !== (int) $incident->client_id) {
            throw ValidationException::withMessages([
                'form.client_id' => 'The saved incident belongs to a different person.',
            ]);
        }

        if ($siteId !== ($incident->site_id ? (int) $incident->site_id : null)) {
            throw ValidationException::withMessages([
                'form.site_id' => 'The saved incident belongs to a different Site.',
            ]);
        }

        if ($shiftId !== ($incident->shift_id ? (int) $incident->shift_id : null)) {
            throw ValidationException::withMessages([
                'form.shift_id' => 'The saved incident belongs to a different shift.',
            ]);
        }
    }

    private function assertCanAccessDraft(User $actor, IncidentReportDraft $draft): void
    {
        if ($draft->client_id !== null) {
            $client = $this->visibleClient($actor, (int) $draft->client_id);
            abort_unless($client, 404);
            abort_unless(
                $draft->site_id !== null
                    && $client->site_id !== null
                    && (int) $client->site_id === (int) $draft->site_id,
                404,
            );
        }

        if ($draft->site_id !== null) {
            $site = Site::query()->whereKey((int) $draft->site_id);
            $this->siteAccess->applySiteScope(
                $site,
                $actor,
                UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS,
            );
            abort_unless($site->exists(), 404);
        }
    }

    /**
     * @param  array<string, mixed>  $form
     * @return array{0: int|null, 1: int|null}
     */
    private function resolveScope(User $actor, array $form): array
    {
        $clientId = filled($form['client_id'] ?? null) ? (int) $form['client_id'] : null;
        $suppliedSiteId = filled($form['site_id'] ?? null) ? (int) $form['site_id'] : null;
        $shiftId = filled($form['shift_id'] ?? null) ? (int) $form['shift_id'] : null;
        $client = $clientId !== null
            ? $this->visibleClient($actor, $clientId)
            : null;

        if ($clientId !== null) {
            abort_unless($client, 404);
        }

        $clientSiteId = $client?->site_id ? (int) $client->site_id : null;
        $shift = null;
        $shiftSiteId = null;
        if ($shiftId !== null) {
            $shiftQuery = Shift::query()
                ->whereKey($shiftId)
                ->where('user_id', $actor->id)
                ->with('client:id,site_id');
            $this->siteAccess->applyShiftScope(
                $shiftQuery,
                $actor,
                UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS,
            );
            $shift = $shiftQuery->first();
            abort_unless($shift, 404);
            $shiftSiteId = $this->siteAccess->shiftSiteId($shift);

            if ($clientId && (int) $shift->client_id !== $clientId) {
                throw ValidationException::withMessages([
                    'form.shift_id' => 'The selected shift does not match this incident draft.',
                ]);
            }
        }

        $resolvedSiteIds = array_values(array_unique(array_filter([
            $suppliedSiteId,
            $clientSiteId,
            $shiftSiteId,
        ], fn (?int $siteId): bool => $siteId !== null)));

        if (count($resolvedSiteIds) > 1) {
            throw ValidationException::withMessages([
                'form.site_id' => 'The selected Site does not match this incident draft.',
            ]);
        }

        $siteId = $resolvedSiteIds[0] ?? null;
        if ($siteId === null) {
            throw ValidationException::withMessages([
                'form.client_id' => 'Choose the person or Site before this draft can be saved.',
            ]);
        }

        if ($siteId !== null) {
            $site = Site::query()->whereKey($siteId);
            $this->siteAccess->applySiteScope(
                $site,
                $actor,
                UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS,
            );
            abort_unless($site->exists(), 404);
        }

        return [$clientId, $siteId];
    }

    private function visibleClient(User $actor, int $clientId): ?Client
    {
        $query = Client::query()->whereKey($clientId);
        $this->siteAccess->applyClientScope(
            $query,
            $actor,
            UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS,
        );
        $client = $query->first();

        if (! $client) {
            return null;
        }

        return ($actor->can('view', $client)
            || ($actor->canDo('incidents.create') && $actor->canDo('healthSafety.viewAllSites')))
            ? $client
            : null;
    }

    /** @param array<string, mixed> $payload */
    private function payloadHash(array $payload): string
    {
        return hash_hmac(
            'sha256',
            json_encode(
                $this->canonicalize($payload),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ),
            (string) config('app.key'),
        );
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value, SORT_STRING);

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }
}
