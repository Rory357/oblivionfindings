<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ControlRoomAlert;
use App\Models\HsEvent;
use App\Models\SafeguardingConcern;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HsEventLegacyStorageCompatibilityBackfillTest extends TestCase
{
    use RefreshDatabase;

    private int $concernReferenceSequence = 0;

    public function test_schema_retains_hidden_application_compatibility_storage_without_a_phantom_organizations_table(): void
    {
        $this->assertFalse(Schema::hasTable('organizations'));
        $this->assertTrue(Schema::hasColumn('safeguarding_concerns', 'organization_id'));

        $column = collect(Schema::getColumns('safeguarding_concerns'))
            ->firstWhere('name', 'organization_id');
        $index = collect(Schema::getIndexes('safeguarding_concerns'))
            ->first(fn (array $candidate): bool => ($candidate['columns'] ?? []) === ['organization_id']
                && ($candidate['unique'] ?? false) === false);

        $this->assertNotNull($column);
        $this->assertTrue((bool) ($column['nullable'] ?? false));
        $this->assertNotNull($index);

        // Use the normal model lifecycle here: the assertion is specifically
        // proving the production compatibility writer, not a fixture bypass.
        $concern = SafeguardingConcern::factory()->create([
            'reference_number' => $this->nextConcernReference(),
        ]);
        $this->assertSame(1, (int) $concern->organization_id);
        $this->assertArrayNotHasKey('organization_id', $concern->toArray());
    }

    public function test_hs_storage_backfill_uses_inert_application_value_without_changing_the_historical_site(): void
    {
        $historicalSite = Site::factory()->create();
        $currentSite = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $currentSite->id]);
        $event = HsEvent::factory()->create([
            'site_id' => $historicalSite->id,
            'client_id' => $client->id,
        ]);
        $this->emulateLegacyNullStorage('hs_events', $event->id);

        $this->historicalSiteCompatibilityMigration()->up();

        $event->refresh();
        $this->assertSame(1, (int) $event->organization_id);
        $this->assertSame($historicalSite->id, (int) $event->site_id);
        $this->assertSame($client->id, (int) $event->client_id);
    }

    public function test_client_subject_storage_backfill_uses_inert_value_without_changing_the_historical_site(): void
    {
        $historicalSite = Site::factory()->create();
        $currentSite = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $currentSite->id]);
        $concern = SafeguardingConcern::withoutEvents(
            fn () => SafeguardingConcern::factory()->create([
                'reference_number' => $this->nextConcernReference(),
                'subject_type' => Client::class,
                'subject_id' => $client->id,
                'site_id' => $historicalSite->id,
            ]),
        );
        $this->emulateLegacyNullStorage('safeguarding_concerns', $concern->id);

        $this->historicalSiteCompatibilityMigration()->up();

        $concern->refresh();
        $this->assertSame(1, (int) $concern->organization_id);
        $this->assertSame($historicalSite->id, (int) $concern->site_id);
        $this->assertSame($client->id, (int) $concern->subject_id);
    }

    public function test_staff_concern_recovers_the_canonical_hr_primary_site_across_the_journey(): void
    {
        $journey = $this->createUnscopedStaffJourney();

        $this->organizationProvenanceMigration()->up();

        $journey['concern']->refresh();
        $journey['event']->refresh();
        $journey['alert']->refresh();

        $this->assertSame(1, (int) $journey['concern']->organization_id);
        $this->assertSame($journey['site']->id, (int) $journey['concern']->site_id);
        $this->assertSame(1, (int) $journey['event']->organization_id);
        $this->assertSame($journey['site']->id, (int) $journey['event']->site_id);
        $this->assertSame($journey['site']->id, (int) $journey['alert']->site_id);
    }

    public function test_staff_concern_with_multiple_secondary_sites_remains_unscoped(): void
    {
        $firstSite = Site::factory()->create();
        $secondSite = Site::factory()->create();
        $subject = User::factory()->create();
        HrEmployeeProfile::factory()->create([
            'user_id' => $subject->id,
            'primary_site_id' => null,
            'secondary_site_ids' => [$firstSite->id, $secondSite->id],
            'is_active' => true,
            'deleted_at' => null,
        ]);
        $concern = SafeguardingConcern::withoutEvents(
            fn () => SafeguardingConcern::factory()->create([
                'reference_number' => $this->nextConcernReference(),
                'subject_type' => User::class,
                'subject_id' => $subject->id,
                'site_id' => null,
            ]),
        );
        $this->emulateLegacyNullStorage('safeguarding_concerns', $concern->id);
        $alert = ControlRoomAlert::factory()->create([
            'source' => 'safeguarding',
            'site_id' => null,
            'client_id' => null,
        ]);
        $event = HsEvent::factory()->create([
            'site_id' => null,
            'client_id' => null,
            'source_type' => SafeguardingConcern::class,
            'source_id' => $concern->id,
            'control_room_alert_id' => $alert->id,
        ]);
        $this->emulateLegacyNullStorage('hs_events', $event->id);

        $this->organizationProvenanceMigration()->up();

        $concern->refresh();
        $event->refresh();
        $alert->refresh();
        $this->assertNull($concern->site_id);
        $this->assertNull($concern->getRawOriginal('organization_id'));
        $this->assertNull($event->site_id);
        $this->assertNull($event->getRawOriginal('organization_id'));
        $this->assertNull($alert->site_id);
    }

    public function test_conflicting_existing_source_claimant_prevents_staff_journey_repair(): void
    {
        $journey = $this->createUnscopedStaffJourney();
        $conflictingSite = Site::factory()->create();
        $claimant = HsEvent::factory()->create([
            'site_id' => $conflictingSite->id,
            'client_id' => null,
            'source_type' => SafeguardingConcern::class,
            'source_id' => $journey['concern']->id,
        ]);

        $this->organizationProvenanceMigration()->up();

        $journey['concern']->refresh();
        $journey['event']->refresh();
        $journey['alert']->refresh();
        $this->assertNull($journey['concern']->site_id);
        $this->assertNull($journey['concern']->getRawOriginal('organization_id'));
        $this->assertNull($journey['event']->site_id);
        $this->assertNull($journey['event']->getRawOriginal('organization_id'));
        $this->assertNull($journey['alert']->site_id);
        $this->assertSame($conflictingSite->id, (int) $claimant->fresh()->site_id);
    }

    public function test_incident_claim_through_linked_hs_event_prevents_alert_site_reassignment(): void
    {
        $journey = $this->createUnscopedStaffJourney();
        $incidentSite = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $incidentSite->id]);
        $incident = ClientIncident::withoutEvents(
            fn () => ClientIncident::factory()->create([
                'client_id' => $client->id,
                'site_id' => $incidentSite->id,
                'hs_event_id' => $journey['event']->id,
                'control_room_alert_id' => null,
            ]),
        );

        $this->organizationProvenanceMigration()->up();

        $this->assertSame($journey['site']->id, (int) $journey['concern']->fresh()->site_id);
        $this->assertSame($journey['site']->id, (int) $journey['event']->fresh()->site_id);
        $this->assertNull($journey['alert']->fresh()->site_id);
        $this->assertSame($incidentSite->id, (int) $incident->fresh()->site_id);
        $this->assertSame($journey['event']->id, (int) $incident->hs_event_id);
    }

    public function test_current_client_site_does_not_rewrite_historical_safeguarding_provenance(): void
    {
        $historicalSite = Site::factory()->create();
        $currentSite = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $currentSite->id]);
        $concern = SafeguardingConcern::withoutEvents(
            fn () => SafeguardingConcern::factory()->create([
                'reference_number' => $this->nextConcernReference(),
                'subject_type' => Client::class,
                'subject_id' => $client->id,
                'site_id' => $historicalSite->id,
            ]),
        );
        $this->emulateLegacyNullStorage('safeguarding_concerns', $concern->id);
        $event = HsEvent::factory()->create([
            'site_id' => $historicalSite->id,
            'client_id' => $client->id,
            'source_type' => SafeguardingConcern::class,
            'source_id' => $concern->id,
        ]);
        $this->emulateLegacyNullStorage('hs_events', $event->id);

        $this->historicalSiteCompatibilityMigration()->up();

        $concern->refresh();
        $event->refresh();
        $client->refresh();
        $this->assertSame($historicalSite->id, (int) $concern->site_id);
        $this->assertSame($client->id, (int) $concern->subject_id);
        $this->assertSame($historicalSite->id, (int) $event->site_id);
        $this->assertSame($client->id, (int) $event->client_id);
        $this->assertSame($currentSite->id, (int) $client->site_id);
        $this->assertSame(1, (int) $concern->getRawOriginal('organization_id'));
        $this->assertSame(1, (int) $event->getRawOriginal('organization_id'));
    }

    public function test_missing_client_subject_stays_untouched(): void
    {
        $site = Site::factory()->create();
        $missingClientId = 9_999_999;
        $concern = SafeguardingConcern::withoutEvents(
            fn () => SafeguardingConcern::factory()->create([
                'reference_number' => $this->nextConcernReference(),
                'subject_type' => Client::class,
                'subject_id' => $missingClientId,
                'site_id' => $site->id,
            ]),
        );
        $this->emulateLegacyNullStorage('safeguarding_concerns', $concern->id);

        $this->historicalSiteCompatibilityMigration()->up();

        $concern->refresh();
        $this->assertNull($concern->organization_id);
        $this->assertSame($site->id, (int) $concern->site_id);
        $this->assertSame($missingClientId, (int) $concern->subject_id);
    }

    public function test_different_site_hs_claim_prevents_alert_site_overwrite(): void
    {
        $journey = $this->createUnscopedStaffJourney();
        $differentSite = Site::factory()->create();

        HsEvent::factory()->create([
            'site_id' => $differentSite->id,
            'client_id' => null,
            'control_room_alert_id' => $journey['alert']->id,
        ]);

        $this->organizationProvenanceMigration()->up();

        $this->assertSame($journey['site']->id, (int) $journey['concern']->fresh()->site_id);
        $this->assertSame($journey['site']->id, (int) $journey['event']->fresh()->site_id);
        $this->assertNull($journey['alert']->fresh()->site_id);
    }

    public function test_different_site_incident_claim_prevents_alert_site_overwrite(): void
    {
        $journey = $this->createUnscopedStaffJourney();
        $differentSite = Site::factory()->create();
        $differentClient = Client::factory()->create(['site_id' => $differentSite->id]);

        ClientIncident::withoutEvents(
            fn () => ClientIncident::factory()->create([
                'client_id' => $differentClient->id,
                'site_id' => $differentSite->id,
                'control_room_alert_id' => $journey['alert']->id,
            ]),
        );

        $this->organizationProvenanceMigration()->up();

        $this->assertSame($journey['site']->id, (int) $journey['concern']->fresh()->site_id);
        $this->assertSame($journey['site']->id, (int) $journey['event']->fresh()->site_id);
        $this->assertNull($journey['alert']->fresh()->site_id);
    }

    public function test_different_site_incident_context_prevents_alert_site_overwrite(): void
    {
        $journey = $this->createUnscopedStaffJourney();
        $differentSite = Site::factory()->create();
        $differentClient = Client::factory()->create(['site_id' => $differentSite->id]);
        $incident = ClientIncident::withoutEvents(
            fn () => ClientIncident::factory()->create([
                'client_id' => $differentClient->id,
                'site_id' => $differentSite->id,
            ]),
        );
        $journey['alert']->updateQuietly([
            'context' => ['incident_id' => $incident->id],
        ]);

        $this->organizationProvenanceMigration()->up();

        $this->assertSame($journey['site']->id, (int) $journey['concern']->fresh()->site_id);
        $this->assertSame($journey['site']->id, (int) $journey['event']->fresh()->site_id);
        $this->assertNull($journey['alert']->fresh()->site_id);
    }

    /**
     * @return array{site: Site, concern: SafeguardingConcern, event: HsEvent, alert: ControlRoomAlert}
     */
    private function createUnscopedStaffJourney(): array
    {
        $site = Site::factory()->create();
        $subject = User::factory()->create();
        HrEmployeeProfile::factory()->create([
            'user_id' => $subject->id,
            'primary_site_id' => $site->id,
            'is_active' => true,
            'deleted_at' => null,
        ]);
        $concern = SafeguardingConcern::withoutEvents(
            fn () => SafeguardingConcern::factory()->create([
                'reference_number' => $this->nextConcernReference(),
                'subject_type' => User::class,
                'subject_id' => $subject->id,
                'site_id' => null,
            ]),
        );
        $this->emulateLegacyNullStorage('safeguarding_concerns', $concern->id);

        $alert = ControlRoomAlert::factory()->create([
            'source' => 'safeguarding',
            'site_id' => null,
            'client_id' => null,
        ]);
        $event = HsEvent::factory()->create([
            'site_id' => null,
            'client_id' => null,
            'source_type' => SafeguardingConcern::class,
            'source_id' => $concern->id,
            'control_room_alert_id' => $alert->id,
        ]);
        $this->emulateLegacyNullStorage('hs_events', $event->id);

        return compact('site', 'concern', 'event', 'alert');
    }

    private function emulateLegacyNullStorage(string $table, int $id): void
    {
        DB::table($table)
            ->where('id', $id)
            ->update(['organization_id' => null]);
    }

    private function nextConcernReference(): string
    {
        return sprintf('SG-LEGACY-COMPAT-%04d', ++$this->concernReferenceSequence);
    }

    private function organizationProvenanceMigration(): object
    {
        $path = database_path(
            'migrations/2026_07_17_000100_backfill_hs_event_organization_provenance.php',
        );
        $this->assertFileExists($path);

        return require $path;
    }

    private function historicalSiteCompatibilityMigration(): object
    {
        $path = database_path(
            'migrations/2026_07_17_000200_backfill_same_tenant_hs_organization.php',
        );
        $this->assertFileExists($path);

        return require $path;
    }
}
