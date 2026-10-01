<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\MedicationRound;
use App\Models\MedicationRoundTemplate;
use App\Models\MedicationSettingChange;
use App\Models\Permission;
use App\Models\ServiceContext;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * eMAR P11 Rounds & timing › Round templates: moved from Meds today ›
 * Rounds. Whoever manages orders at a house reaches the templates in
 * Settings (and nothing else there), every write stays house-scoped, an
 * organisation-wide template stays with the people who could manage it
 * before, and every change is in the change history and the audit log.
 */
class RoundTemplateSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-05-04 08:00:00', 'Pacific/Auckland')->utc());
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_an_orders_manager_without_settings_access_reaches_round_templates_only(): void
    {
        $lead = $this->staff(['medications.orders.manage', 'medications.view']);
        $template = $this->template('Morning round', $this->site);

        $this->actingAs($lead)
            ->get('/emar/settings')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('emar/Settings')
                ->where('settingsAccess', false)
                ->where('readOnlyAudit', false)
                ->where('witnessPin.can_reset', false)
                ->where('templateAccess.manage', true)
                ->where('templateAccess.sites', [['id' => $this->site->id, 'name' => $this->site->name]])
                ->where('roundTemplates.0.id', $template->id)
                ->where('roundTemplates.0.can_change', true)
                ->where('rules', [])
                ->where('settings.history', []));

        // Nothing else in Settings opens up.
        $this->actingAs($lead)
            ->put('/emar/settings/changes', ['view' => 'rounds', 'changes' => [[
                'group' => 'timing', 'key' => 'late', 'site_id' => null, 'value' => '30', 'from' => '60',
            ]]])
            ->assertForbidden();
        $this->actingAs($lead)
            ->post(route('emar.settings.rules.store'), [
                'site_id' => $this->site->id,
                'match_type' => 'medicine_name',
                'match_value' => 'Anything',
                'requires_countersign' => true,
                'required_observations' => [],
                'active' => true,
            ])
            ->assertForbidden();

        // Without orders.manage (or another Settings permission) the page stays closed.
        $this->actingAs($this->staff(['medications.view']))
            ->get('/emar/settings')
            ->assertForbidden();
    }

    public function test_templates_follow_the_house_scope_and_never_name_another_houses_staff(): void
    {
        $foreignSite = Site::factory()->create(['is_active' => true]);
        $houseLead = $this->staff(['medications.orders.manage', 'medications.view']);
        $allHousesLead = $this->staff(['medications.orders.manage', 'medications.view', 'clinical.accessAllSites']);
        $foreignStaff = User::factory()->create(['name' => 'HIDDEN foreign template staff']);
        $localContext = ServiceContext::factory()->create(['site_id' => $this->site->id, 'is_active' => true]);
        $foreignContext = ServiceContext::factory()->create(['site_id' => $foreignSite->id, 'is_active' => true]);
        $organisationContext = ServiceContext::factory()->create(['site_id' => null, 'is_active' => true]);
        $inactiveContext = ServiceContext::factory()->create(['site_id' => $this->site->id, 'is_active' => false]);

        $visible = collect([
            $this->template('Visible local template', $this->site),
            $this->template('Visible local organisation-context template', $this->site, $organisationContext),
            $this->template('Visible local matching-context template', $this->site, $localContext),
            $this->template('Visible context-derived template', null, $localContext),
        ]);
        $visible->first()->forceFill(['default_assigned_to' => $foreignStaff->id])->save();
        $organisationWide = collect([
            $this->template('Organisation-wide no-context template'),
            $this->template('Organisation-wide context template', null, $organisationContext),
        ]);
        collect([
            $this->template('HIDDEN foreign Site template', $foreignSite),
            $this->template('HIDDEN foreign context-derived template', null, $foreignContext),
            $this->template('HIDDEN conflicting context template', $this->site, $foreignContext),
            $this->template('HIDDEN inactive concrete context template', $this->site, $inactiveContext),
            $this->template('HIDDEN inactive context-derived template', null, $inactiveContext),
        ])->each(fn (MedicationRoundTemplate $t) => $t->forceFill(['default_assigned_to' => $foreignStaff->id])->save());

        $response = $this->actingAs($houseLead)->get('/emar/settings')->assertOk();
        $rows = collect($response->inertiaProps('roundTemplates'))->keyBy('id');
        $this->assertEqualsCanonicalizing($visible->pluck('id')->all(), $rows->keys()->all());
        $this->assertNull($rows[$visible->first()->id]['default_assigned_to']);
        $this->assertNull($rows[$visible->first()->id]['default_staff']);
        $this->assertSame($this->site->id, $rows[$visible->last()->id]['site_id']);
        $this->assertTrue($rows->every(fn (array $row): bool => $row['can_change']));
        $this->assertStringNotContainsString('HIDDEN', $response->getContent());
        $this->assertStringNotContainsString('Organisation-wide', $response->getContent());

        // An organisation-wide template stays with all-houses access.
        $rows = collect($this->actingAs($allHousesLead)->get('/emar/settings')->assertOk()->inertiaProps('roundTemplates'))->keyBy('id');
        foreach ($organisationWide as $template) {
            $this->assertTrue($rows->has($template->id));
            $this->assertNull($rows[$template->id]['site_id']);
            $this->assertTrue($rows[$template->id]['can_change']);
        }
        $this->actingAs($houseLead)
            ->put(route('emar.rounds.templates.update', $organisationWide->first()), ['active' => false])
            ->assertNotFound();
    }

    public function test_every_template_write_stays_with_its_house(): void
    {
        $foreignSite = Site::factory()->create(['is_active' => true]);
        $houseLead = $this->staff(['medications.orders.manage', 'medications.view']);
        $foreign = $this->template('Foreign round', $foreignSite);

        $this->actingAs($houseLead)
            ->post(route('emar.rounds.templates.store'), $this->payload(['site_id' => $foreignSite->id]))
            ->assertNotFound();
        $this->actingAs($houseLead)
            ->put(route('emar.rounds.templates.update', $foreign), ['active' => false])
            ->assertNotFound();
        $this->actingAs($houseLead)
            ->post(route('emar.rounds.templates.retire', $foreign))
            ->assertNotFound();
        $this->assertTrue($foreign->fresh()->active);
        $this->assertSame(0, MedicationSettingChange::query()->count());
    }

    public function test_template_changes_are_in_the_change_history_and_the_audit_log(): void
    {
        $lead = $this->staff(['medications.orders.manage', 'medications.view']);

        $this->actingAs($lead)
            ->from('/emar/settings')
            ->post(route('emar.rounds.templates.store'), $this->payload(['active' => false]))
            ->assertRedirect('/emar/settings')
            ->assertSessionHas('medication_settings_saved', 'Template added: Breakfast round. Saved as paused — no rounds are created until someone turns it on.');
        $template = MedicationRoundTemplate::query()->where('name', 'Breakfast round')->sole();
        $this->assertFalse($template->active);

        // Saving the same values changes nothing (the stored time has seconds).
        $this->actingAs($lead)
            ->put(route('emar.rounds.templates.update', $template), $this->payload(['active' => false]))
            ->assertSessionHas('medication_settings_saved', 'Nothing was saved — the template already had those values.');

        $this->actingAs($lead)
            ->put(route('emar.rounds.templates.update', $template), $this->payload(['active' => false, 'window_minutes' => 45, 'days_of_week' => [1, 2, 3, 4, 5]]))
            ->assertRedirect();
        $this->actingAs($lead)->put(route('emar.rounds.templates.update', $template), ['active' => true])->assertRedirect();
        $this->actingAs($lead)
            ->put(route('emar.rounds.templates.update', $template), ['active' => false])
            ->assertSessionHas('medication_settings_saved', 'Template paused. No rounds from tomorrow.');
        $this->actingAs($lead)->post(route('emar.rounds.templates.retire', $template))->assertRedirect();

        $history = MedicationSettingChange::query()->orderBy('id')->get();
        $this->assertSame([
            ['Round template added — Breakfast round', '—', '7:30 am ±60 · Every day · everyone rostered · Paused', false],
            ['Round template changed — Breakfast round', '7:30 am ±60 · Every day · everyone rostered · Paused', '7:30 am ±45 · Monday to Friday · everyone rostered · Paused', false],
            ['Round template turned back on — Breakfast round', 'Paused', 'Active', false],
            ['Round template paused — Breakfast round', 'Active', 'Paused', true],
            ['Round template retired — Breakfast round', 'Paused', 'Retired', true],
        ], $history->map(fn (MedicationSettingChange $c): array => [$c->label, $c->before_text, $c->after_text, $c->loosens])->all());
        $this->assertTrue($history->every(fn (MedicationSettingChange $c): bool => $c->setting_group === 'round_templates'
            && $c->setting_key === 'template:'.$template->id
            && (int) $c->site_id === $this->site->id
            && $c->view === 'rounds'
            && $c->section === 'templates'));
        $this->assertSame(
            ['created', 'updated', 'resumed', 'paused', 'retired'],
            AuditLog::query()->where('action', 'like', 'medications.round_template.%')->orderBy('id')->pluck('action')
                ->map(fn (string $action): string => substr($action, strlen('medications.round_template.')))->all(),
        );

        // A settings manager at the house reads it in Change history.
        $manager = $this->staff(['medications.settings.manage']);
        $this->actingAs($manager)
            ->get('/emar/settings')
            ->assertInertia(fn (Assert $page) => $page
                ->where('settings.history.0.label', 'Round template retired — Breakfast round')
                ->where('settings.history.0.view', 'rounds')
                ->where('settings.history.0.section', 'templates'));
    }

    public function test_a_paused_template_creates_no_rounds(): void
    {
        $lead = $this->staff(['medications.orders.manage', 'medications.view']);
        $active = $this->template('Morning round', $this->site);
        $paused = $this->template('Lunch round', $this->site);
        $paused->forceFill(['active' => false])->save();

        $this->actingAs($lead)
            ->post(route('emar.rounds.generate'), ['date' => '2026-05-04', 'site_id' => $this->site->id])
            ->assertSessionHas('medication_settings_saved', '1 round created (0 already existed and were skipped).');

        $this->assertSame([$active->id], MedicationRound::query()->pluck('round_template_id')->map(fn ($id): int => (int) $id)->all());
    }

    public function test_create_rounds_for_one_house_previews_first_and_existing_callers_are_unchanged(): void
    {
        $second = Site::factory()->create(['is_active' => true]);
        $outside = Site::factory()->create(['is_active' => true]);
        $lead = $this->staff(['medications.orders.manage', 'medications.view'], [$second->id]);
        $here = $this->template('Morning round', $this->site);
        $there = $this->template('Morning round', $second);

        $this->actingAs($lead)
            ->getJson(route('emar.rounds.generate.preview', ['date' => '2026-05-04', 'site_id' => $this->site->id]))
            ->assertOk()
            ->assertExactJson([
                'rounds' => [['template_id' => $here->id, 'name' => 'Morning round', 'scheduled_time' => '08:00', 'status' => 'new', 'reason' => 'would_create']],
                'create' => 1,
                'exists' => 0,
            ]);
        $this->assertSame(0, MedicationRound::query()->count());

        $this->actingAs($lead)
            ->post(route('emar.rounds.generate'), ['date' => '2026-05-04', 'site_id' => $this->site->id])
            ->assertRedirect();
        $this->assertSame([$here->id], MedicationRound::query()->pluck('round_template_id')->map(fn ($id): int => (int) $id)->all());
        $this->actingAs($lead)
            ->getJson(route('emar.rounds.generate.preview', ['date' => '2026-05-04', 'site_id' => $this->site->id]))
            ->assertJsonPath('rounds.0.status', 'exists')
            ->assertJsonPath('create', 0);

        // Without a house it still covers every house the person manages.
        $this->actingAs($lead)
            ->post(route('emar.rounds.generate'), ['date' => '2026-05-04'])
            ->assertSessionHas('round_generation', fn (array $summary): bool => $summary['created'] === 1 && $summary['already_exists'] === 1);
        $this->assertTrue(MedicationRound::query()->where('round_template_id', $there->id)->exists());

        foreach (['generate.preview' => 'getJson', 'generate' => 'post'] as $name => $method) {
            $route = $method === 'getJson'
                ? route('emar.rounds.'.$name, ['date' => '2026-05-04', 'site_id' => $outside->id])
                : route('emar.rounds.'.$name);
            $this->actingAs($lead)->{$method}($route, $method === 'post' ? ['date' => '2026-05-04', 'site_id' => $outside->id] : [])
                ->assertNotFound();
        }
    }

    public function test_the_today_column_comes_from_todays_round(): void
    {
        $lead = $this->staff(['medications.orders.manage', 'medications.view']);
        $client = Client::factory()->create(['site_id' => $this->site->id, 'status' => 'active']);
        ClientMedication::query()->create([
            'client_id' => $client->id,
            'name' => 'Round medicine',
            'dosage' => '1 tablet',
            'frequency' => 'Once daily',
            'dose_times' => ['08:00'],
            'is_prn' => false,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
            'start_date' => today()->subMonth(),
        ]);
        $withRound = $this->template('Morning round', $this->site);
        // Tuesdays only: 4 May 2026 is a Monday, so no round today.
        $withoutRound = $this->template('Evening round', $this->site);
        $withoutRound->forceFill(['days_of_week' => [2]])->save();
        $this->actingAs($lead)->post(route('emar.rounds.generate'), ['date' => '2026-05-04', 'site_id' => $this->site->id]);
        $this->assertFalse(MedicationRound::query()->where('round_template_id', $withoutRound->id)->exists());

        $rows = collect($this->actingAs($lead)->get('/emar/settings')->inertiaProps('roundTemplates'))->keyBy('id');
        $this->assertSame(['doses' => 1, 'people' => 1], $rows[$withRound->id]['today']);
        $this->assertNull($rows[$withoutRound->id]['today']);
    }

    /** @param  array<string, mixed>  $over */
    private function payload(array $over = []): array
    {
        return [
            'name' => 'Breakfast round',
            'scheduled_time' => '07:30',
            'window_minutes' => 60,
            'days_of_week' => [],
            'site_id' => $this->site->id,
            'default_assigned_to' => null,
            'active' => true,
            ...$over,
        ];
    }

    /**
     * @param  list<string>  $permissions
     * @param  list<int>  $secondarySiteIds
     */
    private function staff(array $permissions, array $secondarySiteIds = []): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $user->permissionOverrides()->sync(
            Permission::query()->whereIn('key', $permissions)->pluck('id')
                ->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])
                ->all(),
        );
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => $secondarySiteIds,
            'start_date' => today()->subYear(),
            'end_date' => null,
            'is_active' => true,
        ]);

        return $user->fresh();
    }

    private function template(string $name, ?Site $site = null, ?ServiceContext $context = null): MedicationRoundTemplate
    {
        return MedicationRoundTemplate::query()->create([
            'site_id' => $site?->id,
            'service_context_id' => $context?->id,
            'name' => $name,
            'scheduled_time' => $name === 'Evening round' ? '18:00' : '08:00',
            'window_minutes' => 60,
            'days_of_week' => [1, 2, 3, 4, 5, 6, 7],
            'active' => true,
        ]);
    }
}
