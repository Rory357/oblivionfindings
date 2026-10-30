<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientBreakGlassAccess;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * The per-person medication rule (ClientPolicy::viewMedications) pinned case
 * by case, and EA-106's correctMedications: the same rule with the
 * correction right in place of read access.
 */
final class ClientMedicationPolicyRuleTest extends TestCase
{
    use RefreshDatabase;

    private Site $house;

    private Site $otherHouse;

    private Client $person;

    private Client $elsewhere;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->house = Site::factory()->create(['is_active' => true]);
        $this->otherHouse = Site::factory()->create(['is_active' => true]);
        $this->person = Client::factory()->create(['site_id' => $this->house->id, 'status' => 'active']);
        $this->elsewhere = Client::factory()->create(['site_id' => $this->otherHouse->id, 'status' => 'active']);
    }

    public function test_view_medications_keeps_each_existing_case(): void
    {
        // Read access is required, even for someone assigned to the person.
        $this->assertFalse($this->views($this->assigned([]), $this->person));
        // An ordinary reader opens only the people they're assigned to, at
        // a house they work at.
        $this->assertFalse($this->views($this->staff(['medications.view']), $this->person));
        $this->assertTrue($this->views($this->assigned(['medications.view']), $this->person));
        $this->assertFalse($this->views($this->assigned(['medications.view'], $this->otherHouse), $this->person));
        // Medication operations and client-wide roles open their houses.
        foreach (['medications.stock.update', 'medications.audit.view', 'medications.reports.export', 'medications.reports.view', 'clients.viewAny'] as $key) {
            $user = $this->staff(['medications.view', $key]);
            $this->assertTrue($this->views($user, $this->person), $key);
            $this->assertFalse($this->views($user, $this->elsewhere), $key);
        }
        $this->assertTrue($this->views($this->staff(['medications.view', 'medications.stock.update', 'sites.viewAll']), $this->elsewhere));
        // Clocked in on a shift covering the person; rostered only is not enough.
        $this->assertTrue($this->views($this->onShift(['medications.view'], clockedIn: true), $this->person));
        $this->assertFalse($this->views($this->onShift(['medications.view'], clockedIn: false), $this->person));
        // Break-glass at the person's house.
        $this->assertTrue($this->views($this->brokeGlass(['medications.view', 'medications.breakglass']), $this->person));
        $this->assertFalse($this->views($this->staff(['medications.view', 'medications.breakglass']), $this->person));
        // A portal user for the person follows their read access.
        $this->assertTrue($this->views($this->portal(['medications.view']), $this->person));
        $this->assertFalse($this->views($this->portal([]), $this->person));
    }

    public function test_correct_medications_is_the_same_rule_with_the_correction_right(): void
    {
        $this->assertTrue($this->corrects($this->assigned(['medications.administer.correct']), $this->person));
        $this->assertFalse($this->corrects($this->staff(['medications.administer.correct']), $this->person));
        $this->assertFalse($this->corrects($this->assigned(['medications.view']), $this->person));
        $this->assertFalse($this->corrects($this->assigned(['medications.administer.correct'], $this->otherHouse), $this->person));
        $operations = $this->staff(['medications.administer.correct', 'medications.stock.update']);
        $this->assertTrue($this->corrects($operations, $this->person));
        $this->assertFalse($this->corrects($operations, $this->elsewhere));
        $this->assertTrue($this->corrects($this->onShift(['medications.administer.correct'], clockedIn: true), $this->person));
        $this->assertFalse($this->corrects($this->onShift(['medications.administer.correct'], clockedIn: false), $this->person));
        $this->assertTrue($this->corrects($this->brokeGlass(['medications.administer.correct', 'medications.breakglass']), $this->person));
        // Never through the client portal.
        $this->assertFalse($this->corrects($this->portal(['medications.view', 'medications.administer.correct']), $this->person));
    }

    private function views(User $user, Client $client): bool
    {
        return Gate::forUser($user->fresh())->allows('viewMedications', $client->fresh());
    }

    private function corrects(User $user, Client $client): bool
    {
        return Gate::forUser($user->fresh())->allows('correctMedications', $client->fresh());
    }

    /** @param list<string> $keys */
    private function staff(array $keys, ?Site $site = null): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $this->grant($user, $keys);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id, 'primary_site_id' => ($site ?? $this->house)->id, 'secondary_site_ids' => [],
            'is_active' => true, 'start_date' => today()->subYear(), 'end_date' => null,
        ]);

        return $user;
    }

    /** @param list<string> $keys */
    private function assigned(array $keys, ?Site $site = null): User
    {
        $user = $this->staff($keys, $site);
        $this->person->supportWorkers()->attach($user->id);

        return $user;
    }

    /** @param list<string> $keys */
    private function onShift(array $keys, bool $clockedIn): User
    {
        $user = $this->staff($keys);
        Shift::factory()->create([
            'client_id' => $this->person->id, 'site_id' => $this->house->id, 'user_id' => $user->id,
            'starts_at' => now()->subHour(), 'ends_at' => now()->addHours(7),
            'actual_starts_at' => $clockedIn ? now()->subHour() : null,
            'status' => $clockedIn ? 'in_progress' : 'scheduled',
            'published_at' => now()->subDay(),
        ]);

        return $user;
    }

    /** @param list<string> $keys */
    private function brokeGlass(array $keys): User
    {
        $user = $this->staff($keys);
        ClientBreakGlassAccess::create(['client_id' => $this->person->id, 'user_id' => $user->id, 'reason' => 'Emergency', 'expires_at' => now()->addHour()]);

        return $user;
    }

    /** @param list<string> $keys */
    private function portal(array $keys): User
    {
        $user = User::factory()->create(['role' => 'client', 'approved_at' => now()]);
        $user->roles()->attach(Role::query()->firstOrCreate(['name' => 'client'], ['label' => 'Client', 'level' => 1, 'type' => 'system']));
        $user->portalClients()->attach($this->person->id, ['relation' => 'self']);
        $this->grant($user, $keys);

        return $user;
    }

    /** @param list<string> $keys */
    private function grant(User $user, array $keys): void
    {
        $user->permissionOverrides()->sync(Permission::query()->whereIn('key', $keys)->pluck('id')
            ->mapWithKeys(fn (int $id): array => [$id => ['allowed' => true]])->all());
    }
}
