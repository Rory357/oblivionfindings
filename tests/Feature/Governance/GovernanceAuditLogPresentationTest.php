<?php

namespace Tests\Feature\Governance;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\GovernanceTestHelpers;
use Tests\TestCase;

/**
 * The audit log reads as sentences, links only records the viewer can open,
 * and never sends details (or secrets) the viewer isn't allowed to see.
 */
class GovernanceAuditLogPresentationTest extends TestCase
{
    use GovernanceTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedGovernance();
    }

    private function logAction(?int $userId, string $action, string $type, int $id, ?array $metadata = null, string $ip = '203.0.113.5'): void
    {
        DB::table('governance_audit_log')->insert([
            'user_id' => $userId,
            'action' => $action,
            'resource_type' => $type,
            'resource_id' => $id,
            'metadata' => $metadata ? json_encode($metadata) : null,
            'ip_address' => $ip,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_entries_read_as_sentences_that_link_to_records_the_viewer_can_open(): void
    {
        $admin = $this->createAdminUser();
        $voter = $this->createUserWithRole('board_member', ['name' => 'Jane Smith', 'email' => 'jane.smith@example.test']);
        $resolution = $this->createResolution($admin, ['title' => 'Approve 2026/27 budget']);

        $this->logAction($voter->id, 'resolution.voted', 'Resolution', $resolution->id, [
            'vote' => 'for',
            'board_member_id' => 44,
            'has_vote_note' => false,
            'api_token' => 'tok_live_should_never_show',
        ]);

        $response = $this->actingAs($admin)->get('/governance/audit-log');

        $response->assertInertia(fn ($page) => $page
            ->component('Governance/AuditLog/Index')
            ->where('entries.data.0.sentence', 'Jane Smith voted on “Approve 2026/27 budget”')
            ->where('entries.data.0.record_type', 'Resolution')
            ->where('entries.data.0.record_url', "/governance/resolutions/{$resolution->id}")
            ->where('entries.data.0.can_see_details', true)
            ->where('entries.data.0.ip_address', '203.0.113.5')
            ->where('entries.data.0.details', fn ($details) => collect($details)->contains(fn ($d) => $d['label'] === 'Vote' && $d['value'] === 'For')
                && ! collect($details)->contains(fn ($d) => str_contains($d['label'], 'Board member')))
            ->where('recordTypeOptions', fn ($options) => collect($options)->contains(fn ($o) => $o['value'] === 'Resolution' && $o['label'] === 'Resolution'))
            ->where('activityOptions', fn ($options) => collect($options)->contains(fn ($o) => $o['value'] === 'resolution.voted' && $o['label'] === 'Voted on a resolution')));

        $json = json_encode($response->viewData('page')['props']);
        $this->assertStringNotContainsString('tok_live_should_never_show', $json);
        $this->assertStringNotContainsString('jane.smith@example.test', $json);
    }

    public function test_records_the_viewer_cannot_open_show_no_title_link_or_details(): void
    {
        $admin = $this->createAdminUser();
        $secretary = $this->createUserWithRole('board_secretary');
        $this->assertTrue($secretary->canDo('governance.audit.view'));

        $privateMeeting = $this->createMeeting($admin, ['meeting_type' => 'executive_session']);
        $resolution = $this->createResolution($admin, [
            'title' => 'Chief executive remuneration',
            'governance_meeting_id' => $privateMeeting->id,
        ]);
        $this->logAction($admin->id, 'resolution.voted', 'Resolution', $resolution->id, ['vote' => 'against']);
        $this->logAction(null, 'viewed', 'App\\Models\\SafeguardingConcern', 7, ['restricted' => true, 'summary' => 'Sensitive safeguarding summary']);

        $response = $this->actingAs($secretary)->get('/governance/audit-log');

        $response->assertInertia(fn ($page) => $page
            ->has('entries.data', 2)
            ->where('entries.data', fn ($entries) => collect($entries)->every(fn ($entry) => $entry['record_url'] === null
                && $entry['record_title'] === null
                && $entry['can_see_details'] === false
                && $entry['metadata'] === null
                && $entry['details'] === [])));

        $json = json_encode($response->viewData('page')['props'], JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('Chief executive remuneration', $json);
        $this->assertStringNotContainsString('Sensitive safeguarding summary', $json);
        $this->assertStringContainsString('voted on a resolution', $json);
        $this->assertStringContainsString('Oblivion Care viewed a safeguarding concern', $json);
    }

    public function test_change_rows_show_old_and_new_values_and_the_activity_filter_keeps_to_one_kind(): void
    {
        $admin = $this->createAdminUser();
        $resolution = $this->createResolution($admin, ['title' => 'Adopt the new delegations']);

        DB::table('governance_change_log')->insert([
            'change_type' => 'updated',
            'entity_type' => 'Resolution',
            'entity_id' => $resolution->id,
            'user_id' => $admin->id,
            'description' => 'Status changed',
            'old_values' => json_encode(['status' => 'draft', 'updated_at' => '2026-09-01 00:00:00']),
            'new_values' => json_encode(['status' => 'open', 'updated_at' => '2026-09-02 00:00:00']),
            'ip_address' => '203.0.113.9',
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);
        $this->logAction($admin->id, 'resolution.finalized', 'Resolution', $resolution->id, ['status' => 'carried']);

        $this->actingAs($admin)
            ->get('/governance/audit-log')
            ->assertInertia(fn ($page) => $page
                ->has('entries.data', 2)
                ->where('entries.data.1.kind', 'change')
                ->where('entries.data.1.sentence', "{$admin->name} updated “Adopt the new delegations”")
                ->where('entries.data.1.changes', [['label' => 'Status', 'from' => 'Draft', 'to' => 'Open']]));

        $this->actingAs($admin)
            ->get('/governance/audit-log?action=resolution.finalized')
            ->assertInertia(fn ($page) => $page
                ->has('entries.data', 1)
                ->where('entries.data.0.kind', 'action')
                ->where('filters.action', 'resolution.finalized'));
    }

    public function test_the_csv_uses_names_and_sentences_not_ids(): void
    {
        $admin = $this->createAdminUser(['name' => 'Aroha Admin']);
        $resolution = $this->createResolution($admin, ['title' => 'Approve the risk appetite']);
        $this->logAction($admin->id, 'resolution.finalized', 'Resolution', $resolution->id, ['status' => 'carried']);

        $csv = $this->actingAs($admin)->get('/governance/audit-log/export')->assertOk()->streamedContent();

        $this->assertStringContainsString('When,Who,"What happened","Record type",Record,"What changed","IP address"', $csv);
        $this->assertStringContainsString('Aroha Admin', $csv);
        $this->assertStringContainsString('Aroha Admin finalised “Approve the risk appetite”', $csv);
        $this->assertStringContainsString('Status: Carried', $csv);
        $this->assertStringNotContainsString('UserId', $csv);
        $this->assertStringNotContainsString('EntityType', $csv);
    }
}
