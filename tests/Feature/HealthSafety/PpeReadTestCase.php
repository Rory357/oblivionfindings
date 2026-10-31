<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Permission;
use App\Models\PpeInventory;
use App\Models\PpeType;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

abstract class PpeReadTestCase extends TestCase
{
    use RefreshDatabase;

    protected Site $site;
    protected Site $foreignSite;
    protected User $viewer;
    protected User $worker;
    protected PpeType $type;
    protected PpeInventory $item;
    protected PpeInventory $foreignItem;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('private');
        $this->seed(RbacSeeder::class);
        $this->travelTo(\Carbon\Carbon::parse('2026-10-12T12:00:00+13:00')->utc());
        $this->site = Site::factory()->create(['is_active' => true]);
        $this->foreignSite = Site::factory()->create(['is_active' => true]);
        $this->viewer = $this->staff(['hazards.view', 'hazards.manage']);
        $this->worker = $this->staff([]);
        $this->type = PpeType::factory()->create(['category' => 'respiratory', 'name' => 'Recorded respirator']);
        $this->item = PpeInventory::factory()->create(['site_id' => $this->site->id, 'ppe_type_id' => $this->type->id,
            'serial_number' => 'OWN-ITEM', 'expiry_date' => null, 'next_inspection_due' => null]);
        $this->foreignItem = PpeInventory::factory()->create(['site_id' => $this->foreignSite->id, 'ppe_type_id' => $this->type->id,
            'serial_number' => 'FOREIGN-PRIVATE', 'location' => 'Hidden location']);
    }

    protected function staff(array $permissions, ?Site $site = null): User
    {
        $person = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        HrEmployeeProfile::factory()->create(['user_id' => $person->id, 'primary_site_id' => ($site ?? $this->site)->id,
            'secondary_site_ids' => [], 'is_active' => true, 'start_date' => '2025-01-01', 'end_date' => null]);
        foreach ($permissions as $key) {
            $this->allow($person, $key, true);
        }
        return $person;
    }

    protected function allow(User $person, string $key, bool $allowed): void
    {
        $person->permissionOverrides()->syncWithoutDetaching([Permission::where('key', $key)->sole()->id => ['allowed' => $allowed]]);
    }

    protected function denied(callable $read, int $status): void
    {
        try {
            $read();
            $this->fail('Current source authority must deny this request.');
        } catch (ModelNotFoundException $missing) {
            $this->assertSame(404, $status);
        } catch (HttpExceptionInterface $denial) {
            $this->assertSame($status, $denial->getStatusCode());
        }
    }

    protected function queries(callable $read): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $result = $read();
            return [$result, array_column(DB::getQueryLog(), 'query')];
        } finally {
            DB::disableQueryLog();
        }
    }

    protected function assertDetached(mixed $value): void
    {
        if (is_array($value)) {
            foreach ($value as $nested) {
                $this->assertDetached($nested);
            }
        } else {
            $this->assertTrue($value === null || is_scalar($value));
        }
    }
}
