<?php

namespace Tests\Feature\Incidents;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Jobs\PruneIncidentReportDrafts;
use App\Models\Client;
use App\Models\IncidentReportDraft;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\NotificationService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class IncidentReportDraftRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private User $admin;

    private Site $site;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->admin = $this->userWithRole('admin');
        $this->actor = $this->userWithRole('support_worker');
        $this->site = Site::factory()->create();
        $this->client = Client::factory()->create(['site_id' => $this->site->id]);
        $this->client->supportWorkers()->attach($this->actor->id);
        HrEmployeeProfile::factory()->create([
            'user_id' => $this->actor->id,
            'primary_site_id' => $this->site->id,
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);
    }

    public function test_actor_can_save_replay_and_recover_an_encrypted_revisioned_draft(): void
    {
        $requestUuid = (string) Str::uuid();
        $payload = $this->draftPayload();

        $this->actingAs($this->actor)
            ->putJson("/incidents/drafts/{$requestUuid}", $payload)
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('request_uuid', $requestUuid)
            ->assertJsonPath('revision', 1);

        $rawPayload = (string) DB::table('incident_report_drafts')
            ->where('request_uuid', $requestUuid)
            ->value('encrypted_payload');
        $this->assertStringNotContainsString(
            'Aroha slipped beside the dining table.',
            $rawPayload,
        );
        $this->assertDatabaseHas('incident_report_drafts', [
            'request_uuid' => $requestUuid,
            'user_id' => $this->actor->id,
            'site_id' => $this->site->id,
            'client_id' => $this->client->id,
            'revision' => 1,
        ]);

        // A lost acknowledgement can safely replay the same revision/payload.
        $this->actingAs($this->actor)
            ->putJson("/incidents/drafts/{$requestUuid}", $payload)
            ->assertOk()
            ->assertJsonPath('revision', 1);

        $this->actingAs($this->actor)
            ->getJson("/incidents/drafts/{$requestUuid}")
            ->assertOk()
            ->assertJsonPath('revision', 1)
            ->assertJsonPath(
                'draft.form.description',
                'Aroha slipped beside the dining table.',
            )
            ->assertJsonPath('draft.step_index', 2);

        $changed = $payload;
        $changed['form']['description'] = 'A corrected description.';
        $this->actingAs($this->actor)
            ->putJson("/incidents/drafts/{$requestUuid}", $changed)
            ->assertConflict();

        $changed['expected_revision'] = 1;
        $this->actingAs($this->actor)
            ->putJson("/incidents/drafts/{$requestUuid}", $changed)
            ->assertOk()
            ->assertJsonPath('revision', 2);

        IncidentReportDraft::query()
            ->where('request_uuid', $requestUuid)
            ->update(['expires_at' => now()->subMinute()]);
        $renewed = $changed;
        $renewed['expected_revision'] = 2;
        $renewed['form']['description'] = 'Work resumed after the recovery window elapsed.';
        $this->actingAs($this->actor)
            ->putJson("/incidents/drafts/{$requestUuid}", $renewed)
            ->assertOk()
            ->assertJsonPath('revision', 3);
        $this->actingAs($this->actor)
            ->getJson("/incidents/drafts/{$requestUuid}")
            ->assertOk()
            ->assertJsonPath(
                'draft.form.description',
                'Work resumed after the recovery window elapsed.',
            );

        IncidentReportDraft::query()
            ->where('request_uuid', $requestUuid)
            ->update(['expires_at' => now()->subMinute()]);
        $this->actingAs($this->actor)
            ->getJson("/incidents/drafts/{$requestUuid}")
            ->assertNotFound();
        $this->assertDatabaseMissing('incident_report_drafts', [
            'request_uuid' => $requestUuid,
        ]);
    }

    public function test_foreign_missing_and_zero_scope_ids_are_concealed_without_a_draft(): void
    {
        $requestUuid = (string) Str::uuid();
        $this->actingAs($this->actor)
            ->putJson("/incidents/drafts/{$requestUuid}", $this->draftPayload())
            ->assertOk();

        $foreignSite = Site::factory()->create();
        $foreignClient = Client::factory()->create(['site_id' => $foreignSite->id]);
        $foreignActor = $this->userWithRole('support_worker');
        $foreignClient->supportWorkers()->attach($foreignActor->id);
        HrEmployeeProfile::factory()->create([
            'user_id' => $foreignActor->id,
            'primary_site_id' => $foreignSite->id,
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);

        $foreign = $this->actingAs($foreignActor)
            ->getJson("/incidents/drafts/{$requestUuid}");
        $missing = $this->actingAs($foreignActor)
            ->getJson('/incidents/drafts/'.Str::uuid());
        $foreign->assertNotFound();
        $missing->assertNotFound();
        $this->assertSame($missing->getContent(), $foreign->getContent());

        $invalidForeignReplay = $this->draftPayload();
        $invalidForeignReplay['form']['description'] = str_repeat('x', 10001);
        $this->actingAs($foreignActor)
            ->putJson("/incidents/drafts/{$requestUuid}", $invalidForeignReplay)
            ->assertNotFound();

        foreach ([
            ['client_id' => (string) $foreignClient->id, 'site_id' => (string) $foreignSite->id],
            ['client_id' => '0', 'site_id' => '0'],
        ] as $scope) {
            $payload = $this->draftPayload();
            $payload['form'] = array_replace($payload['form'], $scope);
            $this->actingAs($this->actor)
                ->putJson('/incidents/drafts/'.Str::uuid(), $payload)
                ->assertNotFound();
        }

        $unscoped = $this->draftPayload();
        $unscoped['form']['client_id'] = null;
        $unscoped['form']['site_id'] = null;
        $this->actingAs($this->actor)
            ->putJson('/incidents/drafts/'.Str::uuid(), $unscoped)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['form.client_id']);

        $this->assertDatabaseCount('incident_report_drafts', 1);
    }

    public function test_discard_is_repeatable_and_final_incident_save_consumes_the_uuid(): void
    {
        $discardUuid = (string) Str::uuid();
        $this->actingAs($this->actor)
            ->putJson("/incidents/drafts/{$discardUuid}", $this->draftPayload())
            ->assertOk();
        $this->actingAs($this->actor)
            ->deleteJson("/incidents/drafts/{$discardUuid}")
            ->assertNoContent();
        $this->actingAs($this->actor)
            ->deleteJson("/incidents/drafts/{$discardUuid}")
            ->assertNoContent();

        $requestUuid = (string) Str::uuid();
        $this->actingAs($this->actor)
            ->putJson("/incidents/drafts/{$requestUuid}", $this->draftPayload())
            ->assertOk();

        $this->app->instance(
            NotificationService::class,
            \Mockery::mock(NotificationService::class)->shouldIgnoreMissing(),
        );
        $incidentPayload = [
            'intent' => 'draft',
            'report_request_uuid' => $requestUuid,
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'type' => 'fall',
            'severity' => 'low',
            'occurred_at' => now()->format('Y-m-d H:i:s'),
            'description' => 'Aroha slipped beside the dining table.',
            'immediate_action_taken' => 'Resident checked and the area made safe.',
        ];

        $this->actingAs($this->actor)
            ->post('/incidents', $incidentPayload)
            ->assertRedirect();
        $this->assertNull(
            IncidentReportDraft::query()
                ->where('request_uuid', $requestUuid)
                ->firstOrFail()
                ->consumed_at,
        );
        $sameSiteClient = Client::factory()->create(['site_id' => $this->site->id]);
        $sameSiteClient->supportWorkers()->attach($this->actor->id);
        $driftedRecovery = $this->draftPayload();
        $driftedRecovery['expected_revision'] = 1;
        $driftedRecovery['form']['client_id'] = (string) $sameSiteClient->id;
        $this->actingAs($this->actor)
            ->putJson("/incidents/drafts/{$requestUuid}", $driftedRecovery)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('form.client_id');

        $incidentPayload['intent'] = 'submit';
        $this->actingAs($this->actor)
            ->post('/incidents', $incidentPayload)
            ->assertRedirect();
        $consumed = IncidentReportDraft::query()
            ->where('request_uuid', $requestUuid)
            ->firstOrFail();
        $this->assertNotNull($consumed->consumed_at);
        $this->assertSame(2, (int) $consumed->revision);

        // The canonical incident replay stays idempotent and does not mutate
        // the consumed draft marker or permit a delayed autosave resurrection.
        $this->actingAs($this->actor)
            ->post('/incidents', $incidentPayload)
            ->assertRedirect();
        $this->assertSame(
            2,
            (int) $consumed->fresh()->revision,
        );
        $this->actingAs($this->actor)
            ->putJson("/incidents/drafts/{$requestUuid}", $this->draftPayload())
            ->assertConflict();
        $consumed->delete();
        $invalidDelayedAutosave = $this->draftPayload();
        $invalidDelayedAutosave['form']['description'] = str_repeat('x', 10001);
        $this->actingAs($this->actor)
            ->putJson("/incidents/drafts/{$requestUuid}", $invalidDelayedAutosave)
            ->assertConflict();
        $foreignActor = $this->userWithRole('support_worker');
        $foreignActor->permissionOverrides()->attach(
            Permission::query()->where('key', 'healthSafety.viewAllSites')->firstOrFail(),
            ['allowed' => true],
        );
        $this->actingAs($foreignActor)
            ->putJson("/incidents/drafts/{$requestUuid}", $invalidDelayedAutosave)
            ->assertNotFound();
        $this->assertDatabaseMissing('incident_report_drafts', [
            'request_uuid' => $requestUuid,
        ]);
        $this->assertDatabaseCount('client_incidents', 1);
    }

    public function test_submission_cannot_consume_a_recovery_uuid_bound_to_another_client_or_site(): void
    {
        $sameSiteClient = Client::factory()->create(['site_id' => $this->site->id]);
        $sameSiteClient->supportWorkers()->attach($this->actor->id);
        $clientBoundUuid = (string) Str::uuid();
        $this->actingAs($this->actor)
            ->putJson("/incidents/drafts/{$clientBoundUuid}", $this->draftPayload())
            ->assertOk();

        $this->app->instance(
            NotificationService::class,
            \Mockery::mock(NotificationService::class)->shouldIgnoreMissing(),
        );
        $this->actingAs($this->actor)
            ->post('/incidents', $this->incidentPayload(
                $clientBoundUuid,
                $sameSiteClient,
                $this->site,
            ))
            ->assertSessionHasErrors('report_request_uuid');

        $foreignSite = Site::factory()->create();
        $foreignClient = Client::factory()->create(['site_id' => $foreignSite->id]);
        $this->actor->permissionOverrides()->syncWithoutDetaching([
            Permission::query()
                ->where('key', 'healthSafety.viewAllSites')
                ->firstOrFail()
                ->id => ['allowed' => true],
        ]);
        $siteBoundUuid = (string) Str::uuid();
        $siteOnlyDraft = $this->draftPayload();
        $siteOnlyDraft['form']['client_id'] = null;
        $this->actingAs($this->actor)
            ->putJson("/incidents/drafts/{$siteBoundUuid}", $siteOnlyDraft)
            ->assertOk();
        $this->actingAs($this->actor)
            ->post('/incidents', $this->incidentPayload(
                $siteBoundUuid,
                $foreignClient,
                $foreignSite,
            ))
            ->assertSessionHasErrors('report_request_uuid');

        $this->assertDatabaseCount('client_incidents', 0);
        $this->assertDatabaseHas('incident_report_drafts', [
            'request_uuid' => $clientBoundUuid,
            'consumed_at' => null,
        ]);
        $this->assertDatabaseHas('incident_report_drafts', [
            'request_uuid' => $siteBoundUuid,
            'consumed_at' => null,
        ]);
    }

    public function test_only_the_explicit_health_and_safety_site_permission_broadens_draft_scope(): void
    {
        $foreignSite = Site::factory()->create();
        $foreignClient = Client::factory()->create(['site_id' => $foreignSite->id]);
        $payload = $this->draftPayload();
        $payload['form']['client_id'] = (string) $foreignClient->id;
        $payload['form']['site_id'] = (string) $foreignSite->id;

        $reportViewer = $this->userWithRole('support_worker');
        $reportViewer->permissionOverrides()->attach(
            Permission::query()->where('key', 'reports.viewAny')->firstOrFail(),
            ['allowed' => true],
        );
        $this->actingAs($reportViewer)
            ->putJson('/incidents/drafts/'.Str::uuid(), $payload)
            ->assertNotFound();

        $applicationHsReporter = $this->userWithRole('support_worker');
        $applicationHsReporter->permissionOverrides()->attach(
            Permission::query()->where('key', 'healthSafety.viewAllSites')->firstOrFail(),
            ['allowed' => true],
        );
        $this->actingAs($applicationHsReporter)
            ->putJson('/incidents/drafts/'.Str::uuid(), $payload)
            ->assertOk();
    }

    public function test_recovery_is_concealed_after_the_client_moves_to_another_site(): void
    {
        $requestUuid = (string) Str::uuid();
        $this->actingAs($this->actor)
            ->putJson("/incidents/drafts/{$requestUuid}", $this->draftPayload())
            ->assertOk();

        $this->actor->permissionOverrides()->attach(
            Permission::query()->where('key', 'healthSafety.viewAllSites')->firstOrFail(),
            ['allowed' => true],
        );
        $newSite = Site::factory()->create();
        $this->client->forceFill(['site_id' => $newSite->id])->save();

        $this->actingAs($this->actor)
            ->getJson("/incidents/drafts/{$requestUuid}")
            ->assertNotFound();
        $this->actingAs($this->actor)
            ->putJson("/incidents/drafts/{$requestUuid}", $this->draftPayload())
            ->assertNotFound();

        $this->assertDatabaseHas('incident_report_drafts', [
            'request_uuid' => $requestUuid,
            'site_id' => $this->site->id,
            'client_id' => $this->client->id,
            'revision' => 1,
        ]);
    }

    public function test_pruner_removes_only_expired_recovery_drafts(): void
    {
        $expiredUuid = (string) Str::uuid();
        $activeUuid = (string) Str::uuid();
        foreach ([$expiredUuid, $activeUuid] as $requestUuid) {
            $this->actingAs($this->actor)
                ->putJson("/incidents/drafts/{$requestUuid}", $this->draftPayload())
                ->assertOk();
        }

        IncidentReportDraft::query()
            ->where('request_uuid', $expiredUuid)
            ->update(['expires_at' => now()->subMinute()]);

        (new PruneIncidentReportDrafts)->handle();

        $this->assertDatabaseMissing('incident_report_drafts', [
            'request_uuid' => $expiredUuid,
        ]);
        $this->assertDatabaseHas('incident_report_drafts', [
            'request_uuid' => $activeUuid,
            'user_id' => $this->actor->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function draftPayload(): array
    {
        return [
            'expected_revision' => 0,
            'mode' => 'incident',
            'entry_context' => 'incidents',
            'step_index' => 2,
            'form' => [
                'type' => 'fall',
                'client_id' => (string) $this->client->id,
                'site_id' => (string) $this->site->id,
                'shift_id' => null,
                'occurred_date' => now()->format('Y-m-d'),
                'occurred_time' => now()->format('H:i'),
                'description' => 'Aroha slipped beside the dining table.',
                'severity' => 'low',
                'potential_severity' => null,
                'potential_consequence' => null,
                'hazard' => null,
                'immediate_action_taken' => 'Resident checked and the area made safe.',
                'witnesses' => null,
                'harm_or_injury' => null,
                'consequence' => null,
                'is_notifiable' => false,
                'worksafe_reference' => null,
                'worksafe_notification_status' => null,
                'site_preserved' => false,
                'followups' => [],
                'stay' => true,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function incidentPayload(
        string $requestUuid,
        Client $client,
        Site $site,
    ): array {
        return [
            'intent' => 'submit',
            'report_request_uuid' => $requestUuid,
            'client_id' => $client->id,
            'site_id' => $site->id,
            'type' => 'fall',
            'severity' => 'low',
            'occurred_at' => now()->format('Y-m-d H:i:s'),
            'description' => 'A report whose recovery identity must remain bound.',
            'immediate_action_taken' => 'The person was checked and the area was made safe.',
        ];
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create([
            'role' => $role,
            'approved_at' => now(),
            'email_verified_at' => now(),
        ]);
        $user->roles()->attach(Role::query()->where('name', $role)->firstOrFail());

        return $user;
    }
}
