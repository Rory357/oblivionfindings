<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationRound;
use App\Models\MedicationRoundTemplate;
use App\Models\Permission;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The Medication Rounds page serves a read-only GET payload. Assigned frontline
 * workers explicitly start pending rounds through the canonical scoped POST.
 */
class RoundsPagePayloadTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_exact_record_worker_uses_read_only_get_and_explicit_idempotent_start(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-04 08:00:00', config('app.worker_timezone', 'Pacific/Auckland')));
        $this->seed(RbacSeeder::class);

        $site = Site::factory()->create(['is_active' => true]);
        $user = $this->makeSiteUser($site, ['medications.administer.record']);
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $this->activeShift($user, $client);
        $round = $this->makeRound($site, [
            'assigned_to' => $user->id,
            'status' => 'pending',
        ]);
        $target = route('emar.rounds', ['date' => '2026-05-04', 'guided' => $round->id]);

        $this->assertFalse($user->canDo('medications.view'));
        $this->actingAs($user)
            ->get(route('meds.round.show', $round))
            ->assertRedirect($target);
        $this->assertSame('pending', $round->fresh()->status);

        $this->actingAs($user)
            ->get($target)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('emar/Rounds')
                ->where('guidedRound.round.id', $round->id)
                ->where('guidedRound.round.status', 'pending')
                ->where('guidedRound.can_start', true)
                ->where('guidedRound.can_record', false)
                ->where('board_user.med_competent', true)
            );
        $this->assertSame('pending', $round->fresh()->status);

        $this->actingAs($user)
            ->post(route('meds.round.start', $round))
            ->assertRedirect($target);

        $started = $round->fresh();
        $this->assertSame('in_progress', $started->status);
        $this->assertSame($user->id, (int) $started->started_by);
        $this->assertNotNull($started->started_at);
        $startedAt = $started->started_at->toIso8601String();

        $this->actingAs($user)
            ->post(route('meds.round.start', $round))
            ->assertRedirect($target);
        $this->assertSame($startedAt, $round->fresh()->started_at?->toIso8601String());

        $this->actingAs($user)
            ->get($target)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('guidedRound.round.status', 'in_progress')
                ->where('guidedRound.can_start', false)
                ->where('guidedRound.can_record', true)
            );
    }

    public function test_assigned_record_worker_sees_only_current_work_scope_clients_in_round_projection(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-04 08:00:00', config('app.worker_timezone', 'Pacific/Auckland')));
        $this->seed(RbacSeeder::class);

        $site = Site::factory()->create(['is_active' => true]);
        $user = $this->makeSiteUser($site, ['medications.administer.record']);
        $scopedClient = Client::factory()->create([
            'site_id' => $site->id,
            'status' => 'active',
            'first_name' => 'Visible',
            'last_name' => 'Resident',
        ]);
        $sameSiteHiddenClient = Client::factory()->create([
            'site_id' => $site->id,
            'status' => 'active',
            'first_name' => 'HIDDEN',
            'last_name' => 'Resident',
        ]);
        $this->activeShift($user, $scopedClient);
        $visibleMedication = $this->scheduledMedication($scopedClient);
        $visibleMedication->forceFill(['name' => 'Visible round medicine'])->saveQuietly();
        $hiddenMedication = $this->scheduledMedication($sameSiteHiddenClient);
        $hiddenMedication->forceFill(['name' => 'HIDDEN round medicine'])->saveQuietly();
        $round = $this->makeRound($site, [
            'assigned_to' => $user->id,
            'status' => 'pending',
        ]);
        $scheduledFor = Carbon::parse('2026-05-04 08:00:00', config('app.worker_timezone', 'Pacific/Auckland'));
        foreach ([
            [$scopedClient, $visibleMedication],
            [$sameSiteHiddenClient, $hiddenMedication],
        ] as [$client, $medication]) {
            ClientMedicationAdministration::query()->create([
                'client_id' => $client->id,
                'client_medication_id' => $medication->id,
                'medication_round_id' => $round->id,
                'administered_by' => $user->id,
                'scheduled_for' => $scheduledFor->copy()->utc(),
                'administered_at' => $scheduledFor->copy()->utc(),
                'status' => 'given',
                'dose_given' => '1 tablet',
            ]);
        }

        $response = $this->actingAs($user)
            ->get(route('emar.rounds', [
                'date' => '2026-05-04',
                'guided' => $round->id,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('rounds', 1)
                ->where('rounds.0.id', $round->id)
                ->where('rounds.0.total_medications', 1)
                ->where('rounds.0.given', 1)
                ->where('rounds.0.cells', fn ($cells): bool => collect($cells)->count() === 1
                    && (int) collect($cells)->first()['resident_id'] === (int) $scopedClient->id
                    && collect($cells)->first()['medication_name'] === 'Visible round medicine')
                ->where('residents', fn ($residents): bool => collect($residents)->pluck('id')->map(fn ($id): int => (int) $id)->all() === [$scopedClient->id])
                ->where('guidedRound.items', fn ($items): bool => collect($items)->count() === 1
                    && (int) collect($items)->first()['client_id'] === (int) $scopedClient->id
                    && collect($items)->first()['medication_name'] === 'Visible round medicine')
                ->where('guidedRound.progress.total', 1)
                ->where('guidedRound.progress.given', 1)
                ->where('activity', fn ($activity): bool => collect($activity)->count() === 1
                    && (int) collect($activity)->first()['resident_id'] === (int) $scopedClient->id
                    && collect($activity)->first()['medication_name'] === 'Visible round medicine'));

        $this->assertStringNotContainsString('HIDDEN', $response->getContent());
    }

    public function test_guided_round_direct_objects_deny_foreign_unassigned_and_off_shift_workers(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-04 08:00:00', config('app.worker_timezone', 'Pacific/Auckland')));
        $this->seed(RbacSeeder::class);

        $site = Site::factory()->create(['is_active' => true]);
        $foreignSite = Site::factory()->create(['is_active' => true]);
        $user = $this->makeSiteUser($site, ['medications.administer.record']);
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $shift = $this->activeShift($user, $client);
        $unassigned = $this->makeRound($site, ['status' => 'pending']);
        $foreign = $this->makeRound($foreignSite, [
            'assigned_to' => $user->id,
            'status' => 'pending',
        ]);

        foreach ([$unassigned, $foreign] as $round) {
            $this->actingAs($user)->get(route('meds.round.show', $round))->assertNotFound();
            $this->actingAs($user)->post(route('meds.round.start', $round))->assertNotFound();
            $this->actingAs($user)
                ->get(route('emar.rounds', ['date' => '2026-05-04', 'guided' => $round->id]))
                ->assertNotFound();
            $this->assertSame('pending', $round->fresh()->status);
        }

        $assigned = $this->makeRound($site, [
            'assigned_to' => $user->id,
            'status' => 'pending',
        ]);
        $shift->forceFill([
            'status' => 'completed',
            'actual_ends_at' => now()->subMinute(),
        ])->save();

        $this->actingAs($user)->get(route('meds.round.show', $assigned))->assertForbidden();
        $this->actingAs($user)->post(route('meds.round.start', $assigned))->assertForbidden();
        $this->actingAs($user)
            ->get(route('emar.rounds', ['date' => '2026-05-04', 'guided' => $assigned->id]))
            ->assertForbidden();
        $this->assertSame('pending', $assigned->fresh()->status);
    }

    public function test_exact_reader_can_review_only_approved_site_completed_rounds_without_mutation(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-04 08:00:00', config('app.worker_timezone', 'Pacific/Auckland')));
        $this->seed(RbacSeeder::class);

        $site = Site::factory()->create(['is_active' => true]);
        $foreignSite = Site::factory()->create(['is_active' => true]);
        $reader = $this->makeSiteUser($site, ['medications.view']);
        $completed = $this->makeRound($site, [
            'status' => 'completed',
            'completed_at' => now()->subHour(),
        ]);
        $foreign = $this->makeRound($foreignSite, [
            'status' => 'completed',
            'completed_at' => now()->subHour(),
        ]);

        $this->actingAs($reader)
            ->get(route('emar.rounds', ['date' => '2026-05-04', 'guided' => $completed->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('rounds', 1)
                ->where('rounds.0.id', $completed->id)
                ->where('guidedRound.round.id', $completed->id)
                ->where('guidedRound.round.status', 'completed')
                ->where('guidedRound.can_start', false)
                ->where('guidedRound.can_record', false)
            );

        $this->actingAs($reader)
            ->get(route('emar.rounds', ['date' => '2026-05-04', 'guided' => $foreign->id]))
            ->assertNotFound();
        $this->assertSame('completed', $completed->fresh()->status);
        $this->assertSame('completed', $foreign->fresh()->status);
    }

    public function test_template_payload_reconciles_site_and_active_service_context_without_leaking_names(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-04 08:00:00', config('app.worker_timezone', 'Pacific/Auckland')));
        $this->seed(RbacSeeder::class);

        $site = Site::factory()->create(['is_active' => true]);
        $foreignSite = Site::factory()->create(['is_active' => true]);
        $reader = $this->makeSiteUser($site, ['medications.view']);
        $applicationWideReader = $this->makeSiteUser($site, [
            'medications.view',
            'clinical.accessAllSites',
        ]);
        $foreignStaff = User::factory()->create(['name' => 'HIDDEN foreign template staff']);
        $localContext = ServiceContext::factory()->create([
            'site_id' => $site->id,
            'is_active' => true,
        ]);
        $foreignContext = ServiceContext::factory()->create([
            'site_id' => $foreignSite->id,
            'is_active' => true,
        ]);
        $applicationWideContext = ServiceContext::factory()->create([
            'site_id' => null,
            'is_active' => true,
        ]);
        $inactiveContext = ServiceContext::factory()->create([
            'site_id' => $site->id,
            'is_active' => false,
        ]);

        $visibleTemplates = collect([
            $this->makeTemplate('Visible local template', $site),
            $this->makeTemplate('Visible local application-context template', $site, $applicationWideContext),
            $this->makeTemplate('Visible local matching-context template', $site, $localContext),
            $this->makeTemplate('Visible context-derived template', null, $localContext),
        ]);
        $legacyForeignAssigneeTemplate = $visibleTemplates->first();
        $legacyForeignAssigneeTemplate
            ->forceFill(['default_assigned_to' => $foreignStaff->id])
            ->save();
        $applicationWideTemplates = collect([
            $this->makeTemplate('Application-wide no-context template'),
            $this->makeTemplate('Application-wide context template', null, $applicationWideContext),
        ]);
        collect([
            $this->makeTemplate('HIDDEN foreign Site template', $foreignSite),
            $this->makeTemplate('HIDDEN foreign context-derived template', null, $foreignContext),
            $this->makeTemplate('HIDDEN conflicting context template', $site, $foreignContext),
            $this->makeTemplate('HIDDEN inactive concrete context template', $site, $inactiveContext),
            $this->makeTemplate('HIDDEN inactive context-derived template', null, $inactiveContext),
        ])->each(function (MedicationRoundTemplate $template) use ($foreignStaff): void {
            $template->forceFill(['default_assigned_to' => $foreignStaff->id])->save();
        });

        $response = $this->actingAs($reader)
            ->get(route('emar.rounds', ['date' => '2026-05-04']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('templates', function ($templates) use ($visibleTemplates, $legacyForeignAssigneeTemplate): bool {
                    $actualIds = collect($templates)
                        ->pluck('id')
                        ->map(fn ($id): int => (int) $id)
                        ->sort()
                        ->values()
                        ->all();
                    $expectedIds = $visibleTemplates
                        ->pluck('id')
                        ->map(fn ($id): int => (int) $id)
                        ->sort()
                        ->values()
                        ->all();

                    $legacyRow = collect($templates)->firstWhere('id', $legacyForeignAssigneeTemplate->id);

                    return $actualIds === $expectedIds
                        && data_get($legacyRow, 'default_assigned_to') === null
                        && data_get($legacyRow, 'default_staff') === null;
                }));

        $this->assertStringNotContainsString('HIDDEN', $response->getContent());
        $this->assertStringNotContainsString('Application-wide no-context template', $response->getContent());
        $this->assertStringNotContainsString('Application-wide context template', $response->getContent());

        $this->actingAs($applicationWideReader)
            ->get(route('emar.rounds', ['date' => '2026-05-04']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('templates', function ($templates) use ($applicationWideTemplates): bool {
                    $templateIds = collect($templates)
                        ->pluck('id')
                        ->map(fn ($id): int => (int) $id);

                    return $applicationWideTemplates->pluck('id')->every(
                        fn ($id): bool => $templateIds->contains((int) $id),
                    );
                }));
    }

    public function test_worker_board_lists_only_assigned_rounds_at_approved_sites(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-04 08:00:00', config('app.worker_timezone', 'Pacific/Auckland')));
        $this->seed(RbacSeeder::class);

        $site = Site::factory()->create(['is_active' => true]);
        $foreignSite = Site::factory()->create(['is_active' => true]);
        $user = $this->makeSiteUser($site, ['medications.administer.record']);
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $foreignClient = Client::factory()->create(['site_id' => $foreignSite->id, 'status' => 'active']);
        $this->activeShift($user, $client);
        $this->scheduledMedication($client);
        $this->scheduledMedication($foreignClient);

        $assigned = $this->makeRound($site, [
            'assigned_to' => $user->id,
            'status' => 'pending',
        ]);
        $this->makeRound($site, ['status' => 'pending']);
        $this->makeRound($foreignSite, [
            'assigned_to' => $user->id,
            'status' => 'pending',
        ]);

        $this->actingAs($user)
            ->get(route('meds.today', ['date' => '2026-05-04']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('meds/today/index')
                ->has('rounds', 1)
                ->where('rounds.0.id', $assigned->id)
                ->where('active_round.id', $assigned->id)
            );
    }

    /**
     * @param  array<int, string>  $permissionKeys
     */
    protected function makeSiteUser(Site $site, array $permissionKeys): User
    {
        $user = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        $this->grantPermissions($user, $permissionKeys);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subYear(),
            'end_date' => null,
            'is_active' => true,
        ]);

        return $user;
    }

    /**
     * @param  array<int, string>  $permissionKeys
     */
    protected function grantPermissions(User $user, array $permissionKeys): void
    {
        $permissionMap = Permission::query()
            ->whereIn('key', $permissionKeys)
            ->pluck('id')
            ->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])
            ->all();

        $user->permissionOverrides()->sync($permissionMap);
    }

    protected function activeShift(User $user, Client $client): Shift
    {
        return Shift::factory()->create([
            'client_id' => $client->id,
            'site_id' => $client->site_id,
            'user_id' => $user->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(2),
            'actual_starts_at' => now()->subHour(),
            'actual_ends_at' => null,
            'started_by' => $user->id,
            'created_by' => $user->id,
            'status' => 'in_progress',
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    protected function makeRound(Site $site, array $overrides = []): MedicationRound
    {
        return MedicationRound::query()->create(array_merge([
            'site_id' => $site->id,
            'name' => 'Morning round '.$site->id,
            'round_type' => 'scheduled',
            'scheduled_time' => '08:00',
            'window_minutes' => 60,
            'round_date' => '2026-05-04',
            'status' => 'pending',
            'total_medications' => 1,
        ], $overrides));
    }

    protected function makeTemplate(
        string $name,
        ?Site $site = null,
        ?ServiceContext $serviceContext = null,
    ): MedicationRoundTemplate {
        return MedicationRoundTemplate::query()->create([
            'site_id' => $site?->id,
            'service_context_id' => $serviceContext?->id,
            'name' => $name,
            'scheduled_time' => '08:00',
            'window_minutes' => 60,
            'days_of_week' => [1, 2, 3, 4, 5, 6, 7],
            'active' => true,
        ]);
    }

    protected function scheduledMedication(Client $client): ClientMedication
    {
        return ClientMedication::query()->create([
            'client_id' => $client->id,
            'name' => 'Round medicine '.$client->id,
            'dosage' => '1 tablet',
            'frequency' => 'Once daily',
            'dose_times' => ['08:00'],
            'is_prn' => false,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
            'start_date' => today()->subMonth(),
        ]);
    }
}
