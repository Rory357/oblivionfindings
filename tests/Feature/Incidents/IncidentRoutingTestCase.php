<?php

namespace Tests\Feature\Incidents;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Jobs\DispatchIncidentOwnerDelivery;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\Incidents\IncidentRoutingService;
use App\Services\Incidents\IncidentRoutingSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\CommittedFixtureCleanup;
use Tests\TestCase;

abstract class IncidentRoutingTestCase extends TestCase
{
    use RefreshDatabase { beginDatabaseTransaction as private beginFixtureTransaction; }
    protected bool $physicalCommits = false;
    protected Site $site;
    protected User $manager;
    protected User $reporter;
    protected User $lead;
    protected User $cover;
    protected User $triage;

    public function beginDatabaseTransaction(): void
    {
        if (! $this->physicalCommits) { $this->beginFixtureTransaction(); return; }
        if (! app()->environment('testing') || DB::connection()->getDriverName() !== 'mysql'
            || ! str_contains(strtolower(DB::connection()->getDatabaseName()), 'test') || DB::transactionLevel() !== 0) {
            throw new \RuntimeException('Physical incident routing tests require the admitted MySQL test schema.');
        }
        $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-12T03:00:00Z')->utc());
        Bus::fake([DispatchIncidentOwnerDelivery::class]);
        $this->site = Site::factory()->create(['is_active' => true]);
        $this->manager = $this->staff(['incidents.viewAny', 'incidents.owner.assign', IncidentRoutingSettings::PERMISSION]);
        $this->reporter = $this->staff(['incidents.create']);
        $this->allow($this->reporter, 'incidents.owner.assign', false);
        $this->lead = $this->staff(['incidents.viewAny', 'incidents.owner.hold']);
        $this->cover = $this->staff(['incidents.viewAny', 'incidents.owner.hold']);
        $this->triage = $this->staff(['incidents.viewAny', 'incidents.owner.hold', 'incidents.owner.assign', 'incidents.owner.holdRestricted', 'hr.cases.view']);
    }

    protected function staff(array $keys, ?Site $site = null): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now(), 'external_clinical_account' => false]);
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => ($site ?? $this->site)->id,
            'secondary_site_ids' => [], 'is_active' => true, 'start_date' => '2020-01-01', 'end_date' => null]);
        foreach ($keys as $key) { $this->allow($user, $key); }
        return $user;
    }

    protected function allow(User $user, string $key, bool $allowed = true): void
    {
        $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'incidents', 'module' => 'Compliance']);
        $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => $allowed]]);
    }

    protected function incident(array $values = []): ClientIncident
    {
        // occurred_at is pinned to the frozen clock: the factory's Faker date uses the real clock, so period
        // windows around the frozen "now" would otherwise include or exclude the fixture at random.
        return ClientIncident::withoutEvents(fn () => ClientIncident::factory()->create([
            'site_id' => $this->site->id, 'client_id' => Client::factory()->create(['site_id' => $this->site->id])->id,
            'reported_by' => $this->reporter->id, 'status' => 'submitted', 'submitted_at' => now(), 'occurred_at' => now(), 'type' => 'injury',
            'incident_owner_user_id' => null, 'owner_revision' => 0, 'description' => 'Private synthetic incident narrative', ...$values,
        ]));
    }

    protected function config(array $values = []): array
    {
        return ['schema_version' => 1, 'routes' => [['site_id' => $this->site->id, 'scope' => 'ordinary', 'lead_user_id' => $this->lead->id,
            'effective_from' => '2026-10-12T00:00:00Z', 'effective_until' => null, 'cover_user_id' => null, 'cover_from' => null, 'cover_until' => null]],
            'triage' => ['user_id' => $this->triage->id, 'escalation_user_id' => $this->triage->id, 'escalation_after_minutes' => 47], ...$values];
    }

    protected function settings(array $config = [], int $revision = 0, ?string $uuid = null): array
    {
        return app(IncidentRoutingSettings::class)->save($this->manager, ['configuration' => $config ?: $this->config(),
            'expected_revision' => $revision, 'request_uuid' => $uuid ?? (string) Str::uuid(), 'reason' => 'Explicit organisation incident-routing review.']);
    }

    protected function routed(ClientIncident $root): array
    {
        $id = app(IncidentRoutingService::class)->stageForSubmitted((int) $root->id, $this->reporter);
        return app(IncidentRoutingService::class)->consume($id);
    }

    protected function validation(callable $operation, string $field): void
    {
        try { $operation(); $this->fail('Expected retained-input validation refusal.'); }
        catch (ValidationException $error) { $this->assertArrayHasKey($field, $error->errors()); }
    }

    protected function denial(callable $operation, int $status = 403): void
    {
        try { $operation(); $this->fail('Expected current-authority denial.'); }
        catch (HttpExceptionInterface $error) { $this->assertSame($status, $error->getStatusCode()); }
        catch (ModelNotFoundException) { $this->assertSame(404, $status); }
    }

    protected function veto(string $class, callable $operation): void
    {
        $dispatcher = Model::getEventDispatcher();
        Model::setEventDispatcher(clone $dispatcher);
        $class::saving(fn () => false);
        try { $operation(); $this->fail('Expected checked persistence refusal.'); }
        catch (\LogicException $error) { $this->assertNotSame('', $error->getMessage()); }
        finally { Model::setEventDispatcher($dispatcher); }
    }
}
