<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Http\Responses\LoginResponse;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\MedicationExternalClinician;
use App\Models\MedicationExternalGrant;
use App\Models\MedicationExternalProposal;
use App\Models\MedicationOrderRevision;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\ExternalClinical\ExternalClinicalAccess;
use App\Services\Medication\ExternalClinical\ExternalClinicalProposals;
use App\Services\Medication\MedicationOrderLifecycleService;
use Carbon\Carbon;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CommittedFixtureCleanup;
use Tests\Support\ConnectedCareSwitches;
use Tests\Support\ExternalClinicalFixtures;
use Tests\TestCase;

final class ExternalClinicalAccessTest extends TestCase
{
    use ExternalClinicalFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // D4: the portal and handovers run only while switched on. These single-
        // person flows predate the two-person rule, switched back on in the test_b10_ tests below.
        ConnectedCareSwitches::on('prescriber_portal', 'provider_transfers');
        ConnectedCareSwitches::off('two_person_identity', 'two_person_handover');
        $this->connectedFixtures();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_portal_shares_only_current_named_people_and_minimum_external_props(): void
    {
        $order = $this->chart();
        $hidden = Client::factory()->create(['site_id' => $this->site->id]);
        $this->chart(['client_id' => $hidden->id, 'name' => 'Private other medicine']);
        $this->actingAs($this->clinician)->get('/clinical-portal?client_id='.$this->person->id)->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('emar/ClinicalPortal')->has('people', 1)->where('people.0.id', $this->person->id)
                ->has('selected_client.medications', 1)->where('selected_client.medications.0.id', $order->id)
                ->where('auth.can', [])->missing('auth.unreadMessageCount')->missing('auth.portalClients')
                ->missing('calendarFeedUrl')->missing('financeHubCounts')->missing('notifications')->missing('itNavigation'));
        $this->get('/clinical-portal?client_id='.$hidden->id)->assertNotFound();
    }

    public function test_portal_current_page_omits_people_and_proposals_revoked_after_an_older_read_snapshot(): void
    {
        app(ExternalClinicalProposals::class)->submit($this->clinician, $this->person->id, [
            'kind' => 'start', 'prescription' => $this->prescription(), 'reason' => 'Review', 'request_key' => 'revoked-page',
        ], null);
        $primary = DB::connection();
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('mysql', $primary->getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME));
        $this->assertSame('127.0.0.1', $primary->getConfig('host'));
        $this->assertGreaterThan(0, getmypid());
        $this->assertContains($primary->getDatabaseName(), [
            'oblivion_findings_codex_test_'.getmypid(),
            'emar_connected_external_20261007_'.getmypid(),
        ]);
        $this->assertSame(self::$isolatedMysqlDatabase, $primary->getDatabaseName());
        $this->assertSame($primary->getDatabaseName(), $primary->selectOne('SELECT DATABASE() AS owned_database')->owned_database);
        $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
        DB::commit();
        $primaryName = DB::getDefaultConnection();
        $writerName = 'external_portal_revoke_writer';
        $writerConfig = config('database.connections.'.$primaryName);
        $writerConfig['name'] = $writerName;
        $this->assertSame('127.0.0.1', $writerConfig['host']);
        config(['database.connections.'.$writerName => $writerConfig]);
        $writer = DB::connection($writerName);
        $this->assertSame($writerName, $writer->getName());
        $this->assertNotSame($primary->getPdo(), $writer->getPdo());
        $this->assertSame($primary->getDatabaseName(), $writer->getDatabaseName());
        $primary->beginTransaction();
        try {
            $this->assertNull($primary->table('medication_external_grants')->where('id', $this->grant->id)->value('revoked_at'));
            DB::setDefaultConnection($writerName);
            try {
                app(ExternalClinicalAccess::class)->revokeGrant($this->manager, $this->grant->id, ['reason' => 'Current access withdrawn']);
                $this->assertSame(0, $writer->transactionLevel());
                $this->assertNotNull($writer->table('medication_external_grants')->where('id', $this->grant->id)->value('revoked_at'));
            } finally {
                DB::setDefaultConnection($primaryName);
            }
            $this->assertNull($primary->table('medication_external_grants')->where('id', $this->grant->id)->value('revoked_at'));
            $this->actingAs($this->clinician)->get('/clinical-portal')->assertOk()
                ->assertInertia(fn (Assert $page) => $page->has('people', 0)->has('proposals', 0)->where('pagination.proposals.total', 0));
        } finally {
            DB::setDefaultConnection($primaryName);
            while ($primary->transactionLevel() > 0) {
                $primary->rollBack();
            }
            DB::purge($writerName);
        }
    }

    public function test_forged_internal_roles_and_permission_grants_never_escape_portal(): void
    {
        $this->clinician->permissionOverrides()->syncWithoutDetaching(Permission::all()->mapWithKeys(fn ($p) => [$p->id => ['allowed' => true]])->all());
        $role = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Synthetic administrator', 'level' => 100, 'type' => 'system']);
        $role->permissions()->sync(Permission::all()->modelKeys());
        $this->clinician->roles()->attach($role);
        foreach (['/dashboard', '/my-day', '/emar', '/clients', '/settings/access', '/emar/prescriptions', '/emar/pdf/mar-chart?client_id='.$this->person->id, '/portal'] as $uri) {
            $this->actingAs($this->clinician)->getJson($uri)->assertForbidden();
        }
        $this->getJson('/api/hr/employees')->assertUnauthorized();
        $this->assertFalse($this->clinician->canDo('medications.view'));
        $this->assertFalse($this->clinician->canBeImpersonated());
        $this->assertFalse(User::staff()->whereKey($this->clinician->id)->exists());
    }

    public function test_identity_revocation_keeps_internal_isolation(): void
    {
        $this->manager = $this->allowGlobalIdentityWithdrawal($this->manager, 'clinical.accessAllSites');
        app(ExternalClinicalAccess::class)->revokeIdentity($this->manager, $this->identity->id, ['client_id' => $this->person->id, 'reason' => 'Identity withdrawn']);
        $this->actingAs($this->clinician)->getJson('/clinical-portal?client_id='.$this->person->id)->assertForbidden();
        $this->getJson('/dashboard')->assertForbidden();
        $this->assertTrue($this->clinician->refresh()->external_clinical_account);
    }

    public static function siteScopedIdentityManagers(): array
    {
        return [
            'original one-house verifier' => ['verifier'],
            'another one-house manager with current grant' => ['manager'],
            'former verifier moved to another house' => ['former_verifier'],
        ];
    }

    #[DataProvider('siteScopedIdentityManagers')]
    public function test_site_scoped_managers_cannot_withdraw_a_global_identity(string $kind): void
    {
        Notification::fake();
        Mail::fake();
        $actor = $kind === 'manager' ? $this->connectedStaff() : $this->manager;
        $client = $this->person;
        if ($kind === 'former_verifier') {
            $newSite = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
            $client = Client::factory()->create(['site_id' => $newSite->id, 'service_context_id' => $this->person->service_context_id, 'status' => 'active']);
            $actor->hrEmployeeProfile->forceFill(['primary_site_id' => $newSite->id, 'secondary_site_ids' => []])->save();
            $actor = $actor->fresh();
        }
        $identity = $this->identity->fresh()->getRawOriginal();
        $grant = $this->grant->fresh()->getRawOriginal();
        $this->actingAs($actor)->get('/emar/connected-care?client_id='.$client->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can.manage_access', true)->where('can.revoke_identity', false));
        $this->postJson('/emar/connected-care/clinicians/'.$this->identity->id.'/revoke', [
            'client_id' => $client->id, 'reason' => 'Requested identity withdrawal',
        ])->assertForbidden();
        $this->assertSame($identity, $this->identity->fresh()->getRawOriginal());
        $this->assertSame($grant, $this->grant->fresh()->getRawOriginal());
        $this->assertSame(0, AuditLog::query()->where('action', 'medications.external.identity_revoked')->count());
        $this->actingAs($this->clinician)->get('/clinical-portal?client_id='.$this->person->id)->assertOk();

        $this->actingAs($actor)->postJson('/emar/connected-care/grants/'.$this->grant->id.'/revoke', [
            'reason' => 'Withdraw this person access only',
        ])->assertStatus($kind === 'former_verifier' ? 404 : 200);
        $this->assertSame($identity, $this->identity->fresh()->getRawOriginal());
        if ($kind === 'former_verifier') {
            $this->assertSame($grant, $this->grant->fresh()->getRawOriginal());
        } else {
            $this->assertNotNull($this->grant->fresh()->revoked_at);
            $this->assertSame($actor->id, $this->grant->fresh()->revoked_by);
            $this->assertSame(1, AuditLog::query()->where('action', 'medications.external.access_revoked')->count());
        }
        Notification::assertNothingSent();
        Mail::assertNothingSent();
    }

    public static function globalIdentityManagers(): array
    {
        return [
            'clinical all-sites, never granted' => ['clinical.accessAllSites', false],
            'site all-sites, never granted' => ['sites.viewAll', false],
            'clinical all-sites, multi-house identity' => ['clinical.accessAllSites', true],
            'site all-sites, multi-house identity' => ['sites.viewAll', true],
        ];
    }

    #[DataProvider('globalIdentityManagers')]
    public function test_explicit_current_all_sites_manager_can_withdraw_never_granted_and_multi_house_identities(string $permission, bool $multiHouse): void
    {
        Notification::fake();
        Mail::fake();
        $actor = $this->allowGlobalIdentityWithdrawal($this->connectedStaff(), $permission);
        $profile = $this->identity;
        if ($multiHouse) {
            $otherSite = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
            $otherPerson = Client::factory()->create(['site_id' => $otherSite->id, 'service_context_id' => $this->person->service_context_id, 'status' => 'active']);
            $this->actingAs($actor)->postJson('/emar/connected-care/grants', [
                'client_id' => $otherPerson->id, 'clinician_id' => $profile->id, 'purpose' => 'Explicit review for another house',
                'expires_at' => now()->addDays(7)->toIso8601String(), 'can_propose' => true, 'include_controlled' => false,
            ])->assertSuccessful();
            $this->assertNotSame($actor->id, $profile->verified_by);
            $this->actingAs($this->clinician)->get('/clinical-portal')->assertOk()
                ->assertInertia(fn (Assert $page) => $page->has('people', 2));
        } else {
            $creator = $this->connectedStaff();
            $response = $this->actingAs($creator)->postJson('/emar/connected-care/clinicians', [
                'client_id' => $this->person->id, 'name' => 'Dr Never Granted', 'email' => 'never-granted-fictional@example.test',
                'provider_name' => 'Fictional reviewed practice', 'registration_authority' => 'Fictional clinical register',
                'registration_number' => 'FICTIONAL-NEVER-GRANTED', 'identity_evidence' => 'Fictional independently reviewed identity',
                'identity_confirmed' => true, 'expires_at' => now()->addDays(90)->toIso8601String(),
            ])->assertSuccessful();
            $profile = MedicationExternalClinician::query()->findOrFail($response->json('id'));
            $this->assertSame(0, $profile->grants()->count());
            $this->assertSame($creator->id, $profile->verified_by);
            $this->assertNotSame($actor->id, $profile->verified_by);
            $this->actingAs($creator)->get('/emar/connected-care?client_id='.$this->person->id)->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('can.revoke_identity', false)
                    ->where('clinicians', fn ($rows) => collect($rows)->contains('id', $profile->id)));
            $this->actingAs($this->manager)->get('/emar/connected-care?client_id='.$this->person->id)->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('can.revoke_identity', false)
                    ->where('clinicians', fn ($rows) => ! collect($rows)->contains('id', $profile->id)));
        }
        $grants = MedicationExternalGrant::query()->orderBy('id')->get()->map->getRawOriginal()->all();
        $verifiedBy = $profile->verified_by;
        $user = $profile->user;
        $this->actingAs($actor)->get('/emar/connected-care?client_id='.$this->person->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can.manage_access', true)->where('can.revoke_identity', true)
                ->where('clinicians', fn ($rows) => collect($rows)->contains('id', $profile->id)));
        $this->postJson('/emar/connected-care/clinicians/'.$profile->id.'/revoke', [
            'client_id' => $this->person->id, 'reason' => 'Identity approval withdrawn across all houses',
        ])->assertSuccessful()->assertJsonPath('success', true)->assertJsonPath('id', $profile->id);
        $fresh = $profile->fresh();
        $this->assertNotNull($fresh->revoked_at);
        $this->assertSame($actor->id, $fresh->revoked_by);
        $this->assertSame($verifiedBy, $fresh->verified_by);
        $this->assertSame('Identity approval withdrawn across all houses', $fresh->revoke_reason);
        $this->assertSame($grants, MedicationExternalGrant::query()->orderBy('id')->get()->map->getRawOriginal()->all());
        $audit = AuditLog::query()->where('action', 'medications.external.identity_revoked')->sole();
        $this->assertSame($actor->id, $audit->user_id);
        $this->assertSame($profile->id, $audit->auditable_id);
        $this->actingAs($user)->getJson('/clinical-portal')->assertForbidden();
        $this->getJson('/dashboard')->assertForbidden();
        $this->assertTrue($user->fresh()->external_clinical_account);
        Notification::assertNothingSent();
        Mail::assertNothingSent();
    }

    public static function withdrawnIdentityAuthority(): array
    {
        return [
            'clinical all-sites allowance removed' => ['clinical.accessAllSites', 'clinical.accessAllSites'],
            'site all-sites allowance removed' => ['sites.viewAll', 'sites.viewAll'],
            'action removed with clinical all-sites' => ['clinical.accessAllSites', 'medications.external.manage'],
            'action removed with site all-sites' => ['sites.viewAll', 'medications.external.manage'],
        ];
    }

    #[DataProvider('withdrawnIdentityAuthority')]
    public function test_identity_withdrawal_rechecks_current_authority_instead_of_cached_allowances(string $globalPermission, string $removed): void
    {
        $actor = $this->allowGlobalIdentityWithdrawal($this->manager, $globalPermission)->load(['permissionOverrides', 'roles.permissions']);
        $this->assertTrue(app(ExternalClinicalAccess::class)->canRevokeIdentity($actor));
        $permission = Permission::query()->where('key', $removed)->sole();
        $this->assertSame(1, DB::table('permission_user')->where('user_id', $actor->id)->where('permission_id', $permission->id)->update(['allowed' => false]));
        // The submitted request actor deliberately still contains the earlier allow evidence.
        $this->assertTrue(app(ExternalClinicalAccess::class)->canRevokeIdentity($actor));
        $identity = $this->identity->fresh()->getRawOriginal();
        $grant = $this->grant->fresh()->getRawOriginal();
        $this->actingAs($actor->fresh())->get('/emar/connected-care?client_id='.$this->person->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can.revoke_identity', false));
        $this->actingAs($actor)->postJson('/emar/connected-care/clinicians/'.$this->identity->id.'/revoke', [
            'client_id' => $this->person->id, 'reason' => 'Withdraw using stale permission evidence',
        ])->assertForbidden();
        $this->assertSame($identity, $this->identity->fresh()->getRawOriginal());
        $this->assertSame($grant, $this->grant->fresh()->getRawOriginal());
        $this->assertSame(0, AuditLog::query()->where('action', 'medications.external.identity_revoked')->count());
    }

    public static function unavailableGlobalIdentityManagerScope(): array
    {
        return ['employment ended' => ['employment'], 'current person site archived' => ['site']];
    }

    #[DataProvider('unavailableGlobalIdentityManagerScope')]
    public function test_global_identity_permission_does_not_bypass_current_staff_and_person_site_guards(string $unavailable): void
    {
        $actor = $this->allowGlobalIdentityWithdrawal($this->manager, 'clinical.accessAllSites');
        if ($unavailable === 'employment') {
            $actor->hrEmployeeProfile->forceFill(['is_active' => false])->save();
        } else {
            $this->site->forceFill(['archived' => true, 'archived_at' => now()])->save();
        }
        $identity = $this->identity->fresh()->getRawOriginal();
        $grant = $this->grant->fresh()->getRawOriginal();
        $this->actingAs($actor)->postJson('/emar/connected-care/clinicians/'.$this->identity->id.'/revoke', [
            'client_id' => $this->person->id, 'reason' => 'Withdraw without current staff and person scope',
        ])->assertNotFound();
        $this->assertSame($identity, $this->identity->fresh()->getRawOriginal());
        $this->assertSame($grant, $this->grant->fresh()->getRawOriginal());
        $this->assertSame(0, AuditLog::query()->where('action', 'medications.external.identity_revoked')->count());
    }

    /** EA-083 + EA-085: a portal chart view is a logged disclosure and says what it leaves out. */
    public function test_b10_portal_chart_view_is_logged_and_says_how_many_controlled_medicines_are_hidden(): void
    {
        $this->chart();
        $this->chart(['name' => 'Synthetic controlled medicine', 'controlled_drug' => true]);
        $this->actingAs($this->clinician)->get('/clinical-portal?client_id='.$this->person->id)->assertOk()
            ->assertInertia(fn (Assert $p) => $p->has('selected_client.medications', 1)
                ->where('selected_client.hidden_controlled_count', 1)
                ->where('selected_client.allergy_status.status', 'none'));
        $view = \App\Models\MedicationEvent::query()->where('kind', 'external.chart_viewed')->sole();
        $this->assertSame((int) $this->clinician->id, (int) $view->actor_id);
        $this->assertSame((int) $this->person->id, (int) $view->client_id);
        $this->assertSame((string) $this->grant->id, (string) $view->subject_id);
        $this->actingAs($this->manager)->get('/emar/connected-care?client_id='.$this->person->id)->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('grants.0.views', 1));
    }

    /** EA-104: with the two-person rule on (its default), whoever verified a prescriber doesn't also grant them a chart. */
    public function test_b10_the_verifier_cannot_also_grant_chart_access(): void
    {
        ConnectedCareSwitches::on('two_person_identity');
        app(ExternalClinicalAccess::class)->revokeGrant($this->manager, $this->grant->id, ['reason' => 'Synthetic regrant check']);
        $input = ['clinician_id' => $this->identity->id, 'client_id' => $this->person->id, 'purpose' => 'Medication review',
            'expires_at' => now()->addDays(20)->toIso8601String(), 'can_propose' => false, 'include_controlled' => false];
        $this->actingAs($this->manager)->postJson('/emar/connected-care/grants', $input)->assertUnprocessable()->assertJsonValidationErrors('clinician_id');
        $this->assertSame(1, MedicationExternalGrant::query()->count());
        $this->actingAs($this->connectedStaff())->postJson('/emar/connected-care/grants', $input)->assertSuccessful();
        $this->assertSame(2, MedicationExternalGrant::query()->count());
    }

    /** EA-084 + EA-025: the prescriber sees only the reply; the request alerts order managers until decided. */
    public function test_b10_internal_note_stays_internal_and_a_request_alerts_until_decided(): void
    {
        $proposal = app(ExternalClinicalProposals::class)->submit($this->clinician, $this->person->id, [
            'kind' => 'start', 'prescription' => $this->prescription(), 'reason' => 'Start for review', 'request_key' => 'b10-note',
        ], null);
        $alert = \App\Models\MedicationAlert::query()->where('type', 'prescriberRequest')->where('client_id', $this->person->id)->sole();
        $this->assertNotNull($alert->open_key);
        $this->actingAs($this->manager)->get('/emar/connected-care')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('selected_client', null)->where('proposals.0.id', $proposal->id));

        $this->postJson('/emar/connected-care/proposals/'.$proposal->id.'/decision', [
            'decision' => 'reject', 'decision_note' => 'Please phone the house first.', 'internal_note' => 'Synthetic family concern note',
        ])->assertSuccessful();

        $this->assertNull($alert->fresh()->open_key);
        $this->actingAs($this->manager)->get('/emar/connected-care?client_id='.$this->person->id)->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('proposals.0.internal_note', 'Synthetic family concern note'));
        $portal = $this->actingAs($this->clinician)->get('/clinical-portal')->assertOk();
        $portal->assertInertia(fn (Assert $p) => $p->where('proposals.0.decision_note', 'Please phone the house first.'));
        $this->assertStringNotContainsString('Synthetic family concern note', (string) $portal->getContent());
    }

    private function allowGlobalIdentityWithdrawal(User $actor, string $permission): User
    {
        $definition = Permission::firstOrCreate(['key' => $permission], ['description' => 'Explicit synthetic all-sites authority', 'group' => 'clinical']);
        $actor->permissionOverrides()->syncWithoutDetaching([$definition->id => ['allowed' => true]]);

        return $actor->fresh();
    }

    public function test_grant_revocation_expiry_identity_expiry_and_site_move_deny_direct_identifiers(): void
    {
        $this->grant->forceFill(['expires_at' => now()->subSecond()])->save();
        $this->actingAs($this->clinician)->getJson('/clinical-portal?client_id='.$this->person->id)->assertNotFound();
        $this->grant->forceFill(['expires_at' => now()->addDay(), 'revoked_at' => now()])->save();
        $this->getJson('/clinical-portal?client_id='.$this->person->id)->assertNotFound();
        $this->grant->forceFill(['revoked_at' => null])->save();
        $this->identity->forceFill(['expires_at' => now()->subSecond()])->save();
        $this->getJson('/clinical-portal?client_id='.$this->person->id)->assertForbidden();
        $this->identity->forceFill(['expires_at' => now()->addDay()])->save();
        $other = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $this->person->update(['site_id' => $other->id]);
        $this->getJson('/clinical-portal?client_id='.$this->person->id)->assertNotFound();
    }

    public function test_current_account_setup_and_identity_collisions_fail_closed(): void
    {
        $this->clinician->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($this->clinician)->getJson('/clinical-portal')->assertForbidden();
        $this->clinician->forceFill(['email_verified_at' => now(), 'two_factor_confirmed_at' => null])->save();
        $this->getJson('/clinical-portal')->assertForbidden();
        $this->clinician->forceFill(['two_factor_confirmed_at' => now()])->save();
        HrEmployeeProfile::factory()->create(['user_id' => $this->clinician->id, 'primary_site_id' => $this->site->id]);
        $this->getJson('/clinical-portal')->assertForbidden();
    }

    public function test_internal_staff_cannot_use_external_portal_without_dedicated_identity(): void
    {
        $this->actingAs($this->manager)->getJson('/clinical-portal')->assertNotFound();
    }

    public function test_fresh_account_can_request_password_reset_and_is_not_an_internal_account(): void
    {
        Notification::fake();
        $data = ['client_id' => $this->person->id, 'name' => 'Dr Fresh', 'email' => 'fresh-clinician@example.test',
            'provider_name' => 'Named practice', 'registration_authority' => 'Clinical register', 'registration_number' => 'FRESH-123',
            'identity_evidence' => 'Identity independently checked', 'identity_confirmed' => true, 'expires_at' => now()->addDays(90)->toIso8601String()];
        $this->actingAs($this->manager)->postJson('/emar/connected-care/clinicians', $data)->assertSuccessful();
        $created = User::where('email', $data['email'])->firstOrFail();
        $this->assertTrue($created->external_clinical_account);
        $this->assertNull($created->email_verified_at);
        Notification::assertNothingSent();
        $this->app['auth']->forgetGuards();
        $this->post('/forgot-password', ['email' => $data['email']])->assertRedirect()->assertSessionHasNoErrors();
        Notification::assertSentTo($created, ResetPassword::class);
        $this->actingAs($this->manager)->postJson('/emar/connected-care/clinicians', [...$data, 'email' => $this->manager->email])->assertUnprocessable();
        $this->assertFalse($this->manager->refresh()->external_clinical_account);
    }

    public function test_unverified_external_sign_in_opens_canonical_notice_and_resend_but_no_internal_routes(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->withoutTwoFactor()->create([
            'role' => 'external_clinician', 'external_clinical_account' => true,
        ]);

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('/clinical-portal')->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($user);
        $this->get('/clinical-portal')->assertRedirect(route('verification.notice'));
        $this->get(route('verification.notice'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('auth/verify-email')
                ->where('auth.user.role', 'external_clinician')->where('auth.can', [])
                ->missing('auth.unreadMessageCount')->missing('auth.portalClients')->missing('notifications'));
        $this->from(route('verification.notice'))->post(route('verification.send'))
            ->assertRedirect(route('verification.notice'))->assertSessionHas('status', 'verification-link-sent');
        Notification::assertSentTo($user, VerifyEmail::class);
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        $this->getJson('/clinical-portal')->assertForbidden()
            ->assertJson(['message' => 'Verify your email before opening clinical records.']);
        foreach (['/dashboard', '/my-day', '/emar', '/clients', '/settings/access', '/portal'] as $uri) {
            $this->getJson($uri)->assertForbidden();
        }
    }

    public function test_external_login_ignores_internal_intended_destination(): void
    {
        $request = Request::create('/login', 'POST');
        $request->setLaravelSession($this->app['session']->driver());
        $request->session()->put('url.intended', '/settings/access');
        $request->setUserResolver(fn () => $this->clinician);
        $this->assertSame(url('/clinical-portal'), app(LoginResponse::class)->toResponse($request)->getTargetUrl());
        $this->assertFalse($request->session()->has('url.intended'));
        $request->session()->put('url.intended', '/dashboard');
        $mfaResponse = app(TwoFactorLoginResponse::class);
        $this->assertInstanceOf(\App\Http\Responses\TwoFactorLoginResponse::class, $mfaResponse);
        $this->assertSame(url('/clinical-portal'), $mfaResponse->toResponse($request)->getTargetUrl());
        $this->assertFalse($request->session()->has('url.intended'));
    }

    public function test_connected_care_preserves_requested_site_and_rejects_conflicting_person_context(): void
    {
        $this->actingAs($this->manager)->get('/emar/connected-care?'.http_build_query(['client_id' => $this->person->id, 'site_id' => $this->site->id, 'return_to' => '/emar/rounds?site_id='.$this->site->id]))
            ->assertOk()->assertInertia(fn ($page) => $page->where('filters.site_id', $this->site->id)->where('filters.return_to', '/emar/rounds?site_id='.$this->site->id)->where('selected_client.id', $this->person->id));
        $other = Site::factory()->create(['is_active' => true]);
        $this->getJson('/emar/connected-care?'.http_build_query(['client_id' => $this->person->id, 'site_id' => $other->id]))->assertNotFound();
        $this->getJson('/emar/connected-care?client_id=invalid')->assertUnprocessable();
        $this->getJson('/emar/connected-care?site_id=-1')->assertUnprocessable();
    }

    public function test_proposal_is_immutable_review_evidence_and_acceptance_waits_for_independent_check(): void
    {
        $order = $this->chart();
        $input = ['kind' => 'change', 'medication_id' => $order->id, 'expected_version' => 1,
            'prescription' => $this->prescription(['dosage' => '20 mg']), 'reason' => 'Source clinical review', 'request_key' => 'change-1'];
        $this->actingAs($this->clinician)->postJson('/clinical-portal/people/'.$this->person->id.'/proposals', $input)->assertSuccessful();
        $proposal = MedicationExternalProposal::firstOrFail();
        $this->assertSame('10 mg', $order->refresh()->dosage);
        $this->assertSame(0, MedicationOrderRevision::count());
        $this->assertNotSame($proposal->prescription['dosage'], DB::table('medication_external_proposals')->value('prescription'));
        $decision = ['decision' => 'accept', 'decision_note' => 'Source reviewed and confirmed', 'source_confirmed' => true,
            'source' => $this->sourceInput(), 'source_file' => UploadedFile::fake()->create('prescription.pdf', 8, 'application/pdf')];
        $accepted = $this->actingAs($this->manager)->postJson('/emar/connected-care/proposals/'.$proposal->id.'/decision', $decision)->assertSuccessful()
            ->assertJsonPath('order_id', $order->id);
        $this->assertTrue($this->manager->canDo('medications.orders.manage'));
        $this->assertTrue($this->manager->canDo('medications.orders.verify'));
        $this->assertStringContainsString('order_id='.$order->id, $accepted->json('order_url'));
        parse_str(parse_url($accepted->json('order_url'), PHP_URL_QUERY), $authorTarget);
        $this->assertSame('view', $authorTarget['action']);
        $this->actingAs($this->manager)->get($accepted->json('order_url'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('open_order_id', $order->id)
                ->where('prefill_client_id', $this->person->id)->where('open_order_action', 'view'));
        $persisted = $this->actingAs($this->manager)->get('/emar/connected-care?client_id='.$this->person->id)->assertOk();
        $this->assertSame($order->id, $persisted->inertiaProps('proposals.0.order_id'));
        $this->assertSame($accepted->json('order_url'), $persisted->inertiaProps('proposals.0.order_url'));
        $this->assertSame('10 mg', $order->refresh()->dosage);
        $this->assertSame('verified', $order->approval_status);
        $revision = MedicationOrderRevision::findOrFail($proposal->refresh()->revision_id);
        $this->assertSame('pending', $revision->status);
        $this->assertSame($this->manager->id, (int) $revision->entered_by);
        $pendingEvidence = $revision->getRawOriginal();
        $this->actingAs($this->manager)->post('/emar/order-revisions/'.$revision->id.'/check', ['source_matches' => true,
            'dose_route_times_checked' => true, 'allergies_interactions_checked' => true])
            ->assertRedirect()->assertSessionHasErrors('checker');
        $this->assertSame($pendingEvidence, $revision->refresh()->getRawOriginal());
        $this->assertSame('10 mg', $order->refresh()->dosage);
        $checker = $this->connectedStaff();
        $forChecker = $this->actingAs($checker)->get('/emar/connected-care?client_id='.$this->person->id)->assertOk();
        $checkerUrl = $forChecker->inertiaProps('proposals.0.order_url');
        parse_str(parse_url($checkerUrl, PHP_URL_QUERY), $checkerTarget);
        $this->assertSame('check', $checkerTarget['action']);
        $this->assertSame((string) $order->id, $checkerTarget['order_id']);
        $this->get($checkerUrl)->assertOk()->assertInertia(fn ($page) => $page
            ->where('open_order_id', $order->id)->where('open_order_action', 'check')->where('open_check_mode', 'independent'));
        $this->post('/emar/order-revisions/'.$revision->id.'/check', ['source_matches' => true,
            'dose_route_times_checked' => true, 'allergies_interactions_checked' => true])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('20 mg', $order->refresh()->dosage);
        $this->assertSame($checker->id, (int) $revision->refresh()->checked_by);
        $completed = $this->get('/emar/connected-care?client_id='.$this->person->id)->assertOk();
        parse_str(parse_url($completed->inertiaProps('proposals.0.order_url'), PHP_URL_QUERY), $completedTarget);
        $this->assertSame('view', $completedTarget['action']);
    }

    public function test_accepted_start_proposal_opens_author_view_and_independent_checker_can_publish_initial_version(): void
    {
        $submitted = $this->actingAs($this->clinician)->postJson('/clinical-portal/people/'.$this->person->id.'/proposals', [
            'kind' => 'start', 'prescription' => $this->prescription(), 'reason' => 'Start prescribed medicine',
            'request_key' => 'start-continuation',
        ])->assertSuccessful();
        $proposal = MedicationExternalProposal::findOrFail($submitted->json('id'));
        $accepted = $this->actingAs($this->manager)->postJson('/emar/connected-care/proposals/'.$proposal->id.'/decision', [
            'decision' => 'accept', 'decision_note' => 'Source confirmed', 'source_confirmed' => true,
            'source' => $this->sourceInput(), 'source_file' => UploadedFile::fake()->create('prescription.pdf', 8, 'application/pdf'),
        ])->assertSuccessful();
        $revision = MedicationOrderRevision::with('version')->findOrFail($proposal->refresh()->revision_id);
        $order = ClientMedication::findOrFail($revision->client_medication_id);
        $this->assertSame(1, (int) $order->version);
        $this->assertSame(1, (int) $revision->base_version);
        $this->assertSame(1, (int) $revision->version->version_number);
        $this->assertSame('pending_verification', $order->approval_status);
        $this->assertSame($this->manager->id, (int) $revision->entered_by);
        $accepted->assertJsonPath('order_id', $order->id);
        parse_str(parse_url($accepted->json('order_url'), PHP_URL_QUERY), $authorTarget);
        $this->assertSame('view', $authorTarget['action']);
        $this->get($accepted->json('order_url'))->assertOk()->assertInertia(fn ($page) => $page
            ->where('open_order_id', $order->id)->where('open_order_action', 'view'));

        $checker = $this->connectedStaff();
        $response = $this->actingAs($checker)->get('/emar/connected-care?client_id='.$this->person->id)->assertOk();
        $checkerUrl = $response->inertiaProps('proposals.0.order_url');
        parse_str(parse_url($checkerUrl, PHP_URL_QUERY), $checkerTarget);
        $this->assertSame('check', $checkerTarget['action']);
        $this->assertSame((string) $order->id, $checkerTarget['order_id']);
        $this->get($checkerUrl)->assertOk()->assertInertia(fn ($page) => $page
            ->where('open_order_id', $order->id)->where('open_order_action', 'check')->where('open_check_mode', 'independent'));
        $this->post('/emar/order-revisions/'.$revision->id.'/check', ['source_matches' => true,
            'dose_route_times_checked' => true, 'allergies_interactions_checked' => true])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('verified', $order->refresh()->approval_status);
        $this->assertSame(1, (int) $order->version);
        $this->assertSame('checked', $revision->refresh()->status);
        $this->assertSame($checker->id, (int) $revision->checked_by);
    }

    public static function nonIndependentProposalReaders(): array
    {
        return [
            'read-back witness' => ['witness'],
            'without current work' => ['no_work'],
            'verification withdrawn' => ['no_verify'],
            'stale in-effect version' => ['stale_base'],
            'stopped order' => ['stopped'],
            'sent-back revision' => ['sent_back'],
            'controlled reader without record permission' => ['controlled_read_only'],
        ];
    }

    #[DataProvider('nonIndependentProposalReaders')]
    public function test_accepted_proposal_continuation_keeps_non_independent_readers_in_exact_order_view(string $condition): void
    {
        $order = $this->chart();
        $proposal = app(ExternalClinicalProposals::class)->submit($this->clinician, $this->person->id, [
            'kind' => 'change', 'medication_id' => $order->id, 'expected_version' => 1,
            'prescription' => $this->prescription(['dosage' => '20 mg']), 'reason' => 'Prescriber source reviewed',
            'request_key' => 'continuation-'.$condition,
        ], null);
        $this->actingAs($this->manager)->postJson('/emar/connected-care/proposals/'.$proposal->id.'/decision', [
            'decision' => 'accept', 'decision_note' => 'Source confirmed', 'source_confirmed' => true,
            'source' => $this->sourceInput(), 'source_file' => UploadedFile::fake()->create('prescription.pdf', 8, 'application/pdf'),
        ])->assertSuccessful();
        $revision = MedicationOrderRevision::findOrFail($proposal->refresh()->revision_id);
        $checker = $this->connectedStaff();
        $this->actingAs($checker);
        $baseline = $this->get('/emar/connected-care?client_id='.$this->person->id)->assertOk();
        parse_str(parse_url($baseline->inertiaProps('proposals.0.order_url'), PHP_URL_QUERY), $baselineTarget);
        $this->assertSame('check', $baselineTarget['action']);

        switch ($condition) {
            case 'witness':
                // The recorded read-back involvement remains distinct from the author.
                $revision->forceFill(['read_back_witness_id' => $checker->id])->save();
                break;
            case 'no_work':
                Shift::where('user_id', $checker->id)->where('client_id', $this->person->id)
                    ->update(['status' => 'completed', 'actual_ends_at' => now()->subMinute()]);
                break;
            case 'no_verify':
                $checker->permissionOverrides()->syncWithoutDetaching(Permission::where('key', 'medications.orders.verify')
                    ->pluck('id')->mapWithKeys(fn ($id) => [$id => ['allowed' => false]])->all());
                break;
            case 'stale_base':
                $order->forceFill(['version' => $revision->base_version + 1])->save();
                break;
            case 'stopped':
                $order->forceFill(['state' => 'ceased', 'active' => false, 'ceased_at' => now(),
                    'ceased_by' => $this->manager->id, 'ceased_reason' => 'Prescriber stopped before review'])->save();
                break;
            case 'sent_back':
                $this->post('/emar/order-revisions/'.$revision->id.'/send-back', ['reason' => 'Source needs correction'])
                    ->assertRedirect()->assertSessionHasNoErrors();
                break;
            case 'controlled_read_only':
                $order->forceFill(['controlled_drug' => true])->save();
                foreach (['medications.controlled.view' => true, 'medications.controlled.record' => false] as $key => $allowed) {
                    $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'medications']);
                    $checker->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => $allowed]]);
                }
                break;
        }
        $checker = $checker->fresh();
        $this->assertTrue($checker->can('viewMedications', $this->person->fresh()));
        $retainedOrder = $order->fresh()->getRawOriginal();
        $retainedRevision = $revision->fresh()->getRawOriginal();
        $response = $this->actingAs($checker)->get('/emar/connected-care?client_id='.$this->person->id)->assertOk();
        $orderUrl = $response->inertiaProps('proposals.0.order_url');
        parse_str(parse_url($orderUrl, PHP_URL_QUERY), $viewTarget);
        $this->assertSame('view', $viewTarget['action']);
        $this->assertSame((string) $order->id, $viewTarget['order_id']);
        $this->assertSame((string) $this->person->id, $viewTarget['client_id']);
        $this->get($orderUrl)->assertOk()->assertInertia(fn ($page) => $page
            ->where('open_order_id', $order->id)->where('prefill_client_id', $this->person->id)->where('open_order_action', 'view'));
        $this->assertSame($retainedOrder, $order->fresh()->getRawOriginal());
        $this->assertSame($retainedRevision, $revision->fresh()->getRawOriginal());
    }

    public function test_read_only_grant_and_restricted_controlled_orders_deny_proposals(): void
    {
        $order = $this->chart(['controlled_drug' => true]);
        $input = ['kind' => 'stop', 'medication_id' => $order->id, 'expected_version' => 1, 'reason' => 'Review', 'request_key' => 'stop-1'];
        $this->actingAs($this->clinician)->postJson('/clinical-portal/people/'.$this->person->id.'/proposals', $input)->assertNotFound();
        $this->grant->update(['can_propose' => false]);
        $this->postJson('/clinical-portal/people/'.$this->person->id.'/proposals', ['kind' => 'start',
            'prescription' => $this->prescription(), 'reason' => 'Review', 'request_key' => 'start-1'])->assertNotFound();
        $this->assertSame(0, MedicationExternalProposal::count());
    }

    public function test_replay_deduplicates_and_changed_payload_or_stale_version_fails(): void
    {
        $order = $this->chart();
        $data = ['kind' => 'change', 'medication_id' => $order->id, 'expected_version' => 1,
            'prescription' => $this->prescription(['dosage' => '20 mg']), 'reason' => 'Review', 'request_key' => 'one-request'];
        $service = app(ExternalClinicalProposals::class);
        $first = $service->submit($this->clinician, $this->person->id, $data, null);
        $this->assertSame($first->id, $service->submit($this->clinician, $this->person->id, $data, null)->id);
        $this->actingAs($this->clinician)->postJson('/clinical-portal/people/'.$this->person->id.'/proposals', [...$data, 'reason' => 'Changed'])->assertConflict();
        $order->forceFill(['version' => 2])->saveQuietly();
        $this->postJson('/clinical-portal/people/'.$this->person->id.'/proposals', [...$data, 'request_key' => 'stale'])->assertUnprocessable();
        $this->assertSame(1, MedicationExternalProposal::count());
    }

    public function test_acceptance_cannot_bypass_source_grant_or_current_p04_shift_authority(): void
    {
        $order = $this->chart();
        $proposal = app(ExternalClinicalProposals::class)->submit($this->clinician, $this->person->id, ['kind' => 'change',
            'medication_id' => $order->id, 'expected_version' => 1, 'prescription' => $this->prescription(['dosage' => '20 mg']),
            'reason' => 'Review', 'request_key' => 'authority'], null);
        $data = ['decision' => 'accept', 'decision_note' => 'Reviewed'];
        $this->actingAs($this->manager)->postJson('/emar/connected-care/proposals/'.$proposal->id.'/decision', $data)->assertUnprocessable();
        $this->grant->update(['revoked_at' => now()]);
        $this->postJson('/emar/connected-care/proposals/'.$proposal->id.'/decision', [...$data, 'source_confirmed' => true])->assertNotFound();
        $this->grant->update(['revoked_at' => null]);
        Shift::where('user_id', $this->manager->id)->update(['actual_ends_at' => now()->subSecond(), 'status' => 'completed']);
        $this->postJson('/emar/connected-care/proposals/'.$proposal->id.'/decision', [...$data, 'source_confirmed' => true])->assertForbidden();
        $this->assertSame('submitted', $proposal->refresh()->status);
        $this->assertSame('10 mg', $order->refresh()->dosage);
    }

    public function test_private_source_is_only_available_to_current_named_submitter_and_replays_retain_one_file(): void
    {
        $data = ['kind' => 'start', 'prescription' => $this->prescription(), 'reason' => 'Review', 'request_key' => 'file-1'];
        $file = UploadedFile::fake()->create('source.pdf', 8, 'application/pdf');
        $service = app(ExternalClinicalProposals::class);
        $proposal = $service->submit($this->clinician, $this->person->id, $data, $file);
        $this->assertSame($proposal->id, $service->submit($this->clinician, $this->person->id, $data, $file)->id);
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->actingAs($this->clinician)->get('/clinical-portal/proposals/'.$proposal->id.'/source')->assertOk();
        $this->grant->update(['revoked_at' => now()]);
        $this->get('/clinical-portal/proposals/'.$proposal->id.'/source')->assertNotFound();
    }

    public function test_transaction_retry_rechecks_revoked_grant_and_removes_rolled_back_source_bytes(): void
    {
        $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
        DB::commit();
        $transactions = new DatabaseTransactionsManager;
        $this->app->instance('db.transactions', $transactions);
        DB::connection()->setTransactionManager($transactions);
        $attempts = 0;
        MedicationExternalProposal::created(function () use (&$attempts): void {
            if (++$attempts === 1) {
                throw new QueryException('mysql', 'select 1', [], new \PDOException(
                    'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock', 40001));
            }
        });
        $revoked = false;
        DB::connection()->beforeStartingTransaction(function ($connection) use (&$attempts, &$revoked): void {
            if ($connection->transactionLevel() === 0 && $attempts === 1 && ! $revoked) {
                $revoked = true;
                DB::table('medication_external_grants')->where('id', $this->grant->id)->update(['revoked_at' => now(), 'revoked_by' => $this->manager->id]);
            }
        });
        $this->actingAs($this->clinician)->postJson('/clinical-portal/people/'.$this->person->id.'/proposals', [
            'kind' => 'start', 'prescription' => $this->prescription(), 'reason' => 'Review', 'request_key' => 'retry-revoked',
            'source_file' => UploadedFile::fake()->create('source.pdf', 8, 'application/pdf'),
        ])->assertNotFound();
        $this->assertTrue($revoked);
        $this->assertSame(1, $attempts);
        $this->assertSame(0, MedicationExternalProposal::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_successful_retry_replay_removes_unused_request_owned_private_source(): void
    {
        $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
        DB::commit();
        $transactions = new DatabaseTransactionsManager;
        $this->app->instance('db.transactions', $transactions);
        DB::connection()->setTransactionManager($transactions);
        $attempts = 0;
        $staged = null;
        MedicationExternalProposal::created(function (MedicationExternalProposal $proposal) use (&$attempts, &$staged): void {
            if (++$attempts === 1) {
                $staged = $proposal->getAttributes();
                unset($staged['id']);
                throw new QueryException('mysql', 'select 1', [], new \PDOException(
                    'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock', 40001));
            }
        });
        $winnerId = null;
        $winnerPath = 'medication-external-sources/winner/source.pdf';
        DB::connection()->beforeStartingTransaction(function ($connection) use (&$attempts, &$staged, &$winnerId, $winnerPath): void {
            if ($connection->transactionLevel() === 0 && $attempts === 1 && $winnerId === null) {
                Storage::disk('local')->copy($staged['source_file_path'], $winnerPath);
                $staged['source_file_path'] = $winnerPath;
                $winnerId = DB::table('medication_external_proposals')->insertGetId($staged);
            }
        });
        $proposal = app(ExternalClinicalProposals::class)->submit($this->clinician, $this->person->id, [
            'kind' => 'start', 'prescription' => $this->prescription(), 'reason' => 'Review', 'request_key' => 'retry-winner',
        ], UploadedFile::fake()->create('source.pdf', 8, 'application/pdf'));
        $this->assertNotNull($winnerId);
        $this->assertSame($winnerId, $proposal->id);
        $this->assertSame(1, $attempts);
        $this->assertSame(1, MedicationExternalProposal::count());
        $this->assertSame([$winnerPath], Storage::disk('local')->allFiles());
    }

    public function test_stop_request_links_only_an_exact_newer_canonical_cessation(): void
    {
        $order = $this->chart();
        $submitted = ['kind' => 'stop', 'medication_id' => $order->id,
            'expected_version' => 1, 'reason' => 'Prescriber stop request', 'request_key' => 'stop-link'];
        $proposal = app(ExternalClinicalProposals::class)->submit($this->clinician, $this->person->id, $submitted, null);
        $data = ['decision' => 'link_stop', 'decision_note' => 'Confirmed source and recorded stop', 'source_confirmed' => true,
            'source_reference' => 'Written prescription reference', 'ceased_version' => 1];
        $this->actingAs($this->manager)->postJson('/emar/connected-care/proposals/'.$proposal->id.'/decision', $data)->assertNotFound();
        app(MedicationOrderLifecycleService::class)->discontinue($this->manager, $order, 'Confirmed prescriber stop', submittedClientId: $this->person->id, requestKey: 'confirmed-stop');
        $this->postJson('/emar/connected-care/proposals/'.$proposal->id.'/decision', [...$data, 'ceased_version' => $order->refresh()->version])->assertSuccessful();
        $this->assertSame('accepted', $proposal->refresh()->status);
        $this->assertSame('ceased', $order->refresh()->state);
        $this->assertSame($order->version, (int) $proposal->ceased_version);
        $this->assertSame($proposal->id, app(ExternalClinicalProposals::class)->submit($this->clinician, $this->person->id, $submitted, null)->id);
        $this->assertSame(1, MedicationExternalProposal::count());
    }
}
