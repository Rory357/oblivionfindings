<?php

namespace Tests\Feature\Incidents;

use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\HsEvent;
use App\Models\Site;
use App\Services\Incidents\IncidentRegisterReadService;
use App\Support\HealthSafety\CurrentRecords;
use Illuminate\Http\Request;

/**
 * Lists hide archived Sites and removed clients by default (MASTER for Stephan, 30 Oct). `?records=all` shows them,
 * and a hidden incident's own record still opens.
 */
final class IncidentRegisterCurrentRecordsTest extends IncidentRoutingTestCase
{
    private function read(array $filters = []): array
    {
        $request = Request::create('/incidents', 'GET', $filters);
        $request->setUserResolver(fn () => $this->manager);

        // The detail consumer stands in for the controller's dialog builder: it only proves the record is reachable.
        return app(IncidentRegisterReadService::class)->index($request, fn ($current, int $id) => ['id' => $id]);
    }

    private function ids(array $dto): array
    {
        return array_column($dto['rows']['data'], 'id');
    }

    public function test_an_incident_about_a_removed_client_is_hidden_until_records_all(): void
    {
        $current = $this->incident();
        $removed = $this->incident();
        Client::query()->whereKey($removed->client_id)->firstOrFail()->delete();

        $default = $this->read();
        $this->assertSame([$current->id], $this->ids($default));
        $this->assertSame(1, $default['tabCounts']['all']);
        $this->assertSame('current', $default['filters']['records']);

        $all = $this->read(['records' => 'all']);
        $this->assertEqualsCanonicalizing([$current->id, $removed->id], $this->ids($all));
        $this->assertSame('all', $all['filters']['records']);

        // The hidden incident's own record still opens over the register.
        $this->assertSame(['id' => $removed->id], $this->read(['incident' => $removed->id])['detail']);
    }

    public function test_the_shared_rule_drops_archived_and_deleted_sites_and_clients_living_there(): void
    {
        $archived = Site::factory()->create(['is_active' => false, 'archived' => true, 'archived_at' => now()]);
        $deleted = Site::factory()->create();
        $deleted->delete();
        $kept = $this->incident();
        $atArchived = $this->incident(['site_id' => $archived->id]);
        $atDeleted = $this->incident(['site_id' => $deleted->id]);
        $clientAtArchived = $this->incident(['client_id' => Client::factory()->create(['site_id' => $archived->id])->id]);
        $noSite = $this->incident(['site_id' => null]);

        $ids = CurrentRecords::onCurrentClients(
            CurrentRecords::onCurrentSites(ClientIncident::query(), 'client_incidents.site_id'),
            'client_incidents.client_id',
        )->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$kept->id, $noSite->id], $ids);
        $this->assertNotContains($atArchived->id, $ids);
        $this->assertNotContains($atDeleted->id, $ids);
        $this->assertNotContains($clientAtArchived->id, $ids);
    }

    public function test_the_hs_events_list_hides_removed_clients_until_records_all(): void
    {
        $this->allow($this->manager, 'hazards.view');
        $removedClient = Client::factory()->create(['site_id' => $this->site->id]);
        $event = HsEvent::factory()->create(['site_id' => $this->site->id, 'client_id' => $removedClient->id]);
        $removedClient->delete();

        $this->actingAs($this->manager->fresh())
            ->get('/health-safety/events')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('filters.records', 'current')
                ->where('events.data', fn ($rows) => collect($rows)->pluck('id')->doesntContain($event->id)));

        $this->get('/health-safety/events?records=all')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('filters.records', 'all')
                ->where('events.data', fn ($rows) => collect($rows)->pluck('id')->contains($event->id)));
    }
}
