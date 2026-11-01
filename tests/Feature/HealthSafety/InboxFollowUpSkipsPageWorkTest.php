<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\HsEvent;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\HsDashboardService;
use App\Services\HealthSafety\HsKpiService;
use App\Support\Inertia\SharedDeferredReload;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Tests\TestCase;

/**
 * After a page loads, the header inbox asks the same URL again for just `inbox` (a shared deferred prop).
 * /health-safety and /tasks used to build their whole page again for that request and throw it away
 * (Speed, 30 Oct: about twice the server work per visit). Now they skip the page work. The browser gets
 * exactly what it got before: the inbox plus the page's Inertia::always props, after the same access checks.
 */
class InboxFollowUpSkipsPageWorkTest extends TestCase
{
    use RefreshDatabase;

    private const ROLES = ['admin', 'health_safety_officer', 'provider_manager', 'team_lead'];

    public function test_the_health_safety_inbox_request_returns_the_same_always_props_without_building_the_page(): void
    {
        [$own] = $this->world();
        foreach (self::ROLES as $role) {
            $viewer = $this->viewer($role, $own);
            [$full] = $this->measure(fn () => $this->actingAs($viewer)->get('/health-safety', $this->inertia()));
            // Since step C the first response is cheap too, so compare with the same request also asking for the headline part.
            [, $fullQueries] = $this->measure(fn () => $this->actingAs($viewer)
                ->get('/health-safety', [...$this->inertia('health-safety/dashboard'), 'X-Inertia-Partial-Data' => 'inbox,kpis']));
            // The page's KPI work must not run at all for the inbox request (any call on these mocks fails the test).
            $this->mock(HsDashboardService::class);
            $this->mock(HsKpiService::class);
            [$inbox, $inboxQueries] = $this->measure(fn () => $this->actingAs($viewer)->get('/health-safety', $this->inertia('health-safety/dashboard')));
            $this->app->forgetInstance(HsDashboardService::class);
            $this->app->forgetInstance(HsKpiService::class);

            $full->assertOk();
            $inbox->assertOk()->assertJsonPath('component', 'health-safety/dashboard')->assertJsonMissingPath('props.kpis');
            $props = $inbox->json('props');
            $this->assertArrayHasKey('inbox', $props, $role);
            foreach (['worker_participation', 'filters', 'sites'] as $always) {
                $this->assertSame($full->json("props.{$always}"), $props[$always] ?? 'missing', "{$role}: {$always} must match the full page.");
            }
            foreach (array_diff(array_keys($props), ['inbox']) as $key) {
                $this->assertSame($full->json("props.{$key}"), $props[$key], "{$role}: {$key} must match the full page.");
            }
            $this->assertLessThan($fullQueries, $inboxQueries, "{$role}: the inbox request must not rebuild the page ({$inboxQueries} vs {$fullQueries} queries).");
        }
    }

    public function test_the_health_safety_inbox_request_keeps_the_page_access_checks(): void
    {
        [$own] = $this->world();
        $noPermission = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $noPermission->roles()->attach(Role::where('name', 'support_worker')->sole());
        $noSite = User::factory()->create(['role' => 'team_lead', 'approved_at' => now()]);
        $noSite->roles()->attach(Role::where('name', 'team_lead')->sole());

        $statuses = [];
        foreach (['no permission' => $noPermission, 'no Site' => $noSite, 'officer' => $this->viewer('health_safety_officer', $own)] as $label => $user) {
            $full = $this->actingAs($user)->get('/health-safety', $this->inertia())->status();
            $inbox = $this->actingAs($user)->get('/health-safety', $this->inertia('health-safety/dashboard'))->status();
            $this->assertSame($full, $inbox, "{$label}: the inbox request must answer like the page.");
            $statuses[$label] = $inbox;
        }
        $this->assertSame(403, $statuses['no permission']);
        $this->assertSame(403, $statuses['no Site']);
        $this->assertSame(200, $statuses['officer']);
    }

    public function test_the_tasks_inbox_request_skips_the_task_list(): void
    {
        [$own] = $this->world();
        foreach (self::ROLES as $role) {
            $viewer = $this->viewer($role, $own);
            [$full] = $this->measure(fn () => $this->actingAs($viewer)->get('/tasks', $this->inertia()));
            // The same partial request but also asking for the task list goes the normal way and builds it, so the
            // difference between the two is exactly the page work the inbox request skips. (A ratio against the full
            // page stopped meaning anything once the sidebar count moved out of the first load and roles are read
            // once per page view: the full /tasks page became cheap in this small test world.)
            [, $withListQueries] = $this->measure(fn () => $this->actingAs($viewer)
                ->get('/tasks', [...$this->inertia('tasks/index'), 'X-Inertia-Partial-Data' => 'inbox,items']));
            [$inbox, $inboxQueries] = $this->measure(fn () => $this->actingAs($viewer)->get('/tasks', $this->inertia('tasks/index')));

            $full->assertOk()->assertJsonPath('component', 'tasks/index');
            $inbox->assertOk()->assertJsonPath('component', 'tasks/index')->assertJsonMissingPath('props.items')->assertJsonMissingPath('props.stats');
            $props = $inbox->json('props');
            $this->assertArrayHasKey('inbox', $props, $role);
            foreach (array_diff(array_keys($props), ['inbox']) as $key) {
                $this->assertSame($full->json("props.{$key}"), $props[$key], "{$role}: {$key} must match the full page.");
            }
            $this->assertLessThan($withListQueries, $inboxQueries, "{$role}: the inbox request must not build the task list ({$inboxQueries} queries vs {$withListQueries} when the list is asked for).");
        }
    }

    /**
     * /incidents sends every register prop as Inertia::always, so its inbox request used to rebuild and resend the whole
     * register. Stephan (31 Oct, "Change it"): it now gets only the inbox; the page keeps the register it just loaded.
     */
    public function test_the_incidents_inbox_request_skips_the_register_and_answers_like_the_page(): void
    {
        [$own] = $this->world();
        $noPermission = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $viewers = ['no permission' => $noPermission];
        foreach (self::ROLES as $role) {
            $viewers[$role] = $this->viewer($role, $own);
        }

        $served = 0;
        foreach ($viewers as $label => $viewer) {
            [$full] = $this->measure(fn () => $this->actingAs($viewer)->get('/incidents', $this->inertia()));
            // The inbox request as it used to cost: the same partial request also asking for the register rows.
            [, $withRegisterQueries] = $this->measure(fn () => $this->actingAs($viewer)
                ->get('/incidents', [...$this->inertia('incidents/index'), 'X-Inertia-Partial-Data' => 'inbox,rows']));
            [$inbox, $inboxQueries] = $this->measure(fn () => $this->actingAs($viewer)->get('/incidents', $this->inertia('incidents/index')));

            $this->assertSame($full->status(), $inbox->status(), "{$label}: the inbox request must answer like the page.");
            if ($full->status() !== 200) {
                continue;
            }

            $served++;
            $inbox->assertJsonPath('component', 'incidents/index')->assertHeader('Referrer-Policy', 'no-referrer');
            $this->assertStringContainsString('no-store', (string) $inbox->headers->get('Cache-Control'), $label);
            $this->assertStringContainsString('private', (string) $inbox->headers->get('Cache-Control'), $label);
            $props = $inbox->json('props');
            $this->assertArrayHasKey('inbox', $props, $label);
            foreach (['viewer_id', 'filters', 'rows', 'hero', 'sites', 'clients', 'can', 'detail'] as $register) {
                $this->assertArrayHasKey($register, $full->json('props'), "{$label}: the page sends {$register}.");
                $this->assertArrayNotHasKey($register, $props, "{$label}: the inbox request must not send {$register}.");
            }
            foreach (array_diff(array_keys($props), ['inbox', 'taskNav']) as $key) {
                $this->assertSame($full->json("props.{$key}"), $props[$key], "{$label}: {$key} must match the full page.");
            }
            $this->assertLessThan($withRegisterQueries, $inboxQueries, "{$label}: the inbox request must not build the register ({$inboxQueries} vs {$withRegisterQueries} queries).");
        }
        $this->assertSame(403, $this->actingAs($noPermission)->get('/incidents', $this->inertia('incidents/index'))->status());

        // A query the page refuses is refused for the inbox request too, with the same message. (Last, because a
        // refusal leaves its message in the session for the next request.)
        foreach ($viewers as $label => $viewer) {
            $page = $this->actingAs($viewer)->get('/incidents?owner=everyone', $this->inertia());
            $pageErrors = session('errors')?->getBag('default')->toArray();
            $inbox = $this->actingAs($viewer)->get('/incidents?owner=everyone', $this->inertia('incidents/index'));
            $this->assertSame($page->status(), $inbox->status(), "{$label}: an invalid owner lens must be refused alike.");
            $this->assertSame($pageErrors, session('errors')?->getBag('default')->toArray(), "{$label}: with the same message.");
        }
        $this->assertGreaterThanOrEqual(2, $served, 'At least two roles must open the register.');
    }

    public function test_only_a_request_for_shared_deferred_props_alone_counts(): void
    {
        Inertia::share('inbox', Inertia::defer(fn () => []));
        Inertia::share('auth', ['user' => null]);
        $request = fn (array $headers) => tap(Request::create('/health-safety'), fn (Request $r) => $r->headers->add($headers));
        $partial = ['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'health-safety/dashboard'];

        $this->assertTrue(SharedDeferredReload::only($request([...$partial, 'X-Inertia-Partial-Data' => 'inbox']), 'health-safety/dashboard'));
        $this->assertFalse(SharedDeferredReload::only($request([...$partial, 'X-Inertia-Partial-Data' => 'inbox']), 'tasks/index'), 'another page');
        $this->assertFalse(SharedDeferredReload::only($request([...$partial, 'X-Inertia-Partial-Data' => 'inbox,kpis']), 'health-safety/dashboard'), 'a page prop too');
        $this->assertFalse(SharedDeferredReload::only($request([...$partial, 'X-Inertia-Partial-Data' => 'auth']), 'health-safety/dashboard'), 'a shared prop that is not deferred');
        $this->assertFalse(SharedDeferredReload::only($request([...$partial, 'X-Inertia-Partial-Data' => 'inbox', 'X-Inertia-Partial-Except' => 'kpis']), 'health-safety/dashboard'), 'an except request');
        $this->assertFalse(SharedDeferredReload::only($request($partial), 'health-safety/dashboard'), 'no keys');
        $this->assertFalse(SharedDeferredReload::only($request(['X-Inertia-Partial-Component' => 'health-safety/dashboard', 'X-Inertia-Partial-Data' => 'inbox']), 'health-safety/dashboard'), 'not an Inertia request');
    }

    /** @return array{0: Site, 1: Site} */
    private function world(): array
    {
        $this->seed(RbacSeeder::class);
        $own = Site::factory()->create(['is_active' => true]);
        $other = Site::factory()->create(['is_active' => true]);
        $creator = User::factory()->create();
        foreach ([$own, $other] as $site) {
            HsEvent::factory()->count(2)->create(['site_id' => $site->id, 'event_category' => 'hazard', 'created_by' => $creator->id]);
        }

        return [$own, $other];
    }

    private function viewer(string $role, Site $site): User
    {
        $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
        $user->roles()->attach(Role::where('name', $role)->sole());
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'position_role' => $role, 'primary_site_id' => $site->id,
            'secondary_site_ids' => [], 'start_date' => today()->subYear(), 'end_date' => null, 'is_active' => true]);

        return $user->fresh();
    }

    /** @return array<string, string> */
    private function inertia(?string $partialComponent = null): array
    {
        $headers = ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request())];

        return $partialComponent === null ? $headers
            : [...$headers, 'X-Inertia-Partial-Component' => $partialComponent, 'X-Inertia-Partial-Data' => 'inbox'];
    }

    /** @return array{0: \Illuminate\Testing\TestResponse, 1: int} */
    private function measure(callable $request): array
    {
        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });
        $before = $count;
        $response = $request();

        return [$response, $count - $before];
    }
}
