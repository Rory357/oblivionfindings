<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\HazardousSubstance;
use App\Models\Permission;
use App\Models\Site;
use App\Models\SubstanceStorageLocation;
use App\Models\User;
use App\Services\HealthSafety\ChemicalCommandService;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\CommittedFixtureCleanup;
use Tests\TestCase;

abstract class ChemicalCommandTestCase extends TestCase
{
    use RefreshDatabase { beginDatabaseTransaction as private beginFixtureTransaction; }
    protected bool $physicalCommits = false;
    protected User $manager;
    protected Site $site;
    protected Site $foreignSite;

    public function beginDatabaseTransaction(): void
    {
        if (! $this->physicalCommits) { $this->beginFixtureTransaction(); return; }
        if (! app()->environment('testing') || DB::connection()->getDriverName() !== 'mysql'
            || ! str_contains(strtolower(DB::connection()->getDatabaseName()), 'test')) {
            throw new \RuntimeException('Physical chemical tests require the admitted MySQL test schema.');
        }
        $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-12T01:00:00Z')->utc());
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['is_active' => true]);
        $this->foreignSite = Site::factory()->create(['is_active' => true]);
        $this->manager = $this->staff(['hazards.view', 'hazards.manage']);
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
        $user->permissionOverrides()->syncWithoutDetaching([Permission::where('key', $key)->sole()->id => ['allowed' => $allowed]]);
    }

    protected function service(): ChemicalCommandService { return app(ChemicalCommandService::class); }
    protected function catalogue(array $facts = []): HazardousSubstance
    {
        $r = $this->service()->create($this->manager, ['idempotency_key' => (string) Str::uuid(),
            'name' => 'Recorded disinfectant', 'physical_form' => 'liquid', ...$facts]);
        return HazardousSubstance::findOrFail($r['root_id']);
    }

    protected function storage(HazardousSubstance $parent, array $facts = []): SubstanceStorageLocation
    {
        $r = $this->service()->createStorage($parent, $this->manager, ['idempotency_key' => (string) Str::uuid(),
            'parent_expected_version' => $parent->fresh()->source_revision, 'site_id' => $this->site->id,
            'location_description' => 'Recorded cabinet', 'current_quantity' => '1.25', 'properly_labelled' => false,
            'segregation_compliant' => false, ...$facts]);
        return SubstanceStorageLocation::findOrFail($r['source_id']);
    }

    protected function command(HazardousSubstance|SubstanceStorageLocation $root, array $facts = [], ?string $key = null): array
    {
        return ['idempotency_key' => $key ?? (string) Str::uuid(), 'expected_version' => $root->fresh()->source_revision, ...$facts];
    }

    protected function storageCommand(HazardousSubstance $parent, SubstanceStorageLocation $root, array $facts = [], ?string $key = null): array
    {
        return $this->command($root, ['parent_expected_version' => $parent->fresh()->source_revision, ...$facts], $key);
    }

    protected function validation(callable $operation, string $field): void
    {
        try { $operation(); $this->fail('Expected a draft-preserving chemical validation error.'); }
        catch (ValidationException $error) { $this->assertArrayHasKey($field, $error->errors()); }
    }

    protected function denial(callable $operation, int $status = 403): void
    {
        try { $operation(); $this->fail('Expected current chemical source denial.'); }
        catch (HttpExceptionInterface $error) { $this->assertSame($status, $error->getStatusCode()); }
        catch (ModelNotFoundException) { $this->assertSame(404, $status); }
    }

    protected function integrity(callable $operation): void
    {
        try { $operation(); $this->fail('Expected chemical source integrity refusal.'); }
        catch (\LogicException $error) { $this->assertNotSame('', $error->getMessage()); }
    }
}
