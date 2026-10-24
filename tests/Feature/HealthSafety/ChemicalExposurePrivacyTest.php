<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\HazardousSubstance;
use App\Models\Permission;
use App\Models\Site;
use App\Models\SubstanceExposureRecord;
use App\Models\User;
use App\Services\HealthSafety\ChemicalExposureAccessService;
use App\Services\HealthSafety\ChemicalExposureReadService;
use App\Services\HealthSafety\ChemicalRegisterReadService;
use App\Services\HealthSafety\HealthSafetyReadContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

/** Complete prospective private source suite, independent of still-unmounted controllers and derived source family. */
final class ChemicalExposurePrivacyTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;
    private Site $foreignSite;
    private User $viewer;
    private User $safety;
    private User $worker;
    private HazardousSubstance $substance;
    private HazardousSubstance $other;
    private SubstanceExposureRecord $exposure;
    private SubstanceExposureRecord $foreign;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->travelTo(Carbon::parse('2026-10-12T01:00:00Z'));
        $this->seed(\Database\Seeders\RbacSeeder::class);
        $this->site = Site::factory()->create(['is_active' => true]);
        $this->foreignSite = Site::factory()->create(['is_active' => true]);
        $this->viewer = $this->staff(['hazards.view', 'hr.wellbeing.view']);
        $this->safety = $this->staff(['hazards.view', 'hazards.manage']);
        $this->worker = $this->staff([]);
        $this->substance = HazardousSubstance::factory()->create(['name' => 'Recorded disinfectant']);
        $this->other = HazardousSubstance::factory()->create(['name' => 'Other chemical']);
        $this->exposure = $this->record(['circumstances' => 'PRIVATE-CIRCUMSTANCES', 'symptoms' => 'PRIVATE-SYMPTOMS',
            'first_aid_given' => 'PRIVATE-FIRST-AID', 'medical_treatment' => 'medical', 'medical_attention_sought' => true,
            'medical_outcome' => null, 'created_by' => $this->viewer->id]);
        $this->foreign = $this->record(['site_id' => $this->foreignSite->id, 'circumstances' => 'FOREIGN-PRIVATE-EXPOSURE']);
    }

    public function test_full_private_detail_preserves_actual_facts_worker_and_recorder_without_inventing_fitness(): void
    {
        $dto = $this->read()->detail($this->viewer, $this->exposure->id);
        $this->assertSame('readable', $dto['exposure_access']);
        $this->assertSame($this->worker->id, $dto['record']['worker']['id']);
        $this->assertSame($this->viewer->id, $dto['record']['recorded_by']['id']);
        $this->assertSame($this->site->id, $dto['record']['site']['id']);
        $this->assertSame('PRIVATE-CIRCUMSTANCES', $dto['record']['circumstances']);
        $this->assertSame('PRIVATE-SYMPTOMS', $dto['record']['symptoms']);
        $this->assertSame('PRIVATE-FIRST-AID', $dto['record']['first_aid_given']);
        $this->assertTrue($dto['record']['medical_attention_sought']);
        $this->assertSame('medical', $dto['record']['medical_treatment']);
        $this->assertNull($dto['record']['medical_outcome']);
        $this->assertSame('legacy_unknown', $dto['record']['attribution_state']);
        $this->assertSame('unconfirmed', $dto['delivery']['state']);
        $this->assertNull($dto['delivery']['health_safety_href']);
        $this->assertFalse($dto['can']['correct']);
        $this->assertArrayNotHasKey('fitness_for_work', $dto['record']);
        $this->detached($dto);
    }

    public function test_hazards_only_collections_have_no_rows_counts_identity_or_private_sql(): void
    {
        [$dto, $queries] = $this->queries(fn () => $this->read()->forSubstance($this->safety, $this->substance->id,
            ['worker_id' => $this->worker->id, 'from' => 'bad']));
        $this->assertSame('restricted', $dto['exposure_access']);
        foreach (['records', 'counts', 'page', 'history', 'filters'] as $field) {
            $this->assertNull($dto[$field]);
        }
        $this->noPrivateSql($queries);
        $json = json_encode($dto, JSON_THROW_ON_ERROR);
        foreach (['PRIVATE-SYMPTOMS', 'PRIVATE-CIRCUMSTANCES', $this->worker->name] as $private) {
            $this->assertStringNotContainsString($private, $json);
        }
        [$index, $indexQueries] = $this->queries(fn () => $this->read()->index($this->safety));
        $this->assertNull($index['counts']);
        $this->noPrivateSql($indexQueries);
    }

    public function test_hazards_only_direct_source_denies_before_private_or_dependency_discovery(): void
    {
        [$unused, $queries] = $this->queries(function () {
            $this->denied(fn () => $this->read()->detail($this->safety, $this->exposure->id));
            return null;
        });
        $this->noPrivateSql($queries);
    }

    public function test_wellbeing_without_safety_read_is_not_catalogue_or_exposure_authority(): void
    {
        $wellbeing = $this->staff(['hr.wellbeing.view']);
        $this->denied(fn () => $this->read()->detail($wellbeing, $this->exposure->id));
        $this->denied(fn () => $this->read()->index($wellbeing));
    }

    public function test_exposed_worker_identity_never_confers_self_read_even_with_catalogue_management(): void
    {
        $this->allow($this->worker, 'hazards.view');
        $this->allow($this->worker, 'hazards.manage');
        $this->denied(fn () => $this->read()->detail($this->worker, $this->exposure->id));
        $this->assertNull($this->read()->index($this->worker)['records']);
    }

    public function test_current_private_grant_revoke_and_restore_never_reuses_warmed_viewer(): void
    {
        $this->viewer->load('roles.permissions', 'permissionOverrides', 'hrEmployeeProfile');
        $this->assertSame($this->exposure->id, $this->read()->detail($this->viewer, $this->exposure->id)['record']['id']);
        $this->allow($this->viewer, 'hr.wellbeing.view', false);
        [$dto, $queries] = $this->queries(fn () => $this->read()->index($this->viewer));
        $this->assertSame('restricted', $dto['exposure_access']);
        $this->noPrivateSql($queries);
        $this->denied(fn () => $this->read()->detail($this->viewer, $this->exposure->id));
        $this->allow($this->viewer, 'hr.wellbeing.view');
        $this->assertSame('PRIVATE-SYMPTOMS', $this->read()->detail($this->viewer, $this->exposure->id)['record']['symptoms']);
    }

    public function test_current_safety_read_revoke_and_restore_is_required_with_the_private_grant(): void
    {
        $this->assertSame('readable', $this->read()->index($this->viewer)['exposure_access']);
        $this->allow($this->viewer, 'hazards.view', false);
        $this->denied(fn () => $this->read()->index($this->viewer));
        $this->allow($this->viewer, 'hazards.view');
        $this->assertSame('readable', $this->read()->index($this->viewer)['exposure_access']);
    }

    public function test_foreign_unknown_inactive_and_archived_site_sources_are_not_global_private_reads(): void
    {
        $this->denied(fn () => $this->read()->detail($this->viewer, $this->foreign->id), 404);
        $unknown = $this->record(['site_id' => null]);
        $this->denied(fn () => $this->read()->detail($this->viewer, $unknown->id), 404);
        $this->allow($this->viewer, 'healthSafety.viewAllSites');
        $this->assertSame($this->foreign->id, $this->read()->detail($this->viewer, $this->foreign->id)['record']['id']);
        $this->denied(fn () => $this->read()->detail($this->viewer, $unknown->id), 404);
        $this->foreignSite->update(['is_active' => false]);
        $this->denied(fn () => $this->read()->detail($this->viewer, $this->foreign->id), 404);
        $this->foreignSite->update(['is_active' => true, 'archived_at' => now()]);
        $this->denied(fn () => $this->read()->detail($this->viewer, $this->foreign->id), 404);
    }

    public function test_current_site_membership_revoke_and_restore_applies_to_detail_counts_and_collection(): void
    {
        $this->assertSame(1, $this->read()->forSubstance($this->viewer, $this->substance->id)['counts']['exposures']);
        HrEmployeeProfile::where('user_id', $this->viewer->id)->update(['primary_site_id' => $this->foreignSite->id]);
        $this->denied(fn () => $this->read()->detail($this->viewer, $this->exposure->id), 404);
        $rows = $this->read()->index($this->viewer);
        $this->assertSame([$this->foreign->id], array_column($rows['records'], 'id'));
        HrEmployeeProfile::where('user_id', $this->viewer->id)->update(['primary_site_id' => $this->site->id]);
        $this->assertSame([$this->exposure->id], array_column($this->read()->index($this->viewer)['records'], 'id'));
    }

    public function test_current_hr_and_external_actor_denial_does_not_hydrate_private_sources(): void
    {
        HrEmployeeProfile::where('user_id', $this->viewer->id)->update(['is_active' => false]);
        [$unused, $queries] = $this->queries(function () {
            $this->denied(fn () => $this->read()->index($this->viewer));
            return null;
        });
        $this->noPrivateSql($queries);
        HrEmployeeProfile::where('user_id', $this->viewer->id)->update(['is_active' => true]);
        $external = $this->staff(['hazards.view', 'hr.wellbeing.view']);
        $external->forceFill(['external_clinical_account' => true])->save(); // not mass-assignable on main's User
        $this->denied(fn () => $this->read()->detail($external, $this->exposure->id));
        $this->assertSame('readable', $this->read()->detail($this->viewer, $this->exposure->id)['exposure_access']);
    }

    public function test_historical_worker_read_does_not_invent_a_current_employment_or_fitness_requirement(): void
    {
        HrEmployeeProfile::where('user_id', $this->worker->id)->update(['is_active' => false, 'end_date' => '2026-01-01']);
        $dto = $this->read()->detail($this->viewer, $this->exposure->id);
        $this->assertSame($this->worker->id, $dto['record']['worker']['id']);
        $this->assertSame('PRIVATE-SYMPTOMS', $dto['record']['symptoms']);
    }

    public function test_archived_source_and_catalogue_parent_cannot_retain_exposure_read_or_counts(): void
    {
        $this->exposure->delete();
        $this->denied(fn () => $this->read()->detail($this->viewer, $this->exposure->id), 404);
        $this->assertSame(0, $this->read()->index($this->viewer)['counts']['exposures']);
        $this->exposure->restore();
        $this->substance->delete();
        $this->denied(fn () => $this->read()->detail($this->viewer, $this->exposure->id), 404);
        $this->denied(fn () => $this->read()->forSubstance($this->viewer, $this->substance->id), 404);
    }

    public function test_exact_catalogue_parent_binding_and_current_source_site_movement_are_not_stale_relations(): void
    {
        $loaded = $this->exposure->load('hazardousSubstance', 'site', 'user');
        $access = app(ChemicalExposureAccessService::class);
        $this->denied(fn () => $access->withCurrentViewer($this->viewer, function ($current, $reads, $sites) use ($access, $loaded) {
            $access->assertExposure($loaded, $current, $reads, $sites, $this->other->id);
            return null;
        }), 404);
        $this->exposure->update(['site_id' => $this->foreignSite->id]);
        $this->denied(fn () => $access->withCurrentViewer($this->viewer,
            fn ($current, $reads, $sites) => $access->sourceTuple($loaded, $current, $reads, $sites)), 404);
        $this->exposure->update(['site_id' => $this->site->id]);
        $tuple = $access->withCurrentViewer($this->viewer,
            fn ($current, $reads, $sites) => $access->sourceTuple($loaded, $current, $reads, $sites));
        $this->assertSame($this->site->id, $tuple['site_id']);
        $this->assertSame($this->worker->id, $tuple['worker_id']);
        $this->assertSame(SubstanceExposureRecord::class, $tuple['source_type']);
        $this->assertSame('exposure', $tuple['event_category']);
        $this->assertNull($tuple['asset_id']);
        $this->assertSame('absent', $tuple['client_binding']);
    }

    public function test_related_incident_has_no_unproved_client_identity_href_or_copied_private_content(): void
    {
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $incident = ClientIncident::factory()->create(['site_id' => $this->site->id, 'client_id' => $client->id,
            'reported_by' => $this->viewer->id, 'description' => 'PRIVATE-INCIDENT-NARRATIVE']);
        $this->exposure->update(['incident_reported' => true, 'related_incident_id' => $incident->id]);
        [$dto, $queries] = $this->queries(fn () => $this->read()->detail($this->viewer, $this->exposure->id));
        $this->assertTrue($dto['record']['incident_reported']);
        $this->assertSame('unknown', $dto['record']['incident_connection']['state']);
        $this->assertNull($dto['record']['incident_connection']['id']);
        $this->assertNull($dto['record']['incident_connection']['href']);
        $this->assertArrayNotHasKey('related_incident_id', $dto['record']);
        $this->assertStringNotContainsString('PRIVATE-INCIDENT-NARRATIVE', json_encode($dto, JSON_THROW_ON_ERROR));
        foreach ($queries as $sql) {
            $this->assertStringNotContainsString('client_incidents', strtolower($sql));
        }
    }

    public function test_private_period_filters_use_actual_offsets_and_preserve_nz_calendar_display(): void
    {
        $this->exposure->update(['exposed_at' => '2026-10-11 23:30:00']);
        $dto = $this->read()->index($this->viewer, ['from' => '2026-10-12T12:00:00+13:00', 'to' => '2026-10-12T13:00:00+13:00']);
        $this->assertSame([$this->exposure->id], array_column($dto['records'], 'id'));
        $this->assertSame('2026-10-12T12:30:00+13:00', $dto['records'][0]['exposed_at']);
        foreach ([['from' => '2026-10-12T12:00:00'], ['from' => '2026-02-30T12:00:00+13:00'], ['from' => '2026-10-12T12:00:00+99:99'],
            ['from' => '2026-10-13T12:00:00+13:00', 'to' => '2026-10-12T12:00:00+13:00'], ['page' => true], ['invented' => 1]] as $filters) {
            try {
                $this->read()->index($this->viewer, $filters);
                $this->fail('Invalid source refinements must remain validation failures.');
            } catch (ValidationException $failure) {
                $this->assertNotEmpty($failure->errors());
            }
        }
    }

    public function test_private_collection_counts_and_pages_use_the_exact_same_substance_site_worker_period(): void
    {
        for ($i = 0; $i < 26; $i++) {
            $this->record(['exposed_at' => '2026-10-11 00:00:00', 'medical_attention_sought' => false]);
        }
        $this->record(['hazardous_substance_id' => $this->other->id]);
        $first = $this->read()->forSubstance($this->viewer, $this->substance->id, ['site_id' => $this->site->id, 'worker_id' => $this->worker->id]);
        $second = $this->read()->forSubstance($this->viewer, $this->substance->id, ['site_id' => $this->site->id, 'worker_id' => $this->worker->id, 'page' => 2]);
        $this->assertCount(25, $first['records']);
        $this->assertCount(2, $second['records']);
        $this->assertSame(27, $first['counts']['exposures']);
        $this->assertSame(1, $first['counts']['awaiting_medical_outcome']);
        $this->assertSame($first['counts'], $second['counts']);
        $this->assertSame(2, $second['page']['current_page']);
        $this->assertSame(27, $second['page']['total']);
        $this->denied(fn () => $this->read()->index($this->viewer, ['site_id' => $this->foreignSite->id]), 404);
    }

    public function test_same_active_context_materialises_detail_and_collection_without_a_nested_authority_refresh(): void
    {
        [$result, $queries] = $this->queries(fn () => HealthSafetyReadContext::within($this->viewer,
            ChemicalExposureAccessService::PERMISSIONS, function ($context) {
                $connection = DB::connection();
                $level = $connection->transactionLevel();
                $detail = $this->read()->detailInContext($this->viewer, $this->exposure->id, $context);
                $list = $this->read()->forSubstanceInContext($this->viewer, $this->substance->id, [], $context);
                $this->assertSame($connection, DB::connection());
                $this->assertSame($level, $connection->transactionLevel());

                return ['detail' => $detail, 'list' => $list];
            }));
        $this->assertSame($result['detail']['record']['id'], $result['list']['records'][0]['id']);
        $this->detached($result);
        // Two independent materialisations use the one prepared actor, not two refreshed permission graphs.
        $permissionLoads = array_filter($queries, fn ($sql) => preg_match('/from\s+[`"]?permissions[`"]?\s/i', $sql));
        $this->assertCount(1, $permissionLoads);
    }

    public function test_foreign_viewer_expired_context_and_unprepared_permissions_never_fall_back_to_fresh_reads(): void
    {
        HealthSafetyReadContext::within($this->viewer, ChemicalExposureAccessService::PERMISSIONS, function ($context) {
            $this->integrity(fn () => $this->read()->detailInContext($this->safety, $this->exposure->id, $context));
            $original = DB::getDefaultConnection();
            config(['database.connections.chemical_exposure_context' => config('database.connections.'.$original)]);
            DB::setDefaultConnection('chemical_exposure_context');
            try {
                $this->integrity(fn () => $this->read()->indexInContext($this->viewer, [], $context));
            } finally {
                DB::setDefaultConnection($original);
                DB::purge('chemical_exposure_context');
            }
            return null;
        });
        HealthSafetyReadContext::within($this->viewer, ['hazards.view', 'healthSafety.viewAllSites'], function ($context) {
            $this->integrity(fn () => $this->read()->indexInContext($this->viewer, [], $context));
            return null;
        });
        $captured = null;
        HealthSafetyReadContext::within($this->viewer, ChemicalExposureAccessService::PERMISSIONS, function ($context) use (&$captured) {
            $captured = $context;
            return null;
        });
        $this->integrity(fn () => $this->read()->detailInContext($this->viewer, $this->exposure->id, $captured));
    }

    public function test_physical_connection_source_mismatch_and_escaped_builder_or_model_are_rejected(): void
    {
        config(['database.connections.chemical_exposure_foreign' => config('database.connections.'.config('database.default'))]);
        $foreign = clone $this->exposure;
        $foreign->setConnection('chemical_exposure_foreign');
        $access = app(ChemicalExposureAccessService::class);
        $this->integrity(fn () => $access->withCurrentViewer($this->viewer, function ($current, $reads, $sites) use ($access, $foreign) {
            $access->assertExposure($foreign, $current, $reads, $sites);
            return null;
        }));
        $this->integrity(fn () => $access->withCurrentViewer($this->viewer,
            fn ($current, $reads, $sites) => $access->assertExposure($this->exposure->id, $current, $reads, $sites)));
        $captured = null;
        $access->withCurrentViewer($this->viewer, function ($current, $reads, $sites) use ($access, &$captured) {
            $captured = $access->readableQuery($current, $reads, $sites);
            return null;
        });
        $this->integrity(fn () => $captured->get());
    }

    public function test_nonprivate_catalogue_service_remains_independent_and_does_not_start_reading_private_exposures(): void
    {
        [$dto, $queries] = $this->queries(fn () => app(ChemicalRegisterReadService::class)->detail($this->safety, $this->substance->id));
        $this->assertSame('Recorded disinfectant', $dto['name']);
        $this->assertSame('restricted', $dto['exposure_access']);
        $this->assertNull($dto['exposure_records']);
        $this->assertNull($dto['counts']['exposures']);
        $this->noPrivateSql($queries);
    }

    private function read(): ChemicalExposureReadService
    {
        return app(ChemicalExposureReadService::class);
    }

    private function record(array $facts = []): SubstanceExposureRecord
    {
        return SubstanceExposureRecord::factory()->create(['hazardous_substance_id' => $this->substance->id,
            'user_id' => $this->worker->id, 'site_id' => $this->site->id, 'exposed_at' => '2026-10-12 00:00:00',
            'related_incident_id' => null, 'medical_attention_sought' => false, ...$facts]);
    }

    private function staff(array $keys): User
    {
        $person = User::factory()->create(['role' => 'support_worker', 'approved_at' => now(), 'external_clinical_account' => false]);
        HrEmployeeProfile::factory()->create(['user_id' => $person->id, 'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [], 'is_active' => true, 'start_date' => '2020-01-01', 'end_date' => null]);
        foreach ($keys as $key) {
            $this->allow($person, $key);
        }

        return $person;
    }

    private function allow(User $person, string $key, bool $allowed = true): void
    {
        $person->permissionOverrides()->syncWithoutDetaching([Permission::where('key', $key)->sole()->id => ['allowed' => $allowed]]);
    }

    private function queries(callable $operation): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            return [$operation(), array_column(DB::getQueryLog(), 'query')];
        } finally {
            DB::disableQueryLog();
        }
    }

    private function noPrivateSql(array $queries): void
    {
        foreach ($queries as $sql) {
            foreach (['substance_exposure_records', 'client_incidents', 'hs_events', 'audit_logs'] as $table) {
                $this->assertStringNotContainsString($table, strtolower($sql));
            }
        }
    }

    private function denied(callable $operation, int $status = 403): void
    {
        try {
            $operation();
            $this->fail('Expected fresh private source denial.');
        } catch (ModelNotFoundException) {
            $this->assertSame(404, $status);
        } catch (HttpExceptionInterface $failure) {
            $this->assertSame($status, $failure->getStatusCode());
        }
    }

    private function integrity(callable $operation): void
    {
        try {
            $operation();
            $this->fail('A foreign, expired or escaped source authority must fail.');
        } catch (LogicException $failure) {
            $this->assertNotSame('', $failure->getMessage());
        }
    }

    private function detached(mixed $value): void
    {
        if (is_array($value)) {
            foreach ($value as $part) {
                $this->detached($part);
            }
        } else {
            $this->assertTrue($value === null || is_scalar($value));
        }
    }
}
