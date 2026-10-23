<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Permission;
use App\Models\PpeInventory;
use App\Models\PpeType;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\PpeAccessService;
use App\Services\HealthSafety\PpeCommandService;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\CommittedFixtureCleanup;
use Tests\TestCase;

abstract class PpeCommandTestCase extends TestCase
{
    use RefreshDatabase { beginDatabaseTransaction as private beginFixtureTransaction; }
    protected bool $physicalCommits = false;
    protected Site $site;
    protected User $manager;
    protected User $worker;

    public function beginDatabaseTransaction(): void
    {
        if ($this->physicalCommits) {
            if (! app()->environment('testing') || ! str_contains(strtolower(\Illuminate\Support\Facades\DB::connection()->getDatabaseName()), 'test')) {
                throw new \RuntimeException('Physical PPE tests require an admitted test schema.');
            }
            $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
        } else {
            $this->beginFixtureTransaction();
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-12T01:00:00Z'));
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['is_active' => true]);
        $this->manager = $this->staff(['hazards.view', 'hazards.manage']);
        $this->worker = $this->staff();
    }

    protected function staff(array $grants = [], ?Site $site = null): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now(), 'external_clinical_account' => false]);
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => ($site ?? $this->site)->id,
            'secondary_site_ids' => [], 'start_date' => '2020-01-01', 'end_date' => null, 'is_active' => true]);
        foreach ($grants as $key) {
            $user->permissionOverrides()->syncWithoutDetaching([Permission::where('key', $key)->sole()->id => ['allowed' => true]]);
        }
        return $user;
    }

    protected function service(): PpeCommandService { return app(PpeCommandService::class); }
    protected function item(array $facts = []): PpeInventory
    {
        $type = PpeType::factory()->create();
        $receipt = $this->service()->createInventory($this->manager, ['idempotency_key' => (string) Str::uuid(),
            'site_id' => $this->site->id, 'ppe_type_id' => $type->id, 'serial_number' => 'REAL-PPE', 'quantity' => 3, ...$facts]);
        return PpeInventory::findOrFail($receipt['root_id']);
    }
    protected function command(PpeInventory|PpeType $root, array $facts = [], ?string $key = null): array
    {
        return ['idempotency_key' => $key ?? (string) Str::uuid(), 'expected_version' => (int) $root->fresh()->source_revision, ...$facts];
    }
    protected function issue(PpeInventory $item): \App\Models\PpeAllocation
    {
        $receipt = $this->service()->allocate($item, $this->manager, $this->command($item, ['user_id' => $this->worker->id]));
        return \App\Models\PpeAllocation::findOrFail($receipt['source_id']);
    }
    protected function revoke(User $actor, string $key): void
    {
        $actor->load('roles.permissions', 'permissionOverrides');
        $actor->permissionOverrides()->syncWithoutDetaching([Permission::where('key', $key)->sole()->id => ['allowed' => false]]);
    }
    protected function validation(callable $call, string $field): void
    {
        try { $call(); $this->fail('Expected draft-preserving PPE rejection.'); }
        catch (ValidationException $error) { $this->assertArrayHasKey($field, $error->errors()); }
    }
    protected function denial(callable $call, int $status = 403): void
    {
        try { $call(); $this->fail('Expected current PPE authority denial.'); }
        catch (HttpExceptionInterface $error) { $this->assertSame($status, $error->getStatusCode()); }
        catch (ModelNotFoundException) { $this->assertSame(404, $status); }
    }
    protected function integrity(callable $call): void
    {
        try { $call(); $this->fail('Expected integrity refusal.'); }
        catch (\LogicException $error) { $this->assertNotSame('', $error->getMessage()); }
    }
    protected function sourceState(PpeInventory $root, ?\Illuminate\Database\Eloquent\Model $child = null): string
    {
        return app(PpeAccessService::class)->withCurrentViewer($this->manager, function ($current, $reads, $sites) use ($root, $child) {
            $fresh = app(PpeAccessService::class)->assertInventory($root, $current, $reads, $sites);
            return $this->service()->sourceState($fresh, $child?->fresh());
        });
    }
}
