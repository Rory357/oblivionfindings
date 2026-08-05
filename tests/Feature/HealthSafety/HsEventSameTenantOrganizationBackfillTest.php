<?php

namespace Tests\Feature\HealthSafety;

use App\Models\Client;
use App\Models\HsEvent;
use App\Models\SafeguardingConcern;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HsEventSameTenantOrganizationBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_backfills_tenant_ownership_without_rewriting_a_historical_event_site(): void
    {
        $historicalSite = Site::factory()->create(['tenant_id' => 201]);
        $currentSite = Site::factory()->create(['tenant_id' => 201]);
        $client = Client::factory()->create([
            'organization_id' => 201,
            'site_id' => $currentSite->id,
        ]);
        $event = HsEvent::factory()->create([
            'organization_id' => null,
            'site_id' => $historicalSite->id,
            'client_id' => $client->id,
        ]);

        $this->migration()->up();

        $event->refresh();
        $this->assertSame(201, (int) $event->organization_id);
        $this->assertSame($historicalSite->id, (int) $event->site_id);
        $this->assertSame($client->id, (int) $event->client_id);

        $this->migration()->down();

        $this->assertSame(201, (int) $event->fresh()->organization_id);
    }

    public function test_it_leaves_a_cross_tenant_event_tuple_untouched(): void
    {
        $eventSite = Site::factory()->create(['tenant_id' => 202]);
        $clientSite = Site::factory()->create(['tenant_id' => 203]);
        $client = Client::factory()->create([
            'organization_id' => 203,
            'site_id' => $clientSite->id,
        ]);
        $event = HsEvent::factory()->create([
            'organization_id' => null,
            'site_id' => $eventSite->id,
            'client_id' => $client->id,
        ]);

        $this->migration()->up();

        $this->assertNull($event->fresh()->organization_id);
        $this->assertSame($eventSite->id, (int) $event->fresh()->site_id);
        $this->assertSame($client->id, (int) $event->fresh()->client_id);
    }

    public function test_it_backfills_a_safeguarding_concern_when_site_and_client_subject_share_one_tenant(): void
    {
        $historicalSite = Site::factory()->create(['tenant_id' => 204]);
        $currentSite = Site::factory()->create(['tenant_id' => 204]);
        $client = Client::factory()->create([
            'organization_id' => 204,
            'site_id' => $currentSite->id,
        ]);
        $concern = SafeguardingConcern::withoutEvents(
            fn () => SafeguardingConcern::factory()->create([
                'reference_number' => 'SG-SAME-TENANT-SUBJECT',
                'subject_type' => Client::class,
                'subject_id' => $client->id,
                'site_id' => $historicalSite->id,
                'organization_id' => null,
            ]),
        );

        $this->migration()->up();

        $concern->refresh();
        $this->assertSame(204, (int) $concern->organization_id);
        $this->assertSame($historicalSite->id, (int) $concern->site_id);
        $this->assertSame($client->id, (int) $concern->subject_id);

        $this->migration()->down();

        $this->assertSame(204, (int) $concern->fresh()->organization_id);
    }

    public function test_it_leaves_a_cross_tenant_safeguarding_subject_untouched(): void
    {
        $concernSite = Site::factory()->create(['tenant_id' => 205]);
        $clientSite = Site::factory()->create(['tenant_id' => 206]);
        $client = Client::factory()->create([
            'organization_id' => 206,
            'site_id' => $clientSite->id,
        ]);
        $concern = SafeguardingConcern::withoutEvents(
            fn () => SafeguardingConcern::factory()->create([
                'reference_number' => 'SG-CROSS-TENANT-SUBJECT',
                'subject_type' => Client::class,
                'subject_id' => $client->id,
                'site_id' => $concernSite->id,
                'organization_id' => null,
            ]),
        );

        $this->migration()->up();

        $this->assertNull($concern->fresh()->organization_id);
        $this->assertSame($concernSite->id, (int) $concern->fresh()->site_id);
        $this->assertSame($client->id, (int) $concern->fresh()->subject_id);
    }

    public function test_it_leaves_a_missing_safeguarding_client_subject_untouched(): void
    {
        $concernSite = Site::factory()->create(['tenant_id' => 207]);
        $missingClientId = 9_999_999;
        $concern = SafeguardingConcern::withoutEvents(
            fn () => SafeguardingConcern::factory()->create([
                'reference_number' => 'SG-MISSING-CLIENT-SUBJECT',
                'subject_type' => Client::class,
                'subject_id' => $missingClientId,
                'site_id' => $concernSite->id,
                'organization_id' => null,
            ]),
        );

        $this->migration()->up();

        $this->assertNull($concern->fresh()->organization_id);
        $this->assertSame($concernSite->id, (int) $concern->fresh()->site_id);
        $this->assertSame($missingClientId, (int) $concern->fresh()->subject_id);
    }

    private function migration(): object
    {
        $path = database_path(
            'migrations/2026_07_17_000200_backfill_same_tenant_hs_organization.php',
        );
        $this->assertFileExists($path);

        return require $path;
    }
}
