<?php

use App\Models\SafeguardingConcern;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CHUNK_SIZE = 200;

    private const SAFEGUARDING_ORGANIZATION_INDEX = 'safeguarding_concerns_organization_id_index';

    public function up(): void
    {
        $this->ensureSafeguardingOrganizationColumn();
        $this->repairSafeguardingSourceTuples();
        $this->backfillEventOrganizationsFromCanonicalSites();
    }

    public function down(): void
    {
        // Ownership provenance is intentionally retained on rollback. The
        // migration only fills previously-null values when one canonical
        // tenant tuple is provable; erasing that truth would reintroduce the
        // cross-module ambiguity this repair removes.
    }

    private function ensureSafeguardingOrganizationColumn(): void
    {
        if (! Schema::hasColumn('safeguarding_concerns', 'organization_id')) {
            Schema::table('safeguarding_concerns', function (Blueprint $table): void {
                $table->unsignedBigInteger('organization_id')
                    ->nullable()
                    ->after('site_id')
                    ->index(self::SAFEGUARDING_ORGANIZATION_INDEX);
            });
        }

        $hasIndex = collect(Schema::getIndexes('safeguarding_concerns'))
            ->contains(
                fn (array $index): bool => ($index['columns'] ?? []) === ['organization_id']
                    && ($index['unique'] ?? false) === false,
            );

        if (! $hasIndex) {
            Schema::table('safeguarding_concerns', function (Blueprint $table): void {
                $table->index('organization_id', self::SAFEGUARDING_ORGANIZATION_INDEX);
            });
        }
    }

    private function repairSafeguardingSourceTuples(): void
    {
        DB::table('safeguarding_concerns')
            ->where('subject_type', User::class)
            ->whereNull('site_id')
            ->whereNull('organization_id')
            ->select('id')
            ->chunkById(self::CHUNK_SIZE, function ($concerns): void {
                foreach ($concerns as $concern) {
                    $this->repairSafeguardingSourceTuple((int) $concern->id);
                }
            });
    }

    private function repairSafeguardingSourceTuple(int $concernId): void
    {
        DB::transaction(function () use ($concernId): void {
            $concern = DB::table('safeguarding_concerns')
                ->where('id', $concernId)
                ->where('subject_type', User::class)
                ->whereNull('site_id')
                ->whereNull('organization_id')
                ->lockForUpdate()
                ->first(['id', 'subject_id']);
            if ($concern === null) {
                return;
            }

            $subject = DB::table('users')
                ->where('id', $concern->subject_id)
                ->whereNotNull('organization_id')
                ->lockForUpdate()
                ->first(['id', 'organization_id']);
            if ($subject === null) {
                return;
            }

            $profile = DB::table('hr_employee_profiles')
                ->where('user_id', $subject->id)
                ->where('tenant_id', $subject->organization_id)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->whereNotNull('primary_site_id')
                ->lockForUpdate()
                ->first(['id', 'tenant_id', 'primary_site_id']);
            if ($profile === null) {
                return;
            }

            $site = DB::table('sites')
                ->where('id', $profile->primary_site_id)
                ->where('tenant_id', $subject->organization_id)
                ->lockForUpdate()
                ->first(['id', 'tenant_id']);
            if ($site === null) {
                return;
            }

            $sourceEvents = DB::table('hs_events')
                ->where('source_type', SafeguardingConcern::class)
                ->where('source_id', $concern->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get([
                    'id',
                    'site_id',
                    'organization_id',
                    'client_id',
                    'control_room_alert_id',
                ]);
            if (! $this->eventRowsAgreeWithTuple(
                $sourceEvents,
                (int) $site->id,
                (int) $site->tenant_id,
                allowFullyUnscoped: true,
            )) {
                return;
            }

            $eventRows = $sourceEvents->filter(
                fn ($event): bool => $event->site_id === null
                    && $event->organization_id === null
                    && $event->client_id === null,
            );

            $concernUpdated = DB::table('safeguarding_concerns')
                ->where('id', $concern->id)
                ->where('subject_type', User::class)
                ->where('subject_id', $subject->id)
                ->whereNull('site_id')
                ->whereNull('organization_id')
                ->update([
                    'site_id' => $site->id,
                    'organization_id' => $site->tenant_id,
                ]);
            if ($concernUpdated !== 1) {
                return;
            }

            if ($eventRows->isEmpty()) {
                return;
            }

            $eventIds = $eventRows->pluck('id');
            $eventsUpdated = DB::table('hs_events')
                ->whereIn('id', $eventIds)
                ->where('source_type', SafeguardingConcern::class)
                ->where('source_id', $concern->id)
                ->whereNull('site_id')
                ->whereNull('organization_id')
                ->whereNull('client_id')
                ->update([
                    'site_id' => $site->id,
                    'organization_id' => $site->tenant_id,
                ]);
            if ($eventsUpdated !== $eventIds->count()) {
                throw new RuntimeException(
                    "Safeguarding provenance changed while repairing concern {$concern->id}.",
                );
            }

            $eventRows
                ->pluck('control_room_alert_id')
                ->filter()
                ->unique()
                ->each(
                    fn ($alertId) => $this->repairAlertSiteIfUnambiguous(
                        (int) $alertId,
                        (int) $site->id,
                        (int) $site->tenant_id,
                    ),
                );
        });
    }

    private function repairAlertSiteIfUnambiguous(
        int $alertId,
        int $siteId,
        int $organizationId,
    ): void {
        $alert = DB::table('control_room_alerts')
            ->where('id', $alertId)
            ->lockForUpdate()
            ->first([
                'id',
                'source',
                'site_id',
                'client_id',
                'asset_id',
                'fleet_signal_id',
                'device_id',
                'context',
            ]);
        if (
            $alert === null
            || $alert->source !== 'safeguarding'
            || $alert->site_id !== null
            || $alert->client_id !== null
            || $alert->asset_id !== null
            || $alert->fleet_signal_id !== null
            || $alert->device_id !== null
            || $this->contextCarriesOwnershipClaim($alert->context)
        ) {
            return;
        }

        $signalClaim = DB::table('control_room_signals')
            ->where(function ($query) use ($alert): void {
                $query->where('alert_id', $alert->id)
                    ->orWhere('correlated_alert_id', $alert->id);
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->first(['id']);
        if ($signalClaim !== null) {
            return;
        }

        $linkedEvents = DB::table('hs_events')
            ->where('control_room_alert_id', $alert->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'site_id', 'organization_id', 'client_id']);
        if ($linkedEvents->isEmpty()) {
            return;
        }

        if (! $this->eventRowsAgreeWithTuple($linkedEvents, $siteId, $organizationId)) {
            return;
        }

        $incidentClaim = DB::table('client_incidents')
            ->where(function ($query) use ($alert, $linkedEvents): void {
                $query->where('control_room_alert_id', $alert->id)
                    ->orWhereIn('hs_event_id', $linkedEvents->pluck('id'));
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->first(['id']);
        if ($incidentClaim !== null) {
            return;
        }

        DB::table('control_room_alerts')
            ->where('id', $alert->id)
            ->whereNull('site_id')
            ->whereNull('client_id')
            ->update([
                'site_id' => $siteId,
            ]);
    }

    private function eventRowsAgreeWithTuple(
        $events,
        int $siteId,
        int $organizationId,
        bool $allowFullyUnscoped = false,
    ): bool {
        $clientIds = $events
            ->pluck('client_id')
            ->filter()
            ->unique()
            ->values();
        $clients = $clientIds->isEmpty()
            ? collect()
            : DB::table('clients')
                ->whereIn('id', $clientIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'site_id', 'organization_id'])
                ->keyBy('id');

        return $events->every(function ($event) use (
            $allowFullyUnscoped,
            $clients,
            $siteId,
            $organizationId,
        ): bool {
            if (
                $allowFullyUnscoped
                && $event->site_id === null
                && $event->organization_id === null
                && $event->client_id === null
            ) {
                return true;
            }

            if (
                (int) $event->site_id !== $siteId
                || (int) $event->organization_id !== $organizationId
            ) {
                return false;
            }

            if ($event->client_id === null) {
                return true;
            }

            $client = $clients->get($event->client_id);

            return $client !== null
                && (int) $client->site_id === $siteId
                && (int) $client->organization_id === $organizationId;
        });
    }

    private function contextCarriesOwnershipClaim(mixed $context): bool
    {
        if (is_string($context)) {
            $context = json_decode($context, true);
        }
        if (! is_array($context)) {
            return false;
        }

        foreach ([
            'client_id',
            'client.id',
            'resident_id',
            'resident.id',
            'incident_id',
            'incident.id',
            'site_id',
            'site.id',
            'shift.site_id',
            'shift.site.id',
            'shift_context.site.id',
            'asset_id',
            'fleet_signal_id',
            'device_id',
            'normalized_data.client_id',
            'normalized_data.client.id',
            'normalized_data.resident_id',
            'normalized_data.resident.id',
            'normalized_data.incident_id',
            'normalized_data.incident.id',
            'normalized_data.site_id',
            'normalized_data.site.id',
            'normalized_data.asset_id',
            'normalized_data.fleet_signal_id',
            'normalized_data.device_id',
        ] as $path) {
            $value = data_get($context, $path);
            if (is_numeric($value) && (int) $value > 0) {
                return true;
            }
        }

        return false;
    }

    private function backfillEventOrganizationsFromCanonicalSites(): void
    {
        DB::table('hs_events')
            ->whereNull('organization_id')
            ->whereNotNull('site_id')
            ->select('id')
            ->chunkById(self::CHUNK_SIZE, function ($events): void {
                $this->backfillEventOrganizationChunk(
                    $events->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                );
            });
    }

    /**
     * @param  list<int>  $eventIds
     */
    private function backfillEventOrganizationChunk(array $eventIds): void
    {
        DB::transaction(function () use ($eventIds): void {
            $events = DB::table('hs_events')
                ->whereIn('id', $eventIds)
                ->whereNull('organization_id')
                ->whereNotNull('site_id')
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'site_id', 'client_id']);
            if ($events->isEmpty()) {
                return;
            }

            $sites = DB::table('sites')
                ->whereIn('id', $events->pluck('site_id')->unique())
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'tenant_id'])
                ->keyBy('id');

            $clientIds = $events
                ->pluck('client_id')
                ->filter()
                ->unique()
                ->values();
            $clients = $clientIds->isEmpty()
                ? collect()
                : DB::table('clients')
                    ->whereIn('id', $clientIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get(['id', 'site_id', 'organization_id'])
                    ->keyBy('id');

            foreach ($events as $event) {
                $site = $sites->get($event->site_id);
                if ($site === null || $site->tenant_id === null) {
                    continue;
                }

                if ($event->client_id !== null) {
                    $client = $clients->get($event->client_id);
                    if (
                        $client === null
                        || (int) $client->site_id !== (int) $event->site_id
                        || (int) $client->organization_id !== (int) $site->tenant_id
                    ) {
                        continue;
                    }
                }

                $update = DB::table('hs_events')
                    ->where('id', $event->id)
                    ->whereNull('organization_id')
                    ->where('site_id', $event->site_id);

                if ($event->client_id === null) {
                    $update->whereNull('client_id');
                } else {
                    $update->where('client_id', $event->client_id);
                }

                $update->update([
                    'organization_id' => $site->tenant_id,
                ]);
            }
        });
    }
};
