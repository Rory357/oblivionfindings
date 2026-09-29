<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\UserWitnessPin;
use App\Services\Medication\WitnessPinService;
use App\Services\Medication\WitnessPinSettings;
use Carbon\Carbon;
use Database\Factories\UserFactory;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * PIN-1 (eMAR P00 v5, witness PIN option B): each person's own 6-digit
 * witness PIN, set and changed only by them, reset (never seen) by house and
 * clinical leads, with organisation rules for wrong attempts and lockout.
 */
class WitnessPinTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_09_29_000200_grant_medications_witness_pin_reset.php';

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        // Freeze in UTC: an NZ-zoned test clock makes Eloquent read stored
        // UTC datetimes 13 hours off.
        Carbon::setTestNow(Carbon::parse('2026-09-29 09:30:00', config('app.worker_timezone', 'Pacific/Auckland'))->utc());
        $this->seed(RbacSeeder::class);
        Cache::flush();
        $this->site = Site::factory()->create(['is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_person_sets_their_own_pin_which_is_stored_only_as_a_hash(): void
    {
        $worker = $this->siteStaff('support_worker', ['medications.administer.record'], withPin: false);

        $this->actingAs($worker)
            ->get('/settings/witness-pin')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/witness-pin')
                ->where('witnessPin.status', WitnessPinService::STATUS_NOT_SET)
                ->where('rules.maxAttempts', 5)
                ->where('rules.lockoutMinutes', 15)
                ->where('rules.renewalMonths', null));

        $pins = app(WitnessPinService::class);
        foreach (['12345', '1234567', 'abcdef', '111111', '123456', '654321', '01234 '] as $weak) {
            $this->assertNotNull($pins->formatError($weak), "{$weak} must be refused");
        }
        $this->assertNull($pins->formatError('708142'));

        // The form refuses them too (the route allows 6 tries a minute).
        foreach (['12345', '111111', '123456'] as $weak) {
            $this->actingAs($worker)
                ->from('/settings/witness-pin')
                ->put('/settings/witness-pin', ['pin' => $weak, 'pin_confirmation' => $weak])
                ->assertSessionHasErrors('pin');
        }
        $this->actingAs($worker)
            ->from('/settings/witness-pin')
            ->put('/settings/witness-pin', ['pin' => '482915', 'pin_confirmation' => '482916'])
            ->assertSessionHasErrors('pin');
        $this->assertNull(UserWitnessPin::query()->where('user_id', $worker->id)->first());

        $this->actingAs($worker)
            ->from('/settings/witness-pin')
            ->put('/settings/witness-pin', ['pin' => '708142', 'pin_confirmation' => '708142'])
            ->assertSessionHasNoErrors();

        $pin = UserWitnessPin::query()->where('user_id', $worker->id)->sole();
        $this->assertNotSame('708142', $pin->pin_hash);
        $this->assertTrue(Hash::check('708142', $pin->pin_hash));
        $this->assertArrayNotHasKey('pin_hash', $pin->toArray());
        $this->assertSame(1, AuditLog::query()->where('action', 'medications.witness_pin.set')->count());
        $this->assertStringNotContainsString('708142', AuditLog::query()->get()->toJson());

        // Nothing sent to the browser carries the PIN or its hash.
        $this->actingAs($worker)
            ->get('/settings/witness-pin')
            ->assertInertia(fn (Assert $page) => $page
                ->where('witnessPin.status', WitnessPinService::STATUS_SET)
                ->missing('witnessPin.pin_hash'));
    }

    public function test_changing_needs_the_current_pin_and_a_forgotten_pin_needs_the_login_password(): void
    {
        $worker = $this->siteStaff('support_worker', ['medications.administer.record']);

        $this->actingAs($worker)
            ->from('/settings/witness-pin')
            ->put('/settings/witness-pin', ['pin' => '708142', 'pin_confirmation' => '708142'])
            ->assertSessionHasErrors('current_pin');
        $this->actingAs($worker)
            ->from('/settings/witness-pin')
            ->put('/settings/witness-pin', ['current_pin' => '000001', 'pin' => '708142', 'pin_confirmation' => '708142'])
            ->assertSessionHasErrors(['current_pin' => WitnessPinService::INCORRECT]);
        // A wrong current PIN counts towards the lock like any other.
        $this->assertSame(1, (int) UserWitnessPin::query()->where('user_id', $worker->id)->value('failed_attempts'));

        $this->actingAs($worker)
            ->from('/settings/witness-pin')
            ->put('/settings/witness-pin', [
                'current_pin' => UserFactory::TEST_WITNESS_PIN,
                'pin' => UserFactory::TEST_WITNESS_PIN,
                'pin_confirmation' => UserFactory::TEST_WITNESS_PIN,
            ])
            ->assertSessionHasErrors('pin');
        $this->actingAs($worker)
            ->from('/settings/witness-pin')
            ->put('/settings/witness-pin', [
                'current_pin' => UserFactory::TEST_WITNESS_PIN,
                'pin' => '708142',
                'pin_confirmation' => '708142',
            ])
            ->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('708142', UserWitnessPin::query()->where('user_id', $worker->id)->value('pin_hash')));
        $this->assertSame(1, AuditLog::query()->where('action', 'medications.witness_pin.changed')->count());

        // Forgotten: confirm the login password instead of the old PIN.
        $this->actingAs($worker)
            ->from('/settings/witness-pin')
            ->post('/settings/witness-pin/reset', ['current_password' => 'not-my-password', 'pin' => '593027', 'pin_confirmation' => '593027'])
            ->assertSessionHasErrors('current_password');
        $this->actingAs($worker)
            ->from('/settings/witness-pin')
            ->post('/settings/witness-pin/reset', ['current_password' => 'password', 'pin' => '593027', 'pin_confirmation' => '593027'])
            ->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('593027', UserWitnessPin::query()->where('user_id', $worker->id)->value('pin_hash')));

        // The PIN fields never come back to the form.
        $this->assertArrayNotHasKey('pin', session()->getOldInput());
        $this->assertArrayNotHasKey('current_pin', session()->getOldInput());
    }

    public function test_client_and_family_accounts_have_no_witness_pin(): void
    {
        $client = User::factory()->create(['role' => 'client', 'approved_at' => now()]);

        $this->actingAs($client)->get('/settings/witness-pin')->assertForbidden();
        $this->actingAs($client)
            ->put('/settings/witness-pin', ['pin' => '708142', 'pin_confirmation' => '708142'])
            ->assertForbidden();
    }

    public function test_wrong_pins_survive_a_rolled_back_transaction_and_lock_at_the_limit(): void
    {
        $witness = $this->siteStaff('support_worker', ['medications.controlled.witness']);
        $pins = app(WitnessPinService::class);

        foreach (range(1, 4) as $attempt) {
            try {
                DB::transaction(fn () => $pins->verify($witness, '000001', 'witness_credential'));
                $this->fail('A wrong PIN must be rejected.');
            } catch (ValidationException $rejected) {
                $this->assertSame(WitnessPinService::INCORRECT, $rejected->errors()['witness_credential'][0]);
            }
            $this->assertSame($attempt, (int) UserWitnessPin::query()->where('user_id', $witness->id)->value('failed_attempts'));
        }

        try {
            DB::transaction(fn () => $pins->verify($witness, '000001', 'witness_credential'));
            $this->fail('The fifth wrong PIN must lock it.');
        } catch (ValidationException $locked) {
            $this->assertStringContainsString('unlocks at 9:45 am', $locked->errors()['witness_credential'][0]);
        }
        $this->assertSame(WitnessPinService::STATUS_LOCKED, $pins->status($witness->fresh()));
        $this->assertSame(4, AuditLog::query()->where('action', 'medications.witness_pin.failed')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'medications.witness_pin.locked')->count());

        $this->expectException(ValidationException::class);
        $pins->verify($witness, UserFactory::TEST_WITNESS_PIN, 'witness_credential');
    }

    public function test_the_lock_lifts_after_the_lockout_and_the_organisation_can_tighten_the_rules(): void
    {
        app(WitnessPinSettings::class)->save([
            WitnessPinSettings::MAX_ATTEMPTS => '3',
            WitnessPinSettings::LOCKOUT_MINUTES => '30',
            WitnessPinSettings::RENEWAL_MONTHS => '6',
        ]);
        $witness = $this->siteStaff('support_worker', ['medications.controlled.witness']);
        $pins = app(WitnessPinService::class);

        foreach (range(1, 3) as $attempt) {
            try {
                $pins->verify($witness, '000001', 'witness_credential');
            } catch (ValidationException) {
            }
        }
        $this->assertSame(WitnessPinService::STATUS_LOCKED, $pins->status($witness->fresh()));

        $this->travel(29)->minutes();
        $this->assertSame(WitnessPinService::STATUS_LOCKED, $pins->status($witness->fresh()));
        $this->travel(2)->minutes();
        $pins->verify($witness->fresh(), UserFactory::TEST_WITNESS_PIN, 'witness_credential');

        // Renewal: a PIN older than the organisation's period must be renewed.
        $this->travel(6)->months();
        $this->assertSame(WitnessPinService::STATUS_EXPIRED, $pins->status($witness->fresh()));
    }

    public function test_house_and_clinical_leads_reset_a_colleagues_pin_without_seeing_it(): void
    {
        $lead = $this->siteStaff('team_lead', []);
        $worker = $this->siteStaff('support_worker', ['medications.administer.record']);
        $otherSite = Site::factory()->create(['is_active' => true]);
        $elsewhere = $this->siteStaff('support_worker', ['medications.administer.record'], site: $otherSite);
        $colleague = $this->siteStaff('support_worker', ['medications.administer.record']);

        $this->assertTrue($lead->canDo('medications.witness_pin.reset'));
        $this->assertFalse($colleague->canDo('medications.witness_pin.reset'));

        // A house lead sees only the second-person section, with status only.
        $this->actingAs($lead)
            ->get('/emar/settings')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('emar/Settings')
                ->where('settingsAccess', false)
                ->where('safetyPolicy', null)
                ->where('witnessPin.can_reset', true)
                ->where('witnessPin.can_manage', false)
                ->where('witnessPin.values', ['max_attempts' => '5', 'lockout_minutes' => '15', 'renewal_months' => 'none'])
                ->where('witnessPin.reviewed', ['max_attempts' => false, 'lockout_minutes' => false, 'renewal_months' => false])
                ->where('witnessPin.staff', fn ($rows) => collect($rows)->pluck('id')->contains($worker->id)
                    && ! collect($rows)->pluck('id')->contains($elsewhere->id)
                    && ! collect($rows)->pluck('id')->contains($lead->id)
                    && collect($rows)->every(fn ($row) => ! array_key_exists('pin_hash', (array) $row))));

        $this->actingAs($colleague)->get('/emar/settings')->assertForbidden();
        $this->actingAs($colleague)->post("/emar/settings/witness-pins/{$worker->id}/reset")->assertForbidden();
        $this->actingAs($lead)->post("/emar/settings/witness-pins/{$lead->id}/reset")->assertForbidden();
        $this->actingAs($lead)->post("/emar/settings/witness-pins/{$elsewhere->id}/reset")->assertNotFound();

        $hashBefore = UserWitnessPin::query()->where('user_id', $worker->id)->value('pin_hash');
        $this->actingAs($lead)
            ->from('/emar/settings')
            ->post("/emar/settings/witness-pins/{$worker->id}/reset")
            ->assertRedirect('/emar/settings')
            ->assertSessionHasNoErrors();

        $pin = UserWitnessPin::query()->where('user_id', $worker->id)->sole();
        $this->assertTrue($pin->must_change);
        $this->assertSame($lead->id, (int) $pin->reset_by);
        $this->assertSame($hashBefore, $pin->pin_hash);
        $audit = AuditLog::query()->where('action', 'medications.witness_pin.reset')->sole();
        $this->assertSame($lead->id, (int) $audit->meta['actor_id']);
        $this->assertSame($worker->id, (int) $audit->meta['target_user_id']);

        // Until they choose a new one, the old PIN no longer works.
        try {
            app(WitnessPinService::class)->verify($worker->fresh(), UserFactory::TEST_WITNESS_PIN, 'witness_credential');
            $this->fail('A reset PIN must not be accepted.');
        } catch (ValidationException $reset) {
            $this->assertStringContainsString('witness PIN was reset', $reset->errors()['witness_credential'][0]);
        }
        $this->actingAs($worker)
            ->get('/settings/witness-pin')
            ->assertInertia(fn (Assert $page) => $page
                ->where('witnessPin.status', WitnessPinService::STATUS_RESET)
                ->where('witnessPin.resetBy', $lead->name));
        $this->actingAs($worker)
            ->from('/settings/witness-pin')
            ->put('/settings/witness-pin', ['pin' => '708142', 'pin_confirmation' => '708142'])
            ->assertSessionHasNoErrors();
        app(WitnessPinService::class)->verify($worker->fresh(), '708142', 'witness_credential');
    }

    public function test_only_an_organisation_wide_settings_manager_saves_the_pin_rules(): void
    {
        $payload = ['max_attempts' => '3', 'lockout_minutes' => '30', 'renewal_months' => '12'];

        $siteManager = $this->siteStaff('support_worker', ['medications.settings.manage']);
        $this->actingAs($siteManager)
            ->put('/emar/settings/witness-pin-rules', $payload)
            ->assertForbidden();

        $orgManager = $this->siteStaff('support_worker', ['medications.settings.manage', 'sites.viewAll']);
        $this->actingAs($orgManager)
            ->from('/emar/settings')
            ->put('/emar/settings/witness-pin-rules', [...$payload, 'max_attempts' => '99'])
            ->assertSessionHasErrors('max_attempts');

        $this->actingAs($orgManager)
            ->from('/emar/settings')
            ->put('/emar/settings/witness-pin-rules', $payload)
            ->assertSessionHasNoErrors();

        $settings = app(WitnessPinSettings::class);
        $this->assertSame(3, $settings->maxAttempts());
        $this->assertSame(30, $settings->lockoutMinutes());
        $this->assertSame(12, $settings->renewalMonths());
        $audit = AuditLog::query()->where('action', 'medications.witness_pin_rules.updated')->sole();
        // JSON columns don't keep key order, so compare by key.
        $this->assertEquals(['max_attempts' => '5', 'lockout_minutes' => '15', 'renewal_months' => 'none'], $audit->meta['before']);
        $this->assertEquals($payload, $audit->meta['after']);

        $this->actingAs($orgManager)
            ->get('/emar/settings')
            ->assertInertia(fn (Assert $page) => $page
                ->where('settingsAccess', true)
                ->where('witnessPin.can_manage', true)
                ->where('witnessPin.values', $payload)
                ->where('witnessPin.reviewed', ['max_attempts' => true, 'lockout_minutes' => true, 'renewal_months' => true]));
    }

    public function test_staff_see_the_witness_pin_setting_and_leads_see_reset(): void
    {
        $worker = $this->siteStaff('support_worker', ['medications.administer.record']);
        $lead = $this->siteStaff('team_lead', []);

        $this->actingAs($worker)
            ->get('/settings/witness-pin')
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.can.medications.witnessPin', true)
                ->where('auth.can.medications.witnessPinReset', false));
        $this->actingAs($lead)
            ->get('/settings/witness-pin')
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.can.medications.witnessPinReset', true));
    }

    public function test_the_seeder_and_the_grant_migration_give_reset_to_admin_house_leads_and_clinical_leads(): void
    {
        foreach (['admin', 'team_lead', 'clinical_lead'] as $role) {
            $this->assertContains('medications.witness_pin.reset', $this->roleKeys($role));
        }
        $this->assertNotContains('medications.witness_pin.reset', $this->roleKeys('support_worker'));

        // A deployed database never ran the seeder: the migration adds the key.
        $permissionId = Permission::query()->where('key', 'medications.witness_pin.reset')->value('id');
        DB::table('role_permission')->where('permission_id', $permissionId)->delete();
        Permission::query()->whereKey($permissionId)->delete();

        (require base_path(self::MIGRATION))->up();
        foreach (['admin', 'team_lead', 'clinical_lead'] as $role) {
            $this->assertContains('medications.witness_pin.reset', $this->roleKeys($role));
        }

        $grants = DB::table('role_permission')->count();
        (require base_path(self::MIGRATION))->up();
        $this->assertSame($grants, DB::table('role_permission')->count());
        $this->assertSame(1, Permission::query()->where('key', 'medications.witness_pin.reset')->count());
    }

    /** @return list<string> */
    private function roleKeys(string $role): array
    {
        return Role::query()->where('name', $role)->firstOrFail()->permissions()->pluck('key')->all();
    }

    /** @param list<string> $permissions */
    private function siteStaff(string $role, array $permissions, bool $withPin = true, ?Site $site = null): User
    {
        $factory = User::factory();
        $user = ($withPin ? $factory : $factory->withoutWitnessPin())
            ->create(['role' => $role, 'approved_at' => now()]);
        $roleModel = Role::query()->where('name', $role)->first();
        if ($roleModel) {
            $user->roles()->syncWithoutDetaching([$roleModel->id]);
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
            'primary_site_id' => ($site ?? $this->site)->id,
            'secondary_site_ids' => [],
            'start_date' => now()->subMonth(),
            'end_date' => null,
            'is_active' => true,
        ]);

        return $user->fresh();
    }
}
