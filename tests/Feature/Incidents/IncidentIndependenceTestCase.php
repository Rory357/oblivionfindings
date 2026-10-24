<?php

namespace Tests\Feature\Incidents;

use App\Models\Client;
use App\Models\ClientIncident;
use App\Services\Incidents\IncidentIndependenceIntakeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

abstract class IncidentIndependenceTestCase extends IncidentRoutingTestCase
{
    protected Client $client;
    protected function setUp(): void
    {
        parent::setUp();
        $this->allow($this->reporter, 'clients.viewAny');
        $this->allow($this->reporter, 'incidents.viewAny', false); $this->allow($this->reporter, 'incidents.viewAssigned', false);
        $this->client = Client::factory()->create(['site_id' => $this->site->id]);
    }
    protected function context(): array { return ['client_id' => (int) $this->client->id, 'site_id' => (int) $this->site->id, 'shift_id' => null]; }
    protected function command(array $overrides = []): array
    {
        return ['request_uuid' => (string) Str::uuid(), 'report_request_uuid' => (string) Str::uuid(), 'expected_revision' => 0,
            'expected_policy_revision' => 0, 'intent' => 'submit', 'capture_context' => $this->context(),
            'independent_handling' => ['requested' => true, 'reason_code' => 'usual_manager_involved', 'identification' => 'named', 'involved_user_ids' => [(int) $this->lead->id]], ...$overrides];
    }
    protected function accept(array $command): array
    {
        return app(IncidentIndependenceIntakeService::class)->intake($this->reporter, $command, function () use ($command): int {
            $root = ClientIncident::where('report_request_uuid', $command['report_request_uuid'])->first();
            if ($root) {
                ClientIncident::withoutEvents(function () use ($root, $command): void { $root->status = $command['intent'] === 'draft' ? 'draft' : 'submitted'; $root->saveOrFail(); });
            } else {
                $root = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->create(['client_id' => $this->client->id, 'site_id' => $this->site->id,
                    'reported_by' => $this->reporter->id, 'shift_id' => null, 'source' => 'manual', 'report_request_uuid' => $command['report_request_uuid'],
                    'occurred_at' => now(), 'type' => 'injury', // Faker's date and type would make period/kind counts random.
                    'status' => $command['intent'] === 'draft' ? 'draft' : 'submitted', 'description' => 'Synthetic private source text', 'portal_visible' => false]));
            }
            return (int) $root->id;
        });
    }

    /**
     * A retained declaration on a report that already has submitted work (owner, deliveries, saved copies).
     * The intake admits a declaration only while its report is a draft, and the separate review for submitted
     * reports is not built, so the report returns to draft for this one real intake call (which resubmits it)
     * and then gets its prior status back. Proof, exclusions, receipt and audit all come from the real intake.
     */
    protected function acceptForExisting(ClientIncident $root, array $command): array
    {
        $status = DB::table('client_incidents')->where('id', $root->id)->value('status');
        DB::table('client_incidents')->where('id', $root->id)->update(['status' => 'draft']);
        $receipt = $this->accept(['report_request_uuid' => $root->report_request_uuid, ...$command]);
        DB::table('client_incidents')->where('id', $root->id)->update(['status' => $status]);
        return $receipt;
    }
}
