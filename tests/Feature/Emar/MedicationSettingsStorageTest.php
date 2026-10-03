<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\MedicationSettingChange;
use App\Models\MedicationSiteSetting;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\MedicationSafetyPolicySettings;
use App\Services\Medication\Settings\MedicationSettingDefinition;
use App\Services\Medication\Settings\MedicationSettingGroup;
use App\Services\Medication\Settings\MedicationSettingsRegistry;
use App\Services\Medication\Settings\MedicationSettingsStore;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * eMAR P11 B1 — Medication Settings storage: drafts are saved a view at a
 * time, every save and every "Keep today's value" is in the change history
 * with the structured value before, loosening a check needs confirmation, a
 * change saved elsewhere is never overwritten, and house values are stored
 * per house under that house's authority.
 */
class MedicationSettingsStorageTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['is_active' => true]);
    }

    public function test_values_and_review_markers_see_subsequent_saves_without_reusing_an_earlier_read(): void
    {
        $store = app(MedicationSettingsStore::class);
        $before = $store->valuesAndReviews([$this->site->id]);
        $this->assertSame('off', $before['values']['safety']['restricted_competency']);
        $this->assertNull($before['reviewed']['safety']['restricted_competency']);
        $manager = $this->organisationManager();

        $this->actingAs($manager)->put('/emar/settings/changes', [
            'view' => 'rules',
            'changes' => [['group' => 'safety', 'key' => 'restricted_competency', 'from' => 'off', 'value' => 'block']],
        ])->assertSessionHasNoErrors();

        $after = $store->valuesAndReviews([$this->site->id]);
        $this->assertSame('block', $after['values']['safety']['restricted_competency']);
        $this->assertSame($manager->name, $after['reviewed']['safety']['restricted_competency']['by']);
        $this->assertNotNull($after['reviewed']['safety']['restricted_competency']['at']);
        $this->assertNull($before['reviewed']['safety']['restricted_competency']);
    }

    public function test_a_save_records_the_change_with_the_value_before_it(): void
    {
        $manager = $this->organisationManager();

        $this->actingAs($manager)
            ->from('/emar/settings')
            ->put('/emar/settings/changes', $this->save('rules', [['safety', 'restricted_competency', 'block', 'off']]))
            ->assertRedirect('/emar/settings')
            ->assertSessionHas('medication_settings_saved', '1 change saved. From the next dose signed, at every house.');

        $this->assertSame('block', app(MedicationSafetyPolicySettings::class)->restrictedCompetencyMode());

        $change = MedicationSettingChange::query()->sole();
        $this->assertSame('changed', $change->action);
        $this->assertSame('safety', $change->setting_group);
        $this->assertSame('restricted_competency', $change->setting_key);
        $this->assertNull($change->site_id);
        $this->assertSame('rules', $change->view);
        $this->assertSame('safety', $change->section);
        $this->assertSame('A worker’s medication competency is marked restricted', $change->label);
        $this->assertSame('off', $change->before_value);
        $this->assertSame('block', $change->after_value);
        $this->assertSame('Off — no extra check', $change->before_text);
        $this->assertSame('Block — they can’t sign as given; the dialog shows who on shift can give it', $change->after_text);
        $this->assertFalse($change->loosens);
        $this->assertSame($manager->id, $change->actor_id);
        $this->assertSame('medications.safety_policy.updated', $change->audit_event);
        $this->assertSame(1, AppSetting::query()->where('key', MedicationSettingsStore::REVISION_KEY)->value('value'));

        $audit = AuditLog::query()->where('action', 'medications.safety_policy.updated')->sole();
        $this->assertSame('changed', $audit->meta['action']);
        $this->assertSame(['restricted_competency'], $audit->meta['changed']);
        $this->assertSame([], $audit->meta['loosened']);
        $this->assertEquals([$change->id], $audit->meta['change_ids']);

        $this->actingAs($manager)
            ->get('/emar/settings')
            ->assertInertia(fn (Assert $page) => $page
                ->where('settings.values.safety.restricted_competency', 'block')
                ->where('settings.reviewed.safety.restricted_competency.by', $manager->name)
                ->where('settings.reviewed.safety.competency_areas', null)
                ->where('settings.definitions.safety.restricted_competency.rank', ['off', 'cosigner', 'block'])
                ->has('settings.history', 1)
                ->where('settings.history.0.who', $manager->name)
                ->where('settings.history.0.action', 'changed')
                ->where('settings.history.0.before_value', 'off')
                ->where('settings.history.0.before_text', 'Off — no extra check')
                ->where('settings.history.0.site_name', null)
                ->where('settings.history.0.event', 'medications.safety_policy.updated'));
    }

    public function test_loosening_a_check_needs_confirmation_and_is_labelled_in_the_history(): void
    {
        $manager = $this->organisationManager();
        $this->actingAs($manager)
            ->put('/emar/settings/changes', $this->save('rules', [['safety', 'restricted_competency', 'block', 'off']]))
            ->assertSessionHasNoErrors();

        $this->actingAs($manager)
            ->from('/emar/settings')
            ->put('/emar/settings/changes', $this->save('rules', [['safety', 'restricted_competency', 'cosigner', 'block']]))
            ->assertSessionHasErrors('confirm_loosening');
        $this->assertSame('block', app(MedicationSafetyPolicySettings::class)->restrictedCompetencyMode());
        $this->assertSame(1, MedicationSettingChange::query()->count());

        $this->actingAs($manager)
            ->from('/emar/settings')
            ->put('/emar/settings/changes', [
                ...$this->save('rules', [['safety', 'restricted_competency', 'cosigner', 'block']]),
                'confirm_loosening' => true,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('cosigner', app(MedicationSafetyPolicySettings::class)->restrictedCompetencyMode());
        $latest = MedicationSettingChange::query()->latest('id')->first();
        $this->assertTrue($latest->loosens);
        $this->assertSame('block', $latest->before_value);
        $audit = AuditLog::query()->where('action', 'medications.safety_policy.updated')->latest('id')->first();
        $this->assertSame(['restricted_competency'], $audit->meta['loosened']);
    }

    public function test_a_change_saved_elsewhere_is_never_overwritten(): void
    {
        $this->travelTo(Carbon::parse('2026-10-01 09:10', 'Pacific/Auckland')->utc());
        $first = $this->organisationManager('Rangi Parata');
        $second = $this->organisationManager('Hana Kereama');

        $this->actingAs($first)
            ->put('/emar/settings/changes', $this->save('rules', [['safety', 'profile_allergy_match', 'block', 'warn']]))
            ->assertSessionHasNoErrors();

        // Hana started from the old saved value: nothing she sends is saved.
        $this->actingAs($second)
            ->from('/emar/settings')
            ->put('/emar/settings/changes', $this->save('rules', [
                ['safety', 'competency_areas', 'failed', 'off'],
                ['safety', 'profile_allergy_match', 'warn', 'warn'],
            ]))
            ->assertSessionHasErrors(['conflict' => 'Rangi Parata saved a change in this view at 9:10 am.']);

        $settings = app(MedicationSafetyPolicySettings::class);
        $this->assertSame('block', $settings->profileAllergyMatch());
        $this->assertSame('off', $settings->competencyAreaEnforcement());
        $this->assertSame(1, MedicationSettingChange::query()->count());
    }

    public function test_keep_todays_value_marks_a_default_reviewed_without_changing_it(): void
    {
        $manager = $this->organisationManager();

        $this->actingAs($manager)
            ->from('/emar/settings')
            ->post('/emar/settings/keep', ['items' => [['group' => 'safety', 'key' => 'competency_areas']]])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('medication_settings_saved', 'Kept today’s value for “The controlled-drug or covert area wasn’t passed”. It now shows as reviewed.');

        $settings = app(MedicationSafetyPolicySettings::class);
        $this->assertSame('off', $settings->competencyAreaEnforcement());
        $this->assertTrue($settings->reviewed()[MedicationSafetyPolicySettings::COMPETENCY_AREAS]);
        $kept = MedicationSettingChange::query()->sole();
        $this->assertSame('kept', $kept->action);
        $this->assertSame('Default — not yet reviewed', $kept->before_text);
        $this->assertSame('Kept: Off — no extra check', $kept->after_text);

        // Already reviewed: keeping it again changes nothing and says who did.
        $this->actingAs($manager)
            ->from('/emar/settings')
            ->post('/emar/settings/keep', ['items' => [['group' => 'safety', 'key' => 'competency_areas']]])
            ->assertSessionHasErrors('conflict');

        // The "Review the defaults" walkthrough keeps several in one go.
        $this->actingAs($manager)
            ->from('/emar/settings')
            ->post('/emar/settings/keep', ['items' => [
                ['group' => 'pin', 'key' => 'max_attempts'],
                ['group' => 'pin', 'key' => 'lockout_minutes'],
            ]])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('medication_settings_saved', '2 values kept. They now show as reviewed, and each is in the change history.');

        $this->assertSame(3, MedicationSettingChange::query()->where('action', 'kept')->count());
        $this->actingAs($manager)
            ->get('/emar/settings')
            ->assertInertia(fn (Assert $page) => $page
                ->where('settings.reviewed.pin.max_attempts.by', $manager->name)
                ->where('settings.reviewed.pin.renewal_months', null)
                // A kept default changed nothing, so there is nothing to put back.
                ->where('settings.history.0.before_value', null)
                ->where('settings.history.0.action', 'kept'));
    }

    public function test_each_setting_must_belong_to_the_view_and_appear_once(): void
    {
        $manager = $this->organisationManager();
        $put = fn (array $payload) => $this->actingAs($manager)->from('/emar/settings')->put('/emar/settings/changes', $payload);

        $put($this->save('staff', [['safety', 'restricted_competency', 'block', 'off']]))
            ->assertSessionHasErrors('changes.0.key');
        $put($this->save('rules', [['safety', 'invented', 'block', 'off']]))
            ->assertSessionHasErrors('changes.0.key');
        $put($this->save('rules', [
            ['safety', 'restricted_competency', 'block', 'off'],
            ['safety', 'restricted_competency', 'cosigner', 'off'],
        ]))->assertSessionHasErrors('changes.1.key');
        $put(['view' => 'rules', 'changes' => [[
            'group' => 'safety', 'key' => 'restricted_competency', 'site_id' => $this->site->id, 'value' => 'block', 'from' => 'off',
        ]]])->assertSessionHasErrors('changes.0.key');

        $this->assertSame(0, MedicationSettingChange::query()->count());
    }

    public function test_house_values_are_stored_per_house_under_that_houses_authority(): void
    {
        $this->app->instance(MedicationSettingsRegistry::class, new class extends MedicationSettingsRegistry
        {
            protected function build(): array
            {
                return [...parent::build(), new MedicationSettingGroup(
                    key: 'house',
                    view: self::VIEW_RULES,
                    effect: 'From the next dose, at that house',
                    auditEvent: 'medications.test_house_setting.updated',
                    definitions: [new MedicationSettingDefinition(
                        group: 'house',
                        key: 'witness',
                        storageKey: 'medications.test.house_witness',
                        scope: MedicationSettingDefinition::SCOPE_SITE,
                        section: 'controlled',
                        label: 'Witness at this house',
                        options: ['org' => 'Follow the organisation', 'on' => 'Always required', 'off' => 'Not required'],
                        default: 'org',
                        rank: ['off', 'org', 'on'],
                    )],
                )];
            }
        });
        $otherSite = Site::factory()->create(['is_active' => true]);
        $houseManager = $this->staff(['medications.settings.manage'], $this->site);
        $organisationManager = $this->organisationManager();

        $this->actingAs($organisationManager)
            ->put('/emar/settings/changes', $this->save('rules', [['house', 'witness', 'on', 'org', $otherSite->id]]))
            ->assertSessionHasNoErrors();

        $this->actingAs($houseManager)
            ->put('/emar/settings/changes', $this->save('rules', [['house', 'witness', 'on', 'org', $otherSite->id]]))
            ->assertForbidden();
        $this->actingAs($houseManager)
            ->put('/emar/settings/changes', $this->save('rules', [['safety', 'restricted_competency', 'block', 'off']]))
            ->assertForbidden();
        $this->actingAs($houseManager)
            ->from('/emar/settings')
            ->put('/emar/settings/changes', $this->save('rules', [['house', 'witness', 'on', 'org', $this->site->id]]))
            ->assertSessionHasNoErrors();

        $this->assertSame('on', MedicationSiteSetting::query()->where('site_id', $this->site->id)->sole()->value);
        $this->assertSame(0, AppSetting::query()->where('key', 'medications.test.house_witness')->count());
        $this->assertSame($this->site->id, MedicationSettingChange::query()->latest('id')->first()->site_id);

        // The house manager sees their own house's value and history, not another house's.
        $this->actingAs($houseManager)
            ->get('/emar/settings')
            ->assertInertia(fn (Assert $page) => $page
                ->where('settings.site_values.'.$this->site->id.'.house.witness', 'on')
                ->where('settings.site_reviewed.'.$this->site->id.'.house.witness.by', $houseManager->name)
                ->missing('settings.site_values.'.$otherSite->id)
                ->has('settings.history', 1)
                ->where('settings.history.0.site_name', $this->site->name));

        $this->actingAs($organisationManager)
            ->get('/emar/settings')
            ->assertInertia(fn (Assert $page) => $page->has('settings.history', 2));
    }

    public function test_history_words_come_only_from_the_setting_definitions(): void
    {
        // The change history is readable by everyone who can open Settings, so
        // its before/after text must never carry a client, a medicine or any
        // controlled-medicine detail: it is built only from each setting's
        // fixed option words.
        $manager = $this->organisationManager();
        $this->actingAs($manager)
            ->put('/emar/settings/changes', $this->save('rules', [
                ['safety', 'restricted_competency', 'cosigner', 'off'],
                ['safety', 'competency_areas', 'failed_or_not_seen', 'off'],
            ]))
            ->assertSessionHasNoErrors();
        $this->actingAs($manager)
            ->put('/emar/settings/changes', $this->save('staff', [['pin', 'renewal_months', '6', 'none']]))
            ->assertSessionHasNoErrors();
        $this->actingAs($manager)
            ->post('/emar/settings/keep', ['items' => [['group' => 'safety', 'key' => 'profile_allergy_match']]])
            ->assertSessionHasNoErrors();

        $registry = app(MedicationSettingsRegistry::class);
        $changes = MedicationSettingChange::query()->get();
        $this->assertCount(4, $changes);
        foreach ($changes as $change) {
            $definition = $registry->definition($change->setting_group, $change->setting_key);
            // A number (P11 Q-F: PIN renewal) reads as its definition formats it.
            $words = $definition->isNumber()
                ? [$definition->format((string) $change->before_value), $definition->format((string) $change->after_value)]
                : array_values($definition->options);
            $allowed = [...$words, MedicationSettingsStore::NOT_YET_REVIEWED, ...array_map(fn (string $w): string => 'Kept: '.$w, $words)];
            $this->assertContains($change->before_text, $allowed);
            $this->assertContains($change->after_text, $allowed);
            $this->assertSame($definition->label, $change->label);
        }
        $renewal = $changes->firstWhere('setting_key', 'renewal_months');
        $this->assertSame('No renewal', $renewal->before_text);
        $this->assertSame('6 months', $renewal->after_text);
    }

    // ─── Fixtures ────────────────────────────────────────────

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4?: int}>  $changes  group, key, value, from, site
     * @return array<string, mixed>
     */
    private function save(string $view, array $changes): array
    {
        return [
            'view' => $view,
            'changes' => array_map(fn (array $c): array => [
                'group' => $c[0],
                'key' => $c[1],
                'site_id' => $c[4] ?? null,
                'value' => $c[2],
                'from' => $c[3],
            ], $changes),
        ];
    }

    private function organisationManager(?string $name = null): User
    {
        return $this->staff(['medications.settings.manage', 'sites.viewAll'], $this->site, $name);
    }

    private function staff(array $permissions, Site $site, ?string $name = null): User
    {
        $user = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
            ...($name ? ['name' => $name] : []),
        ]);
        $role = Role::query()->where('name', 'support_worker')->first();
        if ($role) {
            $user->roles()->syncWithoutDetaching([$role->id]);
        }
        $user->permissionOverrides()->syncWithoutDetaching(
            Permission::query()
                ->whereIn('key', $permissions)
                ->pluck('id')
                ->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])
                ->all(),
        );
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => now()->subMonth(),
            'end_date' => null,
            'is_active' => true,
        ]);

        return $user->fresh();
    }
}
