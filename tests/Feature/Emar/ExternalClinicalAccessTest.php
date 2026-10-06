<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Http\Responses\LoginResponse;
use App\Models\Client;
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
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse;
use Tests\Support\CommittedFixtureCleanup;
use Tests\Support\ExternalClinicalFixtures;
use Tests\TestCase;

final class ExternalClinicalAccessTest extends TestCase
{
    use ExternalClinicalFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
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
        app(ExternalClinicalAccess::class)->revokeIdentity($this->manager, $this->identity->id, ['client_id' => $this->person->id, 'reason' => 'Identity withdrawn']);
        $this->actingAs($this->clinician)->getJson('/clinical-portal?client_id='.$this->person->id)->assertForbidden();
        $this->getJson('/dashboard')->assertForbidden();
        $this->assertTrue($this->clinician->refresh()->external_clinical_account);
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
        $this->actingAs($this->manager)->postJson('/emar/connected-care/proposals/'.$proposal->id.'/decision', $decision)->assertSuccessful();
        $this->assertSame('10 mg', $order->refresh()->dosage);
        $this->assertSame('verified', $order->approval_status);
        $revision = MedicationOrderRevision::findOrFail($proposal->refresh()->revision_id);
        $this->assertSame('pending', $revision->status);
        $checker = $this->connectedStaff();
        $this->actingAs($checker)->post('/emar/order-revisions/'.$revision->id.'/check', ['source_matches' => true,
            'dose_route_times_checked' => true, 'allergies_interactions_checked' => true])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('20 mg', $order->refresh()->dosage);
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
