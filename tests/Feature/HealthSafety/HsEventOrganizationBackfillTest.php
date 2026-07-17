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

class HsEventOrganizationBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_adds_the_missing_safeguarding_organization_column_without_a_phantom_tenant_table(): void
    {
        $this->assertFalse(Schema::hasTable('organizations'));

        if (Schema::hasColumn('safeguarding_concerns', 'organization_id')) {
            Schema::table('safeguarding_concerns', function ($table): void {
                $table->dropColumn('organization_id');
            });
        }

        $this->assertFalse(Schema::hasColumn('safeguarding_concerns', 'organization_id'));

        $this->migration()->up();

        $this->assertTrue(Schema::hasColumn('safeguarding_concerns', 'organization_id'));

        $column = collect(Schema::getColumns('safeguarding_concerns'))
            ->firstWhere('name', 'organization_id');
        $index = collect(Schema::getIndexes('safeguarding_concerns'))
            ->firstWhere('name', 'safeguarding_concerns_organization_id_index');

        $this->assertNotNull($column);
        $this->assertTrue((bool) ($column['nullable'] ?? false));
        $typeName = strtolower((string) ($column['type_name'] ?? ''));
        $type = strtolower((string) ($column['type'] ?? ''));
        if (DB::getDriverName() === 'sqlite') {
            $this->assertSame('integer', $typeName);
        } else {
            $this->assertSame('bigint', $typeName);
            $this->assertStringContainsString('unsigned', $type);
        }
        $this->assertNotNull($index);
        $this->assertFalse((bool) ($index['unique'] ?? true));
        $this->assertSame(['organization_id'], $index['columns'] ?? []);
    }

    public function test_it_backfills_only_unambiguous_site_and_client_tenant_provenance(): void
    {
        $site = Site::factory()->create(['tenant_id' => 41]);
        $client = Client::factory()->create([
            'organization_id' => 41,
            'site_id' => $site->id,
        ]);
        $mismatchedClient = Client::factory()->create([
            'organization_id' => 42,
            'site_id' => Site::factory()->create(['tenant_id' => 42])->id,
        ]);

        $repairable = HsEvent::factory()->create([
            'organization_id' => null,
            'site_id' => $site->id,
            'client_id' => $client->id,
        ]);
        $siteOnly = HsEvent::factory()->create([
            'organization_id' => null,
            'site_id' => $site->id,
            'client_id' => null,
        ]);
        $conflicting = HsEvent::factory()->create([
            'organization_id' => null,
            'site_id' => $site->id,
            'client_id' => $mismatchedClient->id,
        ]);
        $unscoped = HsEvent::factory()->create([
            'organization_id' => null,
            'site_id' => null,
            'client_id' => null,
        ]);

        $this->migration()->up();

        $this->assertSame(41, (int) $repairable->fresh()->organization_id);
        $this->assertSame(41, (int) $siteOnly->fresh()->organization_id);
        $this->assertNull($conflicting->fresh()->organization_id);
        $this->assertNull($unscoped->fresh()->organization_id);

        $this->migration()->down();

        $this->assertSame(41, (int) $repairable->fresh()->organization_id);
        $this->assertSame(41, (int) $siteOnly->fresh()->organization_id);
    }

    public function test_it_repairs_an_unscoped_legacy_safeguarding_tuple_from_one_active_subject_site(): void
    {
        $site = Site::factory()->create(['tenant_id' => 51]);
        $subject = User::factory()->create(['organization_id' => 51]);
        HrEmployeeProfile::factory()->create([
            'tenant_id' => 51,
            'user_id' => $subject->id,
            'primary_site_id' => $site->id,
            'is_active' => true,
            'deleted_at' => null,
        ]);
        $concern = SafeguardingConcern::withoutEvents(
            fn () => SafeguardingConcern::factory()->create([
                'reference_number' => 'SG-LEGACY-PROVENANCE-REPAIR',
                'subject_type' => User::class,
                'subject_id' => $subject->id,
                'site_id' => null,
                'organization_id' => null,
            ]),
        );
        $alert = ControlRoomAlert::factory()->create([
            'source' => 'safeguarding',
            'site_id' => null,
            'client_id' => null,
        ]);
        $event = HsEvent::factory()->create([
            'organization_id' => null,
            'site_id' => null,
            'client_id' => null,
            'source_type' => SafeguardingConcern::class,
            'source_id' => $concern->id,
            'control_room_alert_id' => $alert->id,
        ]);

        $this->migration()->up();

        $this->assertSame(51, (int) $concern->fresh()->organization_id);
        $this->assertSame($site->id, (int) $concern->fresh()->site_id);
        $this->assertSame($site->id, (int) $alert->fresh()->site_id);
        $this->assertSame(51, (int) $event->fresh()->organization_id);
        $this->assertSame($site->id, (int) $event->fresh()->site_id);
    }

    public function test_it_leaves_ambiguous_safeguarding_provenance_untouched(): void
    {
        $site = Site::factory()->create(['tenant_id' => 61]);
        $subject = User::factory()->create(['organization_id' => 62]);
        HrEmployeeProfile::factory()->create([
            'tenant_id' => 62,
            'user_id' => $subject->id,
            'primary_site_id' => $site->id,
            'is_active' => true,
            'deleted_at' => null,
        ]);
        $concern = SafeguardingConcern::withoutEvents(
            fn () => SafeguardingConcern::factory()->create([
                'reference_number' => 'SG-AMBIGUOUS-PROVENANCE',
                'subject_type' => User::class,
                'subject_id' => $subject->id,
                'site_id' => null,
                'organization_id' => null,
            ]),
        );
        $alert = ControlRoomAlert::factory()->create([
            'source' => 'safeguarding',
            'site_id' => null,
            'client_id' => null,
        ]);
        $event = HsEvent::factory()->create([
            'organization_id' => null,
            'site_id' => null,
            'client_id' => null,
            'source_type' => SafeguardingConcern::class,
            'source_id' => $concern->id,
            'control_room_alert_id' => $alert->id,
        ]);

        $this->migration()->up();

        $this->assertNull($concern->fresh()->organization_id);
        $this->assertNull($concern->fresh()->site_id);
        $this->assertNull($alert->fresh()->site_id);
        $this->assertNull($event->fresh()->organization_id);
        $this->assertNull($event->fresh()->site_id);
    }

    public function test_it_rejects_an_hr_profile_from_a_different_tenant(): void
    {
        $site = Site::factory()->create(['tenant_id' => 71]);
        $subject = User::factory()->create(['organization_id' => 71]);
        HrEmployeeProfile::factory()->create([
            'tenant_id' => 72,
            'user_id' => $subject->id,
            'primary_site_id' => $site->id,
            'is_active' => true,
            'deleted_at' => null,
        ]);
        $concern = SafeguardingConcern::withoutEvents(
            fn () => SafeguardingConcern::factory()->create([
                'reference_number' => 'SG-PROFILE-TENANT-CONFLICT',
                'subject_type' => User::class,
                'subject_id' => $subject->id,
                'site_id' => null,
                'organization_id' => null,
            ]),
        );
        $alert = ControlRoomAlert::factory()->create([
            'source' => 'safeguarding',
            'site_id' => null,
            'client_id' => null,
        ]);
        $event = HsEvent::factory()->create([
            'organization_id' => null,
            'site_id' => null,
            'client_id' => null,
            'source_type' => SafeguardingConcern::class,
            'source_id' => $concern->id,
            'control_room_alert_id' => $alert->id,
        ]);

        $this->migration()->up();

        $this->assertNull($concern->fresh()->organization_id);
        $this->assertNull($concern->fresh()->site_id);
        $this->assertNull($alert->fresh()->site_id);
        $this->assertNull($event->fresh()->organization_id);
        $this->assertNull($event->fresh()->site_id);
    }

    public function test_it_never_assigns_an_alert_site_when_another_hs_event_claims_a_different_tenant(): void
    {
        foreach (['repairable-first', 'conflict-first'] as $creationOrder) {
            $site = Site::factory()->create(['tenant_id' => 81]);
            $conflictingSite = Site::factory()->create(['tenant_id' => 82]);
            $subject = User::factory()->create(['organization_id' => 81]);
            HrEmployeeProfile::factory()->create([
                'tenant_id' => 81,
                'user_id' => $subject->id,
                'primary_site_id' => $site->id,
                'is_active' => true,
                'deleted_at' => null,
            ]);
            $concern = SafeguardingConcern::withoutEvents(
                fn () => SafeguardingConcern::factory()->create([
                    'reference_number' => "SG-ALERT-CONFLICT-{$creationOrder}",
                    'subject_type' => User::class,
                    'subject_id' => $subject->id,
                    'site_id' => null,
                    'organization_id' => null,
                ]),
            );
            $alert = ControlRoomAlert::factory()->create([
                'source' => 'safeguarding',
                'site_id' => null,
                'client_id' => null,
            ]);

            $createRepairable = fn () => HsEvent::factory()->create([
                'organization_id' => null,
                'site_id' => null,
                'client_id' => null,
                'source_type' => SafeguardingConcern::class,
                'source_id' => $concern->id,
                'control_room_alert_id' => $alert->id,
            ]);
            $createConflict = fn () => HsEvent::factory()->create([
                'organization_id' => 82,
                'site_id' => $conflictingSite->id,
                'client_id' => null,
                'control_room_alert_id' => $alert->id,
            ]);

            if ($creationOrder === 'repairable-first') {
                $repairable = $createRepairable();
                $createConflict();
            } else {
                $createConflict();
                $repairable = $createRepairable();
            }

            $this->migration()->up();

            $this->assertSame(81, (int) $concern->fresh()->organization_id);
            $this->assertSame($site->id, (int) $concern->fresh()->site_id);
            $this->assertSame(81, (int) $repairable->fresh()->organization_id);
            $this->assertSame($site->id, (int) $repairable->fresh()->site_id);
            $this->assertNull($alert->fresh()->site_id);
        }
    }

    public function test_it_rejects_a_safeguarding_source_with_an_existing_foreign_hs_claimant(): void
    {
        $site = Site::factory()->create(['tenant_id' => 91]);
        $foreignSite = Site::factory()->create(['tenant_id' => 92]);
        $subject = User::factory()->create(['organization_id' => 91]);
        HrEmployeeProfile::factory()->create([
            'tenant_id' => 91,
            'user_id' => $subject->id,
            'primary_site_id' => $site->id,
            'is_active' => true,
            'deleted_at' => null,
        ]);
        $concern = SafeguardingConcern::withoutEvents(
            fn () => SafeguardingConcern::factory()->create([
                'reference_number' => 'SG-FOREIGN-HS-CLAIMANT',
                'subject_type' => User::class,
                'subject_id' => $subject->id,
                'site_id' => null,
                'organization_id' => null,
            ]),
        );
        $alert = ControlRoomAlert::factory()->create([
            'source' => 'safeguarding',
            'site_id' => null,
            'client_id' => null,
        ]);
        $repairable = HsEvent::factory()->create([
            'organization_id' => null,
            'site_id' => null,
            'client_id' => null,
            'source_type' => SafeguardingConcern::class,
            'source_id' => $concern->id,
            'control_room_alert_id' => $alert->id,
        ]);
        $foreign = HsEvent::factory()->create([
            'organization_id' => 92,
            'site_id' => $foreignSite->id,
            'client_id' => null,
            'source_type' => SafeguardingConcern::class,
            'source_id' => $concern->id,
        ]);

        $this->migration()->up();

        $this->assertNull($concern->fresh()->organization_id);
        $this->assertNull($concern->fresh()->site_id);
        $this->assertNull($repairable->fresh()->organization_id);
        $this->assertNull($repairable->fresh()->site_id);
        $this->assertSame(92, (int) $foreign->fresh()->organization_id);
        $this->assertSame($foreignSite->id, (int) $foreign->fresh()->site_id);
        $this->assertNull($alert->fresh()->site_id);
    }

    public function test_it_never_assigns_a_safeguarding_alert_site_when_a_direct_incident_already_claims_it(): void
    {
        $site = Site::factory()->create(['tenant_id' => 101]);
        $foreignSite = Site::factory()->create(['tenant_id' => 102]);
        $foreignClient = Client::factory()->create([
            'organization_id' => 102,
            'site_id' => $foreignSite->id,
        ]);
        $subject = User::factory()->create(['organization_id' => 101]);
        HrEmployeeProfile::factory()->create([
            'tenant_id' => 101,
            'user_id' => $subject->id,
            'primary_site_id' => $site->id,
            'is_active' => true,
            'deleted_at' => null,
        ]);
        $concern = SafeguardingConcern::withoutEvents(
            fn () => SafeguardingConcern::factory()->create([
                'reference_number' => 'SG-FOREIGN-DIRECT-INCIDENT',
                'subject_type' => User::class,
                'subject_id' => $subject->id,
                'site_id' => null,
                'organization_id' => null,
            ]),
        );
        $alert = ControlRoomAlert::factory()->create([
            'source' => 'safeguarding',
            'site_id' => null,
            'client_id' => null,
        ]);
        $event = HsEvent::factory()->create([
            'organization_id' => null,
            'site_id' => null,
            'client_id' => null,
            'source_type' => SafeguardingConcern::class,
            'source_id' => $concern->id,
            'control_room_alert_id' => $alert->id,
        ]);
        ClientIncident::factory()->create([
            'client_id' => $foreignClient->id,
            'site_id' => $foreignSite->id,
            'control_room_alert_id' => $alert->id,
        ]);

        $this->migration()->up();

        $this->assertSame(101, (int) $concern->fresh()->organization_id);
        $this->assertSame($site->id, (int) $concern->fresh()->site_id);
        $this->assertSame(101, (int) $event->fresh()->organization_id);
        $this->assertSame($site->id, (int) $event->fresh()->site_id);
        $this->assertNull($alert->fresh()->site_id);
    }

    public function test_it_never_assigns_an_alert_site_when_context_identifies_a_foreign_incident(): void
    {
        $site = Site::factory()->create(['tenant_id' => 111]);
        $foreignSite = Site::factory()->create(['tenant_id' => 112]);
        $foreignClient = Client::factory()->create([
            'organization_id' => 112,
            'site_id' => $foreignSite->id,
        ]);
        $foreignIncident = ClientIncident::factory()->create([
            'client_id' => $foreignClient->id,
            'site_id' => $foreignSite->id,
        ]);
        $subject = User::factory()->create(['organization_id' => 111]);
        HrEmployeeProfile::factory()->create([
            'tenant_id' => 111,
            'user_id' => $subject->id,
            'primary_site_id' => $site->id,
            'is_active' => true,
            'deleted_at' => null,
        ]);
        $concern = SafeguardingConcern::withoutEvents(
            fn () => SafeguardingConcern::factory()->create([
                'reference_number' => 'SG-FOREIGN-CONTEXT-INCIDENT',
                'subject_type' => User::class,
                'subject_id' => $subject->id,
                'site_id' => null,
                'organization_id' => null,
            ]),
        );
        $alert = ControlRoomAlert::factory()->create([
            'source' => 'safeguarding',
            'site_id' => null,
            'client_id' => null,
            'context' => ['incident_id' => $foreignIncident->id],
        ]);
        $event = HsEvent::factory()->create([
            'organization_id' => null,
            'site_id' => null,
            'client_id' => null,
            'source_type' => SafeguardingConcern::class,
            'source_id' => $concern->id,
            'control_room_alert_id' => $alert->id,
        ]);

        $this->migration()->up();

        $this->assertSame(111, (int) $concern->fresh()->organization_id);
        $this->assertSame($site->id, (int) $concern->fresh()->site_id);
        $this->assertSame(111, (int) $event->fresh()->organization_id);
        $this->assertSame($site->id, (int) $event->fresh()->site_id);
        $this->assertNull($alert->fresh()->site_id);
    }

    public function test_it_never_assigns_an_alert_site_when_an_incident_claims_a_linked_hs_event(): void
    {
        $site = Site::factory()->create(['tenant_id' => 121]);
        $foreignSite = Site::factory()->create(['tenant_id' => 122]);
        $foreignClient = Client::factory()->create([
            'organization_id' => 122,
            'site_id' => $foreignSite->id,
        ]);
        $subject = User::factory()->create(['organization_id' => 121]);
        HrEmployeeProfile::factory()->create([
            'tenant_id' => 121,
            'user_id' => $subject->id,
            'primary_site_id' => $site->id,
            'is_active' => true,
            'deleted_at' => null,
        ]);
        $concern = SafeguardingConcern::withoutEvents(
            fn () => SafeguardingConcern::factory()->create([
                'reference_number' => 'SG-FOREIGN-HS-INCIDENT',
                'subject_type' => User::class,
                'subject_id' => $subject->id,
                'site_id' => null,
                'organization_id' => null,
            ]),
        );
        $alert = ControlRoomAlert::factory()->create([
            'source' => 'safeguarding',
            'site_id' => null,
            'client_id' => null,
        ]);
        $event = HsEvent::factory()->create([
            'organization_id' => null,
            'site_id' => null,
            'client_id' => null,
            'source_type' => SafeguardingConcern::class,
            'source_id' => $concern->id,
            'control_room_alert_id' => $alert->id,
        ]);
        ClientIncident::factory()->create([
            'client_id' => $foreignClient->id,
            'site_id' => $foreignSite->id,
            'hs_event_id' => $event->id,
            'control_room_alert_id' => null,
        ]);

        $this->migration()->up();

        $this->assertSame(121, (int) $concern->fresh()->organization_id);
        $this->assertSame($site->id, (int) $concern->fresh()->site_id);
        $this->assertSame(121, (int) $event->fresh()->organization_id);
        $this->assertSame($site->id, (int) $event->fresh()->site_id);
        $this->assertNull($alert->fresh()->site_id);
    }

    private function migration(): object
    {
        $path = database_path(
            'migrations/2026_07_17_000100_backfill_hs_event_organization_provenance.php',
        );
        $this->assertFileExists($path);

        return require $path;
    }
}
