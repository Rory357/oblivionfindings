<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\HsEvent;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SafeguardingConcern;
use App\Models\Site;
use App\Models\User;
use App\Services\Tasks\Providers\HsEventProvider;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Readiness audit B1 (6 Nov 2026): a safety review made from a safeguarding concern names the client and the reporter
 * only to someone who can open the concern itself. An H&S officer refused the concern still sees the review, its Site
 * and its progress, but not whose it is: not on the list, the page (and so the PDF), or /tasks.
 */
final class HsPrivateSourcePersonTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private User $officer;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['is_active' => true, 'name' => 'Kauri House']);
        $this->officer = $this->roleUser('health_safety_officer');
        $this->client = Client::factory()->create(['first_name' => 'Sofia', 'last_name' => 'Brownlee', 'site_id' => $this->site->id]);
    }

    public function test_a_reader_refused_the_concern_sees_the_review_but_not_whose_it_is(): void
    {
        $event = $this->concernEvent(false);
        $this->assertFalse($this->officer->canDo('safeguarding.viewAny'), 'The H&S officer has no safeguarding access by default.');

        $this->actingAs($this->officer)->get('/health-safety/events')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('events.data.0.id', $event->id)
            ->where('events.data.0.client_name', null)
            ->where('events.data.0.staff_name', null));

        $this->actingAs($this->officer)->get("/health-safety/events/{$event->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('detail.title', 'Safeguarding — Kauri House')
            ->where('detail.client', null)
            ->where('detail.created_by_name', null));

        $items = app(HsEventProvider::class)->authorizedTasks($this->officer, ['id' => $event->id, 'include_done' => true]);
        $this->assertCount(1, $items);
        $this->assertNull($items[0]->client);
        $this->assertStringNotContainsString('Brownlee', json_encode(array_map(fn ($item) => $item->toArray(), $items), JSON_THROW_ON_ERROR));
    }

    public function test_a_need_to_know_concern_hides_the_person_even_from_safeguarding_readers_outside_it(): void
    {
        $event = $this->concernEvent(true);
        $this->grant($this->officer, 'safeguarding.viewAny');

        $this->actingAs($this->officer)->get("/health-safety/events/{$event->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('detail.client', null));

        $this->grant($this->officer, 'safeguarding.viewSensitive');
        $this->actingAs($this->officer)->get("/health-safety/events/{$event->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('detail.client.name', 'Sofia Brownlee'));
    }

    public function test_someone_who_can_open_the_concern_still_sees_the_person(): void
    {
        $event = $this->concernEvent(false);
        $this->grant($this->officer, 'safeguarding.viewAny');

        $this->actingAs($this->officer)->get('/health-safety/events')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('events.data.0.client_name', 'Sofia Brownlee'));
        $this->actingAs($this->officer)->get("/health-safety/events/{$event->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('detail.client.name', 'Sofia Brownlee'));
        // /tasks names no one on these cards, for anyone: their titles already say only kind and Site there.
        $items = app(HsEventProvider::class)->authorizedTasks($this->officer, ['id' => $event->id, 'include_done' => true]);
        $this->assertNull($items[0]->client);
    }

    public function test_a_private_exposure_review_names_the_worker_only_to_wellbeing_readers(): void
    {
        $worker = User::factory()->create(['name' => 'Demo Exposed Worker']);
        $event = HsEvent::factory()->create(['site_id' => $this->site->id, 'event_category' => HsEvent::CATEGORY_EXPOSURE,
            'source_type' => \App\Models\SubstanceExposureRecord::class, 'source_id' => 987654, 'staff_id' => $worker->id, 'client_id' => null]);

        $this->actingAs($this->officer)->get("/health-safety/events/{$event->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('detail.staff', null)->where('detail.title', 'Chemical exposure — Kauri House'));

        $this->grant($this->officer, 'hr.wellbeing.view');
        $this->actingAs($this->officer)->get("/health-safety/events/{$event->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('detail.staff.name', 'Demo Exposed Worker'));
    }

    private function concernEvent(bool $needToKnow): HsEvent
    {
        $concern = SafeguardingConcern::factory()->create([
            'site_id' => $this->site->id, 'subject_type' => Client::class, 'subject_id' => $this->client->id,
            'subject_name' => null, 'is_sensitive' => $needToKnow, 'severity' => 'high',
        ]);
        $event = HsEvent::query()->where('source_type', SafeguardingConcern::class)->where('source_id', $concern->id)->sole();
        $this->assertSame($this->client->id, (int) $event->client_id);

        return $event;
    }

    private function grant(User $user, string $key): void
    {
        $user->permissionOverrides()->syncWithoutDetaching([Permission::where('key', $key)->sole()->id => ['allowed' => true]]);
    }

    private function roleUser(string $roleName): User
    {
        $user = User::factory()->create(['role' => $roleName, 'approved_at' => now()]);
        $user->roles()->syncWithoutDetaching([Role::query()->where('name', $roleName)->firstOrFail()->id]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [],
            'position_role' => $roleName, 'start_date' => today()->subYear(), 'end_date' => null, 'is_active' => true,
        ]);

        return $user->fresh();
    }
}
